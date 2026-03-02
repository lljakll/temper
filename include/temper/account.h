#pragma once

#include "types.h"
#include "commodity.h"

namespace temper {

class Account {
public:
    std::string name;
    std::map<Commodity, Decimal> balances;
    std::vector<std::shared_ptr<Account>> children; // tree
    Account* parent = nullptr;

    void add_balance(const Commodity& comm, const Decimal& amt);
};

} // namespace temper