<?php
// Start session and include database connection
session_start();
require_once __DIR__ . '/../../../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

// Get user data
$userId = $_SESSION['user_id'];
$username = $_SESSION['username'];
$role = $_SESSION['role'];
$success_message = '';
$error_message = '';

// Check for success or error messages
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// Fetch user details
try {
    $userStmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
    $userStmt->execute(['id' => $userId]);
    $user = $userStmt->fetch();
    
    if (!$user) {
        $_SESSION['error'] = 'User not found';
        header('Location: ../auth/login.php');
        exit;
    }
    
    // Fetch vehicle owner data associated with this user
    $ownerStmt = $pdo->prepare("
        SELECT vo.* 
        FROM vehicle_owners vo
        WHERE 
            (LOWER(vo.name) = LOWER(:full_name))
            OR (vo.email IS NOT NULL AND LOWER(vo.email) = LOWER(:email))
        LIMIT 1
    ");
    
    $ownerStmt->execute([
        'full_name' => $user['full_name'],
        'email' => $user['email'] ?? ''
    ]);
    
    $ownerData = $ownerStmt->fetch();
    
    // Count the number of vehicles owned
    $vehicleCount = 0;
    if ($ownerData) {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM vehicles WHERE owner_id = :owner_id");
        $countStmt->execute(['owner_id' => $ownerData['id']]);
        $vehicleCount = $countStmt->fetchColumn();
    }
    
} catch (PDOException $e) {
    $error_message = "Database error: " . $e->getMessage();
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validate input
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error_message = "All password fields are required";
    } elseif ($new_password !== $confirm_password) {
        $error_message = "New passwords do not match";
    } elseif (strlen($new_password) < 8) {
        $error_message = "Password must be at least 8 characters long";
    } else {
        try {
            // Verify current password
            if (password_verify($current_password, $user['password'])) {
                // Update password
                $hashedPassword = password_hash($new_password, PASSWORD_DEFAULT);
                $updateStmt = $pdo->prepare("UPDATE users SET password = :password WHERE id = :id");
                $updateStmt->execute([
                    'password' => $hashedPassword,
                    'id' => $userId
                ]);
                
                $success_message = "Password updated successfully";
            } else {
                $error_message = "Current password is incorrect";
            }
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Vehicle Registration System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .page-header {
            background-color: #fff;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            margin-bottom: 1.5rem;
            padding: 1.5rem 0;
        }
        .user-welcome {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .profile-card {
            border-radius: 10px;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            overflow: hidden;
        }
        .profile-header {
            background-color: #0d6efd;
            color: white;
            padding: 20px;
        }
        .profile-image-container {
            position: relative;
            width: 150px;
            height: 150px;
            margin: 0 auto 20px;
        }
        .profile-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
            border: 5px solid #fff;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }
        .profile-image-placeholder {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background-color: #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 4rem;
            color: #adb5bd;
            border: 5px solid #fff;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }
        .profile-stats {
            display: flex;
            margin: 1.5rem 0;
            text-align: center;
        }
        .stat-item {
            flex: 1;
            padding: 10px;
        }
        .stat-value {
            font-size: 1.5rem;
            font-weight: 600;
            display: block;
            margin-bottom: 5px;
        }
        .stat-label {
            color: #6c757d;
            font-size: 0.875rem;
        }
        .profile-details {
            padding: 1.5rem;
        }
        .detail-item {
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e9ecef;
        }
        .detail-item:last-child {
            border-bottom: none;
        }
        .detail-label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 0.25rem;
        }
        .detail-value {
            color: #6c757d;
        }
        .form-section {
            margin-bottom: 2rem;
        }
        .form-section-title {
            font-size: 1.25rem;
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #dee2e6;
        }
        .tab-content {
            padding-top: 1.5rem;
        }
    </style>
</head>
<body>
    <!-- Header -->
  <?php include_once __DIR__ . '/../../../includes/header.php'; ?>
    
    <div class="container mt-4">
        <div class="row">
            <!-- Sidebar/User Profile -->
            <div class="col-lg-3 mb-4">
                <div class="profile-card">
                    <div class="profile-header text-center">
                        <?php if (isset($ownerData) && !empty($ownerData['passport_photo_path'])): ?>
                            <img src="/<?= htmlspecialchars($ownerData['passport_photo_path']) ?>" alt="Profile Photo" class="rounded-circle mb-3" width="100" height="100" style="object-fit: cover;">
                        <?php else: ?>
                            <div class="mb-3 rounded-circle bg-light d-flex align-items-center justify-content-center mx-auto" style="width: 100px; height: 100px;">
                                <i class="fas fa-user text-secondary" style="font-size: 2.5rem;"></i>
                            </div>
                        <?php endif; ?>
                        <h4><?= htmlspecialchars($user['full_name'] ?? $username) ?></h4>
                        <p class="mb-0"><?= htmlspecialchars($user['email'] ?? '') ?></p>
                    </div>
                    <div class="list-group list-group-flush">
                        <a href="dashboard.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                        </a>
                        <a href="my-vehicles.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-car me-2"></i>My Vehicles
                        </a>
                        <a href="profile.php" class="list-group-item list-group-item-action active">
                            <i class="fas fa-user-circle me-2"></i>My Profile
                        </a>
                        <a href="../auth/logout.php" class="list-group-item list-group-item-action text-danger">
                            <i class="fas fa-sign-out-alt me-2"></i>Logout
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="col-lg-9">
                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success_message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error_message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="text-center mb-4">
                            <div class="profile-image-container">
                                <?php if (isset($ownerData) && !empty($ownerData['passport_photo_path'])): ?>
                                    <img src="/<?= htmlspecialchars($ownerData['passport_photo_path']) ?>" alt="Profile Photo" class="profile-image">
                                <?php else: ?>
                                    <div class="profile-image-placeholder">
                                        <i class="fas fa-user"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <h3 class="mb-1"><?= htmlspecialchars($user['full_name'] ?? $username) ?></h3>
                            <p class="text-muted mb-3"><?= htmlspecialchars($role === 'admin' ? 'Administrator' : 'Registered User') ?></p>
                            
                            <?php if (isset($ownerData)): ?>
                                <div class="badge bg-primary px-3 py-2">
                                    <i class="fas fa-id-card me-1"></i> Verified Vehicle Owner
                                </div>
                            <?php else: ?>
                                <div class="badge bg-secondary px-3 py-2">
                                    <i class="fas fa-user me-1"></i> Account Not Linked to Vehicle Owner
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Stats -->
                        <div class="profile-stats">
                            <div class="stat-item">
                                <span class="stat-value"><?= $vehicleCount ?></span>
                                <span class="stat-label">Registered Vehicles</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-value"><?= date('d M Y', strtotime($user['created_at'])) ?></span>
                                <span class="stat-label">Member Since</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-value"><?= date('d M Y', strtotime($user['updated_at'] ?? $user['created_at'])) ?></span>
                                <span class="stat-label">Last Updated</span>
                            </div>
                        </div>
                        
                        <!-- Profile Tabs -->
                        <ul class="nav nav-tabs" id="profileTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="personal-tab" data-bs-toggle="tab" data-bs-target="#personal" type="button" role="tab" aria-controls="personal" aria-selected="true">
                                    <i class="fas fa-user me-2"></i>Personal Info
                                </button>
                            </li>
                            <?php if (isset($ownerData)): ?>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="owner-tab" data-bs-toggle="tab" data-bs-target="#owner" type="button" role="tab" aria-controls="owner" aria-selected="false">
                                    <i class="fas fa-id-card me-2"></i>Owner Details
                                </button>
                            </li>
                            <?php endif; ?>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="security-tab" data-bs-toggle="tab" data-bs-target="#security" type="button" role="tab" aria-controls="security" aria-selected="false">
                                    <i class="fas fa-lock me-2"></i>Security
                                </button>
                            </li>
                        </ul>
                        
                        <div class="tab-content" id="profileTabsContent">
                            <!-- Personal Information Tab -->
                            <div class="tab-pane fade show active" id="personal" role="tabpanel" aria-labelledby="personal-tab">
                                <div class="row mb-4">
                                    <div class="col-md-4 mb-3">
                                        <div class="detail-label">Full Name</div>
                                        <div class="detail-value"><?= htmlspecialchars($user['full_name'] ?? 'Not specified') ?></div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <div class="detail-label">Username</div>
                                        <div class="detail-value"><?= htmlspecialchars($user['username']) ?></div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <div class="detail-label">Email</div>
                                        <div class="detail-value"><?= htmlspecialchars($user['email'] ?? 'Not specified') ?></div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <div class="detail-label">Phone</div>
                                        <div class="detail-value"><?= htmlspecialchars($user['phone'] ?? 'Not specified') ?></div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <div class="detail-label">Role</div>
                                        <div class="detail-value"><?= htmlspecialchars($role === 'admin' ? 'Administrator' : 'Registered User') ?></div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <div class="detail-label">Account Created</div>
                                        <div class="detail-value"><?= date('d M Y', strtotime($user['created_at'])) ?></div>
                                    </div>
                                </div>
                                
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>
                                    To update your personal information, please visit a DVLA office with your Ghana Card and valid identification.
                                </div>
                            </div>
                            
                            <!-- Owner Details Tab -->
                            <?php if (isset($ownerData)): ?>
                            <div class="tab-pane fade" id="owner" role="tabpanel" aria-labelledby="owner-tab">
                                <div class="row mb-4">
                                    <div class="col-md-6 mb-3">
                                        <div class="detail-label">Full Name</div>
                                        <div class="detail-value"><?= htmlspecialchars($ownerData['name']) ?></div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <div class="detail-label">Ghana Card Number</div>
                                        <div class="detail-value"><?= htmlspecialchars($ownerData['ghana_card_number']) ?></div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <div class="detail-label">Phone Number</div>
                                        <div class="detail-value"><?= htmlspecialchars($ownerData['phone']) ?></div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <div class="detail-label">Email</div>
                                        <div class="detail-value"><?= htmlspecialchars($ownerData['email'] ?? 'Not specified') ?></div>
                                    </div>
                                    <?php if (!empty($ownerData['date_of_birth'])): ?>
                                    <div class="col-md-6 mb-3">
                                        <div class="detail-label">Date of Birth</div>
                                        <div class="detail-value"><?= date('d M Y', strtotime($ownerData['date_of_birth'])) ?></div>
                                    </div>
                                    <?php endif; ?>
                                    <div class="col-md-6 mb-3">
                                        <div class="detail-label">Address</div>
                                        <div class="detail-value"><?= htmlspecialchars($ownerData['address']) ?></div>
                                    </div>
                                    <?php if (!empty($ownerData['ghana_card_image_path'])): ?>
                                    <div class="col-12 mb-3">
                                        <div class="detail-label">Ghana Card Image</div>
                                        <div class="detail-value">
                                            <a href="/dvlaregister/<?= htmlspecialchars($ownerData['ghana_card_image_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary mt-2">
                                                <i class="fas fa-id-card me-1"></i> View Ghana Card
                                            </a>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>
                                    To update your owner information, please visit a DVLA office with your Ghana Card.
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Security Tab -->
                            <div class="tab-pane fade" id="security" role="tabpanel" aria-labelledby="security-tab">
                                <form action="profile.php" method="post" class="mb-4">
                                    <div class="form-section">
                                        <h5 class="form-section-title">Change Password</h5>
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label for="current_password" class="form-label">Current Password</label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                                    <input type="password" class="form-control" id="current_password" name="current_password" required>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label for="new_password" class="form-label">New Password</label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="fas fa-key"></i></span>
                                                    <input type="password" class="form-control" id="new_password" name="new_password" required>
                                                </div>
                                                <div class="form-text">Password must be at least 8 characters long.</div>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label for="confirm_password" class="form-label">Confirm New Password</label>
                                                <div class="input-group">
                                                    <span class="input-group-text"><i class="fas fa-key"></i></span>
                                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                                </div>
                                            </div>
                                        </div>
                                        <button type="submit" name="update_password" class="btn btn-primary">
                                            <i class="fas fa-save me-2"></i>Update Password
                                        </button>
                                    </div>
                                </form>
                                
                                <div class="alert alert-warning">
                                    <i class="fas fa-shield-alt me-2"></i>
                                    <strong>Security Tips:</strong>
                                    <ul class="mb-0 mt-2">
                                        <li>Use a strong, unique password for your account</li>
                                        <li>Don't share your login credentials with others</li>
                                        <li>Change your password regularly</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if (!isset($ownerData)): ?>
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title"><i class="fas fa-link me-2"></i>Link Your Account</h5>
                        <p class="card-text">Your user account is not yet linked to a vehicle owner record. To link your account and view your registered vehicles, please visit a DVLA office with your Ghana Card.</p>
                        <div class="alert alert-info mb-0">
                            <i class="fas fa-info-circle me-2"></i>
                            Once linked, you'll be able to view all vehicles registered under your name.
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <?php include_once __DIR__ . '/../../../includes/footer.php'; ?>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>