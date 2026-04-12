<?php
// api/reject-farm.php - FIXED FOR PDO
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$farmId = intval($input['farmId'] ?? 0);
$reason = trim($input['reason'] ?? '');

if (!$farmId || empty($reason)) {
    echo json_encode(['success' => false, 'message' => 'Invalid data']);
    exit;
}

try {
    $pdo = new PDO("mysql:host=localhost;dbname=myproject4;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $userId = $_SESSION['user_id'];

    // Check farm exists & is Pending
    $stmt = $pdo->prepare("
        SELECT f.*, u.firstName, u.lastName 
        FROM farms f 
        JOIN users u ON f.ownerId = u.id 
        WHERE f.id = ? AND f.status = 'Pending'
    ");
    $stmt->execute([$farmId]);
    $farm = $stmt->fetch();

    if (!$farm) {
        echo json_encode(['success' => false, 'message' => 'Farm not found or not pending']);
        exit;
    }

    // Reject farm
    $stmt = $pdo->prepare("
        UPDATE farms SET 
            status = 'Rejected',
            rejection_reason = ?,
            updatedAt = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    $stmt->execute([$reason, $farmId]);

    // Audit log
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $stmt = $pdo->prepare("
        INSERT INTO audit_log (userId, action, tableName, recordId, ipAddress, details) 
        VALUES (?, 'REJECT_FARM', 'farms', ?, ?, ?) ");
    $details = json_encode([
        'farm_id' => $farmId,
        'farm_name' => $farm['name'],
        'owner' => $farm['firstName'] . ' ' . $farm['lastName'],
        'reason' => $reason,
        'officer_id' => $userId
    ]);
    $stmt->execute([$userId, $farmId, $ip, $details]);

    echo json_encode([
        'success' => true,
        'message' => 'Farm rejected successfully',
        'farm_id' => $farmId
    ]);

} catch (PDOException $e) {
    error_log("Reject farm error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>