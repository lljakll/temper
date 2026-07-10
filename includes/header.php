<?php
// Common Setup & Security
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

// Central session check (full-page shell)
requireLogin();
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
    // Central session-expiry handling for the SPA shell.
    // On 401 / X-Auth-Required: redirect to login immediately and suppress follow-on error toasts.
    (function() {
        const LOGIN_EXPIRED = 'login.php?expired=1';

        window.__temperAuthRedirecting = false;

        window.redirectToLoginExpired = function() {
            if (window.__temperAuthRedirecting) return;
            window.__temperAuthRedirecting = true;
            try {
                const c = document.getElementById('appToastContainer');
                if (c) c.innerHTML = '';
            } catch (e) { /* ignore */ }
            // replace() avoids back-button returning to a dead authenticated shell
            window.location.replace(LOGIN_EXPIRED);
        };

        window.isAuthExpiredResponse = function(response) {
            if (!response) return false;
            if (response.status === 401) return true;
            try {
                const h = response.headers && response.headers.get
                    ? response.headers.get('X-Auth-Required')
                    : null;
                if (h === '1' || (h && String(h).toLowerCase() === 'true')) return true;
            } catch (e) { /* ignore */ }
            return false;
        };

        window.isAuthExpiredPayload = function(payload) {
            if (payload == null) return false;
            if (typeof payload === 'string') {
                const t = payload.trim();
                if (t === 'AUTH_REQUIRED') return true;
                if (t.indexOf('"auth_required"') !== -1 && t.indexOf('true') !== -1) {
                    try {
                        const o = JSON.parse(t);
                        return !!(o && o.auth_required);
                    } catch (e) { /* fall through */ }
                }
                return false;
            }
            if (typeof payload === 'object') {
                return payload.auth_required === true || payload.auth_required === 1 || payload.auth_required === '1';
            }
            return false;
        };

        window.redirectToLoginIfSessionExpired = function(response) {
            if (!window.isAuthExpiredResponse(response)) return false;
            window.redirectToLoginExpired();
            return true;
        };

        // Never-resolving promise: stops .then/.catch chains from showing "failed" toasts
        // while the browser navigates to login.
        function authRedirectHang() {
            return new Promise(function() { /* intentionally pending */ });
        }

        const originalFetch = window.fetch.bind(window);
        window.fetch = function() {
            return originalFetch.apply(this, arguments).then(function(response) {
                if (window.redirectToLoginIfSessionExpired(response)) {
                    return authRedirectHang();
                }
                return response;
            });
        };

        // jQuery AJAX (if used later)
        if (window.jQuery) {
            window.jQuery(document).ajaxError(function(_event, jqXHR) {
                if (jqXHR && (jqXHR.status === 401 || (jqXHR.getResponseHeader && jqXHR.getResponseHeader('X-Auth-Required') === '1'))) {
                    window.redirectToLoginExpired();
                }
            });
        }
    })();

    // Global function to load content via AJAX
    function loadPage(page) {
        if (window.__temperAuthRedirecting) return;

        const contentArea = document.getElementById('main-content');
        
        // Show loading indicator
        contentArea.innerHTML = '<div class="text-center p-5"><div class="spinner-border text-primary" role="status"></div><p class="mt-3">Loading...</p></div>';
        
        fetch('pages/' + page + '.php')
            .then(function(response) {
                if (!response.ok) throw new Error('Page not found');
                return response.text();
            })
            .then(function(html) {
                if (window.__temperAuthRedirecting) return;
                if (typeof window.isAuthExpiredPayload === 'function' && window.isAuthExpiredPayload(html)) {
                    window.redirectToLoginExpired();
                    return;
                }
                if (typeof applyMainContent === 'function') {
                    applyMainContent(html);
                } else {
                    contentArea.innerHTML = html;
                }
            })
            .catch(function(error) {
                if (window.__temperAuthRedirecting) return;
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