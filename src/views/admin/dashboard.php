<?php
// filepath: /Applications/XAMPP/xamppfiles/htdocs/dvlaregister/src/views/admin/dashboard.php
session_start();
require_once __DIR__ . '/../../../config/database.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../views/auth/login.php');
    exit;
}

// Determine base URL dynamically for assets
$baseUrl = '';
$scriptDir = dirname(dirname(dirname(dirname($_SERVER['SCRIPT_NAME']))));
if ($scriptDir !== '/' && $scriptDir !== '\\') {
    $baseUrl = $scriptDir;
}

// Get counts for dashboard statistics
try {
    // Count total vehicles
    $stmt = $pdo->query("SELECT COUNT(*) FROM vehicles");
    $totalVehicles = $stmt->fetchColumn();
    
    // Count vehicles registered today
    $stmt = $pdo->query("SELECT COUNT(*) FROM vehicles WHERE DATE(registration_date) = CURDATE()");
    $todayRegistrations = $stmt->fetchColumn();
    
    // Count vehicle owners
    $stmt = $pdo->query("SELECT COUNT(*) FROM vehicle_owners");
    $totalOwners = $stmt->fetchColumn();
    
    // Count users
    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    $totalUsers = $stmt->fetchColumn();
    
    // Get recent registrations
    $stmt = $pdo->query("
        SELECT v.id, v.registration_number, v.make, v.model, v.color, v.registration_date, o.name as owner_name
        FROM vehicles v
        JOIN vehicle_owners o ON v.owner_id = o.id
        ORDER BY v.registration_date DESC
        LIMIT 5
    ");
    $recentRegistrations = $stmt->fetchAll();
    
} catch (PDOException $e) {
    // Handle database errors
    $error = "Database error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | Vehicle Registration System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <!-- Custom CSS -->
    <style>
        /* Dashboard Styles */
        body {
            background-color: #f5f5f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .main-content {
            padding: 25px 15px;
        }
        
        /* Fix for Stat Cards */
        .stat-card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
            overflow: hidden;
            height: 100%;
            position: relative;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }
        
        .stat-card .card-body {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            padding: 1.25rem;
            min-height: 140px;
        }
        
        .card-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            margin-bottom: 15px;
            color: #fff;
        }
        
        .card-icon i {
            font-size: 1.5rem;
        }
        
        .card-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .card-label {
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            font-weight: 500;
        }
        
        /* Background colors for icons */
        .bg-primary {
            background-color: #0d6efd;
        }
        
        .bg-success {
            background-color: #198754;
        }
        
        .bg-warning {
            background-color: #ffc107;
        }
        
        .bg-info {
            background-color: #0dcaf0;
        }
        
        .bg-purple {
            background-color: #6f42c1;
        }
        
        /* Quick action buttons */
        .btn-outline-primary:hover,
        .btn-outline-success:hover,
        .btn-outline-info:hover,
        .btn-outline-danger:hover,
        .btn-outline-purple:hover {
            color: #fff;
        }
        
        .btn-outline-purple {
            color: #6f42c1;
            border-color: #6f42c1;
        }
        
        .btn-outline-purple:hover {
            background-color: #6f42c1;
            border-color: #6f42c1;
        }
        
        /* Action cards */
        .action-card {
            transition: transform 0.2s;
            border-radius: 10px;
            overflow: hidden;
            height: 100%;
        }
        
        .action-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }
        
        /* Recent registrations table */
        .recent-table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        
        /* Auto fading alert for notifications */
        .auto-fade {
            animation: fadeOut 0.5s ease 5s forwards;
        }
        
        @keyframes fadeOut {
            from { opacity: 1; }
            to { opacity: 0; }
        }
    </style>
