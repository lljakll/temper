#pragma once

#include "types.h"

namespace temper {

class Commodity {
public:
    std::string name; // USD, BTC, etc.
    // TODO: price history

    bool operator<(const Commodity& other) const {
        return name < other.name;
    }
};

} // namespace temper