<?php
session_start();

// AUTHENTICATION CHECK
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['PHP_SELF']));
    exit;
}

$user_id = $_SESSION['user_id'] ?? 0;

// Database connection
try {
    $pdo = new PDO('mysql:host=localhost;dbname=myproject4;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch(PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}

// Fetch user profile data
$stmt = $pdo->prepare("
    SELECT u.*, p.gov_id, p.department, p.position, p.office, 
           p.assigned_region, p.municipality, p.province, p.profile_pix
    FROM users u 
    LEFT JOIN officer_profiles p ON u.id = p.user_id 
    WHERE u.id = ?
");
$stmt->execute([$user_id]);
$user_profile = $stmt->fetch();

// Profile Update Handler
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    header('Content-Type: application/json');
    
    try {
        // 1. Handle Profile Picture Upload
        $profile_pic_path = $user_profile['profile_pix'] ?? null;
        if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/profiles/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_extension = strtolower(pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
            
            if (in_array($file_extension, $allowed_extensions)) {
                $new_filename = $user_id . '_' . time() . '.' . $file_extension;
                $upload_path = $upload_dir . $new_filename;
                
                if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $upload_path)) {
                    $profile_pic_path = $upload_path;
                    
                    // Delete old profile pic if exists
                    if ($user_profile['profile_pix'] && file_exists($user_profile['profile_pix'])) {
                        unlink($user_profile['profile_pix']);
                    }
                }
            }
        }
        
        $pdo->beginTransaction();
        
        // 2. Update Users Table
        $stmt = $pdo->prepare("
            UPDATE users SET 
                firstName = ?, lastName = ?, mobile = ?,
                updatedAt = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([
            $_POST['firstName'],
            $_POST['lastName'],
            $_POST['mobile'] ?? null,
            $user_id
        ]);
        
        // 3. Update/Insert Officer Profile
        $stmt = $pdo->prepare("
            INSERT INTO officer_profiles 
            (user_id, gov_id, department, position, office, assigned_region, municipality, province, profile_pix)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                gov_id = VALUES(gov_id),
                department = VALUES(department),
                position = VALUES(position),
                office = VALUES(office),
                assigned_region = VALUES(assigned_region),
                municipality = VALUES(municipality),
                province = VALUES(province),
                profile_pix = VALUES(profile_pix),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            $user_id,
            $_POST['gov_id'] ?? null,
            $_POST['department'] ?? null,
            $_POST['position'] ?? null,
            $_POST['office'] ?? null,
            $_POST['assigned_region'] ?? null,
            $_POST['municipality'] ?? null,
            $_POST['province'] ?? null,
            $profile_pic_path
        ]);
        
        // 4. Handle Password Change
        if (!empty($_POST['new_password'])) {
            $hashed_password = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashed_password, $user_id]);
        }
        
        $pdo->commit();
        
        echo json_encode(['success' => true, 'message' => 'Profile updated successfully!']);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Profile update error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Update failed: ' . $e->getMessage()]);
    }
    exit;
}
?>

