<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'gc_maxlifetime'  => 1800,
    ]);
}

define('DB_HOST', 'localhost');
define('DB_NAME', 'myagri1');  // Fixed: consistent DB name
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

function getPDO(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
                DB_USER, DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            error_log('[AgriTrace DB] ' . $e->getMessage());
            http_response_code(500);
            echo '<h1>Service Unavailable</h1><p>Database connection failed.</p>';
            exit;
        }
    }
    return $pdo;
}

$pdo  = getPDO();
$conn = $pdo;

// Session & Auth Functions (ONLY HERE)
function isLoggedIn(): bool {
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

function requireLogin(string $redirect = '../login.php'): void {
    if (!isLoggedIn()) {
        header('Location: ' . $redirect);
        exit;
    }
}

function requireRole(array $roles, string $redirect = '../login.php'): void {
    requireLogin($redirect);
    if (!in_array($_SESSION['user_role'] ?? '', $roles, true)) {
        header('Location: ' . $redirect . '?error=unauthorized');
        exit;
    }
}

function currentUserId(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

function currentUserRole(): string {
    return $_SESSION['user_role'] ?? '';
}

function auditLog(string $action, string $table = '', int $recordId = 0, array $details = []): void {
    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare("
            INSERT INTO audit_log (userId, action, tableName, recordId, ipAddress, userAgent, details)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            currentUserId(),
            $action,
            $table,
            $recordId ?: null,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            $details ? json_encode($details) : null,
        ]);
    } catch (PDOException $e) {
        error_log('[AuditLog] ' . $e->getMessage());
    }
}

function getConfig(string $key, string $default = ''): string {
    try {
        $pdo  = getPDO();
        $stmt = $pdo->prepare("SELECT `value` FROM config WHERE `key` = ? LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? (string)$row['value'] : $default;
    } catch (PDOException $e) {
        return $default;
    }
}
?>