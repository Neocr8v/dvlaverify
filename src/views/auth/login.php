<?php
// Security Headers - Set early to prevent any output before headers
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

// Content Security Policy
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data:; font-src 'self' https://cdnjs.cloudflare.com https://fonts.gstatic.com; connect-src 'self';");

// Secure session configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 0); // Set to 1 in production with HTTPS
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_lifetime', 0);

session_start();

// Include required files
require_once __DIR__ . '/../../../config/database.php';

// Check if security.php exists
$security_file = __DIR__ . '/../../../config/security.php';
if (file_exists($security_file)) {
    require_once $security_file;
    $security = new SecurityHandler($pdo);
    $use_security = true;
} else {
    $use_security = false;
}

// Initialize variables
$username = '';
$role = 'user';
$errors = [];

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
        if ($use_security) {
            $security->logSecurityEvent('CSRF_ATTACK_ATTEMPT', $_SERVER['REMOTE_ADDR'], 'Invalid CSRF token');
        }
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
        
        // Check rate limiting (if security system available)
        if (empty($errors) && $use_security) {
            $rate_limit_check = $security->checkRateLimit($client_ip, $username);
            if (!$rate_limit_check['allowed']) {
                $errors[] = $rate_limit_check['message'];
                $security->logSecurityEvent('RATE_LIMIT_EXCEEDED', $client_ip, "Username: $username");
            }
        }
        
        // Process login if no errors
        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("SELECT id, username, password, full_name, role FROM users WHERE username = :username LIMIT 1");
                $stmt->bindParam(':username', $username, PDO::PARAM_STR);
                $stmt->execute();
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Timing attack protection
                $dummy_hash = '$2y$10$dummy.hash.to.prevent.timing.attacks.abcdefghijklmnopqrstuvwxyz';
                $password_valid = false;
                
                if ($user) {
                    $password_valid = password_verify($password, $user['password']);
                } else {
                    password_verify($password, $dummy_hash);
                }
                
                if ($user && $password_valid) {
                    // Role validation
                    if ($role === 'user' && $user['role'] === 'admin') {
                        $errors[] = 'Please use the admin login option for administrator accounts';
                    } 
                    elseif ($role === 'admin' && $user['role'] !== 'admin') {
                        $errors[] = 'You do not have administrator privileges';
                    } 
                    else {
                        // Success - regenerate session
                        session_regenerate_id(true);
                        
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['full_name'] = $user['full_name'] ?? $user['username'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['login_time'] = time();
                        $_SESSION['last_activity'] = time();
                        $_SESSION['ip_address'] = $client_ip;
                        
                        unset($_SESSION['csrf_token']);
                        
                        if ($remember && $use_security) {
                            $security->setRememberMeToken($user['id']);
                        }
                        
                        if ($use_security) {
                            $security->logSecurityEvent('SUCCESSFUL_LOGIN', $client_ip, "User: {$user['username']}, Role: {$user['role']}");
                        }
                        
                        $redirect_url = ($user['role'] === 'admin') ? 
                                       '../../views/admin/dashboard.php' : 
                                       '../../views/user/dashboard.php';
                        
                        header("Location: $redirect_url");
                        exit;
                    }
                } else {
                    $errors[] = 'Invalid username or password';
                    if ($use_security) {
                        $security->recordFailedAttempt($client_ip, $username);
                        $security->logSecurityEvent('FAILED_LOGIN', $client_ip, "Username: $username");
                    }
                }
            } catch (PDOException $e) {
                error_log("Database error in login: " . $e->getMessage());
                $errors[] = 'An error occurred. Please try again later.';
                if ($use_security) {
                    $security->logSecurityEvent('DATABASE_ERROR', $client_ip, 'Login database error');
                }
            }
        }
    }
    
    // Timing attack protection
    $execution_time = microtime(true) - $start_time;
    if ($execution_time < 0.5) {
        usleep((0.5 - $execution_time) * 1000000);
    }
}

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Registration System | DVLA Ghana</title>
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous">
    
    <style>
        :root {
            --primary-color: #1e40af;
            --primary-dark: #1e3a8a;
            --primary-light: #3b82f6;
            --secondary-color: #64748b;
            --accent-color: #f59e0b;
            --success-color: #10b981;
            --danger-color: #ef4444;
            --warning-color: #f59e0b;
            --dark-color: #1f2937;
            --light-color: #f8fafc;
            --white: #ffffff;
            --border-radius: 16px;
            --box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            --box-shadow-lg: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
             background: url('/assets/img/logindv.jpg') no-repeat center center;
            background-size: cover;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow-x: hidden;
        }


        .login-container {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 480px;
            padding: 20px;
        }

        .login-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow-lg);
            border: 1px solid rgba(255, 255, 255, 0.2);
            overflow: hidden;
            position: relative;
        }

        .login-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-color), var(--primary-light), var(--accent-color));
            z-index: 1;
        }

        .card-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            color: var(--white);
            text-align: center;
            padding: 2.5rem 2rem;
            border: none;
            position: relative;
            overflow: hidden;
        }

        .card-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: shimmer 3s ease-in-out infinite alternate;
        }

        @keyframes shimmer {
            0% { transform: translateX(-100px) translateY(-100px); }
            100% { transform: translateX(100px) translateY(100px); }
        }

        .logo-container {
            position: relative;
            z-index: 10;
            margin-bottom: 1.5rem;
        }

        .login-logo {
            height: 80px;
            width: auto;
           
        }

        .login-logo:hover {
            transform: scale(1.05);
        }

        .system-title {
            font-family: 'Poppins', sans-serif;
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            position: relative;
            z-index: 2;
        }

        .system-subtitle {
            font-size: 0.95rem;
            opacity: 0.9;
            font-weight: 400;
            position: relative;
            z-index: 2;
        }

        .security-badge {
            display: inline-flex;
            align-items: center;
            background: rgba(255, 255, 255, 0.2);
            padding: 0.5rem 1rem;
            border-radius: 50px;
            margin-top: 1rem;
            font-size: 0.85rem;
            backdrop-filter: blur(10px);
            position: relative;
            z-index: 2;
        }

        .card-body {
            padding: 2.5rem 2rem;
        }

        .role-selection {
            background: var(--light-color);
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 2rem;
            border: 1px solid #e2e8f0;
        }

        .role-option {
            flex: 1;
            margin: 0 0.25rem;
        }

        .role-radio {
            display: none;
        }

        .role-label {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 1rem;
            border-radius: 8px;
            border: 2px solid transparent;
            background: var(--white);
            cursor: pointer;
            transition: var(--transition);
            text-align: center;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.04);
        }

        .role-label:hover {
            border-color: var(--primary-light);
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
        }

        .role-radio:checked + .role-label {
            border-color: var(--primary-color);
            background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
            color: var(--white);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(30, 64, 175, 0.3);
        }

        .role-icon {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }

        .role-text {
            font-weight: 600;
            font-size: 0.9rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 0.75rem;
            display: block;
            font-size: 0.95rem;
        }

        .input-wrapper {
            position: relative;
        }

        .form-control {
            width: 100%;
            padding: 1rem 1rem 1rem 3.5rem;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 1rem;
            transition: var(--transition);
            background: var(--white);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 4px rgba(30, 64, 175, 0.1);
            transform: translateY(-1px);
        }

        .input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--secondary-color);
            font-size: 1.1rem;
            z-index: 2;
        }

        .password-toggle {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--secondary-color);
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 6px;
            transition: var(--transition);
            z-index: 2;
        }

        .password-toggle:hover {
            background: var(--light-color);
            color: var(--primary-color);
        }

        .strength-meter {
            height: 4px;
            background: #e2e8f0;
            border-radius: 2px;
            margin-top: 0.5rem;
            overflow: hidden;
        }

        .strength-bar {
            height: 100%;
            width: 0%;
            border-radius: 2px;
            transition: var(--transition);
        }

        .form-check-wrapper {
            display: flex;
            align-items: center;
            margin-bottom: 2rem;
        }

        .form-check-input {
            width: 1.2rem;
            height: 1.2rem;
            border: 2px solid #e2e8f0;
            border-radius: 4px;
            margin-right: 0.75rem;
            cursor: pointer;
            transition: var(--transition);
        }

        .form-check-input:checked {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .form-check-label {
            font-size: 0.95rem;
            color: var(--dark-color);
            cursor: pointer;
            display: flex;
            align-items: center;
            margin: 0;
        }

        .btn-login {
            width: 100%;
            padding: 1rem 2rem;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
            border: none;
            border-radius: 12px;
            color: var(--white);
            font-weight: 600;
            font-size: 1.1rem;
            cursor: pointer;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3);
        }

        .btn-login::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: var(--transition);
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(30, 64, 175, 0.4);
        }

        .btn-login:hover::before {
            left: 100%;
        }

        .btn-login:disabled {
            opacity: 0.7;
            transform: none;
            cursor: not-allowed;
        }

        .spinner {
            width: 1.2rem;
            height: 1.2rem;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top: 2px solid var(--white);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-left: 0.5rem;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .forgot-password {
            text-align: center;
            margin-top: 1.5rem;
        }

        .forgot-password a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.95rem;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            transition: var(--transition);
        }

        .forgot-password a:hover {
            background: var(--light-color);
            color: var(--primary-dark);
        }

        .security-note {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(6, 182, 212, 0.1));
            border: 1px solid rgba(16, 185, 129, 0.2);
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1.5rem;
            text-align: center;
        }

        .security-note-text {
            font-size: 0.85rem;
            color: var(--secondary-color);
            margin: 0;
        }


        /* Alert Styles */
        .alert {
            border: none;
            border-radius: 12px;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            font-weight: 500;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .alert-danger {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(220, 38, 38, 0.1));
            color: #dc2626;
            border-left: 4px solid #ef4444;
        }

        .alert-success {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(5, 150, 105, 0.1));
            color: #059669;
            border-left: 4px solid #10b981;
        }

        .alert-warning {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(217, 119, 6, 0.1));
            color: #d97706;
            border-left: 4px solid #f59e0b;
        }

        .alert-info {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(37, 99, 235, 0.1));
            color: #2563eb;
            border-left: 4px solid #3b82f6;
        }

        /* Responsive Design */
        @media (max-width: 576px) {
            .login-container {
                padding: 1rem;
            }

            .card-header {
                padding: 2rem 1.5rem;
            }

            .card-body {
                padding: 2rem 1.5rem;
            }

            .system-title {
                font-size: 1.25rem;
            }

            .role-selection {
                padding: 0.75rem;
            }

            .role-label {
                padding: 0.75rem 0.5rem;
            }

            .role-text {
                font-size: 0.8rem;
            }

            .footer {
                position: relative;
                margin-top: 2rem;
            }
        }

        /* Form validation styles */
        .form-control.is-invalid {
            border-color: var(--danger-color);
            box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.1);
        }

        .form-control.is-valid {
            border-color: var(--success-color);
            box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1);
        }

        .form-text {
            font-size: 0.8rem;
            color: var(--secondary-color);
            margin-top: 0.5rem;
        }

        /* Loading overlay */
        .loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.9);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            border-radius: var(--border-radius);
        }

        .loading-spinner {
            width: 3rem;
            height: 3rem;
            border: 4px solid rgba(30, 64, 175, 0.1);
            border-top: 4px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
    </style>
    
    <!-- Security meta tags -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <meta name="robots" content="noindex, nofollow">
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="card-header">
                <div class="logo-container">
                    <img src="/assets/img/dvla.png" alt="DVLA Ghana Logo" class="login-logo">
                </div>
                <h1 class="system-title">Vehicle Registration System</h1>
                <p class="system-subtitle">Ghana Driver & Vehicle Licensing Authority</p>
                <div class="security-badge">
                    <i class="fas fa-shield-check me-2"></i>
                    Enterprise Security Enabled
                </div>
            </div>
            
            <div class="card-body">
                <!-- Logout messages -->
                <?php if ($logout_message): ?>
                    <div class="alert alert-<?= $logout_class ?> alert-dismissible fade show" role="alert">
                        <i class="fas fa-sign-out-alt me-2"></i>
                        <?= htmlspecialchars($logout_message, ENT_QUOTES, 'UTF-8') ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Error messages -->
                <?php if (count($errors) > 0): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <ul class="mb-0 ps-3">
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="login.php" id="loginForm" novalidate>
                    <!-- CSRF Token -->
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    
                    <!-- Role Selection -->
                    <div class="role-selection">
                        <div class="d-flex">
                            <div class="role-option">
                                <input type="radio" name="role" id="userRole" value="user" class="role-radio" <?= $role !== 'admin' ? 'checked' : '' ?> required>
                                <label for="userRole" class="role-label">
                                    <i class="fas fa-user role-icon"></i>
                                    <span class="role-text">User Access</span>
                                </label>
                            </div>
                            <div class="role-option">
                                <input type="radio" name="role" id="adminRole" value="admin" class="role-radio" <?= $role === 'admin' ? 'checked' : '' ?> required>
                                <label for="adminRole" class="role-label">
                                    <i class="fas fa-user-shield role-icon"></i>
                                    <span class="role-text">Admin Access</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Username Field -->
                    <div class="form-group">
                        <label for="username" class="form-label">
                            <i class="fas fa-user me-2"></i>Username
                        </label>
                        <div class="input-wrapper">
                            <i class="fas fa-user input-icon"></i>
                            <input type="text" 
                                   class="form-control" 
                                   id="username" 
                                   name="username" 
                                   value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>"
                                   required 
                                   maxlength="50" 
                                   pattern="[a-zA-Z0-9_.-]+"
                                   autocomplete="username" 
                                   spellcheck="false"
                                   placeholder="Enter your username">
                        </div>
                        <div class="form-text">
                            <i class="fas fa-info-circle me-1"></i>
                            Only letters, numbers, dots, hyphens, and underscores
                        </div>
                    </div>
                    
                    <!-- Password Field -->
                    <div class="form-group">
                        <label for="password" class="form-label">
                            <i class="fas fa-lock me-2"></i>Password
                        </label>
                        <div class="input-wrapper">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" 
                                   class="form-control" 
                                   id="password" 
                                   name="password" 
                                   required 
                                   maxlength="255" 
                                   autocomplete="current-password"
                                   placeholder="Enter your password">
                            <button type="button" class="password-toggle" onclick="togglePassword()">
                                <i class="fas fa-eye" id="toggleIcon"></i>
                            </button>
                        </div>
                        <div class="strength-meter">
                            <div class="strength-bar" id="strengthBar"></div>
                        </div>
                    </div>
                    
                    <!-- Remember Me -->
                    <div class="form-check-wrapper">
                        <input type="checkbox" id="remember" name="remember" class="form-check-input">
                        <label for="remember" class="form-check-label">
                            <i class="fas fa-clock me-2"></i>
                            Remember me for 30 days
                            <small class="d-block text-muted mt-1">
                                <i class="fas fa-info-circle me-1"></i>
                                Only use on trusted devices
                            </small>
                        </label>
                    </div>
                    
                    <!-- Login Button -->
                    <button type="submit" class="btn-login" id="loginBtn">
                        <i class="fas fa-sign-in-alt me-2"></i>
                        <span id="loginBtnText">Secure Login</span>
                        <div class="spinner d-none" id="loginSpinner"></div>
                    </button>
                </form>
                
                <!-- Forgot Password -->
                <div class="forgot-password">
                    <a href="reset-password.php">
                        <i class="fas fa-key me-2"></i>
                        Forgot your password?
                    </a>
                </div>
                
                <!-- Security Note -->
                <div class="security-note">
                    <p class="security-note-text">
                        <i class="fas fa-shield-check me-2"></i>
                        Your connection is protected with enterprise-grade security
                    </p>
                </div>
            </div>
        </div>
    </div>
    
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
    
    <script>
        (function() {
            'use strict';
            
            // Form elements
            const loginForm = document.getElementById('loginForm');
            const passwordField = document.getElementById('password');
            const usernameField = document.getElementById('username');
            const loginBtn = document.getElementById('loginBtn');
            const loginBtnText = document.getElementById('loginBtnText');
            const loginSpinner = document.getElementById('loginSpinner');
            const strengthBar = document.getElementById('strengthBar');
            
            // Password visibility toggle
            window.togglePassword = function() {
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
            passwordField.addEventListener('input', function() {
                const password = this.value;
                let strength = 0;
                
                if (password.length >= 8) strength++;
                if (/[a-z]/.test(password)) strength++;
                if (/[A-Z]/.test(password)) strength++;
                if (/[0-9]/.test(password)) strength++;
                if (/[^A-Za-z0-9]/.test(password)) strength++;
                
                const colors = ['#ef4444', '#f59e0b', '#eab308', '#22c55e', '#10b981'];
                const widths = ['20%', '40%', '60%', '80%', '100%'];
                
                if (password.length > 0) {
                    strengthBar.style.width = widths[strength - 1] || '20%';
                    strengthBar.style.backgroundColor = colors[strength - 1] || '#ef4444';
                } else {
                    strengthBar.style.width = '0%';
                }
            });
            
            // Form validation
            function validateForm() {
                let isValid = true;
                
                // Username validation
                if (!usernameField.value.trim()) {
                    usernameField.classList.add('is-invalid');
                    isValid = false;
                } else if (!/^[a-zA-Z0-9_.-]+$/.test(usernameField.value)) {
                    usernameField.classList.add('is-invalid');
                    isValid = false;
                } else {
                    usernameField.classList.remove('is-invalid');
                    usernameField.classList.add('is-valid');
                }
                
                // Password validation
                if (!passwordField.value) {
                    passwordField.classList.add('is-invalid');
                    isValid = false;
                } else {
                    passwordField.classList.remove('is-invalid');
                    passwordField.classList.add('is-valid');
                }
                
                return isValid;
            }
            
            // Form submission
            loginForm.addEventListener('submit', function(e) {
                if (!validateForm()) {
                    e.preventDefault();
                    return;
                }
                
                // Show loading state
                loginBtn.disabled = true;
                loginBtnText.textContent = 'Authenticating...';
                loginSpinner.classList.remove('d-none');
                
                // Add loading overlay
                const overlay = document.createElement('div');
                overlay.className = 'loading-overlay';
                overlay.innerHTML = '<div class="loading-spinner"></div>';
                document.querySelector('.login-card').style.position = 'relative';
                document.querySelector('.login-card').appendChild(overlay);
            });
            
            // Real-time validation
            usernameField.addEventListener('input', function() {
                if (this.value.trim() && /^[a-zA-Z0-9_.-]+$/.test(this.value)) {
                    this.classList.remove('is-invalid');
                    this.classList.add('is-valid');
                } else {
                    this.classList.remove('is-valid');
                    if (this.value.trim()) {
                        this.classList.add('is-invalid');
                    }
                }
            });
            
            passwordField.addEventListener('input', function() {
                if (this.value) {
                    this.classList.remove('is-invalid');
                    this.classList.add('is-valid');
                } else {
                    this.classList.remove('is-valid');
                }
            });
            
            // Prevent context menu on password field
            passwordField.addEventListener('contextmenu', function(e) {
                e.preventDefault();
            });
            
            // Prevent form resubmission
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.href);
            }
            
            // Session timeout warning
            setTimeout(function() {
                if (!document.hidden) {
                    const toast = document.createElement('div');
                    toast.className = 'alert alert-warning position-fixed top-0 start-50 translate-middle-x mt-3';
                    toast.style.zIndex = '9999';
                    toast.innerHTML = '<i class="fas fa-clock me-2"></i>Session expires in 5 minutes';
                    document.body.appendChild(toast);
                    
                    setTimeout(() => toast.remove(), 5000);
                }
            }, 25 * 60 * 1000);
            
            // Auto-focus username field
            document.addEventListener('DOMContentLoaded', function() {
                usernameField.focus();
            });
            
            // Enhanced role selection animation
            document.querySelectorAll('.role-radio').forEach(radio => {
                radio.addEventListener('change', function() {
                    document.querySelectorAll('.role-label').forEach(label => {
                        label.style.transform = 'scale(1)';
                    });
                    
                    if (this.checked) {
                        const label = this.nextElementSibling;
                        label.style.transform = 'scale(1.02)';
                        setTimeout(() => {
                            label.style.transform = 'scale(1)';
                        }, 200);
                    }
                });
            });
            
        })();
    </script>
</body>
</html>
