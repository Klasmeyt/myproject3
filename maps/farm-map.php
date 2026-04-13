<?php
session_start();
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header('Location: ../login.php');
    exit;
}

// ─── Database Connection ───────────────────────────────────────────
try {
    $pdo = new PDO('mysql:host=localhost;dbname=myproject4;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die(json_encode(['error' => 'DB connection failed: ' . $e->getMessage()]));
}

// ─── JSON API ───────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_farms') {
    header('Content-Type: application/json');
    $stmt = $pdo->query("
        SELECT
            f.id, f.name, f.type, f.address, f.latitude, f.longitude, f.status,
            CONCAT(u.firstName, ' ', u.lastName) AS ownerName,
            u.mobile AS ownerMobile,
            COALESCE(SUM(l.qty), 0) AS total_livestock
        FROM farms f
        LEFT JOIN users u ON f.ownerId = u.id
        LEFT JOIN livestock l ON l.farmId = f.id
        WHERE f.status = 'Approved'
          AND ((f.latitude IS NOT NULL AND f.longitude IS NOT NULL)
               OR (f.address IS NOT NULL AND f.address != ''))
        GROUP BY f.id
        ORDER BY f.createdAt DESC
    ");
    echo json_encode($stmt->fetchAll());
    exit;
}

// ─── Summary stats ──────────────────────────────────────────────────
$totalApproved  = $pdo->query("SELECT COUNT(*) FROM farms WHERE status = 'Approved'")->fetchColumn();
$totalLivestock = $pdo->query("SELECT COALESCE(SUM(l.qty),0) FROM livestock l JOIN farms f ON l.farmId=f.id WHERE f.status='Approved'")->fetchColumn();
$totalPending   = $pdo->query("SELECT COUNT(*) FROM farms WHERE status = 'Pending'")->fetchColumn();
$totalFarmTypes = $pdo->query("SELECT COUNT(DISTINCT type) FROM farms WHERE status='Approved'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AgriTrace+ · Live Farm Map</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<link rel="stylesheet" href="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.css"/>

<style>
/* ═══════════════════════════════════════════════════════
   TOKENS
═══════════════════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --brand-900: #052e16;
  --brand-800: #14532d;
  --brand-700: #15803d;
  --brand-600: #16a34a;
  --brand-500: #22c55e;
  --brand-400: #4ade80;
  --brand-100: #dcfce7;

  --amber-500: #f59e0b;
  --amber-400: #fbbf24;
  --red-500:   #ef4444;
  --red-400:   #f87171;
  --slate-400: #94a3b8;
  --slate-500: #64748b;

  --glass-bg:      rgba(255,255,255,0.92);
  --glass-border:  rgba(255,255,255,0.6);
  --glass-shadow:  0 8px 32px rgba(0,0,0,0.12), 0 2px 8px rgba(0,0,0,0.08);

  --sidebar-w: 320px;
  --topbar-h:  60px;

  --font: 'Outfit', system-ui, sans-serif;
  --mono: 'JetBrains Mono', monospace;

  --radius-sm: 8px;
  --radius-md: 14px;
  --radius-lg: 20px;
  --radius-xl: 28px;
}

html, body { height: 100%; font-family: var(--font); overflow: hidden; }

/* ═══════════════════════════════════════════════════════
   MAP
═══════════════════════════════════════════════════════ */
#map {
  position: absolute;
  inset: 0;
  z-index: 1;
}

/* ═══════════════════════════════════════════════════════
   TOPBAR
═══════════════════════════════════════════════════════ */
#topbar {
  position: fixed;
  top: 0; left: 0; right: 0;
  height: var(--topbar-h);
  z-index: 2000;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 0 16px;
  background: linear-gradient(90deg,
    rgba(5,46,22,0.97) 0%,
    rgba(21,128,61,0.95) 55%,
    rgba(22,163,74,0.94) 100%);
  backdrop-filter: blur(20px) saturate(1.8);
  box-shadow: 0 1px 0 rgba(74,222,128,0.15), 0 4px 20px rgba(0,0,0,0.3);
}

/* Logo */
.logo {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
}
.logo-mark {
  width: 34px; height: 34px;
  background: linear-gradient(135deg, var(--brand-500), var(--brand-400));
  border-radius: 10px;
  display: grid;
  place-items: center;
  font-size: 15px;
  color: #fff;
  box-shadow: 0 0 0 2px rgba(74,222,128,0.3), 0 4px 12px rgba(34,197,94,0.3);
}
.logo-name {
  font-size: 1.15rem;
  font-weight: 900;
  color: #fff;
  letter-spacing: -0.5px;
  line-height: 1;
}
.logo-name span { color: var(--brand-400); }
.logo-tag {
  font-size: .62rem;
  color: rgba(255,255,255,.5);
  text-transform: uppercase;
  letter-spacing: .1em;
  font-weight: 500;
}

.btn-back {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 7px 13px;
  background: rgba(255,255,255,.1);
  border: 1px solid rgba(255,255,255,.2);
  color: rgba(255,255,255,.9);
  border-radius: var(--radius-sm);
  font-size: .8rem;
  font-weight: 600;
  font-family: var(--font);
  cursor: pointer;
  text-decoration: none;
  transition: all .2s;
  flex-shrink: 0;
}
.btn-back:hover { background: rgba(255,255,255,.18); transform: translateX(-2px); }

