<?php
session_start();
require_once 'db.php';
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}

$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$role = $_GET['role'] ?? '';
$action = $_GET['action'] ?? '';
$search = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$validDate = fn($d) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);

$where = ['1=1'];
$params = [];
if ($validDate($from)) { $where[] = 'created_at >= ?'; $params[] = $from . ' 00:00:00'; } else { $from = ''; }
if ($validDate($to))   { $where[] = 'created_at <= ?'; $params[] = $to . ' 23:59:59'; } else { $to = ''; }
if (in_array($role, ['customer', 'worker', 'admin', 'system'], true)) { $where[] = 'user_role = ?'; $params[] = $role; } else { $role = ''; }
if ($action !== '' && preg_match('/^[A-Z_]{1,50}$/', $action)) { $where[] = 'action = ?'; $params[] = $action; } else { $action = ''; }
if ($search !== '') {
    $where[] = '(user_name LIKE ? OR details LIKE ? OR ip_address LIKE ?)';
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
    array_push($params, $like, $like, $like);
}
$W = implode(' AND ', $where);

$rows = [];
$total = 0;
$actions = [];
$error = '';
try {
    $c = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE $W");
    $c->execute($params);
    $total = (int) $c->fetchColumn();
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;
    $q = $pdo->prepare("SELECT id, created_at, user_id, user_name, user_role, action, entity_type, entity_id, details, ip_address, page, user_agent
                        FROM audit_logs WHERE $W ORDER BY id DESC LIMIT $perPage OFFSET $offset");
    $q->execute($params);
    $rows = $q->fetchAll();
    $actions = $pdo->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $ex) {
    error_log('ErrandGo audit view error: ' . $ex->getMessage());
    $error = 'The audit log table is not available. Import sql/update_v6.sql.';
    $pages = 1;
}
$qs = fn(array $extra) => http_build_query(array_merge(['from' => $from, 'to' => $to, 'role' => $role, 'action' => $action, 'q' => $search], $extra));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Logs | ErrandGo</title>
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/theme.css">
</head>
<body>
<?php include 'partials/header.php'; ?>
<div class="container" style="max-width:1100px">
    <h1 class="page-banner">Audit Logs</h1>
    <p>A read-only record of <strong>who</strong> did <strong>what</strong>, <strong>when</strong> and from <strong>where</strong> (IP address, page and browser). Automatic actions appear as &ldquo;System&rdquo;. Passwords are never recorded.</p>
    <?php if ($error): ?><div class="error"><?= e($error) ?></div><?php endif; ?>

    <form method="GET" class="panel filters">
        <div><label for="from">From</label><input type="date" id="from" name="from" value="<?= e($from) ?>"></div>
        <div><label for="to">To</label><input type="date" id="to" name="to" value="<?= e($to) ?>"></div>
        <div><label for="role">Role</label>
            <select id="role" name="role"><option value="">All</option>
                <?php foreach (['customer', 'worker', 'admin', 'system'] as $r): ?><option value="<?= $r ?>" <?= $r === $role ? 'selected' : '' ?>><?= e(ucfirst($r)) ?></option><?php endforeach; ?></select></div>
        <div><label for="action">Action</label>
            <select id="action" name="action"><option value="">All</option>
                <?php foreach ($actions as $a): ?><option value="<?= e($a) ?>" <?= $a === $action ? 'selected' : '' ?>><?= e(status_label(strtolower($a))) ?></option><?php endforeach; ?></select></div>
        <div><label for="q">Search</label><input type="text" id="q" name="q" value="<?= e($search) ?>" placeholder="name, details, IP"></div>
        <div><button type="submit">Apply filters</button> <a class="small" href="admin_audit.php">Reset</a></div>
    </form>

    <div class="panel table-wrap">
        <p class="small" style="margin-top:0"><?= number_format($total) ?> record<?= $total === 1 ? '' : 's' ?> &middot; page <?= (int) $page ?> of <?= (int) $pages ?></p>
        <table class="data">
            <tr><th>When</th><th>Who</th><th>What</th><th>Details</th><th>Where</th></tr>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="small"><?= e($r['created_at']) ?></td>
                    <td><?= e($r['user_name'] ?? 'Guest') ?><br><span class="small"><?= e($r['user_role'] ?? 'not logged in') ?><?= $r['user_id'] ? ' #' . (int) $r['user_id'] : '' ?></span></td>
                    <td><strong><?= e(status_label(strtolower($r['action']))) ?></strong><?= $r['entity_type'] && $r['entity_id'] ? '<br><span class="small">' . e($r['entity_type']) . ' #' . (int) $r['entity_id'] . '</span>' : '' ?></td>
                    <td class="small"><?= e((string) $r['details']) ?></td>
                    <td class="small"><?= e((string) $r['ip_address']) ?><br><?= e((string) $r['page']) ?><br><span title="<?= e((string) $r['user_agent']) ?>"><?= e(mb_substr((string) $r['user_agent'], 0, 40)) ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows && !$error): ?><tr><td colspan="5" class="small">No records match the selected filters.</td></tr><?php endif; ?>
        </table>
        <?php if ($pages > 1): ?>
            <p>
                <?php if ($page > 1): ?><a class="button" href="?<?= e($qs(['page' => $page - 1])) ?>">&larr; Newer</a><?php endif; ?>
                <?php if ($page < $pages): ?><a class="button" href="?<?= e($qs(['page' => $page + 1])) ?>">Older &rarr;</a><?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
</div>
<?php include 'partials/footer.php'; ?>
</body>
</html>
