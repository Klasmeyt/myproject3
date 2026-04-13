<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_role'] !== 'Farmer') {
    header('Location: login.php'); exit;
}
try {
    $pdo = new PDO("mysql:host=localhost;dbname=myproject4;charset=utf8mb4","root","",[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
    ]);
} catch(PDOException $e) { die("Database error."); }

$userId    = $_SESSION['user_id'] ?? 0;
$firstName = $_SESSION['firstName'] ?? 'Farmer';
$lastName  = $_SESSION['lastName']  ?? '';

// ── PROFILE UPDATE ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['update_profile'])) {
    header('Content-Type: application/json');
    try {
        $pdo->prepare("UPDATE users SET email=?,mobile=? WHERE id=?")
            ->execute([$_POST['email'],$_POST['mobile'],$userId]);
        $picPath = null;
        if (!empty($_FILES['profile_pix']['tmp_name'])) {
            $ext = pathinfo($_FILES['profile_pix']['name'],PATHINFO_EXTENSION);
            $picPath = "uploads/profiles/{$userId}_".time().".$ext";
            @mkdir(dirname($picPath),0755,true);
            move_uploaded_file($_FILES['profile_pix']['tmp_name'],$picPath);
        }
        $stmt=$pdo->prepare("INSERT INTO farmer_profiles(user_id,gender,dob,address,emergency_contact".($picPath?",profile_pix":"").")
            VALUES(?,?,?,?,?".($picPath?",?":"").")
            ON DUPLICATE KEY UPDATE gender=VALUES(gender),dob=VALUES(dob),address=VALUES(address),
            emergency_contact=VALUES(emergency_contact)".($picPath?",profile_pix=VALUES(profile_pix)":""));
        $params=[$userId,$_POST['gender']??'',$_POST['dob']??'',$_POST['address']??'',$_POST['emergency_contact']??''];
        if($picPath) $params[]=$picPath;
        $stmt->execute($params);
        echo json_encode(['success'=>true]);
    } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}

// ── FARM REGISTRATION ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='register_farm') {
    header('Content-Type: application/json');
    try {
        $name    = trim($_POST['farm_name']   ?? '');
        $address = trim($_POST['farm_address']?? '');
        $type    = $_POST['farm_type']  ?? 'Mixed';
        $area    = (float)($_POST['farm_area'] ?? 0);
        $lat     = !empty($_POST['farm_lat']) ? (float)$_POST['farm_lat'] : null;
        $lng     = !empty($_POST['farm_lng']) ? (float)$_POST['farm_lng'] : null;
        $ownerName = trim(($firstName??' ').' '.($lastName??''));

        if(!$name) throw new Exception('Farm name required');

        // Save photo if provided
        $photoPath = null;
        if(!empty($_POST['photo_data']) && strpos($_POST['photo_data'],'base64,')!==false) {
            $imgData = base64_decode(explode(',', $_POST['photo_data'])[1]);
            @mkdir('uploads/farms',0755,true);
            $photoPath = 'uploads/farms/farm_'.time().'_'.$userId.'.jpg';
            file_put_contents($photoPath, $imgData);
        }

        $pdo->prepare("INSERT INTO farms(name,ownerId,ownerName,type,address,latitude,longitude,area,status)
                        VALUES(?,?,?,?,?,?,?,?,'Pending')")
            ->execute([$name,$userId,$ownerName,$type,$address,$lat,$lng,$area]);
        $farmId = $pdo->lastInsertId();

        // Livestock
        $lType = $_POST['livestock_type'] ?? 'Cattle';
        $qty   = (int)($_POST['livestock_qty'] ?? 1);
        $tag   = $_POST['livestock_tag'] ?? 'TAG-'.strtoupper(substr($lType,0,3)).'-'.str_pad($farmId,4,'0',STR_PAD_LEFT);
        if(!$tag) $tag = 'TAG-'.strtoupper(substr($lType,0,3)).'-'.str_pad($farmId,4,'0',STR_PAD_LEFT);

        $pdo->prepare("INSERT INTO livestock(farmId,type,tagId,qty,healthStatus,vaccinationStatus,latitude,longitude)
                        VALUES(?,?,?,?,'Healthy','None',?,?)")
            ->execute([$farmId,$lType,$tag,$qty,$lat,$lng]);

        // Audit log
        $pdo->prepare("INSERT INTO audit_log(userId,action,tableName,recordId,details,ipAddress) VALUES(?,?,?,?,?,?)")
            ->execute([$userId,'CREATE','farms',$farmId,json_encode(['farm_name'=>$name]),$_SERVER['REMOTE_ADDR']??'']);

        echo json_encode(['success'=>true,'farm_id'=>$farmId,'message'=>"Farm '$name' registered! Awaiting approval."]);
    } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}

// ── LIVESTOCK UPDATE ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='update_livestock') {
    header('Content-Type: application/json');
    try {
        $id = (int)$_POST['livestock_id'];
        $pdo->prepare("UPDATE livestock SET type=?,breed=?,age=?,weight=?,healthStatus=?,vaccinationStatus=?,qty=?,tagId=? WHERE id=?")
            ->execute([$_POST['type'],$_POST['breed']??null,$_POST['age']??null,$_POST['weight']??null,
                       $_POST['healthStatus'],$_POST['vaccinationStatus'],$_POST['qty'],$_POST['tagId'],$id]);
        echo json_encode(['success'=>true]);
    } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}

// ── INCIDENT REPORT ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='report_incident') {
    header('Content-Type: application/json');
    try {
        $type    = $_POST['incident_type'] ?? 'Others';
        $title   = trim($_POST['title']  ?? '');
        $desc    = trim($_POST['description'] ?? '');
        $farmId  = !empty($_POST['farm_id']) ? (int)$_POST['farm_id'] : null;
        $lat     = !empty($_POST['latitude'])  ? (float)$_POST['latitude']  : null;
        $lng     = !empty($_POST['longitude']) ? (float)$_POST['longitude'] : null;
        $address = $_POST['incident_address'] ?? null;
        if(!$title||!$desc) throw new Exception('Title and description required');

        $photoPath = null;
        if(!empty($_POST['photo_data']) && strpos($_POST['photo_data'],'base64,')!==false){
            $imgData=base64_decode(explode(',',$_POST['photo_data'])[1]);
            @mkdir('uploads/incidents',0755,true);
            $photoPath='uploads/incidents/inc_'.time().'_'.$userId.'.jpg';
            file_put_contents($photoPath,$imgData);
        }

        $pdo->prepare("INSERT INTO incidents(farmId,reporterId,type,title,description,latitude,longitude,photoUrl,status,priority)
                        VALUES(?,?,?,?,?,?,?,?,'Pending','Medium')")
            ->execute([$farmId,$userId,$type,$title,$desc,$lat,$lng,$photoPath]);
        $incId = $pdo->lastInsertId();
        echo json_encode(['success'=>true,'incident_id'=>$incId]);
    } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}

// ── ADD LIVESTOCK ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='add_livestock') {
    header('Content-Type: application/json');
    try {
        $farmId = (int)$_POST['farm_id'];
        // verify farm belongs to user
        $f=$pdo->prepare("SELECT id FROM farms WHERE id=? AND ownerId=?");
        $f->execute([$farmId,$userId]);
        if(!$f->fetch()) throw new Exception('Farm not found');
        $pdo->prepare("INSERT INTO livestock(farmId,type,tagId,breed,age,weight,qty,healthStatus,vaccinationStatus)
                        VALUES(?,?,?,?,?,?,?,'Healthy','None')")
            ->execute([$farmId,$_POST['type'],$_POST['tagId'],$_POST['breed']??null,
                       $_POST['age']??null,$_POST['weight']??null,$_POST['qty']??1]);
        echo json_encode(['success'=>true]);
    } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}

// ── FETCH DATA ──────────────────────────────────────────────────────────────
$dashboardData = [];
$farmsData     = [];
$livestockData = [];
$incidentsData = [];
$profileData   = [];

// Farms (with rejection reason)
$stmt=$pdo->prepare("SELECT * FROM farms WHERE ownerId=? ORDER BY createdAt DESC"); $stmt->execute([$userId]);
$farmsData=$stmt->fetchAll();

// Dashboard stats
$dashboardData['totalLivestock'] = $pdo->prepare("SELECT COALESCE(SUM(l.qty),0) FROM livestock l JOIN farms f ON l.farmId=f.id WHERE f.ownerId=?");
$dashboardData['totalLivestock']->execute([$userId]);
$dashboardData['totalLivestock'] = $dashboardData['totalLivestock']->fetchColumn();

$stmt2=$pdo->prepare("SELECT COUNT(*) FROM incidents WHERE reporterId=? AND status IN('Pending','In Progress')"); $stmt2->execute([$userId]);
$dashboardData['activeIncidents']=$stmt2->fetchColumn();

$stmt3=$pdo->prepare("SELECT COUNT(*) FROM farms WHERE ownerId=? AND status='Pending'"); $stmt3->execute([$userId]);
$dashboardData['pendingInspections']=$stmt3->fetchColumn();

