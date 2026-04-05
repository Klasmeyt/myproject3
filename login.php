<?php 
session_start(); 

// Connect to correct database myproject4
try {
    $pdo = new PDO("mysql:host=localhost;dbname=myproject4;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch(PDOException $e) {
    $db_error = "Database connection failed: " . $e->getMessage();
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
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['user_name'] = $user['firstName'] . ' ' . $user['lastName'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['logged_in'] = true;
                
                $login_success = true;
                
                $role = $user['role'];
                $redirect_page = match($role) {
                    'Admin' => 'admin.php',
                    'Farmer' => 'farmer.php',
                    'Agriculture Official' => 'agriDA.php',
                    default => 'index.php'
                };
                header("Location: $redirect_page");
                exit;
                
            } else {
                $login_error = 'Invalid email or password';
            }
        } catch (PDOException $e) {
            $login_error = 'Database query failed';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | AgriTrace+</title>
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

        body { 
            background-color: var(--bg-dark); 
            color: var(--text-main); 
            min-height: 100vh; 
            display: flex; 
            align-items: center; 
            justify-content: center;
            position: relative;
        }

        .hero-bg {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: linear-gradient(rgba(4, 26, 20, 0.85), rgba(4, 26, 20, 0.95)), 
                        url('https://images.unsplash.com/photo-1500382017468-9049fed747ef?auto=format&fit=crop&q=80&w=2000');
            background-size: cover; background-position: center; z-index: -1;
        }

        .login-card {
            background: var(--glass);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 24px;
            padding: 40px;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.6s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .login-header { text-align: center; margin-bottom: 32px; }
        .login-header h1 { 
            font-family: 'Syne', sans-serif; 
            font-size: 2rem; 
            font-weight: 800; 
            margin-bottom: 8px;
        }
        .login-header span { color: var(--primary); }
        .login-header p { color: var(--text-muted); font-size: 0.95rem; }

        .form-group { margin-bottom: 20px; position: relative; }
        
        .input-icon {
            position: absolute; left: 16px; top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 1.1rem;
        }

        .form-input {
            width: 100%;
            padding: 14px 16px 14px 48px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            color: white;
            font-size: 1rem;
            transition: 0.3s;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary);
            background: rgba(255, 255, 255, 0.07);
        }

        .btn-login {
            width: 100%;
            padding: 14px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            transition: 0.3s;
            margin-top: 10px;
        }

        .btn-login:hover { background: var(--primary-dark); transform: translateY(-2px); }

        .error-msg {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .footer-links {
            display: flex;
            justify-content: space-between;
            margin-top: 25px;
            font-size: 0.9rem;
        }

        .footer-links a { color: var(--text-muted); text-decoration: none; transition: 0.3s; }
        .footer-links a:hover { color: var(--primary); }

        .divider {
            height: 1px;
            background: var(--glass-border);
            margin: 25px 0;
            position: relative;
        }

        .btn-back {
            display: block;
            text-align: center;
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="hero-bg"></div>

    <div class="login-card">
        <div class="login-header">
            <h1>Agri<span>Trace+</span></h1>
            <p>Sign in to your digital dashboard</p>
        </div>

        <?php if ($login_error): ?>
            <div class="error-msg">
                <i class="bi bi-exclamation-circle"></i>
                <?= htmlspecialchars($login_error) ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <i class="bi bi-envelope input-icon"></i>
                <input type="email" name="email" class="form-input" placeholder="Email Address" required autofocus>
            </div>

            <div class="form-group">
                <i class="bi bi-shield-lock input-icon"></i>
                <input type="password" name="password" class="form-input" placeholder="Password" required>
            </div>

            <button type="submit" class="btn-login">Sign In</button>
        </form>

        <div class="footer-links">
            <a href="register-form.php">Create Account</a>
            <a href="forgot.php">Forgot Password?</a>
        </div>

        <div class="divider"></div>

        <a href="index.php" class="btn-back">
            <i class="bi bi-arrow-left"></i> Back to Home
        </a>
    </div>
</body>
</html>