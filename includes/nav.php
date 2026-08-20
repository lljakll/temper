<?php
// Common Setup & Security
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/beancount_mass_import.php';

// Central session check (shell nav)
requireLogin();

// Get current user info + ACL for nav visibility
$user = getCurrentUser();
$navDb = getDbConnection();
$navAcl = $user ? loadUserAcl($navDb, (int)$user['id']) : null;
$navPerms = $navAcl['permissions'] ?? [];

function navCan(array $perms, string $permission): bool {
    return permissionSetAllows($perms, $permission);
}

$canDashboard = navCan($navPerms, 'page.dashboard');
$canLedger = navCan($navPerms, 'page.ledger');
$canLedgerImport = $navAcl && beancountMassImportUserCanAccess($navAcl);
$canReports = navCan($navPerms, 'page.reports');
$canBudget = navCan($navPerms, 'page.budget');
$canTasks = navCan($navPerms, 'page.tasks');
$canAdmin = navCan($navPerms, 'admin.access');
// Backup / Restore and Configuration are Administrator-only (role check)
$canBackup = $user && userIsAdministrator($navDb, (int)$user['id']);
$canDatabase = navCan($navPerms, 'admin.database');
$canLookups = navCan($navPerms, 'admin.lookups');
$canUsers = navCan($navPerms, 'users.manage');
$canConfig = $user && userIsAdministrator($navDb, (int)$user['id']);
$showAdminSection = $canAdmin || $canBackup || $canDatabase || $canLookups || $canUsers || $canConfig;

// Default SPA landing page by highest-priority available permission
$temperHomePage = 'profile';
if ($canDashboard) {
    $temperHomePage = 'dashboard';
} elseif ($canReports) {
    $temperHomePage = 'reports';
} elseif ($canLedger) {
    $temperHomePage = 'ledger';
}

// Forced password change overrides home page
$mustChangePassword = !empty($_SESSION['must_change_password']);
if ($mustChangePassword) {
    $temperHomePage = 'force-password';
}

// Role display: show all role names when multi-role
$navRoleLabel = '';
if ($navAcl) {
    $names = $navAcl['role_names'] ?? [($navAcl['role_name'] ?? '')];
    $navRoleLabel = implode(', ', array_filter($names));
}

// Also run auto-archive cleanup while building shell (lightweight, once/request)
if ($navDb instanceof mysqli) {
    archiveExpiredForcePasswordUsers($navDb);
}

// Sidebar versions: non-admins see application (code) version only.
// Administrators see App + DB side-by-side; DB portion turns red when behind latest.
require_once __DIR__ . '/app_version.php';
$navCodeAppVersion = defined('APP_VERSION') && is_string(APP_VERSION) && APP_VERSION !== ''
    ? APP_VERSION
    : TEMPER_DEFAULT_APP_VERSION;
$navDbAppVersion = ($navDb instanceof mysqli) ? getAppVersion($navDb) : getAppVersion(null);
$navIsAdministrator = $user && $navDb instanceof mysqli
    && userIsAdministrator($navDb, (int)$user['id']);
$navVersionLag = getDatabaseVersionLagStatus($navDb instanceof mysqli ? $navDb : null);
$navDbVersionOutdated = $navIsAdministrator && !empty($navVersionLag['behind']);
$navVersionLinkTitle = 'View changelog (VERSION.md)';
$navDbVersionTitle = $navDbVersionOutdated
    ? sprintf(
        'Database is at v%s — latest available is v%s. See VERSION.md or updates/ folder.',
        $navVersionLag['db_version'],
        $navVersionLag['latest_version']
    )
    : $navVersionLinkTitle;
$navVersionLinkClass = 'sidebar-version-link text-decoration-none';
$navVersionAriaLabel = $navIsAdministrator
    ? (
        $navDbVersionOutdated
            ? sprintf(
                'Application version %s, database version %s is behind latest %s; open changelog',
                $navCodeAppVersion,
                $navVersionLag['db_version'],
                $navVersionLag['latest_version']
            )
            : sprintf(
                'Application version %s, database version %s, open changelog',
                $navCodeAppVersion,
                $navDbAppVersion
            )
    )
    : 'Application version ' . $navCodeAppVersion . ', open changelog';

$navDb->close();
?>
<script>
window.__temperHomePage = <?= json_encode($temperHomePage) ?>;
window.__temperMustChangePassword = <?= $mustChangePassword ? 'true' : 'false' ?>;
</script>
<style>
/* Force-password mode: hide primary nav so users cannot leave the form */
body.temper-force-password-mode .sidebar-panel,
body.temper-force-password-mode #appSidebar,
body.temper-force-password-mode .mobile-bottom-nav {
    display: none !important;
}
body.temper-force-password-mode .mobile-topbar [data-bs-target="#appSidebar"] {
    display: none !important;
}
body.temper-force-password-mode #main-content-col {
    flex: 0 0 100%;
    max-width: 100%;
    width: 100%;
}
</style>
<?php

