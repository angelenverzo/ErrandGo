<?php
session_start();
require_once 'db.php';
require_once 'config.php';

// Only logged-in workers can update errand status
if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['user_role'] ?? '') !== 'worker'
) {
    header('Location: login.php');
    exit;
}

// Accept updates only through POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: worker_dashboard.php');
    exit;
}

$request_id = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
$new_status = $_POST['status'] ?? '';

if (!$request_id || $request_id < 1) {
    $_SESSION['worker_message'] = 'Invalid errand request.';
    header('Location: my_accepted_errands.php');
    exit;
}

// Allow only these status transitions
$allowed_transitions = [
    'in_progress' => 'accepted',
    'completed'   => 'in_progress'
];

if (
    !isset($allowed_transitions[$new_status])
) {
    $_SESSION['worker_message'] = 'Invalid status update.';
    header('Location: my_accepted_errands.php');
    exit;
}

$required_current_status = $allowed_transitions[$new_status];

try {
    // Make sure an acceptance that is older than the time limit has already been released.
    release_expired_acceptances($pdo);

    // Update only an errand assigned to this worker
    // and only when its current status is correct.
    $stmt = $pdo->prepare("
        UPDATE service_requests
        SET status = ?,
            started_at = IF(? = 'in_progress', NOW(), started_at),
            completed_at = IF(? = 'completed', NOW(), completed_at)
        WHERE id = ?
          AND worker_id = ?
          AND status = ?
    ");

    $stmt->execute([
        $new_status,
        $new_status,
        $new_status,
        $request_id,
        $_SESSION['user_id'],
        $required_current_status
    ]);

    if ($stmt->rowCount() === 1) {
        $own = $pdo->prepare('SELECT user_id FROM service_requests WHERE id = ?');
        $own->execute([$request_id]);
        if ($cid = $own->fetchColumn()) {
            $text = $new_status === 'in_progress'
                ? "Your request #$request_id is now in progress."
                : "Your request #$request_id was completed. Please rate your worker.";
            notify($pdo, (int) $cid, $text, 'my_requests.php');
        }
        audit_log($pdo, $new_status === 'in_progress' ? 'ERRAND_STARTED' : 'ERRAND_COMPLETED',
            "Worker marked request #$request_id as " . status_label($new_status) . '.', 'request', $request_id);
        if ($new_status === 'in_progress') {
            $_SESSION['worker_message'] =
                'Errand status updated to In Progress.';
        } else {
            $_SESSION['worker_message'] =
                'Errand marked as Completed successfully!';
        }
    } else {
        $rel = $pdo->prepare('SELECT COUNT(*) FROM request_releases WHERE request_id = ? AND worker_id = ?');
        $rel->execute([$request_id, $_SESSION['user_id']]);
        $_SESSION['worker_message'] = (int) $rel->fetchColumn() > 0
            ? 'This errand was released to other workers because it was not started within ' . ACCEPT_TIMEOUT_MINUTES . ' minutes.'
            : 'The errand could not be updated. It may already have changed status.';
    }
} catch (PDOException $e) {
    error_log('ErrandGo status update error: ' . $e->getMessage());
    $_SESSION['worker_message'] =
        'Unable to update the errand status. Please try again.';
}

header('Location: my_accepted_errands.php');
exit;
