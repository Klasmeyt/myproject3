<?php
session_start();
require_once __DIR__ . '/config/db.php';

// ── Authentication & Authorization ─────────────────────────────────────────────
if (!isLoggedIn()) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['PHP_SELF']));
    exit;
}

if (currentUserRole() !== 'Admin') {
    header('Location: login.php?error=access_denied');
    exit;
}

// ── User Data ──────────────────────────────────────────────────────────────────
$user_id     = currentUserId();
$user_role   = currentUserRole();
$user_name   = $_SESSION['firstName'] ?? 'Admin';
$user_email  = $_SESSION['email'] ?? '';
$user_initial= strtoupper(substr($user_name, 0, 1));
$current_date= date('M d, Y');

// ── Database ───────────────────────────────────────────────────────────────────
try {
    $pdo = getPDO();
} catch(PDOException $e) {
    error_log('Admin DB Error: ' . $e->getMessage());
    die('Database connection failed. Check XAMPP MySQL.');
}

// ── Handle AJAX from admin panel ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // Approve farm
    if ($action === 'approve_farm') {
        $farmId = (int)($_POST['farm_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT name,status FROM farms WHERE id=?");
        $stmt->execute([$farmId]); $farm = $stmt->fetch();
        if (!$farm) { echo json_encode(['success'=>false,'message'=>'Farm not found']); exit; }
        if ($farm['status'] !== 'Pending') { echo json_encode(['success'=>false,'message'=>"Farm already {$farm['status']}"]); exit; }
        $pdo->prepare("UPDATE farms SET status='Approved',updatedAt=NOW() WHERE id=?")->execute([$farmId]);
        $pdo->prepare("INSERT INTO audit_log(userId,action,tableName,recordId,details,ipAddress) VALUES(?,?,?,?,?,?)")
            ->execute([$_SESSION['user_id'],'APPROVE_FARM','farms',$farmId,json_encode(['farm_name'=>$farm['name']]),$_SERVER['REMOTE_ADDR']??'']);
        echo json_encode(['success'=>true,'message'=>"Farm '{$farm['name']}' approved!",'new_status'=>'Approved','farm_id'=>$farmId]);
        exit;
    }

    // Reject farm
    if ($action === 'reject_farm') {
        $farmId = (int)($_POST['farm_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        if (!$reason) { echo json_encode(['success'=>false,'message'=>'Reason required']); exit; }
        $stmt = $pdo->prepare("SELECT name,status FROM farms WHERE id=?");
        $stmt->execute([$farmId]); $farm = $stmt->fetch();
        if (!$farm || $farm['status'] !== 'Pending') { echo json_encode(['success'=>false,'message'=>'Cannot reject this farm']); exit; }
        $pdo->prepare("UPDATE farms SET status='Rejected',rejection_reason=?,updatedAt=NOW() WHERE id=?")->execute([$reason,$farmId]);
        $pdo->prepare("INSERT INTO audit_log(userId,action,tableName,recordId,details,ipAddress) VALUES(?,?,?,?,?,?)")
            ->execute([$_SESSION['user_id'],'REJECT_FARM','farms',$farmId,json_encode(['reason'=>$reason,'farm_name'=>$farm['name']]),$_SERVER['REMOTE_ADDR']??'']);
        echo json_encode(['success'=>true,'message'=>"Farm rejected.",'new_status'=>'Rejected','farm_id'=>$farmId]);
        exit;
    }

    // Activate user
    if ($action === 'activate_user') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $pdo->prepare("UPDATE users SET status='Active' WHERE id=?")->execute([$uid]);
        echo json_encode(['success'=>true,'message'=>'User activated']);
        exit;
    }

    // Suspend user
    if ($action === 'suspend_user') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $pdo->prepare("UPDATE users SET status='Suspended' WHERE id=?")->execute([$uid]);
        echo json_encode(['success'=>true,'message'=>'User suspended']);
        exit;
    }

    // Resolve incident
    if ($action === 'resolve_incident') {
        $id = (int)($_POST['incident_id'] ?? 0);
        $pdo->prepare("UPDATE incidents SET status='Resolved',resolvedAt=NOW(),updatedAt=NOW() WHERE id=?")->execute([$id]);
        echo json_encode(['success'=>true,'message'=>'Incident resolved']);
        exit;
    }

    // Review public report
    if ($action === 'review_report') {
        $id = (int)($_POST['report_id'] ?? 0);
        $status = in_array($_POST['status']??'',['Reviewed','Resolved','Dismissed']) ? $_POST['status'] : 'Reviewed';
        $pdo->prepare("UPDATE public_reports SET status=?,updatedAt=NOW() WHERE id=?")->execute([$status,$id]);
        echo json_encode(['success'=>true,'message'=>"Report $status"]);
        exit;
    }

    // Approve appeal
    if ($action === 'approve_appeal') {
        $appealId = (int)($_POST['appeal_id'] ?? 0);
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT farm_id FROM farm_appeals WHERE id=?");
        $stmt->execute([$appealId]); $appeal = $stmt->fetch();
        if ($appeal) {
            $pdo->prepare("UPDATE farm_appeals SET appeal_status='Approved',updated_at=NOW() WHERE id=?")->execute([$appealId]);
            $pdo->prepare("UPDATE farms SET status='Approved',updatedAt=NOW() WHERE id=?")->execute([$appeal['farm_id']]);
        }
        $pdo->commit();
        echo json_encode(['success'=>true,'message'=>'Appeal approved, farm is now Active']);
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action']);
    exit;
}

// ── Fetch admin dashboard data ────────────────────────────────────────────────
$stats = [
    'total_users'      => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status='Active'")->fetchColumn(),
    'total_farms'      => (int)$pdo->query("SELECT COUNT(*) FROM farms WHERE status='Approved'")->fetchColumn(),
    'pending_farms'    => (int)$pdo->query("SELECT COUNT(*) FROM farms WHERE status='Pending'")->fetchColumn(),
    'total_livestock'  => (int)$pdo->query("SELECT COALESCE(SUM(qty),0) FROM livestock")->fetchColumn(),
    'active_incidents' => (int)$pdo->query("SELECT COUNT(*) FROM incidents WHERE status IN('Pending','In Progress')")->fetchColumn(),
    'pending_reports'  => (int)$pdo->query("SELECT COUNT(*) FROM public_reports WHERE status='Pending'")->fetchColumn(),
    'pending_users'    => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status='Pending'")->fetchColumn(),
    'pending_appeals'  => (int)$pdo->query("SELECT COUNT(*) FROM farm_appeals WHERE appeal_status='Pending'")->fetchColumn(),
];

$all_users    = $pdo->query("SELECT u.*,fp.profile_pix FROM users u LEFT JOIN farmer_profiles fp ON u.id=fp.user_id ORDER BY u.createdAt DESC")->fetchAll();
$all_farms    = $pdo->query("SELECT f.*,u.firstName,u.lastName,u.email,(SELECT COALESCE(SUM(qty),0) FROM livestock WHERE farmId=f.id) as total_livestock FROM farms f LEFT JOIN users u ON f.ownerId=u.id ORDER BY f.createdAt DESC")->fetchAll();
$all_livestock= $pdo->query("SELECT l.*,f.name as farm_name,u.firstName,u.lastName FROM livestock l JOIN farms f ON l.farmId=f.id LEFT JOIN users u ON f.ownerId=u.id ORDER BY l.createdAt DESC")->fetchAll();
$all_incidents= $pdo->query("SELECT i.*,f.name as farm_name,u.firstName,u.lastName FROM incidents i LEFT JOIN farms f ON i.farmId=f.id LEFT JOIN users u ON i.reporterId=u.id ORDER BY i.createdAt DESC")->fetchAll();
$pub_reports  = $pdo->query("SELECT * FROM public_reports ORDER BY createdAt DESC LIMIT 100")->fetchAll();
$farm_appeals = $pdo->query("SELECT fa.*,u.firstName,u.lastName,u.email FROM farm_appeals fa JOIN users u ON fa.user_id=u.id ORDER BY fa.created_at DESC")->fetchAll();
$audit_logs   = $pdo->query("SELECT al.*,u.firstName,u.lastName FROM audit_log al LEFT JOIN users u ON al.userId=u.id ORDER BY al.createdAt DESC LIMIT 200")->fetchAll();


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AgriTrace+ | Admin Panel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Syne:wght@700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.css" />
    <script src="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.js"></script>
    <style>
        :root {
            --sidebar-bg: #064e3b;
            --sidebar-hover: #059669;
            --accent: #10b981;
            --bg-light: #f8fafc;
            --sidebar-width: 280px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', sans-serif; background: var(--bg-light); display: flex; min-height: 100vh; overflow-x: hidden; }

        /* --- SIDEBAR --- */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--sidebar-bg);
            color: white;
            display: flex;
            flex-direction: column;
            position: fixed;
            height: 100vh;
            z-index: 1000;
            transition: transform 0.3s ease;
        }
        .sidebar-header { padding: 25px; border-bottom: 1px solid rgba(255,255,255,0.1); flex-shrink: 0; }
        .logo { font-family: 'Syne', sans-serif; font-size: 1.5rem; font-weight: 800; }
        .logo span { color: var(--accent); }

        .nav-menu { 
            flex: 1; 
            padding: 15px 0; 
            overflow-y: auto; 
            scrollbar-width: thin;
            scrollbar-color: var(--sidebar-hover) transparent;
        }
        .nav-menu::-webkit-scrollbar { width: 5px; }
        .nav-menu::-webkit-scrollbar-thumb { background: var(--sidebar-hover); border-radius: 10px; }

        .nav-item { 
            padding: 12px 25px; display: flex; align-items: center; gap: 12px; 
            cursor: pointer; transition: 0.2s; color: rgba(255,255,255,0.7); font-size: 0.95rem;
        }
        .nav-item:hover { background: rgba(255,255,255,0.1); color: white; }
        .nav-item.active { background: var(--sidebar-hover); color: white; border-right: 4px solid var(--accent); }

        .sidebar-footer { padding: 20px; background: rgba(0,0,0,0.2); display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
        .avatar-circle { width: 40px; height: 40px; background: var(--accent); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; color: white; text-transform: uppercase; }

        /* --- MAIN CONTENT --- */
        .main-content { flex: 1; margin-left: var(--sidebar-width); width: calc(100% - var(--sidebar-width)); }
        .top-bar { background: white; padding: 15px 25px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; position: sticky; top: 0; z-index: 100; }
        .content-area { padding: 30px; max-width: 1400px; margin: 0 auto; }

        /* --- UI COMPONENTS --- */
        .panel-section { display: none; animation: fadeIn 0.3s ease; }
        .panel-section.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }

        .card { background: white; padding: 25px; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        .section-header { margin-bottom: 25px; }
        .section-title { font-family: 'Syne', sans-serif; font-size: 1.8rem; color: #1e293b; }
        .section-desc { color: #64748b; font-size: 0.95rem; }

        .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0; display: flex; flex-direction: column; }
        .stat-val { font-size: 2rem; font-weight: 700; color: #064e3b; }
        .stat-lbl { color: #64748b; font-size: 0.85rem; font-weight: 500; }

        /* --- CHART GRID FIX --- */
        .chart-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .chart-container {
            position: relative;
            height: 320px; /* Controlled height to prevent stretching */
            width: 100%;
        }

        .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 15px; }
        input, select { width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-family: inherit; }
        .btn-panel { background: var(--accent); color: white; padding: 12px 24px; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; transition: 0.2s; }
        .btn-panel:hover { background: #059669; }

        table { width: 100%; border-collapse: collapse; }
        th { background: #f8fafc; padding: 12px; text-align: left; font-size: 0.75rem; color: #64748b; text-transform: uppercase; border-bottom: 2px solid #e2e8f0; }
        td { padding: 15px 12px; border-bottom: 1px solid #f1f5f9; font-size: 0.9rem; }

        .menu-toggle { display: none; background: none; border: none; font-size: 1.5rem; color: var(--sidebar-bg); cursor: pointer; }
        .sidebar-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: none; z-index: 999; }

        /* PREMIUM TOGGLE SWITCHES */
.toggle-switch {
    position: relative; width: 48px; height: 24px; background: #e5e7eb; 
    border-radius: 50px; cursor: pointer; transition: all 0.3s; display: inline-block;
}
.toggle-switch input { display: none; }
.toggle-slider {
    position: absolute; top: 2px; left: 2px; width: 20px; height: 20px; 
    background: white; border-radius: 50%; transition: all 0.3s; 
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
.toggle-switch input:checked + .toggle-slider {
    transform: translateX(24px); background: #10b981;
}
.toggle-switch input:checked ~ .toggle-slider {
    left: 2px; transform: translateX(24px);
}

/* HOVER EFFECTS */
.permission-item:hover {
    background: rgba(16,185,129,0.05) !important; border-color: #10b981 !important;
    transform: translateY(-1px); box-shadow: 0 8px 20px rgba(16,185,129,0.15);
}
.btn-premium:hover { 
    transform: translateY(-2px); box-shadow: 0 12px 35px rgba(16,185,129,0.4);
}
.btn-secondary:hover { 
    transform: translateY(-2px); box-shadow: 0 8px 25px rgba(107,114,128,0.4);
}
.btn-outline:hover { 
    background: #3b82f6; color: white; border-color: #3b82f6;
}

.table-container::-webkit-scrollbar { width: 8px; }
.table-container::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 10px; }
.table-container::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
.table-container::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

.card { background: white; padding: 30px; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
.content-area-inner { padding: 0 20px; }

.map-primary-btn:hover {
    transform: translateY(-3px) !important;
    box-shadow: 0 20px 45px rgba(59,130,246,0.5) !important;
}

.toggle-btn:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2) !important;
}

.legend-item:hover {
    transform: translateY(-5px);
    box-shadow: 0 20px 45px rgba(0,0,0,0.15) !important;
}

.map-preview-card .toggle-group .toggle-btn.active:hover {
    transform: translateY(-2px) !important;
}

.geo-controls-compact .toggle-compact-btn:hover {
    transform: translateY(-1px) !important;
    box-shadow: 0 6px 20px rgba(0,0,0,0.15) !important;
}

.geo-controls-compact .toggle-compact-btn.active {
    background: linear-gradient(135deg, #10b981, #059669) !important;
    color: white !important;
    box-shadow: 0 6px 20px rgba(16,185,129,0.3) !important;
}

.map-preview-mini {
    position: relative;
}

.map-preview-mini::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0; bottom: 0;
    background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="20" cy="20" r="3" fill="rgba(16,185,129,0.3)"/><circle cx="80" cy="40" r="2" fill="rgba(245,158,11,0.3)"/><circle cx="40" cy="80" r="3" fill="rgba(239,68,68,0.3)"/></svg>');
    pointer-events: none;
    z-index: 1;
}

@media (max-width: 768px) {
    .geo-controls-compact { padding: 20px !important; }
    .geo-controls-compact > div { flex-direction: column; gap: 15px; text-align: center; }
}

@media (max-width: 768px) {
    .geo-controls { flex-direction: column; align-items: stretch; }
    .map-primary-btn { width: 100%; justify-content: center; }
    .legend-grid { grid-template-columns: 1fr; }
}

.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
.form-group { margin-bottom: 20px; }
.input-group { display: flex; align-items: center; gap: 12px; }
.content-grid { max-width: 1400px; margin: 0 auto; }
.section-header-small h3 { margin: 0; }
@media (max-width: 768px) { .form-row { grid-template-columns: 1fr; } }

/* RESPONSIVE */
@media (max-width: 768px) {
    .permission-category { grid-column: 1 / -1; }
}

        @media (max-width: 1024px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .sidebar-overlay.open { display: block; }
            .main-content { margin-left: 0; width: 100%; }
            .menu-toggle { display: block; }
            .chart-grid { grid-template-columns: 1fr; }
        }

        :root {
        --primary: #4f46e5;
        --primary-hover: #4338ca;
        --success: #10b981;
        --warning: #f59e0b;
        --danger: #ef4444;
        --slate-50: #f8fafc;
        --slate-200: #e2e8f0;
        --slate-700: #334155;
        --slate-900: #0f172a;
    }

    .geo-panel-container {
        font-family: 'Inter', system-ui, sans-serif;
        max-width: 1200px;
        margin: 0 auto;
        padding: 16px;
    }

    /* Header Section */
    .section-header { margin-bottom: 24px; }
    .section-title { 
        font-family: 'Syne', sans-serif; 
        font-size: 1.75rem; 
        font-weight: 800; 
        color: var(--slate-900);
        margin: 0;
    }
    .section-desc { color: var(--slate-700); font-size: 0.95rem; margin-top: 4px; }

    /* Controls Wrapper */
    .geo-controls-card {
        background: white;
        border: 1px solid var(--slate-200);
        border-radius: 20px;
        padding: 20px;
        display: flex;
        flex-direction: column;
        gap: 20px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        margin-bottom: 24px;
    }

    @media (min-width: 768px) {
        .geo-controls-card {
            flex-direction: row;
            justify-content: space-between;
            align-items: center;
        }
    }

    /* Info Group */
    .info-group { display: flex; align-items: center; gap: 16px; }
    .icon-box {
        width: 54px; height: 54px;
        background: linear-gradient(135deg, var(--primary), #6366f1);
        border-radius: 14px;
        display: flex; align-items: center; justify-content: center;
        color: white; font-size: 1.5rem;
        box-shadow: 0 10px 15px -3px rgba(79, 70, 229, 0.3);
    }
    .farm-label { font-weight: 700; font-size: 1.1rem; color: var(--slate-900); }
    .count-badge {
        background: var(--slate-50);
        border: 1px solid var(--slate-200);
        color: var(--success);
        padding: 2px 12px;
        border-radius: 99px;
        font-size: 0.9rem;
        font-weight: 800;
    }

    /* Button Groupings */
    .action-group {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }

    @media (min-width: 600px) {
        .action-group { display: flex; align-items: center; }
    }

    .btn-main {
        background: var(--primary);
        color: white;
        border: none;
        padding: 12px 24px;
        border-radius: 12px;
        font-weight: 700;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        transition: all 0.2s;
        grid-column: span 2; /* Full width on mobile */
    }

    @media (min-width: 600px) { .btn-main { grid-column: auto; } }

    .btn-main:hover { background: var(--primary-hover); transform: translateY(-1px); }

    .toggle-pill {
        display: flex;
        background: var(--slate-50);
        padding: 4px;
        border-radius: 12px;
        border: 1px solid var(--slate-200);
    }

    .toggle-btn {
        border: none;
        background: transparent;
        padding: 10px 16px;
        border-radius: 8px;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 8px;
        font-weight: 600;
        font-size: 0.85rem;
        color: var(--slate-700);
        transition: all 0.2s;
    }

    .toggle-btn.active {
        background: white;
        color: var(--primary);
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }

    /* Map Preview */
    .map-container {
        position: relative;
        height: 350px;
        border-radius: 24px;
        overflow: hidden;
        background: #e0f2fe;
        border: 1px solid var(--slate-200);
    }

    .map-overlay-top {
        position: absolute;
        top: 20px; left: 20px;
        background: rgba(255, 255, 255, 0.8);
        backdrop-filter: blur(8px);
        padding: 12px 18px;
        border-radius: 14px;
        border: 1px solid rgba(255,255,255,0.3);
        z-index: 10;
    }

    .pulse-dot {
        height: 8px; width: 8px;
        background: var(--success);
        border-radius: 50%;
        display: inline-block;
        margin-right: 6px;
        animation: pulse 2s infinite;
    }

    :root {
        --primary: #10b981; /* AgriTrace Green */
        --danger: #ef4444;
        --warning: #f59e0b;
        --info: #3b82f6;
        --bg-subtle: #f8fafc;
        --border-color: #e2e8f0;
    }

    /* Layout & Cards */
    .panel-section { padding: 1.5rem; max-width: 1200px; margin: 0 auto; }
    .card { 
        background: white; 
        border-radius: 12px; 
        box-shadow: 0 1px 3px rgba(0,0,0,0.1); 
        border: 1px solid var(--border-color);
        margin-bottom: 1.5rem;
        overflow: hidden;
    }

    /* Stat Grid */
    .stat-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 1rem;
        margin-bottom: 2rem;
    }
    .stat-card {
        background: white;
        padding: 1.25rem;
        border-radius: 12px;
        border: 1px solid var(--border-color);
        display: flex;
        flex-direction: column;
    }
    .stat-lbl { color: #64748b; font-size: 0.875rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.025em; }
    .stat-val { font-size: 1.875rem; font-weight: 700; color: #1e293b; margin-top: 0.25rem; }

    /* Responsive Table to Cards */
    @media (max-width: 768px) {
        .audit-table thead { display: none; }
        .audit-table, .audit-table tbody, .audit-table tr, .audit-table td { display: block; width: 100%; }
        .audit-table tr { border-bottom: 1px solid var(--border-color); padding: 1rem; }
        .audit-table td { 
            display: flex; 
            justify-content: space-between; 
            padding: 0.5rem 0; 
            text-align: right;
            font-size: 0.9rem;
        }
        .audit-table td::before {
            content: attr(data-label);
            font-weight: 600;
            color: #64748b;
            text-align: left;
        }
        .filter-container { flex-direction: column; }
        .filter-container > * { width: 100% !important; }
    }

    /* Action Badges */
    .badge {
        padding: 2px 8px;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
    }
    .badge-delete { background: #fee2e2; color: #991b1b; }
    .badge-create { background: #dcfce7; color: #166534; }
    .badge-login { background: #dbeafe; color: #1e40af; }

    @keyframes pulse {
        0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
        70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(16, 185, 129, 0); }
        100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
    }

    @keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

.stat-card { 
    transition: all 0.3s ease; 
    cursor: pointer;
    position: relative;
    overflow: hidden;
}

.stat-card:hover { 
    transform: translateY(-4px); 
    box-shadow: 0 12px 35px rgba(0,0,0,0.15); 
}

.stat-card::after {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0; bottom: 0;
    background: linear-gradient(135deg, transparent, rgba(255,255,255,0.1));
    opacity: 0;
    transition: opacity 0.3s;
}

.stat-card:hover::after { opacity: 1; }

.stat-val {
    font-size: 2.2rem !important;
    font-weight: 800 !important;
    background: linear-gradient(135deg, #10b981, #059669);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}
    </style>
</head>
<body>

    <div class="sidebar-overlay" id="overlay" onclick="toggleMobileMenu()"></div>

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">Agri<span>Trace+</span></div>
            <p style="font-size: 0.7rem; opacity: 0.6; letter-spacing: 1px;">ADMIN PANEL</p>
        </div>
        
        <nav class="nav-menu">
            <div class="nav-item active" onclick="showPanel('dashboard')"><i class="bi bi-grid-1x2"></i> Admin Dashboard</div>
            <div class="nav-item" onclick="showPanel('users')"><i class="bi bi-people"></i> User Management</div>
            <div class="nav-item" onclick="showPanel('roles')"><i class="bi bi-shield-check"></i> Role & Permissions</div>
            <div class="nav-item" onclick="showPanel('config')"><i class="bi bi-gear"></i> System Config</div>
            <div class="nav-item" onclick="showPanel('data')"><i class="bi bi-database"></i> Data Management</div>
            <div class="nav-item" onclick="showPanel('geo')"><i class="bi bi-geo-alt"></i> Geo-Mapping</div>
            <div class="nav-item" onclick="showPanel('audit')"><i class="bi bi-lock"></i> Audit & Security</div>
            <div class="nav-item" onclick="showPanel('reports')"><i class="bi bi-bar-chart"></i> Reports & Analytics</div>
            <a href="login.php" style="text-decoration: none;">
                <div class="nav-item" style="color: #fca5a5; margin-top: 20px;"><i class="bi bi-box-arrow-right"></i> Logout</div>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="avatar-circle"><?php echo substr($user_name, 0, 1); ?></div>
            <div>
                <p style="font-weight: 700; font-size: 0.9rem;"><?php echo $user_name; ?></p>
                <p style="font-size: 0.75rem; opacity: 0.8;"><?php echo $user_role; ?></p>
            </div>
        </div>
    </aside>

    <main class="main-content">
        <header class="top-bar">
            <div style="display:flex; align-items:center; gap:15px;">
                <button class="menu-toggle" onclick="toggleMobileMenu()"><i class="bi bi-list"></i></button>
                <h3 id="panel-title" style="font-weight: 700; color: #064e3b;">Admin Dashboard</h3>
            </div>
            <div style="display:flex; align-items:center; gap:20px;">
                <span style="font-size: 0.85rem; color: #64748b;"><?php echo $current_date; ?></span>
                <div class="avatar-circle" style="width:32px; height:32px; font-size: 0.8rem;"><?php echo substr($user_name, 0, 1); ?></div>
            </div>
        </header>

        <div class="content-area">
            
            <!-- DASHBOARD SECTION - Replace the entire #panel-dashboard section -->
<section id="panel-dashboard" class="panel-section active">
    <div class="section-header">
        <h1 class="section-title">System Overview</h1>
        <p class="section-desc">Real-time system health and activity summary • Last updated: <span id="statsLastUpdate">--</span></p>
    </div>

    <div class="stat-grid" id="dashboardStats">
        <div class="stat-card" data-stat="totalUsers">
            <div class="stat-val" id="statTotalUsers">0</div>
            <div class="stat-lbl">Total Users</div>
        </div>
        <div class="stat-card" data-stat="totalFarms">
            <div class="stat-val" id="statTotalFarms">0</div>
            <div class="stat-lbl">Registered Farms</div>
        </div>
        <div class="stat-card" data-stat="totalLivestock">
            <div class="stat-val" id="statTotalLivestock">0</div>
            <div class="stat-lbl">Total Livestock</div>
        </div>
        <div class="stat-card" style="border-left: 4px solid #ef4444;" data-stat="pendingReports">
            <div class="stat-val" id="statPendingReports">0</div>
            <div class="stat-lbl">Pending Reports</div>
        </div>
    </div>

    <!-- Refresh button -->
    <div style="text-align: center; margin-bottom: 30px;">
        <button onclick="loadDashboardStats()" class="btn-panel" style="background: #10b981; padding: 12px 24px;">
            <i class="bi bi-arrow-clockwise"></i> Refresh Stats
        </button>
    </div>

    <div class="chart-grid">
        <div class="card">
            <h3>System Activity</h3>
            <div class="chart-container">
                <canvas id="chartActivity"></canvas>
            </div>
        </div>
        <div class="card">
            <h3>Role Distribution</h3>
            <div class="chart-container">
                <canvas id="chartRoles"></canvas>
            </div>
        </div>
    </div>
</section>

            <section id="panel-users" class="panel-section">
                <div class="section-header">
                    <h1 class="section-title">User Management</h1>
                    <p class="section-desc">Manage all registered users and their account statuses</p>
                </div>
                
                <!-- CREATE NEW USER -->
                <div class="card" style="margin-bottom: 25px;">
                    <h3 style="display: flex; align-items: center; gap: 8px; margin-bottom: 20px;">
                        <i class="bi bi-person-plus"></i> Create New User
                    </h3>
                    <form id="createUserForm" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px;">
                        <div>
                            <label style="display: block; font-weight: 600; margin-bottom: 6px; color: #374151;">First Name</label>
                            <input type="text" name="firstName" placeholder="First Name" required>
                        </div>
                        <div>
                            <label style="display: block; font-weight: 600; margin-bottom: 6px; color: #374151;">Last Name</label>
                            <input type="text" name="lastName" placeholder="Last Name" required>
                        </div>
                        <div>
                            <label style="display: block; font-weight: 600; margin-bottom: 6px; color: #374151;">Email</label>
                            <input type="email" name="email" placeholder="user@example.com" required>
                        </div>
                        <div>
                            <label style="display: block; font-weight: 600; margin-bottom: 6px; color: #374151;">Mobile</label>
                            <input type="tel" name="mobile" placeholder="+63 912 345 6789">
                        </div>
                        <div>
                            <label style="display: block; font-weight: 600; margin-bottom: 6px; color: #374151;">Role</label>
                            <select name="role" required>
                                <option value="">Select Role</option>
                                <option value="Farmer">Farmer</option>
                                <option value="Agriculture Official">Agriculture Official</option>
                                <option value="Admin">Admin</option>
                            </select>
                        </div>
                        <div>
                            <label style="display: block; font-weight: 600; margin-bottom: 6px; color: #374151;">Password</label>
                            <input type="password" name="password" placeholder="Enter password" required minlength="8">
                        </div>
                        <div style="grid-column: 1 / -1; margin-top: 10px;">
                            <button type="submit" class="btn-panel" style="width: 200px;">
                                <i class="bi bi-person-plus"></i> Create User
                            </button>
                        </div>
                    </form>
                </div>

                <!-- ALL USERS TABLE -->
                <div class="card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                        <h3 style="display: flex; align-items: center; gap: 8px; margin: 0;">
                            <i class="bi bi-people"></i> All Users 
                            <span id="usersCount" style="background: #10b981; color: white; padding: 4px 10px; border-radius: 20px; font-size: 0.8rem; font-weight: 700;">0</span>
                        </h3>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <div style="display: flex; gap: 5px; align-items: center; background: #f8fafc; padding: 8px 12px; border-radius: 8px;">
                                <i class="bi bi-search" style="color: #6b7280;"></i>
                                <input type="text" id="userSearch" placeholder="Search users..." style="border: none; background: none; outline: none; font-size: 0.9rem;">
                            </div>
                            <select id="roleFilter" style="padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 0.9rem;">
                                <option value="">All Roles</option>
                                <option value="Farmer">Farmer</option>
                                <option value="Agriculture Official">Agriculture Official</option>
                                <option value="Admin">Admin</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="table-responsive">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background: #f8fafc;">
                                    <th style="padding: 15px 12px; text-align: left; font-weight: 600; color: #374151; font-size: 0.8rem; text-transform: uppercase; border-bottom: 2px solid #e5e7eb;">Name</th>
                                    <th style="padding: 15px 12px; text-align: left; font-weight: 600; color: #374151; font-size: 0.8rem; text-transform: uppercase; border-bottom: 2px solid #e5e7eb;">Email</th>
                                    <th style="padding: 15px 12px; text-align: left; font-weight: 600; color: #374151; font-size: 0.8rem; text-transform: uppercase; border-bottom: 2px solid #e5e7eb;">Role</th>
                                    <th style="padding: 15px 12px; text-align: left; font-weight: 600; color: #374151; font-size: 0.8rem; text-transform: uppercase; border-bottom: 2px solid #e5e7eb;">Status</th>
                                    <th style="padding: 15px 12px; text-align: left; font-weight: 600; color: #374151; font-size: 0.8rem; text-transform: uppercase; border-bottom: 2px solid #e5e7eb;">Joined</th>
                                    <th style="padding: 15px 12px; text-align: left; font-weight: 600; color: #374151; font-size: 0.8rem; text-transform: uppercase; border-bottom: 2px solid #e5e7eb;">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="usersTableBody">
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 40px; color: #9ca3af;">
                                        <i class="bi bi-people" style="font-size: 3rem; display: block; margin-bottom: 10px;"></i>
                                        <p>Loading users...</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
            <section id="panel-roles" class="panel-section">
    <div class="section-header">
        <h1 class="section-title">Role & Permissions</h1>
        <p class="section-desc">Configure granular access levels for DA Officers and limit their actions on Farmers</p>
    </div>
    
    <!-- OFFICER SELECTION -->
    <div class="card" style="margin-bottom: 25px; background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e2e8f0;">
            <h3 style="display: flex; align-items: center; gap: 8px; margin: 0; font-family: 'Syne', sans-serif; font-size: 1.3rem; color: #1e293b;">
                <i class="bi bi-person-shield"></i> DA Officers 
                <span id="officerCount" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: white; padding: 6px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: 700;">0</span>
            </h3>
            <div style="display: flex; gap: 10px;">
                <div style="position: relative;">
                    <input type="text" id="officerSearch" placeholder="🔍 Search officers..." style="
                        padding: 10px 16px 10px 40px; border: 2px solid #e5e7eb; border-radius: 12px; 
                        font-size: 0.9rem; transition: all 0.2s; width: 220px;
                    ">
                </div>
                <button onclick="loadOfficers()" class="btn-panel" style="padding: 10px 16px; font-size: 0.9rem; background: linear-gradient(135deg, #10b981, #059669);">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
            </div>
        </div>
        
        <div id="officersList" style="max-height: 350px; overflow-y: auto; padding-right: 10px;">
            <div style="text-align: center; padding: 40px; color: #9ca3af;">
                <i class="bi bi-people" style="font-size: 3rem; display: block; margin-bottom: 10px;"></i>
                <p>Loading DA Officers...</p>
            </div>
        </div>
    </div>

    <!-- PERMISSIONS PANEL - BEAUTIFUL REDESIGN -->
<div class="card" style="background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); border: 1px solid #e2e8f0; box-shadow: 0 10px 25px rgba(0,0,0,0.05);">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; padding-bottom: 20px; border-bottom: 2px solid #f1f5f9;">
        <h3 style="display: flex; align-items: center; gap: 12px; margin: 0; font-family: 'Syne', sans-serif; font-size: 1.5rem; color: #1e293b; font-weight: 700;">
            <div style="width: 48px; height: 48px; background: linear-gradient(135deg, #3b82f6, #1d4ed8); border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                <i class="bi bi-shield-check" style="font-size: 1.5rem; color: white;"></i>
            </div>
            Officer Permissions Matrix
        </h3>
        <div style="display: flex; gap: 12px;">
            <span id="selectedOfficerPerms" style="background: #f3f4f6; color: #6b7280; padding: 8px 16px; border-radius: 25px; font-size: 0.85rem; font-weight: 500;">Select an Officer</span>
        </div>
    </div>

    <!-- PERMISSION CATEGORIES - BEAUTIFUL GRID -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 25px; margin-bottom: 30px;">
        
        <!-- FARMER MANAGEMENT -->
        <div class="permission-category" data-category="farmer">
            <div class="category-header" style="display: flex; align-items: center; gap: 12px; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #f0f9ff;">
                <div class="category-icon" style="width: 50px; height: 50px; background: linear-gradient(135deg, #10b981, #059669); border-radius: 14px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(16,185,129,0.3);">
                    <i class="bi bi-house-gear" style="font-size: 1.3rem; color: white;"></i>
                </div>
                <div>
                    <h4 style="margin: 0; font-family: 'Syne', sans-serif; font-size: 1.2rem; font-weight: 700; color: #059669;">Farmer Management</h4>
                    <p style="margin: 4px 0 0 0; font-size: 0.9rem; color: #6b7280;">Control access to farmer accounts and farm operations</p>
                </div>
            </div>
            <div class="permission-items">
                <label class="permission-item" style="display: flex; align-items: center; gap: 14px; padding: 14px 18px; margin-bottom: 10px; background: rgba(255,255,255,0.7); border: 1px solid #e5e7eb; border-radius: 12px; cursor: pointer; transition: all 0.2s; font-size: 0.95rem;">
                    <div class="toggle-switch">
                        <input type="checkbox" class="permission-checkbox" data-action="view_farmers" data-category="farmer" checked>
                        <span class="toggle-slider"></span>
                    </div>
                    <div>
                        <div style="font-weight: 600; color: #1f2937; margin-bottom: 4px;">View all farmers in region</div>
                        <div style="font-size: 0.8rem; color: #9ca3af;">Access farmer profiles and farm details</div>
                    </div>
                </label>
                
                <label class="permission-item" style="display: flex; align-items: center; gap: 14px; padding: 14px 18px; margin-bottom: 10px; background: rgba(255,255,255,0.7); border: 1px solid #e5e7eb; border-radius: 12px; cursor: pointer; transition: all 0.2s; font-size: 0.95rem;">
                    <div class="toggle-switch">
                        <input type="checkbox" class="permission-checkbox" data-action="approve_farms" data-category="farmer" checked>
                        <span class="toggle-slider"></span>
                    </div>
                    <div>
                        <div style="font-weight: 600; color: #1f2937; margin-bottom: 4px;">Approve/Reject farm registrations</div>
                        <div style="font-size: 0.8rem; color: #9ca3af;">Review and approve new farm registrations</div>
                    </div>
                </label>
                
                <label class="permission-item" style="display: flex; align-items: center; gap: 14px; padding: 14px 18px; margin-bottom: 10px; background: rgba(255,255,255,0.7); border: 1px solid #e5e7eb; border-radius: 12px; cursor: pointer; transition: all 0.2s; font-size: 0.95rem;">
                    <div class="toggle-switch">
                        <input type="checkbox" class="permission-checkbox" data-action="inspect_farms" data-category="farmer">
                        <span class="toggle-slider"></span>
                    </div>
                    <div>
                        <div style="font-weight: 600; color: #1f2937; margin-bottom: 4px;">Schedule farm inspections</div>
                        <div style="font-size: 0.8rem; color: #9ca3af;">Create inspection schedules and reports</div>
                    </div>
                </label>
                
                <label class="permission-item" style="display: flex; align-items: center; gap: 14px; padding: 14px 18px; margin-bottom: 0; background: rgba(255,255,255,0.7); border: 1px solid #e5e7eb; border-radius: 12px; cursor: pointer; transition: all 0.2s; font-size: 0.95rem;">
                    <div class="toggle-switch">
                        <input type="checkbox" class="permission-checkbox" data-action="suspend_farmers" data-category="farmer">
                        <span class="toggle-slider"></span>
                    </div>
                    <div>
                        <div style="font-weight: 600; color: #1f2937; margin-bottom: 4px;">Suspend farmer accounts</div>
                        <div style="font-size: 0.8rem; color: #9ca3af;">Temporary/permanent account suspension</div>
                    </div>
                </label>
            </div>
        </div>

        <!-- LIVESTOCK OVERSIGHT -->
        <div class="permission-category" data-category="livestock">
            <div class="category-header" style="display: flex; align-items: center; gap: 12px; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #fef3c7;">
                <div class="category-icon" style="width: 50px; height: 50px; background: linear-gradient(135deg, #f59e0b, #d97706); border-radius: 14px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(245,158,11,0.3);">
                    <i class="bi bi-journal-medical" style="font-size: 1.3rem; color: white;"></i>
                </div>
                <div>
                    <h4 style="margin: 0; font-family: 'Syne', sans-serif; font-size: 1.2rem; font-weight: 700; color: #d97706;">Livestock Oversight</h4>
                    <p style="margin: 4px 0 0 0; font-size: 0.9rem; color: #92400e;">Monitor animal health and vaccination records</p>
                </div>
            </div>
            <div class="permission-items">
                <label class="permission-item" style="display: flex; align-items: center; gap: 14px; padding: 14px 18px; margin-bottom: 10px; background: rgba(255,255,255,0.7); border: 1px solid #e5e7eb; border-radius: 12px; cursor: pointer; transition: all 0.2s; font-size: 0.95rem;">
                    <div class="toggle-switch">
                        <input type="checkbox" class="permission-checkbox" data-action="view_livestock" data-category="livestock" checked>
                        <span class="toggle-slider"></span>
                    </div>
                    <div>
                        <div style="font-weight: 600; color: #1f2937; margin-bottom: 4px;">View livestock records</div>
                        <div style="font-size: 0.8rem; color: #9ca3af;">Access all animal health data</div>
                    </div>
                </label>
                <label class="permission-item" style="display: flex; align-items: center; gap: 14px; padding: 14px 18px; margin-bottom: 10px; background: rgba(255,255,255,0.7); border: 1px solid #e5e7eb; border-radius: 12px; cursor: pointer; transition: all 0.2s; font-size: 0.95rem;">
                    <div class="toggle-switch">
                        <input type="checkbox" class="permission-checkbox" data-action="audit_livestock" data-category="livestock">
                        <span class="toggle-slider"></span>
                    </div>
                    <div>
                        <div style="font-weight: 600; color: #1f2937; margin-bottom: 4px;">Audit vaccination records</div>
                        <div style="font-size: 0.8rem; color: #9ca3af;">Verify vaccination compliance</div>
                    </div>
                </label>
                <label class="permission-item" style="display: flex; align-items: center; gap: 14px; padding: 14px 18px; margin-bottom: 0; background: rgba(255,255,255,0.7); border: 1px solid #e5e7eb; border-radius: 12px; cursor: pointer; transition: all 0.2s; font-size: 0.95rem;">
                    <div class="toggle-switch">
                        <input type="checkbox" class="permission-checkbox" data-action="quarantine_livestock" data-category="livestock">
                        <span class="toggle-slider"></span>
                    </div>
                    <div>
                        <div style="font-weight: 600; color: #1f2937; margin-bottom: 4px;">Issue quarantine orders</div>
                        <div style="font-size: 0.8rem; color: #9ca3af;">Enforce quarantine protocols</div>
                    </div>
                </label>
            </div>
        </div>

        <!-- INCIDENT RESPONSE -->
        <div class="permission-category" data-category="incident">
            <div class="category-header" style="display: flex; align-items: center; gap: 12px; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #fecaca;">
                <div class="category-icon" style="width: 50px; height: 50px; background: linear-gradient(135deg, #ef4444, #dc2626); border-radius: 14px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(239,68,68,0.3);">
                    <i class="bi bi-exclamation-triangle" style="font-size: 1.3rem; color: white;"></i>
                </div>
                <div>
                    <h4 style="margin: 0; font-family: 'Syne', sans-serif; font-size: 1.2rem; font-weight: 700; color: #dc2626;">Incident Response</h4>
                    <p style="margin: 4px 0 0 0; font-size: 0.9rem; color: #991b1b;">Handle disease outbreaks and emergencies</p>
                </div>
            </div>
            <div class="permission-items">
                <label class="permission-item" style="display: flex; align-items: center; gap: 14px; padding: 14px 18px; margin-bottom: 10px; background: rgba(255,255,255,0.7); border: 1px solid #e5e7eb; border-radius: 12px; cursor: pointer; transition: all 0.2s; font-size: 0.95rem;">
                    <div class="toggle-switch">
                        <input type="checkbox" class="permission-checkbox" data-action="manage_incidents" data-category="incident" checked>
                        <span class="toggle-slider"></span>
                    </div>
                    <div>
                        <div style="font-weight: 600; color: #1f2937; margin-bottom: 4px;">Manage incident reports</div>
                        <div style="font-size: 0.8rem; color: #9ca3af;">Process and track incidents</div>
                    </div>
                </label>
                <label class="permission-item" style="display: flex; align-items: center; gap: 14px; padding: 14px 18px; margin-bottom: 10px; background: rgba(255,255,255,0.7); border: 1px solid #e5e7eb; border-radius: 12px; cursor: pointer; transition: all 0.2s; font-size: 0.95rem;">
                    <div class="toggle-switch">
                        <input type="checkbox" class="permission-checkbox" data-action="dispatch_teams" data-category="incident">
                        <span class="toggle-slider"></span>
                    </div>
                    <div>
                        <div style="font-weight: 600; color: #1f2937; margin-bottom: 4px;">Dispatch response teams</div>
                        <div style="font-size: 0.8rem; color: #9ca3af;">Coordinate field response teams</div>
                    </div>
                </label>
                <label class="permission-item" style="display: flex; align-items: center; gap: 14px; padding: 14px 18px; margin-bottom: 0; background: rgba(255,255,255,0.7); border: 1px solid #e5e7eb; border-radius: 12px; cursor: pointer; transition: all 0.2s; font-size: 0.95rem;">
                    <div class="toggle-switch">
                        <input type="checkbox" class="permission-checkbox" data-action="issue_alerts" data-category="incident">
                        <span class="toggle-slider"></span>
                    </div>
                    <div>
                        <div style="font-weight: 600; color: #1f2937; margin-bottom: 4px;">Issue disease alerts</div>
                        <div style="font-size: 0.8rem; color: #9ca3af;">Send emergency notifications</div>
                    </div>
                </label>
            </div>
        </div>

        <!-- REPORTS ACCESS -->
        <div class="permission-category" data-category="reports">
            <div class="category-header" style="display: flex; align-items: center; gap: 12px; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e0e7ff;">
                <div class="category-icon" style="width: 50px; height: 50px; background: linear-gradient(135deg, #8b5cf6, #7c3aed); border-radius: 14px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(139,92,246,0.3);">
                    <i class="bi bi-file-bar-graph" style="font-size: 1.3rem; color: white;"></i>
                </div>
                <div>
                    <h4 style="margin: 0; font-family: 'Syne', sans-serif; font-size: 1.2rem; font-weight: 700; color: #7c3aed;">Reports Access</h4>
                    <p style="margin: 4px 0 0 0; font-size: 0.9rem; color: #3730a3;">Analytics and data export capabilities</p>
                </div>
            </div>
            <div class="permission-items">
                <label class="permission-item" style="display: flex; align-items: center; gap: 14px; padding: 14px 18px; margin-bottom: 10px; background: rgba(255,255,255,0.7); border: 1px solid #e5e7eb; border-radius: 12px; cursor: pointer; transition: all 0.2s; font-size: 0.95rem;">
                    <div class="toggle-switch">
                        <input type="checkbox" class="permission-checkbox" data-action="view_reports" data-category="reports" checked>
                        <span class="toggle-slider"></span>
                    </div>
                    <div>
                        <div style="font-weight: 600; color: #1f2937; margin-bottom: 4px;">View regional analytics</div>
                        <div style="font-size: 0.8rem; color: #9ca3af;">Access dashboard and reports</div>                </label>
                <label class="permission-item" style="display: flex; align-items: center; gap: 14px; padding: 14px 18px; margin-bottom: 0; background: rgba(255,255,255,0.7); border: 1px solid #e5e7eb; border-radius: 12px; cursor: pointer; transition: all 0.2s; font-size: 0.95rem;">
                    <div class="toggle-switch">
                        <input type="checkbox" class="permission-checkbox" data-action="export_data" data-category="reports">
                        <span class="toggle-slider"></span>
                    </div>
                    <div>
                        <div style="font-weight: 600; color: #1f2937; margin-bottom: 4px;">Export reports</div>
                        <div style="font-size: 0.8rem; color: #9ca3af;">Download CSV/PDF reports</div>
                    </div>
                </label>
            </div>
        </div>
    </div>

    <!-- ACTION BUTTONS - PREMIUM DESIGN -->
    <div style="display: flex; gap: 15px; padding-top: 25px; border-top: 2px solid #f8fafc; justify-content: center; flex-wrap: wrap;">
        <button onclick="savePermissions()" class="btn-premium" style="
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white; padding: 14px 32px; border: none; border-radius: 12px; 
            cursor: pointer; font-weight: 700; font-size: 0.95rem; font-family: 'Syne', sans-serif;
            display: flex; align-items: center; gap: 10px; box-shadow: 0 8px 25px rgba(16,185,129,0.3);
            transition: all 0.3s; position: relative; overflow: hidden;
        ">
            <i class="bi bi-save-2"></i>
            Save Permissions Matrix
        </button>
        
        <button onclick="resetPermissions()" class="btn-secondary" style="
            background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);
            color: white; padding: 14px 24px; border: none; border-radius: 12px; 
            cursor: pointer; font-weight: 600; font-size: 0.9rem;
            display: flex; align-items: center; gap: 8px; box-shadow: 0 4px 15px rgba(107,114,128,0.3);
            transition: all 0.3s;
        ">
            <i class="bi bi-arrow-counterclockwise"></i>
            Reset to Default
        </button>
        
        <button onclick="previewPermissions()" class="btn-outline" style="
            background: transparent; color: #3b82f6; padding: 14px 24px; border: 2px solid #dbeafe; 
            border-radius: 12px; cursor: pointer; font-weight: 600; font-size: 0.9rem;
            display: flex; align-items: center; gap: 8px; transition: all 0.3s;
        ">
            <i class="bi bi-eye"></i>
            Preview Changes
        </button>
    </div>

    <!-- SUMMARY STATS -->
    <div style="display: flex; justify-content: space-around; margin-top: 25px; padding: 20px; background: #f8fafc; border-radius: 12px; font-size: 0.9rem;">
        <div style="text-align: center;">
            <div id="totalPermissions" style="font-size: 1.8rem; font-weight: 700; color: #10b981;">16</div>
            <div style="color: #6b7280;">Total Permissions</div>
        </div>
        <div style="text-align: center;">
            <div id="enabledPermissions" style="font-size: 1.8rem; font-weight: 700; color: #059669;">12</div>
            <div style="color: #6b7280;">Enabled</div>
        </div>
        <div style="text-align: center;">
            <div id="disabledPermissions" style="font-size: 1.8rem; font-weight: 700; color: #6b7280;">4</div>
            <div style="color: #6b7280;">Disabled</div>
        </div>
    </div>
</div>
</section>

            <section id="panel-config" class="panel-section">
    <div class="section-header">
        <h1 class="section-title">System Configuration</h1>
        <p class="section-desc">Configure application-wide settings, SMTP email, and SMS integrations</p>
    </div>
    
    <div class="content-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(450px, 1fr)); gap: 30px;">
        
        <!-- GENERAL SETTINGS -->
        <div class="card" style="background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);">
            <div class="section-header-small" style="display: flex; align-items: center; gap: 12px; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 2px solid #e2e8f0;">
                <div style="width: 45px; height: 45px; background: linear-gradient(135deg, #10b981, #059669); border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                    <i class="bi bi-gear" style="font-size: 1.3rem; color: white;"></i>
                </div>
                <div>
                    <h3 style="margin: 0; font-family: 'Syne', sans-serif; font-size: 1.3rem; font-weight: 700; color: #1e293b;">⚙️ General Settings</h3>
                    <p style="margin: 4px 0 0 0; color: #6b7280; font-size: 0.9rem;">Core application configuration</p>
                </div>
            </div>
            
            <div class="form-group">
                <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #374151;">System / Site Name</label>
                <div class="input-group">
                    <i class="bi bi-building" style="color: #6b7280; margin-right: 10px;"></i>
                    <input type="text" id="systemName" value="AgriTrace+" style="flex: 1; padding: 14px 16px 14px 0; border: none; font-size: 1.1rem; font-weight: 600; background: none; outline: none;">
                </div>
                <div style="border: 2px solid #e5e7eb; border-radius: 12px; padding: 16px; margin-bottom: 20px;"></div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Default Region</label>
                    <select id="defaultRegion" style="width: 100%; padding: 14px; border: 2px solid #e5e7eb; border-radius: 12px; background: white;">
                        <option>All Regions</option>
                        <option>Region 1 (Ilocos)</option>
                        <option>Region 2 (Cagayan Valley)</option>
                        <option>Region 3 (Central Luzon)</option>
                        <option>Region 4A (CALABARZON)</option>
                        <option>Region 4B (MIMAROPA)</option>
                        <option>Region 5 (Bicol)</option>
                        <option>Region 6 (Western Visayas)</option>
                        <option>Region 7 (Central Visayas)</option>
                        <option>Region 8 (Eastern Visayas)</option>
                        <option>Region 9 (Zamboanga)</option>
                        <option>Region 10 (Northern Mindanao)</option>
                        <option>Region 11 (Davao)</option>
                        <option>Region 12 (SOCCKSARGEN)</option>
                        <option>Region 13 (CARAGA)</option>
                        <option>CAR (Cordillera)</option>
                        <option>NCR (Metro Manila)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Session Timeout (minutes)</label>
                    <input type="number" id="sessionTimeout" value="30" min="5" max="120" style="width: 100%; padding: 14px; border: 2px solid #e5e7eb; border-radius: 12px;">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Max Login Attempts</label>
                    <input type="number" id="maxLoginAttempts" value="5" min="3" max="10" style="width: 100%; padding: 14px; border: 2px solid #e5e7eb; border-radius: 12px;">
                </div>
                <div class="form-group">
                    <label>Backup Frequency (days)</label>
                    <select id="backupFrequency" style="width: 100%; padding: 14px; border: 2px solid #e5e7eb; border-radius: 12px;">
                        <option value="1">Daily</option>
                        <option value="7" selected>Weekly</option>
                        <option value="14">Bi-weekly</option>
                        <option value="30">Monthly</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Min Password Length</label>
                <input type="number" id="minPasswordLength" value="8" min="6" max="20" style="width: 100%; padding: 14px; border: 2px solid #e5e7eb; border-radius: 12px;">
            </div>
        </div>

        <!-- EMAIL SETTINGS -->
        <div class="card" style="background: linear-gradient(135deg, #fef3c7 0%, #fefce8 100%);">
            <div class="section-header-small" style="display: flex; align-items: center; gap: 12px; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 2px solid #fde68a;">
                <div style="width: 45px; height: 45px; background: linear-gradient(135deg, #f59e0b, #d97706); border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                    <i class="bi bi-envelope" style="font-size: 1.3rem; color: white;"></i>
                </div>
                <div>
                    <h3 style="margin: 0; font-family: 'Syne', sans-serif; font-size: 1.3rem; font-weight: 700; color: #92400e;">📧 Email (SMTP) Settings</h3>
                    <p style="margin: 4px 0 0 0; color: #92400e; font-size: 0.9rem;">Configure transactional emails and notifications</p>
                </div>
            </div>

            <div class="form-group">
                <label>SMTP Host</label>
                <div class="input-group">
                    <i class="bi bi-server" style="color: #6b7280; margin-right: 10px;"></i>
                    <input type="text" id="smtpHost" value="smtp.gmail.com" placeholder="e.g. smtp.gmail.com" style="flex: 1; padding: 14px 16px 14px 0; border: 2px solid #e5e7eb; border-radius: 12px;">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>SMTP Port</label>
                    <select id="smtpPort" style="width: 100%; padding: 14px; border: 2px solid #e5e7eb; border-radius: 12px;">
                        <option>25</option>
                        <option>465 (SSL)</option>
                        <option selected>587 (TLS)</option>
                        <option>2525</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Encryption</label>
                    <select id="smtpEncryption" style="width: 100%; padding: 14px; border: 2px solid #e5e7eb; border-radius: 12px;">
                        <option>TLS</option>
                        <option>SSL</option>
                        <option>None</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>SMTP Username</label>
                <input type="email" id="smtpUsername" value="noreply@agritrace.ph" style="width: 100%; padding: 14px; border: 2px solid #e5e7eb; border-radius: 12px;">
            </div>

            <div class="form-group">
                <label>SMTP Password</label>
                <div style="position: relative;">
                    <input type="password" id="smtpPassword" placeholder="App password" style="width: 100%; padding: 14px; border: 2px solid #e5e7eb; border-radius: 12px;">
                    <button type="button" onclick="togglePassword('smtpPassword')" style="position: absolute; right: 14px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #6b7280; cursor: pointer;">
                        <i class="bi bi-eye" id="smtpToggleIcon"></i>
                    </button>
                </div>
            </div>

            <div class="form-group">
                <label>From Address</label>
                <input type="email" id="smtpFrom" value="noreply@agritrace.ph" style="width: 100%; padding: 14px; border: 2px solid #e5e7eb; border-radius: 12px;">
            </div>

            <div style="margin-top: 20px; padding: 15px; background: #ecfdf5; border: 1px solid #d1fae5; border-radius: 10px;">
                <strong>💡 Tip:</strong> For Gmail, generate an <a href="https://myaccount.google.com/apppasswords" target="_blank" style="color: #059669;">App Password</a>
            </div>
        </div>

        <!-- SMS SETTINGS -->
        <div class="card" style="background: linear-gradient(135deg, #eff6ff 0%, #e0f2fe 100%);">
            <div class="section-header-small" style="display: flex; align-items: center; gap: 12px; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 2px solid #bfdbfe;">
                <div style="width: 45px; height: 45px; background: linear-gradient(135deg, #3b82f6, #1d4ed8); border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                    <i class="bi bi-phone" style="font-size: 1.3rem; color: white;"></i>
                </div>
                <div>
                    <h3 style="margin: 0; font-family: 'Syne', sans-serif; font-size: 1.3rem; font-weight: 700; color: #1e40af;">📱 SMS Settings (OTP)</h3>
                    <p style="margin: 4px 0 0 0; color: #1e40af; font-size: 0.9rem;">Configure OTP and alert SMS notifications</p>
                </div>
            </div>

            <div class="form-group">
                <label>SMS Provider</label>
                <select id="smsProvider" style="width: 100%; padding: 14px; border: 2px solid #e5e7eb; border-radius: 12px;">
                    <option>Semaphore PH</option>
                    <option>Twilio</option>
                    <option>Nexmo (Vonage)</option>
                    <option>Globe Labs</option>
                    <option>Smart Money</option>
                    <option>Disabled</option>
                </select>
            </div>

            <div class="form-group">
                <label>API Key</label>
                <div style="position: relative;">
                    <input type="password" id="smsApiKey" placeholder="Enter provider API key" style="width: 100%; padding: 14px; border: 2px solid #e5e7eb; border-radius: 12px;">
                    <button type="button" onclick="togglePassword('smsApiKey')" style="position: absolute; right: 14px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #6b7280; cursor: pointer;">
                        <i class="bi bi-eye" id="smsToggleIcon"></i>
                    </button>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Sender ID</label>
                    <input type="text" id="smsSenderId" value="AgriTrace" placeholder="e.g. AgriTrace" style="width: 100%; padding: 14px; border: 2px solid #e5e7eb; border-radius: 12px;">
                </div>
                <div class="form-group">
                    <label>Messages per Day Limit</label>
                    <input type="number" id="smsDailyLimit" value="1000" min="100" max="10000" style="width: 100%; padding: 14px; border: 2px solid #e5e7eb; border-radius: 12px;">
                </div>
            </div>

            <div style="margin-top: 20px; padding: 15px; background: #ecfdf5; border: 1px solid #d1fae5; border-radius: 10px;">
                <strong>🚀 Test SMS:</strong> 
                <button onclick="testSMS()" class="btn-panel" style="background: #3b82f6; padding: 8px 16px; font-size: 0.85rem; margin-left: 10px;">
                    Send Test SMS
                </button>
            </div>
        </div>
    </div>

    <!-- SAVE BUTTONS -->
    <div class="card" style="text-align: center; padding: 30px;">
        <div style="display: flex; gap: 15px; justify-content: center; flex-wrap: wrap;">
            <button onclick="saveConfig()" class="btn-premium" style="
                background: linear-gradient(135deg, #10b981 0%, #059669 100%);
                color: white; padding: 16px 40px; border: none; border-radius: 14px; 
                cursor: pointer; font-weight: 700; font-size: 1rem; font-family: 'Syne', sans-serif;
                box-shadow: 0 10px 30px rgba(16,185,129,0.3);
            ">
                <i class="bi bi-save-2"></i> Save Configuration
            </button>
            <button onclick="testEmailConfig()" class="btn-secondary" style="
                background: linear-gradient(135deg, #3b82f6, #1d4ed8);
                color: white; padding: 16px 24px; border: none; border-radius: 14px; 
                cursor: pointer; font-weight: 600; font-size: 0.95rem;
            ">
                <i class="bi bi-envelope-check"></i> Test Email
            </button>
            <button onclick="resetConfig()" class="btn-outline" style="
                color: #6b7280; padding: 16px 24px; border: 2px solid #e5e7eb; border-radius: 14px; 
                background: white; cursor: pointer; font-weight: 600;
            ">
                <i class="bi bi-arrow-counterclockwise"></i> Reset Defaults
            </button>
        </div>
    </div>
</section>

<section id="panel-data" class="panel-section">
    <div class="section-header">
        <h1 class="section-title">Data Management</h1>
        <p class="section-desc">Export user data by role and create full database backups</p>
    </div>

    <div class="content-area-inner" style="max-width: 1400px; margin: 0 auto;">
        
        <!-- USER DATA EXPORT -->
        <div class="card" style="margin-bottom: 30px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
                <h3 style="display: flex; align-items: center; gap: 12px; margin: 0; font-family: 'Syne', sans-serif; font-size: 1.4rem; color: #1e293b;">
                    <i class="bi bi-people"></i> User Data Export
                </h3>
                <div style="display: flex; gap: 12px;">
                    <span id="totalUsersCount" style="background: #10b981; color: white; padding: 8px 16px; border-radius: 25px; font-weight: 700;">0</span>
                </div>
            </div>

            <!-- FILTER & SEARCH -->
            <div style="display: flex; gap: 15px; margin-bottom: 25px; flex-wrap: wrap; align-items: center;">
                <div style="position: relative; flex: 1; min-width: 250px;">
                    <input type="text" id="dataUserSearch" placeholder="🔍 Search users by name or email..." style="
                        width: 100%; padding: 14px 20px 14px 45px; border: 2px solid #e5e7eb; 
                        border-radius: 12px; font-size: 0.95rem;
                    ">
                </div>
                <select id="dataRoleFilter" style="padding: 14px 20px; border: 2px solid #e5e7eb; border-radius: 12px; font-size: 0.95rem; min-width: 180px;">
                    <option value="">All Roles</option>
                    <option value="Admin">Admins Only</option>
                    <option value="Agriculture Official">Officials Only</option>
                    <option value="Farmer">Farmers Only</option>
                </select>
                <select id="dataStatusFilter" style="padding: 14px 20px; border: 2px solid #e5e7eb; border-radius: 12px; font-size: 0.95rem; min-width: 160px;">
                    <option value="">All Status</option>
                    <option value="Active">Active Only</option>
                    <option value="Pending">Pending Only</option>
                    <option value="Inactive">Inactive Only</option>
                </select>
            </div>

            <!-- USERS TABLE -->
            <div class="table-container" style="max-height: 500px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 12px; background: white;">
                <table id="dataUsersTable" style="width: 100%; border-collapse: collapse;">
                    <thead style="position: sticky; top: 0; background: #f8fafc; z-index: 10;">
                        <tr>
                            <th style="padding: 18px 16px; text-align: left; font-weight: 700; color: #374151; font-size: 0.85rem; text-transform: uppercase; border-bottom: 3px solid #e5e7eb;">ID</th>
                            <th style="padding: 18px 16px; text-align: left; font-weight: 700; color: #374151; font-size: 0.85rem; text-transform: uppercase; border-bottom: 3px solid #e5e7eb;">Name</th>
                            <th style="padding: 18px 16px; text-align: left; font-weight: 700; color: #374151; font-size: 0.85rem; text-transform: uppercase; border-bottom: 3px solid #e5e7eb;">Email</th>
                            <th style="padding: 18px 16px; text-align: left; font-weight: 700; color: #374151; font-size: 0.85rem; text-transform: uppercase; border-bottom: 3px solid #e5e7eb;">Mobile</th>
                            <th style="padding: 18px 16px; text-align: left; font-weight: 700; color: #374151; font-size: 0.85rem; text-transform: uppercase; border-bottom: 3px solid #e5e7eb;">Role</th>
                            <th style="padding: 18px 16px; text-align: left; font-weight: 700; color: #374151; font-size: 0.85rem; text-transform: uppercase; border-bottom: 3px solid #e5e7eb;">Status</th>
                            <th style="padding: 18px 16px; text-align: left; font-weight: 700; color: #374151; font-size: 0.85rem; text-transform: uppercase; border-bottom: 3px solid #e5e7eb;">Joined</th>
                        </tr>
                    </thead>
                    <tbody id="dataUsersTableBody">
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 60px; color: #9ca3af;">
                                <i class="bi bi-people" style="font-size: 4rem; display: block; margin-bottom: 15px;"></i>
                                <p>Load data to view users</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- EXPORT BUTTONS -->
            <div style="display: flex; gap: 15px; margin-top: 25px; justify-content: center; flex-wrap: wrap;">
                <button onclick="exportFilteredUsers('csv')" class="btn-premium" style="background: linear-gradient(135deg, #10b981, #059669); padding: 14px 28px;">
                    <i class="bi bi-download"></i> Download CSV (Filtered)
                </button>
                <button onclick="exportAllUsers('csv')" class="btn-secondary" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8); padding: 14px 28px;">
                    <i class="bi bi-download"></i> Download All CSV
                </button>
                <button onclick="exportFilteredUsers('excel')" class="btn-outline" style="border: 2px solid #f59e0b; color: #d97706; padding: 14px 28px;">
                    <i class="bi bi-file-earmark-spreadsheet"></i> Excel (Filtered)
                </button>
            </div>
        </div>

        <!-- DATABASE BACKUP -->
        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
                <h3 style="display: flex; align-items: center; gap: 12px; margin: 0; font-family: 'Syne', sans-serif; font-size: 1.4rem; color: #1e293b;">
                    <i class="bi bi-database"></i> Full Database Backup
                </h3>
                <div style="display: flex; gap: 12px; align-items: center;">
                    <span id="backupSize" style="background: #6b7280; color: white; padding: 6px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 600;">Loading...</span>
                    <span id="lastBackup" style="color: #6b7280; font-size: 0.9rem;">Never</span>
                </div>
            </div>

            <div id="dbStatsContainer" style="background: #f8fafc; border: 2px solid #e5e7eb; border-radius: 12px; padding: 25px; margin-bottom: 25px;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 20px;">
                    <div id="statsTables">
                        <div style="font-size: 2.2rem; font-weight: 700; color: #10b981;">0</div>
                        <div style="color: #6b7280; font-size: 0.9rem;">Tables</div>
                    </div>
                    <div id="statsRecords">
                        <div style="font-size: 2.2rem; font-weight: 700; color: #3b82f6;">0</div>
                        <div style="color: #6b7280; font-size: 0.9rem;">Records</div>
                    </div>
                    <div id="statsSize">
                        <div style="font-size: 2.2rem; font-weight: 700; color: #f59e0b;">0 MB</div>
                        <div style="color: #6b7280; font-size: 0.9rem;">Size</div>
                    </div>
                </div>
                <div id="tablesList" style="font-size: 0.9rem; color: #6b7280;">
                    Loading tables...
                </div>
            </div>

            <!-- BACKUP BUTTONS -->
            <div style="display: flex; gap: 15px; justify-content: center; flex-wrap: wrap;">
                <button onclick="createFullBackup()" class="btn-premium" style="background: linear-gradient(135deg, #ef4444, #dc2626); padding: 16px 36px; font-size: 1rem;">
                    <i class="bi bi-download"></i> Create Full Backup (.sql)
                </button>
                <button onclick="downloadBackupList()" class="btn-secondary" style="background: linear-gradient(135deg, #f59e0b, #d97706); padding: 16px 24px;">
                    <i class="bi bi-list-ul"></i> Backup History
                </button>
                <a id="backupDownloadLink" style="display: none;">
                    <button class="btn-outline" style="border: 2px solid #10b981; color: #059669; padding: 16px 24px;">
                        <i class="bi bi-cloud-download"></i> Download Latest
                    </button>
                </a>
            </div>
        </div>
        </div>
    </div>
</section>

            <!-- Replace the entire <section id="panel-geo" class="geo-panel-container"> block with this: -->
<section id="panel-geo" class="panel-section">
    <div class="geo-panel-container">
        <div class="section-header">
            <h1 class="section-title">Geo-Mapping Control</h1>
            <p class="section-desc">Real-time livestock density and farm distribution</p>
        </div>

        <div class="geo-controls-card">
            <div class="info-group">
                <div class="icon-box">
                    <i class="bi bi-map-fill"></i>
                </div>
                <div>
                    <div class="farm-label">Farm Network</div>
                    <div style="display: flex; align-items: center; gap: 8px; margin-top: 2px;">
                        <span class="count-badge" id="farmCount">1,284</span>
                        <small style="color: var(--slate-700); font-weight: 500;">Active Sites</small>
                    </div>
                </div>
            </div>

            <div class="action-group">
                <div class="toggle-pill">
                    <button onclick="toggleLivestockLayer()" id="livestockToggle" class="toggle-btn active">
                        <i class="bi bi-piggy-bank"></i> Livestock
                    </button>
                    <button onclick="toggleIncidentsLayer()" id="incidentsToggle" class="toggle-btn">
                        <i class="bi bi-shield-exclamation"></i> Alerts
                    </button>
                </div>
                
                <button onclick="loadFarmMap()" class="btn-main">
                    <span>Expand Map</span>
                    <i class="bi bi-arrows-angle-expand"></i>
                </button>
            </div>
        </div>

        <div class="card">
            <div class="map-container">
                <div class="map-overlay-top">
                    <div style="font-weight: 800; font-size: 0.9rem; color: var(--slate-900);">
                        <span class="pulse-dot"></span> PHILIPPINES ARCHIPELAGO
                    </div>
                    <small style="color: var(--slate-700);">Live data feed active</small>
                </div>

                <div style="
                    position: absolute; inset: 0;
                    background-image: url('https://upload.wikimedia.org/wikipedia/commons/thumb/a/ae/Philippines_location_map.svg/800px-Philippines_location_map.svg.png');
                    background-size: contain; background-position: center; background-repeat: no-repeat;
                    opacity: 0.4; filter: grayscale(100%) brightness(1.2);
                "></div>
                
                <div style="position: absolute; top: 30%; left: 45%; width: 60px; height: 60px; background: var(--success); filter: blur(30px); opacity: 0.4;"></div>
                <div style="position: absolute; top: 60%; left: 50%; width: 80px; height: 80px; background: var(--warning); filter: blur(40px); opacity: 0.3;"></div>
            </div>
        </div>
    </div>
</section>
           
                        <section id="panel-audit" class="panel-section">
    <div class="section-header" style="margin-bottom: 2rem;">
        <h1 class="section-title" style="font-size: 1.75rem; color: #0f172a; margin-bottom: 0.5rem;">Audit & Security</h1>
        <p class="section-desc" style="color: #64748b;">Monitor real-time system activity and security logs.</p>
    </div>

    <div class="stat-grid">
        <div class="stat-card">
            <span class="stat-lbl">Total Events</span>
            <div class="stat-val" id="auditTotal">1,284</div>
        </div>
        <div class="stat-card">
            <span class="stat-lbl">Today's Events</span>
            <div class="stat-val" id="auditToday">42</div>
        </div>
        <div class="stat-card" style="border-top: 4px solid var(--danger);">
            <span class="stat-lbl">Critical Actions</span>
            <div class="stat-val" style="color: var(--danger);" id="auditCritical">3</div>
        </div>
    </div>

    <div class="card" style="padding: 1rem;">
        <div class="filter-container" style="display: flex; gap: 12px; align-items: center;">
            <div style="position: relative; flex: 2;">
                <input type="text" id="auditSearch" placeholder="Search by user or IP..." 
                       style="width: 100%; padding: 12px 12px 12px 40px; border: 1px solid var(--border-color); border-radius: 8px; outline-color: var(--primary);">
                <i class="bi bi-search" style="position: absolute; left: 15px; top: 14px; color: #94a3b8;"></i>
            </div>
            
            <select id="auditActionFilter" style="flex: 1; padding: 12px; border: 1px solid var(--border-color); border-radius: 8px; background: white;">
                <option value="">All Actions</option>
                <option value="CREATE">Create</option>
                <option value="UPDATE">Update</option>
                <option value="DELETE">Delete</option>
                <option value="LOGIN">Login</option>
            </select>

            <button onclick="loadAuditLogs()" class="btn-panel" style="padding: 12px 20px; background: var(--primary); color: white; border: none; border-radius: 8px; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                <i class="bi bi-arrow-clockwise"></i> Refresh
            </button>
        </div>
    </div>

    <div class="card">
        <table class="audit-table" style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: var(--bg-subtle); border-bottom: 1px solid var(--border-color); text-align: left;">
                    <th style="padding: 16px; color: #64748b; font-weight: 600; font-size: 0.85rem;">TIMESTAMP</th>
                    <th style="padding: 16px; color: #64748b; font-weight: 600; font-size: 0.85rem;">USER</th>
                    <th style="padding: 16px; color: #64748b; font-weight: 600; font-size: 0.85rem;">ACTION</th>
                    <th style="padding: 16px; color: #64748b; font-weight: 600; font-size: 0.85rem;">TARGET</th>
                    <th style="padding: 16px; color: #64748b; font-weight: 600; font-size: 0.85rem;">DETAILS</th>
                </tr>
            </thead>
            <tbody id="auditTableBody">
                <tr>
                    <td data-label="Timestamp" style="padding: 16px; font-size: 0.9rem;">Apr 05, 11:30 AM</td>
                    <td data-label="User" style="padding: 16px; font-weight: 500;">admin@agritrace.com</td>
                    <td data-label="Action" style="padding: 16px;"><span class="badge badge-delete">Delete</span></td>
                    <td data-label="Target" style="padding: 16px; font-size: 0.9rem; color: #64748b;">User ID: 502</td>
                    <td data-label="Details" style="padding: 16px; font-size: 0.85rem;">Removed inactive field officer</td>
                </tr>
            </tbody>
        </table>
        
        <div style="padding: 1rem; background: var(--bg-subtle); border-top: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <span style="font-size: 0.875rem; color: #64748b;">Showing <b id="auditShowing" style="color: #1e293b;">1</b> of 1,284 entries</span>
            <button onclick="exportAuditLogs('csv')" style="background: white; border: 1px solid var(--border-color); padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 0.875rem; font-weight: 500; display: flex; align-items: center; gap: 6px;">
                <i class="bi bi-download"></i> Export CSV
            </button>
        </div>
    </div>
</section>

<section id="panel-reports" class="panel-section">
    <div class="section-header">
        <h1 class="section-title">Reports & Analytics</h1>
        <p class="section-desc">Comprehensive analytics dashboard with export capabilities</p>
    </div>

    <!-- STATS OVERVIEW -->
    <div class="stat-grid" style="margin-bottom: 30px;">
        <div class="stat-card">
            <span class="stat-val" id="totalFarms">0</span>
            <span class="stat-lbl">Total Farms</span>
        </div>
        <div class="stat-card">
            <span class="stat-val" id="totalLivestock">0</span>
            <span class="stat-lbl">Livestock Head</span>
        </div>
        <div class="stat-card">
            <span class="stat-val" id="totalIncidents">0</span>
            <span class="stat-lbl">Incidents</span>
        </div>
        <div class="stat-card" style="border-left: 4px solid #ef4444;">
            <span class="stat-val" id="auditEvents">0</span>
            <span class="stat-lbl">Audit Events</span>
        </div>
    </div>

    <!-- CONTROLS -->
    <div class="card" style="margin-bottom: 25px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
            <div style="display: flex; gap: 12px; align-items: center;">
                <select id="reportDateRange" style="padding: 10px 15px; border: 2px solid #e5e7eb; border-radius: 8px;">
                    <option value="all">All Time</option>
                    <option value="30d">Last 30 Days</option>
                    <option value="90d">Last 90 Days</option>
                    <option value="year">This Year</option>
                </select>
                <select id="reportDataset" style="padding: 10px 15px; border: 2px solid #e5e7eb; border-radius: 8px;">
                    <option value="overview">Overview</option>
                    <option value="farms">Farms</option>
                    <option value="livestock">Livestock</option>
                    <option value="incidents">Incidents</option>
                    <option value="audit">Audit Logs</option>
                </select>
            </div>
            <div style="display: flex; gap: 10px;">
                <button onclick="refreshReports()" class="btn-panel" style="background: #10b981; padding: 10px 20px;">
                    <i class="bi bi-arrow-clockwise"></i> Refresh
                </button>
                <div class="export-dropdown">
                    <button onclick="toggleExportMenu()" id="exportBtn" class="btn-panel" style="background: #3b82f6; padding: 10px 20px; position: relative;">
                        <i class="bi bi-download"></i> Export
                        <i class="bi bi-chevron-down" style="margin-left: 5px;"></i>
                    </button>
                    <div id="exportMenu" style="display: none; position: absolute; right: 0; top: 100%; background: white; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); min-width: 180px; z-index: 1000;">
                        <button onclick="exportReport('csv')" style="width: 100%; padding: 12px 16px; border: none; background: none; text-align: left; cursor: pointer;">📄 CSV</button>
                        <button onclick="exportReport('excel')" style="width: 100%; padding: 12px 16px; border: none; background: none; text-align: left; cursor: pointer;">📊 Excel</button>
                        <button onclick="exportReport('pdf')" style="width: 100%; padding: 12px 16px; border: none; background: none; text-align: left; cursor: pointer;">📑 PDF</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- CHARTS GRID -->
    <div class="chart-grid">
        <!-- MAIN CHART -->
        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 id="mainChartTitle" style="margin: 0; font-family: 'Syne', sans-serif;">System Overview</h3>
                <div style="display: flex; gap: 10px;">
                    <button onclick="toggleChartType()" id="chartTypeBtn" class="btn-panel" style="padding: 8px 16px; font-size: 0.85rem; background: #f3f4f6; color: #374151;">
                        Bar Chart <i class="bi bi-bar-chart"></i>
                    </button>
                </div>
            </div>
            <div class="chart-container">
                <canvas id="mainReportChart"></canvas>
            </div>
        </div>

        <!-- SECONDARY CHARTS -->
        <div style="display: grid; grid-template-rows: 1fr 1fr; gap: 20px;">
            <div class="card">
                <h3 style="margin: 0 0 15px 0; font-family: 'Syne', sans-serif;">Distribution by Type</h3>
                <div class="chart-container" style="height: 150px;">
                    <canvas id="pieChart"></canvas>
                </div>
            </div>
            <div class="card">
                <h3 style="margin: 0 0 15px 0; font-family: 'Syne', sans-serif;">Status Breakdown</h3>
                <div class="chart-container" style="height: 150px;">
                    <canvas id="statusChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- DATA TABLE -->
    <div class="card" style="margin-top: 25px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h3 style="margin: 0; display: flex; align-items: center; gap: 8px;">
                <i class="bi bi-table"></i> Detailed Data 
                <span id="tableCount" style="background: #10b981; color: white; padding: 4px 10px; border-radius: 20px; font-size: 0.8rem; font-weight: 700;">0</span>
            </h3>
        </div>
        <div class="table-container" style="max-height: 400px; overflow-y: auto;">
            <table id="reportsTable">
                <thead id="reportsTableHead">
                    <tr style="background: #f8fafc;">
                        <th style="padding: 15px 12px;">ID</th>
                        <th style="padding: 15px 12px;">Name/Title</th>
                        <th style="padding: 15px 12px;">Type</th>
                        <th style="padding: 15px 12px;">Status</th>
                        <th style="padding: 15px 12px;">Date</th>
                    </tr>
                </thead>
                <tbody id="reportsTableBody">
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 40px; color: #9ca3af;">
                            <i class="bi bi-graph-up" style="font-size: 3rem; display: block; margin-bottom: 10px;"></i>
                            <p>Select a dataset to view details</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</section>
        </div>
    </main>

    <script>
        function toggleMobileMenu() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('overlay').classList.toggle('open');
        }

        function showPanel(panelId) {
            document.querySelectorAll('.panel-section').forEach(p => p.classList.remove('active'));
            document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
            
            document.getElementById('panel-' + panelId).classList.add('active');
            
            // Find nav item by data or icon
            const navItems = document.querySelectorAll('.nav-item');
            navItems.forEach(item => {
                if(item.innerText.toLowerCase().includes(panelId.substring(0,4))) {
                    item.classList.add('active');
                    document.getElementById('panel-title').innerText = item.innerText;
                }
            });

            if(window.innerWidth < 1024) toggleMobileMenu();
        }

        // === USER MANAGEMENT FUNCTIONALITY ===
            let allUsers = [];

            // Status badge generator
            function getStatusBadge(status) {
                const badges = {
                    'Active': '<span style="background: #10b981; color: white; padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600;">Active</span>',
                    'Pending': '<span style="background: #f59e0b; color: white; padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600;">Pending</span>',
                    'Inactive': '<span style="background: #6b7280; color: white; padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600;">Inactive</span>'
                };
                return badges[status] || '<span style="background: #ef4444; color: white; padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600;">Unknown</span>';
            }

            // Role badge generator
            function getRoleBadge(role) {
                const badges = {
                    'Admin': '<span style="background: #8b5cf6; color: white; padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600;">Admin</span>',
                    'Agriculture Official': '<span style="background: #3b82f6; color: white; padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600;">Official</span>',
                    'Farmer': '<span style="background: #10b981; color: white; padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600;">Farmer</span>'
                };
                return badges[role] || role;
            }

            // Load all users from database
            async function loadUsers() {
                try {
                    const response = await fetch('api/db.php?action=getAll&table=users');
                    const result = await response.json();
                    allUsers = result;
                    renderUsersTable(allUsers);
                } catch (error) {
                    console.error('Error loading users:', error);
                    document.getElementById('usersTableBody').innerHTML = `
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 40px; color: #ef4444;">
                                <i class="bi bi-exclamation-triangle" style="font-size: 2rem;"></i>
                                <p>Error loading users. Please refresh.</p>
                            </td>
                        </tr>
                    `;
                }
            }

            // Render users table
            function renderUsersTable(users) {
                const tbody = document.getElementById('usersTableBody');
                const countEl = document.getElementById('usersCount');
                
                if (users.length === 0) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 40px; color: #9ca3af;">
                                <i class="bi bi-people" style="font-size: 3rem; display: block; margin-bottom: 10px;"></i>
                                <p>No users found</p>
                            </td>
                        </tr>
                    `;
                    countEl.textContent = '0';
                    return;
                }

                tbody.innerHTML = users.map(user => `
                    <tr style="border-bottom: 1px solid #f3f4f6;">
                        <td style="padding: 15px 12px; font-weight: 500;">
                            ${user.firstName} ${user.lastName}
                        </td>
                        <td style="padding: 15px 12px; font-size: 0.9rem; color: #6b7280;">${user.email}</td>
                        <td style="padding: 15px 12px;">${getRoleBadge(user.role)}</td>
                        <td style="padding: 15px 12px;">${getStatusBadge(user.status)}</td>
                        <td style="padding: 15px 12px; font-size: 0.85rem; color: #6b7280;">
                            ${new Date(user.createdAt).toLocaleDateString('en-US', { 
                                year: 'numeric', month: 'short', day: 'numeric' 
                            })}
                        </td>
                        <td style="padding: 15px 12px;">
                            <div style="display: flex; gap: 6px;">
                                <button onclick="editUser(${user.id})" class="btn-edit" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button onclick="deleteUser(${user.id}, '${user.email}')" class="btn-delete" title="Delete">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                `).join('');

                countEl.textContent = users.length;
            }

            // Search and filter
            document.addEventListener('DOMContentLoaded', function() {
                const searchInput = document.getElementById('userSearch');
                const roleFilter = document.getElementById('roleFilter');
                
                function filterUsers() {
                    const searchTerm = searchInput.value.toLowerCase();
                    const selectedRole = roleFilter.value;
                    
                    let filtered = allUsers.filter(user => {
                        const matchesSearch = 
                            user.firstName.toLowerCase().includes(searchTerm) ||
                            user.lastName.toLowerCase().includes(searchTerm) ||
                            user.email.toLowerCase().includes(searchTerm);
                        
                        const matchesRole = !selectedRole || user.role === selectedRole;
                        
                        return matchesSearch && matchesRole;
                    });
                    
                    renderUsersTable(filtered);
                }
                
                searchInput.addEventListener('input', filterUsers);
                roleFilter.addEventListener('change', filterUsers);
                
                // Load users on page load
                loadUsers();
            });

            // Create new user
            document.getElementById('createUserForm').addEventListener('submit', async function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                const data = Object.fromEntries(formData);
                
                // Hash password client-side (for demo - use server-side in production)
                data.password = btoa(data.password); // Simple base64 (replace with proper hash)
                
                try {
                    const response = await fetch('api/db.php?action=insert&table=users', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(data)
                    });
                    
                    const result = await response.json();
                    
                    if (result.success || result.id) {
                        showToast('User created successfully!', 'success');
                        this.reset();
                        loadUsers(); // Refresh table
                    } else {
                        showToast('Error: ' + (result.error || 'Failed to create user'), 'error');
                    }
                } catch (error) {
                    showToast('Network error. Please try again.', 'error');
                }
            });

            // Delete user
            async function deleteUser(id, email) {
                if (!confirm(`Delete user "${email}"? This action cannot be undone.`)) return;
                
                try {
                    const response = await fetch(`api/db.php?action=delete&table=users&id=${id}`, { method: 'DELETE' });
                    const result = await response.json();
                    
                    if (result.success) {
                        showToast('User deleted successfully!', 'success');
                        loadUsers();
                    } else {
                        showToast('Error deleting user', 'error');
                    }
                } catch (error) {
                    showToast('Network error', 'error');
                }
            }

            // Edit user (placeholder)
            function editUser(id) {
                showToast('Edit functionality coming soon!', 'info');
            }

            // Toast notifications
            function showToast(message, type = 'success') {
                const toast = document.createElement('div');
                toast.style.cssText = `
                    position: fixed; top: 20px; right: 20px; z-index: 10000;
                    background: ${type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#3b82f6'};
                    color: white; padding: 12px 20px; border-radius: 8px;
                    box-shadow: 0 10px 25px rgba(0,0,0,0.2); font-weight: 500;
                    transform: translateX(400px); transition: all 0.3s ease;
                `;
                toast.innerHTML = `<i class="bi bi-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-triangle' : 'info-circle'} me-2"></i>${message}`;
                
                document.body.appendChild(toast);
                
                setTimeout(() => toast.style.transform = 'translateX(0)', 100);
                setTimeout(() => {
                    toast.style.transform = 'translateX(400px)';
                    setTimeout(() => toast.remove(), 300);
                }, 3000);
            }

            // === OFFICERS MANAGEMENT (Role & Permissions Panel) ===
// GLOBAL VARIABLES
let officers = [];
let selectedOfficerId = null;
let currentPermissions = {};

// ENHANCED loadOfficers
async function loadOfficers() {
    try {
        const response = await fetch('api/db.php?action=query&table=users&where=status%3D%27Active%27%20AND%20role%3D%27Agriculture%20Official%27');
        const result = await response.json();
        officers = result.data || result;
        renderOfficersList(officers);
        document.getElementById('officerCount').textContent = officers.length;
    } catch (error) {
        showToast('Error loading officers', 'error');
    }
}

// ENHANCED renderOfficersList with selection
function renderOfficersList(officersList) {
    const container = document.getElementById('officersList');
    if (officersList.length === 0) {
        container.innerHTML = noOfficersHTML();
        return;
    }

    container.innerHTML = officersList.map(officer => `
        <div class="officer-card ${selectedOfficerId === officer.id ? 'selected' : ''}" 
             onclick="selectOfficer(${officer.id}, '${officer.firstName} ${officer.lastName}')" 
             style="display: flex; justify-content: space-between; align-items: center; padding: 18px; 
                    border: 2px solid ${selectedOfficerId === officer.id ? '#3b82f6' : '#e5e7eb'}; 
                    border-radius: 14px; margin-bottom: 12px; background: ${selectedOfficerId === officer.id ? '#eff6ff' : 'white'};
                    cursor: pointer; transition: all 0.3s; box-shadow: ${selectedOfficerId === officer.id ? '0 8px 25px rgba(59,130,246,0.2)' : '0 2px 8px rgba(0,0,0,0.05)'};">
            <div style="display: flex; align-items: center; gap: 14px;">
                <div class="avatar-circle" style="width: 48px; height: 48px; background: linear-gradient(135deg, #3b82f6, #1d4ed8); font-size: 1.1rem; font-weight: 700;">
                    ${officer.firstName.charAt(0)}${officer.lastName.charAt(0)}
                </div>
                <div>
                    <div style="font-weight: 700; color: #1f2937; font-size: 1rem;">${officer.firstName} ${officer.lastName}</div>
                    <div style="font-size: 0.85rem; color: #6b7280;">${officer.email}</div>
                </div>
            </div>
            <div style="text-align: right;">
                <span style="background: #10b981; color: white; padding: 5px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; margin-bottom: 6px; display: block;">Active</span>
                <div style="font-size: 0.8rem; color: ${selectedOfficerId === officer.id ? '#1d4ed8' : '#6b7280'}; font-weight: 600;">
                    ${selectedOfficerId === officer.id ? 'Permissions Active' : 'Click to Edit'}
                </div>
            </div>
        </div>
    `).join('');
}

// SELECT OFFICER AND LOAD PERMISSIONS
async function selectOfficer(id, name) {
    selectedOfficerId = id;
    document.getElementById('selectedOfficerPerms').textContent = `${name} (ID: ${id})`;
    
    // Visual update
    renderOfficersList(officers);
    
    // Load this officer's permissions
    try {
        showToast('Loading permissions...', 'info');
        const response = await fetch(`api/db.php?action=getOfficerPermissions&officer_id=${id}`);
        currentPermissions = await response.json();
        loadOfficerPermissions(currentPermissions);
        updatePermissionStats();
        showToast(`Permissions loaded for ${name}`, 'success');
    } catch (error) {
        showToast('Error loading permissions', 'error');
    }
}

// LOAD PERMISSIONS INTO UI
function loadOfficerPermissions(permissions) {
    document.querySelectorAll('.permission-checkbox').forEach(cb => {
        cb.checked = permissions[cb.dataset.action] || false;
    });
}

// SAVE PERMISSIONS
async function savePermissions() {
    if (!selectedOfficerId) {
        showToast('Please select an officer first', 'error');
        return;
    }
    
    const permissions = {};
    document.querySelectorAll('.permission-checkbox').forEach(cb => {
        permissions[cb.dataset.action] = cb.checked ? 1 : 0;
    });
    
    try {
        const response = await fetch('api/db.php?action=saveOfficerPermissions', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                officer_id: selectedOfficerId,
                permissions: permissions
            })
        });
        
        const result = await response.json();
        if (result.success) {
            showToast('✅ Permissions saved successfully for selected officer!', 'success');
        } else {
            showToast('Error saving permissions', 'error');
        }
    } catch (error) {
        showToast('Network error', 'error');
    }
}

// HELPER FUNCTIONS
function noOfficersHTML() {
    return `
        <div style="text-align: center; padding: 50px 20px; color: #9ca3af;">
            <i class="bi bi-people-slash" style="font-size: 4rem; display: block; margin-bottom: 20px; opacity: 0.5;"></i>
            <h4 style="color: #374151; margin-bottom: 10px;">No Active Agriculture Officials</h4>
            <p style="margin-bottom: 20px;">Create one in <strong style="color: #3b82f6;">User Management</strong></p>
            <button onclick="showPanel('users')" class="btn-panel" style="background: #3b82f6; padding: 12px 24px;">
                <i class="bi bi-plus-circle"></i> Create Officer
            </button>
        </div>
    `;
}

// Auto-init
document.addEventListener('DOMContentLoaded', function() {
    loadOfficers();
});

// CONFIGURATION FUNCTIONS
function togglePassword(fieldId) {
    const field = document.getElementById(fieldId);
    const icon = document.getElementById(fieldId.replace('Password', 'ToggleIcon') || 'smsToggleIcon');
    if (field.type === 'password') {
        field.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        field.type = 'password';
        icon.className = 'bi bi-eye';
    }
}

async function saveConfig() {
    const config = {
        systemName: document.getElementById('systemName').value,
        defaultRegion: document.getElementById('defaultRegion').value,
        sessionTimeout: document.getElementById('sessionTimeout').value,
        maxLoginAttempts: document.getElementById('maxLoginAttempts').value,
        backupFrequency: document.getElementById('backupFrequency').value,
        minPasswordLength: document.getElementById('minPasswordLength').value,
        smtpHost: document.getElementById('smtpHost').value,
        smtpPort: document.getElementById('smtpPort').value,
        smtpEncryption: document.getElementById('smtpEncryption').value,
        smtpUsername: document.getElementById('smtpUsername').value,
        smtpPassword: document.getElementById('smtpPassword').value,
        smtpFrom: document.getElementById('smtpFrom').value,
        smsProvider: document.getElementById('smsProvider').value,
        smsApiKey: document.getElementById('smsApiKey').value,
        smsSenderId: document.getElementById('smsSenderId').value,
                smsDailyLimit: document.getElementById('smsDailyLimit').value
    };

    try {
        showToast('Saving configuration...', 'info');
        const response = await fetch('api/config.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(config)
        });
        
        const result = await response.json();
        if (result.success) {
            showToast('✅ Configuration saved successfully!', 'success');
        } else {
            showToast('Error: ' + result.error, 'error');
        }
    } catch (error) {
        showToast('Network error. Please try again.', 'error');
    }
}

async function testEmailConfig() {
    showToast('Sending test email...', 'info');
    try {
        const response = await fetch('api/config.php?action=testEmail', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                smtpHost: document.getElementById('smtpHost').value,
                smtpPort: document.getElementById('smtpPort').value,
                smtpUsername: document.getElementById('smtpUsername').value,
                smtpPassword: document.getElementById('smtpPassword').value
            })
        });
        
        const result = await response.json();
        if (result.success) {
            showToast('✅ Test email sent successfully!', 'success');
        } else {
            showToast('Email test failed: ' + result.error, 'error');
        }
    } catch (error) {
        showToast('Test failed', 'error');
    }
}

async function testSMS() {
    showToast('Sending test SMS...', 'info');
    try {
        const response = await fetch('api/config.php?action=testSMS', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                smsProvider: document.getElementById('smsProvider').value,
                smsApiKey: document.getElementById('smsApiKey').value,
                smsSenderId: document.getElementById('smsSenderId').value,
                phone: '+639123456789' // Replace with your test number
            })
        });
        
        const result = await response.json();
        if (result.success) {
            showToast('✅ Test SMS sent successfully!', 'success');
        } else {
            showToast('SMS test failed: ' + result.error, 'error');
        }
    } catch (error) {
        showToast('Test failed', 'error');
    }
}

function resetConfig() {
    if (confirm('Reset all settings to defaults?')) {
        // Reset all fields to default values
        document.getElementById('systemName').value = 'AgriTrace+';
        document.getElementById('defaultRegion').value = 'All Regions';
        document.getElementById('sessionTimeout').value = '30';
        document.getElementById('maxLoginAttempts').value = '5';
        document.getElementById('backupFrequency').value = '7';
        document.getElementById('minPasswordLength').value = '8';
        document.getElementById('smtpHost').value = 'smtp.gmail.com';
        document.getElementById('smtpPort').value = '587';
        document.getElementById('smtpEncryption').value = 'TLS';
        document.getElementById('smtpUsername').value = 'noreply@agritrace.ph';
        document.getElementById('smtpPassword').value = '';
        document.getElementById('smtpFrom').value = 'noreply@agritrace.ph';
        document.getElementById('smsProvider').value = 'Semaphore PH';
        document.getElementById('smsApiKey').value = '';
        document.getElementById('smsSenderId').value = 'AgriTrace';
        document.getElementById('smsDailyLimit').value = '1000';
        
        showToast('Settings reset to defaults', 'info');
    }
}

// DATA MANAGEMENT
let allDataUsers = [];

// Load users for data management
async function loadDataUsers() {
    try {
        const response = await fetch('api/db.php?action=getAll&table=users');
        allDataUsers = await response.json();
        renderDataUsersTable(allDataUsers);
        document.getElementById('totalUsersCount').textContent = allDataUsers.length;
    } catch (error) {
        console.error('Error loading data users:', error);
    }
}

// Render data users table
function renderDataUsersTable(users) {
    const tbody = document.getElementById('dataUsersTableBody');
    
    if (users.length === 0) {
        tbody.innerHTML = `
            <tr><td colspan="7" style="text-align: center; padding: 60px; color: #9ca3af;">
                <i class="bi bi-people-slash" style="font-size: 4rem; display: block; margin-bottom: 15px;"></i>
                <p>No users found</p>
            </td></tr>
        `;
        return;
    }

    tbody.innerHTML = users.map(user => `
        <tr style="border-bottom: 1px solid #f3f4f6;">
            <td style="padding: 16px; font-weight: 600; color: #374151;">${user.id}</td>
            <td style="padding: 16px; font-weight: 600; color: #1f2937;">${user.firstName} ${user.lastName}</td>
            <td style="padding: 16px; font-size: 0.9rem; color: #6b7280;">${user.email}</td>
            <td style="padding: 16px; font-size: 0.9rem; color: #6b7280;">${user.mobile || '—'}</td>
            <td style="padding: 16px;">${getRoleBadge(user.role)}</td>
            <td style="padding: 16px;">${getStatusBadge(user.status)}</td>
            <td style="padding: 16px; font-size: 0.85rem; color: #6b7280;">${new Date(user.createdAt).toLocaleDateString()}</td>
        </tr>
    `).join('');
}

// EXPORT FUNCTIONS
async function exportFilteredUsers(format = 'csv') {
    const filtered = getFilteredUsers();
    if (filtered.length === 0) {
        showToast('No data to export', 'error');
        return;
    }
    downloadUsers(filtered, `agritrace_users_${getRoleFilterName()}_${new Date().toISOString().split('T')[0]}.${format}`, format);
}

async function exportAllUsers(format = 'csv') {
    downloadUsers(allDataUsers, `agritrace_all_users_${new Date().toISOString().split('T')[0]}.${format}`, format);
}

function getFilteredUsers() {
    const search = document.getElementById('dataUserSearch').value.toLowerCase();
    const roleFilter = document.getElementById('dataRoleFilter').value;
    const statusFilter = document.getElementById('dataStatusFilter').value;

    return allDataUsers.filter(user => {
        const matchesSearch = 
            user.firstName.toLowerCase().includes(search) ||
            user.lastName.toLowerCase().includes(search) ||
            user.email.toLowerCase().includes(search);
        
        const matchesRole = !roleFilter || user.role === roleFilter;
        const matchesStatus = !statusFilter || user.status === statusFilter;
        
        return matchesSearch && matchesRole && matchesStatus;
    });
}

function downloadUsers(users, filename, format) {
    let csvContent = '';
    
    if (format === 'csv') {
        csvContent = 'ID,First Name,Last Name,Email,Mobile,Role,Status,Joined\n';
        users.forEach(user => {
            csvContent += `"${user.id}","${user.firstName}","${user.lastName}","${user.email}","${user.mobile || ''}","${user.role}","${user.status}","${user.createdAt}"\n`;
        });
        
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = filename;
        link.click();
    }
    
    showToast(`${users.length} users exported as ${format.toUpperCase()}`, 'success');
}

function getRoleFilterName() {
    const role = document.getElementById('dataRoleFilter').value;
    return role || 'all';
}

// FULL BACKUP
// DYNAMIC DB STATS & BACKUP
let dbStats = { tables: 0, records: 0, size: 0, tablesList: [] };

async function loadDBStats() {
    try {
        const response = await fetch('api/backup.php?action=stats');
        dbStats = await response.json();
        
        // Update stats display
        document.getElementById('statsTables').innerHTML = `
            <div style="font-size: 2.2rem; font-weight: 700; color: #10b981;">${dbStats.tables}</div>
            <div style="color: #6b7280; font-size: 0.9rem;">Tables</div>
        `;
        document.getElementById('statsRecords').innerHTML = `
            <div style="font-size: 2.2rem; font-weight: 700; color: #3b82f6;">${dbStats.records.toLocaleString()}</div>
            <div style="color: #6b7280; font-size: 0.9rem;">Records</div>
        `;
        document.getElementById('statsSize').innerHTML = `
            <div style="font-size: 2.2rem; font-weight: 700; color: #f59e0b;">${dbStats.size}</div>
            <div style="color: #6b7280; font-size: 0.9rem;">Size</div>
        `;
        
        document.getElementById('backupSize').textContent = dbStats.size;
        document.getElementById('tablesList').innerHTML = `
            Includes: <strong>${dbStats.tablesList.join(', ')}</strong>
        `;
        
    } catch (error) {
        console.error('Error loading DB stats:', error);
    }
}

async function createFullBackup() {
    if (!confirm(`Create backup of ${dbStats.tables} tables with ${dbStats.records.toLocaleString()} records?`)) return;
    
    try {
        showToast('Creating full backup...', 'info');
        const response = await fetch('api/backup.php?action=create');
        const result = await response.json();
        
        if (result.success) {
            document.getElementById('backupDownloadLink').href = result.download_url;
            document.getElementById('backupDownloadLink').style.display = 'inline-flex';
            document.getElementById('backupSize').textContent = result.size;
            document.getElementById('lastBackup').textContent = new Date().toLocaleString();
            showToast(`✅ Backup created (${result.size})! Click Download Latest`, 'success');
        } else {
            showToast('Backup failed: ' + result.error, 'error');
        }
    } catch (error) {
        showToast('Backup error: ' + error.message, 'error');
    }
}

// Auto-load stats when panel opens
document.addEventListener('DOMContentLoaded', function() {
    const statsObserver = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.target.id === 'panel-data' && mutation.target.classList.contains('active')) {
                loadDBStats();
            }
        });
    });
    statsObserver.observe(document.getElementById('panel-data'), { attributes: true, attributeFilter: ['class'] });
});

async function downloadBackupList() {
    window.open('api/backup.php?action=list', '_blank');
}

// FILTER EVENTS
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('dataUserSearch');
    const roleFilter = document.getElementById('dataRoleFilter');
    const statusFilter = document.getElementById('dataStatusFilter');
    
    function filterDataUsers() {
        const filtered = getFilteredUsers();
        renderDataUsersTable(filtered);
    }
    
    searchInput.addEventListener('input', filterDataUsers);
    roleFilter.addEventListener('change', filterDataUsers);
    statusFilter.addEventListener('change', filterDataUsers);
    
    // Load data when panel opens
    const dataObserver = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.target.id === 'panel-data' && mutation.target.classList.contains('active')) {
                loadDataUsers();
            }
        });
    });
    dataObserver.observe(document.getElementById('panel-data'), { attributes: true, attributeFilter: ['class'] });
});

// Geo panel functions
let mapLoaded = false;

async function loadFarmMap() {
    if (mapLoaded) {
        window.open('maps/farm-map.php', '_blank');
        return;
    }
    
    mapLoaded = true;
    window.open('maps/farm-map.php', '_blank');
    showToast('Opening interactive farm map...', 'info');
}

function toggleLivestockLayer() {
    showToast('Use the dedicated map page for livestock/incidents layers!', 'info');
}

function toggleIncidentsLayer() {
    showToast('Use the dedicated map page for incidents layer!', 'info');
}

// Update farm count display
document.addEventListener('DOMContentLoaded', function() {
    // Load initial farm count
    fetch('api/db.php?action=query&table=farms')
        .then(r => r.json())
        .then(data => {
            const farms = data.data || data;
            document.getElementById('farmCount').textContent = farms.length;
        });
});

// === AUDIT & SECURITY LOGIC ===
let allAuditLogs = [];

// Load audit logs from database
async function loadAuditLogs() {
    try {
        // Use correct table name from your PHP: 'audit_log' (no 's')
        const response = await fetch('api/db.php?action=getAll&table=audit_log');
        const logs = await response.json();
        
        if (Array.isArray(logs)) {
            allAuditLogs = logs;
            updateAuditStats(logs);
            renderAuditTable(logs);
            showToast('Audit logs loaded successfully', 'success');
        } else {
            console.error('Invalid data format:', logs);
            showToast('No audit logs found in database', 'info');
            renderAuditTable([]);
        }
    } catch (error) {
        console.error('Error loading audit logs:', error);
        showToast('Failed to load audit logs. Check if audit_log table exists.', 'error');
        renderAuditTable([]);
    }
}

// Update stats cards
function updateAuditStats(logs) {
    const total = logs.length;
    const today = new Date().toDateString();
    const todayLogs = logs.filter(log => new Date(log.createdAt).toDateString() === today).length;
    
    // Critical actions: DELETE, LOGIN_FAILED, PERMISSION_DENIED, etc.
    const critical = logs.filter(log => {
        const action = log.action ? log.action.toUpperCase() : '';
        return ['DELETE', 'LOGIN_FAILED', 'PERMISSION_DENIED', 'SECURITY_ALERT'].includes(action);
    }).length;

    document.getElementById('auditTotal').textContent = total.toLocaleString();
    document.getElementById('auditToday').textContent = todayLogs;
    document.getElementById('auditCritical').textContent = critical;
}

// Render audit table with flexible field mapping
function renderAuditTable(logs) {
    const tbody = document.getElementById('auditTableBody');
    const showingEl = document.getElementById('auditShowing');
    
    if (!logs || logs.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="5" style="text-align:center; padding:60px; color:#9ca3af;">
                    <i class="bi bi-shield-check" style="font-size:4rem; display:block; margin-bottom:20px; opacity:0.5;"></i>
                    <h4 style="color:#374151; margin-bottom:10px;">No Audit Logs Found</h4>
                    <p style="margin-bottom:20px;">System activity will appear here</p>
                    <button onclick="loadAuditLogs()" class="btn-panel" style="background:#3b82f6; padding:10px 20px;">
                        <i class="bi bi-arrow-clockwise"></i> Refresh
                    </button>
                </td>
            </tr>
        `;
        showingEl.textContent = '0';
        return;
    }

    tbody.innerHTML = logs.map(log => {
        // Flexible field mapping for different DB schemas
        const user = log.userEmail || 
                    (log.firstName ? `${log.firstName} ${log.lastName}` : 
                    (log.userId ? `User #${log.userId}` : 'System'));
        
        const target = log.tableName || log.target_table || 'System';
        const targetId = log.recordId || log.target_id || '-';
        const ip = log.ipAddress || log.ip_address || '0.0.0.0';
        
        // Dynamic Badge Colors
        const colors = {
            'CREATE': 'background:#d1fae5; color:#065f46;',
            'UPDATE': 'background:#dbeafe; color:#1e40af;',
            'DELETE': 'background:#fee2e2; color:#991b1b;',
            'LOGIN': 'background:#fef3c7; color:#92400e;',
            'LOGIN_FAILED': 'background:#fecaca; color:#991b1b;',
            'PERMISSION_DENIED': 'background:#fef3c7; color:#92400e;'
        };
        const actionUpper = log.action ? log.action.toUpperCase() : 'OTHER';
        const style = colors[actionUpper] || 'background:#f3f4f6; color:#374151;';

        // Parse Details Safely
        let detailsDisplay = '-';
        try {
            const d = typeof log.details === 'string' ? JSON.parse(log.details) : log.details;
            if (d && typeof d === 'object') {
                detailsDisplay = Object.entries(d)
                    .map(([k, v]) => `${k}: <b>${v}</b>`)
                    .join(' | ');
            } else {
                detailsDisplay = log.details || '-';
            }
        } catch(e) { 
            detailsDisplay = log.details || '-'; 
        }

        return `
            <tr style="border-bottom:1px solid #f3f4f6;">
                <td data-label="Timestamp" style="padding:12px; font-size:0.85rem; white-space:nowrap;">
                    ${new Date(log.createdAt).toLocaleString([], {month:'short', day:'numeric', hour:'2-digit', minute:'2-digit'})}
                </td>
                <td data-label="User" style="padding:12px;">
                    <div style="font-weight:600; color:#1f2937;">${user}</div>
                    <div style="font-size:0.75rem; color:#9ca3af;">${ip}</div>
                </td>
                <td data-label="Action" style="padding:12px;">
                    <span style="padding:6px 12px; border-radius:20px; font-size:0.75rem; font-weight:700; ${style}">
                        ${log.action || 'N/A'}
                    </span>
                </td>
                <td data-label="Target" style="padding:12px;">
                    <div style="font-size:0.9rem; color:#4b5563;">${target}</div>
                    <div style="font-size:0.75rem; color:#9ca3af;">ID: ${targetId}</div>
                </td>
                <td data-label="Details" style="padding:12px; font-size:0.8rem; color:#6b7280; max-width:300px;">
                    ${detailsDisplay}
                </td>
            </tr>
        `;
    }).join('');
    
    showingEl.textContent = logs.length.toLocaleString();
}

// Filter logs based on search and action type
function filterAuditLogs() {
    const searchTerm = document.getElementById('auditSearch').value.toLowerCase();
    const actionFilter = document.getElementById('auditActionFilter').value;

    const filtered = allAuditLogs.filter(log => {
        // Flexible user display
        const userDisplay = log.userEmail || 
                           (log.firstName ? `${log.firstName} ${log.lastName}` : 
                           (log.userId ? `User #${log.userId}` : 'System'));
        
        const matchesSearch = 
            (userDisplay && userDisplay.toLowerCase().includes(searchTerm)) ||
            (log.ipAddress && log.ipAddress.includes(searchTerm)) ||
            (log.action && log.action.toLowerCase().includes(searchTerm));
        
        const matchesAction = !actionFilter || (log.action && log.action.toUpperCase() === actionFilter.toUpperCase());

        return matchesSearch && matchesAction;
    });

    renderAuditTable(filtered);
}

// Export filtered logs to CSV
function exportAuditLogs(format = 'csv') {
    // Get currently filtered data
    const searchTerm = document.getElementById('auditSearch').value.toLowerCase();
    const actionFilter = document.getElementById('auditActionFilter').value;

    let dataToExport = allAuditLogs.filter(log => {
        const userDisplay = log.userEmail || 
                           (log.firstName ? `${log.firstName} ${log.lastName}` : 
                           (log.userId ? `User #${log.userId}` : 'System'));
        
        const matchesSearch = 
            (userDisplay && userDisplay.toLowerCase().includes(searchTerm)) ||
            (log.ipAddress && log.ipAddress.includes(searchTerm)) ||
            (log.action && log.action.toLowerCase().includes(searchTerm));
        
        const matchesAction = !actionFilter || (log.action && log.action.toUpperCase() === actionFilter.toUpperCase());
        return matchesSearch && matchesAction;
    });

    if (dataToExport.length === 0) {
        showToast('No data to export', 'error');
        return;
    }

    let csvContent = "data:text/csv;charset=utf-8,Timestamp,User,Action,Target,IP Address,Details\n";
    
    dataToExport.forEach(log => {
        const user = log.userEmail || (log.firstName ? `${log.firstName} ${log.lastName}` : (log.userId || 'System'));
        const target = log.tableName || log.target_table || '';
        const targetId = log.recordId || log.target_id || '';
        const ip = log.ipAddress || log.ip_address || '';
        const details = typeof log.details === 'object' ? JSON.stringify(log.details).replace(/"/g, '""') : log.details;
        
        csvContent += `"${log.createdAt}","${user}","${log.action}","${target} #${targetId}","${ip}","${details}"\n`;
    });

    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", `audit_logs_${new Date().toISOString().slice(0,10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    
    showToast(`${dataToExport.length} audit logs exported`, 'success');
}

// Initialize Audit Panel
document.addEventListener('DOMContentLoaded', function() {
    // Add event listeners for audit filters
    const auditSearch = document.getElementById('auditSearch');
    const auditActionFilter = document.getElementById('auditActionFilter');
    
    if (auditSearch) {
        auditSearch.addEventListener('input', filterAuditLogs);
    }
    if (auditActionFilter) {
        auditActionFilter.addEventListener('change', filterAuditLogs);
    }

    // Auto-load audit logs when panel becomes active
    const auditObserver = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.target.id === 'panel-audit' && mutation.target.classList.contains('active')) {
                loadAuditLogs();
            }
        });
    });
    
    const auditPanel = document.getElementById('panel-audit');
    if (auditPanel) {
        auditObserver.observe(auditPanel, { attributes: true, attributeFilter: ['class'] });
    }
});

// Auto-refresh every 5 minutes (optional)
setInterval(() => {
    if (document.getElementById('panel-audit')?.classList.contains('active')) {
        loadAuditLogs();
    }
}, 300000); // 5 minutes

// === REPORTS & ANALYTICS ===
let reportData = [];
let currentDataset = 'overview';
let currentChartType = 'bar';
let mainChart = null;
let pieChart = null;
let statusChart = null;

// Status/priority badges for reports
function getReportBadge(status, type = 'status') {
    const statusBadges = {
        'Active': '#10b981', 'Approved': '#10b981', 'Healthy': '#10b981', 'Resolved': '#10b981',
        'Pending': '#f59e0b', 'In Progress': '#f59e0b', 'Partial': '#f59e0b',
        'Inactive': '#6b7280', 'Rejected': '#ef4444', 'Sick': '#ef4444', 'Deceased': '#ef4444', 'Closed': '#6b7280',
        'Critical': '#ef4444', 'High': '#f59e0b', 'Medium': '#10b981', 'Low': '#6b7280'
    };
    
    const color = statusBadges[status] || '#6b7280';
    return `<span style="background: ${color}20; color: ${color}; padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600;">${status}</span>`;
}

// Load reports data
async function loadReports(dataset = 'overview', dateRange = 'all') {
    try {
        showToast('Loading reports...', 'info');
        
        const response = await fetch(`api/db.php?action=reports&dataset=${dataset}&range=${dateRange}`);
        const result = await response.json();
        
        reportData = result.data || [];
        renderReportStats(result.summary);
        renderReportsTable(reportData);
        updateCharts(reportData);
        
        document.getElementById('tableCount').textContent = reportData.length;
        showToast('Reports loaded successfully', 'success');
    } catch (error) {
        console.error('Error loading reports:', error);
        showToast('Error loading reports', 'error');
    }
}

// Update stats cards
function renderReportStats(summary) {
    document.getElementById('totalFarms').textContent = summary.farms || 0;
    document.getElementById('totalLivestock').textContent = summary.livestock || 0;
    document.getElementById('totalIncidents').textContent = summary.incidents || 0;
    document.getElementById('auditEvents').textContent = summary.audit || 0;
}

// Render detailed table
function renderReportsTable(data) {
    const tbody = document.getElementById('reportsTableBody');
    
    if (!data || data.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="5" style="text-align: center; padding: 40px; color: #9ca3af;">
                    <i class="bi bi-inbox" style="font-size: 3rem; display: block; margin-bottom: 10px;"></i>
                    <p>No data available for this dataset</p>
                </td>
            </tr>
        `;
        return;
    }

    tbody.innerHTML = data.slice(0, 50).map(item => {
        const type = item.type || item.action || 'N/A';
        const status = item.status || item.healthStatus || item.priority || 'N/A';
        const date = new Date(item.createdAt || item.date).toLocaleDateString();
        const name = item.name || item.title || item.tagId || item.tableName || `Record #${item.id}`;
        
        return `
            <tr style="border-bottom: 1px solid #f3f4f6;">
                <td style="padding: 15px 12px; font-weight: 500;">#${item.id}</td>
                <td style="padding: 15px 12px; font-weight: 600; max-width: 250px;">${name}</td>
                <td style="padding: 15px 12px;">${type}</td>
                <td style="padding: 15px 12px;">${getReportBadge(status)}</td>
                <td style="padding: 15px 12px; font-size: 0.85rem; color: #6b7280;">${date}</td>
            </tr>
        `;
    }).join('');
}

// Update all charts
function updateCharts(data) {
    const dataset = document.getElementById('reportDataset').value;
    
    // Destroy existing charts
    if (mainChart) mainChart.destroy();
    if (pieChart) pieChart.destroy();
    if (statusChart) statusChart.destroy();
    
    // Main chart data based on dataset
    const chartData = getChartData(data, dataset);
    
    // Main Chart
    const ctx = document.getElementById('mainReportChart').getContext('2d');
    mainChart = new Chart(ctx, {
        type: currentChartType,
        data: chartData.main,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top' },
                title: { display: false }
            },
            scales: currentChartType === 'bar' ? {
                y: { beginAtZero: true }
            } : {}
        }
    });
    
    // Pie Chart - Distribution
    const pieCtx = document.getElementById('pieChart').getContext('2d');
    pieChart = new Chart(pieCtx, {
        type: 'doughnut',
        data: chartData.pie,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '60%',
            plugins: { legend: { position: 'bottom' } }
        }
    });
    
    // Status Chart
    const statusCtx = document.getElementById('statusChart').getContext('2d');
    statusChart = new Chart(statusCtx, {
        type: 'polarArea',
        data: chartData.status,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'right' } }
        }
    });
}

