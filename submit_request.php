<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'customer') {
    header('Location: login.php');
    exit;
}

require_once 'db.php';
require_once 'config.php';
require_once 'fees.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: request_errand.php');
    exit;
}

function fail_request(string $message): void
{
    $_SESSION['request_error'] = $message;
    header('Location: request_errand.php');
    exit;
}

$preferredDate = $_POST['preferred_date'] ?? '';
$preferredTime = $_POST['preferred_time'] ?? '';
$paymentMethod = trim($_POST['payment_method'] ?? '');

$date = DateTime::createFromFormat('!Y-m-d', $preferredDate);
$time = DateTime::createFromFormat('!H:i', $preferredTime);
if (
    !$date || $date->format('Y-m-d') !== $preferredDate || $date->format('Y-m-d') < date('Y-m-d') ||
    !$time || $time->format('H:i') !== $preferredTime
) {
    fail_request('Please enter a valid date and time.');
}
if ($paymentMethod !== '' && !in_array($paymentMethod, PAYMENT_METHODS, true)) {
    fail_request('Please choose a valid payment method.');
}

$errandsIn = $_POST['errands'] ?? [];
if (!is_array($errandsIn) || count($errandsIn) < 1) {
    fail_request('Please add at least one errand.');
}
$errandsIn = array_values($errandsIn);
if (count($errandsIn) > MAX_ERRANDS_PER_BOOKING) {
    fail_request('You can request up to ' . MAX_ERRANDS_PER_BOOKING . ' errands at once.');
}
$multi = count($errandsIn) > 1;

$pricing = get_pricing($pdo);
$tiers = get_weight_tiers($pdo);
$extraRiderFee = get_extra_rider_fee($pdo);
$clean = [];

