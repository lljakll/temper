<?php
// Common Setup & Security
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/permissions.php';

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
$canReports = navCan($navPerms, 'page.reports');
$canBudget = navCan($navPerms, 'page.budget');
$canTasks = navCan($navPerms, 'page.tasks');
$canAdmin = navCan($navPerms, 'admin.access');
$canBackup = navCan($navPerms, 'admin.backup');
$canDatabase = navCan($navPerms, 'admin.database');
$canLookups = navCan($navPerms, 'admin.lookups');
$canUsers = navCan($navPerms, 'users.manage');
// Configuration is Administrator-only (role check, not just permission)
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
    bool $canConfig = false
): void {
?>
        <ul class="nav flex-column w-100">
            <?php if ($canDashboard): ?>
            <li class="nav-item">
                <a href="javascript:void(0)" onclick="loadPage('dashboard')" class="nav-link" data-nav-page="dashboard">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </a>
            </li>
            <?php endif; ?>
            <?php if ($canLedger): ?>
            <li class="nav-item">
                <a href="javascript:void(0)" onclick="loadPage('ledger')" class="nav-link" data-nav-page="ledger">
                    <i class="bi bi-currency-dollar"></i> Ledger
                </a>
            </li>
            <?php endif; ?>
            <?php if ($canReports): ?>
            <li class="nav-item">
                <a href="javascript:void(0)" onclick="loadPage('reports')" class="nav-link" data-nav-page="reports">
                    <i class="bi bi-file-earmark-bar-graph"></i> Reports
                </a>
            </li>
            <?php endif; ?>
            <?php if ($canBudget): ?>
            <li class="nav-item">
                <a href="javascript:void(0)" onclick="loadPage('budget')" class="nav-link" data-nav-page="budget">
                    <i class="bi bi-graph-up"></i> Budget
                </a>
            </li>
            <?php endif; ?>
            <?php if ($canTasks): ?>
            <li class="nav-item">
                <a href="javascript:void(0)" onclick="loadPage('tasks')" class="nav-link" data-nav-page="tasks">
                    <i class="bi bi-check2-square"></i> Tasks / Reminders
                </a>
            </li>
            <?php endif; ?>

            <?php if ($showAdminSection): ?>
            <!-- System Section (formerly Admin) -->
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="collapse" href="#adminCollapse" role="button" aria-expanded="false" aria-controls="adminCollapse">
                    <i class="bi bi-gear"></i> System
                </a>
                <div class="collapse" id="adminCollapse">
                    <ul class="nav flex-column ms-3">
                        <?php if ($canAdmin): ?>
                        <li class="nav-item">
                            <a href="javascript:void(0)" onclick="loadPage('admin')" class="nav-link" data-nav-page="admin">
                                <i class="bi bi-grid"></i> Overview
                            </a>
                        </li>
                        <?php endif; ?>
                        <?php if ($canConfig): ?>
                        <li class="nav-item">
                            <a href="javascript:void(0)" onclick="loadPage('admin-config')" class="nav-link" data-nav-page="admin-config">
                                <i class="bi bi-sliders"></i> Configuration
                            </a>
                        </li>
                        <?php endif; ?>
                        <?php if ($canUsers): ?>
                        <li class="nav-item">
                            <a href="javascript:void(0)" onclick="loadPage('admin-users')" class="nav-link" data-nav-page="admin-users">
                                <i class="bi bi-people"></i> Users &amp; Roles
                            </a>
                        </li>
                        <?php endif; ?>
                        <?php if ($canBackup): ?>
                        <li class="nav-item">
                            <a href="javascript:void(0)" onclick="loadPage('admin-backup')" class="nav-link" data-nav-page="admin-backup">
                                <i class="bi bi-cloud-arrow-up"></i> Backup / Restore
                            </a>
                        </li>
                        <?php endif; ?>
                        <?php if ($canDatabase): ?>
                        <li class="nav-item">
                            <a href="javascript:void(0)" onclick="loadPage('admin-database')" class="nav-link" data-nav-page="admin-database">
                                <i class="bi bi-database-gear"></i> Database Maintenance
                            </a>
                        </li>
                        <?php endif; ?>
                        <?php if ($canLookups): ?>
                        <li class="nav-item">
                            <a class="nav-link" data-bs-toggle="collapse" href="#lookupsCollapse" role="button" aria-expanded="false" aria-controls="lookupsCollapse">
                                <i class="bi bi-list"></i> Lookups
                            </a>
                            <div class="collapse" id="lookupsCollapse">
                                <ul class="nav flex-column ms-3">
                                    <li class="nav-item">
                                        <a href="javascript:void(0)" onclick="loadPage('setup_funds')" class="nav-link" data-nav-page="setup_funds">
                                            <i class="bi bi-wallet2"></i> Funds
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="javascript:void(0)" onclick="loadPage('setup_accounts')" class="nav-link" data-nav-page="setup_accounts">
                                            <i class="bi bi-credit-card"></i> Accounts
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="javascript:void(0)" onclick="loadPage('setup_naturalclasses')" class="nav-link" data-nav-page="setup_naturalclasses">
                                            <i class="bi bi-tag"></i> Natural Classes
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="javascript:void(0)" onclick="loadPage('setup_functionalclasses')" class="nav-link" data-nav-page="setup_functionalclasses">
                                            <i class="bi bi-tags"></i> Functional Classes
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
    <!-- Sidebar: offcanvas below md, sticky column at md+ -->
    <div class="col-md-2">
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
                <!-- Brand (desktop) -->
                <div class="sidebar-brand d-none d-md-flex align-items-center pb-2 mb-3 border-bottom">
                    <i class="bi bi-bank me-2 fs-5"></i>
                    <strong class="text-body">Hope Baptist Treasurer</strong>
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
                    $canConfig
                ); ?>

                <!-- Bottom: footer note + user info + logout -->
                <div class="pt-3 mt-auto">
                    <hr class="sidebar-divider">
                    <small class="sidebar-footnote d-block mb-3">Based on Treasurer's Guide, Rev 1.0</small>

                    <div class="small sidebar-welcome mb-1">
                        Welcome, <strong><?= htmlspecialchars($user['name'] ?? 'User') ?></strong>
                        <?php if ($navRoleLabel !== ''): ?>
                            <div class="text-muted" style="font-size: 0.75rem;"><?= htmlspecialchars($navRoleLabel) ?></div>
                        <?php endif; ?>
                    </div>
                    <a href="javascript:void(0)" onclick="loadPage('profile')" class="btn btn-sm w-100 btn-outline-secondary d-flex align-items-center justify-content-center gap-1 mb-2" data-nav-page="profile">
                        <i class="bi bi-person-circle"></i> My Profile
                    </a>
                    <a href="logout.php" class="btn btn-sm w-100 btn-outline-secondary d-flex align-items-center justify-content-center gap-1">
                        <i class="bi bi-box-arrow-right"></i> Logout
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="col-12 <?= $mustChangePassword ? 'col-md-12' : 'col-md-10' ?> p-2 p-md-3" id="main-content-col">
