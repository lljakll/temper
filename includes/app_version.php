<?php
/**
 * Application version helpers — hybrid versioning
 * (VERSION.md + app_version history table + manual SQL patches in updates/).
 *
 * Schema updates are fully manual: operators apply files under updates/ via mysql.
 * This module only reads/writes version history rows; it does not create tables,
 * seed history on page load, or apply patches.
 *
 * ## Setup baseline (0.944) vs post-baseline patches
 *
 * - `setup_db.php` / `TEMPER_VERSION_HISTORY` are **frozen at app v0.944**.
 *   Fresh destructive setup always leaves the DB at 0.944 with full history through 0.944
 *   and current schema (accounts.account_type, users.preferences, and earlier shape).
 * - Releases **after 0.944** are applied **only** via `updates/*.sql` patches.
 *   Do not append post-0.944 rows to TEMPER_VERSION_HISTORY or re-seed them in setup.
 * - Pre-0.944 incremental patches live in `updates/archive/` (historical upgrades only).
 *
 * Schema version identity = patch filename stem (no .sql) when a release has DDL;
 * process-only releases carry forward the previous schema version stem.
 * Pre-patch baseline (setup_db only, v0.801 era) uses TEMPER_SCHEMA_BASELINE.
 *
 * Security: Prevent direct access.
 */
if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('Location: ../login.php');
    exit;
}

/**
 * Current application release (codebase). Advanced via deploy + updates/*.sql;
 * not the setup seed ceiling.
 */
const TEMPER_DEFAULT_APP_VERSION = '0.944';

/**
 * Highest app version seeded by setup_db.php / TEMPER_VERSION_HISTORY.
 * Raise only when consolidating a new setup milestone.
 */
const TEMPER_SETUP_BASELINE_APP_VERSION = '0.944';

/**
 * Schema version (patch stem) of the setup_db.php baseline (0.944).
 * Matches the last TEMPER_VERSION_HISTORY entry's schema_version.
 * Embodies schema through 0.938 (accounts.account_type + users.preferences).
 */
const TEMPER_SETUP_BASELINE_SCHEMA_VERSION = '20260827_0944_setup_baseline_consolidation';

/**
 * Schema id for the initial setup_db.php shape (no updates/*.sql patch yet).
 * Used by v0.801 and any row that predates named patches.
 */
const TEMPER_SCHEMA_BASELINE = 'setup_baseline';

/**
 * Expected database schema version for this codebase (patch filename stem).
 * Equals the newest required schema id; carry forward when a release has no DDL.
 * 0.944 setup baseline includes accounts.account_type and users.preferences.
 */
const TEMPER_EXPECTED_SCHEMA_VERSION = '20260827_0944_setup_baseline_consolidation';

/**
 * Frozen setup seed: full version history through TEMPER_SETUP_BASELINE_APP_VERSION (0.944).
 * Used by seedAppVersionHistory() from setup_db.php / 08-app-version.php only.
 * Never applied on page load. Never append post-0.944 here — those rows come from updates/*.sql.
 *
 * schema_version is always set (patch stem, or TEMPER_SCHEMA_BASELINE).
 * patch_file is the .sql applied with that app version, or null if none
 * (schema_version is still set — carried forward or baseline).
 *
 * @var list<array{version: string, schema_version: string, patch_file?: ?string, notes?: ?string}>
 */
