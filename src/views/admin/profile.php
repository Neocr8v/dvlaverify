<?php
// filepath: /Applications/XAMPP/xamppfiles/htdocs/dvlaregister/src/views/admin/profile.php
session_start();
require_once __DIR__ . '/../../../config/database.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../auth/login.php');
    exit;
}

// Initialize variables
$userId = $_SESSION['user_id'];
$success = '';
$error = '';
$passwordSuccess = '';
$passwordError = '';
$avatarSuccess = '';
$avatarError = '';
$userData = [];

// Fetch user data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id AND role = 'admin'");
    $stmt->execute(['id' => $userId]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$userData) {
        header('Location: ../../auth/logout.php');
        exit;
    }
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');
    $ghana_card_number = trim($_POST['ghana_card_number'] ?? '');
    $address = trim($_POST['address'] ?? '');
    
    // Validate inputs
    if (empty($full_name)) {
        $error = "Full name is required";
    } elseif (empty($email)) {
        $error = "Email is required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format";
    } else {
        try {
            // Check if email already exists for another user
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = :email AND id != :id");
            $stmt->execute([
                'email' => $email,
                'id' => $userId
            ]);
            
            if ($stmt->fetchColumn() > 0) {
                $error = "Email address is already in use by another account";
            } else {
                // Update user profile
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET full_name = :full_name, 
                        email = :email, 
                        phone_number = :phone_number, 
                        ghana_card_number = :ghana_card_number,
                        address = :address,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                
                $stmt->execute([
                    'full_name' => $full_name,
                    'email' => $email,
                    'phone_number' => $phone_number,
                    'ghana_card_number' => $ghana_card_number,
                    'address' => $address,
                    'id' => $userId
                ]);
                
                $success = "Profile updated successfully";
                
                // Update session variables
                $_SESSION['full_name'] = $full_name;
                $_SESSION['email'] = $email;
                
                // Refresh user data
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
                $stmt->execute(['id' => $userId]);
                $userData = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        } catch (PDOException $e) {
            $error = "Error updating profile: " . $e->getMessage();
        }
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validate inputs
    if (empty($current_password)) {
        $passwordError = "Current password is required";
    } elseif (empty($new_password)) {
        $passwordError = "New password is required";
    } elseif (strlen($new_password) < 8) {
        $passwordError = "New password must be at least 8 characters long";
    } elseif ($new_password !== $confirm_password) {
        $passwordError = "New passwords do not match";
    } else {
        try {
            // Verify current password
            if (password_verify($current_password, $userData['password'])) {
                // Update password
                $hashedPassword = password_hash($new_password, PASSWORD_DEFAULT);
                
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET password = :password, updated_at = NOW()
                    WHERE id = :id
                ");
                
                $stmt->execute([
                    'password' => $hashedPassword,
                    'id' => $userId
                ]);
                
                $passwordSuccess = "Password changed successfully";
            } else {
                $passwordError = "Current password is incorrect";
            }
        } catch (PDOException $e) {
            $passwordError = "Error changing password: " . $e->getMessage();
        }
    }
}

// Handle profile picture upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_avatar'])) {
    // Check if file was uploaded without errors
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['avatar']['name'];
        $filetype = pathinfo($filename, PATHINFO_EXTENSION);
        
        // Verify file extension
        if (!in_array(strtolower($filetype), $allowed)) {
            $avatarError = "Only JPG, JPEG, PNG, and GIF files are allowed";
        }
        
        // Verify file size - 5MB maximum
        if ($_FILES['avatar']['size'] > 5000000) {
            $avatarError = "File size must be less than 5MB";
        }
        
        if (empty($avatarError)) {
            // Create uploads directory if it doesn't exist
            $upload_dir = __DIR__ . '/../../../uploads/avatars/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // Generate unique filename
            $new_filename = 'admin_' . $userId . '_' . time() . '.' . $filetype;
            $upload_path = $upload_dir . $new_filename;
            $db_path = 'uploads/avatars/' . $new_filename;
            
            // Try to upload file
            if (move_uploaded_file($_FILES['avatar']['tmp_name'], $upload_path)) {
                // Remove old avatar if it exists
                if (!empty($userData['avatar']) && $userData['avatar'] != 'assets/img/admin-avatar.png') {
                    $old_file = __DIR__ . '/../../../' . $userData['avatar'];
                    if (file_exists($old_file)) {
                        unlink($old_file);
                    }
                }
                
                // Check if avatar column exists in users table
                try {
                    $stmt = $pdo->prepare("SHOW COLUMNS FROM users LIKE 'avatar'");
                    $stmt->execute();
                    
                    if ($stmt->rowCount() == 0) {
                        // Avatar column doesn't exist, so add it
                        $alterStmt = $pdo->prepare("ALTER TABLE users ADD COLUMN avatar VARCHAR(255)");
                        $alterStmt->execute();
                    }
                    
                    // Update database with new avatar path
                    $stmt = $pdo->prepare("UPDATE users SET avatar = :avatar WHERE id = :id");
                    $stmt->execute([
                        'avatar' => $db_path,
                        'id' => $userId
                    ]);
                    
                    // Update user data
                    $userData['avatar'] = $db_path;
                    $avatarSuccess = "Profile picture updated successfully";
                } catch (PDOException $e) {
                    $avatarError = "Database error: " . $e->getMessage();
                }
            } else {
                $avatarError = "Failed to upload image. Please try again.";
            }
        }
    } else {
        $avatarError = "Please select an image to upload";
    }
}

