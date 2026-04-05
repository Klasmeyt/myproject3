<?php
session_start();

// AUTHENTICATION CHECK
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['PHP_SELF']));
    exit;
}

$user_role = $_SESSION['user_role'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;

// Database connection
try {
    $pdo = new PDO('mysql:host=localhost;dbname=myproject4;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch(PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}

// Fetch dashboard stats with proper error handling
$stats = [
    'approved_farms' => $pdo->query("SELECT COUNT(*) FROM farms WHERE status = 'Approved'")->fetchColumn(),
    'pending_farms' => $pdo->query("SELECT COUNT(*) FROM farms WHERE status = 'Pending'")->fetchColumn(),
    'active_incidents' => $pdo->query("SELECT COUNT(*) FROM incidents WHERE status IN ('Pending', 'In Progress')")->fetchColumn(),
    'pending_reports' => $pdo->query("SELECT COUNT(*) FROM public_reports WHERE status = 'Pending'")->fetchColumn(),
    'total_livestock' => $pdo->query("SELECT COALESCE(SUM(qty), 0) FROM livestock")->fetchColumn()
];

// Recent incidents
$stmt = $pdo->query("
    SELECT i.*, f.name as farm_name, u.firstName, u.lastName 
    FROM incidents i 
    LEFT JOIN farms f ON i.farmId = f.id 
    LEFT JOIN users u ON i.reporterId = u.id 
    ORDER BY i.createdAt DESC LIMIT 5
");
$recent_incidents = $stmt->fetchAll();

// All farms for inspection
$stmt = $pdo->query("
    SELECT f.*, u.firstName, u.lastName,
           (SELECT COALESCE(SUM(qty), 0) FROM livestock WHERE farmId = f.id) as total_livestock
    FROM farms f 
    LEFT JOIN users u ON f.ownerId = u.id 
    ORDER BY f.createdAt DESC
");
$all_farms = $stmt->fetchAll();

// All incidents
$stmt = $pdo->query("
    SELECT i.*, f.name as farm_name 
    FROM incidents i 
    LEFT JOIN farms f ON i.farmId = f.id 
    ORDER BY i.priority DESC, i.createdAt DESC
");
$all_incidents = $stmt->fetchAll();

// All public reports
$stmt = $pdo->query("
    SELECT * FROM public_reports 
    ORDER BY createdAt DESC LIMIT 10
");
$public_reports = $stmt->fetchAll();

// User profile data
// User profile data - FIXED: Removed non-existent 'mobile' column
$stmt = $pdo->prepare("
    SELECT u.*, p.gov_id, p.department, p.position, p.office, 
           p.assigned_region, p.municipality, p.province 
    FROM users u 
    LEFT JOIN officer_profiles p ON u.id = p.user_id 
    WHERE u.id = ?
");
$stmt->execute([$user_id]);
$user_profile = $stmt->fetch();

// Get mobile from users table if it exists there
$user_profile['mobile'] = $user_profile['mobile'] ?? ''; // Default empty if not exists

// Update user info in sidebar
$user_name = $user_profile ? ($user_profile['firstName'] . ' ' . substr($user_profile['lastName'], 0, 1)) : 'G';
$user_initial = strtoupper(substr($user_name, 0, 1));
$user_fullname = $user_profile['firstName'] ?? 'Guest User';

$analytics = [
    'farm_stats' => [],
    'livestock_stats' => [],
    'regional_stats' => [],
    'incident_stats' => [],
    'totals' => [
        'total_farms' => 0,
        'total_livestock' => 0,
        'total_incidents' => 0,
        'active_incidents' => 0
    ]
];

try {
    // 1. Overall Totals
    $analytics['totals']['total_farms'] = $pdo->query("SELECT COUNT(*) FROM farms")->fetchColumn();
    $analytics['totals']['total_livestock'] = $pdo->query("SELECT COALESCE(SUM(qty), 0) FROM livestock")->fetchColumn();
    $analytics['totals']['total_incidents'] = $pdo->query("SELECT COUNT(*) FROM incidents")->fetchColumn();
    $analytics['totals']['active_incidents'] = $pdo->query("SELECT COUNT(*) FROM incidents WHERE status IN ('Pending', 'In Progress')")->fetchColumn();

    // 2. Livestock Distribution by Type
    $analytics['livestock_stats'] = $pdo->query("
        SELECT type, SUM(qty) as total_qty, AVG(weight) as avg_weight 
        FROM livestock 
        GROUP BY type
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 3. Regional Stats (Joining farms with officer_profiles for region data)
    // Note: We use the assigned_region from officer_profiles linked via ownerId or a default
    $analytics['regional_stats'] = $pdo->query("
        SELECT 
            COALESCE(op.assigned_region, 'General') as region,
            COUNT(DISTINCT f.id) as farm_count,
            COALESCE(SUM(l.qty), 0) as livestock_count
        FROM farms f
        LEFT JOIN officer_profiles op ON f.ownerId = op.user_id
        LEFT JOIN livestock l ON f.id = l.farmId
        GROUP BY region
        ORDER BY livestock_count DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // Silent fail or log error
    error_log($e->getMessage());
}

// Helper for Swine count to prevent "null" errors in the card
$swine_count = 0;
foreach ($analytics['livestock_stats'] as $s) {
    if ($s['type'] === 'Swine') {
        $swine_count = $s['total_qty'];
        break;
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $conn->begin_transaction();

    try {
        // 1. Update Core User Info
        $stmt1 = $conn->prepare("UPDATE users SET firstName = ?, lastName = ?, mobile = ? WHERE id = ?");
        $stmt1->bind_param("sssi", $_POST['firstName'], $_POST['lastName'], $_POST['mobile'], $current_user_id);
        $stmt1->execute();

        // 2. Update/Insert Officer Profile Info
        $stmt2 = $conn->prepare("INSERT INTO officer_profiles 
            (user_id, gov_id, department, position, office, assigned_region, municipality, province) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            gov_id = VALUES(gov_id), 
            department = VALUES(department), 
            position = VALUES(position), 
            office = VALUES(office), 
            assigned_region = VALUES(assigned_region), 
            municipality = VALUES(municipality), 
            province = VALUES(province)");
        
        $stmt2->bind_param("isssssss", 
            $current_user_id, $_POST['gov_id'], $_POST['department'], 
            $_POST['position'], $_POST['office'], $_POST['assigned_region'], 
            $_POST['municipality'], $_POST['province']
        );
        $stmt2->execute();

        // 3. Optional Password Change
        if (!empty($_POST['new_password'])) {
            $hashed_pass = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
            $stmt3 = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt3->bind_param("si", $hashed_pass, $current_user_id);
            $stmt3->execute();
        }

        $conn->commit();
        echo "Profile updated successfully!";
    } catch (Exception $e) {
        $conn->rollback();
        echo "Error updating profile: " . $e->getMessage();
    }
}
// Profile Update Handler - ADD THIS AFTER DATABASE CONNECTION
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    header('Content-Type: application/json');
    
    try {
        $user_id = (int)$_POST['user_id'];
        
        // 1. Handle Profile Picture Upload
        $profile_pic_path = $user_profile['profile_pix'] ?? null;
        if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/profiles/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_extension = strtolower(pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
            
            if (in_array($file_extension, $allowed_extensions)) {
                $new_filename = $user_id . '_' . time() . '.' . $file_extension;
                $upload_path = $upload_dir . $new_filename;
                
                if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $upload_path)) {
                    $profile_pic_path = $upload_path;
                    
                    // Delete old profile pic if exists
                    if ($user_profile['profile_pix'] && file_exists($user_profile['profile_pix'])) {
                        unlink($user_profile['profile_pix']);
                    }
                }
            }
        }
        
        $pdo->beginTransaction();
        
        // 2. Update Users Table
        $stmt = $pdo->prepare("
            UPDATE users SET 
                firstName = ?, lastName = ?, mobile = ?,
                updatedAt = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([
            $_POST['firstName'],
            $_POST['lastName'],
            $_POST['mobile'] ?? null,
            $user_id
        ]);
        
        // 3. Update/Insert Officer Profile
        $stmt = $pdo->prepare("
            INSERT INTO officer_profiles 
            (user_id, gov_id, department, position, office, assigned_region, municipality, province, profile_pix)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                gov_id = VALUES(gov_id),
                department = VALUES(department),
                position = VALUES(position),
                office = VALUES(office),
                assigned_region = VALUES(assigned_region),
                municipality = VALUES(municipality),
                province = VALUES(province),
                profile_pix = VALUES(profile_pix),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            $user_id,
            $_POST['gov_id'] ?? null,
            $_POST['department'] ?? null,
            $_POST['position'] ?? null,
            $_POST['office'] ?? null,
            $_POST['assigned_region'] ?? null,
            $_POST['municipality'] ?? null,
            $_POST['province'] ?? null,
            $profile_pic_path
        ]);
        
        // 4. Handle Password Change
        if (!empty($_POST['new_password'])) {
            $hashed_password = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashed_password, $user_id]);
        }
        
        $pdo->commit();
        
        echo json_encode(['success' => true, 'message' => 'Profile updated successfully!']);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Profile update error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Update failed: ' . $e->getMessage()]);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>AgriTrace+ | Official Panel</title>
  
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Syne:wght@700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <link rel="stylesheet" href="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.css" />
  
  <style>
    :root {
        --c-brand-dark: #1A4731;
        --c-brand-mid: #2D6A4F;
        --c-brand-accent: #10B981;
        --c-bg-page: #F8FAF9;
        --c-bg-card: #ffffff;
        --c-slate-50: #F1F5F9;
        --c-slate-100: #E2E8F0;
        --c-slate-200: #CBD5E1;
        --c-text-main: #1E293B;
        --c-text-sub: #64748B;
        --c-danger: #EF4444;
        --c-warning: #F59E0B;
        --c-success: #10B981;
        --sidebar-width: 280px;
        --topbar-height: 70px;
    }

    * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; margin: 0; padding: 0; }
    body { font-family: 'DM Sans', sans-serif; background: var(--c-bg-page); color: var(--c-text-main); overflow-x: hidden; -webkit-font-smoothing: antialiased; }

    .panel-sidebar { width: var(--sidebar-width); height: 100vh; position: fixed; top: 0; left: calc(-1 * var(--sidebar-width)); background: #ffffff; display: flex; flex-direction: column; transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1); z-index: 1000; border-right: 1px solid var(--c-slate-100); }
    .panel-sidebar.open { transform: translateX(var(--sidebar-width)); box-shadow: 10px 0 30px rgba(0,0,0,0.08); }

    .sidebar-header { padding: 24px; flex-shrink: 0; border-bottom: 1px solid var(--c-slate-50); }
    .sidebar-logo { font-family: 'Syne', sans-serif; font-size: 1.6rem; font-weight: 800; color: var(--c-brand-dark); letter-spacing: -1px; }
    .sidebar-logo span { color: var(--c-brand-accent); }
    .sidebar-sub { font-size: 0.7rem; color: var(--c-text-sub); text-transform: uppercase; letter-spacing: 1.5px; font-weight: 700; margin-top: 4px; }

    .sidebar-nav { flex: 1; overflow-y: auto; padding: 12px; scrollbar-width: thin; scrollbar-color: var(--c-slate-100) transparent; }
    .sidebar-nav::-webkit-scrollbar { width: 4px; }
    .sidebar-nav::-webkit-scrollbar-thumb { background: var(--c-slate-100); border-radius: 10px; }

    .nav-item { display: flex; align-items: center; gap: 14px; padding: 12px 16px; margin-bottom: 4px; border-radius: 12px; color: var(--c-text-main); text-decoration: none; cursor: pointer; transition: all 0.2s ease; font-size: 0.95rem; font-weight: 500; }
    .nav-item i { font-size: 1.2rem; color: var(--c-text-sub); }
    .nav-item:hover { background: #F0FDF4; color: var(--c-brand-dark); }
    .nav-item.active { background: linear-gradient(135deg, var(--c-brand-mid), var(--c-brand-dark)); color: #ffffff; box-shadow: 0 4px 12px rgba(26, 71, 49, 0.2); }
    .nav-item.active i { color: var(--c-brand-accent); }
    .nav-divider { height: 1px; background: var(--c-slate-50); margin: 16px; }
    .nav-item.logout { color: var(--c-danger); }
    .nav-item.logout:hover { background: #FEF2F2; }

    .sidebar-footer { padding: 18px 20px; background: var(--c-slate-50); border-top: 1px solid var(--c-slate-100); flex-shrink: 0; }
    .user-box { display: flex; align-items: center; gap: 12px; }
    .user-avatar { width: 42px; height: 42px; background: linear-gradient(135deg, var(--c-brand-mid), var(--c-brand-dark)); color: #fff; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-weight: 700; box-shadow: 0 3px 6px rgba(0,0,0,0.1); }
    .user-name { font-weight: 700; font-size: 0.9rem; color: var(--c-brand-dark); }
    .user-role { font-size: 0.75rem; color: var(--c-text-sub); font-weight: 500; }

    .topbar { position: fixed; top: 0; right: 0; left: 0; height: var(--topbar-height); background: rgba(255, 255, 255, 0.8); backdrop-filter: blur(12px); display: flex; align-items: center; justify-content: space-between; padding: 0 20px; z-index: 900; border-bottom: 1px solid var(--c-slate-100); }
    .main-content { padding: 24px; margin-top: var(--topbar-height); transition: 0.3s; } padding: 24px !important;
  margin-top: var(--topbar-height) !important;
  transition: 0.3s !important;
  padding-top: 90px !important;
    .menu-btn { background: none; border: none; font-size: 1.6rem; color: var(--c-brand-dark); cursor: pointer; }
    .sidebar-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.4); backdrop-filter: blur(2px); z-index: 999; display: none; }
    .sidebar-overlay.open { display: block; }

    .section { display: none; animation: slideUp 0.4s ease; }
    .section.active { display: block; }
    @keyframes slideUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 20px; margin-bottom: 32px; }
    .card { background: #fff; padding: 24px; border-radius: 18px; border: 1px solid var(--c-slate-100); box-shadow: 0 4px 12px rgba(0,0,0,0.04); }
    .stat-val { font-size: 2.2rem; font-weight: 800; color: var(--c-brand-dark); display: block; line-height: 1; }
    .stat-label { font-size: 0.8rem; color: var(--c-text-sub); text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px; margin-top: 4px; }

    .table-container { background: #fff; border-radius: 18px; border: 1px solid var(--c-slate-100); box-shadow: 0 4px 12px rgba(0,0,0,0.04); overflow: hidden; margin-bottom: 24px; }
    .table-header { background: linear-gradient(135deg, var(--c-brand-mid), var(--c-brand-dark)); color: white; padding: 20px 24px; display: flex; justify-content: space-between; align-items: center; }
    .table-header h2 { margin: 0; font-size: 1.3rem; font-weight: 700; }
    table { width: 100%; border-collapse: collapse; }
    th { background: var(--c-slate-50); padding: 16px 20px; text-align: left; font-weight: 700; color: var(--c-text-main); font-size: 0.9rem; border-bottom: 2px solid var(--c-slate-100); }
    td { padding: 16px 20px; border-bottom: 1px solid var(--c-slate-200); vertical-align: middle; }
    tr:hover { background: #F8FAFC; }
    .status-badge { padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
    .status-approved { background: #D1FAE5; color: var(--c-success); }
    .status-pending { background: #FEF3C7; color: var(--c-warning); }
    .status-rejected { background: #FEE2E2; color: var(--c-danger); }
    .priority-critical { background: #FEE2E2; color: #DC2626; }
    .priority-high { background: #FEF3C7; color: #D97706; }
    .btn { padding: 8px 16px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 0.85rem; transition: all 0.2s; text-decoration: none; display: inline-block; }
    .btn-primary { background: var(--c-brand-accent); color: white; }
    .btn-primary:hover { background: #059669; transform: translateY(-1px); }
    .btn-danger { background: var(--c-danger); color: white; }
    .btn-danger:hover { background: #DC2626; }
    .btn-secondary { background: var(--c-slate-200); color: var(--c-text-main); }
    
    .search-box { width: 100%; padding: 14px 20px; border: 2px solid var(--c-slate-200); border-radius: 12px; font-size: 1rem; margin-bottom: 20px; }
    .search-box:focus { outline: none; border-color: var(--c-brand-accent); box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1); }

    #map { height: 500px; border-radius: 18px; margin-bottom: 24px; }
    #loadingOverlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(255,255,255,0.9); display: none; align-items: center; justify-content: center; z-index: 2000; }
    .spinner { width: 50px; height: 50px; border: 4px solid var(--c-slate-200); border-top: 4px solid var(--c-brand-accent); border-radius: 50%; animation: spin 1s linear infinite; }
    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

    .profile-form { background: #fff; border-radius: 18px; padding: 32px; border: 1px solid var(--c-slate-100); box-shadow: 0 4px 12px rgba(0,0,0,0.04); }
    .form-group { margin-bottom: 24px; }
    .form-label { display: block; font-weight: 700; color: var(--c-text-main); margin-bottom: 8px; font-size: 0.95rem; }
    .form-input { width: 100%; padding: 14px 16px; border: 2px solid var(--c-slate-200); border-radius: 12px; font-size: 1rem; transition: all 0.2s; }
    .form-input:focus { outline: none; border-color: var(--c-brand-accent); box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1); }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }

    /* Geo-Monitoring Styles */
    .geo-panel-container { max-width: 1200px; margin: 0 auto; }
    .section-header { text-align: center; padding: 32px 24px 24px; }
    .section-title { font-family: 'Syne', sans-serif; font-size: 2.5rem; font-weight: 800; background: linear-gradient(135deg, var(--c-brand-dark), var(--c-brand-mid)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; margin: 0 0 8px 0; letter-spacing: -1px; }
    .section-desc { font-size: 1.1rem; color: var(--c-text-sub); font-weight: 500; margin: 0; }
    .geo-controls-card { background: #fff; border-radius: 20px; padding: 28px; border: 1px solid var(--c-slate-100); box-shadow: 0 8px 32px rgba(0,0,0,0.06); margin-bottom: 28px; display: grid; grid-template-columns: 1fr 1fr auto; gap: 32px; align-items: center; }
    @media (max-width: 768px) { .geo-controls-card { grid-template-columns: 1fr; gap: 24px; } }
    .info-group { display: flex; align-items: center; gap: 16px; }
    .icon-box { width: 56px; height: 56px; background: linear-gradient(135deg, var(--c-brand-mid), var(--c-brand-accent)); border-radius: 16px; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.4rem; }
    .farm-label { font-weight: 700; font-size: 1rem; color: var(--c-text-main); margin-bottom: 4px; }
        .count-badge { background: linear-gradient(135deg, var(--c-brand-accent), #059669); color: white; padding: 6px 12px; border-radius: 20px; font-weight: 800; font-size: 1.1rem; min-width: 48px; text-align: center; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); }
    .action-group { display: flex; flex-direction: column; align-items: flex-end; gap: 16px; }
    .toggle-pill { display: flex; background: var(--c-slate-50); border-radius: 25px; padding: 4px; box-shadow: inset 0 2px 4px rgba(0,0,0,0.05); }
    .toggle-btn { background: none; border: none; padding: 12px 20px; border-radius: 20px; cursor: pointer; font-weight: 600; color: var(--c-text-sub); display: flex; align-items: center; gap: 8px; font-size: 0.9rem; transition: all 0.2s ease; }
    .toggle-btn.active { background: white; color: var(--c-brand-dark); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
    .btn-main, .btn-secondary { display: flex; align-items: center; gap: 8px; padding: 12px 20px; border-radius: 12px; font-weight: 600; cursor: pointer; transition: all 0.2s ease; border: none; font-size: 0.9rem; }
    .btn-main { background: linear-gradient(135deg, var(--c-brand-accent), #059669); color: white; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); }
    .btn-main:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(16, 185, 129, 0.4); }
    .btn-secondary { background: var(--c-slate-50); color: var(--c-text-main); border: 1px solid var(--c-slate-200); }
    .map-section { background: #fff; border-radius: 24px; border: 1px solid var(--c-slate-100); box-shadow: 0 12px 40px rgba(0,0,0,0.08); overflow: hidden; }
    .map-container { position: relative; }
    .map-overlay-top { position: absolute; top: 20px; left: 24px; z-index: 1000; background: rgba(255,255,255,0.95); backdrop-filter: blur(12px); padding: 16px 20px; border-radius: 16px; border: 1px solid rgba(255,255,255,0.3); box-shadow: 0 8px 24px rgba(0,0,0,0.1); }
    .pulse-dot { width: 10px; height: 10px; background: var(--c-success); border-radius: 50%; animation: pulse 2s infinite; box-shadow: 0 0 0 0 rgba(16, 185, 129, 1); }
    @keyframes pulse { 0% { transform: scale(0.95); opacity: 1; } 70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(16, 185, 129, 0); } 100% { transform: scale(0.95); opacity: 1; box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); } }
    .map-legend { position: absolute; bottom: 24px; right: 24px; background: rgba(255,255,255,0.95); backdrop-filter: blur(12px); padding: 20px; border-radius: 16px; border: 1px solid rgba(255,255,255,0.3); box-shadow: 0 8px 24px rgba(0,0,0,0.1); z-index: 1000; }
    .legend-item { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; font-size: 0.9rem; font-weight: 500; }
    .legend-item:last-child { margin-bottom: 0; }
    .legend-color { width: 20px; height: 20px; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.2); }
    .legend-color.low { background: #10b981; }
    .legend-color.medium { background: #f59e0b; }
    .legend-color.high { background: #ef4444; }

    /* Responsive Grid for Stat Cards */
  .stats-grid {
    display: grid;
    gap: 16px;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    margin-bottom: 32px;
  }

  /* Responsive Controls Card (Downloads & Quick Analytics) */
  .geo-controls-card {
    display: grid;
    grid-template-columns: 1fr; /* Stacked by default on mobile */
    gap: 24px;
    margin-bottom: 28px;
    background: #fff;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
  }

  /* Desktop layout for controls */
  @media (min-width: 768px) {
    .geo-controls-card {
      grid-template-columns: 1fr 1fr;
    }
  }

  /* Button Groups */
  .btn-group-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr); /* 2 columns on mobile */
    gap: 8px;
  }

  @media (min-width: 480px) {
    .btn-group-grid {
      grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    }
  }

  /* Typography Adjustments */
  @media (max-width: 600px) {
    .section-title { font-size: 1.5rem; }
    .stat-val { font-size: 2rem !important; }
    .table-container { margin: 0 -15px; border-radius: 0; } /* Edge-to-edge on mobile */
  }
    
    @media (min-width: 1024px) {
        .panel-sidebar { left: 0; }
        .main-content { margin-left: var(--sidebar-width); }
        .menu-btn { display: none; }
        .sidebar-overlay { display: none !important; }
    }

    .stats-grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); margin-bottom: 32px; }
  .geo-controls-card { display: grid; grid-template-columns: 1fr; gap: 24px; margin-bottom: 28px; background: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
  @media (min-width: 768px) { .geo-controls-card { grid-template-columns: 1fr 1fr; } }
  .btn-group-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
  @media (min-width: 480px) { .btn-group-grid { grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); } }
  :root {
    --c-brand: #2ecc71;
    --c-brand-dark: #27ae60;
    --c-text-main: #2d3436;
    --c-bg-light: #f9f9f9;
    --shadow: 0 4px 12px rgba(0,0,0,0.08);
}

.profile-container {
    padding: 15px;
    background-color: var(--c-bg-light);
    min-height: 100vh;
}

.profile-card {
    background: #fff;
    border-radius: 16px;
    box-shadow: var(--shadow);
    max-width: 800px;
    margin: 0 auto;
    overflow: hidden;
}

.profile-header {
    background: linear-gradient(135deg, var(--c-brand), var(--c-brand-dark));
    color: white;
    padding: 30px 20px;
    text-align: center;
}

.avatar-circle {
    width: 80px;
    height: 80px;
    background: rgba(255,255,255,0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    font-weight: bold;
    margin: 0 auto 10px;
    border: 3px solid #fff;
}

.form-section {
    padding: 20px;
    border-bottom: 1px solid #eee;
}

.section-title {
    font-size: 1.1rem;
    color: var(--c-brand-dark);
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr; /* Default mobile: 1 column */
    gap: 15px;
}

@media (min-width: 600px) {
    .form-grid {
        grid-template-columns: 1fr 1fr; /* Desktop/Tablet: 2 columns */
    }
}

.form-group {
    display: flex;
    flex-direction: column;
}

.form-group label {
    font-size: 0.85rem;
    font-weight: 600;
    margin-bottom: 6px;
    color: #636e72;
}

.form-group input {
    padding: 12px;
    border: 1.5px solid #dfe6e9;
    border-radius: 8px;
    font-size: 1rem;
    transition: border-color 0.3s;
}

.form-group input:focus {
    outline: none;
    border-color: var(--c-brand);
}

.input-disabled {
    background-color: #f1f2f6;
    cursor: not-allowed;
}

.form-actions {
    padding: 25px 20px;
    display: flex;
    gap: 10px;
}

.btn-primary, .btn-secondary {
    flex: 1;
    padding: 14px;
    border-radius: 8px;
    font-weight: bold;
    border: none;
    cursor: pointer;
}

.btn-primary { background: var(--c-brand); color: white; }
.btn-secondary { background: #dfe6e9; color: var(--c-text-main); }

.btn-primary:active { transform: scale(0.98); }

.geo-controls-card {
  background: #fff !important;
  border-radius: 20px !important;
  padding: 28px !important;
  border: 1px solid var(--c-slate-100) !important;
  box-shadow: 0 8px 32px rgba(0,0,0,0.06) !important;
  margin-bottom: 28px !important;
  display: grid !important;
  grid-template-columns: 1fr 1fr 280px !important;  /* FIXED WIDTH */
  gap: 32px !important;
  align-items: center !important;
  min-height: 120px !important;
}

@media (max-width: 992px) {
  .geo-controls-card {
    grid-template-columns: 1fr !important;
    gap: 24px !important;
    padding: 20px !important;
  }
}

@media (max-width: 768px) {
  .geo-controls-card {
    padding: 16px !important;
    gap: 20px !important;
  }
}

/* Profile Styles - Mobile First */
.profile-container {
  padding: 20px;
  max-width: 600px;
  margin: 0 auto;
}

.profile-card {
  background: #fff;
  border-radius: 20px;
  box-shadow: 0 8px 32px rgba(0,0,0,0.08);
  overflow: hidden;
  margin-bottom: 20px;
}

.profile-header {
  background: linear-gradient(135deg, var(--c-brand-mid), var(--c-brand-dark));
  color: white;
  padding: 40px 30px 30px;
  text-align: center;
  position: relative;
}

.avatar-circle {
  width: 100px;
  height: 100px;
  background: rgba(255,255,255,0.2);
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 2.5rem;
  font-weight: 800;
  margin: 0 auto 16px;
  border: 4px solid rgba(255,255,255,0.3);
  backdrop-filter: blur(10px);
  transition: all 0.3s ease;
}

.profile-role {
  font-size: 0.9rem;
  opacity: 0.9;
  margin: 0;
  font-weight: 500;
}

.profile-info {
  padding: 30px;
}

.info-section {
  margin-bottom: 28px;
}

.info-section h3 {
  font-size: 1.1rem;
  color: var(--c-brand-dark);
  margin-bottom: 20px;
  display: flex;
  align-items: center;
  gap: 10px;
  font-weight: 700;
}

.info-item {
  display: flex;
  justify-content: space-between;
  padding: 16px 0;
  border-bottom: 1px solid var(--c-slate-100);
}

.info-label {
  color: var(--c-text-sub);
  font-weight: 600;
  font-size: 0.95rem;
}

.info-value {
  font-weight: 600;
  color: var(--c-text-main);
  text-align: right;
}

.profile-actions {
  padding: 0 30px 30px;
  text-align: center;
}

.btn-edit {
  background: linear-gradient(135deg, var(--c-brand-accent), #059669);
  color: white;
  border: none;
  padding: 16px 32px;
  border-radius: 12px;
  font-weight: 700;
  font-size: 1rem;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 0 auto;
  transition: all 0.3s ease;
  box-shadow: 0 4px 16px rgba(16,185,129,0.3);
}

.btn-edit:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 24px rgba(16,185,129,0.4);
}

/* Modal Styles */
.modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0,0,0,0.5);
  backdrop-filter: blur(8px);
  z-index: 2000;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  opacity: 0;
  visibility: hidden;
  transition: all 0.3s ease;
}

.modal-overlay.active {
  opacity: 1;
  visibility: visible;
}

.modal-content {
  background: #fff;
  border-radius: 20px;
  max-width: 95vw;
  max-height: 90vh;
  width: 100%;
  max-width: 500px;
  overflow-y: auto;
  box-shadow: 0 20px 60px rgba(0,0,0,0.3);
  transform: scale(0.8) translateY(20px);
  transition: all 0.3s ease;
}

.modal-overlay.active .modal-content {
  transform: scale(1) translateY(0);
}

.modal-header {
  padding: 24px 28px 0;
  display: flex;
  justify-content: space-between;
  align-items: center;
  border-bottom: 1px solid var(--c-slate-100);
  margin-bottom: 24px;
}

.modal-header h3 {
  margin: 0;
  font-size: 1.3rem;
  color: var(--c-brand-dark);
  display: flex;
  align-items: center;
  gap: 10px;
}

.modal-close {
  background: none;
  border: none;
  font-size: 1.5rem;
  color: var(--c-text-sub);
  cursor: pointer;
  padding: 8px;
  border-radius: 50%;
  transition: all 0.2s;
}

.modal-close:hover {
  background: var(--c-slate-100);
  color: var(--c-text-main);
}

.profile-pic-upload {
  text-align: center;
  margin-bottom: 24px;
}

.profile-pic-upload img {
  width: 100px;
  height: 100px;
  border-radius: 50%;
  object-fit: cover;
  border: 4px solid var(--c-slate-100);
  margin-bottom: 12px;
}

.btn-upload {
  background: var(--c-slate-50);
  border: 2px dashed var(--c-slate-200);
  color: var(--c-text-sub);
  padding: 12px 24px;
  border-radius: 12px;
  cursor: pointer;
  transition: all 0.2s;
}

.btn-upload:hover {
  background: var(--c-brand-accent);
  color: white;
  border-color: var(--c-brand-accent);
}

.form-section h4 {
  font-size: 1.1rem;
  color: var(--c-brand-dark);
  margin-bottom: 20px;
  padding-bottom: 8px;
  border-bottom: 2px solid var(--c-slate-100);
}

.form-row {
  display: flex;
  flex-direction: column;
  gap: 16px;
  margin-bottom: 16px;
}

@media (min-width: 480px) {
  .form-row {
    flex-direction: row;
  }
  .form-row .form-group {
    flex: 1;
  }
}

.modal-actions {
  padding: 24px;
  border-top: 1px solid var(--c-slate-100);
  display: flex;
  gap: 12px;
  justify-content: flex-end;
}

.btn-cancel, .btn-save {
  padding: 14px 24px;
  border-radius: 12px;
  font-weight: 700;
  border: none;
  cursor: pointer;
  transition: all 0.2s;
}

.btn-cancel {
  background: var(--c-slate-100);
  color: var(--c-text-main);
}

.btn-save {
  background: linear-gradient(135deg, var(--c-brand-accent), #059669);
  color: white;
  display: flex;
  align-items: center;
  gap: 8px;
}

.btn-cancel:hover { background: var(--c-slate-200); }
.btn-save:hover { transform: translateY(-1px); box-shadow: 0 4px 16px rgba(16,185,129,0.4); }

/* Responsive */
@media (max-width: 480px) {
  .profile-container { padding: 15px; }
  .profile-info { padding: 20px; }
  .info-item { flex-direction: column; gap: 4px; text-align: left; }
  .info-value { text-align: left; }
}

.searchable-dropdown {
  position: relative;
  margin-bottom: 12px;
}

.searchable-dropdown input {
  width: 100%;
  padding: 14px 16px 14px 44px;
  border: 2px solid var(--c-slate-200);
  border-radius: 12px;
  font-size: 1rem;
  background: #fff url('data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjQiIGhlaWdodD0iMjQiIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPHBhdGggZD0iTTE5IDExQzE5IDguMDg4IDExLjkyMiA1IDE5IDVIMTZWMTZIMTZWMTRIMTZWMTZIMTYiIGZpbGw9IiM2NDc0OEIiLz4KPC9zdmc+') no-repeat 16px center;
  background-size: 18px;
  cursor: pointer;
  transition: all 0.2s;
}

.searchable-dropdown input:focus {
  border-color: var(--c-brand-accent);
  box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
}

.dropdown-list {
  position: absolute;
  top: 100%;
  left: 0;
  right: 0;
  background: #fff;
  border: 1px solid var(--c-slate-200);
  border-radius: 12px;
  max-height: 240px;
  overflow-y: auto;
  z-index: 100;
  box-shadow: 0 8px 32px rgba(0,0,0,0.12);
  display: none;
  margin-top: 4px;
}

.dropdown-list.active {
  display: block;
  animation: slideDown 0.2s ease;
}

@keyframes slideDown {
  from { opacity: 0; transform: translateY(-8px); }
  to { opacity: 1; transform: translateY(0); }
}

.dropdown-item {
  padding: 14px 16px;
  cursor: pointer;
  border-bottom: 1px solid var(--c-slate-100);
  font-size: 0.95rem;
  transition: all 0.2s;
}

.dropdown-item:hover,
.dropdown-item.selected {
  background: var(--c-brand-accent);
  color: white;
}

.dropdown-item:last-child {
  border-bottom: none;
}

.required {
  color: var(--c-danger);
}
  </style>
</head>
<body>

  <div class="sidebar-overlay" id="overlay" onclick="toggleSidebar()"></div>
  <div id="loadingOverlay"><div class="spinner"></div></div>

  <aside class="panel-sidebar" id="sidebar">
    <div class="sidebar-header">
      <div class="sidebar-logo">Agri<span>Trace+</span></div>
      <div class="sidebar-sub">Official Portal</div>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-item active" onclick="navTo('dashboard', this)">
        <i class="bi bi-grid-1x2"></i> <span>Dashboard</span>
      </div>
      <div class="nav-item" onclick="navTo('farms', this)">
        <i class="bi bi-house-check"></i> <span>Farm Inspection</span>
      </div>
      <div class="nav-item" onclick="navTo('incidents', this)">
        <i class="bi bi-exclamation-triangle"></i> <span>Incidents</span>
      </div>
      <div class="nav-item" onclick="navTo('public', this)">
        <i class="bi bi-file-earmark-text"></i> <span>Public Reports</span>
      </div>
      <div class="nav-item" onclick="navTo('map', this)">
        <i class="bi bi-geo-alt"></i> <span>Geo-Monitoring</span>
      </div>
      <div class="nav-item" onclick="navTo('reports', this)">
        <i class="bi bi-bar-chart-line"></i> <span>Analytics</span>
      </div>
      <div class="nav-item" onclick="navTo('profile', this)">
        <i class="bi bi-person-circle"></i> <span>Profile</span>
      </div>
      
      <div class="nav-divider"></div>
      
      <div class="nav-item logout" onclick="window.location.href='index.php'">
        <i class="bi bi-power"></i> <span>Logout</span>
      </div>
    </nav>

    <div class="sidebar-footer">
      <div class="user-box">
        <div class="user-avatar"><?php echo htmlspecialchars($user_initial); ?></div>
        <div>
          <div class="user-name"><?php echo htmlspecialchars($user_fullname); ?></div>
          <div class="user-role"><?php echo htmlspecialchars($user_role); ?></div>
        </div>
      </div>
    </div>
  </aside>

  <header class="topbar">
    <div style="display:flex; align-items:center; gap:12px;">
      <button class="menu-btn" onclick="toggleSidebar()">
        <i class="bi bi-list"></i>
      </button>
      <strong id="page-title" style="color:var(--c-brand-dark);">Dashboard</strong>
    </div>
    <div class="user-avatar" style="width:34px; height:34px; font-size:0.9rem;"><?php echo htmlspecialchars($user_initial); ?></div>
  </header>

  <main class="main-content">
    
    <!-- DASHBOARD -->
    <section id="sec-dashboard" class="section active">
      <div class="stats-grid">
        <div class="card" style="border-left: 4px solid var(--c-success);">
          <span class="stat-val"><?php echo $stats['approved_farms']; ?></span>
          <span class="stat-label">Approved Farms</span>
        </div>
        <div class="card" style="border-left: 4px solid var(--c-warning);">
          <span class="stat-val"><?php echo $stats['active_incidents']; ?></span>
          <span class="stat-label">Active Incidents</span>
        </div>
        <div class="card" style="border-left: 4px solid var(--c-danger);">
          <span class="stat-val"><?php echo $stats['pending_reports']; ?></span>
          <span class="stat-label">Pending Reports</span>
        </div>
        <div class="card" style="border-left: 4px solid var(--c-brand-mid);">
          <span class="stat-val"><?php echo number_format($stats['total_livestock']); ?></span>
          <span class="stat-label">Total Livestock</span>
        </div>
      </div>

      <div class="table-container">
        <div class="table-header">
          <h2>Recent Incidents</h2>
        </div>
        <table>
          <thead>
            <tr>
              <th>Title</th>
              <th>Farm</th>
              <th>Status</th>
              <th>Priority</th>
              <th>Date</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recent_incidents as $incident): ?>
            <tr>
              <td><?php echo htmlspecialchars($incident['title']); ?></td>
              <td><?php echo htmlspecialchars($incident['farm_name'] ?? 'N/A'); ?></td>
              <td><span class="status-badge status-pending"><?php echo ucfirst($incident['status']); ?></span></td>
              <td><span class="status-badge priority-<?php echo strtolower($incident['priority']); ?>"><?php echo ucfirst($incident['priority']); ?></span></td>
              <td><?php echo date('M j', strtotime($incident['createdAt'])); ?></td>
              <td><a href="#" class="btn btn-primary" onclick="resolveIncident(<?php echo $incident['id']; ?>)">Resolve</a></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($recent_incidents)): ?>
            <tr><td colspan="6" style="text-align:center; padding:40px; color:var(--c-text-sub);">No recent incidents</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- FARM INSPECTION -->
    <section id="sec-farms" class="section">
      <div class="table-container">
        <div class="table-header">
          <h2>Farm Inspection Portal</h2>
          <input type="text" class="search-box" placeholder="Search farms by name, owner, or location..." onkeyup="searchFarms(this.value)">
        </div>
        <table id="farmsTable">
          <thead>
            <tr>
              <th>Farm Name</th>
              <th>Owner</th>
              <th>Type</th>
              <th>Status</th>
              <th>Livestock</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($all_farms as $farm): ?>
            <tr>
              <td><?php echo htmlspecialchars($farm['name']); ?></td>
              <td><?php echo htmlspecialchars(($farm['firstName'] ?? '') . ' ' . ($farm['lastName'] ?? '')); ?></td>
              <td><?php echo ucfirst(str_replace('_', ' ', $farm['type'])); ?> Farm</td>
              <td><span class="status-badge status-<?php echo strtolower($farm['status']); ?>"><?php echo ucfirst($farm['status']); ?></span></td>
              <td><?php echo $farm['total_livestock'] ?? 0; ?> heads</td>
              <td>
                <?php if ($farm['status'] === 'Pending'): ?>
                <a href="#" class="btn btn-primary" onclick="approveFarm(<?php echo $farm['id']; ?>)">Approve</a>
                <a href="#" class="btn btn-danger" style="margin-left:8px;" onclick="rejectFarm(<?php echo $farm['id']; ?>)">Reject</a>
                <?php else: ?>
                <span style="color:var(--c-text-sub);">No action needed</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- INCIDENTS -->
    <section id="sec-incidents" class="section">
      <div class="table-container">
        <div class="table-header">
          <h2>Incident Management</h2>
        </div>
        <table>
          <thead>
            <tr>
              <th>Title</th>
              <th>Farm</th>
              <th>Type</th>
              <th>Status</th>
              <th>Priority</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($all_incidents as $incident): ?>
            <tr>
              <td><?php echo htmlspecialchars($incident['title']); ?></td>
              <td><?php echo htmlspecialchars($incident['farm_name'] ?? 'N/A'); ?></td>
              <td><?php echo ucfirst($incident['type']); ?></td>
              <td><span class="status-badge status-pending"><?php echo ucfirst($incident['status']); ?></span></td>
              <td><span class="status-badge priority-<?php echo strtolower($incident['priority']); ?>"><?php echo ucfirst($incident['priority']); ?></span></td>
              <td>
                <a href="#" class="btn btn-primary" onclick="resolveIncident(<?php echo $incident['id']; ?>)">Resolve</a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- PUBLIC REPORTS -->
    <section id="sec-public" class="section">
      <div class="table-container">
        <div class="table-header">
          <h2>Public Reports</h2>
        </div>
        <table>
          <thead>
            <tr>
              <th>Type</th>
              <th>Description</th>
              <th>Contact</th>
              <th>Status</th>
              <th>Date</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($public_reports as $report): ?>
            <tr>
              <td><?php echo ucfirst($report['reportType']); ?></td>
              <td><?php echo htmlspecialchars(substr($report['description'], 0, 80)) . '...'; ?></td>
              <td><?php echo htmlspecialchars($report['contactPhone']); ?></td>
              <td><span class="status-badge status-pending"><?php echo ucfirst($report['status']); ?></span></td>
              <td><?php echo date('M j', strtotime($report['createdAt'])); ?></td>
              <td>
                <a href="#" class="btn btn-primary">Investigate</a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- GEO-MONITORING SECTION - COMPLETE & FIXED -->
<section id="sec-map" class="section">
  <div class="geo-panel-container">
    <div class="section-header">
      <h1 class="section-title">Geo-Mapping Control</h1>
      <p class="section-desc">Real-time livestock density and farm distribution</p>
    </div>

    <div class="geo-controls-card">
  <div class="info-group">
    <div class="icon-box">
      <i class="bi bi-geo-fill"></i>
    </div>
    <div>
      <div class="farm-label">Live Farms</div>
      <div style="display: flex; align-items: center; gap: 8px; margin-top: 2px;">
        <span class="count-badge"><?php echo count($all_farms); ?></span>
        <small style="color: var(--c-text-sub); font-weight: 500;">Active Sites</small>
      </div>
    </div>
  </div>

  <div class="info-group">
    <div class="icon-box" style="background: linear-gradient(135deg, var(--c-success), #059669);">
      <i class="bi bi-activity"></i>
    </div>
    <div>
      <div class="farm-label">Livestock Heads</div>
      <div style="display: flex; align-items: center; gap: 8px; margin-top: 2px;">
        <span class="count-badge" style="background: var(--c-success);"><?php echo number_format($stats['total_livestock']); ?></span>
        <small style="color: var(--c-text-sub); font-weight: 500;">Total Population</small>
      </div>
    </div>
  </div>

  <div class="action-group">
    <div class="toggle-pill">
      <button onclick="toggleLayer('livestock', event)" id="livestockToggle" class="toggle-btn">
        <i class="bi bi-piggy-bank"></i> Livestock
      </button>
      <button onclick="toggleLayer('incidents', event)" id="incidentsToggle" class="toggle-btn">
        <i class="bi bi-exclamation-triangle"></i> Incidents
      </button>
      <button onclick="toggleLayer('farms', event)" id="farmsToggle" class="toggle-btn active">
        <i class="bi bi-house-door"></i> Farms
      </button>
    </div>
    
    <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px;">
      <button onclick="loadFarmMap()" class="btn-main" style="padding: 10px 16px; font-size: 0.85rem; min-width: 140px;">
        <i class="bi bi-box-arrow-up-right"></i> Fullscreen Map
      </button>
      <button onclick="fitBounds()" class="btn-secondary" style="padding: 10px 12px; font-size: 0.85rem;">
        <i class="bi bi-geo-alt"></i> Fit PH
      </button>
      <button onclick="refreshMapData()" class="btn-secondary" style="padding: 10px 12px; font-size: 0.85rem;">
        <i class="bi bi-arrow-clockwise"></i> Refresh
      </button>
    </div>
  </div>
</div>

    <div class="map-section">
      <div class="map-container">
        <div class="map-overlay-top">
          <div style="font-weight: 800; font-size: 0.95rem; color: var(--c-text-main); display: flex; align-items: center; gap: 8px;">
            <span class="pulse-dot"></span> 
            <span>PHILIPPINES LIVESTOCK MONITORING</span>
          </div>
          <small style="color: var(--c-text-sub);">Live data feed • Last update: <span id="lastUpdate"><?php echo date('H:i:s'); ?></span></small>
        </div>

        <div id="map" style="height: 550px; border-radius: 20px;"></div>
        
        <div class="map-legend">
          <div class="legend-item">
            <div class="legend-color low"></div>
            <span>Low (< 10)</span>
          </div>
          <div class="legend-item">
            <div class="legend-color medium"></div>
            <span>Medium (11-50)</span>
          </div>
          <div class="legend-item">
            <div class="legend-color high"></div>
            <span>High (> 50)</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

    <!-- REPORTS & ANALYTICS - ENHANCED WITH REAL DATA -->
<section id="sec-reports" class="section">
  <div class="geo-panel-container">
    <div class="section-header">
      <h1 class="section-title">Reports & Analytics</h1>
      <p class="section-desc">Regional livestock and farm performance insights</p>
    </div>

    <div class="stats-grid">
      <div class="card" style="border-left: 4px solid #3498db;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
          <span class="stat-label" style="font-size: 0.85rem; font-weight: bold;">TOTAL LIVESTOCK</span>
          <i class="bi bi-bar-chart" style="font-size: 1.5rem; color: #3498db;"></i>
        </div>
        <div style="font-size: 2.5rem; font-weight: 800; color: #2c3e50; line-height: 1;">
          <?php echo number_format($analytics['totals']['total_livestock']); ?>
        </div>
        <span class="stat-label"><?php echo number_format($analytics['totals']['total_farms']); ?> Active Farms</span>
      </div>

      <?php $top_region = $analytics['regional_stats'][0] ?? ['livestock_count' => 0, 'region' => 'N/A']; ?>
      <div class="card" style="border-left: 4px solid #2ecc71;">
        <div style="font-size: 2.5rem; font-weight: 800; color: #2c3e50;">
            <?php echo number_format($top_region['livestock_count']); ?>
        </div>
        <span class="stat-label"><?php echo htmlspecialchars($top_region['region']); ?> Region</span>
      </div>

      <div class="card" style="border-left: 4px solid #f1c40f;">
        <div style="font-size: 2.5rem; font-weight: 800; color: #2c3e50;">
            <?php echo $analytics['totals']['active_incidents']; ?>
        </div>
        <span class="stat-label">Active Incidents</span>
      </div>

      <div class="card" style="border-left: 4px solid #e74c3c;">
        <div style="font-size: 2.5rem; font-weight: 800; color: #2c3e50;">
            <?php echo number_format($swine_count); ?>
        </div>
        <span class="stat-label">Total Swine</span>
      </div>
    </div>

    <div class="geo-controls-card">
      <div style="display: flex; flex-direction: column; gap: 16px;">
        <div class="farm-label" style="font-size: 1.1rem; font-weight: 600;">📊 Generate Reports</div>
        <div class="btn-group-grid">
          <button onclick="downloadReport('pdf')" class="btn-main">Full PDF</button>
          <button onclick="downloadReport('excel')" class="btn-secondary">Excel</button>
          <button onclick="downloadReport('csv')" class="btn-secondary">CSV</button>
          <button onclick="downloadReport('json')" class="btn-secondary">JSON</button>
        </div>
      </div>
      <div style="display: flex; flex-direction: column; gap: 16px;">
        <div class="farm-label" style="font-size: 1.1rem; font-weight: 600;">📈 Quick Analytics</div>
        <div class="btn-group-grid">
          <button class="btn-secondary">Farms</button>
          <button class="btn-secondary">Livestock</button>
          <button class="btn-secondary">Incidents</button>
          <button class="btn-main">Charts</button>
        </div>
      </div>
    </div>

    <div class="table-container" style="background: white; border-radius: 8px; padding: 15px; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
      <div class="table-header" style="margin-bottom: 15px;">
        <h2 style="font-size: 1.2rem; color: #2c3e50;">Regional Livestock Distribution</h2>
      </div>
      <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; min-width: 450px;">
          <thead>
            <tr style="text-align: left; border-bottom: 2px solid #eee;">
              <th style="padding: 12px;">Region</th>
              <th style="padding: 12px;">Farms</th>
              <th style="padding: 12px;">Livestock</th>
              <th style="padding: 12px;">% Total</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($analytics['regional_stats'])): ?>
                <?php foreach ($analytics['regional_stats'] as $region): ?>
                <tr style="border-bottom: 1px solid #eee;">
                  <td style="padding: 12px; font-weight: 600;"><?php echo htmlspecialchars($region['region']); ?></td>
                  <td style="padding: 12px;"><?php echo $region['farm_count']; ?></td>
                  <td style="padding: 12px; font-weight: 700; color: #3498db;"><?php echo number_format($region['livestock_count']); ?></td>
                  <td style="padding: 12px;">
                    <?php 
                    $pct = ($analytics['totals']['total_livestock'] > 0) ? ($region['livestock_count'] / $analytics['totals']['total_livestock']) * 100 : 0;
                    echo round($pct, 1) . '%';
                    ?>
                  </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="4" style="text-align: center; padding: 20px;">No data available</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</section>

    <!-- PROFILE -->
<section id="sec-profile" class="section">
  <div class="profile-container">
    <!-- Profile Display Card -->
    <div class="profile-card">
      <div class="profile-header">
        <div class="avatar-circle" id="profileAvatar">
          <?php if (!empty($user_profile['profile_pix'])): ?>
            <img src="<?php echo htmlspecialchars($user_profile['profile_pix']); ?>" alt="Profile" style="width:100%; height:100%; border-radius:50%; object-fit:cover;">
          <?php else: ?>
            <?php echo strtoupper(substr($user_profile['firstName'] ?? 'U', 0, 1)); ?>
          <?php endif; ?>
        </div>
        <h2 id="profileName"><?php echo htmlspecialchars(($user_profile['firstName'] ?? '') . ' ' . ($user_profile['lastName'] ?? '')); ?></h2>
        <p class="profile-role"><?php echo htmlspecialchars($user_role); ?></p>
      </div>

      <!-- Profile Info Sections -->
      <div class="profile-info">
        <div class="info-section">
          <h3><i class="bi bi-person"></i> Personal Info</h3>
          <div class="info-item">
            <span class="info-label">Email</span>
            <span class="info-value" id="profileEmail"><?php echo htmlspecialchars($user_profile['email'] ?? ''); ?></span>
          </div>
          <div class="info-item">
            <span class="info-label">Mobile</span>
            <span class="info-value" id="profileMobile"><?php echo htmlspecialchars($user_profile['mobile'] ?? 'N/A'); ?></span>
          </div>
        </div>

        <div class="info-section">
          <h3><i class="bi bi-briefcase"></i> Work Info</h3>
          <div class="info-item">
            <span class="info-label">Gov't ID</span>
            <span class="info-value" id="profileGovId"><?php echo htmlspecialchars($user_profile['gov_id'] ?? 'N/A'); ?></span>
          </div>
          <div class="info-item">
            <span class="info-label">Department</span>
            <span class="info-value" id="profileDepartment"><?php echo htmlspecialchars($user_profile['department'] ?? 'N/A'); ?></span>
          </div>
          <div class="info-item">
            <span class="info-label">Position</span>
            <span class="info-value" id="profilePosition"><?php echo htmlspecialchars($user_profile['position'] ?? 'N/A'); ?></span>
          </div>
          <div class="info-item">
            <span class="info-label">Office</span>
            <span class="info-value" id="profileOffice"><?php echo htmlspecialchars($user_profile['office'] ?? 'N/A'); ?></span>
          </div>
        </div>

        <div class="info-section">
          <h3><i class="bi bi-geo-alt"></i> Assignment</h3>
          <div class="info-item">
            <span class="info-label">Region</span>
            <span class="info-value" id="profileRegion"><?php echo htmlspecialchars($user_profile['assigned_region'] ?? 'N/A'); ?></span>
          </div>
          <div class="info-item">
            <span class="info-label">Province</span>
            <span class="info-value" id="profileProvince"><?php echo htmlspecialchars($user_profile['province'] ?? 'N/A'); ?></span>
          </div>
          <div class="info-item">
            <span class="info-label">Municipality</span>
            <span class="info-value" id="profileMunicipality"><?php echo htmlspecialchars($user_profile['municipality'] ?? 'N/A'); ?></span>
          </div>
        </div>
      </div>

      <!-- Edit Button -->
      <div class="profile-actions">
        <button class="btn-edit" onclick="openProfileEditor()">
          <i class="bi bi-pencil-square"></i>
          Edit Profile
        </button>
      </div>
    </div>
  </div>

  <!-- Edit Profile Modal Container -->
  <div id="editProfileModal" class="modal-overlay" style="display: none;"></div>
</section>

  </main>

  <!-- Leaflet JS for Map -->
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script src="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.js"></script>

  <script>
    // Global map variables
    let map;
    let currentLayer = 'farms';
    let farmsLayer, livestockLayer, incidentsLayer;

    // Initialize map
    function initMap() {
      if (map) return; // Prevent multiple initializations
      
      map = L.map('map').setView([12.8797, 121.7740], 6); // Philippines center
      
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors'
      }).addTo(map);
      
      // Load initial farm data
      loadFarmData();
    }

    // Load farm data for map
    function loadFarmData() {
      const farms = <?php echo json_encode($all_farms); ?>;
      
      farmsLayer = L.layerGroup().clearLayers();
      farms.forEach(farm => {
        if (farm.latitude && farm.longitude) {
          const marker = L.marker([parseFloat(farm.latitude), parseFloat(farm.longitude)])
            .bindPopup(`
              <div style="min-width: 200px;">
                <h4 style="margin: 0 0 8px 0; color: var(--c-brand-dark);">${farm.name}</h4>
                <p><strong>Owner:</strong> ${farm.firstName || ''} ${farm.lastName || ''}</p>
                <p><strong>Livestock:</strong> ${farm.total_livestock || 0} heads</p>
                <p><strong>Status:</strong> <span style="color: var(--c-success);">${farm.status}</span></p>
              </div>
            `);
          farmsLayer.addLayer(marker);
        }
      });
      if (farmsLayer) farmsLayer.addTo(map);
    }

    function toggleSidebar() {
      document.getElementById('sidebar').classList.toggle('open');
      document.getElementById('overlay').classList.toggle('open');
    }

    function navTo(id, el) {
      document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
      document.getElementById('sec-' + id).classList.add('active');
      
      document.querySelectorAll('.nav-item').forEach(item => item.classList.remove('active'));
      el.classList.add('active');
      
      document.getElementById('page-title').innerText = el.querySelector('span').innerText;
      
      if (window.innerWidth < 1024) toggleSidebar();
      
      // Initialize map when Geo-Monitoring is opened
      if (id === 'map') {
        setTimeout(() => {
          if (typeof initMap === 'function' && !map) {
            try {
              initMap();
            } catch (e) {
              console.error('Map initialization failed:', e);
            }
          }
        }, 300);
      }
    }

    // Search farms
    function searchFarms(query) {
      const rows = document.querySelectorAll('#farmsTable tbody tr');
      rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(query.toLowerCase()) ? '' : 'none';
      });
    }

    // Farm actions (AJAX simulation)
    function approveFarm(farmId) {
      if (confirm('Approve this farm?')) {
        alert('Farm approved! (API call would go here)');
        // Add real AJAX call here
      }
    }

    function rejectFarm(farmId) {
      if (confirm('Reject this farm?')) {
        alert('Farm rejected! (API call would go here)');
        // Add real AJAX call here
      }
    }

    function resolveIncident(incidentId) {
      if (confirm('Mark this incident as resolved?')) {
        alert('Incident resolved! (API call would go here)');
        // Add real AJAX call here
      }
    }

    // Map layer toggle - FIXED
    function toggleLayer(layerType, event) {
      if (!map) return;
      
      // Update toggle buttons
      document.querySelectorAll('.toggle-btn').forEach(btn => btn.classList.remove('active'));
      event.target.classList.add('active');
      
      currentLayer = layerType;
      
      // Toggle map layers
      if (layerType === 'farms') {
        if (farmsLayer) farmsLayer.addTo(map);
        if (livestockLayer) map.removeLayer(livestockLayer);
        if (incidentsLayer) map.removeLayer(incidentsLayer);
      } else if (layerType === 'livestock') {
        if (livestockLayer) livestockLayer.addTo(map);
        if (farmsLayer) map.removeLayer(farmsLayer);
        if (incidentsLayer) map.removeLayer(incidentsLayer);
        loadLivestockData();
      } else if (layerType === 'incidents') {
        if (incidentsLayer) incidentsLayer.addTo(map);
        if (farmsLayer) map.removeLayer(farmsLayer);
        if (livestockLayer) map.removeLayer(livestockLayer);
        loadIncidentsData();
      }
    }

    // ✅ FIXED Farm Map Functions
let mapLoaded = false;

async function loadFarmMap() {
    // Open fullscreen farm map with LIVE database data
    window.open('maps/farm-map.php', '_blank', 'noopener,noreferrer');
    showToast('Opening Interactive Farm Map...', 'success');
}

function fitBounds() {
    if (!map) return;
    map.fitBounds([
        [4.5, 116.9], 
        [21.2, 127.0]
    ]);
}

function showToast(message, type = 'info') {
    // Simple toast notification
    const toast = document.createElement('div');
    toast.style.cssText = `
        position: fixed; top: 20px; right: 20px; 
        background: ${type === 'success' ? '#10B981' : '#3B82F6'}; 
        color: white; padding: 16px 24px; 
        border-radius: 12px; font-weight: 600; 
        z-index: 9999; transform: translateX(400px); 
        transition: transform 0.3s ease;
        box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    `;
    toast.textContent = message;
    document.body.appendChild(toast);
    
    setTimeout(() => toast.style.transform = 'translateX(0)', 100);
    setTimeout(() => {
        toast.style.transform = 'translateX(400px)';
        setTimeout(() => document.body.removeChild(toast), 300);
    }, 3000);
}

    function loadLivestockData() {
      // Mock livestock data - replace with real AJAX call
      console.log('Loading livestock layer...');
      livestockLayer = L.layerGroup().clearLayers();
      
      // Add some sample livestock markers
      const livestockPoints = [
        [14.5995, 120.9842, 25], // Manila
        [10.3157, 123.8854, 45], // Cebu
        [8.4817, 124.6463, 8]    // Cagayan de Oro
      ];
      
      livestockPoints.forEach(([lat, lng, count]) => {
        const color = count > 50 ? '#ef4444' : count > 10 ? '#f59e0b' : '#10b981';
        const marker = L.circleMarker([lat, lng], {
          radius: Math.max(8, count / 5),
          fillColor: color,
          color: '#000',
          weight: 2,
          opacity: 1,
          fillOpacity: 0.7
        }).bindPopup(`Livestock: ${count} heads`);
        livestockLayer.addLayer(marker);
      });
      
      livestockLayer.addTo(map);
    }

    function loadIncidentsData() {
      // Mock incidents data
      console.log('Loading incidents layer...');
      incidentsLayer = L.layerGroup().clearLayers();
      
      const incidents = <?php echo json_encode($all_incidents); ?>;
      incidents.forEach(incident => {
        if (incident.latitude && incident.longitude) {
          const marker = L.marker([parseFloat(incident.latitude), parseFloat(incident.longitude)])
            .bindPopup(`<b>${incident.title}</b><br>Priority: ${incident.priority}`);
          incidentsLayer.addLayer(marker);
        }
      });
      
      incidentsLayer.addTo(map);
    }

    // Reports & Analytics Functions
function downloadReport(format, type = 'complete') {
  const types = {
    complete: 'Complete System Report',
    farms: 'Farm Analytics',
    livestock: 'Livestock Inventory', 
    incidents: 'Incident Reports'
  };
  
  showLoading(true);
  
  // Real AJAX call to generate and download report
  fetch('api/generate_report.php', {
    method: 'POST',
    headers: { 
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest'
    },
    body: JSON.stringify({
      format: format,
      report_type: type,
      user_id: <?php echo $user_id; ?>
    })
  })
  .then(response => response.blob())
  .then(blob => {
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `AgriTrace_${types[type] || 'Report'}_${new Date().toISOString().slice(0,10)}.${format}`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    window.URL.revokeObjectURL(url);
    showToast(`📥 ${types[type]} downloaded as ${format.toUpperCase()}!`, 'success');
  })
  .catch(error => {
    console.error('Download failed:', error);
    showToast('❌ Download failed. Please try again.', 'error');
  })
  .finally(() => showLoading(false));
}

function showAnalyticsChart() {
  showToast('📊 Chart view coming soon! Use Excel/CSV for detailed analysis.', 'info');
  // Future: Integrate Chart.js for live charts
}

function showLoading(show = true) {
  const overlay = document.getElementById('loadingOverlay');
  if (overlay) {
    overlay.style.display = show ? 'flex' : 'none';
  }
}
// Load Edit Profile Modal
async function loadEditProfileModal() {
    const modalContainer = document.getElementById('editProfileModal');
    try {
        const response = await fetch('edit-profile-modal.php');
        modalContainer.innerHTML = await response.text();
        
        // Re-initialize event listeners after loading
        initProfileModalListeners();
    } catch (error) {
        console.error('Failed to load edit profile modal:', error);
        showToast('Failed to load profile editor', 'error');
    }
}

function initProfileModalListeners() {
    // Open modal button
    const editButtons = document.querySelectorAll('.btn-edit');
    editButtons.forEach(btn => {
        btn.onclick = function() {
            loadEditProfileModal().then(() => {
                openEditProfile();
            });
        };
    });
    
    // Global modal functions for iframe/modal communication
    window.openEditProfile = function() {
        document.getElementById('editProfileModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    };
    
    window.closeEditProfile = function() {
        document.getElementById('editProfileModal').classList.remove('active');
        document.body.style.overflow = '';
    };
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    initProfileModalListeners();
});
  </script>

</body>
</html>