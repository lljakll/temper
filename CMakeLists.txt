cmake_minimum_required(VERSION 3.28)
project(temper VERSION 0.1.0 LANGUAGES CXX)

set(CMAKE_CXX_STANDARD 23)
set(CMAKE_CXX_STANDARD_REQUIRED ON)
set(CMAKE_CXX_EXTENSIONS OFF)
set(CMAKE_EXPORT_COMPILE_COMMANDS ON)
set(CMAKE_POSITION_INDEPENDENT_CODE ON)

option(BUILD_TESTS "Build tests" ON)
option(BUILD_PYTHON_BINDINGS "Build Python bindings" ON)

find_package(fmt REQUIRED)
find_package(spdlog REQUIRED)

include(FetchContent)

FetchContent_Declare(pybind11
  GIT_REPOSITORY https://github.com/pybind/pybind11.git
  GIT_TAG v2.13.6
  GIT_SHALLOW TRUE)
FetchContent_MakeAvailable(pybind11)

FetchContent_Declare(GTest
  GIT_REPOSITORY https://github.com/google/googletest.git
  GIT_TAG v1.15.2
  GIT_SHALLOW TRUE)
FetchContent_MakeAvailable(GTest)

FetchContent_Declare(date
  GIT_REPOSITORY https://github.com/HowardHinnant/date.git
  GIT_TAG v3.0.3
  GIT_SHALLOW TRUE)
FetchContent_MakeAvailable(date)

add_subdirectory(src/core)
add_subdirectory(src/cli)

if(BUILD_PYTHON_BINDINGS)
  add_subdirectory(src/bindings)
endif()

if(BUILD_TESTS)
  enable_testing()
  add_subdirectory(tests)
endif()