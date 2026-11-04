<?php
session_start();
require_once 'db.php';
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$uid = (int) $_SESSION['user_id'];

// Open one notification: mark it read, then go to its page (internal pages only).
if (isset($_GET['open'])) {
    $id = filter_input(INPUT_GET, 'open', FILTER_VALIDATE_INT);
    if ($id) {
        $stmt = $pdo->prepare('SELECT link FROM notifications WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $uid]);
        $row = $stmt->fetch();
        if ($row) {
            $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
            if ($row['link'] && preg_match('/^[a-z_]+\.php$/', $row['link'])) {
                header('Location: ' . $row['link']);
                exit;
            }
        }
    }
    header('Location: notifications.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')->execute([$uid]);
    header('Location: notifications.php');
    exit;
}

$stmt = $pdo->prepare('SELECT id, message, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 50');
$stmt->execute([$uid]);
$items = $stmt->fetchAll();
$unreadCount = count(array_filter($items, fn($n) => !$n['is_read']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications | ErrandGo</title>
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/theme.css">
</head>
<body>
<?php include 'partials/header.php'; ?>
<div class="container narrow">
    <h1 class="page-banner">Notifications</h1>
    <?php if ($unreadCount > 0): ?>
        <form method="POST"><button type="submit" class="primary" style="margin:0 0 14px">Mark all as read</button></form>
    <?php endif; ?>

    <?php if ($items): ?>
        <?php foreach ($items as $n): ?>
            <a class="notif <?= $n['is_read'] ? '' : 'unread' ?>" href="notifications.php?open=<?= (int) $n['id'] ?>">
                <?= e($n['message']) ?>
                <span class="when"><?= e(date('M j, Y g:i A', strtotime($n['created_at']))) ?></span>
            </a>
        <?php endforeach; ?>
        <p class="small">Showing your latest 50 notifications.</p>
    <?php else: ?>
        <div class="empty"><p>No notifications yet. Updates about your errands will appear here.</p></div>
    <?php endif; ?>
</div>
<?php include 'partials/footer.php'; ?>
</body>
</html>