const TEMPER_VERSION_HISTORY = [
    [
        'version' => '0.801',
        'schema_version' => TEMPER_SCHEMA_BASELINE,
        'patch_file' => null,
        'notes' => 'First tracked alpha; schema established by setup_db.php',
    ],
    [
        'version' => '0.802',
        'schema_version' => '20260725_01_app_version_history',
        'patch_file' => '20260725_01_app_version_history.sql',
        'notes' => 'app_version full history; formalized manual schema patches (updates/)',
    ],
    [
        'version' => '0.803',
        'schema_version' => '20260725_02_schema_version_as_filename',
        'patch_file' => '20260725_02_schema_version_as_filename.sql',
        'notes' => 'schema_version stores patch filename stem (not integer)',
    ],
    [
        'version' => '0.804',
        'schema_version' => '20260725_03_formalize_audit_log',
        'patch_file' => '20260725_03_formalize_audit_log.sql',
        'notes' => 'Read-only schema checks; audit_log in setup; no live DDL/seed',
    ],
    [
        'version' => '0.805',
        'schema_version' => '20260725_03_formalize_audit_log',
        'patch_file' => '20260725_04_frozen_baseline_model.sql',
        'notes' => 'Frozen setup baseline at 0.804; post-0.804 releases via updates/ only',
    ],
    [
        'version' => '0.806',
        'schema_version' => '20260725_03_formalize_audit_log',
        'patch_file' => '20260726_01_setup_check_baseline_awareness.sql',
        'notes' => 'setup_db.php --check reports setup baseline vs database app_version',
    ],
    [
        'version' => '0.807',
        'schema_version' => '20260725_03_formalize_audit_log',
        'patch_file' => '20260726_02_admin_version_outdated_indicator.sql',
        'notes' => 'Admin sidebar red version + tooltip when DB lags latest available release',
    ],
    [
        'version' => '0.808',
        'schema_version' => '20260725_03_formalize_audit_log',
        'patch_file' => '20260726_0808_patch_naming_and_sidebar_dual_version.sql',
        'notes' => 'Patch names use app version token; admin sidebar App+DB dual display with lag warning',
    ],
    [
        'version' => '0.809',
        'schema_version' => '20260725_03_formalize_audit_log',
        'patch_file' => '20260726_0809_account_filter_coa_order.sql',
        'notes' => 'Account View defaults to All Accounts; account dropdowns ordered by coa_number',
    ],
    [
        'version' => '0.810',
        'schema_version' => '20260725_03_formalize_audit_log',
        'patch_file' => '20260726_0810_tx_account_category_labels.sql',
        'notes' => 'Transaction lines pull Natural/Functional from account as read-only labels',
    ],
    [
        'version' => '0.811',
        'schema_version' => '20260726_0811_tx_memo_to_description',
        'patch_file' => '20260726_0811_tx_memo_to_description.sql',
        'notes' => 'Rename transaction_details.memo to description; single Description field (no | join)',
    ],
    [
        'version' => '0.900',
        'schema_version' => '20260726_0811_tx_memo_to_description',
        'patch_file' => '20260726_0900_beta_baseline.sql',
        'notes' => 'Beta start: setup baseline consolidated through 0.811; no demo accounts/budgets/transactions',
    ],
    [
        'version' => '0.901',
        'schema_version' => '20260801_0901_account_type_classification',
        'patch_file' => '20260801_0901_account_type_classification.sql',
        'notes' => 'Required accounts.account_type (asset/liability/equity/income/expense); CoA Normal Balance UX',
    ],
    [
        'version' => '0.902',
        'schema_version' => '20260801_0901_account_type_classification',
        'patch_file' => '20260801_0902_modal_form_autofocus.sql',
        'notes' => 'Modal form autofocus: first data field on open; resist SPA/AJAX focus steal',
    ],
    [
        'version' => '0.903',
        'schema_version' => '20260801_0901_account_type_classification',
        'patch_file' => '20260801_0903_login_timeout_disabled_authoritative.sql',
        'notes' => 'Login Timeout disabled is authoritative: no idle modal/redirect; session GC aligned',
    ],
    [
        'version' => '0.904',
        'schema_version' => '20260801_0901_account_type_classification',
        'patch_file' => '20260801_0904_lookup_archive_toggle_fix.sql',
        'notes' => 'Lookup Archive/Unarchive toggle: fix checkbox POST cast; Funds handler; UI refresh',
    ],
    [
        'version' => '0.905',
        'schema_version' => '20260801_0901_account_type_classification',
        'patch_file' => '20260801_0905_lookup_add_edit_modals.sql',
        'notes' => 'Lookup Add/Edit forms: inline sections → modal dialogs with dirty-state protection',
    ],
    [
        'version' => '0.906',
        'schema_version' => '20260801_0901_account_type_classification',
        'patch_file' => '20260801_0906_sidebar_fixed_float.sql',
        'notes' => 'Desktop sidebar: position fixed/floating while main content scrolls',
    ],
    [
        'version' => '0.907',
        'schema_version' => '20260801_0901_account_type_classification',
        'patch_file' => '20260802_0907_login_timeout_from_developer_mode.sql',
        'notes' => 'Login timeout fixed by Developer Mode (5m/20m); Status panel regroup; no disable',
    ],
    [
        'version' => '0.908',
        'schema_version' => '20260801_0901_account_type_classification',
        'patch_file' => '20260803_0908_lookup_table_filter_sort.sql',
        'notes' => 'Lookup tables: live filter + sortable column headers (Funds/Accounts/Natural/Functional)',
    ],
    [
        'version' => '0.909',
        'schema_version' => '20260801_0901_account_type_classification',
        'patch_file' => '20260803_0909_lookup_toolbar_hotkeys.sql',
        'notes' => 'Lookup toolbar: compact title row, table font size, leader hotkeys (; commands)',
    ],
    [
        'version' => '0.910',
        'schema_version' => '20260801_0901_account_type_classification',
        'patch_file' => '20260807_0910_login_timeout_warning_and_devmode.sql',
        'notes' => 'Login timeout: 10m when Dev Mode off; disabled when on; warning modal stacking fix',
    ],
    [
        'version' => '0.911',
        'schema_version' => '20260801_0901_account_type_classification',
        'patch_file' => '20260809_0911_check_pending_schema_updates.sql',
        'notes' => 'setup_db.php --check: clear warning when updates/*.sql patches are pending',
    ],
    [
        'version' => '0.912',
        'schema_version' => '20260801_0901_account_type_classification',
        'patch_file' => '20260809_0912_ledger_modal_infinite_scroll.sql',
        'notes' => 'Ledger redesign: modal Add/Edit/View, infinite scroll, Excel-style filters',
    ],
    [
        'version' => '0.913',
        'schema_version' => '20260801_0901_account_type_classification',
        'patch_file' => '20260809_0913_ledger_excel_multiselect_filters.sql',
        'notes' => 'Ledger Excel-style multi-select auto-filters + Clear all filters',
    ],
    [
        'version' => '0.914',
        'schema_version' => '20260801_0901_account_type_classification',
        'patch_file' => '20260811_0914_ledger_filter_dropdown_layout.sql',
        'notes' => 'Ledger filter dropdown layout: checkboxes, no-wrap, resize, scroll; account/fund name-only labels',
    ],
    [
        'version' => '0.915',
        'schema_version' => '20260801_0901_account_type_classification',
        'patch_file' => '20260812_0915_toast_zindex_and_doc_upload.sql',
        'notes' => 'Toasts above modals site-wide; ledger doc upload selected-file detection',
    ],
    [
        'version' => '0.916',
        'schema_version' => '20260801_0901_account_type_classification',
        'patch_file' => '20260814_0916_attachment_upload_size.sql',
        'notes' => 'Attachment upload size follows PHP ceiling (20 MB); removed hard-coded 2 MB cap',
    ],
    [
        'version' => '0.917',
        'schema_version' => '20260801_0901_account_type_classification',
        'patch_file' => '20260814_0917_ledger_attachment_portfolio.sql',
        'notes' => 'Ledger list paperclip indicator and portfolio attachment viewer',
    ],
    [
        'version' => '0.918',
        'schema_version' => '20260801_0901_account_type_classification',
        'patch_file' => '20260814_0918_ledger_portfolio_viewer_refine.sql',
        'notes' => 'Portfolio viewer: viewport modal, static panes, page panel, fit-height zoom',
    ],
    [
        'version' => '0.919',
        'schema_version' => '20260801_0901_account_type_classification',
        'patch_file' => '20260814_0919_ledger_portfolio_narrow_wheel.sql',
        'notes' => 'Portfolio viewer: narrower modal, larger close, wheel page-turn',
    ],
    [
        'version' => '0.920',
        'schema_version' => '20260801_0901_account_type_classification',
        'patch_file' => '20260814_0920_import_sequence_ref_warning.sql',
        'notes' => 'Import sequence: into Ref #; field warning is only Already Used',
    ],
    [
        'version' => '0.921',
        'schema_version' => '20260801_0901_account_type_classification',
        'patch_file' => '20260814_0921_data_backup_exclude_system_tables.sql',
        'notes' => 'Data-only backups exclude app_version and audit_log; roles remain; restore keeps version history',
    ],
    [
        'version' => '0.922',
        'schema_version' => '20260801_0901_account_type_classification',
        'patch_file' => '20260816_0922_ledger_hotkeys_view_edit.sql',
        'notes' => 'Ledger leader-key hotkeys and View modal Edit action (no DDL)',
    ],
    [
        'version' => '0.923',
        'schema_version' => '20260801_0901_account_type_classification',
        'patch_file' => '20260816_0923_ledger_add_attachments_deposit_budget.sql',
        'notes' => 'Ledger Add attachments, save-upload of selected files, blank budget on deposits',
    ],
    [
        'version' => '0.924',
        'schema_version' => '20260801_0901_account_type_classification',
        'patch_file' => '20260818_0924_ledger_modal_hotkeys_attach_save.sql',
        'notes' => 'Ledger Ctrl/Cmd modal hotkeys, immediate paperclip after save-upload, single-save selected file',
    ],
    [
        'version' => '0.925',
        'schema_version' => '20260801_0901_account_type_classification',
        'patch_file' => '20260818_0925_backup_list_existing_on_load.sql',
        'notes' => 'Backup page lists all existing files in storage/backups on load (no DDL)',
    ],
    [
        'version' => '0.926',
        'schema_version' => '20260801_0901_account_type_classification',
        'patch_file' => '20260819_0926_import_fuzzy_account_match.sql',
        'notes' => 'Import from Text: fuzzy account matching and resolve dialog (no DDL)',
    ],
    [
        'version' => '0.927',
        'schema_version' => '20260801_0901_account_type_classification',
        'patch_file' => '20260819_0927_backup_include_storage_files.sql',
        'notes' => 'Backup packages include DB dump plus attachments and system config (no DDL)',
    ],
    [
        'version' => '0.928',
        'schema_version' => '20260801_0901_account_type_classification',
        'patch_file' => '20260819_0928_beancount_mass_import.sql',
        'notes' => 'Beancount Mass Import under Ledger; grant mass-import permission on named roles (no DDL)',
    ],
    [
        'version' => '0.929',
        'schema_version' => '20260801_0901_account_type_classification',
        'patch_file' => '20260820_0929_mass_import_button_handlers.sql',
        'notes' => 'Mass Import Parse/Clear buttons: SPA init-script bootstrap (no DDL)',
    ],
    [
        'version' => '0.930',
        'schema_version' => '20260801_0901_account_type_classification',
        'patch_file' => '20260820_0930_mass_import_duplicate_refine.sql',
        'notes' => 'Mass Import: same-batch allow checkbox, amount-weighted dupes, live re-check (no DDL)',
    ],
    [
        'version' => '0.931',
        'schema_version' => '20260801_0901_account_type_classification',
        'patch_file' => '20260820_0931_active_role_switching.sql',
        'notes' => 'Active role switching (single session role) and role-stamped audit usernames (no DDL)',
    ],
    [
        'version' => '0.932',
        'schema_version' => '20260801_0901_account_type_classification',
        'patch_file' => '20260820_0932_sidebar_switch_role_popout.sql',
        'notes' => 'Sidebar Switch Role button + drop-up of assigned roles (no DDL)',
    ],
    [
        'version' => '0.933',
        'schema_version' => '20260801_0901_account_type_classification',
        'patch_file' => '20260824_0933_attachment_file_sync.sql',
        'notes' => 'Sync attachment files with deletes, Ref # changes, and full transaction purge; audit while editable (no DDL)',
    ],
    [
        'version' => '0.934',
        'schema_version' => '20260801_0901_account_type_classification',
        'patch_file' => '20260825_0934_bank_export_import.sql',
        'notes' => 'Temporary FMB Checking Bank Export CSV importer under Ledger (no DDL)',
    ],
    [
        'version' => '0.935',
        'schema_version' => '20260801_0901_account_type_classification',
        'patch_file' => '20260825_0935_bank_export_preview_json.sql',
        'notes' => 'Fix Bank Export Preview JSON broken by PHP 8.4 fgetcsv deprecation notices (no DDL)',
    ],
    [
        'version' => '0.936',
        'schema_version' => '20260801_0901_account_type_classification',
        'patch_file' => '20260825_0936_ledger_form_layout_dblclick.sql',
        'notes' => 'Ledger form field widths, account-type grouping, currency Debit/Credit, double-click View/Edit toggle (no DDL)',
    ],
    [
        'version' => '0.937',
        'schema_version' => '20260801_0901_account_type_classification',
        'patch_file' => '20260825_0937_fund_tag_asset_ignored.sql',
        'notes' => 'Fund balances ignore asset-account fund tags; only income/expense/equity lines count (no DDL)',
    ],
    [
        'version' => '0.938',
        'schema_version' => '20260825_0938_user_preferences',
        'patch_file' => '20260825_0938_user_preferences.sql',
        'notes' => 'users.preferences JSON; dashboard Total Cash account picker (dashboard.total_cash.account_ids)',
    ],
    [
        'version' => '0.939',
        'schema_version' => '20260825_0938_user_preferences',
        'patch_file' => '20260826_0939_pending_transaction_delete.sql',
        'notes' => 'Pending-only transaction delete for Admin/Treasurer; required reason; file cleanup; audit_log (no DDL)',
    ],
    [
        'version' => '0.940',
        'schema_version' => '20260825_0938_user_preferences',
        'patch_file' => '20260826_0940_transaction_line_notes.sql',
        'notes' => 'Optional per-line Note on transaction_lines.description; Add/Edit/View + audit (no DDL)',
    ],
    [
        'version' => '0.941',
        'schema_version' => '20260825_0938_user_preferences',
        'patch_file' => '20260826_0941_ledger_filter_search_select.sql',
        'notes' => 'Ledger auto-filter search selects matching values on Apply (Excel-style); no DDL',
    ],
    [
        'version' => '0.942',
        'schema_version' => '20260825_0938_user_preferences',
        'patch_file' => '20260826_0942_ledger_temp_bulk_apply.sql',
        'notes' => 'Temporary Ledger bulk-apply for similar pending txns (Admin/Treasurer); no DDL',
    ],
    [
        'version' => '0.943',
        'schema_version' => '20260825_0938_user_preferences',
        'patch_file' => '20260826_0943_ledger_ref_suggest_last_plus_one.sql',
        'notes' => 'Ledger Ref # tip is last saved Ref # + 1 (placeholder/double-click); skip used in sequence; no DDL',
    ],
    [
        'version' => '0.944',
        'schema_version' => '20260827_0944_setup_baseline_consolidation',
        'patch_file' => '20260827_0944_setup_baseline_consolidation.sql',
        'notes' => 'Setup baseline advanced to 0.944: accounts.account_type + users.preferences in setup_db.php; prior updates archived',
    ],
];

