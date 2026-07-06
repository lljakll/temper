<?php
// Common Setup & Security
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

// Redirect if not logged in
if (!isLoggedIn()) {
    header('Location: ../login.php');
    exit;
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
        .sidebar {
            height: calc(100vh - 1rem);
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
                if (typeof applyMainContent === 'function') {
                    applyMainContent(html);
                } else {
                    contentArea.innerHTML = html;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                contentArea.innerHTML = '<div class="text-muted small p-4">Page failed to load. See notification above.</div>';
                if (typeof showToast === 'function') {
                    showToast('Could not load ' + page + '.php. Please try again.', 'danger');
                }
            });
    }

    // Load default dashboard on initial page load
    document.addEventListener('DOMContentLoaded', function() {
        loadPage('dashboard');
    });
    </script>
<body class="bg-light">

<div class="container-fluid p-2">