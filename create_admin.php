<?php
// One-time admin creation (CLI only):  php create_admin.php "Full Name" admin@example.com "StrongPassword"
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/db.php';
[$script, $name, $email, $pass] = array_pad($argv, 4, '');
if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
    exit("Usage: php create_admin.php \"Full Name\" email@example.com \"password (8+ chars)\"\n");
}
$stmt = $pdo->prepare("INSERT INTO users (full_name, email, password, role) VALUES (?, ?, ?, 'admin')");
$stmt->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT)]);
echo "Admin account created for $email\n";
