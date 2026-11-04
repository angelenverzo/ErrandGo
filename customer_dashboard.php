<?php
session_start();
require_once 'db.php';
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'customer') {
    header('Location: login.php');
    exit;
}
$user_name = $_SESSION['user_name'] ?? 'Customer';

$counts = ['pending' => 0, 'accepted' => 0, 'in_progress' => 0, 'completed' => 0, 'cancelled' => 0];
$stmt = $pdo->prepare('SELECT status, COUNT(*) AS n FROM service_requests WHERE user_id = ? GROUP BY status');
$stmt->execute([$_SESSION['user_id']]);
foreach ($stmt->fetchAll() as $row) {
    $counts[$row['status']] = (int) $row['n'];
}
$active = $counts['pending'] + $counts['accepted'] + $counts['in_progress'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Dashboard | ErrandGo</title>
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/theme.css">
</head>
<body>
<?php include 'partials/header.php'; ?>
<main class="container">
    <section class="welcome">
        <h1>Welcome, <?= e($user_name) ?>!</h1>
        <?php if (($_GET['request'] ?? '') === 'success'): ?>
            <p style="background:rgba(255,255,255,.18);padding:10px 14px;border-radius:8px">&#10003; <?= (int) ($_GET['n'] ?? 1) > 1 ? (int) $_GET['n'] . ' errands were' : 'Your errand request was' ?> submitted successfully! A verified worker will accept <?= (int) ($_GET['n'] ?? 1) > 1 ? 'them' : 'it' ?> soon.</p>
        <?php endif; ?>
        <p>What can ErrandGo help you with today?</p>
    </section>

    <div class="kpi-grid">
        <div class="kpi pink"><div class="v"><?= $active ?></div><div class="n">Active errands</div></div>
        <div class="kpi"><div class="v"><?= $counts['completed'] ?></div><div class="n">Completed</div></div>
        <div class="kpi"><div class="v"><?= $counts['cancelled'] ?></div><div class="n">Cancelled</div></div>
    </div>

    <h2>Your ErrandGo Dashboard</h2>
    <section class="grid">
        <div class="card">
            <h3>Request an Errand</h3>
            <p>Deliveries, shopping, office supplies, moving help, and more.</p>
            <a class="button primary" href="request_errand.php">Request an Errand</a>
        </div>
        <div class="card">
            <h3>My Requests</h3>
            <p>Track your errands and rate completed ones.</p>
            <a class="button" href="my_requests.php">View My Requests</a>
        </div>
        <div class="card">
            <h3>My Profile</h3>
            <p>Manage your account information.</p>
            <a class="button" href="my_profile.php">View My Profile</a>
        </div>
    </section>
</main>
<?php include 'partials/footer.php'; ?>
</body>
</html>
