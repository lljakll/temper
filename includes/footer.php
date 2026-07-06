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

</div>
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
            if (!text || typeof text !== 'string') return;
            const trimmed = text.trim();
            if (!trimmed) return;
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
                    showActionResponse(text);
                    return fetch(reloadUrl);
                })
                .then(function(r) { return r.text(); })
                .then(function(html) {
                    document.getElementById('main-content').innerHTML = html;
                })
                .catch(function(err) {
                    console.error(err);
                    showToast('Request failed. Please try again.', 'danger');
                });
        };

        window.applyMainContent = function(html) {
            document.getElementById('main-content').innerHTML = html;
            consumePageFlash('page-flash');
            consumePageFlash('ledger-flash');
        };
    })();
    </script>
</body>
</html>
