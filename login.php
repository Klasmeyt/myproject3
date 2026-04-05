<?php 
session_start(); 

// FIXED: Connect to correct database myproject4
try {
    $pdo = new PDO("mysql:host=localhost;dbname=myproject4;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch(PDOException $e) {
    $db_error = "Database connection failed: " . $e->getMessage();
    error_log("DB Error: " . $e->getMessage());
}

$login_error = '';
$login_success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    
    if (isset($pdo)) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND status = 'Active'");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password'])) {
                // FIXED: Set all required session variables
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['user_name'] = $user['firstName'] . ' ' . $user['lastName'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['logged_in'] = true;
                
                $login_success = true;
                error_log("LOGIN SUCCESS: {$user['email']} ({$user['role']}) - ID: {$user['id']}");
                
                // IMMEDIATE REDIRECT - No overlay needed
                $role = $user['role'];
                $redirect_page = match($role) {
                    'Admin' => 'admin.php',
                    'Farmer' => 'farmer.php',
                    'Agriculture Official' => 'agri.php',
                    default => 'index.php'
                };
                header("Location: $redirect_page");
                exit;
                
            } else {
                $login_error = 'Invalid email or password';
                error_log("LOGIN FAILED: $email - Password mismatch or inactive account");
            }
        } catch (PDOException $e) {
            $login_error = 'Database query failed';
            error_log("Login Query Error: " . $e->getMessage());
        }
    } else {
        $login_error = 'Database connection failed';
    }
}

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
       <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | AgriTrace+</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Syne:wght@700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DM Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            height: 100vh;
            background: linear-gradient(135deg, #0f5132 0%, #10b981 50%, #059669 100%);
            background-image: 
                radial-gradient(circle at 20% 80%, rgba(120, 119, 198, 0.3) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(255, 255, 255, 0.1) 0%, transparent 50%),
                linear-gradient(135deg, #0f5132 0%, #10b981 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
        }

        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="rgba(255,255,255,0.03)"/><circle cx="75" cy="75" r="0.5" fill="rgba(255,255,255,0.02)"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            pointer-events: none;
            z-index: 1;
        }

        .login-container {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 24px;
            padding: 48px 40px;
            width: 100%;
            max-width: 400px;
            box-shadow: 
                0 25px 45px rgba(0, 0, 0, 0.2),
                0 0 0 1px rgba(255, 255, 255, 0.1);
            position: relative;
            z-index: 10;
            animation: floatIn 0.8s ease-out;
        }

        @keyframes floatIn {
            0% {
                opacity: 0;
                transform: translateY(40px) scale(0.95);
            }
            100% {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .close-btn {
            position: absolute;
            top: 20px;
            right: 20px;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            transition: all 0.3s ease;
        }

        .close-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: rotate(90deg) scale(1.1);
        }

        .logo {
            text-align: center;
            margin-bottom: 24px;
        }

        .logo h1 {
            font-family: 'Syne', sans-serif;
            font-size: clamp(2rem, 5vw, 2.8rem);
            font-weight: 800;
            background: linear-gradient(135deg, white 0%, #e0f2fe 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0;
            letter-spacing: -0.02em;
        }

        .logo-tagline {
            color: rgba(255, 255, 255, 0.8);
            font-size: 1rem;
            margin-top: 8px;
            font-weight: 400;
        }

        .form-group {
            position: relative;
            margin-bottom: 24px;
        }

        .input-wrapper {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(255, 255, 255, 0.6);
            font-size: 1.2rem;
            z-index: 2;
            transition: all 0.3s ease;
        }

        .form-input {
            width: 100%;
            padding: 16px 20px 16px 50px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.1);
            color: white;
            font-size: 1rem;
            outline: none;
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
            font-family: inherit;
        }

        .form-input::placeholder {
            color: rgba(255, 255, 255, 0.4);
        }

        .form-input:focus {
            border-color: #10b981;
            background: rgba(255, 255, 255, 0.15);
            box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.2);
        }

        .form-input:focus + .input-icon {
            color: #10b981;
        }

        .password-toggle {
            position: absolute;
            right: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(255, 255, 255, 0.6);
            cursor: pointer;
            font-size: 1.1rem;
            z-index: 2;
            transition: color 0.3s ease;
        }

        .password-toggle:hover {
            color: white;
        }

                .login-btn {
            width: 100%;
            padding: 18px 32px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border: none;
            border-radius: 16px;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            margin-top: 12px;
            box-shadow: 0 12px 32px rgba(16, 185, 129, 0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            text-decoration: none;
            font-family: inherit;
        }

        .login-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 20px 40px rgba(16, 185, 129, 0.5);
        }

        .login-btn:active {
            transform: translateY(-1px);
        }

        .login-btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }

        .btn-loading {
            position: relative;
        }

        .btn-loading::after {
            content: '';
            position: absolute;
            width: 20px;
            height: 20px;
            top: 50%;
            left: 50%;
            margin-left: -10px;
            margin-top: -10px;
            border: 2px solid transparent;
            border-top-color: white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .error-message {
            background: rgba(239, 68, 68, 0.2);
            border: 1px solid rgba(239, 68, 68, 0.4);
            color: #fecaca;
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9rem;
            animation: shake 0.5s ease-in-out;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }

        .links-container {
            display: flex;
            justify-content: space-between;
            gap: 24px;
            margin: 28px 0;
        }

        .link-item {
            flex: 1;
            text-align: center;
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            padding: 10px 0;
            transition: color 0.3s ease;
            border-radius: 8px;
        }

        .link-item:hover {
            color: white;
            background: rgba(255, 255, 255, 0.1);
        }

        .divider {
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            margin: 28px 0;
        }

        .public-btn {
            width: 100%;
            padding: 16px 24px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            border-radius: 16px;
            font-weight: 600;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            text-decoration: none;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .public-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            border-color: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }

        .footer {
            margin-top: 32px;
            text-align: center;
            color: rgba(255, 255, 255, 0.5);
            font-size: 0.8rem;
        }

        /* Responsive */
        @media (max-width: 480px) {
            .login-container {
                margin: 20px;
                padding: 36px 24px;
                border-radius: 20px;
            }
            
            .logo h1 {
                font-size: 2.2rem;
            }
            
            .links-container {
                flex-direction: column;
                gap: 16px;
            }
        }

        /* Success animation */
        .success-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(16, 185, 129, 0.95);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.5s ease;
        }

        .success-overlay.show {
            opacity: 1;
            visibility: visible;
        }

        .success-content {
            text-align: center;
            color: white;
            animation: bounceIn 0.6s ease;
        }

        @keyframes bounceIn {
            0% { transform: scale(0.3); opacity: 0; }
            50% { transform: scale(1.05); }
            70% { transform: scale(0.9); }
            100% { transform: scale(1); opacity: 1; }
        }
    </style>
