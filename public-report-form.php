<?php
// public_report.php – Anonymous public livestock incident report
session_start();

// DB connection
try {
    $pdo = new PDO("mysql:host=localhost;dbname=myproject4;charset=utf8mb4","root","",[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
    ]);
} catch(PDOException $e) {
    $dbError = true;
}

// ── HANDLE FORM SUBMISSION ────────────────────────────────────────────────────
$successMsg = '';
$errorMsg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_report'])) {
    try {
        // Validate required fields
        $reportType  = $_POST['report_type']  ?? '';
        $otherType   = $_POST['other_type']   ?? '';
        $description = trim($_POST['description'] ?? '');
        $phone       = trim($_POST['contact_phone'] ?? '');
        $email       = trim($_POST['contact_email'] ?? '');
        $lat         = !empty($_POST['latitude'])  ? (float)$_POST['latitude']  : null;
        $lng         = !empty($_POST['longitude']) ? (float)$_POST['longitude'] : null;
        $agreed      = isset($_POST['agree_terms']);

        if(!$reportType)  throw new Exception('Please select a report type.');
        if(!$description) throw new Exception('Please provide a description.');
        if(!$phone)       throw new Exception('Contact phone is required.');
        if(!$agreed)      throw new Exception('You must agree to the terms.');

        // Handle file uploads
        $uploadDir = 'uploads/public_reports/';
        @mkdir($uploadDir, 0755, true);
        $allowed = ['image/jpeg','image/png','image/webp','image/gif','video/mp4','video/quicktime'];

        function saveUpload($file, $dir, $allowed) {
            if(empty($file['tmp_name'])) return null;
            if(!in_array($file['type'], $allowed)) throw new Exception("Invalid file type: ".$file['name']);
            if($file['size'] > 15*1024*1024) throw new Exception("File too large (max 15MB): ".$file['name']);
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $name = uniqid('pr_',true).'.'.$ext;
            move_uploaded_file($file['tmp_name'], $dir.$name);
            return $dir.$name;
        }

        $photoUrl = saveUpload($_FILES['report_media']??[], $uploadDir, $allowed);
        $idPhoto  = saveUpload($_FILES['id_photo']??[], $uploadDir, $allowed);
        $facePic  = saveUpload($_FILES['face_photo']??[], $uploadDir, $allowed);

        // Save to DB
        $finalType = ($reportType === 'Others' && $otherType) ? $otherType : $reportType;
        $pdo->prepare("INSERT INTO public_reports(reportType,otherType,description,contactPhone,contactEmail,idPhotoUrl,facePhotoUrl,latitude,longitude,status)
                        VALUES(?,?,?,?,?,?,?,?,?,'Pending')")
            ->execute([$finalType, $otherType, $description, $phone, $email, $idPhoto, $facePic, $lat, $lng]);

        $successMsg = 'Your report has been submitted successfully. Thank you for helping protect livestock health in our community!';

    } catch(Exception $e) {
        $errorMsg = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AgriTrace+ | Public Report</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Plus Jakarta Sans',-apple-system,sans-serif;background:linear-gradient(rgba(0,0,0,.52),rgba(0,0,0,.52)),url('https://images.unsplash.com/photo-1500382017468-9049fed747ef?auto=format&fit=crop&w=1920&q=80') center/cover fixed;min-height:100vh;color:#fff;}
.navbar{display:flex;justify-content:space-between;align-items:center;padding:1rem 3rem;background:rgba(0,0,0,.25);backdrop-filter:blur(10px);}
.logo{font-size:1.4rem;font-weight:800;color:#fff;text-decoration:none;}.logo span{color:#10b981;}
.nav-links a{color:rgba(255,255,255,.8);text-decoration:none;margin-left:1.5rem;font-size:.9rem;transition:.2s;}
.nav-links a:hover{color:#fff;}
.nav-links a.login-btn{background:#10b981;padding:.5rem 1.25rem;border-radius:.5rem;color:#fff;font-weight:600;}
.main{display:flex;justify-content:center;padding:2.5rem 1.25rem;}
.glass{background:rgba(255,255,255,.1);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);border:1px solid rgba(255,255,255,.2);border-radius:1.25rem;width:100%;max-width:680px;padding:2.5rem;box-shadow:0 10px 40px rgba(0,0,0,.4);position:relative;}
.close-btn{position:absolute;right:1.25rem;top:1.25rem;background:none;border:none;color:rgba(255,255,255,.7);font-size:1.4rem;cursor:pointer;transition:.2s;text-decoration:none;display:flex;align-items:center;justify-content:center;width:2.25rem;height:2.25rem;border-radius:50%;}
.close-btn:hover{background:rgba(255,255,255,.15);color:#fff;}
.hdr{text-align:center;margin-bottom:2rem;}
.brand-big{font-size:1.75rem;font-weight:800;}
.brand-big span{color:#10b981;}
.badge-pub{background:#3b82f6;font-size:.7rem;padding:.3rem .875rem;border-radius:20px;display:inline-flex;align-items:center;gap:.35rem;margin:.5rem 0;font-weight:700;text-transform:uppercase;letter-spacing:.05em;}
.sub-txt{font-size:.875rem;opacity:.8;}
.form-group{margin-bottom:1.25rem;}
.form-label{display:block;margin-bottom:.5rem;font-size:.9rem;font-weight:600;opacity:.95;}
.form-label .req{color:#f87171;}
.radio-card{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);border-radius:.625rem;padding:.875rem 1rem;margin-bottom:.5rem;display:flex;align-items:center;cursor:pointer;transition:.2s;}
.radio-card:hover{background:rgba(255,255,255,.15);}
.radio-card input[type=radio]{width:1.1rem;height:1.1rem;margin-right:.875rem;accent-color:#10b981;flex-shrink:0;}
.radio-card.selected{background:rgba(16,185,129,.15);border-color:#10b981;}
.form-input,.form-textarea,.form-select{width:100%;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.25);border-radius:.625rem;padding:.875rem 1rem;color:#fff;font-size:.95rem;font-family:inherit;transition:.2s;}
.form-input:focus,.form-textarea:focus,.form-select:focus{outline:none;border-color:#10b981;background:rgba(255,255,255,.18);box-shadow:0 0 0 3px rgba(16,185,129,.2);}
.form-input::placeholder,.form-textarea::placeholder{color:rgba(255,255,255,.5);}
.form-select option{background:#064e3b;color:#fff;}
.upload-row{display:flex;gap:.75rem;align-items:stretch;}
.upload-row .form-input{flex:1;}
.upload-btn{background:rgba(59,130,246,.8);color:#fff;border:none;padding:.875rem 1.125rem;border-radius:.625rem;cursor:pointer;font-weight:600;display:flex;align-items:center;gap:.5rem;font-size:.875rem;white-space:nowrap;transition:.2s;flex-shrink:0;}
.upload-btn:hover{background:rgba(59,130,246,1);}
.upload-btn.green{background:rgba(16,185,129,.8);}.upload-btn.green:hover{background:rgba(16,185,129,1);}
.file-preview{display:none;margin-top:.5rem;padding:.625rem .875rem;background:rgba(16,185,129,.15);border:1px solid rgba(16,185,129,.4);border-radius:.5rem;font-size:.85rem;align-items:center;gap:.5rem;}
.file-preview.show{display:flex;}
.file-preview i{color:#10b981;}
.file-preview .rm-file{margin-left:auto;background:none;border:none;color:rgba(255,255,255,.6);cursor:pointer;font-size:1rem;}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem;}
.terms-row{display:flex;align-items:flex-start;gap:.75rem;margin:1.25rem 0;}
.terms-row input[type=checkbox]{width:1.25rem;height:1.25rem;accent-color:#10b981;flex-shrink:0;margin-top:.1rem;cursor:pointer;}
.terms-row label{font-size:.875rem;line-height:1.5;cursor:pointer;}
.terms-link{color:#10b981;font-weight:600;cursor:pointer;text-decoration:underline;}
.submit-btn{width:100%;background:#10b981;color:#fff;border:none;padding:1.125rem;border-radius:.75rem;font-weight:700;font-size:1.05rem;cursor:pointer;transition:.2s;font-family:inherit;}
.submit-btn:hover{background:#059669;transform:translateY(-1px);}
.submit-btn:disabled{background:#374151;cursor:not-allowed;transform:none;}
.bottom-links{display:flex;justify-content:space-between;margin-top:1rem;font-size:.8rem;opacity:.65;}
.bottom-links a{color:#fff;text-decoration:none;}
.bottom-links a:hover{opacity:1;color:#10b981;}
.success-card{background:rgba(16,185,129,.15);border:2px solid rgba(16,185,129,.5);border-radius:1rem;padding:2.5rem;text-align:center;}
.success-card i{font-size:4rem;color:#10b981;display:block;margin-bottom:1rem;}
.error-bar{background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.5);border-radius:.625rem;padding:1rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:.75rem;font-size:.9rem;color:#fca5a5;}
/* Terms Modal */
.modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.8);backdrop-filter:blur(8px);z-index:9000;display:none;align-items:center;justify-content:center;padding:1.25rem;}
.modal-bg.open{display:flex;}
.modal-box{background:#1e293b;border:1px solid rgba(255,255,255,.15);border-radius:1.25rem;width:100%;max-width:560px;max-height:85vh;overflow:hidden;display:flex;flex-direction:column;animation:modalIn .3s ease;}
@keyframes modalIn{from{transform:translateY(20px);opacity:0;}to{transform:translateY(0);opacity:1;}}
.modal-hdr{padding:1.5rem;border-bottom:1px solid rgba(255,255,255,.1);display:flex;justify-content:space-between;align-items:center;}
.modal-hdr h3{margin:0;color:#fff;font-size:1.2rem;}
.modal-close-btn{background:none;border:none;color:rgba(255,255,255,.6);font-size:1.5rem;cursor:pointer;padding:.25rem;}
.modal-close-btn:hover{color:#fff;}
.modal-body{padding:1.5rem;overflow-y:auto;color:rgba(255,255,255,.85);font-size:.9rem;line-height:1.7;}
.modal-body h4{color:#10b981;margin:.875rem 0 .375rem;}
.modal-body ul{padding-left:1.25rem;}
.modal-body li{margin-bottom:.375rem;}
.modal-footer{padding:1.25rem 1.5rem;border-top:1px solid rgba(255,255,255,.1);display:flex;gap:.75rem;justify-content:flex-end;}
.btn-accept{background:#10b981;color:#fff;border:none;padding:.75rem 1.5rem;border-radius:.625rem;font-weight:700;cursor:pointer;font-family:inherit;}
.btn-decline{background:rgba(255,255,255,.1);color:#fff;border:1px solid rgba(255,255,255,.2);padding:.75rem 1.5rem;border-radius:.625rem;cursor:pointer;font-family:inherit;}
.gps-badge{background:rgba(16,185,129,.2);border:1px solid rgba(16,185,129,.5);border-radius:.5rem;padding:.5rem .875rem;font-size:.85rem;font-weight:600;color:#6ee7b7;display:none;align-items:center;gap:.5rem;margin-top:.5rem;}
.gps-badge.show{display:flex;}
@media(max-width:600px){.form-grid{grid-template-columns:1fr;}.navbar{padding:1rem;}.glass{padding:1.5rem;}.upload-row{flex-direction:column;}}
</style>
</head>
<body>

<nav class="navbar">
  <a href="index.php" class="logo">Agri<span>Trace+</span></a>
  <div class="nav-links">
    <a href="index.php">Home</a>
    <a href="login.php" class="login-btn"><i class="bi bi-box-arrow-in-right"></i> Login</a>
  </div>
</nav>

<div class="main">
  <div class="glass">
    <a href="index.php" class="close-btn" title="Close"><i class="bi bi-x-lg"></i></a>

    <div class="hdr">
      <div class="brand-big">Agri<span>Trace+</span></div>
      <div class="badge-pub"><i class="bi bi-globe"></i> Public Access</div>
      <p class="sub-txt">Submit Anonymous Reports for Livestock Health &amp; Safety</p>
    </div>

    <?php if($successMsg): ?>
    <div class="success-card">
      <i class="bi bi-check-circle-fill"></i>
      <h3 style="font-size:1.375rem;font-weight:700;margin-bottom:.875rem;">Report Submitted!</h3>
      <p style="opacity:.85;margin-bottom:1.5rem;"><?= htmlspecialchars($successMsg) ?></p>
      <a href="public_report.php" style="background:#10b981;color:#fff;padding:.75rem 2rem;border-radius:.625rem;text-decoration:none;font-weight:700;">Submit Another Report</a>
    </div>
    <?php else: ?>

    <?php if($errorMsg): ?>
    <div class="error-bar"><i class="bi bi-exclamation-triangle-fill"></i><?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>

    <form id="reportForm" method="POST" enctype="multipart/form-data">
      <input type="hidden" name="submit_report" value="1">
      <input type="hidden" id="repLat" name="latitude">
      <input type="hidden" id="repLng" name="longitude">

      <!-- Report Type -->
      <div class="form-group">
        <label class="form-label">Report Type <span class="req">*</span></label>
        <?php $types=[['Sick','bi-thermometer-half','Sick livestock'],['Dead','bi-heartbreak','Dead animals'],['Stray','bi-signpost-split','Stray livestock'],['Disease','bi-virus','Suspected disease outbreak'],['Others','bi-three-dots','Others']]; ?>
        <?php foreach($types as [$val,$icon,$label]): ?>
        <label class="radio-card" onclick="this.classList.add('selected');document.querySelectorAll('.radio-card').forEach(c=>{if(c!==this)c.classList.remove('selected');});<?= $val==='Others'?'toggleOther(true)':'toggleOther(false)' ?>">
          <input type="radio" name="report_type" value="<?= $val ?>" required>
          <i class="bi <?= $icon ?>" style="margin-right:.5rem;color:#10b981;"></i> <?= $label ?>
        </label>
        <?php endforeach; ?>
        <div id="otherSpec" style="display:none;margin-top:.5rem;">
          <input type="text" class="form-input" name="other_type" placeholder="Please specify…" id="otherText">
        </div>
      </div>

      <!-- Media upload -->
      <div class="form-group">
        <label class="form-label">Upload Photos / Videos</label>
        <div class="upload-row">
          <input type="text" class="form-input" id="mediaFileName" placeholder="No file chosen" readonly>
          <button type="button" class="upload-btn" onclick="document.getElementById('mediaFile').click()"><i class="bi bi-camera-fill"></i> Add</button>
        </div>
        <input type="file" id="mediaFile" name="report_media" accept="image/*,video/*" style="display:none;" onchange="previewFile(this,'mediaFileName','mediaPreview')">
        <div class="file-preview" id="mediaPreview"><i class="bi bi-file-earmark-check"></i><span id="mediaPreviewName">–</span><button type="button" class="rm-file" onclick="clearFile('mediaFile','mediaFileName','mediaPreview')"><i class="bi bi-x-lg"></i></button></div>
      </div>

      <!-- GPS auto-detect -->
      <div class="form-group">
        <label class="form-label">Location <small style="opacity:.7;">(auto-detected or click button)</small></label>
        <div class="upload-row">
          <input type="text" class="form-input" id="repLocText" placeholder="Location will be auto-detected…" readonly>
          <button type="button" class="upload-btn green" onclick="detectLocation()"><i class="bi bi-geo-alt-fill"></i> Detect</button>
        </div>
        <div class="gps-badge" id="repGpsBadge"><i class="bi bi-check-circle-fill"></i><span id="repGpsText">–</span></div>
      </div>

      <!-- Description -->
      <div class="form-group">
        <label class="form-label">Description <span class="req">*</span></label>
        <textarea class="form-textarea" name="description" rows="3" placeholder="Describe the issue, location, or your observation in detail…" required></textarea>
      </div>

      <!-- Contact -->
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Contact Phone <span class="req">*</span></label>
          <input type="tel" class="form-input" name="contact_phone" placeholder="+63 912 345 6789" required>
        </div>
        <div class="form-group">
          <label class="form-label">Contact Email</label>
          <input type="email" class="form-input" name="contact_email" placeholder="Optional">
        </div>
      </div>

      <!-- ID & Face Photo -->
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Upload ID Photo <span class="req">*</span></label>
          <div class="upload-row">
            <input type="text" class="form-input" id="idFileName" placeholder="No file…" readonly>
            <button type="button" class="upload-btn" onclick="document.getElementById('idFile').click()"><i class="bi bi-card-heading"></i></button>
          </div>
          <input type="file" id="idFile" name="id_photo" accept="image/*" style="display:none;" onchange="previewFile(this,'idFileName','idPreview')">
          <div class="file-preview" id="idPreview"><i class="bi bi-check-circle"></i><span id="idPreviewName">–</span><button type="button" class="rm-file" onclick="clearFile('idFile','idFileName','idPreview')"><i class="bi bi-x-lg"></i></button></div>
        </div>
        <div class="form-group">
          <label class="form-label">Upload Face Photo <span class="req">*</span></label>
          <div class="upload-row">
            <input type="text" class="form-input" id="faceFileName" placeholder="No file…" readonly>
            <button type="button" class="upload-btn green" onclick="openSelfie()"><i class="bi bi-camera"></i> Selfie</button>
          </div>
          <input type="file" id="faceFile" name="face_photo" accept="image/*" style="display:none;" onchange="previewFile(this,'faceFileName','facePreview')">
          <div class="file-preview" id="facePreview"><i class="bi bi-check-circle"></i><span id="facePreviewName">–</span><button type="button" class="rm-file" onclick="clearFile('faceFile','faceFileName','facePreview')"><i class="bi bi-x-lg"></i></button></div>
        </div>
      </div>

      <!-- Terms checkbox -->
      <div class="terms-row">
        <input type="checkbox" name="agree_terms" id="agreeChk" onchange="handleTermsChange(this)">
        <label for="agreeChk">I confirm that this report is accurate, genuine, and not submitted in bad faith or for fraudulent purposes. I have read and agree to the <span class="terms-link" onclick="openTerms()">Terms &amp; Conditions</span>.</label>
      </div>

      <button type="submit" class="submit-btn" id="submitBtn" disabled><i class="bi bi-send-fill"></i> SUBMIT REPORT</button>
    </form>

    <div class="bottom-links">
      <a href="login.php"><i class="bi bi-box-arrow-in-right"></i> Log In for Full Access</a>
      <a href="index.php">← Back to Home</a>
    </div>
    <?php endif; ?>

    <p style="text-align:center;margin-top:2rem;font-size:.75rem;opacity:.45;">© <?= date('Y') ?> AgriTrace Technologies · Camarines Sur, Philippines</p>
  </div>
</div>

<!-- Terms & Conditions Modal -->
<div id="termsModal" class="modal-bg">
  <div class="modal-box">
    <div class="modal-hdr">
      <h3><i class="bi bi-shield-check" style="color:#10b981;"></i> Terms &amp; Conditions</h3>
      <button class="modal-close-btn" onclick="closeTerms()"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="modal-body">
      <p><strong>AgriTrace+ Public Report Terms of Service</strong></p>
      <p>By submitting this report, you agree to the following terms:</p>
      <h4>1. Accuracy of Information</h4>
      <p>You certify that all information provided in this report is truthful, accurate, and based on genuine observations. You must not submit false, misleading, or fabricated reports.</p>
      <h4>2. Good Faith Reporting</h4>
      <ul>
        <li>Reports must be submitted in good faith to report legitimate livestock health concerns.</li>
        <li>Submitting malicious or fraudulent reports may result in legal action under applicable Philippine laws.</li>
        <li>Do not use this form to harass, defame, or harm others.</li>
      </ul>
      <h4>3. Data Privacy</h4>
      <ul>
        <li>Your contact information will only be used for follow-up on this specific report.</li>
        <li>Photo, ID, and face data are used solely for verification and investigation purposes.</li>
        <li>We comply with the Data Privacy Act of 2012 (Republic Act 10173).</li>
      </ul>
      <h4>4. Investigation</h4>
      <p>The Department of Agriculture – Camarines Sur may follow up with you regarding this report. By providing contact details, you consent to being contacted for this purpose.</p>
      <h4>5. Limitation of Liability</h4>
      <p>AgriTrace+ and the Department of Agriculture are not liable for outcomes based on reports submitted. Investigation results depend on field verification.</p>
      <h4>6. Penalties</h4>
      <p>False reports may be subject to penalties under Republic Act 10175 (Cybercrime Prevention Act) and other applicable laws.</p>
    </div>
    <div class="modal-footer">
      <button class="btn-decline" onclick="declineTerms()">Decline</button>
      <button class="btn-accept" onclick="acceptTerms()"><i class="bi bi-check-lg"></i> Accept &amp; Agree</button>
    </div>
  </div>
</div>

<!-- Selfie/Camera Modal -->
<div id="selfieModal" class="modal-bg">
  <div class="modal-box" style="max-width:440px;">
    <div class="modal-hdr"><h3><i class="bi bi-camera"></i> Take a Selfie</h3><button class="modal-close-btn" onclick="closeSelfie()"><i class="bi bi-x-lg"></i></button></div>
    <div style="background:#000;position:relative;">
      <video id="selfieVid" autoplay playsinline style="width:100%;max-height:340px;object-fit:cover;display:block;"></video>
    </div>
    <div style="padding:1rem;display:flex;gap:1rem;justify-content:center;background:rgba(255,255,255,.05);">
      <button class="btn-accept" onclick="takeSelfie()" style="padding:.875rem 2rem;"><i class="bi bi-camera-fill"></i> Capture</button>
      <button class="btn-decline" onclick="closeSelfie()">Cancel</button>
    </div>
  </div>
</div>

<script>
// ── Report type: toggle "others" input ────────────────────────────────────────
function toggleOther(show) {
    document.getElementById('otherSpec').style.display = show ? 'block' : 'none';
    document.getElementById('otherText').required = show;
}

// ── File preview ──────────────────────────────────────────────────────────────
function previewFile(input, nameId, previewId) {
    const file = input.files[0];
    if(!file) return;
    document.getElementById(nameId).value = file.name;
    const prev = document.getElementById(previewId);
    prev.classList.add('show');
    const nameEl = document.getElementById(previewId.replace('Preview','PreviewName'));
    if(nameEl) nameEl.textContent = file.name + ' (' + (file.size/1024/1024).toFixed(2) + ' MB)';
}
function clearFile(inputId, nameId, previewId) {
    document.getElementById(inputId).value='';
    document.getElementById(nameId).value='';
    document.getElementById(previewId).classList.remove('show');
}

// ── GPS detection ─────────────────────────────────────────────────────────────
async function detectLocation() {
    const btn = event.target.closest('button');
    btn.innerHTML='<i class="bi bi-hourglass-split"></i>';
    if(!navigator.geolocation){ alert('Geolocation not supported'); btn.innerHTML='<i class="bi bi-geo-alt-fill"></i> Detect'; return; }
    navigator.geolocation.getCurrentPosition(async pos => {
        const {latitude:lat, longitude:lng} = pos.coords;
        document.getElementById('repLat').value = lat;
        document.getElementById('repLng').value = lng;
        try {
            const r = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=16&accept-language=en`);
            const d = await r.json();
            const addr = d.display_name || `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
            document.getElementById('repLocText').value = addr;
            document.getElementById('repGpsBadge').classList.add('show');
            document.getElementById('repGpsText').textContent = `Lat: ${lat.toFixed(6)}, Lng: ${lng.toFixed(6)}`;
        } catch { document.getElementById('repLocText').value = `${lat.toFixed(6)}, ${lng.toFixed(6)}`; }
        btn.innerHTML='<i class="bi bi-geo-alt-fill"></i> Detect';
    }, () => { alert('Could not get location.'); btn.innerHTML='<i class="bi bi-geo-alt-fill"></i> Detect'; }, {enableHighAccuracy:true,timeout:12000});
}

// ── Terms Modal ────────────────────────────────────────────────────────────────
function openTerms(e) { if(e) e.preventDefault(); document.getElementById('termsModal').classList.add('open'); }
function closeTerms() { document.getElementById('termsModal').classList.remove('open'); }
function acceptTerms() {
    document.getElementById('agreeChk').checked = true;
    document.getElementById('submitBtn').disabled = false;
    closeTerms();
}
function declineTerms() {
    document.getElementById('agreeChk').checked = false;
    document.getElementById('submitBtn').disabled = true;
    closeTerms();
}
function handleTermsChange(cb) {
    if(cb.checked) { openTerms(); cb.checked=false; }
    else document.getElementById('submitBtn').disabled = true;
}

document.getElementById('termsModal').addEventListener('click', e => { if(e.target.id==='termsModal') closeTerms(); });

// ── Selfie Camera ─────────────────────────────────────────────────────────────
let selfieStream=null;
async function openSelfie() {
    try {
        selfieStream = await navigator.mediaDevices.getUserMedia({video:{facingMode:'user'}});
        document.getElementById('selfieVid').srcObject = selfieStream;
        document.getElementById('selfieModal').classList.add('open');
    } catch { document.getElementById('faceFile').click(); }
}
function closeSelfie() {
    if(selfieStream){ selfieStream.getTracks().forEach(t=>t.stop()); selfieStream=null; }
    document.getElementById('selfieModal').classList.remove('open');
}
function takeSelfie() {
    const video = document.getElementById('selfieVid');
    const canvas = document.createElement('canvas');
    canvas.width = video.videoWidth||640; canvas.height = video.videoHeight||480;
    canvas.getContext('2d').drawImage(video,0,0);
    canvas.toBlob(blob => {
        const file = new File([blob],'selfie.jpg',{type:'image/jpeg'});
        const dt = new DataTransfer(); dt.items.add(file);
        const input = document.getElementById('faceFile');
        input.files = dt.files;
        previewFile(input,'faceFileName','facePreview');
        closeSelfie();
    },'image/jpeg',.92);
}

// Close modals on backdrop click
document.getElementById('selfieModal').addEventListener('click', e => { if(e.target.id==='selfieModal') closeSelfie(); });
</script>
</body>
</html>