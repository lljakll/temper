#include <gtest/gtest.h>
#include <temper/journal.h>
#include <spdlog/spdlog.h> // NEW

TEST(Journal, BasicLoad) {
    spdlog::set_level(spdlog::level::info); // Set to INFO for clean test
    temper::Journal j;
    j.load_file("../../../tests/fixtures/simple_expenses.temper");
    EXPECT_GT(j.stats().size(), 0); // TODO: real checks
}