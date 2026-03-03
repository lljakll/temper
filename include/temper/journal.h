#pragma once

#include "transaction.h"
#include "account.h"
#include <map> // for open_accounts and balances

namespace temper {

class Journal {
public:
    Journal();
    void load_file(const std::string& path);
    void add_transaction(const Transaction& txn);
    void add_open(const std::string& account, const Date& date = {});
    void add_close(const std::string& account, const Date& date = {});
    void add_price(const std::string& commodity, const Decimal& price, const std::string& currency, const Date& date = {});

    // Reports
    std::string balance() const;
    std::string register_report(const std::string& acct) const;
    std::string print() const;
    std::string stats() const;

private:
    std::vector<Transaction> transactions_;
    std::shared_ptr<Account> root_account_; // balance tree
    std::map<std::string, Date> open_accounts_;
    // TODO: price db - std::map<Date, std::map<std::string, Decimal>>
    void build_account_tree();
    std::string print_balances(const Account& acct, int depth = 0) const; // Recursive tree print
};

} // namespace temper