<?php
/**
 * Secure Login System with Comprehensive Security Measures
 * Enhanced DVLA Vehicle Registration System Login
 */

// Include security utilities first
require_once __DIR__ . '/../../../config/security.php';

// Initialize secure session and set security headers
initSecureSession();
setSecurityHeaders();

// Include database connection
require_once __DIR__ . '/../../../config/database.php';

// Initialize variables
$username = '';
$role = 'user';
$errors = [];
$success_message = '';

// Handle logout message
if (isset($_GET['message']) && $_GET['message'] === 'logged_out') {
    $success_message = 'You have been successfully logged out.';
}

// Validate existing session
if (isset($_SESSION['user_id']) && validateSession()) {
    // User is already logged in, redirect to appropriate dashboard
    $redirect_url = ($_SESSION['role'] === 'admin') ? 
                   '../../views/admin/dashboard.php' : 
                   '../../views/user/dashboard.php';
    header("Location: $redirect_url");
    exit;
}

// Generate CSRF token for the form
$csrf_token = generateCSRFToken();

// Only process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token first
    $csrf_token_input = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($csrf_token_input)) {
        $errors[] = 'Invalid security token. Please try again.';
        logSecurityEvent('CSRF_VIOLATION', $_POST['username'] ?? 'unknown', 'Invalid CSRF token in login attempt');
    } else {
        // Get form data
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'user';
        $remember = isset($_POST['remember']);
        
        // Create identifier for rate limiting (combination of IP and username)
        $rate_limit_identifier = ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '_' . $username;
        
        // Check rate limiting
        $rate_limit = checkRateLimit($rate_limit_identifier);
        if (!$rate_limit['allowed']) {
            $remaining_minutes = ceil($rate_limit['remaining_time'] / 60);
            $errors[] = "Too many failed login attempts. Account is locked for $remaining_minutes minutes.";
            logSecurityEvent('RATE_LIMIT_EXCEEDED', $username, 'Login attempt blocked due to rate limiting');
        } else {
            // Basic validation
            if (empty($username)) {
                $errors[] = 'Username is required';
            }
            
            if (empty($password)) {
                $errors[] = 'Password is required';
            }
            
            // Process login if no validation errors
            if (empty($errors)) {
                // Add timing attack protection delay
                constantTimeDelay();
                
                try {
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username LIMIT 1");
                    $stmt->execute(['username' => $username]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($user && password_verify($password, $user['password'])) {
                        // Role validation
                        if ($role === 'user' && $user['role'] === 'admin') {
                            $errors[] = 'Please use the admin login option for administrator accounts';
                        } 
                        elseif ($role === 'admin' && $user['role'] !== 'admin') {
                            $errors[] = 'You do not have administrator privileges';
                        } 
                        else {
                            // Successful login - clear any failed attempts
                            clearLoginAttempts($rate_limit_identifier);
                            
                            // Regenerate session ID for security
                            regenerateSessionId();
                            
                            // Set session variables
                            $_SESSION['user_id'] = $user['id'];
                            $_SESSION['username'] = $user['username'];
                            $_SESSION['full_name'] = $user['full_name'] ?? $user['username'];
                            $_SESSION['role'] = $user['role'];
                            $_SESSION['last_activity'] = time();
                            $_SESSION['login_time'] = time();
                            
                            // Log successful login
                            logSecurityEvent('SUCCESSFUL_LOGIN', $username, 'User logged in successfully');
                            
                            // Handle secure remember me functionality
                            if ($remember) {
                                $token = bin2hex(random_bytes(32));
                                
                                try {
                                    // Check if remember_token column exists
                                    $checkStmt = $pdo->prepare("SHOW COLUMNS FROM users LIKE 'remember_token'");
                                    $checkStmt->execute();
                                    
                                    if ($checkStmt->rowCount() > 0) {
                                        $expiry = date('Y-m-d H:i:s', time() + 30*24*60*60); // 30 days
                                        
                                        $stmt = $pdo->prepare("UPDATE users SET remember_token = :token, token_expires = :expires WHERE id = :id");
                                        $stmt->execute([
                                            'token' => password_hash($token, PASSWORD_DEFAULT),
                                            'expires' => $expiry,
                                            'id' => $user['id']
                                        ]);
                                        
                                        // Set secure cookies with all security flags
                                        setSecureCookie('remember_user', $user['id'], time() + 30*24*60*60);
                                        setSecureCookie('remember_token', $token, time() + 30*24*60*60);
                                    }
                                } catch (PDOException $e) {
                                    // Log error but don't fail login
                                    logSecurityEvent('REMEMBER_TOKEN_ERROR', $username, 'Failed to set remember token');
                                    error_log("Remember token error: " . $e->getMessage());
                                }
                            }
                            
                            // Proper HTTP redirect instead of JavaScript
                            $redirect_url = ($user['role'] === 'admin') ? 
                                           '../../views/admin/dashboard.php' : 
                                           '../../views/user/dashboard.php';
                            
                            header("Location: $redirect_url");
                            exit;
                        }
                    } else {
                        // Failed login - record attempt and add timing protection
                        recordFailedAttempt($rate_limit_identifier);
                        constantTimeDelay();
                        $errors[] = 'Invalid username or password';
                    }
                } catch (PDOException $e) {
                    // Secure error handling - don't expose database details
                    logSecurityEvent('DATABASE_ERROR', $username, 'Database error during login: ' . $e->getMessage());
                    error_log("Login database error: " . $e->getMessage());
                    $errors[] = 'A system error occurred. Please try again later.';
                }
            }
        }
    }
    
    // Regenerate CSRF token after processing
    $csrf_token = generateCSRFToken();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Vehicle Registration System</title>
    
    <!-- Security Meta Tags -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: url('/assets/img/logindv.jpg') no-repeat center center;
            background-size: cover;
        }
        .login-container {
            margin-top: 5vh;
        }
        .login-card {
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
            border: none;
            border-radius: 10px;
            overflow: hidden;
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
    </style>
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
                    </div>
                    
                    <div class="card-body p-4">
                        <?php if (!empty($success_message)): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success_message) ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (count($errors) > 0): ?>
                            <div class="alert alert-danger">
                                <ul class="mb-0">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?= htmlspecialchars($error) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" action="login.php">
                            <!-- CSRF Protection -->
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <!-- Role Selection -->
                            <div class="role-selector row mb-4">
                                <div class="col-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="role" id="userRole" value="user" <?= $role !== 'admin' ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="userRole">
                                            <i class="fas fa-user me-1"></i>
                                            User Login
                                        </label>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="role" id="adminRole" value="admin" <?= $role === 'admin' ? 'checked' : '' ?>>
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
                                           value="<?= htmlspecialchars($username) ?>" required autocomplete="username">
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <label for="password" class="form-label">Password</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="fas fa-lock"></i>
                                    </span>
                                    <input type="password" class="form-control" id="password" name="password" required autocomplete="current-password">
                                    <span class="input-group-text" style="cursor: pointer;" onclick="togglePassword()">
                                        <i class="fas fa-eye" id="toggleIcon"></i>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="remember" name="remember">
                                    <label class="form-check-label" for="remember">
                                        Remember me
                                    </label>
                                </div>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary py-2">
                                    <i class="fas fa-sign-in-alt me-2"></i> Login
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <div class="login-footer">
                        <p class="mb-0">Forgot your password? <a href="reset-password.php">Reset it here</a></p>
                    </div>
                </div>
                
                <div class="text-center mt-3">
                    <p class="text-muted">
                        &copy; <?= date('Y') ?> Ghana Driver & Vehicle Licensing Authority. All rights reserved.
                    </p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        function togglePassword() {
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
        }
        
        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>
</html>