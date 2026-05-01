<?php
define('JSON_RESPONSE', false);
require_once __DIR__ . '/config/db.php';  // Includes ALL functions + session + PDO

$error = '';
$success = '';

// ── Already logged in → redirect to correct panel ────────────────────────────
if (isLoggedIn()) {
    $role = currentUserRole();
    if ($role === 'Admin')       { header('Location: admin.php');  exit; }
    if ($role === 'DA_Officer')  { header('Location: da-panel.php'); exit; }
    if ($role === 'Farmer')      { header('Location: farmer.php');  exit; }
}

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');
    $password   = trim($_POST['password']   ?? '');

    if (empty($identifier) || empty($password)) {
        $error = 'Please enter your email/mobile and password.';
    } else {
        try {
            $pdo = getPDO();

            // Find user by email or mobile
            $stmt = $pdo->prepare("
                SELECT id, firstName, lastName, email, mobile, password, role, status,
                       email_verified, mobile_verified, login_attempts, locked_until
                FROM users
                WHERE (email = ? OR mobile = ?) AND status != 'Inactive'
                LIMIT 1
            ");
            $stmt->execute([$identifier, $identifier]);
            $user = $stmt->fetch();

            if (!$user) {
                $error = 'No account found with that email or mobile number.';
                auditLog('LOGIN_FAILED', 'users', 0, ['identifier' => $identifier, 'reason' => 'user_not_found']);
            } elseif ($user['status'] === 'Suspended') {
                $error = 'Your account has been suspended. Contact support@agritrace.ph.';
            } elseif ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
                $unlock = date('h:i A', strtotime($user['locked_until']));
                $error = "Account temporarily locked. Try again after $unlock.";
            } elseif (!password_verify($password, $user['password'])) {
                $attempts = ($user['login_attempts'] ?? 0) + 1;
                $maxAttempts = (int)getConfig('max_login_attempts', '5');
                $lockUntil = null;
                
                if ($attempts >= $maxAttempts) {
                    $lockUntil = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                    $error = "Too many failed attempts. Account locked for 15 minutes.";
                } else {
                    $remaining = $maxAttempts - $attempts;
                    $error = "Incorrect password. $remaining attempt(s) remaining.";
                }
                
                $pdo->prepare("UPDATE users SET login_attempts=?, locked_until=? WHERE id=?")
                    ->execute([$attempts, $lockUntil, $user['id']]);
                auditLog('LOGIN_FAILED', 'users', $user['id'], ['reason' => 'wrong_password', 'attempts' => $attempts]);
            } elseif ($user['status'] === 'Pending') {
                $error = 'Your account is pending approval. Check your email for verification.';
            } else {
                // ── SUCCESSFUL LOGIN ────────────────────────────────
                $pdo->prepare("UPDATE users SET login_attempts=0, locked_until=NULL, last_login=NOW() WHERE id=?")
                    ->execute([$user['id']]);

                session_regenerate_id(true);
                $_SESSION['logged_in']  = true;
                $_SESSION['user_id']    = $user['id'];
                $_SESSION['user_role']  = $user['role'];
                $_SESSION['firstName']  = $user['firstName'];
                $_SESSION['lastName']   = $user['lastName'];
                $_SESSION['email']      = $user['email'];
                $_SESSION['mobile']     = $user['mobile'];

                auditLog('LOGIN_SUCCESS', 'users', $user['id'], ['role' => $user['role']]);

                // Role-based redirect
                if ($user['role'] === 'Admin') {
                    header('Location: admin.php');
                } elseif ($user['role'] === 'DA_Officer') {
                    header('Location: da-panel.php');
                } elseif ($user['role'] === 'Farmer') {
                    header('Location: farmer.php');
                } else {
                    header('Location: index.php');
                }
                exit;
            }
        } catch (Exception $e) {
            $error = 'Login failed. Please try again.';
            error_log('Login error: ' . $e->getMessage());
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
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Syne:wght@700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'DM Sans',sans-serif;background:#062c23;color:#fff;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;}
.hero-bg{position:fixed;inset:0;background:linear-gradient(rgba(6,44,35,.88),rgba(6,44,35,.95)),url('https://images.unsplash.com/photo-1500382017468-9049fed747ef?q=80&w=2000')center/cover;z-index:-1;}
.glass{background:rgba(255,255,255,.07);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,.12);border-radius:20px;padding:2.5rem;width:100%;max-width:420px;box-shadow:0 25px 60px rgba(0,0,0,.4);}
.brand{font-family:'Syne',sans-serif;font-size:1.75rem;font-weight:800;text-align:center;margin-bottom:.25rem;}
.brand span{color:#10b981;}
.sub{text-align:center;color:rgba(255,255,255,.6);font-size:.9rem;margin-bottom:2rem;}
.form-group{margin-bottom:1.1rem;}
.form-label{display:block;font-size:.82rem;font-weight:600;margin-bottom:.4rem;color:rgba(255,255,255,.75);letter-spacing:.3px;text-transform:uppercase;}
.form-input{width:100%;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);border-radius:.625rem;padding:.875rem 1rem;color:#fff;font-size:.95rem;font-family:inherit;transition:.2s;outline:none;}
.form-input:focus{border-color:#10b981;background:rgba(255,255,255,.15);box-shadow:0 0 0 3px rgba(16,185,129,.2);}
.form-input::placeholder{color:rgba(255,255,255,.4);}
.input-wrap{position:relative;}
.toggle-pw{position:absolute;right:14px;top:50%;transform:translateY(-50%);background:none;border:none;color:rgba(255,255,255,.5);cursor:pointer;font-size:1rem;padding:2px;}
.btn-submit{width:100%;background:#10b981;color:#fff;border:none;padding:1rem;border-radius:.75rem;font-weight:700;font-size:1rem;cursor:pointer;transition:.2s;font-family:inherit;margin-top:.5rem;display:flex;align-items:center;justify-content:center;gap:.5rem;}
.btn-submit:hover{background:#059669;transform:translateY(-1px);}
.btn-submit:disabled{background:#374151;cursor:not-allowed;transform:none;}
.error-bar{background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.4);border-radius:.625rem;padding:.875rem 1rem;margin-bottom:1.25rem;color:#fca5a5;font-size:.9rem;display:flex;align-items:center;gap:.625rem;}
.success-bar{background:rgba(16,185,129,.15);border:1px solid rgba(16,185,129,.4);border-radius:.625rem;padding:.875rem 1rem;margin-bottom:1.25rem;color:#6ee7b7;font-size:.9rem;display:flex;align-items:center;gap:.625rem;}
.links-row{display:flex;justify-content:space-between;margin-top:1.25rem;font-size:.82rem;}
.links-row a{color:rgba(255,255,255,.6);text-decoration:none;transition:.2s;}
.links-row a:hover{color:#10b981;}
.divider{text-align:center;color:rgba(255,255,255,.3);font-size:.8rem;margin:.75rem 0;}
.spinner{display:none;width:16px;height:16px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:spin .8s linear infinite;}
.spinner.show{display:inline-block;}
@keyframes spin{to{transform:rotate(360deg);}}
</style>
</head>
<body>
<div class="hero-bg"></div>
<div class="glass">
    <div class="brand">Agri<span>Trace+</span></div>
    <p class="sub">Camarines Sur Agricultural Traceability System</p>

    <?php if ($error): ?>
    <div class="error-bar"><i class="bi bi-exclamation-triangle-fill"></i><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="success-bar"><i class="bi bi-check-circle-fill"></i><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form method="POST" id="loginForm">
        <div class="form-group">
            <label class="form-label">Email or Mobile Number</label>
            <input class="form-input" type="text" name="identifier"
                   placeholder="your@email.com or 09XXXXXXXXX"
                   value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>"
                   autocomplete="username" required>
        </div>
        <div class="form-group">
            <label class="form-label">Password</label>
            <div class="input-wrap">
                <input class="form-input" type="password" name="password" id="pwField"
                       placeholder="Enter your password" autocomplete="current-password" required>
                <button type="button" class="toggle-pw" onclick="togglePw()"><i class="bi bi-eye" id="pwIcon"></i></button>
            </div>
        </div>
        <button type="submit" class="btn-submit" id="loginBtn">
            <span class="spinner" id="loginSpinner"></span>
            <i class="bi bi-box-arrow-in-right"></i> Sign In
        </button>
    </form>

    <div class="links-row">
        <a href="forgot-password.php"><i class="bi bi-key"></i> Forgot Password?</a>
        <a href="register.php"><i class="bi bi-person-plus"></i> Create Account</a>
    </div>
    <div class="divider">— or —</div>
    <div style="text-align:center;"><a href="index.php" style="color:rgba(255,255,255,.5);font-size:.82rem;text-decoration:none;"><i class="bi bi-house"></i> Back to Home</a></div>
</div>

<script>
function togglePw(){
    const f=document.getElementById('pwField');
    const i=document.getElementById('pwIcon');
    if(f.type==='password'){f.type='text';i.className='bi bi-eye-slash';}
    else{f.type='password';i.className='bi bi-eye';}
}

document.getElementById('loginForm').addEventListener('submit',function(){
    const btn=document.getElementById('loginBtn');
    const sp=document.getElementById('loginSpinner');
    btn.disabled=true; 
    sp.classList.add('show');
});
</script>
</body>
</html>