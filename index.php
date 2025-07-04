<?php
// filepath: /Applications/XAMPP/xamppfiles/htdocs/dvlaregister/src/index.php
session_start();

// Check if user is already logged in, redirect to appropriate dashboard
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin') {
        header('Location: views/admin/dashboard.php');
        exit;
    } else {
        header('Location: views/user/dashboard.php');
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to DVLA Ghana Vehicle Registration System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            height: 100vh;
            margin: 0;
            background: linear-gradient(rgba(0, 0, 0, 0.6), rgba(0, 0, 0, 0.7)), 
                        url('assets/img/vm.jpg') no-repeat center center;
            background-size: cover;
            color: #fff;
        }
        
        .overlay {
            display: flex;
            flex-direction: column;
            height: 100%;
            width: 100%;
        }
        
        .content-box {
            background-color: rgba(0, 0, 0, 0.7);
            border-radius: 15px;
            padding: 40px;
            max-width: 600px;
            width: 90%;
            text-align: center;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5);
        }
        
        .logo {
            max-width: 220px;
            margin-bottom: 30px;
        }
        
        h1 {
            font-size: 2.5rem;
            margin-bottom: 20px;
            font-weight: 700;
        }
        
        .subtitle {
            font-size: 1.25rem;
            margin-bottom: 30px;
            opacity: 0.9;
        }
        
        .btn-login {
            background-color: #008751; /* Ghana flag green */
            color: white;
            font-weight: 600;
            font-size: 1.1rem;
            padding: 12px 40px;
            border-radius: 50px;
            border: none;
            transition: all 0.3s ease;
            margin-bottom: 20px;
        }
        
        .btn-login:hover {
            background-color: #00703f;
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
        }
        
        .btn-register {
            color: white;
            text-decoration: underline;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-register:hover {
            color: #ffce31; /* Ghana flag yellow */
        }
        
        .feature-box {
            margin-top: 40px;
            display: flex;
            justify-content: space-around;
            flex-wrap: wrap;
        }
        
        .feature {
            text-align: center;
            width: 30%;
            min-width: 150px;
            margin-bottom: 20px;
        }
        
        .feature i {
            font-size: 2rem;
            color: #ffce31; /* Ghana flag yellow */
            margin-bottom: 10px;
        }
        
        .feature h3 {
            font-size: 1rem;
            font-weight: 600;
        }
        
        footer {
            margin-top: auto;
            padding: 20px;
            text-align: center;
            font-size: 0.9rem;
            opacity: 0.7;
        }
        
        /* Ghana flag colors in the bottom bar */
        .ghana-colors {
            display: flex;
            height: 5px;
            width: 100%;
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
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .content-box {
                padding: 25px;
            }
            
            h1 {
                font-size: 1.8rem;
            }
            
            .subtitle {
                font-size: 1rem;
            }
            
            .feature {
                width: 45%;
            }
        }
        
        @media (max-width: 480px) {
            .content-box {
                padding: 20px;
            }
            
            h1 {
                font-size: 1.5rem;
            }
            
            .logo {
                max-width: 150px;
            }
            
            .feature {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="overlay d-flex justify-content-center align-items-center">
        <div class="content-box">
            <img src="assets/img/dvla.png" height="90" alt="DVLA Ghana Logo" class="logo">
            
            <h1>Vehicle Registration System</h1>
            <p class="subtitle">Register, renew, and manage your vehicle documents securely online.</p>
            
            <div class="mb-4">
                <a href="src/views/auth/login.php" class="btn btn-login">
                    <i class="fas fa-sign-in-alt me-2"></i>Login to Portal
                </a>
            </div>
            
            <div class="feature-box">
                <div class="feature">
                    <i class="fas fa-car"></i>
                    <h3>Register Vehicle</h3>
                </div>
                <div class="feature">
                    <i class="fas fa-sync-alt"></i>
                    <h3>Renew License</h3>
                </div>
                <div class="feature">
                    <i class="fas fa-history"></i>
                    <h3>Track Status</h3>
                </div>
            </div>
        </div>
        
        <footer>
            <p>&copy; <?= date('Y') ?> Driver and Vehicle Licensing Authority (DVLA) Ghana. All Rights Reserved.</p>
            
            <div class="ghana-colors mt-2">
                <div class="red"></div>
                <div class="gold"></div>
                <div class="green"></div>
            </div>
        </footer>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>