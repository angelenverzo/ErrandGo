<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'customer') {
    header('Location: login.php');
    exit;
}

require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: my_requests.php');
    exit;
}

$requestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);

if (!$requestId || $requestId < 1) {
    header('Location: my_requests.php?cancel=invalid');
    exit;
}

$sql = "UPDATE service_requests
        SET status = 'cancelled', cancelled_at = NOW()
        WHERE id = ?
          AND user_id = ?
          AND status = 'pending'";

$stmt = $pdo->prepare($sql);
$stmt->execute([$requestId, $_SESSION['user_id']]);

if ($stmt->rowCount() > 0) {
    require_once 'config.php';
    notify_admins($pdo, "Request #$requestId was cancelled by the customer.", 'admin_dashboard.php');
    audit_log($pdo, 'REQUEST_CANCELLED', "Customer cancelled request #$requestId (it was still pending).", 'request', $requestId);
    header('Location: my_requests.php?cancel=success');
    exit;
}

header('Location: my_requests.php?cancel=failed');
exit;
?>
