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

<div class="row">
    <div class="col-md-2 bg-dark text-white min-vh-100 p-3">

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
            
            <!-- Setup Section -->
            <li class="nav-item">
                <a class="nav-link text-white" data-bs-toggle="collapse" href="#setupCollapse" role="button" aria-expanded="false" aria-controls="setupCollapse">
                    <i class="bi bi-gear"></i> Setup
                </a>
                <div class="collapse" id="setupCollapse">
                    <ul class="nav flex-column ms-3">
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
        <hr class="bg-white">
        <small class="text-muted">Based on Treasurer's Guide, Rev 1.0</small>
    </div>
    <div class="col-md-10 p-4">