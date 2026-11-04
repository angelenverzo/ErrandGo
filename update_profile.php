<?php
session_start();

if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['user_role'] ?? '') !== 'customer'
) {
    header('Location: login.php');
    exit;
}

require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: my_profile.php');
    exit;
}

$fullName = trim($_POST['full_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');

if ($fullName === '' || strlen($fullName) > 100) {
    die('Please enter a valid name. <a href="my_profile.php">Go back</a>');
}

if (
    $email === '' ||
    strlen($email) > 100 ||
    !filter_var($email, FILTER_VALIDATE_EMAIL)
) {
    die('Please enter a valid email address. <a href="my_profile.php">Go back</a>');
}

if (strlen($phone) > 20) {
    die('Phone number must not exceed 20 characters. <a href="my_profile.php">Go back</a>');
}

try {
    $sql = "UPDATE users
            SET full_name = ?, email = ?, phone = ?
            WHERE id = ? AND role = 'customer'";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $fullName,
        $email,
        $phone !== '' ? $phone : null,
        $_SESSION['user_id']
    ]);

    $_SESSION['user_name'] = $fullName;
    $_SESSION['user_email'] = $email;
    audit_log($pdo, 'PROFILE_UPDATED', 'Customer updated their profile (name, e-mail, phone).', 'user', (int) $_SESSION['user_id']);

    header('Location: my_profile.php?updated=success');
    exit;

} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        die('That email address is already registered. Please use another email. <a href="my_profile.php">Go back</a>');
    }

    error_log($e->getMessage());
    die('Unable to update your profile right now. Please try again later.');
}
?>
