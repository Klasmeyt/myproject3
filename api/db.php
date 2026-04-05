<?php
// api/db.php - Complete MySQL API for AgriTrace+
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$table = $_GET['table'] ?? '';

header('Content-Type: application/json');

function sendResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function validateTable($table) {
    $allowed = ['users', 'farms', 'livestock', 'incidents', 'public_reports', 'audit_log'];
    return in_array($table, $allowed);
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if (!validateTable($table)) {
    sendResponse(['error' => 'Invalid table'], 400);
}

// Log API access
$userIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$logData = [
    'action' => $action,
    'table' => $table,
    'method' => $method,
    'ip' => $userIP
];

switch ($action) {
    case 'getAll':
        $stmt = $GLOBALS['pdo']->query("SELECT * FROM `$table` ORDER BY id DESC");
        sendResponse($stmt->fetchAll());
        break;

    case 'getById':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) sendResponse(['error' => 'ID required'], 400);
        $stmt = $GLOBALS['pdo']->prepare("SELECT * FROM `$table` WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        sendResponse($result ?: ['error' => 'Not found']);
        break;

    case 'insert':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) sendResponse(['error' => 'Invalid data'], 400);

        $columns = implode('`, `', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));
        $sql = "INSERT INTO `$table` (`$columns`) VALUES ($placeholders)";
        
        $stmt = $GLOBALS['pdo']->prepare($sql);
        $stmt->execute($data);
        $id = $GLOBALS['pdo']->lastInsertId();
        
        sendResponse(['id' => $id, 'success' => true]);
        break;

    case 'update':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) sendResponse(['error' => 'ID required'], 400);
        
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) sendResponse(['error' => 'Invalid data'], 400);

        $setParts = [];
        $params = [];
        foreach ($data as $key => $value) {
            $setParts[] = "`$key` = :$key";
            $params[":$key"] = $value;
        }
        $setClause = implode(', ', $setParts);
        $sql = "UPDATE `$table` SET $setClause, updatedAt = NOW() WHERE id = :id";
        $params[':id'] = $id;
        
        $stmt = $GLOBALS['pdo']->prepare($sql);
        $success = $stmt->execute($params);
        sendResponse(['success' => $success, 'rowsAffected' => $stmt->rowCount()]);
        break;

    case 'delete':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) sendResponse(['error' => 'ID required'], 400);
        
        $stmt = $GLOBALS['pdo']->prepare("DELETE FROM `$table` WHERE id = ?");
        $success = $stmt->execute([$id]);
        sendResponse(['success' => $success, 'rowsAffected' => $stmt->rowCount()]);
        break;

    case 'query':
        $where = $_GET['where'] ?? '';
        $sql = "SELECT * FROM `$table`";
        if ($where) $sql .= " WHERE $where";
        $sql .= " ORDER BY id DESC";
        
        $stmt = $GLOBALS['pdo']->query($sql);
        sendResponse($stmt->fetchAll());
        break;

    case 'seed':
        $GLOBALS['pdo']->exec("
            INSERT IGNORE INTO users (firstName, lastName, email, password, role, status, mobile) VALUES
            ('Juan', 'dela Cruz', 'farmer@agritrace.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Farmer', 'Active', '+63 912 345 6789'),
            ('Maria', 'Reyes', 'official@agritrace.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Agriculture Official', 'Active', '+63 917 234 5678'),
            ('System', 'Admin', 'admin@agritrace.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Admin', 'Active', '+63 999 000 0001');
        ");
        
        // Seed sample farms
        $GLOBALS['pdo']->exec("
            INSERT IGNORE INTO farms (name, ownerId, ownerName, type, status, latitude, longitude) VALUES
            ('Green Valley Farm', 1, 'Juan dela Cruz', 'Cattle', 'Approved', 14.5995, 120.9842),
            ('Sunny Acres Poultry', 1, 'Juan dela Cruz', 'Poultry', 'Approved', 14.6100, 120.9900);
        ");
        
        sendResponse(['success' => true, 'message' => 'Database seeded']);
        break;

    case 'forgot-password':
        $email = $_GET['email'] ?? '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            sendResponse(['error' => 'Invalid email'], 400);
        }
        
        // Check if user exists
        $stmt = $GLOBALS['pdo']->prepare("SELECT id FROM users WHERE email = ? AND status = 'Active'");
        $stmt->execute([$email]);
        if (!$stmt->fetch()) {
            sendResponse(['error' => 'No active account found with this email'], 404);
        }
        
        // Generate reset token
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // Save token
        $stmt = $GLOBALS['pdo']->prepare("UPDATE users SET resetToken = ?, resetExpires = ? WHERE email = ?");
        $stmt->execute([$token, $expires, $email]);
        
        sendResponse(['success' => true, 'message' => 'Reset link sent', 'token' => $token]);
        break;

    default:
        sendResponse(['error' => 'Invalid action'], 400);

    // Add these cases to your switch statement in api/db.php

case 'getOfficerPermissions':
    $officerId = $_GET['officer_id'] ?? 0;
    $stmt = $pdo->prepare("
        SELECT permission_key, is_enabled 
        FROM officer_permissions 
        WHERE officer_id = ? 
        ORDER BY permission_key
    ");
    $stmt->execute([$officerId]);
    $permissions = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Default permissions if none exist
    $defaults = [
        'view_farmers' => 1, 'approve_farms' => 1, 'inspect_farms' => 0, 'suspend_farmers' => 0,
        'view_livestock' => 1, 'audit_livestock' => 0, 'quarantine_livestock' => 0,
        'manage_incidents' => 1, 'dispatch_teams' => 0, 'issue_alerts' => 0,
        'view_reports' => 1, 'export_data' => 0
    ];
    
    echo json_encode(array_merge($defaults, $permissions));
    break;

case 'saveOfficerPermissions':
    $input = json_decode(file_get_contents('php://input'), true);
    $officerId = $input['officer_id'];
    $permissions = $input['permissions'];
    
    $pdo->beginTransaction();
    try {
        // Delete existing permissions
        $stmt = $pdo->prepare("DELETE FROM officer_permissions WHERE officer_id = ?");
        $stmt->execute([$officerId]);
        
        // Insert new permissions
        $stmt = $pdo->prepare("INSERT INTO officer_permissions (officer_id, permission_key, is_enabled) VALUES (?, ?, ?)");
        foreach ($permissions as $key => $enabled) {
            $stmt->execute([$officerId, $key, $enabled]);
        }
        
        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $pdo->rollback();
        echo json_encode(['error' => $e->getMessage()]);
    }
    break;
    
    // Add this case to your api/db.php switch statement
case 'query':
    $table = $_GET['table'] ?? '';
    $join = $_GET['join'] ?? '';
    $where = $_GET['where'] ?? '';
    
    $sql = "SELECT farms.*, users.firstName, users.lastName, users.email, users.mobile, users.role 
            FROM `$table` $join";
    
    if ($where) $sql .= " WHERE $where";
    $sql .= " ORDER BY createdAt DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    echo json_encode([
        'success' => true,
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'count' => $stmt->rowCount()
    ]);
    break;

    // Add this inside your main switch/case in api/db.php