foreach ($errandsIn as $n => $in) {
    $label = 'Errand ' . ($n + 1) . ': ';
    if (!is_array($in)) {
        fail_request($label . 'invalid data.');
    }
    $service = trim($in['service_type'] ?? '');
    $desc = trim($in['description'] ?? '');
    $pickupArea = trim($in['pickup_area'] ?? '');
    $pickupLoc = trim($in['pickup_location'] ?? '');
    $dropArea = trim($in['dropoff_area'] ?? '');
    $dropLoc = trim($in['dropoff_location'] ?? '');

    if (!in_array($service, SERVICES, true) || $desc === '') {
        fail_request($label . 'please choose a service and describe the errand.');
    }
    if (!in_array($pickupArea, PICKUP_AREAS, true) || $pickupLoc === '') {
        fail_request($label . 'please choose the pickup area and enter the pickup address or landmark.');
    }
    if (mb_strlen($desc) > 2000 || mb_strlen($pickupLoc) > 255 || mb_strlen($dropLoc) > 255) {
        fail_request($label . 'one or more fields are too long.');
    }

    // Drop-off: Mandaluyong barangays only (dropdown), with an address or landmark.
    $dropOptional = in_array($service, NO_DROPOFF_SERVICES, true);
    if ($dropArea === '' && $dropLoc === '' && $dropOptional) {
        $dropArea = '';
    } else {
        if (!in_array($dropArea, PICKUP_AREAS, true)) {
            fail_request($label . 'the drop-off must be within Mandaluyong City. Please choose a drop-off area.');
        }
        if ($dropLoc === '') {
            fail_request($label . 'please enter the drop-off address or landmark.');
        }
    }

    // Items: only when the worker must buy something.
    $needsPurchase = ($in['needs_purchase'] ?? '0') === '1';
    $items = [];
    $itemCost = null;
    if ($needsPurchase) {
        foreach ((array) ($in['items'] ?? []) as $it) {
            if (!is_array($it)) {
                continue;
            }
            $name = trim($it['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $qty = filter_var($it['qty'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 999]]);
            $spec = trim($it['spec'] ?? '');
            if ($qty === false || mb_strlen($name) > 100 || mb_strlen($spec) > 255) {
                fail_request($label . 'please check the item list (name up to 100 characters, quantity 1 to 999).');
            }
            $items[] = ['name' => $name, 'qty' => $qty, 'spec' => $spec !== '' ? $spec : null];
        }
        if (count($items) < 1) {
            fail_request($label . 'please list the item(s) the worker should buy (item specification).');
        }
        if (count($items) > MAX_ITEMS_PER_ERRAND) {
            fail_request($label . 'too many items; the limit is ' . MAX_ITEMS_PER_ERRAND . '.');
        }
        $costRaw = trim($in['item_cost'] ?? '');
        if ($costRaw !== '') {
            if (!is_numeric($costRaw) || (float) $costRaw < 0 || (float) $costRaw > 100000) {
                fail_request($label . 'please enter a valid estimated item cost.');
            }
            $itemCost = round((float) $costRaw, 2);
        }
    }

    // Weight (required for some services) and weight tier fee.
    $weightRaw = trim($in['weight_kg'] ?? '');
    $weightDesc = trim($in['weight_desc'] ?? '');
    if (mb_strlen($weightDesc) > 255) {
        fail_request($label . 'the load description is too long.');
    }
    $weight = null;
    $tier = null;
    if ($weightRaw !== '') {
        if (!is_numeric($weightRaw) || (float) $weightRaw <= 0 || (float) $weightRaw > MAX_WEIGHT_KG) {
            fail_request($label . 'please enter a weight between 0.1 and ' . MAX_WEIGHT_KG . ' kg.');
        }
        $weight = round((float) $weightRaw, 1);
        $tier = weight_tier_for($tiers, $weight);
        if ($tier === null) {
            fail_request($label . 'this weight is above what ErrandGo can handle online.');
        }
    }
    if (in_array($service, WEIGHT_REQUIRED_SERVICES, true) && ($weight === null || $weightDesc === '')) {
        fail_request($label . 'please enter the weight and describe the item(s) or load for this service.');
    }

    // Bulk errand rules (the server decides; the form only previews).
    $isBulk = $multi || in_array($service, BULK_SERVICES, true) || ($weight !== null && $weight > BULK_WEIGHT_KG)
        || count($items) >= BULK_ITEM_COUNT || (($in['bulk'] ?? '') === '1');
    $extra = 0;
    if ($isBulk) {
        $extra = filter_var($in['extra_riders'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => MAX_RIDERS - 1]]);
        if ($extra === false) {
            $extra = 0;
        }
    }

    $rule = $pricing[$service] ?? null;
    if ($rule === null) {
        fail_request($label . 'pricing is not available for this service yet.');
    }
    $km = $dropArea !== '' ? area_distance_km($pickupArea, $dropArea) : 0.0;
    $weightFee = $tier ? (float) $tier['fee'] : 0.0;
    $fees = compute_errand_fees($rule, $km, $weightFee, 1 + $extra, $extraRiderFee, $itemCost);

    $clean[] = compact('service', 'desc', 'pickupArea', 'pickupLoc', 'dropArea', 'dropLoc', 'needsPurchase', 'items', 'itemCost',
        'weight', 'weightDesc', 'tier', 'isBulk', 'extra', 'km', 'rule', 'extraRiderFee', 'fees');
}

$created = [];
try {
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO request_batches (user_id, errand_count) VALUES (?, ?)')->execute([$_SESSION['user_id'], count($clean)]);
    $batchId = (int) $pdo->lastInsertId();

    $ins = $pdo->prepare("INSERT INTO service_requests
        (user_id, batch_id, service_type, description, pickup_area, pickup_location, dropoff_area, dropoff_location,
         preferred_date, preferred_time, payment_method, needs_purchase, weight_kg, weight_desc, weight_tier, weight_fee,
         distance_km, per_km_fee, distance_fee, is_bulk, riders_needed, extra_rider_fee, extra_riders_fee,
         service_fee, delivery_fee, item_cost_est, fee_total, est_total, worker_pay)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $insItem = $pdo->prepare('INSERT INTO errand_items (request_id, item_name, quantity, specification) VALUES (?, ?, ?, ?)');

    foreach ($clean as $c) {
        $f = $c['fees'];
        $ins->execute([
            $_SESSION['user_id'], $batchId, $c['service'], $c['desc'], $c['pickupArea'], $c['pickupLoc'],
            $c['dropArea'] !== '' ? $c['dropArea'] : null, $c['dropLoc'] !== '' ? $c['dropLoc'] : null,
            $preferredDate, $preferredTime, $paymentMethod !== '' ? $paymentMethod : null,
            $c['needsPurchase'] ? 1 : 0, $c['weight'], $c['weightDesc'] !== '' ? $c['weightDesc'] : null,
            $c['tier'] ? (int) $c['tier']['tier_no'] : null, $c['weight'] !== null ? $f['weight_fee'] : null,
            $c['dropArea'] !== '' ? $c['km'] : null, $c['rule']['per_km_fee'], $f['distance_fee'],
            $c['isBulk'] ? 1 : 0, 1 + $c['extra'], $c['extra'] > 0 ? $c['extraRiderFee'] : null, $f['extra_riders_fee'],
            $c['rule']['service_fee'], $c['rule']['delivery_fee'], $c['itemCost'],
            $f['fee_total'], $f['est_total'], $f['worker_pay'],
        ]);
        $rid = (int) $pdo->lastInsertId();
        foreach ($c['items'] as $it) {
            $insItem->execute([$rid, $it['name'], $it['qty'], $it['spec']]);
        }
        $created[] = [$rid, $c];
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('ErrandGo submit error: ' . $e->getMessage());
    fail_request('Unable to submit your request right now. Please try again later.');
}

foreach ($created as [$rid, $c]) {
    $where = $c['pickupArea'] . ($c['dropArea'] !== '' ? ' to ' . $c['dropArea'] : '');
    audit_log($pdo, 'REQUEST_CREATED', "Request #$rid ({$c['service']}, $where)" . ($multi ? " as part of booking #$batchId with " . count($created) . ' errands' : '')
        . '; estimated total ' . peso($c['fees']['est_total']) . ($c['needsPurchase'] ? '; worker to buy ' . count($c['items']) . ' item(s)' : ''), 'request', $rid);
    notify_admins($pdo, "New request #$rid: {$c['service']} ({$c['pickupArea']})", 'admin_dashboard.php');
    notify_matching_workers($pdo, $c['service'], "New {$c['service']} errand available in {$c['pickupArea']}.", $c['weight']);
}
header('Location: customer_dashboard.php?request=success&n=' . count($created));
exit;
