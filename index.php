<?php session_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AgriTrace+ | Digital Livestock Registration & Reporting System</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Syne:wght@700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    :root {
      --primary: #10b981;
      --primary-dark: #059669;
      --bg-dark: #041a14;
      --glass: rgba(255, 255, 255, 0.05);
      --glass-border: rgba(255, 255, 255, 0.12);
      --text-main: #ffffff;
      --text-muted: rgba(255, 255, 255, 0.7);
    }

    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'DM Sans', sans-serif; }

    body { background-color: var(--bg-dark); color: var(--text-main); overflow-x: hidden; min-height: 100vh; display: flex; flex-direction: column; }

    .hero-bg {
      position: fixed; top: 0; left: 0; width: 100%; height: 100%;
      background: linear-gradient(rgba(4, 26, 20, 0.8), rgba(4, 26, 20, 0.9)), 
                  url('https://images.unsplash.com/photo-1500382017468-9049fed747ef?auto=format&fit=crop&q=80&w=2000');
      background-size: cover; background-position: center; z-index: -1;
    }

    .navbar { display: flex; justify-content: space-between; align-items: center; padding: 20px 8%; z-index: 1000; }
    .navbar-logo { font-family: 'Syne', sans-serif; font-size: 1.5rem; font-weight: 800; display: flex; align-items: center; gap: 8px; }
    .navbar-logo span { color: var(--primary); }
    .navbar-links { display: flex; list-style: none; gap: 25px; }
    .navbar-links a { text-decoration: none; color: var(--text-muted); font-weight: 500; transition: 0.3s; }
    .menu-toggle { display: none; font-size: 1.8rem; color: white; cursor: pointer; background: none; border: none; }

    .hero-section { flex: 1; display: flex; align-items: center; justify-content: center; text-align: center; padding: 40px 20px; }
    .hero-title { font-family: 'Syne', sans-serif; font-size: clamp(2.2rem, 5vw, 4rem); margin-bottom: 15px; }
    .text-gradient { color: var(--primary); }
    .hero-buttons { display: flex; gap: 12px; justify-content: center; margin: 40px 0; flex-wrap: wrap; }
    .btn { padding: 14px 28px; border-radius: 12px; font-weight: 600; cursor: pointer; border: none; transition: 0.3s; text-decoration: none; display: inline-block; }
    .btn-primary { background: var(--primary); color: white; }
    .btn-outline { background: var(--glass); color: white; border: 1px solid var(--glass-border); }
    .btn:hover { opacity: 0.9; text-decoration: none; }

    .hero-features { display: flex; gap: 15px; justify-content: center; margin-top: 20px; padding-bottom: 20px; }
    .feature-item {
      background: rgba(255, 255, 255, 0.04); backdrop-filter: blur(8px); border: 1px solid var(--glass-border);
      padding: 30px; border-radius: 24px; width: 100px; height: 100px; display: flex; align-items: center; justify-content: center;
      transition: 0.3s ease;
    }
    .feature-item i { font-size: 2rem; color: var(--primary); }
    .feature-item:hover { background: rgba(255, 255, 255, 0.08); transform: translateY(-5px); border-color: var(--primary); }

    .site-footer { padding: 25px; text-align: center; color: var(--text-muted); font-size: 0.85rem; border-top: 1px solid var(--glass-border); }

    @media (max-width: 768px) {
      .menu-toggle { display: block; }
      .navbar-links { position: absolute; top: 80px; left: 5%; right: 5%; background: rgba(4, 26, 20, 0.98); flex-direction: column; padding: 30px; border-radius: 20px; border: 1px solid var(--glass-border); display: none; }
      .navbar-links.active { display: flex; }
      .hero-features { gap: 10px; }
      .feature-item { width: 85px; height: 85px; padding: 20px; }
      .feature-item i { font-size: 1.6rem; }
    }
  </style>
</head>
<body>
  <div class="hero-bg"></div>

  <nav class="navbar">
    <div class="navbar-logo">
      <i class="bi bi-leaf-fill" style="color: var(--primary);"></i> Agri<span>Trace+</span>
    </div>
    <button class="menu-toggle" onclick="toggleMenu()">
      <i class="bi bi-list" id="menu-icon"></i>
    </button>
    <ul class="navbar-links" id="nav-links">
      <li><a href="index.php">Home</a></li>
      <li><a href="about.php">About</a></li>
      <li><a href="contact.php">Contact</a></li>
      <li><a href="login.php">Login</a></li>
    </ul>
  </nav>

  <main class="hero-section">
    <div style="max-width: 800px; margin: 0 auto;">
      <h1 class="hero-title">Welcome to <br><span class="text-gradient">AgriTrace+</span></h1>
      <p style="color: var(--text-muted); font-size: 1.2rem; margin-bottom: 20px;">Digital Livestock Registration & Reporting System</p>
      
      <div class="hero-buttons">
        <a href="login.php" class="btn btn-primary">Get Started</a>
        <a href="public-report-form.php" class="btn btn-outline">Public Report</a>
      </div>

      <div class="hero-features">
        <div class="feature-item" title="Geo-Tagging">
          <i class="bi bi-geo-alt"></i>
        </div>
        <div class="feature-item" title="Secure Data">
          <i class="bi bi-shield-lock"></i>
        </div>
        <div class="feature-item" title="Real-time Alerts">
          <i class="bi bi-broadcast"></i>
        </div>
      </div>
    </div>
  </main>

  <footer class="site-footer">
    © 2026 AgriTrace Technologies | <a href="about.php" style="color: var(--primary);">About</a> | <a href="contact.php" style="color: var(--primary);">Contact</a>
  </footer>

  <script>
    function toggleMenu() {
      const links = document.getElementById('nav-links');
      const icon = document.getElementById('menu-icon');
      links.classList.toggle('active');
      icon.classList.toggle('bi-list');
      icon.classList.toggle('bi-x-lg');
    }
  </script>
</body>
</html>