// Add this case inside your API switch/if block
case 'getAuditLogs':
    $sql = "SELECT al.*, 
            u.firstName, u.lastName, u.email as userEmail 
            FROM audit_log al 
            LEFT JOIN users u ON al.userId = u.id 
            ORDER BY al.createdAt DESC 
            LIMIT 500";
    $result = $conn->query($sql);
    $logs = [];
    while ($row = $result->fetch_assoc()) {
        $logs[] = $row;
    }
    echo json_encode($logs);
    break;

    case 'getDashboardStats':
    $stats = [
        'totalUsers' => 0,
        'totalFarms' => 0,
        'totalLivestock' => 0,
        'pendingReports' => 0
    ];

    // USERS - Your table has 4 records
    $stmt = $GLOBALS['pdo']->query("SELECT COUNT(*) as cnt FROM users");
    $stats['totalUsers'] = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];

    // FARMS - Approved only
    $stmt = $GLOBALS['pdo']->query("SELECT COUNT(*) as cnt FROM farms WHERE status = 'Approved'");
    $stats['totalFarms'] = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];

    // LIVESTOCK - Sum qty
    $stmt = $GLOBALS['pdo']->query("SELECT COALESCE(SUM(qty), 0) as cnt FROM livestock");
    $stats['totalLivestock'] = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];

    // PENDING REPORTS
    $stmt = $GLOBALS['pdo']->query("SELECT COUNT(*) as cnt FROM incidents WHERE status = 'Pending'");
    $pendingIncidents = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    $stmt = $GLOBALS['pdo']->query("SELECT COUNT(*) as cnt FROM public_reports WHERE status = 'Pending'");
    $pendingPublic = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    $stats['pendingReports'] = $pendingIncidents + $pendingPublic;

    sendResponse([
        'success' => true,
        'debug' => [
            'users_raw' => $stats['totalUsers'],
            'farms_raw' => $stats['totalFarms'],
            'livestock_raw' => $stats['totalLivestock']
        ],
        'stats' => $stats
    ]);
    break;
}
?>