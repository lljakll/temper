<?php
// Common Setup & Security
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

// Central session check (full-page shell)
requireLogin();

// Idle login timeout for client-side enforcement (Developer Mode off → 10 min; on → disabled)
$temperLoginTimeout = function_exists('getClientLoginTimeoutConfig')
    ? getClientLoginTimeoutConfig()
    : ['enabled' => true, 'seconds' => 600];
$temperLoginTimeoutEnabled = !empty($temperLoginTimeout['enabled']);
$temperLoginTimeoutSeconds = max(30, (int)($temperLoginTimeout['seconds'] ?? 600));

// Sidebar hover delays (System Configuration → Interface)
$temperSidebarHoverExpandSec = function_exists('getSidebarHoverExpandDelaySeconds')
    ? (float)getSidebarHoverExpandDelaySeconds()
    : 0.5;
$temperSidebarHoverCollapseSec = function_exists('getSidebarHoverCollapseDelaySeconds')
    ? (float)getSidebarHoverCollapseDelaySeconds()
    : 2.0;
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
        :root {
            --temper-sidebar-expanded: 15.5rem;
            --temper-sidebar-collapsed: 4.25rem;
            /* Matches container-fluid p-2 so the fixed panel aligns with the shell */
            --temper-shell-pad: 0.5rem;
            --temper-sidebar-transition: width 0.25s ease, max-width 0.25s ease,
                flex-basis 0.25s ease, box-shadow 0.25s ease, padding 0.2s ease;
        }
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
            white-space: nowrap;
            overflow: hidden;
        }
        .sidebar-panel .nav-link > i {
            flex: 0 0 auto;
            font-size: 1.1rem;
            width: 1.25rem;
            text-align: center;
        }
        .sidebar-panel .nav-link .sidebar-label {
            flex: 1 1 auto;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
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
        .sidebar-panel .sidebar-welcome,
        .sidebar-panel .sidebar-version {
            color: var(--bs-secondary-color);
        }
        .sidebar-panel .sidebar-welcome strong {
            color: var(--bs-body-color);
        }
        .sidebar-panel .sidebar-role-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--bs-body-color);
            line-height: 1.3;
        }
        .sidebar-panel .sidebar-role-switch .dropdown-toggle::after {
            margin-left: 0.35rem;
        }
        .sidebar-panel .sidebar-role-switch .dropdown-menu {
            z-index: 1080;
            min-width: 12rem;
            font-size: 0.875rem;
        }
        .sidebar-panel .sidebar-version-link {
            color: var(--bs-secondary-color);
        }
        .sidebar-panel .sidebar-version-link:hover,
        .sidebar-panel .sidebar-version-link:focus {
            color: var(--bs-primary);
        }
        .sidebar-panel .sidebar-version-dual {
            white-space: nowrap;
        }
        .sidebar-panel .sidebar-version-db {
            color: inherit;
        }
        /* Admin-only: DB version portion red when behind latest known release */
        .sidebar-panel .sidebar-version-db.sidebar-version-outdated {
            color: var(--bs-danger) !important;
            font-weight: 600;
        }
        .sidebar-panel .sidebar-version-link:hover .sidebar-version-db.sidebar-version-outdated,
        .sidebar-panel .sidebar-version-link:focus .sidebar-version-db.sidebar-version-outdated {
            color: var(--bs-danger) !important;
            filter: brightness(0.9);
        }
        .sidebar-panel .sidebar-divider {
            border-color: var(--bs-border-color);
            opacity: 1;
        }
        .sidebar-toggle {
            flex: 0 0 auto;
            line-height: 1;
            color: var(--bs-secondary-color) !important;
            border: 0;
            background: transparent;
            padding: 0.25rem 0.35rem;
            border-radius: 0.375rem;
        }
        .sidebar-toggle:hover,
        .sidebar-toggle:focus {
            color: var(--bs-primary) !important;
            background-color: rgba(var(--bs-primary-rgb), 0.1);
        }
        .sidebar-panel .sidebar-action-btn {
            justify-content: flex-start;
            overflow: hidden;
            white-space: nowrap;
        }
        .sidebar-panel .sidebar-action-btn > i {
            flex: 0 0 auto;
            width: 1.25rem;
            text-align: center;
        }

        /* Offcanvas-md: floating (fixed) panel on desktop; true offcanvas on mobile */
        #appSidebar.offcanvas {
            --bs-offcanvas-width: min(18rem, 85vw);
            --bs-offcanvas-bg: var(--bs-tertiary-bg);
            --bs-offcanvas-color: var(--bs-body-color);
        }
        @media (min-width: 768px) {
            /*
             * Desktop shell layout:
             * - #temperSidebarCol is an in-flow width spacer only (expanded / collapsed rail)
             *   so #main-content-col never sits under the panel.
             * - #appSidebar is position:fixed (floating): stays on screen while page content scrolls.
             * - Hover peek while collapsed widens the fixed panel over content (no spacer reflow).
             * - Mobile (< md) keeps Bootstrap offcanvas; these rules do not apply.
             */
            #temperSidebarCol {
                flex: 0 0 var(--temper-sidebar-expanded);
                max-width: var(--temper-sidebar-expanded);
                width: var(--temper-sidebar-expanded);
                position: relative;
                z-index: 2;
                /* Horizontal reservation only — panel is fixed to the viewport */
                align-self: stretch;
                min-height: 0;
                overflow: visible;
                transition: flex-basis 0.25s ease, max-width 0.25s ease, width 0.25s ease;
            }
            #appSidebar.offcanvas-md {
                /* Override Bootstrap .offcanvas / .offcanvas-start insets → floating rail */
                position: fixed !important;
                top: var(--temper-shell-pad) !important;
                left: var(--temper-shell-pad) !important;
                right: auto !important;
                bottom: auto !important;
                transform: none !important;
                visibility: visible !important;
                height: calc(100vh - (2 * var(--temper-shell-pad)));
                max-height: calc(100vh - (2 * var(--temper-shell-pad)));
                width: var(--temper-sidebar-expanded) !important;
                max-width: var(--temper-sidebar-expanded) !important;
                z-index: 1020 !important;
                background: transparent !important;
                border: 0 !important;
                box-shadow: none !important;
                transition: width 0.25s ease, max-width 0.25s ease, box-shadow 0.2s ease;
            }
            #appSidebar .offcanvas-body {
                height: 100%;
                max-height: 100%;
                overflow-x: hidden;
                overflow-y: auto;
                transition: padding 0.2s ease, border-color 0.2s ease, background-color 0.2s ease;
            }
            #main-content-col {
                /* Take all remaining row space after the sidebar spacer */
                flex: 1 1 0% !important;
                max-width: none !important;
                width: auto !important;
                min-width: 0;
                position: relative;
                z-index: 1;
            }

            /* ── Collapsed (icons only) ──────────────────────────────────── */
            body.sidebar-collapsed #temperSidebarCol {
                flex: 0 0 var(--temper-sidebar-collapsed);
                max-width: var(--temper-sidebar-collapsed);
                width: var(--temper-sidebar-collapsed);
                /* Rail spacer stays collapsed; peek paints outside it */
                overflow: visible;
                z-index: 2;
            }
            /* Explicit non-hover reset (must win over Bootstrap + any residual expand state) */
            body.sidebar-collapsed:not(.sidebar-hover-expand) #appSidebar {
                position: fixed !important;
                top: var(--temper-shell-pad) !important;
                left: var(--temper-shell-pad) !important;
                right: auto !important;
                bottom: auto !important;
                width: var(--temper-sidebar-collapsed) !important;
                max-width: var(--temper-sidebar-collapsed) !important;
                z-index: 1020 !important;
                box-shadow: none !important;
            }
            body.sidebar-collapsed:not(.sidebar-hover-expand) #appSidebar .offcanvas-body {
                padding-left: 0.5rem !important;
                padding-right: 0.5rem !important;
                border: 0 !important;
                box-shadow: none !important;
                background-color: var(--bs-tertiary-bg) !important;
            }
            body.sidebar-collapsed #appSidebar .offcanvas-body {
                padding-left: 0.5rem !important;
                padding-right: 0.5rem !important;
            }
            body.sidebar-collapsed #appSidebar .nav-link {
                justify-content: center;
                padding-left: 0.4rem;
                padding-right: 0.4rem;
                gap: 0;
            }
            body.sidebar-collapsed #appSidebar .sidebar-label,
            body.sidebar-collapsed #appSidebar .sidebar-brand-text,
            body.sidebar-collapsed #appSidebar .sidebar-footnote,
            body.sidebar-collapsed #appSidebar .sidebar-welcome,
            body.sidebar-collapsed #appSidebar .sidebar-version .sidebar-label,
            body.sidebar-collapsed #appSidebar .sidebar-btn-label {
                opacity: 0;
                width: 0 !important;
                max-width: 0;
                margin: 0 !important;
                padding: 0 !important;
                overflow: hidden;
                white-space: nowrap;
                pointer-events: none;
                display: inline-block;
                vertical-align: middle;
                transition: opacity 0.15s ease, max-width 0.2s ease;
            }
            body.sidebar-collapsed #appSidebar .sidebar-version {
                text-align: center;
            }
            body.sidebar-collapsed #appSidebar .sidebar-version-link {
                justify-content: center;
            }
            body.sidebar-collapsed #appSidebar .sidebar-brand {
                justify-content: center;
                flex-direction: column;
                gap: 0.15rem;
                margin-bottom: 0.75rem !important;
                padding-bottom: 0.5rem !important;
            }
            body.sidebar-collapsed #appSidebar .sidebar-brand > i.bi-bank {
                margin-right: 0 !important;
            }
            body.sidebar-collapsed #appSidebar .sidebar-action-btn {
                justify-content: center;
                padding-left: 0.4rem;
                padding-right: 0.4rem;
                gap: 0 !important;
            }
            body.sidebar-collapsed:not(.sidebar-hover-expand) #appSidebar .sidebar-role-switch .dropdown-toggle::after {
                display: none;
            }
            body.sidebar-collapsed #appSidebar .nav.ms-3 {
                margin-left: 0 !important;
            }
            body.sidebar-collapsed #appSidebar .sidebar-toggle {
                position: static;
                margin-left: 0 !important;
            }

            /* ── Hover peek while collapsed (overlay expand, still fixed) ── */
            body.sidebar-collapsed.sidebar-hover-expand #temperSidebarCol {
                /* Column width stays collapsed so main content does not reflow */
                z-index: 1040;
            }
            body.sidebar-collapsed.sidebar-hover-expand #appSidebar {
                position: fixed !important;
                left: var(--temper-shell-pad) !important;
                top: var(--temper-shell-pad) !important;
                right: auto !important;
                bottom: auto !important;
                width: var(--temper-sidebar-expanded) !important;
                max-width: var(--temper-sidebar-expanded) !important;
                height: calc(100vh - (2 * var(--temper-shell-pad)));
                max-height: calc(100vh - (2 * var(--temper-shell-pad)));
                z-index: 1050 !important;
                box-shadow: 0 0.5rem 1.75rem rgba(0, 0, 0, 0.18);
            }
            body.sidebar-collapsed.sidebar-hover-expand #appSidebar .offcanvas-body {
                padding-left: 1rem !important;
                padding-right: 1rem !important;
                background-color: var(--bs-tertiary-bg) !important;
                border: 1px solid var(--bs-border-color);
                border-radius: 0.5rem;
            }
            body.sidebar-collapsed.sidebar-hover-expand #appSidebar .nav-link {
                justify-content: flex-start;
                padding-left: 0.75rem;
                padding-right: 0.75rem;
                gap: 0.5rem;
            }
            body.sidebar-collapsed.sidebar-hover-expand #appSidebar .sidebar-label,
            body.sidebar-collapsed.sidebar-hover-expand #appSidebar .sidebar-brand-text,
            body.sidebar-collapsed.sidebar-hover-expand #appSidebar .sidebar-footnote,
            body.sidebar-collapsed.sidebar-hover-expand #appSidebar .sidebar-welcome,
            body.sidebar-collapsed.sidebar-hover-expand #appSidebar .sidebar-version .sidebar-label,
            body.sidebar-collapsed.sidebar-hover-expand #appSidebar .sidebar-btn-label {
                opacity: 1;
                width: auto !important;
                max-width: 14rem;
                pointer-events: auto;
            }
            body.sidebar-collapsed.sidebar-hover-expand #appSidebar .sidebar-version {
                text-align: start;
            }
            body.sidebar-collapsed.sidebar-hover-expand #appSidebar .sidebar-brand {
                justify-content: flex-start;
                flex-direction: row;
                gap: 0;
            }
            body.sidebar-collapsed.sidebar-hover-expand #appSidebar .sidebar-brand > i.bi-bank {
                margin-right: 0.5rem !important;
            }
            body.sidebar-collapsed.sidebar-hover-expand #appSidebar .sidebar-action-btn {
                justify-content: flex-start;
                gap: 0.25rem !important;
            }
            body.sidebar-collapsed.sidebar-hover-expand #appSidebar .nav.ms-3 {
                margin-left: 1rem !important;
            }
            body.sidebar-collapsed.sidebar-hover-expand #appSidebar .sidebar-toggle {
                margin-left: auto !important;
            }

            /* Hide desktop collapse control on small screens (mobile uses offcanvas) */
            .sidebar-toggle {
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }
        }
        @media (max-width: 767.98px) {
            .sidebar-toggle {
                display: none !important;
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

        /* ── Lookup maintenance toolbar + table chrome ───────────────────── */
        .temper-lookup-page {
            --temper-lookup-font-size: 0.875rem;
        }
        .temper-lookup-toolbar {
            /* Single compact row with title, filter, font, actions */
        }
        .temper-lookup-title {
            line-height: 1.25;
            margin-right: 0.25rem;
        }
        .temper-lookup-filter-wrap {
            width: 100%;
            max-width: 16rem;
            min-width: 9rem;
            flex: 1 1 10rem;
        }
        .temper-lookup-filter-wrap .input-group-text {
            color: var(--bs-secondary-color);
        }
        .temper-lookup-actions {
            flex: 0 1 auto;
        }
        /* Font size applies only to the data table (not page chrome) */
        .temper-lookup-table {
            font-size: var(--temper-lookup-font-size, 0.875rem);
        }
        .temper-lookup-table > :not(caption) > * > * {
            font-size: inherit;
        }
        th.temper-sortable {
            cursor: pointer;
            user-select: none;
            white-space: nowrap;
        }
        th.temper-sortable:hover,
        th.temper-sortable:focus {
            outline: none;
            box-shadow: inset 0 0 0 9999px rgba(255, 255, 255, 0.08);
        }
        th.temper-sortable .temper-sort-icon {
            font-size: 0.7em;
            margin-left: 0.35rem;
            opacity: 0.4;
            vertical-align: 0.05em;
        }
        th.temper-sortable[aria-sort="ascending"] .temper-sort-icon,
        th.temper-sortable[aria-sort="descending"] .temper-sort-icon {
            opacity: 1;
        }
        /* Leader-key hotkey mode indicator (bottom of viewport) */
        .temper-hotkey-banner {
            position: fixed;
            bottom: 1rem;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1080;
            padding: 0.4rem 0.85rem;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            background: var(--bs-body-bg);
            color: var(--bs-body-color);
            border: 1px solid var(--bs-border-color);
            box-shadow: 0 0.35rem 1rem rgba(0, 0, 0, 0.15);
            pointer-events: none;
        }
        .temper-hotkey-banner kbd {
            font-size: 0.8em;
            padding: 0.1em 0.35em;
            border-radius: 0.25rem;
            border: 1px solid var(--bs-border-color);
            background: var(--bs-tertiary-bg);
        }
        .temper-lookup-hotkey-help-list {
            margin: 0;
            padding-left: 1.1rem;
            font-size: 0.8125rem;
        }
        .temper-lookup-hotkey-help-list li {
            margin-bottom: 0.2rem;
        }

        /* ── Mobile top bar (matches sidebar theme) ──────────────────────── */
        .mobile-topbar {
            z-index: 1045; /* above ledger actions flyout/backdrop; below modals */
            color: var(--bs-body-color);
            background-color: var(--bs-tertiary-bg) !important;
            border: 1px solid var(--bs-border-color);
        }
        .mobile-topbar-end {
            flex: 0 0 auto;
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
            #appToastContainer.toast-container {
                bottom: calc(4.5rem + env(safe-area-inset-bottom, 0px)) !important;
                top: auto !important;
            }
        }

        /*
         * App toasts must sit above open Bootstrap modals/backdrops (1050/1055)
         * so success/error messages remain readable without closing the modal.
         * Still below the idle session-timeout layer (20040/20050).
         * Container is reparented to document.body (see footer showToast / mount).
         */
        #appToastContainer.toast-container {
            z-index: 10900 !important;
            position: fixed !important;
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
            /* Touch target for plain checkboxes; form-switch needs Bootstrap’s wider track. */
            .form-check-input {
                width: 1.25rem;
                height: 1.25rem;
            }
            .form-switch .form-check-input {
                width: 2.5em;
                height: 1.25em;
                margin-left: -2.5em;
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

        /* ── Ledger layout (full-height infinite scroll list + modal form) ─ */
        .ledger-page {
            display: flex;
            flex-direction: column;
            min-height: 0;
        }
        .ledger-workspace {
            height: calc(100vh - 150px);
            min-height: 280px;
            flex: 1 1 auto;
        }
        .ledger-tx-list {
            height: 100%;
            min-height: 0;
        }
        .ledger-table-scroll {
            overscroll-behavior: contain;
        }
        .ledger-sticky-head th {
            position: sticky;
            top: 0;
            z-index: 12;
            vertical-align: middle;
            background-color: var(--bs-dark);
            box-shadow: 0 1px 0 rgba(0, 0, 0, 0.15);
        }
        .ledger-th-filter.ledger-filter-active {
            box-shadow: inset 0 -3px 0 var(--bs-warning);
        }
        .ledger-sort-btn {
            font-weight: 600;
            font-size: inherit;
            line-height: 1.2;
            max-width: 100%;
        }
        .ledger-sort-btn:hover,
        .ledger-sort-btn:focus {
            color: #fff !important;
            opacity: 0.9;
        }
        .ledger-filter-toggle {
            line-height: 1;
            min-width: 1.25rem;
        }
        .ledger-filter-menu {
            z-index: 1080;
            /* Resizable Excel-style panel (drag bottom-right handle).
             * Width is capped in JS to remaining space on the RIGHT of the
             * left edge so the panel never shifts left off the viewport. */
            width: 18rem;
            min-width: 10rem;
            max-width: 100vw;
            min-height: 14rem;
            max-height: min(75vh, 36rem);
            height: 18rem;
            resize: both;
            overflow: hidden;
            box-sizing: border-box;
            position: relative;
        }
        .ledger-f-menu-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            margin-bottom: 0.35rem;
        }
        /* Only when open — do not override Bootstrap .dropdown-menu { display:none } */
        .dropdown-menu.ledger-filter-menu.show {
            display: flex;
            flex-direction: column;
        }
        /* Visible resize cue (native resize handle still active) */
        .ledger-filter-menu::after {
            content: '';
            position: absolute;
            right: 2px;
            bottom: 2px;
            width: 12px;
            height: 12px;
            pointer-events: none;
            opacity: 0.45;
            background:
                linear-gradient(135deg, transparent 50%, var(--bs-secondary-color) 50%) 100% 100% / 6px 6px no-repeat,
                linear-gradient(135deg, transparent 50%, var(--bs-secondary-color) 50%) calc(100% - 4px) calc(100% - 4px) / 6px 6px no-repeat;
        }
        /* Excel-style multi-select auto-filter panel */
        .ledger-f-values {
            flex: 1 1 auto;
            min-height: 4rem;
            max-height: none;
            overflow-x: auto;
            overflow-y: auto;
            overscroll-behavior: contain;
            /* Enough inset so checkboxes are never clipped at the left edge */
            padding-left: 0.35rem !important;
            padding-right: 0.35rem !important;
        }
        .ledger-f-select-all-wrap {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            padding-left: 0.35rem;
            margin-bottom: 0.35rem !important;
        }
        .ledger-f-select-all-wrap.form-check {
            padding-left: 0.35rem;
            min-height: 0;
        }
        .ledger-f-select-all-wrap .form-check-input {
            float: none;
            margin: 0;
            flex: 0 0 auto;
            position: relative;
        }
        .ledger-f-select-all-wrap .form-check-label {
            margin: 0;
            white-space: nowrap;
            cursor: pointer;
        }
        /*
         * Filter list rows: do NOT use Bootstrap form-check float/negative-margin.
         * That pattern clips checkboxes inside overflow containers (looks "missing").
         */
        .ledger-f-item {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.15rem 0.25rem;
            border-radius: 0.2rem;
            white-space: nowrap;
            min-width: min-content;
            box-sizing: border-box;
        }
        .ledger-f-item.form-check {
            /* neutralize Bootstrap form-check layout if class still present */
            padding-left: 0.25rem;
            min-height: 0;
            margin-bottom: 0;
        }
        .ledger-f-item:hover {
            background-color: rgba(var(--bs-primary-rgb), 0.08);
        }
        .ledger-f-item > .form-check-input,
        .ledger-f-item .form-check-input {
            float: none !important;
            margin: 0 !important;
            margin-left: 0 !important;
            flex: 0 0 auto;
            position: relative;
            top: 0;
        }
        .ledger-f-item .form-check-label,
        .ledger-f-item .ledger-f-label {
            cursor: pointer;
            margin: 0;
            white-space: nowrap;
            word-break: normal;
            overflow-wrap: normal;
            flex: 0 0 auto;
            min-width: 0;
        }
        .ledger-f-item .ledger-f-count {
            opacity: 0.65;
            font-size: 0.75em;
            white-space: nowrap;
        }
        .ledger-date-tree {
            min-width: min-content;
        }
        .ledger-date-tree .ledger-date-year,
        .ledger-date-tree .ledger-date-month {
            font-weight: 600;
        }
        .ledger-date-tree .ledger-date-month-wrap {
            margin-left: 0.85rem;
        }
        .ledger-date-tree .ledger-date-day-wrap {
            margin-left: 0.85rem;
        }
        .ledger-date-tree details > summary {
            list-style: none;
            cursor: pointer;
            user-select: none;
            display: flex;
            align-items: center;
            gap: 0.35rem;
            white-space: nowrap;
        }
        .ledger-date-tree details > summary::-webkit-details-marker {
            display: none;
        }
        .ledger-date-tree details > summary::before {
            content: '▸';
            display: inline-block;
            width: 0.9em;
            flex: 0 0 auto;
            color: var(--bs-secondary-color);
        }
        .ledger-date-tree details[open] > summary::before {
            content: '▾';
        }
        .ledger-date-tree .ledger-f-count {
            margin-left: auto;
        }
        .ledger-tx-table tbody tr {
            cursor: pointer;
        }
        .ledger-tx-table tbody tr.table-active,
        .ledger-tx-table tbody tr:has(.tx-cb:checked) {
            --bs-table-bg-state: rgba(var(--bs-primary-rgb), 0.08);
        }
        .ledger-attach-cell {
            width: 2.6rem;
            white-space: nowrap;
        }
        .ledger-attach-btn {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 1.5rem;
            min-height: 1.5rem;
            color: var(--bs-secondary-color);
            text-decoration: none;
            line-height: 1;
        }
        .ledger-attach-btn:hover,
        .ledger-attach-btn:focus {
            color: var(--bs-primary);
        }
        .ledger-attach-btn .bi-paperclip {
            font-size: 1.1rem;
            transform: rotate(-45deg);
        }
        .ledger-attach-count {
            position: absolute;
            top: -0.35rem;
            right: -0.55rem;
            font-size: 0.6rem;
            line-height: 1;
            padding: 0.15em 0.35em;
            min-width: 1.1rem;
        }
        /* Viewport-height portfolio dialog; ~25% narrower than full width so
           the backdrop remains clickable on both sides. Selector panes stay fixed px. */
        #txAttachPortfolioModal .ledger-portfolio-dialog {
            width: 75vw;
            max-width: 75vw;
            height: 100vh;
            max-height: 100vh;
            margin: 0 auto;
        }
        #txAttachPortfolioModal .ledger-portfolio-modal {
            height: 100%;
            max-height: 100vh;
            display: flex;
            flex-direction: column;
            border-radius: 0.35rem;
            overflow: hidden;
        }
        #txAttachPortfolioModal .modal-header {
            flex: 0 0 auto;
        }
        #txAttachPortfolioModal .ledger-portfolio-close {
            width: 2.35rem;
            height: 2.35rem;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            font-weight: 700;
            line-height: 1;
            border-width: 2px;
            flex: 0 0 auto;
        }
        #txAttachPortfolioModal .ledger-portfolio-close .bi {
            font-size: 1.25rem;
            line-height: 1;
        }
        #txAttachPortfolioModal .modal-body {
            flex: 1 1 auto;
            min-height: 0;
            display: flex;
        }
        .ledger-portfolio {
            display: flex;
            flex-direction: row;
            width: 100%;
            height: 100%;
            min-height: 0;
        }
        .ledger-portfolio-sidebar {
            flex: 0 0 248px;
            width: 248px;
            min-width: 248px;
            max-width: 248px;
            border-right: 1px solid var(--bs-border-color);
            overflow-x: hidden;
            overflow-y: auto;
            background-color: var(--bs-tertiary-bg);
        }
        .ledger-portfolio-pages {
            flex: 0 0 148px;
            width: 148px;
            min-width: 148px;
            max-width: 148px;
            border-right: 1px solid var(--bs-border-color);
            overflow-x: hidden;
            overflow-y: auto;
            background-color: var(--bs-tertiary-bg);
        }
        .ledger-portfolio-sidebar-head {
            position: sticky;
            top: 0;
            z-index: 1;
            padding: 0.5rem 0.65rem;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: var(--bs-secondary-color);
            background-color: var(--bs-tertiary-bg);
            border-bottom: 1px solid var(--bs-border-color);
        }
        .ledger-portfolio-item {
            display: flex;
            align-items: center;
            gap: 0.7rem;
            width: 100%;
            border: 0;
            background: transparent;
            color: inherit;
            text-align: left;
            padding: 0.65rem 0.7rem;
        }
        .ledger-portfolio-item:hover {
            background-color: rgba(var(--bs-primary-rgb), 0.08);
        }
        .ledger-portfolio-item.active {
            background-color: rgba(var(--bs-primary-rgb), 0.16);
        }
        .ledger-portfolio-type-icon {
            flex: 0 0 48px;
            width: 48px;
            height: 52px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            border-radius: 0.3rem;
            background: var(--bs-body-bg);
            border: 1px solid var(--bs-border-color);
            line-height: 1;
        }
        .ledger-portfolio-type-icon .bi {
            font-size: 1.85rem;
            line-height: 1;
        }
        .ledger-portfolio-type-icon.is-pdf { color: #c0392b; }
        .ledger-portfolio-type-icon.is-img { color: #1d6f42; }
        .ledger-portfolio-type-icon.is-txt { color: #5c6570; }
        .ledger-portfolio-type-icon.is-doc { color: #2b579a; }
        .ledger-portfolio-type-icon.is-other { color: var(--bs-secondary-color); }
        .ledger-portfolio-item-name {
            min-width: 0;
            flex: 1 1 auto;
            font-size: 0.875rem;
            line-height: 1.25;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        .ledger-portfolio-page-nav {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.2rem;
            padding: 0.35rem 0.35rem 0.25rem;
            position: sticky;
            top: 2.15rem;
            z-index: 1;
            background-color: var(--bs-tertiary-bg);
        }
        .ledger-portfolio-page-label {
            font-size: 0.75rem;
            color: var(--bs-secondary-color);
            min-width: 2.6rem;
            text-align: center;
        }
        .ledger-portfolio-page {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.2rem;
            width: 100%;
            border: 0;
            background: transparent;
            color: inherit;
            padding: 0.35rem 0.45rem;
        }
        .ledger-portfolio-page:hover {
            background-color: rgba(var(--bs-primary-rgb), 0.08);
        }
        .ledger-portfolio-page.active .ledger-portfolio-page-thumb,
        .ledger-portfolio-page.active canvas {
            outline: 2px solid var(--bs-primary);
            outline-offset: 1px;
        }
        .ledger-portfolio-page-thumb,
        #txAttachPageList canvas {
            width: 112px;
            height: auto;
            background: #fff;
            box-shadow: 0 0.08rem 0.25rem rgba(0, 0, 0, 0.12);
        }
        .ledger-portfolio-page-num {
            font-size: 0.7rem;
            color: var(--bs-secondary-color);
        }
        .ledger-portfolio-main {
            flex: 1 1 auto;
            min-width: 0;
            display: flex;
            flex-direction: column;
        }
        .ledger-portfolio-toolbar {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex: 0 0 auto;
            padding: 0.35rem 0.75rem;
            border-bottom: 1px solid var(--bs-border-color);
            background-color: var(--bs-body-bg);
        }
        .ledger-portfolio-zoom {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        .ledger-portfolio-zoom-label {
            min-width: 3.25rem;
            text-align: center;
            font-size: 0.8rem;
            color: var(--bs-secondary-color);
        }
        .ledger-portfolio-pane {
            flex: 1 1 auto;
            min-height: 0;
            overflow: hidden;
            background-color: var(--bs-tertiary-bg);
        }
        .ledger-portfolio-pane.is-zoomed {
            overflow: auto;
        }
        .ledger-portfolio-pdf-wrap {
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 100%;
            padding: 0;
        }
        .ledger-portfolio-pdf-canvas {
            display: block;
            background: #fff;
            box-shadow: 0 0.15rem 0.6rem rgba(0, 0, 0, 0.12);
        }
        .ledger-portfolio-image {
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .ledger-portfolio-image img {
            max-height: 100%;
            max-width: 100%;
            width: auto;
            height: auto;
            object-fit: contain;
        }
        .ledger-portfolio-pane.is-zoomed .ledger-portfolio-image {
            display: block;
            height: auto;
            min-height: 100%;
        }
        .ledger-portfolio-text {
            height: 100%;
            overflow: auto;
            white-space: pre-wrap;
            margin: 0;
        }
        @media (max-width: 767.98px) {
            #txAttachPortfolioModal .ledger-portfolio-dialog {
                width: calc(100vw - 1.5rem);
                max-width: calc(100vw - 1.5rem);
            }
            .ledger-portfolio-sidebar {
                flex: 0 0 200px;
                width: 200px;
                min-width: 200px;
                max-width: 200px;
            }
            .ledger-portfolio-pages {
                flex: 0 0 112px;
                width: 112px;
                min-width: 112px;
                max-width: 112px;
            }
            .ledger-portfolio-type-icon {
                flex: 0 0 40px;
                width: 40px;
                height: 44px;
            }
            .ledger-portfolio-type-icon .bi {
                font-size: 1.55rem;
            }
            #txAttachPageList canvas {
                width: 84px;
            }
        }
        .ledger-dblclick-toggle {
            flex: 0 0 auto;
        }
        .ledger-actions-backdrop {
            display: none;
        }
        .ledger-actions-flyout-head {
            display: none;
        }
        .ledger-header-filter-dot {
            position: absolute;
            top: 0.35rem;
            right: 0.35rem;
            width: 0.45rem;
            height: 0.45rem;
            border-radius: 50%;
            background: var(--bs-warning);
            pointer-events: none;
        }
        #ledgerMobileFilterBtn,
        #ledgerActionsFlyoutBtn {
            position: relative;
        }

        /* Dashboard is a simple shell until a new card system is designed. */

        /* ── Modals on small screens ─────────────────────────────────────── */
        @media (max-width: 575.98px) {
            .modal-dialog.modal-xl,
            .modal-dialog.modal-lg {
                margin: 0.5rem;
                max-width: calc(100% - 1rem);
            }
        }

        /*
         * Fragment modals are reparented to body (footer mountModalOnBody /
         * mountFragmentModals / showFragmentModal) so they stack above .modal-backdrop.
         * If a modal remains under #main-content-col (z-index: 1), the body backdrop
         * (1050) steals all clicks — open but dead. Keep Bootstrap stacking on body.
         */
        body > .modal {
            z-index: 1055;
        }
        body > .modal-backdrop {
            z-index: 1050;
        }

        /*
         * Idle session-timeout warning must always sit above every other UI layer
         * (open form modals, their backdrops, toasts, sidebar). Never closes other modals.
         */
        body > #sessionTimeoutModal.modal,
        #sessionTimeoutModal.modal {
            z-index: 20050 !important;
        }
        body > .modal-backdrop.session-timeout-backdrop {
            z-index: 20040 !important;
        }

        /* Toasts on body always paint above page modals; session-timeout still wins */
        body > #appToastContainer {
            z-index: 10900 !important;
        }

        /* ── Utility: page titles that wrap cleanly ──────────────────────── */
        .page-title-row {
            gap: 0.5rem;
        }

        /* ── Phone / small-tablet shell (banking-style) ──────────────────── */
        @media (max-width: 767.98px) {
            .mobile-topbar {
                min-height: 3.15rem;
                padding-top: 0.4rem !important;
                padding-bottom: 0.4rem !important;
            }
            .mobile-topbar .btn {
                min-width: 2.75rem;
                min-height: 2.75rem;
            }
            .mobile-bottom-nav {
                padding: 0.2rem 0.15rem calc(0.25rem + env(safe-area-inset-bottom, 0px));
            }
            .mobile-bottom-nav a {
                min-height: 3.35rem;
                font-size: 0.68rem;
                font-weight: 600;
                gap: 0.2rem;
                padding: 0.3rem 0.1rem;
            }
            .mobile-bottom-nav a i {
                font-size: 1.35rem;
            }
            body.has-mobile-nav {
                padding-bottom: calc(4.6rem + env(safe-area-inset-bottom, 0px));
            }
            .temper-hotkey-banner {
                bottom: calc(5rem + env(safe-area-inset-bottom, 0px));
            }
            .temper-lookup-title {
                white-space: normal;
            }
            .temper-lookup-filter-wrap {
                max-width: none;
                flex: 1 1 100%;
            }
            .temper-lookup-actions {
                width: 100%;
            }
            .temper-lookup-actions .btn {
                flex: 1 1 auto;
            }
        }

        /* ── Ledger mobile card list ─────────────────────────────────────── */
        .ledger-card-list {
            display: none;
        }
        @media (max-width: 767.98px) {
            .ledger-page {
                height: calc(100dvh - 9.6rem - env(safe-area-inset-bottom, 0px));
                max-height: calc(100dvh - 9.6rem - env(safe-area-inset-bottom, 0px));
                min-height: 0;
                overflow: hidden;
            }
            .ledger-workspace {
                height: auto;
                flex: 1 1 auto;
                min-height: 0;
            }
            .ledger-tx-list {
                min-height: 0;
            }
            .ledger-tx-list .card-body {
                min-height: 0;
            }
            .ledger-actions-backdrop {
                display: block;
                position: fixed;
                inset: 0;
                z-index: 1035;
                background: rgba(0, 0, 0, 0.35);
            }
            .ledger-actions-backdrop[hidden] {
                display: none !important;
            }
            .ledger-action-bar {
                position: fixed;
                top: 3.7rem;
                right: 0.5rem;
                left: auto;
                bottom: auto;
                width: min(18rem, calc(100vw - 1rem));
                max-height: calc(100dvh - 8.5rem - env(safe-area-inset-bottom, 0px));
                overflow-x: hidden;
                overflow-y: auto;
                display: flex !important;
                flex-direction: column;
                flex-wrap: nowrap;
                align-items: stretch;
                gap: 0.45rem;
                margin: 0;
                padding: 0.75rem;
                background: var(--bs-body-bg);
                border: 1px solid var(--bs-border-color);
                border-radius: 0.75rem;
                box-shadow: 0 0.45rem 1.4rem rgba(0, 0, 0, 0.18);
                z-index: 1040;
                opacity: 0;
                visibility: hidden;
                pointer-events: none;
                transform: translateY(-0.35rem);
                transition: opacity 0.15s ease, transform 0.15s ease, visibility 0.15s;
            }
            .ledger-page.ledger-actions-open .ledger-action-bar {
                opacity: 1;
                visibility: visible;
                pointer-events: auto;
                transform: none;
            }
            .ledger-actions-flyout-head {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 0.5rem;
                padding-bottom: 0.15rem;
                margin-bottom: 0.15rem;
                border-bottom: 1px solid var(--bs-border-color);
            }
            .ledger-action-bar > .btn {
                flex: 0 0 auto;
                width: 100%;
                justify-content: flex-start;
            }
            .ledger-action-bar .ledger-dblclick-toggle {
                flex: 0 0 auto;
                width: 100%;
                flex-wrap: wrap;
            }
            .ledger-action-bar .ledger-dblclick-toggle .btn-group {
                flex: 1 1 auto;
            }
            .ledger-desktop-table {
                display: none !important;
            }
            .ledger-page.ledger-mobile-filters-open .ledger-desktop-table {
                display: block !important;
                flex: 0 0 auto !important;
                max-height: min(70vh, 22rem);
                min-height: 0;
                overflow: auto;
                border-bottom: 1px solid var(--bs-border-color);
            }
            .ledger-page.ledger-mobile-filters-open .ledger-tx-table {
                min-width: 0 !important;
                width: 100%;
            }
            .ledger-page.ledger-mobile-filters-open .ledger-tx-table thead,
            .ledger-page.ledger-mobile-filters-open .ledger-tx-table thead tr {
                display: block;
                width: 100%;
            }
            .ledger-page.ledger-mobile-filters-open .ledger-tx-table tbody {
                display: none;
            }
            .ledger-page.ledger-mobile-filters-open .ledger-tx-table thead.ledger-sticky-head th {
                display: block;
                width: 100%;
                position: static;
                background-color: var(--bs-tertiary-bg) !important;
                color: var(--bs-body-color) !important;
                box-shadow: none;
                border-bottom: 1px solid var(--bs-border-color);
                padding: 0.55rem 0.75rem;
            }
            .ledger-page.ledger-mobile-filters-open .ledger-tx-table .ledger-sort-btn {
                color: var(--bs-body-color) !important;
            }
            .ledger-page.ledger-mobile-filters-open .ledger-tx-table .ledger-filter-toggle.text-white-50 {
                color: var(--bs-secondary-color) !important;
            }
            .ledger-page.ledger-mobile-filters-open .ledger-tx-table .ledger-th-check,
            .ledger-page.ledger-mobile-filters-open .ledger-tx-table .ledger-th-attach,
            .ledger-page.ledger-mobile-filters-open .ledger-tx-table thead th:not(.ledger-th-filter) {
                display: none;
            }
            /* Full content-area filter sheet: no resize, no anchored dropdown */
            .dropdown-menu.ledger-filter-menu {
                resize: none !important;
            }
            .dropdown-menu.ledger-filter-menu::after {
                display: none !important;
            }
            .dropdown-menu.ledger-filter-menu.show {
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                right: 0 !important;
                bottom: 0 !important;
                width: 100% !important;
                height: 100% !important;
                max-width: none !important;
                max-height: none !important;
                min-width: 0 !important;
                min-height: 0 !important;
                margin: 0 !important;
                transform: none !important;
                inset: 0 !important;
                border-radius: 0 !important;
                border: 0 !important;
                box-shadow: none;
                z-index: 1080 !important;
                padding: calc(0.85rem + env(safe-area-inset-top, 0px)) 0.9rem calc(0.9rem + env(safe-area-inset-bottom, 0px));
            }
            body.ledger-filter-sheet-open {
                overflow: hidden;
            }
            .ledger-f-menu-head {
                padding-bottom: 0.35rem;
                margin-bottom: 0.5rem;
                border-bottom: 1px solid var(--bs-border-color);
            }
            .ledger-f-menu-head .small {
                font-size: 1rem;
                color: var(--bs-body-color) !important;
            }
            .ledger-card-list {
                display: flex;
                flex-direction: column;
                flex: 1 1 auto;
                min-height: 0;
                overflow: auto;
                -webkit-overflow-scrolling: touch;
                padding: 0.5rem 0.65rem 0.85rem;
                gap: 0.55rem;
                background: var(--bs-body-bg);
            }
            .ledger-card-list-head {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 0.5rem;
                padding: 0.15rem 0.15rem 0.35rem;
            }
            .ledger-card {
                display: flex;
                align-items: stretch;
                gap: 0.55rem;
                width: 100%;
                margin: 0;
                padding: 0.7rem 0.8rem;
                border: 1px solid var(--bs-border-color);
                border-radius: 0.7rem;
                background: var(--bs-body-bg);
                box-shadow: 0 0.08rem 0.35rem rgba(0, 0, 0, 0.05);
                min-height: 4.35rem;
                text-align: left;
                color: inherit;
                cursor: pointer;
                -webkit-tap-highlight-color: transparent;
            }
            .ledger-card:active,
            .ledger-card.is-selected {
                background-color: rgba(var(--bs-primary-rgb), 0.08);
                border-color: rgba(var(--bs-primary-rgb), 0.35);
            }
            .ledger-card-check {
                display: flex;
                align-items: flex-start;
                padding-top: 0.15rem;
                flex: 0 0 auto;
            }
            .ledger-card-check .form-check-input {
                width: 1.25rem;
                height: 1.25rem;
                margin: 0;
            }
            .ledger-card-main {
                flex: 1 1 auto;
                min-width: 0;
                display: flex;
                flex-direction: column;
                gap: 0.15rem;
            }
            .ledger-card-top {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 0.75rem;
            }
            .ledger-card-date {
                font-size: 0.78rem;
                color: var(--bs-secondary-color);
                font-weight: 600;
                letter-spacing: 0.01em;
            }
            .ledger-card-amount {
                text-align: right;
                flex: 0 0 auto;
                font-variant-numeric: tabular-nums;
            }
            .ledger-card-amt-value {
                font-size: 1.12rem;
                font-weight: 700;
                font-family: var(--bs-font-monospace);
                line-height: 1.15;
                color: var(--bs-body-color);
            }
            .ledger-card-amt-value.is-debit {
                color: var(--bs-primary);
            }
            .ledger-card-amt-value.is-credit {
                color: var(--bs-success);
            }
            .ledger-card-amt-sides {
                font-size: 0.68rem;
                font-weight: 600;
                letter-spacing: 0.04em;
                text-transform: uppercase;
            }
            .ledger-card-payee {
                font-size: 0.98rem;
                font-weight: 600;
                line-height: 1.25;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            .ledger-card-meta {
                display: flex;
                align-items: center;
                flex-wrap: wrap;
                gap: 0.35rem 0.55rem;
                font-size: 0.75rem;
                color: var(--bs-secondary-color);
            }
            .ledger-card-empty {
                padding: 2.5rem 1rem;
                text-align: center;
                color: var(--bs-secondary-color);
            }
        }

        /* ── Stacked data tables on phones (dashboard, budget, setup, tasks) */
        @media (max-width: 767.98px) {
            table.temper-stack-on-mobile thead {
                display: none;
            }
            table.temper-stack-on-mobile,
            table.temper-stack-on-mobile tbody {
                display: block;
                width: 100%;
            }
            table.temper-stack-on-mobile tr {
                display: block;
                width: 100%;
                background: var(--bs-body-bg);
                border: 1px solid var(--bs-border-color);
                border-radius: 0.65rem;
                margin-bottom: 0.65rem;
                padding: 0.65rem 0.85rem;
                box-shadow: 0 0.06rem 0.28rem rgba(0, 0, 0, 0.04);
            }
            table.temper-stack-on-mobile td {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                gap: 0.75rem;
                width: 100%;
                padding: 0.28rem 0;
                border: 0;
                text-align: right !important;
                white-space: normal !important;
            }
            table.temper-stack-on-mobile td::before {
                content: attr(data-label);
                font-weight: 600;
                color: var(--bs-secondary-color);
                text-align: left;
                flex: 0 1 42%;
                max-width: 42%;
            }
            table.temper-stack-on-mobile td:empty {
                display: none;
            }
            table.temper-stack-on-mobile td:not([data-label])::before,
            table.temper-stack-on-mobile td[colspan]::before {
                content: none;
            }
            table.temper-stack-on-mobile td[colspan] {
                display: block;
                text-align: center !important;
                justify-content: center;
            }
        }

        /* ── Transaction / budget line grids wrap into stacked blocks ───── */
        @media (max-width: 767.98px) {
            #txFormModal .tx-date-col,
            #txFormModal #transaction_date,
            #txFormModal .tx-ref-col,
            #txFormModal #reference_number {
                min-width: 0 !important;
                width: 100%;
            }
            #txLinesTable {
                min-width: 0 !important;
                width: 100%;
            }
            #txLinesTable thead {
                display: none;
            }
            #txLinesTable,
            #txLinesTable tbody {
                display: block;
                width: 100%;
            }
            #txLinesTable tr {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 0.15rem 0.65rem;
                width: 100%;
                border: 1px solid var(--bs-border-color);
                border-radius: 0.65rem;
                margin-bottom: 0.75rem;
                padding: 0.7rem 0.8rem;
                background: var(--bs-body-bg);
            }
            #txLinesTable td {
                display: flex;
                flex-direction: column;
                align-items: stretch;
                gap: 0.2rem;
                width: 100% !important;
                min-width: 0 !important;
                max-width: none !important;
                border: 0 !important;
                padding: 0.28rem 0 !important;
            }
            #txLinesTable td:nth-child(-n+5),
            #txLinesTable td:nth-child(8) {
                grid-column: 1 / -1;
            }
            #txLinesTable td::before {
                content: attr(data-label);
                font-size: 0.72rem;
                font-weight: 600;
                color: var(--bs-secondary-color);
            }
            #txLinesTable td:nth-child(8)::before {
                content: none;
            }
            #txLinesTable td:nth-child(8) {
                align-items: flex-end;
            }
            #txLinesTable .line-amount,
            #txLinesTable .line-note,
            #txLinesTable .line-cat-label,
            #txLinesTable select {
                min-width: 0 !important;
                max-width: none !important;
                width: 100%;
            }
            #txLinesTable .line-cat-label {
                white-space: normal;
                max-width: none;
            }
            #linesTable {
                min-width: 0 !important;
                width: 100%;
                table-layout: auto;
            }
            #linesTable thead {
                display: none;
            }
            #linesTable,
            #linesTable tbody {
                display: block;
                width: 100%;
            }
            #linesTable tr.line-row {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 0.15rem 0.65rem;
                width: 100%;
                border: 1px solid var(--bs-border-color);
                border-radius: 0.65rem;
                margin-bottom: 0.75rem;
                padding: 0.7rem 0.8rem;
                background: var(--bs-body-bg);
            }
            #linesTable td {
                display: flex;
                flex-direction: column;
                align-items: stretch;
                gap: 0.2rem;
                width: 100% !important;
                min-width: 0 !important;
                border: 0 !important;
                padding: 0.28rem 0 !important;
                overflow: visible;
                white-space: normal;
            }
            #linesTable td.line-cell-cat:first-child,
            #linesTable td.line-cell-notes {
                grid-column: 1 / -1;
            }
            #linesTable td.line-actions {
                grid-column: 1 / -1;
                align-items: flex-end;
            }
            #linesTable td::before {
                content: attr(data-label);
                font-size: 0.72rem;
                font-weight: 600;
                color: var(--bs-secondary-color);
            }
            #linesTable td.line-actions::before {
                content: none;
            }
            .budget-lines-table-wrap {
                overflow-x: visible;
            }
        }

        /*
         * Scrollable modals whose header/body/footer live inside a <form>
         * (Ledger Add/Edit/View, lookup dialogs). Bootstrap sizes .modal-content
         * and scrolls .modal-body only when those are direct flex children.
         */
        #txFormModal.modal {
            overflow: hidden;
        }
        .modal-dialog-scrollable .modal-content > form {
            display: flex;
            flex-direction: column;
            flex: 1 1 auto;
            min-height: 0;
            height: 100%;
            overflow: hidden;
        }
        .modal-dialog-scrollable .modal-content > form > .modal-header,
        .modal-dialog-scrollable .modal-content > form > .modal-footer {
            flex: 0 0 auto;
        }
        .modal-dialog-scrollable .modal-content > form > .modal-body {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            overscroll-behavior: contain;
        }

        /* ── Modals: full-height on phones, actions stay on screen ──────── */
        @media (max-width: 575.98px) {
            .modal-dialog.modal-xl,
            .modal-dialog.modal-lg,
            .modal-dialog.modal-fullscreen-sm-down {
                margin: 0;
                max-width: 100%;
            }
            .modal-dialog-scrollable.modal-fullscreen-sm-down .modal-content {
                height: 100%;
                max-height: 100%;
                border-radius: 0;
            }
            .modal-footer {
                flex-wrap: wrap;
                gap: 0.45rem;
                position: sticky;
                bottom: 0;
                background: var(--bs-modal-bg, var(--bs-body-bg));
                z-index: 3;
                padding-bottom: calc(0.5rem + env(safe-area-inset-bottom, 0px));
            }
            .modal-footer .btn {
                min-height: 2.75rem;
            }
        }
        @media (max-width: 767.98px) {
            #txFormModal .modal-dialog {
                max-height: 100%;
            }
            #txFormModal .modal-content {
                max-height: 100dvh;
            }
        }
    </style>