// Generate chart data based on dataset
function getChartData(data, dataset) {
    const colors = ['#10b981', '#3b82f6', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4'];
    
    switch(dataset) {
        case 'farms':
            return {
                main: {
                    labels: ['Cattle', 'Swine', 'Poultry', 'Goat', 'Mixed'],
                    datasets: [{
                        label: 'Farms by Type',
                        data: [data.filter(d => d.type === 'Cattle').length, 
                               data.filter(d => d.type === 'Swine').length,
                               data.filter(d => d.type === 'Poultry').length,
                               data.filter(d => d.type === 'Goat').length,
                               data.filter(d => d.type === 'Mixed').length],
                        backgroundColor: colors
                    }]
                },
                pie: {
                    labels: ['Approved', 'Pending', 'Rejected'],
                    datasets: [{
                        data: [data.filter(d => d.status === 'Approved').length,
                               data.filter(d => d.status === 'Pending').length,
                               data.filter(d => d.status === 'Rejected').length],
                        backgroundColor: ['#10b981', '#f59e0b', '#ef4444']
                    }]
                },
                status: {
                    labels: ['Approved', 'Pending', 'Rejected'],
                    datasets: [{
                        data: [data.filter(d => d.status === 'Approved').length,
                               data.filter(d => d.status === 'Pending').length,
                               data.filter(d => d.status === 'Rejected').length],
                        backgroundColor: ['#10b981', '#f59e0b', '#ef4444']
                    }]
                }
            };
            
        case 'livestock':
            return {
                main: {
                    labels: ['Healthy', 'Sick', 'Recovered', 'Deceased'],
                    datasets: [{
                        label: 'Livestock Health',
                        data: [data.filter(d => d.healthStatus === 'Healthy').length,
                               data.filter(d => d.healthStatus === 'Sick').length,
                               data.filter(d => d.healthStatus === 'Recovered').length,
                               data.filter(d => d.healthStatus === 'Deceased').length],
                        backgroundColor: colors.slice(0, 4)
                    }]
                },
                pie: {
                    labels: ['Cattle', 'Swine', 'Poultry', 'Goat', 'Sheep'],
                    datasets: [{
                        data: [data.filter(d => d.type === 'Cattle').length,
                               data.filter(d => d.type === 'Swine').length,
                               data.filter(d => d.type === 'Poultry').length,
                               data.filter(d => d.type === 'Goat').length,
                               data.filter(d => d.type === 'Sheep').length],
                        backgroundColor: colors
                    }]
                },
                status: {
                    labels: ['Complete', 'Partial', 'None'],
                    datasets: [{
                        data: [data.filter(d => d.vaccinationStatus === 'Complete').length,
                               data.filter(d => d.vaccinationStatus === 'Partial').length,
                               data.filter(d => d.vaccinationStatus === 'None').length],
                        backgroundColor: ['#10b981', '#f59e0b', '#ef4444']
                    }]
                }
            };
            
        case 'incidents':
            return {
                main: {
                    labels: ['Sick', 'Dead', 'Stray', 'Disease', 'Others'],
                    datasets: [{
                        label: 'Incidents by Type',
                        data: [data.filter(d => d.type === 'Sick').length,
                               data.filter(d => d.type === 'Dead').length,
                               data.filter(d => d.type === 'Stray').length,
                               data.filter(d => d.type === 'Disease').length,
                               data.filter(d => d.type === 'Others').length],
                        backgroundColor: colors
                    }]
                },
                pie: {
                    labels: ['Pending', 'In Progress', 'Resolved', 'Closed'],
                    datasets: [{
                        data: [data.filter(d => d.status === 'Pending').length,
                               data.filter(d => d.status === 'In Progress').length,
                               data.filter(d => d.status === 'Resolved').length,
                               data.filter(d => d.status === 'Closed').length],
                        backgroundColor:                        ['#f59e0b', '#3b82f6', '#10b981', '#6b7280']
                    }]
                },
                status: {
                    labels: ['Critical', 'High', 'Medium', 'Low'],
                    datasets: [{
                        data: [data.filter(d => d.priority === 'Critical').length,
                               data.filter(d => d.priority === 'High').length,
                               data.filter(d => d.priority === 'Medium').length,
                               data.filter(d => d.priority === 'Low').length],
                        backgroundColor: ['#ef4444', '#f59e0b', '#10b981', '#6b7280']
                    }]
                }
            };
            
        case 'audit':
            return {
                main: {
                    labels: ['CREATE', 'UPDATE', 'DELETE', 'LOGIN', 'Others'],
                    datasets: [{
                        label: 'Audit Actions',
                        data: [data.filter(d => d.action === 'CREATE').length,
                               data.filter(d => d.action === 'UPDATE').length,
                               data.filter(d => d.action === 'DELETE').length,
                               data.filter(d => d.action === 'LOGIN').length,
                               data.filter(d => !['CREATE','UPDATE','DELETE','LOGIN'].includes(d.action)).length],
                        backgroundColor: colors
                    }]
                },
                pie: {
                    labels: ['Users', 'Farms', 'Livestock', 'Incidents'],
                    datasets: [{
                        data: [data.filter(d => d.tableName === 'users').length,
                               data.filter(d => d.tableName === 'farms').length,
                               data.filter(d => d.tableName === 'livestock').length,
                               data.filter(d => d.tableName === 'incidents').length],
                        backgroundColor: colors.slice(0, 4)
                    }]
                },
                status: {
                    labels: ['Admin', 'Official', 'Farmer'],
                    datasets: [{
                        data: [data.filter(d => d.role === 'Admin').length || 0,
                               data.filter(d => d.role === 'Agriculture Official').length || 0,
                               data.filter(d => d.role === 'Farmer').length || 0],
                        backgroundColor: ['#8b5cf6', '#3b82f6', '#10b981']
                    }]
                }
            };
            
        default:
            return {
                main: {
                    labels: ['Farms', 'Livestock', 'Incidents', 'Audit'],
                    datasets: [{
                        label: 'Overview',
                        data: [reportData.filter(d => d.table === 'farms').length,
                               reportData.filter(d => d.table === 'livestock').length,
                               reportData.filter(d => d.table === 'incidents').length,
                               reportData.filter(d => d.table === 'audit_log').length],
                        backgroundColor: colors.slice(0, 4)
                    }]
                },
                pie: {
                    labels: ['Admin', 'Official', 'Farmer'],
                    datasets: [{
                        data: [reportData.filter(d => d.role === 'Admin').length,
                               reportData.filter(d => d.role === 'Agriculture Official').length,
                               reportData.filter(d => d.role === 'Farmer').length],
                        backgroundColor: ['#8b5cf6', '#3b82f6', '#10b981']
                    }]
                },
                status: {
                    labels: ['Active/Healthy/Approved', 'Pending/In Progress', 'Inactive/Sick/Rejected'],
                    datasets: [{
                        data: [reportData.filter(d => d.status === 'Active' || d.healthStatus === 'Healthy' || d.status === 'Approved').length,
                               reportData.filter(d => d.status === 'Pending' || d.status === 'In Progress').length,
                               reportData.filter(d => d.status === 'Inactive' || d.healthStatus === 'Sick' || d.status === 'Rejected').length],
                        backgroundColor: ['#10b981', '#f59e0b', '#ef4444']
                    }]
                }
            };
    }
}

// Report controls
function refreshReports() {
    const dataset = document.getElementById('reportDataset').value;
    const range = document.getElementById('reportDateRange').value;
    loadReports(dataset, range);
}

function toggleChartType() {
    currentChartType = currentChartType === 'bar' ? 'line' : 'bar';
    document.getElementById('chartTypeBtn').innerHTML = 
        currentChartType === 'bar' ? 
        'Bar Chart <i class="bi bi-bar-chart"></i>' : 
        'Line Chart <i class="bi bi-graph-up"></i>';
    
    updateCharts(reportData);
}

function toggleExportMenu() {
    const menu = document.getElementById('exportMenu');
    menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
}

// Export functions
async function exportReport(format = 'csv') {
    if (reportData.length === 0) {
        showToast('No data to export', 'error');
        return;
    }
    
    const dataset = document.getElementById('reportDataset').value;
    const range = document.getElementById('reportDateRange').value;
    const filename = `agritrace_${dataset}_report_${new Date().toISOString().slice(0,10)}.${format}`;
    
    try {
        const response = await fetch(`api/db.php?action=export&dataset=${dataset}&format=${format}&range=${range}`);
        const blob = await response.blob();
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        
        showToast(`${reportData.length} records exported as ${format.toUpperCase()}`, 'success');
    } catch (error) {
        showToast('Export failed', 'error');
        console.error('Export error:', error);
    }
    
    document.getElementById('exportMenu').style.display = 'none';
}

// Event listeners for reports
document.addEventListener('DOMContentLoaded', function() {
    // Dataset and date range filters
    document.getElementById('reportDataset').addEventListener('change', function() {
        currentDataset = this.value;
        document.getElementById('mainChartTitle').textContent = 
            this.value === 'overview' ? 'System Overview' :
            this.value === 'farms' ? 'Farm Analytics' :
            this.value === 'livestock' ? 'Livestock Health' :
            this.value === 'incidents' ? 'Incident Reports' : 'Audit Activity';
        refreshReports();
    });
    
    document.getElementById('reportDateRange').addEventListener('change', refreshReports);
    
    // Hide export menu on outside click
    document.addEventListener('click', function(e) {
        const exportBtn = document.getElementById('exportBtn');
        const menu = document.getElementById('exportMenu');
        if (!exportBtn.contains(e.target) && !menu.contains(e.target)) {
            menu.style.display = 'none';
        }
    });
    
    // Auto-load reports when panel opens
    const reportsObserver = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.target.id === 'panel-reports' && mutation.target.classList.contains('active')) {
                loadReports();
            }
        });
    });
    
    const reportsPanel = document.getElementById('panel-reports');
    if (reportsPanel) {
        reportsObserver.observe(reportsPanel, { attributes: true, attributeFilter: ['class'] });
    }
});

