<?php 
session_start(); 
require_once 'api/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fname = trim($_POST['fname']);
    $lname = trim($_POST['lname']);
    $email = trim($_POST['email']);
    $mobile = trim($_POST['mobile']);
    $role = $_POST['role'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    
    try {
        $stmt = $pdo->prepare("INSERT INTO users (firstName, lastName, email, password, mobile, role, status) VALUES (?, ?, ?, ?, ?, ?, 'Pending')");
        $stmt->execute([$fname, $lname, $email, $password, $mobile, $role]);
        $register_success = "Registration successful! Your account is pending approval.";
    } catch (PDOException $e) {
        $register_error = "Email already exists or registration failed.";
    }
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
        :root {
            --primary-green: #10b981; 
            --accent-blue: #3b82f6;
            --glass-bg: rgba(255, 255, 255, 0.1);
            --glass-border: rgba(255, 255, 255, 0.2);
            --input-bg: #ffffff;
            --text-main: #ffffff;
            --text-dim: rgba(255, 255, 255, 0.7);
            --strength-weak: #ef4444;
            --strength-fair: #f59e0b;
            --strength-good: #3b82f6;
            --strength-strong: #10b981;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'DM Sans', sans-serif; }

        body {
            min-height: 100vh; display: flex; justify-content: center; align-items: center;
            background: linear-gradient(rgba(0, 0, 0, 0.6), rgba(0, 0, 0, 0.8)), 
                        url('https://images.unsplash.com/photo-1500382017468-9049fed747ef?auto=format&fit=crop&q=80&w=2000') center/cover fixed;
            padding: 40px 20px;
        }

        .login-container {
            background: var(--glass-bg); backdrop-filter: blur(25px); border: 1px solid var(--glass-border);
            border-radius: 32px; padding: 40px 30px; width: 100%; max-width: 480px; text-align: center;
            box-shadow: 0 40px 100px rgba(0, 0, 0, 0.6); margin: auto; 
        }

        .close-btn {
            position: absolute; top: 20px; right: 20px; background: rgba(255, 255, 255, 0.15); border: none;
            color: white; width: 32px; height: 32px; border-radius: 50%; cursor: pointer;
            display: flex; align-items: center; justify-content: center; z-index: 10;
        }

        .brand-logo {
            font-family: 'Syne', sans-serif; font-size: 2.4rem; font-weight: 800; color: white;
            margin-bottom: 5px; letter-spacing: -1px;
        }
        .brand-logo span { color: var(--primary-green); }

        .geo-badge {
            background: #3b82f6; color: white; padding: 6px 16px; border-radius: 100px;
            font-size: 0.7rem; font-weight: 800; display: inline-flex; align-items: center; gap: 6px;
            margin-bottom: 20px; box-shadow: 0 0 20px rgba(59, 130, 246, 0.4);
        }

        .subtitle { color: white; font-weight: 500; margin-bottom: 25px; font-size: 1rem; }

        .success-msg, .error-msg {
            padding: 12px; border-radius: 12px; margin-bottom: 20px; font-size: 0.9rem;
        }
        .success-msg { background: rgba(16, 185, 129, 0.2); border: 1px solid var(--primary-green); color: #d1fae5; }
        .error-msg { background: rgba(239, 68, 68, 0.2); border: 1px solid #ef4444; color: #fecaca; }

        .form-row { display: flex; gap: 12px; margin-bottom: 15px; }
        .form-group { text-align: left; margin-bottom: 15px; flex: 1; }
        .form-label { display: block; color: white; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px; }

        .input-wrap { position: relative; display: flex; align-items: center; }
        .input-wrap i:not(.password-toggle) { position: absolute; left: 15px; color: #64748b; font-size: 1.1rem; }

        .form-input, .form-select {
            width: 100%; padding: 13px 15px 13px 45px; border-radius: 12px; border: none;
            background: #ffffff; font-size: 0.95rem; color: #1e293b; outline: none;
        }
        .password-toggle { position: absolute; right: 15px; color: #64748b; cursor: pointer; }

        .strength-container { margin-top: 10px; }
        .strength-meter { height: 4px; width: 100%; background: rgba(255, 255, 255, 0.1); border-radius: 2px; overflow: hidden; margin-bottom: 4px; }
        .strength-bar { height: 100%; width: 0%; transition: all 0.4s ease; }
        .strength-text { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; display: block; text-align: right; color: rgba(255,255,255,0.5); }

        .requirements-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 12px; text-align: left;
        }
        .req-item {
            font-size: 0.72rem; color: rgba(255, 255, 255, 0.5); display: flex; align-items: center; gap: 6px;
            transition: all 0.3s ease;
        }
        .req-item i { font-size: 0.8rem; }
        .req-item.valid { color: var(--primary-green); font-weight: 600; }
        .req-item.valid i { color: var(--primary-green); }

        .btn-register {
            width: 100%; padding: 16px; background: #065f46; color: white; border: none; border-radius: 12px;
            font-weight: 700; font-size: 1rem; cursor: pointer; margin-top: 25px; transition: 0.2s;
        }
        .btn-register:hover { background: #047857; transform: translateY(-1px); }
        .btn-register:disabled { opacity: 0.6; cursor: not-allowed; }

        .footer-link { margin-top: 20px; font-size: 0.85rem; color: white; }
        .footer-link a { color: white; font-weight: 700; text-decoration: none; }

        @media (max-width: 480px) {
            .form-row { flex-direction: column; gap: 0; }
            .requirements-grid { grid-template-columns: 1fr; }
            .login-container { padding: 35px 20px; border-radius: 24px; }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <button class="close-btn" onclick="window.history.back()">
            <i class="bi bi-x-lg"></i>
        </button>

        <div class="brand-logo">Agri<span>Trace+</span></div>
        <div class="geo-badge">
            <i class="bi bi-geo-alt-fill"></i> GEO-TAGGING ENABLED
        </div>
        <p class="subtitle">Create Your Account</p>

        <?php if (isset($register_success)): ?>
            <div class="success-msg">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?= htmlspecialchars($register_success) ?>
            </div>
        <?php endif; ?>

        <?php if (isset($register_error)): ?>
            <div class="error-msg">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <?= htmlspecialchars($register_error) ?>
            </div>
        <?php endif; ?>

        <form action="register-form.php" method="POST" id="registerForm">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">First Name</label>
                    <div class="input-wrap">
                        <i class="bi bi-person"></i>
                        <input type="text" name="fname" class="form-input" placeholder="Juan" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Last Name</label>
                    <div class="input-wrap">
                        <i class="bi bi-person"></i>
                        <input type="text" name="lname" class="form-input" placeholder="dela Cruz" required>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Email Address</label>
                <div class="input-wrap">
                    <i class="bi bi-envelope"></i>
                    <input type="email" name="email" class="form-input" placeholder="juan@example.com" required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Mobile Number</label>
                <div class="input-wrap">
                    <i class="bi bi-phone"></i>
                    <input type="tel" name="mobile" class="form-input" placeholder="+63 9XX XXX XXXX" required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Role</label>
                <div class="input-wrap">
                    <i class="bi bi-briefcase"></i>
                    <select name="role" class="form-select" required>
                        <option value="">-- Select Role --</option>
                        <option value="Farmer">Farmer</option>
                        <option value="Agriculture Official">Agriculture Official</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Password <i class="bi bi-info-circle"></i></label>
                <div class="input-wrap">
                    <i class="bi bi-lock"></i>
                    <input type="password" id="pass" name="password" class="form-input" placeholder="Create a strong password" required minlength="8">
                    <i class="bi bi-eye password-toggle" id="toggleIcon"></i>
                </div>
                
                <div class="strength-container">
                    <div class="strength-meter">
                        <div class="strength-bar" id="strengthBar"></div>
                    </div>
                    <span class="strength-text" id="strengthText">Very Weak</span>
                </div>

                <div class="requirements-grid">
                    <div class="req-item" id="length"><i class="bi bi-circle"></i> At least 8 characters</div>
                    <div class="req-item" id="number"><i class="bi bi-circle"></i> Contains a number</div>
                    <div class="req-item" id="special"><i class="bi bi-circle"></i> Contains special character</div>
                    <div class="req-item" id="lowercase"><i class="bi bi-circle"></i> Contains lowercase</div>
                    <div class="req-item" id="uppercase"><i class="bi bi-circle"></i> Contains uppercase</div>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Confirm Password</label>
                <div class="input-wrap">
                    <i class="bi bi-shield-lock"></i>
                    <input type="password" name="cpassword" class="form-input" placeholder="Repeat password" required>
                </div>
            </div>

            <div style="text-align: left; margin-top: 15px;">
                <label style="color: white; font-size: 0.8rem; display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="checkbox" name="terms" required> I agree to the <a href="#" style="color: var(--primary-green); text-decoration: none;">Terms and Conditions</a>
                </label>
            </div>

            <button type="submit" class="btn-register" id="submitBtn">
                REGISTER ACCOUNT
            </button>
        </form>

        <div class="footer-link">
            Already have an account? <a href="login.php">Log In</a>
        </div>

        <div style="margin-top: 30px; font-size: 0.7rem; color: rgba(255,255,255,0.4);">
            © 2026 AgriTrace Technologies
        </div>
    </div>

    <script>
        // Password Visibility Toggle
        function toggleVisibility() {
            const p = document.getElementById('pass');
            const icon = document.getElementById('toggleIcon');
            if (p.type === 'password') {
                p.type = 'text';
                icon.classList.replace('bi-eye', 'bi-eye-slash');
            } else {
                p.type = 'password';
                icon.classList.replace('bi-eye-slash', 'bi-eye');
            }
        }
        document.getElementById('toggleIcon').addEventListener('click', toggleVisibility);

        // Real-time Password Validation & Strength Meter
        const passwordInput = document.getElementById('pass');
        const strengthBar = document.getElementById('strengthBar');
        const strengthText = document.getElementById('strengthText');
        const submitBtn = document.getElementById('submitBtn');
        const confirmPass = document.querySelector('input[name="cpassword"]');
        
        const requirements = {
            length: (val) => val.length >= 8,
            number: (val) => /[0-9]/.test(val),
            special: (val) => /[!@#$%^&*(),.?":{}|<>]/.test(val),
            lowercase: (val) => /[a-z]/.test(val),
            uppercase: (val) => /[A-Z]/.test(val)
        };

        function updatePasswordStrength(value) {
            let score = 0;
            for (const key in requirements) {
                const element = document.getElementById(key);
                const icon = element.querySelector('i');
                const isValid = requirements[key](value);
                if (isValid) {
                    element.classList.add('valid');
                    icon.classList.replace('bi-circle', 'bi-check-circle-fill');
                    score++;
                } else {
                    element.classList.remove('valid');
                    icon.classList.replace('bi-check-circle-fill', 'bi-circle');
                }
            }
            updateStrengthMeter(score, value.length);
        }

        function updateStrengthMeter(score, length) {
            let width = "0%", color = "transparent", label = "Very Weak";
            if (length > 0) {
                if (score <= 2) { width = "25%"; color = "var(--strength-weak)"; label = "Weak"; }
                else if (score === 3) { width = "50%"; color = "var(--strength-fair)"; label = "Fair"; }
                else if (score === 4) { width = "75%"; color = "var(--strength-good)"; label = "Good"; }
                else if (score === 5) { width = "100%"; color = "var(--strength-strong)"; label = "Strong"; }
            }
            strengthBar.style.width = width;
            strengthBar.style.backgroundColor = color;
            strengthText.innerText = label;
            strengthText.style.color = length > 0 ? color : "rgba(255,255,255,0.5)";
        }

        passwordInput.addEventListener('input', () => updatePasswordStrength(passwordInput.value));

        // Form validation
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            const pass = passwordInput.value;
            const cpass = confirmPass.value;
            const terms = document.querySelector('input[name="terms"]').checked;
            
            if (pass !== cpass) {
                e.preventDefault();
                alert('Passwords do not match!');
                return false;
            }
            
            const score = Object.values(requirements).filter(req => req(pass)).length;
            if (score < 3) {
                e.preventDefault();
                alert('Password must meet at least 3 requirements!');
                return false;
            }
            
            if (!terms) {
                e.preventDefault();
                alert('You must agree to the Terms and Conditions!');
                return false;
            }
            
            submitBtn.disabled = true;
            submitBtn.innerHTML = 'Creating Account... <i class="bi bi-arrow-repeat"></i>';
        });
    </script>
</body>
</html>