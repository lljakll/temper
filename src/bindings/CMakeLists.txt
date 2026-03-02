pybind11_add_module(temper_python module.cpp)
target_link_libraries(temper_python PUBLIC temper-core) # Changed to PUBLIC