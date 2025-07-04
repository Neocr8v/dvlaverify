<?php

// Security Headers - Set early to prevent any output before headers
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

// HTTPS enforcement (uncomment in production)
// header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');

// Content Security Policy
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data:; font-src 'self' https://cdnjs.cloudflare.com; connect-src 'self';");

// Secure session configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1); // Set to 1 in production with HTTPS
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_lifetime', 0); // Session cookies only

// Start session with regeneration
session_start();

// Include required files
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/security.php';

// Initialize security handler
$security = new SecurityHandler($pdo);

// Initialize variables
$username = '';
$role = 'user';
$errors = [];
$security_token = '';

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Check for existing valid session
if (isset($_SESSION['user_id']) && isset($_SESSION['username'])) {
    $redirect_url = ($_SESSION['role'] === 'admin') ? 
                   '../../views/admin/dashboard.php' : 
                   '../../views/user/dashboard.php';
    header("Location: $redirect_url");
    exit;
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $start_time = microtime(true);
    
    // CSRF Protection
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $security->logSecurityEvent('CSRF_ATTACK_ATTEMPT', $_SERVER['REMOTE_ADDR'], 'Invalid CSRF token');
        $errors[] = 'Security validation failed. Please try again.';
    } else {
        // Get and sanitize form data
        $username = filter_var(trim($_POST['username'] ?? ''), FILTER_SANITIZE_STRING);
        $password = $_POST['password'] ?? '';
        $role = in_array($_POST['role'] ?? '', ['user', 'admin']) ? $_POST['role'] : 'user';
        $remember = isset($_POST['remember']);
        $client_ip = $_SERVER['REMOTE_ADDR'];
        
        // Input validation
        if (empty($username)) {
            $errors[] = 'Username is required';
        } elseif (strlen($username) > 50) {
            $errors[] = 'Username is too long';
        } elseif (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
            $errors[] = 'Username contains invalid characters';
        }
        
        if (empty($password)) {
            $errors[] = 'Password is required';
        } elseif (strlen($password) > 255) {
            $errors[] = 'Password is too long';
        }
        
        // Check rate limiting and account lockout
        if (empty($errors)) {
            $rate_limit_check = $security->checkRateLimit($client_ip, $username);
            if (!$rate_limit_check['allowed']) {
                $errors[] = $rate_limit_check['message'];
                $security->logSecurityEvent('RATE_LIMIT_EXCEEDED', $client_ip, "Username: $username");
            }
        }
        
        // Process login if no errors
        if (empty($errors)) {
            try {
                // Use prepared statement with parameter binding
                $stmt = $pdo->prepare("SELECT id, username, password, full_name, role, account_locked, failed_attempts, last_failed_attempt FROM users WHERE username = :username LIMIT 1");
                $stmt->bindParam(':username', $username, PDO::PARAM_STR);
                $stmt->execute();
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Timing attack protection - always verify password even if user doesn't exist
                $dummy_hash = '$2y$10$dummy.hash.to.prevent.timing.attacks.abcdefghijklmnopqrstuvwxyz';
                $password_valid = false;
                
                if ($user) {
                    $password_valid = password_verify($password, $user['password']);
                } else {
                    // Perform dummy hash verification to maintain consistent timing
                    password_verify($password, $dummy_hash);
                }
                
                if ($user && $password_valid) {
                    // Check if account is locked
                    if ($user['account_locked']) {
                        $errors[] = 'Account is locked. Please contact administrator.';
                        $security->logSecurityEvent('LOCKED_ACCOUNT_LOGIN_ATTEMPT', $client_ip, "Username: $username");
                    }
                    // Role validation
                    elseif ($role === 'user' && $user['role'] === 'admin') {
                        $errors[] = 'Please use the admin login option for administrator accounts';
                        $security->recordFailedAttempt($client_ip, $username);
                    } 
                    elseif ($role === 'admin' && $user['role'] !== 'admin') {
                        $errors[] = 'You do not have administrator privileges';
                        $security->recordFailedAttempt($client_ip, $username);
                    } 
                    else {
                        // Successful login - reset failed attempts
                        $security->resetFailedAttempts($username);
                        
                        // Regenerate session ID to prevent session fixation
                        session_regenerate_id(true);
                        
                        // Set session variables
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['full_name'] = $user['full_name'] ?? $user['username'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['login_time'] = time();
                        $_SESSION['last_activity'] = time();
                        $_SESSION['ip_address'] = $client_ip;
                        
                        // Clear CSRF token after successful login
                        unset($_SESSION['csrf_token']);
                        
                        // Handle remember me functionality
                        if ($remember) {
                            $security->setRememberMeToken($user['id']);
                        }
                        
                        // Log successful login
                        $security->logSecurityEvent('SUCCESSFUL_LOGIN', $client_ip, "User: {$user['username']}, Role: {$user['role']}");
                        
                        // Secure redirect
                        $redirect_url = ($user['role'] === 'admin') ? 
                                       '../../views/admin/dashboard.php' : 
                                       '../../views/user/dashboard.php';
                        
                        header("Location: $redirect_url");
                        exit;
                    }
                } else {
                    $errors[] = 'Invalid username or password';
                    $security->recordFailedAttempt($client_ip, $username);
                    $security->logSecurityEvent('FAILED_LOGIN', $client_ip, "Username: $username");
                }
            } catch (PDOException $e) {
                // Log error securely without exposing details
                error_log("Database error in login: " . $e->getMessage());
                $errors[] = 'An error occurred. Please try again later.';
                $security->logSecurityEvent('DATABASE_ERROR', $client_ip, 'Login database error');
            }
        }
    }
    
    // Timing attack protection - ensure consistent response time
    $execution_time = microtime(true) - $start_time;
    if ($execution_time < 0.5) {
        usleep((0.5 - $execution_time) * 1000000);
    }
}
?>

