-- Hope Baptist Treasurer Data-Only Backup
-- Type: data-only
-- Generated: 2026-08-02 12:56:58 UTC
-- Database: temper_db

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Table data: `accounts`
TRUNCATE TABLE `accounts`;
INSERT INTO `accounts` (`id`, `name`, `description`, `normal_balance`, `account_type`, `coa_number`, `natural_category_id`, `functional_category_id`, `archived`, `archived_at`, `created_at`, `mutable_fund`) VALUES ('3', 'FMB: Checking Account', 'Main Checking Account with Farmers and Merchants Bank.', 'debit', 'asset', '100100', NULL, NULL, '0', NULL, '2026-08-01 09:36:28', '0');
INSERT INTO `accounts` (`id`, `name`, `description`, `normal_balance`, `account_type`, `coa_number`, `natural_category_id`, `functional_category_id`, `archived`, `archived_at`, `created_at`, `mutable_fund`) VALUES ('4', 'Cash', 'Cold and Hard', 'debit', 'asset', '100200', NULL, NULL, '0', NULL, '2026-08-01 09:36:45', '0');
INSERT INTO `accounts` (`id`, `name`, `description`, `normal_balance`, `account_type`, `coa_number`, `natural_category_id`, `functional_category_id`, `archived`, `archived_at`, `created_at`, `mutable_fund`) VALUES ('5', 'Net Assets - WODR', 'Net Assets - Without donor restriction', 'credit', 'equity', '200100', NULL, NULL, '0', NULL, '2026-08-01 10:49:27', '0');
INSERT INTO `accounts` (`id`, `name`, `description`, `normal_balance`, `account_type`, `coa_number`, `natural_category_id`, `functional_category_id`, `archived`, `archived_at`, `created_at`, `mutable_fund`) VALUES ('6', 'Net Assets - WDR', 'Net Assets WITH donor restriction', 'credit', 'equity', '200200', NULL, NULL, '0', NULL, '2026-08-01 10:49:58', '0');

