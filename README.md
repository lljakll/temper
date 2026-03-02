# temper

Fast, personal plain-text accounting engine in C++.

Replaces Beancount/hledger core with modern C++ performance while keeping your existing `import.py` workflow.

## Quick Start
```bash
cmake --preset=linux-debug
cmake --build --preset=linux-debug -j$(nproc)
./build/debug/src/cli/temper balance -f data/examples/expenses.bean