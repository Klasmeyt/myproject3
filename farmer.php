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

// 4. Data Fetching
$totalLivestock = 0;
$activeIncidents = 0;
$farmStatus = 'No Farms Found';

try {
    // Total Livestock
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(l.qty), 0) as total FROM livestock l JOIN farms f ON l.farmId = f.id WHERE f.ownerId = ?");
    $stmt->execute([$userId]);
    $totalLivestock = $stmt->fetch()['total'];

    // Active Incidents
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM incidents WHERE reporterId = ? AND status != 'Resolved'");
    $stmt->execute([$userId]);
    $activeIncidents = $stmt->fetch()['total'];

    // Farm Status
    $stmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM farms WHERE ownerId = ? GROUP BY status");
    $stmt->execute([$userId]);
    $farmData = $stmt->fetch();
    if ($farmData) {
        $farmStatus = ($farmData['status'] == 'Approved') ? 'Verified Active (' . $farmData['count'] . ')' : 'Pending Approval';
    }
} catch (PDOException $e) {
    error_log($e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>AgriTrace+ | Farmer Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
   <style>
    :root {
        --primary-deep: #064e3b;   /* Dark Forest Green (Sidebar) */
        --primary-bright: #10b981; /* Vibrant Mint (Buttons/Active) */
        --primary-hover: #059669;  /* Deep Mint */
        --bg-main: #f9fafb;        /* Light Grey/White Background */
        --text-dark: #064e3b;      /* Matching dark text */
        --text-grey: #6b7280;      /* Muted text */
        --white: #ffffff;
        --sidebar-width: 280px;
        --transition: all 0.25s ease;
    }

    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
        font-family: 'Plus Jakarta Sans', sans-serif;
        background-color: var(--bg-main);
        color: var(--text-dark);
    }

    /* --- Sidebar Navigation (Dark Forest) --- */
    .sidebar {
        position: fixed;
        top: 0;
        left: calc(-1 * var(--sidebar-width));
        width: var(--sidebar-width);
        height: 100%;
        background: var(--primary-deep); /* Dark Green */
        z-index: 2000;
        display: flex;
        flex-direction: column;
        transition: var(--transition);
        color: rgba(255, 255, 255, 0.8);
    }

    .sidebar.active { left: 0; }

    .sidebar-header {
        padding: 30px 24px;
    }

    .brand {
        font-size: 1.6rem;
        font-weight: 800;
        color: var(--white);
        letter-spacing: -0.5px;
    }

    .brand span { color: var(--primary-bright); }

    .portal-label {
        font-size: 0.7rem;
        font-weight: 700;
        color: rgba(255, 255, 255, 0.5);
        text-transform: uppercase;
        margin-top: -5px;
        display: block;
    }

    /* Navigation Links */
    .nav-list { flex: 1; padding: 10px 16px; }

    .nav-item {
        display: flex;
        align-items: center;
        padding: 12px 16px;
        margin-bottom: 4px;
        border-radius: 8px;
        color: rgba(255, 255, 255, 0.7);
        text-decoration: none;
        font-weight: 500;
        transition: var(--transition);
    }

    .nav-item i { font-size: 1.2rem; margin-right: 12px; }

    /* The "Active" state from the image */
    .nav-item.active {
        background: var(--primary-bright);
        color: var(--white);
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2);
    }

    .nav-item:hover:not(.active) {
        background: rgba(255, 255, 255, 0.1);
        color: var(--white);
    }

    /* --- Bottom User Card --- */
    .sidebar-footer {
        padding: 20px;
        background: rgba(0, 0, 0, 0.2); /* Slightly darker bottom */
    }

    .user-card {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px;
    }

    .avatar {
        width: 40px;
        height: 40px;
        background: var(--primary-bright);
        color: var(--white);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
    }

    .user-name { font-size: 0.9rem; font-weight: 600; color: white; }
    .user-role { font-size: 0.75rem; color: rgba(255, 255, 255, 0.5); }

    /* --- Top Bar --- */
    .top-bar {
        padding: 15px 25px;
        background: var(--white);
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .menu-toggle {
        font-size: 1.5rem;
        background: none;
        border: none;
        color: var(--primary-deep);
        cursor: pointer;
    }

    /* --- Content Cards --- */
    .content { padding: 30px; }

    .card {
        background: var(--white);
        padding: 24px;
        border-radius: 12px;
        border: 1px solid #e5e7eb;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }

    /* Refresh Button Style from image */
    .btn-refresh {
        background: var(--primary-bright);
        color: white;
        border: none;
        padding: 8px 18px;
        border-radius: 8px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
    }

    .btn-refresh:hover { background: var(--primary-hover); }

    .overlay {
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.3);
        z-index: 1500;
        opacity: 0;
        visibility: hidden;
        transition: var(--transition);
    }

    .overlay.show { opacity: 1; visibility: visible; }
</style>
</head>
<body>

    <div class="overlay" id="overlay" onclick="toggleMenu()"></div>

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="brand">Agri<span>Trace+</span></div>
            <span class="portal-label">Farmer Portal</span>
        </div>

        <nav class="nav-list">
            <a href="#" class="nav-item active" onclick="navigate('Dashboard')">
                <i class="bi bi-grid-1x2-fill"></i> Dashboard
            </a>
            <a href="#" class="nav-item" onclick="navigate('Farm Registration')">
                <i class="bi bi-house-add"></i> Farm Registration
            </a>
            <a href="#" class="nav-item" onclick="navigate('Livestock Monitoring')">
                <i class="bi bi-activity"></i> Livestock Monitoring
            </a>
            <a href="#" class="nav-item" onclick="navigate('Incident Reporting')">
                <i class="bi bi-exclamation-triangle"></i> Incident Reporting
            </a>
            <a href="#" class="nav-item" onclick="navigate('Notifications')">
                <i class="bi bi-bell"></i> Notifications
                <span class="badge-alert">3</span>
            </a>
            <a href="#" class="nav-item" onclick="navigate('Farm Map')">
                <i class="bi bi-geo-alt"></i> Farm Map
            </a>
            <a href="#" class="nav-item" onclick="navigate('Profile')">
                <i class="bi bi-person-gear"></i> Profile
            </a>
            
            <div style="margin-top: 20px; padding: 0 16px;">
                <hr style="border: none; border-top: 1px solid #f1f5f9;">
            </div>

            <a href="logout.php" class="nav-item" style="color: #ef4444;">
                <i class="bi bi-box-arrow-left"></i> Logout
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="user-card">
                <div class="avatar"><?= substr($firstName, 0, 1) ?></div>
                <div class="user-info">
                    <span class="user-name"><?= $firstName ?></span>
                    <span class="user-role"><?= $userRole ?></span>
                </div>
            </div>
        </div>
    </aside>

    <div class="main-wrapper">
        <header class="top-bar">
            <button class="menu-toggle" onclick="toggleMenu()">
                <i class="bi bi-list"></i>
            </button>
            <div style="font-weight: 700;" id="current-title">Dashboard</div>
            <div style="width: 30px;"></div> </header>

        <main class="content">
            <div class="card">
                <h2 style="font-size: 1.2rem; margin-bottom: 10px;">Welcome back, <?= $firstName ?>!</h2>
                <p style="color: var(--grey); font-size: 0.9rem;">Tap the menu icon in the top left to navigate through your farm tools.</p>
            </div>

            <div id="dynamic-content">
                <div class="card" style="border-left: 5px solid var(--primary);">
                    <div style="font-size: 0.8rem; color: var(--grey);">Total Livestock</div>
                    <div style="font-size: 1.8rem; font-weight: 800;"><?= $totalLivestock ?></div>
                </div>
            </div>
        </main>
    </div>

    <script>
        function toggleMenu() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('overlay');
            
            sidebar.classList.toggle('active');
            overlay.classList.toggle('show');
        }

        function navigate(pageTitle) {
            // Update Title
            document.getElementById('current-title').innerText = pageTitle;
            
            // Highlight active nav
            const items = document.querySelectorAll('.nav-item');
            items.forEach(item => {
                item.classList.remove('active');
                if(item.innerText.includes(pageTitle)) {
                    item.classList.add('active');
                }
            });

            // Close menu on mobile after selection
            toggleMenu();

            // Example dynamic content change
            document.getElementById('dynamic-content').innerHTML = `
                <div class="card">
                    <h3>${pageTitle} Section</h3>
                    <p style="color: #64748b; margin-top: 10px;">This is the placeholder for the ${pageTitle} module.</p>
                </div>
            `;
        }
    </script>
</body>
</html>