$stmt4=$pdo->prepare("SELECT l.type,SUM(l.qty) as total FROM livestock l JOIN farms f ON l.farmId=f.id WHERE f.ownerId=? GROUP BY l.type"); $stmt4->execute([$userId]);
$dashboardData['livestockByType']=$stmt4->fetchAll();

// Livestock
$stmt5=$pdo->prepare("SELECT l.*,f.name as farmName FROM livestock l JOIN farms f ON l.farmId=f.id WHERE f.ownerId=? ORDER BY l.createdAt DESC"); $stmt5->execute([$userId]);
$livestockData=$stmt5->fetchAll();

// Incidents
$stmt6=$pdo->prepare("SELECT i.*,f.name as farmName FROM incidents i LEFT JOIN farms f ON i.farmId=f.id WHERE i.reporterId=? ORDER BY i.createdAt DESC"); $stmt6->execute([$userId]);
$incidentsData=$stmt6->fetchAll();

// Profile
$stmt7=$pdo->prepare("SELECT u.*,fp.gender,fp.dob,fp.gov_id,fp.address,fp.emergency_contact,fp.profile_pix FROM users u LEFT JOIN farmer_profiles fp ON u.id=fp.user_id WHERE u.id=?"); $stmt7->execute([$userId]);
$profileData=$stmt7->fetch();
if(!$profileData) $profileData=['firstName'=>$firstName,'lastName'=>$lastName,'email'=>'','mobile'=>''];

// Approved farms count
$stmt8=$pdo->prepare("SELECT COUNT(*) FROM farms WHERE ownerId=? AND status='Approved'"); $stmt8->execute([$userId]);
$hasApprovedFarms=$stmt8->fetchColumn()>0;

// Certificates
$stmt9=$pdo->prepare("SELECT c.*,f.name as farm_name FROM certificates c JOIN farms f ON c.farm_id=f.id WHERE f.ownerId=? AND c.status='Active' ORDER BY c.created_at DESC"); $stmt9->execute([$userId]);
$eCertData=$stmt9->fetchAll();

