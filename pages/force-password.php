<?php
/**
 * Forced password change — required when admin sets must_change_password.
 * Accessible even when other pages are blocked by the force-password gate.
 */
$temperSkipPagePermission = true;
require_once __DIR__ . '/../includes/page_bootstrap.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/permissions.php';

// requireLogin already ran
$actor = getCurrentUser();
if (!$actor) {
    denyUnauthenticatedAccess();
}
$actorId = (int)$actor['id'];
$success = null;
$error = null;

$forced = !empty($_SESSION['must_change_password']);

/**
 * True when client asked for JSON (fetch with Accept: application/json).
 */
function forcePasswordWantsJson(): bool {
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    return strpos($accept, 'application/json') !== false;
}

function forcePasswordSendJson(array $payload, ?mysqli $db = null): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($db) {
        $db->close();
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'change_password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['new_password_confirm'] ?? '');

        if ($current === '' || $new === '' || $confirm === '') {
            $error = 'All password fields are required.';
        } elseif ($new !== $confirm) {
            $error = 'New passwords do not match.';
        } else {
            $pwErr = validatePasswordStrength($new);
            if ($pwErr) {
                $error = $pwErr;
            } elseif (!verifyUserPassword($db, $actorId, $current)) {
                $error = 'Current password is incorrect.';
                logAuditAction($db, $actorId, (string)$actor['username'], 'force_password_change_failed', 'Incorrect current password');
            } elseif ($current === $new) {
                $error = 'New password must be different from the current password.';
            } else {
                $hash = hashUserPassword($new);
                $stmt = $db->prepare('UPDATE users SET password = ? WHERE id = ?');
                $stmt->bind_param('si', $hash, $actorId);
                if ($stmt->execute()) {
                    $stmt->close();
                    clearMustChangePassword($db, $actorId);
                    logAuditAction($db, $actorId, (string)$actor['username'], 'force_password_change', 'User completed required password change');
                    $success = 'Password updated. You can continue using the application.';
                    $forced = false;

                    if (forcePasswordWantsJson()) {
                        forcePasswordSendJson([
                            'success' => true,
                            'message' => $success,
                            'must_change_password' => false,
                            'redirect' => 'dashboard',
                        ], $db);
                    }
                } else {
                    $error = 'Failed to update password. Please try again.';
                    $stmt->close();
                }
            }
        }

        if ($error && forcePasswordWantsJson()) {
            forcePasswordSendJson([
                'success' => false,
                'error' => $error,
                'must_change_password' => !empty($_SESSION['must_change_password']),
            ], $db);
        }
    }
}
?>

<?php if ($success || $error): ?>
<script type="application/json" id="page-flash"><?= json_encode([
    'message' => $success ?: $error,
    'type' => $success ? 'success' : 'danger',
], JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="card shadow-sm border-warning">
            <div class="card-header py-2 bg-warning-subtle">
                <span class="fw-semibold small">
                    <i class="bi bi-key me-1"></i>
                    <?= $forced ? 'Password change required' : 'Change password' ?>
                </span>
            </div>
            <div class="card-body">
                <?php if ($forced): ?>
                    <div class="alert alert-warning py-2 small mb-3">
                        An administrator requires you to set a new password before continuing.
                        Navigation is disabled until this is complete.
                    </div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger py-2 small"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success py-2 small"><?= htmlspecialchars($success) ?></div>
                    <button type="button" class="btn btn-primary btn-sm" id="forcePwContinueBtn">
                        Continue to app
                    </button>
                <?php else: ?>
                    <form method="post" action="pages/force-password.php" id="forcePasswordForm" autocomplete="off">
                        <input type="hidden" name="action" value="change_password">
                        <div class="mb-2">
                            <label class="form-label small" for="fp_current">Current password</label>
                            <input type="password" class="form-control form-control-sm" id="fp_current"
                                   name="current_password" required autocomplete="current-password">
                        </div>
                        <div class="mb-2">
                            <label class="form-label small" for="fp_new">New password</label>
                            <input type="password" class="form-control form-control-sm" id="fp_new"
                                   name="new_password" required minlength="8" autocomplete="new-password">
                            <div class="form-text">At least 8 characters.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small" for="fp_confirm">Confirm new password</label>
                            <input type="password" class="form-control form-control-sm" id="fp_confirm"
                                   name="new_password_confirm" required minlength="8" autocomplete="new-password">
                        </div>
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <button type="submit" class="btn btn-warning btn-sm">
                                <i class="bi bi-check2"></i> Set new password
                            </button>
                            <a href="logout.php" class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-box-arrow-right"></i> Logout
                            </a>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script type="text/plain" id="init-force-password-script">
(function() {
    // Keep shell in locked mode while form is shown / until success handled
    if (typeof window.setForcePasswordShell === 'function') {
        window.setForcePasswordShell(!!window.__temperMustChangePassword);
    }

    function goHome() {
        window.__temperMustChangePassword = false;
        if (typeof window.setForcePasswordShell === 'function') {
            window.setForcePasswordShell(false);
        }
        // Prefer a full reload so nav re-renders with full links
        if (window.location && window.location.pathname) {
            window.location.href = 'index.php';
            return;
        }
        var home = window.__temperHomePage || 'dashboard';
        if (home === 'force-password') home = 'dashboard';
        if (typeof loadPage === 'function') loadPage(home);
    }

    var cont = document.getElementById('forcePwContinueBtn');
    if (cont) {
        cont.addEventListener('click', goHome);
    }

    var form = document.getElementById('forcePasswordForm');
    if (!form) return;

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        var fd = new FormData(form);
        var submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;

        fetch('pages/force-password.php', {
            method: 'POST',
            body: fd,
            headers: { 'Accept': 'application/json' },
        })
            .then(function(r) { return r.json().catch(function() { return null; }).then(function(j) { return { ok: r.ok, json: j, status: r.status }; }); })
            .then(function(res) {
                if (submitBtn) submitBtn.disabled = false;
                if (!res || !res.json) {
                    // Fallback: HTML path
                    if (typeof submitFormAndReload === 'function') {
                        return submitFormAndReload('pages/force-password.php', fd, 'pages/force-password.php');
                    }
                    if (typeof showToast === 'function') showToast('Unexpected response. Please try again.', 'danger');
                    return;
                }
                var data = res.json;
                if (!data.success) {
                    if (typeof showToast === 'function') showToast(data.error || 'Password change failed.', 'danger');
                    return;
                }
                if (typeof showToast === 'function') showToast(data.message || 'Password updated.', 'success');
                window.__temperMustChangePassword = false;
                if (typeof window.setForcePasswordShell === 'function') {
                    window.setForcePasswordShell(false);
                }
                // Brief pause so toast is visible, then restore shell
                setTimeout(goHome, 600);
            })
            .catch(function(err) {
                if (submitBtn) submitBtn.disabled = false;
                console.error(err);
                if (typeof showToast === 'function') showToast('Request failed. Please try again.', 'danger');
            });
    });
})();
</script>
<img src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==" style="display:none" alt="" onload="var s=document.getElementById('init-force-password-script');if(s){(new Function(s.textContent))();}this.remove();">

<?php $db->close(); ?>
