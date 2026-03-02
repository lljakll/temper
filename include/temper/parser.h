#pragma once

#include "journal.h"

namespace temper {

void parse_bean_file(const std::string& path, Journal& journal);

} // namespace temper