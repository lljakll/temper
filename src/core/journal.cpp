#include <temper/journal.h>
#include <temper/parser.h>
#include <fmt/format.h>

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
    // TODO: update tree incrementally
}

void Journal::build_account_tree() {
    // TODO: construct tree from transactions
}

std::string Journal::balance() const {
    return fmt::format("TODO: balance report ({} txns)", transactions_.size());
}

std::string Journal::register_report(const std::string& acct) const {
    return fmt::format("TODO: register for {}", acct);
}

std::string Journal::print() const {
    return "TODO: print txns";
}

std::string Journal::stats() const {
    return fmt::format("TODO: stats ({} txns)", transactions_.size());
}

} // namespace temper