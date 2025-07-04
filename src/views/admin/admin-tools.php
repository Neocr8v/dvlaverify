<?php
// filepath: /Applications/XAMPP/xamppfiles/htdocs/dvlaregister/src/views/admin/admin-tools.php
session_start();

// Security check - only allow admin users
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../auth/login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Tools | Vehicle Registration System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container mt-5">
        <div class="card shadow">
            <div class="card-header bg-primary text-white">
                <h2><i class="fas fa-tools me-2"></i>Administrator Tools</h2>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4 mb-4">
                        <div class="card h-100">
                            <div class="card-body text-center">
                                <i class="fas fa-user-plus fa-3x text-primary mb-3"></i>
                                <h5 class="card-title">Create Admin Login</h5>
                                <p class="card-text">Create login accounts for administrators.</p>
                                <!-- Fixed: Using correct relative path -->
                                <a href="create-admin-login.php" class="btn btn-primary">Access Tool</a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4 mb-4">
                        <div class="card h-100">
                            <div class="card-body text-center">
                                <i class="fas fa-tachometer-alt fa-3x text-success mb-3"></i>
                                <h5 class="card-title">Admin Dashboard</h5>
                                <p class="card-text">Access the main admin dashboard.</p>
                                <!-- Fixed: Using correct relative path -->
                                <a href="dashboard.php" class="btn btn-success">Go to Dashboard</a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4 mb-4">
                        <div class="card h-100">
                            <div class="card-body text-center">
                                <i class="fas fa-car fa-3x text-info mb-3"></i>
                                <h5 class="card-title">Vehicle Management</h5>
                                <p class="card-text">Manage vehicle registrations.</p>
                                <!-- Fixed: Using correct relative path -->
                                <a href="all-vehicles.php" class="btn btn-info text-white">Manage Vehicles</a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <hr class="my-4">
                
                <div class="alert alert-info">
                    <h5><i class="fas fa-info-circle me-2"></i>Troubleshooting Information</h5>
                    <p>If you're having issues accessing any of these tools directly, use this page as a central access point.</p>
                    
                    <div class="mt-3">
                        <strong>Current User:</strong> <?= htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username']) ?><br>
                        <strong>Role:</strong> <?= htmlspecialchars($_SESSION['role']) ?><br>
                        <strong>User ID:</strong> <?= htmlspecialchars($_SESSION['user_id']) ?>
                    </div>
                    
                    <!-- Add path diagnostic information -->
                    <div class="mt-3 small">
                        <strong>Current File:</strong> <?= __FILE__ ?><br>
                        <strong>Document Root:</strong> <?= $_SERVER['DOCUMENT_ROOT'] ?><br>
                        <strong>Create Admin Login Path:</strong> create-admin-login.php<br>
                        <strong>File Exists:</strong> <?= file_exists(__DIR__ . '/create-admin-login.php') ? 'YES' : 'NO' ?>
                    </div>
                </div>
            </div>
            <div class="card-footer text-center">
                <a href="../../../index.php" class="btn btn-secondary">
                    <i class="fas fa-home me-2"></i>Return to Home
                </a>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>