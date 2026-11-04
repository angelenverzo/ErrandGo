<?php
// Automatic background work, run by ordinary page loads (no button and no cron needed):
//   1. release accepted errands that were not started within ACCEPT_TIMEOUT_MINUTES (checked every ~10 s)
//   2. refresh the data warehouse (ETL) every ETL_INTERVAL_MINUTES
// Each task is "claimed" with one atomic UPDATE, so only one visitor runs it per interval.
require_once __DIR__ . '/config.php';

function scheduler_claim(PDO $pdo, string $task, int $seconds): bool
{
    $seconds = max(1, $seconds);
    $stmt = $pdo->prepare("UPDATE scheduler_state SET last_run = NOW() WHERE task = ? AND last_run <= NOW() - INTERVAL $seconds SECOND");
    $stmt->execute([$task]);
    return $stmt->rowCount() === 1;
}

function run_scheduled_tasks(PDO $pdo, bool $force = false): void
{
    try {
        if ($force || scheduler_claim($pdo, 'expire_accepted', 10)) {
            release_expired_acceptances($pdo);
        }
        if ($force || scheduler_claim($pdo, 'etl', ETL_INTERVAL_MINUTES * 60)) {
            ignore_user_abort(true);
            require_once __DIR__ . '/etl.php';
            run_etl($pdo);
        }
    } catch (Throwable $ex) {
        error_log('ErrandGo scheduler error: ' . $ex->getMessage()); // e.g. update_v6.sql not imported yet
    }
}

// Accepted errands that were not started in time go back to "pending" for OTHER workers.
// The worker who let it expire cannot re-take that same errand. Additional riders of the errand are released too.
function release_expired_acceptances(PDO $pdo): int
{
    $mins = (int) ACCEPT_TIMEOUT_MINUTES;
    $released = 0;
    try {
        $rows = $pdo->query("SELECT sr.id, sr.worker_id, sr.user_id, sr.service_type, sr.pickup_area, sr.weight_kg, u.full_name AS worker_name
                             FROM service_requests sr LEFT JOIN users u ON u.id = sr.worker_id
                             WHERE sr.status = 'accepted' AND sr.worker_id IS NOT NULL AND sr.accepted_at IS NOT NULL
                               AND sr.accepted_at <= NOW() - INTERVAL $mins MINUTE")->fetchAll();
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $wid = (int) $r['worker_id'];
            $riderIds = [];
            try {
                $pdo->beginTransaction();
                $u = $pdo->prepare("UPDATE service_requests SET status = 'pending', worker_id = NULL, accepted_at = NULL
                                    WHERE id = ? AND status = 'accepted' AND worker_id = ? AND accepted_at <= NOW() - INTERVAL $mins MINUTE");
                $u->execute([$id, $wid]);
                if ($u->rowCount() !== 1) {   // someone started it in the meantime
                    $pdo->rollBack();
                    continue;
                }
                $reason = "Not started within $mins minutes";
                $pdo->prepare('INSERT INTO request_releases (request_id, worker_id, reason) VALUES (?, ?, ?)')->execute([$id, $wid, $reason]);
                $rs = $pdo->prepare("SELECT worker_id FROM request_riders WHERE request_id = ? AND status = 'active'");
                $rs->execute([$id]);
                $riderIds = array_map('intval', $rs->fetchAll(PDO::FETCH_COLUMN));
                foreach ($riderIds as $rid) {
                    $pdo->prepare('INSERT INTO request_releases (request_id, worker_id, reason) VALUES (?, ?, ?)')->execute([$id, $rid, 'Lead rider did not start in time']);
                }
                $pdo->prepare("UPDATE request_riders SET status = 'released', released_at = NOW() WHERE request_id = ? AND status = 'active'")->execute([$id]);
                $pdo->commit();
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('ErrandGo release error: ' . $ex->getMessage());
                continue;
            }
            $released++;
            $name = $r['worker_name'] ?: 'the worker';
            audit_system($pdo, 'ACCEPTANCE_AUTO_CANCELLED', "Request #$id: acceptance by $name (worker #$wid) cancelled automatically, not started within $mins minutes; reopened for other workers.", 'request', $id);
            notify($pdo, $wid, "Your acceptance of request #$id was cancelled: it was not started within $mins minutes.", 'worker_dashboard.php');
            foreach ($riderIds as $rid) {
                notify($pdo, $rid, "You were released from request #$id because the lead rider did not start in time.", 'worker_dashboard.php');
            }
            notify($pdo, (int) $r['user_id'], "Request #$id: the worker did not start within $mins minutes, so it is open to other workers again.", 'my_requests.php');
            notify_matching_workers($pdo, (string) $r['service_type'], "Errand #$id ({$r['service_type']}" . ($r['pickup_area'] ? ", {$r['pickup_area']}" : '') . ') is available again.',
                $r['weight_kg'] !== null ? (float) $r['weight_kg'] : null, array_merge([$wid], $riderIds));
        }
    } catch (Throwable $ex) {
        error_log('ErrandGo release error: ' . $ex->getMessage());
    }
    return $released;
}
