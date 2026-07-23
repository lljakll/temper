<?php
// Common Setup & Security
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

// Central session check (shell footer)
requireLogin();

// Get current user info + permissions for mobile nav
$user = getCurrentUser();
require_once __DIR__ . '/permissions.php';

// Database connection (only create if not already set)
if (!isset($db)) {
    $db = getDbConnection();
}

$footerDb = getDbConnection();
$footerAcl = $user ? loadUserAcl($footerDb, (int)$user['id']) : null;
$footerPerms = $footerAcl['permissions'] ?? [];
$footerCan = static function (string $perm) use ($footerPerms): bool {
    return permissionSetAllows($footerPerms, $perm);
};
$footerDb->close();
?>

</div><!-- /#main-content-col -->
</div><!-- /.row -->
</div><!-- /.container-fluid -->

<?php $footerMustChange = !empty($_SESSION['must_change_password']); ?>
<?php if (!$footerMustChange): ?>
<!-- Mobile bottom navigation -->
<nav class="mobile-bottom-nav d-md-none" aria-label="Primary">
<?php if ($footerCan('page.dashboard')): ?>
    <a href="javascript:void(0)" onclick="loadPage('dashboard')" data-nav-page="dashboard">
        <i class="bi bi-speedometer2"></i>
        <span>Home</span>
    </a>
<?php endif; ?>
<?php if ($footerCan('page.ledger')): ?>
    <a href="javascript:void(0)" onclick="loadPage('ledger')" data-nav-page="ledger">
        <i class="bi bi-currency-dollar"></i>
        <span>Ledger</span>
    </a>
<?php endif; ?>
<?php if ($footerCan('page.reports')): ?>
    <a href="javascript:void(0)" onclick="loadPage('reports')" data-nav-page="reports">
        <i class="bi bi-file-earmark-bar-graph"></i>
        <span>Reports</span>
    </a>
<?php endif; ?>
<?php if ($footerCan('page.tasks')): ?>
    <a href="javascript:void(0)" onclick="loadPage('tasks')" data-nav-page="tasks">
        <i class="bi bi-check2-square"></i>
        <span>Tasks</span>
    </a>
<?php endif; ?>
<?php if (!$footerCan('page.dashboard') && !$footerCan('page.ledger')): ?>
    <a href="javascript:void(0)" onclick="loadPage('profile')" data-nav-page="profile">
        <i class="bi bi-person-circle"></i>
        <span>Profile</span>
    </a>
    <a href="logout.php">
        <i class="bi bi-box-arrow-right"></i>
        <span>Logout</span>
    </a>
