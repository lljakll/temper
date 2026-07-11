<?php
// Common Setup & Security
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

// Central session check (shell nav)
requireLogin();

// Get current user info
$user = getCurrentUser();
$navDb = getDbConnection();
$tellerLimited = $user ? isTellerLimitedUser($navDb, (int)$user['id']) : false;
$navDb->close();

/**
 * Render primary navigation links (shared by offcanvas sidebar).
 */
if (!function_exists('temper_render_nav_links')) {
function temper_render_nav_links(bool $tellerLimited): void {
?>
        <ul class="nav flex-column w-100">
            <?php if (!$tellerLimited): ?>
            <li class="nav-item">
                <a href="javascript:void(0)" onclick="loadPage('dashboard')" class="nav-link text-white" data-nav-page="dashboard">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a href="javascript:void(0)" onclick="loadPage('ledger')" class="nav-link text-white" data-nav-page="ledger">
                    <i class="bi bi-currency-dollar"></i> Ledger
                </a>
            </li>
            <li class="nav-item">
                <a href="javascript:void(0)" onclick="loadPage('reports')" class="nav-link text-white" data-nav-page="reports">
                    <i class="bi bi-file-earmark-bar-graph"></i> Reports
                </a>
            </li>
            <li class="nav-item">
                <a href="javascript:void(0)" onclick="loadPage('budget')" class="nav-link text-white" data-nav-page="budget">
                    <i class="bi bi-graph-up"></i> Budget
                </a>
            </li>
            <?php endif; ?>
            <li class="nav-item">
                <a href="javascript:void(0)" onclick="loadPage('workflows')" class="nav-link text-white" data-nav-page="workflows">
                    <i class="bi bi-diagram-3"></i> Workflows
                </a>
            </li>
            <?php if (!$tellerLimited): ?>
            <li class="nav-item">
                <a href="javascript:void(0)" onclick="loadPage('tasks')" class="nav-link text-white" data-nav-page="tasks">
                    <i class="bi bi-check2-square"></i> Tasks / Reminders
                </a>
            </li>

            <!-- Admin Section -->
            <li class="nav-item">
                <a class="nav-link text-white" data-bs-toggle="collapse" href="#adminCollapse" role="button" aria-expanded="false" aria-controls="adminCollapse">
                    <i class="bi bi-gear"></i> Admin
                </a>
                <div class="collapse" id="adminCollapse">
                    <ul class="nav flex-column ms-3">
                        <li class="nav-item">
                            <a href="javascript:void(0)" onclick="loadPage('admin')" class="nav-link text-white" data-nav-page="admin">
                                <i class="bi bi-grid"></i> Overview
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="javascript:void(0)" onclick="loadPage('admin-backup')" class="nav-link text-white" data-nav-page="admin-backup">
                                <i class="bi bi-cloud-arrow-up"></i> Backup / Restore
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="javascript:void(0)" onclick="loadPage('admin-database')" class="nav-link text-white" data-nav-page="admin-database">
                                <i class="bi bi-database-gear"></i> Database Maintenance
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white" data-bs-toggle="collapse" href="#lookupsCollapse" role="button" aria-expanded="false" aria-controls="lookupsCollapse">
                                <i class="bi bi-list"></i> Lookups
                            </a>
                            <div class="collapse" id="lookupsCollapse">
                                <ul class="nav flex-column ms-3">
                                    <li class="nav-item">
                                        <a href="javascript:void(0)" onclick="loadPage('setup_funds')" class="nav-link text-white" data-nav-page="setup_funds">
                                            <i class="bi bi-wallet2"></i> Funds
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="javascript:void(0)" onclick="loadPage('setup_accounts')" class="nav-link text-white" data-nav-page="setup_accounts">
                                            <i class="bi bi-credit-card"></i> Accounts
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="javascript:void(0)" onclick="loadPage('setup_naturalclasses')" class="nav-link text-white" data-nav-page="setup_naturalclasses">
                                            <i class="bi bi-tag"></i> Natural Classes
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="javascript:void(0)" onclick="loadPage('setup_functionalclasses')" class="nav-link text-white" data-nav-page="setup_functionalclasses">
                                            <i class="bi bi-tags"></i> Functional Classes
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </li>
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
<div class="mobile-topbar d-md-none sticky-top bg-dark text-white rounded-3 mb-2 px-2 py-2 d-flex align-items-center gap-2 shadow-sm">
    <button class="btn btn-outline-light btn-sm px-2" type="button"
            data-bs-toggle="offcanvas" data-bs-target="#appSidebar" aria-controls="appSidebar"
            aria-label="Open navigation menu">
        <i class="bi bi-list fs-5"></i>
    </button>
    <div class="d-flex align-items-center flex-grow-1 min-w-0">
        <i class="bi bi-bank me-2"></i>
        <strong class="text-truncate">Hope Baptist Treasurer</strong>
    </div>
    <a href="logout.php" class="btn btn-outline-light btn-sm px-2" title="Logout" aria-label="Logout">
        <i class="bi bi-box-arrow-right"></i>
    </a>
</div>

<div class="row g-2">
    <!-- Sidebar: offcanvas below md, sticky column at md+ -->
    <div class="col-md-2">
        <div class="offcanvas-md offcanvas-start text-bg-dark rounded-3 shadow-sm border border-white border-opacity-25 sidebar-panel"
             tabindex="-1" id="appSidebar" aria-labelledby="appSidebarLabel">
            <div class="offcanvas-header border-bottom border-secondary d-md-none">
                <h5 class="offcanvas-title" id="appSidebarLabel">
                    <i class="bi bi-bank me-1"></i> Menu
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"
                        data-bs-target="#appSidebar" aria-label="Close"></button>
            </div>
            <div class="offcanvas-body d-flex flex-column p-3 bg-dark text-white rounded-3">
                <!-- Brand (desktop) -->
                <div class="d-none d-md-flex align-items-center pb-2 mb-3 border-bottom border-secondary">
                    <i class="bi bi-bank me-2 fs-5"></i>
                    <strong>Hope Baptist Treasurer</strong>
                </div>

                <?php temper_render_nav_links($tellerLimited); ?>

                <!-- Bottom: footer note + user info + logout -->
                <div class="pt-3 mt-auto">
                    <hr class="bg-white border-secondary opacity-50">
                    <small class="text-muted d-block mb-3">Based on Treasurer's Guide, Rev 1.0</small>

                    <div class="small text-white-50 mb-1">
                        Welcome, <strong class="text-white"><?= htmlspecialchars($user['name'] ?? 'Admin') ?></strong>
                    </div>
                    <a href="logout.php" class="btn btn-sm w-100 btn-light text-dark d-flex align-items-center justify-content-center gap-1">
                        <i class="bi bi-box-arrow-right"></i> Logout
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-md-10 p-2 p-md-3" id="main-content-col">