// Add export dropdown styles
const exportStyle = document.createElement('style');
exportStyle.textContent = `
    .export-dropdown { position: relative; }
    #exportMenu button:hover { background: #f3f4f6 !important; }
    #exportMenu { margin-top: 5px; }
`;
document.head.appendChild(exportStyle);

            // Add CSS for action buttons
            const style = document.createElement('style');
            style.textContent = `
                .btn-edit, .btn-delete {
                    width: 32px; height: 32px; border: none; border-radius: 6px; 
                    display: flex; align-items: center; justify-content: center; cursor: pointer;
                    font-size: 0.9rem; transition: all 0.2s;
                }
                .btn-edit { background: #dbeafe; color: #2563eb; }
                .btn-edit:hover { background: #3b82f6; color: white; transform: scale(1.05); }
                .btn-delete { background: #fef2f2; color: #dc2626; }
                .btn-delete:hover { background: #ef4444; color: white; transform: scale(1.05); }
                .table-responsive { overflow-x: auto; }
            `;
            document.head.appendChild(style);

        // --- CHARTS ---
        document.addEventListener('DOMContentLoaded', function() {
            const actCtx = document.getElementById('chartActivity').getContext('2d');
            new Chart(actCtx, {
                type: 'line',
                data: {
                    labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                    datasets: [{ 
                        label: 'Activity', 
                        data: [0, 0, 0, 0, 0, 0, 0], 
                        borderColor: '#10b981', 
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        fill: true,
                        tension: 0.4 
                    }]
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } }
                }
            });

            const roleCtx = document.getElementById('chartRoles').getContext('2d');
            new Chart(roleCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Farmers', 'Officials', 'Admins'],
                    datasets: [{ 
                        data: [1, 1, 1], 
                        backgroundColor: ['#059669', '#3b82f6', '#f59e0b'],
                        borderWidth: 0
                    }]
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false,
                    cutout: '70%',
                    plugins: { legend: { position: 'bottom' } }
                }
            });
        });
        // === DASHBOARD STATS FUNCTIONALITY ===
