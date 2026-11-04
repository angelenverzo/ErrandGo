<?php
// A worker joins a bulk errand as an ADDITIONAL rider (the lead rider already accepted it).
session_start();
require_once 'db.php';
require_once 'config.php';
require_once 'fees.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'worker') {
    header('Location: login.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: worker_dashboard.php');
    exit;
}
$request_id = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
if (!$request_id || $request_id < 1) {
    $_SESSION['worker_message'] = 'Invalid errand request.';
    header('Location: worker_dashboard.php');
    exit;
}
$me = (int) $_SESSION['user_id'];

try {
    release_expired_acceptances($pdo);
    $worker = fetch_worker($pdo, $me);
    if (!$worker || $worker['verification_status'] !== 'verified' || (int) $worker['is_available'] !== 1) {
        $_SESSION['worker_message'] = 'You must be verified and available to join an errand.';
        header('Location: worker_dashboard.php');
        exit;
    }

    $pdo->beginTransaction();
    $s = $pdo->prepare('SELECT id, user_id, service_type, weight_kg, status, worker_id, riders_needed, extra_rider_fee FROM service_requests WHERE id = ? FOR UPDATE');
    $s->execute([$request_id]);
    $req = $s->fetch();
    $ok = $req && in_array($req['status'], ['accepted', 'in_progress'], true) && (int) $req['worker_id'] !== $me
        && worker_can_do($worker, $req['service_type'], $req['weight_kg'] !== null ? (float) $req['weight_kg'] : null);
    $pay = 0.0;
    if ($ok) {
        $c = $pdo->prepare("SELECT COUNT(*) FROM request_riders WHERE request_id = ? AND status = 'active'");
        $c->execute([$request_id]);
        $joined = (int) $c->fetchColumn();
        $mine = $pdo->prepare('SELECT COUNT(*) FROM request_riders WHERE request_id = ? AND worker_id = ?');
        $mine->execute([$request_id, $me]);
        $rel = $pdo->prepare('SELECT COUNT(*) FROM request_releases WHERE request_id = ? AND worker_id = ?');
        $rel->execute([$request_id, $me]);
        $ok = (1 + $joined) < (int) $req['riders_needed'] && (int) $mine->fetchColumn() === 0 && (int) $rel->fetchColumn() === 0;
    }
    if (!$ok) {
        $pdo->rollBack();
        $_SESSION['worker_message'] = 'This errand no longer needs another rider.';
        header('Location: worker_dashboard.php');
        exit;
    }
    $share = get_pricing($pdo)[$req['service_type']]['worker_share_pct'] ?? 80.0;
    $pay = extra_rider_pay((float) ($req['extra_rider_fee'] ?? get_extra_rider_fee($pdo)), (float) $share);
    $pdo->prepare('INSERT INTO request_riders (request_id, worker_id, pay) VALUES (?, ?, ?)')->execute([$request_id, $me, $pay]);
    $pdo->prepare('UPDATE service_requests SET updated_at = NOW() WHERE id = ?')->execute([$request_id]);
    $pdo->commit();

    audit_log($pdo, 'RIDER_JOINED', "Worker joined request #$request_id as an additional rider (pay " . peso($pay) . ').', 'request', $request_id);
    notify($pdo, (int) $req['user_id'], "{$worker['full_name']} joined your request #$request_id as an additional rider.", 'my_requests.php');
    if ($req['worker_id']) {
        notify($pdo, (int) $req['worker_id'], "{$worker['full_name']} joined errand #$request_id as an additional rider.", 'my_accepted_errands.php');
    }
    $_SESSION['worker_message'] = 'You joined the errand as an additional rider. Find it under My Errands.';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('ErrandGo join request error: ' . $e->getMessage());
    $_SESSION['worker_message'] = 'Unable to join the errand right now. Please try again.';
}
header('Location: worker_dashboard.php');
exit;
