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
  <title>AgriTrace+ | Agriculture Official Dashboard</title>
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
  <div class="sidebar-overlay" id="agri-overlay" onclick="closePanelSidebar('agri')"></div>
  
  <!-- Sidebar -->
  <aside class="panel-sidebar" id="agri-sidebar">
    <div class="panel-sidebar-header">
      <div class="panel-sidebar-logo">Agri<span>Trace+</span></div>
      <div class="panel-sidebar-sub">Agriculture Official</div>
    </div>
    <nav class="panel-nav">
      <div class="panel-nav-item active" onclick="showPanel('agri','dashboard')">
        <i class="bi bi-grid-1x2"></i> Official Dashboard
      </div>
      <div class="panel-nav-item" onclick="showPanel('agri','farms')">
        <i class="bi bi-house-check"></i> Farm Inspection
      </div>
      <div class="panel-nav-item" onclick="showPanel('agri','incidents')">
        <i class="bi bi-exclamation-triangle"></i> Incident Management
      </div>
      <div class="panel-nav-item" onclick="showPanel('agri','publicreports')">
        <i class="bi bi-file-earmark-text"></i> Public Reports
      </div>
      <div class="panel-nav-item" onclick="showPanel('agri','map')">
        <i class="bi bi-geo-alt"></i> Geo-Monitoring
      </div>
      <div class="panel-nav-item" onclick="showPanel('agri','reports')">
        <i class="bi bi-bar-chart-line"></i> Reports & Analytics
      </div>
      <div class="panel-nav-item" onclick="showPanel('agri','profile')">
        <i class="bi bi-person-circle"></i> Profile & Security
      </div>
      <div class="panel-nav-divider"></div>
      <div class="panel-nav-item logout" onclick="window.location.href='index.php'">
        <i class="bi bi-power"></i> Logout
      </div>
    </nav>
    <div class="panel-sidebar-footer">
      <div class="panel-user-info">
        <div class="panel-avatar">MR</div>
        <div>
          <div class="panel-user-name">Maria Reyes</div>
          <div class="panel-user-role">Agriculture Official</div>
        </div>
      </div>
    </div>
  </aside>

  <!-- Main Content -->
  <main class="panel-main">
    <div class="panel-topbar">
      <div style="display:flex;align-items:center;gap:12px;">
        <button class="mobile-sidebar-toggle" onclick="openPanelSidebar('agri')">
          <i class="bi bi-list"></i>
        </button>
        <span class="panel-topbar-title" id="agri-section-title">Dashboard</span>
      </div>
      <div class="topbar-right">
        <button class="topbar-notif">
          <i class="bi bi-bell"></i>
          <span class="notif-dot"></span>
        </button>
        <div class="panel-avatar" style="width:32px;height:32px;font-size:0.8rem;">MR</div>
      </div>
    </div>

    <div class="panel-content">
      <!-- Agriculture Official Dashboard -->
      <div class="panel-section active" id="agri-dashboard">
        <div class="page-header-panel">
          <h2>Agriculture Official Dashboard</h2>
          <p>Monitor agricultural activities in your assigned region</p>
        </div>

        <div class="stat-cards">
          <div class="stat-card">
            <div class="stat-icon-wrap stat-icon-green">
              <i class="bi bi-house-door"></i>
            </div>
            <div>
              <div class="stat-num" id="agri-farms-approved">47</div>
              <div class="stat-lbl">Approved Farms</div>
            </div>
          </div>
          <div class="stat-card">
            <div class="stat-icon-wrap stat-icon-amber">
              <i class="bi bi-exclamation-triangle"></i>
            </div>
            <div>
              <div class="stat-num" id="agri-active-incidents">8</div>
              <div class="stat-lbl">Active Incidents</div>
            </div>
          </div>
          <div class="stat-card">
            <div class="stat-icon-wrap stat-icon-blue">
              <i class="bi bi-file-earmark-text"></i>
            </div>
            <div>
              <div class="stat-num" id="agri-pending-public">5</div>
              <div class="stat-lbl">Pending Public Reports</div>
            </div>
          </div>
          <div class="stat-card">
            <div class="stat-icon-wrap stat-icon-red">
              <i class="bi bi-bell"></i>
            </div>
            <div>
              <div class="stat-num" id="agri-active-alerts">3</div>
              <div class="stat-lbl">Active Alerts</div>
            </div>
          </div>
        </div>

        <div class="dash-row">
          <div class="dash-card">
            <div class="dash-card-header">
              <span class="dash-card-title">Recent Incidents</span>
            </div>
            <div class="dash-card-body">
              <div style="display:flex;flex-direction:column;gap:10px;">
                <div style="display:flex;justify-content:space-between;align-items:center;padding:12px;border:1px solid var(--c-slate-200);border-radius:10px;">
                  <div>
                    <p style="margin:0;font-weight:600;font-size:0.88rem;">Disease Symptoms</p>
                    <p style="margin:3px 0 0;font-size:0.78rem;color:var(--c-slate-400);">
                      Status: <span class="badge badge-amber">Pending</span>
                    </p>
                  </div>
                  <button class="btn btn-panel btn-sm" onclick="showToast('Incident resolved!')">
                    Resolve
                  </button>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:12px;border:1px solid var(--c-slate-200);border-radius:10px;">
                  <div>
                    <p style="margin:0;font-weight:600;font-size:0.88rem;">Livestock Death</p>
                    <p style="margin:3px 0 0;font-size:0.78rem;color:var(--c-slate-400);">
                      Status: <span class="badge badge-amber">Pending</span>
                    </p>
                  </div>
                  <button class="btn btn-panel btn-sm" onclick="showToast('Incident resolved!')">
                    Resolve
                  </button>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:12px;border:1px solid var(--c-slate-200);border-radius:10px;">
                  <div>
                    <p style="margin:0;font-weight:600;font-size:0.88rem;">Outbreak Alert</p>
                    <p style="margin:3px 0 0;font-size:0.78rem;color:var(--c-slate-400);">
                      Status: <span class="badge badge-red">Critical</span>
                    </p>
                  </div>
                  <button class="btn btn-panel btn-sm" onclick="showToast('Incident resolved!')">
                    Resolve
                  </button>
                </div>
              </div>
            </div>
          </div>

          <div class="dash-card">
            <div class="dash-card-header">
              <span class="dash-card-title">Approved Farms</span>
            </div>
            <div class="dash-card-body">
              <div style="display:flex;flex-direction:column;gap:10px;">
                <div style="padding:12px;border:1px solid var(--c-slate-200);border-radius:10px;">
                  <p style="margin:0;font-weight:600;font-size:0.88rem;">Green Valley Farm</p>
                  <p style="margin:3px 0 0;font-size:0.78rem;color:var(--c-slate-400);">Type: Cattle Farm</p>
                </div>
                <div style="padding:12px;border:1px solid var(--c-slate-200);border-radius:10px;">
                  <p style="margin:0;font-weight:600;font-size:0.88rem;">Sunny Acres Poultry</p>
                  <p style="margin:3px 0 0;font-size:0.78rem;color:var(--c-slate-400);">Type: Poultry Farm</p>
                </div>
                <div style="padding:12px;border:1px solid var(--c-slate-200);border-radius:10px;">
                  <p style="margin:0;font-weight:600;font-size:0.88rem;">Hillside Ranch</p>
                  <p style="margin:3px 0 0;font-size:0.78rem;color:var(--c-slate-400);">Type: Mixed Farm</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Farm Inspection -->
      <div class="panel-section" id="agri-farms">
        <div class="page-header-panel">
          <h2>Farm Inspection</h2>
          <p>View and inspect all registered farms in your region</p>
        </div>
        <div class="filter-bar">
          <div class="search-wrap">
            <i class="bi bi-search"></i>
            <input type="text" placeholder="Search farms..." oninput="renderAgriFarms(this.value)">
          </div>
          <select class="form-select panel-select" style="width:auto;">
            <option>All Status</option>
            <option>Approved</option>
            <option>Pending</option>
            <option>Rejected</option>
          </select>
        </div>
        <div class="dash-card">
          <div class="table-wrap">
            <table id="agri-farms-table">
              <thead>
                <tr>
                  <th>Farm Name</th>
                  <th>Owner</th>
                  <th>Type</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td>Green Valley Farm</td>
                  <td>Juan dela Cruz</td>
                  <td>Cattle Farm</td>
                  <td><span class="badge badge-green">Approved</span></td>
                  <td>
                    <button class="btn btn-outline btn-sm" onclick="showToast('Inspecting Green Valley Farm')">
                      Inspect
                    </button>
                  </td>
                </tr>
                <tr>
                  <td>Sunny Acres Poultry</td>
                  <td>Pedro Santos</td>
                  <td>Poultry Farm</td>
                  <td><span class="badge badge-green">Approved</span></td>
                  <td>
                    <button class="btn btn-outline btn-sm" onclick="showToast('Inspecting Sunny Acres Poultry')">
                      Inspect
                    </button>
                  </td>
                </tr>
                <tr>
                  <td>Hillside Ranch</td>
                  <td>Ana Reyes</td>
                  <td>Mixed Farm</td>
                  <td><span class="badge badge-green">Approved</span></td>
                  <td>
                    <button class="btn btn-outline btn-sm" onclick="showToast('Inspecting Hillside Ranch')">
                      Inspect
                    </button>
                  </td>
                </tr>
                <tr>
                  <td>Bautista Farm</td>
                  <td>Lito Bautista</td>
                  <td>Cattle Farm</td>
                  <td><span class="badge badge-amber">Pending</span></td>
                  <td>
                    <button class="btn btn-outline btn-sm" onclick="showToast('Reviewing Bautista Farm')">
                      Review
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

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
    // DA Officer Panel JavaScript
    document.addEventListener('DOMContentLoaded', function() {
      DB.seed();
      initAgriPanel();
    });

    function initAgriPanel() {
      refreshStats();
      renderAgriFarms();
      updateSidebarUser('agri');
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
      // Update user info in sidebar
      const nameEl = document.querySelector('#agri-sidebar .panel-user-name');
      const roleEl = document.querySelector('#agri-sidebar .panel-user-role');
      const avatarEl = document.querySelector('#agri-sidebar .panel-avatar');
      const topAvatarEl = document.querySelector('.topbar-right .panel-avatar');
      
      if (nameEl) nameEl.textContent = 'Maria Reyes';
      if (roleEl) roleEl.textContent = 'Agriculture Official';
      if (avatarEl) avatarEl.textContent = 'MR';
      if (topAvatarEl) topAvatarEl.textContent = 'MR';
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
      // Hide all sections
      document.querySelectorAll('#page-agri-panel .panel-section').forEach(s => s.classList.remove('active'));
      const target = document.getElementById(`agri-${sectionId}`);
      if (target) target.classList.add('active');

      // Update nav active state
      document.querySelectorAll('#agri-sidebar .panel-nav-item').forEach(item => item.classList.remove('active'));
      event.currentTarget.classList.add('active');

      // Update topbar title
      const titleEl = document.getElementById('agri-section-title');
      const titles = {
        dashboard: 'Dashboard',
        farms: 'Farm Inspection',
        incidents: 'Incident Management',
        publicreports: 'Public Reports',
        map: 'Geo-Monitoring',
        reports: 'Reports & Analytics',
        profile: 'Profile & Security'
      };
      if (titleEl) titleEl.textContent = titles[sectionId] || sectionId;

      closePanelSidebar(panelId);
    }

    function refreshStats() {
      // Update stats from database
      const farms = DB.getAll('farms');
      const incidents = DB.getAll('incidents');
      const publicReports = DB.getAll('publicReports');
      
      document.getElementById('agri-farms-approved').textContent = farms.filter(f => f.status === 'Approved').length;
      document.getElementById('agri-active-incidents').textContent = incidents.filter(i => i.status !== 'Resolved').length;
      document.getElementById('agri-pending-public').textContent = publicReports.filter(r => r.status === 'Pending').length;
      document.getElementById('agri-active-alerts').textContent = publicReports.filter(r => r.status === 'Urgent').length;
    }

    function renderAgriFarms(filter = '') {
      const tbody = document.querySelector('#agri-farms-table tbody');
      if (!tbody) return;

      let farms = DB.getAll('farms');
      if (filter) {
        farms = farms.filter(f => 
          f.name.toLowerCase().includes(filter.toLowerCase()) ||
          f.owner.toLowerCase().includes(filter.toLowerCase())
        );
      }

      if (!farms.length) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--c-slate-400);padding:24px;">No farms found.</td></tr>';
        return      }

      tbody.innerHTML = farms.map(farm => `
        <tr>
          <td>${farm.name}</td>
          <td>${farm.owner}</td>
          <td>${farm.type}</td>
          <td>${badge(farm.status)}</td>
          <td>
            <div style="display:flex;gap:6px;">
              <button class="btn btn-panel btn-sm" onclick="inspectFarm(${farm.id})">
                Inspect
              </button>
              ${farm.status === 'Pending' ? 
                `<button class="btn btn-outline btn-sm" onclick="reviewFarm(${farm.id})">Review</button>` : 
                ''
              }
            </div>
          </td>
        </tr>
      `).join('');
    }

    function badge(status) {
      const badgeMap = {
        'Approved': 'badge-green',
        'Pending': 'badge-amber',
        'Rejected': 'badge-red',
        'Active': 'badge-blue',
        'Inactive': 'badge-gray'
      };
      return `<span class="badge ${badgeMap[status] || 'badge-gray'}">${status}</span>`;
    }

    function inspectFarm(id) {
      const farm = DB.getById('farms', id);
      showToast(`Inspecting ${farm ? farm.name : 'farm'} - Field visit scheduled`);
    }

    function reviewFarm(id) {
      const farm = DB.getById('farms', id);
      showToast(`Reviewing ${farm ? farm.name : 'farm'} registration`);
    }

    // Incident Management Section
    document.querySelector('#agri-incidents')?.addEventListener('click', function() {
      renderAgriIncidents();
    });

    function renderAgriIncidents() {
      // This will be populated when navigating to incidents section
      console.log('Rendering incidents...');
    }

    // Public Reports Section
    document.querySelector('#agri-publicreports')?.addEventListener('click', function() {
      renderAgriPublicReports();
    });

    function renderAgriPublicReports() {
      // This will be populated when navigating to public reports section
      console.log('Rendering public reports...');
    }
  </script>
</body>
</html>