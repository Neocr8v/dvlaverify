<?php
// filepath: /Applications/XAMPP/xamppfiles/htdocs/dvlaregister/src/views/admin/create-admin-login.php
session_start();
require_once __DIR__ . '/../../../config/database.php';

// Check if user is logged in and is admin (superadmin check could be added here)
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../auth/login.php');
    exit;
}

// Initialize variables
$error = '';
$success = '';
$formData = [
    'username' => '',
    'full_name' => '',
    'email' => '',
    'ghana_card_number' => '',
    'phone_number' => '',
    'address' => ''
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $username = trim($_POST['username'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $generate_password = isset($_POST['generate_password']);
    $send_email = isset($_POST['send_email']);
    $ghana_card_number = trim($_POST['ghana_card_number'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');
    $address = trim($_POST['address'] ?? '');
    
    // Save form data for repopulating the form in case of error
    $formData = [
        'username' => $username,
        'full_name' => $full_name,
        'email' => $email,
        'ghana_card_number' => $ghana_card_number,
        'phone_number' => $phone_number,
        'address' => $address
    ];
    
    // Generate a password if requested
    if ($generate_password) {
        $password = generateSecurePassword();
    }
    
    // Validate form data
    if (empty($username)) {
        $error = 'Username is required';
    } elseif (empty($full_name)) {
        $error = 'Full name is required';
    } elseif (empty($email)) {
        $error = 'Email address is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address';
    } elseif (empty($password) && !$generate_password) {
        $error = 'Password is required or select "Generate Password"';
    } else {
        try {
            // Check if username already exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :username");
            $stmt->execute(['username' => $username]);
            if ($stmt->fetchColumn() > 0) {
                $error = 'Username already exists. Please choose another one.';
            }
            
            // Check if email already exists
            if (!$error) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = :email");
                $stmt->execute(['email' => $email]);
                if ($stmt->fetchColumn() > 0) {
                    $error = 'Email address is already registered. Please use another email.';
                }
            }
            
            // Check if Ghana Card is provided and already exists
            if (!$error && !empty($ghana_card_number)) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE ghana_card_number = :ghana_card_number");
                $stmt->execute(['ghana_card_number' => $ghana_card_number]);
                if ($stmt->fetchColumn() > 0) {
                    $error = 'Ghana Card number is already registered.';
                }
            }
            
            // If no errors, insert new admin user
            if (!$error) {
                // Hash the password
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                
                // Insert admin user data - role is set to 'admin'
                $stmt = $pdo->prepare("
                    INSERT INTO users (username, password, email, full_name, ghana_card_number, phone_number, address, role, created_at, updated_at)
                    VALUES (:username, :password, :email, :full_name, :ghana_card_number, :phone_number, :address, 'admin', NOW(), NOW())
                ");
                
                $stmt->execute([
                    'username' => $username,
                    'password' => $hashedPassword,
                    'email' => $email,
                    'full_name' => $full_name,
                    'ghana_card_number' => $ghana_card_number ?: null,
                    'phone_number' => $phone_number ?: null,
                    'address' => $address ?: null
                ]);
                
                $userId = $pdo->lastInsertId();
                
                // Send email with login credentials if requested
                if ($send_email) {
                    $emailSent = sendLoginCredentials($email, $full_name, $username, $password);
                    $emailMsg = $emailSent ? " and login details were sent to the admin's email" : " (Note: Could not send email with login details)";
                }
                
                // Set success message
                $success = "Admin account created successfully!" . ($emailMsg ?? '');
                if ($generate_password) {
                    $success .= "<br>Generated password: <strong>$password</strong> (make note of this)";
                }
                
                // Clear form data
                $formData = [
                    'username' => '',
                    'full_name' => '',
                    'email' => '',
                    'ghana_card_number' => '',
                    'phone_number' => '',
                    'address' => ''
                ];
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Function to generate a secure random password
function generateSecurePassword($length = 12) {
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%^&*()_+';
    $password = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $max)];
    }
    return $password;
}

// Function to send login credentials via email
function sendLoginCredentials($email, $name, $username, $password) {
    $subject = 'Your DVLA Admin Account Details';
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= 'From: DVLA Ghana <noreply@dvla.gov.gh>' . "\r\n";
    
    $message = "
    <html>
    <head>
        <title>Your DVLA Admin Account</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #0d6efd; color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; border: 1px solid #ddd; }
            .footer { font-size: 12px; color: #777; margin-top: 20px; text-align: center; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>DVLA Vehicle Registration System</h2>
            </div>
            <div class='content'>
                <p>Dear " . htmlspecialchars($name) . ",</p>
                <p>An <strong>administrator account</strong> has been created for you on the DVLA Vehicle Registration System. You can use this account to manage vehicle registrations, users, and all system functions.</p>
                <p><strong>Your admin login credentials:</strong></p>
                <p>Username: <strong>" . htmlspecialchars($username) . "</strong><br>
                Password: <strong>" . htmlspecialchars($password) . "</strong></p>
                <p>Please login at: <a href='http://localhost/dvlaregister/src/views/auth/login.php'>DVLA Vehicle Registration Portal</a></p>
                <p><strong>Security Note:</strong> For your security, please change your password after your first login. As an admin, you have elevated access to the system, so please keep your credentials secure.</p>
                <p>If you have any questions or need assistance, please contact IT support.</p>
                <p>Thank you,<br>DVLA Ghana</p>
            </div>
            <div class='footer'>
                <p>This is an automated message, please do not reply to this email.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    // Uncomment the line below when ready to actually send emails
    // return mail($email, $subject, $message, $headers);
    
    // For now, just return true for testing
    return true;
}

// Determine base URL dynamically for assets
$baseUrl = '';
$scriptDir = dirname(dirname(dirname(dirname($_SERVER['SCRIPT_NAME']))));
if ($scriptDir !== '/' && $scriptDir !== '\\') {
    $baseUrl = $scriptDir;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Admin Account | Admin Dashboard</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?= $baseUrl ?>/assets/css/style.css">
    <style>
        .card {
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            border-radius: 10px;
            overflow: hidden;
            margin: 0 auto;
        }
        .card-header {
            background-color: #8B0000; /* Dark red color for admin */
            color: white;
            font-weight: bold;
        }
        .form-label.required::after {
            content: "*";
            color: red;
            margin-left: 4px;
        }
        .password-strength {
            height: 5px;
            margin-top: 8px;
            border-radius: 2px;
            transition: all 0.3s ease;
        }
        .btn-toggle-password {
            border: none;
            background: none;
            color: #6c757d;
        }
        .btn-toggle-password:focus {
            outline: none;
            box-shadow: none;
        }
        .admin-badge {
            background-color: #8B0000;
            color: white;
            font-size: 12px;
            padding: 3px 8px;
            border-radius: 4px;
            display: inline-block;
            margin-left: 10px;
        }
        .btn-admin {
            background-color: #8B0000;
            border-color: #8B0000;
            color: white;
        }
        .btn-admin:hover {
            background-color: #6d0000;
            border-color: #6d0000;
            color: white;
        }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../../../includes/header.php'; ?>
    
    <div class="container my-4">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h4 class="mb-0">
                            <i class="fas fa-user-shield me-2"></i>Create Administrator Account
                            <span class="admin-badge">Admin Access</span>
                        </h4>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($error)): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($success)): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="fas fa-check-circle me-2"></i><?= $success ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>
                        
                        <div class="alert alert-warning mb-4">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Important:</strong> Creating an admin account provides full system access. Only create accounts for authorized personnel.
                        </div>
                        
                        <form method="post" action="<?= $_SERVER['PHP_SELF'] ?>" id="create-admin-form" novalidate>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="username" class="form-label required">Username</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-user-shield"></i></span>
                                            <input type="text" class="form-control" id="username" name="username" required value="<?= htmlspecialchars($formData['username']) ?>">
                                        </div>
                                        <div class="form-text">Create a unique username for the administrator.</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="full_name" class="form-label required">Full Name</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-id-card"></i></span>
                                            <input type="text" class="form-control" id="full_name" name="full_name" required value="<?= htmlspecialchars($formData['full_name']) ?>">
                                        </div>
                                        <div class="form-text">Enter the administrator's full name.</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="email" class="form-label required">Email Address</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                            <input type="email" class="form-control" id="email" name="email" required value="<?= htmlspecialchars($formData['email']) ?>">
                                        </div>
                                        <div class="form-text">System notifications and alerts will be sent to this email.</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="ghana_card_number" class="form-label">Ghana Card Number</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-id-badge"></i></span>
                                            <input type="text" class="form-control" id="ghana_card_number" name="ghana_card_number" value="<?= htmlspecialchars($formData['ghana_card_number']) ?>">
                                        </div>
                                        <div class="form-text">For identity verification purposes.</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="password" class="form-label">Password</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                            <input type="password" class="form-control" id="password" name="password">
                                            <button class="btn btn-toggle-password" type="button" id="toggle-password">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                        <div class="password-strength w-100 bg-light" id="password-strength"></div>
                                        <div class="form-text" id="password-feedback">Enter a strong password or use the auto-generate option below.</div>
                                    </div>
                                    
                                    <div class="mb-3 form-check">
                                        <input type="checkbox" class="form-check-input" id="generate_password" name="generate_password">
                                        <label class="form-check-label" for="generate_password">
                                            <i class="fas fa-magic me-1"></i>Auto-generate secure password
                                        </label>
                                    </div>
                                    
                                    <div class="mb-3 form-check">
                                        <input type="checkbox" class="form-check-input" id="send_email" name="send_email">
                                        <label class="form-check-label" for="send_email">
                                            <i class="fas fa-envelope me-1"></i>Send login details to administrator's email
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="phone_number" class="form-label">Phone Number</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-phone"></i></span>
                                            <input type="tel" class="form-control" id="phone_number" name="phone_number" value="<?= htmlspecialchars($formData['phone_number']) ?>">
                                        </div>
                                        <div class="form-text">For emergency contact and authentication.</div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="address" class="form-label">Address</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-building"></i></span>
                                            <input type="text" class="form-control" id="address" name="address" value="<?= htmlspecialchars($formData['address']) ?>">
                                        </div>
                                        <div class="form-text">Optional. Administrator's work address.</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="d-flex justify-content-between">
                                <a href="dashboard.php" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                                </a>
                                <button type="submit" class="btn btn-admin">
                                    <i class="fas fa-user-shield me-2"></i>Create Administrator Account
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>About Administrator Accounts</h5>
                    </div>
                    <div class="card-body">
                        <p>Administrator accounts have the following privileges:</p>
                        <ul>
                            <li>Full access to all system features</li>
                            <li>Ability to create and manage users</li>
                            <li>Access to sensitive data and operations</li>
                            <li>System configuration capabilities</li>
                            <li>Reporting and auditing functions</li>
                        </ul>
                        
                        <div class="alert alert-danger mb-0">
                            <i class="fas fa-shield-alt me-2"></i>
                            <strong>Security Notice:</strong> Administrator accounts should only be created for authorized personnel with proper training. All admin actions are logged for security and audit purposes.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php include_once __DIR__ . '/../../../includes/footer.php'; ?>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const passwordInput = document.getElementById('password');
            const passwordStrength = document.getElementById('password-strength');
            const passwordFeedback = document.getElementById('password-feedback');
            const togglePasswordBtn = document.getElementById('toggle-password');
            const generatePasswordCheckbox = document.getElementById('generate_password');
            
            // Ghana Card formatter
            document.getElementById('ghana_card_number').addEventListener('input', function(e) {
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
            
            // Password strength checker - more strict for admin accounts
            passwordInput.addEventListener('input', function() {
                if (generatePasswordCheckbox.checked) return;
                
                const password = this.value;
                let strength = 0;
                let feedback = '';
                
                if (password.length >= 10) { // Increased from 8 to 10 for admin
                    strength += 20;
                    feedback = 'Minimum length met. ';
                } else {
                    feedback = 'Password must be at least 10 characters. ';
                }
                
                if (password.match(/[A-Z]/)) {
                    strength += 20;
                    feedback += 'Contains uppercase. ';
                }
                
                if (password.match(/[a-z]/)) {
                    strength += 20;
                    feedback += 'Contains lowercase. ';
                }
                
                if (password.match(/[0-9]/)) {
                    strength += 20;
                    feedback += 'Contains numbers. ';
                }
                
                if (password.match(/[^A-Za-z0-9]/)) {
                    strength += 20;
                    feedback += 'Contains special characters. ';
                }
                
                // Update strength bar
                passwordStrength.style.width = strength + '%';
                
                if (strength <= 40) {
                    passwordStrength.style.backgroundColor = '#dc3545'; // red
                    feedback = 'Weak password. ' + feedback;
                } else if (strength <= 60) {
                    passwordStrength.style.backgroundColor = '#ffc107'; // yellow
                    feedback = 'Moderate password. ' + feedback;
                } else if (strength <= 80) {
                    passwordStrength.style.backgroundColor = '#0dcaf0'; // light blue
                    feedback = 'Good password. ' + feedback;
                } else {
                    passwordStrength.style.backgroundColor = '#198754'; // green
                    feedback = 'Strong password! ' + feedback;
                }
                
                passwordFeedback.textContent = feedback;
            });
            
            // Toggle password visibility
            togglePasswordBtn.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                
                // Toggle eye icon
                const icon = this.querySelector('i');
                if (type === 'text') {
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                } else {
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                }
            });
            
            // Handle generate password checkbox
            generatePasswordCheckbox.addEventListener('change', function() {
                if (this.checked) {
                    passwordInput.value = '';
                    passwordInput.disabled = true;
                    passwordStrength.style.width = '0%';
                    passwordFeedback.textContent = 'A secure password will be generated automatically.';
                } else {
                    passwordInput.disabled = false;
                    passwordFeedback.textContent = 'Enter a password manually.';
                }
            });
            
            // Form validation before submit - stricter for admin accounts
            document.getElementById('create-admin-form').addEventListener('submit', function(event) {
                const username = document.getElementById('username').value.trim();
                const fullName = document.getElementById('full_name').value.trim();
                const email = document.getElementById('email').value.trim();
                const password = document.getElementById('password').value;
                const generatePassword = document.getElementById('generate_password').checked;
                
                if (!username) {
                    event.preventDefault();
                    alert('Username is required.');
                    return;
                }
                
                if (!fullName) {
                    event.preventDefault();
                    alert('Full name is required.');
                    return;
                }
                
                if (!email) {
                    event.preventDefault();
                    alert('Email address is required.');
                    return;
                }
                
                if (!generatePassword && (!password || password.length < 10)) {
                    event.preventDefault();
                    alert('Please enter a strong password with at least 10 characters or select "Auto-generate secure password".');
                    return;
                }
            });
            
            // Admin username suggestion from full name
            document.getElementById('full_name').addEventListener('blur', function() {
                const usernameField = document.getElementById('username');
                if (usernameField.value.trim() === '') {
                    const fullName = this.value.trim().toLowerCase();
                    if (fullName) {
                        const names = fullName.split(' ');
                        let username = 'admin_';
                        
                        if (names.length >= 2) {
                            // Use first name + first letter of last name for admin
                            username += names[0] + names[names.length - 1].substring(0, 1);
                        } else {
                            username += names[0];
                        }
                        
                        usernameField.value = username;
                    }
                }
            });
        });
    </script>
</body>
</html>