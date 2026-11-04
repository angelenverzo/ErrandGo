<?php
// Fee calculation shared by the request form (preview) and submit_request.php / add_rider.php (authoritative).
// Fee = base (current service fee + delivery fee) + distance fee (km x per-km rate) + weight fee + extra-rider fee.
// Item costs are separate: reimbursed at the actual price and shown only as an estimate.
require_once __DIR__ . '/config.php';

function haversine_km(array $a, array $b): float
{
    $r = 6371.0;
    $dLat = deg2rad($b[0] - $a[0]);
    $dLng = deg2rad($b[1] - $a[1]);
    $h = sin($dLat / 2) ** 2 + cos(deg2rad($a[0])) * cos(deg2rad($b[0])) * sin($dLng / 2) ** 2;
    return 2 * $r * asin(min(1, sqrt($h)));
}

// Estimated billable km between two Mandaluyong barangays.
function area_distance_km(string $from, string $to): float
{
    if (!array_key_exists($from, AREA_COORDS) || !array_key_exists($to, AREA_COORDS)) {
        return 3.0; // unknown area: neutral estimate
    }
    $km = round(haversine_km(AREA_COORDS[$from], AREA_COORDS[$to]) * ROAD_FACTOR, 1);
    return max(MIN_BILLABLE_KM, $km);
}

// Whole matrix, so the browser preview shows exactly the numbers the server will charge.
function distance_matrix(): array
{
    $m = [];
    foreach (PICKUP_AREAS as $a) {
        foreach (PICKUP_AREAS as $b) {
            $m[$a][$b] = area_distance_km($a, $b);
        }
    }
    return $m;
}

// Rows: tier_no, label, max_kg, fee (ordered from lightest).
function get_weight_tiers(PDO $pdo): array
{
    try {
        return $pdo->query('SELECT tier_no, label, max_kg, fee FROM weight_tiers ORDER BY max_kg ASC')->fetchAll();
    } catch (Throwable $ex) {
        error_log('ErrandGo weight tiers error: ' . $ex->getMessage());
        return [];
    }
}

function weight_tier_for(array $tiers, float $kg): ?array
{
    foreach ($tiers as $t) {
        if ($kg <= (float) $t['max_kg']) {
            return $t;
        }
    }
    return null;
}

function get_extra_rider_fee(PDO $pdo): float
{
    try {
        $v = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key = 'extra_rider_fee'")->fetchColumn();
        return $v === false ? 0.0 : (float) $v;
    } catch (Throwable $ex) {
        return 0.0;
    }
}

// $rule = row of get_pricing()[service]. Returns every fee part; all amounts rounded to centavos.
function compute_errand_fees(array $rule, float $km, float $weightFee, int $ridersNeeded, float $extraRiderFee, ?float $itemCost): array
{
    $base = round($rule['service_fee'] + $rule['delivery_fee'], 2);
    $distanceFee = round($km * $rule['per_km_fee'], 2);
    $extraRidersFee = round(max(0, $ridersNeeded - 1) * $extraRiderFee, 2);
    $feeTotal = round($base + $distanceFee + $weightFee + $extraRidersFee, 2);
    return [
        'base' => $base,
        'distance_fee' => $distanceFee,
        'weight_fee' => round($weightFee, 2),
        'extra_riders_fee' => $extraRidersFee,
        'fee_total' => $feeTotal,
        'est_total' => round($feeTotal + ($itemCost ?? 0), 2),
        // The lead rider earns the worker share of base + distance + weight fees.
        'worker_pay' => round(($base + $distanceFee + $weightFee) * $rule['worker_share_pct'] / 100, 2),
    ];
}

// Pay of one additional rider = worker share of the per-rider fee.
function extra_rider_pay(float $extraRiderFee, float $sharePct): float
{
    return round($extraRiderFee * $sharePct / 100, 2);
}
