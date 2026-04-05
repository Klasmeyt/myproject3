<?php session_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | AgriTrace+</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Syne:wght@800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <style>
        :root {
            --primary-green: #10b981; 
            --primary-hover: #059669;
            --glass-bg: rgba(255, 255, 255, 0.1);
            --glass-border: rgba(255, 255, 255, 0.2);
            --text-main: #ffffff;
            --text-dim: rgba(255, 255, 255, 0.7);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'DM Sans', sans-serif; }

        body {
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            background: linear-gradient(rgba(0, 0, 0, 0.65), rgba(0, 0, 0, 0.85)), 
                        url('https://images.unsplash.com/photo-1500382017468-9049fed747ef?auto=format&fit=crop&q=80&w=2000') center/cover fixed;
            padding: 20px;
        }

        .auth-container {
            width: 100%;
            max-width: 440px;
            perspective: 1000px;
        }

        .auth-card {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 32px;
            padding: 40px;
            box-shadow: 0 40px 100px rgba(0, 0, 0, 0.5);
            text-align: center;
            position: relative;
            animation: cardAppear 0.6s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes cardAppear {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .brand-logo {
            font-family: 'Syne', sans-serif;
            font-size: 2.2rem;
            font-weight: 800;
            color: white;
            margin-bottom: 30px;
            letter-spacing: -1px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .brand-logo i { color: var(--primary-green); font-size: 1.8rem; }
        .brand-logo span { color: var(--primary-green); }

        h1 { color: white; font-size: 1.5rem; font-weight: 700; margin-bottom: 12px; }
        .subtitle { color: var(--text-dim); font-size: 0.95rem; line-height: 1.5; margin-bottom: 30px; }

        .form-group { text-align: left; margin-bottom: 25px; }
        .form-label { display: block; color: white; font-size: 0.85rem; font-weight: 600; margin-bottom: 8px; margin-left: 4px; }
        
        .input-wrap { position: relative; display: flex; align-items: center; }
        .input-wrap i { position: absolute; left: 18px; color: #94a3b8; font-size: 1.1rem; transition: color 0.3s; }

        .form-input {
            width: 100%;
            padding: 15px 15px 15px 50px;
            border-radius: 16px;
            border: 1px solid transparent;
            background: rgba(255, 255, 255, 0.95);
            font-size: 1rem;
            color: #1e293b;
            outline: none;
            transition: all 0.3s ease;
        }

        .form-input:focus {
            background: #ffffff;
            border-color: var(--primary-green);
            box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.2);
        }

        .btn-reset {
            width: 100%;
            padding: 16px;
            background: var(--primary-green);
            color: white;
            border: none;
            border-radius: 16px;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 10px 20px rgba(16, 185, 129, 0.2);
        }

        .btn-reset:hover {
            background: var(--primary-hover);
            transform: translateY(-2px);
            box-shadow: 0 15px 25px rgba(16, 185, 129, 0.3);
        }

        .btn-reset:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }

        .auth-footer { margin-top: 30px; font-size: 0.9rem; }
        .auth-footer a { 
            color: white; 
            text-decoration: none; 
            font-weight: 700; 
            display: inline-flex; 
            align-items: center; 
            gap: 5px;
            transition: color 0.2s;
        }
        .auth-footer a:hover { color: var(--primary-green); }

        .spinner { animation: rotate 1s linear infinite; }
        @keyframes rotate { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes slideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }

        @media (max-width: 480px) {
            .auth-card { padding: 30px 20px; border-radius: 24px; }
            .brand-logo { font-size: 1.8rem; }
        }
    </style>
</head>
<body>

    <div class="auth-container">
        <div class="auth-card">
            <div class="brand-logo" onclick="window.location.href='index.php'" style="cursor:pointer">
                <i class="bi bi-leaf-fill"></i> Agri<span>Trace+</span>
            </div>

            <h1>Reset Your Password</h1>
            <p class="subtitle">Enter your email or mobile number and we'll send your recovery instructions.</p>

            <form id="forgot-form">
                <div class="form-group">
                    <label class="form-label" id="input-label">Email or Mobile Number</label>
                    <div class="input-wrap">
                        <i class="bi bi-person-badge" id="input-icon"></i>
                        <input type="text" id="forgot-identifier" class="form-input" 
                               placeholder="e.g. user@email.com or 09123456789" required>
                    </div>
                </div>
                
                <button type="submit" class="btn-reset" id="submit-btn">
                    <span>Send Reset Link</span>
                    <i class="bi bi-arrow-right" id="btn-icon"></i>
                </button>
            </form>

            <div class="auth-footer">
                <a href="login.php#login">
                    <i class="bi bi-arrow-left"></i> Back to Login
                </a>
            </div>
        </div>

        <div style="text-align:center; margin-top: 30px; color: rgba(255,255,255,0.4); font-size: 0.75rem;">
            &copy; 2026 AgriTrace Technologies. All rights reserved.
        </div>
    </div>

    <script>
        const inputField = document.getElementById('forgot-identifier');
        const inputIcon = document.getElementById('input-icon');
        const submitBtn = document.getElementById('submit-btn');
        const btnText = submitBtn.querySelector('span');

        // Logic to detect Email vs Mobile Number and update UI
        inputField.addEventListener('input', (e) => {
            const val = e.target.value.trim();
            
            // Regex for numbers only (Mobile)
            if (/^[0-9+]+$/.test(val) && val.length > 5) {
                inputIcon.className = 'bi bi-phone-fill';
                inputIcon.style.color = '#10b981';
                btnText.innerText = 'Send Reset Code via SMS';
            } 
            // Regex for Email detection
            else if (val.includes('@')) {
                inputIcon.className = 'bi bi-envelope-fill';
                inputIcon.style.color = '#10b981';
                btnText.innerText = 'Send Reset Link via Email';
            } 
            // Default/Empty state
            else {
                inputIcon.className = 'bi bi-person-badge';
                inputIcon.style.color = '#94a3b8';
                btnText.innerText = 'Send Reset Request';
            }
        });

        document.getElementById('forgot-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const identifier = inputField.value.trim();
            const btnIcon = document.getElementById('btn-icon');
            
            // Determine type for backend processing
            const type = identifier.includes('@') ? 'email' : 'phone';
            
            // Loading State
            submitBtn.disabled = true;
            const originalBtnText = btnText.innerText;
            btnText.innerText = 'Processing Request...';
            btnIcon.className = 'bi bi-arrow-repeat spinner';
            
            try {
                // Adjusting the API call to include the "type" parameter
                const response = await fetch(`api/db.php?action=forgot-password&identifier=${encodeURIComponent(identifier)}&type=${type}`);
                const result = await response.json();
                
                if (result.success) {
                    const message = type === 'email' ? 'Email sent! Check your inbox.' : 'SMS sent! Check your messages.';
                    showToast(message, false);
                    
                    submitBtn.style.background = '#059669';
                    btnText.innerText = 'Success!';
                    btnIcon.className = 'bi bi-check2-all';
                    
                    // Redirect after a short wait
                    setTimeout(() => window.location.href = 'index.php#login', 3000);
                } else {
                    showToast(result.error || 'User not found in our records', true);
                    resetButton(originalBtnText);
                }
            } catch (error) {
                showToast('Network error. Connection failed.', true);
                resetButton(originalBtnText);
            }
        });

        function resetButton(oldText) {
            submitBtn.disabled = false;
            btnText.innerText = oldText;
            document.getElementById('btn-icon').className = 'bi bi-arrow-right';
        }

        function showToast(msg, isError = false) {
            const toast = document.createElement('div');
            toast.style.cssText = `
                position:fixed; top:20px; right:20px; z-index:10000;
                background:${isError ? '#ef4444' : '#10b981'}; 
                color:#fff; padding:16px 24px; border-radius:16px;
                box-shadow:0 10px 30px rgba(0,0,0,0.3);
                display:flex; align-items:center; gap:12px;
                font-weight:600; font-size:0.9rem;
                animation: slideIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            `;
            
            toast.innerHTML = `
                <i class="bi ${isError ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill'}"></i>
                <span>${msg}</span>
            `;
            
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'slideOut 0.4s forwards';
                setTimeout(() => toast.remove(), 400);
            }, 4000);
        }
    </script>
</body>
</html>