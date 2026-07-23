-- Hope Baptist Treasurer Full Database Backup
-- Type: full (schema + data)
-- Generated: 2026-07-23 10:47:49 UTC
-- Database: temper_db

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `accounts`;
CREATE TABLE `accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `normal_balance` enum('debit','credit') NOT NULL,
  `coa_number` varchar(50) DEFAULT NULL,
  `natural_category_id` int(11) DEFAULT NULL,
  `functional_category_id` int(11) DEFAULT NULL,
  `archived` tinyint(1) DEFAULT 0,
  `archived_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `mutable_fund` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_accounts_normal_balance` (`normal_balance`),
  KEY `idx_accounts_archived` (`archived`),
  KEY `idx_accounts_natural_category_id` (`natural_category_id`),
  KEY `idx_accounts_functional_category_id` (`functional_category_id`),
  CONSTRAINT `fk_accounts_functional_category` FOREIGN KEY (`functional_category_id`) REFERENCES `functional_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_accounts_natural_category` FOREIGN KEY (`natural_category_id`) REFERENCES `natural_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `accounts` (`id`, `name`, `description`, `normal_balance`, `coa_number`, `natural_category_id`, `functional_category_id`, `archived`, `archived_at`, `created_at`, `mutable_fund`) VALUES ('1', 'Cash', 'Cash on hand', 'debit', '100200', '1', '1', '0', NULL, '2026-07-10 07:10:34', '1');
