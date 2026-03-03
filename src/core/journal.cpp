#include <temper/journal.h>
#include <temper/parser.h>
#include <fmt/format.h>
#include <spdlog/spdlog.h> // NEW: for spdlog::warn/debug
#include <sstream> // NEW: for std::istringstream

namespace temper {

Journal::Journal() : root_account_(std::make_shared<Account>()) {
    root_account_->name = "Root";
}

void Journal::load_file(const std::string& path) {
    parse_bean_file(path, *this);
    build_account_tree();
}

void Journal::add_transaction(const Transaction& txn) {
    transactions_.push_back(txn);
}

void Journal::add_open(const std::string& account, const Date& date) {
    if (open_accounts_.count(account)) {
        spdlog::warn("Account {} already open", account);
    }
    open_accounts_[account] = date;
    spdlog::debug("Added open account: {}", account);
}

void Journal::add_close(const std::string& account, const Date& date) {
    (void)date; // Suppress unused for now
    if (open_accounts_.count(account) == 0) {
        spdlog::warn("Account {} not open", account);
    }
    open_accounts_.erase(account);
    spdlog::debug("Added close account: {}", account);
}

void Journal::add_price(const std::string& commodity, const Decimal& price, const std::string& currency, const Date& date) {
    (void)date; // Suppress unused for now
    // TODO: store in price db
    spdlog::debug("Added price for {}: {} {}", commodity, price.value, currency);
}

void Journal::build_account_tree() {
    spdlog::debug("Building tree with {} transactions", transactions_.size());
    for (const auto& txn : transactions_) {
        spdlog::debug("Processing txn: {}", txn.description);
        for (const auto& post : txn.postings) {
            spdlog::debug("Posting: {} {} {}", post.account, post.amount.value, post.commodity.name);
            Account* current = root_account_.get();
            std::istringstream acct_iss(post.account);
            std::string part;
            while (std::getline(acct_iss, part, ':')) {
                bool found = false;
                for (auto& child : current->children) {
                    if (child->name == part) {
                        current = child.get();
                        found = true;
                        break;
                    }
                }
                if (!found) {
                    auto new_child = std::make_shared<Account>();
                    new_child->name = part;
                    new_child->parent = current;
                    current->children.push_back(new_child);
                    current = new_child.get();
                }
            }
            current->add_balance(post.commodity, post.amount);
        }
    }
    spdlog::debug("Built account tree with {} open accounts", open_accounts_.size());
}

std::string Journal::balance() const {
    return print_balances(*root_account_);
}

std::string Journal::register_report(const std::string& acct) const {
    return fmt::format("TODO: register for {}", acct);
}

std::string Journal::print() const {
    return "TODO: print txns";
}

std::string Journal::stats() const {
    return fmt::format("{} txns, {} open accounts", transactions_.size(), open_accounts_.size());
}

std::string Journal::print_balances(const Account& acct, int depth) const {
    std::string output;
    std::string indent(depth * 2, ' ');
    if (!acct.balances.empty()) {
        for (const auto& bal : acct.balances) {
            output += fmt::format("{}{}: {:.2f} {}\n", indent, acct.name, bal.second.value, bal.first.name);
        }
    } else {
        output += fmt::format("{}{}\n", indent, acct.name);
    }
    for (const auto& child : acct.children) {
        output += print_balances(*child, depth + 1);
    }
    return output;
}


} // namespace temper