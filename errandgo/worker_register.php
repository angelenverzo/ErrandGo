<?php
session_start();
require_once 'db.php';
require_once 'config.php';

$full_name = $email = $phone = $error = '';
$vehicle = 'None / On foot';
$caps = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $vehicle = $_POST['vehicle_type'] ?? '';
    $caps = array_values(array_intersect(SERVICES, (array) ($_POST['capabilities'] ?? [])));

    if ($full_name === '' || $email === '' || $password === '') {
        $error = 'Please complete all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif (!in_array($vehicle, VEHICLE_TYPES, true)) {
        $error = 'Please choose your vehicle type.';
    } elseif (count($caps) === 0) {
        $error = 'Please select at least one service you can perform.';
    } else {
        try {
            // Role and verification status are set by the server, never by the form.
            $stmt = $pdo->prepare(
                "INSERT INTO users (full_name, email, phone, password, role, vehicle_type, capabilities, verification_status)
                 VALUES (?, ?, ?, ?, 'worker', ?, ?, 'pending')"
            );
            $stmt->execute([
                $full_name, $email, $phone !== '' ? $phone : null,
                password_hash($password, PASSWORD_DEFAULT), $vehicle, implode(',', $caps),
            ]);
            $newId = (int) $pdo->lastInsertId();
            audit_log($pdo, 'REGISTER_WORKER', "New worker account created ($email); verification pending.", 'user', $newId, ['id' => $newId, 'name' => $full_name, 'role' => 'worker']);
            notify_admins($pdo, "New worker registered: $full_name (verification pending).", 'admin_workers.php');
            header('Location: login.php?worker_registered=success');
            exit;
        } catch (PDOException $e) {
            $error = $e->getCode() === '23000'
                ? 'This email address is already registered.'
                : 'Worker registration failed. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Worker Registration | ErrandGo</title>
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/theme.css">
</head>
<body>
<?php include 'partials/header.php'; ?>
<div class="auth-wrap">
<div class="container">
    <h1>Become an ErrandGo Worker</h1>
    <p class="subtitle">Earn flexibly with the time and vehicle you already have.</p>

    <?php if ($error !== ''): ?><div class="error"><?= e($error) ?></div><?php endif; ?>

    <form method="POST" action="worker_register.php">
        <label for="full_name">Full Name *</label>
        <input type="text" id="full_name" name="full_name" value="<?= e($full_name) ?>" maxlength="100" autocomplete="name" required>

        <label for="email">Email Address *</label>
        <input type="email" id="email" name="email" value="<?= e($email) ?>" maxlength="100" autocomplete="email" required>

        <label for="phone">Phone Number</label>
        <input type="tel" id="phone" name="phone" value="<?= e($phone) ?>" maxlength="20" autocomplete="tel">

        <label for="vehicle_type">Vehicle *</label>
        <select id="vehicle_type" name="vehicle_type" required>
            <?php foreach (VEHICLE_TYPES as $v): ?>
                <option value="<?= e($v) ?>" <?= $v === $vehicle ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
        </select>

        <label>Services you can perform *</label>
        <?php foreach (SERVICES as $s): ?>
            <label style="font-weight:normal;margin:4px 0;display:flex;gap:8px;align-items:center">
                <input type="checkbox" style="width:auto" name="capabilities[]" value="<?= e($s) ?>" <?= in_array($s, $caps, true) ? 'checked' : '' ?>>
                <?= e($s) ?>
            </label>
        <?php endforeach; ?>

        <label for="password">Password *</label>
        <input type="password" id="password" name="password" minlength="8" autocomplete="new-password" required>

        <label for="confirm_password">Confirm Password *</label>
        <input type="password" id="confirm_password" name="confirm_password" minlength="8" autocomplete="new-password" required>

        <div class="notice">Please use your own information. New worker accounts are reviewed by ErrandGo management before they can accept errands.</div>
        <button type="submit" class="primary">Create Worker Account</button>
    </form>

    <div class="links">
        <p>Already registered? <a href="login.php">Log in</a></p>
        <p><a href="register.php">Register as a customer</a> &middot; <a href="index.php">Back to Home</a></p>
    </div>
</div>
</div>
<?php include 'partials/footer.php'; ?>
</body>
</html>