INSERT INTO `accounts` (`id`, `name`, `description`, `normal_balance`, `coa_number`, `natural_category_id`, `functional_category_id`, `archived`, `archived_at`, `created_at`, `mutable_fund`) VALUES ('2', 'FMB General Checking', 'Primary bank account', 'debit', '100100', '8', '4', '0', NULL, '2026-07-10 07:10:34', '1');
INSERT INTO `accounts` (`id`, `name`, `description`, `normal_balance`, `coa_number`, `natural_category_id`, `functional_category_id`, `archived`, `archived_at`, `created_at`, `mutable_fund`) VALUES ('3', 'Accounts Receivable', 'Amounts owed to the church', 'debit', NULL, '3', '3', '1', NULL, '2026-07-10 07:10:34', '1');
INSERT INTO `accounts` (`id`, `name`, `description`, `normal_balance`, `coa_number`, `natural_category_id`, `functional_category_id`, `archived`, `archived_at`, `created_at`, `mutable_fund`) VALUES ('4', 'Prepaid Expenses', 'Prepaid expenses such as insurance', 'debit', NULL, '4', '4', '1', NULL, '2026-07-10 07:10:34', '1');
INSERT INTO `accounts` (`id`, `name`, `description`, `normal_balance`, `coa_number`, `natural_category_id`, `functional_category_id`, `archived`, `archived_at`, `created_at`, `mutable_fund`) VALUES ('5', 'Fixed Assets', 'Property, equipment, and other fixed assets', 'debit', NULL, '3', '7', '1', NULL, '2026-07-10 07:10:34', '1');
INSERT INTO `accounts` (`id`, `name`, `description`, `normal_balance`, `coa_number`, `natural_category_id`, `functional_category_id`, `archived`, `archived_at`, `created_at`, `mutable_fund`) VALUES ('6', 'Accounts Payable', 'Amounts owed to others', 'credit', NULL, '3', '4', '1', NULL, '2026-07-10 07:10:34', '1');
INSERT INTO `accounts` (`id`, `name`, `description`, `normal_balance`, `coa_number`, `natural_category_id`, `functional_category_id`, `archived`, `archived_at`, `created_at`, `mutable_fund`) VALUES ('7', 'Accrued Expenses', 'Expenses that have been incurred but not yet paid', 'credit', NULL, '3', '4', '1', NULL, '2026-07-10 07:10:34', '1');
INSERT INTO `accounts` (`id`, `name`, `description`, `normal_balance`, `coa_number`, `natural_category_id`, `functional_category_id`, `archived`, `archived_at`, `created_at`, `mutable_fund`) VALUES ('8', 'Unearned Revenue', 'Revenue received in advance', 'credit', NULL, '1', '6', '1', NULL, '2026-07-10 07:10:34', '1');
INSERT INTO `accounts` (`id`, `name`, `description`, `normal_balance`, `coa_number`, `natural_category_id`, `functional_category_id`, `archived`, `archived_at`, `created_at`, `mutable_fund`) VALUES ('9', 'Retained Earnings', 'Cumulative earnings of the church', 'credit', NULL, '3', '4', '1', NULL, '2026-07-10 07:10:34', '0');
INSERT INTO `accounts` (`id`, `name`, `description`, `normal_balance`, `coa_number`, `natural_category_id`, `functional_category_id`, `archived`, `archived_at`, `created_at`, `mutable_fund`) VALUES ('10', 'General Contributions', 'Donations received', 'credit', '900100', '1', '6', '0', NULL, '2026-07-10 07:10:34', '1');
INSERT INTO `accounts` (`id`, `name`, `description`, `normal_balance`, `coa_number`, `natural_category_id`, `functional_category_id`, `archived`, `archived_at`, `created_at`, `mutable_fund`) VALUES ('13', 'Utilities:Electric', 'Power Bill', 'credit', '500610', '8', '5', '0', NULL, '2026-07-23 06:05:18', '0');

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
) ENGINE=InnoDB AUTO_INCREMENT=110 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('1', '1', 'admin', 'ledger.updated', 'transaction=34 Manual transaction updated. {\"debits\":58.75,\"credits\":58.75}', '::1', '2026-07-10 07:12:34');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('2', '1', 'admin', 'ledger.updated', 'transaction=34 Manual transaction updated. {\"debits\":58.75,\"credits\":58.75}', '::1', '2026-07-10 07:13:05');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('3', '1', 'admin', 'ledger.updated', 'transaction=34 Manual transaction updated. {\"debits\":58.75,\"credits\":58.75}', '::1', '2026-07-10 07:13:13');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('4', '1', 'admin', 'ledger.updated', 'transaction=34 Manual transaction updated. {\"debits\":58.75,\"credits\":58.75}', '::1', '2026-07-10 07:16:43');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('5', '1', 'admin', 'ledger.document_uploaded', 'transaction=34 Document uploaded: CityOfNashville.receipt.pdf {\"doc_id\":5}', '::1', '2026-07-10 07:26:07');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('6', '1', 'admin', 'ledger.created', 'transaction=35 Manual transaction created. {\"debits\":23,\"credits\":23}', '::1', '2026-07-10 07:27:17');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('7', '1', 'admin', 'ledger.document_uploaded', 'transaction=35 Document uploaded: CityOfNashville.receipt.pdf {\"doc_id\":6}', '::1', '2026-07-10 07:27:31');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('8', '1', 'admin', 'ledger.document_uploaded', 'transaction=35 Document uploaded: image-1.jpg {\"doc_id\":7}', '::1', '2026-07-10 07:34:30');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('9', '1', 'admin', 'ledger.document_uploaded', 'transaction=34 Document uploaded: image-4.jpg {\"doc_id\":8}', '::1', '2026-07-10 07:34:50');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('10', '1', 'admin', 'ledger.document_deleted', 'transaction=35 Document deleted: image-1.jpg {\"doc_id\":7}', '::1', '2026-07-10 07:56:48');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('11', '1', 'admin', 'ledger.document_uploaded', 'transaction=35 Document uploaded: image-1.jpg {\"doc_id\":10}', '::1', '2026-07-10 08:03:41');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('12', '1', 'admin', 'ledger.updated', 'transaction=35 Manual transaction updated. {\"debits\":23,\"credits\":23}', '::1', '2026-07-10 08:03:46');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('13', '1', 'admin', 'ledger.updated', 'transaction=35 Manual transaction updated. {\"debits\":24,\"credits\":24}', '::1', '2026-07-10 08:04:00');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('14', NULL, 'system', 'ledger.updated', 'transaction=35 Transaction saved with no field changes (re-saved). {\"debits\":24,\"credits\":24,\"changes\":[]}', '::1', '2026-07-10 13:43:21');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('15', '1', 'admin', 'ledger.updated', 'transaction=35 Transaction saved with no field changes (re-saved). {\"debits\":24,\"credits\":24,\"changes\":[]}', '::1', '2026-07-10 13:44:00');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('16', '1', 'admin', 'ledger.document_deleted', 'transaction=35 Attachment \"image-1.jpg\" removed. {\"doc_id\":10,\"original_filename\":\"image-1.jpg\"}', '::1', '2026-07-10 13:44:00');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('17', '1', 'admin', 'ledger.draft_created', 'transaction=36 Contribution draft created by first teller totaling $100.00. {\"grand_total\":100}', '::1', '2026-07-10 16:58:46');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('18', '1', 'admin', 'workflow.created', 'instance=1 Workflow created: Sunday Offering — 2026-07-10 {\"workflow_type\":\"contribution\",\"status\":\"draft_pending_second_count\",\"transaction_detail_id\":36}', '::1', '2026-07-10 16:58:46');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('19', '1', 'admin', 'workflow.step_completed', 'instance=1 Teller count saved; pending second teller verification. {\"status\":\"draft_pending_second_count\",\"transaction_detail_id\":36}', '::1', '2026-07-10 16:58:46');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('20', '1', 'admin', 'ledger.document_uploaded', 'transaction=36 Attachment \"image-3.jpg\" added. {\"doc_id\":11,\"workflow_instance_id\":1,\"original_filename\":\"image-3.jpg\"}', '::1', '2026-07-10 16:58:58');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('21', '1', 'admin', 'workflow.document_uploaded', 'instance=1 Attachment \"image-3.jpg\" added to ledger #36. {\"doc_id\":11,\"transaction_detail_id\":36,\"original_filename\":\"image-3.jpg\"}', '::1', '2026-07-10 16:58:58');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('22', '2', 'finance', 'ledger.second_teller_signed', 'transaction=36 Second teller Finance Manager signed off on dual count ($100.00 cash verified). {\"second_teller_id\":2,\"second_teller_name\":\"Finance Manager\",\"verify_cash_total\":100}', '::1', '2026-07-10 16:59:58');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('23', '2', 'finance', 'workflow.signed', 'instance=1 Second teller signed off on dual count. {\"second_teller_id\":2,\"transaction_detail_id\":36}', '::1', '2026-07-10 16:59:58');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('24', '1', 'admin', 'ledger.validated', 'transaction=36 Transaction validated by Admin User. {\"validated_by_user_id\":1,\"validated_by_name\":\"Admin User\"}', '::1', '2026-07-10 17:00:41');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('25', '1', 'admin', 'ledger.deposit_finalized', 'transaction=36 Contribution deposit of $100.00 finalized by Admin User. {\"workflow_instance_id\":1,\"amount\":100,\"finalized_by\":\"Admin User\"}', '::1', '2026-07-10 17:00:41');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('26', '1', 'admin', 'workflow.deposit_created', 'instance=1 Ledger deposit #36 finalized from contribution workflow. {\"transaction_id\":36,\"amount\":100}', '::1', '2026-07-10 17:00:41');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('27', '1', 'admin', 'ledger.document_uploaded', 'transaction=35 Attachment \"image-3.jpg\" added. {\"doc_id\":12,\"original_filename\":\"image-3.jpg\"}', '::1', '2026-07-10 17:40:45');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('28', '1', 'admin', 'ledger.updated', 'transaction=35 Transaction saved with no field changes (re-saved). {\"debits\":24,\"credits\":24,\"changes\":[]}', '::1', '2026-07-10 17:40:47');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('29', '1', 'admin', 'ledger.document_uploaded', 'transaction=35 Attachment \"17837764175673659541700467166883.jpg\" added. {\"doc_id\":13,\"original_filename\":\"17837764175673659541700467166883.jpg\"}', '192.168.254.5', '2026-07-11 09:27:02');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('30', '1', 'admin', 'user_create', 'smoke test', NULL, '2026-07-13 08:33:42');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('31', '1', 'admin', 'user_create', 'Created user id=6 username=apitest1882 role=Board Member', '127.0.0.1', '2026-07-13 08:34:13');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('32', '1', 'admin', 'user_create', 'Created user id=9 username=apiu2682 roles=[Board Member, Teller] must_change=1 custom_perms=1', '127.0.0.1', '2026-07-13 09:34:28');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('33', '1', 'admin', 'role_create', 'Created role id=11 name=Temp Auditor perms=3', '127.0.0.1', '2026-07-13 09:34:36');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('34', '1', 'admin', 'role_update', 'Updated role id=11 name=Temp Auditor perms=2', '127.0.0.1', '2026-07-13 09:34:36');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('35', '1', 'admin', 'user_archive', 'Archived user id=3 username=member', '127.0.0.1', '2026-07-13 09:34:36');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('36', '1', 'admin', 'user_activate', 'Restored user id=3 username=member', '127.0.0.1', '2026-07-13 09:34:36');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('37', '1', 'admin', 'role_delete', 'Deleted role id=11 name=Temp Auditor', '127.0.0.1', '2026-07-13 09:34:36');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('38', '1', 'admin', 'user_archive', 'Archived user id=2 username=finance', '::1', '2026-07-15 06:08:58');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('39', '1', 'admin', 'user_archive', 'Archived user id=3 username=member', '::1', '2026-07-15 06:09:02');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('40', '1', 'admin', 'user_create', 'Created user id=10 username=jakadair roles=[Financial Secretary] must_change=1 custom_perms=0', '::1', '2026-07-15 06:09:56');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('41', '1', 'admin', 'user_create', 'Created user id=11 username=heathet roles=[Board Member] must_change=1 custom_perms=0', '::1', '2026-07-15 06:10:53');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('42', '1', 'admin', 'user_create', 'Created user id=12 username=blueelephant roles=[Board Member] must_change=1 custom_perms=0', '::1', '2026-07-15 06:11:45');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('43', '1', 'admin', 'user_update', 'Updated user id=11 username=heathet roles=[2] must_change=1 custom_perms=0', '::1', '2026-07-15 06:12:59');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('44', '10', 'jakadair', 'force_password_change', 'User completed required password change', '::1', '2026-07-15 06:27:33');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('45', NULL, 'system', 'user_auto_archive', 'Auto-archived user id=13 username=stale666: must_change_password not completed within 24h of creation', NULL, '2026-07-15 06:40:13');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('46', '1', 'admin', 'profile_password_change_failed', 'Incorrect current password', NULL, '2026-07-15 06:40:13');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('47', '15', 'fcpw279', 'force_password_change', 'User completed required password change', NULL, '2026-07-15 06:40:13');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('48', '1', 'admin', 'user_create', 'Created user id=16 username=testuser1 roles=[Teller] must_change=1 custom_perms=0', '::1', '2026-07-15 07:11:52');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('49', '1', 'admin', 'user_create', 'Created user id=17 username=testuser2 roles=[Teller] must_change=1 custom_perms=0', '::1', '2026-07-15 07:12:25');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('50', '1', 'admin', 'user_create', 'Created user id=18 username=testuser3 roles=[Second Teller] must_change=1 custom_perms=0', '::1', '2026-07-15 07:14:25');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('51', '1', 'admin', 'user_create', 'Created user id=20 username=testuser4 roles=[Teller] must_change=1 custom_perms=0', '::1', '2026-07-15 07:14:53');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('52', '20', 'testuser4', 'force_password_change', 'User completed required password change', '::1', '2026-07-15 07:16:12');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('53', NULL, 'system', 'user_auto_archive', 'Auto-archived user id=11 username=heathet: must_change_password not completed within 24h of creation', '::1', '2026-07-16 07:02:35');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('54', NULL, 'system', 'user_auto_archive', 'Auto-archived user id=12 username=blueelephant: must_change_password not completed within 24h of creation', '::1', '2026-07-16 07:02:35');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('55', NULL, 'system', 'user_auto_archive', 'Auto-archived user id=16 username=testuser1: must_change_password not completed within 24h of creation', '::1', '2026-07-16 08:07:39');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('56', NULL, 'system', 'user_auto_archive', 'Auto-archived user id=17 username=testuser2: must_change_password not completed within 24h of creation', '::1', '2026-07-16 08:07:39');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('57', NULL, 'system', 'user_auto_archive', 'Auto-archived user id=18 username=testuser3: must_change_password not completed within 24h of creation', '::1', '2026-07-16 08:07:39');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('58', '1', 'admin', 'workflow.definition.import', 'example_minimal v1 checksum=e8d4bf7139a5b4415ca5c47e6aaf95e349a954f0dafcdbe942b084ddccee1471', NULL, '2026-07-17 07:33:05');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('59', '1', 'admin', 'workflow.created', 'instance=1 Workflow started: Test instance', NULL, '2026-07-17 07:33:05');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('60', '1', 'admin', 'workflow.step_started', 'instance=1 Step started: enter_basics', NULL, '2026-07-17 07:33:05');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('61', '1', 'admin', 'workflow.input', 'instance=1 Input applied on step enter_basics', NULL, '2026-07-17 07:33:05');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('62', '1', 'admin', 'workflow.validation', 'instance=1 Validation passed for step enter_basics', NULL, '2026-07-17 07:33:05');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('63', '1', 'admin', 'workflow.step_completed', 'instance=1 Step completed: enter_basics', NULL, '2026-07-17 07:33:05');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('64', '1', 'admin', 'workflow.action', 'instance=1 Action: log_event', NULL, '2026-07-17 07:33:05');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('65', '1', 'admin', 'workflow.routing', 'instance=1 Routed enter_basics → enter_details', NULL, '2026-07-17 07:33:05');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('66', '1', 'admin', 'workflow.step_started', 'instance=1 Step started: enter_details', NULL, '2026-07-17 07:33:05');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('67', '1', 'admin', 'workflow.validation', 'instance=1 Validation passed for step enter_details', NULL, '2026-07-17 07:33:05');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('68', '1', 'admin', 'workflow.step_completed', 'instance=1 Step completed: enter_details', NULL, '2026-07-17 07:33:05');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('69', '1', 'admin', 'workflow.action', 'instance=1 Action: validate_totals', NULL, '2026-07-17 07:33:05');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('70', '1', 'admin', 'workflow.routing', 'instance=1 Routed enter_details → review_and_finish', NULL, '2026-07-17 07:33:05');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('71', '1', 'admin', 'workflow.step_started', 'instance=1 Step started: review_and_finish', NULL, '2026-07-17 07:33:05');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('72', '1', 'admin', 'workflow.validation', 'instance=1 Validation passed for step review_and_finish', NULL, '2026-07-17 07:33:05');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('73', '1', 'admin', 'workflow.step_completed', 'instance=1 Step completed: review_and_finish', NULL, '2026-07-17 07:33:05');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('74', '1', 'admin', 'workflow.action', 'instance=1 Action: require_signature', NULL, '2026-07-17 07:33:05');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('75', '1', 'admin', 'workflow.action', 'instance=1 Action: set_status', NULL, '2026-07-17 07:33:05');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('76', '1', 'admin', 'workflow.action', 'instance=1 Action: log_event', NULL, '2026-07-17 07:33:05');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('77', '1', 'admin', 'workflow.completed', 'instance=1 Workflow completed', NULL, '2026-07-17 07:33:05');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('78', '1', 'admin', 'user_update', 'Updated user id=10 username=jakadair roles=[8,9,2,7,6] must_change=0 custom_perms=0', '::1', '2026-07-18 22:43:01');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('79', '1', 'admin', 'system_config_update', 'Updated: developer_mode=true', '::1', '2026-07-19 08:22:16');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('80', '1', 'admin', 'system_config_update', 'Updated: developer_mode=false', '::1', '2026-07-19 08:22:39');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('81', '1', 'admin', 'user_activate', 'Restored user id=12 username=blueelephant', '::1', '2026-07-19 08:22:57');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('82', NULL, 'system', 'user_auto_archive', 'Auto-archived user id=12 username=blueelephant: must_change_password not completed within 24h of creation', '::1', '2026-07-19 08:23:04');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('83', '1', 'admin', 'user_activate', 'Restored user id=11 username=heathet', '::1', '2026-07-19 08:23:04');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('84', NULL, 'system', 'user_auto_archive', 'Auto-archived user id=11 username=heathet: must_change_password not completed within 24h of creation', '::1', '2026-07-19 08:23:13');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('85', '1', 'admin', 'user_activate', 'Restored user id=12 username=blueelephant', '::1', '2026-07-19 08:23:13');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('86', NULL, 'system', 'user_auto_archive', 'Auto-archived user id=12 username=blueelephant: must_change_password not completed within 24h of creation', '::1', '2026-07-19 08:23:17');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('87', '1', 'admin', 'user_activate', 'Restored user id=11 username=heathet', '::1', '2026-07-19 08:23:25');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('88', NULL, 'system', 'user_auto_archive', 'Auto-archived user id=11 username=heathet: must_change_password not completed within 24h of creation', '::1', '2026-07-19 08:23:28');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('89', NULL, 'system', 'user_auto_archive', 'Auto-archived user id=11 username=heathet: must_change_password not completed within 24h of creation', NULL, '2026-07-19 08:25:46');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('90', '1', 'admin', 'user_activate', 'Restored user id=12 username=blueelephant (force-password grace restarted)', '::1', '2026-07-19 08:30:16');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('91', '1', 'admin', 'user_activate', 'Restored user id=11 username=heathet (force-password grace restarted)', '::1', '2026-07-19 08:30:19');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('92', '1', 'admin', 'user_archive', 'Archived user id=12 username=blueelephant', '::1', '2026-07-19 08:37:42');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('93', '1', 'admin', 'user_activate', 'Restored user id=12 username=blueelephant (force-password grace restarted)', '::1', '2026-07-19 08:37:56');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('94', '1', 'admin', 'ledger.created', 'transaction=37 Transaction created for \"543\" totaling $200.00 (2 lines). {\"debits\":200,\"credits\":200,\"changes\":[\"Transaction created for \\\"543\\\" totaling $200.00 (2 lines).\",\"Fund \\\"General Operating Fund\\\" $400.00.\"]}', '::1', '2026-07-20 07:06:58');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('95', NULL, 'system', 'user_auto_archive', 'Auto-archived user id=11 username=heathet: must_change_password not completed within 24h of force-password set', '::1', '2026-07-21 05:52:53');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('96', NULL, 'system', 'user_auto_archive', 'Auto-archived user id=12 username=blueelephant: must_change_password not completed within 24h of force-password set', '::1', '2026-07-21 05:52:53');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('97', '1', 'admin', 'ledger.updated', 'transaction=37 Budget changed from \"—\" to \"2026 - test2\". {\"debits\":200,\"credits\":200,\"changes\":[\"Budget changed from \\\"—\\\" to \\\"2026 - test2\\\".\"]}', '::1', '2026-07-21 06:58:23');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('98', '1', 'admin', 'ledger.updated', 'transaction=36 Transaction updated (2 changes). {\"debits\":100,\"credits\":100,\"changes\":[\"Reference # changed from \\\"WF-CONTRIB-1\\\" to \\\"263433\\\".\",\"Budget changed from \\\"—\\\" to \\\"2026 - d\\\".\"]}', '::1', '2026-07-21 06:58:51');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('99', '1', 'admin', 'ledger.updated', 'transaction=28 Budget changed from \"—\" to \"2026 - d\". {\"budget_only\":true,\"status\":\"cleared\",\"changes\":[\"Budget changed from \\\"—\\\" to \\\"2026 - d\\\".\"]}', '::1', '2026-07-21 07:08:48');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('100', '1', 'admin', 'system_config_update', 'Updated: login_timeout_seconds=30', '::1', '2026-07-22 06:11:22');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('101', '1', 'admin', 'system_config_update', 'Updated: login_timeout_seconds=300', '::1', '2026-07-22 06:12:19');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('102', '1', 'admin', 'system_config_update', 'Updated: developer_mode=true', '::1', '2026-07-22 06:29:01');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('103', '1', 'admin', 'system_config_update', 'Updated: developer_mode=false', '::1', '2026-07-22 06:29:07');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('104', '1', 'admin', 'system_config_update', 'Updated: developer_mode=true', '::1', '2026-07-22 06:32:20');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('105', '1', 'admin', 'system_config_update', 'Updated: login_timeout_seconds=30', '::1', '2026-07-22 07:02:39');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('106', '1', 'admin', 'system_config_update', 'Updated: login_timeout_seconds=600', '::1', '2026-07-22 07:04:03');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('107', '1', 'admin', 'system_config_update', 'Updated: login_timeout_disabled=true', '::1', '2026-07-22 07:04:08');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('108', '1', 'admin', 'ledger.updated', 'transaction=35 Transaction updated (3 changes). {\"debits\":24,\"credits\":24,\"changes\":[\"Reference # changed from \\\"23221\\\" to \\\"263434\\\".\",\"Added Fund \\\"General Operating Fund\\\" (Utilities:Electric) credit $24.00.\",\"Removed Fund \\\"General Operating Fund\\\" (Accounts Payable) credit $24.00.\"]}', '::1', '2026-07-23 06:15:05');
INSERT INTO `audit_log` (`id`, `user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES ('109', '1', 'admin', 'ledger.created', 'transaction=40 Transaction created for \"DEPOSIT\" totaling $1,850.00 (3 lines). {\"debits\":1850,\"credits\":1850,\"changes\":[\"Transaction created for \\\"DEPOSIT\\\" totaling $1,850.00 (3 lines).\",\"Fund \\\"General Operating Fund\\\" $1,750.00.\",\"Fund \\\"Benevolence Fund\\\" $100.00.\"]}', '::1', '2026-07-23 06:19:43');

DROP TABLE IF EXISTS `budget_lines`;
CREATE TABLE `budget_lines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `budget_id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `budgeted_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_budget_lines_budget_id` (`budget_id`),
  KEY `idx_budget_lines_account_id` (`account_id`),
  KEY `idx_budget_lines_budgeted_amount` (`budgeted_amount`),
  CONSTRAINT `budget_lines_ibfk_1` FOREIGN KEY (`budget_id`) REFERENCES `budgets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_budget_lines_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `budget_lines` (`id`, `budget_id`, `account_id`, `budgeted_amount`, `notes`, `created_at`, `updated_at`) VALUES ('1', '1', '1', '200000.00', 'Contributions for worship and programs', '2026-07-10 07:10:35', '2026-07-10 07:10:35');
INSERT INTO `budget_lines` (`id`, `budget_id`, `account_id`, `budgeted_amount`, `notes`, `created_at`, `updated_at`) VALUES ('2', '1', '2', '150000.00', 'Program expenses', '2026-07-10 07:10:35', '2026-07-10 07:10:35');
INSERT INTO `budget_lines` (`id`, `budget_id`, `account_id`, `budgeted_amount`, `notes`, `created_at`, `updated_at`) VALUES ('3', '1', '3', '50000.00', 'Administrative expenses', '2026-07-10 07:10:35', '2026-07-10 07:10:35');
INSERT INTO `budget_lines` (`id`, `budget_id`, `account_id`, `budgeted_amount`, `notes`, `created_at`, `updated_at`) VALUES ('4', '1', '4', '100000.00', 'Capital expenditures', '2026-07-10 07:10:35', '2026-07-10 07:10:35');
INSERT INTO `budget_lines` (`id`, `budget_id`, `account_id`, `budgeted_amount`, `notes`, `created_at`, `updated_at`) VALUES ('5', '2', '5', '20.00', '', '2026-07-20 06:30:53', '2026-07-20 06:30:53');
INSERT INTO `budget_lines` (`id`, `budget_id`, `account_id`, `budgeted_amount`, `notes`, `created_at`, `updated_at`) VALUES ('6', '3', '2', '20.00', '', '2026-07-20 06:32:10', '2026-07-20 06:32:10');
INSERT INTO `budget_lines` (`id`, `budget_id`, `account_id`, `budgeted_amount`, `notes`, `created_at`, `updated_at`) VALUES ('9', '5', '7', '33.33', '', '2026-07-21 06:12:34', '2026-07-21 06:12:34');
INSERT INTO `budget_lines` (`id`, `budget_id`, `account_id`, `budgeted_amount`, `notes`, `created_at`, `updated_at`) VALUES ('11', '6', '13', '22000.00', '', '2026-07-23 06:07:40', '2026-07-23 06:07:40');

DROP TABLE IF EXISTS `budgets`;
CREATE TABLE `budgets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fiscal_year` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `approved_date` date DEFAULT NULL,
  `reference_number` varchar(50) DEFAULT NULL,
  `status` enum('draft','approved','active','closed') DEFAULT 'draft',
  `total_budgeted` decimal(15,2) DEFAULT 0.00,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_budgets_fiscal_year` (`fiscal_year`),
  KEY `idx_budgets_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `budgets` (`id`, `fiscal_year`, `name`, `start_date`, `end_date`, `approved_date`, `reference_number`, `status`, `total_budgeted`, `description`, `created_at`, `updated_at`) VALUES ('1', '2024', '2024 Church Budget', '2024-01-01', '2024-12-31', '2023-12-15', '2023-12-15-001', 'active', '500000.00', 'Annual church budget for 2024', '2026-07-10 07:10:35', '2026-07-10 07:10:35');
INSERT INTO `budgets` (`id`, `fiscal_year`, `name`, `start_date`, `end_date`, `approved_date`, `reference_number`, `status`, `total_budgeted`, `description`, `created_at`, `updated_at`) VALUES ('2', '2025', 'test', '2025-01-01', '2025-12-31', '2024-09-01', '23232', 'active', '20.00', '', '2026-07-20 06:30:53', '2026-07-20 06:31:29');
INSERT INTO `budgets` (`id`, `fiscal_year`, `name`, `start_date`, `end_date`, `approved_date`, `reference_number`, `status`, `total_budgeted`, `description`, `created_at`, `updated_at`) VALUES ('3', '2026', 'test2', '2026-01-01', '2026-12-31', '2025-09-01', '23221', 'closed', '20.00', '', '2026-07-20 06:32:10', '2026-07-23 06:22:41');
INSERT INTO `budgets` (`id`, `fiscal_year`, `name`, `start_date`, `end_date`, `approved_date`, `reference_number`, `status`, `total_budgeted`, `description`, `created_at`, `updated_at`) VALUES ('5', '2026', 'd', '2026-01-01', '2026-12-31', '2026-07-21', '434', 'closed', '33.33', 'General Operating Budget for the year', '2026-07-21 06:11:51', '2026-07-23 06:22:26');
INSERT INTO `budgets` (`id`, `fiscal_year`, `name`, `start_date`, `end_date`, `approved_date`, `reference_number`, `status`, `total_budgeted`, `description`, `created_at`, `updated_at`) VALUES ('6', '2026', 'CY2026', '2026-01-01', '2027-12-31', NULL, '269100', 'draft', '22000.00', 'Standard Budget for Calendar Year 2026', '2026-07-21 06:15:17', '2026-07-23 06:07:40');

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
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `functional_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('1', 'Worship', 'Expenses related to worship services', '0', NULL, '2026-07-10 07:10:34');
INSERT INTO `functional_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('2', 'Education', 'Expenses related to educational programs', '0', NULL, '2026-07-10 07:10:34');
INSERT INTO `functional_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('3', 'Community Outreach', 'Expenses related to community outreach', '0', NULL, '2026-07-10 07:10:34');
INSERT INTO `functional_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('4', 'Finance', 'Expenses related to financial operations', '0', NULL, '2026-07-10 07:10:34');
INSERT INTO `functional_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('5', 'Facilities', 'Expenses related to facilities maintenance', '0', NULL, '2026-07-10 07:10:34');
INSERT INTO `functional_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('6', 'Stewardship', 'Expenses related to stewardship and giving', '0', NULL, '2026-07-10 07:10:34');
INSERT INTO `functional_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('7', 'Leadership', 'Expenses related to leadership development', '0', NULL, '2026-07-10 07:10:34');

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
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `funds` (`id`, `name`, `code`, `type`, `current_balance`, `description`, `purpose`, `donor_reference`, `is_active`, `archived`, `archived_at`, `created_at`, `updated_at`) VALUES ('1', 'General Operating Fund', 'GOF', 'WODR', '0.00', 'Main operating fund for general church activities', 'General church operations', NULL, '1', '0', NULL, '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `funds` (`id`, `name`, `code`, `type`, `current_balance`, `description`, `purpose`, `donor_reference`, `is_active`, `archived`, `archived_at`, `created_at`, `updated_at`) VALUES ('2', 'Missions Fund', 'MF', 'WDR', '0.00', 'Donor-restricted funds for missionary work', 'Mission work', NULL, '1', '0', NULL, '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `funds` (`id`, `name`, `code`, `type`, `current_balance`, `description`, `purpose`, `donor_reference`, `is_active`, `archived`, `archived_at`, `created_at`, `updated_at`) VALUES ('3', 'Benevolence Fund', 'BF', 'WDR', '0.00', 'Donor-restricted funds for assistance to members in need', 'Member assistance', NULL, '1', '0', NULL, '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `funds` (`id`, `name`, `code`, `type`, `current_balance`, `description`, `purpose`, `donor_reference`, `is_active`, `archived`, `archived_at`, `created_at`, `updated_at`) VALUES ('4', 'Building Fund', 'BLD', 'WDR', '0.00', 'Donor-restricted funds for church building projects', 'Building projects', NULL, '1', '0', NULL, '2026-07-10 07:10:34', '2026-07-10 07:10:34');

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
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `natural_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('1', 'Contributions', 'Donations and offerings', '0', NULL, '2026-07-10 07:10:34');
INSERT INTO `natural_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('2', 'Program', 'Expenses for church programs', '0', NULL, '2026-07-10 07:10:34');
INSERT INTO `natural_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('3', 'Administrative', 'Administrative expenses', '0', NULL, '2026-07-10 07:10:34');
INSERT INTO `natural_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('4', 'Capital Expenditure', 'Purchases of equipment or improvement', '0', NULL, '2026-07-10 07:10:34');
INSERT INTO `natural_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('5', 'Events', 'Expenses related to church events', '0', NULL, '2026-07-10 07:10:34');
INSERT INTO `natural_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('6', 'Salaries', 'Employee salaries and wages', '0', NULL, '2026-07-10 07:10:34');
INSERT INTO `natural_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('7', 'Benefits', 'Employee benefits', '0', NULL, '2026-07-10 07:10:34');
INSERT INTO `natural_categories` (`id`, `name`, `description`, `archived`, `archived_at`, `created_at`) VALUES ('8', 'Operating', 'General operating expenses', '0', NULL, '2026-07-10 07:10:34');

DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `permissions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`permissions`)),
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `roles` (`id`, `name`, `description`, `permissions`, `is_system`, `created_at`, `updated_at`) VALUES ('1', 'Administrator', 'System administrator with full access', '[\"*\"]', '1', '2026-07-10 07:10:35', '2026-07-13 09:34:08');
INSERT INTO `roles` (`id`, `name`, `description`, `permissions`, `is_system`, `created_at`, `updated_at`) VALUES ('2', 'Finance Manager', 'Finance manager with access to financial data and budgets', '[\"page.dashboard\",\"page.ledger\",\"page.ledger.write\",\"page.reports\",\"page.budget\",\"page.budget.write\",\"page.tasks\",\"admin.access\",\"admin.lookups\",\"profile.self\"]', '1', '2026-07-10 07:10:35', '2026-07-18 22:05:36');
INSERT INTO `roles` (`id`, `name`, `description`, `permissions`, `is_system`, `created_at`, `updated_at`) VALUES ('3', 'Member', 'Limited member access (profile only by default)', '[\"profile.self\"]', '1', '2026-07-10 07:10:35', '2026-07-13 09:34:08');
INSERT INTO `roles` (`id`, `name`, `description`, `permissions`, `is_system`, `created_at`, `updated_at`) VALUES ('6', 'Treasurer', 'Church treasurer — full financial operations and official approvals', '[\"page.dashboard\",\"page.ledger\",\"page.ledger.write\",\"page.reports\",\"page.budget\",\"page.budget.write\",\"page.tasks\",\"admin.access\",\"admin.backup\",\"admin.lookups\",\"profile.self\"]', '1', '2026-07-10 07:10:35', '2026-07-18 22:05:36');
INSERT INTO `roles` (`id`, `name`, `description`, `permissions`, `is_system`, `created_at`, `updated_at`) VALUES ('7', 'Financial Secretary', 'Financial secretary — deposits, official contribution validation', '[\"page.dashboard\",\"page.ledger\",\"page.ledger.write\",\"page.reports\",\"page.budget\",\"page.tasks\",\"admin.access\",\"admin.lookups\",\"profile.self\"]', '1', '2026-07-10 07:10:35', '2026-07-18 22:05:36');
INSERT INTO `roles` (`id`, `name`, `description`, `permissions`, `is_system`, `created_at`, `updated_at`) VALUES ('8', 'Archivist', 'Historical data import only (no current-year Treasurer duties)', '[\"page.dashboard\",\"page.ledger\",\"page.reports\",\"page.budget\",\"admin.access\",\"admin.lookups\",\"archive.import\",\"profile.self\"]', '1', '2026-07-13 08:33:16', '2026-07-13 09:34:08');
INSERT INTO `roles` (`id`, `name`, `description`, `permissions`, `is_system`, `created_at`, `updated_at`) VALUES ('9', 'Board Member', 'Read-only access to dashboard, reports, and budgets', '[\"page.dashboard\",\"page.reports\",\"page.budget\",\"profile.self\"]', '1', '2026-07-13 08:33:16', '2026-07-13 09:34:08');

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
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tasks` (`id`, `title`, `description`, `due_date`, `status`, `created_at`, `updated_at`) VALUES ('8', 'test', 'test task', '2026-07-17', 'due_soon', '2026-07-12 09:21:47', '2026-07-12 09:21:47');

DROP TABLE IF EXISTS `transaction_details`;
CREATE TABLE `transaction_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_date` date NOT NULL,
  `cleared_date` date DEFAULT NULL,
  `check_number` varchar(20) DEFAULT NULL,
  `pay_to` varchar(255) DEFAULT NULL,
  `memo` text DEFAULT NULL,
  `reference_number` varchar(50) DEFAULT NULL,
  `budget_id` int(11) DEFAULT NULL,
  `status` enum('pending','cleared','reconciled') DEFAULT 'pending',
  `date_reconciled` date DEFAULT NULL,
  `created_by_user_id` int(11) DEFAULT NULL,
  `validated_by_user_id` int(11) DEFAULT NULL,
  `validated_at` datetime DEFAULT NULL,
  `transaction_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`transaction_data`)),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_transaction_details_date` (`transaction_date`),
  KEY `idx_transaction_details_status` (`status`),
  KEY `idx_transaction_details_reference` (`reference_number`),
  KEY `idx_transaction_details_budget_id` (`budget_id`),
  CONSTRAINT `fk_transaction_details_budget` FOREIGN KEY (`budget_id`) REFERENCES `budgets` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `budget_id`, `status`, `date_reconciled`, `created_by_user_id`, `validated_by_user_id`, `validated_at`, `transaction_data`, `created_at`, `updated_at`) VALUES ('1', '2025-01-05', '2025-01-06', NULL, 'Worship Service Offering', 'First Sunday tithes and offerings of the year', 'OFF-250105', NULL, 'cleared', NULL, NULL, NULL, NULL, NULL, '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `budget_id`, `status`, `date_reconciled`, `created_by_user_id`, `validated_by_user_id`, `validated_at`, `transaction_data`, `created_at`, `updated_at`) VALUES ('2', '2025-01-08', '2025-01-09', '1201', 'City Electric Co.', 'Monthly electric utility bill', NULL, NULL, 'cleared', NULL, NULL, NULL, NULL, NULL, '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `budget_id`, `status`, `date_reconciled`, `created_by_user_id`, `validated_by_user_id`, `validated_at`, `transaction_data`, `created_at`, `updated_at`) VALUES ('3', '2025-01-12', '2025-01-15', NULL, 'Global Missions Outreach', 'January support payment', 'MSN-202501', NULL, 'reconciled', '2025-02-01', NULL, NULL, NULL, NULL, '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `budget_id`, `status`, `date_reconciled`, `created_by_user_id`, `validated_by_user_id`, `validated_at`, `transaction_data`, `created_at`, `updated_at`) VALUES ('4', '2025-01-15', '2025-01-16', '1202', 'Metro Water Authority', 'Water and sewer services', NULL, NULL, 'cleared', NULL, NULL, NULL, NULL, NULL, '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `budget_id`, `status`, `date_reconciled`, `created_by_user_id`, `validated_by_user_id`, `validated_at`, `transaction_data`, `created_at`, `updated_at`) VALUES ('5', '2025-01-19', '2025-01-20', NULL, 'Worship Service Offering', 'Second Sunday of January', 'OFF-250119', NULL, 'cleared', NULL, NULL, NULL, NULL, NULL, '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `budget_id`, `status`, `date_reconciled`, `created_by_user_id`, `validated_by_user_id`, `validated_at`, `transaction_data`, `created_at`, `updated_at`) VALUES ('6', '2025-01-22', '2025-01-23', '1203', 'Office Depot', 'Office supplies, printer paper and ink', NULL, NULL, 'cleared', NULL, NULL, NULL, NULL, NULL, '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `budget_id`, `status`, `date_reconciled`, `created_by_user_id`, `validated_by_user_id`, `validated_at`, `transaction_data`, `created_at`, `updated_at`) VALUES ('7', '2025-01-25', '2025-01-27', '1204', 'Rev. Michael Thompson', 'Pastoral compensation - January', NULL, NULL, 'cleared', NULL, NULL, NULL, NULL, NULL, '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `budget_id`, `status`, `date_reconciled`, `created_by_user_id`, `validated_by_user_id`, `validated_at`, `transaction_data`, `created_at`, `updated_at`) VALUES ('8', '2025-01-28', '2025-01-29', NULL, 'Benevolence Assistance', 'Emergency housing support for member family', 'BEN-250128', NULL, 'cleared', NULL, NULL, NULL, NULL, NULL, '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `budget_id`, `status`, `date_reconciled`, `created_by_user_id`, `validated_by_user_id`, `validated_at`, `transaction_data`, `created_at`, `updated_at`) VALUES ('9', '2025-02-02', '2025-02-03', NULL, 'Worship Service Offering', 'February opening Sunday', 'OFF-250202', NULL, 'cleared', NULL, NULL, NULL, NULL, NULL, '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `budget_id`, `status`, `date_reconciled`, `created_by_user_id`, `validated_by_user_id`, `validated_at`, `transaction_data`, `created_at`, `updated_at`) VALUES ('10', '2025-02-05', '2025-02-06', NULL, 'Anonymous Donor', 'Designated gift for building repairs', 'BLD-250205', NULL, 'reconciled', '2025-02-20', NULL, NULL, NULL, NULL, '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `budget_id`, `status`, `date_reconciled`, `created_by_user_id`, `validated_by_user_id`, `validated_at`, `transaction_data`, `created_at`, `updated_at`) VALUES ('11', '2025-02-08', '2025-02-10', '1205', 'Acme Insurance Agency', 'Property and liability insurance premium', NULL, NULL, 'cleared', NULL, NULL, NULL, NULL, NULL, '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `budget_id`, `status`, `date_reconciled`, `created_by_user_id`, `validated_by_user_id`, `validated_at`, `transaction_data`, `created_at`, `updated_at`) VALUES ('12', '2025-02-09', '2025-02-11', NULL, 'Regional Youth Camp', 'Deposit for summer youth camp (10 campers)', 'EVT-2025YC', NULL, 'cleared', NULL, NULL, NULL, NULL, NULL, '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `budget_id`, `status`, `date_reconciled`, `created_by_user_id`, `validated_by_user_id`, `validated_at`, `transaction_data`, `created_at`, `updated_at`) VALUES ('13', '2025-02-12', '2025-02-13', NULL, 'Worship Service Offering', 'Cash and check offerings', 'OFF-250212', NULL, 'cleared', NULL, NULL, NULL, NULL, NULL, '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `budget_id`, `status`, `date_reconciled`, `created_by_user_id`, `validated_by_user_id`, `validated_at`, `transaction_data`, `created_at`, `updated_at`) VALUES ('14', '2025-02-15', '2025-02-17', '1206', 'Sparkle Clean Janitorial', 'Monthly cleaning services', NULL, NULL, 'cleared', NULL, NULL, NULL, NULL, NULL, '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `budget_id`, `status`, `date_reconciled`, `created_by_user_id`, `validated_by_user_id`, `validated_at`, `transaction_data`, `created_at`, `updated_at`) VALUES ('15', '2025-02-20', '2025-02-21', NULL, 'Missions Designated Gift', 'Smith family missions pledge', 'DON-MSN-15', NULL, 'cleared', NULL, NULL, NULL, NULL, NULL, '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `budget_id`, `status`, `date_reconciled`, `created_by_user_id`, `validated_by_user_id`, `validated_at`, `transaction_data`, `created_at`, `updated_at`) VALUES ('16', '2025-02-22', '2025-02-24', '1207', 'Green Thumb Landscaping', 'Lawn care and snow removal', NULL, NULL, 'cleared', NULL, NULL, NULL, NULL, NULL, '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `budget_id`, `status`, `date_reconciled`, `created_by_user_id`, `validated_by_user_id`, `validated_at`, `transaction_data`, `created_at`, `updated_at`) VALUES ('17', '2025-02-28', '2025-03-01', '1208', 'Rev. Michael Thompson', 'Pastoral compensation - February', NULL, NULL, 'cleared', NULL, NULL, NULL, NULL, NULL, '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `budget_id`, `status`, `date_reconciled`, `created_by_user_id`, `validated_by_user_id`, `validated_at`, `transaction_data`, `created_at`, `updated_at`) VALUES ('18', '2025-03-02', '2025-03-03', NULL, 'Worship Service Offering', 'First Sunday March', 'OFF-250302', NULL, 'cleared', NULL, NULL, NULL, NULL, NULL, '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `budget_id`, `status`, `date_reconciled`, `created_by_user_id`, `validated_by_user_id`, `validated_at`, `transaction_data`, `created_at`, `updated_at`) VALUES ('19', '2025-03-05', '2025-03-06', '1209', 'Faith Book & Supply', 'Sunday school and VBS curriculum', NULL, NULL, 'cleared', NULL, NULL, NULL, NULL, NULL, '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `budget_id`, `status`, `date_reconciled`, `created_by_user_id`, `validated_by_user_id`, `validated_at`, `transaction_data`, `created_at`, `updated_at`) VALUES ('20', '2025-03-10', '2025-03-10', NULL, 'Internal Fund Transfer', 'Allocate reserves to building fund', 'XFR-250310', NULL, 'reconciled', '2025-03-15', NULL, NULL, NULL, NULL, '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `budget_id`, `status`, `date_reconciled`, `created_by_user_id`, `validated_by_user_id`, `validated_at`, `transaction_data`, `created_at`, `updated_at`) VALUES ('21', '2025-03-16', '2025-03-17', NULL, 'Worship Service Offering', 'Mid March offerings', 'OFF-250316', NULL, 'cleared', NULL, NULL, NULL, NULL, NULL, '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `budget_id`, `status`, `date_reconciled`, `created_by_user_id`, `validated_by_user_id`, `validated_at`, `transaction_data`, `created_at`, `updated_at`) VALUES ('22', '2025-03-18', '2025-03-19', NULL, 'Hope Food Pantry', 'Monthly benevolence allocation', 'BEN-250318', NULL, 'cleared', NULL, NULL, NULL, NULL, NULL, '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `budget_id`, `status`, `date_reconciled`, `created_by_user_id`, `validated_by_user_id`, `validated_at`, `transaction_data`, `created_at`, `updated_at`) VALUES ('23', '2025-03-22', '2025-03-24', '1210', 'Comcast Business', 'Internet and phone service', NULL, NULL, 'cleared', NULL, NULL, NULL, NULL, NULL, '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `budget_id`, `status`, `date_reconciled`, `created_by_user_id`, `validated_by_user_id`, `validated_at`, `transaction_data`, `created_at`, `updated_at`) VALUES ('24', '2025-03-25', '2025-03-27', '1211', 'Rev. Michael Thompson', 'Pastoral compensation - March', NULL, NULL, 'cleared', NULL, NULL, NULL, NULL, NULL, '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `budget_id`, `status`, `date_reconciled`, `created_by_user_id`, `validated_by_user_id`, `validated_at`, `transaction_data`, `created_at`, `updated_at`) VALUES ('25', '2025-03-30', '2025-03-31', NULL, 'Easter Offering', 'Special resurrection Sunday collection', 'OFF-EAST25', NULL, 'cleared', NULL, NULL, NULL, NULL, NULL, '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `budget_id`, `status`, `date_reconciled`, `created_by_user_id`, `validated_by_user_id`, `validated_at`, `transaction_data`, `created_at`, `updated_at`) VALUES ('26', '2025-04-02', '2025-04-03', '1212', 'Harmony Piano Service', 'Annual piano tuning and maintenance', NULL, NULL, 'cleared', NULL, NULL, NULL, NULL, NULL, '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `budget_id`, `status`, `date_reconciled`, `created_by_user_id`, `validated_by_user_id`, `validated_at`, `transaction_data`, `created_at`, `updated_at`) VALUES ('27', '2025-04-06', '2025-04-07', NULL, 'Worship Service Offering', 'Palm Sunday offerings', 'OFF-250406', NULL, 'cleared', NULL, NULL, NULL, NULL, NULL, '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `budget_id`, `status`, `date_reconciled`, `created_by_user_id`, `validated_by_user_id`, `validated_at`, `transaction_data`, `created_at`, `updated_at`) VALUES ('28', '2025-04-10', '2025-04-11', NULL, 'Central Seminary Scholarship Fund', 'Leadership development grant', 'EDU-0425', '5', 'cleared', NULL, NULL, NULL, NULL, NULL, '2026-07-10 07:10:34', '2026-07-21 07:08:48');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `budget_id`, `status`, `date_reconciled`, `created_by_user_id`, `validated_by_user_id`, `validated_at`, `transaction_data`, `created_at`, `updated_at`) VALUES ('29', '2025-04-15', '2025-04-16', '1213', 'Sarah Kline - Admin', 'Administrative assistant wages', NULL, NULL, 'cleared', NULL, NULL, NULL, NULL, NULL, '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `budget_id`, `status`, `date_reconciled`, `created_by_user_id`, `validated_by_user_id`, `validated_at`, `transaction_data`, `created_at`, `updated_at`) VALUES ('30', '2025-04-20', '2025-04-21', NULL, 'Worship Service Offering', 'Regular Sunday giving', 'OFF-250420', NULL, 'cleared', NULL, NULL, NULL, NULL, NULL, '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `budget_id`, `status`, `date_reconciled`, `created_by_user_id`, `validated_by_user_id`, `validated_at`, `transaction_data`, `created_at`, `updated_at`) VALUES ('31', '2025-04-25', '2025-04-28', '1214', 'A+ Plumbing & Heating', 'Fellowship hall bathroom repair', NULL, NULL, 'cleared', NULL, NULL, NULL, NULL, NULL, '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `budget_id`, `status`, `date_reconciled`, `created_by_user_id`, `validated_by_user_id`, `validated_at`, `transaction_data`, `created_at`, `updated_at`) VALUES ('32', '2025-05-01', '2025-05-02', NULL, 'Global Missions Outreach', 'Q2 missions support payment', 'MSN-2025Q2', NULL, 'cleared', NULL, NULL, NULL, NULL, NULL, '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `budget_id`, `status`, `date_reconciled`, `created_by_user_id`, `validated_by_user_id`, `validated_at`, `transaction_data`, `created_at`, `updated_at`) VALUES ('33', '2025-05-04', '2025-05-05', NULL, 'Worship Service Offering', 'May the fourth Sunday offerings', 'OFF-250504', NULL, 'cleared', NULL, NULL, NULL, NULL, NULL, '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `budget_id`, `status`, `date_reconciled`, `created_by_user_id`, `validated_by_user_id`, `validated_at`, `transaction_data`, `created_at`, `updated_at`) VALUES ('34', '2026-06-10', NULL, '1219', 'Corner Market Supplies', 'Fellowship supplies and coffee', '', NULL, 'pending', NULL, NULL, NULL, NULL, NULL, '2026-07-10 07:10:34', '2026-07-10 07:12:34');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `budget_id`, `status`, `date_reconciled`, `created_by_user_id`, `validated_by_user_id`, `validated_at`, `transaction_data`, `created_at`, `updated_at`) VALUES ('35', '2026-07-10', NULL, '1219', 'Test', 'test | test', '263434', NULL, 'pending', NULL, '1', NULL, NULL, NULL, '2026-07-10 07:27:17', '2026-07-23 06:15:05');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `budget_id`, `status`, `date_reconciled`, `created_by_user_id`, `validated_by_user_id`, `validated_at`, `transaction_data`, `created_at`, `updated_at`) VALUES ('36', '2026-07-10', NULL, '', 'Contribution Deposit', 'Workflow contribution deposit | Instance #1', '263433', '5', 'pending', NULL, '1', '1', '2026-07-10 17:00:41', '{\"type\":\"contribution\",\"service_date\":\"2026-07-10\",\"description\":\"Sunday Offering\",\"cash_denominations\":{\"1\":0,\"5\":0,\"10\":0,\"20\":0,\"50\":0,\"100\":1,\"0.25\":0,\"0.10\":0,\"0.05\":0,\"0.01\":0},\"checks\":[],\"fund_allocations\":[{\"fund_id\":1,\"amount\":100}],\"totals\":{\"cash\":100,\"checks\":0,\"grand\":100},\"first_teller_id\":1,\"first_teller_at\":\"2026-07-10T20:58:46+00:00\",\"second_teller_id\":2,\"second_teller_at\":\"2026-07-10T20:59:58+00:00\",\"second_verify_denominations\":{\"1\":0,\"5\":0,\"10\":0,\"20\":0,\"50\":0,\"100\":1,\"0.25\":0,\"0.10\":0,\"0.05\":0,\"0.01\":0},\"official_id\":1,\"official_at\":\"2026-07-10T21:00:41+00:00\",\"official_verified\":{\"denominations\":true,\"checks\":true,\"funds\":true}}', '2026-07-10 16:58:46', '2026-07-21 06:58:51');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `budget_id`, `status`, `date_reconciled`, `created_by_user_id`, `validated_by_user_id`, `validated_at`, `transaction_data`, `created_at`, `updated_at`) VALUES ('37', '2026-07-20', NULL, '', '543', '', '263432', '3', 'pending', NULL, '1', NULL, NULL, NULL, '2026-07-20 07:06:58', '2026-07-21 06:58:23');
