<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AgriTrace+ | Public Access Report</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root {
            --primary-green: #00a86b;
            --glass-bg: rgba(255, 255, 255, 0.1);
            --glass-border: rgba(255, 255, 255, 0.2);
            --text-white: #ffffff;
            --transition: all 0.3s ease;
        }

        body {
            margin: 0;
            font-family: 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(rgba(0,0,0,0.5), rgba(0,0,0,0.5)), 
                        url('https://images.unsplash.com/photo-1500382017468-9049fed747ef?auto=format&fit=crop&w=1920&q=80');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            min-height: 100vh;
            color: var(--text-white);
        }

        /* Navbar */
        .navbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 50px;
            background: rgba(0, 0, 0, 0.2);
            backdrop-filter: blur(10px);
        }

        .logo {
            font-size: 22px;
            font-weight: bold;
            color: var(--text-white);
        }
        .logo span { color: var(--primary-green); }

        .nav-links a {
            color: white;
            text-decoration: none;
            margin-left: 25px;
            font-size: 0.9rem;
        }

        .login-btn {
            background: var(--primary-green);
            padding: 8px 18px;
            border-radius: 6px;
            font-weight: bold;
        }

        /* Container */
        .main-wrapper {
            display: flex;
            justify-content: center;
            padding: 40px 20px;
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);
            border: 1px solid var(--glass-border);
            border-radius: 15px;
            width: 100%;
            max-width: 650px;
            padding: 35px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.4);
            position: relative;
        }

        /* Close Link Styling */
        .close-link {
            position: absolute;
            right: 20px;
            top: 20px;
            color: white;
            text-decoration: none;
            opacity: 0.6;
            transition: var(--transition);
            z-index: 10;
        }

        .close-link:hover {
            opacity: 1;
            transform: scale(1.1);
        }

        .close-icon {
            font-size: 1.4rem;
            cursor: pointer;
        }

        .header-area {
            text-align: center;
            margin-bottom: 25px;
        }

        .badge-access {
            background: #3b82f6;
            font-size: 0.7rem;
            padding: 4px 12px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin: 10px 0;
            font-weight: bold;
            text-transform: uppercase;
        }

        /* Form Controls */
        .form-group { margin-bottom: 18px; }
        
        .label-text {
            display: block;
            margin-bottom: 8px;
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .radio-box {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 8px;
            padding: 12px 15px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            cursor: pointer;
            transition: var(--transition);
        }

        .radio-box:hover { background: rgba(255, 255, 255, 0.15); }

        .radio-box input {
            margin-right: 12px;
            width: 18px;
            height: 18px;
            accent-color: var(--primary-green);
        }

        .input-field {
            width: 100%;
            background: #fff;
            border: none;
            border-radius: 6px;
            padding: 12px 15px;
            box-sizing: border-box;
            color: #333;
            font-size: 0.95rem;
        }

        .input-icon-wrapper {
            position: relative;
        }

        .input-icon-wrapper i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #777;
        }

        .input-icon-wrapper .input-field {
            padding-left: 40px;
        }

        .flex-row {
            display: flex;
            gap: 12px;
        }

        .btn-blue {
            background: #3b82f6;
            color: white;
            border: none;
            padding: 0 15px;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: bold;
            white-space: nowrap;
        }

        /* Animations */
        #other-spec-container {
            display: none;
            margin-top: 10px;
            animation: slideDown 0.3s ease-out;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .grid-half {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .submit-btn {
            width: 100%;
            background: var(--primary-green);
            color: white;
            border: none;
            padding: 16px;
            border-radius: 8px;
            font-weight: bold;
            font-size: 1rem;
            cursor: pointer;
            margin-top: 10px;
            transition: var(--transition);
        }

        .submit-btn:hover {
            filter: brightness(1.1);
            transform: translateY(-1px);
        }

        .bottom-links {
            display: flex;
            justify-content: space-between;
            margin-top: 15px;
            font-size: 0.8rem;
            opacity: 0.7;
        }

        .bottom-links a { color: white; text-decoration: none; }

        @media (max-width: 600px) {
            .grid-half { grid-template-columns: 1fr; }
            .navbar { padding: 15px; }
        }
    </style>
</head>
<body>

    <div class="main-wrapper">
        <div class="glass-card">
            <a href="index.php" class="close-link" title="Close and return to home">
                <i class="bi bi-x-lg close-icon"></i>
            </a>
            
            <div class="header-area">
                <h1 style="margin:0">Agri<span style="color: var(--primary-green)">Trace+</span></h1>
                <div class="badge-access"><i class="bi bi-globe"></i> Public Access</div>
                <p style="font-size: 0.85rem; opacity: 0.8;">Submit Anonymous Reports for Livestock Health and Safety</p>
            </div>

            <form id="publicForm">
                <div class="form-group">
                    <label class="label-text">Report Type</label>
                    <label class="radio-box">
                        <input type="radio" name="report_type" value="Sick" required>
                        <span>Sick livestock</span>
                    </label>
                    <label class="radio-box">
                        <input type="radio" name="report_type" value="Dead">
                        <span>Dead animals</span>
                    </label>
                    <label class="radio-box">
                        <input type="radio" name="report_type" value="Stray">
                        <span>Stray livestock</span>
                    </label>
                    <label class="radio-box">
                        <input type="radio" name="report_type" value="Disease">
                        <span>Suspected disease outbreak</span>
                    </label>
                    <label class="radio-box">
                        <input type="radio" name="report_type" value="Others" id="othersRadio">
                        <span>Others</span>
                    </label>

                    <div id="other-spec-container">
                        <input type="text" id="other-text" class="input-field" placeholder="Please specify report type..." style="border: 1px solid var(--primary-green)">
                    </div>
                </div>

                <div class="form-group">
                    <label class="label-text">Upload Photos/Videos</label>
                    <div class="flex-row">
                        <input type="text" class="input-field" placeholder="No file chosen" readonly>
                        <button type="button" class="btn-blue"><i class="bi bi-camera-fill"></i> Add</button>
                    </div>
                </div>

                <div class="form-group">
                    <label class="label-text">Description</label>
                    <textarea class="input-field" rows="3" placeholder="Describe the issue, location, or observation..." required></textarea>
                </div>

                <div class="form-group">
                    <label class="label-text">Contact Phone *</label>
                    <div class="input-icon-wrapper">
                        <i class="bi bi-telephone"></i>
                        <input type="tel" class="input-field" placeholder="Enter phone number" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="label-text">Contact Email (Optional)</label>
                    <div class="input-icon-wrapper">
                        <i class="bi bi-envelope"></i>
                        <input type="email" class="input-field" placeholder="Enter email address">
                    </div>
                </div>

                <div class="grid-half">
                    <div class="form-group">
                        <label class="label-text">Upload ID Photo *</label>
                        <div class="flex-row">
                            <input type="text" class="input-field" placeholder="No file..." readonly>
                            <button type="button" class="btn-blue">Add</button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="label-text">Upload Face Photo *</label>
                        <div class="flex-row">
                            <input type="text" class="input-field" placeholder="No file..." readonly>
                            <button type="button" class="btn-blue"><i class="bi bi-camera"></i> Selfie</button>
                        </div>
                    </div>
                </div>

                <div style="display: flex; gap: 10px; margin: 20px 0; font-size: 0.85rem;">
                    <input type="checkbox" required style="margin-top: 3px;">
                    <label>I confirm that this report is accurate, genuine, and not submitted in bad faith or for fraudulent purposes.</label>
                </div>

                <button type="submit" class="submit-btn">SUBMIT REPORT</button>

                <div class="bottom-links">
                    <a href="login.php">Log In for Full Access</a>
                </div>
            </form>

            <div style="text-align: center; margin-top: 35px; font-size: 0.75rem; opacity: 0.5;">
                &copy; 2026 AgriTrace Technologies
            </div>
        </div>
    </div>

    <script>
        const radioBtns = document.querySelectorAll('input[name="report_type"]');
        const specContainer = document.getElementById('other-spec-container');
        const specInput = document.getElementById('other-text');

        radioBtns.forEach(radio => {
            radio.addEventListener('change', function() {
                if (this.id === 'othersRadio') {
                    specContainer.style.display = 'block';
                    specInput.setAttribute('required', 'required');
                } else {
                    specContainer.style.display = 'none';
                    specInput.removeAttribute('required');
                    specInput.value = ''; 
                }
            });
        });

        // Simple Submit Handling
        document.getElementById('publicForm').addEventListener('submit', (e) => {
            e.preventDefault();
            alert('Report submitted successfully!');
        });
    </script>
</body>
</html>