async function loadDashboardStats() {
    const statsContainer = document.getElementById('dashboardStats');
    const loadingEl = document.getElementById('statsLoading');
    
    try {
        // Show loading
        statsContainer.style.opacity = '0.5';
        loadingEl.style.display = 'block';
        
        const response = await fetch('api/db.php?action=getDashboardStats');
        const result = await response.json();
        
        if (result.success && result.stats) {
            // Update each stat
            Object.keys(result.stats).forEach(statKey => {
                const el = document.getElementById(`stat${statKey.charAt(0).toUpperCase() + statKey.slice(1)}`);
                if (el) {
                    el.textContent = result.stats[statKey].toLocaleString();
                    
                    // Animate counter effect
                    animateCounter(el, 0, result.stats[statKey]);
                }
            });
            
            showToast('Dashboard stats updated!', 'success');
        }
    } catch (error) {
        console.error('Error loading dashboard stats:', error);
        showToast('Error loading stats. Check database connection.', 'error');
    } finally {
        // Hide loading
        statsContainer.style.opacity = '1';
        loadingEl.style.display = 'none';
    }
}

// Animate counter effect
function animateCounter(element, start, end, duration = 1500) {
    let startTime = null;
    const step = (timestamp) => {
        if (!startTime) startTime = timestamp;
        const progress = Math.min((timestamp - startTime) / duration, 1);
        const current = Math.floor(progress * (end - start) + start);
        element.textContent = current.toLocaleString();
        
        if (progress < 1) {
            requestAnimationFrame(step);
        }
    };
    requestAnimationFrame(step);
}

