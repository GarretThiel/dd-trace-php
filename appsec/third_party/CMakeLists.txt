include(FetchContent)

option(MPACK_DEBUG "Compile mpack with MPACK_DEBUG=1 and min buffer size" OFF)

if(DD_APPSEC_BUILD_HELPER)
    set(LIBDDWAF_TESTING OFF)
    add_subdirectory(libddwaf EXCLUDE_FROM_ALL)

    FetchContent_Declare(
        googletest
        GIT_REPOSITORY https://github.com/google/googletest.git
        GIT_TAG e2239ee6043f73722e7aa812a459f54a28552929)

    FetchContent_MakeAvailable(googletest)

    file(GLOB_RECURSE MSGPACK_C_SOURCES ${CMAKE_CURRENT_SOURCE_DIR}/msgpack-c/src/*.c)
    add_library(msgpack_c STATIC ${MSGPACK_C_SOURCES})
    set_target_properties(msgpack_c PROPERTIES POSITION_INDEPENDENT_CODE 1)
    target_include_directories(msgpack_c PUBLIC ${CMAKE_CURRENT_SOURCE_DIR}/msgpack-c/include/)
    target_compile_definitions(msgpack_c INTERFACE MSGPACK_CXX17=ON)

    file(GLOB_RECURSE CPPBASE64_C_SOURCES ${CMAKE_CURRENT_SOURCE_DIR}/cpp-base64/base64.cpp)
    add_library(cpp-base64 STATIC ${CPPBASE64_C_SOURCES})
    target_include_directories(cpp-base64 PUBLIC ${CMAKE_CURRENT_SOURCE_DIR}/cpp-base64)
endif()

if(DD_APPSEC_BUILD_EXTENSION)
    file(GLOB_RECURSE MPACK_C_SOURCES ${CMAKE_CURRENT_SOURCE_DIR}/../../ext/vendor/mpack/*.c)
    add_library(mpack OBJECT ${MPACK_C_SOURCES})
    set_target_properties(mpack PROPERTIES
        POSITION_INDEPENDENT_CODE 1
        C_VISIBILITY_PRESET hidden)

    target_include_directories(mpack PUBLIC ${CMAKE_CURRENT_SOURCE_DIR}/../../ext/vendor/mpack/)
    if(MPACK_DEBUG)
        target_compile_definitions(mpack PUBLIC MPACK_DEBUG=1 MPACK_BUFFER_SIZE=32)
    else()
        target_compile_definitions(mpack PUBLIC MPACK_STDIO=0 MPACK_WRITE_TRACKING=1 MPACK_BUFFER_SIZE=131072)
    endif()
endif()

FetchContent_Declare(
    spdlog
    GIT_REPOSITORY https://github.com/gabime/spdlog.git
    GIT_TAG eb3220622e73a4889eee355ffa37972b3cac3df5)
FetchContent_MakeAvailable(spdlog)

include(ExternalProject)
ExternalProject_Add(event_rules
    GIT_REPOSITORY https://github.com/DataDog/appsec-event-rules.git
    GIT_TAG 1.8.0
    GIT_SHALLOW ON
    CONFIGURE_COMMAND ""
    BUILD_COMMAND ""
    INSTALL_COMMAND "")


