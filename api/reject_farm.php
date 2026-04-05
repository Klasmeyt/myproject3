<?php
// api/reject_farm.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Include database connection
include '../config/db.php'; // Adjust path to your DB config

// Start session for audit logging
session_start();

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$farmId = intval($input['farmId'] ?? 0);
$reason = trim($input['reason'] ?? '');

// Validate input
if (!$farmId || $farmId <= 0) {
    echo json_encode([
        'success' => false, 
        'message' => 'Invalid farm ID'
    ]);
    exit;
}

if (empty($reason)) {
    echo json_encode([
        'success' => false, 
        'message' => 'Rejection reason is required'
    ]);
    exit;
}

// Get current user for audit log (Agriculture Official/Admin)
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

if (!$farm) {
    echo json_encode([
        'success' => false, 
        'message' => 'Farm not found'
    ]);
    exit;
}

if ($farm['status'] !== 'Pending') {
    echo json_encode([
        'success' => false, 
        'message' => 'Farm is not pending approval'
    ]);
    exit;
}

// Update farm status to Rejected
$stmt = $conn->prepare("
    UPDATE farms 
    SET status = 'Rejected', 
        updatedAt = CURRENT_TIMESTAMP,
        address = CONCAT(COALESCE(address, ''), ' | Rejected: ', ?)
    WHERE id = ?
");
$stmt->bind_param("si", $reason, $farmId);

if ($stmt->execute()) {
    // Log audit trail
    if ($userId) {
        $logStmt = $conn->prepare("
            INSERT INTO audit_log (userId, action, tableName, recordId, ipAddress, details) 
            VALUES (?, 'REJECT_FARM', 'farms', ?, ?, ?)
        ");
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $details = json_encode([
            'farm_name' => $farm['name'],
            'owner' => $farm['firstName'] . ' ' . $farm['lastName'],
            'reason' => $reason
        ]);
        $logStmt->bind_param("iiss", $userId, $farmId, $ip, $details);
        $logStmt->execute();
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Farm rejected successfully',
        'farm_id' => $farmId,
        'farm_name' => $farm['name']
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $conn->error
    ]);
}

$stmt->close();
$conn->close();
?>