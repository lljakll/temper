-- Hope Baptist Treasurer Database Backup
-- Generated: 2026-06-21 03:22:03
-- Database: treasurer_db

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `accounts`;
CREATE TABLE `accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `normal_balance` enum('debit','credit') NOT NULL,
  `archived` tinyint(1) DEFAULT 0,
  `archived_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `mutable_fund` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_accounts_normal_balance` (`normal_balance`),
  KEY `idx_accounts_archived` (`archived`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

INSERT INTO `accounts` (`id`, `name`, `description`, `normal_balance`, `archived`, `archived_at`, `created_at`, `mutable_fund`) VALUES ('1', 'Cash', 'Cash on hand', 'debit', '0', NULL, '2026-06-19 11:15:17', '1');
INSERT INTO `accounts` (`id`, `name`, `description`, `normal_balance`, `archived`, `archived_at`, `created_at`, `mutable_fund`) VALUES ('2', 'Bank Account', 'Primary bank account', 'debit', '0', NULL, '2026-06-19 11:15:17', '1');
INSERT INTO `accounts` (`id`, `name`, `description`, `normal_balance`, `archived`, `archived_at`, `created_at`, `mutable_fund`) VALUES ('3', 'Accounts Receivable', 'Amounts owed to the church', 'debit', '0', NULL, '2026-06-19 11:15:17', '1');
INSERT INTO `accounts` (`id`, `name`, `description`, `normal_balance`, `archived`, `archived_at`, `created_at`, `mutable_fund`) VALUES ('4', 'Prepaid Expenses', 'Prepaid expenses such as insurance', 'debit', '0', NULL, '2026-06-19 11:15:17', '1');
INSERT INTO `accounts` (`id`, `name`, `description`, `normal_balance`, `archived`, `archived_at`, `created_at`, `mutable_fund`) VALUES ('5', 'Fixed Assets', 'Property, equipment, and other fixed assets', 'debit', '0', NULL, '2026-06-19 11:15:17', '1');
INSERT INTO `accounts` (`id`, `name`, `description`, `normal_balance`, `archived`, `archived_at`, `created_at`, `mutable_fund`) VALUES ('6', 'Accounts Payable', 'Amounts owed to others', 'credit', '0', NULL, '2026-06-19 11:15:17', '1');
INSERT INTO `accounts` (`id`, `name`, `description`, `normal_balance`, `archived`, `archived_at`, `created_at`, `mutable_fund`) VALUES ('7', 'Accrued Expenses', 'Expenses that have been incurred but not yet paid', 'credit', '0', NULL, '2026-06-19 11:15:17', '1');
INSERT INTO `accounts` (`id`, `name`, `description`, `normal_balance`, `archived`, `archived_at`, `created_at`, `mutable_fund`) VALUES ('8', 'Unearned Revenue', 'Revenue received in advance', 'credit', '0', NULL, '2026-06-19 11:15:17', '1');
INSERT INTO `accounts` (`id`, `name`, `description`, `normal_balance`, `archived`, `archived_at`, `created_at`, `mutable_fund`) VALUES ('9', 'Retained Earnings', 'Cumulative earnings of the church', 'credit', '0', NULL, '2026-06-19 11:15:17', '0');
INSERT INTO `accounts` (`id`, `name`, `description`, `normal_balance`, `archived`, `archived_at`, `created_at`, `mutable_fund`) VALUES ('10', 'Contributions', 'Donations received', 'credit', '0', NULL, '2026-06-19 11:15:17', '1');

DROP TABLE IF EXISTS `audit_log`;
CREATE TABLE `audit_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `action` varchar(100) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_log_created_at` (`created_at`),
  KEY `idx_audit_log_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `budget_lines`;
CREATE TABLE `budget_lines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `budget_id` int(11) NOT NULL,
  `natural_category_id` int(11) DEFAULT NULL,
  `functional_category_id` int(11) DEFAULT NULL,
  `account_id` int(11) DEFAULT NULL,
  `budgeted_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_budget_lines_budget_id` (`budget_id`),
  KEY `idx_budget_lines_natural_category_id` (`natural_category_id`),
  KEY `idx_budget_lines_functional_category_id` (`functional_category_id`),
  KEY `idx_budget_lines_account_id` (`account_id`),
  KEY `idx_budget_lines_budgeted_amount` (`budgeted_amount`),
  CONSTRAINT `budget_lines_ibfk_1` FOREIGN KEY (`budget_id`) REFERENCES `budgets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `budget_lines_ibfk_2` FOREIGN KEY (`natural_category_id`) REFERENCES `natural_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `budget_lines_ibfk_3` FOREIGN KEY (`functional_category_id`) REFERENCES `functional_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `budget_lines_ibfk_4` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

