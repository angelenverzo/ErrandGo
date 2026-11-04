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

$stmt = $pdo->prepare(
    "SELECT full_name, email, phone
     FROM users
     WHERE id = ? AND role = 'customer'"
);

$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}

function escapeProfile($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$message = '';

if (isset($_GET['updated']) && $_GET['updated'] === 'success') {
    $message = 'Your profile was updated successfully.';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | ErrandGo</title>
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/theme.css">
</head>
<body>
<?php include 'partials/header.php'; ?>
    <div class="container">
        <h1 class="page-banner">My Profile</h1>
        <p>View your ErrandGo account information.</p>

        <?php if ($message !== ''): ?>
            <p class="message">
                <?php echo escapeProfile($message); ?>
            </p>
        <?php endif; ?>

        <form action="update_profile.php" method="POST">
            <label for="full_name">Full Name</label>
            <input
                type="text"
                id="full_name"
                name="full_name"
                maxlength="100"
                value="<?php echo escapeProfile($user['full_name']); ?>"
                required
            >

            <label for="email">Email Address</label>
            <input
                type="email"
                id="email"
                name="email"
                maxlength="100"
                value="<?php echo escapeProfile($user['email']); ?>"
                required
            >

            <label for="phone">Phone Number</label>
            <input
                type="tel"
                id="phone"
                name="phone"
                maxlength="20"
                value="<?php echo escapeProfile($user['phone'] ?? ''); ?>"
            >

            <button class="button" type="submit">
                Save Changes
            </button>

            <a class="button back" href="customer_dashboard.php">
                Back to Dashboard
            </a>
            
            <a class="button" href="change_password.php">
                Change Password
            </a>

        </form>
    </div>
<?php include 'partials/footer.php'; ?>
</body>
</html>
