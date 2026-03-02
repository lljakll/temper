#!/bin/bash

set -e

cmake --preset=linux-debug
cmake --build --preset=linux-debug -j$(nproc)
ctest --preset=linux-debug --output-on-failure