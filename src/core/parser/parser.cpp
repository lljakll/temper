#include <temper/parser.h>
#include <temper/journal.h>
#include <spdlog/spdlog.h>
#include <fstream>
#include <sstream>
#include <filesystem> // for path handling
#include <date/date.h> // NEW: for date parsing

namespace temper {

void parse_bean_file(const std::string& path, Journal& journal) {
    std::ifstream file(path);
    if (!file) {
        spdlog::error("Failed to open {}", path);
        return;
    }

    spdlog::info("Parsing .temper file: {}", path);
    spdlog::set_level(spdlog::level::debug); // Enable debug for testing (move or remove later)

    std::string line;
    int line_num = 0;
    bool in_multi_comment = false; // Track multi-line comments '/* */'
    std::string token; // Declare token here, before the loop
    while (std::getline(file, line)) {
        line_num++;
        // Trim whitespace
        size_t start = line.find_first_not_of(" \t");
        if (start == std::string::npos) {
            continue; // blank line
        }
        line = line.substr(start);

        // Handle multi-line comment continuation
        if (in_multi_comment) {
            size_t end_pos = line.find("*/");
            if (end_pos != std::string::npos) {
                line = line.substr(end_pos + 2);
                in_multi_comment = false;
                // Trim again after removing comment
                start = line.find_first_not_of(" \t");
                if (start == std::string::npos) continue;
                line = line.substr(start);
            } else {
                continue; // Entire line is comment
            }
        }

        // Skip single-line comments: ';' or '//'
        if (line[0] == ';' || line.rfind("//", 0) == 0) {
            continue;
        }

        // Check for multi-line comment start '/*'
        size_t multi_start = line.find("/*");
        if (multi_start != std::string::npos) {
            size_t end_pos = line.find("*/", multi_start + 2);
            if (end_pos != std::string::npos) {
                // Single-line multi-comment
                line = line.substr(0, multi_start) + line.substr(end_pos + 2);
            } else {
                // Starts multi-line
                line = line.substr(0, multi_start);
                in_multi_comment = true;
            }
            // Trim again if anything left
            start = line.find_first_not_of(" \t");
            if (start == std::string::npos) continue;
            line = line.substr(start);
        }

        // Handle include
        if (line.rfind("include ", 0) == 0) {
            std::string include_path = line.substr(8);
            // Trim quotes if present
            if (include_path[0] == '"') {
                include_path = include_path.substr(1, include_path.size() - 2);
            }
            // Resolve relative to current path
            std::filesystem::path full_path = std::filesystem::path(path).parent_path() / include_path;
            parse_bean_file(full_path.string(), journal);
            continue;
        }

        // Parse potential date
        std::istringstream line_iss(line);
        std::string first_token;
        line_iss >> first_token;

        Date txn_date = {}; // Stub - we'll parse below
        bool is_dated = false;
        if (first_token.size() == 10 && first_token[4] == '-' && first_token[7] == '-') {
            // Parse date using date lib
            date::year_month_day ymd;
            std::istringstream date_iss(first_token);
            date_iss >> date::parse("%Y-%m-%d", ymd);
            if (date_iss) {
                txn_date.year = static_cast<int>(ymd.year());
                txn_date.month = static_cast<unsigned>(ymd.month());
                txn_date.day = static_cast<unsigned>(ymd.day());
                is_dated = true;
                line_iss >> token; // Next token after date
            } else {
                token = first_token;
            }
        } else {
            token = first_token;
        }

        spdlog::debug("Token after date parse: '{}'", token); // NEW debug

        // Now check for directive or transaction
        if (token == "open") {
            std::string account;
            std::getline(line_iss, account);
            account = account.substr(account.find_first_not_of(" \t"));
            // TODO: add to journal with date if is_dated
            spdlog::debug("Opened account: {}", account);
            continue;
        } else if (token == "close") {
            std::string account;
            std::getline(line_iss, account);
            account = account.substr(account.find_first_not_of(" \t"));
            // TODO: add to journal
            spdlog::debug("Closed account: {}", account);
            continue;
        } else if (token == "price") {
            std::string commodity;
            Decimal price;
            std::string currency;
            line_iss >> commodity >> price.value >> currency; // Stub
            // TODO: add to price db with date
            spdlog::debug("Price for {}: {} {}", commodity, price.value, currency);
            continue;
        } 

        // Transaction
        Transaction txn;
        std::string flag_str;
        if (is_dated) {
            txn.date = txn_date;
            line_iss >> flag_str; // Get flag after date
        } else {
            flag_str = token;
        }

        if (flag_str.size() == 1) {
            if (flag_str == "*") txn.flag = Flag::Cleared;
            else if (flag_str == "!") txn.flag = Flag::Pending;
            else if (flag_str == "?") txn.flag = Flag::Review;
            else txn.flag = Flag::Custom;
            line_iss >> txn.description; // Get description after flag
        } else {
            txn.flag = Flag::None;
            txn.description = flag_str; // No flag, token is description
        }

        // Get rest of description if any
        std::string rest_desc;
        std::getline(line_iss, rest_desc);
        txn.description += rest_desc;

        // Parse indented lines for postings/metadata
        int indent_level = 2; // Assume 2 spaces
        while (std::getline(file, line)) {
            line_num++;
            size_t start = line.find_first_not_of(" \t");
            if (start == std::string::npos || start < indent_level) {
                file.seekg(-(line.length() + 1), std::ios::cur);
                line_num--;
                break;
            }
            line = line.substr(start);

            if (line.rfind("txnhash:", 0) == 0) {
                txn.txnhash = line.substr(9);
            } else if (line.rfind("tag:", 0) == 0) {
                txn.tags.push_back(line.substr(5));
            } else if (line[0] == ';') {
                // Comment
            } else if (line.find(':') != std::string::npos && line[0] != ' ') {
                // Metadata key:value
                size_t colon = line.find(':');
                std::string key = line.substr(0, colon);
                std::string val = line.substr(colon + 1);
                txn.metadata[key] = val;
            } else {
                // Posting
                Posting post;
                std::istringstream post_iss(line);
                post_iss >> post.account >> post.amount.value >> post.commodity.name;
                txn.postings.push_back(post);
            }
        }

        journal.add_transaction(txn);
        spdlog::debug("Parsed transaction: {}", txn.description);
    }
}

} // namespace temper