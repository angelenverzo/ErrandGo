<?php
session_start();

if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['user_role'] ?? '') !== 'customer'
) {
    header('Location: login.php');
    exit;
}

$message = $_SESSION['password_message'] ?? '';
unset($_SESSION['password_message']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password | ErrandGo</title>
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/theme.css">
</head>
<body>
<?php include 'partials/header.php'; ?>
    <div class="container">
        <h1 class="page-banner">Change Password</h1>
        <p>Enter your current password and choose a new one.</p>

        <?php if ($message !== ''): ?>
            <p class="message">
                <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
            </p>
        <?php endif; ?>

        <form action="update_password.php" method="POST">
            <label for="current_password">Current Password</label>
            <input
                type="password"
                id="current_password"
                name="current_password"
                autocomplete="current-password"
                required
            >

            <label for="new_password">New Password</label>
            <input
                type="password"
                id="new_password"
                name="new_password"
                minlength="8"
                autocomplete="new-password"
                required
            >

            <label for="confirm_password">Confirm New Password</label>
            <input
                type="password"
                id="confirm_password"
                name="confirm_password"
                minlength="8"
                autocomplete="new-password"
                required
            >

            <button type="submit">Update Password</button>

            <a class="back" href="customer_dashboard.php">
                Back to Dashboard
            </a>
        </form>
    </div>
<?php include 'partials/footer.php'; ?>
</body>
</html>
