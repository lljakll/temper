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
    <!-- Apply Bootstrap color mode before CSS paints to avoid flash / wrong text color -->
    <script>
    (function () {
        try {
            var key = 'temper-theme';
            var stored = localStorage.getItem(key); // 'light' | 'dark' | 'auto' | null
            var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            var theme = (stored === 'light' || stored === 'dark')
                ? stored
                : (prefersDark ? 'dark' : 'light');
            document.documentElement.setAttribute('data-bs-theme', theme);
            window.__temperTheme = {
                key: key,
                get: function () { return localStorage.getItem(key) || 'auto'; },
                resolve: function () {
                    var s = localStorage.getItem(key);
                    if (s === 'light' || s === 'dark') return s;
                    return (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
                },
                apply: function (mode) {
                    if (mode === 'light' || mode === 'dark' || mode === 'auto') {
                        localStorage.setItem(key, mode);
                    }
                    document.documentElement.setAttribute('data-bs-theme', this.resolve());
                }
            };
        } catch (e) {
            document.documentElement.setAttribute('data-bs-theme', 'light');
        }
    })();
    </script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <style>
        .collapse.show {
            display: block !important;
        }

        /* ── Theme-aware base (Bootstrap body color + background) ────────── */
        body {
            color: var(--bs-body-color);
            background-color: var(--bs-body-bg);
        }

        /* Soft surfaces that track the active theme (prefer over hard bg-light) */
        .bg-surface {
            background-color: var(--bs-tertiary-bg) !important;
            color: var(--bs-body-color);
        }
        .bg-surface-secondary {
            background-color: var(--bs-secondary-bg) !important;
            color: var(--bs-body-color);
        }

        /* Reference # suggestion: ghosted placeholder (clearly not a real value) */
        .ref-number-input {
            color: var(--bs-body-color);
        }
        .ref-number-input::placeholder {
            color: var(--bs-secondary-color, #6c757d);
            opacity: 0.4;
            font-weight: 400;
            font-style: italic;
        }
        .ref-number-input::-webkit-input-placeholder {
            color: var(--bs-secondary-color, #6c757d);
            opacity: 0.4;
            font-weight: 400;
            font-style: italic;
        }
        .ref-number-input::-moz-placeholder {
            color: var(--bs-secondary-color, #6c757d);
            opacity: 0.4;
            font-weight: 400;
            font-style: italic;
        }
        .ref-number-input:-ms-input-placeholder {
            color: var(--bs-secondary-color, #6c757d);
            opacity: 0.4;
            font-weight: 400;
            font-style: italic;
        }

        /* ── Sidebar (theme-aware surface + text) ────────────────────────── */
        .sidebar-panel {
            height: calc(100vh - 1rem);
            max-height: calc(100vh - 1rem);
            color: var(--bs-body-color);
            background-color: var(--bs-tertiary-bg);
            border-color: var(--bs-border-color) !important;
        }
        .sidebar-panel .offcanvas-body {
            color: var(--bs-body-color);
            background-color: var(--bs-tertiary-bg) !important;
        }
        .sidebar-panel .offcanvas-header {
            color: var(--bs-body-color);
            border-bottom-color: var(--bs-border-color) !important;
        }
        .sidebar-panel .offcanvas-title {
            color: var(--bs-body-color);
        }
        .sidebar-panel .nav-link {
            border-radius: 0.375rem;
            padding: 0.5rem 0.75rem;
            min-height: 2.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--bs-body-color);
        }
        .sidebar-panel .nav-link:hover,
        .sidebar-panel .nav-link:focus {
            background-color: rgba(var(--bs-primary-rgb), 0.1);
            color: var(--bs-primary);
        }
        .sidebar-panel .nav-link.active {
            background-color: rgba(var(--bs-primary-rgb), 0.15);
            font-weight: 600;
            color: var(--bs-primary);
        }
        .sidebar-panel .sidebar-brand,
        .sidebar-panel .sidebar-meta {
            color: var(--bs-body-color);
            border-bottom-color: var(--bs-border-color) !important;
        }
        .sidebar-panel .sidebar-footnote,
        .sidebar-panel .sidebar-welcome {
            color: var(--bs-secondary-color);
        }
        .sidebar-panel .sidebar-welcome strong {
            color: var(--bs-body-color);
        }
        .sidebar-panel .sidebar-divider {
            border-color: var(--bs-border-color);
            opacity: 1;
        }

        /* Offcanvas-md: sticky column on desktop */
        #appSidebar.offcanvas {
            --bs-offcanvas-width: min(18rem, 85vw);
            --bs-offcanvas-bg: var(--bs-tertiary-bg);
            --bs-offcanvas-color: var(--bs-body-color);
        }
        @media (min-width: 768px) {
            #appSidebar.offcanvas-md {
                position: sticky;
                top: 0.5rem;
                transform: none !important;
                visibility: visible !important;
                height: calc(100vh - 1rem);
                background: transparent !important;
                border: 0 !important;
            }
            #appSidebar .offcanvas-body {
                height: 100%;
            }
        }

        /* ── Main content area ───────────────────────────────────────────── */
        #main-content-col {
            min-width: 0; /* allow flex children to shrink / tables to scroll */
            color: var(--bs-body-color);
        }
        #main-content {
            min-width: 0;
            color: var(--bs-body-color);
        }

        /* ── Mobile top bar (matches sidebar theme) ──────────────────────── */
        .mobile-topbar {
            z-index: 1020;
            color: var(--bs-body-color);
            background-color: var(--bs-tertiary-bg) !important;
            border: 1px solid var(--bs-border-color);
        }

        /* ── Bottom navigation (phones / small tablets) ──────────────────── */
        .mobile-bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            z-index: 1030;
            background: var(--bs-tertiary-bg);
            border-top: 1px solid var(--bs-border-color);
            display: flex;
            justify-content: space-around;
            padding: 0.35rem 0.25rem calc(0.35rem + env(safe-area-inset-bottom, 0px));
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.08);
            color: var(--bs-body-color);
        }
        .mobile-bottom-nav a {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.15rem;
            color: var(--bs-secondary-color);
            text-decoration: none;
            font-size: 0.65rem;
            padding: 0.35rem 0.15rem;
            min-height: 3rem;
            border-radius: 0.375rem;
            -webkit-tap-highlight-color: transparent;
        }
        .mobile-bottom-nav a i {
            font-size: 1.25rem;
            line-height: 1;
        }
        .mobile-bottom-nav a:hover,
        .mobile-bottom-nav a:focus,
        .mobile-bottom-nav a.active {
            color: var(--bs-primary);
            background: rgba(var(--bs-primary-rgb), 0.1);
        }
        /* Room for fixed bottom nav on small screens */
        @media (max-width: 767.98px) {
            body.has-mobile-nav {
                padding-bottom: calc(4.25rem + env(safe-area-inset-bottom, 0px));
            }
            .toast-container {
                bottom: calc(4.5rem + env(safe-area-inset-bottom, 0px)) !important;
                top: auto !important;
            }
        }

        /* ── Touch-friendly forms & controls ─────────────────────────────── */
        @media (max-width: 991.98px) {
            .form-control,
            .form-select {
                min-height: 2.75rem;
                font-size: 16px; /* prevents iOS zoom on focus */
            }
            .form-control-sm,
            .form-select-sm {
                min-height: 2.5rem;
                font-size: 16px;
                padding-top: 0.4rem;
                padding-bottom: 0.4rem;
            }
            .btn {
                min-height: 2.5rem;
                padding-left: 0.85rem;
                padding-right: 0.85rem;
            }
            .btn-sm {
                min-height: 2.35rem;
                padding: 0.35rem 0.7rem;
            }
            .form-check-input {
                width: 1.25rem;
                height: 1.25rem;
            }
            /* Stack tight action toolbars */
            .btn-toolbar-mobile {
                width: 100%;
            }
            .btn-toolbar-mobile > .btn {
                flex: 1 1 auto;
            }
        }

        /* ── Tables: horizontal scroll helpers ───────────────────────────── */
        .table-responsive {
            -webkit-overflow-scrolling: touch;
        }
        .table-responsive > .table {
            margin-bottom: 0;
        }
        /* Prefer not to shrink critical money columns on small screens */
        .table .text-nowrap {
            white-space: nowrap;
        }

        /* ── Ledger layout ───────────────────────────────────────────────── */
        .ledger-workspace {
            height: calc(100vh - 170px);
            min-height: 300px;
        }
        .ledger-tx-list {
            height: 35vh;
        }
        @media (max-width: 767.98px) {
            .ledger-workspace {
                height: auto;
                min-height: 0;
            }
            .ledger-tx-list {
                height: 45vh;
                max-height: 45vh;
            }
            .ledger-filter-row .col-auto {
                flex: 1 1 45%;
            }
            .ledger-filter-row .col-auto .form-control,
            .ledger-filter-row .col-auto .form-select {
                width: 100%;
            }
            .ledger-action-bar .btn {
                flex: 1 1 calc(50% - 0.25rem);
            }
        }

        /* ── Dashboard cards ─────────────────────────────────────────────── */
        @media (max-width: 575.98px) {
            .dashboard-summary-card .card-header h5 {
                font-size: 0.95rem;
            }
            .dashboard-summary-card .card-body h3 {
                font-size: 1.5rem;
            }
        }

        /* ── Modals on small screens ─────────────────────────────────────── */
        @media (max-width: 575.98px) {
            .modal-dialog.modal-xl,
            .modal-dialog.modal-lg {
                margin: 0.5rem;
                max-width: calc(100% - 1rem);
            }
        }

        /* ── Utility: page titles that wrap cleanly ──────────────────────── */
        .page-title-row {
            gap: 0.5rem;
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

    // Close mobile offcanvas after navigation
    window.closeMobileNav = function() {
        var el = document.getElementById('appSidebar');
        if (!el || typeof bootstrap === 'undefined' || !bootstrap.Offcanvas) return;
        var inst = bootstrap.Offcanvas.getInstance(el);
        if (inst) inst.hide();
    };

    // Highlight active nav item (sidebar + bottom nav)
    window.setActiveNav = function(page) {
        if (!page) return;
        document.querySelectorAll('[data-nav-page]').forEach(function(a) {
            a.classList.toggle('active', a.getAttribute('data-nav-page') === page);
        });
    };

    // Global function to load content via AJAX
    function loadPage(page) {
        if (window.__temperAuthRedirecting) return;

        // While forced password change is required, only allow force-password
        if (window.__temperMustChangePassword && page !== 'force-password') {
            page = 'force-password';
            if (typeof showToast === 'function') {
                showToast('You must change your password before using the app.', 'warning', 3500);
            }
        }

        if (typeof window.closeMobileNav === 'function') {
            window.closeMobileNav();
        }
        if (typeof window.setActiveNav === 'function') {
            window.setActiveNav(page);
        }

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

    // Load default landing page based on role permissions (set by nav.php)
    document.addEventListener('DOMContentLoaded', function() {
        // Follow OS light/dark changes when theme preference is auto
        try {
            if (window.matchMedia) {
                window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function() {
                    if (window.__temperTheme && window.__temperTheme.get() === 'auto') {
                        window.__temperTheme.apply('auto');
                    } else if (window.__temperTheme && !localStorage.getItem(window.__temperTheme.key)) {
                        window.__temperTheme.apply('auto');
                    }
                });
            }
        } catch (e) { /* ignore */ }

        var home = (window.__temperHomePage && typeof window.__temperHomePage === 'string')
            ? window.__temperHomePage
            : 'dashboard';
        loadPage(home);
    });
    </script>
<body class="has-mobile-nav">

<div class="container-fluid p-2">