/**
 * Render primary navigation links (shared by offcanvas sidebar).
 */
if (!function_exists('temper_render_nav_links')) {
function temper_render_nav_links(
    bool $canDashboard,
    bool $canLedger,
    bool $canReports,
    bool $canBudget,
    bool $canTasks,
    bool $showAdminSection,
    bool $canAdmin,
    bool $canBackup,
    bool $canDatabase,
    bool $canLookups,
    bool $canUsers,
    bool $canConfig = false,
    bool $canLedgerImport = false
): void {
?>
        <ul class="nav flex-column w-100">
            <?php if ($canDashboard): ?>
            <li class="nav-item">
                <a href="javascript:void(0)" onclick="loadPage('dashboard')" class="nav-link" data-nav-page="dashboard" title="Dashboard">
                    <i class="bi bi-speedometer2" aria-hidden="true"></i>
                    <span class="sidebar-label">Dashboard</span>
                </a>
            </li>
            <?php endif; ?>
            <?php if ($canLedger): ?>
            <li class="nav-item">
                <?php if ($canLedgerImport): ?>
                <a class="nav-link" data-bs-toggle="collapse" href="#ledgerCollapse" role="button" aria-expanded="false" aria-controls="ledgerCollapse" title="Ledger">
                    <i class="bi bi-currency-dollar" aria-hidden="true"></i>
                    <span class="sidebar-label">Ledger</span>
                </a>
                <div class="collapse" id="ledgerCollapse">
                    <ul class="nav flex-column ms-3">
                        <li class="nav-item">
                            <a href="javascript:void(0)" onclick="loadPage('ledger')" class="nav-link" data-nav-page="ledger" title="Ledger">
                                <i class="bi bi-journal-text" aria-hidden="true"></i>
                                <span class="sidebar-label">Ledger</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="javascript:void(0)" onclick="loadPage('ledger_import')" class="nav-link" data-nav-page="ledger_import" title="Import">
                                <i class="bi bi-box-arrow-in-down" aria-hidden="true"></i>
                                <span class="sidebar-label">Import</span>
                            </a>
                        </li>
                    </ul>
                </div>
                <?php else: ?>
                <a href="javascript:void(0)" onclick="loadPage('ledger')" class="nav-link" data-nav-page="ledger" title="Ledger">
                    <i class="bi bi-currency-dollar" aria-hidden="true"></i>
                    <span class="sidebar-label">Ledger</span>
                </a>
                <?php endif; ?>
            </li>
            <?php endif; ?>
            <?php if ($canReports): ?>
            <li class="nav-item">
                <a href="javascript:void(0)" onclick="loadPage('reports')" class="nav-link" data-nav-page="reports" title="Reports">
                    <i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i>
                    <span class="sidebar-label">Reports</span>
                </a>
            </li>
            <?php endif; ?>
            <?php if ($canBudget): ?>
            <li class="nav-item">
                <a href="javascript:void(0)" onclick="loadPage('budget')" class="nav-link" data-nav-page="budget" title="Budget">
                    <i class="bi bi-graph-up" aria-hidden="true"></i>
                    <span class="sidebar-label">Budget</span>
                </a>
            </li>
            <?php endif; ?>
            <?php if ($canTasks): ?>
            <li class="nav-item">
                <a href="javascript:void(0)" onclick="loadPage('tasks')" class="nav-link" data-nav-page="tasks" title="Tasks / Reminders">
                    <i class="bi bi-check2-square" aria-hidden="true"></i>
                    <span class="sidebar-label">Tasks / Reminders</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if ($showAdminSection): ?>
            <!-- System Section (formerly Admin) -->
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="collapse" href="#adminCollapse" role="button" aria-expanded="false" aria-controls="adminCollapse" title="System">
                    <i class="bi bi-gear" aria-hidden="true"></i>
                    <span class="sidebar-label">System</span>
                </a>
                <div class="collapse" id="adminCollapse">
                    <ul class="nav flex-column ms-3">
                        <?php if ($canAdmin): ?>
                        <li class="nav-item">
                            <a href="javascript:void(0)" onclick="loadPage('admin')" class="nav-link" data-nav-page="admin" title="Overview">
                                <i class="bi bi-grid" aria-hidden="true"></i>
                                <span class="sidebar-label">Overview</span>
                            </a>
                        </li>
                        <?php endif; ?>
                        <?php if ($canConfig): ?>
                        <li class="nav-item">
                            <a href="javascript:void(0)" onclick="loadPage('admin-config')" class="nav-link" data-nav-page="admin-config" title="Configuration">
                                <i class="bi bi-sliders" aria-hidden="true"></i>
                                <span class="sidebar-label">Configuration</span>
                            </a>
                        </li>
                        <?php endif; ?>
                        <?php if ($canUsers): ?>
                        <li class="nav-item">
                            <a href="javascript:void(0)" onclick="loadPage('admin-users')" class="nav-link" data-nav-page="admin-users" title="Users &amp; Roles">
                                <i class="bi bi-people" aria-hidden="true"></i>
                                <span class="sidebar-label">Users &amp; Roles</span>
                            </a>
                        </li>
                        <?php endif; ?>
                        <?php if ($canBackup): ?>
                        <li class="nav-item">
                            <a href="javascript:void(0)" onclick="loadPage('admin-backup')" class="nav-link" data-nav-page="admin-backup" title="Backup / Restore (data-only)">
                                <i class="bi bi-cloud-arrow-up" aria-hidden="true"></i>
                                <span class="sidebar-label">Backup / Restore</span>
                            </a>
                        </li>
                        <?php endif; ?>
                        <?php if ($canDatabase): ?>
                        <li class="nav-item">
                            <a href="javascript:void(0)" onclick="loadPage('admin-database')" class="nav-link" data-nav-page="admin-database" title="Database Maintenance">
                                <i class="bi bi-database-gear" aria-hidden="true"></i>
                                <span class="sidebar-label">Database Maintenance</span>
                            </a>
                        </li>
                        <?php endif; ?>
                        <?php if ($canLookups): ?>
                        <li class="nav-item">
                            <a class="nav-link" data-bs-toggle="collapse" href="#lookupsCollapse" role="button" aria-expanded="false" aria-controls="lookupsCollapse" title="Lookups">
                                <i class="bi bi-list" aria-hidden="true"></i>
                                <span class="sidebar-label">Lookups</span>
                            </a>
                            <div class="collapse" id="lookupsCollapse">
                                <ul class="nav flex-column ms-3">
                                    <li class="nav-item">
                                        <a href="javascript:void(0)" onclick="loadPage('setup_funds')" class="nav-link" data-nav-page="setup_funds" title="Funds">
                                            <i class="bi bi-wallet2" aria-hidden="true"></i>
                                            <span class="sidebar-label">Funds</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="javascript:void(0)" onclick="loadPage('setup_accounts')" class="nav-link" data-nav-page="setup_accounts" title="Accounts">
                                            <i class="bi bi-credit-card" aria-hidden="true"></i>
                                            <span class="sidebar-label">Accounts</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="javascript:void(0)" onclick="loadPage('setup_naturalclasses')" class="nav-link" data-nav-page="setup_naturalclasses" title="Natural Classes">
                                            <i class="bi bi-tag" aria-hidden="true"></i>
                                            <span class="sidebar-label">Natural Classes</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="javascript:void(0)" onclick="loadPage('setup_functionalclasses')" class="nav-link" data-nav-page="setup_functionalclasses" title="Functional Classes">
                                            <i class="bi bi-tags" aria-hidden="true"></i>
                                            <span class="sidebar-label">Functional Classes</span>
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </li>
            <?php endif; ?>
        </ul>
<?php
}
} // end function_exists
?>

