<?php
session_start();

require_once 'db.php';

// Allow logged-in customers only
if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['user_role'] ?? '') !== 'customer'
) {
    header('Location: login.php');
    exit;
}

// Process the submitted form
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: change_password.php');
    exit;
}

$currentPassword = $_POST['current_password'] ?? '';
$newPassword = $_POST['new_password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

// Check that all fields are filled in
if (
    $currentPassword === '' ||
    $newPassword === '' ||
    $confirmPassword === ''
) {
    $_SESSION['password_message'] = 'Please fill in all fields.';
    header('Location: change_password.php');
    exit;
}

// Require at least 8 characters
if (strlen($newPassword) < 8) {
    $_SESSION['password_message'] =
        'Your new password must be at least 8 characters long.';

    header('Location: change_password.php');
    exit;
}

// Check whether the new passwords match
if ($newPassword !== $confirmPassword) {
    $_SESSION['password_message'] =
        'The new passwords do not match.';

    header('Location: change_password.php');
    exit;
}

try {
    // Get the current customer's password hash
    $stmt = $pdo->prepare(
        'SELECT password FROM users WHERE id = ? AND role = ?'
    );

    $stmt->execute([
        $_SESSION['user_id'],
        'customer'
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($currentPassword, $user['password'])) {
        audit_log($pdo, 'PASSWORD_CHANGE_FAILED', 'Password change refused: the current password was incorrect.', 'user', (int) $_SESSION['user_id']);
        $_SESSION['password_message'] =
            'Your current password is incorrect.';

        header('Location: change_password.php');
        exit;
    }

    // Hash the new password before saving it
    $hashedPassword = password_hash(
        $newPassword,
        PASSWORD_DEFAULT
    );

    $stmt = $pdo->prepare(
        'UPDATE users SET password = ? WHERE id = ? AND role = ?'
    );

    $stmt->execute([
        $hashedPassword,
        $_SESSION['user_id'],
        'customer'
    ]);

    audit_log($pdo, 'PASSWORD_CHANGED', 'Customer changed their password.', 'user', (int) $_SESSION['user_id']);
    $_SESSION['password_message'] =
        'Your password has been changed successfully.';

    header('Location: change_password.php');
    exit;

} catch (PDOException $e) {
    error_log($e->getMessage());

    $_SESSION['password_message'] =
        'Something went wrong. Please try again later.';

    header('Location: change_password.php');
    exit;
}
?>
