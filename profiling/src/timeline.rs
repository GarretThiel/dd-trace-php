use crate::bindings as zend;
use crate::zend::{zai_str_from_zstr, zend_get_executed_filename_ex};
use crate::{PROFILER, REQUEST_LOCALS};
use libc::c_char;
use log::{error, trace};
use std::mem::MaybeUninit;
use std::ptr;
use std::time::Instant;
use std::time::SystemTime;
use std::time::UNIX_EPOCH;

/// The engine's original (or neighbouring extensions) `gc_collect_cycles()` function
static mut PREV_GC_COLLECT_CYCLES: Option<zend::VmGcCollectCyclesFn> = None;

/// The engine's original (or neighbouring extensions) `zend_compile_string()` function
static mut PREV_ZEND_COMPILE_STRING: Option<zend::VmZendCompileString> = None;

/// The engine's original (or neighbouring extensions) `zend_compile_file()` function
static mut PREV_ZEND_COMPILE_FILE: Option<zend::VmZendCompileFile> = None;

/// This functions needs to be called in MINIT of the module
pub fn timeline_minit() {
    unsafe {
        // register our function in the `gc_collect_cycles` pointer
        PREV_GC_COLLECT_CYCLES = zend::gc_collect_cycles;
        zend::gc_collect_cycles = Some(ddog_php_prof_gc_collect_cycles);

        // register our function in the `zend_compile_file` pointer
        PREV_ZEND_COMPILE_FILE = zend::zend_compile_file;
        zend::zend_compile_file = Some(ddog_php_prof_compile_file);

        // register our function in the `zend_compile_string` pointer
        PREV_ZEND_COMPILE_STRING = zend::zend_compile_string;
        zend::zend_compile_string = Some(ddog_php_prof_compile_string);
    }
}

/// This function gets called when a `eval()` is being called. This is done by letting the
/// `zend_compile_string` function pointer point to this function.
/// When called, we call the previous function and measure the wall-time it took to compile the
/// given string which will then be reported to the profiler.
unsafe extern "C" fn ddog_php_prof_compile_string(
    #[cfg(php7)] source_string: *mut zend::_zval_struct,
    #[cfg(php8)] source_string: *mut zend::ZendString,
    #[cfg(php7)] filename: *mut c_char,
    #[cfg(php8)] filename: *const c_char,
    #[cfg(php_zend_compile_string_has_position)] position: zend::zend_compile_position,
) -> *mut zend::_zend_op_array {
    if let Some(prev) = PREV_ZEND_COMPILE_STRING {
        let timeline_enabled = REQUEST_LOCALS.with(|cell| {
            cell.try_borrow()
                .map(|locals| locals.profiling_experimental_timeline_enabled)
                .unwrap_or(false)
        });

        if !timeline_enabled {
            #[cfg(php_zend_compile_string_has_position)]
            return prev(source_string, filename, position);
            #[cfg(not(php_zend_compile_string_has_position))]
            return prev(source_string, filename);
        }

        let start = Instant::now();
        #[cfg(php_zend_compile_string_has_position)]
        let op_array = prev(source_string, filename, position);
        #[cfg(not(php_zend_compile_string_has_position))]
        let op_array = prev(source_string, filename);
        let duration = start.elapsed();
        let now = SystemTime::now().duration_since(UNIX_EPOCH);

        // eval() failed
        // TODO we might collect this event anyway and label it accordingly in a later stage of
        // this feature
        if op_array.is_null() || now.is_err() {
            return op_array;
        }

        let filename = zai_str_from_zstr(zend_get_executed_filename_ex().as_mut()).into_string();

        let line = zend::zend_get_executed_lineno();

        trace!(
            "Compiling eval()'ed code in \"{filename}\" at line {line} took {} nanoseconds",
            duration.as_nanos(),
        );
        REQUEST_LOCALS.with(|cell| {
            // try to borrow and bail out if not successful
            let Ok(locals) = cell.try_borrow() else {
                return;
            };

            if let Some(profiler) = PROFILER.lock().unwrap().as_ref() {
                profiler.collect_compile_string(
                    // Safety: checked for `is_err()` above
                    now.unwrap().as_nanos() as i64,
                    duration.as_nanos() as i64,
                    filename,
                    line,
                    &locals,
                );
            }
        });
        return op_array;
    }
    error!("No previous `zend_compile_string` handler found! This is a huge problem as your eval() won't work and PHP will higly likely crash. I am sorry, but the die is cast.");
    ptr::null_mut()
}

