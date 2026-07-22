<?php
/**
 * User profile — view account info and change own password.
 */
require_once __DIR__ . '/../includes/page_bootstrap.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/permissions.php';

$actor = requirePermission($db, 'profile.self');
$success = null;
$error = null;

function profileWantsJson(): bool {
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    return strpos($accept, 'application/json') !== false;
}

function profileSendJson(array $payload, ?mysqli $db = null): void {
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
            } elseif (!verifyUserPassword($db, (int)$actor['id'], $current)) {
                $error = 'Current password is incorrect.';
                logAuditAction(
                    $db,
                    (int)$actor['id'],
                    (string)$actor['username'],
                    'profile_password_change_failed',
                    'Incorrect current password'
                );
            } elseif ($current === $new) {
                $error = 'New password must be different from the current password.';
            } else {
                $hash = hashUserPassword($new);
                $userId = (int)$actor['id'];
                $stmt = $db->prepare('UPDATE users SET password = ? WHERE id = ?');
                $stmt->bind_param('si', $hash, $userId);
                if ($stmt->execute()) {
                    $stmt->close();
                    clearMustChangePassword($db, $userId);
                    $success = 'Password updated successfully.';
                    logAuditAction(
                        $db,
                        $userId,
                        (string)$actor['username'],
                        'profile_password_change',
                        'User changed own password'
                    );
                    if (profileWantsJson()) {
                        profileSendJson([
                            'success' => true,
                            'message' => $success,
                            'must_change_password' => false,
                        ], $db);
                    }
                } else {
                    $error = 'Failed to update password. Please try again.';
                    $stmt->close();
                }
            }
        }

        if ($error && profileWantsJson()) {
            profileSendJson([
                'success' => false,
                'error' => $error,
            ], $db);
        }
    }
}

// Fresh profile row (may include inactive edge cases via ACL)
$profile = loadUserAcl($db, (int)$actor['id']);
if (!$profile) {
    denyUnauthenticatedAccess();
}

// last_login / phone from users table
$lastLogin = null;
$phone = $profile['phone'] ?? null;
$cols = 'last_login, email, created_at';
if (temperColumnExists($db, 'users', 'phone')) {
    $cols .= ', phone';
}
$ll = $db->prepare("SELECT {$cols} FROM users WHERE id = ? LIMIT 1");
$ll->bind_param('i', $profile['id']);
$ll->execute();
$llRow = $ll->get_result()->fetch_assoc();
$ll->close();
if ($llRow) {
    $lastLogin = $llRow['last_login'] ?? null;
    $profile['email'] = $llRow['email'] ?? $profile['email'];
    $createdAt = $llRow['created_at'] ?? null;
    $phone = $llRow['phone'] ?? $phone;
} else {
    $createdAt = null;
}
$roleLabel = implode(', ', $profile['role_names'] ?? [($profile['role_name'] ?? '')]);
?>