</head>
<body>
    <?php
    include(__DIR__ . '/../../../includes/header.php');
    ?>
    <div class="container main-content">
        <div class="row mb-4">
            <div class="col-12">
                <h2>Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?></h2>
                <p>This is your admin dashboard for the Vehicle Registration System.</p>
            </div>
        </div>
        
        <!-- Simple Dashboard Stats -->
        <div class="row g-3 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="card stat-card">
                    <div class="card-body">
                        <div class="card-icon bg-primary">
                            <i class="fas fa-car"></i>
                        </div>
                        <div class="card-value"><?= number_format($totalVehicles ?? 0) ?></div>
                        <div class="card-label">Total Vehicles</div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6">
                <div class="card stat-card">
                    <div class="card-body">
                        <div class="card-icon bg-success">
                            <i class="fas fa-calendar-day"></i>
                        </div>
                        <div class="card-value"><?= number_format($todayRegistrations ?? 0) ?></div>
                        <div class="card-label">Today's Registrations</div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6">
                <div class="card stat-card">
                    <div class="card-body">
                        <div class="card-icon bg-warning">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="card-value"><?= number_format($totalOwners ?? 0) ?></div>
                        <div class="card-label">Vehicle Owners</div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6">
                <div class="card stat-card">
                    <div class="card-body">
                        <div class="card-icon bg-info">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <div class="card-value"><?= number_format($totalUsers ?? 0) ?></div>
                        <div class="card-label">System Users</div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <a href="register-vehicle.php" class="btn btn-outline-primary w-100">
                                    <i class="fas fa-car me-2"></i> New Vehicle Registration
                                </a>
                            </div>
                            <div class="col-md-3 mb-3">
                                <a href="add-owner.php" class="btn btn-outline-success w-100">
                                    <i class="fas fa-user-plus me-2"></i> Add Vehicle Owner
                                </a>
                            </div>
                            <div class="col-md-3 mb-3">
                                <a href="../vehicle/search.php" class="btn btn-outline-info w-100">
                                    <i class="fas fa-search me-2"></i> Search Database
                                </a>
                            </div>
                            <div class="col-md-3 mb-3">
                                <a href="generate-report.php" class="btn btn-outline-danger w-100">
                                    <i class="fas fa-file-export me-2"></i> Generate Report
                                </a>
                            </div>
                        </div>
                        
                        <!-- Updated Action Buttons Row with Upload Insurance -->
                        <div class="row mt-2">
                            <div class="col-md-3 mb-3">
                                <a href="generateqr.php" class="btn btn-outline-purple w-100">
                                    <i class="fas fa-qrcode me-2"></i> Generate QR Code
                                </a>
                            </div>
                            <div class="col-md-3 mb-3">
                                <a href="view-certificates.php" class="btn btn-outline-primary w-100">
                                    <i class="fas fa-file-pdf me-2"></i> View Certificates
                                </a>
                            </div>
                            <div class="col-md-3 mb-3">
                                <a href="upload-insurance.php" class="btn btn-outline-success w-100">
                                    <i class="fas fa-file-upload me-2"></i> Upload Insurance
                                </a>
                            </div>
                            <div class="col-md-3 mb-3">
                                <a href="settings.php" class="btn btn-outline-secondary w-100">
                                    <i class="fas fa-cogs me-2"></i> System Settings
                                </a>
                            </div>
                        </div>
                        
                        <!-- Third row for Manage Vehicles and future actions -->
                        <div class="row mt-2">
                            <div class="col-md-3 mb-3">
                                <a href="all-vehicles.php" class="btn btn-outline-warning w-100">
                                    <i class="fas fa-users-cog me-2"></i> Manage Vehicles
                                </a>
                            </div>
                            <div class="col-md-3 mb-3">
                                <a href="admin-tools.php" class="btn btn-outline-info w-100">
                                    <i class="fas fa-tools me-2"></i> Admin Tools
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Document Management Section -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="fas fa-file-alt me-2"></i>Document Management</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <div class="card action-card h-100">
                                    <div class="card-body text-center">
                                        <i class="fas fa-file-upload fa-3x text-success mb-3"></i>
                                        <h5>Insurance Certificates</h5>
                                        <p class="text-muted">Upload and manage vehicle insurance documents</p>
                                        <a href="upload-insurance.php" class="btn btn-outline-success">
                                            <i class="fas fa-upload me-2"></i>Upload Insurance
                                        </a>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-4 mb-3">
                                <div class="card action-card h-100">
                                    <div class="card-body text-center">
                                        <i class="fas fa-file-pdf fa-3x text-danger mb-3"></i>
                                        <h5>Registration Certificates</h5>
                                        <p class="text-muted">View and print vehicle registration certificates</p>
                                        <a href="view-certificates.php" class="btn btn-outline-danger">
                                            <i class="fas fa-print me-2"></i>Print Certificates
                                        </a>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-4 mb-3">
                                <div class="card action-card h-100">
                                    <div class="card-body text-center">
                                        <i class="fas fa-qrcode fa-3x text-primary mb-3"></i>
                                        <h5>QR Codes</h5>
                                        <p class="text-muted">Generate and manage vehicle QR codes</p>
                                        <a href="generateqr.php" class="btn btn-outline-primary">
                                            <i class="fas fa-qrcode me-2"></i>Generate QR Codes
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Recent Registrations -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fas fa-history me-2"></i>Recent Vehicle Registrations</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($recentRegistrations)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover recent-table">
                                    <thead>
                                        <tr>
                                            <th>Reg. Number</th>
                                            <th>Make & Model</th>
                                            <th>Color</th>
                                            <th>Owner</th>
                                            <th>Registration Date</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentRegistrations as $vehicle): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($vehicle['registration_number']) ?></td>
                                                <td><?= htmlspecialchars($vehicle['make'] . ' ' . $vehicle['model']) ?></td>
                                                <td>
                                                    <span class="d-inline-block rounded-circle me-2" 
                                                          style="width: 15px; height: 15px; background-color: <?= htmlspecialchars($vehicle['color']) ?>; 
                                                                 border: 1px solid #dee2e6;"></span>
                                                    <?= htmlspecialchars($vehicle['color']) ?>
                                                </td>
                                                <td><?= htmlspecialchars($vehicle['owner_name']) ?></td>
                                                <td><?= date('M j, Y', strtotime($vehicle['registration_date'])) ?></td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="/src/views/vehicle/view-details.php?id=<?= $vehicle['id'] ?>" class="btn btn-outline-primary" title="View Details">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <a href="edit-vehicle.php?id=<?= $vehicle['id'] ?>" class="btn btn-outline-secondary" title="Edit Vehicle">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <a href="generateqr.php?id=<?= $vehicle['id'] ?>" class="btn btn-outline-info" title="Generate QR Code">
                                                            <i class="fas fa-qrcode"></i>
                                                        </a>
                                                        <a href="upload-insurance.php?vehicle_id=<?= $vehicle['id'] ?>" class="btn btn-outline-success" title="Upload Insurance">
                                                            <i class="fas fa-file-upload"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="text-end mt-3">
                                <a href="all-vehicles.php" class="btn btn-sm btn-primary">
                                    <i class="fas fa-list me-2"></i>View All Vehicles
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info mb-0">
                                <i class="fas fa-info-circle me-2"></i>No recent vehicle registrations found.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
      </div>  
    <?php
    include(__DIR__ . '/../../../includes/footer.php');
    ?>
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>