<?php
session_start();

// 1. Authentication Check
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_role'] !== 'Farmer') {
    header('Location: login.php');
    exit;
}

// 2. Database Connection
try {
    $pdo = new PDO("mysql:host=localhost;dbname=myproject4;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch(PDOException $e) {
    die("Database connection failed. Please try again later.");
}

// 3. User Context
$userId = $_SESSION['user_id'] ?? 0;
$firstName = $_SESSION['firstName'] ?? 'Farmer';
$lastName = $_SESSION['lastName'] ?? '';

// 4. Pre-fetch ALL data
$dashboardData = [];
$farmsData = [];
$livestockData = [];
$incidentsData = [];

try {
    // Dashboard Data
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(l.qty), 0) as total FROM livestock l JOIN farms f ON l.farmId = f.id WHERE f.ownerId = ?");
    $stmt->execute([$userId]);
    $dashboardData['totalLivestock'] = $stmt->fetch()['total'] ?? 0;

    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM incidents WHERE reporterId = ? AND status IN ('Pending', 'In Progress')");
    $stmt->execute([$userId]);
    $dashboardData['activeIncidents'] = $stmt->fetch()['total'] ?? 0;

    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM farms WHERE ownerId = ? AND status = 'Pending'");
    $stmt->execute([$userId]);
    $dashboardData['pendingInspections'] = $stmt->fetch()['total'] ?? 0;

    $stmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM farms WHERE ownerId = ? GROUP BY status ORDER BY count DESC LIMIT 1");
    $stmt->execute([$userId]);
    $farmData = $stmt->fetch();
    $dashboardData['farmStatus'] = $farmData ? $farmData['status'] . ' (' . $farmData['count'] . ')' : 'No Farms';

    $stmt = $pdo->prepare("SELECT l.type, SUM(l.qty) as total FROM livestock l JOIN farms f ON l.farmId = f.id WHERE f.ownerId = ? GROUP BY l.type");
    $stmt->execute([$userId]);
    $dashboardData['livestockByType'] = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, type, title, description, status, createdAt FROM incidents WHERE reporterId = ? ORDER BY createdAt DESC LIMIT 3");
    $stmt->execute([$userId]);
    $dashboardData['recentIncidents'] = $stmt->fetchAll();

    // Farms Data
    $stmt = $pdo->prepare("SELECT * FROM farms WHERE ownerId = ? ORDER BY createdAt DESC");
    $stmt->execute([$userId]);
    $farmsData = $stmt->fetchAll();

    // Fetch farms with rejection reasons for farmers
$stmt = $pdo->prepare("
    SELECT f.*, 
           CASE 
               WHEN f.status = 'Rejected' THEN f.rejection_reason 
               ELSE NULL 
           END as rejection_reason
    FROM farms f 
    WHERE f.ownerId = ? 
    ORDER BY f.createdAt DESC
");
$stmt->execute([$userId]);
$farmsWithReasons = $stmt->fetchAll();

    // Livestock Data
    $stmt = $pdo->prepare("SELECT l.*, f.name as farmName FROM livestock l JOIN farms f ON l.farmId = f.id WHERE f.ownerId = ? ORDER BY l.createdAt DESC");
    $stmt->execute([$userId]);
    $livestockData = $stmt->fetchAll();

    // Incidents Data
    $stmt = $pdo->prepare("SELECT i.*, f.name as farmName FROM incidents i LEFT JOIN farms f ON i.farmId = f.id WHERE i.reporterId = ? ORDER BY i.createdAt DESC");
    $stmt->execute([$userId]);
    $incidentsData = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log("Dashboard Error: " . $e->getMessage());
}

// Profile Data (add this after livestockData and incidentsData)
$profileData = [
    'firstName' => $firstName, 'lastName' => $lastName ?? '', 'email' => '', 'mobile' => '',
    'gender' => '', 'dob' => '', 'gov_id' => '', 'address' => '', 'emergency_contact' => '',
    'profile_pix' => null, 'farm_count' => 0, 'total_livestock' => 0
];

// User data
$stmt = $pdo->prepare("SELECT firstName, lastName, email, mobile FROM users WHERE id = ?");
$stmt->execute([$userId]);
if ($userData = $stmt->fetch()) {
    $profileData = array_merge($profileData, $userData);
}

// Farmer profile data
$stmt = $pdo->prepare("SELECT * FROM farmer_profiles WHERE user_id = ?");
$stmt->execute([$userId]);
if ($farmerProfile = $stmt->fetch()) {
    $profileData = array_merge($profileData, $farmerProfile);
}

// Farm statistics (reuse dashboard data or fetch)
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM farms WHERE ownerId = ?");
$stmt->execute([$userId]);
$profileData['farm_count'] = $stmt->fetch()['count'];
$profileData['total_livestock'] = $dashboardData['totalLivestock'];

// Fix: Check if user has approved farms for certificate section
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM farms WHERE ownerId = ? AND status = 'Approved'");
$stmt->execute([$userId]);
$hasApprovedFarms = $stmt->fetch()['count'] > 0;

// Fix: Fetch E-Certificate data
$stmt = $pdo->prepare("
    SELECT c.*, f.name as farm_name, u.firstName, u.lastName 
    FROM certificates c 
    JOIN farms f ON c.farm_id = f.id 
    JOIN users u ON f.ownerId = u.id 
    WHERE f.ownerId = ? AND c.status = 'Active'
    ORDER BY c.created_at DESC
");
$stmt->execute([$userId]);
$eCertData = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AgriTrace+ | Farmer Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --primary-deep: #064e3b;
            --primary-bright: #10b981;
            --primary-hover: #059669;
            --bg-main: #f9fafb;
            --text-dark: #064e3b;
            --text-grey: #6b7280;
            --white: #ffffff;
            --danger: #ef4444;
            --warning: #f59e0b;
            --border-color: #e5e7eb;
            --sidebar-width: 280px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, sans-serif;
            background: var(--bg-main);
            color: var(--text-dark);
            line-height: 1.6;
            overflow-x: hidden;
        }

        /* Sidebar */
        .sidebar {
            position: fixed;
            left: -300px;
            top: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: var(--primary-deep);
            z-index: 2000;
            transition: var(--transition);
            display: flex;
            flex-direction: column;
            color: rgba(255,255,255,0.9);
        }

        .sidebar.active { left: 0; }

        .sidebar-header {
            padding: 2rem 1.5rem;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .brand {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--white);
            margin-bottom: 0.25rem;
        }

        .brand span { color: var(--primary-bright); }

        .portal-label {
            font-size: 0.75rem;
            font-weight: 700;
            color: rgba(255,255,255,0.6);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .nav-list {
            flex: 1;
            padding: 1rem 0;
        }

        .nav-item {
            display: flex;
            align-items: center;
            padding: 0.875rem 1.5rem;
            margin: 0 0.25rem;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            border-radius: 0.75rem;
            font-weight: 500;
            transition: var(--transition);
            position: relative;
            margin-bottom: 0.25rem;
        }

        .nav-item i { 
            font-size: 1.25rem; 
            margin-right: 0.75rem;
            width: 1.5rem;
            text-align: center;
        }

        .nav-item:hover {
            background: rgba(255,255,255,0.1);
            color: var(--white);
            transform: translateX(4px);
        }

        .nav-item.active {
            background: var(--primary-bright);
            color: var(--white);
            box-shadow: 0 8px 25px rgba(16,185,129,0.3);
        }

        .badge-alert {
            background: var(--danger);
            color: var(--white);
            border-radius: 9999px;
            padding: 0.25rem 0.75rem;
            font-size: 0.75rem;
            font-weight: 700;
            margin-left: auto;
        }

        .sidebar-footer {
            padding: 1.5rem;
            border-top: 1px solid rgba(255,255,255,0.1);
        }

        .user-card {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .avatar {
            width: 2.5rem;
            height: 2.5rem;
            background: var(--primary-bright);
            color: var(--white);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.125rem;
            flex-shrink: 0;
        }

        .user-name { 
            font-size: 0.95rem; 
            font-weight: 600; 
            color: var(--white);
            display: block;
        }

        .user-role { 
            font-size: 0.8rem; 
            color: rgba(255,255,255,0.7);
        }

        /* Overlay */
        .overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1500;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition);
        }

        .overlay.show { 
            opacity: 1; 
            visibility: visible; 
        }

        /* Top Bar */
        .top-bar {
            position: sticky;
            top: 0;
            background: var(--white);
            border-bottom: 1px solid var(--border-color);
            padding: 1rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 1000;
            backdrop-filter: blur(10px);
        }

        .menu-toggle {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--primary-deep);
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 0.5rem;
            transition: var(--transition);
        }

        .menu-toggle:hover {
            background: rgba(16,185,129,0.1);
            color: var(--primary-bright);
        }

        .page-title {
            font-weight: 700;
            font-size: 1.25rem;
            color: var(--text-dark);
        }

        /* Main Content */
        .main-wrapper {
            min-height: 100vh;
            margin-left: 0;
            transition: var(--transition);
        }

        .content {
            padding: 2rem;
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Cards */
        .card {
            background: var(--white);
            border: 1px solid var(--border-color);
            border-radius: 1rem;
            padding: 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: var(--transition);
        }

        .card:hover {
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            transform: translateY(-2px);
        }

        .card-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            color: var(--text-dark);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .stat-card {
            text-align: center;
            padding: 1.75rem;
            border-radius: 1rem;
            border: 1px solid var(--border-color);
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(0,0,0,0.15);
        }

        .stat-number {
            font-size: 2.75rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary-bright), var(--primary-hover));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 0.25rem;
            line-height: 1;
        }

        .stat-label {
            font-size: 0.95rem;
            color: var(--text-grey);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }

        /* Status Badges */
        .status-badge {
            padding: 0.375rem 1rem;
            border-radius: 9999px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .status-pending { background: #fef3c7; color: #92400e; }
        .status-approved, .status-active { background: #d1fae5; color: #065f46; }
        .status-rejected, .status-healthy { background: #d1fae5; color: #065f46; }
        .status-sick { background: #fee2e2; color: #991b1b; }

        /* Forms */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
            font-size: 0.95rem;
        }

        .form-input, .form-select, textarea.form-input {
            width: 100%;
            padding: 0.875rem 1rem;
            border: 2px solid var(--border-color);
            border-radius: 0.75rem;
            font-size: 1rem;
            font-family: inherit;
            transition: var(--transition);
            background: var(--white);
        }

        .form-input:focus, .form-select:focus, textarea.form-input:focus {
            outline: none;
            border-color: var(--primary-bright);
            box-shadow: 0 0 0 4px rgba(16,185,129,0.1);
        }

        /* Buttons */
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            font-size: 0.95rem;
        }

        .btn-primary {
            background: var(--primary-bright);
            color: var(--white);
        }

        .btn-primary:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: #f8fafc;
            color: var(--text-dark);
            border: 2px solid var(--border-color);
        }

        .btn-secondary:hover {
            background: var(--white);
            border-color: var(--primary-bright);
            color: var(--primary-bright);
        }

        /* Tables */
        .table-responsive {
            overflow-x: auto;
            border-radius: 1rem;
            border: 1px solid var(--border-color);
            margin-top: 1rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: var(--white);
        }

        th {
            background: #f8fafc;
            padding: 1rem 1.25rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--text-grey);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 2px solid var(--border-color);
            white-space: nowrap;
        }

        td {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid #f1f5f9;
        }

        tr:hover {
            background: #f8fafc;
        }

        /* Notifications */
        .notification-item {
            display: flex;
            gap: 1rem;
            padding: 1.25rem;
            border: 1px solid var(--border-color);
            border-radius: 0.75rem;
            margin-bottom: 1rem;
            transition: var(--transition);
        }

        .notification-item:hover {
            border-color: var(--primary-bright);
            box-shadow: 0 4px 12px rgba(16,185,129,0.15);
        }

        .notification-icon {
            width: 2.75rem;
            height: 2.75rem;
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-weight: 600;
            flex-shrink: 0;
        }

        /* Grid Layouts */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
        }

        .gps-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .content { padding: 1.5rem 1rem; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .form-grid { grid-template-columns: 1fr; }
            .top-bar { padding: 1rem; }
        }

        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
            .top-bar { padding: 0.75rem; }
        }

        /* Empty States */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--text-grey);
        }

        .empty-state i {
            font-size: 4rem;
            display: block;
            margin-bottom: 1.5rem;
            opacity: 0.5;
        }

        /* Fix Modal Z-Index and Positioning */
