<?php
session_start();

// DB Connection
try {
    $pdo = new PDO("mysql:host=localhost;dbname=myproject4;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch(PDOException $e) {
    die("Database connection failed.");
}

$register_success = '';
$register_error   = '';

// ── OTP VERIFY STEP ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_otp'])) {
    header('Content-Type: application/json');
    $userId = (int)($_SESSION['pending_user_id'] ?? 0);
    $otp    = trim($_POST['otp'] ?? '');

    if (!$userId || !$otp) {
        echo json_encode(['success' => false, 'message' => 'Session expired. Please register again.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT otp_code, otp_expires, email FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user || $user['otp_code'] !== $otp) {
        echo json_encode(['success' => false, 'message' => 'Invalid OTP code. Please try again.']);
        exit;
    }

    if (new DateTime() > new DateTime($user['otp_expires'])) {
        echo json_encode(['success' => false, 'message' => 'OTP expired. Please request a new one.']);
        exit;
    }

    // Activate account
    $pdo->prepare("UPDATE users SET status='Pending', email_verified=1, otp_code=NULL, otp_expires=NULL WHERE id=?")
        ->execute([$userId]);

    unset($_SESSION['pending_user_id']);
    echo json_encode(['success' => true, 'message' => 'Email verified! Your account is pending admin approval.']);
    exit;
}

// ── RESEND OTP ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend_otp'])) {
    header('Content-Type: application/json');
    $userId = (int)($_SESSION['pending_user_id'] ?? 0);
    if (!$userId) { echo json_encode(['success' => false, 'message' => 'Session expired.']); exit; }

    $otp     = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));
    $pdo->prepare("UPDATE users SET otp_code=?, otp_expires=? WHERE id=?")->execute([$otp, $expires, $userId]);

    $stmt = $pdo->prepare("SELECT email, firstName FROM users WHERE id=?");
    $stmt->execute([$userId]);
    $u = $stmt->fetch();
    sendOtpEmail($u['email'], $u['firstName'], $otp);

    echo json_encode(['success' => true, 'message' => 'A new OTP has been sent to your email.']);
    exit;
}

// ── MAIN REGISTRATION ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['verify_otp'], $_POST['resend_otp'])) {
    $fname    = trim($_POST['fname']     ?? '');
    $lname    = trim($_POST['lname']     ?? '');
    $email    = trim($_POST['email']     ?? '');
    $mobile   = trim($_POST['mobile']    ?? '');
    $role     = $_POST['role']           ?? '';
    $password = $_POST['password']       ?? '';
    $cpass    = $_POST['cpassword']      ?? '';
    $errors   = [];

    if (!$fname || !$lname || !$email || !$mobile || !$role || !$password)
        $errors[] = 'All fields are required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        $errors[] = 'Invalid email format.';
    if ($password !== $cpass)
        $errors[] = 'Passwords do not match.';
    if (strlen($password) < 8)
        $errors[] = 'Password must be at least 8 characters.';
    if (!isset($_POST['terms']))
        $errors[] = 'You must agree to the Terms and Conditions.';
    if (!in_array($role, ['Farmer', 'Agriculture Official']))
        $errors[] = 'Invalid role selected.';

    if (empty($errors)) {
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$email]);
        if ($check->fetch()) $errors[] = 'Email already registered. Please log in.';
    }

    if (empty($errors)) {
        $hash    = password_hash($password, PASSWORD_DEFAULT);
        $otp     = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));

        try {
            $pdo->prepare("INSERT INTO users (firstName,lastName,email,password,mobile,role,status,otp_code,otp_expires)
                           VALUES (?,?,?,?,?,?,'Unverified',?,?)")
                ->execute([$fname, $lname, $email, $hash, $mobile, $role, $otp, $expires]);

            $userId = (int)$pdo->lastInsertId();
            $_SESSION['pending_user_id'] = $userId;

            // Create empty profile record
            if ($role === 'Farmer') {
                $pdo->prepare("INSERT IGNORE INTO farmer_profiles (user_id) VALUES (?)")->execute([$userId]);
            } else {
                $pdo->prepare("INSERT IGNORE INTO officer_profiles (user_id) VALUES (?)")->execute([$userId]);
            }

            // Send OTP email
            $sent = sendOtpEmail($email, $fname, $otp);
            $register_success = 'verify';

        } catch(PDOException $e) {
            $register_error = 'Registration failed. Please try again.';
            error_log("Register error: " . $e->getMessage());
        }
    } else {
        $register_error = implode(' ', $errors);
    }
}

