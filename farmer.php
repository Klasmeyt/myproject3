<?php
session_start();

// AUTHENTICATION CHECK - ADD THIS TO ALL PANEL PAGES
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['PHP_SELF']));
    exit;
}

$user_role = $_SESSION['user_role'] ?? '';

// ROLE CHECK
$allowed_roles = match(basename($_SERVER['PHP_SELF'])) {
    'admin.php' => ['Admin'],
    'farmer.php' => ['Farmer'],
    'agri.php' => ['Agriculture Official'],
    default => []
};

if (!in_array($user_role, $allowed_roles)) {
    header('Location: index.php?error=access_denied');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AgriTrace+ | Farmer Portal</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,400&family=Syne:wght@700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body class="panel-page" style="background: var(--c-slate-50);">

  <!-- Sidebar Overlay -->
  <div class="sidebar-overlay" id="farmer-overlay" onclick="closePanelSidebar('farmer')"></div>
  
  <!-- Sidebar -->
  <aside class="panel-sidebar" id="farmer-sidebar">
    <div class="panel-sidebar-header">
      <div class="panel-sidebar-logo">Agri<span>Trace+</span></div>
      <div class="panel-sidebar-sub">Farmer Portal</div>
    </div>
    <nav class="panel-nav">
      <div class="panel-nav-item active" onclick="showPanel('farmer','dashboard')">
        <i class="bi bi-speedometer2"></i> Dashboard
      </div>
      <div class="panel-nav-item" onclick="showPanel('farmer','farm')">
        <i class="bi bi-house-gear"></i> Farm Registration
      </div>
      <div class="panel-nav-item" onclick="showPanel('farmer','livestock')">
        <i class="bi bi-journal-check"></i> Livestock Monitoring
      </div>
      <div class="panel-nav-item" onclick="showPanel('farmer','incidents')">
        <i class="bi bi-exclamation-triangle"></i> Incident Reporting
      </div>
      <div class="panel-nav-item" onclick="showPanel('farmer','notifications')">
        <i class="bi bi-bell"></i> Notifications
      </div>
      <div class="panel-nav-item" onclick="showPanel('farmer','map')">
        <i class="bi bi-geo-alt"></i> Farm Map
      </div>
      <div class="panel-nav-item" onclick="showPanel('farmer','profile')">
        <i class="bi bi-person-circle"></i> Profile
      </div>
      <div class="panel-nav-divider"></div>
      <div class="panel-nav-item logout" onclick="window.location.href='index.php'">
        <i class="bi bi-power"></i> Logout
      </div>
    </nav>
    <div class="panel-sidebar-footer">
      <div class="panel-user-info">
        <div class="panel-avatar">JD</div>
        <div>
          <div class="panel-user-name">Juan dela Cruz</div>
          <div class="panel-user-role">Farmer</div>
        </div>
      </div>
    </div>
  </aside>

  <!-- Main Content -->
  <main class="panel-main">
    <div class="panel-topbar">
      <div style="display:flex; align-items:center; gap:12px;">
        <button class="mobile-sidebar-toggle" onclick="openPanelSidebar('farmer')">
          <i class="bi bi-list"></i>
        </button>
        <span class="panel-topbar-title" id="farmer-section-title">Dashboard</span>
      </div>
      <div class="topbar-right">
        <button class="topbar-notif">
          <i class="bi bi-bell"></i>
          <span class="notif-dot"></span>
        </button>
        <div class="panel-avatar" style="width:32px;height:32px;font-size:0.8rem;">JD</div>
      </div>
    </div>

    <div class="panel-content">
      <!-- Farmer Dashboard -->
      <div class="panel-section active" id="farmer-dashboard">
        <div class="page-header-panel">
          <h2>Good morning, Juan! 👋</h2>
          <p>Here's an overview of your farm activities</p>
        </div>

        <div class="stat-cards">
          <div class="stat-card">
            <div class="stat-icon-wrap stat-icon-green">
              <i class="bi bi-database"></i>
            </div>
            <div>
              <div class="stat-num" id="farmer-total-livestock">24</div>
              <div class="stat-lbl">Total Livestock</div>
            </div>
          </div>
          <div class="stat-card">
            <div class="stat-icon-wrap stat-icon-amber">
              <i class="bi bi-exclamation-triangle"></i>
            </div>
            <div>
              <div class="stat-num" id="farmer-active-incidents">2</div>
              <div class="stat-lbl">Active Incidents</div>
            </div>
          </div>
          <div class="stat-card">
            <div class="stat-icon-wrap stat-icon-blue">
              <i class="bi bi-clipboard-check"></i>
            </div>
            <div>
              <div class="stat-num" id="farmer-pending-inspections">1</div>
              <div class="stat-lbl">Pending Inspections</div>
            </div>
          </div>
          <div class="stat-card">
            <div class="stat-icon-wrap stat-icon-green">
              <i class="bi bi-patch-check-fill"></i>
            </div>
            <div>
              <div class="stat-num">Active</div>
              <div class="stat-lbl">Farm Status</div>
            </div>
          </div>
        </div>

        <div class="dash-row">
          <div class="dash-card">
            <div class="dash-card-header">
              <span class="dash-card-title">Livestock by Type</span>
            </div>
            <div class="dash-card-body">
              <div class="chart-container">
                <canvas id="farmer-livestock-chart"></canvas>
              </div>
            </div>
          </div>
          <div class="dash-card">
            <div class="dash-card-header">
              <span class="dash-card-title">Notifications</span>
            </div>
            <div class="dash-card-body">
              <div style="display:flex;flex-direction:column;gap:10px;">
                <div style="display:flex;gap:12px;align-items:flex-start;padding:12px;background:var(--c-slate-50);border-radius:10px;border-left:3px solid var(--c-amber);">
                  <i class="bi bi-virus" style="color:var(--c-amber);font-size:1.1rem;margin-top:2px;"></i>
                  <div>
                    <p style="margin:0;font-size:0.85rem;font-weight:600;">Disease Outbreak Alert</p>
                    <p style="margin:0;font-size:0.8rem;color:var(--c-slate-400);">Avian Flu in nearby areas</p>
                  </div>
                </div>
                <div style="display:flex;gap:12px;align-items:flex-start;padding:12px;background:var(--c-slate-50);border-radius:10px;border-left:3px solid var(--c-blue);">
                  <i class="bi bi-syringe" style="color:var(--c-blue);font-size:1.1rem;margin-top:2px;"></i>
                  <div>
                    <p style="margin:0;font-size:0.85rem;font-weight:600;">Vaccination Reminder</p>
                    <p style="margin:0;font-size:0.8rem;color:var(--c-slate-400);">Cattle vaccination due next week</p>
                  </div>
                </div>
                <div style="display:flex;gap:12px;align-items:flex-start;padding:12px;background:var(--c-slate-50);border-radius:10px;border-left:3px solid var(--c-emerald);">
                  <i class="bi bi-calendar-check" style="color:var(--c-emerald);font-size:1.1rem;margin-top:2px;"></i>
                  <div>
                    <p style="margin:0;font-size:0.85rem;font-weight:600;">Inspection Scheduled</p>
                    <p style="margin:0;font-size:0.8rem;color:var(--c-slate-400);">Farm inspection on Mar 25, 2026</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="dash-card">
          <div class="dash-card-header">
            <span class="dash-card-title">Report Status Updates</span>
          </div>
          <div class="dash-card-body">
            <div style="display:flex;flex-direction:column;gap:10px;">
              <div style="display:flex;gap:14px;align-items:flex-start;padding:14px;border:1.5px solid var(--c-slate-200);border-radius:10px;">
                <i class="bi bi-exclamation-triangle-fill" style="color:var(--c-red);font-size:1.3rem;margin-top:2px;"></i>
                <div>
                  <p style="margin:0;font-weight:600;font-size:0.9rem;">Disease Symptoms: Chicken showing flu-like symptoms</p>
                  <p style="margin:4px 0 0;font-size:0.8rem;">
                    <span class="badge badge-amber">Pending</span>
                  </p>
                </div>
              </div>
              <div style="display:flex;gap:14px;align-items:flex-start;padding:14px;border:1.5px solid var(--c-slate-200);border-radius:10px;">
                <i class="bi bi-exclamation-triangle-fill" style="color:var(--c-emerald);font-size:1.3rem;margin-top:2px;"></i>
                <div>
                  <p style="margin:0;font-weight:600;font-size:0.9rem;">Livestock Death: 1 pig died unexpectedly</p>
                  <p style="margin:4px 0 0;font-size:0.8rem;">
                    <span class="badge badge-green">Resolved</span>
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Other Farmer Sections (Farm Registration, Livestock, etc.) -->
      <!-- Add other sections similar to admin panel structure -->

    </div>
  </main>

  <!-- Toast Notification -->
    <div id="toast" style="position:fixed;bottom:28px;right:28px;z-index:9999;display:none;">
    <div style="background:var(--c-forest);color:#fff;padding:14px 22px;border-radius:12px;box-shadow:0 8px 28px rgba(0,0,0,0.25);display:flex;align-items:center;gap:10px;font-size:0.9rem;font-weight:500;max-width:340px;animation:fadeIn 0.3s ease;">
      <i class="bi bi-check-circle-fill" style="color:var(--c-emerald);font-size:1.1rem;"></i>
      <span id="toast-msg">Action completed</span>
    </div>
  </div>

  <script src="db.js"></script>
  <script>
    // Farmer Panel JavaScript
    document.addEventListener('DOMContentLoaded', function() {
      DB.seed();
      initFarmerPanel();
    });

    function initFarmerPanel() {
      refreshFarmerStats();
      updateSidebarUser('farmer');
      initFarmerCharts();
    }

    function showToast(msg, isError = false) {
      const toast = document.getElementById('toast');
      const msgEl = document.getElementById('toast-msg');
      const icon = toast.querySelector('i');
      
      if (msgEl) msgEl.textContent = msg;
      if (icon) icon.style.color = isError ? '#ef4444' : '#10b981';
      
      toast.style.display = 'block';
      setTimeout(() => {
        toast.style.display = 'none';
      }, 3500);
    }

    function updateSidebarUser(panelId) {
      const nameEl = document.querySelector('#farmer-sidebar .panel-user-name');
      const roleEl = document.querySelector('#farmer-sidebar .panel-user-role');
      const avatarEl = document.querySelector('#farmer-sidebar .panel-avatar');
      const topAvatarEl = document.querySelector('.topbar-right .panel-avatar');
      
      if (nameEl) nameEl.textContent = 'Juan dela Cruz';
      if (roleEl) roleEl.textContent = 'Farmer';
      if (avatarEl) avatarEl.textContent = 'JD';
      if (topAvatarEl) topAvatarEl.textContent = 'JD';
    }

    function openPanelSidebar(panel) {
      document.getElementById(panel+'-sidebar')?.classList.add('open');
      document.getElementById(panel+'-overlay')?.classList.add('open');
    }

    function closePanelSidebar(panel) {
      document.getElementById(panel+'-sidebar')?.classList.remove('open');
      document.getElementById(panel+'-overlay')?.classList.remove('open');
    }

    function showPanel(panelId, sectionId) {
      document.querySelectorAll('.panel-section').forEach(s => s.classList.remove('active'));
      const target = document.getElementById(`farmer-${sectionId}`);
      if (target) target.classList.add('active');

      document.querySelectorAll('#farmer-sidebar .panel-nav-item').forEach(item => item.classList.remove('active'));
      event.currentTarget.classList.add('active');

      const titleEl = document.getElementById('farmer-section-title');
      const titles = {
        dashboard: 'Dashboard',
        farm: 'Farm Registration',
        livestock: 'Livestock Monitoring',
        incidents: 'Incident Reporting',
        notifications: 'Notifications',
        map: 'Farm Map',
        profile: 'Profile'
      };
      if (titleEl) titleEl.textContent = titles[sectionId] || sectionId;

      closePanelSidebar(panelId);
    }

    function refreshFarmerStats() {
      const livestock = DB.getAll('livestock');
      const incidents = DB.getAll('incidents');
      const farms = DB.getAll('farms');
      
      const totalLivestock = livestock.reduce((sum, l) => sum + (parseInt(l.qty) || 0), 0);
      const activeIncidents = incidents.filter(i => i.status !== 'Resolved').length;
      
      document.getElementById('farmer-total-livestock').textContent = totalLivestock;
      document.getElementById('farmer-active-incidents').textContent = activeIncidents;
      document.getElementById('farmer-pending-inspections').textContent = farms.filter(f => f.status === 'Pending').length;
    }

    function initFarmerCharts() {
      // Livestock by type chart
      const ctx = document.getElementById('farmer-livestock-chart')?.getContext('2d');
      if (ctx) {
        new Chart(ctx, {
          type: 'doughnut',
          data: {
            labels: ['Cattle', 'Swine', 'Poultry', 'Goat'],
            datasets: [{
              data: [8, 6, 120, 4],
              backgroundColor: ['#10b981', '#f59e0b', '#3b82f6', '#8b5cf6']
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              legend: {
                position: 'bottom',
                labels: { font: { size: 11 } }
              }
            }
          }
        });
      }
    }

    // Form handlers
    document.querySelectorAll('form').forEach(form => {
      form.addEventListener('submit', function(e) {
        e.preventDefault();
        showToast('Form submitted successfully!');
        this.reset();
      });
    });
  </script>
</body>
</html>