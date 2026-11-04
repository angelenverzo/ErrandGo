<?php
session_start();
require_once 'db.php';
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'customer') {
    header('Location: login.php');
    exit;
}
release_expired_acceptances($pdo);   // keep the list up to date (errands not started in time are re-opened)

$sql = "SELECT sr.*, u.full_name AS worker_name, u.vehicle_type AS worker_vehicle,
               r.rating, r.comment_category, r.tags AS rating_tags,
               (SELECT COUNT(*) FROM request_riders rr WHERE rr.request_id = sr.id AND rr.status = 'active') AS riders_joined
        FROM service_requests sr
        LEFT JOIN users u ON sr.worker_id = u.id
        LEFT JOIN ratings r ON r.request_id = sr.id
        WHERE sr.user_id = ?
        ORDER BY sr.created_at DESC, sr.id ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$_SESSION['user_id']]);
$requests = $stmt->fetchAll();

// Item specifications of all these requests.
$itemsBy = [];
if ($requests) {
    $ids = array_map(fn($r) => (int) $r['id'], $requests);
    $in = implode(',', $ids);   // integers only
    foreach ($pdo->query("SELECT request_id, item_name, quantity, specification FROM errand_items WHERE request_id IN ($in) ORDER BY id")->fetchAll() as $it) {
        $itemsBy[(int) $it['request_id']][] = $it;
    }
}

// Group errands that were requested together.
$groups = [];
foreach ($requests as $r) {
    $key = $r['batch_id'] ? 'b' . $r['batch_id'] : 'r' . $r['id'];
    $groups[$key][] = $r;
}

$flash = null;
$cancel = $_GET['cancel'] ?? '';
if ($cancel === 'success') { $flash = ['success', 'Your errand request has been cancelled successfully.']; }
elseif ($cancel === 'failed') { $flash = ['error', 'Unable to cancel this request. It may already be cancelled or no longer pending.']; }
elseif ($cancel !== '') { $flash = ['error', 'Invalid request. Please try again.']; }
$rated = $_GET['rated'] ?? '';
if ($rated === 'success') { $flash = ['success', 'Thank you! Your rating was saved.']; }
elseif ($rated === 'failed') { $flash = ['error', 'Unable to save the rating. It may already be rated.']; }
$rider = $_GET['rider'] ?? '';
if ($rider === 'success') { $flash = ['success', 'Another rider was requested. Available workers have been notified.']; }
elseif ($rider === 'failed') { $flash = ['error', 'Unable to add a rider. The request must be a bulk errand that is not completed or cancelled, and the rider limit may be reached.']; }

