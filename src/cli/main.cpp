#include <temper/journal.h>
#include <fmt/format.h>
#include <spdlog/spdlog.h>
#include <string>

int main(int argc, char** argv) {
    if (argc < 2) {
        fmt::print("temper v0.1.0 - Usage: temper balance -f file.bean\n");
        return 0;
    }

    std::string cmd = argv[1];
    std::string file = "data/examples/expenses.bean"; // TODO: parse args

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