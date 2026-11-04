<?php
session_start();
require_once 'db.php';
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'worker') {
    header('Location: login.php');
    exit;
}
$worker = fetch_worker($pdo, (int) $_SESSION['user_id']);
$msg = '';
$err = '';

if ($worker && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $vehicle = $_POST['vehicle_type'] ?? '';
    $caps = array_values(array_intersect(SERVICES, (array) ($_POST['capabilities'] ?? [])));
    $available = isset($_POST['is_available']) ? 1 : 0;
    $phone = trim($_POST['phone'] ?? '');
    if (!in_array($vehicle, VEHICLE_TYPES, true) || count($caps) === 0 || strlen($phone) > 20) {
        $err = 'Please choose a vehicle, at least one service, and a phone number of 20 characters or fewer.';
    } else {
        $pdo->prepare('UPDATE users SET vehicle_type = ?, capabilities = ?, is_available = ?, phone = ? WHERE id = ? AND role = \'worker\'')
            ->execute([$vehicle, implode(',', $caps), $available, $phone !== '' ? $phone : null, $worker['id']]);
        audit_log($pdo, 'WORKER_PROFILE_UPDATED', 'Worker profile updated: vehicle ' . $vehicle . ', services ' . implode(', ', $caps) . ', ' . ($available ? 'available' : 'unavailable') . '.', 'user', (int) $worker['id']);
        $msg = 'Your worker profile was updated.';
        $worker = fetch_worker($pdo, (int) $_SESSION['user_id']);
    }
}
$phoneRow = $pdo->prepare('SELECT phone, email FROM users WHERE id = ?');
$phoneRow->execute([$_SESSION['user_id']]);
$contact = $phoneRow->fetch();
$myCaps = array_filter(array_map('trim', explode(',', (string) $worker['capabilities'])));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Worker Profile | ErrandGo</title>
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/theme.css">
</head>
<body>
<?php include 'partials/header.php'; ?>
<div class="container narrow">
    <div class="panel">
        <h1 class="page-banner">Worker Profile</h1>
        <p><?= e($worker['full_name']) ?> &middot; <?= e($contact['email']) ?>
            <span class="status <?= e($worker['verification_status'] ?? 'pending') ?>"><?= e(status_label($worker['verification_status'] ?? 'pending')) ?></span></p>
        <?php if ($msg): ?><div class="success"><?= e($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="error"><?= e($err) ?></div><?php endif; ?>
        <form method="POST">
            <label for="phone">Phone Number</label>
            <input type="tel" id="phone" name="phone" maxlength="20" value="<?= e($contact['phone'] ?? '') ?>">

            <label for="vehicle_type">Vehicle</label>
            <select id="vehicle_type" name="vehicle_type">
                <?php foreach (VEHICLE_TYPES as $v): ?>
                    <option value="<?= e($v) ?>" <?= $v === $worker['vehicle_type'] ? 'selected' : '' ?>><?= e($v) ?></option>
                <?php endforeach; ?>
            </select>

            <label>Services you can perform</label>
            <?php foreach (SERVICES as $s): ?>
                <label style="font-weight:normal;margin:4px 0;display:flex;gap:8px;align-items:center">
                    <input type="checkbox" style="width:auto" name="capabilities[]" value="<?= e($s) ?>" <?= in_array($s, $myCaps, true) ? 'checked' : '' ?>> <?= e($s) ?>
                </label>
            <?php endforeach; ?>
            <p class="small">Moving Assistance needs a Car, Van, or Truck.</p>

            <label style="font-weight:normal;display:flex;gap:8px;align-items:center">
                <input type="checkbox" style="width:auto" name="is_available" <?= (int) $worker['is_available'] === 1 ? 'checked' : '' ?>> <strong>I'm available for errands</strong>
            </label>

            <button type="submit" class="primary">Save Profile</button>
            <a class="back-link" href="worker_dashboard.php">Back</a>
        </form>
    </div>
</div>
<?php include 'partials/footer.php'; ?>
</body>
</html>
