<?php
session_start();
require_once 'db.php';

// Record the logout before the session is destroyed.
if (isset($_SESSION['user_id'])) {
    audit_log($pdo, 'LOGOUT', 'Logged out.', 'user', (int) $_SESSION['user_id']);
}

// Remove all session variables.
$_SESSION = [];

// Destroy the current session.
session_destroy();

// Return to the login page.
header('Location: login.php');
exit;
?>
