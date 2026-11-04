<?php
session_start();
require_once 'db.php';
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}
// The warehouse refreshes itself automatically (scheduler.php, run from db.php), so there is no "Run ETL" button.

// ---- Filters (Chapter III VII: date range, service type, status, location) ----
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$service = $_GET['service'] ?? '';
$status = $_GET['status'] ?? '';
$area = $_GET['area'] ?? '';
$validDate = fn($d) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);

// Base filters apply to the KPI denominator and all dashboard views.
// The status filter is applied to charts/detail tables only, so selecting one
// status cannot make completion/cancellation rates misleading by shrinking the denominator.
$baseWhere = ['1=1'];
$baseParams = [];
if ($validDate($from)) { $baseWhere[] = 'd.full_date >= ?'; $baseParams[] = $from; } else { $from = ''; }
if ($validDate($to))   { $baseWhere[] = 'd.full_date <= ?'; $baseParams[] = $to; }   else { $to = ''; }
if (in_array($service, SERVICES, true)) { $baseWhere[] = 'sv.service_name = ?'; $baseParams[] = $service; } else { $service = ''; }
if (in_array($area, array_merge(PICKUP_AREAS, ['Unspecified']), true)) { $baseWhere[] = 'l.pickup_area = ?'; $baseParams[] = $area; } else { $area = ''; }
$WBase = implode(' AND ', $baseWhere);
$where = $baseWhere;
$params = $baseParams;
if (in_array($status, ['pending', 'accepted', 'in_progress', 'completed', 'cancelled'], true)) { $where[] = 'f.status = ?'; $params[] = $status; } else { $status = ''; }
$W = implode(' AND ', $where);
$JOIN = "FROM dw_fact_service_request f
         JOIN dw_dim_date d ON d.date_key = f.date_key
         JOIN dw_dim_service sv ON sv.service_key = f.service_key
         JOIN dw_dim_location l ON l.location_key = f.location_key
         JOIN dw_dim_customer c ON c.customer_key = f.customer_key";

function q(PDO $pdo, string $sql, array $params): array
{
    $s = $pdo->prepare($sql);
    $s->execute($params);
    return $s->fetchAll();
}

