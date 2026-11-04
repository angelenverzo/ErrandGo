<?php
session_start();
require_once 'db.php';
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
    $id = filter_input(INPUT_POST, 'worker_id', FILTER_VALIDATE_INT);
    $decision = $_POST['decision'] ?? '';
    if ($id && in_array($decision, ['verified', 'rejected', 'pending'], true)) {
        $pdo->prepare("UPDATE users SET verification_status = ? WHERE id = ? AND role = 'worker'")->execute([$decision, $id]);
        $msg = 'Worker status updated to ' . $decision . '.';
        audit_log($pdo, 'WORKER_' . strtoupper($decision), "Admin set worker #$id verification to $decision.", 'user', $id);
        $txt = ['verified' => 'Your worker account was verified. You can now accept errands.',
                'rejected' => 'Your worker account was not approved. Please contact ErrandGo customer service.',
                'pending'  => 'Your worker account was set back to pending review.'];
        notify($pdo, $id, $txt[$decision], 'worker_dashboard.php');
    }
}

$workers = $pdo->query("SELECT u.id, u.full_name, u.email, u.phone, u.vehicle_type, u.capabilities, u.verification_status,
                               u.is_available, u.created_at,
                               (SELECT COUNT(*) FROM service_requests sr WHERE sr.worker_id = u.id AND sr.status = 'completed') AS done
                        FROM users u WHERE u.role = 'worker' ORDER BY FIELD(u.verification_status, 'pending', 'verified', 'rejected'), u.created_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Worker Verification | ErrandGo</title>
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/theme.css">
</head>
<body>
<?php include 'partials/header.php'; ?>
<div class="container">
    <h1 class="page-banner">Worker Verification</h1>
    <p>Review worker accounts. Only <strong>verified</strong> workers can accept errands.</p>
    <?php if ($msg): ?><div class="success"><?= e($msg) ?></div><?php endif; ?>
    <div class="panel table-wrap">
        <table class="data">
            <tr><th>Worker</th><th>Contact</th><th>Vehicle</th><th>Services</th><th>Done</th><th>Status</th><th>Action</th></tr>
            <?php foreach ($workers as $w): $st = $w['verification_status'] ?? 'pending'; ?>
                <tr>
                    <td><?= e($w['full_name']) ?></td>
                    <td><?= e($w['email']) ?><br><span class="small"><?= e($w['phone'] ?? '') ?></span></td>
                    <td><?= e($w['vehicle_type'] ?? '—') ?></td>
                    <td class="small"><?= e(str_replace(',', ', ', (string) $w['capabilities'])) ?: 'All' ?></td>
                    <td><?= (int) $w['done'] ?></td>
                    <td><span class="status <?= e($st) ?>"><?= e(status_label($st)) ?></span></td>
                    <td>
                        <form method="POST" class="inline">
                            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                            <input type="hidden" name="worker_id" value="<?= (int) $w['id'] ?>">
                            <?php if ($st !== 'verified'): ?><button name="decision" value="verified" class="primary" style="margin:0;padding:6px 12px">Verify</button><?php endif; ?>
                            <?php if ($st !== 'rejected'): ?><button name="decision" value="rejected" class="danger" style="margin:0;padding:6px 12px">Reject</button><?php endif; ?>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$workers): ?><tr><td colspan="7" class="small">No worker accounts yet.</td></tr><?php endif; ?>
        </table>
    </div>
</div>
<?php include 'partials/footer.php'; ?>
</body>
</html>
