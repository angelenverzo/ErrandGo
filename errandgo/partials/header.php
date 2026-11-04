<?php
// Shared site header. Set $page_title (and optionally $nav_back) before including.
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}
require_once __DIR__ . '/../config.php';

$role = $_SESSION['user_role'] ?? '';

// Admin navbar gets dark logo; Customers/Workers get light logo
if ($role === 'admin') {
    $logo = file_exists(__DIR__ . '/../assets/logo-light.svg') ? 'assets/logo-light.svg' : 'assets/logo.svg';
    } 
if ($role === 'worker') {
    $logo = file_exists(__DIR__ . '/../assets/logo-light.svg') ? 'assets/logo-light.svg' : 'assets/logo.svg';
    } 
if ($role === 'customer') {
    $logo = file_exists(__DIR__ . '/../assets/logo-light.svg') ? 'assets/logo-light.svg' : 'assets/logo.svg';
    } 
$home = 'index.php';
$links = [];
if ($role === 'customer') {
    $home = 'customer_dashboard.php';
    $links = ['request_errand.php' => 'Request', 'my_requests.php' => 'My Requests', 'my_profile.php' => 'Profile'];
} elseif ($role === 'worker') {
    $home = 'worker_dashboard.php';
    $links = ['worker_dashboard.php' => 'Available', 'my_accepted_errands.php' => 'My Errands', 'worker_profile.php' => 'Profile'];
} elseif ($role === 'admin') {
    $home = 'admin_dashboard.php';
    $links = ['admin_dashboard.php' => 'Dashboard', 'admin_workers.php' => 'Workers', 'admin_pricing.php' => 'Pricing', 'admin_audit.php' => 'Audit Logs'];
}
$current = basename($_SERVER['SCRIPT_NAME']);

$unread = 0;
if ($role !== '' && isset($_SESSION['user_id'])) {
    try {
        require_once __DIR__ . '/../db.php';
        $uc = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $uc->execute([$_SESSION['user_id']]);
        $unread = (int) $uc->fetchColumn();
    } catch (Throwable $ex) {
        $unread = 0; // notifications table not imported yet: header still works
    }
}
?>
<header class="site-header">
    <div class="header-inner">
        <a class="brand" href="<?= e($home) ?>"><img src="<?= e($logo) ?>" alt="ErrandGo - <?= e(ERRANDGO_TAGLINE) ?>"></a>
        <nav class="main-nav" aria-label="Main">
            <?php foreach ($links as $href => $label): ?>
                <a href="<?= e($href) ?>" class="<?= $current === $href ? 'active' : '' ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
            <?php if ($role !== ''): ?>
                <a class="bell <?= $current === 'notifications.php' ? 'active' : '' ?>" href="notifications.php" title="Notifications" aria-label="Notifications">
                    &#128276;<span id="bell-count" class="bell-count" <?= $unread > 0 ? '' : 'hidden' ?>><?= $unread > 99 ? '99+' : $unread ?></span>
                </a>
                <a class="nav-logout" href="logout.php">Logout</a>
            <?php else: ?>
                <a href="login.php">Login</a>
                <a class="nav-cta" href="register.php">Get Started</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<?php if ($role !== ''): ?>
<script>
// Check for new notifications every 30 seconds.
(function () {
    var badge = document.getElementById('bell-count');
    if (!badge || !window.fetch) { return; }
    function refresh() {
        fetch('notifications_count.php', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d) { return; }
                badge.textContent = d.unread > 99 ? '99+' : d.unread;
                badge.hidden = d.unread < 1;
            })
            .catch(function () {});
    }
    setInterval(refresh, 30000);
})();
</script>
<?php endif; ?>