/// This function gets called when a file is `include()`ed/`require()`d. This is done by letting
/// the `zend_compile_file` function pointer point to this function.
/// When called, we call the previous function and measure the wall-time it took to compile the
/// given file which will then be reported to the profiler.
unsafe extern "C" fn ddog_php_prof_compile_file(
    handle: *mut zend::zend_file_handle,
    r#type: i32,
) -> *mut zend::_zend_op_array {
    if let Some(prev) = PREV_ZEND_COMPILE_FILE {
        let timeline_enabled = REQUEST_LOCALS.with(|cell| {
            cell.try_borrow()
                .map(|locals| locals.profiling_experimental_timeline_enabled)
                .unwrap_or(false)
        });

        if !timeline_enabled {
            return prev(handle, r#type);
        }

        let start = Instant::now();
        let op_array = prev(handle, r#type);
        let duration = start.elapsed();
        let now = SystemTime::now().duration_since(UNIX_EPOCH);

        // include/require failed, could be invalid PHP in file or file not found, or time went
        // backwards
        // TODO we might collect this event anyway and label it accordingly in a later stage of
        // this feature
        if op_array.is_null() || (*op_array).filename.is_null() || now.is_err() {
            return op_array;
        }

        let include_type = match r#type as u32 {
            zend::ZEND_INCLUDE => "include", // `include_once()` and `include_once()`
            zend::ZEND_REQUIRE => "require", // `require()` and `require_once()`
            _default => "",
        };

        // Extract the filename from the returned op_array.
        // We could also extract from the handle, but those filenames might be different from
        // the one in the `op_array`: In the handle we get what `include()` was called with,
        // for example "/var/www/html/../vendor/foo/bar.php" while during stack walking we get
        // "/var/html/vendor/foo/bar.php". This makes sure it is the exact same string we'd
        // collect in stack walking and therefore we are fully utilizing the pprof string table
        let filename = zai_str_from_zstr((*op_array).filename.as_mut()).into_string();

        trace!(
            "Compile file \"{filename}\" with include type \"{include_type}\" took {} nanoseconds",
            duration.as_nanos(),
        );
        REQUEST_LOCALS.with(|cell| {
            // try to borrow and bail out if not successful
            let Ok(locals) = cell.try_borrow() else {
                return;
            };

            if let Some(profiler) = PROFILER.lock().unwrap().as_ref() {
                profiler.collect_compile_file(
                    // Safety: checked for `is_err()` above
                    now.unwrap().as_nanos() as i64,
                    duration.as_nanos() as i64,
                    filename,
                    include_type,
                    &locals,
                );
            }
        });
        return op_array;
    }
    error!("No previous `zend_compile_file` handler found! This is a huge problem as your include()/require() won't work and PHP will higly likely crash. I am sorry, but the die is cast.");
    ptr::null_mut()
}

/// Find out the reason for the current garbage collection cycle. If there is
/// a `gc_collect_cycles` function at the top of the call stack, it is because
/// of a userland call  to `gc_collect_cycles()`, otherwise the engine decided
/// to run it.
unsafe fn gc_reason() -> &'static str {
    let execute_data = zend::ddog_php_prof_get_current_execute_data();
    let fname = || execute_data.as_ref()?.func.as_ref()?.name();
    match fname() {
        Some(name) if name == b"gc_collect_cycles" => "induced",
        _ => "engine",
    }
}

/// This function gets called whenever PHP does a garbage collection cycle instead of the original
/// handler. This is done by letting the `zend::gc_collect_cycles` pointer point to this function
/// and store the previous pointer in `PREV_GC_COLLECT_CYCLES` for later use.
/// When called, we do collect the time the call to the `PREV_GC_COLLECT_CYCLES` took and report
/// this to the profiler.
#[no_mangle]
unsafe extern "C" fn ddog_php_prof_gc_collect_cycles() -> i32 {
    if let Some(prev) = PREV_GC_COLLECT_CYCLES {
        let timeline_enabled = REQUEST_LOCALS.with(|cell| {
            cell.try_borrow()
                .map(|locals| locals.profiling_experimental_timeline_enabled)
                .unwrap_or(false)
        });

        if !timeline_enabled {
            return prev();
        }

        #[cfg(php_gc_status)]
        let mut status = MaybeUninit::<zend::zend_gc_status>::uninit();

        let start = Instant::now();
        let collected = prev();
        let duration = start.elapsed();
        let now = SystemTime::now().duration_since(UNIX_EPOCH);
        if now.is_err() {
            // time went backwards
            return collected;
        }

        let reason = gc_reason();

        #[cfg(php_gc_status)]
        zend::zend_gc_get_status(status.as_mut_ptr());
        #[cfg(php_gc_status)]
        let status = status.assume_init();

        trace!(
            "Garbage collection with reason \"{reason}\" took {} nanoseconds",
            duration.as_nanos()
        );

        REQUEST_LOCALS.with(|cell| {
            // try to borrow and bail out if not successful
            let Ok(locals) = cell.try_borrow() else {
                return;
            };

            if let Some(profiler) = PROFILER.lock().unwrap().as_ref() {
                cfg_if::cfg_if! {
                    if #[cfg(php_gc_status)] {
                        profiler.collect_garbage_collection(
                            // Safety: checked for `is_err()` above
                            now.unwrap().as_nanos() as i64,
                            duration.as_nanos() as i64,
                            reason,
                            collected as i64,
                            status.runs as i64,
                            &locals,
                        );
                    } else {
                        profiler.collect_garbage_collection(
                            // Safety: checked for `is_err()` above
                            now.unwrap().as_nanos() as i64,
                            duration.as_nanos() as i64,
                            reason,
                            collected as i64,
                            &locals,
                        );
                    }
                }
            }
        });
        collected
    } else {
        // this should never happen, as it would mean that no `gc_collect_cycles` function pointer
        // did exist, which could only be the case if another extension was misbehaving.
        // But technically it could be, so better safe than sorry
        0
    }
}
