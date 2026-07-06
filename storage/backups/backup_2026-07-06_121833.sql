-- Hope Baptist Treasurer Database Backup
-- Generated: 2026-07-06 12:18:33
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

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
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('1', '1', 'admin', 'clear_all_financial', 'Tables: transaction_lines, transaction_details, budget_lines, budgets, tasks, accounts, funds, functional_categories, natural_categories', '::1', '2026-06-20 23:22:30');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('2', '1', 'admin', 'clear_all_financial_repopulate_categories', '{\"natural_categories\":13,\"functional_categories\":10}', '::1', '2026-06-20 23:22:31');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('3', '1', 'admin', 'clear_all_financial_completed', '{\"transaction_lines\":\"truncated\",\"transaction_details\":\"truncated\",\"budget_lines\":\"truncated\",\"budgets\":\"truncated\",\"tasks\":\"truncated\",\"accounts\":\"truncated\",\"funds\":\"truncated\",\"functional_categories\":\"truncated\",\"natural_categories\":\"truncated\",\"repopulated\":{\"natural_categories\":13,\"functional_categories\":10}}', '::1', '2026-06-20 23:22:31');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('4', '1', 'admin', 'workflow.created', 'instance=1 Workflow created: Test Sunday Offering — 2026-06-30 {\"workflow_type\":\"contribution\",\"status\":\"draft_pending_second_count\"}', NULL, '2026-07-05 13:40:17');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('5', '1', 'admin', 'workflow.step_completed', 'instance=1 Teller count saved; pending second teller verification. {\"status\":\"draft_pending_second_count\",\"grand_total\":250}', NULL, '2026-07-05 13:40:17');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('6', '2', 'finance', 'workflow.created', 'instance=2 Workflow created: Flow Test Offering — 2026-06-30 {\"workflow_type\":\"contribution\",\"status\":\"draft_pending_second_count\"}', NULL, '2026-07-05 13:40:34');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('7', '2', 'finance', 'workflow.step_completed', 'instance=2 Teller count saved; pending second teller verification. {\"status\":\"draft_pending_second_count\",\"grand_total\":40}', NULL, '2026-07-05 13:40:34');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('8', '1', 'admin', 'workflow.signed', 'instance=2 Second teller signed off on dual count. {\"second_teller_id\":1}', NULL, '2026-07-05 13:40:35');

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

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
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

INSERT INTO `budgets` (`id`, `fiscal_year`, `name`, `start_date`, `end_date`, `approved_date`, `reference_number`, `status`, `total_budgeted`, `description`, `created_at`, `updated_at`) VALUES ('1', '2025', 'test', '2025-01-01', '2025-12-31', '2024-07-05', '23232', 'closed', '0.00', 'tet', '2026-07-05 08:29:40', '2026-07-05 08:33:44');
INSERT INTO `budgets` (`id`, `fiscal_year`, `name`, `start_date`, `end_date`, `approved_date`, `reference_number`, `status`, `total_budgeted`, `description`, `created_at`, `updated_at`) VALUES ('2', '2026', 'tst6', '2026-01-01', '2026-12-31', '2025-07-05', '2342', 'closed', '0.00', '', '2026-07-05 08:30:12', '2026-07-05 08:32:40');
INSERT INTO `budgets` (`id`, `fiscal_year`, `name`, `start_date`, `end_date`, `approved_date`, `reference_number`, `status`, `total_budgeted`, `description`, `created_at`, `updated_at`) VALUES ('3', '2026', 'test2', '2026-01-01', '2026-12-31', '2026-07-05', '3422', 'closed', '0.00', '', '2026-07-05 08:30:34', '2026-07-05 08:33:35');

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
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

INSERT INTO `functional_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('1', 'Worship', 'Worship services and related activities', '0', NULL, '2026-06-20 23:22:31');
INSERT INTO `functional_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('2', 'Music', 'Music ministry and worship arts', '0', NULL, '2026-06-20 23:22:31');
INSERT INTO `functional_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('3', 'Youth Ministry', 'Youth programs and activities', '0', NULL, '2026-06-20 23:22:31');
INSERT INTO `functional_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('4', 'Children\'s Ministry', 'Children\'s programs and activities', '0', NULL, '2026-06-20 23:22:31');
INSERT INTO `functional_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('5', 'Adult Education', 'Adult discipleship and education', '0', NULL, '2026-06-20 23:22:31');
INSERT INTO `functional_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('6', 'Facilities & Maintenance', 'Building and grounds operations', '0', NULL, '2026-06-20 23:22:31');
INSERT INTO `functional_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('7', 'Administration', 'Administrative and finance operations', '0', NULL, '2026-06-20 23:22:31');
INSERT INTO `functional_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('8', 'Missions', 'Mission work and partnerships', '0', NULL, '2026-06-20 23:22:31');
INSERT INTO `functional_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('9', 'Evangelism', 'Evangelism and outreach efforts', '0', NULL, '2026-06-20 23:22:31');
INSERT INTO `functional_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('10', 'Stewardship', 'Stewardship and giving programs', '0', NULL, '2026-06-20 23:22:31');

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
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