/* ── Map Mode Toggle ── */
.mode-toggle {
  display: flex;
  background: rgba(0,0,0,.25);
  border: 1px solid rgba(255,255,255,.15);
  border-radius: 50px;
  padding: 3px;
  gap: 2px;
}
.mode-btn {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 6px 14px;
  border: none;
  border-radius: 50px;
  font-family: var(--font);
  font-size: .78rem;
  font-weight: 600;
  cursor: pointer;
  transition: all .25s cubic-bezier(.4,0,.2,1);
  color: rgba(255,255,255,.65);
  background: transparent;
  white-space: nowrap;
}
.mode-btn.active {
  background: rgba(255,255,255,.15);
  color: #fff;
  box-shadow: 0 2px 8px rgba(0,0,0,.2);
}
.mode-btn i { font-size: .85rem; }

/* Stats pills */
.topbar-right { margin-left: auto; display: flex; align-items: center; gap: 8px; }

.stat-chip {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 6px 12px;
  background: rgba(255,255,255,.1);
  border: 1px solid rgba(255,255,255,.15);
  border-radius: 50px;
  font-size: .75rem;
  font-weight: 600;
  color: rgba(255,255,255,.9);
  white-space: nowrap;
}
.chip-dot {
  width: 6px; height: 6px;
  border-radius: 50%;
  flex-shrink: 0;
}
.chip-dot.green  { background: var(--brand-400); box-shadow: 0 0 6px var(--brand-400); }
.chip-dot.amber  { background: var(--amber-400); box-shadow: 0 0 6px var(--amber-400); }
.chip-dot.pulse  {
  background: var(--brand-400);
  animation: chip-pulse 2s ease-in-out infinite;
}
@keyframes chip-pulse {
  0%,100% { box-shadow: 0 0 0 0 rgba(74,222,128,.7); }
  50%      { box-shadow: 0 0 0 5px rgba(74,222,128,0); }
}

/* ═══════════════════════════════════════════════════════
   LOADING
═══════════════════════════════════════════════════════ */
#loadingOverlay {
  position: fixed; inset: 0;
  background: radial-gradient(ellipse at 30% 50%, #064e3b 0%, #022c22 50%, #001a13 100%);
  z-index: 9999;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 24px;
  transition: opacity .6s ease;
}
#loadingOverlay.fade-out { opacity: 0; pointer-events: none; }

.load-spinner {
  position: relative;
  width: 72px; height: 72px;
}
.spin-outer {
  position: absolute; inset: 0;
  border: 3px solid transparent;
  border-top-color: var(--brand-500);
  border-radius: 50%;
  animation: spin 1s linear infinite;
}
.spin-inner {
  position: absolute; inset: 10px;
  border: 2px solid transparent;
  border-bottom-color: rgba(34,197,94,.35);
  border-radius: 50%;
  animation: spin 1.5s linear infinite reverse;
}
.spin-core {
  position: absolute; inset: 22px;
  background: rgba(34,197,94,.08);
  border-radius: 50%;
  display: grid;
  place-items: center;
  font-size: 1.1rem;
}
@keyframes spin { to { transform: rotate(360deg); } }

.load-title {
  font-size: 2.2rem;
  font-weight: 900;
  color: #fff;
  letter-spacing: -1px;
}
.load-title span { color: var(--brand-400); }
.load-sub {
  font-size: .85rem;
  color: rgba(255,255,255,.5);
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 500;
}
.load-dots span {
  display: inline-block;
  animation: blink 1.4s ease-in-out infinite;
  font-weight: 700;
  color: var(--brand-400);
}
.load-dots span:nth-child(2) { animation-delay: .2s; }
.load-dots span:nth-child(3) { animation-delay: .4s; }
@keyframes blink { 0%,80%,100% { opacity: 0; } 40% { opacity: 1; } }

/* ═══════════════════════════════════════════════════════
   LIVE STATUS CARD
═══════════════════════════════════════════════════════ */
#liveCard {
  position: fixed;
  top: calc(var(--topbar-h) + 14px);
  left: 16px;
  z-index: 1200;
  background: var(--glass-bg);
  backdrop-filter: blur(16px) saturate(1.6);
  border: 1px solid var(--glass-border);
  border-radius: var(--radius-lg);
  padding: 16px;
  min-width: 220px;
  box-shadow: var(--glass-shadow);
}

.lc-head {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: .7rem;
  font-weight: 800;
  color: var(--brand-800);
  text-transform: uppercase;
  letter-spacing: .1em;
  margin-bottom: 12px;
}
.pulse-ring {
  position: relative;
  width: 10px; height: 10px;
  flex-shrink: 0;
}
.pulse-ring::before, .pulse-ring::after {
  content: '';
  position: absolute;
  inset: 0;
  border-radius: 50%;
  background: var(--brand-500);
}
.pulse-ring::after {
  inset: -4px;
  background: transparent;
  border: 2px solid var(--brand-500);
  animation: ring-pulse 2s ease-out infinite;
}
@keyframes ring-pulse {
  0%   { transform: scale(.6); opacity: 1; }
  100% { transform: scale(2.2); opacity: 0; }
}

