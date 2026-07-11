<?php
// Common Setup & Security
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

// Central session check (shell footer)
requireLogin();

// Get current user info
$user = getCurrentUser();

// Database connection (only create if not already set)
if (!isset($db)) {
    $db = getDbConnection();
}

$footerDb = getDbConnection();
$tellerLimitedFooter = $user ? isTellerLimitedUser($footerDb, (int)$user['id']) : false;
$footerDb->close();
?>

</div><!-- /#main-content-col -->
</div><!-- /.row -->
</div><!-- /.container-fluid -->

<!-- Mobile bottom navigation -->
<nav class="mobile-bottom-nav d-md-none" aria-label="Primary">
<?php if (!$tellerLimitedFooter): ?>
    <a href="javascript:void(0)" onclick="loadPage('dashboard')" data-nav-page="dashboard">
        <i class="bi bi-speedometer2"></i>
        <span>Home</span>
    </a>
    <a href="javascript:void(0)" onclick="loadPage('ledger')" data-nav-page="ledger">
        <i class="bi bi-currency-dollar"></i>
        <span>Ledger</span>
    </a>
    <a href="javascript:void(0)" onclick="loadPage('workflows')" data-nav-page="workflows">
        <i class="bi bi-diagram-3"></i>
        <span>Workflows</span>
    </a>
    <a href="javascript:void(0)" onclick="loadPage('reports')" data-nav-page="reports">
        <i class="bi bi-file-earmark-bar-graph"></i>
        <span>Reports</span>
    </a>
    <a href="javascript:void(0)" onclick="loadPage('tasks')" data-nav-page="tasks">
        <i class="bi bi-check2-square"></i>
        <span>Tasks</span>
    </a>
<?php else: ?>
    <a href="javascript:void(0)" onclick="loadPage('workflows')" data-nav-page="workflows">
        <i class="bi bi-diagram-3"></i>
        <span>Workflows</span>
    </a>
    <a href="logout.php">
        <i class="bi bi-box-arrow-right"></i>
        <span>Logout</span>
    </a>
<?php endif; ?>
</nav>

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

        window.showActionResponse = function(text) {
            if (window.__temperAuthRedirecting) return;
            if (!text || typeof text !== 'string') return;
            const trimmed = text.trim();
            if (!trimmed) return;
            if (typeof window.isAuthExpiredPayload === 'function' && window.isAuthExpiredPayload(trimmed)) {
                if (typeof window.redirectToLoginExpired === 'function') {
                    window.redirectToLoginExpired();
                }
                return;
            }
            const htmlIdx = trimmed.search(/<[!do]/i);
            const prefix = (htmlIdx > 0 ? trimmed.slice(0, htmlIdx) : trimmed).trim();
            const line = prefix.split('\n').map(function(l) { return l.trim(); }).find(function(l) { return l.length > 0; });
            if (!line) return;
            const lower = line.toLowerCase();
            if (lower.indexOf('error') === 0) {
                showToast(line, 'danger');
            } else if (lower.indexOf('success') !== -1 || /added|updated|deleted|saved|archived|cleared|restored|reset/.test(lower)) {
                showToast(line, 'success');
            } else {
                showToast(line, 'info');
            }
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
            return fetch(postUrl, { method: 'POST', body: formData })
                .then(function(r) { return r.text(); })
                .then(function(text) {
                    if (window.__temperAuthRedirecting) return null;
                    if (typeof window.isAuthExpiredPayload === 'function' && window.isAuthExpiredPayload(text)) {
                        window.redirectToLoginExpired();
                        return null;
                    }
                    showActionResponse(text);
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
                    document.getElementById('main-content').innerHTML = html;
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
    })();
    </script>
</body>
</html>