-- Table data: `app_version`
TRUNCATE TABLE `app_version`;
INSERT INTO `app_version` (`id`, `version`, `schema_version`, `patch_file`, `notes`, `applied_at`) VALUES ('1', '0.801', 'setup_baseline', NULL, 'Migrated from single-row app_version (v0.801)', '2026-07-23 07:19:07');
INSERT INTO `app_version` (`id`, `version`, `schema_version`, `patch_file`, `notes`, `applied_at`) VALUES ('2', '0.802', '20260725_01_app_version_history', '20260725_01_app_version_history.sql', 'app_version full history; formalized manual schema patches (updates/)', '2026-07-25 11:54:54');
INSERT INTO `app_version` (`id`, `version`, `schema_version`, `patch_file`, `notes`, `applied_at`) VALUES ('3', '0.803', '20260725_02_schema_version_as_filename', '20260725_02_schema_version_as_filename.sql', 'schema_version stores patch filename stem (not integer)', '2026-07-25 12:22:46');
INSERT INTO `app_version` (`id`, `version`, `schema_version`, `patch_file`, `notes`, `applied_at`) VALUES ('4', '0.804', '20260725_03_formalize_audit_log', '20260725_03_formalize_audit_log.sql', 'Read-only schema checks; audit_log in setup; no live DDL/seed', '2026-07-26 08:28:05');
INSERT INTO `app_version` (`id`, `version`, `schema_version`, `patch_file`, `notes`, `applied_at`) VALUES ('5', '0.805', '20260725_03_formalize_audit_log', '20260725_04_frozen_baseline_model.sql', 'Frozen setup baseline at 0.804; post-0.804 releases via updates/ only', '2026-07-26 08:28:55');
INSERT INTO `app_version` (`id`, `version`, `schema_version`, `patch_file`, `notes`, `applied_at`) VALUES ('6', '0.806', '20260725_03_formalize_audit_log', '20260726_01_setup_check_baseline_awareness.sql', 'setup_db.php --check reports setup baseline vs database app_version', '2026-07-26 08:31:31');
INSERT INTO `app_version` (`id`, `version`, `schema_version`, `patch_file`, `notes`, `applied_at`) VALUES ('7', '0.807', '20260725_03_formalize_audit_log', '20260726_02_admin_version_outdated_indicator.sql', 'Admin sidebar red version + tooltip when DB lags latest available release', '2026-07-26 08:39:47');
INSERT INTO `app_version` (`id`, `version`, `schema_version`, `patch_file`, `notes`, `applied_at`) VALUES ('8', '0.808', '20260725_03_formalize_audit_log', '20260726_0808_patch_naming_and_sidebar_dual_version.sql', 'Patch names use app version token; admin sidebar App+DB dual display with lag warning', '2026-07-26 22:14:44');
INSERT INTO `app_version` (`id`, `version`, `schema_version`, `patch_file`, `notes`, `applied_at`) VALUES ('9', '0.809', '20260725_03_formalize_audit_log', '20260726_0809_account_filter_coa_order.sql', 'Account View defaults to All Accounts; account dropdowns ordered by coa_number', '2026-07-26 22:28:10');
INSERT INTO `app_version` (`id`, `version`, `schema_version`, `patch_file`, `notes`, `applied_at`) VALUES ('10', '0.810', '20260725_03_formalize_audit_log', '20260726_0810_tx_account_category_labels.sql', 'Transaction lines pull Natural/Functional from account as read-only labels', '2026-07-26 22:35:56');
INSERT INTO `app_version` (`id`, `version`, `schema_version`, `patch_file`, `notes`, `applied_at`) VALUES ('11', '0.811', '20260726_0811_tx_memo_to_description', '20260726_0811_tx_memo_to_description.sql', 'Rename transaction_details.memo to description; single Description field (no | join)', '2026-07-26 22:49:40');
INSERT INTO `app_version` (`id`, `version`, `schema_version`, `patch_file`, `notes`, `applied_at`) VALUES ('12', '0.900', '20260726_0811_tx_memo_to_description', '20260726_0900_beta_baseline.sql', 'Beta start: setup baseline consolidated through 0.811; no demo accounts/budgets/transactions', '2026-07-26 23:13:52');
INSERT INTO `app_version` (`id`, `version`, `schema_version`, `patch_file`, `notes`, `applied_at`) VALUES ('13', '0.901', '20260801_0901_account_type_classification', '20260801_0901_account_type_classification.sql', 'Required accounts.account_type (asset/liability/equity/income/expense); CoA Normal Balance UX', '2026-08-01 09:20:49');
INSERT INTO `app_version` (`id`, `version`, `schema_version`, `patch_file`, `notes`, `applied_at`) VALUES ('14', '0.902', '20260801_0901_account_type_classification', '20260801_0902_modal_form_autofocus.sql', 'Modal form autofocus: first data field on open; resist SPA/AJAX focus steal', '2026-08-01 23:07:13');
INSERT INTO `app_version` (`id`, `version`, `schema_version`, `patch_file`, `notes`, `applied_at`) VALUES ('15', '0.903', '20260801_0901_account_type_classification', '20260801_0903_login_timeout_disabled_authoritative.sql', 'Login Timeout disabled is authoritative: no idle modal/redirect; session GC aligned', '2026-08-01 23:14:17');
INSERT INTO `app_version` (`id`, `version`, `schema_version`, `patch_file`, `notes`, `applied_at`) VALUES ('16', '0.904', '20260801_0901_account_type_classification', '20260801_0904_lookup_archive_toggle_fix.sql', 'Lookup Archive/Unarchive toggle: fix checkbox POST cast; Funds handler; UI refresh', '2026-08-01 23:26:24');
INSERT INTO `app_version` (`id`, `version`, `schema_version`, `patch_file`, `notes`, `applied_at`) VALUES ('17', '0.905', '20260801_0901_account_type_classification', '20260801_0905_lookup_add_edit_modals.sql', 'Lookup Add/Edit forms: inline sections → modal dialogs with dirty-state protection', '2026-08-01 23:35:05');
INSERT INTO `app_version` (`id`, `version`, `schema_version`, `patch_file`, `notes`, `applied_at`) VALUES ('18', '0.906', '20260801_0901_account_type_classification', '20260801_0906_sidebar_fixed_float.sql', 'Desktop sidebar: position fixed/floating while main content scrolls', '2026-08-01 23:42:29');
INSERT INTO `app_version` (`id`, `version`, `schema_version`, `patch_file`, `notes`, `applied_at`) VALUES ('19', '0.907', '20260801_0901_account_type_classification', '20260802_0907_login_timeout_from_developer_mode.sql', 'Login timeout fixed by Developer Mode (5m/20m); Status panel regroup; no disable', '2026-08-02 08:50:02');

