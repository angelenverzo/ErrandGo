<?php
// Customer asks for ANOTHER rider on a bulk errand.
session_start();
require_once 'db.php';
require_once 'config.php';
require_once 'fees.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'customer') {
    header('Location: login.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: my_requests.php');
    exit;
}
$requestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
if (!$requestId || $requestId < 1) {
    header('Location: my_requests.php?rider=failed');
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT service_type, weight_kg, status, extra_rider_fee, riders_needed FROM service_requests WHERE id = ? AND user_id = ?');
    $stmt->execute([$requestId, $_SESSION['user_id']]);
    $req = $stmt->fetch();
    if (!$req) {
        header('Location: my_requests.php?rider=failed');
        exit;
    }
    $fee = $req['extra_rider_fee'] !== null ? (float) $req['extra_rider_fee'] : get_extra_rider_fee($pdo);

    // Only bulk errands that are still active, below the rider limit.
    $u = $pdo->prepare("UPDATE service_requests
                        SET riders_needed = riders_needed + 1,
                            extra_rider_fee = COALESCE(extra_rider_fee, ?),
                            extra_riders_fee = extra_riders_fee + ?,
                            fee_total = COALESCE(fee_total, 0) + ?,
                            est_total = COALESCE(est_total, 0) + ?
                        WHERE id = ? AND user_id = ? AND is_bulk = 1
                          AND status IN ('pending','accepted','in_progress') AND riders_needed < ?");
    $u->execute([$fee, $fee, $fee, $fee, $requestId, $_SESSION['user_id'], MAX_RIDERS]);

    if ($u->rowCount() === 1) {
        $newTotal = (int) $req['riders_needed'] + 1;
        audit_log($pdo, 'RIDER_ADDED', "Customer requested another rider for request #$requestId (riders needed: $newTotal, +" . peso($fee) . ')', 'request', $requestId);
        if ($req['status'] !== 'pending') {   // pending errands are already visible to every matching worker
            notify_matching_workers($pdo, (string) $req['service_type'], "Additional rider needed for errand #$requestId ({$req['service_type']}).",
                $req['weight_kg'] !== null ? (float) $req['weight_kg'] : null);
        }
        header('Location: my_requests.php?rider=success');
    } else {
        header('Location: my_requests.php?rider=failed');
    }
} catch (Throwable $e) {
    error_log('ErrandGo add rider error: ' . $e->getMessage());
    header('Location: my_requests.php?rider=failed');
}
exit;
