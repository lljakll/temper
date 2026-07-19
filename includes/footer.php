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

        window.applyMainContent = function(html) {
            if (window.__temperAuthRedirecting) return;
            if (typeof window.isAuthExpiredPayload === 'function' && window.isAuthExpiredPayload(html)) {
                window.redirectToLoginExpired();
                return;
            }
            document.getElementById('main-content').innerHTML = html;
            consumePageFlash('page-flash');
            consumePageFlash('ledger-flash');
        };

        /**
         * Hide/show primary navigation while forced password change is required.
         * Prevents navigation away from the form (and lost form state).
         */
        window.setForcePasswordShell = function(forceMode) {
            const hide = !!forceMode;
            document.body.classList.toggle('temper-force-password-mode', hide);
            const sidebarCol = document.querySelector('#appSidebar')
                ? document.querySelector('#appSidebar').closest('.col-md-2')
                : null;
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
    })();
    </script>
</body>
</html>
