<?php
session_start();

// Auth check - DA Officers and Admin only
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php');
    exit;
}

$allowed_roles = ['Admin', 'DA_Officer', 'DA Officer'];
if (!in_array($_SESSION['user_role'] ?? '', $allowed_roles)) {
    header('Location: ../unauthorized.php');
    exit;
}

// Database connection
try {
    $pdo = new PDO("mysql:host=localhost;dbname=myproject4;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
} catch (PDOException $e) {
    error_log("DB Error: " . $e->getMessage());
    die(json_encode(['error' => 'Database connection failed']));
}

// Handle AJAX actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    switch ($_POST['action']) {

        case 'issue_certificate':
            $farm_id    = intval($_POST['farm_id'] ?? 0);
            $cert_type  = trim($_POST['cert_type'] ?? '');
            $valid_from = trim($_POST['valid_from'] ?? '');
            $valid_until= trim($_POST['valid_until'] ?? '');
            $issued_by  = $_SESSION['user_id'] ?? 0;

            if (!$farm_id || !$cert_type || !$valid_from || !$valid_until) {
                echo json_encode(['success' => false, 'message' => 'All fields are required.']);
                exit;
            }

            // Check if farm is approved
            $stmt = $pdo->prepare("SELECT id, name, status FROM farms WHERE id = ? AND status = 'Approved'");
            $stmt->execute([$farm_id]);
            $farm = $stmt->fetch();

            if (!$farm) {
                echo json_encode(['success' => false, 'message' => 'Farm not found or not approved.']);
                exit;
            }

            // Generate unique certificate number: AGRI-CAMSR-YYYY-XXXXXXX
            $year      = date('Y');
            $rand_part = strtoupper(substr(md5(uniqid(rand(), true)), 0, 7));
            $cert_number = "AGRI-CAMSR-{$year}-{$rand_part}";

            $stmt = $pdo->prepare("
                INSERT INTO farm_certificates 
                    (farm_id, certificate_number, certificate_type, issued_by, issued_date, valid_from, valid_until, status, created_at)
                VALUES 
                    (?, ?, ?, ?, NOW(), ?, ?, 'Active', NOW())
            ");
            $stmt->execute([$farm_id, $cert_number, $cert_type, $issued_by, $valid_from, $valid_until]);
            $cert_id = $pdo->lastInsertId();

            // Audit log
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $details = json_encode(['cert_number' => $cert_number, 'farm_id' => $farm_id, 'cert_type' => $cert_type]);
            $log = $pdo->prepare("INSERT INTO audit_log (userId, action, tableName, recordId, ipAddress, details) VALUES (?, 'ISSUE_CERTIFICATE', 'farm_certificates', ?, ?, ?)");
            $log->execute([$issued_by, $cert_id, $ip, $details]);

            echo json_encode(['success' => true, 'message' => 'Certificate issued successfully.', 'cert_id' => $cert_id, 'cert_number' => $cert_number]);
            exit;

        case 'revoke_certificate':
            $cert_id = intval($_POST['cert_id'] ?? 0);
            $reason  = trim($_POST['reason'] ?? '');
            if (!$cert_id) { echo json_encode(['success' => false, 'message' => 'Invalid certificate.']); exit; }

            $stmt = $pdo->prepare("UPDATE farm_certificates SET status = 'Revoked', revoke_reason = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$reason, $cert_id]);

            $issued_by = $_SESSION['user_id'] ?? 0;
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $log = $pdo->prepare("INSERT INTO audit_log (userId, action, tableName, recordId, ipAddress, details) VALUES (?, 'REVOKE_CERTIFICATE', 'farm_certificates', ?, ?, ?)");
            $log->execute([$issued_by, $cert_id, $ip, json_encode(['reason' => $reason])]);

            echo json_encode(['success' => true, 'message' => 'Certificate revoked.']);
            exit;

        case 'get_farms':
            $search = '%' . trim($_POST['search'] ?? '') . '%';
            $stmt = $pdo->prepare("
                SELECT f.id, f.name, f.farmType, f.barangay, f.municipality, f.province,
                       CONCAT(u.firstName, ' ', u.lastName) AS owner_name
                FROM farms f
                LEFT JOIN users u ON f.ownerId = u.id
                WHERE f.status = 'Approved'
                  AND (f.name LIKE ? OR f.municipality LIKE ? OR u.firstName LIKE ? OR u.lastName LIKE ?)
                ORDER BY f.name ASC
                LIMIT 50
            ");
            $stmt->execute([$search, $search, $search, $search]);
            echo json_encode(['success' => true, 'farms' => $stmt->fetchAll()]);
            exit;
    }
    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

// Fetch certificates with pagination
$page     = max(1, intval($_GET['page'] ?? 1));
$per_page = 10;
$offset   = ($page - 1) * $per_page;
$filter   = trim($_GET['filter'] ?? 'All');
$search   = trim($_GET['search'] ?? '');

$where_parts = [];
$params      = [];

if ($filter !== 'All') {
    $where_parts[] = "fc.status = ?";
    $params[] = $filter;
}
if ($search !== '') {
    $where_parts[] = "(fc.certificate_number LIKE ? OR f.name LIKE ? OR CONCAT(u.firstName,' ',u.lastName) LIKE ?)";
    $s = "%$search%";
    $params[] = $s; $params[] = $s; $params[] = $s;
}

$where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

$count_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM farm_certificates fc
    LEFT JOIN farms f ON fc.farm_id = f.id
    LEFT JOIN users u ON f.ownerId = u.id
    $where_sql
");
$count_stmt->execute($params);
$total = $count_stmt->fetchColumn();
$total_pages = max(1, ceil($total / $per_page));

$list_stmt = $pdo->prepare("
    SELECT fc.id, fc.certificate_number, fc.certificate_type, fc.status,
           fc.issued_date, fc.valid_from, fc.valid_until, fc.revoke_reason,
           f.name AS farm_name, f.farmType, f.barangay, f.municipality, f.province,
           CONCAT(u.firstName, ' ', u.lastName) AS owner_name,
           CONCAT(o.firstName, ' ', o.lastName) AS issued_by_name
    FROM farm_certificates fc
    LEFT JOIN farms f ON fc.farm_id = f.id
    LEFT JOIN users u ON f.ownerId = u.id
    LEFT JOIN users o ON fc.issued_by = o.id
    $where_sql
    ORDER BY fc.issued_date DESC
    LIMIT $per_page OFFSET $offset
");
$list_stmt->execute($params);
$certificates = $list_stmt->fetchAll();

// Stats
$stats_stmt = $pdo->query("
    SELECT 
        COUNT(*) AS total,
        SUM(status = 'Active') AS active,
        SUM(status = 'Expired') AS expired,
        SUM(status = 'Revoked') AS revoked
    FROM farm_certificates
");
$stats = $stats_stmt->fetch();

$current_user = $_SESSION['user_id'] ?? 0;
$officer_stmt = $pdo->prepare("SELECT firstName, lastName, email FROM users WHERE id = ?");
$officer_stmt->execute([$current_user]);
$officer = $officer_stmt->fetch();
$officer_name = trim(($officer['firstName'] ?? '') . ' ' . ($officer['lastName'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>E-Certificate Management | AgriTrace+ Camarines Sur</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700&family=Lora:ital,wght@0,400;0,500;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<style>
:root {
    --green-dark:   #1a4731;
    --green-mid:    #2d6a4f;
    --green-light:  #52b788;
    --gold:         #c9a84c;
    --gold-light:   #e8c96d;
    --cream:        #fdf8ef;
    --cream-dark:   #f0e8d5;
    --text-dark:    #1c1c1c;
    --text-mid:     #4a4a4a;
    --text-light:   #888;
    --white:        #ffffff;
    --red:          #c0392b;
    --sidebar-w:    260px;
    --header-h:     64px;
    --radius:       12px;
    --shadow-card:  0 4px 24px rgba(0,0,0,0.08);
    --transition:   0.2s ease;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'DM Sans', sans-serif;
    background: #f4f6f0;
    color: var(--text-dark);
    min-height: 100vh;
}

/* ── Layout ── */
.layout { display: flex; min-height: 100vh; }

/* Sidebar */
.sidebar {
    width: var(--sidebar-w);
    background: var(--green-dark);
    position: fixed; left: 0; top: 0; bottom: 0;
    display: flex; flex-direction: column;
    z-index: 100;
    box-shadow: 4px 0 20px rgba(0,0,0,0.15);
}
.sidebar-logo {
    padding: 24px 20px 20px;
    border-bottom: 1px solid rgba(255,255,255,0.08);
}
.sidebar-logo img { height: 36px; }
.sidebar-logo .brand { color: #fff; font-family: 'Cinzel', serif; font-size: 13px; letter-spacing: 1px; margin-top: 8px; line-height: 1.4; }
.sidebar-logo .brand span { color: var(--gold-light); display: block; font-size: 10px; letter-spacing: 2px; }

.sidebar-nav { flex: 1; overflow-y: auto; padding: 16px 0; }
.nav-section { margin-bottom: 8px; }
.nav-label { color: rgba(255,255,255,0.35); font-size: 10px; letter-spacing: 2px; text-transform: uppercase; padding: 8px 20px 4px; }
.nav-item {
    display: flex; align-items: center; gap: 12px;
    color: rgba(255,255,255,0.7);
    text-decoration: none;
    padding: 11px 20px;
    font-size: 13.5px; font-weight: 400;
    transition: var(--transition);
    border-left: 3px solid transparent;
}
.nav-item:hover { background: rgba(255,255,255,0.06); color: #fff; border-left-color: var(--gold); }
.nav-item.active { background: rgba(201,168,76,0.12); color: var(--gold-light); border-left-color: var(--gold); font-weight: 600; }
.nav-item i { width: 18px; text-align: center; font-size: 14px; }

/* Main content */
.main { margin-left: var(--sidebar-w); flex: 1; display: flex; flex-direction: column; }

/* Header */
.topbar {
    height: var(--header-h);
    background: var(--white);
    display: flex; align-items: center; justify-content: space-between;
    padding: 0 28px;
    border-bottom: 1px solid #e8eaed;
    position: sticky; top: 0; z-index: 50;
    box-shadow: 0 1px 8px rgba(0,0,0,0.05);
}
.topbar-left h1 { font-family: 'Cinzel', serif; font-size: 17px; color: var(--green-dark); }
.topbar-left p { font-size: 12px; color: var(--text-light); margin-top: 1px; }
.topbar-right { display: flex; align-items: center; gap: 16px; }
.topbar-user {
    display: flex; align-items: center; gap: 10px;
    background: var(--cream);
    padding: 6px 14px 6px 8px;
    border-radius: 99px;
    font-size: 13px; font-weight: 500;
    color: var(--green-dark);
}
.topbar-user .avatar {
    width: 30px; height: 30px;
    background: var(--green-mid);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 12px; font-weight: 700;
}

/* Content area */
.content { padding: 28px; flex: 1; }

/* Stats row */
.stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; }
.stat-card {
    background: var(--white);
    border-radius: var(--radius);
    padding: 20px;
    display: flex; align-items: center; gap: 16px;
    box-shadow: var(--shadow-card);
    border: 1px solid #edf0e8;
}
.stat-icon {
    width: 48px; height: 48px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px;
}
.stat-icon.green  { background: rgba(82,183,136,0.12); color: var(--green-mid); }
.stat-icon.gold   { background: rgba(201,168,76,0.12);  color: var(--gold); }
.stat-icon.red    { background: rgba(192,57,43,0.1);    color: var(--red); }
.stat-icon.gray   { background: rgba(100,100,100,0.08); color: var(--text-light); }
.stat-info .val { font-size: 26px; font-weight: 700; color: var(--text-dark); line-height: 1; }
.stat-info .lbl { font-size: 12px; color: var(--text-light); margin-top: 4px; }

/* Toolbar */
.toolbar {
    background: var(--white);
    border-radius: var(--radius);
    padding: 16px 20px;
    display: flex; align-items: center; gap: 12px;
    margin-bottom: 20px;
    box-shadow: var(--shadow-card);
    flex-wrap: wrap;
}
.toolbar-search {
    display: flex; align-items: center; gap: 8px;
    background: #f4f6f0; border-radius: 8px;
    padding: 8px 14px; flex: 1; min-width: 220px;
}
.toolbar-search i { color: var(--text-light); font-size: 14px; }
.toolbar-search input { border: none; background: transparent; outline: none; font-size: 14px; color: var(--text-dark); width: 100%; font-family: inherit; }

.filter-tabs { display: flex; gap: 4px; }
.filter-tab {
    padding: 7px 14px; border-radius: 8px; font-size: 13px;
    border: 1px solid #e0e3db; background: #fff;
    color: var(--text-mid); cursor: pointer; font-family: inherit;
    transition: var(--transition);
}
.filter-tab:hover { border-color: var(--green-light); color: var(--green-mid); }
.filter-tab.active { background: var(--green-dark); color: #fff; border-color: var(--green-dark); }

.btn-issue {
    padding: 9px 20px;
    background: var(--gold);
    color: var(--green-dark);
    border: none; border-radius: 8px;
    font-size: 13.5px; font-weight: 700;
    cursor: pointer; display: flex; align-items: center; gap: 8px;
    font-family: inherit; transition: var(--transition);
    white-space: nowrap;
}
.btn-issue:hover { background: var(--gold-light); }

/* Table */
.table-card {
    background: var(--white);
    border-radius: var(--radius);
    box-shadow: var(--shadow-card);
    overflow: hidden;
    border: 1px solid #edf0e8;
}
.table-card table { width: 100%; border-collapse: collapse; }
.table-card th {
    background: #f7f9f4;
    padding: 12px 16px;
    text-align: left; font-size: 11.5px;
    font-weight: 600; letter-spacing: 0.5px;
    color: var(--text-light); text-transform: uppercase;
    border-bottom: 1px solid #edf0e8;
}
.table-card td {
    padding: 13px 16px;
    font-size: 13.5px;
    border-bottom: 1px solid #f0f2ed;
    vertical-align: middle;
}
.table-card tr:last-child td { border-bottom: none; }
.table-card tr:hover td { background: #fafcf8; }

.cert-num { font-family: 'Lora', serif; font-size: 12.5px; color: var(--green-dark); font-weight: 500; }
.farm-name { font-weight: 600; color: var(--text-dark); }
.farm-sub  { font-size: 11.5px; color: var(--text-light); margin-top: 2px; }

.badge {
    display: inline-block; padding: 3px 10px; border-radius: 99px;
    font-size: 11px; font-weight: 600; letter-spacing: 0.3px;
}
.badge-active  { background: #e8f5ef; color: #1e7d4f; }
.badge-expired { background: #fef3cd; color: #856404; }
.badge-revoked { background: #fde8e6; color: var(--red); }

.action-btns { display: flex; gap: 6px; }
.btn-sm {
    width: 30px; height: 30px;
    border-radius: 6px; border: 1px solid #e0e3db;
    background: #fff; color: var(--text-mid);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; font-size: 13px; transition: var(--transition);
}
.btn-sm:hover { border-color: var(--green-light); color: var(--green-mid); }
.btn-sm.danger:hover { border-color: var(--red); color: var(--red); }

/* Pagination */
.pagination { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-top: 1px solid #edf0e8; }
.pagination-info { font-size: 13px; color: var(--text-light); }
.pagination-btns { display: flex; gap: 4px; }
.page-btn {
    width: 32px; height: 32px; border-radius: 7px;
    border: 1px solid #e0e3db; background: #fff;
    font-size: 13px; cursor: pointer; font-family: inherit;
    display: flex; align-items: center; justify-content: center;
    color: var(--text-mid); transition: var(--transition);
}
.page-btn:hover { border-color: var(--green-mid); color: var(--green-mid); }
.page-btn.active { background: var(--green-dark); color: #fff; border-color: var(--green-dark); }
.page-btn:disabled { opacity: 0.4; cursor: not-allowed; }

/* ── MODAL base ── */
.modal-overlay {
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.5); backdrop-filter: blur(3px);
    z-index: 1000; display: none;
    align-items: center; justify-content: center; padding: 20px;
}
.modal-overlay.open { display: flex; }
.modal-box {
    background: #fff; border-radius: 16px;
    width: 100%; box-shadow: 0 24px 80px rgba(0,0,0,0.2);
    overflow: hidden; animation: slideUp 0.25s ease;
}
@keyframes slideUp { from { transform: translateY(20px); opacity:0; } to { transform: translateY(0); opacity:1; } }
.modal-header {
    padding: 20px 24px;
    border-bottom: 1px solid #edf0e8;
    display: flex; align-items: center; justify-content: space-between;
}
.modal-header h2 { font-family: 'Cinzel', serif; font-size: 16px; color: var(--green-dark); }
.modal-close { background: none; border: none; font-size: 20px; cursor: pointer; color: var(--text-light); }
.modal-body { padding: 24px; }

/* Form */
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-size: 12.5px; font-weight: 600; color: var(--text-mid); margin-bottom: 6px; letter-spacing: 0.3px; text-transform: uppercase; }
.form-group input,
.form-group select,
.form-group textarea {
    width: 100%; padding: 10px 14px;
    border: 1px solid #e0e3db; border-radius: 8px;
    font-size: 14px; font-family: inherit; color: var(--text-dark);
    background: #fafcf8; outline: none;
    transition: var(--transition);
}
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus { border-color: var(--green-light); background: #fff; }
.form-footer { display: flex; gap: 10px; justify-content: flex-end; padding-top: 8px; }

.btn-cancel {
    padding: 10px 20px; border-radius: 8px; border: 1px solid #e0e3db;
    background: #fff; font-family: inherit; font-size: 14px;
    cursor: pointer; color: var(--text-mid); transition: var(--transition);
}
.btn-cancel:hover { border-color: #ccc; }
.btn-submit {
    padding: 10px 24px; border-radius: 8px; border: none;
    background: var(--green-dark); color: #fff;
    font-family: inherit; font-size: 14px; font-weight: 600;
    cursor: pointer; transition: var(--transition);
}
.btn-submit:hover { background: var(--green-mid); }
.btn-submit:disabled { opacity: 0.6; cursor: not-allowed; }

/* Farm search */
.farm-search-wrap { position: relative; }
.farm-results {
    position: absolute; top: 100%; left: 0; right: 0;
    background: #fff; border: 1px solid #e0e3db; border-radius: 8px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.1);
    max-height: 200px; overflow-y: auto; z-index: 10;
    display: none;
}
.farm-results.open { display: block; }
.farm-result-item {
    padding: 10px 14px; cursor: pointer;
    font-size: 13.5px; border-bottom: 1px solid #f0f2ed;
    transition: var(--transition);
}
.farm-result-item:last-child { border-bottom: none; }
.farm-result-item:hover { background: #f4f6f0; }
.farm-result-item .fn { font-weight: 600; color: var(--text-dark); }
.farm-result-item .fs { font-size: 11.5px; color: var(--text-light); }

/* ── CERTIFICATE PREVIEW ── */
#cert-preview-overlay { max-width: 820px; }
.cert-preview-wrapper {
    background: var(--cream);
    border: 1px solid #e0d8c4;
    border-radius: 8px;
    overflow: hidden;
    margin-bottom: 16px;
}

.certificate-doc {
    width: 760px;
    margin: 0 auto;
    background: var(--white);
    font-family: 'Lora', serif;
    position: relative;
    padding: 0;
}

/* Outer decorative border */
.cert-outer-border {
    border: 12px solid var(--green-dark);
    margin: 16px;
    padding: 0;
    position: relative;
}
.cert-inner-border {
    border: 3px double var(--gold);
    margin: 5px;
    padding: 0;
}

/* Watermark */
.cert-watermark {
    position: absolute;
    top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    width: 340px; height: 340px;
    opacity: 0.04;
    pointer-events: none;
    z-index: 0;
}
.cert-watermark svg { width: 100%; height: 100%; }

.cert-body {
    padding: 36px 40px;
    position: relative; z-index: 1;
}

/* Header */
.cert-header {
    display: flex; align-items: center; gap: 20px;
    justify-content: center;
    padding-bottom: 18px;
    border-bottom: 2px solid var(--gold);
    margin-bottom: 18px;
}
.cert-logo-circle {
    width: 80px; height: 80px;
    background: linear-gradient(135deg, var(--green-dark), var(--green-mid));
    border-radius: 50%;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    border: 3px solid var(--gold);
    flex-shrink: 0;
    box-shadow: 0 4px 16px rgba(0,0,0,0.15);
}
.cert-logo-circle i { color: var(--gold-light); font-size: 24px; }
.cert-logo-circle .logo-label { color: rgba(255,255,255,0.8); font-size: 7px; letter-spacing: 1px; margin-top: 2px; font-family: 'DM Sans', sans-serif; text-transform: uppercase; }

.cert-header-text { text-align: center; }
.cert-agency { font-family: 'DM Sans', sans-serif; font-size: 10px; font-weight: 600; letter-spacing: 2px; text-transform: uppercase; color: var(--text-light); }
.cert-dept { font-family: 'Cinzel', serif; font-size: 15px; font-weight: 700; color: var(--green-dark); }
.cert-subdept { font-family: 'DM Sans', sans-serif; font-size: 10.5px; color: var(--text-mid); margin-top: 2px; }

/* Title band */
.cert-title-band {
    background: var(--green-dark);
    text-align: center;
    padding: 12px 0;
    margin: 0 -40px;
    margin-bottom: 22px;
}
.cert-title-band h1 {
    font-family: 'Cinzel', serif;
    font-size: 22px; font-weight: 700;
    color: var(--gold-light);
    letter-spacing: 3px;
    text-transform: uppercase;
}
.cert-title-band p {
    font-family: 'Lora', serif;
    font-size: 11px; color: rgba(255,255,255,0.6);
    font-style: italic; margin-top: 2px;
}

/* Meta info */
.cert-meta { display: flex; justify-content: space-between; margin-bottom: 20px; }
.cert-meta-item { font-family: 'DM Sans', sans-serif; font-size: 11px; color: var(--text-mid); }
.cert-meta-item strong { color: var(--green-dark); display: block; font-size: 12.5px; font-weight: 600; }

/* Main body text */
.cert-issued-to { text-align: center; margin-bottom: 16px; }
.cert-issued-to .label { font-family: 'DM Sans', sans-serif; font-size: 11px; letter-spacing: 2px; text-transform: uppercase; color: var(--text-light); }
.cert-issued-to .recipient { font-family: 'Cinzel', serif; font-size: 26px; font-weight: 700; color: var(--green-dark); margin: 6px 0; }
.cert-issued-to .address { font-family: 'Lora', serif; font-size: 13px; color: var(--text-mid); font-style: italic; }

.cert-body-text {
    font-family: 'Lora', serif;
    font-size: 13px; line-height: 1.9;
    color: var(--text-mid);
    text-align: center;
    max-width: 580px; margin: 0 auto 20px;
}
.cert-body-text em { color: var(--green-dark); font-weight: 500; }

/* Table */
.cert-table {
    width: 100%; border-collapse: collapse;
    margin: 16px 0 24px;
    font-size: 13px;
}
.cert-table th {
    background: var(--green-dark); color: var(--gold-light);
    padding: 8px 16px; font-family: 'DM Sans', sans-serif;
    font-size: 11px; letter-spacing: 1px; text-transform: uppercase;
    font-weight: 600;
}
.cert-table td {
    padding: 10px 16px; border: 1px solid #e0d8c4;
    font-family: 'Lora', serif;
}
.cert-table tr:nth-child(even) td { background: #fdf8ef; }

/* Validity banner */
.cert-validity {
    display: flex; justify-content: center; gap: 32px;
    background: var(--cream-dark);
    padding: 14px; border-radius: 8px;
    margin-bottom: 24px;
    border: 1px solid #ddd0b0;
}
.cert-validity-item { text-align: center; }
.cert-validity-item .lbl { font-family: 'DM Sans', sans-serif; font-size: 10px; letter-spacing: 1.5px; text-transform: uppercase; color: var(--text-light); }
.cert-validity-item .val { font-family: 'Cinzel', serif; font-size: 14px; color: var(--green-dark); font-weight: 700; margin-top: 3px; }

/* Signature area */
.cert-signatures {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 32px; margin-top: 8px;
}
.cert-sig { text-align: center; }
.cert-sig-line {
    border-bottom: 1.5px solid var(--text-dark);
    width: 80%; margin: 0 auto 8px;
}
.cert-sig-name { font-family: 'Cinzel', serif; font-size: 13px; color: var(--text-dark); font-weight: 600; }
.cert-sig-title { font-family: 'DM Sans', sans-serif; font-size: 10.5px; color: var(--text-light); }

/* QR placeholder */
.cert-qr-row { display: flex; align-items: center; justify-content: space-between; margin-top: 24px; padding-top: 16px; border-top: 1.5px solid var(--gold); }
.cert-qr-box {
    width: 70px; height: 70px;
    background: #f0ece0; border: 1px solid #ccc;
    display: flex; align-items: center; justify-content: center;
    border-radius: 6px;
    font-size: 10px; color: var(--text-light);
    font-family: 'DM Sans', sans-serif;
}
.cert-doc-footer { font-family: 'DM Sans', sans-serif; font-size: 10px; color: var(--text-light); text-align: right; line-height: 1.8; }

/* Preview print actions */
.preview-actions { display: flex; gap: 10px; justify-content: flex-end; }
.btn-download {
    padding: 10px 20px; border-radius: 8px; border: none;
    font-family: inherit; font-size: 14px; font-weight: 600;
    cursor: pointer; display: flex; align-items: center; gap: 8px;
    transition: var(--transition);
}
.btn-download.pdf { background: var(--red); color: #fff; }
.btn-download.pdf:hover { background: #a93226; }
.btn-download.print { background: var(--green-dark); color: #fff; }
.btn-download.print:hover { background: var(--green-mid); }

/* Toast */
#toast {
    position: fixed; bottom: 24px; right: 24px; z-index: 9999;
    background: var(--green-dark); color: #fff;
    padding: 14px 20px; border-radius: 10px;
    font-size: 14px; display: none; align-items: center; gap: 10px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.15);
    animation: fadeIn 0.2s;
}
#toast.error { background: var(--red); }
#toast.show { display: flex; }
@keyframes fadeIn { from { opacity:0; transform: translateY(10px); } to { opacity:1; transform: translateY(0); } }

/* Spinner */
.spinner {
    width: 16px; height: 16px;
    border: 2px solid rgba(255,255,255,0.4);
    border-top-color: #fff;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
    display: none;
}
.spinner.show { display: inline-block; }
@keyframes spin { to { transform: rotate(360deg); } }

/* Empty state */
.empty-state { text-align: center; padding: 60px 20px; color: var(--text-light); }
.empty-state i { font-size: 40px; margin-bottom: 12px; color: #ccc; }
.empty-state p { font-size: 14px; }

@media (max-width: 900px) {
    .sidebar { display: none; }
    .main { margin-left: 0; }
    .stats-row { grid-template-columns: 1fr 1fr; }
}
@media print {
    body * { visibility: hidden; }
    .certificate-doc, .certificate-doc * { visibility: visible; }
    .certificate-doc { position: absolute; left: 0; top: 0; width: 100%; }
}
</style>
</head>
<body>
<div class="layout">

<!-- SIDEBAR -->
<aside class="sidebar">
    <div class="sidebar-logo">
        <div class="brand">AgriTrace+<span>Camarines Sur</span></div>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-section">
            <div class="nav-label">Main</div>
            <a href="dashboard.php" class="nav-item"><i class="fas fa-th-large"></i> Dashboard</a>
        </div>
        <div class="nav-section">
            <div class="nav-label">Farm Management</div>
            <a href="farms.php" class="nav-item"><i class="fas fa-tractor"></i> Farms</a>
            <a href="farmers.php" class="nav-item"><i class="fas fa-users"></i> Farmers</a>
            <a href="ecertificate.php" class="nav-item active"><i class="fas fa-certificate"></i> E-Certificate</a>
        </div>
        <div class="nav-section">
            <div class="nav-label">Tools</div>
            <a href="geo-mapping.php" class="nav-item"><i class="fas fa-map-marked-alt"></i> Geo-Mapping</a>
            <a href="reports.php" class="nav-item"><i class="fas fa-chart-bar"></i> Reports</a>
            <a href="audit.php" class="nav-item"><i class="fas fa-shield-alt"></i> Audit & Security</a>
        </div>
        <div class="nav-section">
            <div class="nav-label">System</div>
            <a href="settings.php" class="nav-item"><i class="fas fa-cog"></i> Settings</a>
            <a href="../logout.php" class="nav-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </nav>
</aside>

<!-- MAIN -->
<div class="main">
    <header class="topbar">
        <div class="topbar-left">
            <h1>E-Certificate Management</h1>
            <p>Issue, manage and download farm certifications — Camarines Sur</p>
        </div>
        <div class="topbar-right">
            <div class="topbar-user">
                <div class="avatar"><?= strtoupper(substr($officer['firstName'] ?? 'U', 0, 1)) ?></div>
                <?= htmlspecialchars($officer_name) ?>
            </div>
        </div>
    </header>

    <div class="content">

        <!-- Stats -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-certificate"></i></div>
                <div class="stat-info">
                    <div class="val"><?= number_format($stats['total'] ?? 0) ?></div>
                    <div class="lbl">Total Certificates</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon gold"><i class="fas fa-check-circle"></i></div>
                <div class="stat-info">
                    <div class="val"><?= number_format($stats['active'] ?? 0) ?></div>
                    <div class="lbl">Active</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon gray"><i class="fas fa-hourglass-end"></i></div>
                <div class="stat-info">
                    <div class="val"><?= number_format($stats['expired'] ?? 0) ?></div>
                    <div class="lbl">Expired</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon red"><i class="fas fa-times-circle"></i></div>
                <div class="stat-info">
                    <div class="val"><?= number_format($stats['revoked'] ?? 0) ?></div>
                    <div class="lbl">Revoked</div>
                </div>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="toolbar">
            <div class="toolbar-search">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" placeholder="Search certificate number, farm name, or owner…" value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="filter-tabs">
                <?php foreach (['All','Active','Expired','Revoked'] as $f): ?>
                <button class="filter-tab <?= $filter === $f ? 'active' : '' ?>"
                        onclick="applyFilter('<?= $f ?>')"><?= $f ?></button>
                <?php endforeach; ?>
            </div>
            <button class="btn-issue" onclick="openIssueModal()">
                <i class="fas fa-plus-circle"></i> Issue Certificate
            </button>
        </div>

        <!-- Table -->
        <div class="table-card">
            <table>
                <thead>
                    <tr>
                        <th>Certificate No.</th>
                        <th>Farm</th>
                        <th>Owner</th>
                        <th>Type</th>
                        <th>Issued Date</th>
                        <th>Valid Until</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($certificates)): ?>
                    <tr><td colspan="8">
                        <div class="empty-state">
                            <i class="fas fa-certificate"></i>
                            <p>No certificates found. Issue the first one!</p>
                        </div>
                    </td></tr>
                <?php else: foreach ($certificates as $c): ?>
                    <tr>
                        <td><div class="cert-num"><?= htmlspecialchars($c['certificate_number']) ?></div></td>
                        <td>
                            <div class="farm-name"><?= htmlspecialchars($c['farm_name']) ?></div>
                            <div class="farm-sub"><?= htmlspecialchars($c['barangay'] . ', ' . $c['municipality']) ?></div>
                        </td>
                        <td><?= htmlspecialchars($c['owner_name']) ?></td>
                        <td><?= htmlspecialchars($c['certificate_type']) ?></td>
                        <td><?= date('d M Y', strtotime($c['issued_date'])) ?></td>
                        <td><?= date('d M Y', strtotime($c['valid_until'])) ?></td>
                        <td>
                            <?php
                            $badge = match($c['status']) {
                                'Active'  => 'badge-active',
                                'Expired' => 'badge-expired',
                                'Revoked' => 'badge-revoked',
                                default   => ''
                            };
                            ?>
                            <span class="badge <?= $badge ?>"><?= $c['status'] ?></span>
                        </td>
                        <td>
                            <div class="action-btns">
                                <button class="btn-sm" title="Preview & Print"
                                        onclick='previewCert(<?= json_encode($c) ?>)'>
                                    <i class="fas fa-eye"></i>
                                </button>
                                <?php if ($c['status'] === 'Active'): ?>
                                <button class="btn-sm danger" title="Revoke"
                                        onclick="openRevoke(<?= $c['id'] ?>, '<?= htmlspecialchars($c['certificate_number']) ?>')">
                                    <i class="fas fa-ban"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ($total > $per_page): ?>
            <div class="pagination">
                <div class="pagination-info">
                    Showing <?= min($offset+1,$total) ?>–<?= min($offset+$per_page,$total) ?> of <?= $total ?> certificates
                </div>
                <div class="pagination-btns">
                    <button class="page-btn" <?= $page<=1?'disabled':'' ?>
                            onclick="goPage(<?= $page-1 ?>)"><i class="fas fa-chevron-left"></i></button>
                    <?php for ($p=max(1,$page-2); $p<=min($total_pages,$page+2); $p++): ?>
                    <button class="page-btn <?= $p==$page?'active':'' ?>"
                            onclick="goPage(<?= $p ?>)"><?= $p ?></button>
                    <?php endfor; ?>
                    <button class="page-btn" <?= $page>=$total_pages?'disabled':'' ?>
                            onclick="goPage(<?= $page+1 ?>)"><i class="fas fa-chevron-right"></i></button>
                </div>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /content -->
</div><!-- /main -->
</div><!-- /layout -->

<!-- ═══════════════ ISSUE MODAL ═══════════════ -->
<div class="modal-overlay" id="issueModal">
    <div class="modal-box" style="max-width:560px">
        <div class="modal-header">
            <h2><i class="fas fa-certificate" style="color:var(--gold);margin-right:8px"></i>Issue New Certificate</h2>
            <button class="modal-close" onclick="closeIssueModal()">×</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Search & Select Farm <span style="color:var(--red)">*</span></label>
                <div class="farm-search-wrap">
                    <input type="text" id="farmSearchInput" placeholder="Type farm name…" autocomplete="off">
                    <div class="farm-results" id="farmResults"></div>
                </div>
                <input type="hidden" id="selectedFarmId">
            </div>
            <div class="form-group" id="farmInfoBox" style="display:none;background:#f4f6f0;padding:12px;border-radius:8px;font-size:13px;color:var(--text-mid)"></div>
            <div class="form-group">
                <label>Certificate Type <span style="color:var(--red)">*</span></label>
                <select id="certType">
                    <option value="">— Select Type —</option>
                    <option value="Good Agricultural Practices (GAP)">Good Agricultural Practices (GAP)</option>
                    <option value="Good Animal Husbandry Practices (GAHP)">Good Animal Husbandry Practices (GAHP)</option>
                    <option value="Organic Certification">Organic Certification</option>
                    <option value="Farm Registration Certificate">Farm Registration Certificate</option>
                    <option value="PhilGAP Certification">PhilGAP Certification</option>
                    <option value="PNS/BAFS Compliance">PNS/BAFS Compliance</option>
                </select>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Valid From <span style="color:var(--red)">*</span></label>
                    <input type="date" id="validFrom" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label>Valid Until <span style="color:var(--red)">*</span></label>
                    <input type="date" id="validUntil">
                </div>
            </div>
            <div class="form-footer">
                <button class="btn-cancel" onclick="closeIssueModal()">Cancel</button>
                <button class="btn-submit" id="issueBtn" onclick="issueCertificate()">
                    <span class="spinner" id="issueSpinner"></span> Issue Certificate
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════ REVOKE MODAL ═══════════════ -->
<div class="modal-overlay" id="revokeModal">
    <div class="modal-box" style="max-width:440px">
        <div class="modal-header">
            <h2 style="color:var(--red)"><i class="fas fa-ban" style="margin-right:8px"></i>Revoke Certificate</h2>
            <button class="modal-close" onclick="document.getElementById('revokeModal').classList.remove('open')">×</button>
        </div>
        <div class="modal-body">
            <p style="font-size:13.5px;color:var(--text-mid);margin-bottom:16px">
                Revoking: <strong id="revokeCertNum" style="color:var(--green-dark)"></strong>
            </p>
            <div class="form-group">
                <label>Reason for Revocation <span style="color:var(--red)">*</span></label>
                <textarea id="revokeReason" rows="3" placeholder="Explain why this certificate is being revoked…"></textarea>
            </div>
            <input type="hidden" id="revokeCertId">
            <div class="form-footer">
                <button class="btn-cancel" onclick="document.getElementById('revokeModal').classList.remove('open')">Cancel</button>
                <button class="btn-submit" style="background:var(--red)" onclick="submitRevoke()">Confirm Revoke</button>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════ CERTIFICATE PREVIEW MODAL ═══════════════ -->
<div class="modal-overlay" id="certPreviewOverlay">
    <div class="modal-box" id="cert-preview-overlay">
        <div class="modal-header">
            <h2><i class="fas fa-file-certificate" style="color:var(--gold);margin-right:8px"></i>Certificate Preview</h2>
            <button class="modal-close" onclick="document.getElementById('certPreviewOverlay').classList.remove('open')">×</button>
        </div>
        <div class="modal-body">
            <div class="cert-preview-wrapper">
                <div id="certificatePreview"></div>
            </div>
            <div class="preview-actions">
                <button class="btn-download print" onclick="printCert()"><i class="fas fa-print"></i> Print</button>
                <button class="btn-download pdf" onclick="downloadPDF()"><i class="fas fa-file-pdf"></i> Download PDF</button>
            </div>
        </div>
    </div>
</div>

<!-- Toast -->
<div id="toast"><i class="fas fa-check-circle"></i><span id="toastMsg"></span></div>

<script>
// ── Utilities ──
function showToast(msg, type='success') {
    const t = document.getElementById('toast');
    document.getElementById('toastMsg').textContent = msg;
    t.className = 'show' + (type==='error' ? ' error' : '');
    setTimeout(() => t.className = '', 3500);
}

function goPage(p) {
    const u = new URL(window.location);
    u.searchParams.set('page', p);
    window.location = u;
}
function applyFilter(f) {
    const u = new URL(window.location);
    u.searchParams.set('filter', f);
    u.searchParams.set('page', 1);
    window.location = u;
}

// Search debounce
let searchTimer;
document.getElementById('searchInput').addEventListener('input', function() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        const u = new URL(window.location);
        u.searchParams.set('search', this.value);
        u.searchParams.set('page', 1);
        window.location = u;
    }, 600);
});

// ── Issue Modal ──
function openIssueModal() {
    document.getElementById('issueModal').classList.add('open');
    // Set default valid_until = 1 year from today
    const d = new Date();
    d.setFullYear(d.getFullYear() + 1);
    document.getElementById('validUntil').value = d.toISOString().split('T')[0];
}
function closeIssueModal() {
    document.getElementById('issueModal').classList.remove('open');
    document.getElementById('farmSearchInput').value = '';
    document.getElementById('selectedFarmId').value = '';
    document.getElementById('farmInfoBox').style.display = 'none';
    document.getElementById('farmResults').classList.remove('open');
    document.getElementById('certType').value = '';
}

// Farm live search
let farmSearchTimer;
document.getElementById('farmSearchInput').addEventListener('input', function() {
    clearTimeout(farmSearchTimer);
    const q = this.value.trim();
    if (q.length < 2) { document.getElementById('farmResults').classList.remove('open'); return; }
    farmSearchTimer = setTimeout(() => searchFarms(q), 350);
});

async function searchFarms(q) {
    const fd = new FormData();
    fd.append('action', 'get_farms'); fd.append('search', q);
    const res = await fetch('', {method:'POST', body:fd});
    const data = await res.json();
    const box = document.getElementById('farmResults');
    if (!data.success || !data.farms.length) {
        box.innerHTML = '<div class="farm-result-item" style="color:var(--text-light);cursor:default">No approved farms found.</div>';
        box.classList.add('open'); return;
    }
    box.innerHTML = data.farms.map(f => `
        <div class="farm-result-item" onclick='selectFarm(${JSON.stringify(f)})'>
            <div class="fn">${f.name}</div>
            <div class="fs">${f.farmType} — ${f.barangay}, ${f.municipality} | Owner: ${f.owner_name}</div>
        </div>`).join('');
    box.classList.add('open');
}

function selectFarm(f) {
    document.getElementById('farmSearchInput').value = f.name;
    document.getElementById('selectedFarmId').value = f.id;
    document.getElementById('farmResults').classList.remove('open');
    const box = document.getElementById('farmInfoBox');
    box.style.display = 'block';
    box.innerHTML = `<strong>${f.name}</strong> &mdash; ${f.farmType}<br>
        <span style="color:var(--text-light)">${f.barangay}, ${f.municipality}, ${f.province}</span><br>
        Owner: <strong>${f.owner_name}</strong>`;
}

document.addEventListener('click', e => {
    if (!e.target.closest('.farm-search-wrap')) document.getElementById('farmResults').classList.remove('open');
});

async function issueCertificate() {
    const farm_id   = document.getElementById('selectedFarmId').value;
    const cert_type = document.getElementById('certType').value;
    const valid_from = document.getElementById('validFrom').value;
    const valid_until = document.getElementById('validUntil').value;

    if (!farm_id || !cert_type || !valid_from || !valid_until) {
        showToast('Please fill in all required fields.', 'error'); return;
    }
    if (new Date(valid_until) <= new Date(valid_from)) {
        showToast('Valid Until must be after Valid From.', 'error'); return;
    }

    const btn = document.getElementById('issueBtn');
    const spinner = document.getElementById('issueSpinner');
    btn.disabled = true; spinner.classList.add('show');

    const fd = new FormData();
    fd.append('action', 'issue_certificate');
    fd.append('farm_id', farm_id);
    fd.append('cert_type', cert_type);
    fd.append('valid_from', valid_from);
    fd.append('valid_until', valid_until);

    try {
        const res = await fetch('', {method:'POST', body:fd});
        const data = await res.json();
        if (data.success) {
            showToast('Certificate issued: ' + data.cert_number);
            closeIssueModal();
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast(data.message || 'Failed to issue certificate.', 'error');
        }
    } catch (e) {
        showToast('Network error. Please try again.', 'error');
    } finally {
        btn.disabled = false; spinner.classList.remove('show');
    }
}

// ── Revoke ──
function openRevoke(id, num) {
    document.getElementById('revokeCertId').value = id;
    document.getElementById('revokeCertNum').textContent = num;
    document.getElementById('revokeReason').value = '';
    document.getElementById('revokeModal').classList.add('open');
}

async function submitRevoke() {
    const cert_id = document.getElementById('revokeCertId').value;
    const reason  = document.getElementById('revokeReason').value.trim();
    if (!reason) { showToast('Please provide a revocation reason.', 'error'); return; }

    const fd = new FormData();
    fd.append('action', 'revoke_certificate');
    fd.append('cert_id', cert_id);
    fd.append('reason', reason);

    const res = await fetch('', {method:'POST', body:fd});
    const data = await res.json();
    if (data.success) {
        showToast('Certificate revoked successfully.');
        document.getElementById('revokeModal').classList.remove('open');
        setTimeout(() => location.reload(), 1500);
    } else {
        showToast(data.message || 'Revocation failed.', 'error');
    }
}

// ── Certificate Preview ──
let currentCertData = null;

function previewCert(cert) {
    currentCertData = cert;
    const issuedDate  = new Date(cert.issued_date).toLocaleDateString('en-PH', {day:'numeric',month:'long',year:'numeric'});
    const validFrom   = new Date(cert.valid_from).toLocaleDateString('en-PH',  {day:'numeric',month:'long',year:'numeric'});
    const validUntil  = new Date(cert.valid_until).toLocaleDateString('en-PH', {day:'numeric',month:'long',year:'numeric'});
    const location    = [cert.barangay, cert.municipality, cert.province].filter(Boolean).join(', ');

    document.getElementById('certificatePreview').innerHTML = `
    <div class="certificate-doc" id="certDoc">
      <div class="cert-outer-border">
        <div class="cert-inner-border">
          <div class="cert-body">
            <!-- Watermark -->
            <div class="cert-watermark" aria-hidden="true">
              <svg viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg">
                <circle cx="100" cy="100" r="90" fill="none" stroke="#2d6a4f" stroke-width="4"/>
                <text x="100" y="95" text-anchor="middle" font-family="serif" font-size="22" fill="#2d6a4f" font-weight="bold">AgriTrace+</text>
                <text x="100" y="118" text-anchor="middle" font-family="serif" font-size="11" fill="#2d6a4f">CAMARINES SUR</text>
              </svg>
            </div>

            <!-- Header -->
            <div class="cert-header">
              <div class="cert-logo-circle">
                <i class="fas fa-seedling"></i>
                <div class="logo-label">AgriTrace+</div>
              </div>
              <div class="cert-header-text">
                <div class="cert-agency">Republic of the Philippines</div>
                <div class="cert-dept">Department of Agriculture</div>
                <div class="cert-subdept">Provincial Agriculture Office — Camarines Sur</div>
                <div class="cert-subdept" style="font-size:10px;margin-top:2px;color:var(--gold)">AgriTrace+ Digital Certification System</div>
              </div>
              <div class="cert-logo-circle">
                <i class="fas fa-award"></i>
                <div class="logo-label">Official</div>
              </div>
            </div>

            <!-- Title -->
            <div class="cert-title-band">
              <h1>Certificate of Compliance</h1>
              <p>${cert.certificate_type}</p>
            </div>

            <!-- Meta -->
            <div class="cert-meta">
              <div class="cert-meta-item">
                Certificate Number
                <strong>${cert.certificate_number}</strong>
              </div>
              <div class="cert-meta-item" style="text-align:right">
                Date Issued
                <strong>${issuedDate}</strong>
              </div>
            </div>

            <!-- Issued To -->
            <div class="cert-issued-to">
              <div class="label">This certifies that</div>
              <div class="recipient">${cert.farm_name}</div>
              <div class="address">${location}</div>
            </div>

            <!-- Body text -->
            <div class="cert-body-text">
              The farm mentioned above, owned and operated by
              <em>${cert.owner_name}</em>,
              has been found to be compliant with the requirements set forth under
              <em>${cert.certificate_type}</em> standards
              as implemented by the
              <em>Department of Agriculture — Camarines Sur</em>
              through the AgriTrace+ Digital Monitoring System.
            </div>

            <!-- Details table -->
            <table class="cert-table">
              <thead>
                <tr>
                  <th>Farm Type</th>
                  <th>Location</th>
                  <th>Date of Certification</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td>${cert.farmType || '—'}</td>
                  <td>${cert.municipality || '—'}, Camarines Sur</td>
                  <td>${issuedDate}</td>
                </tr>
              </tbody>
            </table>

            <!-- Validity -->
            <div class="cert-validity">
              <div class="cert-validity-item">
                <div class="lbl">Valid From</div>
                <div class="val">${validFrom}</div>
              </div>
              <div class="cert-validity-item" style="border-left:1px solid #ddd0b0;padding-left:32px">
                <div class="lbl">Valid Until</div>
                <div class="val">${validUntil}</div>
              </div>
              <div class="cert-validity-item" style="border-left:1px solid #ddd0b0;padding-left:32px">
                <div class="lbl">Status</div>
                <div class="val" style="color:${cert.status==='Active'?'var(--green-mid)':cert.status==='Revoked'?'var(--red)':'#856404'}">${cert.status}</div>
              </div>
            </div>

            <!-- Signatures -->
            <div class="cert-signatures">
              <div class="cert-sig">
                <div style="height:48px"></div>
                <div class="cert-sig-line"></div>
                <div class="cert-sig-name">${cert.issued_by_name || 'DA Officer'}</div>
                <div class="cert-sig-title">Certifying Officer<br>AgriTrace+ System</div>
              </div>
              <div class="cert-sig">
                <div style="height:48px"></div>
                <div class="cert-sig-line"></div>
                <div class="cert-sig-name">Provincial Agriculture Officer</div>
                <div class="cert-sig-title">Bureau of Agriculture<br>Camarines Sur</div>
              </div>
            </div>

            <!-- QR & footer -->
            <div class="cert-qr-row">
              <div class="cert-qr-box">
                <i class="fas fa-qrcode" style="font-size:36px;color:#aaa"></i>
              </div>
              <div class="cert-doc-footer">
                This is an electronically generated certificate by AgriTrace+<br>
                Verify at: agritrace.camariensur.ph/verify/${cert.certificate_number}<br>
                <strong style="color:var(--green-dark)">${cert.certificate_number}</strong>
              </div>
            </div>

          </div><!-- /cert-body -->
        </div><!-- /inner border -->
      </div><!-- /outer border -->
    </div><!-- /certificate-doc -->
    `;

    document.getElementById('certPreviewOverlay').classList.add('open');
}

function printCert() {
    const printContent = document.getElementById('certDoc').outerHTML;
    const w = window.open('', '_blank');
    w.document.write(`<!DOCTYPE html><html><head>
        <title>Certificate — ${currentCertData.certificate_number}</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700&family=Lora:ital,wght@0,400;0,500;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
        <style>
        :root{--green-dark:#1a4731;--green-mid:#2d6a4f;--green-light:#52b788;--gold:#c9a84c;--gold-light:#e8c96d;--cream:#fdf8ef;--cream-dark:#f0e8d5;--text-dark:#1c1c1c;--text-mid:#4a4a4a;--text-light:#888;--red:#c0392b;}
        *{box-sizing:border-box;margin:0;padding:0;}
        body{font-family:'DM Sans',sans-serif;background:#fff;}
        .certificate-doc{width:100%;font-family:'Lora',serif;}
        .cert-outer-border{border:12px solid var(--green-dark);margin:16px;padding:0;position:relative;}
        .cert-inner-border{border:3px double var(--gold);margin:5px;padding:0;}
        .cert-watermark{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:340px;height:340px;opacity:0.04;pointer-events:none;z-index:0;}
        .cert-body{padding:36px 40px;position:relative;z-index:1;}
        .cert-header{display:flex;align-items:center;gap:20px;justify-content:center;padding-bottom:18px;border-bottom:2px solid var(--gold);margin-bottom:18px;}
        .cert-logo-circle{width:80px;height:80px;background:linear-gradient(135deg,var(--green-dark),var(--green-mid));border-radius:50%;display:flex;flex-direction:column;align-items:center;justify-content:center;border:3px solid var(--gold);flex-shrink:0;}
        .cert-logo-circle i{color:var(--gold-light);font-size:24px;}
        .cert-logo-circle .logo-label{color:rgba(255,255,255,0.8);font-size:7px;letter-spacing:1px;margin-top:2px;font-family:'DM Sans',sans-serif;text-transform:uppercase;}
        .cert-header-text{text-align:center;}
        .cert-agency{font-family:'DM Sans',sans-serif;font-size:10px;font-weight:600;letter-spacing:2px;text-transform:uppercase;color:var(--text-light);}
        .cert-dept{font-family:'Cinzel',serif;font-size:15px;font-weight:700;color:var(--green-dark);}
        .cert-subdept{font-family:'DM Sans',sans-serif;font-size:10.5px;color:var(--text-mid);}
        .cert-title-band{background:var(--green-dark);text-align:center;padding:12px 0;margin:0 -40px;margin-bottom:22px;}
        .cert-title-band h1{font-family:'Cinzel',serif;font-size:22px;font-weight:700;color:var(--gold-light);letter-spacing:3px;text-transform:uppercase;}
        .cert-title-band p{font-family:'Lora',serif;font-size:11px;color:rgba(255,255,255,0.6);font-style:italic;margin-top:2px;}
        .cert-meta{display:flex;justify-content:space-between;margin-bottom:20px;}
        .cert-meta-item{font-family:'DM Sans',sans-serif;font-size:11px;color:var(--text-mid);}
        .cert-meta-item strong{color:var(--green-dark);display:block;font-size:12.5px;font-weight:600;}
        .cert-issued-to{text-align:center;margin-bottom:16px;}
        .cert-issued-to .label{font-family:'DM Sans',sans-serif;font-size:11px;letter-spacing:2px;text-transform:uppercase;color:var(--text-light);}
        .cert-issued-to .recipient{font-family:'Cinzel',serif;font-size:26px;font-weight:700;color:var(--green-dark);margin:6px 0;}
        .cert-issued-to .address{font-family:'Lora',serif;font-size:13px;color:var(--text-mid);font-style:italic;}
        .cert-body-text{font-family:'Lora',serif;font-size:13px;line-height:1.9;color:var(--text-mid);text-align:center;max-width:580px;margin:0 auto 20px;}
        .cert-body-text em{color:var(--green-dark);font-weight:500;}
        .cert-table{width:100%;border-collapse:collapse;margin:16px 0 24px;font-size:13px;}
        .cert-table th{background:var(--green-dark);color:var(--gold-light);padding:8px 16px;font-family:'DM Sans',sans-serif;font-size:11px;letter-spacing:1px;text-transform:uppercase;font-weight:600;}
        .cert-table td{padding:10px 16px;border:1px solid #e0d8c4;font-family:'Lora',serif;}
        .cert-table tr:nth-child(even) td{background:#fdf8ef;}
        .cert-validity{display:flex;justify-content:center;gap:32px;background:#f0e8d5;padding:14px;border-radius:8px;margin-bottom:24px;border:1px solid #ddd0b0;}
        .cert-validity-item{text-align:center;}
        .cert-validity-item .lbl{font-family:'DM Sans',sans-serif;font-size:10px;letter-spacing:1.5px;text-transform:uppercase;color:var(--text-light);}
        .cert-validity-item .val{font-family:'Cinzel',serif;font-size:14px;color:var(--green-dark);font-weight:700;margin-top:3px;}
        .cert-signatures{display:grid;grid-template-columns:1fr 1fr;gap:32px;margin-top:8px;}
        .cert-sig{text-align:center;}
        .cert-sig-line{border-bottom:1.5px solid var(--text-dark);width:80%;margin:0 auto 8px;}
        .cert-sig-name{font-family:'Cinzel',serif;font-size:13px;color:var(--text-dark);font-weight:600;}
        .cert-sig-title{font-family:'DM Sans',sans-serif;font-size:10.5px;color:var(--text-light);}
        .cert-qr-row{display:flex;align-items:center;justify-content:space-between;margin-top:24px;padding-top:16px;border-top:1.5px solid var(--gold);}
        .cert-qr-box{width:70px;height:70px;background:#f0ece0;border:1px solid #ccc;display:flex;align-items:center;justify-content:center;border-radius:6px;font-size:10px;color:var(--text-light);font-family:'DM Sans',sans-serif;}
        .cert-doc-footer{font-family:'DM Sans',sans-serif;font-size:10px;color:var(--text-light);text-align:right;line-height:1.8;}
        @media print{@page{size:A4 landscape;margin:10mm;}}
        </style>
        </head><body>${printContent}</body></html>`);
    w.document.close();
    setTimeout(() => { w.focus(); w.print(); }, 800);
}

async function downloadPDF() {
    if (!currentCertData) return;
    const { jsPDF } = window.jspdf;
    const element = document.getElementById('certDoc');
    const canvas  = await html2canvas(element, { scale: 2, useCORS: true, backgroundColor: '#ffffff' });
    const imgData = canvas.toDataURL('image/jpeg', 0.95);
    const pdf = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
    const w = pdf.internal.pageSize.getWidth();
    const h = pdf.internal.pageSize.getHeight();
    pdf.addImage(imgData, 'JPEG', 5, 5, w-10, h-10);
    pdf.save(`AgriTrace_Certificate_${currentCertData.certificate_number}.pdf`);
}
</script>
</body>
</html>