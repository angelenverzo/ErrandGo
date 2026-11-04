<?php
/**
 * ErrandGo ETL: operational tables -> staging -> validated -> star-schema warehouse.
 * Incremental: only requests created/updated since the last successful run are extracted.
 * Returns an array: status, extracted, loaded, rejected, message.
 */
function run_etl(PDO $pdo): array
{
    $started = date('Y-m-d H:i:s');
    $pdo->prepare("INSERT INTO dw_etl_log (started_at, status) VALUES (?, 'running')")->execute([$started]);
    $runId = (int) $pdo->lastInsertId();

    try {
        // ---------- EXTRACT ----------
        $last = $pdo->query("SELECT MAX(started_at) FROM dw_etl_log WHERE status = 'success'")->fetchColumn();
        $pdo->exec('TRUNCATE TABLE stg_service_request');
        $sql = "INSERT INTO stg_service_request
                (request_id, customer_id, worker_id, service_type, pickup_area, payment_method, status,
                 created_at, accepted_at, started_at, completed_at, cancelled_at, rating, extracted_at,
                 service_fee, delivery_fee, worker_pay, est_fee)
                SELECT sr.id, sr.user_id, sr.worker_id, TRIM(sr.service_type),
                       COALESCE(NULLIF(TRIM(sr.pickup_area), ''), 'Unspecified'),
                       COALESCE(NULLIF(TRIM(sr.payment_method), ''), 'Unspecified'),
                       LOWER(TRIM(sr.status)), sr.created_at, sr.accepted_at, sr.started_at,
                       sr.completed_at, sr.cancelled_at, r.rating, NOW(),
                       sr.service_fee, sr.delivery_fee,
                       -- pay of the lead rider plus any additional riders
                       IF(sr.worker_pay IS NULL, NULL, sr.worker_pay + COALESCE((SELECT SUM(rr.pay) FROM request_riders rr WHERE rr.request_id = sr.id AND rr.status = 'active'), 0)),
                       -- all fees (base + distance + weight + extra riders), without the item cost
                       COALESCE(sr.fee_total, IF(sr.service_fee IS NULL, NULL, sr.service_fee + COALESCE(sr.delivery_fee, 0)))
                FROM service_requests sr
                LEFT JOIN ratings r ON r.request_id = sr.id";
        if ($last) {
            $stmt = $pdo->prepare($sql . ' WHERE sr.updated_at >= ?');
            $stmt->execute([$last]);
        } else {
            $pdo->exec($sql);
        }
        $extracted = (int) $pdo->query('SELECT COUNT(*) FROM stg_service_request')->fetchColumn();

        // ---------- TRANSFORM / VALIDATE ----------
        // Standardise legacy labels.
        $pdo->exec("UPDATE stg_service_request SET service_type = 'Office-Supply Purchasing' WHERE service_type = 'Business Errands'");
        $pdo->exec("UPDATE stg_service_request SET service_type = 'Other Errands' WHERE service_type = 'Other'");
        // Reject invalid rows (they are counted and logged, not loaded).
        $pdo->exec("UPDATE stg_service_request SET is_valid = 0, reject_reason = 'Unknown status'
                    WHERE status NOT IN ('pending','accepted','in_progress','completed','cancelled')");
        $in = "'" . implode("','", array_map('addslashes', SERVICES)) . "'";
        $pdo->exec("UPDATE stg_service_request SET is_valid = 0, reject_reason = 'Unknown service'
                    WHERE is_valid = 1 AND service_type NOT IN ($in)");
        $pdo->exec("UPDATE stg_service_request SET is_valid = 0, reject_reason = 'Completion before start'
                    WHERE is_valid = 1 AND completed_at IS NOT NULL AND started_at IS NOT NULL AND completed_at < started_at");

        // ---------- LOAD: dimensions first ----------
        $pdo->exec("INSERT IGNORE INTO dw_dim_service (service_name)
                    SELECT DISTINCT service_type FROM stg_service_request WHERE is_valid = 1");
        $pdo->exec("INSERT IGNORE INTO dw_dim_location (pickup_area)
                    SELECT DISTINCT pickup_area FROM stg_service_request WHERE is_valid = 1");
        $pdo->exec("INSERT INTO dw_dim_customer (customer_id, registration_date)
                    SELECT DISTINCT u.id, DATE(u.created_at) FROM users u
                    JOIN stg_service_request s ON s.customer_id = u.id WHERE s.is_valid = 1
                    ON DUPLICATE KEY UPDATE registration_date = VALUES(registration_date)");
        $pdo->exec("INSERT INTO dw_dim_worker (worker_id, vehicle_type, verification_status)
                    SELECT DISTINCT u.id, u.vehicle_type, u.verification_status FROM users u
                    JOIN stg_service_request s ON s.worker_id = u.id WHERE s.is_valid = 1
                    ON DUPLICATE KEY UPDATE vehicle_type = VALUES(vehicle_type), verification_status = VALUES(verification_status)");
        $pdo->exec("INSERT IGNORE INTO dw_dim_date
                    (date_key, full_date, day_of_month, month_num, month_name, quarter_num, year_num, weekday_name, is_weekend)
                    SELECT DISTINCT CAST(DATE_FORMAT(created_at, '%Y%m%d') AS UNSIGNED), DATE(created_at), DAY(created_at),
                           MONTH(created_at), MONTHNAME(created_at), QUARTER(created_at), YEAR(created_at),
                           DAYNAME(created_at), IF(DAYOFWEEK(created_at) IN (1,7), 1, 0)
                    FROM stg_service_request WHERE is_valid = 1");

        // ---------- LOAD: fact (repeatable upsert on request_id) ----------
        $pdo->exec("INSERT INTO dw_fact_service_request
                    (request_id, date_key, hour_of_day, customer_key, worker_key, service_key, location_key, payment_method,
                     status, is_assigned, is_completed, is_cancelled, acceptance_minutes, duration_minutes, rating,
                     created_at, etl_loaded_at, est_fee, worker_pay)
                    SELECT s.request_id, CAST(DATE_FORMAT(s.created_at, '%Y%m%d') AS UNSIGNED), HOUR(s.created_at),
                           c.customer_key, w.worker_key, sv.service_key, l.location_key, s.payment_method, s.status,
                           IF(s.worker_id IS NULL, 0, 1), IF(s.status = 'completed', 1, 0), IF(s.status = 'cancelled', 1, 0),
                           IF(s.accepted_at IS NULL, NULL, TIMESTAMPDIFF(MINUTE, s.created_at, s.accepted_at)),
                           IF(s.started_at IS NULL OR s.completed_at IS NULL, NULL, TIMESTAMPDIFF(MINUTE, s.started_at, s.completed_at)),
                           s.rating, s.created_at, NOW(),
                           s.est_fee, s.worker_pay
                    FROM stg_service_request s
                    JOIN dw_dim_customer c ON c.customer_id = s.customer_id
                    LEFT JOIN dw_dim_worker w ON w.worker_id = s.worker_id
                    JOIN dw_dim_service sv ON sv.service_name = s.service_type
                    JOIN dw_dim_location l ON l.pickup_area = s.pickup_area
                    WHERE s.is_valid = 1
                    ON DUPLICATE KEY UPDATE
                      worker_key = VALUES(worker_key), payment_method = VALUES(payment_method), status = VALUES(status),
                      is_assigned = VALUES(is_assigned), is_completed = VALUES(is_completed), is_cancelled = VALUES(is_cancelled),
                      acceptance_minutes = VALUES(acceptance_minutes), duration_minutes = VALUES(duration_minutes),
                      rating = VALUES(rating), etl_loaded_at = VALUES(etl_loaded_at),
                      est_fee = VALUES(est_fee), worker_pay = VALUES(worker_pay)");

        // ---------- VALIDATE / RECONCILE ----------
        $valid = (int) $pdo->query('SELECT COUNT(*) FROM stg_service_request WHERE is_valid = 1')->fetchColumn();
        $rejected = $extracted - $valid;
        $inWh = (int) $pdo->query('SELECT COUNT(*) FROM stg_service_request s JOIN dw_fact_service_request f ON f.request_id = s.request_id WHERE s.is_valid = 1')->fetchColumn();
        if ($inWh !== $valid) {
            throw new RuntimeException("Reconciliation failed: $valid valid rows in staging, $inWh found in warehouse.");
        }
        $msg = $rejected > 0 ? "$rejected row(s) rejected; see stg_service_request.reject_reason" : 'OK';
        $pdo->prepare("UPDATE dw_etl_log SET finished_at = NOW(), rows_extracted = ?, rows_loaded = ?, rows_rejected = ?, status = 'success', message = ? WHERE run_id = ?")
            ->execute([$extracted, $inWh, $rejected, $msg, $runId]);
        return ['status' => 'success', 'extracted' => $extracted, 'loaded' => $inWh, 'rejected' => $rejected, 'message' => $msg];
    } catch (Throwable $ex) {
        error_log('ErrandGo ETL error: ' . $ex->getMessage());
        $pdo->prepare("UPDATE dw_etl_log SET finished_at = NOW(), status = 'failed', message = ? WHERE run_id = ?")
            ->execute([substr($ex->getMessage(), 0, 250), $runId]);
        return ['status' => 'failed', 'extracted' => 0, 'loaded' => 0, 'rejected' => 0, 'message' => $ex->getMessage()];
    }
}
