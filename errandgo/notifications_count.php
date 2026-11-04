<?php
// JSON: number of unread notifications for the logged-in user (polled by the header bell).
session_start();
require_once 'db.php';
header('Content-Type: application/json');
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['unread' => 0]);
    exit;
}
try {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$_SESSION['user_id']]);
    echo json_encode(['unread' => (int) $stmt->fetchColumn()]);
} catch (Throwable $ex) {
    echo json_encode(['unread' => 0]);
}
