<?php
// Optional command line runner (php scheduler_run.php), e.g. from Windows Task Scheduler every minute.
// Not required: the same work already happens automatically while people use the site.
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/db.php';
run_scheduled_tasks($pdo, true);
echo "Scheduled tasks done.\n";