INSERT INTO `transaction_details` (`id`, `transaction_date`, `cleared_date`, `check_number`, `pay_to`, `memo`, `reference_number`, `budget_id`, `status`, `date_reconciled`, `created_by_user_id`, `validated_by_user_id`, `validated_at`, `transaction_data`, `created_at`, `updated_at`) VALUES ('40', '2026-01-04', NULL, '', 'DEPOSIT', 'Amount corrected by bank. I put 1750 not 1850', '260001', '5', 'pending', NULL, '1', NULL, NULL, NULL, '2026-07-23 06:19:43', '2026-07-23 06:19:43');

DROP TABLE IF EXISTS `transaction_documents`;
CREATE TABLE `transaction_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_detail_id` int(11) NOT NULL,
  `stored_filename` varchar(255) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `mime_type` varchar(120) DEFAULT NULL,
  `file_size` int(11) NOT NULL DEFAULT 0,
  `uploaded_by_user_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_transaction_documents_tx` (`transaction_detail_id`),
  CONSTRAINT `transaction_documents_ibfk_1` FOREIGN KEY (`transaction_detail_id`) REFERENCES `transaction_details` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `transaction_documents` (`id`, `transaction_detail_id`, `stored_filename`, `original_filename`, `mime_type`, `file_size`, `uploaded_by_user_id`, `created_at`) VALUES ('3', '34', 'doc_20260710_112035_3cee5641.pdf', 'receipt.pdf', 'application/pdf', '37', '1', '2026-07-10 07:20:35');