$k = q($pdo, "SELECT COUNT(*) total, COALESCE(SUM(f.is_completed),0) done, COALESCE(SUM(f.is_cancelled),0) cancelled,
                     COALESCE(SUM(f.is_assigned),0) assigned, AVG(f.acceptance_minutes) acc, AVG(f.duration_minutes) dur,
                     COUNT(DISTINCT f.customer_key) custs, AVG(f.rating) avg_rating, COUNT(f.rating) n_rating
              $JOIN WHERE $WBase", $baseParams)[0];
$total = (int) $k['total'];
$eligible = $total - (int) $k['cancelled'];
$pct = fn($a, $b) => $b > 0 ? round($a / $b * 100, 1) . '%' : '—';
$repeat = q($pdo, "SELECT COUNT(*) n FROM (SELECT f.customer_key $JOIN WHERE $WBase GROUP BY f.customer_key HAVING COUNT(*) > 1) t", $baseParams)[0]['n'];
$mins = fn($v) => $v === null ? '—' : round((float) $v, 1) . ' min';

$bySvc = q($pdo, "SELECT sv.service_name label, COUNT(*) n $JOIN WHERE $W GROUP BY sv.service_name ORDER BY n DESC", $params);
$byArea = q($pdo, "SELECT l.pickup_area label, COUNT(*) n $JOIN WHERE $W GROUP BY l.pickup_area ORDER BY n DESC LIMIT 8", $params);
$byStatus = q($pdo, "SELECT f.status label, COUNT(*) n $JOIN WHERE $W GROUP BY f.status ORDER BY n DESC", $params);
$byDay = q($pdo, "SELECT d.full_date label, COUNT(*) n $JOIN WHERE $W GROUP BY d.full_date ORDER BY d.full_date DESC LIMIT 14", $params);
$byDay = array_reverse($byDay);
$byWeekday = q($pdo, "SELECT d.weekday_name label, COUNT(*) n $JOIN WHERE $W GROUP BY d.weekday_name, DAYOFWEEK(d.full_date) ORDER BY DAYOFWEEK(d.full_date)", $params);
$byHour = q($pdo, "SELECT f.hour_of_day label, COUNT(*) n $JOIN WHERE $W GROUP BY f.hour_of_day ORDER BY n DESC LIMIT 5", $params);
$svcRates = q($pdo, "SELECT sv.service_name label, COUNT(*) total, SUM(f.is_completed) done, SUM(f.is_cancelled) cancelled, AVG(f.rating) rating
                     $JOIN WHERE $WBase GROUP BY sv.service_name ORDER BY total DESC", $baseParams);
$workerPerf = q($pdo, "SELECT w.worker_id, w.vehicle_type, COUNT(*) assigned, SUM(f.is_completed) done, AVG(f.duration_minutes) dur, AVG(f.rating) rating
                       $JOIN JOIN dw_dim_worker w ON w.worker_key = f.worker_key WHERE $W
                       GROUP BY w.worker_id, w.vehicle_type ORDER BY done DESC, assigned DESC LIMIT 10", $params);
$fees = q($pdo, "SELECT COALESCE(SUM(f.est_fee),0) fee, COALESCE(SUM(f.worker_pay),0) pay, COUNT(f.est_fee) n $JOIN WHERE $WBase AND f.is_completed = 1", $baseParams)[0];
$feeBySvc = q($pdo, "SELECT sv.service_name label, ROUND(SUM(f.est_fee),2) n $JOIN WHERE $WBase AND f.is_completed = 1 AND f.est_fee IS NOT NULL GROUP BY sv.service_name ORDER BY n DESC", $baseParams);
$byPay = q($pdo, "SELECT f.payment_method label, COUNT(*) n $JOIN WHERE $W GROUP BY f.payment_method ORDER BY n DESC", $params);

$lastRun = $pdo->query("SELECT started_at, finished_at, status, rows_extracted, rows_loaded, rows_rejected, message
                        FROM dw_etl_log WHERE status = 'success' ORDER BY run_id DESC LIMIT 1")->fetch();
$lastRefresh = $lastRun['finished_at'] ?? null;

function bars(array $rows, string $cls = '', ?callable $fmt = null): void
{
    if (!$rows) { echo '<p class="small">No data for the selected filters.</p>'; return; }
    $max = max(array_map(fn($r) => (int) $r['n'], $rows)) ?: 1;
    foreach ($rows as $r) {
        $label = $fmt ? $fmt($r['label']) : $r['label'];
        $w = max(2, round($r['n'] / $max * 100));
        echo '<div class="bar-row"><span>' . e($label) . '</span><div class="bar-track"><div class="bar-fill ' . $cls . '" style="width:' . $w . '%"></div></div><span class="bar-val">' . (int) $r['n'] . '</span></div>';
    }
}
function kpi(string $name, string $value, string $def, bool $pink = false): void
{
    echo '<div class="kpi ' . ($pink ? 'pink' : '') . '"><div class="v">' . e($value) . '</div><div class="n">' . e($name) . '</div><div class="d">' . e($def) . '</div></div>';
}
$stars = fn($v) => $v === null ? '—' : number_format((float) $v, 1) . ' ★';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Management Dashboard | ErrandGo</title>
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/theme.css">
</head>
<body>
<?php include 'partials/header.php'; ?>
<div class="container" style="max-width:1100px">
    <section class="welcome">
        <h1>Management Dashboard</h1>
        <p>Data warehouse reporting &middot; Refreshes automatically every <?= (int) ETL_INTERVAL_MINUTES ?> minutes &middot; Last refresh: <strong><?= $lastRefresh ? e($lastRefresh) : 'waiting for the first automatic refresh' ?></strong></p>
    </section>

    <div class="panel">
        <h3 style="margin-top:0">Latest requests <span class="small">(live, refreshes every 30 seconds)</span></h3>
        <div id="live-requests"><p class="small">Loading...</p></div>
    </div>
    <script>
    (function () {
        var box = document.getElementById('live-requests');
        function load() {
            fetch('admin_live.php', { credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.text() : ''; })
                .then(function (html) { if (html) { box.innerHTML = html; } })
                .catch(function () {});
        }
        load();
        setInterval(load, 30000);
    })();
    </script>

    <form method="GET" class="panel filters">
        <div><label for="from">From</label><input type="date" id="from" name="from" value="<?= e($from) ?>"></div>
        <div><label for="to">To</label><input type="date" id="to" name="to" value="<?= e($to) ?>"></div>
        <div><label for="service">Service</label>
            <select id="service" name="service"><option value="">All</option>
                <?php foreach (SERVICES as $s): ?><option <?= $s === $service ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
        <div><label for="status">Status</label>
            <select id="status" name="status"><option value="">All</option>
                <?php foreach (['pending', 'accepted', 'in_progress', 'completed', 'cancelled'] as $s): ?><option value="<?= $s ?>" <?= $s === $status ? 'selected' : '' ?>><?= e(status_label($s)) ?></option><?php endforeach; ?></select></div>
        <div><label for="area">Pickup area</label>
            <select id="area" name="area"><option value="">All</option>
                <?php foreach (PICKUP_AREAS as $s): ?><option <?= $s === $area ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
        <div><button type="submit">Apply filters</button> <a class="small" href="admin_dashboard.php">Reset</a></div>
    </form>

    <h2>Key Performance Indicators</h2>
    <div class="kpi-grid">
        <?php
        kpi('Total requests', (string) $total, 'Distinct request IDs in the selected period.', true);
        kpi('Completion rate', $pct((int) $k['done'], $eligible), 'Completed ÷ eligible requests (submitted minus cancelled).');
        kpi('Cancellation rate', $pct((int) $k['cancelled'], $total), 'Cancelled ÷ submitted requests.');
        kpi('Worker assignment rate', $pct((int) $k['assigned'], $eligible), 'Assigned to a worker ÷ eligible requests (submitted minus cancelled).');
        kpi('Avg. acceptance time', $mins($k['acc']), 'Submission to acceptance, where timestamps were captured.');
        kpi('Avg. task duration', $mins($k['dur']), 'Start to completion, where timestamps were captured.');
        kpi('Repeat customer rate', $pct((int) $repeat, (int) $k['custs']), 'Customers with more than one request ÷ customers with at least one.');
        kpi('Estimated fees (completed)', peso($fees['fee']), 'Service + delivery fees of completed requests. An estimate, not confirmed revenue.');
        kpi('Estimated worker pay', peso($fees['pay']), 'Worker share of those fees. ErrandGo keeps the rest: ' . peso($fees['fee'] - $fees['pay']) . '.');
        kpi('Average rating', $stars($k['avg_rating']) . ($k['n_rating'] ? ' (' . (int) $k['n_rating'] . ')' : ''), 'Mean customer rating of rated, completed requests.');
        ?>
    </div>
    <p class="small">KPI cards and the completion-by-service table use the selected date, service, and pickup-area filters but intentionally ignore the Status filter so rate denominators include all statuses. Charts and the worker summary follow the Status filter. Data last refreshed: <?= $lastRefresh ? e($lastRefresh) : 'never' ?>.</p>

    <div class="chart-grid">
        <div class="panel"><h3 style="margin-top:0">Requests by service type</h3><?php bars($bySvc); ?></div>
        <div class="panel"><h3 style="margin-top:0">Request status distribution</h3><?php bars($byStatus, 'pink', fn($s) => status_label($s)); ?></div>
        <div class="panel"><h3 style="margin-top:0">Requests over time (last 14 days with data)</h3><?php bars($byDay); ?></div>
        <div class="panel"><h3 style="margin-top:0">Requests by pickup area (top 8)</h3><?php bars($byArea, 'pink'); ?></div>
        <div class="panel"><h3 style="margin-top:0">Requests by weekday</h3><?php bars($byWeekday); ?></div>
        <div class="panel"><h3 style="margin-top:0">Busiest hours (hour of day)</h3><?php bars($byHour, 'pink', fn($h) => sprintf('%02d:00', $h)); ?></div>
        <div class="panel"><h3 style="margin-top:0">Preferred payment method</h3><?php bars($byPay); ?><p class="small">Preference only; no payment processing yet.</p></div>
        <div class="panel"><h3 style="margin-top:0">Estimated fees by service type (completed)</h3>
            <?php bars($feeBySvc, 'pink', null); ?>
            <p class="small">Values in pesos (&#8369;), rounded. Estimated from the fee set on each request; revenue is confirmed only once payments are recorded.</p></div>
    </div>

    <div class="panel table-wrap">
        <h3 style="margin-top:0">Completion by service category</h3>
        <table class="data"><tr><th>Service</th><th>Requests</th><th>Completed</th><th>Cancelled</th><th>Completion rate</th><th>Avg. rating</th></tr>
            <?php foreach ($svcRates as $r): ?>
                <tr><td><?= e($r['label']) ?></td><td><?= (int) $r['total'] ?></td><td><?= (int) $r['done'] ?></td><td><?= (int) $r['cancelled'] ?></td>
                    <td><?= $pct((int) $r['done'], (int) $r['total'] - (int) $r['cancelled']) ?></td><td><?= $stars($r['rating']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$svcRates): ?><tr><td colspan="6" class="small">No data for the selected filters.</td></tr><?php endif; ?>
        </table>
            </div>

    <div class="panel table-wrap">
        <h3 style="margin-top:0">Worker performance summary (top 10)</h3>
        <table class="data"><tr><th>Worker ID</th><th>Vehicle</th><th>Assigned</th><th>Completed</th><th>Avg. duration</th><th>Avg. rating</th></tr>
            <?php foreach ($workerPerf as $r): ?>
                <tr><td>#<?= (int) $r['worker_id'] ?></td><td><?= e($r['vehicle_type'] ?? '—') ?></td><td><?= (int) $r['assigned'] ?></td><td><?= (int) $r['done'] ?></td>
                    <td><?= $mins($r['dur']) ?></td><td><?= $stars($r['rating']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$workerPerf): ?><tr><td colspan="6" class="small">No assigned requests for the selected filters.</td></tr><?php endif; ?>
        </table>
        <p class="small">Interpret completions alongside workload and service quality. Worker IDs only; names are not copied into the warehouse.</p>
    </div>

    <p class="small">Warehouse source: star schema (dw_fact_service_request + date, customer, worker, service, location dimensions). The warehouse refreshes by itself every <?= (int) ETL_INTERVAL_MINUTES ?> minutes; this page also reloads automatically. Test or demonstration data must be labelled as such and not presented as real results.</p>
</div>
<?php include 'partials/footer.php'; ?>
<script>
// Reload the dashboard (keeping the selected filters) so the figures follow each automatic warehouse refresh.
setTimeout(function () { location.reload(); }, <?= (int) ETL_INTERVAL_MINUTES * 60 * 1000 ?>);
</script>
</body>
</html>