<?php
session_start();
require_once 'db.php';
require_once 'config.php';
require_once 'fees.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'worker') {
    header('Location: login.php');
    exit;
}
$worker = fetch_worker($pdo, (int) $_SESSION['user_id']);
if (!$worker) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}
$verified = $worker['verification_status'] === 'verified';

$requests = [];
$openRider = [];
$itemsBy = [];
release_expired_acceptances($pdo);   // errands accepted but not started in time are re-opened first
if ($verified && (int) $worker['is_available'] === 1) {
    // Pending errands (a worker released from an errand does not see it again).
    $stmt = $pdo->prepare("SELECT sr.id, sr.service_type, sr.description, sr.pickup_area, sr.pickup_location,
                                  sr.dropoff_area, sr.dropoff_location, sr.preferred_date, sr.preferred_time, sr.created_at, sr.worker_pay,
                                  sr.needs_purchase, sr.item_cost_est, sr.weight_kg, sr.weight_desc, sr.distance_km, sr.is_bulk, sr.riders_needed,
                                  u.full_name AS customer_name
                           FROM service_requests sr
                           INNER JOIN users u ON sr.user_id = u.id
                           WHERE sr.status = 'pending' AND sr.worker_id IS NULL
                             AND NOT EXISTS (SELECT 1 FROM request_releases rl WHERE rl.request_id = sr.id AND rl.worker_id = ?)
                           ORDER BY sr.preferred_date ASC, sr.preferred_time ASC");
    $stmt->execute([$worker['id']]);
    // Show only errands this worker is eligible for (capability + vehicle + weight).
    $requests = array_values(array_filter($stmt->fetchAll(), fn($r) => worker_can_do($worker, $r['service_type'], $r['weight_kg'] !== null ? (float) $r['weight_kg'] : null)));

    // Bulk errands whose lead rider already accepted and that still need another rider.
    $stmt = $pdo->prepare("SELECT sr.id, sr.service_type, sr.description, sr.pickup_area, sr.pickup_location,
                                  sr.dropoff_area, sr.dropoff_location, sr.preferred_date, sr.preferred_time, sr.status,
                                  sr.needs_purchase, sr.weight_kg, sr.weight_desc, sr.distance_km, sr.riders_needed, sr.extra_rider_fee,
                                  u.full_name AS customer_name, lw.full_name AS lead_name,
                                  (SELECT COUNT(*) FROM request_riders rr WHERE rr.request_id = sr.id AND rr.status = 'active') AS joined
                           FROM service_requests sr
                           INNER JOIN users u ON sr.user_id = u.id
                           LEFT JOIN users lw ON lw.id = sr.worker_id
                           WHERE sr.status IN ('accepted','in_progress') AND sr.worker_id IS NOT NULL AND sr.worker_id <> ?
                             AND sr.riders_needed > 1
                             AND NOT EXISTS (SELECT 1 FROM request_riders me WHERE me.request_id = sr.id AND me.worker_id = ?)
                             AND NOT EXISTS (SELECT 1 FROM request_releases rl WHERE rl.request_id = sr.id AND rl.worker_id = ?)
                           ORDER BY sr.preferred_date ASC, sr.preferred_time ASC");
    $stmt->execute([$worker['id'], $worker['id'], $worker['id']]);
    $pricingAll = get_pricing($pdo);
    foreach ($stmt->fetchAll() as $r) {
        if ((1 + (int) $r['joined']) >= (int) $r['riders_needed']) { continue; }
        if (!worker_can_do($worker, $r['service_type'], $r['weight_kg'] !== null ? (float) $r['weight_kg'] : null)) { continue; }
        $share = $pricingAll[$r['service_type']]['worker_share_pct'] ?? 80.0;
        $r['rider_pay'] = extra_rider_pay((float) ($r['extra_rider_fee'] ?? 0), (float) $share);
        $openRider[] = $r;
    }

    $ids = array_merge(array_map(fn($r) => (int) $r['id'], $requests), array_map(fn($r) => (int) $r['id'], $openRider));
    if ($ids) {
        $in = implode(',', $ids);   // integers only
        foreach ($pdo->query("SELECT request_id, item_name, quantity, specification FROM errand_items WHERE request_id IN ($in) ORDER BY id")->fetchAll() as $it) {
            $itemsBy[(int) $it['request_id']][] = $it;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Worker Dashboard | ErrandGo</title>
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/theme.css">
</head>
<body>
<?php include 'partials/header.php'; ?>
<div class="container">
    <?php if (!empty($_SESSION['worker_message'])): ?>
        <div class="worker-message"><?= e($_SESSION['worker_message']) ?></div>
        <?php unset($_SESSION['worker_message']); ?>
    <?php endif; ?>

    <section class="welcome">
        <h2>Welcome, <?= e($_SESSION['user_name'] ?? 'Worker') ?>!</h2>
        <p>Browse pending errands that match your services and vehicle.</p>
    </section>

    <?php if ($worker['verification_status'] === 'pending'): ?>
        <div class="notice"><strong>Verification pending.</strong> ErrandGo management is reviewing your account. You can accept errands once you are verified.</div>
    <?php elseif ($worker['verification_status'] === 'rejected'): ?>
        <div class="error"><strong>Verification not approved.</strong> Please contact ErrandGo customer service.</div>
    <?php elseif ((int) $worker['is_available'] === 0): ?>
        <div class="notice">You are currently <strong>unavailable</strong>. Turn availability on in your <a href="worker_profile.php">profile</a> to see errands.</div>
    <?php endif; ?>

    <a href="my_accepted_errands.php" class="button">View My Accepted Errands</a>

    <h2>Available Errand Requests</h2>
    <?php if (count($requests) > 0): ?>
        <?php foreach ($requests as $request): $rid = (int) $request['id']; ?>
            <div class="request-card">
                <h3 style="margin-top:0"><?= e($request['service_type']) ?> <span class="status">Pending</span>
                    <?php if ($request['is_bulk']): ?><span class="status accepted">Bulk</span><?php endif; ?></h3>
                <p class="detail"><span class="label">Customer:</span> <?= e($request['customer_name']) ?></p>
                <p class="detail"><span class="label">Errand specification:</span> <?= nl2br(e($request['description'])) ?></p>
                <?php if ($request['needs_purchase'] && !empty($itemsBy[$rid])): ?>
                    <div class="detail"><span class="label">Items to buy:</span>
                        <ul class="item-list">
                            <?php foreach ($itemsBy[$rid] as $it): ?>
                                <li><?= (int) $it['quantity'] ?> &times; <?= e($it['item_name']) ?><?= $it['specification'] ? ' <span class="small">(' . e($it['specification']) . ')</span>' : '' ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if ($request['item_cost_est'] !== null): ?><span class="small">Customer's estimate: <?= e(peso($request['item_cost_est'])) ?> (reimbursed at the actual price)</span><?php endif; ?>
                    </div>
                <?php endif; ?>
                <p class="detail"><span class="label">Pickup:</span> <?= e($request['pickup_location']) ?><?= $request['pickup_area'] ? ' (' . e($request['pickup_area']) . ')' : '' ?></p>
                <p class="detail"><span class="label">Drop-off:</span>
                    <?php if ($request['dropoff_area'] || $request['dropoff_location']): ?>
                        <?= e((string) $request['dropoff_location']) ?><?= $request['dropoff_area'] ? ' (' . e($request['dropoff_area']) . ')' : '' ?>
                        <?= $request['distance_km'] !== null ? '<span class="small">&middot; about ' . e(number_format((float) $request['distance_km'], 1)) . ' km</span>' : '' ?>
                    <?php else: ?>Not specified<?php endif; ?></p>
                <?php if ($request['weight_kg'] !== null): ?>
                    <p class="detail"><span class="label">Weight:</span> <?= e(rtrim(rtrim(number_format((float) $request['weight_kg'], 1), '0'), '.')) ?> kg<?= $request['weight_desc'] ? ' &middot; ' . e($request['weight_desc']) : '' ?></p>
                <?php endif; ?>
                <p class="detail"><span class="label">Preferred:</span> <?= e($request['preferred_date']) ?>, <?= e(date('h:i A', strtotime($request['preferred_time']))) ?></p>
                <?php if ((int) $request['riders_needed'] > 1): ?>
                    <p class="detail"><span class="label">Riders needed:</span> <?= (int) $request['riders_needed'] ?> <span class="small">(you would be the lead rider; others can join after you accept)</span></p>
                <?php endif; ?>
                <?php if ($request['worker_pay'] !== null): ?>
                    <p class="detail"><span class="label">You earn:</span> <strong><?= e(peso($request['worker_pay'])) ?></strong> <span class="small">(plus the actual cost of any items you buy, paid back by the customer)</span></p>
                <?php endif; ?>
                <form method="POST" action="accept_request.php" onsubmit="return confirm('Accept this errand? You must start it within <?= (int) ACCEPT_TIMEOUT_MINUTES ?> minutes or it will be given to another worker.');">
                    <input type="hidden" name="request_id" value="<?= $rid ?>">
                    <button type="submit" class="accept-button">Accept Request</button>
                </form>
            </div>
        <?php endforeach; ?>
    <?php elseif ($verified && (int) $worker['is_available'] === 1): ?>
        <div class="empty">
            <h3>No matching requests yet</h3>
            <p>New errands that fit your services and vehicle will appear here.</p>
        </div>
    <?php endif; ?>

    <?php if (count($openRider) > 0): ?>
        <h2>Additional Rider Needed</h2>
        <p class="small">The customer asked for more than one rider on these bulk errands. Join as an additional rider; the lead rider keeps control of the status.</p>
        <?php foreach ($openRider as $request): $rid = (int) $request['id']; ?>
            <div class="request-card">
                <h3 style="margin-top:0"><?= e($request['service_type']) ?> <span class="status accepted">Rider slot open</span></h3>
                <p class="detail"><span class="label">Request:</span> #<?= $rid ?> &middot; <?= e($request['customer_name']) ?> &middot; lead rider: <?= e($request['lead_name'] ?? '—') ?></p>
                <p class="detail"><span class="label">Errand specification:</span> <?= nl2br(e($request['description'])) ?></p>
                <?php if ($request['needs_purchase'] && !empty($itemsBy[$rid])): ?>
                    <div class="detail"><span class="label">Items to buy:</span>
                        <ul class="item-list">
                            <?php foreach ($itemsBy[$rid] as $it): ?>
                                <li><?= (int) $it['quantity'] ?> &times; <?= e($it['item_name']) ?><?= $it['specification'] ? ' <span class="small">(' . e($it['specification']) . ')</span>' : '' ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <p class="detail"><span class="label">Pickup:</span> <?= e($request['pickup_location']) ?><?= $request['pickup_area'] ? ' (' . e($request['pickup_area']) . ')' : '' ?></p>
                <p class="detail"><span class="label">Drop-off:</span> <?= $request['dropoff_area'] || $request['dropoff_location'] ? e((string) $request['dropoff_location']) . ($request['dropoff_area'] ? ' (' . e($request['dropoff_area']) . ')' : '') : 'Not specified' ?></p>
                <?php if ($request['weight_kg'] !== null): ?>
                    <p class="detail"><span class="label">Weight:</span> <?= e(rtrim(rtrim(number_format((float) $request['weight_kg'], 1), '0'), '.')) ?> kg<?= $request['weight_desc'] ? ' &middot; ' . e($request['weight_desc']) : '' ?></p>
                <?php endif; ?>
                <p class="detail"><span class="label">Preferred:</span> <?= e($request['preferred_date']) ?>, <?= e(date('h:i A', strtotime($request['preferred_time']))) ?></p>
                <p class="detail"><span class="label">Riders:</span> <?= 1 + (int) $request['joined'] ?> of <?= (int) $request['riders_needed'] ?> confirmed</p>
                <p class="detail"><span class="label">You earn:</span> <strong><?= e(peso($request['rider_pay'])) ?></strong></p>
                <form method="POST" action="join_request.php" onsubmit="return confirm('Join this errand as an additional rider?');">
                    <input type="hidden" name="request_id" value="<?= $rid ?>">
                    <button type="submit" class="accept-button">Join as Additional Rider</button>
                </form>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php include 'partials/footer.php'; ?>
</body>
</html>
