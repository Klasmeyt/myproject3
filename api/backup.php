<?php
header('Content-Type: application/json');
$host = 'localhost'; $dbname = 'myproject4'; $username = 'root'; $password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die(json_encode(['error' => 'Database connection failed']));
}

$action = $_GET['action'] ?? '';

switch($action) {
    case 'create':
        $backupDir = __DIR__ . '/backups/';
        if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
        
        $filename = 'agritrace_full_backup_' . date('Y-m-d_His') . '.sql';
        $filepath = $backupDir . $filename;
        
        // Create full SQL dump
        $tables = ['users', 'farms', 'livestock', 'incidents', 'public_reports', 'audit_log', 'officer_permissions', 'config'];
        $sql = "-- AgriTrace+ Full Database Backup\n-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
        
        foreach ($tables as $table) {
            $sql .= "-- Table: $table\n";
            $result = $pdo->query("SHOW CREATE TABLE `$table`");
            $row = $result->fetch(PDO::FETCH_ASSOC);
            $sql .= $row['Create Table'] . ";\n\n";
            
            $stmt = $pdo->query("SELECT * FROM `$table`");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $sql .= "INSERT INTO `$table` (";
                $sql .= implode(', ', array_map(fn($col) => "`$col`", array_keys($row)));
                $sql .= ") VALUES (";
                $values = array_map(fn($val) => is_null($val) ? 'NULL' : "'".str_replace("'", "''", $val)."'", $row);
                $sql .= implode(', ', $values) . ");\n";
            }
            $sql .= "\n";
        }
        
        file_put_contents($filepath, $sql);
        
        // Return download URL (adjust path as needed)
        echo json_encode([
            'success' => true,
            'filename' => $filename,
            'download_url' => 'api/backup.php?action=download&file=' . urlencode($filename),
            'size' => number_format(filesize($filepath) / 1024, 1) . ' KB',
            'tables' => count($tables)
        ]);
        break;
        
    case 'download':
        $file = $_GET['file'] ?? '';
        $filepath = __DIR__ . '/backups/' . basename($file);
        
        if (file_exists($filepath)) {
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($filepath) . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($filepath));
            readfile($filepath);
            exit;
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Backup file not found']);
        }
        break;
        
    case 'list':
        $backups = glob(__DIR__ . '/backups/*.sql');
        $backupList = array_map(function($path) {
            return [
                'filename' => basename($path),
                'size' => number_format(filesize($path) / 1024, 1) . ' KB',
                'date' => date('M d, Y H:i', filemtime($path))
            ];
        }, $backups);
        
        // HTML backup list page
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>AgriTrace+ Backup History</title>
            <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Syne:wght@700&display=swap" rel="stylesheet">
            <style>
                body { font-family: 'DM Sans', sans-serif; background: #f8fafc; padding: 40px; margin: 0; }
                .container { max-width: 800px; margin: 0 auto; background: white; border-radius: 16px; box-shadow: 0 20px 40px rgba(0,0,0,0.1); overflow: hidden; }
                .header { background: linear-gradient(135deg, #064e3b, #059669); color: white; padding: 30px; text-align: center; }
                .header h1 { margin: 0; font-family: 'Syne', sans-serif; font-size: 2rem; }
                table { width: 100%; border-collapse: collapse; }
                th, td { padding: 16px; text-align: left; border-bottom: 1px solid #e5e7eb; }
                th { background: #f8fafc; font-weight: 700; color: #374151; font-size: 0.85rem; text-transform: uppercase; }
                tr:hover { background: #f8fafc; }
                .download-btn { background: #10b981; color: white; padding: 10px 20px; border: none; border-radius: 8px; text-decoration: none; font-weight: 600; }
                .download-btn:hover { background: #059669; }
                .empty { text-align: center; padding: 60px; color: #9ca3af; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>📦 Backup History</h1>
                    <p>AgriTrace+ Database Backups</p>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Filename</th>
                            <th>Size</th>
                            <th>Created</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($backupList)): ?>
                            <tr><td colspan="4" class="empty">
                                <i class="bi bi-archive" style="font-size: 4rem; display: block; margin-bottom: 15px;"></i>
                                <p>No backups found</p>
                            </td></tr>
                        <?php else: ?>
                            <?php foreach (array_reverse($backupList) as $backup): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($backup['filename']) ?></strong></td>
                                    <td><?= $backup['size'] ?></td>
                                    <td><?= $backup['date'] ?></td>
                                    <td>
                                        <a href="backup.php?action=download&file=<?= urlencode($backup['filename']) ?>" 
                                           class="download-btn" download>
                                            <i class="bi bi-download"></i> Download
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                <div style="padding: 25px; text-align: center; background: #f8fafc; border-top: 1px solid #e5e7eb;">
                    <a href="../admin.php" style="color: #3b82f6; text-decoration: none; font-weight: 600;">
                        ← Back to Admin Panel
                    </a>
                </div>
            </div>
        </body>
        </html>
        <?php
        break;
        
    default:
        echo json_encode(['error' => 'Invalid action']);
}
?>