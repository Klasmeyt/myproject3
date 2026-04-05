<?php
// api/approve_farm.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

include '../config/db.php';
session_start();

$input = json_decode(file_get_contents('php://input'), true);
$farmId = intval($input['farmId'] ?? 0);

if (!$farmId || $farmId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid farm ID']);
    exit;
}

$userId = $_SESSION['user_id'] ?? null;

// Check if farm exists and is pending
$stmt = $conn->prepare("
    SELECT f.name, f.status, u.firstName, u.lastName 
    FROM farms f 
    LEFT JOIN users u ON f.ownerId = u.id 
    WHERE f.id = ?
");
$stmt->bind_param("i", $farmId);
$stmt->execute();
$farm = $stmt->get_result()->fetch_assoc();

if (!$farm || $farm['status'] !== 'Pending') {
    echo json_encode([
        'success' => false, 
        'message' => $farm ? 'Farm not pending' : 'Farm not found'
    ]);
    exit;
}

// Approve farm
$stmt = $conn->prepare("
    UPDATE farms 
    SET status = 'Approved', 
        updatedAt = CURRENT_TIMESTAMP 
    WHERE id = ?
");
$stmt->bind_param("i", $farmId);

if ($stmt->execute()) {
    // Audit log
    if ($userId) {
        $logStmt = $conn->prepare("
            INSERT INTO audit_log (userId, action, tableName, recordId, ipAddress, details) 
            VALUES (?, 'APPROVE_FARM', 'farms', ?, ?, ?)
        ");
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $details = json_encode([
            'farm_name' => $farm['name'],
            'owner' => $farm['firstName'] . ' ' . $farm['lastName']
        ]);
        $logStmt->bind_param("iiss", $userId, $farmId, $ip, $details);
        $logStmt->execute();
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Farm approved successfully',
        'farm_id' => $farmId
    ]);
} else {
    echo json_encode([
        'success' => false, 
        'message' => 'Database error: ' . $conn->error
    ]);
}
?>