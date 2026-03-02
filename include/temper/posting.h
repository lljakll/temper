#pragma once

#include "types.h"
#include "commodity.h"

namespace temper {

class Posting {
public:
    std::string account;
    Decimal amount;
    Commodity commodity;
    Metadata metadata;
};

} // namespace temper