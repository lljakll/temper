add_executable(temper_tests
  test_journal.cpp
  test_parser.cpp
)

target_link_libraries(temper_tests PUBLIC temper-core GTest::gtest_main) # Changed to PUBLIC
add_test(NAME temper_tests COMMAND temper_tests)