INSERT INTO `transaction_documents` (`id`, `transaction_detail_id`, `stored_filename`, `original_filename`, `mime_type`, `file_size`, `uploaded_by_user_id`, `created_at`) VALUES ('4', '34', 'doc_20260710_112110_1b091b87.pdf', 'final.pdf', 'application/pdf', '20', '1', '2026-07-10 07:21:10');
INSERT INTO `transaction_documents` (`id`, `transaction_detail_id`, `stored_filename`, `original_filename`, `mime_type`, `file_size`, `uploaded_by_user_id`, `created_at`) VALUES ('5', '34', 'doc_20260710_112607_cf908c50.pdf', 'CityOfNashville.receipt.pdf', 'application/pdf', '79398', '1', '2026-07-10 07:26:07');
INSERT INTO `transaction_documents` (`id`, `transaction_detail_id`, `stored_filename`, `original_filename`, `mime_type`, `file_size`, `uploaded_by_user_id`, `created_at`) VALUES ('6', '35', 'doc_20260710_112731_8b0851b8.pdf', 'CityOfNashville.receipt.pdf', 'application/pdf', '79398', '1', '2026-07-10 07:27:31');
INSERT INTO `transaction_documents` (`id`, `transaction_detail_id`, `stored_filename`, `original_filename`, `mime_type`, `file_size`, `uploaded_by_user_id`, `created_at`) VALUES ('8', '34', 'doc_20260710_113450_007015fa.jpg', 'image-4.jpg', 'image/jpeg', '82803', '1', '2026-07-10 07:34:50');
INSERT INTO `transaction_documents` (`id`, `transaction_detail_id`, `stored_filename`, `original_filename`, `mime_type`, `file_size`, `uploaded_by_user_id`, `created_at`) VALUES ('11', '36', 'doc_20260710_205858_2daeb7ab.jpg', 'image-3.jpg', 'image/jpeg', '102549', '1', '2026-07-10 16:58:58');
INSERT INTO `transaction_documents` (`id`, `transaction_detail_id`, `stored_filename`, `original_filename`, `mime_type`, `file_size`, `uploaded_by_user_id`, `created_at`) VALUES ('12', '35', 'doc_20260710_214045_e7279aca.jpg', 'image-3.jpg', 'image/jpeg', '102549', '1', '2026-07-10 17:40:45');
INSERT INTO `transaction_documents` (`id`, `transaction_detail_id`, `stored_filename`, `original_filename`, `mime_type`, `file_size`, `uploaded_by_user_id`, `created_at`) VALUES ('13', '35', 'doc_20260711_132702_ed0a09a8.jpg', '17837764175673659541700467166883.jpg', 'image/jpeg', '1175999', '1', '2026-07-11 09:27:02');