/**
 * Normalize a patch path/filename to the canonical schema version id (stem, no .sql).
 */
function temperSchemaVersionId(string $patchFileOrStem): string
{
    $base = basename(trim($patchFileOrStem));
    if ($base === '') {
        return TEMPER_SCHEMA_BASELINE;
    }
    if (preg_match('/\.sql$/i', $base)) {
        $base = substr($base, 0, -4);
    }
    return $base !== '' ? $base : TEMPER_SCHEMA_BASELINE;
}

/**
 * Convert a dotted app version to the compact token used in new patch filenames.
 * Example: "0.806" → "0806", "0.808" → "0808", "1.2.3" → "123".
 *
 * New patch convention (0.808+):
 *   YYYYMMDD_<appversion_without_decimal>_short_description.sql
 * Older patches may still use YYYYMMDD_NN_description.sql — leave them as-is.
 */
function temperAppVersionToPatchToken(string $version): string
{
    $version = temperNormalizeAppVersionString($version);
    if ($version === '') {
        return '';
    }
    return str_replace('.', '', $version);
}

/**
 * Build a new-format patch basename (with .sql) for an app version + description.
 * Does not write files — naming helper only.
 *
 * @param string $appVersion Dotted app version (e.g. 0.808)
 * @param string $description Short lowercase underscore description (normalized)
 * @param string|null $dateYmd Author date YYYYMMDD; defaults to today
 */
