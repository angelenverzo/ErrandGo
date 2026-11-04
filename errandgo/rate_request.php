<?php
session_start();
require_once 'db.php';
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'customer') {
    header('Location: login.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: my_requests.php');
    exit;
}

$requestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
$rating = filter_input(INPUT_POST, 'rating', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
$allowedTags = array_merge(RATING_TAGS_GOOD, RATING_TAGS_BAD);
$tags = array_values(array_unique(array_filter((array) ($_POST['tags'] ?? []), fn($t) => is_string($t) && in_array($t, $allowedTags, true))));
$tagText = implode(',', $tags);
$comment = trim($_POST['comment'] ?? '');

if (!$requestId || !$rating || strlen($comment) > 500) {
    header('Location: my_requests.php?rated=failed');
    exit;
}

try {
    // Only the owner of a COMPLETED, assigned, not-yet-rated request can rate it.
    $stmt = $pdo->prepare(
        "INSERT INTO ratings (request_id, customer_id, worker_id, rating, comment_category, tags, comment)
         SELECT id, user_id, worker_id, ?, ?, ?, ?
         FROM service_requests
         WHERE id = ? AND user_id = ? AND status = 'completed' AND worker_id IS NOT NULL"
    );
    $stmt->execute([
        $rating,
        $tags ? $tags[0] : null,          // first tick kept in the old single-category column
        $tagText !== '' ? $tagText : null,
        $comment !== '' ? $comment : null,
        $requestId, $_SESSION['user_id'],
    ]);

    if ($stmt->rowCount() === 1) {
        // Touch the request so the next automatic ETL run picks up the rating.
        $pdo->prepare('UPDATE service_requests SET updated_at = NOW() WHERE id = ?')->execute([$requestId]);
        $wid = $pdo->prepare('SELECT worker_id FROM service_requests WHERE id = ?');
        $wid->execute([$requestId]);
        if ($w = $wid->fetchColumn()) {
            notify($pdo, (int) $w, "You received a $rating-star rating for request #$requestId.", 'my_accepted_errands.php');
        }
        audit_log($pdo, 'RATING_SUBMITTED', "Rated request #$requestId: $rating star(s)" . ($tagText !== '' ? '; ' . str_replace(',', ', ', $tagText) : ''), 'request', $requestId);
        header('Location: my_requests.php?rated=success');
    } else {
        header('Location: my_requests.php?rated=failed');
    }
} catch (PDOException $e) {
    error_log('ErrandGo rating error: ' . $e->getMessage());
    header('Location: my_requests.php?rated=failed');
}
exit;
