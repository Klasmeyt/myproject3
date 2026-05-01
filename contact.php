<?php
session_start();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name = trim($_POST['name'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $subject = trim($_POST['subject'] ?? '');
  $message = trim($_POST['message'] ?? '');
  
  if ($name && $email && $subject && $message) {
    // Log to database or send email
    $form_submitted = true;
    $to = "support@agritrace.ph";
    $headers = "From: $email\r\nReply-To: $email\r\n";
    mail($to, "AgriTrace+ Contact: $subject", $message, $headers);
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Contact Us | AgriTrace+</title>
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
    html, body { overflow-x: hidden; width: 100%; }
    body { font-family: 'DM Sans', sans-serif; background-color: var(--dark-bg); color: white; }

    .hero-bg {
      position: fixed; top: 0; left: 0; width: 100%; height: 100%;
      background: linear-gradient(rgba(6, 44, 35, 0.8), rgba(6, 44, 35, 0.9)), 
                  url('https://images.unsplash.com/photo-1500382017468-9049fed747ef?q=80&w=2000') center/cover;
      z-index: -1;
    }

    .navbar {
      position: fixed; top: 0; width: 100%; display: flex; justify-content: space-between; align-items: center;
      padding: 15px 8%; background: rgba(6, 44, 35, 0.95); backdrop-filter: blur(10px); z-index: 10000;
      border-bottom: 1px solid var(--glass-border);
    }
    .navbar-logo { font-family: 'Syne', sans-serif; font-size: 1.5rem; font-weight: 800; color: white; text-decoration: none; cursor: pointer; }
    .navbar-logo span { color: var(--primary-green); }
    .navbar-links { display: flex; list-style: none; align-items: center; gap: 30px; }
    .navbar-links a { text-decoration: none; color: rgba(255,255,255,0.7); font-weight: 500; transition: 0.3s; }
    .navbar-links a.active { color: white; }
    .btn-nav { background: var(--primary-green) !important; color: white !important; padding: 10px 24px !important; border-radius: 12px; font-weight: 600; }
    .menu-toggle { display: none; font-size: 1.8rem; color: white; cursor: pointer; }

    .content-page-wrap { padding-top: 120px; padding-bottom: 80px; min-height: 100vh; }
    .container { width: 90%; max-width: 1100px; margin: 0 auto; }
    .content-header { text-align: center; margin-bottom: 40px; }
    .content-header h1 { font-family: 'Syne', sans-serif; font-size: clamp(2rem, 8vw, 3rem); }

    .contact-info-grid {
      display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 40px;
    }
    .contact-card {
      background: var(--glass); border: 1px solid var(--glass-border); padding: 25px; border-radius: 20px;
      text-align: center; backdrop-filter: blur(10px);
    }
    .contact-card i { font-size: 1.8rem; color: var(--primary-green); margin-bottom: 10px; display: block; }

    .glass-card {
      background: var(--glass); backdrop-filter: blur(15px); border: 1px solid var(--glass-border);
      padding: clamp(20px, 5vw, 40px); border-radius: 24px;
    }

    .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    .form-input {
      width: 100%; background: rgba(255,255,255,0.05); border: 1px solid var(--glass-border);
      padding: 14px; border-radius: 12px; color: white; margin-bottom: 20px; font-family: inherit;
    }
    .btn-primary {
      background: var(--primary-green); color: white; border: none; padding: 15px 40px; border-radius: 12px;
      font-weight: 600; cursor: pointer; width: 100%; max-width: 250px;
    }

    @media (max-width: 768px) {
      .menu-toggle { display: block; }
      .navbar-links {
        position: absolute; top: 65px; right: -100%; width: 75%; height: 100vh;
        background: rgba(6, 44, 35, 0.98); flex-direction: column; padding-top: 40px;
        transition: 0.4s ease; border-left: 1px solid var(--glass-border);
      }
      .navbar-links.active { right: 0; }
      .form-grid { grid-template-columns: 1fr; }
      .btn-primary { max-width: 100%; }
    }
    .site-footer { text-align: center; padding: 40px; color: rgba(255,255,255,0.4); }
  </style>
</head>
<body>
  <div class="hero-bg"></div>

  <nav class="navbar">
    <div class="navbar-logo" onclick="window.location.href='index.php'">
      <i class="bi bi-leaf-fill"></i> Agri<span>Trace+</span>
    </div>
    <div class="menu-toggle" id="mobile-menu">
      <i class="bi bi-list"></i>
    </div>
    <ul class="navbar-links" id="nav-list">
      <li><a href="index.php">Home</a></li>
      <li><a href="about.php">About</a></li>
      <li><a href="contact.php" class="active">Contact</a></li>
      <li><a href="login.php" class="btn-nav">Login</a></li>
    </ul>
  </nav>

  <div class="content-page-wrap">
    <div class="container">
      <div class="content-header">
        <h1><i class="bi bi-envelope-fill" style="color: var(--primary-green);"></i> Contact Us</h1>
        <p>Get in touch with the AgriTrace+ team</p>
      </div>

      <div class="contact-info-grid">
        <div class="contact-card">
          <i class="bi bi-envelope-fill"></i>
          <h5>Email</h5>
          <p>support@agritrace.ph</p>
        </div>
        <div class="contact-card">
          <i class="bi bi-telephone-fill"></i>
          <h5>Phone</h5>
          <p>+63 2 8XXX XXXX</p>
        </div>
        <div class="contact-card">
          <i class="bi bi-geo-alt-fill"></i>
          <h5>Address</h5>
          <p>Ragay, Philippines</p>
        </div>
      </div>

      <div class="glass-card">
        <h2><i class="bi bi-chat-dots-fill" style="color: var(--primary-green);"></i> Message Us</h2>
        
        <?php if (isset($form_submitted) && $form_submitted): ?>
          <div style="background: rgba(16, 185, 129, 0.2); border: 1px solid var(--primary-green); padding: 15px; border-radius: 12px; margin-bottom: 20px; color: #d1fae5;">
            <i class="bi bi-check-circle-fill"></i> Thank you! Your message has been sent successfully. We'll respond within 24 hours.
          </div>
        <?php endif; ?>
        
        <form method="POST">
          <div class="form-grid">
            <div>
              <input type="text" name="name" class="form-input" placeholder="Your Name" required>
            </div>
            <div>
              <input type="email" name="email" class="form-input" placeholder="Email Address" required>
            </div>
          </div>
          <input type="text" name="subject" class="form-input" placeholder="Subject" required>
          <textarea name="message" class="form-input" rows="5" placeholder="How can we help you?" required></textarea>
          <button type="submit" class="btn-primary">
            Send Message <i class="bi bi-send ms-2"></i>
          </button>
        </form>
      </div>
    </div>

    <footer class="site-footer">
      © 2026 AgriTrace Technologies | <a href="about.php" style="color:var(--primary-green); text-decoration:none;">About</a>
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