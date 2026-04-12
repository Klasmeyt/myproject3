<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_role'] !== 'Farmer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];
$ownerName = $_SESSION['firstName'] . ' ' . $_SESSION['lastName'];

try {
    $pdo = new PDO("mysql:host=localhost;dbname=myproject4;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $pdo->beginTransaction();

    // 1. Create Farm
    $farmStmt = $pdo->prepare("
        INSERT INTO farms (name, ownerId, ownerName, type, address, latitude, longitude, area, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending')
    ");
    $farmStmt->execute([
        $_POST['farm_name'],
        $userId,
        $ownerName,
        $_POST['farm_type'],
        $_POST['farm_address'],
        $_POST['latitude'],
        $_POST['longitude'],
        $_POST['farm_area'] ?? null
    ]);
    $farmId = $pdo->lastInsertId();

    // 2. Save Farm Photo (base64)
    if (!empty($_POST['photo_data'])) {
        $photoData = str_replace('data:image/jpeg;base64,', '', $_POST['photo_data']);
        $photoData = str_replace(' ', '+', $photoData);
        $photoPath = "uploads/farms/farm_{$farmId}_" . time() . ".jpg";
        
        if (!is_dir('uploads/farms')) {
            mkdir('uploads/farms', 0755, true);
        }
        file_put_contents($photoPath, base64_decode($photoData));
    }

    // 3. Create Livestock
    $livestockStmt = $pdo->prepare("
        INSERT INTO livestock (farmId, type, tagId, breed, age, qty, latitude, longitude, healthStatus) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Healthy')
    ");
    $livestockStmt->execute([
        $farmId,
        $_POST['livestock_type'],
        $_POST['livestock_tag'],
        $_POST['livestock_breed'] ?? null,
        $_POST['livestock_age'] ?? null,
        $_POST['livestock_qty'],
        $_POST['latitude'],
        $_POST['longitude']
    ]);

    // 4. Log audit
    $auditStmt = $pdo->prepare("
        INSERT INTO audit_log (userId, action, tableName, recordId, details) 
        VALUES (?, 'CREATE_FARM_LIVESTOCK', 'farms', ?, ?)
    ");
    $auditStmt->execute([$userId, $farmId, json_encode($_POST)]);

    $pdo->commit();

    echo json_encode([
        'success' => true, 
        'message' => 'Farm and livestock registered successfully!',
        'farm_id' => $farmId
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Farm registration error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>