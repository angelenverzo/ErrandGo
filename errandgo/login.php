<?php
session_start();
require_once 'db.php';
require_once 'config.php';

$email = '';
$error = '';
$successMessage = '';

if (isset($_GET['registered']) && $_GET['registered'] === 'success') {
    $successMessage = 'Customer registration successful! You can now log in.';
} elseif (
    isset($_GET['worker_registered']) &&
    $_GET['worker_registered'] === 'success'
) {
    $successMessage = 'Worker registration successful! You can now log in.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please enter your email and password.';
    } else {
        $stmt = $pdo->prepare(
            "SELECT id, full_name, email, password, role
             FROM users
             WHERE email = ?
             LIMIT 1"
        );

        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {

            if (!in_array($user['role'], ['customer', 'worker', 'admin'], true)) {
                $error = 'This account cannot log in through this page.';
            } else {
                session_regenerate_id(true);

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['full_name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_role'] = $user['role'];
                audit_log($pdo, 'LOGIN', 'Logged in as ' . $user['role'] . '.', 'user', (int) $user['id']);

                if ($user['role'] === 'admin') {
                    header('Location: admin_dashboard.php');
                    exit;
                }

                if ($user['role'] === 'worker') {
                    header('Location: worker_dashboard.php');
                    exit;
                }

                header('Location: customer_dashboard.php');
                exit;
            }
        } else {
            $error = 'Incorrect email or password.';
            // Only the e-mail that was typed is recorded, never the password.
            audit_log($pdo, 'LOGIN_FAILED', 'Failed login attempt for e-mail ' . mb_substr($email, 0, 100) . '.', 'user', $user ? (int) $user['id'] : null,
                ['id' => $user ? (int) $user['id'] : null, 'name' => mb_substr($email, 0, 100), 'role' => $user['role'] ?? null]);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | ErrandGo</title>
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/theme.css">
</head>
<body>
<?php include 'partials/header.php'; ?>
<div class="auth-wrap">
<div class="container">
    <h1>Welcome Back!</h1>
    <p class="subtitle">Sign in to your ErrandGo account.</p>

    <?php if ($successMessage !== ''): ?>
        <div class="success">
            <?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="login.php">
        <label for="email">Email Address</label>
        <input
            type="email"
            id="email"
            name="email"
            value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"
            autocomplete="email"
            required
        >

        <label for="password">Password</label>
        <input
            type="password"
            id="password"
            name="password"
            autocomplete="current-password"
            required
        >

        <button type="submit" class="primary">Login</button>
    </form>

    <div class="links">
        <p>Customer? <a href="register.php">Create a customer account</a></p>
        <p>Want to become a worker? <a href="worker_register.php">Register as a worker</a></p>
        <p><a href="index.php">Back to Home</a></p>
    </div>
</div>
</div>
<?php include 'partials/footer.php'; ?>
</body>
</html>
