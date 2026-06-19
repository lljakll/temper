<?php
session_start();

// Security: Prevent direct access to this helper file
if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    header('Location: login.php');
    exit;
}

// DEBUG VERSION - Login function
function login($username, $password) {
    require_once 'config.php';
    $db = getDbConnection();

    echo "<div style='background:#f8f9fa; padding:15px; margin:10px; border:2px solid #007bff;'>";
    echo "<strong>🔍 LOGIN DEBUG:</strong><br>";
    echo "Username entered: <b>" . htmlspecialchars($username) . "</b><br>";

    $stmt = $db->prepare("SELECT id, username, first_name, email, password FROM users WHERE username = ? AND is_active = TRUE");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        echo "✅ User found!<br>";
        echo "Hash length: " . strlen($user['password']) . "<br>";
        echo "Hash preview: " . htmlspecialchars(substr($user['password'], 0, 60)) . "<br>";

        $verify = password_verify($password, $user['password']);
        echo "password_verify() = " . ($verify ? "<b style='color:green'>TRUE - SUCCESS</b>" : "<b style='color:red'>FALSE - FAIL</b>") . "<br>";

        if ($verify) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['first_name'] ?? $user['username'];
            $_SESSION['username'] = $user['username'];

            $update = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $update->bind_param("i", $user['id']);
            $update->execute();

            echo "<b style='color:green'>LOGIN SUCCESSFUL - Redirecting...</b>";
            $stmt->close();
            $db->close();
            return true;
        }
    } else {
        echo "❌ No user found with username: " . htmlspecialchars($username) . "<br>";
    }

    echo "</div>";

    $stmt->close();
    $db->close();
    return false;
}

function logout() { session_destroy(); }
function isLoggedIn() { return isset($_SESSION['user_id']); }
function getCurrentUser() {
    if (isLoggedIn()) {
        return ['id' => $_SESSION['user_id'], 'name' => $_SESSION['user_name'] ?? 'User'];
    }
    return null;
}
?>