function temperBuildPatchFilename(string $appVersion, string $description, ?string $dateYmd = null): string
{
    $date = $dateYmd !== null && preg_match('/^\d{8}$/', $dateYmd)
        ? $dateYmd
        : date('Ymd');
    $token = temperAppVersionToPatchToken($appVersion);
    if ($token === '') {
        $token = '0';
    }
    $desc = strtolower(trim($description));
    $desc = preg_replace('/[^a-z0-9]+/', '_', $desc) ?? '';
    $desc = trim($desc, '_');
    if ($desc === '') {
        $desc = 'patch';
    }
    return "{$date}_{$token}_{$desc}.sql";
}

/**
 * CREATE TABLE SQL for app_version (setup_db / patches only — not runtime).
 */
function temperAppVersionCreateSql(): string
{
    return "CREATE TABLE IF NOT EXISTS app_version (
    id INT AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(32) NOT NULL,
    schema_version VARCHAR(128) NOT NULL,
    patch_file VARCHAR(128) NULL DEFAULT NULL,
    notes VARCHAR(512) NULL DEFAULT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_app_version_applied (applied_at),
    KEY idx_app_version_schema (schema_version)
)";
}

/**
 * Read-only check: app_version table must exist with required columns.
 * Does not create the table or seed history. Safe to call repeatedly.
 *
 * @return list<string> Empty when OK; otherwise human-readable issues
 */
function checkAppVersionTable(mysqli $db): array
{
    $issues = [];
    $res = $db->query("SHOW TABLES LIKE 'app_version'");
    if (!$res || $res->num_rows === 0) {
        if ($res) {
            $res->close();
        }
        return ['table app_version is missing'];
    }
    $res->close();

    foreach (['version', 'schema_version', 'patch_file', 'notes', 'applied_at'] as $col) {
        $c = $db->query("SHOW COLUMNS FROM app_version LIKE '" . $db->real_escape_string($col) . "'");
        if (!$c || $c->num_rows === 0) {
            $issues[] = "column app_version.{$col} is missing";
        }
        if ($c) {
            $c->close();
        }
    }

    return $issues;
}

/**
 * Ensure app_version schema is present (read-only). Logs and throws if outdated.
 * Does not CREATE TABLE or seed TEMPER_VERSION_HISTORY on page load.
 */
function ensureAppVersionTable(mysqli $db): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $issues = checkAppVersionTable($db);
    if ($issues !== []) {
        temperSchemaOutOfDate('app_version', $issues);
    }

    $done = true;
}

/**
 * Compare live app_version history against the frozen setup_db.php baseline.
 * Read-only — never mutates the database.
 *
 * Status values:
 * - match: highest DB app version equals baseline (fresh setup, or live DB after consolidation patch)
 * - ahead: highest DB app version is above baseline (expected after post-baseline updates/*.sql)
 * - behind: highest DB app version is below baseline (apply pending updates/*.sql when >= 0.900)
 * - missing: app_version table or rows unavailable
 * - incomplete: required 0.801–0.900 seed rows from TEMPER_VERSION_HISTORY are not all present
 *
 * @return array{
 *   ok: bool,
 *   status: 'match'|'ahead'|'behind'|'missing'|'incomplete',
 *   db_version: ?string,
 *   db_schema: ?string,
 *   baseline_version: string,
 *   baseline_schema: string,
 *   missing_seed_versions: list<string>,
 *   requires_full_setup: bool,
 *   messages: list<string>
 * }
 */
