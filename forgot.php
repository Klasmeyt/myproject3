<?php
session_start();

try {
    $pdo = new PDO("mysql:host=localhost;dbname=myproject4;charset=utf8mb4","root","",[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
    ]);
} catch(PDOException $e) { die("DB error"); }

// ── STEP 2: Verify reset OTP & set new password ───────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['reset_password'])) {
    header('Content-Type: application/json');
    $userId = (int)($_SESSION['reset_user_id'] ?? 0);
    $otp    = trim($_POST['otp'] ?? '');
    $newPw  = $_POST['new_password'] ?? '';
    if (!$userId || !$otp || !$newPw) { echo json_encode(['success'=>false,'message'=>'Missing data']); exit; }

    $stmt = $pdo->prepare("SELECT otp_code, otp_expires FROM users WHERE id=?");
    $stmt->execute([$userId]); $u = $stmt->fetch();
    if (!$u || $u['otp_code'] !== $otp) { echo json_encode(['success'=>false,'message'=>'Invalid OTP']); exit; }
    if (new DateTime() > new DateTime($u['otp_expires'])) { echo json_encode(['success'=>false,'message'=>'OTP expired. Please start over.']); exit; }

    $pdo->prepare("UPDATE users SET password=?, otp_code=NULL, otp_expires=NULL WHERE id=?")
        ->execute([password_hash($newPw, PASSWORD_DEFAULT), $userId]);
    unset($_SESSION['reset_user_id']);
    echo json_encode(['success'=>true,'message'=>'Password reset successfully!']);
    exit;
}