-- Table data: `audit_log`
TRUNCATE TABLE `audit_log`;

-- Table data: `budget_lines`
TRUNCATE TABLE `budget_lines`;

-- Table data: `budgets`
TRUNCATE TABLE `budgets`;

-- Table data: `functional_categories`
TRUNCATE TABLE `functional_categories`;
INSERT INTO `functional_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('4', 'Fundraising', 'Activities whose primary purpose is raising money.', '0', NULL, '2026-07-26 23:16:40');
INSERT INTO `functional_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('5', 'Management & General', 'Administrative and operational support.', '0', NULL, '2026-07-26 23:16:40');
INSERT INTO `functional_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('7', 'Program Services', 'Direct ministry and outreach activities.', '0', NULL, '2026-07-26 23:16:40');

-- Table data: `funds`
TRUNCATE TABLE `funds`;

-- Table data: `natural_categories`
TRUNCATE TABLE `natural_categories`;
INSERT INTO `natural_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('5', 'Salaries & Benefits', 'Base staff compensation and benefits', '0', NULL, '2026-07-26 23:16:40');
INSERT INTO `natural_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('6', 'Utilities', 'Electric, water, gas, and utility expenses', '0', NULL, '2026-07-26 23:16:40');
INSERT INTO `natural_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('8', 'Insurance', 'Property, liability, and other insurance', '0', NULL, '2026-07-26 23:16:40');
INSERT INTO `natural_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('9', 'Maintenance & Repairs', 'Building and equipment maintenance', '0', NULL, '2026-07-26 23:16:40');
INSERT INTO `natural_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('10', 'Office Supplies', 'General office and administrative supplies', '0', NULL, '2026-07-26 23:16:40');
INSERT INTO `natural_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('11', 'Program Supplies', 'Ministry activities, events, fellowship, VBS, youth, children, decorating, music, classroom & new-member supplies', '0', NULL, '2026-07-26 23:16:40');
INSERT INTO `natural_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('12', 'Missions Support', 'Missions support and outreach expenses', '0', NULL, '2026-07-26 23:16:40');
INSERT INTO `natural_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('14', 'Housing & Allowances', 'Housing allowances and pastoral fuel/auto/misc allowances', '0', NULL, '2026-08-01 22:44:34');
INSERT INTO `natural_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('15', 'Fuel & Travel', 'Vehicle fuel and other travel costs', '0', NULL, '2026-08-01 22:44:59');

-- Table data: `roles`
TRUNCATE TABLE `roles`;
INSERT INTO `roles` (`id`, `name`, `description`, `permissions`, `is_system`, `created_at`, `updated_at`) VALUES ('1', 'Administrator', 'System administrator with full access', '[\"*\"]', '1', '2026-07-10 07:10:35', '2026-07-13 09:34:08');
INSERT INTO `roles` (`id`, `name`, `description`, `permissions`, `is_system`, `created_at`, `updated_at`) VALUES ('2', 'Finance Manager', 'Finance manager with access to financial data and budgets', '[\"page.dashboard\",\"page.ledger\",\"page.ledger.write\",\"page.reports\",\"page.budget\",\"page.budget.write\",\"page.tasks\",\"admin.access\",\"admin.lookups\",\"profile.self\"]', '1', '2026-07-10 07:10:35', '2026-07-18 22:05:36');
INSERT INTO `roles` (`id`, `name`, `description`, `permissions`, `is_system`, `created_at`, `updated_at`) VALUES ('3', 'Member', 'Limited member access (profile only by default)', '[\"profile.self\"]', '1', '2026-07-10 07:10:35', '2026-07-13 09:34:08');
INSERT INTO `roles` (`id`, `name`, `description`, `permissions`, `is_system`, `created_at`, `updated_at`) VALUES ('6', 'Treasurer', 'Church treasurer — full financial operations and official approvals', '[\"page.dashboard\",\"page.ledger\",\"page.ledger.write\",\"page.reports\",\"page.budget\",\"page.budget.write\",\"page.tasks\",\"admin.access\",\"admin.backup\",\"admin.lookups\",\"profile.self\"]', '1', '2026-07-10 07:10:35', '2026-07-18 22:05:36');
INSERT INTO `roles` (`id`, `name`, `description`, `permissions`, `is_system`, `created_at`, `updated_at`) VALUES ('7', 'Financial Secretary', 'Financial secretary — deposits, official contribution validation', '[\"page.dashboard\",\"page.ledger\",\"page.ledger.write\",\"page.reports\",\"page.budget\",\"page.tasks\",\"admin.access\",\"admin.lookups\",\"profile.self\"]', '1', '2026-07-10 07:10:35', '2026-07-18 22:05:36');
INSERT INTO `roles` (`id`, `name`, `description`, `permissions`, `is_system`, `created_at`, `updated_at`) VALUES ('8', 'Archivist', 'Historical data import only (no current-year Treasurer duties)', '[\"page.dashboard\",\"page.ledger\",\"page.reports\",\"page.budget\",\"admin.access\",\"admin.lookups\",\"archive.import\",\"profile.self\"]', '1', '2026-07-13 08:33:16', '2026-07-13 09:34:08');
INSERT INTO `roles` (`id`, `name`, `description`, `permissions`, `is_system`, `created_at`, `updated_at`) VALUES ('9', 'Board Member', 'Read-only access to dashboard, reports, and budgets', '[\"page.dashboard\",\"page.reports\",\"page.budget\",\"profile.self\"]', '1', '2026-07-13 08:33:16', '2026-07-13 09:34:08');

