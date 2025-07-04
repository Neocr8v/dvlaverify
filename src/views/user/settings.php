<?php
// filepath: /Applications/XAMPP/xamppfiles/htdocs/dvlaregister/src/views/user/settings.php
session_start();
require_once __DIR__ . '/../../../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'user') {
    header('Location: ../../auth/login.php');
    exit;
}

// Initialize variables
$userId = $_SESSION['user_id'];
$success = '';
$error = '';
$userData = [];
$userPreferences = [];

// Fetch user data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
    $stmt->execute(['id' => $userId]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$userData) {
        header('Location: ../../auth/logout.php');
        exit;
    }
    
    // Check if user_preferences table exists
    $stmt = $pdo->prepare("SHOW TABLES LIKE 'user_preferences'");
    $stmt->execute();
    
    if ($stmt->rowCount() == 0) {
        // Create user preferences table
        $pdo->exec("
            CREATE TABLE user_preferences (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                notification_email BOOLEAN DEFAULT 1,
                notification_sms BOOLEAN DEFAULT 0,
                reminder_days INT DEFAULT 14,
                theme VARCHAR(20) DEFAULT 'light',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");
    }
    
    // Get user preferences or create default
    $stmt = $pdo->prepare("SELECT * FROM user_preferences WHERE user_id = :user_id");
    $stmt->execute(['user_id' => $userId]);
    $userPreferences = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$userPreferences) {
        // Insert default preferences
        $stmt = $pdo->prepare("
            INSERT INTO user_preferences (user_id, notification_email, notification_sms, reminder_days, theme)
            VALUES (:user_id, 1, 0, 14, 'light')
        ");
        $stmt->execute(['user_id' => $userId]);
        
        // Fetch the newly created preferences
        $stmt = $pdo->prepare("SELECT * FROM user_preferences WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $userId]);
        $userPreferences = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_preferences'])) {
    $notification_email = isset($_POST['notification_email']) ? 1 : 0;
    $notification_sms = isset($_POST['notification_sms']) ? 1 : 0;
    $reminder_days = intval($_POST['reminder_days'] ?? 14);
    $theme = $_POST['theme'] ?? 'light';
    
    // Validate inputs
    if ($reminder_days < 1 || $reminder_days > 90) {
        $error = "Reminder days must be between 1 and 90";
    } else {
        try {
            // Update preferences
            $stmt = $pdo->prepare("
                UPDATE user_preferences 
                SET notification_email = :notification_email,
                    notification_sms = :notification_sms,
                    reminder_days = :reminder_days,
                    theme = :theme,
                    updated_at = NOW()
                WHERE user_id = :user_id
            ");
            
            $stmt->execute([
                'notification_email' => $notification_email,
                'notification_sms' => $notification_sms,
                'reminder_days' => $reminder_days,
                'theme' => $theme,
                'user_id' => $userId
            ]);
            
            $success = "Settings updated successfully";
            
            // Save theme preference to session
            $_SESSION['user_theme'] = $theme;
            
            // Refresh preferences
            $stmt = $pdo->prepare("SELECT * FROM user_preferences WHERE user_id = :user_id");
            $stmt->execute(['user_id' => $userId]);
            $userPreferences = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $error = "Error updating settings: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Settings | Vehicle Registration System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="/assets/css/style.css">
    <?php if (isset($userPreferences['theme']) && $userPreferences['theme'] === 'dark'): ?>
    <link rel="stylesheet" href="/assets/css/dark-theme.css">
    <?php endif; ?>
    <style>
        .settings-header {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 5px solid #0d6efd;
        }
        
        .settings-card {
            border-radius: 10px;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            margin-bottom: 20px;
        }
        
        .theme-option {
            display: inline-block;
            width: 100px;
            text-align: center;
            margin-right: 15px;
            cursor: pointer;
        }
        
        .theme-preview {
            height: 60px;
            border-radius: 8px;
            margin-bottom: 8px;
            border: 2px solid #dee2e6;
        }
        
        .theme-preview.light {
            background: linear-gradient(to bottom, #f8f9fa, #ffffff);
        }
        
        .theme-preview.dark {
            background: linear-gradient(to bottom, #343a40, #212529);
            border-color: #495057;
        }
        
        .theme-option.selected .theme-preview {
            border-color: #0d6efd;
            border-width: 3px;
        }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../../../includes/header.php'; ?>
    
    <div class="container my-4">
        <!-- Breadcrumb -->
        <nav aria-label="breadcrumb" class="mb-4">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="../user/dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item active">Settings</li>
            </ol>
        </nav>
        
        <!-- Settings Header -->
        <div class="settings-header d-flex align-items-center">
            <div class="me-4">
                <i class="fas fa-user-cog fa-3x text-primary"></i>
            </div>
            <div>
                <h2 class="mb-1">User Settings</h2>
                <p class="mb-0 text-muted">Customize your preferences</p>
            </div>
        </div>
        
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
        
        <div class="row">
            <div class="col-lg-3 mb-4">
                <!-- User Info Sidebar -->
                <div class="card settings-card">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="fas fa-user me-2"></i>User Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <?php if (!empty($userData['avatar'])): ?>
                                <img src="/dvlaregister/<?= htmlspecialchars($userData['avatar']) ?>" alt="Profile Picture" class="rounded-circle img-thumbnail" style="width: 100px; height: 100px; object-fit: cover;">
                            <?php else: ?>
                                <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center mx-auto" style="width: 100px; height: 100px; font-size: 2.5rem;">
                                    <?= strtoupper(substr($userData['full_name'] ?? 'U', 0, 1)) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <h5 class="text-center mb-3"><?= htmlspecialchars($userData['full_name']) ?></h5>
                        
                        <div class="mb-2">
                            <small class="text-muted d-block">Username:</small>
                            <span><?= htmlspecialchars($userData['username']) ?></span>
                        </div>
                        
                        <div class="mb-2">
                            <small class="text-muted d-block">Email:</small>
                            <span><?= htmlspecialchars($userData['email']) ?></span>
                        </div>
                        
                        <div>
                            <small class="text-muted d-block">Member Since:</small>
                            <span><?= date('M d, Y', strtotime($userData['created_at'])) ?></span>
                        </div>
                        
                        <hr>
                        
                        <div class="d-grid gap-2">
                            <a href="../user/profile.php" class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-user-edit me-2"></i>Edit Profile
                            </a>
                            <a href="../user/profile.php" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-key me-2"></i>Change Password
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Links -->
                <div class="card settings-card">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="fas fa-link me-2"></i>Quick Links</h5>
                    </div>
                    <div class="list-group list-group-flush">
                        <a href="../user/dashboard.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                        </a>
                        <a href="../user/my-vehicles.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-car me-2"></i>My Vehicles
                        </a>
                        <a href="/src/views/auth/logout.php" class="list-group-item list-group-item-action text-danger">
                            <i class="fas fa-sign-out-alt me-2"></i>Logout
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-9">
                <!-- User Preferences -->
                <form method="post" action="">
                    <!-- Notification Settings -->
                    <div class="card settings-card">
                        <div class="card-header bg-light">
                            <h5 class="mb-0"><i class="fas fa-bell me-2"></i>Notification Settings</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" 
                                           id="notification_email" name="notification_email" 
                                           value="1" <?= ($userPreferences['notification_email'] ?? 1) == 1 ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="notification_email">
                                        <strong>Email Notifications</strong>
                                    </label>
                                </div>
                                <div class="form-text ms-4">Receive notifications about your vehicle registration, renewals, and important updates via email.</div>
                            </div>
                            
                            <div class="mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" 
                                           id="notification_sms" name="notification_sms" 
                                           value="1" <?= ($userPreferences['notification_sms'] ?? 0) == 1 ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="notification_sms">
                                        <strong>SMS Notifications</strong>
                                    </label>
                                </div>
                                <div class="form-text ms-4">Receive text message alerts about important events and deadlines.</div>
                            </div>
                            
                            <div class="mb-0">
                                <label for="reminder_days" class="form-label"><strong>Registration Renewal Reminder</strong></label>
                                <div class="input-group mb-2">
                                    <input type="number" class="form-control" id="reminder_days" name="reminder_days" 
                                           min="1" max="90" value="<?= $userPreferences['reminder_days'] ?? 14 ?>">
                                    <span class="input-group-text">days before expiry</span>
                                </div>
                                <div class="form-text">Choose how many days before your vehicle registration expires you want to receive a reminder.</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Display Settings -->
                    <div class="card settings-card mt-4">
                        <div class="card-header bg-light">
                            <h5 class="mb-0"><i class="fas fa-palette me-2"></i>Display Settings</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-0">
                                <label class="form-label"><strong>Theme</strong></label>
                                <div class="d-flex align-items-center mt-2">
                                    <div class="theme-option <?= ($userPreferences['theme'] ?? 'light') === 'light' ? 'selected' : '' ?>" data-theme="light">
                                        <div class="theme-preview light"></div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="theme" id="theme_light" 
                                                   value="light" <?= ($userPreferences['theme'] ?? 'light') === 'light' ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="theme_light">Light</label>
                                        </div>
                                    </div>
                                    
                                    <div class="theme-option <?= ($userPreferences['theme'] ?? 'light') === 'dark' ? 'selected' : '' ?>" data-theme="dark">
                                        <div class="theme-preview dark"></div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="theme" id="theme_dark" 
                                                   value="dark" <?= ($userPreferences['theme'] ?? 'light') === 'dark' ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="theme_dark">Dark</label>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-text mt-2">Choose your preferred visual theme for the application.</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Data Privacy Settings -->
                    <div class="card settings-card mt-4">
                        <div class="card-header bg-light">
                            <h5 class="mb-0"><i class="fas fa-shield-alt me-2"></i>Data & Privacy</h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small mb-3">
                                Your data is important to us. We only collect information that is necessary for the vehicle registration process
                                and to provide you with the best service possible.
                            </p>
                            
                            <div class="alert alert-info" role="alert">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Data Usage Notice:</strong> Your vehicle information is shared with relevant government agencies as required by law.
                                Your personal information is not sold to any third parties.
                            </div>
                            
                            <div class="d-flex justify-content-between align-items-center mt-3">
                                <a href="#" class="link-secondary small" data-bs-toggle="modal" data-bs-target="#privacyPolicyModal">
                                    <i class="fas fa-file-alt me-1"></i> Privacy Policy
                                </a>
                                
                                <a href="#" class="link-secondary small" data-bs-toggle="modal" data-bs-target="#dataExportModal">
                                    <i class="fas fa-download me-1"></i> Export My Data
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-grid gap-2 mt-4">
                        <button type="submit" name="update_preferences" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save Settings
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Privacy Policy Modal -->
    <div class="modal fade" id="privacyPolicyModal" tabindex="-1" aria-labelledby="privacyPolicyModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="privacyPolicyModalLabel">Privacy Policy</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <h5>Vehicle Registration System Privacy Policy</h5>
                    <p>Last updated: May 30, 2025</p>
                    
                    <h6>1. Information We Collect</h6>
                    <p>We collect information you provide directly to us, including personal information such as your name, email address, phone number, and vehicle information when you register for an account or register your vehicle.</p>
                    
                    <h6>2. How We Use Your Information</h6>
                    <p>We use the information we collect to provide, maintain, and improve our services, including to process vehicle registrations, send notifications about expiration dates, and comply with legal requirements.</p>
                    
                    <h6>3. Data Sharing</h6>
                    <p>We may share your information with government agencies as required by law for vehicle registration purposes. We do not sell your personal information to third parties.</p>
                    
                    <h6>4. Data Security</h6>
                    <p>We implement measures designed to protect your information from unauthorized access, loss, misuse, or alteration.</p>
                    
                    <h6>5. Your Rights</h6>
                    <p>You have the right to access, correct, or delete your personal information. You can manage your notification preferences through your account settings.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Data Export Modal -->
    <div class="modal fade" id="dataExportModal" tabindex="-1" aria-labelledby="dataExportModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="dataExportModalLabel">Export Your Data</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>You can request a copy of your personal data and vehicle information. The export will be prepared and sent to your registered email address.</p>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        This process may take up to 24 hours to complete.
                    </div>
                    
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" value="" id="confirmExport">
                        <label class="form-check-label" for="confirmExport">
                            I confirm that I want to export a copy of my data
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="btnRequestExport" disabled>
                        <i class="fas fa-download me-2"></i>Request Data Export
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <?php include_once __DIR__ . '/../../../includes/footer.php'; ?>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Theme selection
            const themeOptions = document.querySelectorAll('.theme-option');
            themeOptions.forEach(option => {
                option.addEventListener('click', function() {
                    const theme = this.dataset.theme;
                    const radioInput = this.querySelector('input[type="radio"]');
                    
                    // Update radio button
                    radioInput.checked = true;
                    
                    // Update visual selection
                    document.querySelectorAll('.theme-option').forEach(el => {
                        el.classList.remove('selected');
                    });
                    this.classList.add('selected');
                });
            });
            
            // Data export confirmation
            const confirmExportCheckbox = document.getElementById('confirmExport');
            const requestExportBtn = document.getElementById('btnRequestExport');
            
            if (confirmExportCheckbox && requestExportBtn) {
                confirmExportCheckbox.addEventListener('change', function() {
                    requestExportBtn.disabled = !this.checked;
                });
                
                requestExportBtn.addEventListener('click', function() {
                    // Here you would normally send an AJAX request to the server
                    // For this demo, we'll just show a success message
                    
                    // Disable the button and show loading state
                    this.disabled = true;
                    this.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Processing...';
                    
                    // Simulate processing delay
                    setTimeout(() => {
                        // Show success message
                        const modalBody = document.querySelector('#dataExportModal .modal-body');
                        modalBody.innerHTML = `
                            <div class="text-center py-4">
                                <i class="fas fa-check-circle text-success" style="font-size: 3rem;"></i>
                                <h5 class="mt-3">Data Export Request Submitted</h5>
                                <p class="mb-0">We've received your request. Your data will be sent to your email address within 24 hours.</p>
                            </div>
                        `;
                        
                        // Update footer buttons
                        document.querySelector('#dataExportModal .modal-footer').innerHTML = `
                            <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
                        `;
                    }, 1500);
                });
            }
        });
    </script>
    <?php include_once __DIR__ . '/../../../includes/footer.php'; ?>
</body>
</html>