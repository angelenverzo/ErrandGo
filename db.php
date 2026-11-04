

<?php
$host = "sql206.infinityfree.com";
$dbname = "if0_43355248_ErrandGo";
$username = "if0_43355248";
$password = "YOUR_INFINITYFREE_DATABASE_PASSWORD";

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database connection failed. Please check your database settings.");
}

// Audit helper + automatic tasks (ETL refresh, release of errands not started in time).
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/scheduler.php';
if (php_sapi_name() !== 'cli') {
    run_scheduled_tasks($pdo);
}
?>