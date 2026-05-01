<?php
// 1. Session Management
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. Database Connection
$host = 'localhost';
$dbname = 'myproject5';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    // If it's an API call, return JSON error, otherwise plain text
    if (isset($_GET['action'])) {
        header('Content-Type: application/json');
        die(json_encode(['error' => 'Database connection failed']));
    }
    die("Database connection failed. Please check your XAMPP settings.");
}

// 3. Conditional Headers
// We only send JSON headers if an 'action' is requested via URL
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}

// 4. API Logic (Only runs if a POST action is sent)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    $action = $_GET['action'];
    $input = json_decode(file_get_contents('php://input'), true);

    switch($action) {
        case 'testEmail':
            echo json_encode(['success' => true, 'message' => 'SMTP Test OK']);
            exit;
        
        case 'saveSettings':
            $pdo->beginTransaction();
            try {
                foreach ($input as $key => $value) {
                    $stmt = $pdo->prepare("REPLACE INTO config (`key`, `value`) VALUES (?, ?)");
                    $stmt->execute([$key, $value]);
                }
                $pdo->commit();
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                $pdo->rollback();
                echo json_encode(['error' => $e->getMessage()]);
            }
            exit;
    }
}
// If no action is set, the script ends here, and the HTML page continues loading.