.lc-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
}
.lc-cell {
  background: linear-gradient(135deg, #f0fdf4, #dcfce7);
  border: 1px solid #bbf7d0;
  border-radius: var(--radius-sm);
  padding: 10px 12px;
  text-align: center;
}
.lc-num {
  font-size: 1.35rem;
  font-weight: 900;
  color: var(--brand-700);
  line-height: 1;
  font-variant-numeric: tabular-nums;
}
.lc-lbl {
  font-size: .6rem;
  color: var(--brand-600);
  text-transform: uppercase;
  letter-spacing: .08em;
  font-weight: 700;
  margin-top: 3px;
}
.lc-time {
  font-size: .68rem;
  color: var(--slate-500);
  text-align: center;
  margin-top: 10px;
  font-family: var(--mono);
}

/* ═══════════════════════════════════════════════════════
   SIDEBAR
═══════════════════════════════════════════════════════ */
#farmSidebar {
  position: fixed;
  top: calc(var(--topbar-h) + 14px);
  right: 16px;
  width: var(--sidebar-w);
  max-height: calc(100vh - var(--topbar-h) - 28px);
  overflow-y: auto;
  background: var(--glass-bg);
  backdrop-filter: blur(20px) saturate(1.8);
  border: 1px solid var(--glass-border);
  border-radius: var(--radius-xl);
  box-shadow: var(--glass-shadow), 0 0 0 1px rgba(34,197,94,.05);
  z-index: 1500;
  display: none;
  scrollbar-width: thin;
  scrollbar-color: #d1fae5 transparent;
}
#farmSidebar.visible {
  display: block;
  animation: slideIn .3s cubic-bezier(.22,.68,0,1.15);
}
@keyframes slideIn {
  from { opacity:0; transform: translateX(20px) scale(.98); }
  to   { opacity:1; transform: translateX(0) scale(1); }
}

.sb-head {
  position: sticky;
  top: 0;
  background: rgba(255,255,255,.95);
  backdrop-filter: blur(12px);
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 18px;
  border-bottom: 1px solid rgba(0,0,0,.06);
  border-radius: var(--radius-xl) var(--radius-xl) 0 0;
  z-index: 1;
}
.sb-head-title {
  font-size: .85rem;
  font-weight: 800;
  color: var(--brand-800);
  display: flex;
  align-items: center;
  gap: 7px;
  text-transform: uppercase;
  letter-spacing: .06em;
}
.sb-close {
  width: 30px; height: 30px;
  border: none;
  background: rgba(0,0,0,.06);
  border-radius: 8px;
  cursor: pointer;
  font-size: .9rem;
  color: var(--slate-500);
  display: grid;
  place-items: center;
  transition: all .2s;
}
.sb-close:hover { background: rgba(0,0,0,.12); color: #1e293b; }

.sb-content { padding: 18px; }

/* Hero inside sidebar */
.sb-hero {
  border-radius: var(--radius-md);
  padding: 22px 20px;
  color: #fff;
  margin-bottom: 16px;
  position: relative;
  overflow: hidden;
}
.sb-hero::after {
  content: '';
  position: absolute;
  bottom: -30px; right: -30px;
  width: 110px; height: 110px;
  background: rgba(255,255,255,.08);
  border-radius: 50%;
}
.sb-hero-name {
  font-size: 1.25rem;
  font-weight: 900;
  letter-spacing: -.3px;
  position: relative;
}
.sb-hero-type {
  font-size: .78rem;
  opacity: .8;
  margin-top: 2px;
  position: relative;
}
.sb-hero-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: rgba(255,255,255,.18);
  border: 1px solid rgba(255,255,255,.28);
  padding: 4px 10px;
  border-radius: 20px;
  font-size: .68rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .06em;
  margin-top: 10px;
  position: relative;
}

.sb-count {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: var(--radius-md);
  padding: 18px;
  text-align: center;
  margin-bottom: 16px;
}
.sb-count-num {
  font-size: 2.8rem;
  font-weight: 900;
  line-height: 1;
  font-variant-numeric: tabular-nums;
}
.sb-count-lbl {
  font-size: .68rem;
  color: var(--slate-500);
  text-transform: uppercase;
  letter-spacing: .1em;
  font-weight: 700;
  margin-top: 4px;
}
.density-tag {
  display: inline-block;
  padding: 4px 14px;
  border-radius: 20px;
  font-size: .68rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: .07em;
  margin-top: 8px;
}

/* Info rows */
.sb-rows { display: flex; flex-direction: column; gap: 0; }
.sb-row {
  display: grid;
  grid-template-columns: 80px 1fr;
  gap: 8px;
  align-items: start;
  padding: 10px 0;
  border-bottom: 1px solid rgba(0,0,0,.05);
}
.sb-row:last-child { border-bottom: none; }
.sb-row-lbl {
  font-size: .68rem;
  font-weight: 800;
  color: var(--slate-500);
  text-transform: uppercase;
  letter-spacing: .07em;
  padding-top: 1px;
  display: flex;
  align-items: center;
  gap: 4px;
}
.sb-row-val {
  font-size: .85rem;
  color: #1e293b;
  font-weight: 500;
  line-height: 1.45;
}

