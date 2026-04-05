<?php
session_start();
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    exit(json_encode(['error' => 'Unauthorized']));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AgriTrace+ Farm Map - Philippines</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Syne:wght@700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.css" />
    <link rel="stylesheet" href="../assets/css/map-styles.css">
</head>
<body>
    <div id="map" style="height: 100vh; width: 100vw;"></div>
    
    <!-- Loading Overlay -->
    <div id="loadingOverlay" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(6,78,59,0.95); z-index: 10000; display: flex; flex-direction: column; justify-content: center; align-items: center; color: white;">
        <div style="font-size: 3rem; margin-bottom: 20px;"><i class="bi bi-geo-alt-fill"></i></div>
        <h2 style="font-family: 'Syne', sans-serif; font-size: 2rem; margin-bottom: 10px;">AgriTrace+ Farm Map</h2>
        <p style="font-size: 1.1rem; opacity: 0.9;">Loading Philippines farms...</p>
    </div>

    <!-- Sidebar -->
    <div id="sidebar" style="position: fixed; top: 20px; right: 20px; background: white; padding: 20px; border-radius: 16px; box-shadow: 0 20px 40px rgba(0,0,0,0.15); z-index: 1000; width: 320px; max-height: 80vh; overflow-y: auto; display: none;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #f1f5f9;">
            <h3 style="margin: 0; font-family: 'Syne', sans-serif; color: #064e3b; font-size: 1.4rem;">Farm Details</h3>
            <button onclick="closeSidebar()" style="background: #ef4444; color: white; border: none; border-radius: 8px; padding: 8px 12px; cursor: pointer;"><i class="bi bi-x"></i></button>
        </div>
        <div id="farmDetails"></div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.js"></script>
    <script src="../assets/js/farm-map.js"></script>
</body>
</html>