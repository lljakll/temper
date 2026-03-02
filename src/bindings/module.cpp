#include <pybind11/pybind11.h>
#include <temper/journal.h>

PYBIND11_MODULE(temper, m) {
    m.doc() = "temper Python bindings";

    pybind11::class_<temper::Journal>(m, "Journal")
        .def(pybind11::init<>())
        .def("load_file", &temper::Journal::load_file)
        .def("balance", &temper::Journal::balance)
        .def("register", &temper::Journal::register_report); // Updated name
}