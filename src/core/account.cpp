#include <temper/account.h>

namespace temper {

void Account::add_balance(const Commodity& comm, const Decimal& amt) {
    balances[comm] += amt;
}

} // namespace temper