</head>
<body>
    <?php if ($login_success): ?>
    <div class="success-overlay" id="successOverlay">
        <div class="success-content">
            <div style="font-size: 4rem; margin-bottom: 20px;">
                <i class="bi bi-check-circle-fill"></i>
            </div>
            <h2>Welcome Back!</h2>
            <p>Redirecting to your dashboard...</p>
        </div>
    </div>
    <?php endif; ?>

    <div class="login-container">
        <a href="index.php" class="close-btn" title="Back to Home">
            <i class="bi bi-x-lg"></i>
        </a>

        <div class="logo">
            <h1>Agri<span style="color: #10b981;">Trace</span>+</h1>
            <div class="logo-tagline">Digital Livestock System</div>
        </div>

        <?php if ($login_error): ?>
        <div class="error-message">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <?= htmlspecialchars($login_error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" id="loginForm">
            <div class="form-group">
                <div class="input-wrapper">
                    <i class="bi bi-person-circle input-icon"></i>
                    <input type="email" 
                           name="email" 
                           class="form-input" 
                           placeholder="Email address"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                           required 
                           autocomplete="email"
                           autofocus>
                </div>
            </div>

            <div class="form-group">
                <div class="input-wrapper">
                    <i class="bi bi-lock-fill input-icon"></i>
                    <input type="password" 
                           name="password" 
                           id="passwordField"
                           class="form-input" 
                           placeholder="Password"
                           required 
                           autocomplete="current-password">
                    <i class="bi bi-eye password-toggle" id="toggleIcon"></i>
                </div>
            </div>

            <button type="submit" class="login-btn" id="loginButton">
                Sign In
                <i class="bi bi-arrow-right"></i>
            </button>
        </form>

        <div class="links-container">
            <a href="register-form.php" class="link-item">Create Account</a>
            <a href="forgot.php" class="link-item">Forgot Password?</a>
        </div>

        <div class="divider"></div>

        <a href="public-report-form.php" class="public-btn">
            <i class="bi bi-globe"></i>
            Public Reporting (No Login)
        </a>

        <div class="footer">
            © 2026 AgriTrace Technologies
        </div>
    </div>

    <script>
        // Password visibility toggle
        const toggleIcon = document.getElementById('toggleIcon');
        const passwordField = document.getElementById('passwordField');
        
        toggleIcon.addEventListener('click', function() {
            const type = passwordField.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordField.setAttribute('type', type);
            this.classList.toggle('bi-eye');
            this.classList.toggle('bi-eye-slash');
        });

        // Form submission with loading
        document.getElementById('loginForm').addEventListener('submit', function() {
            const button = document.getElementById('loginButton');
            button.classList.add('btn-loading');
            button.disabled = true;
            button.innerHTML = '<span>Signing In...</span>';
        });

        // Success overlay redirect
        <?php if ($login_success): ?>
document.getElementById('successOverlay').classList.add('show');
setTimeout(() => {
    <?php if (isset($_SESSION['user_role'])): ?>
        <?php 
        $role = $_SESSION['user_role'] ?? 'index';
        $redirect_page = match($role) {
            'Admin' => "'admin.php'",
            'Farmer' => "'farmer.php'", 
            'Agriculture Official' => "'agri.php'",
            default => "'index.php'"
        };
        ?>
        window.location.href = <?= $redirect_page ?>;
    <?php else: ?>
        window.location.href = 'index.php';
    <?php endif; ?>
}, 2000);
<?php endif; ?>

        // Debug console
        console.log('✅ Login page loaded perfectly!');
        console.log('📧 Test users: admin@agritrace.ph / farmer@agritrace.ph');
        console.log('🔑 Password: password');
    </script>
</body>
</html>