<?php
session_start();
require_once 'db.php';
require_once 'config.php';

$full_name = '';
$email = '';
$phone = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($full_name === '' || $email === '' || $password === '') {
        $error = 'Please complete all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO users (full_name, email, phone, password, role)
                 VALUES (?, ?, ?, ?, 'customer')"
            );

            $stmt->execute([
                $full_name,
                $email,
                $phone,
                password_hash($password, PASSWORD_DEFAULT)
            ]);

            $newId = (int) $pdo->lastInsertId();
            audit_log($pdo, 'REGISTER_CUSTOMER', "New customer account created ($email).", 'user', $newId, ['id' => $newId, 'name' => $full_name, 'role' => 'customer']);

            // Redirect to login after successful registration.
            header('Location: login.php?registered=success');
            exit;

        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $error = 'This email address is already registered.';
            } else {
                $error = 'Registration failed. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register | ErrandGo</title>
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/theme.css">
</head>
<body>
<?php include 'partials/header.php'; ?>
<div class="auth-wrap">
<div class="container">
    <h1>Create an Account</h1>
    <p class="subtitle">Join ErrandGo today.</p>

    <?php if ($error !== ''): ?>
        <div class="error">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="register.php">
        <label for="full_name">Full Name *</label>
        <input
            type="text"
            id="full_name"
            name="full_name"
            value="<?= htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8') ?>"
            maxlength="100"
            autocomplete="name"
            required
        >

        <label for="email">Email Address *</label>
        <input
            type="email"
            id="email"
            name="email"
            value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"
            maxlength="100"
            autocomplete="email"
            required
        >

        <label for="phone">Phone Number</label>
        <input
            type="tel"
            id="phone"
            name="phone"
            value="<?= htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') ?>"
            maxlength="20"
            autocomplete="tel"
        >

        <label for="password">Password *</label>
        <input
            type="password"
            id="password"
            name="password"
            minlength="8"
            autocomplete="new-password"
            required
        >

        <label for="confirm_password">Confirm Password *</label>
        <input
            type="password"
            id="confirm_password"
            name="confirm_password"
            minlength="8"
            autocomplete="new-password"
            required
        >

        <button type="submit" class="primary">Create Account</button>
    </form>

    <div class="links">
        <p><a href="login.php">Already have an account? Log in</a></p>
        <p><a href="index.php">Back to Home</a></p>
    </div>
</div>
</div>
<?php include 'partials/footer.php'; ?>
</body>
</html>
