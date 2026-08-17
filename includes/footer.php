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

        /**
         * Ensure the toast host lives on document.body (not under #main-content-col).
         * Fragments/modals use body stacking (z-index ~1055); a toast trapped inside
         * #main-content-col (z-index: 1) is painted under open modals even if its own
         * z-index is higher within that stacking context.
         */
        window.ensureAppToastContainer = function() {
            let container = document.getElementById('appToastContainer');
            if (!container) {
                container = document.createElement('div');
                container.id = 'appToastContainer';
                container.className = 'toast-container position-fixed top-0 start-50 translate-middle-x p-3';
                container.setAttribute('aria-live', 'polite');
                container.setAttribute('aria-atomic', 'true');
                document.body.appendChild(container);
            } else if (container.parentElement !== document.body) {
                document.body.appendChild(container);
            }
            return container;
        };

        // Lift toast host out of #main-content-col as soon as the shell is ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                window.ensureAppToastContainer();
            });
        } else {
            window.ensureAppToastContainer();
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
            const container = typeof window.ensureAppToastContainer === 'function'
                ? window.ensureAppToastContainer()
                : document.getElementById('appToastContainer');
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
         * Lookup maintenance tables: live text filter + clickable column sort.
         *
         * Usage:
         *   TemperLookupTable.enhance({
         *     table: document.querySelector('table'),
         *     filterInput: document.getElementById('myFilter'),
         *     emptyMessage: 'No matching rows.'
         *   });
         *
         * Data rows must have data-id. Filter matches all visible cell text
         * (case-insensitive). Sort toggles asc → desc on the same column.
         * Filter and sort combine: visibility is filter; DOM order is sort.
         */
        (function initTemperLookupTable() {
            function cellText(td) {
                return (td && td.textContent ? td.textContent : '').replace(/\s+/g, ' ').trim();
            }

            function rowSearchText(tr) {
                const parts = [];
                const cells = tr.cells || [];
                for (let i = 0; i < cells.length; i++) {
                    parts.push(cellText(cells[i]));
                }
                return parts.join(' ').toLowerCase();
            }

            function isEmptySortValue(s) {
                return s === '' || s === '—' || s === '–' || s === '-';
            }

            function compareValues(a, b) {
                if (isEmptySortValue(a) && isEmptySortValue(b)) return 0;
                if (isEmptySortValue(a)) return 1;
                if (isEmptySortValue(b)) return -1;
                return String(a).localeCompare(String(b), undefined, {
                    numeric: true,
                    sensitivity: 'base'
                });
            }

            function resolveEl(ref) {
                if (!ref) return null;
                if (typeof ref === 'string') return document.querySelector(ref);
                return ref;
            }

            window.TemperLookupTable = {
                enhance: function(opts) {
                    opts = opts || {};
                    const table = resolveEl(opts.table);
                    if (!table || !table.tBodies || !table.tBodies[0]) return null;
                    const tbody = table.tBodies[0];
                    const filterInput = resolveEl(opts.filterInput);
                    const emptyMessage = opts.emptyMessage || 'No matching rows.';
                    const ths = table.querySelectorAll('thead th');
                    let sortCol = null;
                    let sortDir = 1; // 1 = ascending, -1 = descending

                    function dataRows() {
                        return Array.from(tbody.querySelectorAll('tr[data-id]'));
                    }

                    function ensureNoMatchRow() {
                        let el = tbody.querySelector('tr.temper-lookup-no-match');
                        if (!el) {
                            el = document.createElement('tr');
                            el.className = 'temper-lookup-no-match';
                            const td = document.createElement('td');
                            td.colSpan = Math.max(ths.length, 1);
                            td.className = 'text-center text-muted py-3';
                            td.textContent = emptyMessage;
                            el.appendChild(td);
                            tbody.appendChild(el);
                        } else {
                            const td = el.querySelector('td');
                            if (td) {
                                td.colSpan = Math.max(ths.length, 1);
                                td.textContent = emptyMessage;
                            }
                        }
                        return el;
                    }

                    function updateHeaderIndicators() {
                        ths.forEach(function(th, idx) {
                            const icon = th.querySelector('.temper-sort-icon');
                            if (sortCol === idx) {
                                th.setAttribute('aria-sort', sortDir > 0 ? 'ascending' : 'descending');
                                if (icon) {
                                    icon.className = 'bi temper-sort-icon ' +
                                        (sortDir > 0 ? 'bi-caret-up-fill' : 'bi-caret-down-fill');
                                }
                            } else {
                                th.setAttribute('aria-sort', 'none');
                                if (icon) {
                                    icon.className = 'bi bi-arrow-down-up temper-sort-icon';
                                }
                            }
                        });
                    }

                    function applyFilter() {
                        const q = filterInput
                            ? String(filterInput.value || '').trim().toLowerCase()
                            : '';
                        const rows = dataRows();
                        let visible = 0;
                        rows.forEach(function(tr) {
                            const match = !q || rowSearchText(tr).indexOf(q) !== -1;
                            tr.style.display = match ? '' : 'none';
                            if (match) visible++;
                        });
                        // Hide static server empty-state rows when data rows exist
                        tbody.querySelectorAll('tr:not([data-id]):not(.temper-lookup-no-match)').forEach(function(tr) {
                            if (rows.length > 0) {
                                tr.style.display = 'none';
                            }
                        });
                        const noMatch = ensureNoMatchRow();
                        noMatch.style.display = (rows.length > 0 && visible === 0) ? '' : 'none';
                    }

                    function applySort() {
                        if (sortCol === null) return;
                        const rows = dataRows();
                        rows.sort(function(ra, rb) {
                            const ta = cellText(ra.cells[sortCol]);
                            const tb = cellText(rb.cells[sortCol]);
                            return compareValues(ta, tb) * sortDir;
                        });
                        rows.forEach(function(r) {
                            tbody.appendChild(r);
                        });
                        const nm = tbody.querySelector('tr.temper-lookup-no-match');
                        if (nm) tbody.appendChild(nm);
                    }

                    function onSortColumn(idx) {
                        if (sortCol === idx) {
                            sortDir = -sortDir;
                        } else {
                            sortCol = idx;
                            sortDir = 1;
                        }
                        applySort();
                        updateHeaderIndicators();
                    }

                    ths.forEach(function(th, idx) {
                        th.classList.add('temper-sortable');
                        th.setAttribute('role', 'columnheader');
                        th.setAttribute('tabindex', '0');
                        th.setAttribute('aria-sort', 'none');
                        if (!th.querySelector('.temper-sort-icon')) {
                            th.appendChild(document.createTextNode(' '));
                            const icon = document.createElement('i');
                            icon.className = 'bi bi-arrow-down-up temper-sort-icon';
                            icon.setAttribute('aria-hidden', 'true');
                            th.appendChild(icon);
                        }
                        th.addEventListener('click', function() {
                            onSortColumn(idx);
                        });
                        th.addEventListener('keydown', function(e) {
                            if (e.key === 'Enter' || e.key === ' ') {
                                e.preventDefault();
                                onSortColumn(idx);
                            }
                        });
                    });

                    if (filterInput) {
                        filterInput.setAttribute('autocomplete', 'off');
                        filterInput.setAttribute('data-dirty-ignore', '1');
                        filterInput.addEventListener('input', function() {
                            applyFilter();
                        });
                    }

                    applyFilter();

                    return {
                        reapply: function() {
                            applyFilter();
                            if (sortCol !== null) applySort();
                        },
                        clearSort: function() {
                            sortCol = null;
                            sortDir = 1;
                            updateHeaderIndicators();
                        },
                        focusFilter: function() {
                            if (!filterInput) return;
                            filterInput.focus();
                            if (typeof filterInput.select === 'function') {
                                try { filterInput.select(); } catch (e) { /* ignore */ }
                            }
                        }
                    };
                }
            };

            /**
             * Lookup page shell: compact toolbar wiring, table-only font size,
             * and leader-key hotkeys (; then command). Extends TemperLookupTable.
             *
             *   TemperLookupPage.init({
             *     root, table, filterInput, emptyMessage,
             *     actions: { add, edit, delete, archive, toggleArchived } // elements
             *   });
             *
             * Hotkey leader is ";" (when not typing in a field). Then:
             *   f / = filter, a = Add, e = Edit, d = Delete, r = Archive,
             *   s = Show/Hide archived, + / - = table font, ? = help list.
             */
            const FONT_STEPS = [0.75, 0.8125, 0.875, 0.9375, 1, 1.125, 1.25];
            const FONT_DEFAULT_IDX = 2; // 0.875rem
            const FONT_STORAGE_KEY = 'temper-lookup-table-font-idx';
            const LEADER_KEY = ';';
            const LEADER_TIMEOUT_MS = 2500;
            let activeLookupPage = null;

            function resolveEl(ref) {
                if (!ref) return null;
                if (typeof ref === 'string') return document.querySelector(ref);
                return ref;
            }

            function isTypingTarget(el) {
                if (!el || !el.tagName) return false;
                const tag = el.tagName.toLowerCase();
                if (tag === 'input' || tag === 'textarea' || tag === 'select') return true;
                if (el.isContentEditable) return true;
                return !!(el.closest && el.closest('[contenteditable="true"]'));
            }

            function isModalOpen() {
                return !!(document.querySelector('.modal.show'));
            }

            function clickIfEnabled(btn) {
                if (!btn || btn.disabled || btn.getAttribute('aria-disabled') === 'true') {
                    if (typeof showToast === 'function') {
                        showToast('That action is not available right now.', 'warning', 2200);
                    }
                    return false;
                }
                btn.click();
                return true;
            }

            function readFontIdx() {
                try {
                    const raw = localStorage.getItem(FONT_STORAGE_KEY);
                    if (raw === null || raw === '') return FONT_DEFAULT_IDX;
                    const n = parseInt(raw, 10);
                    if (isNaN(n) || n < 0 || n >= FONT_STEPS.length) return FONT_DEFAULT_IDX;
                    return n;
                } catch (e) {
                    return FONT_DEFAULT_IDX;
                }
            }

            function writeFontIdx(idx) {
                try {
                    localStorage.setItem(FONT_STORAGE_KEY, String(idx));
                } catch (e) { /* private mode */ }
            }

            function buildHelpHtml() {
                return (
                    '<p class="mb-2 small text-muted">Press <kbd>;</kbd> then a command ' +
                    '(when not typing in a field):</p>' +
                    '<ul class="temper-lookup-hotkey-help-list">' +
                    '<li><kbd>;</kbd> <kbd>f</kbd> — Focus filter</li>' +
                    '<li><kbd>;</kbd> <kbd>a</kbd> — Add</li>' +
                    '<li><kbd>;</kbd> <kbd>e</kbd> — Edit selected</li>' +
                    '<li><kbd>;</kbd> <kbd>d</kbd> — Delete selected</li>' +
                    '<li><kbd>;</kbd> <kbd>r</kbd> — Archive / Unarchive</li>' +
                    '<li><kbd>;</kbd> <kbd>s</kbd> — Show / Hide archived</li>' +
                    '<li><kbd>;</kbd> <kbd>+</kbd> / <kbd>-</kbd> — Table text size</li>' +
                    '<li><kbd>;</kbd> <kbd>?</kbd> or <kbd>h</kbd> — This help</li>' +
                    '<li><kbd>Esc</kbd> — Cancel hotkey mode</li>' +
                    '</ul>'
                );
            }

            window.TemperLookupPage = {
                LEADER_KEY: LEADER_KEY,
                init: function(opts) {
                    opts = opts || {};
                    // Tear down previous page instance (SPA fragment swap)
                    if (activeLookupPage && typeof activeLookupPage.dispose === 'function') {
                        activeLookupPage.dispose();
                        activeLookupPage = null;
                    }

                    const root = resolveEl(opts.root) || document.querySelector('.temper-lookup-page');
                    const table = resolveEl(opts.table);
                    const filterInput = resolveEl(opts.filterInput);
                    const actions = opts.actions || {};
                    const addBtn = resolveEl(actions.add);
                    const editBtn = resolveEl(actions.edit);
                    const deleteBtn = resolveEl(actions.delete);
                    const archiveBtn = resolveEl(actions.archive);
                    const toggleArchivedBtn = resolveEl(actions.toggleArchived);

                    let tableApi = null;
                    if (typeof window.TemperLookupTable !== 'undefined' && table) {
                        tableApi = window.TemperLookupTable.enhance({
                            table: table,
                            filterInput: filterInput,
                            emptyMessage: opts.emptyMessage || 'No matching rows.'
                        });
                    }

                    // ── Table-only font size ───────────────────────────────
                    let fontIdx = readFontIdx();

                    function applyFontSize() {
                        const rem = FONT_STEPS[fontIdx] || FONT_STEPS[FONT_DEFAULT_IDX];
                        if (root) {
                            root.style.setProperty('--temper-lookup-font-size', rem + 'rem');
                        }
                        if (table) {
                            table.style.setProperty('--temper-lookup-font-size', rem + 'rem');
                            table.style.fontSize = rem + 'rem';
                        }
                        writeFontIdx(fontIdx);
                        const dec = root ? root.querySelector('[data-lookup-font-delta="-1"]') : null;
                        const inc = root ? root.querySelector('[data-lookup-font-delta="1"]') : null;
                        if (dec) dec.disabled = fontIdx <= 0;
                        if (inc) inc.disabled = fontIdx >= FONT_STEPS.length - 1;
                    }

                    function bumpFont(delta) {
                        const next = Math.max(0, Math.min(FONT_STEPS.length - 1, fontIdx + delta));
                        if (next === fontIdx) return;
                        fontIdx = next;
                        applyFontSize();
                    }

                    applyFontSize();

                    if (root) {
                        root.querySelectorAll('[data-lookup-font-delta]').forEach(function(btn) {
                            btn.addEventListener('click', function() {
                                const d = parseInt(btn.getAttribute('data-lookup-font-delta'), 10);
                                if (!isNaN(d)) bumpFont(d);
                            });
                        });
                    }

                    // ── Hotkey help popover ────────────────────────────────
                    let helpPopover = null;
                    const helpBtn = root
                        ? root.querySelector('[data-lookup-hotkey-help]')
                        : null;

                    function disposeHelpPopover() {
                        if (helpPopover) {
                            try { helpPopover.dispose(); } catch (e) { /* ignore */ }
                            helpPopover = null;
                        }
                    }

                    function showHelp() {
                        if (!helpBtn || typeof bootstrap === 'undefined' || !bootstrap.Popover) {
                            if (typeof showToast === 'function') {
                                showToast('Shortcuts: ; f filter · ; a add · ; e edit · ; ? help', 'info', 5000);
                            }
                            return;
                        }
                        disposeHelpPopover();
                        helpPopover = bootstrap.Popover.getOrCreateInstance(helpBtn, {
                            title: 'Lookup keyboard shortcuts',
                            content: buildHelpHtml(),
                            html: true,
                            trigger: 'manual',
                            placement: 'bottom',
                            container: 'body',
                            customClass: 'temper-lookup-hotkey-popover',
                            sanitize: false
                        });
                        helpPopover.show();
                        // Auto-hide after a few seconds or on outside click
                        setTimeout(function() {
                            try { if (helpPopover) helpPopover.hide(); } catch (e) { /* ignore */ }
                        }, 8000);
                    }

                    if (helpBtn) {
                        helpBtn.addEventListener('click', function(e) {
                            e.preventDefault();
                            if (helpPopover && helpBtn.getAttribute('aria-describedby')) {
                                try { helpPopover.hide(); } catch (err) { /* ignore */ }
                            } else {
                                showHelp();
                            }
                        });
                    }

                    // ── Leader-key hotkeys ─────────────────────────────────
                    let leaderActive = false;
                    let leaderTimer = null;
                    let bannerEl = null;

                    function hideBanner() {
                        if (bannerEl && bannerEl.parentNode) {
                            bannerEl.parentNode.removeChild(bannerEl);
                        }
                        bannerEl = null;
                    }

                    function showBanner() {
                        hideBanner();
                        bannerEl = document.createElement('div');
                        bannerEl.className = 'temper-hotkey-banner';
                        bannerEl.setAttribute('role', 'status');
                        bannerEl.innerHTML =
                            'Hotkey mode — press a command (<kbd>f</kbd> filter, <kbd>a</kbd> add, <kbd>?</kbd> help) or <kbd>Esc</kbd>';
                        document.body.appendChild(bannerEl);
                    }

                    function endLeaderMode() {
                        leaderActive = false;
                        if (leaderTimer) {
                            clearTimeout(leaderTimer);
                            leaderTimer = null;
                        }
                        hideBanner();
                    }

                    function startLeaderMode() {
                        leaderActive = true;
                        if (leaderTimer) clearTimeout(leaderTimer);
                        showBanner();
                        leaderTimer = setTimeout(endLeaderMode, LEADER_TIMEOUT_MS);
                    }

                    function runCommand(key) {
                        // Use e.key as provided: '?' stays '?', letters lowercased
                        if (key === '?' || key === 'h') {
                            showHelp();
                            return true;
                        }
                        const k = String(key || '').toLowerCase();
                        if (k === 'f' || k === '/') {
                            if (tableApi && tableApi.focusFilter) tableApi.focusFilter();
                            else if (filterInput) filterInput.focus();
                            return true;
                        }
                        if (k === 'a') {
                            clickIfEnabled(addBtn);
                            return true;
                        }
                        if (k === 'e') {
                            clickIfEnabled(editBtn);
                            return true;
                        }
                        if (k === 'd') {
                            clickIfEnabled(deleteBtn);
                            return true;
                        }
                        if (k === 'r') {
                            clickIfEnabled(archiveBtn);
                            return true;
                        }
                        if (k === 's') {
                            clickIfEnabled(toggleArchivedBtn);
                            return true;
                        }
                        if (k === '+' || k === '=' || key === '+') {
                            bumpFont(1);
                            return true;
                        }
                        if (k === '-' || k === '_' || key === '-') {
                            bumpFont(-1);
                            return true;
                        }
                        return false;
                    }

                    function onKeyDown(e) {
                        if (!root || !root.isConnected) {
                            dispose();
                            return;
                        }
                        // Never steal keys while typing or when a modal is open
                        if (isModalOpen()) {
                            if (leaderActive) endLeaderMode();
                            return;
                        }
                        if (isTypingTarget(e.target)) {
                            if (leaderActive) endLeaderMode();
                            return;
                        }
                        if (e.ctrlKey || e.metaKey || e.altKey) return;

                        if (leaderActive) {
                            if (e.key === 'Escape') {
                                e.preventDefault();
                                endLeaderMode();
                                return;
                            }
                            // Consume printable command keys
                            const raw = e.key;
                            if (raw === 'Shift' || raw === 'Control' || raw === 'Alt' || raw === 'Meta') return;
                            e.preventDefault();
                            endLeaderMode();
                            if (raw === '?') {
                                showHelp();
                                return;
                            }
                            runCommand(raw);
                            return;
                        }

                        // Enter leader mode with ";"
                        if (e.key === LEADER_KEY) {
                            e.preventDefault();
                            startLeaderMode();
                        }
                    }

                    function dispose() {
                        endLeaderMode();
                        disposeHelpPopover();
                        document.removeEventListener('keydown', onKeyDown, true);
                        if (activeLookupPage && activeLookupPage._id === instanceId) {
                            activeLookupPage = null;
                        }
                    }

                    const instanceId = 'lookup-' + Date.now() + '-' + Math.random().toString(36).slice(2, 7);
                    document.addEventListener('keydown', onKeyDown, true);

                    const api = {
                        _id: instanceId,
                        tableApi: tableApi,
                        bumpFont: bumpFont,
                        showHelp: showHelp,
                        dispose: dispose
                    };
                    activeLookupPage = api;
                    return api;
                },
                disposeActive: function() {
                    if (activeLookupPage && typeof activeLookupPage.dispose === 'function') {
                        activeLookupPage.dispose();
                    }
                    activeLookupPage = null;
                }
            };

            // SPA navigates away: key handler self-disposes when root is disconnected;
            // re-init of another lookup page also disposes the previous instance.
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
         * Form-bearing modals auto-focus the first data field via TemperModalFocus.
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
         * Global form-modal autofocus: first logical data-entry field on open,
         * and re-assert focus if SPA/AJAX/DOM updates steal it while the modal is open.
         *
         * Opt-out: data-no-autofocus on the .modal root, or data-no-autofocus on a field.
         * Opt-in target: data-autofocus on a specific field inside the modal.
         * No visual/layout changes.
         */
        window.TemperModalFocus = (function() {
            const FIELD_SEL = [
                'input:not([type="hidden"]):not([type="button"]):not([type="submit"]):not([type="reset"]):not([type="image"]):not([type="file"])',
                'select',
                'textarea',
                '[contenteditable="true"]'
            ].join(',');

            let activeModal = null;
            let preferredField = null;
            let lastInModal = null;
            let guardTimers = [];
            let focusGuardWired = false;

            function isShown(modal) {
                return !!(modal && modal.classList && modal.classList.contains('show'));
            }

            function isFieldVisible(el) {
                if (!el || el.disabled) return false;
                if (el.getAttribute('aria-hidden') === 'true') return false;
                if (el.closest('[aria-hidden="true"]')) return false;
                const style = window.getComputedStyle(el);
                if (style.display === 'none' || style.visibility === 'hidden') return false;
                // zero-size hidden groups (e.g. display:none ancestors already handled by display)
                if (el.offsetParent === null && style.position !== 'fixed' && style.position !== 'sticky') {
                    // Still allow if inside a shown Bootstrap modal (fixed positioning chain)
                    if (!el.closest('.modal.show')) return false;
                }
                return true;
            }

            function findFirstFormField(modal) {
                if (!modal || !modal.querySelector) return null;
                if (modal.hasAttribute('data-no-autofocus')) return null;

                const explicit = modal.querySelector('[data-autofocus]');
                if (explicit && isFieldVisible(explicit) && !explicit.disabled) {
                    return explicit;
                }

                // Prefer a form body; fall back to modal-body / whole modal
                const roots = [];
                const form = modal.querySelector('form');
                if (form) roots.push(form);
                const body = modal.querySelector('.modal-body');
                if (body && body !== form) roots.push(body);
                roots.push(modal);

                const seen = new Set();
                for (let r = 0; r < roots.length; r++) {
                    const list = roots[r].querySelectorAll(FIELD_SEL);
                    for (let i = 0; i < list.length; i++) {
                        const el = list[i];
                        if (seen.has(el)) continue;
                        seen.add(el);
                        if (el.hasAttribute('data-no-autofocus')) continue;
                        if (el.closest('[data-no-autofocus]')) continue;
                        // Skip chrome controls
                        if (el.closest('.modal-header') && el.matches('button, .btn-close')) continue;
                        if (el.closest('.modal-footer')) continue;
                        if (el.readOnly && el.tagName === 'INPUT' && el.type !== 'checkbox' && el.type !== 'radio') {
                            // Prefer first writable field for data entry
                            continue;
                        }
                        if (!isFieldVisible(el)) continue;
                        return el;
                    }
                }
                return null;
            }

            function safeFocus(el) {
                if (!el || typeof el.focus !== 'function') return false;
                try {
                    if (document.activeElement === el) return true;
                    el.focus({ preventScroll: true });
                    return document.activeElement === el;
                } catch (err) {
                    try {
                        el.focus();
                        return document.activeElement === el;
                    } catch (err2) {
                        return false;
                    }
                }
            }

            function clearGuardTimers() {
                guardTimers.forEach(function(id) { clearTimeout(id); });
                guardTimers = [];
            }

            function isDataEntryField(el) {
                return !!(el && el.matches && el.matches(FIELD_SEL) && isFieldVisible(el));
            }

            /**
             * @param {'initial'|'escape'} mode
             *   initial — force a data field (override Bootstrap close-button focus)
             *   escape  — only pull focus back if it left the modal entirely
             */
            function restoreFocus(mode) {
                if (!isShown(activeModal)) return;
                const active = document.activeElement;
                const inside = !!(active && activeModal.contains(active));

                if (mode === 'escape') {
                    if (inside) {
                        if (isDataEntryField(active) || (active.matches && active.matches('button, a, [href], [tabindex]:not([tabindex="-1"])'))) {
                            lastInModal = active;
                        }
                        return;
                    }
                } else {
                    // initial: accept only a real data-entry field as success
                    if (inside && isDataEntryField(active)) {
                        lastInModal = active;
                        return;
                    }
                }

                const target = (mode === 'escape' && lastInModal && activeModal.contains(lastInModal) && isFieldVisible(lastInModal))
                    ? lastInModal
                    : (preferredField && activeModal.contains(preferredField) && isFieldVisible(preferredField)
                        ? preferredField
                        : findFirstFormField(activeModal));
                if (target) {
                    safeFocus(target);
                    lastInModal = target;
                }
            }

            function armFocusGuard(modal, field) {
                clearGuardTimers();
                activeModal = modal;
                preferredField = field;
                lastInModal = field;
                // Early ticks: override Bootstrap focusing .btn-close / .modal
                [0, 16, 50, 100, 200, 350].forEach(function(ms) {
                    guardTimers.push(setTimeout(function() {
                        if (activeModal !== modal || !isShown(modal)) return;
                        restoreFocus('initial');
                    }, ms));
                });
                // Later ticks: only recover from external focus steal (SPA/AJAX)
                [500, 800, 1200].forEach(function(ms) {
                    guardTimers.push(setTimeout(function() {
                        if (activeModal !== modal || !isShown(modal)) return;
                        restoreFocus('escape');
                    }, ms));
                });
            }

            function release(modal) {
                if (modal && activeModal && modal !== activeModal) return;
                clearGuardTimers();
                activeModal = null;
                preferredField = null;
                lastInModal = null;
            }

            function onModalShown(e) {
                const modal = e.target;
                if (!modal || !modal.classList || !modal.classList.contains('modal')) return;
                const field = findFirstFormField(modal);
                if (!field) {
                    // No data-entry fields (confirm-only / session warning) — leave Bootstrap default
                    return;
                }
                safeFocus(field);
                armFocusGuard(modal, field);
            }

            function onModalHidden(e) {
                // Only release after the modal has fully closed. Do not use hide.bs.modal:
                // dirty-form handlers may preventDefault on hide, and the modal stays open.
                if (e.target === activeModal || (activeModal && !isShown(activeModal))) {
                    release(e.target === activeModal ? e.target : activeModal);
                }
            }

            function onFocusIn(e) {
                if (!isShown(activeModal)) return;
                const t = e.target;
                if (t && activeModal.contains(t)) {
                    // Remember last intentional control inside the modal
                    if (isDataEntryField(t) || (t.matches && t.matches('button, a, [href], [tabindex]:not([tabindex="-1"])'))) {
                        lastInModal = t;
                    }
                    return;
                }
                // Focus escaped the open form modal (AJAX/DOM/toast/etc.) — pull it back
                // Use rAF so we run after the thief's focus settles
                requestAnimationFrame(function() {
                    if (!isShown(activeModal)) return;
                    const cur = document.activeElement;
                    if (cur && activeModal.contains(cur)) {
                        if (isDataEntryField(cur) || (cur.matches && cur.matches('button, a, [href], [tabindex]:not([tabindex="-1"])'))) {
                            lastInModal = cur;
                        }
                        return;
                    }
                    restoreFocus('escape');
                });
            }

            function wire() {
                if (focusGuardWired) return;
                focusGuardWired = true;
                document.addEventListener('shown.bs.modal', onModalShown);
                document.addEventListener('hidden.bs.modal', onModalHidden);
                document.addEventListener('focusin', onFocusIn, true);
            }

            return {
                wire: wire,
                findFirstFormField: findFirstFormField,
                focusFirstField: function(modal) {
                    const field = findFirstFormField(modal);
                    if (field) {
                        safeFocus(field);
                        armFocusGuard(modal, field);
                    }
                    return field;
                },
                restore: restoreFocus,
                release: release
            };
        })();
        window.TemperModalFocus.wire();

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
                    // Never remove or dispose the shell idle-timeout warning modal
                    if (el.id === 'sessionTimeoutModal') return;
                    try {
                        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                            const inst = bootstrap.Modal.getInstance(el);
                            if (inst) inst.dispose();
                        }
                    } catch (e) { /* ignore */ }
                    el.remove();
                });
                // Drop page modal backdrops only; keep the session-timeout overlay if open
                document.querySelectorAll('body > .modal-backdrop').forEach(function(el) {
                    if (el.classList.contains('session-timeout-backdrop')) return;
                    el.remove();
                });
                // Keep body modal-open while the idle warning (or its backdrop) is still shown
                const shellOpen = document.querySelector('#sessionTimeoutModal.show');
                if (!shellOpen) {
                    document.body.classList.remove('modal-open');
                    document.body.style.removeProperty('overflow');
                    document.body.style.removeProperty('padding-right');
                } else {
                    document.body.classList.add('modal-open');
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
            // Dispose lookup / ledger page hotkey listeners / banner before fragment swap
            if (window.TemperLookupPage && typeof window.TemperLookupPage.disposeActive === 'function') {
                window.TemperLookupPage.disposeActive();
            }
            if (window.TemperLedgerPage && typeof window.TemperLedgerPage.disposeActive === 'function') {
                window.TemperLedgerPage.disposeActive();
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