$farmCount=$pdo->prepare("SELECT COUNT(*) FROM farms WHERE ownerId=?"); $farmCount->execute([$userId]);
$farmCount=$farmCount->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AgriTrace+ | Farmer Portal</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<style>
:root{
  --pri:#064e3b;--acc:#10b981;--acc2:#059669;--bg:#f9fafb;
  --txt:#064e3b;--sub:#6b7280;--white:#fff;--danger:#ef4444;
  --warn:#f59e0b;--border:#e5e7eb;--sw:280px;
}
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Plus Jakarta Sans',-apple-system,sans-serif;background:var(--bg);color:var(--txt);overflow-x:hidden;}
.sidebar{position:fixed;left:-300px;top:0;width:var(--sw);height:100vh;background:var(--pri);z-index:2000;transition:.3s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column;color:rgba(255,255,255,.9);}
.sidebar.active{left:0;}
.sidebar-header{padding:2rem 1.5rem;border-bottom:1px solid rgba(255,255,255,.1);}
.brand{font-size:1.75rem;font-weight:800;color:#fff;}.brand span{color:var(--acc);}
.portal-label{font-size:.75rem;font-weight:700;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:.05em;}
.nav-list{flex:1;padding:1rem 0;overflow-y:auto;}
.nav-item{display:flex;align-items:center;padding:.875rem 1.5rem;margin:.25rem;color:rgba(255,255,255,.8);text-decoration:none;border-radius:.75rem;font-weight:500;transition:.3s;cursor:pointer;}
.nav-item i{font-size:1.25rem;margin-right:.75rem;width:1.5rem;text-align:center;}
.nav-item:hover{background:rgba(255,255,255,.1);color:#fff;transform:translateX(4px);}
.nav-item.active{background:var(--acc);color:#fff;box-shadow:0 8px 25px rgba(16,185,129,.3);}
.badge-alert{background:var(--danger);color:#fff;border-radius:9999px;padding:.25rem .75rem;font-size:.75rem;font-weight:700;margin-left:auto;}
.sidebar-footer{padding:1.5rem;border-top:1px solid rgba(255,255,255,.1);}
.user-card{display:flex;align-items:center;gap:.75rem;}
.avatar{width:2.5rem;height:2.5rem;background:var(--acc);color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1.125rem;flex-shrink:0;}
.user-name{font-size:.95rem;font-weight:600;color:#fff;display:block;}
.user-role{font-size:.8rem;color:rgba(255,255,255,.7);}
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1500;opacity:0;visibility:hidden;transition:.3s;}
.overlay.show{opacity:1;visibility:visible;}
.top-bar{position:sticky;top:0;background:#fff;border-bottom:1px solid var(--border);padding:1rem 1.5rem;display:flex;align-items:center;justify-content:space-between;z-index:1000;}
.menu-btn{background:none;border:none;font-size:1.5rem;color:var(--pri);cursor:pointer;padding:.5rem;border-radius:.5rem;}
.page-title{font-weight:700;font-size:1.25rem;color:var(--txt);}
.main-wrap{min-height:100vh;}
.content{padding:2rem;max-width:1400px;margin:0 auto;}
.card{background:#fff;border:1px solid var(--border);border-radius:1rem;padding:2rem;margin-bottom:1.5rem;box-shadow:0 1px 3px rgba(0,0,0,.1);transition:.3s;}
.card:hover{box-shadow:0 4px 20px rgba(0,0,0,.08);}
.card-title{font-size:1.5rem;font-weight:700;margin-bottom:1.5rem;color:var(--txt);}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1.5rem;margin:2rem 0;}
.stat-card{text-align:center;padding:1.75rem;border-radius:1rem;border:1px solid var(--border);background:#fff;transition:.3s;}
.stat-card:hover{transform:translateY(-4px);box-shadow:0 12px 40px rgba(0,0,0,.15);}
.stat-num{font-size:2.75rem;font-weight:800;background:linear-gradient(135deg,var(--acc),var(--acc2));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;line-height:1;}
.stat-lbl{font-size:.95rem;color:var(--sub);font-weight:600;text-transform:uppercase;letter-spacing:.025em;}
.badge{padding:.375rem 1rem;border-radius:9999px;font-size:.8rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;}
.badge-pending{background:#fef3c7;color:#92400e;}
.badge-approved{background:#d1fae5;color:#065f46;}
.badge-rejected{background:#fee2e2;color:#991b1b;}
.badge-healthy{background:#d1fae5;color:#065f46;}
.badge-sick{background:#fee2e2;color:#991b1b;}
.badge-active{background:#d1fae5;color:#065f46;}
.form-group{margin-bottom:1.25rem;}
.form-label{display:block;font-weight:600;margin-bottom:.5rem;color:var(--txt);font-size:.95rem;}
.form-input,.form-select,textarea.form-input{width:100%;padding:.875rem 1rem;border:2px solid var(--border);border-radius:.75rem;font-size:1rem;font-family:inherit;transition:.3s;background:#fff;}
.form-input:focus,.form-select:focus,textarea.form-input:focus{outline:none;border-color:var(--acc);box-shadow:0 0 0 4px rgba(16,185,129,.1);}
.btn{padding:.75rem 1.5rem;border:none;border-radius:.75rem;font-weight:600;cursor:pointer;transition:.3s;display:inline-flex;align-items:center;gap:.5rem;text-decoration:none;font-size:.95rem;font-family:inherit;}
.btn-primary{background:var(--acc);color:#fff;}.btn-primary:hover{background:var(--acc2);transform:translateY(-1px);}
.btn-secondary{background:#f8fafc;color:var(--txt);border:2px solid var(--border);}.btn-secondary:hover{border-color:var(--acc);color:var(--acc);}
.btn-danger{background:var(--danger);color:#fff;}
.table-wrap{overflow-x:auto;border-radius:1rem;border:1px solid var(--border);}
table{width:100%;border-collapse:collapse;background:#fff;}
th{background:#f8fafc;padding:1rem 1.25rem;text-align:left;font-weight:600;font-size:.875rem;color:var(--sub);text-transform:uppercase;letter-spacing:.05em;border-bottom:2px solid var(--border);white-space:nowrap;}
td{padding:1rem 1.25rem;border-bottom:1px solid #f1f5f9;vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tr:hover{background:#f8fafc;}
.page-section{display:none;animation:fadeUp .3s ease;}
.page-section.active{display:block;}
@keyframes fadeUp{from{opacity:0;transform:translateY(10px);}to{opacity:1;transform:translateY(0);}}
.empty-state{text-align:center;padding:4rem 2rem;color:var(--sub);}
.empty-state i{font-size:4rem;display:block;margin-bottom:1.5rem;opacity:.5;}
.modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.65);backdrop-filter:blur(8px);z-index:5000;display:none;align-items:center;justify-content:center;padding:1rem;}
.modal-backdrop.open{display:flex;}
.modal-box{background:#fff;border-radius:1.25rem;width:100%;max-width:560px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);animation:modalIn .3s ease;}
@keyframes modalIn{from{transform:translateY(20px) scale(.96);opacity:0;}to{transform:translateY(0) scale(1);opacity:1;}}
.modal-hdr{padding:1.5rem 2rem;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border);position:sticky;top:0;background:#fff;z-index:1;}
.modal-hdr h3{margin:0;font-size:1.25rem;color:var(--pri);}
.modal-close{background:none;border:none;font-size:1.5rem;color:var(--sub);cursor:pointer;padding:.25rem;border-radius:.5rem;}
.modal-close:hover{background:#f3f4f6;}
.modal-body{padding:1.5rem 2rem;}
.modal-footer{padding:1rem 2rem;border-top:1px solid var(--border);display:flex;gap:.75rem;justify-content:flex-end;background:#f8fafc;border-radius:0 0 1.25rem 1.25rem;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem;}
.gps-badge{background:#d1fae5;color:#065f46;padding:.5rem 1rem;border-radius:.5rem;font-size:.85rem;font-weight:600;display:flex;align-items:center;gap:.5rem;margin-top:.5rem;}
.capture-zone{border:2px dashed var(--border);border-radius:.75rem;padding:1.5rem;text-align:center;cursor:pointer;transition:.3s;}
.capture-zone:hover{border-color:var(--acc);background:#f0fdf4;}
.photo-preview{position:relative;border-radius:.75rem;overflow:hidden;border:2px solid var(--acc);margin-top:.75rem;}
.photo-preview canvas{width:100%;height:auto;display:block;}
.photo-remove{position:absolute;top:.5rem;right:.5rem;background:rgba(239,68,68,.9);color:#fff;border:none;width:2rem;height:2rem;border-radius:50%;cursor:pointer;font-size:1rem;display:flex;align-items:center;justify-content:center;}
.info-row{display:flex;justify-content:space-between;padding:.875rem;background:#f8fafc;border-radius:.5rem;margin-bottom:.5rem;}
.info-lbl{color:var(--sub);font-size:.9rem;font-weight:500;}
.info-val{font-weight:600;color:var(--txt);text-align:right;}
.notif-item{display:flex;gap:1rem;padding:1.25rem;border:1px solid var(--border);border-radius:.75rem;margin-bottom:1rem;transition:.3s;}
.notif-item:hover{border-color:var(--acc);box-shadow:0 4px 12px rgba(16,185,129,.15);}
.notif-icon{width:2.75rem;height:2.75rem;border-radius:.75rem;display:flex;align-items:center;justify-content:center;color:#fff;flex-shrink:0;}
.cert-card{background:#fff;border-radius:1rem;padding:1.75rem;border:1px solid var(--border);box-shadow:0 4px 12px rgba(0,0,0,.06);position:relative;overflow:hidden;transition:.3s;}
.cert-card::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,#064e3b,#10b981,#d4af37);}
.cert-card:hover{transform:translateY(-6px);box-shadow:0 16px 40px rgba(6,78,59,.15);}
.cert-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:1.5rem;}
.request-cert{background:linear-gradient(135deg,#f0f9f4,#e6f4ea);border:2px dashed #4a7c59;border-radius:1.25rem;padding:4rem 2rem;text-align:center;}
.toast{position:fixed;top:1.25rem;right:1.25rem;z-index:9999;padding:1rem 1.5rem;border-radius:.75rem;color:#fff;font-weight:600;box-shadow:0 10px 30px rgba(0,0,0,.2);transform:translateX(400px);transition:transform .3s ease;pointer-events:none;}
.toast.show{transform:translateX(0);}
.toast-success{background:var(--acc);}
.toast-error{background:var(--danger);}
.toast-warn{background:var(--warn);}
#incident-map{height:300px;border-radius:.75rem;margin-top:.75rem;border:2px solid var(--border);}
@media(max-width:768px){.content{padding:1.5rem 1rem;}.stats-grid{grid-template-columns:repeat(2,1fr);}.form-row{grid-template-columns:1fr;}.cert-grid{grid-template-columns:1fr;}}
@media(max-width:480px){.stats-grid{grid-template-columns:1fr;}}
</style>
</head>
<body>
<div class="overlay" id="overlay"></div>

<aside class="sidebar" id="sidebar">
  <div class="sidebar-header">
    <div class="brand">Agri<span>Trace+</span></div>
    <span class="portal-label">Farmer Portal</span>
  </div>
  <nav class="nav-list">
    <div class="nav-item active" data-page="dashboard"><i class="bi bi-grid-1x2-fill"></i>Dashboard</div>
    <div class="nav-item" data-page="farm-reg"><i class="bi bi-house-add"></i>Farm Registration</div>
    <div class="nav-item" data-page="livestock"><i class="bi bi-activity"></i>Livestock Monitoring</div>
    <div class="nav-item" data-page="incident"><i class="bi bi-exclamation-triangle"></i>Incident Reporting</div>
    <div class="nav-item" data-page="notifications"><i class="bi bi-bell"></i>Notifications<span class="badge-alert"><?= $dashboardData['activeIncidents'] ?></span></div>
    <div class="nav-item" data-page="ecert"><i class="bi bi-patch-check"></i>E-Certificate</div>
    <div class="nav-item" data-page="profile"><i class="bi bi-person-circle"></i>Profile</div>
    <div style="padding:1rem 1.5rem;"><hr style="border:none;border-top:1px solid rgba(255,255,255,.1);"></div>
    <a href="login.php" class="nav-item" style="color:#fbbf24;"><i class="bi bi-box-arrow-right"></i>Logout</a>
  </nav>
  <div class="sidebar-footer">
    <div class="user-card">
      <div class="avatar"><?= strtoupper(substr($firstName,0,1)) ?></div>
      <div><span class="user-name"><?= htmlspecialchars($firstName.' '.$lastName) ?></span><span class="user-role">Farmer</span></div>
    </div>
  </div>
</aside>

<div class="main-wrap">
  <header class="top-bar">
    <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
    <div class="page-title" id="pageTitle">Dashboard</div>
    <div style="width:2rem;"></div>
  </header>

  <main class="content">

    <!-- ═══ DASHBOARD ═══ -->
    <section id="sec-dashboard" class="page-section active">
      <div class="card" style="background:linear-gradient(135deg,#064e3b,#10b981);color:#fff;padding:2rem;">
        <h1 style="font-size:1.875rem;font-weight:800;margin-bottom:.5rem;">Good day, <?= htmlspecialchars($firstName) ?>! 👋</h1>
        <p style="opacity:.85;font-size:1.05rem;">Here's an overview of your farm activities</p>
      </div>
      <div class="stats-grid">
        <div class="stat-card"><div class="stat-num"><?= number_format($dashboardData['totalLivestock']) ?></div><div class="stat-lbl">Total Livestock</div></div>
        <div class="stat-card"><div class="stat-num" style="color:var(--danger);"><?= $dashboardData['activeIncidents'] ?></div><div class="stat-lbl">Active Incidents</div></div>
        <div class="stat-card"><div class="stat-num" style="color:var(--warn);"><?= $dashboardData['pendingInspections'] ?></div><div class="stat-lbl">Pending Approvals</div></div>
        <div class="stat-card"><div class="stat-num"><?= count($farmsData) ?></div><div class="stat-lbl">My Farms</div></div>
      </div>

      <!-- My Farms table -->
      <div class="card">
        <h3 class="card-title">My Farms</h3>
        <?php if(!empty($farmsData)): ?>
        <div class="table-wrap"><table>
          <thead><tr><th>Farm Name</th><th>Type</th><th>Address</th><th>Status</th><th>Registered</th><th>Action</th></tr></thead>
          <tbody>
          <?php foreach($farmsData as $farm): ?>
          <tr>
            <td><strong><?= htmlspecialchars($farm['name']) ?></strong></td>
            <td><span class="badge badge-approved" style="background:rgba(16,185,129,.1);color:#065f46;"><?= htmlspecialchars($farm['type']) ?></span></td>
            <td style="max-width:200px;font-size:.85rem;color:var(--sub);"><?= htmlspecialchars(substr($farm['address']??'N/A',0,60)) ?><?= strlen($farm['address']??'')>60?'...':'' ?></td>
            <td>
              <span class="badge badge-<?= strtolower($farm['status']) ?>"><?= $farm['status'] ?></span>
              <?php if($farm['status']==='Rejected'&&!empty($farm['rejection_reason'])): ?>
              <button class="btn btn-secondary" style="margin-left:.5rem;padding:.3rem .6rem;font-size:.75rem;" onclick="showRejection('<?= htmlspecialchars(addslashes($farm['rejection_reason'])) ?>')"><i class="bi bi-info-circle"></i></button>
              <?php endif; ?>
            </td>
            <td style="font-size:.85rem;"><?= date('M d, Y',strtotime($farm['createdAt'])) ?></td>
            <td>
              <?php if($farm['status']==='Rejected'): ?>
              <button class="btn btn-primary" style="padding:.4rem .8rem;font-size:.8rem;" onclick="appealFarm(<?= $farm['id'] ?>)"><i class="bi bi-arrow-repeat"></i> Appeal</button>
              <?php elseif($farm['status']==='Pending'): ?>
              <span style="color:var(--warn);font-size:.85rem;">⏳ Awaiting</span>
              <?php else: ?>
              <span style="color:var(--acc);font-size:.85rem;">✅ Approved</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <?php else: ?>
        <div class="empty-state"><i class="bi bi-house"></i><h4>No farms registered yet</h4><p>Register your first farm to get started</p>
          <button class="btn btn-primary" style="margin-top:1rem;" onclick="navTo('farm-reg')"><i class="bi bi-plus-lg"></i> Register Farm</button>
        </div>
        <?php endif; ?>
      </div>

      <?php if(!empty($dashboardData['livestockByType'])): ?>
      <div class="card"><h3 class="card-title">Livestock by Type</h3>
        <div class="stats-grid">
          <?php foreach($dashboardData['livestockByType'] as $item): ?>
          <div class="stat-card"><div class="stat-num"><?= number_format($item['total']) ?></div><div class="stat-lbl"><?= ucfirst($item['type']) ?></div></div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Recent incidents as notifications -->
      <?php if(!empty($incidentsData)): ?>
      <div class="card"><h3 class="card-title">Recent Incidents</h3>
        <?php foreach(array_slice($incidentsData,0,3) as $inc): ?>
        <div class="notif-item">
          <div class="notif-icon" style="background:var(--danger);"><i class="bi bi-exclamation-triangle-fill"></i></div>
          <div style="flex:1;">
            <div style="font-weight:600;margin-bottom:.25rem;"><?= htmlspecialchars($inc['title']) ?></div>
            <div style="font-size:.9rem;color:var(--sub);"><?= date('M d, Y',strtotime($inc['createdAt'])) ?> · <span class="badge badge-<?= strtolower($inc['status']) ?>"><?= $inc['status'] ?></span></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>

    <!-- ═══ FARM REGISTRATION ═══ -->
    <section id="sec-farm-reg" class="page-section">
      <div class="card" style="max-width:600px;margin:0 auto;">
        <h2 class="card-title">Farm Registration</h2>
        <p style="color:var(--sub);margin-bottom:1.5rem;">Complete the details below to register your farm and livestock.</p>

        <!-- Photo capture -->
        <div class="form-group">
          <label class="form-label">Farm Documentation Photo</label>
          <div style="display:flex;gap:.75rem;">
            <button type="button" class="btn btn-secondary" style="flex:1;" onclick="openCam()"><i class="bi bi-camera"></i> Camera</button>
            <button type="button" class="btn btn-secondary" style="flex:1;" onclick="document.getElementById('fileUp').click()"><i class="bi bi-upload"></i> Upload</button>
          </div>
          <input type="file" id="fileUp" accept="image/*" style="display:none;" onchange="handleUpload(event)">
          <div id="photoPreview" class="photo-preview" style="display:none;">
            <canvas id="stampCanvas"></canvas>
            <button class="photo-remove" onclick="removePhoto()">✕</button>
          </div>
          <div id="gpsBadge" class="gps-badge" style="display:none;"><i class="bi bi-geo-alt-fill"></i><span id="gpsText">Getting GPS…</span></div>
        </div>

        <form id="farmForm">
          <input type="hidden" id="fLat" name="farm_lat">
          <input type="hidden" id="fLng" name="farm_lng">
          <input type="hidden" id="fPhoto" name="photo_data">

          <div class="form-group">
            <label class="form-label">Farm Name <span style="color:var(--acc);">*</span></label>
            <input type="text" class="form-input" id="farmName" name="farm_name" placeholder="Enter farm name" required>
          </div>

          <div class="form-group">
            <label class="form-label">Farm Address <span style="color:var(--acc);">*</span></label>
            <textarea class="form-input" id="farmAddr" name="farm_address" rows="2" placeholder="Address (auto-filled from GPS or type manually)" required></textarea>
            <small style="color:var(--sub);font-size:.8rem;margin-top:.25rem;display:block;">📍 Address is auto-filled when you capture a photo with GPS, or you can type manually.</small>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Farm Type <span style="color:var(--acc);">*</span></label>
              <select class="form-input form-select" id="farmType" name="farm_type" required>
                <option value="">Select…</option>
                <option>Cattle</option><option>Swine</option><option>Poultry</option><option>Goat</option><option>Mixed</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Area (ha) <span style="color:var(--acc);">*</span></label>
              <input type="number" class="form-input" id="farmArea" name="farm_area" placeholder="0.00" step="0.01" min="0" required>
            </div>
          </div>

          <div class="card" style="background:#f8fafc;padding:1.25rem;margin-bottom:1.25rem;">
            <h4 style="font-size:.9rem;font-weight:700;color:var(--sub);margin-bottom:.875rem;"><i class="bi bi-clipboard-data"></i> Initial Livestock</h4>
            <div class="form-row">
              <div class="form-group" style="margin-bottom:0;">
                <label class="form-label" style="font-size:.85rem;">Animal Type <span style="color:var(--acc);">*</span></label>
                <select class="form-input form-select" id="lvType" name="livestock_type" required>
                  <option>Cattle</option><option>Swine</option><option>Poultry</option><option>Goat</option>
                </select>
              </div>
              <div class="form-group" style="margin-bottom:0;">
                <label class="form-label" style="font-size:.85rem;">Heads <span style="color:var(--acc);">*</span></label>
                <input type="number" class="form-input" id="lvQty" name="livestock_qty" value="1" min="1" required>
              </div>
            </div>
            <div class="form-group" style="margin-top:.75rem;margin-bottom:0;">
              <label class="form-label" style="font-size:.85rem;">Tag ID / Reference</label>
              <input type="text" class="form-input" id="lvTag" name="livestock_tag" placeholder="e.g. CAT-001 (auto-generated if blank)">
            </div>
          </div>

          <button type="submit" class="btn btn-primary" style="width:100%;padding:1rem;font-size:1rem;justify-content:center;">
            <i class="bi bi-check-lg"></i> Register Farm &amp; Livestock
          </button>
        </form>
      </div>
    </section>

    <!-- ═══ LIVESTOCK MONITORING ═══ -->
    <section id="sec-livestock" class="page-section">
      <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
          <h2 class="card-title" style="margin:0;">Livestock Monitoring</h2>
          <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
            <input type="text" class="form-input" id="lvSearch" placeholder="Search…" style="width:200px;padding:.75rem 1rem;" oninput="filterLivestock(this.value)">
            <select class="form-input form-select" id="lvTypeFilter" style="width:150px;" onchange="filterLivestock(document.getElementById('lvSearch').value)">
              <option value="">All Types</option><option>Cattle</option><option>Swine</option><option>Poultry</option><option>Goat</option>
            </select>
            <button class="btn btn-primary" onclick="openAddLivestock()"><i class="bi bi-plus-lg"></i> Add Livestock</button>
          </div>
        </div>
        <?php if(!empty($livestockData)): ?>
        <div class="table-wrap"><table id="lvTable">
          <thead><tr><th>Tag ID</th><th>Type</th><th>Breed</th><th>Qty / Age</th><th>Weight</th><th>Health</th><th>Vaccination</th><th>Farm</th><th>Actions</th></tr></thead>
          <tbody>
          <?php foreach($livestockData as $lv): ?>
          <tr data-type="<?= strtolower($lv['type']) ?>">
            <td><strong><?= htmlspecialchars($lv['tagId']) ?></strong></td>
            <td><span class="badge badge-approved" style="background:rgba(16,185,129,.1);color:#065f46;"><?= $lv['type'] ?></span></td>
            <td><?= htmlspecialchars($lv['breed']??'—') ?></td>
            <td><?= $lv['qty'] ?> heads<?= $lv['age']?' / '.$lv['age'].' yrs':'' ?></td>
            <td><?= $lv['weight'] ? $lv['weight'].' kg':'—' ?></td>
            <td><span class="badge badge-<?= strtolower($lv['healthStatus']) ?>"><?= $lv['healthStatus'] ?></span></td>
            <td><span class="badge" style="background:#dbeafe;color:#1e40af;"><?= $lv['vaccinationStatus'] ?></span></td>
            <td style="font-size:.85rem;"><?= htmlspecialchars($lv['farmName']) ?></td>
            <td>
              <div style="display:flex;gap:.375rem;">
                <button class="btn btn-secondary" style="padding:.4rem .6rem;font-size:.85rem;" onclick='editLivestock(<?= json_encode($lv) ?>)' title="Edit"><i class="bi bi-pencil"></i></button>
                <button class="btn btn-secondary" style="padding:.4rem .6rem;font-size:.85rem;" onclick='viewLivestock(<?= json_encode($lv) ?>)' title="View"><i class="bi bi-eye"></i></button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <?php else: ?>
        <div class="empty-state"><i class="bi bi-activity"></i><h4>No livestock registered</h4><p>Add livestock to start monitoring</p></div>
        <?php endif; ?>
      </div>
    </section>

    <!-- ═══ INCIDENT REPORTING ═══ -->
    <section id="sec-incident" class="page-section">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
        <!-- Form -->
        <div class="card">
          <h2 class="card-title">File New Incident</h2>
          <div class="form-group">
            <label class="form-label">Capture Location Photo <small style="color:var(--sub);">(auto-fills GPS)</small></label>
            <div style="display:flex;gap:.75rem;">
              <button type="button" class="btn btn-secondary" style="flex:1;" onclick="openIncCam()"><i class="bi bi-camera"></i> Capture</button>
              <button type="button" class="btn btn-secondary" style="flex:1;" onclick="document.getElementById('incFileUp').click()"><i class="bi bi-upload"></i> Upload</button>
            </div>
            <input type="file" id="incFileUp" accept="image/*" style="display:none;" onchange="handleIncUpload(event)">
            <div id="incPhotoPreview" class="photo-preview" style="display:none;">
              <canvas id="incStampCanvas"></canvas>
              <button class="photo-remove" onclick="removeIncPhoto()">✕</button>
            </div>
            <div id="incGpsBadge" class="gps-badge" style="display:none;"><i class="bi bi-geo-alt-fill"></i><span id="incGpsText">Locating…</span></div>
          </div>
          <div id="incident-map"></div>

          <form id="incidentForm" style="margin-top:1rem;">
            <input type="hidden" id="incLat" name="latitude">
            <input type="hidden" id="incLng" name="longitude">
            <input type="hidden" id="incPhoto" name="photo_data">
            <input type="hidden" id="incAddr" name="incident_address">

            <div class="form-group">
              <label class="form-label">Incident Type</label>
              <select class="form-input form-select" name="incident_type">
                <option>Disease Symptoms</option><option>Livestock Death</option><option>Stray Livestock</option><option>Disease Outbreak</option><option>Others</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Title <span style="color:var(--acc);">*</span></label>
              <input type="text" class="form-input" name="title" placeholder="Brief description" required>
            </div>
            <div class="form-group">
              <label class="form-label">Farm</label>
              <select class="form-input form-select" name="farm_id">
                <option value="">— Select Farm (optional) —</option>
                <?php foreach($farmsData as $f): ?>
                <option value="<?= $f['id'] ?>"><?= htmlspecialchars($f['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Description <span style="color:var(--acc);">*</span></label>
              <textarea class="form-input" rows="3" name="description" placeholder="Describe the incident…" required></textarea>
            </div>
            <div id="incLocInfo" style="display:none;" class="gps-badge"><i class="bi bi-map"></i><span id="incLocText">Location captured</span></div>
            <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;"><i class="bi bi-send-fill"></i> Report Incident</button>
          </form>
        </div>

        <!-- My Reports -->
        <div class="card">
          <h3 class="card-title">My Incident Reports</h3>
          <?php if(!empty($incidentsData)): ?>
          <div class="table-wrap"><table>
            <thead><tr><th>Ref</th><th>Date</th><th>Title</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach($incidentsData as $inc): ?>
            <tr>
              <td style="font-size:.8rem;font-weight:700;color:var(--sub);">#<?= $inc['id'] ?></td>
              <td style="font-size:.85rem;"><?= date('M d',strtotime($inc['createdAt'])) ?></td>
              <td><?= htmlspecialchars(substr($inc['title'],0,35)) ?><?= strlen($inc['title'])>35?'…':'' ?></td>
              <td><span class="badge badge-<?= strtolower($inc['status']) ?>"><?= $inc['status'] ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
          <?php else: ?>
          <div class="empty-state"><i class="bi bi-exclamation-triangle"></i><h4>No incidents yet</h4></div>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <!-- ═══ NOTIFICATIONS ═══ -->
    <section id="sec-notifications" class="page-section">
      <div class="card">
        <h2 class="card-title">Notifications</h2>
        <?php if(!empty($incidentsData)): ?>
          <?php foreach($incidentsData as $inc): ?>
          <div class="notif-item">
            <div class="notif-icon" style="background:<?= $inc['status']==='Resolved'?'var(--acc)':'var(--danger)' ?>;"><i class="bi bi-<?= $inc['status']==='Resolved'?'check-circle-fill':'exclamation-triangle-fill' ?>"></i></div>
            <div style="flex:1;">
              <div style="font-weight:600;"><?= htmlspecialchars($inc['title']) ?></div>
              <div style="font-size:.875rem;color:var(--sub);margin-top:.25rem;"><?= date('M d, Y H:i',strtotime($inc['createdAt'])) ?> · <span class="badge badge-<?= strtolower($inc['status']) ?>"><?= $inc['status'] ?></span></div>
            </div>
          </div>
          <?php endforeach; ?>
        <?php else: ?>
        <div class="empty-state"><i class="bi bi-bell-slash"></i><h4>No notifications</h4></div>
        <?php endif; ?>
      </div>
    </section>

    <!-- ═══ E-CERTIFICATE ═══ -->
    <section id="sec-ecert" class="page-section">
      <div class="card">
        <h2 class="card-title"><i class="bi bi-patch-check-fill" style="color:var(--acc);"></i> E-Certificates</h2>
        <?php if(!empty($eCertData)): ?>
        <div class="cert-grid">
          <?php foreach($eCertData as $cert): ?>
          <div class="cert-card">
            <div style="font-size:.8rem;font-weight:700;color:#2c5530;letter-spacing:1px;text-transform:uppercase;margin-bottom:.375rem;">DA-CS-<?= htmlspecialchars($cert['certificate_no']) ?></div>
            <div style="font-size:1.15rem;font-weight:700;color:#1a3c20;margin-bottom:.375rem;"><?= htmlspecialchars($cert['farm_name']) ?></div>
            <div style="font-size:.9rem;color:var(--sub);margin-bottom:.875rem;"><?= htmlspecialchars($cert['recipient_name']??$firstName.' '.$lastName) ?></div>
            <span class="badge badge-active">✅ Active</span>
            <div style="font-size:.85rem;color:var(--sub);margin-top:.875rem;"><i class="bi bi-calendar-check"></i> Valid until <?= date('M d, Y',strtotime($cert['valid_until'])) ?></div>
            <div style="margin-top:1rem;display:flex;gap:.75rem;">
              <a href="download_certificate.php?id=<?= $cert['id'] ?>" class="btn btn-primary" style="flex:1;justify-content:center;"><i class="bi bi-download"></i> Download</a>
              <button class="btn btn-secondary" style="padding:.75rem 1rem;" onclick="window.print()"><i class="bi bi-printer"></i></button>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="request-cert">
          <i class="bi bi-award" style="font-size:4rem;color:#2c5530;display:block;margin-bottom:1.5rem;"></i>
          <h3 style="color:#2c5530;font-size:1.75rem;font-weight:800;margin-bottom:.875rem;">Get Your DA Camarines Sur Certificate</h3>
          <p style="color:#4a7c59;margin-bottom:2rem;">Official accreditation for approved farms. Valid 3 years.</p>
          <?php if($hasApprovedFarms): ?>
          <form method="POST" action="request_certificate.php" style="max-width:420px;margin:0 auto;">
            <input type="hidden" name="user_id" value="<?= $userId ?>">
            <textarea name="request_message" rows="2" class="form-input" placeholder="Optional notes…" style="margin-bottom:1rem;"></textarea>
            <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:1rem;font-size:1.05rem;"><i class="bi bi-file-earmark-check"></i> Generate Certificate</button>
          </form>
          <?php else: ?>
          <p style="color:var(--sub);margin-bottom:1.5rem;">You need at least one <strong>approved farm</strong> to request a certificate.</p>
          <button class="btn btn-primary" style="justify-content:center;" onclick="navTo('farm-reg')"><i class="bi bi-house-add-fill"></i> Register a Farm</button>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </section>

    <!-- ═══ PROFILE ═══ -->
    <section id="sec-profile" class="page-section">
      <div style="max-width:800px;margin:0 auto;">
        <div class="card" style="background:linear-gradient(135deg,#064e3b,#10b981);color:#fff;display:flex;align-items:center;gap:2rem;flex-wrap:wrap;">
          <div style="width:100px;height:100px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:2.5rem;font-weight:800;border:4px solid rgba(255,255,255,.3);flex-shrink:0;">
            <?php if(!empty($profileData['profile_pix'])&&file_exists($profileData['profile_pix'])): ?>
            <img src="<?= htmlspecialchars($profileData['profile_pix']) ?>" style="width:100%;height:100%;border-radius:50%;object-fit:cover;">
            <?php else: ?>
            <?= strtoupper(substr($firstName,0,1).substr($lastName,0,1)) ?>
            <?php endif; ?>
          </div>
          <div style="flex:1;">
            <span style="background:rgba(255,255,255,.2);padding:.3rem .875rem;border-radius:20px;font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;">Farmer</span>
            <h1 style="font-size:2rem;font-weight:800;margin:.5rem 0;"><?= htmlspecialchars($firstName.' '.$lastName) ?></h1>
            <div style="display:flex;gap:2rem;margin-top:1rem;">
              <div><p style="margin:0;opacity:.8;font-size:.85rem;">Farms</p><p style="margin:0;font-size:1.5rem;font-weight:700;"><?= $farmCount ?></p></div>
              <div style="border-left:1px solid rgba(255,255,255,.3);padding-left:2rem;"><p style="margin:0;opacity:.8;font-size:.85rem;">Livestock</p><p style="margin:0;font-size:1.5rem;font-weight:700;"><?= number_format($dashboardData['totalLivestock']) ?></p></div>
            </div>
          </div>
          <button class="btn" style="background:rgba(255,255,255,.2);color:#fff;border:2px solid rgba(255,255,255,.4);" onclick="openProfileModal()"><i class="bi bi-pencil-square"></i> Edit Profile</button>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
          <div class="card"><h3 style="font-size:1.1rem;font-weight:700;margin-bottom:1.25rem;"><i class="bi bi-person-circle" style="color:var(--acc);"></i> Personal Info</h3>
            <div class="info-row"><span class="info-lbl">Email</span><span class="info-val"><?= htmlspecialchars($profileData['email']??'—') ?></span></div>
            <div class="info-row"><span class="info-lbl">Mobile</span><span class="info-val"><?= htmlspecialchars($profileData['mobile']??'—') ?></span></div>
            <div class="info-row"><span class="info-lbl">Gender</span><span class="info-val"><?= ucfirst($profileData['gender']??'—') ?></span></div>
            <div class="info-row"><span class="info-lbl">Birthday</span><span class="info-val"><?= $profileData['dob']??'—' ?></span></div>
          </div>
          <div class="card"><h3 style="font-size:1.1rem;font-weight:700;margin-bottom:1.25rem;"><i class="bi bi-geo-alt" style="color:#3b82f6;"></i> Location &amp; Emergency</h3>
            <div class="info-row"><span class="info-lbl">Address</span><span class="info-val" style="max-width:200px;text-align:right;font-size:.9rem;"><?= htmlspecialchars($profileData['address']??'—') ?></span></div>
            <div class="info-row"><span class="info-lbl">Gov't ID</span><span class="info-val"><?= htmlspecialchars($profileData['gov_id']??'—') ?></span></div>
            <div class="info-row"><span class="info-lbl">Emergency</span><span class="info-val" style="color:var(--danger);"><?= htmlspecialchars($profileData['emergency_contact']??'—') ?></span></div>
          </div>
        </div>
      </div>
    </section>

  </main><!-- /content -->
</div><!-- /main-wrap -->

<!-- ═══ MODALS ═══ -->

<!-- Profile Edit Modal -->
<div id="profileModal" class="modal-backdrop">
  <div class="modal-box">
    <div class="modal-hdr"><h3><i class="bi bi-person-gear"></i> Edit Profile</h3><button class="modal-close" onclick="closeModal('profileModal')">×</button></div>
    <form id="profileForm" method="POST" enctype="multipart/form-data">
      <input type="hidden" name="update_profile" value="1">
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group"><label class="form-label">Email</label><input type="email" class="form-input" name="email" value="<?= htmlspecialchars($profileData['email']??'') ?>"></div>
          <div class="form-group"><label class="form-label">Mobile</label><input type="tel" class="form-input" name="mobile" value="<?= htmlspecialchars($profileData['mobile']??'') ?>" placeholder="+639…"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Gender</label>
            <select class="form-input form-select" name="gender">
              <option value="">Select</option>
              <option value="Male" <?= ($profileData['gender']??'')==='Male'?'selected':'' ?>>Male</option>
              <option value="Female" <?= ($profileData['gender']??'')==='Female'?'selected':'' ?>>Female</option>
              <option value="Other" <?= ($profileData['gender']??'')==='Other'?'selected':'' ?>>Other</option>
            </select>
          </div>
          <div class="form-group"><label class="form-label">Date of Birth</label><input type="date" class="form-input" name="dob" value="<?= htmlspecialchars($profileData['dob']??'') ?>"></div>
        </div>
        <div class="form-group"><label class="form-label">Home Address</label><textarea class="form-input" name="address" rows="2"><?= htmlspecialchars($profileData['address']??'') ?></textarea></div>
        <div class="form-group"><label class="form-label">Emergency Contact</label><input type="text" class="form-input" name="emergency_contact" value="<?= htmlspecialchars($profileData['emergency_contact']??'') ?>" placeholder="Name & phone number"></div>
        <div class="form-group"><label class="form-label">Profile Photo</label>
          <input type="file" class="form-input" name="profile_pix" accept="image/*" style="padding:.5rem;">
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('profileModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save</button></div>
    </form>
  </div>
</div>

<!-- Livestock Edit Modal -->
<div id="lvEditModal" class="modal-backdrop">
  <div class="modal-box">
    <div class="modal-hdr"><h3 id="lvModalTitle">Edit Livestock</h3><button class="modal-close" onclick="closeModal('lvEditModal')">×</button></div>
    <form id="lvEditForm">
      <input type="hidden" id="lvEditId" name="livestock_id">
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group"><label class="form-label">Tag ID</label><input type="text" class="form-input" id="lvEditTag" name="tagId" required></div>
          <div class="form-group"><label class="form-label">Type</label>
            <select class="form-input form-select" id="lvEditType" name="type">
              <option>Cattle</option><option>Swine</option><option>Poultry</option><option>Goat</option>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Breed</label><input type="text" class="form-input" id="lvEditBreed" name="breed"></div>
          <div class="form-group"><label class="form-label">Quantity</label><input type="number" class="form-input" id="lvEditQty" name="qty" min="1"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Age (years)</label><input type="number" class="form-input" id="lvEditAge" name="age" min="0"></div>
          <div class="form-group"><label class="form-label">Weight (kg)</label><input type="number" class="form-input" id="lvEditWeight" name="weight" step="0.1"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Health Status</label>
            <select class="form-input form-select" id="lvEditHealth" name="healthStatus">
              <option>Healthy</option><option>Sick</option><option>Recovered</option><option>Deceased</option>
            </select>
          </div>
          <div class="form-group"><label class="form-label">Vaccination</label>
            <select class="form-input form-select" id="lvEditVacc" name="vaccinationStatus">
              <option>None</option><option>Partial</option><option>Complete</option>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('lvEditModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save</button></div>
    </form>
  </div>
</div>

<!-- View Livestock Modal -->
<div id="lvViewModal" class="modal-backdrop">
  <div class="modal-box">
    <div class="modal-hdr"><h3>Livestock Details</h3><button class="modal-close" onclick="closeModal('lvViewModal')">×</button></div>
    <div class="modal-body" id="lvViewBody"></div>
    <div class="modal-footer"><button class="btn btn-secondary" onclick="closeModal('lvViewModal')">Close</button></div>
  </div>
</div>

<!-- Add Livestock Modal -->
<div id="lvAddModal" class="modal-backdrop">
  <div class="modal-box">
    <div class="modal-hdr"><h3><i class="bi bi-plus-circle"></i> Add Livestock</h3><button class="modal-close" onclick="closeModal('lvAddModal')">×</button></div>
    <form id="lvAddForm">
      <div class="modal-body">
        <div class="form-group"><label class="form-label">Farm <span style="color:var(--acc);">*</span></label>
          <select class="form-input form-select" name="farm_id" required>
            <?php foreach($farmsData as $f): ?><option value="<?= $f['id'] ?>"><?= htmlspecialchars($f['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Tag ID <span style="color:var(--acc);">*</span></label><input type="text" class="form-input" name="tagId" required></div>
          <div class="form-group"><label class="form-label">Type <span style="color:var(--acc);">*</span></label>
            <select class="form-input form-select" name="type"><option>Cattle</option><option>Swine</option><option>Poultry</option><option>Goat</option></select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Breed</label><input type="text" class="form-input" name="breed"></div>
          <div class="form-group"><label class="form-label">Quantity</label><input type="number" class="form-input" name="qty" value="1" min="1"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Age (yrs)</label><input type="number" class="form-input" name="age" min="0"></div>
          <div class="form-group"><label class="form-label">Weight (kg)</label><input type="number" class="form-input" name="weight" step="0.1"></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('lvAddModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Add</button></div>
    </form>
  </div>
</div>

<!-- Rejection Modal -->
<div id="rejModal" class="modal-backdrop">
  <div class="modal-box" style="max-width:460px;">
    <div class="modal-hdr" style="background:#fee2e2;"><h3 style="color:#991b1b;"><i class="bi bi-x-circle"></i> Rejection Reason</h3><button class="modal-close" onclick="closeModal('rejModal')">×</button></div>
    <div class="modal-body"><div id="rejText" style="padding:1rem;background:#fef2f2;border-radius:.75rem;border-left:4px solid var(--danger);white-space:pre-wrap;line-height:1.6;"></div>
      <div style="margin-top:1.25rem;display:flex;gap:.75rem;">
        <button class="btn btn-primary" style="flex:1;justify-content:center;" onclick="navTo('farm-reg');closeModal('rejModal')"><i class="bi bi-arrow-repeat"></i> Appeal / Re-apply</button>
        <button class="btn btn-secondary" onclick="closeModal('rejModal')">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Camera Modal -->
<div id="camModal" class="modal-backdrop">
  <div class="modal-box" style="max-width:480px;">
    <div class="modal-hdr"><h3><i class="bi bi-camera"></i> <span id="camTitle">Capture Farm Photo</span></h3><button class="modal-close" onclick="closeCam()">×</button></div>
    <div style="background:#000;position:relative;">
      <video id="camVideo" autoplay playsinline style="width:100%;max-height:400px;display:block;object-fit:cover;"></video>
      <div id="camGpsStatus" style="position:absolute;top:12px;left:12px;background:rgba(0,0,0,.7);color:#fff;padding:.5rem 1rem;border-radius:20px;font-size:.85rem;font-weight:600;">Getting GPS…</div>
    </div>
    <div style="padding:1rem;display:flex;gap:1rem;justify-content:center;background:#f8fafc;">
      <button class="btn btn-secondary" style="width:3rem;height:3rem;padding:0;justify-content:center;border-radius:50%;" onclick="switchCam()" title="Flip"><i class="bi bi-arrow-repeat" style="font-size:1.2rem;"></i></button>
      <button class="btn btn-primary" style="width:4rem;height:4rem;padding:0;justify-content:center;border-radius:50%;" onclick="capturePhoto()" title="Capture"><i class="bi bi-camera-fill" style="font-size:1.4rem;"></i></button>
      <button class="btn btn-secondary" style="width:3rem;height:3rem;padding:0;justify-content:center;border-radius:50%;" onclick="closeCam()" title="Close"><i class="bi bi-x-lg" style="font-size:1.2rem;"></i></button>
    </div>
  </div>
</div>

<!-- Toast -->
<div id="toast" class="toast"></div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
// ── Navigation ────────────────────────────────────────────────────────────────
function navTo(page) {
    document.querySelectorAll('.page-section').forEach(s => s.classList.remove('active'));
    document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
    const sec = document.getElementById('sec-'+page);
    if(sec) sec.classList.add('active');
    const nav = document.querySelector(`.nav-item[data-page="${page}"]`);
    if(nav) { nav.classList.add('active'); document.getElementById('pageTitle').textContent = nav.textContent.replace(/\d+/g,'').trim(); }
    closeSidebar();
    if(page==='incident' && !incMap) setTimeout(initIncMap, 300);
}

document.querySelectorAll('.nav-item[data-page]').forEach(n => {
    n.addEventListener('click', () => navTo(n.dataset.page));
});
document.getElementById('menuBtn').addEventListener('click', toggleSidebar);
document.getElementById('overlay').addEventListener('click', closeSidebar);

function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('active');
    document.getElementById('overlay').classList.toggle('show');
}
function closeSidebar() {
    document.getElementById('sidebar').classList.remove('active');
    document.getElementById('overlay').classList.remove('show');
}

// ── Modals ────────────────────────────────────────────────────────────────────
function openModal(id) { document.getElementById(id).classList.add('open'); document.body.style.overflow='hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('open'); document.body.style.overflow=''; }
function openProfileModal() { openModal('profileModal'); }

document.addEventListener('click', e => {
    if(e.target.classList.contains('modal-backdrop')) closeModal(e.target.id);
});
document.addEventListener('keydown', e => { if(e.key==='Escape') document.querySelectorAll('.modal-backdrop.open').forEach(m => closeModal(m.id)); });

// ── Toast ─────────────────────────────────────────────────────────────────────
function showToast(msg, type='success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast toast-'+type+' show';
    setTimeout(() => t.classList.remove('show'), 3500);
}

// ── GPS ───────────────────────────────────────────────────────────────────────
let currentGPS = null;
function getGPS() {
    return new Promise(resolve => {
        if(!navigator.geolocation) { resolve({lat:13.6234,lng:123.1945,acc:999}); return; }
        navigator.geolocation.getCurrentPosition(
            p => { currentGPS={lat:p.coords.latitude,lng:p.coords.longitude,acc:p.coords.accuracy}; resolve(currentGPS); },
            () => resolve({lat:13.6234,lng:123.1945,acc:999}),
            {enableHighAccuracy:true,timeout:12000,maximumAge:60000}
        );
    });
}

async function reverseGeocode(lat,lng) {
    try {
        const r = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1&accept-language=en`);
        const d = await r.json();
        return d.display_name || `Lat: ${lat.toFixed(6)}, Lng: ${lng.toFixed(6)}`;
    } catch { return `Lat: ${lat.toFixed(6)}, Lng: ${lng.toFixed(6)}`; }
}

// ── GPS Stamp ─────────────────────────────────────────────────────────────────
async function stampGPS(canvas, gps) {
    const ctx = canvas.getContext('2d');
    const ts = new Date().toLocaleString('en-PH',{timeZone:'Asia/Manila',year:'numeric',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});
    const h = Math.floor(canvas.height * 0.18);
    ctx.fillStyle = 'rgba(0,0,0,0.82)';
    ctx.fillRect(0, canvas.height-h, canvas.width, h);
    ctx.fillStyle = '#fff';
    const fs = Math.max(14, Math.floor(canvas.width/40));
    ctx.font = `bold ${fs}px sans-serif`;
    ctx.shadowColor='rgba(0,0,0,.7)'; ctx.shadowBlur=3;
    ctx.fillText(`📍 ${gps.lat.toFixed(6)}, ${gps.lng.toFixed(6)}`, 16, canvas.height-h+fs+8);
    ctx.fillText(`⏰ ${ts}`, 16, canvas.height-h+fs*2+14);
    ctx.font = `${Math.floor(fs*.85)}px sans-serif`;
    ctx.fillText(`AgriTrace+ | Accuracy: ±${Math.round(gps.acc)}m`, 16, canvas.height-h+fs*3+18);
    ctx.shadowBlur=0;
}

// ── Camera system (shared) ────────────────────────────────────────────────────
let stream = null, camFacing = 'environment', camMode = 'farm';
let incGPS = null;

function openCam(mode='farm') {
    camMode = mode;
    document.getElementById('camTitle').textContent = mode==='farm' ? 'Capture Farm Photo' : 'Capture Incident Photo';
    startCam();
}
function openIncCam() { openCam('incident'); }

async function startCam() {
    stopCam();
    try {
        stream = await navigator.mediaDevices.getUserMedia({video:{facingMode:camFacing,width:{ideal:1280},height:{ideal:720}}});
        document.getElementById('camVideo').srcObject = stream;
        openModal('camModal');
        getGPS().then(g => {
            document.getElementById('camGpsStatus').textContent = `GPS: ${g.lat.toFixed(4)}, ${g.lng.toFixed(4)}`;
        });
    } catch(e) { showToast('Camera access denied. Please use Upload instead.','error'); }
}
function switchCam() { camFacing = camFacing==='environment'?'user':'environment'; startCam(); }
function stopCam() { if(stream){ stream.getTracks().forEach(t=>t.stop()); stream=null; } }
function closeCam() { stopCam(); closeModal('camModal'); }

async function capturePhoto() {
    const video = document.getElementById('camVideo');
    const canvas = document.createElement('canvas');
    canvas.width = video.videoWidth || 640;
    canvas.height = video.videoHeight || 480;
    canvas.getContext('2d').drawImage(video,0,0);
    const gps = await getGPS();
    await stampGPS(canvas, gps);
    if(camMode==='farm') processFarmPhoto(canvas, gps);
    else processIncPhoto(canvas, gps);
    closeCam();
}

async function handleUpload(e) {
    const file = e.target.files[0]; if(!file) return;
    const img = new Image();
    img.onload = async () => {
        const canvas = document.createElement('canvas');
        canvas.width = img.width; canvas.height = img.height;
        canvas.getContext('2d').drawImage(img,0,0);
        const gps = await getGPS();
        await stampGPS(canvas, gps);
        processFarmPhoto(canvas, gps);
    };
    img.src = URL.createObjectURL(file);
}

async function handleIncUpload(e) {
    const file = e.target.files[0]; if(!file) return;
    const img = new Image();
    img.onload = async () => {
        const canvas = document.createElement('canvas');
        canvas.width = img.width; canvas.height = img.height;
        canvas.getContext('2d').drawImage(img,0,0);
        const gps = await getGPS();
        await stampGPS(canvas, gps);
        processIncPhoto(canvas, gps);
    };
    img.src = URL.createObjectURL(file);
}

async function processFarmPhoto(canvas, gps) {
    const dest = document.getElementById('stampCanvas');
    dest.width = 600;
    dest.height = Math.round(canvas.height * (600/canvas.width));
    dest.getContext('2d').drawImage(canvas,0,0,600,dest.height);
    document.getElementById('photoPreview').style.display='block';
    document.getElementById('fPhoto').value = canvas.toDataURL('image/jpeg',.9);
    document.getElementById('fLat').value = gps.lat;
    document.getElementById('fLng').value = gps.lng;
    document.getElementById('gpsBadge').style.display='flex';
    document.getElementById('gpsText').textContent = `Lat: ${gps.lat.toFixed(6)}, Lng: ${gps.lng.toFixed(6)}`;
    // Auto-fill address
    const addr = await reverseGeocode(gps.lat, gps.lng);
    document.getElementById('farmAddr').value = addr;
    showToast('📍 Photo & GPS captured!','success');
}

async function processIncPhoto(canvas, gps) {
    incGPS = gps;
    const dest = document.getElementById('incStampCanvas');
    dest.width = 600;
    dest.height = Math.round(canvas.height * (600/canvas.width));
    dest.getContext('2d').drawImage(canvas,0,0,600,dest.height);
    document.getElementById('incPhotoPreview').style.display='block';
    document.getElementById('incPhoto').value = canvas.toDataURL('image/jpeg',.9);
    document.getElementById('incLat').value = gps.lat;
    document.getElementById('incLng').value = gps.lng;
    document.getElementById('incGpsBadge').style.display='flex';
    document.getElementById('incGpsText').textContent = `Lat: ${gps.lat.toFixed(6)}, Lng: ${gps.lng.toFixed(6)}`;
    // Address
    const addr = await reverseGeocode(gps.lat, gps.lng);
    document.getElementById('incAddr').value = addr;
    document.getElementById('incLocInfo').style.display='flex';
    document.getElementById('incLocText').textContent = addr;
    // Update map
    if(incMap) { incMap.setView([gps.lat,gps.lng],14); if(incMarker) incMarker.setLatLng([gps.lat,gps.lng]); else { incMarker=L.marker([gps.lat,gps.lng]).addTo(incMap).bindPopup('Incident location').openPopup(); } }
    showToast('📍 Incident location captured!','success');
}

function removePhoto() {
    document.getElementById('photoPreview').style.display='none';
    document.getElementById('gpsBadge').style.display='none';
    document.getElementById('fPhoto').value='';
    document.getElementById('fLat').value='';
    document.getElementById('fLng').value='';
}
function removeIncPhoto() {
    document.getElementById('incPhotoPreview').style.display='none';
    document.getElementById('incGpsBadge').style.display='none';
    document.getElementById('incPhoto').value='';
    document.getElementById('incLat').value='';
    document.getElementById('incLng').value='';
    incGPS=null;
}

// ── Incident Map ──────────────────────────────────────────────────────────────
let incMap=null, incMarker=null;
function initIncMap() {
    if(incMap) return;
    incMap = L.map('incident-map').setView([13.6234,123.1945],10);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OpenStreetMap'}).addTo(incMap);
    // Click to set location
    incMap.on('click', async e => {
        const {lat,lng} = e.latlng;
        document.getElementById('incLat').value = lat;
        document.getElementById('incLng').value = lng;
        if(incMarker) incMarker.setLatLng([lat,lng]); else incMarker=L.marker([lat,lng]).addTo(incMap).bindPopup('Incident here').openPopup();
        const addr = await reverseGeocode(lat,lng);
        document.getElementById('incAddr').value = addr;
        document.getElementById('incLocInfo').style.display='flex';
        document.getElementById('incLocText').textContent = addr;
        showToast('📍 Location set from map click','success');
    });
}

// ── Farm Form ─────────────────────────────────────────────────────────────────
document.getElementById('farmForm').addEventListener('submit', async e => {
    e.preventDefault();
    const btn = e.target.querySelector('[type=submit]');
    btn.disabled=true; btn.innerHTML='<i class="bi bi-hourglass-split"></i> Registering…';
    const fd = new FormData(e.target);
    fd.append('action','register_farm');
    try {
        const r = await fetch('', {method:'POST',body:fd});
        const d = await r.json();
        if(d.success) {
            showToast(d.message,'success');
            e.target.reset();
            removePhoto();
            setTimeout(()=>location.reload(),1800);
        } else showToast(d.message||'Error','error');
    } catch { showToast('Network error','error'); }
    btn.disabled=false; btn.innerHTML='<i class="bi bi-check-lg"></i> Register Farm & Livestock';
});

// ── Incident Form ─────────────────────────────────────────────────────────────
document.getElementById('incidentForm').addEventListener('submit', async e => {
    e.preventDefault();
    const btn = e.target.querySelector('[type=submit]');
    btn.disabled=true; btn.innerHTML='<i class="bi bi-hourglass-split"></i> Submitting…';
    const fd = new FormData(e.target);
    fd.append('action','report_incident');
    try {
        const r = await fetch('', {method:'POST',body:fd});
        const d = await r.json();
        if(d.success) {
            showToast('Incident reported successfully!','success');
            e.target.reset();
            removeIncPhoto();
            setTimeout(()=>location.reload(),1800);
        } else showToast(d.message||'Error','error');
    } catch { showToast('Network error','error'); }
    btn.disabled=false; btn.innerHTML='<i class="bi bi-send-fill"></i> Report Incident';
});

// ── Profile Form ──────────────────────────────────────────────────────────────
document.getElementById('profileForm').addEventListener('submit', async e => {
    e.preventDefault();
    const btn = e.target.querySelector('[type=submit]');
    btn.disabled=true;
    const fd = new FormData(e.target);
    try {
        const r = await fetch('', {method:'POST',body:fd});
        const d = await r.json();
        if(d.success) { showToast('Profile updated!','success'); closeModal('profileModal'); setTimeout(()=>location.reload(),1200); }
        else showToast(d.message||'Error','error');
    } catch { showToast('Network error','error'); }
    btn.disabled=false;
});

// ── Livestock ─────────────────────────────────────────────────────────────────
function filterLivestock(q) {
    const type = document.getElementById('lvTypeFilter').value.toLowerCase();
    document.querySelectorAll('#lvTable tbody tr').forEach(row => {
        const txt = row.textContent.toLowerCase();
        const rowType = row.dataset.type||'';
        const matchQ = !q||txt.includes(q.toLowerCase());
        const matchT = !type||rowType===type;
        row.style.display = (matchQ&&matchT)?'':'none';
    });
}

function editLivestock(lv) {
    document.getElementById('lvModalTitle').textContent = 'Edit Livestock #'+lv.id;
    document.getElementById('lvEditId').value   = lv.id;
    document.getElementById('lvEditTag').value   = lv.tagId;
    document.getElementById('lvEditType').value  = lv.type;
    document.getElementById('lvEditBreed').value = lv.breed||'';
    document.getElementById('lvEditQty').value   = lv.qty;
    document.getElementById('lvEditAge').value   = lv.age||'';
    document.getElementById('lvEditWeight').value= lv.weight||'';
    document.getElementById('lvEditHealth').value= lv.healthStatus;
    document.getElementById('lvEditVacc').value  = lv.vaccinationStatus;
    openModal('lvEditModal');
}

function viewLivestock(lv) {
    document.getElementById('lvViewBody').innerHTML = `
        <div class="info-row"><span class="info-lbl">Tag ID</span><span class="info-val">${lv.tagId}</span></div>
        <div class="info-row"><span class="info-lbl">Type</span><span class="info-val">${lv.type}</span></div>
        <div class="info-row"><span class="info-lbl">Breed</span><span class="info-val">${lv.breed||'—'}</span></div>
        <div class="info-row"><span class="info-lbl">Quantity</span><span class="info-val">${lv.qty} heads</span></div>
        <div class="info-row"><span class="info-lbl">Age</span><span class="info-val">${lv.age?lv.age+' yrs':'—'}</span></div>
        <div class="info-row"><span class="info-lbl">Weight</span><span class="info-val">${lv.weight?lv.weight+' kg':'—'}</span></div>
        <div class="info-row"><span class="info-lbl">Health</span><span class="info-val"><span class="badge badge-${lv.healthStatus.toLowerCase()}">${lv.healthStatus}</span></span></div>
        <div class="info-row"><span class="info-lbl">Vaccination</span><span class="info-val">${lv.vaccinationStatus}</span></div>
        <div class="info-row"><span class="info-lbl">Farm</span><span class="info-val">${lv.farmName}</span></div>
        <div class="info-row"><span class="info-lbl">Added</span><span class="info-val">${new Date(lv.createdAt).toLocaleDateString()}</span></div>`;
    openModal('lvViewModal');
}

document.getElementById('lvEditForm').addEventListener('submit', async e => {
    e.preventDefault();
    const btn=e.target.querySelector('[type=submit]'); btn.disabled=true;
    const fd=new FormData(e.target); fd.append('action','update_livestock');
    try {
        const r=await fetch('',{method:'POST',body:fd});
        const d=await r.json();
        if(d.success){showToast('Livestock updated!','success');closeModal('lvEditModal');setTimeout(()=>location.reload(),1000);}
        else showToast(d.message,'error');
    } catch{showToast('Network error','error');}
    btn.disabled=false;
});

function openAddLivestock() { openModal('lvAddModal'); }
document.getElementById('lvAddForm').addEventListener('submit', async e => {
    e.preventDefault();
    const btn=e.target.querySelector('[type=submit]'); btn.disabled=true;
    const fd=new FormData(e.target); fd.append('action','add_livestock');
    try {
        const r=await fetch('',{method:'POST',body:fd});
        const d=await r.json();
        if(d.success){showToast('Livestock added!','success');closeModal('lvAddModal');setTimeout(()=>location.reload(),1000);}
        else showToast(d.message,'error');
    } catch{showToast('Network error','error');}
    btn.disabled=false;
});

// ── Rejection & Appeal ────────────────────────────────────────────────────────
function showRejection(reason) {
    document.getElementById('rejText').textContent = reason;
    openModal('rejModal');
}
async function appealFarm(id) {
    if(!confirm('Submit an appeal for this farm?')) return;
    try {
        const fd=new FormData(); fd.append('action','appeal_farm'); fd.append('farm_id',id);
        const r=await fetch('api/appeal-farm.php',{method:'POST',body:fd});
        const d=await r.json();
        if(d.success){showToast('Appeal submitted!','success');setTimeout(()=>location.reload(),1500);}
        else showToast(d.message||'Error','error');
    } catch {showToast('Appeal submitted (offline mode)','warn');}
}

// Init map for incident section when first opened
document.querySelector('.nav-item[data-page="incident"]').addEventListener('click', ()=>{
    setTimeout(initIncMap,400);
});
</script>
</body>
</html>