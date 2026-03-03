#include <temper/journal.h>
#include <fmt/format.h>
#include <spdlog/spdlog.h>
#include <string>
#include <cstring> // for strcmp

#ifndef CMAKE_SOURCE_DIR
#define CMAKE_SOURCE_DIR "."
#endif

int main(int argc, char** argv) {
    std::string cmd = "balance"; // Default command
    std::string file = std::string(CMAKE_SOURCE_DIR) + "/tests/fixtures/main_with_includes.temper"; // Default to your main test file

    // Simple arg parsing
    bool debug_mode = false;
    for (int i = 1; i < argc; ++i) {
        if (std::strcmp(argv[i], "-f") == 0 && i + 1 < argc) {
            file = argv[++i];
        } else if (std::strcmp(argv[i], "--debug") == 0) {
            debug_mode = true;
        } else {
            cmd = argv[i];
        }
    }

    if (debug_mode) {
        spdlog::set_level(spdlog::level::debug);
    } else {
        spdlog::set_level(spdlog::level::info);
    }

    temper::Journal j;
    j.load_file(file);

    if (cmd == "balance") {
        fmt::print("{}\n", j.balance());
    } else if (cmd == "register") {
        fmt::print("{}\n", j.register_report("Assets"));
    } else if (cmd == "print") {
        fmt::print("{}\n", j.print());
    } else if (cmd == "stats") {
        fmt::print("{}\n", j.stats());
    } else if (cmd == "repl") {
        fmt::print("TODO: REPL mode\n");
    } else {
        spdlog::error("Unknown command: {}", cmd);
        return 1;
    }

    return 0;
}