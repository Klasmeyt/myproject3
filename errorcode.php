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

// All farms for inspection - FIXED: Using PDO consistently
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
$user_profile['mobile'] = $user_profile['mobile'] ?? ''; 

// Update user info in sidebar
$user_name = $user_profile ? ($user_profile['firstName'] . ' ' . substr($user_profile['lastName'], 0, 1)) : 'G';
$user_initial = strtoupper(substr($user_name, 0, 1));
$user_fullname = $user_profile['firstName'] ?? 'Guest User';
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
    /* [Previous CSS styles remain exactly the same - keeping them unchanged for brevity] */
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
    .main-content { padding: 24px; margin-top: var(--topbar-height); transition: 0.3s; }
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
    
    @media (min-width: 1024px) {
        .panel-sidebar { left: 0; }
        .main-content { margin-left: var(--sidebar-width); }
        .menu-btn { display: none; }
        .sidebar-overlay { display: none !important; }
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
      
      <div class="nav-item logout" onclick="window.location.href='logout.php'">
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
              <th>Address</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($all_farms)): ?>
            <?php foreach ($all_farms as $farm): ?>
              <tr>
                <td><?php echo htmlspecialchars($farm['name'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars(($farm['firstName'] ?? '') . ' ' . ($farm['lastName'] ?? '')); ?></td>
                <td><?php echo ucfirst(str_replace('_', ' ', $farm['type'] ?? '')); ?> Farm</td>
                <td>
                  <span class="status-badge status-<?php echo strtolower($farm['status'] ?? 'pending'); ?>">
                    <?php echo ucfirst($farm['status'] ?? 'Pending'); ?>
                  </span>
                </td>
                <td><?php echo number_format($farm['total_livestock'] ?? 0); ?> heads</td>
                <td><?php echo htmlspecialchars(substr($farm['address'] ?? 'N/A', 0, 50)) . (strlen($farm['address'] ?? '')> 50 ? '...' : ''); ?></td>
                <td>
                  <?php if (($farm['status'] ?? '') === 'Pending'): ?>
                  <a href="#" class="btn btn-primary" onclick="approveFarm(<?php echo (int)$farm['id']; ?>)">Approve</a>
                  <a href="#" class="btn btn-danger" style="margin-left:8px;" onclick="rejectFarm(<?php echo (int)$farm['id']; ?>)">Reject</a>
                  <?php else: ?>
                  <span style="color:var(--c-text-sub);">No action needed</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="7" style="text-align: center; padding: 40px; color: var(--c-text-sub);">
                  No farms found. Farms will appear here once farmers register them.
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

<script>
// ✅ FIXED: JavaScript functions for approve/reject with proper error handling
function approveFarm(farmId){
  if (confirm('Approve this farm?')) {
    fetch('api/approve_farm.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({farmId: farmId})
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        location.reload();
      } else {
        alert('Error approving farm: + (data.message || 'Unknown error'));
      }
    })
    .catch(error => {
      console.error('Error:', error);
      alert('Network error. Please try again.');
    });
  }
}

function rejectFarm(farmId) {
  const reason = prompt('Reason for rejection:');
  if (reason && confirm('Reject this farm?')) {
    fetch('api/reject_farm.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
            body: JSON.stringify({farmId: farmId, reason: reason})
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        location.reload();
      } else {
        alert('Error rejecting farm: ' + (data.message || 'Unknown error'));
      }
    })
    .catch(error => {
      console.error('Error:', error);
      alert('Network error. Please try again.');
    });
  }
}