<!-- Mobile top bar: hamburger + brand -->
<div class="mobile-topbar d-md-none sticky-top rounded-3 mb-2 px-2 py-2 d-flex align-items-center gap-2 shadow-sm">
    <?php if (!$mustChangePassword): ?>
    <button class="btn btn-outline-secondary btn-sm px-2" type="button"
            data-bs-toggle="offcanvas" data-bs-target="#appSidebar" aria-controls="appSidebar"
            aria-label="Open navigation menu">
        <i class="bi bi-list fs-5"></i>
    </button>
    <?php endif; ?>
    <div class="d-flex align-items-center flex-grow-1 min-w-0 text-body">
        <i class="bi bi-bank me-2"></i>
        <strong class="text-truncate"><?= $mustChangePassword ? 'Set your password' : 'Hope Baptist Treasurer' ?></strong>
    </div>
    <a href="logout.php" class="btn btn-outline-secondary btn-sm px-2" title="Logout" aria-label="Logout">
        <i class="bi bi-box-arrow-right"></i>
    </a>
</div>

<div class="row g-2">
    <?php if (!$mustChangePassword): ?>
    <!-- Sidebar: offcanvas below md; fixed floating collapsible rail at md+ -->
    <div class="col-md-2 temper-sidebar-col" id="temperSidebarCol">
        <div class="offcanvas-md offcanvas-start rounded-3 shadow-sm border sidebar-panel"
             tabindex="-1" id="appSidebar" aria-labelledby="appSidebarLabel">
            <div class="offcanvas-header border-bottom d-md-none">
                <h5 class="offcanvas-title text-body" id="appSidebarLabel">
                    <i class="bi bi-bank me-1"></i> Menu
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas"
                        data-bs-target="#appSidebar" aria-label="Close"></button>
            </div>
            <div class="offcanvas-body d-flex flex-column p-3 rounded-3">
                <!-- Brand (desktop) + collapse toggle -->
                <div class="sidebar-brand d-none d-md-flex align-items-center pb-2 mb-3 border-bottom position-relative">
                    <i class="bi bi-bank me-2 fs-5" aria-hidden="true"></i>
                    <strong class="text-body sidebar-brand-text text-truncate">Hope Baptist Treasurer</strong>
                    <button type="button" class="btn sidebar-toggle ms-auto" id="sidebarToggle"
                            title="Collapse sidebar" aria-label="Collapse sidebar" aria-expanded="true"
                            aria-controls="appSidebar">
                        <i class="bi bi-chevron-double-left" id="sidebarToggleIcon" aria-hidden="true"></i>
                    </button>
                </div>

                <?php temper_render_nav_links(
                    $canDashboard,
                    $canLedger,
                    $canReports,
                    $canBudget,
                    $canTasks,
                    $showAdminSection,
                    $canAdmin,
                    $canBackup,
                    $canDatabase,
                    $canLookups,
                    $canUsers,
                    $canConfig,
                    $canLedgerImport
                ); ?>

                <!-- Bottom: footer note + version + user info + logout -->
                <div class="pt-3 mt-auto">
                    <hr class="sidebar-divider">
                    <small class="sidebar-footnote d-block mb-3">Based on Treasurer's Guide, Rev 1.0</small>

                    <div class="small sidebar-version mb-2">
                        <a href="VERSION.md" target="_blank" rel="noopener noreferrer"
                           class="<?= htmlspecialchars($navVersionLinkClass) ?>"
                           title="<?= htmlspecialchars($navVersionLinkTitle) ?>"
                           aria-label="<?= htmlspecialchars($navVersionAriaLabel) ?>">
                            <i class="bi bi-tag me-1" aria-hidden="true"></i>
                            <?php if ($navIsAdministrator): ?>
                            <span class="sidebar-label sidebar-version-dual">
                                <span class="sidebar-version-app">App: v<?= htmlspecialchars($navCodeAppVersion) ?></span>
                                <span class="sidebar-version-sep" aria-hidden="true"> </span>
                                <span class="sidebar-version-db<?= $navDbVersionOutdated ? ' sidebar-version-outdated' : '' ?>"
                                      <?php if ($navDbVersionOutdated): ?>
                                      title="<?= htmlspecialchars($navDbVersionTitle) ?>"
                                      <?php endif; ?>>
                                    DB: v<?= htmlspecialchars($navDbAppVersion) ?>
                                </span>
                            </span>
                            <?php else: ?>
                            <span class="sidebar-label">v<?= htmlspecialchars($navCodeAppVersion) ?></span>
                            <?php endif; ?>
                        </a>
                    </div>

                    <div class="small sidebar-welcome mb-1">
                        Welcome, <strong><?= htmlspecialchars($user['name'] ?? 'User') ?></strong>
                        <?php if ($navRoleLabel !== ''): ?>
                            <div class="text-muted" style="font-size: 0.75rem;"><?= htmlspecialchars($navRoleLabel) ?></div>
                        <?php endif; ?>
                    </div>
                    <a href="javascript:void(0)" onclick="loadPage('profile')" class="btn btn-sm w-100 btn-outline-secondary d-flex align-items-center justify-content-center gap-1 mb-2 sidebar-action-btn" data-nav-page="profile" title="My Profile">
                        <i class="bi bi-person-circle" aria-hidden="true"></i>
                        <span class="sidebar-btn-label">My Profile</span>
                    </a>
                    <a href="logout.php" class="btn btn-sm w-100 btn-outline-secondary d-flex align-items-center justify-content-center gap-1 sidebar-action-btn" title="Logout">
                        <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
                        <span class="sidebar-btn-label">Logout</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="col-12 <?= $mustChangePassword ? 'col-md-12' : 'col-md-10' ?> p-2 p-md-3" id="main-content-col">