function assessSetupBaselineVsDatabase(?mysqli $db): array
{
    $baselineVersion = TEMPER_SETUP_BASELINE_APP_VERSION;
    $baselineSchema = TEMPER_SETUP_BASELINE_SCHEMA_VERSION;

    $result = [
        'ok' => false,
        'status' => 'missing',
        'db_version' => null,
        'db_schema' => null,
        'baseline_version' => $baselineVersion,
        'baseline_schema' => $baselineSchema,
        'missing_seed_versions' => [],
        'requires_full_setup' => true,
        'messages' => [],
    ];

    if (!$db instanceof mysqli) {
        $result['messages'][] = 'No database connection; cannot read app_version history.';
        return $result;
    }

    $tableIssues = checkAppVersionTable($db);
    if ($tableIssues !== []) {
        $result['messages'][] = 'app_version table is missing or incomplete: ' . implode('; ', $tableIssues);
        return $result;
    }

    $res = $db->query(
        'SELECT version, schema_version, patch_file FROM app_version ORDER BY id ASC'
    );
    if (!$res) {
        $result['messages'][] = 'Failed to query app_version: ' . $db->error;
        return $result;
    }

    $rows = [];
    $versionsPresent = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
        $v = trim((string)($row['version'] ?? ''));
        if ($v !== '') {
            $versionsPresent[$v] = true;
        }
    }
    $res->close();

    if ($rows === []) {
        $result['messages'][] = 'app_version table exists but has no history rows.';
        return $result;
    }

    // Highest app version by semantic compare (not id order alone).
    $highestVersion = null;
    $highestSchema = null;
    foreach ($rows as $row) {
        $v = trim((string)($row['version'] ?? ''));
        if ($v === '') {
            continue;
        }
        if ($highestVersion === null || version_compare($v, $highestVersion) > 0) {
            $highestVersion = $v;
            $highestSchema = temperNormalizeStoredSchemaVersion(
                (string)($row['schema_version'] ?? ''),
                $row['patch_file'] ?? null
            );
        }
    }

    $result['db_version'] = $highestVersion;
    $result['db_schema'] = $highestSchema;

    $missingSeed = [];
    foreach (TEMPER_VERSION_HISTORY as $entry) {
        $seedV = (string)($entry['version'] ?? '');
        if ($seedV === '' || isset($versionsPresent[$seedV])) {
            continue;
        }
        // Required on every database: the original 0.801–0.900 setup seed.
        // Post-0.900 rows are seeded on a fresh 0.944 install and recorded by
        // patches; missing future/consolidation rows are pending updates, not
        // an incomplete 0.900 seed.
        if (version_compare($seedV, '0.900') <= 0) {
            $missingSeed[] = $seedV;
        }
    }
    $result['missing_seed_versions'] = $missingSeed;

    if ($highestVersion === null) {
        $result['messages'][] = 'Could not determine a highest app version from app_version rows.';
        return $result;
    }

    if ($missingSeed !== []) {
        $result['status'] = 'incomplete';
        $result['ok'] = false;
        $result['requires_full_setup'] = true;
        $result['messages'][] = 'Version history is incomplete; missing seed version(s): '
            . implode(', ', $missingSeed) . '.';
        $result['messages'][] = 'A full (destructive) setup_db.php run is required to establish the '
            . $baselineVersion . ' baseline before applying any newer updates/*.sql patches.';
        $result['messages'][] = 'Back up application data first — full setup drops and recreates tables.';
        $result['messages'][] = 'Do not run full setup against a database that has live treasurer data.';
        return $result;
    }

    $cmp = version_compare($highestVersion, $baselineVersion);

    if ($cmp < 0) {
        $result['status'] = 'behind';
        // Live databases that already passed the 0.900 floor catch up via the
        // consolidation / later updates/*.sql patch — not destructive setup.
        $belowOperationalFloor = version_compare($highestVersion, '0.900') < 0;
        $result['requires_full_setup'] = $belowOperationalFloor;
        $result['ok'] = !$belowOperationalFloor;
        $result['messages'][] = "Database app version ({$highestVersion}) is behind the setup_db.php baseline ({$baselineVersion}).";
        if ($belowOperationalFloor) {
            $result['messages'][] = 'A full (destructive) setup_db.php run is required to reach the '
                . $baselineVersion . ' / ' . $baselineSchema
                . ' baseline before applying any newer updates/*.sql patches.';
            $result['messages'][] = 'Back up application data first — full setup drops and recreates tables.';
            $result['messages'][] = 'Do not run full setup against a database that has live treasurer data.';
        } else {
            $result['messages'][] = 'Apply the pending updates/*.sql patch(es) listed below (non-destructive). '
                . 'Do not run destructive setup_db.php on a live database.';
        }
        return $result;
    }

    if ($cmp === 0) {
        $result['status'] = 'match';
        $result['ok'] = true;
        $result['requires_full_setup'] = false;
        $schemaMatch = ($highestSchema === $baselineSchema);
        if ($schemaMatch) {
            $result['messages'][] = 'Database is at the setup_db.php baseline (app and schema match).';
        } else {
            // App version at baseline but schema stem differs — still usable but note it.
            $result['messages'][] = 'Database app version matches the setup baseline, but schema version differs '
                . "(db: {$highestSchema}; baseline: {$baselineSchema}).";
            $result['messages'][] = 'If structure validation fails, run full setup after backup, or apply missing patches carefully.';
        }
        return $result;
    }

    // Ahead of baseline — normal after post-baseline patches
    $result['status'] = 'ahead';
    $result['ok'] = true;
    $result['requires_full_setup'] = false;
    $result['messages'][] = "Database is ahead of the setup_db.php baseline ({$highestVersion} > {$baselineVersion}); post-baseline patches appear applied.";
    return $result;
}

/**
 * Discover updates/*.sql patches whose declared app version is above $dbVersion.
 * Read-only filesystem scan — does not apply patches or change detection logic.
 *
 * @return list<array{file: string, basename: string, app_version: string, schema_version: string}>
 */
function temperListPendingUpdatePatches(string $dbVersion, ?string $updatesDir = null): array
{
    $dbVersion = temperNormalizeAppVersionString($dbVersion);
    $dir = $updatesDir ?? (dirname(__DIR__) . '/updates');
    if (!is_dir($dir) || $dbVersion === '') {
        return [];
    }

    $files = glob(rtrim($dir, '/\\') . '/*.sql') ?: [];
    $pending = [];

    foreach ($files as $file) {
        $base = basename($file);
        if ($base === '' || $base[0] === '_') {
            continue; // skip templates like _header_template.sql
        }

        $content = @file_get_contents($file);
        if (!is_string($content) || $content === '') {
            continue;
        }

        $appVer = '';
        if (preg_match('/^\s*--\s*App version\s*:\s*v?(\d+(?:\.\d+)+)\b/mi', $content, $m)) {
            $appVer = temperNormalizeAppVersionString((string)$m[1]);
        } elseif (preg_match(
            "/INSERT\\s+INTO\\s+app_version[\\s\\S]*?SELECT\\s+'(\\d+(?:\\.\\d+)+)'/i",
            $content,
            $m
        )) {
            $appVer = temperNormalizeAppVersionString((string)$m[1]);
        }

        if ($appVer === '' || version_compare($appVer, $dbVersion, '<=')) {
            continue;
        }

        $schemaVer = '';
        if (preg_match('/^\s*--\s*Schema ver\.?\s*:\s*(\S+)/mi', $content, $m)) {
            $schemaVer = temperSchemaVersionId((string)$m[1]);
        }
        if ($schemaVer === '') {
            $schemaVer = temperSchemaVersionId($base);
        }

        $pending[] = [
            'file' => 'updates/' . $base,
            'basename' => $base,
            'app_version' => $appVer,
            'schema_version' => $schemaVer,
        ];
    }

    usort($pending, static function (array $a, array $b): int {
        $cmp = version_compare($a['app_version'], $b['app_version']);
        if ($cmp !== 0) {
            return $cmp;
        }
        return strcmp($a['basename'], $b['basename']);
    });

    return $pending;
}

/**
 * Print the pending schema-updates section for setup_db.php --check.
 * Uses existing lag detection (getDatabaseVersionLagStatus); messaging only.
 *
 * @return bool true when no updates are pending (database not behind latest)
 */
