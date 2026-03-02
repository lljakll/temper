#pragma once

#include <string>
#include <map>
#include <vector>
#include <variant>
#include <memory> // for shared_ptr

namespace temper {

// Basic types
using Metadata = std::map<std::string, std::string>;
using Tags = std::vector<std::string>;

// Stub for Decimal (we'll use boost::multiprecision later)
class Decimal {
public:
    Decimal() = default;
    Decimal(double val) : value(val) {} // TODO: proper fixed-point
    double value{0.0};

    Decimal& operator+=(const Decimal& other) {
        value += other.value;
        return *this;
    }
    Decimal operator+(const Decimal& other) const {
        return Decimal(value + other.value);
    }
};

// Stub for Date (using date lib)
struct Date {
    int year, month, day;
};

// Flag enum
enum class Flag {
    Cleared,  // *
    Pending,  // !
    Review,   // ?
    Custom,
    None
};

} // namespace temper