/* ═══════════════════════════════════════════════════════
   LEAFLET OVERRIDES
═══════════════════════════════════════════════════════ */
.leaflet-popup-content-wrapper {
  border-radius: var(--radius-md) !important;
  box-shadow: 0 20px 40px rgba(0,0,0,.18), 0 0 0 1px rgba(0,0,0,.06) !important;
  padding: 0 !important;
  overflow: hidden;
  border: none !important;
}
.leaflet-popup-content { margin: 0 !important; width: auto !important; }
.leaflet-popup-tip { background: #fff !important; }
.leaflet-popup-close-button { display: none !important; }

/* Filter control */
.filter-ctrl {
  background: var(--glass-bg) !important;
  backdrop-filter: blur(16px) !important;
  border: 1px solid var(--glass-border) !important;
  border-radius: var(--radius-md) !important;
  box-shadow: var(--glass-shadow) !important;
  padding: 14px 16px !important;
  font-family: var(--font) !important;
  min-width: 200px !important;
}
.filter-ctrl-lbl {
  font-size: .68rem;
  font-weight: 800;
  color: var(--brand-800);
  text-transform: uppercase;
  letter-spacing: .1em;
  margin-bottom: 8px;
  display: flex;
  align-items: center;
  gap: 6px;
}
.filter-ctrl-lbl i { color: var(--brand-600); }
.filter-select {
  width: 100%;
  padding: 8px 10px;
  border: 1.5px solid #d1d5db;
  border-radius: var(--radius-sm);
  font-size: .83rem;
  font-family: var(--font);
  color: #1e293b;
  background: #fff;
  cursor: pointer;
  outline: none;
  transition: border-color .2s, box-shadow .2s;
}
.filter-select:focus {
  border-color: var(--brand-500);
  box-shadow: 0 0 0 3px rgba(34,197,94,.15);
}

/* Legend */
.legend-ctrl {
  background: var(--glass-bg) !important;
  backdrop-filter: blur(16px) !important;
  border: 1px solid var(--glass-border) !important;
  border-radius: var(--radius-md) !important;
  box-shadow: var(--glass-shadow) !important;
  padding: 16px !important;
  font-family: var(--font) !important;
  min-width: 185px !important;
}
.leg-title {
  font-size: .7rem;
  font-weight: 800;
  color: var(--brand-800);
  text-transform: uppercase;
  letter-spacing: .09em;
  margin-bottom: 10px;
  padding-bottom: 8px;
  border-bottom: 2px solid #f1f5f9;
  display: flex;
  align-items: center;
  gap: 6px;
}
.leg-item {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 6px;
  font-size: .78rem;
  color: #374151;
  font-weight: 500;
}
.leg-item:last-of-type { margin-bottom: 0; }
.leg-dot {
  width: 20px; height: 20px;
  border-radius: 50%;
  flex-shrink: 0;
  border: 2.5px solid;
}
.leg-dot.gray   { background: rgba(148,163,184,.45); border-color: #64748b; }
.leg-dot.green  { background: rgba(34,197,94,.45);   border-color: #16a34a; }
.leg-dot.amber  { background: rgba(245,158,11,.45);  border-color: #d97706; }
.leg-dot.red    { background: rgba(239,68,68,.45);   border-color: #dc2626; }
.leg-range { font-size: .65rem; color: var(--slate-400); margin-left: auto; }
.leg-footer {
  margin-top: 10px;
  padding-top: 8px;
  border-top: 1px solid #f1f5f9;
  font-size: .65rem;
  color: var(--slate-400);
  text-align: center;
}

/* Geocoder styling */
.leaflet-control-geocoder {
  border-radius: var(--radius-sm) !important;
  box-shadow: var(--glass-shadow) !important;
  border: 1px solid var(--glass-border) !important;
}
.leaflet-control-geocoder-form input {
  font-family: var(--font) !important;
  font-size: .85rem !important;
  padding: 9px 14px !important;
}

/* ═══════════════════════════════════════════════════════
   TOAST
═══════════════════════════════════════════════════════ */
#toastBox {
  position: fixed;
  bottom: 24px;
  left: 50%;
  transform: translateX(-50%);
  z-index: 9000;
  display: flex;
  flex-direction: column;
  gap: 6px;
  align-items: center;
  pointer-events: none;
}
.toast {
  background: #1e293b;
  color: #fff;
  padding: 10px 20px;
  border-radius: 50px;
  font-size: .8rem;
  font-weight: 600;
  box-shadow: 0 8px 24px rgba(0,0,0,.25);
  display: flex;
  align-items: center;
  gap: 8px;
  animation: toastUp .3s cubic-bezier(.22,.68,0,1.2);
  white-space: nowrap;
  pointer-events: auto;
}
.toast.success { background: var(--brand-700); }
.toast.error   { background: #dc2626; }
.toast.info    { background: #2563eb; }
@keyframes toastUp {
  from { opacity:0; transform: translateY(12px); }
  to   { opacity:1; transform: translateY(0); }
}
.toast.out { animation: toastDown .3s ease forwards; }
@keyframes toastDown { to { opacity:0; transform: translateY(12px); } }

/* ═══════════════════════════════════════════════════════
   MAP MODE INDICATOR
═══════════════════════════════════════════════════════ */
#modeIndicator {
  position: fixed;
  bottom: 24px;
  right: 16px;
  background: var(--glass-bg);
  backdrop-filter: blur(12px);
  border: 1px solid var(--glass-border);
  border-radius: 50px;
  padding: 8px 16px;
  font-size: .72rem;
  font-weight: 700;
  color: var(--brand-800);
  box-shadow: var(--glass-shadow);
  display: flex;
  align-items: center;
  gap: 6px;
  text-transform: uppercase;
  letter-spacing: .07em;
  z-index: 1200;
  transition: all .3s ease;
}
#modeIndicator i { font-size: .85rem; color: var(--brand-600); }

/* Satellite mode adjustments */
body.satellite-mode #modeIndicator {
  background: rgba(10,10,10,0.85);
  border-color: rgba(255,255,255,.15);
  color: rgba(255,255,255,.9);
}
body.satellite-mode #modeIndicator i { color: var(--amber-400); }
body.satellite-mode #liveCard {
  background: rgba(10,15,10,0.88);
  border-color: rgba(74,222,128,.15);
}
body.satellite-mode .lc-cell {
  background: rgba(34,197,94,.08);
  border-color: rgba(34,197,94,.18);
}
body.satellite-mode .lc-num { color: var(--brand-400); }
body.satellite-mode .lc-head { color: var(--brand-400); }
body.satellite-mode .lc-lbl { color: rgba(74,222,128,.7); }
body.satellite-mode .lc-time { color: rgba(255,255,255,.4); }
body.satellite-mode #farmSidebar {
  background: rgba(10,15,12,0.92);
  border-color: rgba(74,222,128,.12);
}
body.satellite-mode .sb-head {
  background: rgba(10,15,12,0.95);
  border-color: rgba(255,255,255,.06);
}
body.satellite-mode .sb-head-title { color: var(--brand-400); }
body.satellite-mode .sb-count { background: rgba(255,255,255,.05); border-color: rgba(255,255,255,.08); }
body.satellite-mode .sb-count-lbl { color: rgba(255,255,255,.45); }
body.satellite-mode .sb-row { border-color: rgba(255,255,255,.06); }
body.satellite-mode .sb-row-lbl { color: rgba(255,255,255,.4); }
body.satellite-mode .sb-row-val { color: rgba(255,255,255,.85); }
body.satellite-mode .legend-ctrl,
body.satellite-mode .filter-ctrl {
  background: rgba(10,15,12,0.9) !important;
  border-color: rgba(255,255,255,.1) !important;
}
body.satellite-mode .leg-title { color: var(--brand-400); border-color: rgba(255,255,255,.08); }
body.satellite-mode .leg-item { color: rgba(255,255,255,.75); }
body.satellite-mode .leg-range { color: rgba(255,255,255,.35); }
body.satellite-mode .leg-footer { color: rgba(255,255,255,.3); border-color: rgba(255,255,255,.06); }
body.satellite-mode .filter-ctrl-lbl { color: var(--brand-400); }
body.satellite-mode .filter-select {
  background: rgba(255,255,255,.06) !important;
  border-color: rgba(255,255,255,.15) !important;
  color: rgba(255,255,255,.85) !important;
}
body.satellite-mode .sb-close {
  background: rgba(255,255,255,.08);
  color: rgba(255,255,255,.6);
}
body.satellite-mode .sb-close:hover { background: rgba(255,255,255,.14); color: #fff; }

/* Responsive */
@media (max-width: 640px) {
  #farmSidebar { width: calc(100vw - 28px); right: 14px; }
  .topbar-right .stat-chip:not(:first-child) { display: none; }
  .mode-btn .mode-label { display: none; }
}
</style>
</head>
<body>

<!-- ── Loading ──────────────────────────────────────────────────── -->
<div id="loadingOverlay">
  <div class="load-spinner">
    <div class="spin-outer"></div>
    <div class="spin-inner"></div>
    <div class="spin-core">🌿</div>
  </div>
  <div class="load-title">Agri<span>Trace+</span></div>
  <div class="load-sub">
    Loading farm locations
    <div class="load-dots">
      <span>.</span><span>.</span><span>.</span>
    </div>
  </div>
</div>

<!-- ── Top Bar ──────────────────────────────────────────────────── -->
<header id="topbar">
  <a href="../agriDA.php" class="btn-back">
    <i class="bi bi-arrow-left"></i> Back
  </a>

  <div class="logo">
    <div class="logo-mark"><i class="bi bi-geo-alt-fill"></i></div>
    <div>
      <div class="logo-name">Agri<span>Trace+</span></div>
      <div class="logo-tag">Live Farm Map</div>
    </div>
  </div>

  <!-- MAP MODE TOGGLE -->
  <div class="mode-toggle" id="modeToggle">
    <button class="mode-btn active" id="btnNormal" onclick="setMapMode('normal')">
      <i class="bi bi-map"></i>
      <span class="mode-label">Normal</span>
    </button>
    <button class="mode-btn" id="btnSatellite" onclick="setMapMode('satellite')">
      <i class="bi bi-globe-americas"></i>
      <span class="mode-label">Satellite</span>
    </button>
  </div>

  <div class="topbar-right">
    <div class="stat-chip">
      <span class="chip-dot pulse"></span>
      <i class="bi bi-check-circle-fill" style="color:var(--brand-400);font-size:.8rem;"></i>
      <?php echo number_format($totalApproved); ?> Farms
    </div>
    <div class="stat-chip">
      <span class="chip-dot green"></span>
      <?php echo number_format($totalLivestock); ?> Animals
    </div>
    <div class="stat-chip">
      <span class="chip-dot amber"></span>
      <?php echo number_format($totalPending); ?> Pending
    </div>
  </div>
</header>

<!-- ── Live Card ────────────────────────────────────────────────── -->
<div id="liveCard">
  <div class="lc-head">
    <div class="pulse-ring"></div>
    PH Livestock Monitor
  </div>
  <div class="lc-grid">
    <div class="lc-cell">
      <div class="lc-num"><?php echo $totalApproved; ?></div>
      <div class="lc-lbl">Farms</div>
    </div>
    <div class="lc-cell">
      <div class="lc-num"><?php echo number_format($totalLivestock); ?></div>
      <div class="lc-lbl">Animals</div>
    </div>
  </div>
  <div class="lc-time">⏱ <span id="lastUpdate"><?php echo date('H:i:s'); ?></span></div>
</div>

<!-- ── Map ──────────────────────────────────────────────────────── -->
<div id="map"></div>

<!-- ── Farm Sidebar ─────────────────────────────────────────────── -->
<div id="farmSidebar">
  <div class="sb-head">
    <div class="sb-head-title">
      <i class="bi bi-geo-alt-fill" style="color:var(--brand-500);"></i>
      Farm Details
    </div>
    <button class="sb-close" onclick="closeSidebar()"><i class="bi bi-x-lg"></i></button>
  </div>
  <div class="sb-content" id="farmDetails"></div>
</div>

<!-- ── Mode Indicator ───────────────────────────────────────────── -->
<div id="modeIndicator">
  <i class="bi bi-map" id="modeIcon"></i>
  <span id="modeName">Normal Map</span>
</div>

<!-- ── Toasts ───────────────────────────────────────────────────── -->
<div id="toastBox"></div>

<!-- Scripts -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.js"></script>

<script>
/* ════════════════════════════════════════════════════════
   CONFIG
════════════════════════════════════════════════════════ */
const API_URL = window.location.pathname + '?action=get_farms';

const DISTRICT1 = {
  "Ragay":       [13.8189, 122.7911],
  "Lupi":        [13.7844, 122.8686],
  "Sipocot":     [13.7636, 122.9733],
  "Del Gallego": [13.9111, 122.5858],
  "Cabusao":     [13.7167, 123.1167]
};

/* ════════════════════════════════════════════════════════
   TILE LAYERS  (defined once, swapped by setMapMode)
════════════════════════════════════════════════════════ */
const TILES = {
  normal: L.tileLayer(
    'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png',
    { attribution: '© OpenStreetMap © CARTO | AgriTrace+', subdomains: 'abcd', maxZoom: 19 }
  ),
  satellite: L.tileLayer(
    'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
    { attribution: '© Esri, Maxar, GeoEye | AgriTrace+', maxZoom: 19 }
  ),
  // Labels overlay for satellite mode
  labels: L.tileLayer(
    'https://{s}.basemaps.cartocdn.com/rastertiles/voyager_only_labels/{z}/{x}/{y}{r}.png',
    { attribution: '', subdomains: 'abcd', maxZoom: 19, opacity: 0.85 }
  )
};

let currentMode = 'normal';
let map, farmsLayer, allFarms = [], geocodeCache = {}, allCircleBounds = [];

/* ════════════════════════════════════════════════════════
   DENSITY HELPERS
════════════════════════════════════════════════════════ */
function tier(count) {
  if (count > 20)  return { fill:'#ef4444', stroke:'#dc2626', label:'High',    badge:{ bg:'#fef2f2', color:'#dc2626' } };
  if (count >= 11) return { fill:'#f59e0b', stroke:'#d97706', label:'Medium',  badge:{ bg:'#fffbeb', color:'#92400e' } };
  if (count >= 5)  return { fill:'#22c55e', stroke:'#16a34a', label:'Low',     badge:{ bg:'#f0fdf4', color:'#15803d' } };
  return                   { fill:'#94a3b8', stroke:'#64748b', label:'Minimal', badge:{ bg:'#f8fafc', color:'#475569' } };
}
function radius(count) { return Math.max(9, Math.min(34, 9 + Math.sqrt(count) * 2.9)); }
function heroGrad(count) {
  if (count > 20)  return 'linear-gradient(135deg,#ef4444,#dc2626)';
  if (count >= 11) return 'linear-gradient(135deg,#f59e0b,#d97706)';
  if (count >= 5)  return 'linear-gradient(135deg,#22c55e,#16a34a)';
  return                  'linear-gradient(135deg,#94a3b8,#64748b)';
}

/* ════════════════════════════════════════════════════════
   MAP INIT
════════════════════════════════════════════════════════ */
function initMap() {
  map = L.map('map', {
    minZoom: 5, maxZoom: 18,
    maxBounds: [[2,114],[23,131]],
    maxBoundsViscosity: .85,
    zoomControl: true
  }).setView([12.8797, 121.774], 6);

  // Start with normal tiles
  TILES.normal.addTo(map);

  // Geocoder
  if (typeof L.Control.Geocoder !== 'undefined') {
    L.Control.geocoder({
      defaultMarkGeocode: true,
      placeholder: 'Search location…',
      geocoder: L.Control.Geocoder.nominatim({ geocodingQueryParams: { countrycodes: 'ph' } })
    }).addTo(map);
  }

  addLegend();
  addDistrictFilter();
  loadFarms();
}

/* ════════════════════════════════════════════════════════
   MAP MODE SWITCHER
════════════════════════════════════════════════════════ */
function setMapMode(mode) {
  if (mode === currentMode) return;
  currentMode = mode;

  // Swap tiles
  if (mode === 'satellite') {
    map.removeLayer(TILES.normal);
    TILES.satellite.addTo(map);
    TILES.labels.addTo(map);
    document.body.classList.add('satellite-mode');
    document.getElementById('modeIcon').className = 'bi bi-globe-americas';
    document.getElementById('modeName').textContent = 'Satellite View';
  } else {
    map.removeLayer(TILES.satellite);
    map.removeLayer(TILES.labels);
    TILES.normal.addTo(map);
    document.body.classList.remove('satellite-mode');
    document.getElementById('modeIcon').className = 'bi bi-map';
    document.getElementById('modeName').textContent = 'Normal Map';
  }

  // Update toggle buttons
  document.getElementById('btnNormal').classList.toggle('active', mode === 'normal');
  document.getElementById('btnSatellite').classList.toggle('active', mode === 'satellite');

  showToast(
    mode === 'satellite' ? '🛰 Switched to Satellite View' : '🗺 Switched to Normal Map',
    'info'
  );
}

/* ════════════════════════════════════════════════════════
   DATA
════════════════════════════════════════════════════════ */
async function loadFarms() {
  try {
    const res  = await fetch(API_URL);
    const data = await res.json();
    if (data.error) throw new Error(data.error);
    allFarms = Array.isArray(data) ? data : [];

    if (farmsLayer) map.removeLayer(farmsLayer);
    farmsLayer = L.layerGroup().addTo(map);
    allCircleBounds = [];

    const withCoords   = allFarms.filter(f => f.latitude && f.longitude);
    const needsGeocode = allFarms.filter(f => (!f.latitude || !f.longitude) && f.address);

    withCoords.forEach(f => addCircle(f));
    fitAll();
    geocodeInBg(needsGeocode);
  } catch (err) {
    console.error(err);
    showToast('⚠ Failed to load farm data', 'error');
  } finally {
    hideLoading();
  }
}

function fitAll() {
  if (!allCircleBounds.length) return;
  try { map.fitBounds(L.latLngBounds(allCircleBounds), { padding: [80,80], maxZoom: 14 }); } catch(_) {}
}

async function geocodeInBg(farms) {
  for (const f of farms) {
    if (!f.address) continue;
    if (geocodeCache[f.address]) {
      [f.latitude, f.longitude] = geocodeCache[f.address];
      addCircle(f); fitAll();
      continue;
    }
    try {
      const q   = encodeURIComponent(f.address + ', Philippines');
      const geo = await (await fetch(`https://nominatim.openstreetmap.org/search?q=${q}&format=json&limit=1&countrycodes=ph`, { headers: {'Accept-Language':'en'} })).json();
      if (geo.length) {
        f.latitude  = parseFloat(geo[0].lat);
        f.longitude = parseFloat(geo[0].lon);
        geocodeCache[f.address] = [f.latitude, f.longitude];
        addCircle(f); fitAll();
        showToast(`📍 ${f.name} located`, 'success');
      }
    } catch(e) { console.warn(e); }
    await new Promise(r => setTimeout(r, 1100));
  }
}

/* ════════════════════════════════════════════════════════
   RENDER
════════════════════════════════════════════════════════ */
function renderFarms(farms) {
  if (farmsLayer) map.removeLayer(farmsLayer);
  farmsLayer = L.layerGroup().addTo(map);
  allCircleBounds = [];
  let n = 0;
  farms.forEach(f => { if (addCircle(f)) n++; });
  document.getElementById('lastUpdate').textContent = new Date().toLocaleTimeString('en-PH');
  fitAll();
  if (!n) showToast('No farms with coordinates here.', 'info');
}

function addCircle(farm) {
  const lat = parseFloat(farm.latitude);
  const lng = parseFloat(farm.longitude);
  if (isNaN(lat) || isNaN(lng) || lat === 0 || lng === 0) return false;
  if (!farmsLayer) farmsLayer = L.layerGroup().addTo(map);

  allCircleBounds.push([lat, lng]);
  const count = parseInt(farm.total_livestock) || 0;
  const t = tier(count);

  const circle = L.circleMarker([lat, lng], {
    radius:      radius(count),
    fillColor:   t.fill,
    color:       t.stroke,
    weight:      2.5,
    opacity:     1,
    fillOpacity: .45,
  });

  circle.on('mouseover', function() { this.setStyle({ fillOpacity: .75, weight: 3.5 }); this.openPopup(); });
  circle.on('mouseout',  function() { this.setStyle({ fillOpacity: .45, weight: 2.5 }); });
  circle.on('click', () => { openSidebar(farm, count, t); });

  circle.bindPopup(buildPopup(farm, count, t), { maxWidth: 300, autoPan: true, closeButton: false });
  circle.addTo(farmsLayer);
  return true;
}

/* ════════════════════════════════════════════════════════
   POPUP
════════════════════════════════════════════════════════ */
function buildPopup(farm, count, t) {
  const grad = heroGrad(count);
  return `
  <div style="font-family:'Outfit',sans-serif;width:270px;">
    <div style="background:${grad};color:#fff;padding:16px 18px 14px;">
      <div style="font-size:1.05rem;font-weight:800;letter-spacing:-.2px;">${esc(farm.name)}</div>
      <div style="font-size:.75rem;opacity:.8;margin-top:1px;">${esc(farm.type)} Farm</div>
      <div style="margin-top:12px;display:flex;align-items:baseline;gap:6px;">
        <span style="font-size:2rem;font-weight:900;line-height:1;">${count.toLocaleString()}</span>
        <span style="font-size:.68rem;opacity:.8;text-transform:uppercase;letter-spacing:.08em;">animals · ${t.label}</span>
      </div>
    </div>
    <div style="padding:14px 18px;background:#fff;display:grid;grid-template-columns:70px 1fr;gap:8px 12px;font-size:.8rem;line-height:1.5;">
      <span style="font-weight:700;color:#374151;">Owner</span><span style="color:#1e293b;">${esc(farm.ownerName||'N/A')}</span>
      <span style="font-weight:700;color:#374151;">Address</span><span style="color:#64748b;font-size:.74rem;">${esc(farm.address||'N/A')}</span>
      ${farm.ownerMobile ? `<span style="font-weight:700;color:#374151;">Mobile</span><span><a href="tel:${esc(farm.ownerMobile)}" style="color:#16a34a;font-weight:700;">${esc(farm.ownerMobile)}</a></span>` : ''}
    </div>
    <div style="padding:10px 18px 14px;background:#f8fafc;border-top:1px solid #f1f5f9;text-align:right;cursor:pointer;"
         onclick="document.querySelectorAll('.leaflet-popup-close-button').forEach(b=>b.click?.())">
      <span style="font-size:.68rem;font-weight:800;color:#16a34a;letter-spacing:.05em;">VIEW DETAILS →</span>
    </div>
  </div>`;
}

/* ════════════════════════════════════════════════════════
   SIDEBAR
════════════════════════════════════════════════════════ */
function openSidebar(farm, count, t) {
  if (count === undefined) { count = parseInt(farm.total_livestock) || 0; t = tier(count); }
  const grad = heroGrad(count);
  const countClr = count > 20 ? '#ef4444' : count >= 11 ? '#f59e0b' : count >= 5 ? '#22c55e' : '#94a3b8';

  document.getElementById('farmDetails').innerHTML = `
    <div class="sb-hero" style="background:${grad};">
      <div class="sb-hero-name">${esc(farm.name)}</div>
      <div class="sb-hero-type">${esc(farm.type)} Farm</div>
      <div class="sb-hero-badge"><i class="bi bi-check-circle-fill"></i> ${esc(farm.status)}</div>
    </div>
    <div class="sb-count">
      <div class="sb-count-num" style="color:${countClr};">${count.toLocaleString()}</div>
      <div class="sb-count-lbl">Total Livestock Heads</div>
      <div class="density-tag" style="background:${t.badge.bg};color:${t.badge.color};">${t.label} Density</div>
    </div>
    <div class="sb-rows">
      <div class="sb-row">
        <div class="sb-row-lbl"><i class="bi bi-person-fill"></i> Owner</div>
        <div class="sb-row-val">${esc(farm.ownerName||'N/A')}</div>
      </div>
      <div class="sb-row">
        <div class="sb-row-lbl"><i class="bi bi-tag-fill"></i> Type</div>
        <div class="sb-row-val">${esc(farm.type)}</div>
      </div>
      <div class="sb-row">
        <div class="sb-row-lbl"><i class="bi bi-geo-alt-fill"></i> Address</div>
        <div class="sb-row-val" style="font-size:.78rem;color:#64748b;">${esc(farm.address||'N/A')}</div>
      </div>
      ${farm.latitude && farm.longitude ? `
      <div class="sb-row">
        <div class="sb-row-lbl"><i class="bi bi-pin-map-fill"></i> Coords</div>
        <div class="sb-row-val" style="font-size:.75rem;font-family:'JetBrains Mono',monospace;color:#64748b;">
          ${parseFloat(farm.latitude).toFixed(6)}, ${parseFloat(farm.longitude).toFixed(6)}
        </div>
      </div>` : ''}
      ${farm.ownerMobile ? `
      <div class="sb-row">
        <div class="sb-row-lbl"><i class="bi bi-telephone-fill"></i> Mobile</div>
        <div class="sb-row-val"><a href="tel:${esc(farm.ownerMobile)}" style="color:var(--brand-700);font-weight:700;">${esc(farm.ownerMobile)}</a></div>
      </div>` : ''}
    </div>`;

  const sb = document.getElementById('farmSidebar');
  sb.classList.remove('visible');
  void sb.offsetWidth;
  sb.classList.add('visible');
  map.closePopup();
}

function closeSidebar() { document.getElementById('farmSidebar').classList.remove('visible'); }

/* ════════════════════════════════════════════════════════
   DISTRICT FILTER
════════════════════════════════════════════════════════ */
function addDistrictFilter() {
  const ctrl = L.control({ position: 'topright' });
  ctrl.onAdd = () => {
    const d = L.DomUtil.create('div', 'filter-ctrl leaflet-control');
    d.innerHTML = `
      <div class="filter-ctrl-lbl"><i class="bi bi-funnel-fill"></i> Cam Sur 1st District</div>
      <select class="filter-select" onchange="filterDistrict(this.value)">
        <option value="all">— All Philippines —</option>
        <option value="Cabusao">Cabusao</option>
        <option value="Del Gallego">Del Gallego</option>
        <option value="Lupi">Lupi</option>
        <option value="Ragay">Ragay</option>
        <option value="Sipocot">Sipocot</option>
      </select>`;
    L.DomEvent.disableClickPropagation(d);
    return d;
  };
  ctrl.addTo(map);
}

function filterDistrict(town) {
  closeSidebar();
  if (town === 'all') {
    renderFarms(allFarms.filter(f => f.latitude && f.longitude));
    showToast('Showing all Philippines farms', 'info');
    return;
  }
  const filtered = allFarms.filter(f =>
    (f.address||'').toLowerCase().includes(town.toLowerCase()) && f.latitude && f.longitude
  );
  renderFarms(filtered);
  if (!filtered.length && DISTRICT1[town]) map.setView(DISTRICT1[town], 13);
  showToast(`${filtered.length} farm(s) in ${town}`, filtered.length ? 'success' : 'info');
}

/* ════════════════════════════════════════════════════════
   LEGEND
════════════════════════════════════════════════════════ */
function addLegend() {
  const leg = L.control({ position: 'bottomleft' });
  leg.onAdd = () => {
    const d = L.DomUtil.create('div', 'legend-ctrl leaflet-control');
    d.innerHTML = `
      <div class="leg-title"><i class="bi bi-bar-chart-fill" style="color:var(--brand-600);"></i> Livestock Density</div>
      <div class="leg-item"><div class="leg-dot gray"></div> Minimal <span class="leg-range">&lt;5</span></div>
      <div class="leg-item"><div class="leg-dot green"></div> Low <span class="leg-range">5–10</span></div>
      <div class="leg-item"><div class="leg-dot amber"></div> Medium <span class="leg-range">11–20</span></div>
      <div class="leg-item"><div class="leg-dot red"></div> High <span class="leg-range">20+</span></div>
      <div class="leg-footer">⊙ Circle size ∝ population</div>`;
    return d;
  };
  leg.addTo(map);
}

/* ════════════════════════════════════════════════════════
   HELPERS
════════════════════════════════════════════════════════ */
function hideLoading() {
  const el = document.getElementById('loadingOverlay');
  el.classList.add('fade-out');
  setTimeout(() => el.remove(), 650);
}

function esc(s) {
  const d = document.createElement('div');
  d.textContent = String(s ?? '');
  return d.innerHTML;
}

function showToast(msg, type = 'info') {
  const box = document.getElementById('toastBox');
  const t   = document.createElement('div');
  t.className = `toast ${type}`;
  t.textContent = msg;
  box.appendChild(t);
  setTimeout(() => { t.classList.add('out'); setTimeout(() => t.remove(), 350); }, 3200);
}

/* Boot */
document.addEventListener('DOMContentLoaded', initMap);
</script>
</body>
</html>