<?php endif; ?>
</nav>
<?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    (function() {
        const TOAST_VARIANTS = {
            success: 'success',
            danger: 'danger',
            error: 'danger',
            warning: 'warning',
            info: 'info',
        };

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = String(text);
            return div.innerHTML;
        }

        window.showToast = function(message, type = 'info', delay = 4500) {
            // Never surface toasts while redirecting to login after session expiry
            if (window.__temperAuthRedirecting) return null;
            if (typeof window.isAuthExpiredPayload === 'function' && window.isAuthExpiredPayload(message)) {
                if (typeof window.redirectToLoginExpired === 'function') {
                    window.redirectToLoginExpired();
                }
                return null;
            }
            const msg = String(message || '');
            if (/session has expired|auth_required|AUTH_REQUIRED|please log in again/i.test(msg)) {
                if (typeof window.redirectToLoginExpired === 'function') {
                    window.redirectToLoginExpired();
                }
                return null;
            }
            const container = document.getElementById('appToastContainer');
            const variant = TOAST_VARIANTS[type] || type || 'info';
            if (!container || typeof bootstrap === 'undefined' || !bootstrap.Toast) {
                console.warn('[toast]', message);
                return null;
            }
            const el = document.createElement('div');
            el.className = 'toast align-items-center text-bg-' + variant + ' border-0';
            el.setAttribute('role', 'alert');
            el.innerHTML = '<div class="d-flex"><div class="toast-body">' + escapeHtml(message) + '</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>';
            container.appendChild(el);
            const toast = new bootstrap.Toast(el, { autohide: true, delay: delay });
            el.addEventListener('hidden.bs.toast', function() { el.remove(); }, { once: true });
            toast.show();
            return toast;
        };

        /**
         * Extract a clean toast from a POST response body.
         * Handles: pure JSON APIs, embedded page-flash JSON, plain-text prefixes before HTML.
         * Never surfaces raw HTML or raw JSON script tags as the toast message.
         */
        window.toastFromPostResponse = function(text) {
            if (window.__temperAuthRedirecting) return { handled: false, success: null };
            if (!text || typeof text !== 'string') return { handled: false, success: null };
            const trimmed = text.trim();
            if (!trimmed) return { handled: false, success: null };
            if (typeof window.isAuthExpiredPayload === 'function' && window.isAuthExpiredPayload(trimmed)) {
                if (typeof window.redirectToLoginExpired === 'function') {
                    window.redirectToLoginExpired();
                }
                return { handled: true, success: false };
            }

            // 1) Embedded page-flash / ledger-flash (preferred for HTML fragments)
            const flashRe = /id=["'](?:page-flash|ledger-flash)["'][^>]*>\s*(\{[\s\S]*?\})\s*<\/script>/i;
            const flashMatch = trimmed.match(flashRe);
            if (flashMatch) {
                try {
                    const flash = JSON.parse(flashMatch[1]);
                    if (flash && flash.message) {
                        showToast(String(flash.message), flash.type || 'success', flash.delay || 4500);
                        return { handled: true, success: (flash.type || 'success') !== 'danger' };
                    }
                } catch (e) { /* fall through */ }
            }

            // 2) Pure JSON body
            if (trimmed.charAt(0) === '{' || trimmed.charAt(0) === '[') {
                try {
                    const data = JSON.parse(trimmed);
                    if (data && typeof data === 'object' && !Array.isArray(data)) {
                        if (data.error) {
                            showToast(String(data.error), 'danger');
                            return { handled: true, success: false, data: data };
                        }
                        if (data.message) {
                            showToast(String(data.message), data.success === false ? 'danger' : 'success');
                            return { handled: true, success: data.success !== false, data: data };
                        }
                        return { handled: true, success: data.success !== false, data: data };
                    }
                } catch (e) { /* fall through */ }
            }

            // 3) Plain-text line before any HTML (setup pages: "Fund added successfully\n<div…")
            // Skip lines that are HTML/script/JSON noise
            const htmlIdx = trimmed.search(/</);
            const prefix = (htmlIdx > 0 ? trimmed.slice(0, htmlIdx) : (/^\s*</.test(trimmed) ? '' : trimmed)).trim();
            const line = prefix.split('\n').map(function(l) { return l.trim(); }).find(function(l) {
                return l.length > 0 && l.charAt(0) !== '<' && l.charAt(0) !== '{' && l.charAt(0) !== '[';
            });
            if (!line) return { handled: false, success: null };

            const lower = line.toLowerCase();
            if (lower.indexOf('error') === 0) {
                showToast(line, 'danger');
                return { handled: true, success: false };
            }
            if (lower.indexOf('success') !== -1 || /added|updated|deleted|saved|archived|cleared|restored|reset/.test(lower)) {
                showToast(line, 'success');
                return { handled: true, success: true };
            }
            showToast(line, 'info');
            return { handled: true, success: true };
        };

        window.showActionResponse = function(text) {
            window.toastFromPostResponse(text);
        };

        window.consumePageFlash = function(id) {
            if (window.__temperAuthRedirecting) return;
            const el = document.getElementById(id || 'page-flash');
            if (!el) return;
            try {
                const flash = JSON.parse(el.textContent);
                if (flash && flash.message) {
                    showToast(flash.message, flash.type || 'success', flash.delay || 4500);
                }
            } catch (e) { /* ignore malformed flash */ }
            el.remove();
        };

        window.submitFormAndReload = function(postUrl, formData, reloadUrl) {
            return fetch(postUrl, {
                method: 'POST',
                body: formData,
                headers: { 'Accept': 'application/json, text/html;q=0.9,*/*;q=0.8' },
            })
                .then(function(r) { return r.text(); })
                .then(function(text) {
                    if (window.__temperAuthRedirecting) return null;
                    const result = toastFromPostResponse(text);
                    if (result.success === false) {
                        return null; // keep current form state on error
                    }
                    // Successful save: drop dirty flags before fragment reload
                    if (typeof window.TemperDirtyForms !== 'undefined') {
                        window.TemperDirtyForms.markClean();
                    }
                    if (result.data && result.data.must_change_password === false) {
                        window.__temperMustChangePassword = false;
                        if (typeof window.setForcePasswordShell === 'function') {
                            window.setForcePasswordShell(false);
                        }
                    }
                    return fetch(reloadUrl);
                })
                .then(function(r) {
                    if (!r || window.__temperAuthRedirecting) return null;
                    return r.text();
                })
                .then(function(html) {
                    if (html == null || window.__temperAuthRedirecting) return;
                    if (typeof window.isAuthExpiredPayload === 'function' && window.isAuthExpiredPayload(html)) {
                        window.redirectToLoginExpired();
                        return;
                    }
                    // Reload fragment; skip re-toasting page-flash if we already toasted from POST
                    if (typeof applyMainContent === 'function') {
                        applyMainContent(html, { skipFlash: true });
                        return;
                    }
                    const main = document.getElementById('main-content');
                    if (!main) return;
                    main.innerHTML = html;
                    // Remove flash nodes without toasting again (already handled from POST)
                    ['page-flash', 'ledger-flash'].forEach(function(fid) {
                        const el = document.getElementById(fid);
                        if (el) el.remove();
                    });
                })
                .catch(function(err) {
                    if (window.__temperAuthRedirecting) return;
                    console.error(err);
                    showToast('Request failed. Please try again.', 'danger');
                });
        };

        /**
         * Dirty form detection (Bootstrap/jQuery-style pattern).
         *
         * Opt-in: add data-dirty-track to a <form>. Any input/change inside
         * marks it dirty (data-dirty="1"). Fields or containers with
         * data-dirty-ignore are skipped.
         *
         * Complex pages (ledger, budget) may also registerChecker(fn).
         *
         * Before SPA navigation (loadPage) or full page leave (beforeunload),
         * confirmLeave() prompts: "You have unsaved changes. Leave anyway?"
         */
        (function initDirtyForms() {
            const MESSAGE = 'You have unsaved changes. Leave anyway?';
            let customCheckers = [];

            function resolveForm(formOrEl) {
                if (!formOrEl) return null;
                if (formOrEl.tagName === 'FORM') return formOrEl;
                if (formOrEl.closest) return formOrEl.closest('form');
                return null;
            }

            function trackedFormsDirty() {
                const forms = document.querySelectorAll('form[data-dirty-track]');
                for (let i = 0; i < forms.length; i++) {
                    if (forms[i].getAttribute('data-dirty') === '1') return true;
                }
                return false;
            }

            function customDirty() {
                for (let i = 0; i < customCheckers.length; i++) {
                    try {
                        if (customCheckers[i]()) return true;
                    } catch (e) { /* ignore broken checker */ }
                }
                return false;
            }

            window.TemperDirtyForms = {
                MESSAGE: MESSAGE,
                isDirty: function() {
                    return trackedFormsDirty() || customDirty();
                },
                markDirty: function(formOrEl) {
                    const form = resolveForm(formOrEl);
                    if (form) form.setAttribute('data-dirty', '1');
                },
                markClean: function(formOrEl) {
                    if (!formOrEl) {
                        document.querySelectorAll('form[data-dirty-track]').forEach(function(f) {
                            f.removeAttribute('data-dirty');
                        });
                        return;
                    }
                    const form = resolveForm(formOrEl);
                    if (form) form.removeAttribute('data-dirty');
                },
                clearAll: function() {
                    document.querySelectorAll('form[data-dirty]').forEach(function(f) {
                        f.removeAttribute('data-dirty');
                    });
                    customCheckers = [];
                },
                /**
                 * Register a page-specific isDirty() callback.
                 * Returns an unregister function. Cleared automatically on content swap.
                 */
                registerChecker: function(fn) {
                    if (typeof fn !== 'function') return function() {};
                    customCheckers.push(fn);
                    return function unregister() {
                        customCheckers = customCheckers.filter(function(c) { return c !== fn; });
                    };
                },
                confirmLeave: function(message) {
                    if (!this.isDirty()) return true;
                    return window.confirm(message || MESSAGE);
                }
            };

            // jQuery-friendly aliases (standard pattern)
            window.isFormDirty = function() {
                return window.TemperDirtyForms.isDirty();
            };
            window.confirmLeaveIfDirty = function(message) {
                return window.TemperDirtyForms.confirmLeave(message);
            };

            function markFromEvent(e) {
                const t = e.target;
                if (!t || !t.closest) return;
                if (t.closest('[data-dirty-ignore]')) return;
                // Skip pure UI controls that are not form state (e.g. table row checkboxes outside tracked forms)
                const form = t.closest('form[data-dirty-track]');
                if (!form) return;
                // Ignore disabled / readonly-only noise where possible
                if (t.disabled) return;
                form.setAttribute('data-dirty', '1');
            }

            document.addEventListener('input', markFromEvent, true);
            document.addEventListener('change', markFromEvent, true);

            // Modal dismiss while dirty (user management, tasks, etc.)
            document.addEventListener('hide.bs.modal', function(e) {
                const modal = e.target;
                if (!modal || !modal.querySelector) return;
                const dirtyForm = modal.querySelector('form[data-dirty-track][data-dirty="1"]');
                if (!dirtyForm) return;
                if (!window.TemperDirtyForms.confirmLeave()) {
                    e.preventDefault();
                    return;
                }
                dirtyForm.removeAttribute('data-dirty');
            });

            // Browser back/forward, refresh, close tab, external links
            window.addEventListener('beforeunload', function(e) {
                if (window.__temperAuthRedirecting) return;
                if (!window.TemperDirtyForms.isDirty()) return;
                e.preventDefault();
                // Chrome / modern browsers require returnValue to be set
                e.returnValue = '';
                return '';
            });
        })();

        /**
         * Move a Bootstrap modal to document.body before show.
         * SPA fragments live under #main-content-col (z-index: 1). Bootstrap appends
         * .modal-backdrop to body at z-index 1050, so a modal that stays inside the
         * column is painted under the backdrop — open but non-interactive (no close,
         * fields, or buttons). Reparenting restores normal stacking.
         */
        window.mountModalOnBody = function(modalEl) {
            if (!modalEl || !modalEl.classList || !modalEl.classList.contains('modal')) {
                return modalEl;
            }
            if (modalEl.parentElement === document.body) {
                return modalEl;
            }
            if (modalEl.id) {
                const esc = (typeof CSS !== 'undefined' && CSS.escape)
                    ? CSS.escape(modalEl.id)
                    : String(modalEl.id).replace(/([^a-zA-Z0-9_-])/g, '\\$1');
                document.querySelectorAll('body > .modal#' + esc).forEach(function(other) {
                    if (other === modalEl) return;
                    try {
                        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                            const inst = bootstrap.Modal.getInstance(other);
                            if (inst) inst.dispose();
                        }
                    } catch (e) { /* ignore */ }
                    other.remove();
                });
            }
            document.body.appendChild(modalEl);
            return modalEl;
        };

        /**
         * Reparent + show a fragment modal with optional Bootstrap Modal options.
         */
        window.showFragmentModal = function(modalEl, options) {
            if (!modalEl || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
                return null;
            }
            window.mountModalOnBody(modalEl);
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl, options || {});
            modal.show();
            return modal;
        };

        /**
         * Reparent every .modal still under #main-content (or a given root) onto body.
         * Called after SPA fragment injection so all page modals are interactive.
         */
        window.mountFragmentModals = function(root) {
            try {
                const scope = root && root.querySelectorAll
                    ? root
                    : document.getElementById('main-content');
                if (!scope) return;
                scope.querySelectorAll('.modal').forEach(function(el) {
                    window.mountModalOnBody(el);
                });
            } catch (e) { /* ignore */ }
        };

        // Shell idle-timeout modal is rendered in index.php under #main-content-col;
        // lift it once so its backdrop/buttons are interactive.
        (function mountShellSessionModal() {
            const el = document.getElementById('sessionTimeoutModal');
            if (el) window.mountModalOnBody(el);
        })();

        /**
         * Remove fragment modals reparented onto document.body (see mountModalOnBody).
         * Keeps the shell sessionTimeoutModal. Prevents duplicate IDs + stuck backdrops after SPA nav.
         */
        window.cleanupFragmentModals = function() {
            try {
                document.querySelectorAll('body > .modal').forEach(function(el) {
                    if (el.id === 'sessionTimeoutModal') return;
                    try {
                        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                            const inst = bootstrap.Modal.getInstance(el);
                            if (inst) inst.dispose();
                        }
                    } catch (e) { /* ignore */ }
                    el.remove();
                });
                document.querySelectorAll('body > .modal-backdrop').forEach(function(el) {
                    el.remove();
                });
                // Only clear modal-open if no shell modal remains open
                const shellOpen = document.querySelector('#sessionTimeoutModal.show');
                if (!shellOpen) {
                    document.body.classList.remove('modal-open');
                    document.body.style.removeProperty('overflow');
                    document.body.style.removeProperty('padding-right');
                }
            } catch (e) { /* ignore */ }
        };

        window.applyMainContent = function(html, options) {
            if (window.__temperAuthRedirecting) return;
            if (typeof window.isAuthExpiredPayload === 'function' && window.isAuthExpiredPayload(html)) {
                window.redirectToLoginExpired();
                return;
            }
            // Drop dirty trackers bound to the outgoing fragment
            if (typeof window.TemperDirtyForms !== 'undefined') {
                window.TemperDirtyForms.clearAll();
            }
            // Tear down body-mounted page modals before replacing #main-content
            if (typeof window.cleanupFragmentModals === 'function') {
                window.cleanupFragmentModals();
            }
            document.getElementById('main-content').innerHTML = html;
            // Lift fragment modals out of #main-content-col stacking context
            if (typeof window.mountFragmentModals === 'function') {
                window.mountFragmentModals(document.getElementById('main-content'));
            }
            const opts = options || {};
            if (opts.skipFlash) {
                ['page-flash', 'ledger-flash'].forEach(function(fid) {
                    const el = document.getElementById(fid);
                    if (el) el.remove();
                });
            } else {
                consumePageFlash('page-flash');
                consumePageFlash('ledger-flash');
            }
        };

        /**
         * Hide/show primary navigation while forced password change is required.
         * Prevents navigation away from the form (and lost form state).
         */
        window.setForcePasswordShell = function(forceMode) {
            const hide = !!forceMode;
            document.body.classList.toggle('temper-force-password-mode', hide);
            const sidebarCol = document.getElementById('temperSidebarCol')
                || (document.querySelector('#appSidebar')
                    ? document.querySelector('#appSidebar').closest('.temper-sidebar-col, .col-md-2')
                    : null);
            const mainCol = document.getElementById('main-content-col');
            const mobileTop = document.querySelector('.mobile-topbar');
            const mobileBottom = document.querySelector('.mobile-bottom-nav');
            if (sidebarCol) sidebarCol.classList.toggle('d-none', hide);
            if (mobileTop) {
                // Keep logout only: hide hamburger when forced
                const burger = mobileTop.querySelector('[data-bs-target="#appSidebar"]');
                if (burger) burger.classList.toggle('d-none', hide);
            }
            if (mobileBottom) mobileBottom.classList.toggle('d-none', hide);
            if (mainCol) {
                mainCol.classList.toggle('col-md-10', !hide);
                mainCol.classList.toggle('col-12', true);
                if (hide) {
                    mainCol.classList.add('col-md-12');
                } else {
                    mainCol.classList.remove('col-md-12');
                }
            }
        };

        // Apply on shell load
        if (window.__temperMustChangePassword) {
            document.addEventListener('DOMContentLoaded', function() {
                window.setForcePasswordShell(true);
            });
            // In case DOMContentLoaded already fired
            if (document.readyState !== 'loading') {
                window.setForcePasswordShell(true);
            }
        }

        /**
         * Desktop sidebar: expand/collapse with persistent preference + hover peek.
         * - Default: expanded
         * - Collapsed: icons only
         * - Hover while collapsed: wait expand delay then show labels (avoids accidental peek)
         * - After mouse leave: wait collapse delay then collapse again
         * - Delays from System Configuration (window.__temperSidebarHover), defaults 0.5s / 2.0s
         * - Click main content (or anywhere outside sidebar) while hover-expanded: collapse immediately
         * - Toggle click: persist expanded/collapsed in localStorage
         */
        (function initCollapsibleSidebar() {
            const STORAGE_KEY = 'temper-sidebar-collapsed';
            const DEFAULT_EXPAND_SEC = 0.5;
            const DEFAULT_COLLAPSE_SEC = 2.0;
            const DESKTOP_MQ = '(min-width: 768px)';
            let enterTimer = null;
            let leaveTimer = null;

            function isDesktop() {
                return window.matchMedia && window.matchMedia(DESKTOP_MQ).matches;
            }

            /** Live-configurable expand delay (ms). Re-reads window.__temperSidebarHover each call. */
            function hoverExpandMs() {
                const cfg = window.__temperSidebarHover || {};
                let sec = parseFloat(cfg.expandSeconds);
                if (isNaN(sec) || sec < 0) sec = DEFAULT_EXPAND_SEC;
                if (sec > 10) sec = 10;
                return Math.round(sec * 1000);
            }

            /** Live-configurable collapse delay (ms). Re-reads window.__temperSidebarHover each call. */
            function hoverCollapseMs() {
                const cfg = window.__temperSidebarHover || {};
                let sec = parseFloat(cfg.collapseSeconds);
                if (isNaN(sec) || sec < 0) sec = DEFAULT_COLLAPSE_SEC;
                if (sec > 30) sec = 30;
                return Math.round(sec * 1000);
            }

            function readStoredCollapsed() {
                try {
                    return localStorage.getItem(STORAGE_KEY) === '1';
                } catch (e) {
                    return false;
                }
            }

            function writeStoredCollapsed(collapsed) {
                try {
                    localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0');
                } catch (e) { /* private mode / blocked */ }
            }

            function updateToggleUi(collapsed) {
                const btn = document.getElementById('sidebarToggle');
                const icon = document.getElementById('sidebarToggleIcon');
                if (!btn) return;
                if (collapsed) {
                    if (icon) icon.className = 'bi bi-chevron-double-right';
                    btn.setAttribute('title', 'Expand sidebar');
                    btn.setAttribute('aria-label', 'Expand sidebar');
                    btn.setAttribute('aria-expanded', 'false');
                } else {
                    if (icon) icon.className = 'bi bi-chevron-double-left';
                    btn.setAttribute('title', 'Collapse sidebar');
                    btn.setAttribute('aria-label', 'Collapse sidebar');
                    btn.setAttribute('aria-expanded', 'true');
                }
            }

            function clearEnterTimer() {
                if (enterTimer) {
                    clearTimeout(enterTimer);
                    enterTimer = null;
                }
            }

            function clearLeaveTimer() {
                if (leaveTimer) {
                    clearTimeout(leaveTimer);
                    leaveTimer = null;
                }
            }

            function clearHoverTimers() {
                clearEnterTimer();
                clearLeaveTimer();
            }

            /** End temporary hover peek immediately (does not change persistent collapse). */
            function endHoverExpand() {
                clearHoverTimers();
                document.body.classList.remove('sidebar-hover-expand');
            }

            function isHoverExpanded() {
                return document.body.classList.contains('sidebar-collapsed')
                    && document.body.classList.contains('sidebar-hover-expand');
            }

            function setCollapsed(collapsed, persist) {
                document.body.classList.toggle('sidebar-collapsed', !!collapsed);
                if (!collapsed) {
                    endHoverExpand();
                }
                updateToggleUi(!!collapsed);
                if (persist) writeStoredCollapsed(!!collapsed);
            }

            function scheduleHoverExpand() {
                clearEnterTimer();
                clearLeaveTimer();
                // Already peaking (e.g. re-entered during collapse delay)
                if (document.body.classList.contains('sidebar-hover-expand')) return;
                const delay = hoverExpandMs();
                if (delay <= 0) {
                    if (!isDesktop()) return;
                    if (!document.body.classList.contains('sidebar-collapsed')) return;
                    document.body.classList.add('sidebar-hover-expand');
                    return;
                }
                enterTimer = setTimeout(function() {
                    enterTimer = null;
                    if (!isDesktop()) return;
                    if (!document.body.classList.contains('sidebar-collapsed')) return;
                    document.body.classList.add('sidebar-hover-expand');
                }, delay);
            }

            function scheduleHoverCollapse() {
                clearEnterTimer(); // cancel pending open if pointer left early
                clearLeaveTimer();
                // Nothing to collapse if never expanded
                if (!document.body.classList.contains('sidebar-hover-expand')) return;
                const delay = hoverCollapseMs();
                if (delay <= 0) {
                    document.body.classList.remove('sidebar-hover-expand');
                    return;
                }
                leaveTimer = setTimeout(function() {
                    document.body.classList.remove('sidebar-hover-expand');
                    leaveTimer = null;
                }, delay);
            }

            function applyFromStorage() {
                if (!isDesktop()) {
                    // Mobile offcanvas always full labels; strip desktop-only classes
                    document.body.classList.remove('sidebar-collapsed', 'sidebar-hover-expand');
                    updateToggleUi(false);
                    return;
                }
                setCollapsed(readStoredCollapsed(), false);
            }

            function wire() {
                const sidebar = document.getElementById('appSidebar');
                const toggle = document.getElementById('sidebarToggle');
                if (!sidebar) return;

                applyFromStorage();

                if (toggle && !toggle.dataset.wired) {
                    toggle.dataset.wired = '1';
                    toggle.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        if (!isDesktop()) return;
                        clearHoverTimers();
                        const next = !document.body.classList.contains('sidebar-collapsed');
                        setCollapsed(next, true);
                    });
                }

                if (!sidebar.dataset.hoverWired) {
                    sidebar.dataset.hoverWired = '1';
                    sidebar.addEventListener('mouseenter', function() {
                        if (!isDesktop()) return;
                        if (!document.body.classList.contains('sidebar-collapsed')) return;
                        scheduleHoverExpand();
                    });
                    sidebar.addEventListener('mouseleave', function() {
                        if (!isDesktop()) return;
                        if (!document.body.classList.contains('sidebar-collapsed')) return;
                        scheduleHoverCollapse();
                    });
                    // Keyboard: expand immediately (no hover delay)
                    sidebar.addEventListener('focusin', function() {
                        if (!isDesktop()) return;
                        if (!document.body.classList.contains('sidebar-collapsed')) return;
                        clearHoverTimers();
                        document.body.classList.add('sidebar-hover-expand');
                    });
                    sidebar.addEventListener('focusout', function(e) {
                        if (!isDesktop()) return;
                        if (!document.body.classList.contains('sidebar-collapsed')) return;
                        // If focus moved to another node still inside sidebar, ignore
                        if (e.relatedTarget && sidebar.contains(e.relatedTarget)) return;
                        scheduleHoverCollapse();
                    });
                }

                // Click-off: while hover-expanded, any click outside the sidebar
                // (main content, chrome, etc.) collapses the peek immediately.
                // Also cancel a pending expand if the user clicks away during the delay.
                if (!window.__temperSidebarClickOffWired) {
                    window.__temperSidebarClickOffWired = true;
                    document.addEventListener('pointerdown', function(e) {
                        if (!isDesktop()) return;
                        if (!document.body.classList.contains('sidebar-collapsed')) return;
                        const t = e.target;
                        if (!t || !t.closest) return;
                        // Stay open / keep pending when interacting with the sidebar rail / overlay
                        if (t.closest('#appSidebar') || t.closest('#temperSidebarCol')) return;
                        endHoverExpand();
                    }, true);
                }

                // Respond to viewport breakpoint changes
                if (window.matchMedia && !window.__temperSidebarMqWired) {
                    window.__temperSidebarMqWired = true;
                    const mq = window.matchMedia(DESKTOP_MQ);
                    const onChange = function() {
                        clearHoverTimers();
                        applyFromStorage();
                    };
                    if (typeof mq.addEventListener === 'function') {
                        mq.addEventListener('change', onChange);
                    } else if (typeof mq.addListener === 'function') {
                        mq.addListener(onChange);
                    }
                }
            }

            // jQuery-friendly public API (optional)
            window.TemperSidebar = {
                collapse: function() { setCollapsed(true, true); },
                expand: function() { setCollapsed(false, true); },
                toggle: function() {
                    setCollapsed(!document.body.classList.contains('sidebar-collapsed'), true);
                },
                isCollapsed: function() {
                    return document.body.classList.contains('sidebar-collapsed');
                },
                endHoverExpand: endHoverExpand
            };

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', wire);
            } else {
                wire();
            }
        })();
    })();
    </script>
</body>
</html>
