<?php
// Audit trail: WHO (user), WHEN (created_at), WHERE (IP address, page, browser) and WHAT (action + details).
// Never breaks the main action: a failed log write is only written to the PHP error log.

// $actor = ['id' => ?int, 'name' => ?string, 'role' => ?string]; defaults to the logged-in user.
function audit_log(PDO $pdo, string $action, string $details = '', ?string $entityType = null, ?int $entityId = null, ?array $actor = null): void
{
    try {
        $uid = $actor['id'] ?? ($_SESSION['user_id'] ?? null);
        $name = $actor['name'] ?? ($_SESSION['user_name'] ?? null);
        $role = $actor['role'] ?? ($_SESSION['user_role'] ?? null);
        $cli = php_sapi_name() === 'cli';
        $ip = $cli ? 'CLI' : ($_SERVER['REMOTE_ADDR'] ?? null);   // REMOTE_ADDR only: forwarded headers can be faked
        $page = $cli ? 'cli' : (($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . basename($_SERVER['SCRIPT_NAME'] ?? ''));
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $pdo->prepare('INSERT INTO audit_logs (user_id, user_name, user_role, action, entity_type, entity_id, details, ip_address, page, user_agent)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $uid !== null ? (int) $uid : null,
                $name !== null ? mb_substr((string) $name, 0, 100) : null,
                $role !== null ? mb_substr((string) $role, 0, 20) : null,
                mb_substr($action, 0, 50),
                $entityType,
                $entityId,
                $details !== '' ? mb_substr($details, 0, 500) : null,
                $ip !== null ? mb_substr((string) $ip, 0, 45) : null,
                mb_substr($page, 0, 120),
                $agent !== null ? mb_substr((string) $agent, 0, 255) : null,
            ]);
    } catch (Throwable $ex) {
        error_log('ErrandGo audit error: ' . $ex->getMessage());
    }
}

// For automatic actions (no person involved).
function audit_system(PDO $pdo, string $action, string $details = '', ?string $entityType = null, ?int $entityId = null): void
{
    audit_log($pdo, $action, $details, $entityType, $entityId, ['id' => null, 'name' => 'System (automatic)', 'role' => 'system']);
}
