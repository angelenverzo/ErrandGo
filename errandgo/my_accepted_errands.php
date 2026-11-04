<?php
session_start();
require_once 'db.php';
require_once 'config.php';

// Allow only logged-in workers
if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['user_role'] ?? '') !== 'worker'
) {
    header('Location: login.php');
    exit;
}
$me = (int) $_SESSION['user_id'];
release_expired_acceptances($pdo);   // errands not started within the time limit go back to other workers

// Errands where this worker is the lead rider OR an additional rider.
$mins = (int) ACCEPT_TIMEOUT_MINUTES;
$stmt = $pdo->prepare("
    SELECT sr.id, sr.service_type, sr.description, sr.pickup_area, sr.pickup_location, sr.dropoff_area, sr.dropoff_location,
           sr.preferred_date, sr.preferred_time, sr.status, sr.worker_pay, sr.created_at, sr.needs_purchase, sr.item_cost_est,
           sr.weight_kg, sr.weight_desc, sr.distance_km, sr.riders_needed, sr.worker_id,
           u.full_name AS customer_name, u.phone AS customer_phone,
           lw.full_name AS lead_name, rr.pay AS rider_pay,
           IF(sr.status = 'accepted' AND sr.worker_id = ?,
              GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), sr.accepted_at + INTERVAL $mins MINUTE)), NULL) AS secs_left
    FROM service_requests sr
    INNER JOIN users u ON sr.user_id = u.id
    LEFT JOIN users lw ON lw.id = sr.worker_id
    LEFT JOIN request_riders rr ON rr.request_id = sr.id AND rr.worker_id = ? AND rr.status = 'active'
    WHERE sr.worker_id = ? OR rr.id IS NOT NULL
    ORDER BY sr.created_at DESC
