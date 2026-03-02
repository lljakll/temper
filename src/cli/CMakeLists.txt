add_executable(temper main.cpp)
target_link_libraries(temper PUBLIC temper-core) # Changed to PUBLIC
install(TARGETS temper RUNTIME DESTINATION bin)