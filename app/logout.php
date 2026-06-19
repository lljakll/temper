<?php

session_start();

// Destroy all session data
session_destroy();

// Redirect back to login with success message
header("Location: login.php?logout=1");
exit;
?>