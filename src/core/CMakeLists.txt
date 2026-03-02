add_library(temper-core STATIC
  journal.cpp
  account.cpp
  parser/parser.cpp
  model/transaction.cpp
  model/posting.cpp
  model/commodity.cpp
  reports/balance.cpp
  reports/register.cpp
  reports/print.cpp
  reports/stats.cpp
  utils/decimal.cpp
  utils/date.cpp
  utils/hash.cpp
)

target_include_directories(temper-core PUBLIC ${PROJECT_SOURCE_DIR}/include)
target_link_libraries(temper-core PUBLIC fmt::fmt spdlog::spdlog date::date) # Changed to PUBLIC