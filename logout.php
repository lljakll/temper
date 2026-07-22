<?php

require_once __DIR__ . '/auth.php';

// Capture before clear: idle/client timeout of a logged-in user
$hadAuthenticatedSession = isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;
$expired = isset($_GET['expired']) && (string)$_GET['expired'] === '1';

if ($expired && $hadAuthenticatedSession) {
    markAuthSessionExpiredFlash();
}

logout();

// Idle timeout → expired banner; manual logout → success message
if ($expired) {
    header('Location: login.php?expired=1');
} else {
    header('Location: login.php?logout=1');
}
exit;
