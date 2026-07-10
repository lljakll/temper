<?php

require_once __DIR__ . '/auth.php';

logout();

// Redirect back to login with success message
header('Location: login.php?logout=1');
exit;
