<?php session_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>About AgriTrace+ | Digital Livestock System</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Syne:wght@700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    :root {
      --primary-green: #10b981;
      --dark-bg: #062c23;
      --glass: rgba(255, 255, 255, 0.05);
      --glass-border: rgba(255, 255, 255, 0.1);
    }

    * { margin: 0; padding: 0; box-sizing: border-box; }
    html, body { overflow-x: hidden; width: 100%; height: 100%; }
    body { font-family: 'DM Sans', sans-serif; background-color: var(--dark-bg); color: white; line-height: 1.6; }

    .hero-bg {
      position: fixed; top: 0; left: 0; width: 100%; height: 100%;
      background: linear-gradient(rgba(6, 44, 35, 0.8), rgba(6, 44, 35, 0.9)), 
                  url('https://images.unsplash.com/photo-1500382017468-9049fed747ef?q=80&w=2000') center/cover;
      z-index: -1;
    }

    .navbar {
      position: fixed; top: 0; width: 100%; display: flex; justify-content: space-between; align-items: center;
      padding: 15px 8%; background: rgba(6, 44, 35, 0.95); backdrop-filter: blur(10px); z-index: 1001;
      border-bottom: 1px solid var(--glass-border);
    }
    .navbar-logo { font-family: 'Syne', sans-serif; font-size: 1.5rem; font-weight: 800; color: white; text-decoration: none; }
    .navbar-logo span { color: var(--primary-green); }
    .navbar-links { display: flex; list-style: none; align-items: center; gap: 30px; }
    .navbar-links a { text-decoration: none; color: rgba(255,255,255,0.7); transition: 0.3s; }
    .navbar-links a.active { color: white; font-weight: 600; }
    .btn-nav { background: var(--primary-green) !important; color: white !important; padding: 10px 24px !important; border-radius: 10px; font-weight: 600; box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3); }
    .menu-toggle { display: none; font-size: 1.8rem; color: white; cursor: pointer; }

    .content-page-wrap { padding-top: 100px; padding-bottom: 60px; min-height: 100vh; }
    .container { width: 90%; max-width: 1100px; margin: 0 auto; }
    .content-header { text-align: center; margin-bottom: 40px; padding: 0 10px; }
    .content-header h1 { font-family: 'Syne', sans-serif; font-size: clamp(2rem, 8vw, 3rem); margin-bottom: 10px; }
    .glass-card {
      background: var(--glass); backdrop-filter: blur(15px); border: 1px solid var(--glass-border);
      padding: 30px; border-radius: 24px; margin-bottom: 20px;
    }

    @media (max-width: 768px) {
      .menu-toggle { display: block; }
      .navbar-links {
        position: absolute; top: 70px; right: -100%; width: 70%; height: 100vh;
        background: rgba(6, 44, 35, 0.98); flex-direction: column; padding-top: 50px;
        transition: 0.4s ease-in-out; backdrop-filter: blur(20px); border-left: 1px solid var(--glass-border);
      }
      .navbar-links.active { right: 0; }
      .navbar-links li { width: 100%; text-align: center; margin: 15px 0; }
      .btn-nav { display: inline-block; width: 80%; }
    }
    .site-footer { text-align: center; padding: 40px; color: rgba(255,255,255,0.4); font-size: 0.9rem; }
  </style>
</head>
<body>
  <div class="hero-bg"></div>

  <nav class="navbar">
    <a href="index.php" class="navbar-logo">
      <i class="bi bi-leaf-fill"></i> Agri<span>Trace+</span>
    </a>
    <div class="menu-toggle" id="mobile-menu">
      <i class="bi bi-list"></i>
    </div>
    <ul class="navbar-links" id="nav-list">
      <li><a href="index.php">Home</a></li>
      <li><a href="about.php" class="active">About</a></li>
      <li><a href="contact.php">Contact</a></li>
      <li><a href="login.php" class="btn-nav">Login</a></li>
    </ul>
  </nav>

  <div class="content-page-wrap">
    <div class="container">
      <div class="content-header">
        <h1><i class="bi bi-info-circle-fill" style="color: var(--primary-green);"></i> About AgriTrace+</h1>
        <p>Revolutionizing Agricultural Traceability</p>
      </div>

      <div class="glass-card">
        <h2><i class="bi bi-bullseye"></i> Our Mission</h2>
        <p>AgriTrace+ is committed to transforming agricultural practices through cutting-edge technology and comprehensive traceability solutions.</p>
        <h2 style="font-size: 1.5rem; margin-top: 20px;"><i class="bi bi-eye"></i> Our Vision</h2>
        <p>To become the leading agricultural traceability platform connecting farmers and officials.</p>
      </div>

      <div class="glass-card">
        <h2><i class="bi bi-gear-fill"></i> What We Do</h2>
        <ul style="list-style: none; color: rgba(255,255,255,0.7);">
          <li style="margin-bottom: 10px;"><i class="bi bi-check-circle-fill" style="color: var(--primary-green);"></i> Real-time livestock tracking</li>
          <li style="margin-bottom: 10px;"><i class="bi bi-check-circle-fill" style="color: var(--primary-green);"></i> Geo-tagging integration</li>
          <li style="margin-bottom: 10px;"><i class="bi bi-check-circle-fill" style="color: var(--primary-green);"></i> Secure data management</li>
          <li style="margin-bottom: 10px;"><i class="bi bi-check-circle-fill" style="color: var(--primary-green);"></i> Incident reporting system</li>
          <li style="margin-bottom: 10px;"><i class="bi bi-check-circle-fill" style="color: var(--primary-green);"></i> Public reporting portal</li>
        </ul>
      </div>

      <div class="glass-card">
        <h2><i class="bi bi-people-fill"></i> Our Team</h2>
        <p>Built by agriculture experts, software engineers, and data scientists dedicated to modernizing Philippine agriculture.</p>
      </div>
    </div>

    <footer class="site-footer">
      © 2026 AgriTrace Technologies
    </footer>
  </div>

  <script>
    const menuToggle = document.getElementById('mobile-menu');
    const navList = document.getElementById('nav-list');
    menuToggle.addEventListener('click', () => {
      navList.classList.toggle('active');
      const icon = menuToggle.querySelector('i');
      icon.classList.toggle('bi-list');
      icon.classList.toggle('bi-x-lg');
    });
  </script>
</body>
</html>