$steps = ['pending' => 1, 'accepted' => 2, 'in_progress' => 3, 'completed' => 4];
$labels = ['Pending', 'Accepted', 'In Progress', 'Completed'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Requests | ErrandGo</title>
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/theme.css">
</head>
<body>
<?php include 'partials/header.php'; ?>
<div class="container">
    <h1 class="page-banner">My Errand Requests</h1>
    <p>Hello, <?= e($_SESSION['user_name']) ?>! Here are your submitted requests and their current status.</p>
    <?php if ($flash): ?><div class="<?= $flash[0] ?>"><?= e($flash[1]) ?></div><?php endif; ?>
    <a class="button primary" href="request_errand.php" style="margin:0 0 18px">+ New Request</a>

    <?php if (count($groups) > 0): ?>
        <?php foreach ($groups as $group): $isBooking = count($group) > 1; ?>
            <?php if ($isBooking): ?>
                <div class="booking-head">
                    <strong>Booking #<?= (int) $group[0]['batch_id'] ?></strong> &mdash; <?= count($group) ?> errands requested together
                    <span class="small">&middot; <?= e($group[0]['preferred_date']) ?>, <?= e(date('g:i A', strtotime($group[0]['preferred_time']))) ?></span>
                </div>
            <?php endif; ?>
            <?php foreach ($group as $request): $rid = (int) $request['id']; ?>
            <div class="request-card">
                <h2 style="margin-top:0">Request #<?= $rid ?> &mdash; <?= e($request['service_type']) ?>
                    <?php if ($request['is_bulk']): ?><span class="status accepted">Bulk</span><?php endif; ?></h2>
                <span class="status <?= e($request['status']) ?>"><?= e(status_label($request['status'])) ?></span>

                <?php if ($request['status'] === 'cancelled'): ?>
                    <div class="tracking-cancelled">This request has been cancelled.</div>
                <?php else: ?>
                    <?php $current = $steps[$request['status']] ?? 1; ?>
                    <div class="tracking">
                        <?php foreach ($labels as $i => $label): $n = $i + 1; ?>
                            <div class="tracking-step <?= $n <= $current ? 'active' : '' ?>">
                                <div class="tracking-dot"><?= $n <= $current ? '✓' : $n ?></div>
                                <div><?= e($label) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

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
                <p class="detail"><span class="label">Drop-off:</span>
                    <?php if ($request['dropoff_area'] || $request['dropoff_location']): ?>
                        <?= e((string) $request['dropoff_location']) ?><?= $request['dropoff_area'] ? ' (' . e($request['dropoff_area']) . ')' : '' ?>
                    <?php else: ?>Not specified<?php endif; ?></p>
                <?php if ($request['weight_kg'] !== null): ?>
                    <p class="detail"><span class="label">Weight:</span> <?= e(rtrim(rtrim(number_format((float) $request['weight_kg'], 1), '0'), '.')) ?> kg<?= $request['weight_desc'] ? ' &middot; ' . e($request['weight_desc']) : '' ?></p>
                <?php endif; ?>
                <p class="detail"><span class="label">Preferred:</span> <?= e($request['preferred_date']) ?>, <?= e(date('g:i A', strtotime($request['preferred_time']))) ?></p>

                <?php if ($request['service_fee'] !== null): ?>
                    <div class="detail"><span class="label">Fees:</span>
                        <div class="fee-lines">
                            <div class="live-row"><span>Base rate (service <?= e(peso($request['service_fee'])) ?> + delivery <?= e(peso($request['delivery_fee'])) ?>)</span><span><?= e(peso($request['service_fee'] + $request['delivery_fee'])) ?></span></div>
                            <?php if ($request['distance_fee'] !== null && $request['distance_km'] !== null): ?>
                                <div class="live-row"><span>Distance (about <?= e(number_format((float) $request['distance_km'], 1)) ?> km &times; <?= e(peso($request['per_km_fee'])) ?>)</span><span><?= e(peso($request['distance_fee'])) ?></span></div>
                            <?php endif; ?>
                            <?php if ($request['weight_fee'] !== null && (float) $request['weight_fee'] > 0): ?>
                                <div class="live-row"><span>Weight fee</span><span><?= e(peso($request['weight_fee'])) ?></span></div>
                            <?php endif; ?>
                            <?php if ((float) $request['extra_riders_fee'] > 0): ?>
                                <div class="live-row"><span>Extra rider<?= (int) $request['riders_needed'] > 2 ? 's (' . ((int) $request['riders_needed'] - 1) . ')' : '' ?></span><span><?= e(peso($request['extra_riders_fee'])) ?></span></div>
                            <?php endif; ?>
                            <?php if ($request['needs_purchase'] && $request['item_cost_est'] !== null): ?>
                                <div class="live-row"><span>Estimated item cost (paid at actual price)</span><span><?= e(peso($request['item_cost_est'])) ?></span></div>
                            <?php endif; ?>
                            <div class="live-row"><strong>Estimated total</strong><strong><?= e(peso($request['est_total'])) ?></strong></div>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($request['payment_method']): ?>
                    <p class="detail"><span class="label">Payment preference:</span> <?= e($request['payment_method']) ?></p>
                <?php endif; ?>
                <?php if ($request['worker_name']): ?>
                    <p class="detail"><span class="label">Worker:</span> <?= e($request['worker_name']) ?> <span class="status verified">Verified</span>
                        <?= $request['worker_vehicle'] ? '· ' . e($request['worker_vehicle']) : '' ?></p>
                <?php endif; ?>
                <?php if ((int) $request['riders_needed'] > 1): ?>
                    <p class="detail"><span class="label">Riders:</span> <?= 1 + (int) $request['riders_joined'] ?> of <?= (int) $request['riders_needed'] ?> confirmed
                        <?= $request['status'] === 'pending' ? '<span class="small">(extra riders can join once a lead rider accepts)</span>' : '' ?></p>
                <?php endif; ?>
                <p class="detail small">Submitted: <?= e($request['created_at']) ?></p>

                <?php if (in_array($request['status'], ['pending', 'accepted', 'in_progress'], true) && $request['is_bulk'] && (int) $request['riders_needed'] < MAX_RIDERS): ?>
                    <form action="add_rider.php" method="POST" class="inline" onsubmit="return confirm('Add another rider to this bulk errand? An extra-rider fee will be added to your estimated total.');">
                        <input type="hidden" name="request_id" value="<?= $rid ?>">
                        <button type="submit">+ Add another rider</button>
                    </form>
                <?php endif; ?>

                <?php if ($request['status'] === 'pending'): ?>
                    <form action="cancel_request.php" method="POST" class="inline" onsubmit="return confirm('Are you sure you want to cancel this request?');">
                        <input type="hidden" name="request_id" value="<?= $rid ?>">
                        <button type="submit" class="danger">Cancel Request</button>
                    </form>
                <?php elseif ($request['status'] === 'completed'): ?>
                    <?php if ($request['rating']): ?>
                        <p class="detail"><span class="label">Your rating:</span>
                            <span class="stars"><?= str_repeat('★', (int) $request['rating']) . str_repeat('☆', 5 - (int) $request['rating']) ?></span>
                            <?php
                            $shown = $request['rating_tags'] ?: ($request['comment_category'] ?? '');
                            echo $shown ? '· ' . e(str_replace(',', ', ', $shown)) : '';
                            ?></p>
                    <?php else: ?>
                        <form action="rate_request.php" method="POST" class="panel rate-form" style="margin-top:14px;background:var(--pink-soft)">
                            <strong>How was the service?</strong>
                            <input type="hidden" name="request_id" value="<?= $rid ?>">
                            <p class="small" style="margin:6px 0 2px">Tap a star to rate your worker.</p>
                            <div class="star-rating" role="radiogroup" aria-label="Rating from 1 to 5 stars">
                                <?php for ($s = 5; $s >= 1; $s--): ?>
                                    <input type="radio" id="star<?= $rid ?>_<?= $s ?>" name="rating" value="<?= $s ?>" required>
                                    <label for="star<?= $rid ?>_<?= $s ?>" title="<?= $s ?> star<?= $s > 1 ? 's' : '' ?>">&#9733;</label>
                                <?php endfor; ?>
                            </div>
                            <div class="star-caption small" aria-live="polite">&nbsp;</div>

                            <div class="tag-group"><span class="label">What went well?</span>
                                <div class="tag-grid">
                                    <?php foreach (RATING_TAGS_GOOD as $ti => $tag): ?>
                                        <label class="choice tag"><input type="checkbox" name="tags[]" value="<?= e($tag) ?>"> <?= e($tag) ?></label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="tag-group"><span class="label">What could be better?</span>
                                <div class="tag-grid">
                                    <?php foreach (RATING_TAGS_BAD as $tag): ?>
                                        <label class="choice tag"><input type="checkbox" name="tags[]" value="<?= e($tag) ?>"> <?= e($tag) ?></label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <label for="cm<?= $rid ?>">Comment (optional)</label>
                            <input type="text" id="cm<?= $rid ?>" name="comment" maxlength="500">
                            <button type="submit" class="primary">Submit Rating</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty">
            <p>You haven't submitted any errand requests yet.</p>
            <a class="button primary" href="request_errand.php">Request an Errand</a>
        </div>
    <?php endif; ?>
    <a class="back-link" href="customer_dashboard.php">Back to Dashboard</a>
</div>
<?php include 'partials/footer.php'; ?>
<script>
// Caption under the tappable stars.
(function () {
    var words = { 1: 'Very poor', 2: 'Poor', 3: 'Okay', 4: 'Good', 5: 'Excellent' };
    document.querySelectorAll('.rate-form').forEach(function (form) {
        var cap = form.querySelector('.star-caption');
        form.querySelectorAll('.star-rating input').forEach(function (r) {
            r.addEventListener('change', function () { cap.textContent = r.value + ' / 5 - ' + words[r.value]; });
        });
    });
})();
</script>
</body>
</html>
