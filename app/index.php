<?php
require_once 'config.php';
require_once 'auth.php';

// Check if user is logged in
if (!isLoggedIn()) {
    // Redirect to login page
    header('Location: login.php');
    exit;
}

// Get current user
$user = getCurrentUser();

require_once 'includes/header.php';
require_once 'includes/nav.php';
?>

<div id="main-content" class="p-4">
    <!-- Content will be loaded here via AJAX -->
</div>

<script>
    // Load dashboard content on page load
    document.addEventListener('DOMContentLoaded', function() {
        fetch('pages/dashboard.php')
            .then(response => response.text())
            .then(data => {
                document.getElementById('main-content').innerHTML = data;
            })
            .catch(error => {
                console.error('Error loading content:', error);
                document.getElementById('main-content').innerHTML = '<p>Error loading content</p>';
            });
    });
</script>

<?php require_once 'includes/footer.php'; ?>