function searchFarms(query) {
  const rows = document.querySelectorAll('#farmsTable tbody tr');
  rows.forEach(row => {
    const text = row.textContent.toLowerCase();
    row.style.display = text.includes(query.toLowerCase()) ? '' : 'none';
  });
}
</script>

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
        
        <div style="display: flex; gap: 12px; margin-top: 12px;">
          <!-- ✅ FIXED: Opens REAL maps/farm-map.php with LIVE data -->
          <button onclick="openFullscreenMap(event)" class="btn-main" style="padding: 10px 16px; font-size: 0.9rem;" title="Open Fullscreen Interactive Farm Map">
            <i class="bi bi-box-arrow-up-right"></i> Fullscreen Map
          </button>
          <button onclick="fitBounds()" class="btn-secondary" style="padding: 10px 16px; font-size: 0.9rem;">
            <i class="bi bi-geo-alt"></i> Fit Philippines
          </button>
                    <button onclick="refreshMapData()" class="btn-secondary" style="padding: 10px 16px; font-size: 0.9rem;">
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

    <!-- REPORTS & ANALYTICS -->
    <section id="sec-reports" class="section">
      <div class="stats-grid">
        <div class="card">
          <span class="stat-val">Generate Reports</span>
          <div style="margin-top:16px;">
            <a href="#" class="btn btn-primary" style="margin-right:8px;">Farm Report PDF</a>
            <a href="#" class="btn btn-primary">Incident Excel</a>
          </div>
        </div>
        <div class="card">
          <span class="stat-val">Analytics</span>
          <div style="margin-top:16px;">
            <a href="#" class="btn btn-secondary">Farm Trends</a><br>
            <a href="#" class="btn btn-secondary" style="margin-top:8px;">Incident Patterns</a>
          </div>
        </div>
      </div>
    </section>

    <!-- PROFILE -->
    <section id="sec-profile" class="section">
      <div class="profile-form">
        <h2 style="margin-bottom:32px; color:var(--c-brand-dark);">👤 My Profile</h2>
        
        <form id="profileForm" onsubmit="updateProfile(event)">
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Full Name</label>
              <input type="text" class="form-input" value="<?php echo htmlspecialchars(($user_profile['firstName'] ?? '') . ' ' . ($user_profile['lastName'] ?? '')); ?>" readonly>
            </div>
            <div class="form-group">
              <label class="form-label">Email</label>
              <input type="email" class="form-input" value="<?php echo htmlspecialchars($user_profile['email'] ?? ''); ?>" readonly>
            </div>
          </div>
          
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Mobile</label>
                            <input type="tel" class="form-input" id="mobile" name="mobile" value="<?php echo htmlspecialchars($user_profile['mobile'] ?? ''); ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Gov't/Employee ID</label>
              <input type="text" class="form-input" id="gov_id" name="gov_id" value="<?php echo htmlspecialchars($user_profile['gov_id'] ?? ''); ?>">
            </div>
          </div>
          
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Department</label>
              <input type="text" class="form-input" id="department" name="department" value="<?php echo htmlspecialchars($user_profile['department'] ?? ''); ?>">
            </div>
                        <div class="form-group">
              <label class="form-label">Position</label>
              <input type="text" class="form-input" id="position" name="position" value="<?php echo htmlspecialchars($user_profile['position'] ?? ''); ?>">
            </div>
          </div>
          
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Assigned Region</label>
              <input type="text" class="form-input" id="assigned_region" name="assigned_region" value="<?php echo htmlspecialchars($user_profile['assigned_region'] ?? ''); ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Office</label>
              <input type="text" class="form-input" id="office" name="office" value="<?php echo htmlspecialchars($user_profile['office'] ?? ''); ?>">
            </div>
          </div>
          
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Municipality</label>
              <input type="text" class="form-input" id="municipality" name="municipality" value="<?php echo htmlspecialchars($user_profile['municipality'] ?? ''); ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Province</label>
              <input type="text" class="form-input" id="province" name="province" value="<?php echo htmlspecialchars($user_profile['province'] ?? ''); ?>">
            </div>
          </div>
          
          <div style="text-align:right;">
            <button type="submit" class="btn btn-primary" style="padding:12px 32px; font-size:1rem;">Update Profile</button>
          </div>
        </form>
      </div>
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

    // Map functions
    function initMap() {
      if (map) return;
      
      map = L.map('map').setView([12.8797, 121.7740], 6);
      
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors'
      }).addTo(map);
      
      loadFarmData();
    }

    function loadFarmData() {
      const farms = <?php echo json_encode($all_farms); ?>;
      
      farmsLayer = L.layerGroup().clearLayers();
      farms.forEach(farm => {
        if (farm.latitude && farm.longitude) {
          const marker = L.marker([parseFloat(farm.latitude), parseFloat(farm.longitude)])
            .bindPopup(`
              <div style="min-width: 200px;">
                <h4 style="margin: 0 0 8px 0; color: var(--c-brand-dark);">${farm.name || 'Unnamed'}</h4>
                <p><strong>Owner:</strong> ${farm.firstName || ''} ${farm.lastName || ''}</p>
                <p><strong>Livestock:</strong> ${farm.total_livestock || 0} heads</p>
                <p><strong>Status:</strong> <span style="color: var(--c-success);">${farm.status || 'Unknown'}</span></p>
              </div>
            `);
          farmsLayer.addLayer(marker);
        }
      });
      if (farmsLayer) farmsLayer.addTo(map);
    }

    function toggleLayer(layerType, event) {
      // Toggle layer visibility
      document.querySelectorAll('.toggle-btn').forEach(btn => btn.classList.remove('active'));
      event.target.classList.add('active');
      currentLayer = layerType;
      
      if (layerType === 'farms') {
        loadFarmData();
      }
      // Add other layer logic here
    }

    function fitBounds() {
      if (map) map.fitBounds([[4.5, 116.5], [21.5, 127.0]]);
    }

    function refreshMapData() {
      document.getElementById('lastUpdate').textContent = new Date().toLocaleTimeString();
      loadFarmData();
    }

    // Incident resolution function
    function resolveIncident(incidentId) {
      if (confirm('Mark this incident as resolved?')) {
        fetch('api/resolve_incident.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({incidentId: incidentId})
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            location.reload();
          } else {
            alert('Error resolving incident: ' + (data.message || 'Unknown error'));
          }
        })
        .catch(error => {
          console.error('Error:', error);
          alert('Network error. Please try again.');
        });
      }
    }

    function openFullscreenMap() {
  // Optional: Show loading state
  const btn = event.target.closest('.btn-main');
  const originalText = btn.innerHTML;
  btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Loading...';
  btn.disabled = true;
  
  // Open map in new tab
  window.open('maps/farm-map.php', '_blank', 'noopener,noreferrer');
  
  // Reset button after 2 seconds
  setTimeout(() => {
    btn.innerHTML = originalText;
    btn.disabled = false;
  }, 2000);
}

    // Profile update function
    function updateProfile(event) {
      event.preventDefault();
      const formData = {
        mobile: document.getElementById('mobile').value,
        gov_id: document.getElementById('gov_id').value,
        department: document.getElementById('department').value,
        position: document.getElementById('position').value,
        assigned_region: document.getElementById('assigned_region').value,
        office: document.getElementById('office').value,
        municipality: document.getElementById('municipality').value,
        province: document.getElementById('province').value
      };

      fetch('api/update_profile.php', {
        method: 'POST',
        headers: { 
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          user_id: <?php echo (int)$user_id; ?>,
          ...formData
        })
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          alert('Profile updated successfully!');
          location.reload();
        } else {
          alert('Update failed: ' + (data.message || 'Unknown error'));
        }
      })
      .catch(error => {
        alert('Error updating profile: ' + error.message);
      });
    }

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
      const loadingOverlay = document.getElementById('loadingOverlay');
      if (loadingOverlay) {
        loadingOverlay.style.display = 'none';
      }
    });
  </script>

</body>
</html>