INSERT INTO `funds` (`id`, `name`, `code`, `type`, `current_balance`, `description`, `purpose`, `donor_reference`, `is_active`, `archived`, `archived_at`, `created_at`, `updated_at`) VALUES ('1', 'General Fund', '900100', 'WODR', '0.00', 'General Operating Fund.  Most transactions use this fund as a source.', NULL, NULL, '1', '0', NULL, '2026-06-28 08:18:45', '2026-06-28 08:18:45');

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
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

INSERT INTO `natural_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('1', 'Offerings', 'General worship offerings and collections', '0', NULL, '2026-06-20 23:22:31');
INSERT INTO `natural_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('2', 'Tithes', 'Tithe contributions', '0', NULL, '2026-06-20 23:22:31');
INSERT INTO `natural_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('3', 'Special Gifts', 'Designated and special-purpose gifts', '0', NULL, '2026-06-20 23:22:31');
INSERT INTO `natural_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('4', 'Interest Income', 'Interest earned on accounts', '0', NULL, '2026-06-20 23:22:31');
INSERT INTO `natural_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('5', 'Salaries & Benefits', 'Staff compensation and benefits', '0', NULL, '2026-06-20 23:22:31');
INSERT INTO `natural_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('6', 'Utilities', 'Electric, water, gas, and utility expenses', '0', NULL, '2026-06-20 23:22:31');
INSERT INTO `natural_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('7', 'Rent/Mortgage', 'Facility rent or mortgage payments', '0', NULL, '2026-06-20 23:22:31');
INSERT INTO `natural_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('8', 'Insurance', 'Property, liability, and other insurance', '0', NULL, '2026-06-20 23:22:31');
INSERT INTO `natural_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('9', 'Maintenance & Repairs', 'Building and equipment maintenance', '0', NULL, '2026-06-20 23:22:31');
INSERT INTO `natural_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('10', 'Office Supplies', 'General office and administrative supplies', '0', NULL, '2026-06-20 23:22:31');
INSERT INTO `natural_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('11', 'Program Supplies', 'Ministry and program materials', '0', NULL, '2026-06-20 23:22:31');
INSERT INTO `natural_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('12', 'Missions & Outreach', 'Missions support and outreach expenses', '0', NULL, '2026-06-20 23:22:31');
INSERT INTO `natural_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('13', 'Scholarships', 'Educational scholarships and grants', '0', NULL, '2026-06-20 23:22:31');

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

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

INSERT INTO `users` (`id`, `role_id`, `username`, `first_name`, `last_name`, `email`, `password`, `is_active`, `last_login`, `created_at`, `updated_at`) VALUES ('1', '1', 'admin', 'Admin', 'User', 'admin@church.org', '$2y$12$hElsAKEKx9CLXDqzYsxEeOLXq2V7vr.OY1qgi2RjTq19MqWII.Ute', '1', '2026-07-06 08:18:03', '2026-06-19 11:15:18', '2026-07-06 08:18:03');
INSERT INTO `users` (`id`, `role_id`, `username`, `first_name`, `last_name`, `email`, `password`, `is_active`, `last_login`, `created_at`, `updated_at`) VALUES ('2', '2', 'finance', 'Finance', 'Manager', 'finance@church.org', 'password', '1', NULL, '2026-06-19 11:15:18', '2026-06-19 11:15:18');
INSERT INTO `users` (`id`, `role_id`, `username`, `first_name`, `last_name`, `email`, `password`, `is_active`, `last_login`, `created_at`, `updated_at`) VALUES ('3', '3', 'member', 'Regular', 'Member', 'member@church.org', 'password', '1', NULL, '2026-06-19 11:15:18', '2026-06-19 11:15:18');

DROP TABLE IF EXISTS `workflow_documents`;
CREATE TABLE `workflow_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `workflow_instance_id` int(11) NOT NULL,
  `workflow_step_id` int(11) DEFAULT NULL,
  `stored_filename` varchar(255) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `mime_type` varchar(120) DEFAULT NULL,
  `file_size` int(11) NOT NULL DEFAULT 0,
  `uploaded_by_user_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_workflow_documents_instance` (`workflow_instance_id`),
  CONSTRAINT `workflow_documents_ibfk_1` FOREIGN KEY (`workflow_instance_id`) REFERENCES `workflow_instances` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