DROP TABLE IF EXISTS `transaction_events`;
CREATE TABLE `transaction_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_detail_id` int(11) NOT NULL,
  `event_type` varchar(80) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `summary` varchar(255) NOT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_transaction_events_tx` (`transaction_detail_id`),
  KEY `idx_transaction_events_type` (`event_type`),
  CONSTRAINT `transaction_events_ibfk_1` FOREIGN KEY (`transaction_detail_id`) REFERENCES `transaction_details` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `transaction_events` (`id`, `transaction_detail_id`, `event_type`, `user_id`, `username`, `summary`, `details`, `created_at`) VALUES ('1', '34', 'updated', '1', 'admin', 'Manual transaction updated.', '{\"debits\":58.75,\"credits\":58.75}', '2026-07-10 07:12:34');
INSERT INTO `transaction_events` (`id`, `transaction_detail_id`, `event_type`, `user_id`, `username`, `summary`, `details`, `created_at`) VALUES ('2', '34', 'updated', '1', 'admin', 'Manual transaction updated.', '{\"debits\":58.75,\"credits\":58.75}', '2026-07-10 07:13:05');
INSERT INTO `transaction_events` (`id`, `transaction_detail_id`, `event_type`, `user_id`, `username`, `summary`, `details`, `created_at`) VALUES ('3', '34', 'updated', '1', 'admin', 'Manual transaction updated.', '{\"debits\":58.75,\"credits\":58.75}', '2026-07-10 07:13:13');
INSERT INTO `transaction_events` (`id`, `transaction_detail_id`, `event_type`, `user_id`, `username`, `summary`, `details`, `created_at`) VALUES ('4', '34', 'updated', '1', 'admin', 'Manual transaction updated.', '{\"debits\":58.75,\"credits\":58.75}', '2026-07-10 07:16:43');
INSERT INTO `transaction_events` (`id`, `transaction_detail_id`, `event_type`, `user_id`, `username`, `summary`, `details`, `created_at`) VALUES ('5', '34', 'document_uploaded', '1', 'admin', 'Document uploaded: CityOfNashville.receipt.pdf', '{\"doc_id\":5}', '2026-07-10 07:26:07');
INSERT INTO `transaction_events` (`id`, `transaction_detail_id`, `event_type`, `user_id`, `username`, `summary`, `details`, `created_at`) VALUES ('6', '35', 'created', '1', 'admin', 'Manual transaction created.', '{\"debits\":23,\"credits\":23}', '2026-07-10 07:27:17');
INSERT INTO `transaction_events` (`id`, `transaction_detail_id`, `event_type`, `user_id`, `username`, `summary`, `details`, `created_at`) VALUES ('7', '35', 'document_uploaded', '1', 'admin', 'Document uploaded: CityOfNashville.receipt.pdf', '{\"doc_id\":6}', '2026-07-10 07:27:31');
INSERT INTO `transaction_events` (`id`, `transaction_detail_id`, `event_type`, `user_id`, `username`, `summary`, `details`, `created_at`) VALUES ('8', '35', 'document_uploaded', '1', 'admin', 'Document uploaded: image-1.jpg', '{\"doc_id\":7}', '2026-07-10 07:34:30');
INSERT INTO `transaction_events` (`id`, `transaction_detail_id`, `event_type`, `user_id`, `username`, `summary`, `details`, `created_at`) VALUES ('9', '34', 'document_uploaded', '1', 'admin', 'Document uploaded: image-4.jpg', '{\"doc_id\":8}', '2026-07-10 07:34:50');
INSERT INTO `transaction_events` (`id`, `transaction_detail_id`, `event_type`, `user_id`, `username`, `summary`, `details`, `created_at`) VALUES ('10', '35', 'document_deleted', '1', 'admin', 'Document deleted: image-1.jpg', '{\"doc_id\":7}', '2026-07-10 07:56:48');
INSERT INTO `transaction_events` (`id`, `transaction_detail_id`, `event_type`, `user_id`, `username`, `summary`, `details`, `created_at`) VALUES ('11', '35', 'document_uploaded', '1', 'admin', 'Document uploaded: image-1.jpg', '{\"doc_id\":10}', '2026-07-10 08:03:41');
INSERT INTO `transaction_events` (`id`, `transaction_detail_id`, `event_type`, `user_id`, `username`, `summary`, `details`, `created_at`) VALUES ('12', '35', 'updated', '1', 'admin', 'Manual transaction updated.', '{\"debits\":23,\"credits\":23}', '2026-07-10 08:03:46');
INSERT INTO `transaction_events` (`id`, `transaction_detail_id`, `event_type`, `user_id`, `username`, `summary`, `details`, `created_at`) VALUES ('13', '35', 'updated', '1', 'admin', 'Manual transaction updated.', '{\"debits\":24,\"credits\":24}', '2026-07-10 08:04:00');
INSERT INTO `transaction_events` (`id`, `transaction_detail_id`, `event_type`, `user_id`, `username`, `summary`, `details`, `created_at`) VALUES ('14', '35', 'updated', NULL, 'system', 'Transaction saved with no field changes (re-saved).', '{\"debits\":24,\"credits\":24,\"changes\":[]}', '2026-07-10 13:43:21');
INSERT INTO `transaction_events` (`id`, `transaction_detail_id`, `event_type`, `user_id`, `username`, `summary`, `details`, `created_at`) VALUES ('15', '35', 'updated', '1', 'admin', 'Transaction saved with no field changes (re-saved).', '{\"debits\":24,\"credits\":24,\"changes\":[]}', '2026-07-10 13:44:00');
INSERT INTO `transaction_events` (`id`, `transaction_detail_id`, `event_type`, `user_id`, `username`, `summary`, `details`, `created_at`) VALUES ('16', '35', 'document_deleted', '1', 'admin', 'Attachment \"image-1.jpg\" removed.', '{\"doc_id\":10,\"original_filename\":\"image-1.jpg\"}', '2026-07-10 13:44:00');
INSERT INTO `transaction_events` (`id`, `transaction_detail_id`, `event_type`, `user_id`, `username`, `summary`, `details`, `created_at`) VALUES ('17', '36', 'draft_created', '1', 'admin', 'Contribution draft created by first teller totaling $100.00.', '{\"grand_total\":100}', '2026-07-10 16:58:46');
INSERT INTO `transaction_events` (`id`, `transaction_detail_id`, `event_type`, `user_id`, `username`, `summary`, `details`, `created_at`) VALUES ('18', '36', 'document_uploaded', '1', 'admin', 'Attachment \"image-3.jpg\" added.', '{\"doc_id\":11,\"workflow_instance_id\":1,\"original_filename\":\"image-3.jpg\"}', '2026-07-10 16:58:58');
INSERT INTO `transaction_events` (`id`, `transaction_detail_id`, `event_type`, `user_id`, `username`, `summary`, `details`, `created_at`) VALUES ('19', '36', 'second_teller_signed', '2', 'finance', 'Second teller Finance Manager signed off on dual count ($100.00 cash verified).', '{\"second_teller_id\":2,\"second_teller_name\":\"Finance Manager\",\"verify_cash_total\":100}', '2026-07-10 16:59:58');
INSERT INTO `transaction_events` (`id`, `transaction_detail_id`, `event_type`, `user_id`, `username`, `summary`, `details`, `created_at`) VALUES ('20', '36', 'validated', '1', 'admin', 'Transaction validated by Admin User.', '{\"validated_by_user_id\":1,\"validated_by_name\":\"Admin User\"}', '2026-07-10 17:00:41');
INSERT INTO `transaction_events` (`id`, `transaction_detail_id`, `event_type`, `user_id`, `username`, `summary`, `details`, `created_at`) VALUES ('21', '36', 'deposit_finalized', '1', 'admin', 'Contribution deposit of $100.00 finalized by Admin User.', '{\"workflow_instance_id\":1,\"amount\":100,\"finalized_by\":\"Admin User\"}', '2026-07-10 17:00:41');
INSERT INTO `transaction_events` (`id`, `transaction_detail_id`, `event_type`, `user_id`, `username`, `summary`, `details`, `created_at`) VALUES ('22', '35', 'document_uploaded', '1', 'admin', 'Attachment \"image-3.jpg\" added.', '{\"doc_id\":12,\"original_filename\":\"image-3.jpg\"}', '2026-07-10 17:40:45');
INSERT INTO `transaction_events` (`id`, `transaction_detail_id`, `event_type`, `user_id`, `username`, `summary`, `details`, `created_at`) VALUES ('23', '35', 'updated', '1', 'admin', 'Transaction saved with no field changes (re-saved).', '{\"debits\":24,\"credits\":24,\"changes\":[]}', '2026-07-10 17:40:47');
INSERT INTO `transaction_events` (`id`, `transaction_detail_id`, `event_type`, `user_id`, `username`, `summary`, `details`, `created_at`) VALUES ('24', '35', 'document_uploaded', '1', 'admin', 'Attachment \"17837764175673659541700467166883.jpg\" added.', '{\"doc_id\":13,\"original_filename\":\"17837764175673659541700467166883.jpg\"}', '2026-07-11 09:27:02');
INSERT INTO `transaction_events` (`id`, `transaction_detail_id`, `event_type`, `user_id`, `username`, `summary`, `details`, `created_at`) VALUES ('25', '37', 'created', '1', 'admin', 'Transaction created for \"543\" totaling $200.00 (2 lines).', '{\"debits\":200,\"credits\":200,\"changes\":[\"Transaction created for \\\"543\\\" totaling $200.00 (2 lines).\",\"Fund \\\"General Operating Fund\\\" $400.00.\"]}', '2026-07-20 07:06:58');
INSERT INTO `transaction_events` (`id`, `transaction_detail_id`, `event_type`, `user_id`, `username`, `summary`, `details`, `created_at`) VALUES ('26', '37', 'updated', '1', 'admin', 'Budget changed from \"—\" to \"2026 - test2\".', '{\"debits\":200,\"credits\":200,\"changes\":[\"Budget changed from \\\"—\\\" to \\\"2026 - test2\\\".\"]}', '2026-07-21 06:58:23');
INSERT INTO `transaction_events` (`id`, `transaction_detail_id`, `event_type`, `user_id`, `username`, `summary`, `details`, `created_at`) VALUES ('27', '36', 'updated', '1', 'admin', 'Transaction updated (2 changes).', '{\"debits\":100,\"credits\":100,\"changes\":[\"Reference # changed from \\\"WF-CONTRIB-1\\\" to \\\"263433\\\".\",\"Budget changed from \\\"—\\\" to \\\"2026 - d\\\".\"]}', '2026-07-21 06:58:51');
INSERT INTO `transaction_events` (`id`, `transaction_detail_id`, `event_type`, `user_id`, `username`, `summary`, `details`, `created_at`) VALUES ('28', '28', 'updated', '1', 'admin', 'Budget changed from \"—\" to \"2026 - d\".', '{\"budget_only\":true,\"status\":\"cleared\",\"changes\":[\"Budget changed from \\\"—\\\" to \\\"2026 - d\\\".\"]}', '2026-07-21 07:08:48');
INSERT INTO `transaction_events` (`id`, `transaction_detail_id`, `event_type`, `user_id`, `username`, `summary`, `details`, `created_at`) VALUES ('29', '35', 'updated', '1', 'admin', 'Transaction updated (3 changes).', '{\"debits\":24,\"credits\":24,\"changes\":[\"Reference # changed from \\\"23221\\\" to \\\"263434\\\".\",\"Added Fund \\\"General Operating Fund\\\" (Utilities:Electric) credit $24.00.\",\"Removed Fund \\\"General Operating Fund\\\" (Accounts Payable) credit $24.00.\"]}', '2026-07-23 06:15:05');
INSERT INTO `transaction_events` (`id`, `transaction_detail_id`, `event_type`, `user_id`, `username`, `summary`, `details`, `created_at`) VALUES ('30', '40', 'created', '1', 'admin', 'Transaction created for \"DEPOSIT\" totaling $1,850.00 (3 lines).', '{\"debits\":1850,\"credits\":1850,\"changes\":[\"Transaction created for \\\"DEPOSIT\\\" totaling $1,850.00 (3 lines).\",\"Fund \\\"General Operating Fund\\\" $1,750.00.\",\"Fund \\\"Benevolence Fund\\\" $100.00.\"]}', '2026-07-23 06:19:43');

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
) ENGINE=InnoDB AUTO_INCREMENT=103 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('1', '1', '2', '1', '2845.50', 'debit', '1', '6', NULL, 'Cash and checks deposit', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('2', '1', '10', '1', '2845.50', 'credit', '1', '6', NULL, 'General contributions', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('3', '2', '9', '1', '378.40', 'debit', '8', '5', NULL, 'Utilities - electric', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('4', '2', '2', '1', '378.40', 'credit', '8', '5', NULL, 'Payment to utility', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('5', '3', '9', '2', '1500.00', 'debit', '2', '3', NULL, 'Missions disbursement', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('6', '3', '2', '1', '1500.00', 'credit', '2', '3', NULL, 'Bank payment', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('7', '4', '9', '1', '92.30', 'debit', '8', '5', NULL, 'Water and sewer', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('8', '4', '2', '1', '92.30', 'credit', '8', '5', NULL, 'Payment to utility', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('9', '5', '2', '1', '3050.00', 'debit', '1', '6', NULL, 'Weekly deposit', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('10', '5', '10', '1', '3050.00', 'credit', '1', '6', NULL, 'General contributions', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('11', '6', '9', '1', '67.80', 'debit', '3', '4', NULL, 'Admin supplies', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('12', '6', '2', '1', '67.80', 'credit', '3', '4', NULL, 'Payment', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('13', '7', '9', '1', '4250.00', 'debit', '6', '7', NULL, 'Pastoral salary', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('14', '7', '2', '1', '4250.00', 'credit', '6', '7', NULL, 'Bank payment', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('15', '8', '9', '3', '500.00', 'debit', '2', '3', NULL, 'Benevolence aid - housing', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('16', '8', '2', '1', '500.00', 'credit', '2', '3', NULL, 'Bank payment', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('17', '9', '2', '1', '2890.00', 'debit', '1', '6', NULL, 'Weekly deposit', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('18', '9', '10', '1', '2890.00', 'credit', '1', '6', NULL, 'General contributions', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('19', '10', '2', '4', '10000.00', 'debit', '1', '6', NULL, 'Designated building gift', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('20', '10', '10', '4', '10000.00', 'credit', '1', '6', NULL, 'Building fund contribution', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('21', '11', '9', '1', '1250.00', 'debit', '8', '5', NULL, 'Insurance premium', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('22', '11', '2', '1', '1250.00', 'credit', '8', '5', NULL, 'Payment', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('23', '12', '9', '1', '850.00', 'debit', '5', '2', NULL, 'Youth camp deposit', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('24', '12', '2', '1', '850.00', 'credit', '5', '2', NULL, 'Payment', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('25', '13', '1', '1', '485.00', 'debit', '1', '6', NULL, 'Cash in plate', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('26', '13', '2', '1', '2620.00', 'debit', '1', '6', NULL, 'Checks and online gifts', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('27', '13', '10', '1', '3105.00', 'credit', '1', '6', NULL, 'Total contributions', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('28', '14', '9', '1', '320.00', 'debit', '8', '5', NULL, 'Janitorial services', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('29', '14', '2', '1', '320.00', 'credit', '8', '5', NULL, 'Payment', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('30', '15', '2', '2', '750.00', 'debit', '1', '6', NULL, 'Restricted missions gift', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('31', '15', '10', '2', '750.00', 'credit', '1', '6', NULL, 'Missions contribution', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('32', '16', '9', '1', '275.00', 'debit', '8', '5', NULL, 'Grounds maintenance', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('33', '16', '2', '1', '275.00', 'credit', '8', '5', NULL, 'Payment', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('34', '17', '9', '1', '4250.00', 'debit', '6', '7', NULL, 'Pastoral salary', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('35', '17', '2', '1', '4250.00', 'credit', '6', '7', NULL, 'Bank payment', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('36', '18', '2', '1', '3125.75', 'debit', '1', '6', NULL, 'Weekly deposit', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('37', '18', '10', '1', '3125.75', 'credit', '1', '6', NULL, 'General contributions', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('38', '19', '9', '1', '412.60', 'debit', '5', '2', NULL, 'Education supplies', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('39', '19', '2', '1', '412.60', 'credit', '5', '2', NULL, 'Payment', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('40', '20', '2', '4', '3000.00', 'debit', '4', '5', NULL, 'Transfer in to building', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('41', '20', '2', '1', '3000.00', 'credit', '4', '5', NULL, 'Transfer out from general', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('42', '21', '2', '1', '2765.00', 'debit', '1', '6', NULL, 'Weekly deposit', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('43', '21', '10', '1', '2765.00', 'credit', '1', '6', NULL, 'General contributions', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('44', '22', '9', '3', '325.00', 'debit', '2', '3', NULL, 'Benevolence - food pantry', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('45', '22', '2', '1', '325.00', 'credit', '2', '3', NULL, 'Bank payment', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('46', '23', '9', '1', '89.99', 'debit', '3', '4', NULL, 'Communications', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('47', '23', '2', '1', '89.99', 'credit', '3', '4', NULL, 'Payment', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('48', '24', '9', '1', '4250.00', 'debit', '6', '7', NULL, 'Pastoral salary', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('49', '24', '2', '1', '4250.00', 'credit', '6', '7', NULL, 'Bank payment', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('50', '25', '2', '1', '1925.50', 'debit', '1', '6', NULL, 'Special Easter offering', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('51', '25', '10', '1', '1925.50', 'credit', '1', '6', NULL, 'General contributions', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('52', '26', '9', '1', '175.00', 'debit', '8', '1', NULL, 'Worship support', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('53', '26', '2', '1', '175.00', 'credit', '8', '1', NULL, 'Payment', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('54', '27', '2', '1', '2540.00', 'debit', '1', '6', NULL, 'Weekly deposit', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('55', '27', '10', '1', '2540.00', 'credit', '1', '6', NULL, 'General contributions', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('56', '28', '9', '1', '1200.00', 'debit', '2', '2', NULL, 'Seminary scholarship', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('57', '28', '2', '1', '1200.00', 'credit', '2', '2', NULL, 'Payment', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('58', '29', '9', '1', '2100.00', 'debit', '6', '7', NULL, 'Admin wages', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('59', '29', '2', '1', '2100.00', 'credit', '6', '7', NULL, 'Bank payment', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('60', '30', '2', '1', '2995.00', 'debit', '1', '6', NULL, 'Weekly deposit', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('61', '30', '10', '1', '2995.00', 'credit', '1', '6', NULL, 'General contributions', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('62', '31', '9', '1', '685.00', 'debit', '4', '5', NULL, 'Facilities capital repair', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('63', '31', '2', '1', '685.00', 'credit', '4', '5', NULL, 'Payment', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('64', '32', '9', '2', '4500.00', 'debit', '2', '3', NULL, 'Q2 missions support', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('65', '32', '2', '1', '4500.00', 'credit', '2', '3', NULL, 'Bank payment', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('66', '33', '2', '1', '2680.00', 'debit', '1', '6', NULL, 'Weekly deposit', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('67', '33', '10', '1', '2680.00', 'credit', '1', '6', NULL, 'General contributions', '2026-07-10 07:10:34', '2026-07-10 07:10:34');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('76', '34', '9', '1', '58.75', 'credit', '8', '1', NULL, '', '2026-07-10 07:16:43', '2026-07-10 07:16:43');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('77', '34', '2', '1', '58.75', 'debit', '8', '1', NULL, '', '2026-07-10 07:16:43', '2026-07-10 07:16:43');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('94', '37', '7', '1', '200.00', 'debit', '4', '5', NULL, '', '2026-07-21 06:58:23', '2026-07-21 06:58:23');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('95', '37', '2', '1', '200.00', 'credit', '4', '5', NULL, '', '2026-07-21 06:58:23', '2026-07-21 06:58:23');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('96', '36', '2', NULL, '100.00', 'debit', '1', NULL, NULL, '', '2026-07-21 06:58:51', '2026-07-21 06:58:51');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('97', '36', '10', '1', '100.00', 'credit', '1', NULL, NULL, '', '2026-07-21 06:58:51', '2026-07-21 06:58:51');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('98', '35', '2', '1', '24.00', 'debit', '3', '5', NULL, '', '2026-07-23 06:15:05', '2026-07-23 06:15:05');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('99', '35', '13', '1', '24.00', 'credit', '3', '5', NULL, '', '2026-07-23 06:15:05', '2026-07-23 06:15:05');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('100', '40', '2', '1', '1750.00', 'debit', NULL, NULL, NULL, '', '2026-07-23 06:19:43', '2026-07-23 06:19:43');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('101', '40', '2', '3', '100.00', 'debit', NULL, NULL, NULL, '', '2026-07-23 06:19:43', '2026-07-23 06:19:43');
INSERT INTO `transaction_lines` (`id`, `transaction_detail_id`, `account_id`, `fund_id`, `amount`, `type`, `natural_category_id`, `functional_category_id`, `budget_line_id`, `description`, `created_at`, `updated_at`) VALUES ('102', '40', '10', NULL, '1850.00', 'credit', NULL, NULL, NULL, '', '2026-07-23 06:19:43', '2026-07-23 06:19:43');

DROP TABLE IF EXISTS `user_roles`;
CREATE TABLE `user_roles` (
  `user_id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`,`role_id`),
  KEY `idx_user_roles_role_id` (`role_id`),
  CONSTRAINT `fk_user_roles_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`),
  CONSTRAINT `fk_user_roles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `user_roles` (`user_id`, `role_id`, `is_primary`, `created_at`) VALUES ('1', '1', '1', '2026-07-13 09:34:08');
