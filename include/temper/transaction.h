#pragma once

#include "types.h"
#include "posting.h"

namespace temper {

class Transaction {
public:
    Date date;
    Flag flag = Flag::None;
    std::string description;
    std::vector<Posting> postings;
    Metadata metadata;
    Tags tags;
    std::string txnhash; // first-class
};

} // namespace temper