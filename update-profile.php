<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

try {
    $pdo = new PDO('mysql:host=localhost;dbname=myproject4;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    $user_id = $_POST['user_id'];
    
    // Update users table
    $stmt = $pdo->prepare("
        UPDATE users SET 
            firstName = ?, lastName = ?, email = ?, mobile = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $_POST['firstName'],
        $_POST['lastName'],
        $_POST['email'],
        $_POST['mobile'],
        $user_id
    ]);

    // Update/Insert officer_profiles
    $stmt = $pdo->prepare("
        INSERT INTO officer_profiles (user_id, gov_id, department, position, office, assigned_region, province, municipality)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            gov_id = VALUES(gov_id),
            department = VALUES(department),
            position = VALUES(position),
            office = VALUES(office),
            assigned_region = VALUES(assigned_region),
            province = VALUES(province),
            municipality = VALUES(municipality)
    ");
    $stmt->execute([
        $user_id,
        $_POST['gov_id'] ?? '',
        $_POST['department'] ?? '',
        $_POST['position'] ?? '',
        $_POST['office'] ?? '',
        $_POST['assigned_region'] ?? '',
        $_POST['province'] ?? '',
        $_POST['municipality'] ?? ''
    ]);

    echo json_encode(['success' => true, 'message' => 'Profile updated successfully']);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>