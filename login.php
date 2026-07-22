<?php
require_once 'auth.php';

// If user is already logged in (and session still within idle limit), go to app
if (isLoggedIn() && isSessionWithinIdleLimit()) {
    header('Location: index.php');
    exit;
}
// Stale idle session cookie: clear so the login form can be used
$showSessionExpired = false;
if (isLoggedIn() && !isSessionWithinIdleLimit()) {
    clearAuthSession();
    $showSessionExpired = true;
}

// One-time flash set only when a real authenticated session ended (idle/forced).
// Query param alone is not enough — avoids banner on fresh visits / bookmarks of ?expired=1.
$showSessionExpired = $showSessionExpired || consumeAuthSessionExpiredFlash();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        if (login($username, $password)) {
            header('Location: index.php');
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Hope Baptist Treasurer</title>
    <script>
    (function () {
        try {
            var stored = localStorage.getItem('temper-theme');
            var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            var theme = (stored === 'light' || stored === 'dark') ? stored : (prefersDark ? 'dark' : 'light');
            document.documentElement.setAttribute('data-bs-theme', theme);
        } catch (e) {
            document.documentElement.setAttribute('data-bs-theme', 'light');
        }
    })();
    </script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            color: var(--bs-body-color);
            background-color: var(--bs-body-bg);
        }
    </style>
</head>
<body>
    <div class="container px-3">
        <div class="row justify-content-center min-vh-100 align-items-center py-4">
            <div class="col-12 col-sm-10 col-md-6 col-lg-4">
                <div class="card shadow-sm">
                    <div class="card-header py-3">
                        <h3 class="text-center mb-0 h4">Login</h3>
                    </div>
                    <div class="card-body p-3 p-md-4">
                        <?php if (isset($_GET['logout'])): ?>
                            <div class="alert alert-success text-center">
                                You have been logged out successfully.
                            </div>
                        <?php endif; ?>
                        <?php if ($showSessionExpired): ?>
                            <div class="alert alert-warning text-center">
                                Your session has expired. Please log in again.
                            </div>
                        <?php endif; ?>
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                        <?php endif; ?>

                        <form method="POST" action="">
                            <div class="mb-3">
                                <label for="username" class="form-label">Username</label>
                                <input type="text" class="form-control form-control-lg" id="username" name="username" autocomplete="username" required>
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">Password</label>
                                <input type="password" class="form-control form-control-lg" id="password" name="password" autocomplete="current-password" required>
                            </div>
                            <button type="submit" class="btn btn-primary btn-lg w-100">Login</button>
                        </form>

                        <div class="text-center mt-3">
                            <small class="text-body-secondary">Try: <strong>admin</strong> / <strong>password</strong></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
