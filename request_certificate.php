<?php
session_start();
require_once 'config/database.php'; // Adjust path as needed

if ($_POST['user_id'] && $_SESSION['user_id'] == $_POST['user_id']) {
    $userId = $_POST['user_id'];
    
    // Get first approved farm
    $stmt = $pdo->prepare("SELECT * FROM farms WHERE ownerId = ? AND status = 'Approved' LIMIT 1");
    $stmt->execute([$userId]);
    $farm = $stmt->fetch();
    
    if ($farm) {
        // Generate certificate number
        $certNo = 'CERT-' . date('Y') . '-' . strtoupper(substr($farm['name'], 0, 3)) . '-' . sprintf('%04d', time() % 10000);
        
        // Insert certificate
        $stmt = $pdo->prepare("
            INSERT INTO certificates (farm_id, certificate_no, recipient_name, recipient_location, scope, valid_until) 
            VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 3 YEAR))
        ");
        $scope = "This certifies that " . $farm['name'] . " is compliant with DA Camarines Sur livestock monitoring standards.";
        $stmt->execute([
            $farm['id'], 
            $certNo, 
            $_SESSION['firstName'] . ' ' . ($_SESSION['lastName'] ?? ''),
            $farm['address'] ?? 'Camarines Sur',
            $scope
        ]);
        
        $_SESSION['success'] = 'Certificate generated successfully!';
        header('Location: farmer.php#e-certificate');
    } else {
        $_SESSION['error'] = 'No approved farms found.';
        header('Location: farmer.php#e-certificate');
    }
} else {
    header('Location: farmer.php');
}
?>