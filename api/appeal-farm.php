<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// 1. Authentication Check
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_role'] !== 'Farmer') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$userId = $_SESSION['user_id'] ?? 0;

if ($userId === 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid user session']);
    exit;
}

// 2. Get Input Data
$input = json_decode(file_get_contents('php://input'), true);
$farmId = intval($input['farmId'] ?? 0);

if ($farmId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid farm ID']);
    exit;
}

// 3. Database Connection
try {
    $pdo = new PDO("mysql:host=localhost;dbname=myproject4;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
} catch(PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

try {
    $pdo->beginTransaction();
    
    // 4. Verify farm belongs to user and is rejected
    $stmt = $pdo->prepare("
        SELECT id, name, status, rejection_reason, ownerId 
        FROM farms 
        WHERE id = ? AND ownerId = ?
    ");
    $stmt->execute([$farmId, $userId]);
    $farm = $stmt->fetch();
    
    if (!$farm) {
        echo json_encode(['success' => false, 'message' => 'Farm not found or access denied']);
        $pdo->rollBack();
        exit;
    }
    
    if ($farm['status'] !== 'Rejected') {
        echo json_encode(['success' => false, 'message' => 'Farm is not rejected. Cannot appeal.']);
        $pdo->rollBack();
        exit;
    }
    
    // 5. Check if already appealed (prevent spam)
    $stmt = $pdo->prepare("
        SELECT id FROM farm_appeals 
        WHERE farm_id = ? AND user_id = ? AND status IN ('Pending', 'Under Review')
    ");
    $stmt->execute([$farmId, $userId]);
    
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Appeal already pending. Please wait for admin review.']);
        $pdo->rollBack();
        exit;
    }
    
    // 6. Create appeal record
    $appealData = [
        'farm_id' => $farmId,
        'user_id' => $userId,
        'farm_name' => $farm['name'],
        'original_status' => $farm['status'],
        'rejection_reason' => $farm['rejection_reason'],
        'appeal_status' => 'Pending',
        'appeal_notes' => "Farmer appealed rejection of farm '{$farm['name']}'. Original reason: {$farm['rejection_reason']}",
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ];
    
    $stmt = $pdo->prepare("
        INSERT INTO farm_appeals (farm_id, user_id, farm_name, original_status, rejection_reason, 
                                  appeal_status, appeal_notes, created_at, updated_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $appealData['farm_id'],
        $appealData['user_id'],
        $appealData['farm_name'],
        $appealData['original_status'],
        $appealData['rejection_reason'],
        $appealData['appeal_status'],
        $appealData['appeal_notes'],
        $appealData['created_at'],
        $appealData['updated_at']
    ]);
    
    $appealId = $pdo->lastInsertId();
    
    // 7. Update farm status to 'Under Appeal'
    $stmt = $pdo->prepare("
        UPDATE farms 
        SET status = 'Under Appeal', 
            updated_at = NOW(),
            appeal_id = ?
        WHERE id = ?
    ");
    $stmt->execute([$appealId, $farmId]);
    
    // 8. Create admin notification
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, type, title, message, related_id, related_type, status, created_at) 
        VALUES (0, 'farm_appeal', 'Farm Appeal Received', 
                'Farmer appealed rejected farm: {$farm["name"]} (ID: {$farmId})', 
                ?, 'farm', 'Unread', NOW())
    ");
    $stmt->execute([$farmId]);
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Appeal submitted successfully! Admin will review within 24-48 hours.',
        'appeal_id' => $appealId,
        'farm_id' => $farmId
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Appeal farm error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Appeal processing failed. Please try again.']);
}
?>