DROP TABLE IF EXISTS `workflow_events`;
CREATE TABLE `workflow_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `workflow_instance_id` int(11) NOT NULL,
  `workflow_step_id` int(11) DEFAULT NULL,
  `event_type` varchar(80) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `summary` varchar(255) NOT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_workflow_events_instance` (`workflow_instance_id`),
  KEY `idx_workflow_events_type` (`event_type`),
  CONSTRAINT `workflow_events_ibfk_1` FOREIGN KEY (`workflow_instance_id`) REFERENCES `workflow_instances` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

INSERT INTO `workflow_events` (`id`, `workflow_instance_id`, `workflow_step_id`, `event_type`, `user_id`, `username`, `summary`, `details`, `created_at`) VALUES ('1', '1', NULL, 'created', '1', 'admin', 'Workflow created: Test Sunday Offering — 2026-06-30', '{\"workflow_type\":\"contribution\",\"status\":\"draft_pending_second_count\"}', '2026-07-05 13:40:17');
INSERT INTO `workflow_events` (`id`, `workflow_instance_id`, `workflow_step_id`, `event_type`, `user_id`, `username`, `summary`, `details`, `created_at`) VALUES ('2', '1', '1', 'step_completed', '1', 'admin', 'Teller count saved; pending second teller verification.', '{\"status\":\"draft_pending_second_count\",\"grand_total\":250}', '2026-07-05 13:40:17');
INSERT INTO `workflow_events` (`id`, `workflow_instance_id`, `workflow_step_id`, `event_type`, `user_id`, `username`, `summary`, `details`, `created_at`) VALUES ('3', '2', NULL, 'created', '2', 'finance', 'Workflow created: Flow Test Offering — 2026-06-30', '{\"workflow_type\":\"contribution\",\"status\":\"draft_pending_second_count\"}', '2026-07-05 13:40:34');
INSERT INTO `workflow_events` (`id`, `workflow_instance_id`, `workflow_step_id`, `event_type`, `user_id`, `username`, `summary`, `details`, `created_at`) VALUES ('4', '2', '4', 'step_completed', '2', 'finance', 'Teller count saved; pending second teller verification.', '{\"status\":\"draft_pending_second_count\",\"grand_total\":40}', '2026-07-05 13:40:34');
INSERT INTO `workflow_events` (`id`, `workflow_instance_id`, `workflow_step_id`, `event_type`, `user_id`, `username`, `summary`, `details`, `created_at`) VALUES ('5', '2', '5', 'signed', '1', 'admin', 'Second teller signed off on dual count.', '{\"second_teller_id\":1}', '2026-07-05 13:40:35');

