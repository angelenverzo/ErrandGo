<?php
// HTML fragment: latest requests straight from the live database (no ETL needed). Admin only.
session_start();
require_once 'db.php';
require_once 'config.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit;
}
$rows = $pdo->query("SELECT sr.id, sr.service_type, sr.pickup_area, sr.status, sr.created_at, u.full_name
                     FROM service_requests sr JOIN users u ON u.id = sr.user_id
                     ORDER BY sr.created_at DESC, sr.id DESC LIMIT 8")->fetchAll();
if (!$rows) {
    echo '<p class="small">No requests yet.</p>';
    exit;
}
foreach ($rows as $r) {
    echo '<div class="live-row"><span><strong>#' . (int) $r['id'] . '</strong> ' . e($r['service_type'])
        . ' &middot; ' . e($r['pickup_area'] ?: 'No area') . ' &middot; ' . e($r['full_name']) . '</span>'
        . '<span><span class="status ' . e($r['status']) . '">' . e(status_label($r['status'])) . '</span> '
        . '<span class="small">' . e(date('M j, g:i A', strtotime($r['created_at']))) . '</span></span></div>';
}