// Load stats on dashboard activation and every 30 seconds
function setupDashboardAutoRefresh() {
    loadDashboardStats(); // Initial load
    
    // Auto-refresh every 30 seconds when dashboard is active
    setInterval(() => {
        if (document.getElementById('panel-dashboard')?.classList.contains('active')) {
            loadDashboardStats();
        }
    }, 30000);
    
    // Also load role distribution chart data
    loadRoleDistribution();
}

// Load role distribution for pie chart
async function loadRoleDistribution() {
    try {
        const response = await fetch('api/db.php?action=getAll&table=users');
        const users = await response.json();
        
        const roleCounts = {};
        users.forEach(user => {
            roleCounts[user.role] = (roleCounts[user.role] || 0) + 1;
        });
        
        updateRoleChart(roleCounts);
    } catch (error) {
        console.error('Error loading role distribution:', error);
    }
}

// Update role distribution chart
function updateRoleChart(roleCounts) {
    const ctx = document.getElementById('chartRoles')?.getContext('2d');
    if (!ctx) return;
    
    if (window.roleChart) {
        window.roleChart.destroy();
    }
    
    const labels = Object.keys(roleCounts);
    const data = Object.values(roleCounts);
    const colors = ['#059669', '#3b82f6', '#f59e0b'];
    
    window.roleChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{ 
                data: data, 
                backgroundColor: colors.slice(0, labels.length),
                borderWidth: 0
            }]
        },
        options: { 
            responsive: true, 
            maintainAspectRatio: false,
            cutout: '70%',
            plugins: { 
                legend: { 
                    position: 'bottom',
                    labels: {
                        font: { size: 12 },
                        padding: 20
                    }
                }
            }
        }
    });
}

