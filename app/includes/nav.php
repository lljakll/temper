<?php
// Common Setup & Security
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

// Redirect if not logged in
if (!isLoggedIn()) {
    header('Location: ../login.php');
    exit;
}

// Get current user info
$user = getCurrentUser();
?>

<div class="row g-2">
    <div class="col-md-2 sidebar bg-dark text-white p-3 position-sticky top-2 rounded-3 d-flex flex-column shadow-sm border border-white border-opacity-25">

        <!-- Brand / Title -->
        <div class="d-flex align-items-center pb-2 mb-3 border-bottom border-secondary">
            <i class="bi bi-bank me-2 fs-5"></i>
            <strong>Hope Baptist Treasurer</strong>
        </div>

        <ul class="nav flex-column">
            <li class="nav-item">
                <a href="javascript:void(0)" onclick="loadPage('dashboard')" class="nav-link text-white">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a href="javascript:void(0)" onclick="loadPage('ledger')" class="nav-link text-white">
                    <i class="bi bi-currency-dollar"></i> Ledger
                </a>
            </li>
            <li class="nav-item">
                <a href="javascript:void(0)" onclick="loadPage('reports')" class="nav-link text-white">
                    <i class="bi bi-file-earmark-bar-graph"></i> Reports
                </a>
            </li>
            <li class="nav-item">
                <a href="javascript:void(0)" onclick="loadPage('budget')" class="nav-link text-white">
                    <i class="bi bi-graph-up"></i> Budget
                </a>
            </li>
            <li class="nav-item">
                <a href="javascript:void(0)" onclick="loadPage('tasks')" class="nav-link text-white">
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
                            <a href="javascript:void(0)" onclick="loadPage('admin')" class="nav-link text-white">
                                <i class="bi bi-grid"></i> Overview
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="javascript:void(0)" onclick="loadPage('admin-backup')" class="nav-link text-white">
                                <i class="bi bi-cloud-arrow-up"></i> Backup / Restore
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white" data-bs-toggle="collapse" href="#lookupsCollapse" role="button" aria-expanded="false" aria-controls="lookupsCollapse">
                                <i class="bi bi-list"></i> Lookups
                            </a>
                            <div class="collapse" id="lookupsCollapse">
                                <ul class="nav flex-column ms-3">
                                    <li class="nav-item">
                                        <a href="javascript:void(0)" onclick="loadPage('setup_funds')" class="nav-link text-white">
                                            <i class="bi bi-wallet2"></i> Funds
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="javascript:void(0)" onclick="loadPage('setup_accounts')" class="nav-link text-white">
                                            <i class="bi bi-credit-card"></i> Accounts
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="javascript:void(0)" onclick="loadPage('setup_naturalclasses')" class="nav-link text-white">
                                            <i class="bi bi-tag"></i> Natural Classes
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="javascript:void(0)" onclick="loadPage('setup_functionalclasses')" class="nav-link text-white">
                                            <i class="bi bi-tags"></i> Functional Classes
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </li>
                    </ul>
                </div>
            </li>
        </ul>

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
    <div class="col-md-10 p-3">