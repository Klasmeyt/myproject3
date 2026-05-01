<?php
// api/chat.php – Farmer-to-Farmer Chat API
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit;
}

$userId   = (int)$_SESSION['user_id'];
$userRole = $_SESSION['user_role'] ?? '';

try {
    $pdo = new PDO("mysql:host=localhost;dbname=myproject4;charset=utf8mb4","root","",[
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch(PDOException $e) {
    echo json_encode(['success'=>false,'message'=>'DB error']); exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

    // ── List all farmers (to start a chat) ────────────────────────────────────
    case 'list_farmers':
        $stmt = $pdo->prepare("
            SELECT u.id, u.firstName, u.lastName, u.mobile,
                   fp.profile_pix,
                   (SELECT COUNT(*) FROM chat_messages cm 
                    WHERE cm.sender_id = u.id AND cm.receiver_id = ? AND cm.is_read = 0) as unread
            FROM users u
            LEFT JOIN farmer_profiles fp ON u.id = fp.user_id
            WHERE u.role = 'Farmer' AND u.status = 'Active' AND u.id != ?
            ORDER BY u.firstName ASC
        ");
        $stmt->execute([$userId, $userId]);
        echo json_encode(['success'=>true,'farmers'=>$stmt->fetchAll()]);
        break;

    // ── Get conversation messages ──────────────────────────────────────────────
    case 'get_messages':
        $otherId  = (int)($_GET['other_id'] ?? 0);
        $lastId   = (int)($_GET['last_id']  ?? 0);
        if (!$otherId) { echo json_encode(['success'=>false,'message'=>'Invalid user']); break; }

        // Mark as read
        $pdo->prepare("UPDATE chat_messages SET is_read=1, read_at=NOW() WHERE receiver_id=? AND sender_id=? AND is_read=0")
            ->execute([$userId, $otherId]);

        $stmt = $pdo->prepare("
            SELECT cm.id, cm.sender_id, cm.receiver_id, cm.message, cm.is_read, cm.created_at,
                   u.firstName, u.lastName
            FROM chat_messages cm
            JOIN users u ON cm.sender_id = u.id
            WHERE ((cm.sender_id=? AND cm.receiver_id=?) OR (cm.sender_id=? AND cm.receiver_id=?))
              AND cm.id > ?
            ORDER BY cm.created_at ASC
            LIMIT 100
        ");
        $stmt->execute([$userId,$otherId,$otherId,$userId,$lastId]);
        echo json_encode(['success'=>true,'messages'=>$stmt->fetchAll()]);
        break;

    // ── Send a message ─────────────────────────────────────────────────────────
    case 'send_message':
        $input    = json_decode(file_get_contents('php://input'), true);
        $otherId  = (int)($input['receiver_id'] ?? 0);
        $message  = trim($input['message'] ?? '');

        if (!$otherId || !$message) { echo json_encode(['success'=>false,'message'=>'Invalid data']); break; }
        if (mb_strlen($message) > 2000) { echo json_encode(['success'=>false,'message'=>'Message too long']); break; }

        // Verify receiver is a farmer
        $check = $pdo->prepare("SELECT id FROM users WHERE id=? AND role='Farmer' AND status='Active'");
        $check->execute([$otherId]);
        if (!$check->fetch()) { echo json_encode(['success'=>false,'message'=>'Recipient not found']); break; }

        $pdo->prepare("INSERT INTO chat_messages (sender_id,receiver_id,message) VALUES (?,?,?)")
            ->execute([$userId,$otherId,$message]);
        $msgId = $pdo->lastInsertId();

        // Notification
        $pdo->prepare("INSERT INTO notifications (user_id,type,title,message,related_id,related_type) VALUES (?,?,?,?,?,'chat')")
            ->execute([$otherId,'chat_message','New Message from '.$_SESSION['firstName'],"You have a new message.",$msgId]);

        echo json_encode(['success'=>true,'message_id'=>$msgId]);
        break;

    // ── Unread count ──────────────────────────────────────────────────────────
    case 'unread_count':
        $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM chat_messages WHERE receiver_id=? AND is_read=0");
        $stmt->execute([$userId]);
        echo json_encode(['success'=>true,'count'=>(int)$stmt->fetchColumn()]);
        break;

    // ── Get own profile info (for chat header) ────────────────────────────────
    case 'my_info':
        $stmt = $pdo->prepare("SELECT u.id,u.firstName,u.lastName,fp.profile_pix FROM users u LEFT JOIN farmer_profiles fp ON u.id=fp.user_id WHERE u.id=?");
        $stmt->execute([$userId]);
        echo json_encode(['success'=>true,'user'=>$stmt->fetch()]);
        break;

    default:
        echo json_encode(['success'=>false,'message'=>'Invalid action']);
}
?>