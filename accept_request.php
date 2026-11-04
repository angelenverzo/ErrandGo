<?php
session_start();
require_once 'db.php';
require_once 'config.php';

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

try {
    release_expired_acceptances($pdo);
    $worker = fetch_worker($pdo, (int) $_SESSION['user_id']);
    if (!$worker || $worker['verification_status'] !== 'verified') {
        $_SESSION['worker_message'] = 'Your worker account must be verified before you can accept errands.';
        header('Location: worker_dashboard.php');
        exit;
    }
    if ((int) $worker['is_available'] !== 1) {
        $_SESSION['worker_message'] = 'You are marked unavailable. Update your profile to accept errands.';
        header('Location: worker_dashboard.php');
        exit;
    }

    $svc = $pdo->prepare('SELECT service_type, weight_kg, riders_needed FROM service_requests WHERE id = ?');
    $svc->execute([$request_id]);
    $req = $svc->fetch();
    if (!$req || !worker_can_do($worker, $req['service_type'], $req['weight_kg'] !== null ? (float) $req['weight_kg'] : null)) {
        $_SESSION['worker_message'] = 'This errand does not match your services or vehicle.';
        header('Location: worker_dashboard.php');
        exit;
    }

    // A worker whose acceptance expired cannot take the same errand again.
    $rel = $pdo->prepare('SELECT COUNT(*) FROM request_releases WHERE request_id = ? AND worker_id = ?');
    $rel->execute([$request_id, $_SESSION['user_id']]);
    if ((int) $rel->fetchColumn() > 0) {
        $_SESSION['worker_message'] = 'You were released from this errand earlier, so it is now open to other workers.';
        header('Location: worker_dashboard.php');
        exit;
    }

    // Accept only requests that are still pending and unassigned.
    $stmt = $pdo->prepare("UPDATE service_requests
                           SET worker_id = ?, status = 'accepted', accepted_at = NOW()
                           WHERE id = ? AND status = 'pending' AND worker_id IS NULL");
    $stmt->execute([$_SESSION['user_id'], $request_id]);

    if ($stmt->rowCount() === 1) {
        $_SESSION['worker_message'] = 'Errand request accepted! Start it within ' . ACCEPT_TIMEOUT_MINUTES . ' minutes, or it will be given to another worker.';
        audit_log($pdo, 'ERRAND_ACCEPTED', "Worker accepted request #$request_id ({$req['service_type']}).", 'request', $request_id);
        $own = $pdo->prepare('SELECT user_id FROM service_requests WHERE id = ?');
        $own->execute([$request_id]);
        if ($cid = $own->fetchColumn()) {
            notify($pdo, (int) $cid, "Your request #$request_id was accepted by {$worker['full_name']}.", 'my_requests.php');
        }
        // Bulk errand that needs more riders: tell the other matching workers.
        if ((int) $req['riders_needed'] > 1) {
            notify_matching_workers($pdo, $req['service_type'], "Additional rider needed for errand #$request_id ({$req['service_type']}).",
                $req['weight_kg'] !== null ? (float) $req['weight_kg'] : null, [(int) $_SESSION['user_id']]);
        }
    } else {
        $_SESSION['worker_message'] = 'This request is no longer available. Please refresh the dashboard.';
    }
} catch (PDOException $e) {
    error_log('ErrandGo accept request error: ' . $e->getMessage());
    $_SESSION['worker_message'] = 'Unable to accept the request right now. Please try again.';
}
header('Location: worker_dashboard.php');
exit;
