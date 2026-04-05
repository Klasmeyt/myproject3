<?php
header('Content-Type: application/json');
require_once 'config.php'; // Your DB connection

$action = $_GET['action'] ?? '';

switch($action) {
    case 'stats':
        $stats = [
            'totalLivestock' => getTotalLivestock($pdo),
            'totalFarms' => getTotalFarms($pdo),
            'totalIncidents' => getRecentIncidents($pdo),
            'healthRate' => getHealthRate($pdo)
        ];
        echo json_encode($stats);
        break;
        
    case 'farms':
        $stmt = $pdo->query("
            SELECT farms.*, users.firstName, users.lastName, 
                   (SELECT SUM(qty) FROM livestock WHERE farmId = farms.id) as total_livestock
            FROM farms LEFT JOIN users ON farms.ownerId = users.id
            WHERE farms.status = 'Approved'
        ");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        break;
}

function getTotalLivestock($pdo) {
    $stmt = $pdo->query("SELECT SUM(qty) as total FROM livestock WHERE healthStatus = 'Healthy'");
    return $stmt->fetchColumn() ?: 0;
}
// Add other functions...
?>