// ── EMAIL HELPER ──────────────────────────────────────────────────────────────
function sendOtpEmail($to, $name, $otp) {
    $subject = "AgriTrace+ - Email Verification OTP";
    $body = "Hello $name,\n\nYour OTP verification code is:\n\n  $otp\n\nThis code expires in 10 minutes.\n\nIf you did not register, please ignore this email.\n\n-- AgriTrace+ Team";
    $headers = "From: noreply@agritrace.ph\r\nReply-To: support@agritrace.ph\r\nX-Mailer: PHP/" . phpversion();
    return @mail($to, $subject, $body, $headers);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register | AgriTrace+</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Syne:wght@800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
:root{--g:#10b981;--gd:#059669;--glass:rgba(255,255,255,.1);--gb:rgba(255,255,255,.2);}
*{margin:0;padding:0;box-sizing:border-box;font-family:'DM Sans',sans-serif;}
body{min-height:100vh;display:flex;justify-content:center;align-items:center;
  background:linear-gradient(rgba(0,0,0,.6),rgba(0,0,0,.8)),
  url('https://images.unsplash.com/photo-1500382017468-9049fed747ef?auto=format&fit=crop&q=80&w=2000') center/cover fixed;
  padding:40px 20px;}
.card{background:var(--glass);backdrop-filter:blur(25px);border:1px solid var(--gb);border-radius:32px;
  padding:40px 30px;width:100%;max-width:480px;text-align:center;box-shadow:0 40px 100px rgba(0,0,0,.6);position:relative;}
.close-btn{position:absolute;top:20px;right:20px;background:rgba(255,255,255,.15);border:none;color:white;
  width:32px;height:32px;border-radius:50%;cursor:pointer;font-size:1rem;display:flex;align-items:center;justify-content:center;}
.brand{font-family:'Syne',sans-serif;font-size:2.4rem;font-weight:800;color:white;letter-spacing:-1px;}
.brand span{color:var(--g);}
.badge{background:#3b82f6;color:white;padding:6px 16px;border-radius:100px;font-size:.7rem;font-weight:800;
  display:inline-flex;align-items:center;gap:6px;margin:.5rem 0;box-shadow:0 0 20px rgba(59,130,246,.4);}
.subtitle{color:white;font-weight:500;margin-bottom:25px;}
.alert{padding:12px;border-radius:12px;margin-bottom:20px;font-size:.9rem;}
.alert-success{background:rgba(16,185,129,.2);border:1px solid var(--g);color:#d1fae5;}
.alert-error{background:rgba(239,68,68,.2);border:1px solid #ef4444;color:#fecaca;}
.form-row{display:flex;gap:12px;margin-bottom:15px;}
.form-group{text-align:left;margin-bottom:15px;flex:1;}
.form-label{display:block;color:white;font-size:.85rem;font-weight:600;margin-bottom:6px;}
.input-wrap{position:relative;display:flex;align-items:center;}
.input-wrap>i:not(.ptog){position:absolute;left:15px;color:#64748b;font-size:1.1rem;}
.form-input,.form-select{width:100%;padding:13px 15px 13px 45px;border-radius:12px;border:none;
  background:#fff;font-size:.95rem;color:#1e293b;outline:none;font-family:inherit;}
.form-select{padding-left:45px;}
.ptog{position:absolute;right:15px;color:#64748b;cursor:pointer;}
.strength-meter{height:4px;background:rgba(255,255,255,.1);border-radius:2px;overflow:hidden;margin-top:8px;}
.strength-bar{height:100%;width:0%;transition:.4s;}
.strength-text{font-size:.7rem;font-weight:700;text-transform:uppercase;display:block;text-align:right;color:rgba(255,255,255,.5);margin-top:2px;}
.req-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:12px;}
.req-item{font-size:.72rem;color:rgba(255,255,255,.5);display:flex;align-items:center;gap:6px;transition:.3s;}
.req-item.valid{color:var(--g);font-weight:600;}
.btn-reg{width:100%;padding:16px;background:#065f46;color:white;border:none;border-radius:12px;
  font-weight:700;font-size:1rem;cursor:pointer;margin-top:25px;transition:.2s;}
.btn-reg:hover{background:#047857;transform:translateY(-1px);}
.btn-reg:disabled{opacity:.6;cursor:not-allowed;}
.footer-link{margin-top:20px;font-size:.85rem;color:white;}
.footer-link a{color:white;font-weight:700;text-decoration:none;}

/* OTP Modal */
.otp-modal{position:fixed;inset:0;background:rgba(0,0,0,.7);backdrop-filter:blur(10px);z-index:9999;
  display:none;align-items:center;justify-content:center;padding:20px;}
.otp-modal.show{display:flex;}
.otp-box{background:rgba(255,255,255,.12);backdrop-filter:blur(30px);border:1px solid rgba(255,255,255,.25);
  border-radius:28px;padding:40px;max-width:420px;width:100%;text-align:center;box-shadow:0 30px 80px rgba(0,0,0,.5);}
.otp-icon{width:70px;height:70px;background:rgba(16,185,129,.2);border-radius:50%;display:flex;align-items:center;
  justify-content:center;margin:0 auto 20px;font-size:2rem;color:var(--g);}
.otp-box h2{color:white;font-family:'Syne',sans-serif;margin-bottom:10px;}
.otp-box p{color:rgba(255,255,255,.7);font-size:.9rem;margin-bottom:25px;line-height:1.6;}
.otp-inputs{display:flex;gap:10px;justify-content:center;margin-bottom:20px;}
.otp-input{width:50px;height:60px;border-radius:12px;border:2px solid rgba(255,255,255,.3);
  background:rgba(255,255,255,.1);color:white;font-size:1.5rem;font-weight:700;text-align:center;
  outline:none;transition:.3s;}
.otp-input:focus{border-color:var(--g);background:rgba(16,185,129,.1);}
.btn-verify{width:100%;padding:14px;background:var(--g);color:white;border:none;border-radius:12px;
  font-weight:700;font-size:1rem;cursor:pointer;transition:.2s;margin-bottom:12px;}
.btn-verify:hover{background:var(--gd);}
.resend-link{color:rgba(255,255,255,.6);font-size:.85rem;cursor:pointer;background:none;border:none;
  text-decoration:underline;}
.resend-link:hover{color:var(--g);}
.otp-timer{font-size:.8rem;color:rgba(255,255,255,.5);margin-top:8px;}

@media(max-width:480px){.form-row{flex-direction:column;gap:0;}.req-grid{grid-template-columns:1fr;}
  .card{padding:35px 20px;border-radius:24px;}.otp-input{width:42px;height:54px;font-size:1.3rem;}}
</style>
</head>
<body>
<div class="card">
  <button class="close-btn" onclick="window.history.back()"><i class="bi bi-x-lg"></i></button>
  <div class="brand">Agri<span>Trace+</span></div>
  <div class="badge"><i class="bi bi-geo-alt-fill"></i> GEO-TAGGING ENABLED</div>
  <p class="subtitle">Create Your Account</p>

  <?php if ($register_error): ?>
    <div class="alert alert-error"><i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($register_error) ?></div>
  <?php endif; ?>

  <?php if ($register_success === 'verify'): ?>
  <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> Account created! Please check your email for the OTP.</div>
  <?php endif; ?>

  <?php if ($register_success !== 'verify'): ?>
  <form action="register-form.php" method="POST" id="regForm">
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">First Name</label>
        <div class="input-wrap">
          <i class="bi bi-person"></i>
          <input type="text" name="fname" class="form-input" placeholder="Juan" value="<?= htmlspecialchars($_POST['fname'] ?? '') ?>" required>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Last Name</label>
        <div class="input-wrap">
          <i class="bi bi-person"></i>
          <input type="text" name="lname" class="form-input" placeholder="dela Cruz" value="<?= htmlspecialchars($_POST['lname'] ?? '') ?>" required>
        </div>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label">Email Address</label>
      <div class="input-wrap">
        <i class="bi bi-envelope"></i>
        <input type="email" name="email" class="form-input" placeholder="juan@example.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label">Mobile Number</label>
      <div class="input-wrap">
        <i class="bi bi-phone"></i>
        <input type="tel" name="mobile" class="form-input" placeholder="+63 9XX XXX XXXX" value="<?= htmlspecialchars($_POST['mobile'] ?? '') ?>" required>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label">Role</label>
      <div class="input-wrap">
        <i class="bi bi-briefcase"></i>
        <select name="role" class="form-select form-input" required>
          <option value="">-- Select Role --</option>
          <option value="Farmer" <?= ($_POST['role'] ?? '') === 'Farmer' ? 'selected' : '' ?>>Farmer</option>
          <option value="Agriculture Official" <?= ($_POST['role'] ?? '') === 'Agriculture Official' ? 'selected' : '' ?>>Agriculture Official</option>
        </select>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label">Password</label>
      <div class="input-wrap">
        <i class="bi bi-lock"></i>
        <input type="password" id="pass" name="password" class="form-input" placeholder="Create a strong password" required minlength="8">
        <i class="bi bi-eye ptog" id="togIcon"></i>
      </div>
      <div class="strength-meter"><div class="strength-bar" id="sBar"></div></div>
      <span class="strength-text" id="sText">Very Weak</span>
      <div class="req-grid">
        <div class="req-item" id="req-length"><i class="bi bi-circle"></i> At least 8 chars</div>
        <div class="req-item" id="req-number"><i class="bi bi-circle"></i> Contains a number</div>
        <div class="req-item" id="req-special"><i class="bi bi-circle"></i> Special character</div>
        <div class="req-item" id="req-lower"><i class="bi bi-circle"></i> Lowercase letter</div>
        <div class="req-item" id="req-upper"><i class="bi bi-circle"></i> Uppercase letter</div>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label">Confirm Password</label>
      <div class="input-wrap">
        <i class="bi bi-shield-lock"></i>
        <input type="password" name="cpassword" id="cpass" class="form-input" placeholder="Repeat password" required>
      </div>
    </div>

    <label style="color:white;font-size:.8rem;display:flex;align-items:center;gap:8px;cursor:pointer;text-align:left;">
      <input type="checkbox" name="terms" required>
      I agree to the <a href="#" style="color:var(--g);text-decoration:none;">Terms and Conditions</a>
    </label>

    <button type="submit" class="btn-reg" id="subBtn">REGISTER ACCOUNT</button>
  </form>
  <div class="footer-link">Already have an account? <a href="login.php">Log In</a></div>
  <?php endif; ?>

  <div style="margin-top:30px;font-size:.7rem;color:rgba(255,255,255,.4);">© 2026 AgriTrace Technologies</div>
</div>

<!-- OTP Verification Modal -->
<div class="otp-modal <?= $register_success === 'verify' ? 'show' : '' ?>" id="otpModal">
  <div class="otp-box">
    <div class="otp-icon"><i class="bi bi-envelope-check-fill"></i></div>
    <h2>Verify Your Email</h2>
    <p>We've sent a 6-digit OTP to your email address. Enter it below to activate your account.</p>

    <div class="otp-inputs">
      <input type="text" class="otp-input" maxlength="1" inputmode="numeric">
      <input type="text" class="otp-input" maxlength="1" inputmode="numeric">
      <input type="text" class="otp-input" maxlength="1" inputmode="numeric">
      <input type="text" class="otp-input" maxlength="1" inputmode="numeric">
      <input type="text" class="otp-input" maxlength="1" inputmode="numeric">
      <input type="text" class="otp-input" maxlength="1" inputmode="numeric">
    </div>

    <div id="otpMsg" style="color:#fca5a5;font-size:.85rem;min-height:20px;margin-bottom:10px;"></div>

    <button class="btn-verify" id="verifyBtn" onclick="verifyOtp()">Verify OTP</button>

    <div>
      <button class="resend-link" id="resendBtn" onclick="resendOtp()">Resend OTP</button>
      <div class="otp-timer" id="otpTimer"></div>
    </div>
  </div>
</div>

<script>
// ── Password Strength ─────────────────────────────────────────────────────────
const reqs = {
  'req-length':  v => v.length >= 8,
  'req-number':  v => /[0-9]/.test(v),
  'req-special': v => /[!@#$%^&*(),.?":{}|<>]/.test(v),
  'req-lower':   v => /[a-z]/.test(v),
  'req-upper':   v => /[A-Z]/.test(v)
};
document.getElementById('pass').addEventListener('input', function() {
  const v = this.value;
  let score = 0;
  for (const [id, fn] of Object.entries(reqs)) {
    const el = document.getElementById(id), ic = el.querySelector('i'), ok = fn(v);
    el.classList.toggle('valid', ok);
    ic.className = ok ? 'bi bi-check-circle-fill' : 'bi bi-circle';
    if (ok) score++;
  }
  const bar = document.getElementById('sBar'), txt = document.getElementById('sText');
  const levels = [['0%','transparent','Very Weak'],['25%','#ef4444','Weak'],['50%','#f59e0b','Fair'],['75%','#3b82f6','Good'],['100%','#10b981','Strong']];
  const l = v.length ? levels[score] : levels[0];
  bar.style.width = l[0]; bar.style.backgroundColor = l[1]; txt.textContent = l[2];
});
document.getElementById('togIcon').addEventListener('click', () => {
  const p = document.getElementById('pass'), ic = document.getElementById('togIcon');
  p.type = p.type === 'password' ? 'text' : 'password';
  ic.classList.toggle('bi-eye'); ic.classList.toggle('bi-eye-slash');
});

// ── Form Submit ───────────────────────────────────────────────────────────────
const regForm = document.getElementById('regForm');
if (regForm) regForm.addEventListener('submit', e => {
  const pass = document.getElementById('pass').value;
  const cpass = document.getElementById('cpass').value;
  if (pass !== cpass) { e.preventDefault(); alert('Passwords do not match!'); return; }
  document.getElementById('subBtn').disabled = true;
  document.getElementById('subBtn').textContent = 'Creating Account...';
});

// ── OTP Inputs Auto-Tab ───────────────────────────────────────────────────────
const otpInputs = document.querySelectorAll('.otp-input');
otpInputs.forEach((inp, i) => {
  inp.addEventListener('input', () => {
    if (inp.value && i < otpInputs.length - 1) otpInputs[i+1].focus();
  });
  inp.addEventListener('keydown', e => {
    if (e.key === 'Backspace' && !inp.value && i > 0) otpInputs[i-1].focus();
  });
  inp.addEventListener('paste', e => {
    const paste = e.clipboardData.getData('text').trim().replace(/\D/g,'');
    if (paste.length === 6) {
      otpInputs.forEach((o, idx) => { o.value = paste[idx] || ''; });
      e.preventDefault();
    }
  });
});

// ── Verify OTP ────────────────────────────────────────────────────────────────
async function verifyOtp() {
  const otp = Array.from(otpInputs).map(i => i.value).join('');
  if (otp.length < 6) { showOtpMsg('Please enter all 6 digits.', true); return; }
  const btn = document.getElementById('verifyBtn');
  btn.disabled = true; btn.textContent = 'Verifying...';

  const fd = new FormData();
  fd.append('verify_otp', '1'); fd.append('otp', otp);
  const res = await fetch('register-form.php', { method: 'POST', body: fd });
  const data = await res.json();

  if (data.success) {
    showOtpMsg(data.message, false);
    setTimeout(() => window.location.href = 'login.php', 2000);
  } else {
    showOtpMsg(data.message, true);
    btn.disabled = false; btn.textContent = 'Verify OTP';
    otpInputs.forEach(i => { i.value = ''; }); otpInputs[0].focus();
  }
}

// ── Resend OTP ────────────────────────────────────────────────────────────────
let resendTimer = null;
async function resendOtp() {
  const btn = document.getElementById('resendBtn');
  btn.disabled = true;
  const fd = new FormData(); fd.append('resend_otp', '1');
  const res = await fetch('register-form.php', { method: 'POST', body: fd });
  const data = await res.json();
  showOtpMsg(data.message, !data.success);
  if (data.success) startResendTimer(60);
  else btn.disabled = false;
}

function startResendTimer(secs) {
  const timerEl = document.getElementById('otpTimer');
  clearInterval(resendTimer);
  resendTimer = setInterval(() => {
    timerEl.textContent = `Resend in ${secs}s`;
    if (--secs < 0) {
      clearInterval(resendTimer);
      timerEl.textContent = '';
      document.getElementById('resendBtn').disabled = false;
    }
  }, 1000);
}

function showOtpMsg(msg, isErr) {
  const el = document.getElementById('otpMsg');
  el.textContent = msg;
  el.style.color = isErr ? '#fca5a5' : '#6ee7b7';
}

// Auto-start timer if OTP modal is open on load
<?php if ($register_success === 'verify'): ?>
startResendTimer(60);
document.getElementById('resendBtn').disabled = true;
<?php endif; ?>
</script>
</body>
</html>