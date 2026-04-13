<?php
session_start();
header('Content-Type: application/json');

// 1. Authentication Check
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_role'] !== 'Farmer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$userId = $_SESSION['user_id'];
$userName = $_SESSION['firstName'] . ' ' . ($_SESSION['lastName'] ?? '');

// 2. Database Connection
try {
    $pdo = new PDO("mysql:host=localhost;dbname=myproject4;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Extract form data
    $farmName = trim($_POST['farm_name'] ?? '');
    $farmAddress = trim($_POST['farm_address'] ?? '');
    $farmType = $_POST['farm_type'] ?? '';
    $farmArea = !empty($_POST['farm_area']) ? (float)$_POST['farm_area'] : null;
    $latitude = !empty($_POST['latitude']) ? (float)$_POST['latitude'] : null;
    $longitude = !empty($_POST['longitude']) ? (float)$_POST['longitude'] : null;
    
    $livestockType = $_POST['livestock_type'] ?? '';
    $livestockQty = (int)($_POST['livestock_qty'] ?? 1);
    $livestockTag = trim($_POST['livestock_tag'] ?? '');
    $livestockBreed = trim($_POST['livestock_breed'] ?? null);
    $livestockAge = !empty($_POST['livestock_age']) ? (int)$_POST['livestock_age'] : null;

    // Validation
    $errors = [];
    if (empty($farmName)) $errors[] = 'Farm name is required';
    if (empty($farmAddress)) $errors[] = 'Farm address is required';
    if (!in_array($farmType, ['Cattle','Swine','Poultry','Goat','Mixed'])) $errors[] = 'Invalid farm type';
    if (empty($livestockType)) $errors[] = 'Livestock type is required';
    if (empty($livestockTag)) $errors[] = 'Livestock tag ID is required';
    if ($livestockQty < 1) $errors[] = 'Livestock quantity must be at least 1';
    if (empty($_POST['photo_data'])) $errors[] = 'Photo with GPS stamp is required';

    if (!empty($errors)) {
        throw new Exception(implode('; ', $errors));
    }

    // 3. Save Photo (Base64 to file)
    $photoData = $_POST['photo_data'];
    if (preg_match('/^data:image\/(jpeg|png|jpg);base64,(.*)$/i', $photoData, $matches)) {
        $photoData = base64_decode($matches[2]);
        $photoFilename = 'farm_photos/' . $userId . '_' . time() . '_' . uniqid() . '.jpg';
        
        // Create directory if needed
        if (!is_dir('farm_photos')) {
            mkdir('farm_photos', 0755, true);
        }
        
        file_put_contents($photoFilename, $photoData);
    } else {
        throw new Exception('Invalid photo data');
    }

    // 4. Insert Farm FIRST
    $stmt = $pdo->prepare("
        INSERT INTO farms (name, ownerId, ownerName, type, address, latitude, longitude, area, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending')
    ");
    $stmt->execute([
        $farmName, $userId, $userName, $farmType, $farmAddress, 
        $latitude, $longitude, $farmArea
    ]);
    $farmId = $pdo->lastInsertId();

    // 5. Insert Livestock
    $stmt = $pdo->prepare("
        INSERT INTO livestock (farmId, type, tagId, breed, age, qty, healthStatus, latitude, longitude, notes) 
        VALUES (?, ?, ?, ?, ?, ?, 'Healthy', ?, ?, ?)
    ");
    $stmt->execute([
        $farmId, $livestockType, $livestockTag, $livestockBreed, $livestockAge, 
        $livestockQty, $latitude, $longitude, "Photo: $photoFilename"
    ]);
    $livestockId = $pdo->lastInsertId();

    // 6. Log audit trail
    $stmt = $pdo->prepare("
        INSERT INTO audit_log (userId, action, tableName, recordId, details, ipAddress) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $userId, 'CREATE_FARM_LIVESTOCK', 'farms/livestock', $farmId,
        json_encode(['livestock_id' => $livestockId, 'photo' => $photoFilename]),
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);

    // 7. Create notification
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, type, title, message, related_id, related_type) 
        VALUES (?, 'farm_registration', ?, ?, ?, 'farm')
    ");
    $stmt->execute([
        $userId,
        "New Farm Registered",
        "Farm '$farmName' with $livestockQty $livestockType registered successfully. Awaiting approval.",
        $farmId
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true, 
        'message' => 'Farm and livestock registered successfully!',
        'farm_id' => $farmId,
        'livestock_id' => $livestockId
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Farm Registration Error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>