INSERT INTO `user_roles` (`user_id`, `role_id`, `is_primary`, `created_at`) VALUES ('2', '2', '1', '2026-07-13 09:34:08');
INSERT INTO `user_roles` (`user_id`, `role_id`, `is_primary`, `created_at`) VALUES ('3', '3', '1', '2026-07-13 09:34:08');
INSERT INTO `user_roles` (`user_id`, `role_id`, `is_primary`, `created_at`) VALUES ('10', '2', '0', '2026-07-18 22:43:01');
INSERT INTO `user_roles` (`user_id`, `role_id`, `is_primary`, `created_at`) VALUES ('10', '6', '0', '2026-07-18 22:43:01');
INSERT INTO `user_roles` (`user_id`, `role_id`, `is_primary`, `created_at`) VALUES ('10', '7', '0', '2026-07-18 22:43:01');
INSERT INTO `user_roles` (`user_id`, `role_id`, `is_primary`, `created_at`) VALUES ('10', '8', '1', '2026-07-18 22:43:01');
INSERT INTO `user_roles` (`user_id`, `role_id`, `is_primary`, `created_at`) VALUES ('10', '9', '0', '2026-07-18 22:43:01');
INSERT INTO `user_roles` (`user_id`, `role_id`, `is_primary`, `created_at`) VALUES ('11', '2', '1', '2026-07-15 06:12:59');
INSERT INTO `user_roles` (`user_id`, `role_id`, `is_primary`, `created_at`) VALUES ('12', '9', '1', '2026-07-15 06:11:45');
INSERT INTO `user_roles` (`user_id`, `role_id`, `is_primary`, `created_at`) VALUES ('16', '3', '1', '2026-07-18 22:06:08');
INSERT INTO `user_roles` (`user_id`, `role_id`, `is_primary`, `created_at`) VALUES ('17', '3', '1', '2026-07-18 22:06:08');
INSERT INTO `user_roles` (`user_id`, `role_id`, `is_primary`, `created_at`) VALUES ('18', '3', '1', '2026-07-18 22:06:08');
INSERT INTO `user_roles` (`user_id`, `role_id`, `is_primary`, `created_at`) VALUES ('20', '3', '1', '2026-07-18 22:06:08');

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
  `force_password_set_at` datetime DEFAULT NULL,
  `custom_permissions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`custom_permissions`)),
  `last_login` datetime DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_users_email` (`email`),
  KEY `idx_users_role_id` (`role_id`),
  KEY `idx_users_is_active` (`is_active`),
  KEY `idx_users_archived_at` (`archived_at`),
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` (`id`, `role_id`, `username`, `first_name`, `last_name`, `email`, `phone`, `password`, `is_active`, `must_change_password`, `force_password_set_at`, `custom_permissions`, `last_login`, `archived_at`, `created_at`, `updated_at`) VALUES ('1', '1', 'admin', 'Admin', 'User', 'admin@church.org', NULL, '$2y$12$hElsAKEKx9CLXDqzYsxEeOLXq2V7vr.OY1qgi2RjTq19MqWII.Ute', '1', '0', NULL, NULL, '2026-07-23 05:54:10', NULL, '2026-07-10 07:10:35', '2026-07-23 05:54:10');
INSERT INTO `users` (`id`, `role_id`, `username`, `first_name`, `last_name`, `email`, `phone`, `password`, `is_active`, `must_change_password`, `force_password_set_at`, `custom_permissions`, `last_login`, `archived_at`, `created_at`, `updated_at`) VALUES ('2', '2', 'finance', 'Finance', 'Manager', 'finance@church.org', NULL, '$2y$12$hElsAKEKx9CLXDqzYsxEeOLXq2V7vr.OY1qgi2RjTq19MqWII.Ute', '0', '0', NULL, NULL, NULL, '2026-07-19 08:25:32', '2026-07-10 07:10:35', '2026-07-19 08:25:32');
INSERT INTO `users` (`id`, `role_id`, `username`, `first_name`, `last_name`, `email`, `phone`, `password`, `is_active`, `must_change_password`, `force_password_set_at`, `custom_permissions`, `last_login`, `archived_at`, `created_at`, `updated_at`) VALUES ('3', '3', 'member', 'Regular', 'Member', 'member@church.org', NULL, '$2y$12$hElsAKEKx9CLXDqzYsxEeOLXq2V7vr.OY1qgi2RjTq19MqWII.Ute', '0', '0', NULL, NULL, NULL, '2026-07-15 06:09:02', '2026-07-10 07:10:35', '2026-07-15 06:09:02');
INSERT INTO `users` (`id`, `role_id`, `username`, `first_name`, `last_name`, `email`, `phone`, `password`, `is_active`, `must_change_password`, `force_password_set_at`, `custom_permissions`, `last_login`, `archived_at`, `created_at`, `updated_at`) VALUES ('10', '8', 'jakadair', 'Jackie', 'Adair', 'jakadair@gmail.com', '2292374351', '$2y$12$1YG87zjCFXGk3CvCguixVeSUqm4gmdtSNFH8mItp1d1GgwBLAfitK', '1', '0', NULL, '[]', '2026-07-22 06:12:29', NULL, '2026-07-15 06:09:56', '2026-07-22 06:12:29');
INSERT INTO `users` (`id`, `role_id`, `username`, `first_name`, `last_name`, `email`, `phone`, `password`, `is_active`, `must_change_password`, `force_password_set_at`, `custom_permissions`, `last_login`, `archived_at`, `created_at`, `updated_at`) VALUES ('11', '2', 'heathet', 'Heather', 'Hanchey', 'heatherhanchey@gmai.com', '2292372901', '$2y$12$R5ZfVyHe2H0o.U3XIIXiVuE9m5OmosScpzr/wXjGvu3HXm29XCRb6', '0', '1', '2026-07-19 08:30:19', '[]', NULL, '2026-07-21 05:52:53', '2026-07-15 06:10:53', '2026-07-21 05:52:53');
INSERT INTO `users` (`id`, `role_id`, `username`, `first_name`, `last_name`, `email`, `phone`, `password`, `is_active`, `must_change_password`, `force_password_set_at`, `custom_permissions`, `last_login`, `archived_at`, `created_at`, `updated_at`) VALUES ('12', '9', 'blueelephant', 'AnnaBelle', 'Adair', 'blueelephantbaby@gmail.com', '2292372119', '$2y$12$26dS1hqipsYol21sU9fdP.1LrJj8QWm9kxA3477MfE35.UdG2k/je', '0', '1', '2026-07-19 08:37:56', '[]', NULL, '2026-07-21 05:52:53', '2026-07-15 06:11:45', '2026-07-21 05:52:53');
INSERT INTO `users` (`id`, `role_id`, `username`, `first_name`, `last_name`, `email`, `phone`, `password`, `is_active`, `must_change_password`, `force_password_set_at`, `custom_permissions`, `last_login`, `archived_at`, `created_at`, `updated_at`) VALUES ('16', '3', 'testuser1', 'test', '20260715-0711', 'test@test.test', NULL, '$2y$12$2wrbIemK4y8zv1ZvQmNHmuiR1PxlhqE8AoWteU96nw3ONmpBowOQS', '0', '1', '2026-07-15 07:11:52', '[]', NULL, '2026-07-16 08:07:39', '2026-07-15 07:11:52', '2026-07-19 08:28:24');
INSERT INTO `users` (`id`, `role_id`, `username`, `first_name`, `last_name`, `email`, `phone`, `password`, `is_active`, `must_change_password`, `force_password_set_at`, `custom_permissions`, `last_login`, `archived_at`, `created_at`, `updated_at`) VALUES ('17', '3', 'testuser2', 'test', '20260715-0712', 'test@test.test2', NULL, '$2y$12$Yw5I6EhaM.htVSqQHiiuHeHRNXd3ou1Vq..UwTuie4x2TX3P3IabG', '0', '1', '2026-07-15 07:12:25', '[]', NULL, '2026-07-16 08:07:39', '2026-07-15 07:12:25', '2026-07-19 08:28:24');
INSERT INTO `users` (`id`, `role_id`, `username`, `first_name`, `last_name`, `email`, `phone`, `password`, `is_active`, `must_change_password`, `force_password_set_at`, `custom_permissions`, `last_login`, `archived_at`, `created_at`, `updated_at`) VALUES ('18', '3', 'testuser3', 't', 't', 't@t.t', NULL, '$2y$12$nv05Ujqn7JUIbpEOw/beoeyMfO/mrL6qU5ir32AAnWip5jL.2Q9kq', '0', '1', '2026-07-15 07:14:24', '[]', NULL, '2026-07-16 08:07:39', '2026-07-15 07:14:24', '2026-07-19 08:28:24');
INSERT INTO `users` (`id`, `role_id`, `username`, `first_name`, `last_name`, `email`, `phone`, `password`, `is_active`, `must_change_password`, `force_password_set_at`, `custom_permissions`, `last_login`, `archived_at`, `created_at`, `updated_at`) VALUES ('20', '3', 'testuser4', 't', 't', 't@t.te', NULL, '$2y$12$SxF.MvBJphwI3w5dhmMcLuxIho/Lj7FpS0Kjb4AjbZdebaeEUV3OS', '1', '0', NULL, '[]', '2026-07-15 07:15:48', NULL, '2026-07-15 07:14:53', '2026-07-18 22:06:08');

SET FOREIGN_KEY_CHECKS = 1;