<!-- Edit Profile Modal HTML -->
<div id="editProfileModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="bi bi-person-gear"></i> Edit Profile</h3>
            <button class="modal-close" onclick="closeEditProfile()">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <form id="editProfileForm" enctype="multipart/form-data">
            <!-- Profile Picture -->
            <div class="form-group">
                <label>Profile Picture</label>
                <div class="profile-pic-upload">
                    <img id="editProfilePreview" src="<?php echo htmlspecialchars($user_profile['profile_pix'] ?? ''); ?>" 
                         alt="Preview" style="display: <?php echo !empty($user_profile['profile_pix']) ? 'block' : 'none'; ?>;">
                    <input type="file" id="profilePicInput" name="profile_pic" accept="image/*" style="display: none;">
                    <button type="button" class="btn-upload" onclick="document.getElementById('profilePicInput').click()">
                        <i class="bi bi-camera"></i> Change Photo
                    </button>
                    <small>Recommended: 300x300px, JPG/PNG</small>
                </div>
            </div>

            <!-- Personal Info -->
            <div class="form-section">
                <h4>Personal Information</h4>
                <div class="form-row">
                    <div class="form-group">
                        <label>First Name <span class="required">*</span></label>
                        <input type="text" name="firstName" value="<?php echo htmlspecialchars($user_profile['firstName'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Last Name <span class="required">*</span></label>
                        <input type="text" name="lastName" value="<?php echo htmlspecialchars($user_profile['lastName'] ?? ''); ?>" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" value="<?php echo htmlspecialchars($user_profile['email'] ?? ''); ?>" disabled>
                    </div>
                    <div class="form-group">
                        <label>Mobile</label>
                        <input type="tel" name="mobile" value="<?php echo htmlspecialchars($user_profile['mobile'] ?? ''); ?>" placeholder="09XXXXXXXXX">
                    </div>
                </div>
            </div>

            <!-- Work Info -->
            <div class="form-section">
                <h4>Work Information</h4>
                <div class="form-row">
                    <div class="form-group">
                        <label>Gov't ID</label>
                        <input type="text" name="gov_id" value="<?php echo htmlspecialchars($user_profile['gov_id'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Department</label>
                        <input type="text" name="department" value="<?php echo htmlspecialchars($user_profile['department'] ?? ''); ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Position</label>
                        <input type="text" name="position" value="<?php echo htmlspecialchars($user_profile['position'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Office</label>
                        <input type="text" name="office" value="<?php echo htmlspecialchars($user_profile['office'] ?? ''); ?>">
                    </div>
                </div>
            </div>

            <!-- Assignment Area - Searchable Dropdowns -->
            <div class="form-section">
                <h4>Assignment Area</h4>
                
                <!-- Region Dropdown -->
                <div class="form-group">
                    <label>Region <span class="required">*</span></label>
                    <div class="searchable-dropdown">
                        <input type="text" id="regionSearch" placeholder="Search regions..." readonly>
                        <div class="dropdown-list" id="regionDropdown">
                            <!-- Populated by JavaScript -->
                        </div>
                    </div>
                    <input type="hidden" name="assigned_region" id="regionInput" value="<?php echo htmlspecialchars($user_profile['assigned_region'] ?? ''); ?>">
                </div>

                <!-- Province Dropdown -->
                <div class="form-group">
                    <label>Province</label>
                    <div class="searchable-dropdown">
                        <input type="text" id="provinceSearch" placeholder="Search provinces..." readonly>
                        <div class="dropdown-list" id="provinceDropdown">
                            <!-- Populated by JavaScript -->
                        </div>
                    </div>
                    <input type="hidden" name="province" id="provinceInput" value="<?php echo htmlspecialchars($user_profile['province'] ?? ''); ?>">
                </div>

                <!-- Municipality Dropdown -->
                <div class="form-group">
                    <label>Municipality</label>
                    <div class="searchable-dropdown">
                        <input type="text" id="municipalitySearch" placeholder="Search municipalities..." readonly>
                        <div class="dropdown-list" id="municipalityDropdown">
                            <!-- Populated by JavaScript -->
                        </div>
                    </div>
                    <input type="hidden" name="municipality" id="municipalityInput" value="<?php echo htmlspecialchars($user_profile['municipality'] ?? ''); ?>">
                </div>
            </div>

            <!-- Password -->
            <div class="form-section">
                <h4>Security</h4>
                <div class="form-group">
                    <label>New Password (leave blank to keep current)</label>
                    <input type="password" name="new_password" placeholder="••••••••">
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeEditProfile()">Cancel</button>
                <button type="submit" class="btn-save">
                    <i class="bi bi-check-lg"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal JavaScript -->
<script>
// PH Location Data (same as main file)
const phRegions = [
    "Region I – Ilocos Region", "Region II – Cagayan Valley", "Region III – Central Luzon",
    "Region IV‑A – CALABARZON", "MIMAROPA Region", "Region V – Bicol Region",
    "Region VI – Western Visayas", "Region VII – Central Visayas", "Region VIII – Eastern Visayas",
    "Region IX – Zamboanga Peninsula", "Region X – Northern Mindanao", "Region XI – Davao Region",
    "Region XII – SOCCSKSARGEN", "Region XIII – Caraga", "NCR – National Capital Region",
    "CAR – Cordillera Administrative Region", "BARMM – Bangsamoro Autonomous Region in Muslim Mindanao",
    "NIR – Negros Island Region"
];

// Include province and municipality data here (same as main file)
// ... (phProvinces and phMunicipalitiesByProvince)

<?php if ($_SERVER["REQUEST_METHOD"] != "POST"): ?>
// Initialize dropdowns when modal loads
document.addEventListener('DOMContentLoaded', function() {
    initProfileModal();
});

function initProfileModal() {
    // Initialize searchable dropdowns (same SearchableDropdown class as main file)
    // ... (include the full SearchableDropdown class here)
}
<?php endif; ?>

// Form submission handler
document.getElementById('editProfileForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('user_id', <?php echo $user_id; ?>);
    formData.append('action', 'update_profile');
    
    fetch('edit-profile-modal.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            parent.showToast('✅ Profile updated successfully!', 'success');
            parent.closeEditProfile();
            setTimeout(() => parent.location.reload(), 1500);
        } else {
            parent.showToast('❌ ' + (data.message || 'Update failed'), 'error');
        }
    })
    .catch(error => {
        parent.showToast('❌ Network error. Please try again.', 'error');
        console.error('Profile update error:', error);
    });
});

// Profile picture preview
document.getElementById('profilePicInput').addEventListener('change', function(e) {
    const file = e.target.files[0];
    const preview = document.getElementById('editProfilePreview');
    
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            preview.style.display = 'block';
        };
        reader.readAsDataURL(file);
    }
});
</script>