function setupDbPrintPendingUpdatesReport(?mysqli $db): bool
{
    $lag = getDatabaseVersionLagStatus($db);
    $info = getAppVersionInfo($db);
    $dbVer = $lag['db_version'] !== '' ? $lag['db_version'] : '(none / unknown)';
    $latestVer = $lag['latest_version'] !== '' ? $lag['latest_version'] : '(unknown)';
    $dbSchema = $info['schema_version'] !== ''
        ? $info['schema_version']
        : '(none / unknown)';
    $expectedSchema = TEMPER_EXPECTED_SCHEMA_VERSION;

    echo "=== Pending Schema Updates ===\n\n";
    echo "  Database app version         : {$dbVer}\n";
    echo "  Latest available app version : {$latestVer}\n";
    echo "  Database schema version      : {$dbSchema}\n";
    echo "  Expected schema version      : {$expectedSchema}\n";
    echo "\n";

    if (!empty($lag['behind'])) {
        $pending = temperListPendingUpdatePatches(
            temperNormalizeAppVersionString($lag['db_version']) ?: $lag['db_version']
        );

        echo "  ************************************************************************\n";
        echo "  ***  WARNING: SCHEMA UPDATES ARE REQUIRED                             ***\n";
        echo "  ***  The database is behind the latest available patch(es).           ***\n";
        echo "  ************************************************************************\n";
        echo "\n";
        echo "  Database is at app v{$dbVer}; latest known release is v{$latestVer}.\n";
        echo "  Operators must apply the pending updates/*.sql patch file(s), then re-check.\n";
        echo "\n";

        if ($pending !== []) {
            echo "  Pending patch file(s) in updates/ (apply in order after backup):\n";
            foreach ($pending as $patch) {
                echo "    • {$patch['file']}  (app v{$patch['app_version']})\n";
            }
            echo "\n";
        } else {
            echo "  Could not list individual patch files from updates/; see VERSION.md\n";
            echo "  for the upgrade path from v{$dbVer} to v{$latestVer}.\n";
            echo "\n";
        }

        echo "  ---------------------------------------------------------------------------\n";
        echo "  Next steps:\n";
        echo "    1. Back up the database\n";
        echo "    2. Open VERSION.md and each pending .sql header (notes, min app version)\n";
        echo "    3. Apply each pending patch with mysql, for example:\n";
        if ($pending !== []) {
            $first = $pending[0]['file'];
            echo "         mysql -u " . DB_USER . " -p " . DB_NAME . " < {$first}\n";
        } else {
            echo "         mysql -u " . DB_USER . " -p " . DB_NAME . " < updates/<patch>.sql\n";
        }
        echo "    4. Re-run: php setup_db.php --check\n";
        echo "  ---------------------------------------------------------------------------\n";
        echo "\n";

        return false;
    }

    echo "  No schema updates are pending.\n";
    echo "  Database matches the latest available release (app v{$latestVer}).\n";
    if ($dbSchema !== $expectedSchema && $dbSchema !== '(none / unknown)') {
        echo "  Note: schema stem ({$dbSchema}) differs from codebase expected"
            . " ({$expectedSchema}); structure validation above is authoritative.\n";
    }
    echo "\n";

    return true;
}

/**
 * Print the setup baseline vs database report for setup_db.php --check.
 * Returns true when baseline status is acceptable (match or ahead).
 */
function setupDbPrintBaselineVersionReport(?mysqli $db): bool
{
    $assessment = assessSetupBaselineVsDatabase($db);

    echo "\n=== Setup Baseline Version Check ===\n\n";

    $dbVer = $assessment['db_version'] ?? '(none / unknown)';
    $dbSchema = $assessment['db_schema'] ?? '(none / unknown)';
    $baseVer = $assessment['baseline_version'];
    $baseSchema = $assessment['baseline_schema'];
    $lag = getDatabaseVersionLagStatus($db);
    $pending = !empty($lag['behind']);
    $pendingLabel = $pending ? 'YES — updates are pending' : 'NO — no schema updates pending';

    echo "  Baseline version (setup_db.php) : app {$baseVer}  /  schema {$baseSchema}\n";
    echo "  Current DB version              : app {$dbVer}  /  schema {$dbSchema}\n";
    echo "  Updates pending                 : {$pendingLabel}\n";
    echo "\n";
    echo "  Database (highest app_version row):\n";
    echo "    App version    : {$dbVer}\n";
    echo "    Schema version : {$dbSchema}\n";
    echo "\n";
    echo "  setup_db.php internal baseline (seed ceiling):\n";
    echo "    App version    : {$baseVer}\n";
    echo "    Schema version : {$baseSchema}\n";
    echo "\n";

    $statusLabel = match ($assessment['status']) {
        'match' => 'MATCH — database is at the setup baseline',
        'ahead' => 'AHEAD — database is above the setup baseline (OK)',
        'behind' => 'BEHIND — database is below the setup baseline',
        'incomplete' => 'INCOMPLETE — setup baseline history is missing rows',
        'missing' => 'MISSING — app_version history unavailable',
        default => strtoupper((string)$assessment['status']),
    };
    echo "  Comparison       : {$statusLabel}\n";

    if ($assessment['status'] === 'match' || $assessment['status'] === 'ahead') {
        $appEqual = ($assessment['db_version'] === $baseVer);
        $schemaEqual = ($assessment['db_schema'] === $baseSchema);
        if ($appEqual && $schemaEqual) {
            echo "  Match detail     : app version and schema version both match baseline\n";
        } elseif ($assessment['status'] === 'ahead') {
            echo "  Match detail     : not equal (expected when post-baseline patches are applied)\n";
        } else {
            echo "  Match detail     : app version matches baseline"
                . ($schemaEqual ? '; schema matches' : '; schema does NOT match') . "\n";
        }
    } elseif ($assessment['status'] === 'behind' && empty($assessment['requires_full_setup'])) {
        echo "  Match detail     : below current baseline — apply pending updates/*.sql (non-destructive)\n";
    } else {
        echo "  Match detail     : do NOT match — remediation required\n";
    }

    echo "\n";
    foreach ($assessment['messages'] as $msg) {
        $prefix = $assessment['requires_full_setup'] ? '  WARNING: ' : '  ';
        echo $prefix . $msg . "\n";
    }

    if ($assessment['requires_full_setup']) {
        echo "\n";
        echo "  ---------------------------------------------------------------------------\n";
        echo "  DO NOT apply newer updates/*.sql patches until the baseline is established.\n";
        echo "  DO NOT run full setup against a database that has live treasurer data.\n";
        echo "  Recommended steps:\n";
        echo "    1. Back up the database and any needed application data\n";
        echo "    2. Run: php setup_db.php   (destructive — requires confirmations)\n";
        echo "    3. Re-run: php setup_db.php --check\n";
        echo "    4. Then apply post-baseline patches from VERSION.md in order\n";
        echo "  ---------------------------------------------------------------------------\n";
    }

    echo "\n";

    // Always report whether updates/*.sql patches are still pending (messaging only).
    // When full setup is required first, still show lag so operators know work remains after baseline.
    setupDbPrintPendingUpdatesReport($db);

    return $assessment['ok'];
}