// Set default avatar if not set
if (empty($userData['avatar'])) {
    $userData['avatar'] = 'assets/img/admin-avatar.png';
}

// Ghana Card formatter function
function formatGhanaCard($card) {
    $card = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', $card));
    
    if (strlen($card) > 3) {
        if (substr($card, 3, 1) !== '-') {
            $card = substr($card, 0, 3) . '-' . substr($card, 3);
        }
    }
    
    if (strlen($card) > 13) {
        if (substr($card, 13, 1) !== '-') {
            $card = substr($card, 0, 13) . '-' . substr($card, 13);
        }
    }
    
    return $card;
}

// Format Ghana Card if exists
if (!empty($userData['ghana_card_number'])) {
    $userData['ghana_card_number'] = formatGhanaCard($userData['ghana_card_number']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Profile | Vehicle Registration System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .profile-header {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 5px solid #0d6efd;
        }
        
        .profile-img {
            width: 120px;
            height: 120px;
            object-fit: cover;
            border: 4px solid #fff;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        
        .nav-pills .nav-link.active {
            background-color: #0d6efd;
        }
        
        .form-label.required:after {
            content: " *";
            color: red;
        }
        
        .password-strength {
            height: 5px;
            margin-top: 8px;
            transition: all 0.3s ease;
        }
        
        .admin-badge {
            background-color: #dc3545;
            color: white;
            font-size: 0.8rem;
            padding: 3px 8px;
            border-radius: 15px;
            margin-left: 10px;
        }
        
        .stat-card {
            transition: transform 0.3s;
            border-radius: 10px;
            overflow: hidden;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .position-relative {
            position: relative;
        }
        
        .position-absolute {
            position: absolute;
        }
        
        .bottom-0 {
            bottom: 0;
        }
        
        .end-0 {
            right: 0;
        }
        
        .avatar-preview {
            width: 200px;
            height: 200px;
            object-fit: cover;
            margin: 0 auto;
        }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../../../includes/header.php'; ?>
    
    <div class="container my-4">
        <!-- Breadcrumb -->
        <nav aria-label="breadcrumb" class="mb-4">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item active">Admin Profile</li>
            </ol>
        </nav>
        
        <!-- Profile Header -->
        <div class="profile-header d-flex align-items-center">
            <div class="me-4 position-relative">
                <img src="/<?= htmlspecialchars($userData['avatar']) ?>" alt="Admin Avatar" class="profile-img rounded-circle">
                <button type="button" class="btn btn-sm btn-primary position-absolute bottom-0 end-0" 
                        data-bs-toggle="modal" data-bs-target="#avatarModal">
                    <i class="fas fa-camera"></i>
                </button>
            </div>
            <div>
                <h2 class="mb-1"><?= htmlspecialchars($userData['full_name']) ?>
                    <span class="admin-badge"><i class="fas fa-shield-alt me-1"></i>Administrator</span>
                </h2>
                <p class="mb-1"><i class="fas fa-envelope me-2"></i><?= htmlspecialchars($userData['email']) ?></p>
                <p class="mb-0"><i class="fas fa-user-circle me-2"></i>Username: <?= htmlspecialchars($userData['username']) ?></p>
                <p class="text-muted mt-2">
                    <small>
                        <i class="fas fa-clock me-2"></i>Last updated: 
                        <?= date('F j, Y, g:i a', strtotime($userData['updated_at'] ?? $userData['created_at'])) ?>
                    </small>
                </p>
            </div>
        </div>
        
        <div class="row">
            <div class="col-lg-9">
                <!-- Tabs Navigation -->
                <ul class="nav nav-pills mb-3" id="profileTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="profile-tab" data-bs-toggle="pill" data-bs-target="#profile" type="button" role="tab" aria-controls="profile" aria-selected="true">
                            <i class="fas fa-user me-2"></i>Profile Details
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="password-tab" data-bs-toggle="pill" data-bs-target="#password" type="button" role="tab" aria-controls="password" aria-selected="false">
                            <i class="fas fa-key me-2"></i>Change Password
                        </button>
                    </li>
                </ul>
                
                <!-- Tabs Content -->
                <div class="tab-content" id="profileTabsContent">
                    <!-- Profile Tab -->
                    <div class="tab-pane fade show active" id="profile" role="tabpanel" aria-labelledby="profile-tab">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-user-edit me-2"></i>Edit Profile</h5>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($success)): ?>
                                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                                        <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?>
                                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($error)): ?>
                                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                        <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
                                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                    </div>
                                <?php endif; ?>
                                
                                <form method="post" action="">
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label for="full_name" class="form-label required">Full Name</label>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="fas fa-user"></i></span>
                                                <input type="text" class="form-control" id="full_name" name="full_name" value="<?= htmlspecialchars($userData['full_name']) ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="email" class="form-label required">Email Address</label>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                                <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($userData['email']) ?>" required>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label for="phone_number" class="form-label">Phone Number</label>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="fas fa-phone"></i></span>
                                                <input type="tel" class="form-control" id="phone_number" name="phone_number" value="<?= htmlspecialchars($userData['phone_number'] ?? '') ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="ghana_card_number" class="form-label">Ghana Card Number</label>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="fas fa-id-card"></i></span>
                                                <input type="text" class="form-control" id="ghana_card_number" name="ghana_card_number" value="<?= htmlspecialchars($userData['ghana_card_number'] ?? '') ?>">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="address" class="form-label">Address</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-home"></i></span>
                                            <textarea class="form-control" id="address" name="address" rows="3"><?= htmlspecialchars($userData['address'] ?? '') ?></textarea>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="username" class="form-label">Username</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-user-circle"></i></span>
                                            <input type="text" class="form-control" id="username" value="<?= htmlspecialchars($userData['username']) ?>" readonly disabled>
                                        </div>
                                        <div class="form-text text-muted">Username cannot be changed</div>
                                    </div>
                                    
                                    <div class="d-grid gap-2">
                                        <button type="submit" name="update_profile" class="btn btn-primary">
                                            <i class="fas fa-save me-2"></i>Update Profile
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Password Tab -->
                    <div class="tab-pane fade" id="password" role="tabpanel" aria-labelledby="password-tab">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-key me-2"></i>Change Password</h5>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($passwordSuccess)): ?>
                                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                                        <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($passwordSuccess) ?>
                                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($passwordError)): ?>
                                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                        <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($passwordError) ?>
                                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                    </div>
                                <?php endif; ?>
                                
                                <form method="post" action="" id="passwordForm">
                                    <div class="mb-3">
                                        <label for="current_password" class="form-label required">Current Password</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                            <input type="password" class="form-control" id="current_password" name="current_password" required>
                                            <button class="btn btn-outline-secondary toggle-password" type="button" data-target="current_password">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="new_password" class="form-label required">New Password</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-key"></i></span>
                                            <input type="password" class="form-control" id="new_password" name="new_password" required minlength="8">
                                            <button class="btn btn-outline-secondary toggle-password" type="button" data-target="new_password">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                        <div class="password-strength w-100 bg-light" id="password-strength"></div>
                                        <div class="form-text" id="password-feedback">Password must be at least 8 characters long</div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="confirm_password" class="form-label required">Confirm New Password</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-key"></i></span>
                                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                            <button class="btn btn-outline-secondary toggle-password" type="button" data-target="confirm_password">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                        <div class="form-text" id="password-match"></div>
                                    </div>
                                    
                                    <div class="d-grid gap-2">
                                        <button type="submit" name="change_password" class="btn btn-primary">
                                            <i class="fas fa-key me-2"></i>Change Password
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3">
                <!-- Account Activity Card -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-user-shield me-2"></i>Account Status</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span><i class="fas fa-user"></i> Status:</span>
                            <span class="badge bg-success">Active</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span><i class="fas fa-shield-alt"></i> Role:</span>
                            <span class="badge bg-danger">Administrator</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span><i class="fas fa-calendar"></i> Member Since:</span>
                            <span class="text-muted"><?= date('M d, Y', strtotime($userData['created_at'])) ?></span>
                        </div>
                        <hr>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span><i class="fas fa-clock"></i> Last Login:</span>
                            <span class="text-muted"><?= date('M d, Y, g:i a', strtotime($userData['last_login'] ?? $userData['created_at'])) ?></span>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Links Card -->
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fas fa-link me-2"></i>Quick Links</h5>
                    </div>
                    <div class="list-group list-group-flush">
                        <a href="dashboard.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-tachometer-alt me-2"></i>Admin Dashboard
                        </a>
                        <a href="create-user-login.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-user-plus me-2"></i>Create User Login
                        </a>
                        <a href="register-vehicle.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-car me-2"></i>Register Vehicle
                        </a>
                        <a href="settings.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-cog me-2"></i>System Settings
                        </a>
                        <a href="../auth/logout.php" class="list-group-item list-group-item-action text-danger">
                            <i class="fas fa-sign-out-alt me-2"></i>Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Avatar Upload Modal -->
    <div class="modal fade" id="avatarModal" tabindex="-1" aria-labelledby="avatarModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="avatarModalLabel"><i class="fas fa-image me-2"></i>Change Profile Picture</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php if (!empty($avatarError)): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($avatarError) ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($avatarSuccess)): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($avatarSuccess) ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="post" enctype="multipart/form-data" id="avatar-form">
                        <div class="text-center mb-4">
                            <img id="avatar-preview" src="/<?= htmlspecialchars($userData['avatar']) ?>" 
                                alt="Avatar Preview" class="rounded-circle img-thumbnail avatar-preview">
                        </div>
                        
                        <div class="mb-3">
                            <label for="avatar" class="form-label">Select Image</label>
                            <input class="form-control" type="file" id="avatar" name="avatar" accept="image/*">
                            <div class="form-text">Allowed file types: JPG, JPEG, PNG, GIF. Maximum file size: 5MB.</div>
                        </div>
                        
                        <div class="d-grid gap-2">
                            <button type="submit" name="upload_avatar" class="btn btn-primary">
                                <i class="fas fa-upload me-2"></i>Upload Profile Picture
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <?php include_once __DIR__ . '/../../../includes/footer.php'; ?>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Password visibility toggle
            const toggleButtons = document.querySelectorAll('.toggle-password');
            toggleButtons.forEach(function(button) {
                button.addEventListener('click', function() {
                    const targetId = this.getAttribute('data-target');
                    const passwordInput = document.getElementById(targetId);
                    const icon = this.querySelector('i');
                    
                    if (passwordInput.type === 'password') {
                        passwordInput.type = 'text';
                        icon.classList.remove('fa-eye');
                        icon.classList.add('fa-eye-slash');
                    } else {
                        passwordInput.type = 'password';
                        icon.classList.remove('fa-eye-slash');
                        icon.classList.add('fa-eye');
                    }
                });
            });
            
            // Password strength indicator
            const newPassword = document.getElementById('new_password');
            const passwordStrength = document.getElementById('password-strength');
            const passwordFeedback = document.getElementById('password-feedback');
            
            if (newPassword) {
                newPassword.addEventListener('input', function() {
                    const password = this.value;
                    let strength = 0;
                    let feedback = '';
                    
                    if (password.length >= 8) {
                        strength += 25;
                        feedback += 'Minimum length met. ';
                    } else {
                        feedback += 'Password must be at least 8 characters. ';
                    }
                    
                    if (password.match(/[A-Z]/)) {
                        strength += 25;
                        feedback += 'Contains uppercase. ';
                    }
                    
                    if (password.match(/[0-9]/)) {
                        strength += 25;
                        feedback += 'Contains numbers. ';
                    }
                    
                    if (password.match(/[^A-Za-z0-9]/)) {
                        strength += 25;
                        feedback += 'Contains special characters. ';
                    }
                    
                    // Update strength bar
                    passwordStrength.style.width = strength + '%';
                    
                    if (strength <= 25) {
                        passwordStrength.style.backgroundColor = '#dc3545'; // Danger
                        feedback = 'Weak password. ' + feedback;
                    } else if (strength <= 50) {
                        passwordStrength.style.backgroundColor = '#ffc107'; // Warning
                        feedback = 'Fair password. ' + feedback;
                    } else if (strength <= 75) {
                        passwordStrength.style.backgroundColor = '#0dcaf0'; // Info
                        feedback = 'Good password. ' + feedback;
                    } else {
                        passwordStrength.style.backgroundColor = '#198754'; // Success
                        feedback = 'Strong password! ' + feedback;
                    }
                    
                    passwordFeedback.textContent = feedback;
                });
            }
            
            // Password match indicator
            const confirmPassword = document.getElementById('confirm_password');
            const passwordMatch = document.getElementById('password-match');
            
            if (confirmPassword && newPassword) {
                confirmPassword.addEventListener('input', function() {
                    if (this.value === newPassword.value) {
                        passwordMatch.textContent = 'Passwords match!';
                        passwordMatch.className = 'form-text text-success';
                        confirmPassword.setCustomValidity('');
                    } else {
                        passwordMatch.textContent = 'Passwords do not match!';
                        passwordMatch.className = 'form-text text-danger';
                        confirmPassword.setCustomValidity('Passwords do not match');
                    }
                });
                
                newPassword.addEventListener('input', function() {
                    if (confirmPassword.value) {
                        if (this.value === confirmPassword.value) {
                            passwordMatch.textContent = 'Passwords match!';
                            passwordMatch.className = 'form-text text-success';
                            confirmPassword.setCustomValidity('');
                        } else {
                            passwordMatch.textContent = 'Passwords do not match!';
                            passwordMatch.className = 'form-text text-danger';
                            confirmPassword.setCustomValidity('Passwords do not match');
                        }
                    }
                });
            }
            
            // Ghana Card formatter
            const ghanaCardInput = document.getElementById('ghana_card_number');
            if (ghanaCardInput) {
                ghanaCardInput.addEventListener('input', function(e) {
                    let value = e.target.value.replace(/[^A-Za-z0-9-]/g, '').toUpperCase();
                    
                    // Format as GHA-XXXXXXXXX-X
                    if (value.length > 3 && !value.includes('-')) {
                        value = value.slice(0, 3) + '-' + value.slice(3);
                    }
                    if (value.length > 13 && value.charAt(13) !== '-') {
                        value = value.slice(0, 13) + '-' + value.slice(13);
                    }
                    
                    e.target.value = value;
                });
            }
            
            // Avatar preview functionality
            const avatarInput = document.getElementById('avatar');
            const avatarPreview = document.getElementById('avatar-preview');
            
            if (avatarInput && avatarPreview) {
                avatarInput.addEventListener('change', function() {
                    if (this.files && this.files[0]) {
                        const reader = new FileReader();
                        
                        reader.onload = function(e) {
                            avatarPreview.src = e.target.result;
                        }
                        
                        reader.readAsDataURL(this.files[0]);
                    }
                });
            }
            
            // Activate tab based on URL hash
            const url = new URL(window.location.href);
            const hash = url.hash;
            
            if (hash === '#password') {
                const passwordTab = document.getElementById('password-tab');
                if (passwordTab) {
                    const tab = new bootstrap.Tab(passwordTab);
                    tab.show();
                }
            }
            
            // If there's an avatar error or success message, open the modal automatically
            <?php if (!empty($avatarError) || !empty($avatarSuccess)): ?>
            const avatarModal = new bootstrap.Modal(document.getElementById('avatarModal'));
            avatarModal.show();
            <?php endif; ?>
        });
    </script>
</body>
</html>