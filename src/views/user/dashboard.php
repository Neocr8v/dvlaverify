<?php
session_start();
require_once __DIR__ . '/../../../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../views/auth/login.php');
    exit;
}

// Check if user has the right role for this page
if ($_SESSION['role'] !== 'user') {
    if ($_SESSION['role'] === 'admin') {
        header('Location: ../../views/admin/dashboard.php');
    } else {
        header('Location: ../../views/auth/login.php');
    }
    exit;
}

// Initialize variables
$userId = $_SESSION['user_id'];
$ownerData = null;
$vehicles = [];
$error = '';

try {
    // First, get the user's basic information
    $userStmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
    $userStmt->execute(['id' => $userId]);
    $user = $userStmt->fetch();
    
    if (!$user) {
        // User not found in database
        header('Location: ../../controllers/auth/logout.php');
        exit;
    }
    
    // NEW APPROACH: Check if this user information matches with any vehicle owner
    // Try to match with any of these: user_id (if it exists), name, email, ghana_card_number
    
    // First, check if user table has ghana_card_number column
    $hasGhanaCardColumn = false;
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM users LIKE 'ghana_card_number'");
        $stmt->execute();
        $hasGhanaCardColumn = ($stmt->rowCount() > 0);
    } catch (PDOException $e) {
        // Ignore error, assume column doesn't exist
    }
    
    // Build the query based on what's available
    $query = "SELECT * FROM vehicle_owners WHERE 1=0"; // Start with impossible condition
    $params = [];
    
    // Check if there's a direct link column (user_id) in vehicle_owners table
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM vehicle_owners LIKE 'user_id'");
        $stmt->execute();
        $hasUserIdColumn = ($stmt->rowCount() > 0);
        
        if ($hasUserIdColumn) {
            $query .= " OR user_id = :user_id";
            $params['user_id'] = $userId;
        }
    } catch (PDOException $e) {
        // Ignore error, assume column doesn't exist
    }
    
    // Try to match by name
    $query .= " OR LOWER(name) = LOWER(:name)";
    $params['name'] = $user['full_name'];
    
    // Try to match by email
    $query .= " OR (email IS NOT NULL AND LOWER(email) = LOWER(:email))";
    $params['email'] = $user['email'];
    
    // Try to match by Ghana Card number if available
    if ($hasGhanaCardColumn && !empty($user['ghana_card_number'])) {
        $query .= " OR ghana_card_number = :ghana_card";
        $params['ghana_card'] = $user['ghana_card_number'];
    }
    
    // Add LIMIT 1 to get just the first match
    $query .= " LIMIT 1";
    
    // Execute the query
    $ownerStmt = $pdo->prepare($query);
    $ownerStmt->execute($params);
    $ownerData = $ownerStmt->fetch();
    
    // If we found an owner record, get their vehicles
    if ($ownerData) {
        $vehicleStmt = $pdo->prepare("
            SELECT v.*, 
                   DATE_FORMAT(v.registration_date, '%d %b %Y') as formatted_reg_date,
                   DATE_FORMAT(v.expiry_date, '%d %b %Y') as formatted_expiry_date,
                   DATEDIFF(v.expiry_date, CURDATE()) as days_to_expiry
            FROM vehicles v
            WHERE v.owner_id = :owner_id
            ORDER BY v.registration_date DESC
        ");
        $vehicleStmt->execute(['owner_id' => $ownerData['id']]);
        $vehicles = $vehicleStmt->fetchAll();
    }
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Vehicle Registration System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .profile-card {
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .profile-header {
            background-color: #0d6efd;
            color: white;
            padding: 20px;
        }
        
        .owner-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background-color: #0d6efd;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 40px;
            color: white;
            margin: 0 auto 15px;
        }
        
        .vehicle-card {
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            transition: transform 0.2s;
        }
        
        .vehicle-card:hover {
            transform: translateY(-5px);
        }
        
        .vehicle-header {
            background-color: #0d6efd;
            color: white;
            padding: 15px;
        }
        
        .qr-code-container {
            background-color: #f8f9fa;
            padding: 15px;
            text-align: center;
            border-radius: 5px;
            margin-top: 15px;
        }
        
        .quick-stats .card {
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            transition: transform 0.2s;
            height: 100%;
        }
        
        .quick-stats .card:hover {
            transform: translateY(-5px);
        }
        
        .status-badge {
            position: absolute;
            top: 15px;
            right: 15px;
        }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../../../includes/header.php'; ?>
    
    <div class="container mt-4">
        <div class="row">
            <!-- Sidebar/User Profile -->
            <div class="col-lg-3 mb-4">
                <div class="profile-card">
                    <div class="profile-header text-center">
                        <h4><?= htmlspecialchars($user['full_name']) ?></h4>
                        <p class="mb-0"><?= htmlspecialchars($user['email']) ?></p>
                    </div>
                    <div class="list-group list-group-flush">
                        <a href="dashboard.php" class="list-group-item list-group-item-action active">
                            <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                        </a>
                        <a href="my-vehicles.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-car me-2"></i>My Vehicles
                        </a>
                        <a href="profile.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-user-circle me-2"></i>My Profile
                        </a>
                        <a href="/src/views/auth/logout.php" class="list-group-item list-group-item-action text-danger">
                            <i class="fas fa-sign-out-alt me-2"></i>Logout
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="col-lg-9">
                <!-- Welcome Card -->
                <div class="card mb-4">
                    <div class="card-body">
                        <h3>Welcome, <?= htmlspecialchars($user['full_name']) ?>!</h3>
                        <p>This is your personal dashboard where you can manage your vehicles and registration details.</p>
                    </div>
                </div>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i><?= $error ?>
                    </div>
                <?php endif; ?>
                
                <!-- Owner Information -->
<div class="card mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="fas fa-id-card me-2"></i>Your Registration Details</h5>
    </div>
    <div class="card-body">
        <?php if ($ownerData): ?>
            <div class="row">
                <div class="col-md-3 text-center">
                            <?php if (!empty($ownerData['passport_photo_path'])): ?>
                                <div class="owner-photo mb-2">
                                    <img src="<?= $baseUrl ?>/<?= htmlspecialchars($ownerData['passport_photo_path']) ?>" 
                                        alt="<?= htmlspecialchars($ownerData['name']) ?>" 
                                        class="img-fluid rounded-circle" 
                                        style="width: 120px; height: 120px; object-fit: cover; border: 3px solid #0d6efd;">
                                </div>
                    <?php else: ?>
                        <div class="owner-avatar">
                            <?= strtoupper(substr($ownerData['name'], 0, 1)) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="col-md-9">
                    <h4><?= htmlspecialchars($ownerData['name']) ?></h4>
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <p><strong>Ghana Card:</strong><br> <?= htmlspecialchars($ownerData['ghana_card_number']) ?></p>
                            <p><strong>Phone:</strong><br> <?= htmlspecialchars($ownerData['phone']) ?></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Email:</strong><br> <?= htmlspecialchars($ownerData['email'] ?? 'Not provided') ?></p>
                            <p><strong>Address:</strong><br> <?= htmlspecialchars($ownerData['address'] ?? 'Not provided') ?></p>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>No vehicle owner information found linked to your account.</strong>
                <p class="mb-0 mt-2">If you believe this is an error, please contact DVLA support with your Ghana Card details to link your records.</p>
            </div>
        <?php endif; ?>
    </div>
</div>
                
                <!-- Quick Stats -->
                <?php if ($ownerData && count($vehicles) > 0): ?>
                    <div class="row quick-stats mb-4">
                        <div class="col-md-4 mb-3">
                            <div class="card bg-primary text-white">
                                <div class="card-body">
                                    <h5 class="card-title">Total Vehicles</h5>
                                    <p class="display-4"><?= count($vehicles) ?></p>
                                    <p class="card-text"><i class="fas fa-car me-1"></i> Registered in your name</p>
                                </div>
                            </div>
                        </div>
                        
                        <?php 
                        $expiredCount = 0;
                        $expiringSoonCount = 0;
                        
                        foreach ($vehicles as $vehicle) {
                            if ($vehicle['days_to_expiry'] < 0) {
                                $expiredCount++;
                            } elseif ($vehicle['days_to_expiry'] <= 30) {
                                $expiringSoonCount++;
                            }
                        }
                        ?>
                        
                        <div class="col-md-4 mb-3">
                            <div class="card bg-warning text-dark">
                                <div class="card-body">
                                    <h5 class="card-title">Expiring Soon</h5>
                                    <p class="display-4"><?= $expiringSoonCount ?></p>
                                    <p class="card-text"><i class="fas fa-exclamation-triangle me-1"></i> Within 30 days</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <div class="card bg-danger text-white">
                                <div class="card-body">
                                    <h5 class="card-title">Expired</h5>
                                    <p class="display-4"><?= $expiredCount ?></p>
                                    <p class="card-text"><i class="fas fa-exclamation-circle me-1"></i> Needs renewal</p>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Vehicle Information -->
                <div class="card">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-car me-2"></i>Your Vehicles</h5>
                        <?php if ($ownerData && count($vehicles) > 0): ?>
                            <a href="my-vehicles.php" class="btn btn-sm btn-light">View All</a>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if ($ownerData && count($vehicles) > 0): ?>
                            <?php 
                            // Show at most 2 vehicles on the dashboard
                            $displayVehicles = array_slice($vehicles, 0, 2);
                            ?>
                            
                            <?php foreach ($displayVehicles as $vehicle): ?>
                                <div class="vehicle-card position-relative mb-4">
                                    <?php 
                                    $statusClass = 'success';
                                    $statusText = 'Active';
                                    
                                    if ($vehicle['days_to_expiry'] < 0) {
                                        $statusClass = 'danger';
                                        $statusText = 'Expired';
                                    } elseif ($vehicle['days_to_expiry'] <= 30) {
                                        $statusClass = 'warning';
                                        $statusText = 'Expiring Soon';
                                    }
                                    ?>
                                    <span class="badge bg-<?= $statusClass ?> status-badge"><?= $statusText ?></span>
                                    
                                    <div class="vehicle-header">
                                        <h5 class="mb-0">
                                            <?= htmlspecialchars($vehicle['registration_number']) ?> - 
                                            <?= htmlspecialchars($vehicle['make'] . ' ' . $vehicle['model']) ?>
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-8">
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <p><strong>Color:</strong> <?= htmlspecialchars($vehicle['color'] ?? 'N/A') ?></p>
                                                        <p><strong>Year:</strong> <?= htmlspecialchars($vehicle['year_of_manufacture']) ?></p>
                                                        <p><strong>Vehicle Type:</strong> <?= htmlspecialchars($vehicle['vehicle_type'] ?? 'N/A') ?></p>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <p><strong>Registration Date:</strong> <?= $vehicle['formatted_reg_date'] ?></p>
                                                        <p><strong>Expiry Date:</strong> <?= $vehicle['formatted_expiry_date'] ?></p>
                                                        <p>
                                                            <strong>Status:</strong> 
                                                            <span class="text-<?= $statusClass ?>">
                                                                <?= $statusText ?>
                                                                <?php if ($vehicle['days_to_expiry'] > 0): ?>
                                                                    (<?= $vehicle['days_to_expiry'] ?> days left)
                                                                <?php elseif ($vehicle['days_to_expiry'] < 0): ?>
                                                                    (<?= abs($vehicle['days_to_expiry']) ?> days ago)
                                                                <?php endif; ?>
                                                            </span>
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <?php if (!empty($vehicle['qr_code_path'])): ?>
                                                    <div class="qr-code-container">
                                                        <img src="/<?= htmlspecialchars($vehicle['qr_code_path']) ?>" alt="QR Code" class="img-fluid mb-2" style="max-width: 150px;">
                                                        <a href="download-qr.php?id=<?= $vehicle['id'] ?>" class="btn btn-sm btn-primary">
                                                            <i class="fas fa-download me-1"></i> Download QR
                                                        </a>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="alert alert-info text-center h-100 d-flex align-items-center justify-content-center">
                                                        <div>
                                                            <i class="fas fa-qrcode fa-2x mb-2"></i>
                                                            <p>QR code not available</p>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                                        <div class="mt-3">
                                            <a href="vehicle-details.php?id=<?= $vehicle['id'] ?>" class="btn btn-outline-primary">
                                                <i class="fas fa-info-circle me-1"></i> View Details
                                            </a>
                                            <?php if (!empty($vehicle['certificate_pdf_path'])): ?>
                                                <a href="/dvlaregister/<?= htmlspecialchars($vehicle['certificate_pdf_path']) ?>" class="btn btn-outline-success ms-2" target="_blank">
                                                    <i class="fas fa-file-pdf me-1"></i> View Certificate
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <?php if (count($vehicles) > 2): ?>
                                <div class="text-center mt-3">
                                    <a href="my-vehicles.php" class="btn btn-primary">
                                        <i class="fas fa-car me-1"></i> View All <?= count($vehicles) ?> Vehicles
                                    </a>
                                </div>
                            <?php endif; ?>
                            
                        <?php elseif ($ownerData): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-car-crash fa-4x text-muted mb-3"></i>
                                <h4>No Vehicles Found</h4>
                                <p>You don't have any registered vehicles yet. If you believe this is an error, please contact DVLA support.</p>
                                <a href="contact-support.php" class="btn btn-outline-primary mt-2">Contact Support</a>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-link-slash fa-4x text-muted mb-3"></i>
                                <h4>Account Not Linked</h4>
                                <p>Your user account is not linked with any vehicle owner record in our system.</p>
                                <p>Please visit any DVLA office with your Ghana Card to link your account to your vehicle ownership records.</p>
                                <div class="alert alert-info mt-3">
                                    <i class="fas fa-info-circle me-2"></i>
                                    <strong>How to link your account:</strong>
                                    <ol class="mb-0 mt-2 text-start">
                                        <li>Visit any DVLA office with your Ghana Card</li>
                                        <li>Request to link your online account to your vehicle records</li>
                                        <li>Provide your Ghana Card and this username: <strong><?= htmlspecialchars($user['username']) ?></strong></li>
                                        <li>Once linked, your vehicles will appear here</li>
                                    </ol>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php include_once __DIR__ . '/../../../includes/footer.php'; ?>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>