DROP TABLE IF EXISTS `workflow_instances`;
CREATE TABLE `workflow_instances` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `workflow_type` varchar(50) NOT NULL,
  `title` varchar(200) NOT NULL,
  `status` varchar(80) NOT NULL,
  `current_step` varchar(80) NOT NULL,
  `created_by_user_id` int(11) NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
  `transaction_detail_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_workflow_type` (`workflow_type`),
  KEY `idx_workflow_status` (`status`),
  KEY `idx_workflow_created_by` (`created_by_user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

INSERT INTO `workflow_instances` (`id`, `workflow_type`, `title`, `status`, `current_step`, `created_by_user_id`, `payload`, `transaction_detail_id`, `created_at`, `updated_at`) VALUES ('1', 'contribution', 'Test Sunday Offering — 2026-06-30', 'draft_pending_second_count', 'second_teller_verify', '1', '{\"service_date\":\"2026-06-30\",\"description\":\"Test Sunday Offering\",\"cash_denominations\":{\"100\":0,\"50\":0,\"20\":5,\"10\":0,\"5\":0,\"1\":0,\"0.25\":0,\"0.10\":0,\"0.05\":0,\"0.01\":0},\"checks\":[{\"payor\":\"John Doe\",\"check_number\":\"1001\",\"check_date\":\"2026-06-29\",\"amount\":150,\"notes\":\"\"}],\"fund_allocations\":[{\"fund_id\":1,\"amount\":250}],\"totals\":{\"cash\":100,\"checks\":150,\"grand\":250},\"first_teller_id\":1,\"first_teller_at\":\"2026-07-05T17:40:17+00:00\"}', NULL, '2026-07-05 13:40:17', '2026-07-05 13:40:17');
INSERT INTO `workflow_instances` (`id`, `workflow_type`, `title`, `status`, `current_step`, `created_by_user_id`, `payload`, `transaction_detail_id`, `created_at`, `updated_at`) VALUES ('2', 'contribution', 'Flow Test Offering — 2026-06-30', 'dual_count_complete_pending_official', 'official_validate', '2', '{\"service_date\":\"2026-06-30\",\"description\":\"Flow Test Offering\",\"cash_denominations\":{\"100\":0,\"50\":0,\"20\":2,\"10\":0,\"5\":0,\"1\":0,\"0.25\":0,\"0.10\":0,\"0.05\":0,\"0.01\":0},\"checks\":[],\"fund_allocations\":[{\"fund_id\":1,\"amount\":40}],\"totals\":{\"cash\":40,\"checks\":0,\"grand\":40},\"first_teller_id\":2,\"first_teller_at\":\"2026-07-05T17:40:34+00:00\",\"second_teller_id\":1,\"second_teller_at\":\"2026-07-05T17:40:34+00:00\",\"second_verify_denominations\":{\"100\":0,\"50\":0,\"20\":2,\"10\":0,\"5\":0,\"1\":0,\"0.25\":0,\"0.10\":0,\"0.05\":0,\"0.01\":0}}', NULL, '2026-07-05 13:40:34', '2026-07-05 13:40:34');

DROP TABLE IF EXISTS `workflow_steps`;
CREATE TABLE `workflow_steps` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `workflow_instance_id` int(11) NOT NULL,
  `step_key` varchar(80) NOT NULL,
  `step_order` int(11) NOT NULL DEFAULT 0,
  `status` enum('pending','completed','rejected') NOT NULL DEFAULT 'pending',
  `required_role` varchar(50) DEFAULT NULL,
  `completed_by_user_id` int(11) DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `signature_username` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_workflow_steps_instance` (`workflow_instance_id`),
  KEY `idx_workflow_steps_key` (`step_key`),
  CONSTRAINT `workflow_steps_ibfk_1` FOREIGN KEY (`workflow_instance_id`) REFERENCES `workflow_instances` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

INSERT INTO `workflow_steps` (`id`, `workflow_instance_id`, `step_key`, `step_order`, `status`, `required_role`, `completed_by_user_id`, `completed_at`, `signature_username`, `notes`, `payload`, `created_at`, `updated_at`) VALUES ('1', '1', 'teller_create', '1', 'completed', 'Teller', '1', '2026-07-05 13:40:17', 'admin', 'First teller count recorded.', '{\"totals\":{\"cash\":100,\"checks\":150,\"grand\":250}}', '2026-07-05 13:40:17', '2026-07-05 13:40:17');
INSERT INTO `workflow_steps` (`id`, `workflow_instance_id`, `step_key`, `step_order`, `status`, `required_role`, `completed_by_user_id`, `completed_at`, `signature_username`, `notes`, `payload`, `created_at`, `updated_at`) VALUES ('2', '1', 'second_teller_verify', '2', 'pending', 'Second Teller', NULL, NULL, NULL, NULL, NULL, '2026-07-05 13:40:17', '2026-07-05 13:40:17');
INSERT INTO `workflow_steps` (`id`, `workflow_instance_id`, `step_key`, `step_order`, `status`, `required_role`, `completed_by_user_id`, `completed_at`, `signature_username`, `notes`, `payload`, `created_at`, `updated_at`) VALUES ('3', '1', 'official_validate', '3', 'pending', 'Official', NULL, NULL, NULL, NULL, NULL, '2026-07-05 13:40:17', '2026-07-05 13:40:17');
INSERT INTO `workflow_steps` (`id`, `workflow_instance_id`, `step_key`, `step_order`, `status`, `required_role`, `completed_by_user_id`, `completed_at`, `signature_username`, `notes`, `payload`, `created_at`, `updated_at`) VALUES ('4', '2', 'teller_create', '1', 'completed', 'Teller', '2', '2026-07-05 13:40:34', 'finance', 'First teller count recorded.', '{\"totals\":{\"cash\":40,\"checks\":0,\"grand\":40}}', '2026-07-05 13:40:34', '2026-07-05 13:40:34');
INSERT INTO `workflow_steps` (`id`, `workflow_instance_id`, `step_key`, `step_order`, `status`, `required_role`, `completed_by_user_id`, `completed_at`, `signature_username`, `notes`, `payload`, `created_at`, `updated_at`) VALUES ('5', '2', 'second_teller_verify', '2', 'completed', 'Second Teller', '1', '2026-07-05 13:40:35', 'admin', 'Second teller dual count verified.', '{\"verify_cash_total\":40}', '2026-07-05 13:40:34', '2026-07-05 13:40:35');
INSERT INTO `workflow_steps` (`id`, `workflow_instance_id`, `step_key`, `step_order`, `status`, `required_role`, `completed_by_user_id`, `completed_at`, `signature_username`, `notes`, `payload`, `created_at`, `updated_at`) VALUES ('6', '2', 'official_validate', '3', 'pending', 'Official', NULL, NULL, NULL, NULL, NULL, '2026-07-05 13:40:34', '2026-07-05 13:40:34');

SET FOREIGN_KEY_CHECKS = 1;
