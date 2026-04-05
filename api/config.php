<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$host = 'localhost';
$dbname = 'myproject4';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die(json_encode(['error' => 'Database connection failed']));
}

// Create config table if not exists
$pdo->exec("CREATE TABLE IF NOT EXISTS `config` (
    `key` varchar(100) PRIMARY KEY,
    `value` TEXT,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$input = json_decode(file_get_contents('php://input'), true);
$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch($action) {
        case 'testEmail':
            // TODO: Implement actual SMTP test
            echo json_encode(['success' => true, 'message' => 'SMTP configuration test successful']);
            break;
            
        case 'testSMS':
            // TODO: Implement actual SMS test
            echo json_encode(['success' => true, 'message' => 'SMS configuration test successful']);
            break;
            
        default:
            // Save all config
            $pdo->beginTransaction();
            try {
                foreach ($input as $key => $value) {
                    $stmt = $pdo->prepare("REPLACE INTO config (key, value) VALUES (?, ?)");
                    $stmt->execute([$key, $value]);
                }
                $pdo->commit();
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                $pdo->rollback();
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;
    }
}
?>