// Add CSS for loading animation
const statsStyle = document.createElement('style');
statsStyle.textContent = `
    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
    .stat-card { transition: all 0.3s ease; }
    .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
`;
document.head.appendChild(statsStyle);

// Initialize dashboard when DOM loads
document.addEventListener('DOMContentLoaded', function() {
    // Setup dashboard auto-refresh observer
    const dashboardObserver = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.target.id === 'panel-dashboard' && mutation.target.classList.contains('active')) {
                loadDashboardStats();
                setupDashboardAutoRefresh();
            }
        });
    });
    
    const dashboardPanel = document.getElementById('panel-dashboard');
    if (dashboardPanel) {
        dashboardObserver.observe(dashboardPanel, { attributes: true, attributeFilter: ['class'] });
    }
});
// === DASHBOARD LIVE STATS ===
async function loadDashboardStats() {
    try {
        const response = await fetch('api/db.php?action=getDashboardStats');
        const result = await response.json();
        
        if (result.success && result.stats) {
            // Animate counters
            Object.keys(result.stats).forEach(statKey => {
                const el = document.getElementById(`stat${statKey.charAt(0).toUpperCase() + statKey.slice(1)}`);
                if (el) {
                    animateCounter(el, parseInt(el.textContent.replace(/,/g, '') || 0), result.stats[statKey]);
                }
            });
            
            // Update timestamp
            document.getElementById('statsLastUpdate').textContent = new Date().toLocaleTimeString();
            
            showToast('📊 Dashboard updated!', 'success');
        }
    } catch (error) {
        console.error('Stats error:', error);
        showToast('❌ Database connection failed', 'error');
    }
}

function animateCounter(el, start, end) {
    const duration = 1000;
    let startTime = null;
    function step(timestamp) {
        if (!startTime) startTime = timestamp;
        const progress = Math.min((timestamp - startTime) / duration, 1);
        const current = Math.floor(progress * (end - start) + start);
        el.textContent = current.toLocaleString();
        if (progress < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
}

// Auto-refresh dashboard
let refreshInterval;
function startDashboardRefresh() {
    loadDashboardStats(); // Initial load
    refreshInterval = setInterval(loadDashboardStats, 30000); // Every 30s
}

function stopDashboardRefresh() {
    if (refreshInterval) clearInterval(refreshInterval);
}

// Watch dashboard panel
document.addEventListener('DOMContentLoaded', function() {
    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            if (mutation.target.id === 'panel-dashboard') {
                if (mutation.target.classList.contains('active')) {
                    startDashboardRefresh();
                } else {
                    stopDashboardRefresh();
                }
            }
        });
    });
    
    observer.observe(document.getElementById('panel-dashboard'), {
        attributes: true,
        attributeFilter: ['class']
    });
    
    // Load initial stats
    loadDashboardStats();
});
    </script>
</body>
</html>