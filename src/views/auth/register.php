<?php
session_start();
require_once __DIR__ . '/../../../config/database.php';

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    // Redirect based on role
    if ($_SESSION['role'] === 'admin') {
        header('Location: ../../views/admin/dashboard.php');
    } else {
        header('Location: ../../views/vehicle/search.php');
    }
    exit;
}

// Only admins can create new users
$adminCreation = false;
if (isset($_SESSION['user_id']) && $_SESSION['role'] === 'admin') {
    $adminCreation = true;
}

$username = $email = $fullName = '';
$errors = [];

// Process registration form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get and sanitize input
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $email = trim($_POST['email'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    
    // Validate username
    if (empty($username)) {
        $errors[] = 'Username is required';
    } elseif (strlen($username) < 3 || strlen($username) > 50) {
        $errors[] = 'Username must be between 3 and 50 characters';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $errors[] = 'Username can only contain letters, numbers, and underscores';
    } else {
        // Check if username already exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :username");
        $stmt->execute(['username' => $username]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = 'Username already exists';
        }
    }
    
    // Validate password
    if (empty($password)) {
        $errors[] = 'Password is required';
    } elseif (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long';
    } elseif (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must include uppercase, lowercase letters and numbers';
    }
    
    // Confirm passwords match
    if ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match';
    }
    
    // Validate email
    if (empty($email)) {
        $errors[] = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address';
    } else {
        // Check if email already exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = 'Email already registered';
        }
    }
    
    // Validate full name
    if (empty($fullName)) {
        $errors[] = 'Full name is required';
    } elseif (strlen($fullName) > 100) {
        $errors[] = 'Full name cannot exceed 100 characters';
    }
    
    // If no validation errors, create the user
    if (empty($errors)) {
        try {
            $role = $adminCreation && isset($_POST['role']) ? $_POST['role'] : 'user';
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            
            $stmt = $pdo->prepare("
                INSERT INTO users (username, password, email, full_name, role)
                VALUES (:username, :password, :email, :fullName, :role)
            ");
            
            $stmt->execute([
                'username' => $username,
                'password' => $hashedPassword,
                'email' => $email,
                'fullName' => $fullName,
                'role' => $role
            ]);
            
            // Registration successful message
            $successMessage = "User registration successful!";
            
            if (!$adminCreation) {
                // Redirect to login page with success message
                header('Location: login.php?registered=1');
                exit;
            } else {
                // Clear form for admin to add another user
                $username = $email = $fullName = '';
            }
        } catch (PDOException $e) {
            $errors[] = 'Registration error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register | Vehicle Registration System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <style>
        :root {
            --primary-color: #0a3d62;
            --secondary-color: #60a3bc;
            --accent-color: #e58e26;
            --light-color: #f0f0f0;
            --dark-color: #333333;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
        }
        
        .register-container {
            margin-top: 5vh;
            margin-bottom: 5vh;
        }
        
        .register-card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 0.5rem 1.5rem rgba(0, 0, 0, 0.15);
            overflow: hidden;
        }
        
        .register-card .card-header {
            background-color: var(--primary-color);
            color: white;
            text-align: center;
            padding: 1.5rem;
            border-bottom: none;
        }
        
        .register-card .card-body {
            padding: 2.5rem;
        }
        
        .register-logo {
            max-width: 120px;
            margin-bottom: 15px;
        }
        
        .form-control {
            padding: 0.75rem 1rem;
            border-radius: 5px;
        }
        
        .form-control:focus {
            box-shadow: 0 0 0 0.25rem rgba(10, 61, 98, 0.25);
            border-color: var(--secondary-color);
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            padding: 0.75rem 1rem;
        }
        
        .btn-primary:hover {
            background-color: #072f4a;
            border-color: #072f4a;
        }
        
        .password-requirements {
            font-size: 0.85rem;
            color: #6c757d;
        }
        
        .system-name {
            font-weight: 700;
            color: var(--accent-color);
        }
        
        .register-footer {
            background-color: white;
            border-top: 1px solid #eee;
            padding: 1.25rem;
            text-align: center;
        }
        
        .password-toggle {
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="container register-container">
        <div class="row justify-content-center">
            <div class="col-lg-6 col-md-8">
                <div class="card register-card">
                    <div class="card-header">
                        <!-- You can add your logo here -->
                        <img src="/dvlaregister/assets/img/logo.png" alt="DVLA Logo" class="register-logo">
                        <h4 class="mb-0"><span class="system-name">Vehicle Registration System</span></h4>
                        <p class="text-light">User Registration</p>
                    </div>
                    
                    <div class="card-body">
                        <?php if (count($errors) > 0): ?>
                            <div class="alert alert-danger">
                                <ul class="mb-0">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?= htmlspecialchars($error) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (isset($successMessage)): ?>
                            <div class="alert alert-success">
                                <?= htmlspecialchars($successMessage) ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" action="">
                            <div class="mb-3">
                                <label for="username" class="form-label">Username</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="fas fa-user"></i>
                                    </span>
                                    <input type="text" class="form-control" id="username" name="username" value="<?= htmlspecialchars($username) ?>" required>
                                </div>
                                <small class="text-muted">Username must be between 3-50 characters and contain only letters, numbers, and underscores.</small>
                            </div>
                            
                            <div class="mb-3">
                                <label for="full_name" class="form-label">Full Name</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="fas fa-id-card"></i>
                                    </span>
                                    <input type="text" class="form-control" id="full_name" name="full_name" value="<?= htmlspecialchars($fullName) ?>" required>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="email" class="form-label">Email Address</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="fas fa-envelope"></i>
                                    </span>
                                    <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($email) ?>" required>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="password" class="form-label">Password</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="fas fa-lock"></i>
                                    </span>
                                    <input type="password" class="form-control" id="password" name="password" required>
                                    <span class="input-group-text password-toggle" onclick="togglePassword('password', 'toggleIcon1')">
                                        <i class="fas fa-eye" id="toggleIcon1"></i>
                                    </span>
                                </div>
                                <div class="password-requirements mt-1">
                                    <ul class="mb-0 ps-3">
                                        <li>At least 8 characters long</li>
                                        <li>Include uppercase & lowercase letters</li>
                                        <li>Include at least one number</li>
                                    </ul>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <label for="confirm_password" class="form-label">Confirm Password</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="fas fa-lock"></i>
                                    </span>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                    <span class="input-group-text password-toggle" onclick="togglePassword('confirm_password', 'toggleIcon2')">
                                        <i class="fas fa-eye" id="toggleIcon2"></i>
                                    </span>
                                </div>
                            </div>
                            
                            <?php if ($adminCreation): ?>
                            <div class="mb-4">
                                <label for="role" class="form-label">User Role</label>
                                <select class="form-select" id="role" name="role">
                                    <option value="user">Regular User</option>
                                    <option value="admin">Administrator</option>
                                </select>
                            </div>
                            <?php endif; ?>
                            
                            <div class="d-grid mb-3">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-user-plus me-2"></i> Register
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <div class="card-footer register-footer">
                        <p class="mb-0">Already have an account? <a href="login.php">Login here</a></p>
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
    
    <!-- Custom JS -->
    <script>
        function togglePassword(fieldId, iconId) {
            const passwordField = document.getElementById(fieldId);
            const toggleIcon = document.getElementById(iconId);
            
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
    </script>
</body>
</html>