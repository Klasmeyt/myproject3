<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $pdo = new PDO('mysql:host=localhost;dbname=myproject4;charset=utf8mb4', 'username', 'password');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get farms with owner details and livestock count
    $stmt = $pdo->prepare("
        SELECT 
            f.*,
            CONCAT(u.firstName, ' ', u.lastName) as ownerName,
            u.mobile as ownerMobile,
            u.email as ownerEmail,
            (SELECT SUM(l.qty) FROM livestock l WHERE l.farmId = f.id) as total_livestock,
            (SELECT COUNT(c.id) FROM certificates c WHERE c.farm_id = f.id AND c.status = 'Active') as active_certificates,
            fap.appeal_status,
            fap.rejection_reason
        FROM farms f
        LEFT JOIN users u ON f.ownerId = u.id
        LEFT JOIN farm_appeals fap ON f.appeal_id = fap.id
        WHERE f.latitude IS NOT NULL AND f.longitude IS NOT NULL
        ORDER BY f.createdAt DESC
    ");
    
    $stmt->execute();
    $farms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'farms' => $farms,
        'count' => count($farms)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>