<?php
// Handle logout messages
$logout_message = '';
$logout_class = 'info';

if (isset($_GET['logout'])) {
    switch ($_GET['logout']) {
        case 'timeout':
            $logout_message = 'Your session expired due to inactivity. Please login again.';
            $logout_class = 'warning';
            break;
        case 'security':
            $logout_message = 'You have been logged out for security reasons. Please login again.';
            $logout_class = 'danger';
            break;
        case 'manual':
            $logout_message = 'You have been successfully logged out.';
            $logout_class = 'success';
            break;
        case 'admin':
            $logout_message = 'You have been logged out by an administrator.';
            $logout_class = 'warning';
            break;
        default:
            $logout_message = 'You have been logged out.';
            $logout_class = 'info';
    }
}

// Add this HTML after the existing error display section in your login form:
if ($logout_message): ?>
    <div class="alert alert-<?= $logout_class ?> alert-dismissible fade show" role="alert">
        <i class="fas fa-sign-out-alt me-2"></i>
        <?= htmlspecialchars($logout_message, ENT_QUOTES, 'UTF-8') ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif;?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Login | Vehicle Registration System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-9ndCyUa2J5WV8SvpL2vhKXp+8uSSSy1SvNSBZZEKqzXKe0PjE1QqP7N0TsSjNzgv" crossorigin="anonymous">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous">
    <style>
        body {
            background: url('/assets/img/logindv.jpg') no-repeat center center;
            background-size: cover;
            min-height: 100vh;
        }
        .login-container {
            margin-top: 5vh;
        }
        .login-card {
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
            border: none;
            border-radius: 10px;
            overflow: hidden;
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.95);
        }
        .login-card .card-header {
            background-color: #0d6efd;
            color: white;
            text-align: center;
            padding: 1.5rem;
            border-bottom: none;
        }
        .login-logo {
            max-height: 60px;
            margin-bottom: 1rem;
        }
        .system-name {
            font-weight: bold;
        }
        .role-selector {
            border-bottom: 1px solid #eee;
            padding-bottom: 1rem;
        }
        .admin-role {
            color: #dc3545;
        }
        .login-footer {
            background: #f8f9fa;
            border-top: 1px solid #eee;
            text-align: center;
            padding: 1rem;
        }
        .security-info {
            font-size: 0.85rem;
            color: #6c757d;
            margin-top: 1rem;
        }
        .strength-meter {
            height: 5px;
            margin-top: 5px;
            background: #e9ecef;
            border-radius: 3px;
            overflow: hidden;
        }
        .strength-bar {
            height: 100%;
            transition: all 0.3s ease;
            border-radius: 3px;
        }
    </style>
    <!-- Security meta tags -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <meta name="robots" content="noindex, nofollow">