// ── STEP 1: Send reset OTP ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['send_reset'])) {
    header('Content-Type: application/json');
    $identifier = trim($_POST['identifier'] ?? '');
    $type       = strpos($identifier,'@') !== false ? 'email' : 'phone';

    if ($type === 'email') {
        $stmt = $pdo->prepare("SELECT id,firstName,email,mobile FROM users WHERE email=? AND status IN('Active','Pending')");
    } else {
        $stmt = $pdo->prepare("SELECT id,firstName,email,mobile FROM users WHERE mobile=? AND status IN('Active','Pending')");
    }
    $stmt->execute([$identifier]); $user = $stmt->fetch();

    if (!$user) { echo json_encode(['success'=>false,'message'=>'No account found with that '.($type==='email'?'email':'phone number')]); exit; }

    $otp     = str_pad(random_int(0,999999),6,'0',STR_PAD_LEFT);
    $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));
    $pdo->prepare("UPDATE users SET otp_code=?, otp_expires=? WHERE id=?")->execute([$otp,$expires,$user['id']]);
    $_SESSION['reset_user_id'] = $user['id'];

    if ($type === 'email') {
        $subject = "AgriTrace+ Password Reset OTP";
        $body    = "Hello {$user['firstName']},\n\nYour password reset OTP is:\n\n  $otp\n\nThis code expires in 10 minutes.\nIf you did not request this, please ignore.\n\n-- AgriTrace+ Team";
        @mail($user['email'], $subject, $body, "From: noreply@agritrace.ph\r\n");
        echo json_encode(['success'=>true,'type'=>'email','message'=>'OTP sent to your email address.']);
    } else {
        // SMS via local gateway or Semaphore (Philippines)
        // Replace with actual SMS API: Semaphore, Vonage, etc.
        // sendSms($user['mobile'], "AgriTrace+ OTP: $otp. Expires in 10 minutes.");
        echo json_encode(['success'=>true,'type'=>'sms','message'=>'OTP sent via SMS to your mobile number.']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password | AgriTrace+</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Syne:wght@800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
:root{--g:#10b981;--gd:#059669;--gb:rgba(255,255,255,.2);}
*{margin:0;padding:0;box-sizing:border-box;font-family:'DM Sans',sans-serif;}
body{min-height:100vh;display:flex;justify-content:center;align-items:center;
  background:linear-gradient(rgba(0,0,0,.65),rgba(0,0,0,.85)),
  url('https://images.unsplash.com/photo-1500382017468-9049fed747ef?auto=format&fit=crop&q=80&w=2000') center/cover fixed;padding:20px;}
.card{background:rgba(255,255,255,.1);backdrop-filter:blur(20px);border:1px solid var(--gb);
  border-radius:32px;padding:40px;width:100%;max-width:440px;box-shadow:0 40px 100px rgba(0,0,0,.5);text-align:center;}
.brand{font-family:'Syne',sans-serif;font-size:2.2rem;font-weight:800;color:white;cursor:pointer;}
.brand span{color:var(--g);}
h1{color:white;font-size:1.5rem;font-weight:700;margin:20px 0 10px;}
.sub{color:rgba(255,255,255,.7);font-size:.95rem;margin-bottom:30px;line-height:1.5;}
.form-group{text-align:left;margin-bottom:20px;}
.input-wrap{position:relative;display:flex;align-items:center;}
.input-wrap i.ico{position:absolute;left:18px;color:#94a3b8;font-size:1.1rem;transition:.3s;}
.form-input{width:100%;padding:15px 15px 15px 50px;border-radius:16px;border:1px solid transparent;
  background:rgba(255,255,255,.95);font-size:1rem;color:#1e293b;outline:none;transition:.3s;}
.form-input:focus{border-color:var(--g);box-shadow:0 0 0 4px rgba(16,185,129,.2);}
.btn-primary{width:100%;padding:16px;background:var(--g);color:white;border:none;border-radius:16px;
  font-weight:700;font-size:1rem;cursor:pointer;transition:.3s;display:flex;align-items:center;justify-content:center;gap:10px;}
.btn-primary:hover{background:var(--gd);transform:translateY(-2px);}
.btn-primary:disabled{opacity:.7;cursor:not-allowed;transform:none;}
.back-link{margin-top:25px;font-size:.9rem;}
.back-link a{color:white;text-decoration:none;font-weight:700;display:inline-flex;align-items:center;gap:5px;}
.back-link a:hover{color:var(--g);}

/* Step 2 - OTP + new password */
.step2{display:none;}
.step2.show{display:block;}
.otp-inputs{display:flex;gap:10px;justify-content:center;margin:20px 0;}
.otp-inp{width:50px;height:60px;border-radius:12px;border:2px solid rgba(255,255,255,.3);
  background:rgba(255,255,255,.1);color:white;font-size:1.5rem;font-weight:700;text-align:center;outline:none;transition:.3s;}
.otp-inp:focus{border-color:var(--g);background:rgba(16,185,129,.1);}
.toast-box{position:fixed;top:20px;right:20px;z-index:9999;pointer-events:none;}
.toast{display:flex;align-items:center;gap:12px;padding:14px 20px;border-radius:14px;color:white;
  font-weight:600;font-size:.9rem;box-shadow:0 10px 30px rgba(0,0,0,.3);margin-bottom:10px;
  animation:slideIn .4s ease;}
.toast.success{background:#10b981;}.toast.error{background:#ef4444;}
@keyframes slideIn{from{transform:translateX(110%);opacity:0;}to{transform:translateX(0);opacity:1;}}
@keyframes slideOut{from{transform:translateX(0);opacity:1;}to{transform:translateX(110%);opacity:0;}}
</style>
</head>
<body>
<div class="toast-box" id="toastBox"></div>

<div class="card">
  <div class="brand" onclick="window.location.href='index.php'">
    <i class="bi bi-leaf-fill"></i> Agri<span>Trace+</span>
  </div>

  <!-- Step 1: Request OTP -->
  <div id="step1">
    <h1>Reset Your Password</h1>
    <p class="sub">Enter your email or mobile number to receive a reset OTP.</p>

    <div class="form-group">
      <div class="input-wrap">
        <i class="bi bi-person-badge ico" id="inpIcon"></i>
        <input type="text" id="identifier" class="form-input" placeholder="Email or +63 9XX XXX XXXX">
      </div>
    </div>

    <button class="btn-primary" id="sendBtn" onclick="sendReset()">
      <span id="sendTxt">Send Reset OTP</span>
      <i class="bi bi-arrow-right" id="sendIco"></i>
    </button>
  </div>

  <!-- Step 2: Verify OTP + new password -->
  <div id="step2" class="step2">
    <h1>Enter OTP & New Password</h1>
    <p class="sub" id="step2Sub">Enter the 6-digit OTP sent to you and choose a new password.</p>

    <div class="otp-inputs">
      <input type="text" class="otp-inp" maxlength="1" inputmode="numeric">
      <input type="text" class="otp-inp" maxlength="1" inputmode="numeric">
      <input type="text" class="otp-inp" maxlength="1" inputmode="numeric">
      <input type="text" class="otp-inp" maxlength="1" inputmode="numeric">
      <input type="text" class="otp-inp" maxlength="1" inputmode="numeric">
      <input type="text" class="otp-inp" maxlength="1" inputmode="numeric">
    </div>

    <div class="form-group">
      <div class="input-wrap">
        <i class="bi bi-lock ico"></i>
        <input type="password" id="newPw" class="form-input" placeholder="New Password (min 8 chars)">
      </div>
    </div>
    <div class="form-group">
      <div class="input-wrap">
        <i class="bi bi-shield-lock ico"></i>
        <input type="password" id="cfmPw" class="form-input" placeholder="Confirm New Password">
      </div>
    </div>

    <button class="btn-primary" id="resetBtn" onclick="doReset()">
      <span>Reset Password</span>
      <i class="bi bi-check-circle"></i>
    </button>
  </div>

  <div class="back-link"><a href="login.php"><i class="bi bi-arrow-left"></i> Back to Login</a></div>
</div>

<script>
// ── Detect input type ─────────────────────────────────────────────────────────
document.getElementById('identifier').addEventListener('input', e => {
  const v = e.target.value.trim(), ico = document.getElementById('inpIcon');
  if (/^[0-9+]+$/.test(v) && v.length > 5) { ico.className = 'bi bi-phone-fill ico'; ico.style.color='#10b981'; }
  else if (v.includes('@'))                  { ico.className = 'bi bi-envelope-fill ico'; ico.style.color='#10b981'; }
  else                                        { ico.className = 'bi bi-person-badge ico'; ico.style.color=''; }
});

// ── Send Reset OTP ────────────────────────────────────────────────────────────
async function sendReset() {
  const id = document.getElementById('identifier').value.trim();
  if (!id) { showToast('Please enter your email or mobile number.', false); return; }
  const btn = document.getElementById('sendBtn');
  btn.disabled = true;
  document.getElementById('sendTxt').textContent = 'Sending...';
  document.getElementById('sendIco').className = 'bi bi-arrow-repeat';

  const fd = new FormData(); fd.append('send_reset','1'); fd.append('identifier', id);
  const res = await fetch('forgot.php', {method:'POST', body:fd});
  const data = await res.json();

  if (data.success) {
    showToast(data.message, true);
    document.getElementById('step1').style.display = 'none';
    document.getElementById('step2').classList.add('show');
    if (data.type === 'sms') document.getElementById('step2Sub').textContent = 'Enter the OTP sent via SMS to your mobile number.';
    else document.getElementById('step2Sub').textContent = 'Enter the OTP sent to your email address.';
    otpSetup();
  } else {
    showToast(data.message, false);
    btn.disabled = false;
    document.getElementById('sendTxt').textContent = 'Send Reset OTP';
    document.getElementById('sendIco').className = 'bi bi-arrow-right';
  }
}

// ── OTP Inputs ────────────────────────────────────────────────────────────────
function otpSetup() {
  const inps = document.querySelectorAll('.otp-inp');
  inps.forEach((inp, i) => {
    inp.addEventListener('input', () => { if(inp.value && i < inps.length-1) inps[i+1].focus(); });
    inp.addEventListener('keydown', e => { if(e.key==='Backspace' && !inp.value && i>0) inps[i-1].focus(); });
  });
  inps[0].focus();
}

// ── Reset Password ────────────────────────────────────────────────────────────
async function doReset() {
  const otp  = Array.from(document.querySelectorAll('.otp-inp')).map(i=>i.value).join('');
  const pw   = document.getElementById('newPw').value;
  const cpw  = document.getElementById('cfmPw').value;
  if (otp.length < 6) { showToast('Please enter all 6 OTP digits.', false); return; }
  if (pw.length < 8)  { showToast('Password must be at least 8 characters.', false); return; }
  if (pw !== cpw)     { showToast('Passwords do not match.', false); return; }

  const btn = document.getElementById('resetBtn');
  btn.disabled = true;

  const fd = new FormData();
  fd.append('reset_password','1'); fd.append('otp', otp); fd.append('new_password', pw);
  const res = await fetch('forgot.php', {method:'POST', body:fd});
  const data = await res.json();
  showToast(data.message, data.success);
  if (data.success) setTimeout(() => window.location.href='login.php', 2500);
  else btn.disabled = false;
}

// ── Toast ─────────────────────────────────────────────────────────────────────
function showToast(msg, ok) {
  const box = document.getElementById('toastBox');
  const t = document.createElement('div');
  t.className = 'toast ' + (ok ? 'success' : 'error');
  t.innerHTML = `<i class="bi bi-${ok?'check-circle-fill':'exclamation-triangle-fill'}"></i><span>${msg}</span>`;
  box.appendChild(t);
  setTimeout(() => { t.style.animation='slideOut .4s forwards'; setTimeout(()=>t.remove(),400); }, 4000);
}
</script>
</body>
</html>