INSERT INTO `budget_lines` (`id`, `budget_id`, `natural_category_id`, `functional_category_id`, `account_id`, `budgeted_amount`, `notes`, `created_at`, `updated_at`) VALUES ('1', '1', '1', '1', '1', '200000.00', 'Contributions for worship and programs', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `budget_lines` (`id`, `budget_id`, `natural_category_id`, `functional_category_id`, `account_id`, `budgeted_amount`, `notes`, `created_at`, `updated_at`) VALUES ('2', '1', '2', '2', '2', '150000.00', 'Program expenses', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `budget_lines` (`id`, `budget_id`, `natural_category_id`, `functional_category_id`, `account_id`, `budgeted_amount`, `notes`, `created_at`, `updated_at`) VALUES ('3', '1', '3', '3', '3', '50000.00', 'Administrative expenses', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `budget_lines` (`id`, `budget_id`, `natural_category_id`, `functional_category_id`, `account_id`, `budgeted_amount`, `notes`, `created_at`, `updated_at`) VALUES ('4', '1', '4', '4', '4', '100000.00', 'Capital expenditures', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `budget_lines` (`id`, `budget_id`, `natural_category_id`, `functional_category_id`, `account_id`, `budgeted_amount`, `notes`, `created_at`, `updated_at`) VALUES ('8', '3', '2', NULL, '2', '999.50', 'v', '2026-06-20 09:57:52', '2026-06-20 09:57:52');
INSERT INTO `budget_lines` (`id`, `budget_id`, `natural_category_id`, `functional_category_id`, `account_id`, `budgeted_amount`, `notes`, `created_at`, `updated_at`) VALUES ('10', '4', '4', '5', '2', '2500.00', 'tmp', '2026-06-20 10:31:11', '2026-06-20 10:31:11');
INSERT INTO `budget_lines` (`id`, `budget_id`, `natural_category_id`, `functional_category_id`, `account_id`, `budgeted_amount`, `notes`, `created_at`, `updated_at`) VALUES ('15', '5', '4', '7', '5', '25.00', 'fdsa', '2026-06-20 10:37:13', '2026-06-20 10:37:13');
INSERT INTO `budget_lines` (`id`, `budget_id`, `natural_category_id`, `functional_category_id`, `account_id`, `budgeted_amount`, `notes`, `created_at`, `updated_at`) VALUES ('16', '5', '2', '7', '8', '25.00', 'fs', '2026-06-20 10:37:13', '2026-06-20 10:37:13');
INSERT INTO `budget_lines` (`id`, `budget_id`, `natural_category_id`, `functional_category_id`, `account_id`, `budgeted_amount`, `notes`, `created_at`, `updated_at`) VALUES ('21', '7', '8', '5', '5', '2500.00', '', '2026-06-20 11:41:11', '2026-06-20 11:41:11');
INSERT INTO `budget_lines` (`id`, `budget_id`, `natural_category_id`, `functional_category_id`, `account_id`, `budgeted_amount`, `notes`, `created_at`, `updated_at`) VALUES ('22', '7', '4', '7', '6', '2500.00', '', '2026-06-20 11:41:11', '2026-06-20 11:41:11');
INSERT INTO `budget_lines` (`id`, `budget_id`, `natural_category_id`, `functional_category_id`, `account_id`, `budgeted_amount`, `notes`, `created_at`, `updated_at`) VALUES ('23', '7', '7', '7', '5', '25.00', '', '2026-06-20 11:41:11', '2026-06-20 11:41:11');
INSERT INTO `budget_lines` (`id`, `budget_id`, `natural_category_id`, `functional_category_id`, `account_id`, `budgeted_amount`, `notes`, `created_at`, `updated_at`) VALUES ('26', '6', '6', '7', '8', '2500.00', 'tes', '2026-06-20 16:31:19', '2026-06-20 16:31:19');
INSERT INTO `budget_lines` (`id`, `budget_id`, `natural_category_id`, `functional_category_id`, `account_id`, `budgeted_amount`, `notes`, `created_at`, `updated_at`) VALUES ('27', '6', '2', '1', '8', '250.00', 'fds', '2026-06-20 16:31:19', '2026-06-20 16:31:19');
INSERT INTO `budget_lines` (`id`, `budget_id`, `natural_category_id`, `functional_category_id`, `account_id`, `budgeted_amount`, `notes`, `created_at`, `updated_at`) VALUES ('28', '6', '8', '1', '4', '222.22', 'fds', '2026-06-20 16:31:19', '2026-06-20 16:31:19');

DROP TABLE IF EXISTS `budgets`;
CREATE TABLE `budgets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fiscal_year` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `approved_date` date DEFAULT NULL,
  `reference_number` varchar(50) DEFAULT NULL,
  `status` enum('draft','approved','active','closed') NOT NULL DEFAULT 'draft',
  `total_budgeted` decimal(15,2) DEFAULT 0.00,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_budgets_fiscal_year` (`fiscal_year`),
  KEY `idx_budgets_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

INSERT INTO `budgets` (`id`, `fiscal_year`, `name`, `start_date`, `end_date`, `approved_date`, `reference_number`, `status`, `total_budgeted`, `description`, `created_at`, `updated_at`) VALUES ('1', '2024', '2024 Church Budget', '2024-01-01', '2024-12-31', '2023-12-15', '2023-12-15-001', 'closed', '500000.00', 'Annual church budget for 2024', '2026-06-19 11:15:18', '2026-06-20 10:29:14');
INSERT INTO `budgets` (`id`, `fiscal_year`, `name`, `start_date`, `end_date`, `approved_date`, `reference_number`, `status`, `total_budgeted`, `description`, `created_at`, `updated_at`) VALUES ('3', '2026', 'Verify B', '2026-01-01', '2026-06-20', NULL, 'BM20261022-010', 'closed', '999.50', '', '2026-06-20 09:01:29', '2026-06-20 10:32:54');
INSERT INTO `budgets` (`id`, `fiscal_year`, `name`, `start_date`, `end_date`, `approved_date`, `reference_number`, `status`, `total_budgeted`, `description`, `created_at`, `updated_at`) VALUES ('4', '2027', 'CY2027 Budget', '2026-06-20', '2027-12-31', '2026-10-15', 'CY2027BDGT', 'active', '2500.00', 'Temp2', '2026-06-20 09:07:48', '2026-06-20 10:32:54');
INSERT INTO `budgets` (`id`, `fiscal_year`, `name`, `start_date`, `end_date`, `approved_date`, `reference_number`, `status`, `total_budgeted`, `description`, `created_at`, `updated_at`) VALUES ('5', '2028', '2028 Budget', '2028-01-01', '2028-12-31', '2026-06-20', 'OFF-250504', 'approved', '50.00', 'test 2', '2026-06-20 10:34:48', '2026-06-20 10:37:13');
INSERT INTO `budgets` (`id`, `fiscal_year`, `name`, `start_date`, `end_date`, `approved_date`, `reference_number`, `status`, `total_budgeted`, `description`, `created_at`, `updated_at`) VALUES ('6', '2029', '2029 Budget', '2029-01-01', '2029-12-31', NULL, '', 'draft', '2972.22', 'test 4', '2026-06-20 10:43:16', '2026-06-20 16:31:19');
INSERT INTO `budgets` (`id`, `fiscal_year`, `name`, `start_date`, `end_date`, `approved_date`, `reference_number`, `status`, `total_budgeted`, `description`, `created_at`, `updated_at`) VALUES ('7', '2025', '2025 Budget', '2025-01-01', '2025-12-31', '2025-06-20', 'test', 'closed', '5025.00', '', '2026-06-20 11:40:36', '2026-06-20 11:41:38');

DROP TABLE IF EXISTS `functional_categories`;
CREATE TABLE `functional_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `archived` tinyint(1) DEFAULT 0,
  `archived_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_functional_categories_archived` (`archived`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

INSERT INTO `functional_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('1', 'Worship', 'Expenses related to worship services', '0', NULL, '2026-06-19 11:15:17');
INSERT INTO `functional_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('2', 'Education', 'Expenses related to educational programs', '0', NULL, '2026-06-19 11:15:17');
INSERT INTO `functional_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('3', 'Community Outreach', 'Expenses related to community outreach', '0', NULL, '2026-06-19 11:15:17');
INSERT INTO `functional_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('4', 'Finance', 'Expenses related to financial operations', '0', NULL, '2026-06-19 11:15:17');
INSERT INTO `functional_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('5', 'Facilities', 'Expenses related to facilities maintenance', '0', NULL, '2026-06-19 11:15:17');
INSERT INTO `functional_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('6', 'Stewardship', 'Expenses related to stewardship and giving', '0', NULL, '2026-06-19 11:15:17');
INSERT INTO `functional_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('7', 'Leadership', 'Expenses related to leadership development', '0', NULL, '2026-06-19 11:15:17');

DROP TABLE IF EXISTS `funds`;
CREATE TABLE `funds` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `code` varchar(50) DEFAULT NULL,
  `type` enum('WODR','WDR') NOT NULL,
  `current_balance` decimal(15,2) DEFAULT 0.00,
  `description` text DEFAULT NULL,
  `purpose` text DEFAULT NULL,
  `donor_reference` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `archived` tinyint(1) DEFAULT 0,
  `archived_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_funds_type` (`type`),
  KEY `idx_funds_is_active` (`is_active`),
  KEY `idx_funds_archived` (`archived`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

INSERT INTO `funds` (`id`, `name`, `code`, `type`, `current_balance`, `description`, `purpose`, `donor_reference`, `is_active`, `archived`, `archived_at`, `created_at`, `updated_at`) VALUES ('1', 'General Operating Fund', 'GOF', 'WODR', '0.00', 'Main operating fund for general church activities', 'General church operations', NULL, '1', '0', NULL, '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `funds` (`id`, `name`, `code`, `type`, `current_balance`, `description`, `purpose`, `donor_reference`, `is_active`, `archived`, `archived_at`, `created_at`, `updated_at`) VALUES ('2', 'Missions Fund', 'MF', 'WDR', '0.00', 'Donor-restricted funds for missionary work', 'Mission work', NULL, '1', '0', NULL, '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `funds` (`id`, `name`, `code`, `type`, `current_balance`, `description`, `purpose`, `donor_reference`, `is_active`, `archived`, `archived_at`, `created_at`, `updated_at`) VALUES ('3', 'Benevolence Fund', 'BF', 'WDR', '0.00', 'Donor-restricted funds for assistance to members in need', 'Member assistance', NULL, '1', '0', NULL, '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `funds` (`id`, `name`, `code`, `type`, `current_balance`, `description`, `purpose`, `donor_reference`, `is_active`, `archived`, `archived_at`, `created_at`, `updated_at`) VALUES ('4', 'Building Fund', 'BLD', 'WDR', '0.00', 'Donor-restricted funds for church building projects', 'Building projects', NULL, '1', '0', NULL, '2026-06-19 11:15:18', '2026-06-19 11:15:18');

DROP TABLE IF EXISTS `natural_categories`;
CREATE TABLE `natural_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `archived` tinyint(1) DEFAULT 0,
  `archived_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_natural_categories_archived` (`archived`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

INSERT INTO `natural_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('1', 'Contributions', 'Donations and offerings', '0', NULL, '2026-06-19 11:15:17');
INSERT INTO `natural_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('2', 'Program', 'Expenses for church programs', '0', NULL, '2026-06-19 11:15:17');
INSERT INTO `natural_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('3', 'Administrative', 'Administrative expenses', '0', NULL, '2026-06-19 11:15:17');
INSERT INTO `natural_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('4', 'Capital Expenditure', 'Purchases of equipment or improvement', '0', NULL, '2026-06-19 11:15:17');
INSERT INTO `natural_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('5', 'Events', 'Expenses related to church events', '0', NULL, '2026-06-19 11:15:17');
INSERT INTO `natural_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('6', 'Salaries', 'Employee salaries and wages', '0', NULL, '2026-06-19 11:15:17');
INSERT INTO `natural_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('7', 'Benefits', 'Employee benefits', '0', NULL, '2026-06-19 11:15:17');
INSERT INTO `natural_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('8', 'Operating', 'General operating expenses', '0', NULL, '2026-06-19 11:15:17');

DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `permissions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`permissions`)),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

INSERT INTO `roles` (`id`, `name`, `description`, `permissions`, `created_at`, `updated_at`) VALUES ('1', 'Administrator', 'System administrator with full access', '[]', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `roles` (`id`, `name`, `description`, `permissions`, `created_at`, `updated_at`) VALUES ('2', 'Finance Manager', 'Finance manager with access to financial data', '[]', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `roles` (`id`, `name`, `description`, `permissions`, `created_at`, `updated_at`) VALUES ('3', 'Member', 'Regular church member with limited access', '[]', '2026-06-19 11:15:18', '2026-06-19 11:15:18');

DROP TABLE IF EXISTS `tasks`;
CREATE TABLE `tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `status` enum('upcoming','due_soon','overdue','in_progress','done') NOT NULL DEFAULT 'upcoming',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_tasks_status` (`status`),
  KEY `idx_tasks_due_date` (`due_date`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

INSERT INTO `tasks` (`id`, `title`, `description`, `due_date`, `status`, `created_at`, `updated_at`) VALUES ('2', 'Pay Morrisons', 'Pay Morrison\'s Home Center Statement', '2026-07-05', 'upcoming', '2026-06-20 12:33:27', '2026-06-20 12:33:40');
INSERT INTO `tasks` (`id`, `title`, `description`, `due_date`, `status`, `created_at`, `updated_at`) VALUES ('3', 'test', 'test', '2029-01-02', 'upcoming', '2026-06-20 16:31:38', '2026-06-20 16:31:38');

DROP TABLE IF EXISTS `transaction_details`;
CREATE TABLE `transaction_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_date` date NOT NULL,
  `cleared_date` date DEFAULT NULL,
  `check_number` varchar(20) DEFAULT NULL,
  `pay_to` varchar(255) DEFAULT NULL,
  `memo` text DEFAULT NULL,
  `reference_number` varchar(50) DEFAULT NULL,
  `status` enum('pending','cleared','reconciled') DEFAULT 'pending',
  `date_reconciled` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_transaction_details_date` (`transaction_date`),
  KEY `idx_transaction_details_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `status`, `date_reconciled`, `created_at`, `updated_at`) VALUES ('1', '2025-01-05', '2025-01-06', NULL, 'Worship Service Offering', 'First Sunday tithes and offerings of the year', 'OFF-250105', 'cleared', NULL, '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `status`, `date_reconciled`, `created_at`, `updated_at`) VALUES ('2', '2025-01-08', '2025-01-09', '1201', 'City Electric Co.', 'Monthly electric utility bill', NULL, 'cleared', NULL, '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `status`, `date_reconciled`, `created_at`, `updated_at`) VALUES ('3', '2025-01-12', '2025-01-15', NULL, 'Global Missions Outreach', 'January support payment', 'MSN-202501', 'reconciled', '2025-02-01', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `status`, `date_reconciled`, `created_at`, `updated_at`) VALUES ('4', '2025-01-15', '2025-01-16', '1202', 'Metro Water Authority', 'Water and sewer services', NULL, 'cleared', NULL, '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `status`, `date_reconciled`, `created_at`, `updated_at`) VALUES ('5', '2025-01-19', '2025-01-20', NULL, 'Worship Service Offering', 'Second Sunday of January', 'OFF-250119', 'cleared', NULL, '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `status`, `date_reconciled`, `created_at`, `updated_at`) VALUES ('6', '2025-01-22', '2025-01-23', '1203', 'Office Depot', 'Office supplies, printer paper and ink', NULL, 'cleared', NULL, '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `status`, `date_reconciled`, `created_at`, `updated_at`) VALUES ('7', '2025-01-25', '2025-01-27', '1204', 'Rev. Michael Thompson', 'Pastoral compensation - January', NULL, 'cleared', NULL, '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `status`, `date_reconciled`, `created_at`, `updated_at`) VALUES ('8', '2025-01-28', '2025-01-29', NULL, 'Benevolence Assistance', 'Emergency housing support for member family', 'BEN-250128', 'cleared', NULL, '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `status`, `date_reconciled`, `created_at`, `updated_at`) VALUES ('9', '2025-02-02', '2025-02-03', NULL, 'Worship Service Offering', 'February opening Sunday', 'OFF-250202', 'cleared', NULL, '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `status`, `date_reconciled`, `created_at`, `updated_at`) VALUES ('10', '2025-02-05', '2025-02-06', NULL, 'Anonymous Donor', 'Designated gift for building repairs', 'BLD-250205', 'reconciled', '2025-02-20', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `status`, `date_reconciled`, `created_at`, `updated_at`) VALUES ('11', '2025-02-08', '2025-02-10', '1205', 'Acme Insurance Agency', 'Property and liability insurance premium', NULL, 'cleared', NULL, '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `status`, `date_reconciled`, `created_at`, `updated_at`) VALUES ('12', '2025-02-09', '2025-02-11', NULL, 'Regional Youth Camp', 'Deposit for summer youth camp (10 campers)', 'EVT-2025YC', 'cleared', NULL, '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `status`, `date_reconciled`, `created_at`, `updated_at`) VALUES ('13', '2025-02-12', '2025-02-13', NULL, 'Worship Service Offering', 'Cash and check offerings', 'OFF-250212', 'cleared', NULL, '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `status`, `date_reconciled`, `created_at`, `updated_at`) VALUES ('14', '2025-02-15', '2025-02-17', '1206', 'Sparkle Clean Janitorial', 'Monthly cleaning services', NULL, 'cleared', NULL, '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `status`, `date_reconciled`, `created_at`, `updated_at`) VALUES ('15', '2025-02-20', '2025-02-21', NULL, 'Missions Designated Gift', 'Smith family missions pledge', 'DON-MSN-15', 'cleared', NULL, '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `status`, `date_reconciled`, `created_at`, `updated_at`) VALUES ('16', '2025-02-22', '2025-02-24', '1207', 'Green Thumb Landscaping', 'Lawn care and snow removal', NULL, 'cleared', NULL, '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `status`, `date_reconciled`, `created_at`, `updated_at`) VALUES ('17', '2025-02-28', '2025-03-01', '1208', 'Rev. Michael Thompson', 'Pastoral compensation - February', NULL, 'cleared', NULL, '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `status`, `date_reconciled`, `created_at`, `updated_at`) VALUES ('18', '2025-03-02', '2025-03-03', NULL, 'Worship Service Offering', 'First Sunday March', 'OFF-250302', 'cleared', NULL, '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `status`, `date_reconciled`, `created_at`, `updated_at`) VALUES ('19', '2025-03-05', '2025-03-06', '1209', 'Faith Book & Supply', 'Sunday school and VBS curriculum', NULL, 'cleared', NULL, '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `status`, `date_reconciled`, `created_at`, `updated_at`) VALUES ('20', '2025-03-10', '2025-03-10', NULL, 'Internal Fund Transfer', 'Allocate reserves to building fund', 'XFR-250310', 'reconciled', '2025-03-15', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `status`, `date_reconciled`, `created_at`, `updated_at`) VALUES ('21', '2025-03-16', '2025-03-17', NULL, 'Worship Service Offering', 'Mid March offerings', 'OFF-250316', 'cleared', NULL, '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `status`, `date_reconciled`, `created_at`, `updated_at`) VALUES ('22', '2025-03-18', '2025-03-19', NULL, 'Hope Food Pantry', 'Monthly benevolence allocation', 'BEN-250318', 'cleared', NULL, '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `status`, `date_reconciled`, `created_at`, `updated_at`) VALUES ('23', '2025-03-22', '2025-03-24', '1210', 'Comcast Business', 'Internet and phone service', NULL, 'cleared', NULL, '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `status`, `date_reconciled`, `created_at`, `updated_at`) VALUES ('24', '2025-03-25', '2025-03-27', '1211', 'Rev. Michael Thompson', 'Pastoral compensation - March', NULL, 'cleared', NULL, '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `status`, `date_reconciled`, `created_at`, `updated_at`) VALUES ('25', '2025-03-30', '2025-03-31', NULL, 'Easter Offering', 'Special resurrection Sunday collection', 'OFF-EAST25', 'cleared', NULL, '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `status`, `date_reconciled`, `created_at`, `updated_at`) VALUES ('26', '2025-04-02', '2025-04-03', '1212', 'Harmony Piano Service', 'Annual piano tuning and maintenance', NULL, 'cleared', NULL, '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `status`, `date_reconciled`, `created_at`, `updated_at`) VALUES ('27', '2025-04-06', '2025-04-07', NULL, 'Worship Service Offering', 'Palm Sunday offerings', 'OFF-250406', 'cleared', NULL, '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `status`, `date_reconciled`, `created_at`, `updated_at`) VALUES ('28', '2025-04-10', '2025-04-11', NULL, 'Central Seminary Scholarship Fund', 'Leadership development grant', 'EDU-0425', 'cleared', NULL, '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `status`, `date_reconciled`, `created_at`, `updated_at`) VALUES ('29', '2025-04-15', '2025-04-16', '1213', 'Sarah Kline - Admin', 'Administrative assistant wages', NULL, 'cleared', NULL, '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `status`, `date_reconciled`, `created_at`, `updated_at`) VALUES ('30', '2025-04-20', '2025-04-21', NULL, 'Worship Service Offering', 'Regular Sunday giving', 'OFF-250420', 'cleared', NULL, '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `status`, `date_reconciled`, `created_at`, `updated_at`) VALUES ('31', '2025-04-25', '2025-04-28', '1214', 'A+ Plumbing & Heating', 'Fellowship hall bathroom repair', NULL, 'cleared', NULL, '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `status`, `date_reconciled`, `created_at`, `updated_at`) VALUES ('32', '2025-05-01', '2025-05-02', NULL, 'Global Missions Outreach', 'Q2 missions support payment', 'MSN-2025Q2', 'cleared', NULL, '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `status`, `date_reconciled`, `created_at`, `updated_at`) VALUES ('33', '2025-05-04', '2026-06-19', NULL, 'Worship Service Offering', 'May the fourth Sunday offerings', 'OFF-250504', 'cleared', NULL, '2026-06-19 11:15:18', '2026-06-19 12:46:49');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `status`, `date_reconciled`, `created_at`, `updated_at`) VALUES ('34', '2026-06-10', NULL, '1219', 'Corner Market Supplies', 'Fellowship supplies and coffee', '', 'reconciled', '2026-06-19', '2026-06-19 11:15:18', '2026-06-19 12:52:11');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `status`, `date_reconciled`, `created_at`, `updated_at`) VALUES ('35', '2026-06-20', NULL, '', 'fdsa', 'fa | fa', '', 'pending', NULL, '2026-06-20 16:27:08', '2026-06-20 16:27:08');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `status`, `date_reconciled`, `created_at`, `updated_at`) VALUES ('36', '2026-06-20', NULL, '', 'fdsa', 'fda | fda', '', 'pending', NULL, '2026-06-20 16:30:58', '2026-06-20 16:30:58');

DROP TABLE IF EXISTS `transaction_lines`;
CREATE TABLE `transaction_lines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_detail_id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `fund_id` int(11) DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL CHECK (`amount` > 0),
  `type` enum('debit','credit') NOT NULL,
  `natural_category_id` int(11) DEFAULT NULL,
  `functional_category_id` int(11) DEFAULT NULL,
  `budget_line_id` int(11) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `transaction_detail_id` (`transaction_detail_id`),
  KEY `natural_category_id` (`natural_category_id`),
  KEY `functional_category_id` (`functional_category_id`),
  KEY `idx_transaction_lines_account_id` (`account_id`),
  KEY `idx_transaction_lines_fund_id` (`fund_id`),
  KEY `idx_transaction_lines_amount` (`amount`),
  KEY `idx_transaction_lines_type` (`type`),
  CONSTRAINT `transaction_lines_ibfk_1` FOREIGN KEY (`transaction_detail_id`) REFERENCES `transaction_details` (`id`) ON DELETE CASCADE,
  CONSTRAINT `transaction_lines_ibfk_2` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `transaction_lines_ibfk_3` FOREIGN KEY (`fund_id`) REFERENCES `funds` (`id`) ON DELETE SET NULL,
  CONSTRAINT `transaction_lines_ibfk_4` FOREIGN KEY (`natural_category_id`) REFERENCES `natural_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `transaction_lines_ibfk_5` FOREIGN KEY (`functional_category_id`) REFERENCES `functional_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=78 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('1', '1', '2', '1', '2845.50', 'debit', '1', '6', NULL, 'Cash and checks deposit', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('2', '1', '10', '1', '2845.50', 'credit', '1', '6', NULL, 'General contributions', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('3', '2', '9', '1', '378.40', 'debit', '8', '5', NULL, 'Utilities - electric', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('4', '2', '2', '1', '378.40', 'credit', '8', '5', NULL, 'Payment to utility', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('5', '3', '9', '2', '1500.00', 'debit', '2', '3', NULL, 'Missions disbursement', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('6', '3', '2', '1', '1500.00', 'credit', '2', '3', NULL, 'Bank payment', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('7', '4', '9', '1', '92.30', 'debit', '8', '5', NULL, 'Water and sewer', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('8', '4', '2', '1', '92.30', 'credit', '8', '5', NULL, 'Payment to utility', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('9', '5', '2', '1', '3050.00', 'debit', '1', '6', NULL, 'Weekly deposit', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('10', '5', '10', '1', '3050.00', 'credit', '1', '6', NULL, 'General contributions', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('11', '6', '9', '1', '67.80', 'debit', '3', '4', NULL, 'Admin supplies', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('12', '6', '2', '1', '67.80', 'credit', '3', '4', NULL, 'Payment', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('13', '7', '9', '1', '4250.00', 'debit', '6', '7', NULL, 'Pastoral salary', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('14', '7', '2', '1', '4250.00', 'credit', '6', '7', NULL, 'Bank payment', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('15', '8', '9', '3', '500.00', 'debit', '2', '3', NULL, 'Benevolence aid - housing', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('16', '8', '2', '1', '500.00', 'credit', '2', '3', NULL, 'Bank payment', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('17', '9', '2', '1', '2890.00', 'debit', '1', '6', NULL, 'Weekly deposit', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('18', '9', '10', '1', '2890.00', 'credit', '1', '6', NULL, 'General contributions', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('19', '10', '2', '4', '10000.00', 'debit', '1', '6', NULL, 'Designated building gift', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('20', '10', '10', '4', '10000.00', 'credit', '1', '6', NULL, 'Building fund contribution', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('21', '11', '9', '1', '1250.00', 'debit', '8', '5', NULL, 'Insurance premium', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('22', '11', '2', '1', '1250.00', 'credit', '8', '5', NULL, 'Payment', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('23', '12', '9', '1', '850.00', 'debit', '5', '2', NULL, 'Youth camp deposit', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('24', '12', '2', '1', '850.00', 'credit', '5', '2', NULL, 'Payment', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('25', '13', '1', '1', '485.00', 'debit', '1', '6', NULL, 'Cash in plate', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('26', '13', '2', '1', '2620.00', 'debit', '1', '6', NULL, 'Checks and online gifts', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('27', '13', '10', '1', '3105.00', 'credit', '1', '6', NULL, 'Total contributions', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('28', '14', '9', '1', '320.00', 'debit', '8', '5', NULL, 'Janitorial services', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('29', '14', '2', '1', '320.00', 'credit', '8', '5', NULL, 'Payment', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('30', '15', '2', '2', '750.00', 'debit', '1', '6', NULL, 'Restricted missions gift', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('31', '15', '10', '2', '750.00', 'credit', '1', '6', NULL, 'Missions contribution', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('32', '16', '9', '1', '275.00', 'debit', '8', '5', NULL, 'Grounds maintenance', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('33', '16', '2', '1', '275.00', 'credit', '8', '5', NULL, 'Payment', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('34', '17', '9', '1', '4250.00', 'debit', '6', '7', NULL, 'Pastoral salary', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('35', '17', '2', '1', '4250.00', 'credit', '6', '7', NULL, 'Bank payment', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('36', '18', '2', '1', '3125.75', 'debit', '1', '6', NULL, 'Weekly deposit', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('37', '18', '10', '1', '3125.75', 'credit', '1', '6', NULL, 'General contributions', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('38', '19', '9', '1', '412.60', 'debit', '5', '2', NULL, 'Education supplies', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('39', '19', '2', '1', '412.60', 'credit', '5', '2', NULL, 'Payment', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('40', '20', '2', '4', '3000.00', 'debit', '4', '5', NULL, 'Transfer in to building', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('41', '20', '2', '1', '3000.00', 'credit', '4', '5', NULL, 'Transfer out from general', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('42', '21', '2', '1', '2765.00', 'debit', '1', '6', NULL, 'Weekly deposit', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('43', '21', '10', '1', '2765.00', 'credit', '1', '6', NULL, 'General contributions', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('44', '22', '9', '3', '325.00', 'debit', '2', '3', NULL, 'Benevolence - food pantry', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('45', '22', '2', '1', '325.00', 'credit', '2', '3', NULL, 'Bank payment', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('46', '23', '9', '1', '89.99', 'debit', '3', '4', NULL, 'Communications', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('47', '23', '2', '1', '89.99', 'credit', '3', '4', NULL, 'Payment', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('48', '24', '9', '1', '4250.00', 'debit', '6', '7', NULL, 'Pastoral salary', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('49', '24', '2', '1', '4250.00', 'credit', '6', '7', NULL, 'Bank payment', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('50', '25', '2', '1', '1925.50', 'debit', '1', '6', NULL, 'Special Easter offering', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('51', '25', '10', '1', '1925.50', 'credit', '1', '6', NULL, 'General contributions', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('52', '26', '9', '1', '175.00', 'debit', '8', '1', NULL, 'Worship support', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('53', '26', '2', '1', '175.00', 'credit', '8', '1', NULL, 'Payment', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('54', '27', '2', '1', '2540.00', 'debit', '1', '6', NULL, 'Weekly deposit', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('55', '27', '10', '1', '2540.00', 'credit', '1', '6', NULL, 'General contributions', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('56', '28', '9', '1', '1200.00', 'debit', '2', '2', NULL, 'Seminary scholarship', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('57', '28', '2', '1', '1200.00', 'credit', '2', '2', NULL, 'Payment', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('58', '29', '9', '1', '2100.00', 'debit', '6', '7', NULL, 'Admin wages', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('59', '29', '2', '1', '2100.00', 'credit', '6', '7', NULL, 'Bank payment', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('60', '30', '2', '1', '2995.00', 'debit', '1', '6', NULL, 'Weekly deposit', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('61', '30', '10', '1', '2995.00', 'credit', '1', '6', NULL, 'General contributions', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('62', '31', '9', '1', '685.00', 'debit', '4', '5', NULL, 'Facilities capital repair', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('63', '31', '2', '1', '685.00', 'credit', '4', '5', NULL, 'Payment', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('64', '32', '9', '2', '4500.00', 'debit', '2', '3', NULL, 'Q2 missions support', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('65', '32', '2', '1', '4500.00', 'credit', '2', '3', NULL, 'Bank payment', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('66', '33', '2', '1', '2680.00', 'debit', '1', '6', NULL, 'Weekly deposit', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('67', '33', '10', '1', '2680.00', 'credit', '1', '6', NULL, 'General contributions', '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('72', '34', '6', '1', '58.75', 'credit', '8', '1', NULL, '', '2026-06-19 11:58:41', '2026-06-19 11:58:41');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('73', '34', '2', '1', '58.75', 'debit', '8', '1', NULL, '', '2026-06-19 11:58:41', '2026-06-19 11:58:41');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('74', '35', '1', '3', '22.00', 'debit', '7', '3', NULL, '', '2026-06-20 16:27:08', '2026-06-20 16:27:08');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('75', '35', '6', '3', '22.00', 'credit', '5', '1', NULL, '', '2026-06-20 16:27:08', '2026-06-20 16:27:08');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('76', '36', '4', '3', '33.00', 'debit', '3', '3', NULL, '', '2026-06-20 16:30:58', '2026-06-20 16:30:58');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('77', '36', '6', '3', '33.00', 'credit', '7', '1', NULL, '', '2026-06-20 16:30:58', '2026-06-20 16:30:58');

DROP TABLE IF EXISTS `transactions`;
CREATE TABLE `transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fund_id` int(11) NOT NULL,
  `transaction_date` date NOT NULL,
  `description` varchar(255) NOT NULL,
  `debit` decimal(15,2) DEFAULT 0.00,
  `credit` decimal(15,2) DEFAULT 0.00,
  `transaction_type` enum('donation','expense','transfer','adjustment') NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `reference_number` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fund_id` (`fund_id`),
  CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`fund_id`) REFERENCES `funds` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_users_email` (`email`),
  KEY `idx_users_role_id` (`role_id`),
  KEY `idx_users_is_active` (`is_active`),
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

INSERT INTO `users` (`id`, `role_id`, `username`, `first_name`, `last_name`, `email`, `password`, `is_active`, `last_login`, `created_at`, `updated_at`) VALUES ('1', '1', 'admin', 'Admin', 'User', 'admin@church.org', '$2y$12$hElsAKEKx9CLXDqzYsxEeOLXq2V7vr.OY1qgi2RjTq19MqWII.Ute', '1', '2026-06-20 13:38:43', '2026-06-19 11:15:18', '2026-06-20 13:38:43');
INSERT INTO `users` (`id`, `role_id`, `username`, `first_name`, `last_name`, `email`, `password`, `is_active`, `last_login`, `created_at`, `updated_at`) VALUES ('2', '2', 'finance', 'Finance', 'Manager', 'finance@church.org', 'password', '1', NULL, '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `users` (`id`, `role_id`, `username`, `first_name`, `last_name`, `email`, `password`, `is_active`, `last_login`, `created_at`, `updated_at`) VALUES ('3', '3', 'member', 'Regular', 'Member', 'member@church.org', 'password', '1', NULL, '2026-06-19 11:15:18', '2026-06-19 11:15:18');

SET FOREIGN_KEY_CHECKS = 1;
