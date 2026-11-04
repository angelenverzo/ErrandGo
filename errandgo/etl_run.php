<?php
// Command-line ETL for scheduling (daily): php etl_run.php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/etl.php';
$r = run_etl($pdo);
echo strtoupper($r['status']) . " - extracted {$r['extracted']}, loaded {$r['loaded']}, rejected {$r['rejected']} ({$r['message']})\n";