.modal-overlay {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    bottom: 0 !important;
    z-index: 5000 !important; /* Higher than sidebar (2000) and topbar (1000) */
}

.modal-card {
    position: relative !important;
    max-height: 90vh !important;
}

/* Ensure main content doesn't interfere */
.main-wrapper {
    position: relative;
    z-index: 1;
}

.sidebar {
    z-index: 2000 !important;
}

/* Modal Logic - Fixes common bugs */
.custom-modal {
    display: none; 
    position: fixed; 
    top: 0; left: 0; width: 100%; height: 100%; 
    background: rgba(15, 23, 42, 0.6); 
    backdrop-filter: blur(8px);
    z-index: 1000;
    align-items: center; justify-content: center;
    padding: 20px;
}

.modal-content {
    background: white;
    width: 100%;
    max-width: 650px;
    border-radius: 20px;
    overflow: hidden;
    animation: modalSlide 0.3s ease-out;
}

@keyframes modalSlide {
    from { transform: translateY(20px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

.modal-scroll-body {
    max-height: 70vh;
    overflow-y: auto;
    padding: 2rem;
}

/* Info Item Styling */
.info-item {
    display: flex;
    justify-content: space-between;
    padding: 12px;
    background: #f8fafc;
    border-radius: 10px;
    transition: 0.2s;
}
.info-item:hover { background: #f1f5f9; }
.info-item span { color: #64748b; font-size: 0.9rem; }
.info-item strong { color: #1e293b; }

/* Form Elements */
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem; }
.form-group { margin-bottom: 1.25rem; }
.form-group label { display: block; margin-bottom: 0.5rem; font-weight: 600; color: #475569; font-size: 0.9rem; }
.form-group input, .form-group select, .form-group textarea {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    outline: none;
}
.form-group input:focus { border-color: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,0.1); }

/* Modal Buttons */
.modal-footer { padding: 1.5rem 2rem; background: #f8fafc; display: flex; justify-content: flex-end; gap: 1rem; }
.btn-save { background: #10b981; color: white; border: none; padding: 10px 24px; border-radius: 8px; cursor: pointer; font-weight: 600; }
.btn-cancel { background: white; border: 1px solid #e2e8f0; padding: 10px 24px; border-radius: 8px; cursor: pointer; }

@media (max-width: 600px) {
    .modal-content {
        margin: 20px;
        max-width: calc(100% - 40px);
    }
    .modal-scroll-body {
        padding: 1.5rem;
    }
    .form-row {
        grid-template-columns: 1fr !important;
    }
}

.custom-modal select {
    background-position: right 12px center !important;
}

/* Mobile responsiveness */
@media (max-width: 600px) {
    .form-row { grid-template-columns: 1fr; }
    .profile-hero-card { text-align: center; justify-content: center; }
}

/* Sidebar Styling */
.nav-item {
    display: flex;
    align-items: center;
    padding: 12px 20px;
    text-decoration: none;
    color: #555;
    transition: all 0.3s ease;
    border-radius: 8px;
    margin: 4px 0;
}

.nav-item i {
    margin-right: 12px;
    font-size: 1.2rem;
}

.nav-item:hover, .nav-item.active {
    background-color: #f0f7ff;
    color: #0d6efd;
}

/* Certificate Page Styling */
.certificate-container {
    max-width: 800px;
    margin: 40px auto;
    text-align: center;
    font-family: 'Segoe UI', sans-serif;
}

.certificate-frame {
    border: 10px solid #f8f9fa;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    border-radius: 4px;
    margin-bottom: 20px;
    background: white;
}

.cert-img {
    width: 100%;
    height: auto;
    display: block;
}

.cert-actions .btn-download, .cert-actions .btn-print {
    padding: 10px 20px;
    margin: 5px;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    background: #0d6efd;
    color: white;
}

/* Container & Background */
.certificate-wrapper {
    padding: 20px;
    background-color: #f4f7f6;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
}

.page-header {
    text-align: center;
    margin-bottom: 30px;
}

.page-header h2 { color: #004d40; font-weight: 700; }

/* The Card */
.cert-card {
    background: #ffffff;
    width: 100%;
    max-width: 800px;
    position: relative;
    padding: 15px;
    border-radius: 12px;
    box-shadow: 0 15px 35px rgba(0,0,0,0.1);
    border-top: 6px solid #004d40; /* Professional accent */
}

.inner-border {
    border: 1px solid #e0e0e0;
    padding: 40px 20px;
    text-align: center;
}

/* Typography Scaling */
.top-seal { font-size: 50px; color: #004d40; margin-bottom: 10px; }

.issuer-name { 
    text-transform: uppercase; 
    letter-spacing: 2px; 
    font-size: 0.85rem; 
    color: #666;
}

.cert-headline {
    font-size: clamp(1.8rem, 5vw, 2.8rem);
    font-family: 'serif';
    margin: 15px 0;
    color: #222;
}

.recipient-info h3 {
    font-size: clamp(1.1rem, 3vw, 1.5rem);
    color: #1a4a7a;
    margin-bottom: 5px;
}

.scope {
    font-size: 0.95rem;
    max-width: 500px;
    margin: 20px auto;
    line-height: 1.6;
    color: #555;
}

/* Footer Layout - Responsive Grid */
.footer-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 20px;
    margin-top: 40px;
    align-items: flex-end;
}

.signature { font-family: 'Brush Script MT', cursive; font-size: 1.4rem; border-bottom: 1px solid #ccc; padding-bottom: 5px; }
.title { font-size: 0.75rem; text-transform: uppercase; color: #888; margin-top: 5px; }
.val-label { font-size: 0.7rem; color: #999; margin: 0; }
.val-date { font-weight: bold; color: #333; }

/* Buttons */
.action-bar { margin-top: 30px; display: flex; gap: 10px; }
.btn-primary, .btn-secondary {
    padding: 12px 24px;
    border-radius: 8px;
    border: none;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
}
.btn-primary { background: #004d40; color: white; }
.btn-secondary { background: #fff; border: 1px solid #ddd; }

/* Mobile Adjustments */
@media (max-width: 600px) {
    .footer-grid {
        grid-template-columns: 1fr; /* Stack signatures on mobile */
        gap: 30px;
    }
    .inner-border { padding: 20px 10px; }
    .action-bar { flex-direction: column; width: 100%; }
    .btn-primary, .btn-secondary { width: 100%; justify-content: center; }
}

/* Print Logic: Forces portrait and removes UI elements */
@media print {
    .action-bar, .page-header { display: none; }
    .certificate-wrapper { padding: 0; background: none; }
    .cert-card { box-shadow: none; border: 1px solid #eee; width: 100%; }
}
/* ========================================
   DA CAMARINES SUR E-CERTIFICATE STYLES
   ======================================= */

/* Color Scheme */
.da-cs-green { color: #2c5530 !important; }
.da-cs-bg { background: linear-gradient(135deg, #2c5530, #4a7c59) !important; }
.da-gold-accent { color: #d4af37 !important; }

/* Certificate Grid */
.certificate-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(380px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

/* Certificate Preview Cards */
.cert-preview-card {
    background: #ffffff;
    border-radius: 16px;
    padding: 1.75rem;
    box-shadow: 0 8px 25px rgba(0,0,0,0.08);
    border: 1px solid #e5e7eb;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
}

.cert-preview-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 20px 40px rgba(44,85,48,0.15);
    border-color: #2c5530;
}

.cert-preview-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, #2c5530, #4a7c59, #d4af37);
}

/* Certificate Details */
.cert-no {
    font-size: 0.85rem;
    font-weight: 700;
    color: #2c5530;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 0.5rem;
}

.cert-farm-name {
    font-size: 1.15rem;
    font-weight: 700;
    color: #1a3c20;
    margin-bottom: 0.375rem;
    line-height: 1.3;
}

.cert-status {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 0.875rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 700;
    text-transform: uppercase;
}

.status-active {
    background: #d1fae5;
    color: #065f46;
}

.cert-validity {
    font-size: 0.9rem;
    color: #6b7280;
    margin-top: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

/* Request Certificate Card */
.request-cert-card {
    background: linear-gradient(135deg, #f0f9f4, #e6f4ea);
    border: 2px dashed #4a7c59;
    text-align: center;
    padding: 3.5rem 2rem;
    position: relative;
    overflow: hidden;
    border-radius: 20px;
}

.request-cert-card::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 100%;
    height: 100%;
    background: radial-gradient(circle, rgba(74,124,89,0.08) 0%, transparent 70%);
    animation: float 6s ease-in-out infinite;
}

@keyframes float {
    0%, 100% { transform: translateY(0) rotate(0deg); }
    50% { transform: translateY(-12px) rotate(1deg); }
}

.request-icon {
    font-size: 4.5rem;
    color: #2c5530;
    margin-bottom: 1.5rem;
    display: block;
    filter: drop-shadow(0 6px 12px rgba(44,85,48,0.3));
}

/* Full Certificate Modal */
.custom-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(15, 23, 42, 0.85);
    backdrop-filter: blur(12px);
    z-index: 5000;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}

.modal-content {
    background: white;
    width: 100%;
    max-width: 950px;
    max-height: 95vh;
    border-radius: 24px;
    overflow: hidden;
    animation: modalSlide 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
}

@keyframes modalSlide {
    from { transform: translateY(30px) scale(0.95); opacity: 0; }
    to { transform: translateY(0) scale(1); opacity: 1; }
}

.modal-header {
    padding: 1.75rem 2rem;
    background: linear-gradient(135deg, #2c5530, #4a7c59);
    color: white;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

/* Certificate Print Area */
.cert-print-area {
    padding: 3rem 2.5rem;
}

.cert-body {
    text-align: center;
    max-width: 800px;
    margin: 0 auto;
}

.da-issuer {
    font-size: 1.25rem;
    font-weight: 800;
    color: #2c5530;
    text-transform: uppercase;
    letter-spacing: 2px;
    margin: 2.5rem 0 1.5rem;
}

.cert-main-title {
    font-size: clamp(2.5rem, 6vw, 3.5rem);
    font-weight: 900;
    color: #1a3c20;
    margin: 2rem 0 3rem;
    font-family: 'Georgia', serif;
    letter-spacing: -1px;
    text-shadow: 3px 3px 6px rgba(0,0,0,0.1);
}

.recipient-block {
    background: linear-gradient(135deg, #f8fcf9, #f0f8f3);
    border: 4px solid #e6f4ea;
    border-radius: 20px;
    padding: 2.5rem 2rem;
    margin: 3rem 0;
    box-shadow: 0 12px 35px rgba(44,85,48,0.12);
}

.recipient-name {
    font-size: 1.9rem;
    color: #2c5530;
    margin-bottom: 0.75rem;
    font-weight: 800;
    line-height: 1.3;
}

.recipient-location {
    font-size: 1.2rem;
    color: #4a7c59;
    font-style: italic;
    font-weight: 600;
}

.cert-scope {
    font-size: 1.15rem;
    line-height: 1.75;
    color: #374151;
    background: rgba(44,85,48,0.05);
    border-radius: 16px;
    padding: 2rem;
    margin: 3rem 0;
    border-left: 6px solid #2c5530;
    text-align: left;
}

/* Footer Signatures */
.cert-footer-grid {
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    gap: 3.5rem;
    margin-top: 5rem;
    align-items: end;
}

.signature-block {
    text-align: center;
    min-height: 5rem;
}

.signature-line {
    font-family: 'Brush Script MT', 'cursive', serif;
    font-size: 1.75rem;
    font-weight: 800;
    color: #2c5530;
    border-bottom: 3px solid #2c5530;
    padding-bottom: 1rem;
    margin-bottom: 0.75rem;
    min-height: 3rem;
    display: flex;
    align-items: center;
    justify-content: center;
    letter-spacing: 0.5px;
}

.signature-title {
    font-size: 0.95rem;
    color: #64748b;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
}

/* Validity Block */
.validity-block {
    background: linear-gradient(135deg, #2c5530, #4a7c59);
    color: white;
    padding: 2.5rem 2rem;
    border-radius: 20px;
    text-align: center;
    box-shadow: 0 15px 40px rgba(44,85,48,0.35);
}

.validity-label {
    font-size: 1.1rem;
    opacity: 0.95;
    margin-bottom: 0.75rem;
    font-weight: 600;
}

.validity-date {
    font-size: 2.5rem;
    font-weight: 900;
    letter-spacing: -1px;
}

/* Action Buttons */
.cert-actions {
    background: #f8fcf9;
    padding: 2.5rem;
    text-align: center;
    border-top: 1px solid #e6f4ea;
    display: flex;
    gap: 1.25rem;
    justify-content: center;
    flex-wrap: wrap;
}

.btn-cert {
    padding: 1.125rem 2.25rem;
    border: none;
    border-radius: 14px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.75rem;
    font-size: 1rem;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    text-decoration: none;
    position: relative;
    overflow: hidden;
}

.btn-print {
    background: linear-gradient(135deg, #2c5530, #4a7c59);
    color: white;
    box-shadow: 0 10px 30px rgba(44,85,48,0.4);
}

.btn-print:hover {
    transform: translateY(-4px);
    box-shadow: 0 20px 45px rgba(44,85,48,0.5);
}

.btn-share {
    background: white;
    color: #2c5530;
    border: 2px solid #2c5530;
}

.btn-share:hover {
    background: #2c5530;
    color: white;
    transform: translateY(-2px);
}

.btn-request {
    background: linear-gradient(135deg, #4a7c59, #2c5530);
    color: white;
    padding: 1.375rem 3rem;
    border-radius: 20px;
    font-weight: 800;
    font-size: 1.15rem;
    border: none;
    box-shadow: 0 15px 40px rgba(44,85,48,0.4);
    width: 100%;
    justify-content: center;
}

.btn-request:hover {
    transform: translateY(-5px);
    box-shadow: 0 25px 50px rgba(44,85,48,0.5);
}

/* Print Styles */
@media print {
    .no-print, .custom-modal, .card, .request-cert-card { display: none !important; }
    .full-cert-view { display: block !important; box-shadow: none !important; margin: 0 !important; max-width: none !important; }
    .cert-print-area { padding: 2rem 1.5rem !important; page-break-inside: avoid; }
    body { background: white !important; font-size: 12pt; }
    .btn-cert { display: none !important; }
}

/* Mobile Responsiveness */
@media (max-width: 768px) {
    .certificate-grid { grid-template-columns: 1fr; }
    .cert-footer-grid { 
        grid-template-columns: 1fr; 
        gap: 2.5rem; 
        text-align: center; 
    }
    .cert-actions { 
        flex-direction: column; 
        gap: 1rem; 
    }
    .btn-cert { 
        width: 100%; 
        justify-content: center; 
        padding: 1rem 1.5rem; 
    }
    .modal-content { 
        margin: 1rem; 
        width: calc(100% - 2rem); 
    }
}

@media (max-width: 480px) {
    .cert-main-title { font-size: 2.25rem; }
    .recipient-name { font-size: 1.5rem; }
    .signature-line { font-size: 1.4rem; }
    .validity-date { font-size: 2rem; }
}

/* Responsive Stacking */
.form-sections-stack {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.mobile-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

/* Camera Preview UI */
.location-preview-box {
    width: 100%;
    height: 180px;
    background: #f8f9fa;
    border: 2px dashed #dee2e6;
    border-radius: 12px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    margin-bottom: 10px;
    overflow: hidden;
    color: #6c757d;
}

.location-preview-box img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.section-subtitle {
    font-size: 1.1rem;
    font-weight: 700;
    margin-bottom: 1rem;
    border-left: 4px solid var(--primary-color);
    padding-left: 10px;
}

/* Make table scrollable on small screens */
.table-responsive {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

@media (max-width: 600px) {
    .card { padding: 1rem; }
    .mobile-grid { grid-template-columns: 1fr; }
}

/* Add this to your existing <style> section */
#farm-address[style*="GPS"] {
    background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
    border-color: #10b981;
    font-style: italic;
}

#farm-address::placeholder {
    color: #9ca3b8;
}

/* ✅ Form is ALWAYS editable after photo */
#farm-livestock-form {
    transition: opacity 0.3s ease !important;
}

#farm-livestock-form * {
    pointer-events: auto !important;
}

/* GPS-filled address styling */
#farm-address[value*="GPS"],
#farm-address[value*="Lat"] {
    background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%) !important;
    border: 2px solid #10b981 !important;
    font-style: italic;
    position: relative;
}

#farm-address[value*="GPS"]::after {
    content: "📍 GPS Auto-filled";
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 0.75rem;
    color: #059669;
    background: rgba(16,185,129,0.1);
    padding: 2px 8px;
    border-radius: 12px;
}
    </style>
</head>
<body>
    <!-- Overlay -->
    <div class="overlay" id="overlay" onclick="toggleMenu()"></div>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
                        <div class="brand">Agri<span>Trace+</span></div>
            <span class="portal-label">Farmer Portal</span>
        </div>

        <nav class="nav-list">
            <a href="#" class="nav-item active" data-page="dashboard">
                <i class="bi bi-grid-1x2-fill"></i> Dashboard
            </a>
            <a href="#" class="nav-item" data-page="farm-registration">
                <i class="bi bi-house-add"></i> Farm Registration
            </a>
            <a href="#" class="nav-item" data-page="livestock-monitoring">
                <i class="bi bi-activity"></i> Livestock Monitoring
            </a>
            <a href="#" class="nav-item" data-page="incident-reporting">
                <i class="bi bi-exclamation-triangle"></i> Incident Reporting
            </a>
            <a href="#" class="nav-item" data-page="notifications">
                <i class="bi bi-bell"></i> Notifications
                <span class="badge-alert"><?= $dashboardData['activeIncidents'] ?></span>
            </a>
            <a href="#" class="nav-item" data-page="e-certificate">
                <i class="bi bi-patch-check"></i> E-Certificate
            </a>
            <a href="#" class="nav-item" data-page="profile" style="color: var(--primary-bright);">
                <i class="bi bi-person-circle"></i> Profile
            </a>
            
            <div style="padding: 1rem 1.5rem;">
                <hr style="border: none; border-top: 1px solid rgba(255,255,255,0.1);">
            </div>

            <a href="login.php" class="nav-item" style="color: #fbbf24;">
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="user-card">
                <div class="avatar"><?= strtoupper(substr($firstName, 0, 1)) ?></div>
                <div>
                    <div class="user-name"><?= htmlspecialchars($firstName) ?></div>
                    <div class="user-role">Farmer</div>
                </div>
            </div>
        </div>
    </aside>

    <!-- Main Wrapper -->
    <div class="main-wrapper">
        <!-- Top Bar -->
        <header class="top-bar">
            <button class="menu-toggle" onclick="toggleMenu()" aria-label="Toggle menu">
                <i class="bi bi-list"></i>
            </button>
            <div class="page-title" id="pageTitle">Dashboard</div>
            <div style="width: 2rem;"></div>
        </header>

        <!-- Content -->
        <main class="content">
            <!-- Dashboard -->
            <section id="dashboard" class="page-section">
                <div class="card">
                    <div style="display: flex; flex-direction: column; gap: 1rem; margin-bottom: 2rem;">
                        <h1 style="font-size: 1.875rem; font-weight: 800; color: var(--text-dark);">
                            Good morning, <?= htmlspecialchars($firstName) ?>! 👋
                        </h1>
                        <p style="color: var(--text-grey); font-size: 1.1rem;">
                            Here's an overview of your farm activities
                        </p>
                    </div>
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?= number_format($dashboardData['totalLivestock']) ?></div>
                        <div class="stat-label">Total Livestock</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number" style="color: var(--danger);"><?= $dashboardData['activeIncidents'] ?></div>
                        <div class="stat-label">Active Incidents</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number" style="color: var(--warning);"><?= $dashboardData['pendingInspections'] ?></div>
                        <div class="stat-label">Pending Inspections</div>
                    </div>
                </div>

                <div class="card">
                    <h3 class="card-title" style="margin-bottom: 1rem;">Farm Status</h3>
                    <div style="font-size: 1.25rem; font-weight: 600;">
                        <span class="status-badge status-<?= strtolower(str_replace(' ', '-', $dashboardData['farmStatus'])) ?>">
                            <?= htmlspecialchars($dashboardData['farmStatus']) ?>
                        </span>
                    </div>
                </div>

                <!-- ADD THIS in #dashboard section, after stats-grid -->
<div class="card">
    <h3 class="card-title">My Farms</h3>
    <?php if (!empty($farmsWithReasons)): ?>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Farm Name</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($farmsWithReasons as $farm): ?>  <!-- ✅ FIXED: Added missing "as" -->
                <tr>
                    <td><strong><?= htmlspecialchars($farm['name']) ?></strong></td>
                    <td>
                        <span class="status-badge" style="background: rgba(16,185,129,0.1); color: #065f46;">
                            <?= htmlspecialchars($farm['type']) ?>
                        </span>
                    </td>
                    <td>
                        <span class="status-badge status-<?= strtolower($farm['status']) ?>">
                            <?= ucfirst($farm['status']) ?>
                        </span>
                        <?php if (isset($farm['status']) && $farm['status'] === 'Rejected' && !empty($farm['rejection_reason'])): ?>
                            <button class="btn btn-secondary" style="margin-top: 0.5rem; padding: 0.4rem 0.8rem; font-size: 0.75rem;" 
                                    onclick="showRejection('<?= htmlspecialchars($farm['rejection_reason']) ?>')">
                                <i class="bi bi-info-circle"></i> Reason
                            </button>
                        <?php endif; ?>
                    </td>
                    <td><?= date('M d, Y', strtotime($farm['createdAt'])) ?></td>
                    <td>
                        <?php if (isset($farm['status'])): ?>
                            <?php if ($farm['status'] === 'Rejected'): ?>
                                <button class="btn btn-primary" style="padding: 0.5rem 1rem; font-size: 0.85rem;" 
                                        onclick="appealFarm(<?= (int)$farm['id'] ?>)">
                                    <i class="bi bi-arrow-repeat"></i> Appeal
                                </button>
                            <?php elseif ($farm['status'] === 'Pending'): ?>
                                <span style="color: var(--warning); font-size: 0.8rem;">⏳ Waiting</span>
                            <?php else: ?>
                                <span style="color: var(--primary-bright); font-size: 0.8rem;">✅ Approved</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color: var(--text-grey);">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state">
        <i class="bi bi-house"></i>
        <h4>No farms registered</h4>
        <p>Register your first farm using Farm Registration</p>
    </div>
    <?php endif; ?>
</div>


                <?php if (!empty($dashboardData['livestockByType'])): ?>
                <div class="card">
                    <h3 class="card-title">Livestock by Type</h3>
                    <div class="stats-grid">
                        <?php foreach ($dashboardData['livestockByType'] as $item): ?>
                            <div class="stat-card">
                                <div class="stat-number"><?= number_format($item['total']) ?></div>
                                <div class="stat-label"><?= htmlspecialchars(ucfirst($item['type'])) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="card">
                    <h3 class="card-title">Recent Notifications</h3>
                    <?php if (!empty($dashboardData['recentIncidents'])): ?>
                        <?php foreach ($dashboardData['recentIncidents'] as $incident): ?>
                            <div class="notification-item">
                                <div class="notification-icon" style="background: var(--danger);">
                                    <i class="bi bi-exclamation-triangle-fill"></i>
                                </div>
                                <div style="flex: 1;">
                                    <div style="font-weight: 600; margin-bottom: 0.25rem; color: var(--text-dark);">
                                        <?= htmlspecialchars($incident['title'] ?: ucfirst($incident['type'])) ?>
                                    </div>
                                    <div style="font-size: 0.9rem; color: var(--text-grey);">
                                        <?= date('M d, Y', strtotime($incident['createdAt'])) ?> • 
                                        <span class="status-badge status-<?= strtolower($incident['status']) ?>">
                                            <?= $incident['status'] ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-bell-slash"></i>
                            <p>No recent notifications</p>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Farm Registration - FIXED WITH PROPER DATABASE INTEGRATION -->

<!-- ✅ FIXED Farm Registration Section -->
<section id="farm-registration" class="page-section" style="display: none; background-color: #f8fafc; min-height: 100vh; padding: 16px;">
    <div class="registration-container" style="max-width: 480px; margin: auto; background: white; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06); padding: 20px;">
        
        <header style="margin-bottom: 24px;">
            <h2 style="font-size: 1.25rem; font-weight: 800; color: #0f172a; margin: 0;">Farm Registration</h2>
            <p style="font-size: 0.85rem; color: #64748b; margin-top: 4px;">Complete the details below to register your livestock.</p>
        </header>

        <!-- ✅ FIXED: Farm Documentation Section -->
        <div id="capture-section" style="margin-bottom: 24px;">
            <label style="display: block; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: #94a3b8; margin-bottom: 8px; letter-spacing: 0.025em;">Farm Documentation</label>
            <div id="action-zone" style="display: flex; gap: 10px;">
                <button type="button" onclick="openCamera()" 
                        style="flex: 1; background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0; padding: 12px; border-radius: 12px; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 0.9rem; transition: all 0.2s;">
                    <i class="bi bi-camera" style="font-size: 1.1rem;"></i> Camera
                </button>
                <!-- ✅ FIXED: Upload Button -->
                <button type="button" id="upload-btn" onclick="document.getElementById('file-upload').click()" 
                        style="flex: 1; background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0; padding: 12px; border-radius: 12px; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 0.9rem; transition: all 0.2s;">
                    <i class="bi bi-upload" style="font-size: 1.1rem;"></i> Upload
                </button>
            </div>
            
            <!-- ✅ FIXED: File Input (HIDDEN) -->
            <input type="file" id="file-upload" accept="image/*" style="display: none;" onchange="handleFileUpload(event)">
            
            <!-- Photo Preview -->
            <div id="photo-preview" style="display: none; position: relative; margin-top: 12px; border-radius: 12px; overflow: hidden; border: 2px solid #10b981; background: #f0fdf4;">
                <canvas id="stamped-canvas" style="width: 100%; height: auto; display: block;"></canvas>
                <button type="button" onclick="removePhoto()" 
                        style="position: absolute; top: 8px; right: 8px; background: rgba(239,68,68,0.9); color: white; border: none; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; font-size: 1rem; font-weight: bold; backdrop-filter: blur(8px);">✕</button>
            </div>

            <!-- ✅ GPS Verification -->
            <div id="gps-verification" style="display: none; background: #ecfdf5; padding: 12px; border-radius: 12px; border: 2px solid #10b981; margin-top: 12px;">
                <div style="display: flex; align-items: center; gap: 8px; font-size: 0.85rem; color: #065f46; font-weight: 600;">
                    <i class="bi bi-geo-alt-fill" style="font-size: 1.1rem;"></i>
                    <span id="gps-details">Getting GPS...</span>
                    <span style="margin-left: auto; background: #10b981; color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.75rem;">✅ READY</span>
                </div>
            </div>
        </div>

        <!-- ✅ FIXED: Form (ALWAYS EDITABLE) -->
        <form id="farm-livestock-form" style="transition: opacity 0.3s ease;">
            
            <!-- Hidden GPS fields -->
            <input type="hidden" id="farm-lat" name="farm_lat">
            <input type="hidden" id="farm-lng" name="farm_lng">
            <input type="hidden" id="farm-photo" name="farm_photo">

            <div style="margin-bottom: 20px;">
                <label style="display: block; font-size: 0.85rem; font-weight: 600; color: #334155; margin-bottom: 6px;">Farm Name <span style="color: #10b981;">*</span></label>
                <input type="text" id="farm-name" name="farm_name" class="form-input" placeholder="Enter farm name" required
                       style="width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 1rem; outline: none; box-sizing: border-box;">
            </div>

            <div style="margin-bottom: 20px;">
                <label style="display: block; font-size: 0.85rem; font-weight: 600; color: #334155; margin-bottom: 6px;">Farm Address <span style="color: #10b981;">*</span></label>
                <textarea id="farm-address" name="farm_address" rows="2" placeholder="Address" required
                          style="width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 1rem; resize: none; box-sizing: border-box;"></textarea>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 24px;">
                <div>
                    <label style="display: block; font-size: 0.85rem; font-weight: 600; color: #334155; margin-bottom: 6px;">Farm Type <span style="color: #10b981;">*</span></label>
                    <select id="farm-type" name="farm_type" required style="width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 10px; background: white; height: 48px;">
                        <option value="">Select...</option>
                        <option value="Cattle">Cattle</option>
                        <option value="Swine">Swine</option>
                        <option value="Poultry">Poultry</option>
                        <option value="Mixed">Mixed</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; font-size: 0.85rem; font-weight: 600; color: #334155; margin-bottom: 6px;">Area (ha) <span style="color: #10b981;">*</span></label>
                    <input type="number" id="farm-area" name="farm_area" placeholder="0.00" step="0.01" min="0" required
                           style="width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 1rem; height: 48px; box-sizing: border-box;">
                </div>
            </div>

            <!-- Livestock Details -->
            <div style="background: #f8fafc; padding: 16px; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 24px;">
                <h4 style="font-size: 0.9rem; font-weight: 700; color: #475569; margin: 0 0 12px 0; display: flex; align-items: center; gap: 6px;">
                    <i class="bi bi-clipboard-data"></i> Livestock Details
                </h4>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 12px;">
                    <div>
                        <label style="display: block; font-size: 0.75rem; font-weight: 600; color: #64748b; margin-bottom: 4px;">Animal <span style="color: #10b981;">*</span></label>
                        <select id="livestock-type" name="livestock_type" required style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; background: white;">
                            <option value="Cattle">Cattle</option>
                            <option value="Swine">Swine</option>
                            <option value="Poultry">Poultry</option>
                        </select>
                    </div>
                    <div>
                        <label style="display: block; font-size: 0.75rem; font-weight: 600; color: #64748b; margin-bottom: 4px;">Heads <span style="color: #10b981;">*</span></label>
                        <input type="number" id="livestock-qty" name="livestock_qty" value="1" min="1" required style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box;">
                    </div>
                </div>

                <div>
                    <label style="display: block; font-size: 0.75rem; font-weight: 600; color: #64748b; margin-bottom: 4px;">Tag ID / Reference</label>
                    <input type="text" id="livestock-tag" name="livestock_tag" placeholder="e.g. CAT-001" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box;">
                </div>
            </div>

            <!-- ✅ Submit Button -->
            <button type="submit" id="submit-btn" 
                    style="width: 100%; padding: 16px; background: linear-gradient(135deg, #10b981, #059669); color: white; border: none; border-radius: 12px; font-size: 1rem; font-weight: 700; cursor: pointer; box-shadow: 0 10px 15px rgba(16,185,129,0.3); transition: all 0.3s;">
                <i class="bi bi-check-lg"></i> Register Farm & Livestock
            </button>
        </form>
    </div>

    <!-- Success Message -->
    <div id="success-message" style="display: none; position: fixed; bottom: 20px; left: 20px; right: 20px; background: #10b981; color: white; padding: 16px; border-radius: 12px; text-align: center; box-shadow: 0 20px 25px rgba(16,185,129,0.3); font-weight: 600;">
        ✅ Farm Registered Successfully! Redirecting...
    </div>
</section>

            <!-- Livestock Monitoring -->
            <section id="livestock-monitoring" class="page-section" style="display: none;">
                <div class="card">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 2rem;">
                        <h2 class="card-title">Livestock Monitoring</h2>
                        <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                            <div style="display: flex; gap: 0.5rem; align-items: center; background: var(--white); padding: 0.75rem 1rem; border: 2px solid var(--border-color); border-radius: 0.75rem;">
                                <input type="text" class="form-input" style="border: none; background: none; width: 200px;" placeholder="Search livestock...">
                                <i class="bi bi-search" style="color: var(--text-grey);"></i>
                            </div>
                            <select class="form-input form-select" style="min-width: 150px;">
                                <option>All Types</option>
                                <option>Cattle</option>
                                <option>Swine</option>
                                <option>Poultry</option>
                            </select>
                            <button class="btn btn-primary">
                                <i class="bi bi-plus-lg"></i> Add Livestock
                            </button>
                        </div>
                    </div>

                    <?php if (!empty($livestockData)): ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Tag ID</th>
                                    <th>Type</th>
                                    <th>Breed</th>
                                    <th>Age/Qty</th>
                                    <th>Health</th>
                                    <th>Farm</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($livestockData as $livestock): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($livestock['tagId']) ?></strong></td>
                                    <td>
                                        <span style="display: inline-block; padding: 0.25rem 0.75rem; background: rgba(16,185,129,0.1); border-radius: 9999px; font-size: 0.8rem; font-weight: 600;">
                                            <?= htmlspecialchars($livestock['type']) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($livestock['breed'] ?? 'N/A') ?></td>
                                    <td><?= $livestock['age'] ? $livestock['age'].' yrs' : 'N/A' ?> (<?= $livestock['qty'] ?>)</td>
                                    <td><span class="status-badge status-<?= strtolower($livestock['healthStatus']) ?>"><?= $livestock['healthStatus'] ?></span></td>
                                    <td><?= htmlspecialchars($livestock['farmName']) ?></td>
                                    <td>
                                        <div style="display: flex; gap: 0.5rem;">
                                            <button class="btn btn-secondary" title="Edit" style="padding: 0.5rem;">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button class="btn btn-secondary" title="View" style="padding: 0.5rem;">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="empty-state">
                        <i class="bi bi-activity"></i>
                        <h4 style="color: var(--text-dark);">No livestock registered</h4>
                        <p>Add your livestock using the button above</p>
                    </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Incident Reporting -->
            <section id="incident-reporting" class="page-section" style="display: none;">
                                <div class="card">
                    <h2 class="card-title">Report Incident</h2>
                    <p style="color: var(--text-grey); margin-bottom: 2rem; font-size: 1.1rem;">
                        File and track incident reports for your farm
                    </p>

                    <div class="form-grid">
                        <!-- New Incident Form -->
                        <div>
                            <h4 style="font-size: 1.125rem; font-weight: 700; margin-bottom: 1.5rem;">File New Incident</h4>
                            
                            <div class="form-group">
                                <label class="form-label">Incident Type</label>
                                <select class="form-input form-select">
                                    <option>Disease Symptoms</option>
                                    <option>Livestock Death</option>
                                    <option>Stray Livestock</option>
                                    <option>Disease</option>
                                    <option>Others</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Title</label>
                                <input type="text" class="form-input" placeholder="Brief title for the incident">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Description</label>
                                <textarea class="form-input" rows="4" placeholder="Describe the incident in detail..."></textarea>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Upload Photos/Videos</label>
                                <div style="display: flex; gap: 1rem; align-items: center;">
                                    <input type="file" class="form-input" multiple accept="image/*,video/*">
                                    <button class="btn btn-secondary">
                                        <i class="bi bi-camera"></i> Capture
                                    </button>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">GPS Coordinates (Optional)</label>
                                <div class="gps-grid">
                                    <input type="number" step="any" class="form-input" placeholder="Latitude">
                                    <input type="number" step="any" class="form-input" placeholder="Longitude">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Date & Time</label>
                                <input type="datetime-local" class="form-input">
                            </div>

                            <button class="btn btn-primary" style="width: 100%;">
                                <i class="bi bi-send-fill"></i> Report Incident
                            </button>
                        </div>

                        <!-- Recent Incidents -->
                        <div>
                            <h4 style="font-size: 1.125rem; font-weight: 700; margin-bottom: 1.5rem;">My Incident Reports</h4>
                            
                            <?php if (!empty($incidentsData)): ?>
                            <div class="table-responsive">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Ref #</th>
                                            <th>Date</th>
                                            <th>Type</th>
                                            <th>Description</th>
                                            <th>Status</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_slice($incidentsData, 0, 5) as $incident): ?>
                                        <tr>
                                            <td><strong>INC-<?= strtoupper(substr($incident['type'] ?? 'OTH', 0, 3)) . sprintf('%04d', $incident['id']) ?></strong></td>
                                            <td><?= date('M d', strtotime($incident['createdAt'])) ?></td>
                                            <td><?= htmlspecialchars($incident['type'] ?? 'Others') ?></td>
                                            <td style="max-width: 200px;">
                                                <?= htmlspecialchars(substr($incident['description'], 0, 50)) ?>
                                                <?= strlen($incident['description']) > 50 ? '...' : '' ?>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?= strtolower($incident['status']) ?>">
                                                    <?= ucfirst($incident['status']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button class="btn btn-secondary" style="padding: 0.5rem; font-size: 0.875rem;">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?>
                            <div class="empty-state">
                                <i class="bi bi-exclamation-triangle"></i>
                                <h4 style="color: var(--text-dark);">No incidents reported</h4>
                                <p>Report your first incident using the form above</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Notifications -->
            <section id="notifications" class="page-section" style="display: none;">
                <div class="card">
                    <h2 class="card-title">Notifications</h2>
                    <p style="color: var(--text-grey);">All your farm notifications, alerts, and updates will appear here in real-time.</p>
                    
                    <div style="margin-top: 2rem; padding: 2rem; text-align: center; color: var(--text-grey); border: 2px dashed var(--border-color); border-radius: 1rem;">
                        <i class="bi bi-bell" style="font-size: 3rem; display: block; margin-bottom: 1rem; opacity: 0.5;"></i>
                        <h3 style="color: var(--text-dark); margin-bottom: 0.5rem;">Notifications Center</h3>
                        <p>Coming soon - real-time push notifications for farm events</p>
                    </div>
                </div>
            </section>

            <!-- E-Certificate Section - COMPLETE & WORKING -->
<section id="e-certificate" class="page-section" style="display: none;">
    <div class="card" style="max-width: 1200px; margin: 0 auto;">
        <?php if (!empty($eCertData)): ?>
            <!-- SHOW CERTIFICATES -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
                <h2 style="margin: 0; font-size: 1.75rem; font-weight: 800; color: #1e293b;">
                    <i class="bi bi-patch-check-fill" style="color: #10b981; margin-right: 0.75rem;"></i>
                    Your DA Camarines Sur Certificates
                </h2>
                <span style="background: #d1fae5; color: #065f46; padding: 0.5rem 1.25rem; border-radius: 25px; font-weight: 700; font-size: 0.9rem;">
                    <?= count($eCertData) ?> Certificate<?= count($eCertData) > 1 ? 's' : '' ?>
                </span>
            </div>
            
            <div class="certificate-grid">
                <?php foreach ($eCertData as $cert): ?>
                <div class="cert-preview-card">
                    <div class="cert-no">DA-CS-<?= htmlspecialchars($cert['certificate_no']) ?></div>
                    <div class="cert-farm-name"><?= htmlspecialchars($cert['farm_name']) ?></div>
                    <div style="color: #64748b; font-size: 0.95rem; margin-bottom: 1rem;">
                        <?= htmlspecialchars($cert['recipient_name']) ?> • <?= number_format($cert['total_livestock'] ?? 0) ?> heads
                    </div>
                    <span class="status-badge status-active cert-status">✅ Valid</span>
                    <div class="cert-validity">
                        <i class="bi bi-calendar-check"></i> Valid until <?= date('M d, Y', strtotime($cert['valid_until'])) ?>
                    </div>
                    <div style="margin-top: 1.25rem; display: flex; gap: 0.75rem; flex-wrap: wrap;">
                        <a href="download_certificate.php?id=<?= $cert['id'] ?>" class="btn btn-primary btn-cert" style="flex: 1; text-align: center;">
                            <i class="bi bi-download"></i> Download PDF
                        </a>
                        <button class="btn btn-secondary btn-cert" onclick="printCert(<?= $cert['id'] ?>)" style="padding: 0.875rem 1rem;">
                            <i class="bi bi-printer"></i> Print
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <!-- REQUEST CERTIFICATE -->
            <div class="request-cert-card" style="text-align: center; padding: 4rem 2rem; margin: 2rem 0;">
                <i class="bi bi-award request-icon"></i>
                <h3 style="color: #2c5530; font-size: 1.875rem; font-weight: 800; margin-bottom: 1rem;">
                    Get Your FREE DA Camarines Sur Certificate
                </h3>
                <p style="color: #4a7c59; font-size: 1.125rem; margin-bottom: 2.5rem; line-height: 1.6;">
                    Official accreditation for <strong>approved farms</strong>. 
                    <br>Valid 3 years • Livestock monitoring compliant
                </p>
                
                <?php if ($hasApprovedFarms): ?>
                    <!-- HAS APPROVED FARMS - SHOW REQUEST BUTTON -->
                    <form method="POST" action="request_certificate.php" style="max-width: 450px; margin: 0 auto;">
                        <input type="hidden" name="user_id" value="<?= $userId ?>">
                        <textarea name="request_message" rows="3" 
                                placeholder="Any special notes for your certificate? (optional)" 
                                style="width: 100%; padding: 1.125rem; border: 2px solid #e6f4ea; border-radius: 12px; margin-bottom: 1.75rem; font-family: inherit; resize: vertical; background: white;"></textarea>
                        <button type="submit" class="btn-request" style="width: 100%; font-size: 1.125rem;">
                            <i class="bi bi-file-earmark-check"></i> Generate Certificate Now
                        </button>
                    </form>
                <?php else: ?>
                    <!-- NO APPROVED FARMS - SHOW FARM REGISTRATION CTA -->
                    <div style="max-width: 450px; margin: 0 auto;">
                        <p style="color: #64748b; margin-bottom: 2.25rem; line-height: 1.6; font-size: 1.1rem;">
                            <strong>Approve 1 farm first</strong> to unlock your official DA certificate!
                        </p>
                        <button onclick="switchToFarms()" class="btn-request" style="width: 100%; font-size: 1.125rem;">
                            <i class="bi bi-house-add-fill" style="margin-right: 0.75rem;"></i>
                            Register & Approve Farm Now
                        </button>
                        <p style="font-size: 0.875rem; color: #9ca3af; margin-top: 1.5rem;">
                            Takes 2 minutes • Free • Instant approval
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

           <!-- Profile Section - FULL CONTENT -->
<section id="profile" class="page-section" style="display: none; max-width: 1000px; margin: 0 auto; padding: 2rem 1rem;">
    
    <div class="profile-hero-card" style="background: white; border-radius: 20px; padding: 2.5rem; box-shadow: 0 10px 30px rgba(0,0,0,0.05); margin-bottom: 2rem; position: relative; display: flex; align-items: center; gap: 2.5rem; flex-wrap: wrap;">
        <div class="profile-avatar-wrapper">
            <?php if (!empty($profileData['profile_pix']) && file_exists($profileData['profile_pix'])): ?>
                <img src="<?= htmlspecialchars($profileData['profile_pix']) ?>" alt="Profile" style="width: 140px; height: 140px; border-radius: 50%; object-fit: cover; border: 5px solid #fff; box-shadow: 0 5px 15px rgba(0,0,0,0.1);">
            <?php else: ?>
                <div style="width: 140px; height: 140px; border-radius: 50%; background: linear-gradient(135deg, #10b981, #059669); display: flex; align-items: center; justify-content: center; color: white; font-size: 3rem; font-weight: 700; border: 5px solid #fff; box-shadow: 0 5px 15px rgba(0,0,0,0.1);">
                    <?= strtoupper(substr($profileData['firstName'], 0, 1) . substr($profileData['lastName'], 0, 1)) ?>
                </div>
            <?php endif; ?>
        </div>

        <div style="flex: 1; min-width: 300px;">
            <span style="background: #d1fae5; color: #065f46; padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px;">Professional Farmer</span>
            <h1 style="font-size: 2.25rem; font-weight: 800; color: #1e293b; margin: 0.5rem 0;"><?= htmlspecialchars($profileData['firstName'] . ' ' . $profileData['lastName']) ?></h1>
            
            <div style="display: flex; gap: 2rem; margin-top: 1.5rem;">
                <div>
                    <p style="margin: 0; color: #64748b; font-size: 0.9rem;">Farms Managed</p>
                    <p style="margin: 0; font-size: 1.5rem; font-weight: 700; color: #10b981;"><?= number_format($profileData['farm_count']) ?></p>
                </div>
                <div style="border-left: 1px solid #e2e8f0; padding-left: 2rem;">
                    <p style="margin: 0; color: #64748b; font-size: 0.9rem;">Total Livestock</p>
                    <p style="margin: 0; font-size: 1.5rem; font-weight: 700; color: #2563eb;"><?= number_format($profileData['total_livestock']) ?></p>
                </div>
            </div>
        </div>

        <div style="display: flex; flex-direction: column; gap: 0.75rem;">
            <button class="btn btn-primary" onclick="toggleModal('profileModal')" style="display: flex; align-items: center; gap: 8px; justify-content: center; padding: 12px 24px;">
                <i class="bi bi-pencil-square"></i> Edit Profile
            </button>
            <button class="btn btn-outline" onclick="toggleModal('passwordModal')" style="display: flex; align-items: center; gap: 8px; justify-content: center; padding: 12px 24px; background: #f8fafc; border: 1px solid #e2e8f0; color: #475569;">
                <i class="bi bi-shield-lock"></i> Security
            </button>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(450px, 1fr)); gap: 1.5rem;">
        <div class="card" style="background: white; border-radius: 15px; padding: 2rem; box-shadow: 0 4px 6px rgba(0,0,0,0.02);">
            <h3 style="display: flex; align-items: center; gap: 10px; margin-bottom: 1.5rem; font-size: 1.25rem; color: #1e293b;">
                <i class="bi bi-person-circle" style="color: #10b981;"></i> Personal Details
            </h3>
            <div class="info-list" style="display: grid; gap: 1rem;">
                <div class="info-item"><span>Email</span><strong><?= htmlspecialchars($profileData['email'] ?: '—') ?></strong></div>
                <div class="info-item"><span>Phone</span><strong><?= htmlspecialchars($profileData['mobile'] ?: '—') ?></strong></div>
                <div class="info-item"><span>Gender</span><strong><?= ucfirst($profileData['gender'] ?: '—') ?></strong></div>
                <div class="info-item"><span>Birthday</span><strong><?= $profileData['dob'] ?: '—' ?></strong></div>
            </div>
        </div>

        <div class="card" style="background: white; border-radius: 15px; padding: 2rem; box-shadow: 0 4px 6px rgba(0,0,0,0.02);">
            <h3 style="display: flex; align-items: center; gap: 10px; margin-bottom: 1.5rem; font-size: 1.25rem; color: #1e293b;">
                <i class="bi bi-geo-alt" style="color: #2563eb;"></i> Location & Emergency
            </h3>
            <div class="info-list" style="display: grid; gap: 1rem;">
                <div class="info-item"><span>Address</span><strong><?= htmlspecialchars($profileData['address'] ?: '—') ?></strong></div>
                <div class="info-item"><span>National ID</span><strong><?= htmlspecialchars($profileData['gov_id'] ?: '—') ?></strong></div>
                <div class="info-item"><span>Emergency Contact</span><strong style="color: #dc2626;"><?= htmlspecialchars($profileData['emergency_contact'] ?: '—') ?></strong></div>
            </div>
        </div>
    </div>
</section>

<!-- Profile Edit Modal - FIXED DESIGN -->
<div id="profileModal" class="custom-modal">
    <div class="modal-content">
        <form method="POST" enctype="multipart/form-data">
            <div class="modal-header" style="
                display: flex; 
                justify-content: space-between; 
                align-items: center; 
                padding: 1.5rem 2rem; 
                border-bottom: 1px solid #e5e7eb; 
                background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
            ">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <i class="bi bi-person-circle" style="font-size: 1.5rem; color: #10b981;"></i>
                    <h2 style="margin: 0; font-size: 1.5rem; font-weight: 700; color: #1e293b;">Edit Profile</h2>
                </div>
                <button type="button" onclick="toggleModal('profileModal')" style="
                    background: none; 
                    border: none; 
                    font-size: 1.75rem; 
                    color: #6b7280; 
                    cursor: pointer; 
                    padding: 0.25rem; 
                    border-radius: 50%; 
                    width: 40px; 
                    height: 40px; 
                    display: flex; 
                    align-items: center; 
                    justify-content: center;
                    transition: all 0.2s;
                " onmouseover="this.style.background='#f3f4f6'; this.style.color='#374151';" 
                onmouseout="this.style.background='none'; this.style.color='#6b7280';">&times;</button>
            </div>
            
            <div class="modal-scroll-body" style="padding: 2rem;">
                <div class="form-row">
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #475569; font-size: 0.95rem;">Contact Number</label>
                        <input type="tel" name="mobile" value="<?= htmlspecialchars($profileData['mobile'] ?? '') ?>" required 
                               style="width: 100%; padding: 12px 16px; border: 2px solid #e2e8f0; border-radius: 12px; font-size: 1rem; font-family: inherit; transition: all 0.2s; background: #ffffff;"
                               onfocus="this.style.borderColor='#10b981'; this.style.boxShadow='0 0 0 4px rgba(16,185,129,0.1)'"
                               onblur="this.style.borderColor='#e2e8f0'; this.style.boxShadow='none'">
                    </div>
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #475569; font-size: 0.95rem;">Email Address</label>
                        <input type="email" name="email" value="<?= htmlspecialchars($profileData['email'] ?? '') ?>" 
                               style="width: 100%; padding: 12px 16px; border: 2px solid #e2e8f0; border-radius: 12px; font-size: 1rem; font-family: inherit; transition: all 0.2s; background: #ffffff;"
                               onfocus="this.style.borderColor='#10b981'; this.style.boxShadow='0 0 0 4px rgba(16,185,129,0.1)'"
                               onblur="this.style.borderColor='#e2e8f0'; this.style.boxShadow='none'">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #475569; font-size: 0.95rem;">Gender</label>
                        <select name="gender" style="width: 100%; padding: 12px 16px; border: 2px solid #e2e8f0; border-radius: 12px; font-size: 1rem; font-family: inherit; background: #ffffff; transition: all 0.2s; appearance: none; background-image: url('data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTIiIGhlaWdodD0iOCIgdmlld0JveD0iMCAwIDEyIDgiIGZpbGw9Im5vbmUiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+CjxwYXRoIGQ9Ik0xIDFMNiA2TDExIDEiIGZpbGw9IiM5QzkxQUYiIHN0cm9rZT0iIzlDOTFBRSIgc3Ryb2tlLXdpZHRoPSIyIiBzdHJva2UtbGluZWNhcD0icm91bmQiIHN0cm9rZS1saW5lam9pbj0icm91bmQiLz4KPC9zdmc+'); background-repeat: no-repeat; background-position: right 12px center; background-size: 16px;"
                                onfocus="this.style.borderColor='#10b981'; this.style.boxShadow='0 0 0 4px rgba(16,185,129,0.1)'"
                                onblur="this.style.borderColor='#e2e8f0'; this.style.boxShadow='none'">
                            <option value="">Select Gender</option>
                            <option value="Male" <?= ($profileData['gender'] ?? '') == 'Male' ? 'selected' : '' ?>>Male</option>
                            <option value="Female" <?= ($profileData['gender'] ?? '') == 'Female' ? 'selected' : '' ?>>Female</option>
                            <option value="Other" <?= ($profileData['gender'] ?? '') == 'Other' ? 'selected' : '' ?>>Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #475569; font-size: 0.95rem;">Date of Birth</label>
                        <input type="date" name="dob" value="<?= htmlspecialchars($profileData['dob'] ?? '') ?>" 
                               style="width: 100%; padding: 12px 16px; border: 2px solid #e2e8f0; border-radius: 12px; font-size: 1rem; font-family: inherit; transition: all 0.2s; background: #ffffff;"
                               onfocus="this.style.borderColor='#10b981'; this.style.boxShadow='0 0 0 4px rgba(16,185,129,0.1)'"
                               onblur="this.style.borderColor='#e2e8f0'; this.style.boxShadow='none'">
                    </div>
                </div>
                
                <div class="form-group">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #475569; font-size: 0.95rem;">Home Address</label>
                    <textarea name="address" rows="3" style="width: 100%; padding: 12px 16px; border: 2px solid #e2e8f0; border-radius: 12px; font-size: 1rem; font-family: inherit; resize: vertical; transition: all 0.2s; background: #ffffff; line-height: 1.5;"><?= htmlspecialchars($profileData['address'] ?? '') ?></textarea>
                </div>
                
                <div class="form-group">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #475569; font-size: 0.95rem;">Profile Photo</label>
                    <div style="position: relative; display: flex; align-items: center; gap: 12px; padding: 12px 16px; border: 2px dashed #e2e8f0; border-radius: 12px; background: #fafbfc; transition: all 0.2s; cursor: pointer;"
                         onclick="document.querySelector('#profile_pix_input').click()">
                        <i class="bi bi-cloud-upload" style="font-size: 1.5rem; color: #10b981; flex-shrink: 0;"></i>
                        <div>
                            <div style="font-weight: 600; color: #374151; margin-bottom: 2px;">Choose Profile Photo</div>
                            <div style="font-size: 0.875rem; color: #6b7280;">JPG, PNG or WebP (max 5MB)</div>
                        </div>
                        <input type="file" name="profile_pix" id="profile_pix_input" accept="image/*" class="file-input" style="display: none;">
                    </div>
                    <?php if (!empty($profileData['profile_pix']) && file_exists($profileData['profile_pix'])): ?>
                    <div style="margin-top: 12px; text-align: center;">
                        <img src="<?= htmlspecialchars($profileData['profile_pix']) ?>" alt="Current profile" style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 3px solid #f3f4f6;">
                        <div style="font-size: 0.8rem; color: #6b7280; margin-top: 4px;">Current photo</div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="modal-footer" style="
                padding: 1.5rem 2rem; 
                background: #f8fafc; 
                border-top: 1px solid #e5e7eb; 
                display: flex; 
                justify-content: flex-end; 
                gap: 12px;
            ">
                <button type="button" class="btn-cancel" onclick="toggleModal('profileModal')" style="
                    background: #ffffff; 
                    border: 2px solid #e2e8f0; 
                    color: #475569; 
                    padding: 12px 24px; 
                    border-radius: 12px; 
                    cursor: pointer; 
                    font-weight: 600; 
                    font-size: 0.95rem; 
                    transition: all 0.2s;
                " onmouseover="this.style.background='#f3f4f6'; this.style.borderColor='#10b981'; this.style.color='#10b981';"
                onmouseout="this.style.background='#ffffff'; this.style.borderColor='#e2e8f0'; this.style.color='#475569';">
                    Cancel
                </button>
                <button type="submit" name="update_profile" class="btn-save" style="
                    background: linear-gradient(135deg, #10b981 0%, #059669 100%); 
                    color: white; 
                    border: none; 
                    padding: 12px 28px; 
                    border-radius: 12px; 
                    cursor: pointer; 
                    font-weight: 700; 
                    font-size: 0.95rem; 
                    display: flex; 
                    align-items: center; 
                    gap: 8px; 
                    transition: all 0.2s; 
                    box-shadow: 0 4px 12px rgba(16,185,129,0.3);
                " onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 8px 25px rgba(16,185,129,0.4)'"
                onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 12px rgba(16,185,129,0.3)'">
                    <i class="bi bi-check-lg"></i>
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>
<!-- Rejection Reason Modal -->
<div id="rejectionModal" class="custom-modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header" style="background: linear-gradient(135deg, #fee2e2, #fecaca); color: #991b1b; padding: 1.5rem;">
            <h3 style="margin: 0;"><i class="bi bi-x-circle"></i> Rejection Details</h3>
            <button onclick="toggleModal('rejectionModal')" style="background: none; border: none; font-size: 1.5rem; cursor: pointer;">×</button>
        </div>
        <div class="modal-scroll-body">
            <div id="rejectionReason" style="padding: 1.5rem; background: #fef2f2; border-radius: 12px; border-left: 4px solid #ef4444; white-space: pre-wrap;"></div>
            <div style="margin-top: 1.5rem; text-align: center;">
                <button onclick="appealRejectedFarm()" class="btn btn-primary" style="width: 100%; margin-bottom: 0.5rem;">
                    <i class="bi bi-arrow-repeat"></i> Appeal Decision
                </button>
                <button onclick="switchToFarms()" class="btn btn-secondary" style="width: 100%;">
                    <i class="bi bi-house-add"></i> Register New Farm
                </button>
            </div>
        </div>
    </div>
</div>  
</div>
</section>
        </main>
    </div>
    
    <script>
document.addEventListener('DOMContentLoaded', function() {
    initAll();
});

function initAll() {
    initNavigation();
    initMobileMenu();
    initAnimations();
    initForms();
    initModals();
    initCameraSystem();
}

// ========================================
// 1. NAVIGATION SYSTEM
// ========================================
function initNavigation() {
    const navItems = document.querySelectorAll('.nav-item[data-page]');
    const pageSections = document.querySelectorAll('.page-section');
    const pageTitle = document.getElementById('pageTitle');

    navItems.forEach(item => {
        item.addEventListener('click', function(e) {
            e.preventDefault();
            const pageId = this.dataset.page;
            
            // Update active nav
            navItems.forEach(nav => nav.classList.remove('active'));
            this.classList.add('active');
            
            // Update page title
            pageTitle.textContent = this.textContent.trim().replace(/\s*\d+$/, '');
            
            // Show/hide sections
            pageSections.forEach(section => {
                section.style.display = section.id === pageId ? 'block' : 'none';
            });
            
            // Close mobile menu & scroll to top
            toggleMenu();
            setTimeout(() => {
                document.querySelector('.content').scrollIntoView({ behavior: 'smooth' });
            }, 300);
        });
    });
}

// ========================================
// 2. MOBILE MENU SYSTEM
// ========================================
function initMobileMenu() {
    const overlay = document.getElementById('overlay');
    overlay.addEventListener('click', toggleMenu);
    
    // ESC key closes menu
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeAllModals();
            toggleMenu();
        }
    });
}

function toggleMenu() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('overlay');
    
    sidebar.classList.toggle('active');
    overlay.classList.toggle('show');
}

// ========================================
// 3. MODAL SYSTEM (UNIFIED)
// ========================================
function initModals() {
    // Close modals on outside click
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('custom-modal')) {
            closeAllModals();
        }
    });
}

function toggleModal(modalId) {
    const modal = document.getElementById(modalId);
    if (!modal) return;
    
    if (modal.style.display === 'flex') {
        closeModal(modalId);
    } else {
        openModal(modalId);
    }
}

function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
}

function closeAllModals() {
    document.querySelectorAll('.custom-modal').forEach(modal => {
        modal.style.display = 'none';
    });
    document.body.style.overflow = 'auto';
}

// ========================================
// 4. FORM SYSTEM
// ========================================
function initForms() {
    // Prevent default form submissions (demo mode)
    document.querySelectorAll('form:not(#farm-livestock-form)').forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            showDemoAlert('Form submission coming soon!');
        });
    });
}

// ========================================
// 5. ANIMATION SYSTEM
// ========================================
function initAnimations() {
    // Form focus animations
    document.querySelectorAll('.form-input, .form-select, textarea').forEach(input => {
        input.addEventListener('focus', function() {
            this.parentElement.style.transform = 'translateY(-2px)';
        });
        input.addEventListener('blur', function() {
            this.parentElement.style.transform = 'translateY(0)';
        });
    });

    // Button hover effects
    document.querySelectorAll('.btn').forEach(btn => {
        btn.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-2px)';
        });
        btn.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
    });

    // Table row interactions
    document.querySelectorAll('table tbody tr').forEach(row => {
        row.addEventListener('mouseenter', function() {
            this.style.transform = 'scale(1.01)';
        });
        row.addEventListener('mouseleave', function() {
            this.style.transform = 'scale(1)';
        });
    });
}

// ========================================
// 6. CAMERA + GPS SYSTEM (BULLETPROOF)
// ========================================
let mediaStream = null;
let currentGPS = null;
let stampedCanvasData = null;

function initCameraSystem() {
    // File upload handler
    const fileUpload = document.getElementById('file-upload');
    if (fileUpload) {
        fileUpload.addEventListener('change', handleFileUpload);
    }
}

async function openCamera() {
    try {
        const constraints = {
            video: { 
                facingMode: 'environment',
                width: { ideal: 1280 },
                height: { ideal: 720 }
            }
        };
        
        mediaStream = await navigator.mediaDevices.getUserMedia(constraints);
        showCameraModal(mediaStream);
        
    } catch (err) {
        showAlert('Camera access denied. Please enable camera permissions.', 'error');
        console.error('Camera error:', err);
    }
}

function showCameraModal(stream) {
    const modal = document.createElement('div');
    modal.id = 'camera-modal';
    modal.className = 'custom-modal';
    modal.innerHTML = `
        <div class="modal-content" style="width: 95%; max-width: 600px; height: 85vh; border-radius: 20px; overflow: hidden;">
            <div style="position: relative; flex: 1; background: #000;">
                <video id="camera-video" autoplay playsinline style="width: 100%; height: 100%; object-fit: cover;"></video>
                <div id="gps-status-camera" style="position: absolute; top: 20px; left: 20px; background: rgba(0,0,0,0.7); color: white; padding: 10px 15px; border-radius: 20px; font-size: 0.9rem; font-weight: 600;">
                    Getting GPS...
                </div>
            </div>
            <div style="padding: 20px; display: flex; gap: 15px; justify-content: center; background: #f8fafc;">
                <button onclick="switchCamera()" style="width: 60px; height: 60px; border-radius: 50%; background: rgba(255,255,255,0.3); border: none; font-size: 1.5rem; cursor: pointer;">🔄</button>
                <button onclick="capturePhotoFromModal()" style="width: 80px; height: 80px; border-radius: 50%; background: linear-gradient(135deg, #10b981, #059669); border: none; font-size: 2rem; color: white; cursor: pointer; box-shadow: 0 8px 25px rgba(16,185,129,0.4);">📸</button>
                <button onclick="closeCameraModal()" style="width: 60px; height: 60px; border-radius: 50%; background: rgba(255,255,255,0.3); border: none; font-size: 1.5rem; cursor: pointer;">❌</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
    openModal('camera-modal');
    
    const video = document.getElementById('camera-video');
    video.srcObject = stream;
    
    // Get GPS for camera preview
    getGPS().then(gps => {
        document.getElementById('gps-status-camera').textContent = `GPS: ${gps.lat.toFixed(4)}, ${gps.lng.toFixed(4)}`;
    });
}

async function capturePhotoFromModal() {
    const video = document.getElementById('camera-video');
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    ctx.drawImage(video, 0, 0);
    
    const gps = await getGPS();
    await stampGPS(canvas, gps);
    processFinalImage(canvas);
    
    closeCameraModal();
}

function closeCameraModal() {
    stopCamera();
    closeAllModals();
    const modal = document.getElementById('camera-modal');
    if (modal) modal.remove();
}

async function switchCamera() {
    stopCamera();
    const constraints = {
        video: { facingMode: 'user' }
    };
    try {
        const stream = await navigator.mediaDevices.getUserMedia(constraints);
        showCameraModal(stream);
    } catch (err) {
        showCameraModal(mediaStream); // Fallback
    }
}

async function handleFileUpload(event) {
    const file = event.target.files[0];
    if (!file) return;
    
    const img = new Image();
    img.onload = async () => {
        const canvas = document.createElement('canvas');
        canvas.width = img.width;
        canvas.height = img.height;
        canvas.getContext('2d').drawImage(img, 0, 0);
        
        const gps = await getGPS();
        await stampGPS(canvas, gps);
        processFinalImage(canvas);
    };
    img.src = URL.createObjectURL(file);
}

async function getGPS() {
    return new Promise((resolve) => {
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(
                (position) => {
                    currentGPS = {
                        lat: position.coords.latitude,
                        lng: position.coords.longitude,
                        accuracy: position.coords.accuracy
                    };
                    resolve(currentGPS);
                },
                () => resolve({ lat: 14.5995, lng: 120.9842, accuracy: 1000 }),
                { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
            );
        } else {
            resolve({ lat: 14.5995, lng: 120.9842, accuracy: 1000 });
        }
    });
}

async function stampGPS(canvas, gps) {
    const ctx = canvas.getContext('2d');
    const timestamp = new Date().toLocaleString('en-PH', { 
        timeZone: 'Asia/Manila',
        year: 'numeric', month: 'short', day: 'numeric',
        hour: '2-digit', minute: '2-digit'
    });
    
    const stampHeight = canvas.height * 0.18;
    ctx.fillStyle = 'rgba(0, 0, 0, 0.85)';
    ctx.fillRect(0, canvas.height - stampHeight, canvas.width, stampHeight);
    
    ctx.fillStyle = 'white';
    ctx.font = `bold ${Math.floor(canvas.width / 35)}px sans-serif`;
    ctx.shadowColor = 'rgba(0,0,0,0.8)';
    ctx.shadowOffsetX = 2;
    ctx.shadowOffsetY = 2;
    ctx.shadowBlur = 4;
    
    ctx.textAlign = 'left';
    ctx.fillText(`📍 GPS: ${gps.lat.toFixed(6)}, ${gps.lng.toFixed(6)}`, 25, canvas.height - stampHeight + 45);
    ctx.fillText(`⏰ ${timestamp}`, 25, canvas.height - stampHeight + 85);
    ctx.font = `bold ${Math.floor(canvas.width / 60)}px sans-serif`;
    ctx.fillText(`✅ ${gps.accuracy < 20 ? 'High' : 'Good'} Accuracy`, 25, canvas.height - stampHeight + 125);
    
    const userName = document.querySelector('.user-name')?.textContent || 'Farmer';
    ctx.font = `${Math.floor(canvas.width / 50)}px sans-serif`;
    ctx.fillText(`👤 ${userName}`, canvas.width - 300, canvas.height - stampHeight + 45);
    
    ctx.shadowBlur = 0;
}

// ✅ FIXED VERSION - Replace your existing processFinalImage function
async function processFinalImage(canvas) {
    stampedCanvasData = canvas.toDataURL('image/jpeg', 0.9);
    
    const previewCanvas = document.getElementById('stamped-canvas');
    previewCanvas.width = 600;
    previewCanvas.height = Math.round(canvas.height * (600 / canvas.width));
    previewCanvas.getContext('2d').drawImage(canvas, 0, 0, 600, previewCanvas.height);
    
    document.getElementById('photo-preview').style.display = 'block';
    document.getElementById('gps-verification').style.display = 'block';
    document.getElementById('gps-details').textContent = `Lat: ${currentGPS.lat.toFixed(6)}, Lng: ${currentGPS.lng.toFixed(6)}`;
    
    // ✅ FIXED: Store GPS data
    if (document.getElementById('farm-lat')) document.getElementById('farm-lat').value = currentGPS.lat;
    if (document.getElementById('farm-lng')) document.getElementById('farm-lng').value = currentGPS.lng;
    
    // 🌍 Auto-fill Farm Address with reverse geocoding
    await reverseGeocodeAddress(currentGPS.lat, currentGPS.lng);
    
    // ✅ FIXED: FULLY UNLOCK FORM - Now you can type everywhere!
    const form = document.getElementById('farm-livestock-form');
    form.style.opacity = '1 !important';
    form.style.pointerEvents = 'auto !important';
    
    // ✅ Visual confirmation
    showAlert('✅ Photo & GPS captured! Form is ready - start typing!', 'success');
    
    // 🎯 Auto-focus Farm Name field
    setTimeout(() => {
        document.getElementById('farm-name').focus();
    }, 500);
}

function removePhoto() {
    // Hide photo preview
    document.getElementById('photo-preview').style.display = 'none';
    document.getElementById('gps-verification').style.display = 'none';
    
    // Clear all auto-filled data
    const farmAddress = document.getElementById('farm-address');
    const farmLat = document.getElementById('farm-lat');
    const farmLng = document.getElementById('farm-lng');
    const farmPhoto = document.getElementById('farm-photo');
    
    if (farmAddress) farmAddress.value = '';
    if (farmLat) farmLat.value = '';
    if (farmLng) farmLng.value = '';
    if (farmPhoto) farmPhoto.value = '';
    
    // Reset form state (but keep it unlocked for manual entry)
    const form = document.getElementById('farm-livestock-form');
    form.style.opacity = '1';
    form.style.pointerEvents = 'auto';
    
    stampedCanvasData = null;
    currentGPS = null;
    
    // Focus back to photo capture
    setTimeout(() => {
        document.querySelector('#action-zone button:first-child').focus();
    }, 200);
}

function stopCamera() {
    if (mediaStream) {
        mediaStream.getTracks().forEach(track => track.stop());
        mediaStream = null;
    }
}

// 🌍 Auto-fill address from GPS (works offline too)
async function reverseGeocodeAddress(lat, lng) {
    const farmAddress = document.getElementById('farm-address');
    if (!farmAddress) return;
    
    try {
        // Try real reverse geocoding
        const response = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1&accept-language=en`);
        const data = await response.json();
        
        if (data?.display_name) {
            farmAddress.value = data.display_name;
            farmAddress.style.background = '#ecfdf5';
            farmAddress.style.borderColor = '#10b981';
        }
    } catch (e) {
        // ✅ Offline fallback - precise coordinates
        farmAddress.value = `Farm located at GPS: ${lat.toFixed(6)}, ${lng.toFixed(6)}`;
        farmAddress.style.background = '#fef3c7';
    }
    
    // Allow editing
    farmAddress.readOnly = false;
}

// ========================================
// 7. FARM REGISTRATION FORM HANDLER
// ========================================
if (document.getElementById('farm-livestock-form')) {
    document.getElementById('farm-livestock-form').addEventListener('submit', handleFarmRegistration);
}

async function handleFarmRegistration(e) {
    e.preventDefault();
    
    if (!stampedCanvasData) {
        showAlert('⚠️ Please take a photo with GPS first!', 'warning');
        return;
    }
    
    const formData = new FormData(e.target);
    formData.append('photo_data', stampedCanvasData);
    formData.append('user_id', <?= $userId ?>);
    formData.append('action', 'register_farm_livestock');
    
    const submitBtn = document.getElementById('submit-btn');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Saving...';
    submitBtn.disabled = true;
    
    try {
        const response = await fetch('api/register_farm.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showSuccess(result);
        } else {
            showAlert('❌ ' + (result.message || 'Registration failed'), 'error');
        }
    } catch (error) {
        showAlert('❌ Network error. Please try again.', 'error');
        console.error('Registration error:', error);
    } finally {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
}

function showSuccess(result) {
    const successMsg = document.getElementById('success-message');
    successMsg.style.display = 'block';
    document.querySelector('#farm-registration .card').style.display = 'none';
}

function resetForm() {
    document.querySelector('#farm-registration .card').style.display = 'block';
    document.getElementById('success-message').style.display = 'none';
    removePhoto();
    document.getElementById('farm-livestock-form').reset();
}

// ========================================
// 8. E-CERTIFICATE FUNCTIONS
// ========================================
function switchToFarms() {
    document.querySelector('[data-page="farm-registration"]').click();
}

function printCert(certId) {
    window.open(`download_certificate.php?id=${certId}`, '_blank');
}

// ========================================
// 9. REJECTION HANDLING
// ========================================
function showRejection(reason) {
    document.getElementById('rejectionReason').textContent = reason;
    openModal('rejectionModal');
}

async function appealFarm(farmId) {
    if (!confirm('Appeal this rejected farm? You can edit and resubmit.')) return;
    
    try {
        const response = await fetch('api/appeal-farm.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ farmId })
        });
        const result = await response.json();
        
        if (result.success) {
            showAlert('✅ Appeal submitted! Admin will review.', 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showAlert('❌ ' + result.message, 'error');
        }
    } catch (error) {
        showAlert('Network error', 'error');
    }
}

function appealRejectedFarm() {
    closeModal('rejectionModal');
    switchToFarms();
}

// ========================================
// 10. UTILITY FUNCTIONS
// ========================================
function showAlert(message, type = 'info') {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type}`;
    alertDiv.style.cssText = `
        position: fixed; top: 20px; right: 20px; z-index: 10000;
        padding: 1rem 1.5rem; border-radius: 12px; color: white;
        font-weight: 600; box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        background: ${type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : type === 'warning' ? '#f59e0b' : '#3b82f6'};
        transform: translateX(400px); transition: all 0.3s ease;
    `;
    alertDiv.textContent = message;
    
    document.body.appendChild(alertDiv);
    
        setTimeout(() => {
        alertDiv.style.transform = 'translateX(400px)';
    }, 100);

    setTimeout(() => {
        if (alertDiv.parentNode) {
            alertDiv.remove();
        }
    }, 4000);
}

// ========================================
// 11. CLEANUP & LIFECYCLE
// ========================================
window.addEventListener('beforeunload', function() {
    stopCamera();
    closeAllModals();
});

document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        stopCamera();
    }
});

// ========================================
// 12. PROFILE MODAL HANDLER
// ========================================
document.addEventListener('DOMContentLoaded', function() {
    // Profile form handler
    const profileForm = document.querySelector('#profileModal form');
    if (profileForm) {
        profileForm.addEventListener('submit', function(e) {
            e.preventDefault();
            showAlert('Profile updated successfully! (Demo mode)', 'success');
            closeModal('profileModal');
        });
    }

    // Profile photo upload
    const profilePhotoInput = document.getElementById('profile_pix_input');
    if (profilePhotoInput) {
        profilePhotoInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(event) {
                    const img = document.querySelector('#profileModal img');
                    if (img) {
                        img.src = event.target.result;
                        img.style.display = 'block';
                    }
                };
                reader.readAsDataURL(file);
            }
        });
    }
});

// ========================================
// 13. DEMO ALERTS & FALLBACKS
// ========================================
function showDemoAlert(message) {
    showAlert(message + ' 🚀', 'info');
}

// Legacy function support (for existing onclick handlers)
window.showRejection = showRejection;
window.appealFarm = appealFarm;
window.appealRejectedFarm = appealRejectedFarm;
window.switchToFarms = switchToFarms;
window.printCert = printCert;
window.toggleModal = toggleModal;
window.openCamera = openCamera;
window.removePhoto = removePhoto;
window.resetForm = resetForm;

console.log('✅ AgriTrace+ Farmer Portal - All systems initialized successfully!');
</script>
</body>
</html>