<?php
// filepath: /Applications/XAMPP/xamppfiles/htdocs/dvlaregister/src/views/auth/reset_password.php
session_start();
require_once __DIR__ . '/../../../config/database.php';

$step = isset($_GET['step']) ? $_GET['step'] : 'request';
$token = isset($_GET['token']) ? $_GET['token'] : '';
$email = isset($_GET['email']) ? $_GET['email'] : '';
$messages = [];
$errors = [];

// Step 1: Handle the request for password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'request') {
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    
    if (empty($email)) {
        $errors[] = "Email is required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    } else {
        try {
            // Check if the email exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();
            
            if ($user) {
                // Generate a token
                $token = bin2hex(random_bytes(32));
                $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
                
                // Delete any existing tokens for this user
                $deleteStmt = $pdo->prepare("DELETE FROM password_resets WHERE email = :email");
                $deleteStmt->execute(['email' => $email]);
                
                // Store the token
                $insertStmt = $pdo->prepare("INSERT INTO password_resets (email, token, expiry) VALUES (:email, :token, :expiry)");
                $insertStmt->execute([
                    'email' => $email,
                    'token' => $token,
                    'expiry' => $expiry
                ]);
                
                // In a real application, send email with reset link
                $resetLink = "http://" . $_SERVER['HTTP_HOST'] . "/src/views/auth/reset_password.php?step=reset&token=" . $token . "&email=" . urlencode($email);
                
                // For development, just show the link on the page
                $messages[] = "A password reset link has been generated. In a real application, this would be emailed to you.";
                $messages[] = "Reset link: <a href='{$resetLink}'>{$resetLink}</a>";
                
                // Uncomment this when you have email sending set up
                /*
                $to = $email;
                $subject = "Reset Your Password - DVLA Vehicle Registration System";
                $message = "Hello,\n\nYou have requested to reset your password. Please click the link below to reset it:\n\n{$resetLink}\n\nThis link will expire in one hour.\n\nIf you didn't request this, please ignore this email.\n\nRegards,\nDVLA Ghana";
                $headers = "From: noreply@dvlaghana.gov.gh";
                
                if (mail($to, $subject, $message, $headers)) {
                    $messages[] = "A password reset link has been sent to your email address. Please check your inbox.";
                } else {
                    $errors[] = "Failed to send the password reset email. Please try again later.";
                }
                */
            } else {
                // Don't reveal that the email doesn't exist for security reasons
                $messages[] = "If your email is registered in our system, you will receive a password reset link shortly.";
            }
        } catch (PDOException $e) {
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}

// Step 2: Handle the password reset form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'reset') {
    $token = isset($_POST['token']) ? $_POST['token'] : '';
    $email = isset($_POST['email']) ? $_POST['email'] : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $confirmPassword = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
    
    if (empty($token) || empty($email)) {
        $errors[] = "Invalid password reset link";
    } elseif (empty($password)) {
        $errors[] = "Password is required";
    } elseif (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long";
    } elseif ($password !== $confirmPassword) {
        $errors[] = "Passwords do not match";
    } else {
        try {
            // Verify the token
            $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE email = :email AND token = :token AND expiry > NOW()");
            $stmt->execute([
                'email' => $email,
                'token' => $token
            ]);
            $reset = $stmt->fetch();
            
            if ($reset) {
                // Update the user's password
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $updateStmt = $pdo->prepare("UPDATE users SET password = :password WHERE email = :email");
                $updateStmt->execute([
                    'password' => $hashedPassword,
                    'email' => $email
                ]);
                
                // Delete the used token
                $deleteStmt = $pdo->prepare("DELETE FROM password_resets WHERE email = :email");
                $deleteStmt->execute(['email' => $email]);
                
                $messages[] = "Your password has been reset successfully. You can now login with your new password.";
                // Set step to success to show success message
                $step = 'success';
            } else {
                $errors[] = "Invalid or expired password reset token";
            }
        } catch (PDOException $e) {
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}

// Verify token validity for the reset form
if ($step === 'reset' && !empty($token) && !empty($email) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    try {
        // Check if the token is valid and not expired
        $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE email = :email AND token = :token AND expiry > NOW()");
        $stmt->execute([
            'email' => $email,
            'token' => $token
        ]);
        $reset = $stmt->fetch();
        
        if (!$reset) {
            $errors[] = "Invalid or expired password reset token";
            $step = 'invalid';
        }
    } catch (PDOException $e) {
        $errors[] = "Database error: " . $e->getMessage();
        $step = 'invalid';
    }
}

// Check if the password_resets table exists, create it if not
try {
    $tableExists = $pdo->query("SHOW TABLES LIKE 'password_resets'")->rowCount() > 0;
    if (!$tableExists) {
        $pdo->exec("CREATE TABLE password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            token VARCHAR(255) NOT NULL,
            expiry DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
    }
} catch (PDOException $e) {
    $errors[] = "Database setup error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | DVLA Vehicle Registration System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            background: linear-gradient(rgba(0, 0, 0, 0.6), rgba(0, 0, 0, 0.7)), 
                        url('/assets/img/ghana-traffic.jpg') no-repeat center center;
            background-size: cover;
            display: flex;
            flex-direction: column;
        }
        
        .page-content {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }
        
        .reset-card {
            background-color: #fff;
            border-radius: 10px;
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 500px;
            padding: 2rem;
        }
        
        .logo-container {
            display: flex;
            justify-content: center;
            margin-bottom: 1.5rem;
        }
        
        .logo {
            height: 80px;
        }
        
        .card-title {
            color: #333;
            font-weight: 700;
            text-align: center;
            margin-bottom: 1.5rem;
        }
        
        .form-control {
            padding: 0.75rem;
            border-radius: 5px;
            border: 1px solid #ddd;
            margin-bottom: 1rem;
        }
        
        .form-control:focus {
            border-color: #008751;
            box-shadow: 0 0 0 0.25rem rgba(0, 135, 81, 0.25);
        }
        
        .btn-primary {
            background-color: #008751;
            border: none;
            padding: 0.75rem;
            font-weight: 600;
            border-radius: 5px;
        }
        
        .btn-primary:hover, .btn-primary:focus {
            background-color: #006b40;
        }
        
        .password-container {
            position: relative;
        }
        
        .password-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #6c757d;
        }
        
        .alert-info a {
            color: #055160;
            font-weight: bold;
        }
        
        .footer {
            background-color: rgba(0, 0, 0, 0.7);
            color: white;
            padding: 1rem;
            text-align: center;
            margin-top: auto;
        }
        
        .ghana-colors {
            display: flex;
            height: 5px;
            width: 100%;
            margin-top: 0.5rem;
        }
        
        .red {
            background-color: #ce1126;
            flex: 1;
        }
        
        .gold {
            background-color: #ffce31;
            flex: 1;
        }
        
        .green {
            background-color: #008751;
            flex: 1;
        }
        
        .text-success {
            color: #008751 !important;
        }
    </style>
</head>
<body>
    <div class="page-content">
        <div class="reset-card">
            <div class="logo-container">
                <img src="/assets/img/dvla.png" alt="DVLA Logo" class="logo">
            </div>
            
            <h2 class="card-title">
                <?php if ($step === 'request'): ?>
                    Reset Your Password
                <?php elseif ($step === 'reset'): ?>
                    Create New Password
                <?php elseif ($step === 'success'): ?>
                    Password Reset Complete
                <?php else: ?>
                    Invalid Request
                <?php endif; ?>
            </h2>
            
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($messages)): ?>
                <div class="alert alert-info">
                    <?php foreach ($messages as $message): ?>
                        <p class="mb-1"><?= $message ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($step === 'request'): ?>
                <!-- Step 1: Request password reset -->
                <form method="post" action="">
                    <div class="mb-4">
                        <label for="email" class="form-label">Email Address</label>
                        <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($email) ?>" required>
                        <div class="form-text">Enter the email address associated with your account.</div>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary">Request Password Reset</button>
                        <a href="login.php" class="btn btn-outline-secondary">Back to Login</a>
                    </div>
                </form>
            <?php elseif ($step === 'reset'): ?>
                <!-- Step 2: Create new password -->
                <form method="post" action="">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                    <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
                    
                    <div class="mb-3">
                        <label for="password" class="form-label">New Password</label>
                        <div class="password-container">
                            <input type="password" class="form-control" id="password" name="password" required>
                            <span class="password-toggle" onclick="togglePassword('password', 'toggleIcon1')">
                                <i id="toggleIcon1" class="fa fa-eye"></i>
                            </span>
                        </div>
                        <div class="form-text">Password must be at least 8 characters long.</div>
                    </div>
                    
                    <div class="mb-4">
                        <label for="confirm_password" class="form-label">Confirm New Password</label>
                        <div class="password-container">
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                            <span class="password-toggle" onclick="togglePassword('confirm_password', 'toggleIcon2')">
                                <i id="toggleIcon2" class="fa fa-eye"></i>
                            </span>
                        </div>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary">Reset Password</button>
                        <a href="login.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            <?php elseif ($step === 'success'): ?>
                <!-- Step 3: Password reset successful -->
                <div class="text-center">
                    <i class="fas fa-check-circle text-success mb-3" style="font-size: 4rem;"></i>
                    <p class="mb-4">Your password has been reset successfully. You can now login using your new password.</p>
                    
                    <div class="d-grid">
                        <a href="login.php" class="btn btn-primary">Login Now</a>
                    </div>
                </div>
            <?php else: ?>
                <!-- Invalid or expired token -->
                <div class="text-center">
                    <i class="fas fa-exclamation-triangle text-warning mb-3" style="font-size: 4rem;"></i>
                    <p class="mb-4">This password reset link is invalid or has expired. Please request a new password reset link.</p>
                    
                    <div class="d-grid">
                        <a href="reset_password.php" class="btn btn-primary">Request New Link</a>
                        <a href="login.php" class="btn btn-outline-secondary mt-2">Back to Login</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <footer class="footer">
        <div class="container">
            <p class="mb-1">
                &copy; <?= date('Y') ?> Driver and Vehicle Licensing Authority (DVLA) Ghana. All Rights Reserved.
            </p>
            <div class="ghana-colors">
                <div class="red"></div>
                <div class="gold"></div>
                <div class="green"></div>
            </div>
        </div>
    </footer>
    
    <script>
        function togglePassword(inputId, iconId) {
            const passwordField = document.getElementById(inputId);
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
        
        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>
</html>