/**
 * Insert the frozen setup baseline history (through TEMPER_SETUP_BASELINE_APP_VERSION / 0.944).
 * For setup_db.php / 08-app-version.php only — never call on page load.
 * Does not seed post-0.944 rows; apply those via updates/*.sql after setup.
 */
function seedAppVersionHistory(mysqli $db): bool
{
    $stmt = $db->prepare(
        'INSERT INTO app_version (version, schema_version, patch_file, notes)
         VALUES (?, ?, ?, ?)'
    );
    if (!$stmt) {
        error_log('[app_version] Failed to prepare history seed: ' . $db->error);
        return false;
    }

    foreach (TEMPER_VERSION_HISTORY as $entry) {
        $version = (string)($entry['version'] ?? '');
        $schema = temperSchemaVersionId((string)($entry['schema_version'] ?? TEMPER_SCHEMA_BASELINE));
        $patch = isset($entry['patch_file']) && $entry['patch_file'] !== null && $entry['patch_file'] !== ''
            ? (string)$entry['patch_file']
            : null;
        $notes = isset($entry['notes']) && $entry['notes'] !== null && $entry['notes'] !== ''
            ? (string)$entry['notes']
            : null;

        if ($version === '') {
            continue;
        }

        $stmt->bind_param('ssss', $version, $schema, $patch, $notes);
        if (!$stmt->execute()) {
            error_log('[app_version] Failed to seed history row v' . $version . ': ' . $stmt->error);
            $stmt->close();
            return false;
        }
    }

    $stmt->close();
    return true;
}

/**
 * Read the current (latest) application version string from the database.
 * Falls back to TEMPER_DEFAULT_APP_VERSION / APP_VERSION if unavailable.
 */
function getAppVersion(?mysqli $db = null): string
{
    $fallback = defined('APP_VERSION') && is_string(APP_VERSION) && APP_VERSION !== ''
        ? APP_VERSION
        : TEMPER_DEFAULT_APP_VERSION;

    if (!$db instanceof mysqli) {
        return $fallback;
    }

    try {
        ensureAppVersionTable($db);
    } catch (RuntimeException $e) {
        // Sidebar / display paths: log already emitted; show codebase constant.
        return $fallback;
    }

    $res = $db->query(
        'SELECT version FROM app_version ORDER BY id DESC LIMIT 1'
    );
    if ($res) {
        $row = $res->fetch_assoc();
        $res->close();
        if ($row && isset($row['version']) && $row['version'] !== '') {
            return (string)$row['version'];
        }
    }

    return $fallback;
}

/**
 * Full latest version row for diagnostics / operator checks.
 *
 * @return array{version: string, schema_version: string, patch_file: ?string, notes: ?string, applied_at: ?string}
 */
function getAppVersionInfo(?mysqli $db = null): array
{
    $fallback = [
        'version' => getAppVersion(null),
        'schema_version' => TEMPER_EXPECTED_SCHEMA_VERSION,
        'patch_file' => null,
        'notes' => null,
        'applied_at' => null,
    ];

    if (!$db instanceof mysqli) {
        return $fallback;
    }

    try {
        ensureAppVersionTable($db);
    } catch (RuntimeException $e) {
        return $fallback;
    }

    // Prefer history-shaped table (v0.802+). Fall back to legacy single-row columns
    // so sidebar/diagnostics still work if a manual schema patch has not been applied yet.
    $res = $db->query(
        'SELECT version, schema_version, patch_file, notes, applied_at
         FROM app_version
         ORDER BY id DESC
         LIMIT 1'
    );
    if (!$res) {
        $res = $db->query(
            'SELECT version, schema_version, updated_at AS applied_at
             FROM app_version
             ORDER BY id DESC
             LIMIT 1'
        );
    }
    if ($res) {
        $row = $res->fetch_assoc();
        $res->close();
        if ($row) {
            $rawSchema = (string)($row['schema_version'] ?? '');
            $schema = temperNormalizeStoredSchemaVersion($rawSchema, $row['patch_file'] ?? null);

            return [
                'version' => (string)($row['version'] ?? $fallback['version']),
                'schema_version' => $schema,
                'patch_file' => isset($row['patch_file']) && $row['patch_file'] !== ''
                    ? (string)$row['patch_file']
                    : null,
                'notes' => isset($row['notes']) && $row['notes'] !== ''
                    ? (string)$row['notes']
                    : null,
                'applied_at' => $row['applied_at'] ?? null,
            ];
        }
    }

    return $fallback;
}

/**
 * Map legacy integer / empty schema_version values to canonical filename stems.
 * Does not mutate the database (read path only).
 */
function temperNormalizeStoredSchemaVersion(string $rawSchema, mixed $patchFile = null): string
{
    $rawSchema = trim($rawSchema);
    if ($rawSchema === '' || $rawSchema === '0') {
        if (is_string($patchFile) && $patchFile !== '') {
            return temperSchemaVersionId($patchFile);
        }
        return TEMPER_SCHEMA_BASELINE;
    }

    // Legacy integer schema generations (pre-0.803)
    if (preg_match('/^\d+$/', $rawSchema)) {
        $map = [
            '1' => TEMPER_SCHEMA_BASELINE,
            '2' => '20260725_01_app_version_history',
        ];
        if (isset($map[$rawSchema])) {
            return $map[$rawSchema];
        }
        if (is_string($patchFile) && $patchFile !== '') {
            return temperSchemaVersionId($patchFile);
        }
        return TEMPER_SCHEMA_BASELINE;
    }

    return temperSchemaVersionId($rawSchema);
}

/**
 * Return full version history (oldest → newest). Empty array if unavailable.
 *
 * @return list<array{id: int, version: string, schema_version: string, patch_file: ?string, notes: ?string, applied_at: ?string}>
 */
function getAppVersionHistory(?mysqli $db = null): array
{
    if (!$db instanceof mysqli) {
        return [];
    }

    try {
        ensureAppVersionTable($db);
    } catch (RuntimeException $e) {
        return [];
    }

    $res = $db->query(
        'SELECT id, version, schema_version, patch_file, notes, applied_at
         FROM app_version
         ORDER BY id ASC'
    );
    if (!$res) {
        return [];
    }

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = [
            'id' => (int)$row['id'],
            'version' => (string)$row['version'],
            'schema_version' => temperNormalizeStoredSchemaVersion(
                (string)($row['schema_version'] ?? ''),
                $row['patch_file'] ?? null
            ),
            'patch_file' => isset($row['patch_file']) && $row['patch_file'] !== ''
                ? (string)$row['patch_file']
                : null,
            'notes' => isset($row['notes']) && $row['notes'] !== ''
                ? (string)$row['notes']
                : null,
            'applied_at' => $row['applied_at'] ?? null,
        ];
    }
    $res->close();

    return $rows;
}

