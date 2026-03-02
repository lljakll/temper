#include <gtest/gtest.h>
#include <temper/journal.h>

TEST(Journal, BasicLoad) {
    temper::Journal j;
    j.load_file("../../../tests/fixtures/simple_expenses.temper");
    EXPECT_GT(j.stats().size(), 0); // TODO: real checks
}