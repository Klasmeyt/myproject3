<?php
session_start();

// ── Auth Check (FIXED - uses same session keys as login.php) ──────────────────
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['PHP_SELF']));
    exit;
}

$user_role = $_SESSION['user_role'] ?? '';
$user_id   = $_SESSION['user_id']   ?? 0;

  if (!in_array($user_role, ['Agriculture Official', 'Admin'])) {
      header('Location: index.php?error=access_denied');
    exit;
}

// ── DB Connection ─────────────────────────────────────────────────────────────
try {
    $pdo = new PDO('mysql:host=localhost;dbname=myproject4;charset=utf8mb4','root','',[
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch(PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}

// ── AJAX Handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    // APPROVE FARM
    if (($_POST['action']??'') === 'approve_farm') {
        $farmId = (int)$_POST['farm_id'];
        $s = $pdo->prepare("SELECT id,name,status FROM farms WHERE id=?"); $s->execute([$farmId]); $farm = $s->fetch();
        if (!$farm) { echo json_encode(['success'=>false,'message'=>'Farm not found']); exit; }
        if ($farm['status'] !== 'Pending') { echo json_encode(['success'=>false,'message'=>"Farm already {$farm['status']}"]); exit; }
        $pdo->prepare("UPDATE farms SET status='Approved',updatedAt=NOW() WHERE id=?")->execute([$farmId]);
        $pdo->prepare("INSERT INTO audit_log(userId,action,tableName,recordId,details,ipAddress) VALUES(?,?,?,?,?,?)")
            ->execute([$user_id,'APPROVE_FARM','farms',$farmId,json_encode(['farm_name'=>$farm['name'],'officer_id'=>$user_id]),$_SERVER['REMOTE_ADDR']??'']);
        echo json_encode(['success'=>true,'message'=>"Farm '{$farm['name']}' approved!",'new_status'=>'Approved','farm_id'=>$farmId]);
        exit;
    }

    // REJECT FARM
    if (($_POST['action']??'') === 'reject_farm') {
        $farmId = (int)$_POST['farm_id'];
        $reason = trim($_POST['reason'] ?? 'No reason provided');
        $s = $pdo->prepare("SELECT id,name,status FROM farms WHERE id=?"); $s->execute([$farmId]); $farm = $s->fetch();
        if (!$farm || $farm['status'] !== 'Pending') { echo json_encode(['success'=>false,'message'=>'Farm not found or not pending']); exit; }
        $pdo->prepare("UPDATE farms SET status='Rejected',rejection_reason=?,updatedAt=NOW() WHERE id=?")->execute([$reason,$farmId]);
        $pdo->prepare("INSERT INTO audit_log(userId,action,tableName,recordId,details,ipAddress) VALUES(?,?,?,?,?,?)")
            ->execute([$user_id,'REJECT_FARM','farms',$farmId,json_encode(['reason'=>$reason,'farm_name'=>$farm['name']]),$_SERVER['REMOTE_ADDR']??'']);
        echo json_encode(['success'=>true,'message'=>"Farm rejected.",'new_status'=>'Rejected','farm_id'=>$farmId]);
        exit;
    }

    // RESOLVE INCIDENT
    if (($_POST['action']??'') === 'resolve_incident') {
        $id = (int)$_POST['incident_id'];
        $pdo->prepare("UPDATE incidents SET status='Resolved',resolvedAt=NOW(),updatedAt=NOW() WHERE id=?")->execute([$id]);
        echo json_encode(['success'=>true,'message'=>'Incident resolved.']);
        exit;
    }

    // PROFILE UPDATE WITH IMAGE SUPPORT
    if (isset($_POST['update_profile'])) {
        try {
            $firstName = trim($_POST['firstName'] ?? '');
            $lastName  = trim($_POST['lastName']  ?? '');
            $email     = trim($_POST['email']     ?? '');
            $mobile    = trim($_POST['mobile']    ?? '');

            $profileImage = $_POST['existing_image'] ?? '';
            if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = 'uploads/profiles/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
                if (in_array($_FILES['profile_image']['type'], $allowed) && $_FILES['profile_image']['size'] <= 2*1024*1024) {
                    $fname2  = $user_id . '_' . time() . '.' . pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
                    $fpath   = $uploadDir . $fname2;
                    if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $fpath)) {
                        $profileImage = $fpath;
                    }
                }
            }

            $pdo->prepare("UPDATE users SET firstName=?,lastName=?,email=?,mobile=? WHERE id=?")
                ->execute([$firstName,$lastName,$email,$mobile,$user_id]);

            $pdo->prepare("INSERT INTO officer_profiles (user_id,gov_id,department,position,office,assigned_region,province,municipality,profile_image)
                VALUES(?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE
                gov_id=VALUES(gov_id),department=VALUES(department),position=VALUES(position),
                office=VALUES(office),assigned_region=VALUES(assigned_region),province=VALUES(province),
                municipality=VALUES(municipality),profile_image=VALUES(profile_image)")
                ->execute([$user_id,$_POST['gov_id']??'',$_POST['department']??'',$_POST['position']??'',
                           $_POST['office']??'',$_POST['assigned_region']??'',$_POST['province']??'',
                           $_POST['municipality']??'',$profileImage]);

            // Update session name
            $_SESSION['firstName'] = $firstName;
            $_SESSION['lastName']  = $lastName;

            echo json_encode(['success'=>true,'message'=>'Profile updated successfully']);
        } catch(Exception $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action']);
    exit;
}

// ── Fetch Dashboard Data ──────────────────────────────────────────────────────
$stats = [
    'approved_farms'   => (int)$pdo->query("SELECT COUNT(*) FROM farms WHERE status='Approved'")->fetchColumn(),
    'pending_farms'    => (int)$pdo->query("SELECT COUNT(*) FROM farms WHERE status='Pending'")->fetchColumn(),
    'active_incidents' => (int)$pdo->query("SELECT COUNT(*) FROM incidents WHERE status IN('Pending','In Progress')")->fetchColumn(),
    'pending_reports'  => (int)$pdo->query("SELECT COUNT(*) FROM public_reports WHERE status='Pending'")->fetchColumn(),
    'total_livestock'  => (int)$pdo->query("SELECT COALESCE(SUM(qty),0) FROM livestock")->fetchColumn()
];

$recent_incidents = $pdo->query("SELECT i.*,f.name as farm_name,u.firstName,u.lastName FROM incidents i LEFT JOIN farms f ON i.farmId=f.id LEFT JOIN users u ON i.reporterId=u.id ORDER BY i.createdAt DESC LIMIT 20")->fetchAll();
$all_farms = $pdo->query("SELECT f.*,u.firstName,u.lastName,(SELECT COALESCE(SUM(qty),0) FROM livestock WHERE farmId=f.id) as total_livestock FROM farms f LEFT JOIN users u ON f.ownerId=u.id ORDER BY f.createdAt DESC")->fetchAll();
$pub_reports = $pdo->query("SELECT * FROM public_reports ORDER BY createdAt DESC LIMIT 50")->fetchAll();

// Officer profile
$stmt = $pdo->prepare("SELECT u.*,op.* FROM users u LEFT JOIN officer_profiles op ON u.id=op.user_id WHERE u.id=?");
$stmt->execute([$user_id]);
$profileData = $stmt->fetch() ?: [];

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>AgriTrace+ | Official Panel</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Syne:wght@700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root{
  --brand-dark:#1A4731;--brand-mid:#2D6A4F;--brand-acc:#10B981;
  --bg:#F8FAF9;--card:#ffffff;--slate-50:#F1F5F9;--slate-100:#E2E8F0;
  --slate-200:#CBD5E1;--text:#1E293B;--sub:#64748B;
  --danger:#EF4444;--warn:#F59E0B;--success:#10B981;
  --sw:280px;--th:70px;
}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);overflow-x:hidden;}
.sidebar{width:var(--sw);height:100vh;position:fixed;top:0;left:calc(-1 * var(--sw));background:#fff;display:flex;flex-direction:column;transition:transform .3s cubic-bezier(.4,0,.2,1);z-index:1000;border-right:1px solid var(--slate-100);}
.sidebar.open{transform:translateX(var(--sw));box-shadow:10px 0 30px rgba(0,0,0,.08);}
.sidebar-hdr{padding:24px;flex-shrink:0;border-bottom:1px solid var(--slate-50);}
.logo{font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:var(--brand-dark);letter-spacing:-1px;}
.logo span{color:var(--brand-acc);}
.sidebar-sub{font-size:.7rem;color:var(--sub);text-transform:uppercase;letter-spacing:1.5px;font-weight:700;margin-top:4px;}
.sidebar-nav{flex:1;overflow-y:auto;padding:12px;scrollbar-width:thin;}
.nav-item{display:flex;align-items:center;gap:14px;padding:12px 16px;margin-bottom:4px;border-radius:12px;color:var(--text);cursor:pointer;transition:.2s;font-size:.95rem;font-weight:500;}
.nav-item i{font-size:1.2rem;color:var(--sub);}
.nav-item:hover{background:#F0FDF4;color:var(--brand-dark);}
.nav-item.active{background:linear-gradient(135deg,var(--brand-mid),var(--brand-dark));color:#fff;box-shadow:0 4px 12px rgba(26,71,49,.2);}
.nav-item.active i{color:var(--brand-acc);}
.nav-divider{height:1px;background:var(--slate-50);margin:16px;}
.nav-item.logout{color:var(--danger);}
.sidebar-footer{padding:18px 20px;background:var(--slate-50);border-top:1px solid var(--slate-100);flex-shrink:0;}
.user-box{display:flex;align-items:center;gap:12px;}
.user-av{width:42px;height:42px;background:linear-gradient(135deg,var(--brand-mid),var(--brand-dark));color:#fff;border-radius:10px;display:flex;align-items:center;justify-content:center;font-weight:700;box-shadow:0 3px 6px rgba(0,0,0,.1);}
.user-name{font-weight:700;font-size:.9rem;color:var(--brand-dark);}
.user-role{font-size:.75rem;color:var(--sub);font-weight:500;}
.topbar{position:fixed;top:0;right:0;left:0;height:var(--th);background:rgba(255,255,255,.8);backdrop-filter:blur(12px);display:flex;align-items:center;justify-content:space-between;padding:0 20px;z-index:900;border-bottom:1px solid var(--slate-100);}
.main-content{padding:24px;margin-top:var(--th);transition:.3s;}
.menu-btn{background:none;border:none;font-size:1.6rem;color:var(--brand-dark);cursor:pointer;}
.overlay{position:fixed;inset:0;background:rgba(15,23,42,.4);backdrop-filter:blur(2px);z-index:999;display:none;}
.overlay.open{display:block;}
.section{display:none;animation:slideUp .4s ease;}
.section.active{display:block;}
@keyframes slideUp{from{opacity:0;transform:translateY(10px);}to{opacity:1;transform:translateY(0);}}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:20px;margin-bottom:32px;}
.card{background:#fff;padding:24px;border-radius:18px;border:1px solid var(--slate-100);box-shadow:0 4px 12px rgba(0,0,0,.04);}
.stat-val{font-size:2.2rem;font-weight:800;color:var(--brand-dark);display:block;line-height:1;}
.stat-label{font-size:.8rem;color:var(--sub);text-transform:uppercase;font-weight:700;letter-spacing:.5px;margin-top:4px;}
.table-container{background:#fff;border-radius:18px;border:1px solid var(--slate-100);box-shadow:0 4px 12px rgba(0,0,0,.04);overflow:hidden;margin-bottom:24px;}
.table-hdr{background:linear-gradient(135deg,var(--brand-mid),var(--brand-dark));color:#fff;padding:20px 24px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;}
.table-hdr h2{margin:0;font-size:1.3rem;font-weight:700;}
table{width:100%;border-collapse:collapse;}
th{background:var(--slate-50);padding:16px 20px;text-align:left;font-weight:700;color:var(--text);font-size:.9rem;border-bottom:2px solid var(--slate-100);}
td{padding:14px 20px;border-bottom:1px solid var(--slate-200);vertical-align:middle;}
tr:hover{background:#F8FAFC;}
.badge{padding:4px 12px;border-radius:20px;font-size:.75rem;font-weight:700;text-transform:uppercase;}
.badge-approved{background:#D1FAE5;color:var(--success);}
.badge-pending{background:#FEF3C7;color:var(--warn);}
.badge-rejected{background:#FEE2E2;color:var(--danger);}
.badge-resolved{background:#D1FAE5;color:var(--success);}
.badge-progress{background:#DBEAFE;color:#1D4ED8;}
.priority-critical{background:#FEE2E2;color:#DC2626;}
.priority-high{background:#FEF3C7;color:#D97706;}
.priority-medium{background:#DBEAFE;color:#1D4ED8;}
.priority-low{background:#F1F5F9;color:var(--sub);}
.btn{padding:8px 16px;border:none;border-radius:8px;font-weight:600;cursor:pointer;font-size:.85rem;transition:.2s;display:inline-flex;align-items:center;gap:.375rem;text-decoration:none;font-family:inherit;}
.btn-primary{background:var(--brand-acc);color:#fff;}.btn-primary:hover{background:#059669;transform:translateY(-1px);}
.btn-danger{background:var(--danger);color:#fff;}.btn-danger:hover{background:#DC2626;}
.btn-secondary{background:var(--slate-200);color:var(--text);}
.search-box{width:100%;padding:12px 18px;border:2px solid var(--slate-200);border-radius:12px;font-size:.95rem;font-family:inherit;}
.search-box:focus{outline:none;border-color:var(--brand-acc);box-shadow:0 0 0 3px rgba(16,185,129,.1);}
#map{height:500px;border-radius:18px;margin-bottom:24px;}
.modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.55);backdrop-filter:blur(8px);z-index:5000;display:none;align-items:center;justify-content:center;padding:1rem;}
.modal-bg.open{display:flex;}
.modal-box{background:#fff;border-radius:20px;width:100%;max-width:520px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);animation:modalIn .3s ease;}
@keyframes modalIn{from{transform:translateY(20px) scale(.95);opacity:0;}to{transform:translateY(0) scale(1);opacity:1;}}
.modal-hdr{padding:1.5rem;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--slate-100);position:sticky;top:0;background:#fff;z-index:1;}
.modal-hdr h3{margin:0;font-size:1.2rem;color:var(--brand-dark);}
.modal-close{background:none;border:none;font-size:1.5rem;color:var(--sub);cursor:pointer;padding:.25rem;border-radius:.5rem;}
.modal-close:hover{background:var(--slate-100);}
.modal-body{padding:1.5rem;}
.modal-footer{padding:1rem 1.5rem;border-top:1px solid var(--slate-100);display:flex;gap:.75rem;justify-content:flex-end;background:var(--slate-50);border-radius:0 0 20px 20px;}
.form-group{margin-bottom:1.25rem;}
.form-label{display:block;font-weight:700;color:var(--text);margin-bottom:.5rem;font-size:.9rem;}
.form-input{width:100%;padding:12px 14px;border:2px solid var(--slate-200);border-radius:12px;font-size:.95rem;font-family:inherit;transition:.2s;}
.form-input:focus{outline:none;border-color:var(--brand-acc);box-shadow:0 0 0 3px rgba(16,185,129,.1);}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem;}
.chart-grid{display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:24px;}
.chart-wrap{position:relative;height:300px;}
.info-row{display:flex;justify-content:space-between;padding:.875rem;background:var(--slate-50);border-radius:.5rem;margin-bottom:.5rem;}
.toast{position:fixed;top:20px;right:20px;z-index:9999;padding:1rem 1.5rem;border-radius:12px;color:#fff;font-weight:600;box-shadow:0 10px 30px rgba(0,0,0,.2);transform:translateX(400px);transition:transform .3s ease;}
.toast.show{transform:translateX(0);}
.toast-success{background:var(--success);}
.toast-error{background:var(--danger);}
#loadingOverlay{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(255,255,255,.9);display:none;align-items:center;justify-content:center;z-index:8000;}
.spinner{width:50px;height:50px;border:4px solid var(--slate-200);border-top:4px solid var(--brand-acc);border-radius:50%;animation:spin 1s linear infinite;}
@keyframes spin{to{transform:rotate(360deg)}}
@media(min-width:1024px){.sidebar{left:0;}.main-content{margin-left:var(--sw);}.menu-btn{display:none;}.overlay{display:none!important;}}
@media(max-width:600px){.stats-grid{grid-template-columns:repeat(2,1fr);}.chart-grid{grid-template-columns:1fr;}.form-row{grid-template-columns:1fr;}}
.profile-avatar img { display: block; }
.profile-camera:hover { background: #059669; transform: scale(1.05); }
.profile-camera input[type=file]:hover { cursor: pointer; }
#modalProfilePreview:hover { border-color: var(--brand-acc); }
.profile-avatar img { display: block; }
.profile-camera:hover { background: #059669 !important; transform: scale(1.05); }
.profile-camera input[type=file] { cursor: pointer; }
#modalProfilePreview:hover { border-color: var(--brand-acc); }
</style>
</head>
<body>
<div class="overlay" id="overlay" onclick="toggleSidebar()"></div>
<div id="loadingOverlay"><div class="spinner"></div></div>
<div id="toast" class="toast"></div>

<aside class="sidebar" id="sidebar">
  <div class="sidebar-hdr">
    <div class="logo">Agri<span>Trace+</span></div>
    <div class="sidebar-sub">Official Portal</div>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-item active" onclick="navTo('dashboard',this)"><i class="bi bi-grid-1x2"></i><span>Dashboard</span></div>
    <div class="nav-item" onclick="navTo('farms',this)"><i class="bi bi-house-check"></i><span>Farm Inspection</span></div>
    <div class="nav-item" onclick="navTo('incidents',this)"><i class="bi bi-exclamation-triangle"></i><span>Incidents</span></div>
    <div class="nav-item" onclick="navTo('public',this)"><i class="bi bi-file-earmark-text"></i><span>Public Reports</span></div>
    <div class="nav-item" onclick="navTo('map',this)"><i class="bi bi-geo-alt"></i><span>Geo-Monitoring</span></div>
    <div class="nav-item" onclick="navTo('analytics',this)"><i class="bi bi-bar-chart-line"></i><span>Analytics</span></div>
    <div class="nav-item" onclick="navTo('profile',this)"><i class="bi bi-person-circle"></i><span>Profile</span></div>
    <div class="nav-divider"></div>
    <div class="nav-item logout" onclick="window.location.href='index.php'"><i class="bi bi-power"></i><span>Logout</span></div>
  </nav>
  <div class="sidebar-footer">
    <div class="user-box">
      <div class="user-av"><?= htmlspecialchars($user_initial) ?></div>
      <div><div class="user-name"><?= htmlspecialchars($user_name) ?></div><div class="user-role"><?= htmlspecialchars($user_role) ?></div></div>
    </div>
  </div>
</aside>

<header class="topbar">
  <div style="display:flex;align-items:center;gap:12px;">
    <button class="menu-btn" onclick="toggleSidebar()"><i class="bi bi-list"></i></button>
    <strong id="page-title" style="color:var(--brand-dark);">Dashboard</strong>
  </div>
  <div class="user-av" style="width:34px;height:34px;font-size:.9rem;"><?= htmlspecialchars($user_initial) ?></div>
</header>

<main class="main-content">

  <!-- ═══ DASHBOARD ═══ -->
  <section id="sec-dashboard" class="section active">
    <div class="stats-grid">
      <div class="card" style="border-left:4px solid var(--success);"><span class="stat-val"><?= $stats['approved_farms'] ?></span><span class="stat-label">Approved Farms</span></div>
      <div class="card" style="border-left:4px solid var(--warn);"><span class="stat-val"><?= $stats['active_incidents'] ?></span><span class="stat-label">Active Incidents</span></div>
      <div class="card" style="border-left:4px solid var(--danger);"><span class="stat-val"><?= $stats['pending_reports'] ?></span><span class="stat-label">Pending Reports</span></div>
      <div class="card" style="border-left:4px solid var(--brand-mid);"><span class="stat-val"><?= number_format($stats['total_livestock']) ?></span><span class="stat-label">Total Livestock</span></div>
    </div>
    <div class="table-container">
      <div class="table-hdr"><h2>Recent Incidents</h2></div>
      <table><thead><tr><th>Title</th><th>Farm</th><th>Status</th><th>Priority</th><th>Date</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach($recent_incidents as $inc): ?>
      <tr>
        <td><?= htmlspecialchars($inc['title']) ?></td>
        <td><?= htmlspecialchars($inc['farm_name']??'N/A') ?></td>
        <td><span class="badge badge-<?= strtolower($inc['status']) ?>"><?= $inc['status'] ?></span></td>
        <td><span class="badge priority-<?= strtolower($inc['priority']) ?>"><?= $inc['priority'] ?></span></td>
        <td><?= date('M j',strtotime($inc['createdAt'])) ?></td>
        <td><button class="btn btn-primary" onclick="resolveIncident(<?= $inc['id'] ?>)"><i class="bi bi-check-lg"></i> Resolve</button></td>
      </tr>
      <?php endforeach; ?>
      <?php if(empty($recent_incidents)): ?><tr><td colspan="6" style="text-align:center;padding:40px;color:var(--sub);">No recent incidents</td></tr><?php endif; ?>
      </tbody></table>
    </div>
  </section>

  <!-- ═══ FARM INSPECTION ═══ -->
  <section id="sec-farms" class="section">
    <div class="table-container">
      <div class="table-hdr">
        <h2>Farm Inspection Portal</h2>
        <input type="text" class="search-box" style="max-width:320px;padding:10px 16px;" placeholder="Search farms…" oninput="filterFarms(this.value)">
      </div>
      <table id="farmsTable"><thead><tr><th>Farm Name</th><th>Owner</th><th>Type</th><th>Address</th><th>Status</th><th>Livestock</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($all_farms as $farm): ?>
      <tr data-name="<?= strtolower($farm['name']) ?>" data-owner="<?= strtolower(($farm['firstName']??'').' '.($farm['lastName']??'')) ?>">
        <td><strong><?= htmlspecialchars($farm['name']) ?></strong></td>
        <td><?= htmlspecialchars(($farm['firstName']??'').' '.($farm['lastName']??'')) ?></td>
        <td><?= ucfirst($farm['type']) ?></td>
        <td style="font-size:.85rem;max-width:180px;"><?= htmlspecialchars(substr($farm['address']??'',0,60)) ?><?= strlen($farm['address']??'')>60?'…':'' ?></td>
        <td><span class="badge badge-<?= strtolower($farm['status']) ?>" id="farm-status-<?= $farm['id'] ?>"><?= $farm['status'] ?></span></td>
        <td><?= $farm['total_livestock'] ?> heads</td>
        <td id="farm-actions-<?= $farm['id'] ?>">
          <?php if($farm['status']==='Pending'): ?>
          <button class="btn btn-primary" onclick="approveFarm(<?= $farm['id'] ?>,this)"><i class="bi bi-check-lg"></i> Approve</button>
          <button class="btn btn-danger" style="margin-left:6px;" onclick="rejectFarm(<?= $farm['id'] ?>,this)"><i class="bi bi-x-lg"></i> Reject</button>
          <?php else: ?>
          <span style="color:var(--sub);font-size:.875rem;">—</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody></table>
    </div>
  </section>

  <!-- ═══ INCIDENTS ═══ -->
  <section id="sec-incidents" class="section">
    <div class="table-container">
      <div class="table-hdr"><h2>Incident Management</h2></div>
      <table><thead><tr><th>#</th><th>Title</th><th>Farm</th><th>Type</th><th>Status</th><th>Priority</th><th>Date</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach($all_incidents as $inc): ?>
      <tr>
        <td style="font-size:.8rem;color:var(--sub);">#<?= $inc['id'] ?></td>
        <td><?= htmlspecialchars($inc['title']) ?></td>
        <td><?= htmlspecialchars($inc['farm_name']??'N/A') ?></td>
        <td><?= ucfirst($inc['type']) ?></td>
        <td><span class="badge badge-<?= strtolower(str_replace(' ','-',$inc['status'])) ?>"><?= $inc['status'] ?></span></td>
        <td><span class="badge priority-<?= strtolower($inc['priority']) ?>"><?= $inc['priority'] ?></span></td>
        <td style="font-size:.85rem;"><?= date('M j',strtotime($inc['createdAt'])) ?></td>
        <td><?php if($inc['status']!=='Resolved'&&$inc['status']!=='Closed'): ?>
          <button class="btn btn-primary" onclick="resolveIncident(<?= $inc['id'] ?>)"><i class="bi bi-check-lg"></i> Resolve</button>
        <?php else: ?><span style="color:var(--success);font-size:.875rem;">✓ Done</span><?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody></table>
    </div>
  </section>

  <!-- ═══ PUBLIC REPORTS ═══ -->
  <section id="sec-public" class="section">
    <div class="table-container">
      <div class="table-hdr"><h2>Public Reports</h2></div>
      <table><thead><tr><th>#</th><th>Type</th><th>Description</th><th>Contact</th><th>Status</th><th>Date</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach($public_reports as $rep): ?>
      <tr>
        <td style="font-size:.8rem;color:var(--sub);">#<?= $rep['id'] ?></td>
        <td><span class="badge badge-pending"><?= htmlspecialchars($rep['reportType']) ?></span></td>
        <td style="max-width:200px;font-size:.875rem;"><?= htmlspecialchars(substr($rep['description'],0,80)) ?>…</td>
        <td><?= htmlspecialchars($rep['contactPhone']) ?></td>
        <td><span class="badge badge-<?= strtolower($rep['status']) ?>"><?= $rep['status'] ?></span></td>
        <td style="font-size:.85rem;"><?= date('M j',strtotime($rep['createdAt'])) ?></td>
        <td><button class="btn btn-primary"><i class="bi bi-search"></i> Investigate</button></td>
      </tr>
      <?php endforeach; ?>
      <?php if(empty($public_reports)): ?><tr><td colspan="7" style="text-align:center;padding:40px;color:var(--sub);">No reports yet</td></tr><?php endif; ?>
      </tbody></table>
    </div>
  </section>

  <!-- ═══ GEO-MONITORING ═══ -->
  <section id="sec-map" class="section">
    <div style="margin-bottom:24px;">
      <h1 style="font-family:'Syne',sans-serif;font-size:2rem;font-weight:800;background:linear-gradient(135deg,var(--brand-dark),var(--brand-mid));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;margin:0 0 6px;">Geo-Mapping Control</h1>
      <p style="color:var(--sub);">Real-time livestock density and farm distribution</p>
    </div>
    <div class="card" style="display:flex;flex-wrap:wrap;gap:24px;align-items:center;justify-content:space-between;margin-bottom:24px;padding:20px 24px;">
      <div style="display:flex;align-items:center;gap:16px;">
        <div style="width:54px;height:54px;background:linear-gradient(135deg,var(--brand-mid),var(--brand-acc));border-radius:14px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.4rem;"><i class="bi bi-geo-fill"></i></div>
        <div>
          <div style="font-weight:700;color:var(--text);">Live Farms</div>
          <div style="display:flex;align-items:center;gap:8px;margin-top:2px;">
            <span style="background:linear-gradient(135deg,var(--brand-acc),#059669);color:#fff;padding:4px 12px;border-radius:20px;font-weight:800;font-size:1.05rem;box-shadow:0 4px 12px rgba(16,185,129,.3);"><?= count($all_farms) ?></span>
            <small style="color:var(--sub);">Active Sites</small>
          </div>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:16px;">
        <div style="width:54px;height:54px;background:linear-gradient(135deg,var(--success),#059669);border-radius:14px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.4rem;"><i class="bi bi-activity"></i></div>
        <div>
          <div style="font-weight:700;color:var(--text);">Livestock Heads</div>
          <div style="display:flex;align-items:center;gap:8px;margin-top:2px;">
            <span style="background:var(--success);color:#fff;padding:4px 12px;border-radius:20px;font-weight:800;font-size:1.05rem;"><?= number_format($stats['total_livestock']) ?></span>
            <small style="color:var(--sub);">Total</small>
          </div>
        </div>
      </div>
      <div style="display:flex;flex-direction:column;gap:10px;">
        <div style="display:flex;background:var(--slate-50);border-radius:25px;padding:4px;">
            <button onclick="setMapLayer('farms',this)" id="layerFarms" class="layer-btn active" style="background:#fff;border:none;padding:10px 18px;border-radius:20px;cursor:pointer;font-weight:600;font-size:.875rem;display:flex;align-items:center;gap:6px;box-shadow:0 4px 10px rgba(0,0,0,.1);transition:.2s;"><i class="bi bi-house-door"></i> Farms</button>
            <button onclick="setMapLayer('livestock',this)" id="layerLivestock" class="layer-btn" style="background:none;border:none;padding:10px 18px;border-radius:20px;cursor:pointer;font-weight:600;font-size:.875rem;color:var(--sub);display:flex;align-items:center;gap:6px;transition:.2s;"><i class="bi bi-piggy-bank"></i> Livestock</button>
            <button onclick="setMapLayer('incidents',this)" id="layerIncidents" class="layer-btn" style="background:none;border:none;padding:10px 18px;border-radius:20px;cursor:pointer;font-weight:600;font-size:.875rem;color:var(--sub);display:flex;align-items:center;gap:6px;transition:.2s;"><i class="bi bi-exclamation-triangle"></i> Incidents</button>
          </div>
          <div style="display:flex;flex-wrap:wrap;gap:8px;">
            <button class="btn btn-primary" style="font-size:.85rem;" onclick="fitPhilippines()"><i class="bi bi-geo-alt"></i> Fit PH</button>
            <button class="btn btn-secondary" style="font-size:.85rem;" onclick="refreshMap()"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
            <!-- NEW FULL SCREEN BUTTON -->
            <a href="maps/farm-map.php" 
              class="btn" 
              style="
                background: #10b981; /* Matching Fit PH Emerald */
                color: #fff;
                font-size: .875rem;
                font-weight: 600;
                padding: 10px 20px;
                border-radius: 20px; 
                border: none;
                display: flex;
                align-items: center;
                gap: 8px;
                text-decoration: none;
                transition: background 0.2s ease;
                box-shadow: 0 2px 6px rgba(16, 185, 129, 0.2); /* Matching shadow tint */
              " 
              target="_blank" 
              title="Open advanced full-screen map"
              onmouseover="this.style.background='#059669'"
              onmouseout="this.style.background='#10b981'">
              <i class="bi bi-fullscreen" style="font-size: 0.9rem;"></i> 
              Full Map
            </a>
        </div>
</div>
    </div>
    <div class="card" style="padding:0;overflow:hidden;position:relative;">
      <div style="position:absolute;top:16px;left:16px;z-index:1000;background:rgba(255,255,255,.95);backdrop-filter:blur(12px);padding:12px 16px;border-radius:14px;border:1px solid rgba(255,255,255,.3);box-shadow:0 8px 24px rgba(0,0,0,.1);">
        <div style="font-weight:800;font-size:.9rem;color:var(--text);display:flex;align-items:center;gap:8px;">
          <span style="width:10px;height:10px;background:var(--success);border-radius:50%;animation:pulse 2s infinite;"></span>
          PHILIPPINES LIVESTOCK MONITORING
        </div>
        <small style="color:var(--sub);">Live data · <span id="mapLastUpdate"><?= date('H:i:s') ?></span></small>
      </div>
      <div id="map"></div>
      <div style="position:absolute;bottom:20px;right:20px;z-index:1000;background:rgba(255,255,255,.95);backdrop-filter:blur(12px);padding:16px;border-radius:14px;box-shadow:0 8px 24px rgba(0,0,0,.1);">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;"><div style="width:20px;height:20px;border-radius:4px;background:#10b981;"></div><span style="font-size:.875rem;">Low (&lt; 10)</span></div>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;"><div style="width:20px;height:20px;border-radius:4px;background:#f59e0b;"></div><span style="font-size:.875rem;">Medium (11–50)</span></div>
        <div style="display:flex;align-items:center;gap:10px;"><div style="width:20px;height:20px;border-radius:4px;background:#ef4444;"></div><span style="font-size:.875rem;">High (&gt; 50)</span></div>
      </div>
    </div>
    <style>@keyframes pulse{0%{box-shadow:0 0 0 0 rgba(16,185,129,.7);}70%{box-shadow:0 0 0 8px rgba(16,185,129,0);}100%{box-shadow:0 0 0 0 rgba(16,185,129,0);}}</style>
  </section>

  <!-- ═══ ANALYTICS ═══ -->
  <section id="sec-analytics" class="section">
    <div style="margin-bottom:24px;">
      <h1 style="font-family:'Syne',sans-serif;font-size:2rem;font-weight:800;background:linear-gradient(135deg,var(--brand-dark),var(--brand-mid));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;margin:0 0 6px;">Reports &amp; Analytics</h1>
      <p style="color:var(--sub);">Livestock and farm performance insights</p>
    </div>
    <div class="stats-grid">
      <div class="card" style="border-left:4px solid #3498db;"><span class="stat-val"><?= number_format($stats['total_livestock']) ?></span><span class="stat-label">Total Livestock</span></div>
      <div class="card" style="border-left:4px solid var(--success);"><span class="stat-val"><?= $stats['approved_farms'] ?></span><span class="stat-label">Approved Farms</span></div>
      <div class="card" style="border-left:4px solid var(--warn);"><span class="stat-val"><?= $stats['active_incidents'] ?></span><span class="stat-label">Active Incidents</span></div>
      <div class="card" style="border-left:4px solid var(--danger);"><span class="stat-val"><?= $stats['pending_farms'] ?></span><span class="stat-label">Pending Farms</span></div>
    </div>
    <div class="chart-grid">
      <div class="card"><h3 style="margin-bottom:16px;font-size:1.1rem;">Livestock Distribution by Type</h3><div class="chart-wrap"><canvas id="chartLivestock"></canvas></div></div>
      <div class="card"><h3 style="margin-bottom:16px;font-size:1.1rem;">Farm Status</h3><div class="chart-wrap"><canvas id="chartFarmStatus"></canvas></div></div>
    </div>
    <div class="chart-grid">
      <div class="card"><h3 style="margin-bottom:16px;font-size:1.1rem;">Incident Priority Breakdown</h3><div class="chart-wrap"><canvas id="chartIncidentPriority"></canvas></div></div>
      <div class="card"><h3 style="margin-bottom:16px;font-size:1.1rem;">Incident Status</h3><div class="chart-wrap"><canvas id="chartIncidentStatus"></canvas></div></div>
    </div>
  </section>

  <!-- ═══ PROFILE ═══ -->
<section id="sec-profile" class="section">
  <div style="max-width:700px;margin:0 auto;">
    
    <!-- UPDATED PROFILE HEADER WITH IMAGE UPLOAD -->
   <div class="card" style="background:linear-gradient(135deg,var(--brand-dark),var(--brand-mid));color:#fff;display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap;margin-bottom:24px;padding:2rem;" id="profileHeader">
  <div class="profile-avatar" id="profileAvatar" style="width:90px;height:90px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:2.25rem;font-weight:800;border:4px solid rgba(255,255,255,.3);flex-shrink:0;position:relative;overflow:hidden;">
    <?php if($user_profile['profile_image'] && file_exists($user_profile['profile_image'])): ?>
      <img src="<?= htmlspecialchars($user_profile['profile_image']) ?>" alt="Profile" style="width:100%;height:100%;object-fit:cover;">
    <?php else: ?>
      <span style="position:relative;z-index:2;"><?= htmlspecialchars($user_initial) ?></span>
    <?php endif; ?>
  </div>
  <div style="flex:1;">
    <span style="background:rgba(255,255,255,.2);padding:.3rem .875rem;border-radius:20px;font-size:.8rem;font-weight:700;text-transform:uppercase;"><?= htmlspecialchars($user_role) ?></span>
    <h2 style="font-size:1.75rem;font-weight:800;margin:.5rem 0;"><?= htmlspecialchars($user_name) ?></h2>
    <p style="opacity:.85;font-size:.9rem;"><?= htmlspecialchars($user_profile['email']??'') ?></p>
  </div>
  <button class="btn" style="background:rgba(255,255,255,.2);color:#fff;border:2px solid rgba(255,255,255,.4);" onclick="openModal('profileModal')"><i class="bi bi-pencil-square"></i> Edit</button>
</div>

    <!-- REST OF PROFILE CARDS (UNCHANGED) -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
      <div class="card"><h4 style="margin-bottom:1rem;font-size:1rem;"><i class="bi bi-person" style="color:var(--brand-acc);"></i> Personal</h4>
        <div class="info-row"><span style="color:var(--sub);font-size:.875rem;">Email</span><span style="font-weight:600;font-size:.875rem;"><?= htmlspecialchars($user_profile['email']??'—') ?></span></div>
        <div class="info-row"><span style="color:var(--sub);font-size:.875rem;">Mobile</span><span style="font-weight:600;font-size:.875rem;"><?= htmlspecialchars($user_profile['mobile']??'—') ?></span></div>
      </div>
      <div class="card"><h4 style="margin-bottom:1rem;font-size:1rem;"><i class="bi bi-briefcase" style="color:#3b82f6;"></i> Work</h4>
        <div class="info-row"><span style="color:var(--sub);font-size:.875rem;">Dept</span><span style="font-weight:600;font-size:.875rem;"><?= htmlspecialchars($user_profile['department']??'—') ?></span></div>
        <div class="info-row"><span style="color:var(--sub);font-size:.875rem;">Position</span><span style="font-weight:600;font-size:.875rem;"><?= htmlspecialchars($user_profile['position']??'—') ?></span></div>
        <div class="info-row"><span style="color:var(--sub);font-size:.875rem;">Office</span><span style="font-weight:600;font-size:.875rem;"><?= htmlspecialchars($user_profile['office']??'—') ?></span></div>
      </div>
      <div class="card" style="grid-column:1/-1;"><h4 style="margin-bottom:1rem;font-size:1rem;"><i class="bi bi-geo-alt" style="color:var(--danger);"></i> Address</h4>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.5rem;">
          <div class="info-row" style="flex-direction:column;"><span style="color:var(--sub);font-size:.8rem;">Region</span><span style="font-weight:600;font-size:.875rem;"><?= htmlspecialchars($user_profile['assigned_region']??'—') ?></span></div>
          <div class="info-row" style="flex-direction:column;"><span style="color:var(--sub);font-size:.8rem;">Province</span><span style="font-weight:600;font-size:.875rem;"><?= htmlspecialchars($user_profile['province']??'—') ?></span></div>
          <div class="info-row" style="flex-direction:column;"><span style="color:var(--sub);font-size:.8rem;">Municipality</span><span style="font-weight:600;font-size:.875rem;"><?= htmlspecialchars($user_profile['municipality']??'—') ?></span></div>
        </div>
      </div>
    </div>
  </div>
</section>

</main>

<!-- ═══ PROFILE MODAL ═══ -->
<div id="profileModal" class="modal-bg">
  <div class="modal-box" style="max-width: 600px;">
    <div class="modal-hdr">
      <h3><i class="bi bi-person-gear"></i> Edit Profile</h3>
      <button class="modal-close" onclick="closeModal('profileModal')">×</button>
    </div>
    
    <form id="profileForm" method="POST" enctype="multipart/form-data">
      <input type="hidden" name="update_profile" value="1">
      
      <div class="modal-body">
        <div style="text-align:center; padding-bottom: 1.5rem; border-bottom: 1px solid var(--slate-200); margin-bottom: 1.5rem;">
          <div id="modalProfilePreview" style="width:110px; height:110px; border-radius:50%; background:var(--slate-100); margin:0 auto 12px; display:flex; align-items:center; justify-content:center; font-size:2rem; font-weight:700; border:4px solid #fff; box-shadow: 0 2px 10px rgba(0,0,0,0.1); overflow:hidden;">
            <?php if($user_profile['profile_image'] && file_exists($user_profile['profile_image'])): ?>
              <img src="<?= htmlspecialchars($user_profile['profile_image']) ?>" id="modalProfileImg" style="width:100%; height:100%; object-fit:cover;">
            <?php else: ?>
              <span style="color: var(--brand-mid);"><?= htmlspecialchars($user_initial) ?></span>
            <?php endif; ?>
          </div>
          <label for="modalProfileImage" style="cursor:pointer; color:var(--brand-mid); font-weight:700; font-size:.85rem; display:inline-block; border: 1px solid var(--brand-mid); padding: 5px 15px; border-radius: 20px; transition: all 0.2s;">
            <i class="bi bi-camera-fill"></i> Change Photo
          </label>
          <input type="file" id="modalProfileImage" name="profile_image" accept="image/*" style="display:none;" onchange="previewImage(this)">
          <div style="color:var(--sub); font-size:.7rem; margin-top: 5px;">JPG, PNG or WebP • Max 2MB</div>
        </div>

        <div class="form-row">
          <div class="form-group"><label class="form-label">First Name</label><input class="form-input" type="text" name="firstName" value="<?= htmlspecialchars($user_profile['firstName']??'') ?>"></div>
          <div class="form-group"><label class="form-label">Last Name</label><input class="form-input" type="text" name="lastName" value="<?= htmlspecialchars($user_profile['lastName']??'') ?>"></div>
        </div>
        
        <div class="form-row">
          <div class="form-group"><label class="form-label">Email</label><input class="form-input" type="email" name="email" value="<?= htmlspecialchars($user_profile['email']??'') ?>"></div>
          <div class="form-group"><label class="form-label">Mobile</label><input class="form-input" type="tel" name="mobile" value="<?= htmlspecialchars($user_profile['mobile']??'') ?>"></div>
        </div>

        <div class="form-row">
          <div class="form-group"><label class="form-label">Department</label><input class="form-input" type="text" name="department" value="<?= htmlspecialchars($user_profile['department']??'') ?>"></div>
          <div class="form-group"><label class="form-label">Position</label><input class="form-input" type="text" name="position" value="<?= htmlspecialchars($user_profile['position']??'') ?>"></div>
        </div>

        <div style="background: var(--slate-50); padding: 1rem; border-radius: 8px; margin-top: 10px;">
            <p style="font-size: 0.75rem; font-weight: 700; color: var(--sub); text-transform: uppercase; margin-bottom: 10px; letter-spacing: 0.5px;">Location Assignment</p>
            <div class="form-row">
              <div class="form-group"><label class="form-label">Region</label><input class="form-input" type="text" name="assigned_region" value="<?= htmlspecialchars($user_profile['assigned_region']??'') ?>"></div>
              <div class="form-group"><label class="form-label">Province</label><input class="form-input" type="text" name="province" value="<?= htmlspecialchars($user_profile['province']??'') ?>"></div>
            </div>
            <div class="form-group"><label class="form-label">Municipality</label><input class="form-input" type="text" name="municipality" value="<?= htmlspecialchars($user_profile['municipality']??'') ?>"></div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn" style="background:#eee; color:#444;" onclick="closeModal('profileModal')">Cancel</button>
        <button type="submit" class="btn btn-primary" style="padding: 0.6rem 2rem;"><i class="bi bi-save"></i> Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
// ── Sidebar & Navigation ──────────────────────────────────────────────────────
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('overlay').classList.toggle('open');
}
function navTo(id, el) {
    document.querySelectorAll('.section').forEach(s=>s.classList.remove('active'));
    document.getElementById('sec-'+id).classList.add('active');
    document.querySelectorAll('.nav-item').forEach(n=>n.classList.remove('active'));
    el.classList.add('active');
    document.getElementById('page-title').textContent = el.querySelector('span').textContent;
    if(window.innerWidth<1024) toggleSidebar();
    if(id==='map') setTimeout(initMap,300);
    if(id==='analytics') setTimeout(initCharts,200);
}

// ── Modals ────────────────────────────────────────────────────────────────────
function openModal(id){ document.getElementById(id).classList.add('open'); document.body.style.overflow='hidden'; }
function closeModal(id){ document.getElementById(id).classList.remove('open'); document.body.style.overflow=''; }
document.addEventListener('click', e=>{ if(e.target.classList.contains('modal-bg')) closeModal(e.target.id); });

// ── Toast ─────────────────────────────────────────────────────────────────────
function showToast(msg, type='success'){
    const t=document.getElementById('toast');
    t.textContent=msg; t.className='toast toast-'+type+' show';
    setTimeout(()=>t.classList.remove('show'),3500);
}
function showLoading(v){ document.getElementById('loadingOverlay').style.display=v?'flex':'none'; }

// ── Farm Actions ──────────────────────────────────────────────────────────────
function filterFarms(q){
    document.querySelectorAll('#farmsTable tbody tr').forEach(row=>{
        const txt=(row.dataset.name+' '+row.dataset.owner).toLowerCase();
        row.style.display=txt.includes(q.toLowerCase())?'':'none';
    });
}

async function approveFarm(farmId, btn) {
    if(!confirm('Approve this farm registration?')) return;
    showLoading(true);
    try {
        const fd=new FormData(); fd.append('action','approve_farm'); fd.append('farm_id',farmId);
        const r=await fetch('',{method:'POST',body:fd});
        const d=await r.json();
        if(d.success){
            showToast(d.message,'success');
            document.getElementById('farm-status-'+farmId).className='badge badge-approved';
            document.getElementById('farm-status-'+farmId).textContent='Approved';
            document.getElementById('farm-actions-'+farmId).innerHTML='<span style="color:var(--success);font-size:.875rem;">✓ Approved</span>';
        } else showToast(d.message,'error');
    } catch{showToast('Network error','error');}
    showLoading(false);
}

async function rejectFarm(farmId, btn) {
    const reason=prompt('Reason for rejection (optional):');
    if(reason===null) return;
    showLoading(true);
    try {
        const fd=new FormData(); fd.append('action','reject_farm'); fd.append('farm_id',farmId); fd.append('reason',reason||'No reason provided');
        const r=await fetch('',{method:'POST',body:fd});
        const d=await r.json();
        if(d.success){
            showToast(d.message,'success');
            document.getElementById('farm-status-'+farmId).className='badge badge-rejected';
            document.getElementById('farm-status-'+farmId).textContent='Rejected';
            document.getElementById('farm-actions-'+farmId).innerHTML='<span style="color:var(--danger);font-size:.875rem;">✗ Rejected</span>';
        } else showToast(d.message,'error');
    } catch{showToast('Network error','error');}
    showLoading(false);
}

async function resolveIncident(id){
    if(!confirm('Mark this incident as resolved?')) return;
    showLoading(true);
    try {
        const fd=new FormData(); fd.append('action','resolve_incident'); fd.append('incident_id',id);
        const r=await fetch('',{method:'POST',body:fd});
        const d=await r.json();
        if(d.success){ showToast('Incident resolved!','success'); setTimeout(()=>location.reload(),1200); }
        else showToast(d.message,'error');
    } catch{showToast('Network error','error');}
    showLoading(false);
}

// ── Profile Form ──────────────────────────────────────────────────────────────
document.getElementById('profileForm').addEventListener('submit', async e=>{
    e.preventDefault();
    const btn=e.target.querySelector('[type=submit]'); btn.disabled=true;
    const fd=new FormData(e.target);
    try {
        const r=await fetch('',{method:'POST',body:fd});
        const d=await r.json();
        if(d.success){showToast('Profile updated!','success');closeModal('profileModal');setTimeout(()=>location.reload(),1200);}
        else showToast(d.message,'error');
    } catch{showToast('Network error','error');}
    btn.disabled=false;
});

// ── Profile Image Handling ────────────────────────────────────────────────────
function updateProfileAvatar(imageUrl) {
    const avatar = document.getElementById('profileAvatar');
    const img = avatar.querySelector('img');
    
    if (imageUrl) {
        if (img) {
            img.src = imageUrl;
        } else {
            const newImg = document.createElement('img');
            newImg.src = imageUrl;
            newImg.alt = 'Profile';
            newImg.style.cssText = 'width:100%;height:100%;object-fit:cover;';
            avatar.innerHTML = '';
            avatar.appendChild(newImg);
            // Keep camera overlay
            const camera = document.querySelector('.profile-camera');
            if (camera) avatar.appendChild(camera);
        }
    } else {
        // Fallback to initials
        const initials = '<?= htmlspecialchars($user_initial) ?>';
        avatar.innerHTML = `
            <span style="position:relative;z-index:2;font-size:2.25rem;font-weight:800;">${initials}</span>
            <label for="profileImageInput" class="profile-camera" style="position:absolute;bottom:8px;right:8px;width:28px;height:28px;background:var(--brand-acc);border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid rgba(255,255,255,.9);cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.2);">
                <i class="bi bi-camera" style="font-size:1rem;color:#fff;"></i>
            </label>
            <input type="file" id="profileImageInput" name="profile_image" accept="image/*" style="display:none;">
        `;
        attachProfileImageHandlers();
    }
}

// Attach image upload handlers
function attachProfileImageHandlers() {
    // Main profile image
    document.getElementById('profileImageInput').addEventListener('change', handleProfileImageUpload);
    
    // Modal profile image
    document.getElementById('modalProfileImage').addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('modalProfileImg') ? 
                    document.getElementById('modalProfileImg').src = e.target.result :
                    document.getElementById('modalProfilePreview').innerHTML = `<img id="modalProfileImg" src="${e.target.result}" style="width:100%;height:100%;object-fit:cover;">`;
            };
            reader.readAsDataURL(file);
        }
    });
}

function handleProfileImageUpload(e) {
    const file = e.target.files[0];
    if (file && file.size <= 2 * 1024 * 1024) { // 2MB limit
        const reader = new FileReader();
        reader.onload = function(e) {
            // Temporary preview
            const avatar = document.getElementById('profileAvatar');
            const img = avatar.querySelector('img');
            if (img) img.src = e.target.result;
        };
        reader.readAsDataURL(file);
    } else {
        showToast('Please select an image under 2MB', 'error');
        e.target.value = '';
    }
}

// Initialize image handlers on page load
document.addEventListener('DOMContentLoaded', function() {
    attachProfileImageHandlers();
    
    // Update profile form success handler
    const originalProfileSubmit = document.getElementById('profileForm').onsubmit;
    document.getElementById('profileForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const btn = e.target.querySelector('[type=submit]');
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Saving...';
        
        const fd = new FormData(e.target);
        try {
            const r = await fetch('', {method: 'POST', body: fd});
            const d = await r.json();
            if (d.success) {
                showToast('Profile updated!', 'success');
                if (d.profile_image) {
                    updateProfileAvatar(d.profile_image);
                }
                closeModal('profileModal');
                setTimeout(() => location.reload(), 1200);
            } else {
                showToast(d.message, 'error');
            }
        } catch {
            showToast('Network error', 'error');
        }
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-save"></i> Save';
    });
});

// ── Leaflet Map ───────────────────────────────────────────────────────────────
let map=null, layerGroups={farms:null,livestock:null,incidents:null}, activeLayer='farms';

const farmsData    = <?= json_encode($all_farms) ?>;
const incidentsData= <?= json_encode($all_incidents) ?>;
const livestockData= <?= json_encode($livestock_stats) ?>;

function initMap(){
    if(map) return;
    map=L.map('map').setView([12.8797,121.774],6);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OpenStreetMap'}).addTo(map);

    // Farms layer
    layerGroups.farms=L.layerGroup();
    farmsData.forEach(farm=>{
        if(farm.latitude && farm.longitude){
            const color=farm.total_livestock>50?'#ef4444':farm.total_livestock>10?'#f59e0b':'#10b981';
            const marker=L.circleMarker([parseFloat(farm.latitude),parseFloat(farm.longitude)],{
                radius:Math.max(8,Math.min(20,farm.total_livestock/5+6)),
                fillColor:color,color:'#fff',weight:2,opacity:1,fillOpacity:.85
            }).bindPopup(`<div style="min-width:200px;">
                <strong style="color:#064e3b;font-size:1rem;">${farm.name}</strong><br>
                <span style="color:#6b7280;font-size:.85rem;">Owner: ${farm.firstName||''} ${farm.lastName||''}</span><br>
                <span style="font-size:.9rem;"><b>${farm.total_livestock}</b> livestock heads</span><br>
                <span style="padding:2px 8px;border-radius:20px;font-size:.75rem;font-weight:600;background:${farm.status==='Approved'?'#d1fae5':'#fef3c7'};color:${farm.status==='Approved'?'#065f46':'#92400e'};">${farm.status}</span>
            </div>`);
            layerGroups.farms.addLayer(marker);
        }
    });

    // Incidents layer
    layerGroups.incidents=L.layerGroup();
    incidentsData.forEach(inc=>{
        if(inc.latitude && inc.longitude){
            const marker=L.marker([parseFloat(inc.latitude),parseFloat(inc.longitude)],{
                icon:L.divIcon({html:'<div style="background:#ef4444;color:#fff;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.3);">⚠</div>',className:'',iconSize:[28,28],iconAnchor:[14,14]})
            }).bindPopup(`<b>${inc.title}</b><br>Priority: ${inc.priority}<br>Status: ${inc.status}`);
            layerGroups.incidents.addLayer(marker);
        }
    });

    // Default: show farms
    layerGroups.farms.addTo(map);
    document.getElementById('mapLastUpdate').textContent=new Date().toLocaleTimeString();
}

function setMapLayer(type, btn){
    if(!map) return;
    activeLayer=type;
    Object.values(layerGroups).forEach(lg=>{ if(lg) map.removeLayer(lg); });
    if(layerGroups[type]) layerGroups[type].addTo(map);
    document.querySelectorAll('.layer-btn').forEach(b=>{ b.style.background='none'; b.style.boxShadow='none'; b.style.color='var(--sub)'; });
    btn.style.background='#fff'; btn.style.boxShadow='0 4px 10px rgba(0,0,0,.1)'; btn.style.color='var(--text)';
}

function fitPhilippines(){ if(map) map.fitBounds([[4.5,116.9],[21.2,127.0]]); }
function refreshMap(){
    if(map){ map.remove(); map=null; Object.keys(layerGroups).forEach(k=>layerGroups[k]=null); }
    setTimeout(initMap,200);
    showToast('Map refreshed','success');
}

// ── Charts ────────────────────────────────────────────────────────────────────
let chartsInit=false;
function initCharts(){
    if(chartsInit) return;
    chartsInit=true;

    const lvData = <?= json_encode($livestock_stats) ?>;
    const lvLabels = lvData.map(d=>d.type);
    const lvValues = lvData.map(d=>parseInt(d.total_qty));

    const farmStatusData = <?= json_encode($farm_by_status) ?>;
    const incPriorityData= <?= json_encode($incident_by_priority) ?>;
    const incStatusData  = <?= json_encode($incident_by_status) ?>;

    const palette=['#10b981','#3b82f6','#f59e0b','#ef4444','#8b5cf6','#06b6d4','#84cc16'];

    // Livestock bar chart
    new Chart(document.getElementById('chartLivestock'),{
        type:'bar',
        data:{labels:lvLabels,datasets:[{label:'Heads',data:lvValues,backgroundColor:palette.slice(0,lvLabels.length),borderRadius:8,borderSkipped:false}]},
        options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{color:'#64748b'}},x:{ticks:{color:'#64748b'}}}}
    });

    // Farm status doughnut
    new Chart(document.getElementById('chartFarmStatus'),{
        type:'doughnut',
        data:{labels:farmStatusData.map(d=>d.status),datasets:[{data:farmStatusData.map(d=>d.cnt),backgroundColor:['#10b981','#f59e0b','#ef4444'],borderWidth:0}]},
        options:{responsive:true,maintainAspectRatio:false,cutout:'65%',plugins:{legend:{position:'bottom'}}}
    });

    // Incident priority polar
    new Chart(document.getElementById('chartIncidentPriority'),{
        type:'polarArea',
        data:{labels:incPriorityData.map(d=>d.priority),datasets:[{data:incPriorityData.map(d=>d.cnt),backgroundColor:['#ef4444aa','#f59e0baa','#3b82f6aa','#10b981aa']}]},
        options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}}}
    });

    // Incident status pie
    new Chart(document.getElementById('chartIncidentStatus'),{
        type:'pie',
        data:{labels:incStatusData.map(d=>d.status),datasets:[{data:incStatusData.map(d=>d.cnt),backgroundColor:palette.slice(0,incStatusData.length),borderWidth:0}]},
        options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}}}
    });
}
</script>
</body>
</html>