");
$stmt->execute([$me, $me, $me]);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$itemsBy = [];
if ($requests) {
    $in = implode(',', array_map(fn($r) => (int) $r['id'], $requests));   // integers only
    foreach ($pdo->query("SELECT request_id, item_name, quantity, specification FROM errand_items WHERE request_id IN ($in) ORDER BY id")->fetchAll() as $it) {
        $itemsBy[(int) $it['request_id']][] = $it;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Accepted Errands | ErrandGo</title>
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

    <h2 class="page-banner">
        Welcome, <?= htmlspecialchars($_SESSION['user_name'] ?? 'Worker', ENT_QUOTES, 'UTF-8') ?>!
    </h2>

    <p>View the errands assigned to your worker account.</p>

    <?php if (count($requests) > 0): ?>

        <?php foreach ($requests as $request): $rid = (int) $request['id']; $lead = (int) $request['worker_id'] === $me; ?>
            <div class="request-card">

                <h3>
                    <?= htmlspecialchars($request['service_type'], ENT_QUOTES, 'UTF-8') ?>
                    <?php if (!$lead): ?><span class="status accepted">Additional rider</span><?php endif; ?>
                </h3>

                <p>
                    <span class="label">Request ID:</span>
                    <?= $rid ?>
                    <?php if (!$lead && $request['lead_name']): ?><span class="small">&middot; lead rider: <?= e($request['lead_name']) ?></span><?php endif; ?>
                </p>

                <p>
                    <span class="label">Customer:</span>
                    <?= htmlspecialchars($request['customer_name'], ENT_QUOTES, 'UTF-8') ?>
                </p>

                <?php if (!empty($request['customer_phone'])): ?>
                    <p>
                        <span class="label">Customer phone:</span>
                        <?= htmlspecialchars($request['customer_phone'], ENT_QUOTES, 'UTF-8') ?>
                    </p>
                <?php endif; ?>

                <p>
                    <span class="label">Errand specification:</span><br>
                    <?= nl2br(htmlspecialchars($request['description'], ENT_QUOTES, 'UTF-8')) ?>
                </p>

                <?php if ($request['needs_purchase'] && !empty($itemsBy[$rid])): ?>
                    <div>
                        <span class="label">Items to buy:</span>
                        <ul class="item-list">
                            <?php foreach ($itemsBy[$rid] as $it): ?>
                                <li><?= (int) $it['quantity'] ?> &times; <?= e($it['item_name']) ?><?= $it['specification'] ? ' <span class="small">(' . e($it['specification']) . ')</span>' : '' ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if ($request['item_cost_est'] !== null): ?><span class="small">Customer's estimate: <?= e(peso($request['item_cost_est'])) ?> (reimbursed at the actual price)</span><?php endif; ?>
                    </div>
                <?php endif; ?>

                <p>
                    <span class="label">Pickup location:</span>
                    <?= htmlspecialchars($request['pickup_location'], ENT_QUOTES, 'UTF-8') ?><?= $request['pickup_area'] ? ' (' . e($request['pickup_area']) . ')' : '' ?>
                </p>

                <p>
                    <span class="label">Drop-off location:</span>
                    <?php if ($request['dropoff_area'] || $request['dropoff_location']): ?>
                        <?= e((string) $request['dropoff_location']) ?><?= $request['dropoff_area'] ? ' (' . e($request['dropoff_area']) . ')' : '' ?>
                        <?= $request['distance_km'] !== null ? '<span class="small">&middot; about ' . e(number_format((float) $request['distance_km'], 1)) . ' km</span>' : '' ?>
                    <?php else: ?>Not specified<?php endif; ?>
                </p>

                <?php if ($request['weight_kg'] !== null): ?>
                    <p>
                        <span class="label">Weight:</span>
                        <?= e(rtrim(rtrim(number_format((float) $request['weight_kg'], 1), '0'), '.')) ?> kg<?= $request['weight_desc'] ? ' &middot; ' . e($request['weight_desc']) : '' ?>
                    </p>
                <?php endif; ?>

                <p>
                    <span class="label">Preferred date:</span>
                    <?= htmlspecialchars($request['preferred_date'], ENT_QUOTES, 'UTF-8') ?>
                </p>

                <p>
                    <span class="label">Preferred time:</span>
                    <?= htmlspecialchars(date('h:i A', strtotime($request['preferred_time'])), ENT_QUOTES, 'UTF-8') ?>
                </p>

                <?php
                $pay = $lead ? $request['worker_pay'] : $request['rider_pay'];
                if ($pay !== null): ?>
                <p>
                    <span class="label"><?= $lead ? 'Your pay' : 'Your pay (additional rider)' ?>:</span>
                    <strong><?= e(peso($pay)) ?></strong>
                    <span class="small">(plus the actual cost of any items you buy)</span>
                </p>
                <?php endif; ?>

<p>
    <span class="label">Status:</span>
    <span class="status <?= e($request['status']) ?>"><?= e(status_label($request['status'])) ?></span>
</p>

<?php if ($lead && $request['status'] === 'accepted' && $request['secs_left'] !== null): ?>
    <p class="countdown-box">
        Start this errand within <strong class="countdown" data-secs="<?= (int) $request['secs_left'] ?>"><?= gmdate('i:s', (int) $request['secs_left']) ?></strong>
        or it will be released to other workers.
    </p>
<?php endif; ?>

<?php if ($lead && $request['status'] === 'accepted'): ?>

    <form method="POST" action="update_request_status.php"
          onsubmit="return confirm('Start this errand now?');">

        <input type="hidden" name="request_id"
               value="<?= $rid ?>">

        <input type="hidden" name="status" value="in_progress">

        <button type="submit" class="action-button">
            Start Errand
        </button>
    </form>

<?php elseif ($lead && $request['status'] === 'in_progress'): ?>

    <form method="POST" action="update_request_status.php"
          onsubmit="return confirm('Mark this errand as completed?');">

        <input type="hidden" name="request_id"
               value="<?= $rid ?>">

        <input type="hidden" name="status" value="completed">

        <button type="submit" class="action-button">
            Mark as Completed
        </button>
    </form>

<?php elseif ($request['status'] === 'completed'): ?>

    <p class="completed-message">
        This errand has been completed.
    </p>

<?php elseif (!$lead): ?>

    <p class="small">The lead rider updates the status of this errand.</p>

<?php endif; ?>

            </div>
        <?php endforeach; ?>

    <?php else: ?>

        <div class="empty">
            <h3>No assigned errands yet</h3>
            <p>Errands you accept will appear here.</p>
        </div>

    <?php endif; ?>

</div>

<?php include 'partials/footer.php'; ?>
<script>
// Countdown until an accepted errand is released to other workers.
(function () {
    var els = document.querySelectorAll('.countdown');
    if (!els.length) { return; }
    function fmt(s) { var m = Math.floor(s / 60), r = s % 60; return (m < 10 ? '0' : '') + m + ':' + (r < 10 ? '0' : '') + r; }
    var left = [], reloading = false;
    els.forEach(function (el, i) { left[i] = parseInt(el.getAttribute('data-secs'), 10) || 0; });
    setInterval(function () {
        els.forEach(function (el, i) {
            if (left[i] > 0) { left[i]--; el.textContent = fmt(left[i]); }
            else { el.textContent = '00:00'; }
        });
        // When every countdown has ended, reload so the released errand disappears from the list.
        if (!reloading && left.every(function (v) { return v <= 0; })) {
            reloading = true;
            setTimeout(function () { location.reload(); }, 2500);
        }
    }, 1000);
})();
</script>
</body>
</html>