</head>
<body>
    <div class="container login-container">
        <div class="row justify-content-center">
            <div class="col-lg-5 col-md-7">
                <div class="card login-card">
                    <div class="card-header">
                        <img src="/assets/img/dvla.png" alt="DVLA Logo" class="login-logo">
                        <h4 class="mb-0"><span class="system-name">Vehicle Registration System</span></h4>
                        <p class="text-light mb-0">Ghana Driver & Vehicle Licensing Authority</p>
                        <div class="security-info text-light">
                            <i class="fas fa-shield-alt me-1"></i>
                            Secured with enterprise-grade protection
                        </div>
                    </div>
                    
                    <div class="card-body p-4">
                        <?php if (count($errors) > 0): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <ul class="mb-0">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                                    <?php endforeach; ?>
                                </ul>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" action="login.php" id="loginForm" autocomplete="off">
                            <!-- CSRF Token -->
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                            
                            <!-- Role Selection -->
                            <div class="role-selector row mb-4">
                                <div class="col-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="role" id="userRole" value="user" <?= $role !== 'admin' ? 'checked' : '' ?> required>
                                        <label class="form-check-label" for="userRole">
                                            <i class="fas fa-user me-1"></i>
                                            User Login
                                        </label>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="role" id="adminRole" value="admin" <?= $role === 'admin' ? 'checked' : '' ?> required>
                                        <label class="form-check-label admin-role" for="adminRole">
                                            <i class="fas fa-user-shield me-1"></i>
                                            Admin Login
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <label for="username" class="form-label">Username</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="fas fa-user"></i>
                                    </span>
                                    <input type="text" class="form-control" id="username" name="username" 
                                           value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>" 
                                           required maxlength="50" pattern="[a-zA-Z0-9_.-]+" 
                                           autocomplete="username" spellcheck="false">
                                </div>
                                <div class="form-text">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Only letters, numbers, dots, hyphens, and underscores allowed
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <label for="password" class="form-label">Password</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="fas fa-lock"></i>
                                    </span>
                                    <input type="password" class="form-control" id="password" name="password" 
                                           required maxlength="255" autocomplete="current-password" 
                                           onpaste="return false" ondrop="return false">
                                    <span class="input-group-text" style="cursor: pointer;" onclick="togglePassword()">
                                        <i class="fas fa-eye" id="toggleIcon"></i>
                                    </span>
                                </div>
                                <div class="strength-meter">
                                    <div class="strength-bar" id="strengthBar"></div>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="remember" name="remember">
                                    <label class="form-check-label" for="remember">
                                        <i class="fas fa-clock me-1"></i>
                                        Remember me for 30 days
                                    </label>
                                </div>
                                <div class="form-text">
                                    <i class="fas fa-shield-alt me-1"></i>
                                    Only use on your personal device
                                </div>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary py-2" id="loginBtn">
                                    <i class="fas fa-sign-in-alt me-2"></i> 
                                    <span id="loginBtnText">Secure Login</span>
                                    <div class="spinner-border spinner-border-sm ms-2 d-none" id="loginSpinner"></div>
                                </button>
                            </div>
                        </form>
                        
                        <div class="security-info mt-3 text-center">
                            <small>
                                <i class="fas fa-lock me-1"></i>
                                Your connection is secured with enterprise-grade encryption
                            </small>
                        </div>
                    </div>
                    
                    <div class="login-footer">
                        <p class="mb-0">
                            <a href="reset-password.php" class="text-decoration-none">
                                <i class="fas fa-key me-1"></i>
                                Forgot your password?
                            </a>
                        </p>
                    </div>
                </div>
                
                <div class="text-center mt-3">
                    <p class="text-muted">
                        <i class="fas fa-copyright me-1"></i>
                        <?= date('Y') ?> Ghana Driver & Vehicle Licensing Authority. All rights reserved.
                    </p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" integrity="sha384-geWF76RCwLtnZ8qwWowPQNguL3RmwHVBC9FhGdlKrxdiJJigb/j/68SIy3Te4Bkz" crossorigin="anonymous"></script>
    
    <script>
        // Security-enhanced JavaScript
        (function() {
            'use strict';
            
            // Disable right-click context menu on sensitive elements
            document.getElementById('password').addEventListener('contextmenu', function(e) {
                e.preventDefault();
            });
            
            // Password visibility toggle
            window.togglePassword = function() {
                const passwordField = document.getElementById('password');
                const toggleIcon = document.getElementById('toggleIcon');
                
                if (passwordField.type === 'password') {
                    passwordField.type = 'text';
                    toggleIcon.classList.remove('fa-eye');
                    toggleIcon.classList.add('fa-eye-slash');
                } else {
                    passwordField.type = 'password';
                    toggleIcon.classList.remove('fa-eye-slash');
                    toggleIcon.classList.add('fa-eye');
                }
            };
            
            // Password strength indicator
            document.getElementById('password').addEventListener('input', function() {
                const password = this.value;
                const strengthBar = document.getElementById('strengthBar');
                let strength = 0;
                
                if (password.length >= 8) strength++;
                if (/[a-z]/.test(password)) strength++;
                if (/[A-Z]/.test(password)) strength++;
                if (/[0-9]/.test(password)) strength++;
                if (/[^A-Za-z0-9]/.test(password)) strength++;
                
                const colors = ['#dc3545', '#fd7e14', '#ffc107', '#20c997', '#28a745'];
                const widths = ['20%', '40%', '60%', '80%', '100%'];
                
                if (password.length > 0) {
                    strengthBar.style.width = widths[strength - 1] || '20%';
                    strengthBar.style.backgroundColor = colors[strength - 1] || '#dc3545';
                } else {
                    strengthBar.style.width = '0%';
                }
            });
            
            // Form submission with loading state
            document.getElementById('loginForm').addEventListener('submit', function() {
                const btn = document.getElementById('loginBtn');
                const btnText = document.getElementById('loginBtnText');
                const spinner = document.getElementById('loginSpinner');
                
                btn.disabled = true;
                btnText.textContent = 'Authenticating...';
                spinner.classList.remove('d-none');
            });
            
            // Prevent form resubmission on page refresh
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.href);
            }
            
            // Session timeout warning (25 minutes)
            setTimeout(function() {
                if (!document.hidden) {
                    alert('Your session will expire in 5 minutes due to inactivity. Please save your work.');
                }
            }, 25 * 60 * 1000);
            
            // Auto-focus username field
            document.getElementById('username').focus();
            
        })();
    </script>
</body>
</html>
