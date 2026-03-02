#pragma once

#include "transaction.h"
#include "account.h"

namespace temper {

class Journal {
public:
    Journal();
    void load_file(const std::string& path);
    void add_transaction(const Transaction& txn);

    // Reports
    std::string balance() const;
    std::string register_report(const std::string& acct) const;
    std::string print() const;
    std::string stats() const;

private:
    std::vector<Transaction> transactions_;
    std::shared_ptr<Account> root_account_; // balance tree
    void build_account_tree();
};

} // namespace temper