<?php
// Common Setup & Security
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

// Central session check (full-page shell)
requireLogin();

// Idle login timeout for client-side enforcement (System Configuration)
$temperLoginTimeout = function_exists('getClientLoginTimeoutConfig')
    ? getClientLoginTimeoutConfig()
    : ['enabled' => true, 'seconds' => 300];
$temperLoginTimeoutEnabled = !empty($temperLoginTimeout['enabled']);
$temperLoginTimeoutSeconds = max(30, (int)($temperLoginTimeout['seconds'] ?? 300));

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

        /* ── Utility: page titles that wrap cleanly ──────────────────────── */
        .page-title-row {
            gap: 0.5rem;
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
        // Single authority: window.__temperLoginTimeout (System Configuration).
        // When enabled=false ("Disable Login Timeout"), never show the warning modal
        // and never redirect for idle time — timers are cleared and stay off.
        // When enabled, shows a 60s warning before logout (or half of timeout if short);
        // "Stay logged in" refreshes the server session and resets the timer.
        // Re-reads config so Configuration saves apply without full reload.
        (function initIdleLoginTimeout() {
            const WARN_LEAD_SEC = 60;
            const PING_URL = 'pages/session_ping.php';

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
                    seconds: Math.max(30, parseInt(cfg.seconds, 10) || 300)
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

            function getModalInstance() {
                let el = getModal();
                if (!el || typeof bootstrap === 'undefined' || !bootstrap.Modal) return null;
                // Same stacking fix as fragment modals (shell modal starts under #main-content-col)
                if (typeof window.mountModalOnBody === 'function') {
                    el = window.mountModalOnBody(el) || el;
                }
                if (!modalInst) {
                    modalInst = bootstrap.Modal.getOrCreateInstance(el, {
                        backdrop: 'static',
                        keyboard: false
                    });
                }
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
                const inst = getModalInstance();
                if (inst) {
                    try { inst.hide(); } catch (e) { /* ignore */ }
                }
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
                const el = getModal();
                if (!el) {
                    // Modal markup missing — fall through to hard expiry only
                    return;
                }
                warningOpen = true;
                ignoreActivity = true;
                const rem = Math.max(0, remainingMs() / 1000);
                setCountdownDisplay(rem);

                const inst = getModalInstance();
                if (inst) {
                    try { inst.show(); } catch (e) { /* ignore */ }
                }

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
                // Authoritative: when Login Timeout is disabled, no modal and no idle redirect
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

                // Enter or stay in warning window
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
        // Drop lookup page hotkey listeners / banner before tearing down fragment
        if (window.TemperLookupPage && typeof window.TemperLookupPage.disposeActive === 'function') {
            window.TemperLookupPage.disposeActive();
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