/**
 * Normalize a dotted app version string (e.g. 0.807). Empty if invalid.
 */
function temperNormalizeAppVersionString(string $version): string
{
    $version = trim($version);
    $version = ltrim($version, 'vV');
    if ($version === '' || !preg_match('/^\d+(?:\.\d+)+$/', $version)) {
        return '';
    }
    return $version;
}

/**
 * Keep the higher of two app version strings (version_compare). Empty loses.
 */
function temperMaxAppVersion(string $a, string $b): string
{
    $a = temperNormalizeAppVersionString($a);
    $b = temperNormalizeAppVersionString($b);
    if ($a === '') {
        return $b;
    }
    if ($b === '') {
        return $a;
    }
    return version_compare($a, $b) >= 0 ? $a : $b;
}

/**
 * Highest app version declared in VERSION.md (## vX.Y headings).
 */
function temperDiscoverLatestVersionFromVersionMd(?string $versionMdPath = null): string
{
    $path = $versionMdPath ?? (dirname(__DIR__) . '/VERSION.md');
    if (!is_readable($path)) {
        return '';
    }
    $content = @file_get_contents($path);
    if (!is_string($content) || $content === '') {
        return '';
    }
    $latest = '';
    if (preg_match_all('/^##\s+v?(\d+(?:\.\d+)+)\b/mi', $content, $matches)) {
        foreach ($matches[1] as $ver) {
            $latest = temperMaxAppVersion($latest, (string)$ver);
        }
    }
    return $latest;
}

/**
 * Highest app version declared in updates/*.sql patch headers ("App version : X.Y").
 * Does not apply patches — filesystem scan only.
 */
function temperDiscoverLatestVersionFromUpdatesDir(?string $updatesDir = null): string
{
    $dir = $updatesDir ?? (dirname(__DIR__) . '/updates');
    if (!is_dir($dir)) {
        return '';
    }
    $latest = '';
    $files = glob(rtrim($dir, '/\\') . '/*.sql') ?: [];
    foreach ($files as $file) {
        $base = basename($file);
        if ($base === '' || $base[0] === '_') {
            continue; // skip templates like _header_template.sql
        }
        $content = @file_get_contents($file);
        if (!is_string($content) || $content === '') {
            continue;
        }
        // Prefer explicit header field
        if (preg_match('/^\s*--\s*App version\s*:\s*v?(\d+(?:\.\d+)+)\b/mi', $content, $m)) {
            $latest = temperMaxAppVersion($latest, (string)$m[1]);
            continue;
        }
        // Fallback: INSERT history row version literal
        if (preg_match("/INSERT\\s+INTO\\s+app_version[\\s\\S]*?SELECT\\s+'(\\d+(?:\\.\\d+)+)'/i", $content, $m)) {
            $latest = temperMaxAppVersion($latest, (string)$m[1]);
        }
    }
    return $latest;
}

/**
 * Latest available application version known to this deployment.
 * Max of: APP_VERSION / TEMPER_DEFAULT_APP_VERSION, VERSION.md, updates/*.sql headers.
 * Cached per request. Never applies patches.
 */
function getLatestAvailableAppVersion(): string
{
    static $cached = null;
    if (is_string($cached)) {
        return $cached;
    }

    $latest = '';
    if (defined('APP_VERSION') && is_string(APP_VERSION)) {
        $latest = temperMaxAppVersion($latest, APP_VERSION);
    }
    $latest = temperMaxAppVersion($latest, TEMPER_DEFAULT_APP_VERSION);
    $latest = temperMaxAppVersion($latest, temperDiscoverLatestVersionFromVersionMd());
    $latest = temperMaxAppVersion($latest, temperDiscoverLatestVersionFromUpdatesDir());

    if ($latest === '') {
        $latest = TEMPER_DEFAULT_APP_VERSION;
    }

    $cached = $latest;
    return $cached;
}

/**
 * Whether the database's highest app_version is behind the latest known release.
 *
 * @return array{behind: bool, db_version: string, latest_version: string}
 */
function getDatabaseVersionLagStatus(?mysqli $db = null): array
{
    $latest = getLatestAvailableAppVersion();
    $dbVersion = getAppVersion($db);
    $dbVersion = temperNormalizeAppVersionString($dbVersion) ?: $dbVersion;
    $behind = $dbVersion !== ''
        && $latest !== ''
        && version_compare($dbVersion, $latest, '<');

    return [
        'behind' => $behind,
        'db_version' => $dbVersion,
        'latest_version' => $latest,
    ];
}

/**
 * Append a version history row (manual patch / release record).
 * Prefer recording via the SQL patch itself; this helper is for setup tooling.
 * Requires app_version table to already exist (does not create it).
 *
 * @param string $schemaVersion Patch filename stem (or TEMPER_SCHEMA_BASELINE). Required.
 * @param string|null $patchFile Full .sql basename when this release applied a patch; null if carry-forward.
 */
function recordAppVersion(
    mysqli $db,
    string $version,
    string $schemaVersion,
    ?string $patchFile = null,
    ?string $notes = null
): bool {
    $version = trim($version);
    if ($version === '') {
        return false;
    }

    $schema = temperSchemaVersionId($schemaVersion);
    if ($schema === '') {
        return false;
    }

    try {
        ensureAppVersionTable($db);
    } catch (RuntimeException $e) {
        return false;
    }

    $patch = ($patchFile !== null && trim($patchFile) !== '') ? trim($patchFile) : null;
    $note = ($notes !== null && trim($notes) !== '') ? trim($notes) : null;

    $stmt = $db->prepare(
        'INSERT INTO app_version (version, schema_version, patch_file, notes)
         VALUES (?, ?, ?, ?)'
    );
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ssss', $version, $schema, $patch, $note);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/**
 * @deprecated Use recordAppVersion(). Kept for callers that still pass a single current version.
 * Updates by appending a history row (does not mutate prior rows).
 * When $schemaVersion is null, carries forward TEMPER_EXPECTED_SCHEMA_VERSION.
 */
function setAppVersion(mysqli $db, string $version, ?string $schemaVersion = null): bool
{
    $schema = $schemaVersion !== null && trim($schemaVersion) !== ''
        ? $schemaVersion
        : TEMPER_EXPECTED_SCHEMA_VERSION;
    return recordAppVersion($db, $version, $schema, null, null);
}