<?php if ($success || $error): ?>
<script type="application/json" id="page-flash"><?= json_encode([
    'message' => $success ?: $error,
    'type' => $success ? 'success' : 'danger',
], JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>

<div class="row mb-3">
    <div class="col-12">
        <h2 class="h4 mb-0">My Profile</h2>
        <p class="text-muted small mb-0">Account details and password.</p>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-5">
        <div class="card shadow-sm h-100">
            <div class="card-header py-2">
                <span class="fw-semibold small"><i class="bi bi-person-badge me-1"></i> Account</span>
            </div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-sm-4 text-muted">Name</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars($profile['display_name']) ?></dd>

                    <dt class="col-sm-4 text-muted">Username</dt>
                    <dd class="col-sm-8"><code><?= htmlspecialchars($profile['username']) ?></code></dd>

                    <dt class="col-sm-4 text-muted">Email</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars($profile['email'] ?? '') ?></dd>

                    <dt class="col-sm-4 text-muted">Phone</dt>
                    <dd class="col-sm-8"><?= $phone ? htmlspecialchars($phone) : '—' ?></dd>

                    <dt class="col-sm-4 text-muted">Role<?= (count($profile['role_names'] ?? []) > 1) ? 's' : '' ?></dt>
                    <dd class="col-sm-8">
                        <?php foreach (($profile['role_names'] ?? [$profile['role_name']]) as $rn): ?>
                            <span class="badge text-bg-primary-subtle text-primary border me-1">
                                <?= htmlspecialchars((string)$rn) ?>
                            </span>
                        <?php endforeach; ?>
                    </dd>

                    <dt class="col-sm-4 text-muted">Last login</dt>
                    <dd class="col-sm-8"><?= $lastLogin ? htmlspecialchars($lastLogin) : '—' ?></dd>

                    <?php if ($createdAt): ?>
                    <dt class="col-sm-4 text-muted">Member since</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars($createdAt) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-md-7">
        <div class="card shadow-sm h-100">
            <div class="card-header py-2">
                <span class="fw-semibold small"><i class="bi bi-key me-1"></i> Change password</span>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger py-2 small"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success py-2 small"><?= htmlspecialchars($success) ?></div>
                <?php endif; ?>

                <form method="post" action="pages/profile.php" id="profilePasswordForm" autocomplete="off" data-dirty-track>
                    <input type="hidden" name="action" value="change_password">
                    <div class="mb-2">
                        <label class="form-label small" for="current_password">Current password</label>
                        <input type="password" class="form-control form-control-sm" id="current_password"
                               name="current_password" required autocomplete="current-password">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small" for="new_password">New password</label>
                        <input type="password" class="form-control form-control-sm" id="new_password"
                               name="new_password" required minlength="8" autocomplete="new-password">
                        <div class="form-text">At least 8 characters.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small" for="new_password_confirm">Confirm new password</label>
                        <input type="password" class="form-control form-control-sm" id="new_password_confirm"
                               name="new_password_confirm" required minlength="8" autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-check2"></i> Update password
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script type="text/plain" id="init-profile-script">
(function() {
    const form = document.getElementById('profilePasswordForm');
    if (!form) return;
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        const fd = new FormData(form);
        const btn = form.querySelector('button[type="submit"]');
        if (btn) btn.disabled = true;
        fetch('pages/profile.php', {
            method: 'POST',
            body: fd,
            headers: { 'Accept': 'application/json' },
        })
            .then(function(r) { return r.json().catch(function() { return null; }); })
            .then(function(data) {
                if (btn) btn.disabled = false;
                if (!data) {
                    // Fallback to HTML reload path
                    if (typeof submitFormAndReload === 'function') {
                        submitFormAndReload('pages/profile.php', fd, 'pages/profile.php');
                    }
                    return;
                }
                if (!data.success) {
                    if (typeof showToast === 'function') showToast(data.error || 'Password change failed.', 'danger');
                    return;
                }
                if (typeof showToast === 'function') showToast(data.message || 'Password updated.', 'success');
                form.reset();
                if (typeof window.TemperDirtyForms !== 'undefined') {
                    window.TemperDirtyForms.markClean(form);
                }
                // Reload profile fragment for clean state (flash toast already shown)
                fetch('pages/profile.php')
                    .then(function(r) { return r.text(); })
                    .then(function(html) {
                        if (typeof applyMainContent === 'function') applyMainContent(html);
                        else {
                            document.getElementById('main-content').innerHTML = html;
                        }
                    });
            })
            .catch(function(err) {
                if (btn) btn.disabled = false;
                console.error(err);
                if (typeof showToast === 'function') showToast('Request failed. Please try again.', 'danger');
            });
    });
})();
</script>
<img src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==" style="display:none" alt="" onload="var s=document.getElementById('init-profile-script');if(s){(new Function(s.textContent))();}this.remove();">

<?php $db->close(); ?>
