#include <gtest/gtest.h>
#include <temper/parser.h>
#include <temper/journal.h>
#include <spdlog/spdlog.h> // NEW

TEST(Parser, RealFile) {
    spdlog::set_level(spdlog::level::info); // Set to INFO for clean test
    temper::Journal j;
    parse_bean_file("../../../tests/fixtures/main_with_includes.temper", j);
    EXPECT_TRUE(true); // TODO: check txns, txnhash, balances
}