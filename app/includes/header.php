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

// Database connection (only create if not already set)
if (!isset($db)) {
    $db = getDbConnection();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?? "Hope Baptist Treasurer" ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <style>
        .collapse.show {
            display: block !important;
        }
    </style>
</head>
    <script>
    // Global function to load content via AJAX
    function loadPage(page) {
        const contentArea = document.getElementById('main-content');
        
        // Show loading indicator
        contentArea.innerHTML = '<div class="text-center p-5"><div class="spinner-border text-primary" role="status"></div><p class="mt-3">Loading...</p></div>';
        
        fetch(`pages/${page}.php`)
            .then(response => {
                if (!response.ok) throw new Error('Page not found');
                return response.text();
            })
            .then(html => {
                contentArea.innerHTML = html;
            })
            .catch(error => {
                console.error('Error:', error);
                contentArea.innerHTML = `
                    <div class="alert alert-danger p-4">
                        <h5>Error loading page</h5>
                        <p>Could not load ${page}.php. Please try again.</p>
                    </div>`;
            });
    }

    // Load default dashboard on initial page load
    document.addEventListener('DOMContentLoaded', function() {
        loadPage('dashboard');
    });
    </script>
<body class="bg-light">

<!-- Simple Top Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm mb-4">
    <div class="container-fluid px-4">
        <!-- Logo / Brand -->
        <a class="navbar-brand d-flex align-items-center" href="index.php">
            <i class="bi bi-bank me-2"></i>
            Hope Baptist Treasurer
        </a>

        <!-- User Info & Logout -->
        <div class="d-flex align-items-center gap-3">
            <span class="text-light">
                Welcome, <strong><?= htmlspecialchars($user['name'] ?? 'Admin') ?></strong>
            </span>
            <a href="logout.php" class="btn btn-outline-light btn-sm">
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </div>
    </div>
</nav>

<div class="container-fluid px-4">