-- Table data: `tasks`
TRUNCATE TABLE `tasks`;
INSERT INTO `tasks` (`id`, `title`, `description`, `due_date`, `status`, `created_at`, `updated_at`) VALUES ('1', 'Load Accounts', '', '2026-07-31', 'overdue', '2026-07-31 06:14:13', '2026-08-01 09:35:12');
INSERT INTO `tasks` (`id`, `title`, `description`, `due_date`, `status`, `created_at`, `updated_at`) VALUES ('2', 'Load Funds', '', '2026-07-31', 'overdue', '2026-07-31 06:14:37', '2026-08-01 09:35:11');
INSERT INTO `tasks` (`id`, `title`, `description`, `due_date`, `status`, `created_at`, `updated_at`) VALUES ('3', 'Load Functional Classes', '', '2026-07-31', 'overdue', '2026-07-31 06:14:52', '2026-08-01 09:35:08');
INSERT INTO `tasks` (`id`, `title`, `description`, `due_date`, `status`, `created_at`, `updated_at`) VALUES ('4', 'Load Natural Classes', '', '2026-07-31', 'overdue', '2026-07-31 06:15:20', '2026-08-01 09:35:10');
INSERT INTO `tasks` (`id`, `title`, `description`, `due_date`, `status`, `created_at`, `updated_at`) VALUES ('5', 'Import Transactions', '', '2026-07-31', 'overdue', '2026-07-31 06:15:40', '2026-08-01 09:35:07');
INSERT INTO `tasks` (`id`, `title`, `description`, `due_date`, `status`, `created_at`, `updated_at`) VALUES ('6', 'Add Transactions', '', '2026-07-31', 'overdue', '2026-07-31 06:16:10', '2026-08-01 09:35:06');

-- Table data: `transaction_details`
TRUNCATE TABLE `transaction_details`;

-- Table data: `transaction_documents`
TRUNCATE TABLE `transaction_documents`;

-- Table data: `transaction_events`
TRUNCATE TABLE `transaction_events`;

-- Table data: `transaction_lines`
TRUNCATE TABLE `transaction_lines`;

-- Table data: `user_roles`
TRUNCATE TABLE `user_roles`;
INSERT INTO `user_roles` (`user_id`, `role_id`, `is_primary`, `created_at`) VALUES ('1', '1', '1', '2026-07-26 23:16:40');

-- Table data: `users`
TRUNCATE TABLE `users`;
INSERT INTO `users` (`id`, `role_id`, `username`, `first_name`, `last_name`, `email`, `phone`, `password`, `is_active`, `must_change_password`, `force_password_set_at`, `custom_permissions`, `last_login`, `archived_at`, `created_at`, `updated_at`) VALUES ('1', '1', 'admin', 'Admin', 'User', 'admin@church.org', NULL, '$2y$12$hElsAKEKx9CLXDqzYsxEeOLXq2V7vr.OY1qgi2RjTq19MqWII.Ute', '1', '0', NULL, NULL, '2026-08-02 08:16:55', NULL, '2026-07-26 23:16:40', '2026-08-02 08:16:55');

SET FOREIGN_KEY_CHECKS = 1;
