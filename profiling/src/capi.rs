//! Definitions for interacting with the profiler from a C API, such as the
//! ddtrace extension.

use crate::bindings::{zend_execute_data, ZaiStr};
use crate::runtime_id;

#[no_mangle]
pub extern "C" fn datadog_profiling_notify_trace_finished(
    local_root_span_id: u64,
    span_type: ZaiStr,
    resource: ZaiStr,
) {
    crate::notify_trace_finished(
        local_root_span_id,
        span_type.to_string_lossy(),
        resource.to_string_lossy(),
    );
}

/// Alignment to 16 bytes was done by the C version of the profiler. It's not
/// strictly necessary, but changing it requires a change to the tracer too.
#[repr(C, align(16))]
pub struct Uuid(uuid::Uuid);

impl From<&uuid::Uuid> for Uuid {
    fn from(uuid: &uuid::Uuid) -> Self {
        Self(*uuid)
    }
}

/// Fetch the runtime id of the process. Note that it may return the nil UUID.
/// Only call this from a PHP thread.
#[no_mangle]
pub extern "C" fn datadog_profiling_runtime_id() -> Uuid {
    Uuid::from(runtime_id())
}

#[cfg(feature = "trigger_time_sample")]
#[no_mangle]
extern "C" fn ddog_php_prof_trigger_time_sample() {
    use std::sync::atomic::Ordering;
    super::REQUEST_LOCALS.with(|cell| {
        if let Ok(locals) = cell.try_borrow() {
            if locals.profiling_enabled {
                // Safety: only vm interrupts are stored there, or possibly null (edges only).
                if let Some(vm_interrupt) = unsafe { locals.vm_interrupt_addr.as_ref() } {
                    locals.interrupt_count.fetch_add(1, Ordering::SeqCst);
                    vm_interrupt.store(true, Ordering::SeqCst);
                }
            }
        }
    })
}

/// Gathers a time sample if the configured period has elapsed. Used by the
/// tracer to handle pending profiler interrupts before calling a tracing
/// closure from an internal function hook; if this isn't done then the
/// closure is erroneously at the top of the stack.
///
/// # Safety
/// The zend_execute_data pointer should come from the engine to ensure it and
/// its sub-objects are valid.
#[no_mangle]
pub extern "C" fn datadog_profiling_interrupt_function(execute_data: *mut zend_execute_data) {
    crate::interrupt_function(execute_data);
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::borrow::Cow;

    #[test]
    fn test_string_view() {
        let slice: &[u8] = b"datadog \xF0\x9F\x90\xB6";
        let string_view = ZaiStr::from(slice);
        assert_eq!(slice, string_view.as_bytes());

        let expected = "datadog 🐶";
        let actual = string_view.to_string_lossy();
        match actual {
            Cow::Borrowed(actual) => assert_eq!(expected, actual),
            _ => panic!("Expected a borrowed string, got: {:?}", actual),
        };

        let actual = string_view.into_string();
        assert_eq!(expected, actual)
    }
}