</head>
    <script>
    // Central session-expiry handling for the SPA shell.
    // On 401 / X-Auth-Required: redirect to login immediately and suppress follow-on error toasts.
    // Idle timeout (System Configuration) also forces an immediate logout redirect while the page is open.
    (function() {
        const LOGIN_EXPIRED = 'login.php?expired=1';
        const LOGOUT_EXPIRED = 'logout.php?expired=1';

        // Injected from System Configuration (Login Timeout)
        window.__temperLoginTimeout = {
            enabled: <?= $temperLoginTimeoutEnabled ? 'true' : 'false' ?>,
            seconds: <?= (int)$temperLoginTimeoutSeconds ?>
        };

        // Injected from System Configuration (sidebar hover delays, seconds)
        window.__temperSidebarHover = {
            expandSeconds: <?= json_encode((float)$temperSidebarHoverExpandSec) ?>,
            collapseSeconds: <?= json_encode((float)$temperSidebarHoverCollapseSec) ?>
        };

        window.__temperAuthRedirecting = false;

        /** Scrub visible app content before leaving so sensitive data is not left on screen. */
        function scrubSensitiveDom() {
            try {
                const main = document.getElementById('main-content');
                if (main) {
                    main.innerHTML = '<div class="p-4 text-center text-muted">Session expired. Redirecting to login…</div>';
                }
                const c = document.getElementById('appToastContainer');
                if (c) c.innerHTML = '';
                // Blank form fields that may still hold credentials or PII
                document.querySelectorAll('input, textarea').forEach(function(el) {
                    try {
                        if (el && el.type !== 'hidden' && el.type !== 'submit' && el.type !== 'button') {
                            el.value = '';
                        }
                    } catch (e) { /* ignore */ }
                });
            } catch (e) { /* ignore */ }
        }

        window.redirectToLoginExpired = function(opts) {
            if (window.__temperAuthRedirecting) return;
            window.__temperAuthRedirecting = true;
            scrubSensitiveDom();
            const useLogout = opts && opts.destroySession;
            // replace() avoids back-button returning to a dead authenticated shell
            window.location.replace(useLogout ? LOGOUT_EXPIRED : LOGIN_EXPIRED);
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
            // Any authenticated network activity counts as activity for the idle timer
            if (typeof window.__temperIdlePing === 'function') {
                try { window.__temperIdlePing(); } catch (e) { /* ignore */ }
            }
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

        // ── Client idle login timeout ──────────────────────────────────────
        // Single authority: window.__temperLoginTimeout (Developer Mode).
        // enabled=false when Developer Mode is ON → no warning modal, no idle redirect.
        // enabled=true (Developer Mode OFF) → fixed 10-minute idle; 60s warning before logout;
        // "Stay logged in" refreshes the server session and resets the timer.
        // Warning modal must appear above any open Bootstrap modals without closing them.
        // Re-reads config so Configuration saves apply without full reload.
        (function initIdleLoginTimeout() {
            const WARN_LEAD_SEC = 60;
            const PING_URL = 'pages/session_ping.php';
            const MODAL_Z = '20050';
            const BACKDROP_Z = '20040';

            let lastActivity = Date.now();
            let timerId = null;
            let countdownId = null;
            let checking = false;
            let warningOpen = false;
            let modalInst = null;
            // While true, background activity must not silently reset (user must dismiss modal)
            let ignoreActivity = false;

            function currentCfg() {
                const cfg = window.__temperLoginTimeout || {};
                const raw = cfg.enabled;
                // Explicit disable is authoritative (bool, 0/1, or common string forms).
                // Missing/unknown defaults to enabled (fail-safe: keep idle timeout on).
                let enabled = true;
                if (raw === false || raw === 0 || raw === '0' || raw === 'false' || raw === 'off' || raw === 'no') {
                    enabled = false;
                } else if (raw === true || raw === 1 || raw === '1' || raw === 'true' || raw === 'on' || raw === 'yes') {
                    enabled = true;
                }
                return {
                    enabled: enabled,
                    seconds: Math.max(30, parseInt(cfg.seconds, 10) || 600)
                };
            }

            /** Seconds of warning before hard logout. Prefer 60; shorter if timeout is short. */
            function warnLeadSeconds(totalSec) {
                if (totalSec > WARN_LEAD_SEC) return WARN_LEAD_SEC;
                return Math.max(10, Math.floor(totalSec / 2));
            }

            function remainingMs() {
                return currentCfg().seconds * 1000 - (Date.now() - lastActivity);
            }

            function getModal() {
                return document.getElementById('sessionTimeoutModal');
            }

            /**
             * Lift timeout modal above every other modal/backdrop without touching them.
             * Marks the newest backdrop so SPA cleanup can preserve it.
             */
            function elevateTimeoutLayer(el) {
                if (!el) return;
                try {
                    el.style.zIndex = MODAL_Z;
                    el.classList.add('session-timeout-modal');
                    // Mark the topmost backdrop that Bootstrap just (or previously) created
                    const backs = document.querySelectorAll('body > .modal-backdrop');
                    if (backs.length) {
                        const last = backs[backs.length - 1];
                        last.classList.add('session-timeout-backdrop');
                        last.style.zIndex = BACKDROP_Z;
                    }
                    // Ensure we are the last modal in body so paint order also favors us
                    if (el.parentElement === document.body && el.nextSibling) {
                        document.body.appendChild(el);
                    }
                } catch (e) { /* ignore */ }
            }

            function clearTimeoutBackdropMarks() {
                try {
                    document.querySelectorAll('.modal-backdrop.session-timeout-backdrop').forEach(function(b) {
                        b.classList.remove('session-timeout-backdrop');
                        b.style.removeProperty('z-index');
                    });
                } catch (e) { /* ignore */ }
            }

            function ensureModalOnBody() {
                let el = getModal();
                if (!el) return null;
                if (typeof window.mountModalOnBody === 'function') {
                    el = window.mountModalOnBody(el) || el;
                } else if (el.parentElement !== document.body) {
                    document.body.appendChild(el);
                }
                return el;
            }

            function getModalInstance() {
                const el = ensureModalOnBody();
                if (!el || typeof bootstrap === 'undefined' || !bootstrap.Modal) return null;
                // Re-bind if instance was disposed or element was recreated
                try {
                    const existing = bootstrap.Modal.getInstance(el);
                    if (existing) {
                        modalInst = existing;
                        return modalInst;
                    }
                } catch (e) { /* fall through */ }
                modalInst = bootstrap.Modal.getOrCreateInstance(el, {
                    backdrop: 'static',
                    keyboard: false,
                    focus: true
                });
                return modalInst;
            }

            function setCountdownDisplay(sec) {
                const n = Math.max(0, Math.ceil(sec));
                const el = document.getElementById('sessionTimeoutCountdown');
                const pl = document.getElementById('sessionTimeoutCountdownPlural');
                if (el) el.textContent = String(n);
                if (pl) pl.textContent = n === 1 ? '' : 's';
            }

            function stopCountdown() {
                if (countdownId) {
                    clearInterval(countdownId);
                    countdownId = null;
                }
            }

            function hideWarning() {
                stopCountdown();
                warningOpen = false;
                ignoreActivity = false;
                const el = getModal();
                const inst = getModalInstance();
                if (inst) {
                    try { inst.hide(); } catch (e) { /* ignore */ }
                }
                // Manual cleanup for fallback forced-show path (other open modals stay intact)
                if (el) {
                    try {
                        el.classList.remove('show');
                        el.style.display = '';
                        el.setAttribute('aria-hidden', 'true');
                        el.removeAttribute('aria-modal');
                        el.style.removeProperty('z-index');
                    } catch (e) { /* ignore */ }
                }
                // Remove marked timeout backdrops before unmarking (order matters)
                try {
                    document.querySelectorAll('body > .modal-backdrop.session-timeout-backdrop').forEach(function(b) {
                        b.remove();
                    });
                } catch (e) { /* ignore */ }
                clearTimeoutBackdropMarks();
                // Restore body scroll lock if another modal is still open
                try {
                    const otherOpen = document.querySelector('body > .modal.show:not(#sessionTimeoutModal)');
                    if (otherOpen) {
                        document.body.classList.add('modal-open');
                    } else if (!document.querySelector('body > .modal.show')) {
                        document.body.classList.remove('modal-open');
                    }
                } catch (e) { /* ignore */ }
            }

            /** Tear down all idle timers and hide the modal (disabled / reschedule path). */
            function disarmIdleTimeout() {
                clearSchedule();
                hideWarning();
                checking = false;
            }

            function expireNow() {
                if (checking || window.__temperAuthRedirecting) return;
                // Disabled mid-flight or residual timer: never redirect for idle
                if (!currentCfg().enabled) {
                    disarmIdleTimeout();
                    return;
                }
                checking = true;
                hideWarning();
                window.redirectToLoginExpired({ destroySession: true });
            }

            function showWarning() {
                if (warningOpen || window.__temperAuthRedirecting) return;
                if (!currentCfg().enabled) {
                    disarmIdleTimeout();
                    return;
                }
                const el = ensureModalOnBody();
                if (!el) {
                    // Modal markup missing — still schedule hard expiry via schedule()
                    return;
                }
                warningOpen = true;
                ignoreActivity = true;
                const rem = Math.max(0, remainingMs() / 1000);
                setCountdownDisplay(rem);
                wireStayButton();

                // Do not hide/dispose any other open modals — only stack above them
                const inst = getModalInstance();
                let shown = false;
                if (inst) {
                    try {
                        inst.show();
                        shown = true;
                    } catch (e) { /* fallback below */ }
                }
                // Fallback if Bootstrap unavailable or show() failed: force visible overlay
                if (!shown || !el.classList.contains('show')) {
                    try {
                        el.classList.add('show');
                        el.style.display = 'block';
                        el.removeAttribute('aria-hidden');
                        el.setAttribute('aria-modal', 'true');
                        el.setAttribute('role', 'dialog');
                        document.body.classList.add('modal-open');
                        if (!document.querySelector('.modal-backdrop.session-timeout-backdrop')) {
                            const bd = document.createElement('div');
                            bd.className = 'modal-backdrop fade show session-timeout-backdrop';
                            bd.style.zIndex = BACKDROP_Z;
                            document.body.appendChild(bd);
                        }
                    } catch (e) { /* ignore */ }
                }

                elevateTimeoutLayer(el);
                // Re-elevate after Bootstrap finishes animating (new backdrop created async)
                const reelevate = function() {
                    elevateTimeoutLayer(el);
                    // Focus primary action so modal is interactable above focus traps
                    const btn = document.getElementById('sessionTimeoutStayBtn');
                    if (btn && typeof btn.focus === 'function') {
                        try { btn.focus(); } catch (e) { /* ignore */ }
                    }
                };
                el.addEventListener('shown.bs.modal', reelevate, { once: true });
                setTimeout(reelevate, 50);
                setTimeout(reelevate, 200);

                stopCountdown();
                countdownId = setInterval(function() {
                    if (window.__temperAuthRedirecting) {
                        stopCountdown();
                        return;
                    }
                    if (!currentCfg().enabled) {
                        disarmIdleTimeout();
                        return;
                    }
                    // Keep layer on top if another modal reopened underneath
                    elevateTimeoutLayer(el);
                    const left = remainingMs() / 1000;
                    setCountdownDisplay(left);
                    if (left <= 0) {
                        stopCountdown();
                        expireNow();
                    }
                }, 250);
            }

            function clearSchedule() {
                if (timerId) {
                    clearTimeout(timerId);
                    timerId = null;
                }
            }

            function schedule() {
                clearSchedule();
                if (window.__temperAuthRedirecting || checking) return;
                const cfg = currentCfg();
                // Authoritative: when Login Timeout is disabled (Developer Mode ON), no modal / no idle redirect
                if (!cfg.enabled) {
                    disarmIdleTimeout();
                    return;
                }

                const totalMs = cfg.seconds * 1000;
                const warnMs = warnLeadSeconds(cfg.seconds) * 1000;
                const idle = Date.now() - lastActivity;
                const rem = totalMs - idle;

                if (rem <= 0) {
                    expireNow();
                    return;
                }

                // Enter or stay in warning window (always at ≤60s remaining when total > 60s)
                if (rem <= warnMs) {
                    if (!warningOpen) showWarning();
                    // Next hard deadline
                    timerId = setTimeout(function() {
                        if (!currentCfg().enabled) {
                            disarmIdleTimeout();
                            return;
                        }
                        if (remainingMs() <= 0) expireNow();
                        else schedule();
                    }, Math.max(50, rem));
                    return;
                }

                // Still in quiet idle period — hide warning if it was open (e.g. after stay)
                if (warningOpen) hideWarning();
                const untilWarn = rem - warnMs;
                timerId = setTimeout(function() {
                    schedule();
                }, Math.max(50, untilWarn));
            }

            /**
             * Reset client idle clock. When fromUserActivity is false (explicit stay / server ping),
             * always apply. When true, ignore while the warning modal is open.
             * When timeout is disabled, only keeps activity stamp; does not arm timers.
             */
            function ping(fromUserActivity) {
                if (window.__temperAuthRedirecting) return;
                if (fromUserActivity && ignoreActivity) return;
                lastActivity = Date.now();
                schedule();
            }

            window.__temperIdlePing = function() {
                // Network activity from fetch wrapper — do not dismiss the warning silently
                // When timeout is disabled, schedule() no-ops (disarms only).
                ping(true);
            };

            /** Re-apply current config (e.g. after System Configuration save). */
            window.__temperRescheduleLoginTimeout = function() {
                schedule();
            };

            /** Explicit session refresh (Stay logged in). */
            function stayLoggedIn() {
                if (window.__temperAuthRedirecting) return;
                const btn = document.getElementById('sessionTimeoutStayBtn');
                if (btn) {
                    btn.disabled = true;
                    btn.textContent = 'Refreshing…';
                }
                // Optimistically reset client timer; server confirm follows
                ignoreActivity = false;
                lastActivity = Date.now();
                hideWarning();
                schedule();

                const body = new FormData();
                body.append('action', 'ping');
                originalFetch(PING_URL, {
                    method: 'POST',
                    body: body,
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin'
                })
                    .then(function(response) {
                        if (window.isAuthExpiredResponse && window.isAuthExpiredResponse(response)) {
                            window.redirectToLoginExpired();
                            return null;
                        }
                        return response.json().catch(function() { return null; });
                    })
                    .then(function(data) {
                        if (btn) {
                            btn.disabled = false;
                            btn.textContent = 'Stay logged in';
                        }
                        if (!data || data.success === false) {
                            // Server rejected — force login
                            expireNow();
                            return;
                        }
                        // Apply any config returned by ping
                        if (window.__temperLoginTimeout) {
                            if (typeof data.login_timeout_enabled === 'boolean') {
                                window.__temperLoginTimeout.enabled = data.login_timeout_enabled;
                            }
                            if (data.login_timeout_seconds != null) {
                                window.__temperLoginTimeout.seconds = data.login_timeout_seconds;
                            }
                        }
                        lastActivity = Date.now();
                        schedule();
                        if (typeof showToast === 'function') {
                            showToast('Session extended.', 'success', 2500);
                        }
                    })
                    .catch(function() {
                        if (btn) {
                            btn.disabled = false;
                            btn.textContent = 'Stay logged in';
                        }
                        // Offline or error: keep client extension but note uncertainty
                        lastActivity = Date.now();
                        schedule();
                    });
            }

            window.__temperStayLoggedIn = stayLoggedIn;

            // Wire modal button when DOM is ready
            function wireStayButton() {
                const btn = document.getElementById('sessionTimeoutStayBtn');
                if (btn && !btn.dataset.wired) {
                    btn.dataset.wired = '1';
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        stayLoggedIn();
                    });
                }
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', wireStayButton);
            } else {
                wireStayButton();
            }

            const activityEvents = [
                'mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'touchmove', 'click', 'wheel'
            ];
            let rafPending = false;
            function onActivity() {
                if (rafPending) return;
                rafPending = true;
                requestAnimationFrame(function() {
                    rafPending = false;
                    ping(true);
                });
            }
            activityEvents.forEach(function(ev) {
                document.addEventListener(ev, onActivity, { capture: true, passive: true });
            });
            window.addEventListener('focus', function() {
                if (!currentCfg().enabled) return;
                ping(true);
            });
            document.addEventListener('visibilitychange', function() {
                if (!document.hidden) {
                    // Tab visible again — re-evaluate idle (no-op when timeout disabled)
                    schedule();
                }
            });

            // Arm only when enabled; when disabled, ensure any residual state is clear
            schedule();
        })();
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
        var setupGroup = page.indexOf('setup_') === 0;
        var moreGroup = (
            page === 'tasks' || page === 'profile' || page === 'admin'
            || page.indexOf('admin-') === 0
            || page === 'ledger_import' || page === 'ledger_bank_export'
        );
        document.querySelectorAll('[data-nav-page]').forEach(function(a) {
            var key = a.getAttribute('data-nav-page');
            var on = key === page;
            if (setupGroup && key === 'setup') on = true;
            if (key === 'more') on = moreGroup;
            a.classList.toggle('active', on);
            if (on && key !== 'more' && key !== 'setup') {
                var parent = a.closest('.collapse');
                while (parent) {
                    parent.classList.add('show');
                    var id = parent.id;
                    if (id) {
                        document.querySelectorAll('[href="#' + id + '"], [data-bs-target="#' + id + '"]').forEach(function(t) {
                            t.setAttribute('aria-expanded', 'true');
                            t.classList.remove('collapsed');
                        });
                    }
                    parent = parent.parentElement ? parent.parentElement.closest('.collapse') : null;
                }
            }
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

        // Unsaved form guard (sidebar, mobile nav, in-app links)
        if (typeof window.TemperDirtyForms !== 'undefined') {
            if (window.TemperDirtyForms.isDirty() && !window.TemperDirtyForms.confirmLeave()) {
                return;
            }
            // Always clear before tearing down #main-content so checkers don't touch dead DOM
            window.TemperDirtyForms.clearAll();
        }

        if (typeof window.closeMobileNav === 'function') {
            window.closeMobileNav();
        }
        if (typeof window.setActiveNav === 'function') {
            window.setActiveNav(page);
        }

        const contentArea = document.getElementById('main-content');

        // Drop body-mounted page modals from the previous fragment before the spinner
        if (typeof window.cleanupFragmentModals === 'function') {
            window.cleanupFragmentModals();
        }
        // Drop lookup / ledger page hotkey listeners / banner before tearing down fragment
        if (window.TemperLookupPage && typeof window.TemperLookupPage.disposeActive === 'function') {
            window.TemperLookupPage.disposeActive();
        }
        if (window.TemperLedgerPage && typeof window.TemperLedgerPage.disposeActive === 'function') {
            window.TemperLedgerPage.disposeActive();
        }

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
                    if (typeof window.TemperDirtyForms !== 'undefined') {
                        window.TemperDirtyForms.clearAll();
                    }
                    if (typeof window.cleanupFragmentModals === 'function') {
                        window.cleanupFragmentModals();
                    }
                    contentArea.innerHTML = html;
                    if (typeof window.mountFragmentModals === 'function') {
                        window.mountFragmentModals(contentArea);
                    }
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
