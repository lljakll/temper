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
</body>
</html>
