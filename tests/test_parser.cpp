#include <gtest/gtest.h>
#include <temper/parser.h>
#include <temper/journal.h>

TEST(Parser, RealFile) {
    temper::Journal j;
    parse_bean_file("../../../tests/fixtures/main_with_includes.temper", j);
    EXPECT_TRUE(true); // TODO: check txns, txnhash, balances
}