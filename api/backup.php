<?php
header('Content-Type: application/json');
$host = 'localhost'; $dbname = 'myproject4'; $username = 'root'; $password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
}

$action = $_GET['action'] ?? '';

switch($action) {
    case 'stats':
        // Get ALL tables dynamically from your exact database
        $tables = [];
        $totalRecords = 0;
        $totalSize = 0;
        
        $stmt = $pdo->query("SHOW TABLE STATUS FROM `$dbname`");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $tables[] = $row['Name'];
            $totalRecords += $row['Rows'];
            $totalSize += $row['Data_length'] + $row['Index_length'];
        }
        
        echo json_encode([
            'tables' => count($tables),
            'records' => $totalRecords,
            'size' => number_format($totalSize / 1024 / 1024, 1) . ' MB',
            'tablesList' => $tables
        ]);
        break;
        
    case 'create':
        $backupDir = __DIR__ . '/backups/';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        $filename = 'agritrace_full_backup_' . date('Y-m-d_H-i-s') . '.sql';
        $filepath = $backupDir . $filename;
        
        // Dynamic full SQL dump - ALL your tables
        $sql = "-- AgriTrace+ Full Database Backup\n";
        $sql .= "-- Database: myproject4\n";
        $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "-- Tables: " . implode(', ', array_column(json_decode(file_get_contents('php://input'), true), 'tablesList')) . "\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
        
        // Get ALL tables dynamically
        $stmt = $pdo->query("SHOW TABLES");
        $allTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($allTables as $table) {
            $sql .= "-- =============================================\n";
            $sql .= "-- TABLE: `$table`\n";
            $sql .= "-- =============================================\n\n";
            
            // Table structure
            $result = $pdo->query("SHOW CREATE TABLE `$table`");
            $row = $result->fetch(PDO::FETCH_ASSOC);
            $sql .= "DROP TABLE IF EXISTS `$table`;\n";
            $sql .= $row['Create Table'] . ";\n\n";
            
            // Table data
            $stmt = $pdo->query("SELECT * FROM `$table`");
            $columns = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (empty($columns)) {
                    $columns = array_keys($row);
                    $sql .= "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES\n";
                }
                
                $values = array_map(function($val) {
                    return $val === null ? 'NULL' : 
                           (is_numeric($val) ? $val : 
                           "'" . str_replace("'", "''", $val) . "'");
                }, $row);
                
                $sql .= "(" . implode(', ', $values) . "),\n";
            }
            
            if (!empty($columns)) {
                $sql = rtrim($sql, ",\n") . ";\n\n";
            }
        }
        
        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
        
        if (file_put_contents($filepath, $sql) !== false) {
            echo json_encode([
                'success' => true,
                'filename' => $filename,
                'download_url' => 'backup.php?action=download&file=' . urlencode($filename),
                'size' => number_format(filesize($filepath) / 1024, 1) . ' KB',
                'tables' => count($allTables),
                'records' => 'All data'
            ]);
        } else {
            echo json_encode(['error' => 'Failed to write backup file']);
        }
        break;
        
    case 'download':
        $file = $_GET['file'] ?? '';
        $filepath = __DIR__ . '/backups/' . basename($file);
        
        if (file_exists($filepath) && strpos($filepath, __DIR__ . '/backups/') === 0) {
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($filepath) . '"');
            header('Content-Transfer-Encoding: binary');
            header('Expires: 0');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Pragma: public');
            header('Content-Length: ' . filesize($filepath));
            readfile($filepath);
            exit;
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Backup file not found or access denied']);
        }
        break;
        
    case 'list':
        // Same HTML list page as before (unchanged)
        $backups = glob(__DIR__ . '/backups/*.sql');
        $backupList = array_map(function($path) {
            return [
                'filename' => basename($path),
                'size' => number_format(filesize($path) / 1024, 1) . ' KB',
                'date' => date('M d, Y H:i', filemtime($path))
            ];
        }, $backups);
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>AgriTrace+ Backup History</title>
            <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Syne:wght@700&display=swap" rel="stylesheet">
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { font-family: 'DM Sans', sans-serif; background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%); min-height: 100vh; padding: 20px; }
                .container { max-width: 900px; margin: 0 auto; background: white; border-radius: 20px; box-shadow: 0 25px 50px rgba(0,0,0,0.1); overflow: hidden; }
                .header { background: linear-gradient(135deg, #064e3b 0%, #059669 100%); color: white; padding: 40px; text-align: center; }
                .header h1 { font-family: 'Syne', sans-serif; font-size: 2.5rem; margin-bottom: 10px; }
                .header p { font-size: 1.1rem; opacity: 0.9; }
                .content { padding: 40px; }
                table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
                th, td { padding: 20px 16px; text-align: left; border-bottom: 1px solid #f1f5f9; }
                th { background: #f8fafc; font-weight: 700; color: #374151; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; }
                tr:hover { background: #f8fafc; transform: scale(1.01); transition: all 0.2s; }
                .download-btn { 
                    background: linear-gradient(135deg, #10b981, #059669); 
                    color: white; padding: 12px 24px; border: none; border-radius: 12px; 
                    text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 8px;
                    box-shadow: 0 4px 15px rgba(16,185,129,0.3); transition: all 0.3s;
                }
                .download-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(16,185,129,0.4); }
                .empty { text-align: center; padding: 80px 40px; color: #9ca3af; }
                .empty i { font-size: 5rem; display: block; margin-bottom: 20px; opacity: 0.5; }
                .back-btn { color: #3b82f6; text-decoration: none; font-weight: 600; padding: 15px 30px; border: 2px solid #dbeafe; border-radius: 12px; display: inline-block; margin-top: 20px; transition: all 0.3s; }
                .back-btn:hover { background: #3b82f6; color: white; }
                @media (max-width: 768px) { .content { padding: 20px; } th, td { padding: 12px 8px; font-size: 0.9rem; } }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <i class="bi bi-archive" style="font-size: 3rem; display: block; margin-bottom: 15px;"></i>
                    <h1>📦 Backup History</h1>
                    <p>AgriTrace+ Complete Database Backups</p>
                </div>
                <div class="content">
                    <?php if (empty($backupList)): ?>
                        <div class="empty">
                            <i class="bi bi-inbox"></i>
                            <h3>No backups found</h3>
                            <p>Create your first backup from the <strong>Data Management</strong> panel</p>
                            <a href="../admin.php#panel-data" class="back-btn">← Back to Admin</a>
                        </div>
                    <?php else: ?>
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
                                <?php foreach (array_reverse($backupList) as $backup): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($backup['filename']) ?></strong></td>
                                        <td><span style="color: #059669; font-weight: 600;"><?= $backup['size'] ?></span></td>
                                        <td><?= $backup['date'] ?></td>
                                        <td>
                                            <a href="backup.php?action=download&file=<?= urlencode($backup['filename']) ?>" 
                                               class="download-btn" download>
                                                <i class="bi bi-download"></i> Download
                                                                                        </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <div style="text-align: center; padding: 25px; background: #f8fafc; border-top: 2px solid #e5e7eb; margin-top: 20px; border-radius: 12px;">
                            <p style="color: #6b7280; margin-bottom: 15px;">
                                <i class="bi bi-shield-check" style="color: #10b981;"></i>
                                All backups are securely stored and can be downloaded anytime
                            </p>
                            <a href="../admin.php" class="back-btn">
                                <i class="bi bi-arrow-left"></i> Back to Admin Panel
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </body>
        </html>
        <?php
        break;
        
    default:
        echo json_encode(['error' => 'Invalid action']);
        break;
}
?>