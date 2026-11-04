<?php
session_start();
require_once 'db.php';
require_once 'config.php';
require_once 'fees.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$msg = '';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $err = 'Invalid form token. Please try again.';
    } else {
        $ok = true;
        $rows = [];
        $num = fn($v, $max) => is_numeric($v) && $v >= 0 && $v <= $max;
        foreach (SERVICES as $i => $s) {
            $sf = $_POST['service_fee'][$i] ?? '';
            $df = $_POST['delivery_fee'][$i] ?? '';
            $kf = $_POST['per_km_fee'][$i] ?? '';
            $sh = $_POST['share'][$i] ?? '';
            if (!$num($sf, 99999) || !$num($df, 99999) || !$num($kf, 9999) || !$num($sh, 100)) {
                $ok = false;
                break;
            }
            $rows[] = [$s, round((float) $sf, 2), round((float) $df, 2), round((float) $kf, 2), round((float) $sh, 2)];
        }
        $tierRows = [];
        foreach (get_weight_tiers($pdo) as $t) {
            $tf = $_POST['tier_fee'][$t['tier_no']] ?? '';
            if (!$num($tf, 99999)) {
                $ok = false;
                break;
            }
            $tierRows[] = [round((float) $tf, 2), (int) $t['tier_no']];
        }
        $erf = $_POST['extra_rider_fee'] ?? '';
        if (!$num($erf, 99999)) {
            $ok = false;
        }
        if ($ok) {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('INSERT INTO pricing_rules (service_type, service_fee, delivery_fee, per_km_fee, worker_share_pct) VALUES (?, ?, ?, ?, ?)
                                       ON DUPLICATE KEY UPDATE service_fee = VALUES(service_fee), delivery_fee = VALUES(delivery_fee), per_km_fee = VALUES(per_km_fee), worker_share_pct = VALUES(worker_share_pct)');
                foreach ($rows as $r) {
                    $stmt->execute($r);
                }
                $tstmt = $pdo->prepare('UPDATE weight_tiers SET fee = ? WHERE tier_no = ?');
                foreach ($tierRows as $r) {
                    $tstmt->execute($r);
                }
                $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('extra_rider_fee', ?)
                               ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([number_format((float) $erf, 2, '.', '')]);
                $pdo->commit();
                audit_log($pdo, 'PRICING_UPDATED', 'Pricing rules saved (base fees, per-km fees, worker share, weight-tier fees, extra-rider fee ' . peso($erf) . ').', 'pricing', null);
                $msg = 'Pricing rules saved. They apply to new requests only; existing requests keep the fees they were created with.';
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('ErrandGo pricing save error: ' . $ex->getMessage());
                $err = 'Unable to save the pricing rules. Make sure sql/update_v6.sql was imported.';
            }
        } else {
            $err = 'Fees must be numbers of 0 or more, and the worker share must be between 0 and 100.';
        }
    }
}
$pricing = get_pricing($pdo);
$tiers = get_weight_tiers($pdo);
$extraRiderFee = get_extra_rider_fee($pdo);
$prevMax = 0.0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pricing | ErrandGo</title>
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/theme.css">
</head>
<body>
<?php include 'partials/header.php'; ?>
<div class="container">
    <h1 class="page-banner">Pricing Rules</h1>
    <p>Errands are <strong>within Mandaluyong City</strong>. The customer sees <em>base rate + distance (per km) + weight fee + extra riders</em> (plus any item cost) before submitting, and the worker sees their pay before accepting.</p>
    <div class="notice">The rates below are proposed sample values. Management decides the final price schedule. Distance is <strong>estimated</strong> from the pickup and drop-off barangays (see AREA_COORDS in config.php; its coordinates are approximate and should be checked on a map).</div>
    <?php if ($msg): ?><div class="success"><?= e($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="error"><?= e($err) ?></div><?php endif; ?>

    <form method="POST" class="panel table-wrap">
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
        <h3 style="margin-top:0">Fees per service</h3>
        <table class="data">
            <tr><th>Service</th><th>Service fee (&#8369;)</th><th>Delivery fee (&#8369;)</th><th>Fee per km (&#8369;)</th><th>Worker share (%)</th><th>Worker pay (base only)</th></tr>
            <?php foreach (SERVICES as $i => $s): $r = $pricing[$s] ?? ['service_fee' => 0, 'delivery_fee' => 0, 'per_km_fee' => 0, 'worker_share_pct' => 80]; ?>
                <tr>
                    <td><?= e($s) ?></td>
                    <td><input type="number" name="service_fee[<?= $i ?>]" min="0" step="0.01" value="<?= e(number_format($r['service_fee'], 2, '.', '')) ?>" required></td>
                    <td><input type="number" name="delivery_fee[<?= $i ?>]" min="0" step="0.01" value="<?= e(number_format($r['delivery_fee'], 2, '.', '')) ?>" required></td>
                    <td><input type="number" name="per_km_fee[<?= $i ?>]" min="0" step="0.01" value="<?= e(number_format($r['per_km_fee'], 2, '.', '')) ?>" required></td>
                    <td><input type="number" name="share[<?= $i ?>]" min="0" max="100" step="0.01" value="<?= e(number_format($r['worker_share_pct'], 2, '.', '')) ?>" required></td>
                    <td><?= e(peso(($r['service_fee'] + $r['delivery_fee']) * $r['worker_share_pct'] / 100)) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <p class="small">The <strong>service fee + delivery fee</strong> is the base rate (the original flat rate). The <strong>fee per km</strong> is added on top for every estimated kilometre between pickup and drop-off (minimum <?= e(number_format(MIN_BILLABLE_KM, 1)) ?> km when a drop-off is given).</p>

        <h3>Weight fee (parcel delivery, moving assistance and any errand with a stated weight)</h3>
        <table class="data">
            <tr><th>Tier</th><th>Weight range</th><th>Fee (&#8369;)</th></tr>
            <?php foreach ($tiers as $t): ?>
                <tr>
                    <td><?= e($t['label']) ?></td>
                    <td><?= $prevMax > 0 ? e(number_format($prevMax + 0.1, 1)) : '0.1' ?> &ndash; <?= e(rtrim(rtrim(number_format((float) $t['max_kg'], 1), '0'), '.')) ?> kg</td>
                    <td><input type="number" name="tier_fee[<?= (int) $t['tier_no'] ?>]" min="0" step="0.01" value="<?= e(number_format((float) $t['fee'], 2, '.', '')) ?>" required></td>
                </tr>
                <?php $prevMax = (float) $t['max_kg']; ?>
            <?php endforeach; ?>
            <?php if (!$tiers): ?><tr><td colspan="3" class="small">Weight tiers not found. Import sql/update_v6.sql.</td></tr><?php endif; ?>
        </table>
        <p class="small">Loads above <?= (int) BULK_WEIGHT_KG ?> kg need a motorcycle or larger, above 30 kg a car, van or truck, and above 100 kg a van or truck.</p>

        <h3>Extra rider (bulk errands)</h3>
        <label for="extra_rider_fee">Fee per additional rider (&#8369;)</label>
        <input type="number" id="extra_rider_fee" name="extra_rider_fee" min="0" step="0.01" value="<?= e(number_format($extraRiderFee, 2, '.', '')) ?>" required style="max-width:220px">
        <p class="small">Charged to the customer for each additional rider; the additional rider earns the worker share of it.</p>

        <button type="submit" class="primary">Save pricing</button>
    </form>
    <p class="small">Lead-rider pay = (base + distance fee + weight fee) &times; worker share. Item costs are reimbursed at the actual price and are not part of the fee.</p>
</div>
<?php include 'partials/footer.php'; ?>
</body>
</html>
