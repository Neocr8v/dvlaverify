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

// Check if vehicle ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: dashboard.php');
    exit;
}

$vehicleId = $_GET['id'];
$vehicle = null;
$ownerData = null;
$error = '';
$success = '';
$timestamp = time(); // Used to prevent caching

// Check for success message
if (isset($_GET['success'])) {
    $success = $_GET['success'];
}

// Helper function to determine vehicle use if not explicitly set
function determineVehicleUse($vehicleType) {
    $commercialTypes = ['taxi', 'bus', 'truck', 'commercial'];
    
    if (in_array(strtolower($vehicleType), $commercialTypes)) {
        return 'Commercial';
    }
    
    return 'Private';
}

// Check if insurance columns exist
$insuranceColumnsExist = false;
try {
    $checkColumnStmt = $pdo->prepare("SHOW COLUMNS FROM vehicles LIKE 'insurance_provider'");
    $checkColumnStmt->execute();
    $insuranceColumnsExist = ($checkColumnStmt->rowCount() > 0);
} catch (PDOException $e) {
    // Ignore errors checking columns
}

// Check if roadworthy columns exist
$roadworthyColumnsExist = false;
try {
    $checkColumnStmt = $pdo->prepare("SHOW COLUMNS FROM vehicles LIKE 'roadworthy_certificate_path'");
    $checkColumnStmt->execute();
    $roadworthyColumnsExist = ($checkColumnStmt->rowCount() > 0);
} catch (PDOException $e) {
    // Ignore errors checking columns
}

// Check if vehicle_use column exists
$vehicleUseExists = false;
try {
    $checkColumnStmt = $pdo->prepare("SHOW COLUMNS FROM vehicles LIKE 'vehicle_use'");
    $checkColumnStmt->execute();
    $vehicleUseExists = ($checkColumnStmt->rowCount() > 0);
} catch (PDOException $e) {
    // Ignore errors checking columns
}

try {
    // First, get the user's basic information
    $userStmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
    $userStmt->execute(['id' => $_SESSION['user_id']]);
    $user = $userStmt->fetch();
    
    if (!$user) {
        header('Location: ../../controllers/auth/logout.php');
        exit;
    }
    
    // Build the query based on which columns exist in the database
    $selectFields = "v.*, 
               DATE_FORMAT(v.registration_date, '%d %b %Y') as formatted_reg_date,
               DATE_FORMAT(v.expiry_date, '%d %b %Y') as formatted_expiry_date,
               DATEDIFF(v.expiry_date, CURDATE()) as days_to_expiry,
               vo.name as owner_name,
               vo.ghana_card_number,
               vo.phone as owner_phone,
               vo.email as owner_email,
               vo.address as owner_address";
    
    // Add insurance-related fields if they exist
    if ($insuranceColumnsExist) {
        $selectFields .= ", v.insurance_provider, v.policy_number, v.insurance_expiry, v.insurance_certificate_path";
    }
    
    // Add roadworthy-related fields if they exist
    if ($roadworthyColumnsExist) {
        $selectFields .= ", v.roadworthy_certificate_path, v.roadworthy_expiry_date";
    }
    
    // Add vehicle_use if it exists
    if ($vehicleUseExists) {
        $selectFields .= ", v.vehicle_use";
    }
    
    // Get vehicle details with owner information
    $vehicleStmt = $pdo->prepare("
        SELECT $selectFields
        FROM vehicles v
        JOIN vehicle_owners vo ON v.owner_id = vo.id
        WHERE v.id = :vehicle_id
    ");
    $vehicleStmt->execute(['vehicle_id' => $vehicleId]);
    $vehicle = $vehicleStmt->fetch();
    
    if (!$vehicle) {
        $error = "Vehicle not found or you don't have permission to view it.";
    } else {
        // If vehicle_use is not set in the database, determine it from the vehicle_type
        if (!$vehicleUseExists || empty($vehicle['vehicle_use'])) {
            $vehicle['vehicle_use'] = determineVehicleUse($vehicle['vehicle_type']);
        }
        
        // Verify that the current user is authorized to view this vehicle
        $ownerStmt = $pdo->prepare("
            SELECT vo.* FROM vehicle_owners vo
            WHERE vo.id = :owner_id AND (
                LOWER(vo.name) = LOWER(:user_name)
                OR (vo.email IS NOT NULL AND LOWER(vo.email) = LOWER(:user_email))
            )
        ");
        $ownerStmt->execute([
            'owner_id' => $vehicle['owner_id'],
            'user_name' => $user['full_name'],
            'user_email' => $user['email']
        ]);
        
        $ownerData = $ownerStmt->fetch();
        
        if (!$ownerData) {
            // Check if the user has a ghana_card_number column and if it matches
            $hasGhanaCardColumn = false;
            try {
                $stmt = $pdo->prepare("SHOW COLUMNS FROM users LIKE 'ghana_card_number'");
                $stmt->execute();
                $hasGhanaCardColumn = ($stmt->rowCount() > 0);
            } catch (PDOException $e) {
                // Ignore error, assume column doesn't exist
            }
            
            if ($hasGhanaCardColumn) {
                $ownerStmt = $pdo->prepare("
                    SELECT vo.* FROM vehicle_owners vo
                    WHERE vo.id = :owner_id AND vo.ghana_card_number = :ghana_card_number
                ");
                $ownerStmt->execute([
                    'owner_id' => $vehicle['owner_id'],
                    'ghana_card_number' => $user['ghana_card_number']
                ]);
                $ownerData = $ownerStmt->fetch();
            }
            
            if (!$ownerData) {
                $error = "You don't have permission to view this vehicle.";
                $vehicle = null; // Clear vehicle data since user isn't authorized
            }
        }
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
    <title>Vehicle Details | DVLA Registration System</title>
    <!-- Meta tag to prevent caching -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    
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
        
        .detail-section {
            margin-bottom: 2rem;
            border-bottom: 1px solid #eee;
            padding-bottom: 1.5rem;
        }
        
        .detail-section:last-child {
            border-bottom: none;
        }
        
        .detail-section h4 {
            margin-bottom: 1rem;
            color: #0d6efd;
        }
        
        .qr-code-container {
            background-color: #f8f9fa;
            padding: 20px;
            text-align: center;
            border-radius: 10px;
            margin-top: 15px;
        }
        
        .document-box {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 15px;
            transition: transform 0.2s;
        }
        
        .document-box:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.1);
        }
        
        .vehicle-header {
            background: linear-gradient(to right, #0d6efd, #0a58ca);
            color: white;
            padding: 20px;
            border-radius: 10px 10px 0 0;
        }

        /* Vehicle Use Badge Styles */
        .vehicle-use-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 14px;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .vehicle-use-private {
            background-color: #cfe2ff;
            color: #0a58ca;
            border: 1px solid #9ec5fe;
        }
        
        .vehicle-use-commercial {
            background-color: #f8d7da;
            color: #842029;
            border: 1px solid #f5c2c7;
        }
        
        .vehicle-use-government {
            background-color: #d1e7dd;
            color: #146c43;
            border: 1px solid #a3cfbb;
        }
        
        .vehicle-use-diplomatic {
            background-color: #fff3cd;
            color: #664d03;
            border: 1px solid #ffecb5;
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
                        <a href="dashboard.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                        </a>
                        <a href="my-vehicles.php" class="list-group-item list-group-item-action active">
                            <i class="fas fa-car me-2"></i>My Vehicles
                        </a>
                        <a href="profile.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-user-circle me-2"></i>My Profile
                        </a>
                        <a href="../../controllers/auth/logout.php" class="list-group-item list-group-item-action text-danger">
                            <i class="fas fa-sign-out-alt me-2"></i>Logout
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="col-lg-9">
                <!-- Page Header -->
                <nav aria-label="breadcrumb" class="mb-4">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="my-vehicles.php">My Vehicles</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Vehicle Details</li>
                    </ol>
                </nav>
                
                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-2"></i><?= $success ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i><?= $error ?>
                        <div class="mt-3">
                            <a href="dashboard.php" class="btn btn-primary">Go to Dashboard</a>
                        </div>
                    </div>
                <?php elseif ($vehicle): ?>
                    <!-- Vehicle Details Card -->
                    <div class="card mb-4">
                        <div class="vehicle-header">
                            <div class="d-flex justify-content-between align-items-center">
                                <h3 class="mb-0">
                                    <?= htmlspecialchars($vehicle['registration_number']) ?>
                                </h3>
                                
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
                                <span class="badge bg-<?= $statusClass ?> fs-6">
                                    <?= $statusText ?>
                                    <?php if ($vehicle['days_to_expiry'] > 0): ?>
                                        (<?= $vehicle['days_to_expiry'] ?> days left)
                                    <?php elseif ($vehicle['days_to_expiry'] < 0): ?>
                                        (<?= abs($vehicle['days_to_expiry']) ?> days ago)
                                    <?php endif; ?>
                                </span>
                            </div>
                            <h5 class="mb-0 mt-2">
                                <?= htmlspecialchars($vehicle['make'] . ' ' . $vehicle['model']) ?> (<?= htmlspecialchars($vehicle['year_of_manufacture']) ?>)
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <!-- Left Column: Vehicle Information -->
                                <div class="col-md-8">
                                    <!-- Basic Information -->
                                    <div class="detail-section">
                                        <h4><i class="fas fa-info-circle me-2"></i>Basic Information</h4>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <p><strong>Make:</strong> <?= htmlspecialchars($vehicle['make']) ?></p>
                                                <p><strong>Model:</strong> <?= htmlspecialchars($vehicle['model']) ?></p>
                                                <p><strong>Year:</strong> <?= htmlspecialchars($vehicle['year_of_manufacture']) ?></p>
                                                <p><strong>Color:</strong> <?= htmlspecialchars($vehicle['color'] ?? 'N/A') ?></p>
                                            </div>
                                            <div class="col-md-6">
                                                <p><strong>Vehicle Type:</strong> <?= htmlspecialchars($vehicle['vehicle_type'] ?? 'N/A') ?></p>
                                                <p>
                                                    <strong>Vehicle Use:</strong>
                                                   <?php 
                                                // Display vehicle use as regular text without badge styling
                                                $vehicleUse = $vehicle['vehicle_use'];
                                                echo htmlspecialchars($vehicleUse);
                                                ?>
                                                </p>
                                                <p><strong>Engine Capacity:</strong> <?= $vehicle['engine_capacity'] ? htmlspecialchars($vehicle['engine_capacity'].' cc') : 'N/A' ?></p>
                                                <p><strong>Seating Capacity:</strong> <?= $vehicle['seating_capacity'] ? htmlspecialchars($vehicle['seating_capacity']) : 'N/A' ?></p>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Technical Details -->
                                    <div class="detail-section">
                                        <h4><i class="fas fa-cogs me-2"></i>Technical Details</h4>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <p><strong>Chassis Number:</strong> <?= htmlspecialchars($vehicle['chassis_number'] ?? 'N/A') ?></p>
                                            </div>
                                            <div class="col-md-6">
                                                <p><strong>Engine Number:</strong> <?= htmlspecialchars($vehicle['engine_number'] ?? 'N/A') ?></p>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Registration Information -->
                                    <div class="detail-section">
                                        <h4><i class="fas fa-calendar-alt me-2"></i>Registration Information</h4>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <p><strong>Registration Number:</strong> <?= htmlspecialchars($vehicle['registration_number']) ?></p>
                                                <p><strong>Registration Date:</strong> <?= $vehicle['formatted_reg_date'] ?></p>
                                            </div>
                                            <div class="col-md-6">
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
                                    
                                    <!-- Owner Information -->
                                    <div class="detail-section">
                                        <h4><i class="fas fa-user me-2"></i>Owner Information</h4>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <p><strong>Owner Name:</strong> <?= htmlspecialchars($vehicle['owner_name']) ?></p>
                                                <p><strong>Ghana Card:</strong> <?= htmlspecialchars($vehicle['ghana_card_number']) ?></p>
                                            </div>
                                            <div class="col-md-6">
                                                <p><strong>Phone:</strong> <?= htmlspecialchars($vehicle['owner_phone'] ?? 'N/A') ?></p>
                                                <p><strong>Email:</strong> <?= htmlspecialchars($vehicle['owner_email'] ?? 'N/A') ?></p>
                                            </div>
                                        </div>
                                        <p><strong>Address:</strong> <?= htmlspecialchars($vehicle['owner_address'] ?? 'N/A') ?></p>
                                    </div>
                                </div>
                                
                                <!-- Right Column: QR Code and Documents -->
                                <div class="col-md-4">
                                    <!-- QR Code Section -->
                                    <?php if (!empty($vehicle['qr_code_path'])): ?>
                                        <div class="qr-code-container mb-4">
                                            <p class="fw-bold">Vehicle QR Code</p>
                                            <img src="/<?= htmlspecialchars($vehicle['qr_code_path']) ?>?v=<?= $timestamp ?>" alt="QR Code" class="img-fluid mb-3" style="max-width: 200px;">
                                            <div>
                                                <a href="download-qr.php?id=<?= $vehicle['id'] ?>" class="btn btn-primary">
                                                    <i class="fas fa-download me-1"></i> Download QR
                                                </a>
                                            </div>
                                            <small class="text-muted mt-2 d-block">
                                                This QR code contains your vehicle registration information.
                                                Keep it accessible for verification purposes.
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- Insurance Information Card -->
                                    <?php 
                                    // Check if insurance data exists in the vehicle record
                                    $hasInsurance = $insuranceColumnsExist && 
                                                !empty($vehicle['insurance_provider']) && 
                                                !empty($vehicle['policy_number']) && 
                                                !empty($vehicle['insurance_expiry']);
                                    
                                    // Calculate insurance status
                                    $insuranceStatus = 'Invalid';
                                    $insuranceStatusClass = 'danger';
                                    
                                    if ($hasInsurance) {
                                        $insuranceExpiry = strtotime($vehicle['insurance_expiry']);
                                        $today = strtotime('today');
                                        $daysToInsuranceExpiry = floor(($insuranceExpiry - $today) / (60 * 60 * 24));
                                        
                                        if ($daysToInsuranceExpiry > 30) {
                                            $insuranceStatus = 'Valid';
                                            $insuranceStatusClass = 'success';
                                        } elseif ($daysToInsuranceExpiry >= 0) {
                                            $insuranceStatus = 'Expiring Soon';
                                            $insuranceStatusClass = 'warning';
                                        } else {
                                            $insuranceStatus = 'Expired';
                                            $insuranceStatusClass = 'danger';
                                        }
                                    }
                                    ?>
                                    
                                    <?php if ($hasInsurance): ?>
                                    <div class="card mb-4">
                                        <div class="card-header bg-info text-white">
                                            <h5 class="mb-0"><i class="fas fa-file-contract me-2"></i>Insurance Information</h5>
                                        </div>
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-center mb-3">
                                                <span class="fw-bold">Status:</span>
                                                <span class="badge bg-<?= $insuranceStatusClass ?>"><?= $insuranceStatus ?></span>
                                            </div>
                                            
                                            <div class="mb-2">
                                                <small class="text-muted">Provider:</small>
                                                <div class="fw-bold"><?= htmlspecialchars($vehicle['insurance_provider']) ?></div>
                                            </div>
                                            
                                            <div class="mb-2">
                                                <small class="text-muted">Policy Number:</small>
                                                <div class="fw-bold"><?= htmlspecialchars($vehicle['policy_number']) ?></div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <small class="text-muted">Expiry Date:</small>
                                                <div class="fw-bold"><?= date('d M Y', strtotime($vehicle['insurance_expiry'])) ?></div>
                                            </div>
                                            
                                            <?php if (!empty($vehicle['insurance_certificate_path'])): ?>
                                                <div class="text-center">
                                                    <a href="/<?= htmlspecialchars($vehicle['insurance_certificate_path']) ?>" 
                                                    class="btn btn-sm btn-outline-info" target="_blank">
                                                        <i class="fas fa-file-alt me-1"></i> View Certificate
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <!-- Documents Section -->
                                    <div class="card mb-4">
                                        <div class="card-header bg-light">
                                            <h5 class="mb-0"><i class="fas fa-file-alt me-2"></i>Documents</h5>
                                        </div>
                                        <div class="card-body">
                                            <!-- Registration Certificate -->
                                            <div class="document-box">
                                                <div class="d-flex align-items-center">
                                                    <i class="fas fa-file-pdf fa-2x text-danger me-3"></i>
                                                    <div>
                                                        <h6 class="mb-1">Registration Certificate</h6>
                                                        <small class="text-muted">Official vehicle registration document</small>
                                                    </div>
                                                </div>
                                                <div class="mt-3">
                                                    <?php if (!empty($vehicle['certificate_pdf_path'])): ?>
                                                        <!-- Use existing certificate if available -->
                                                        <div class="d-flex flex-wrap">
                                                            <a href="view-certificate.php?id=<?= $vehicle['id'] ?>&v=<?= $timestamp ?>" class="btn btn-outline-primary me-2 mb-2" target="_blank">
                                                                <i class="fas fa-eye me-1"></i> View
                                                            </a>
                                                            <a href="download-certificate.php?id=<?= $vehicle['id'] ?>" class="btn btn-outline-success mb-2">
                                                                <i class="fas fa-download me-1"></i> Download
                                                            </a>
                                                        </div>
                                                    <?php else: ?>
                                                        <!-- Generate certificate on-demand if not available -->
                                                        <div class="d-flex flex-wrap">
                                                            <a href="view-certificate.php?id=<?= $vehicle['id'] ?>&v=<?= $timestamp ?>" class="btn btn-primary me-2 mb-2" target="_blank">
                                                                <i class="fas fa-eye me-1"></i> View Certificate
                                                            </a>
                                                            <a href="download-certificate-pdf.php?id=<?= $vehicle['id'] ?>" class="btn btn-success mb-2">
                                                                <i class="fas fa-download me-1"></i> Download PDF
                                                            </a>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            
                                            <!-- COMPLETELY REBUILT ROADWORTHY CERTIFICATE SECTION -->
                                            <div class="document-box mt-3">
                                                <div class="d-flex align-items-center">
                                                    <i class="fas fa-check-circle fa-2x text-info me-3"></i>
                                                    <div>
                                                        <h6 class="mb-1">Roadworthy Certificate</h6>
                                                        <small class="text-muted">Vehicle inspection document</small>
                                                    </div>
                                                </div>
                                                <div class="mt-3">
                                                    <!-- Always use the view-roadworthy.php with timestamp to avoid caching issues -->
                                                    <a href="view-roadworthy.php?id=<?= $vehicle['id'] ?>&fresh=1&v=<?= $timestamp ?>" class="btn btn-sm btn-primary me-2" target="_blank">
                                                        <i class="fas fa-eye me-1"></i> View Certificate
                                                    </a>
                                                    
                                                    <!-- Only show regenerate button if certificate exists -->
                                                    <?php if (!empty($vehicle['roadworthy_certificate_path'])): ?>
                                                    <a href="generate-roadworthy.php?id=<?= $vehicle['id'] ?>&fresh=1" class="btn btn-sm btn-outline-success">
                                                        <i class="fas fa-sync-alt me-1"></i> Regenerate
                                                    </a>
                                                    <?php else: ?>
                                                    <a href="generate-roadworthy.php?id=<?= $vehicle['id'] ?>" class="btn btn-sm btn-outline-success">
                                                        <i class="fas fa-sync-alt me-1"></i> Generate PDF
                                                    </a>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            
                                            <!-- Note to user about document generation -->
                                            <div class="alert alert-light border mt-3 mb-0">
                                                <i class="fas fa-info-circle me-2 text-primary"></i>
                                                <small>You can view your certificates at any time. Clicking "View Certificate" will automatically generate a fresh document if needed.</small>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Action Buttons -->
                                    <div class="d-grid gap-2">
                                        <a href="my-vehicles.php" class="btn btn-secondary">
                                            <i class="fas fa-arrow-left me-2"></i>Back to My Vehicles
                                        </a>
                                        
                                        <?php if ($vehicle['days_to_expiry'] < 30): ?>
                                            <a href="renew-registration.php?id=<?= $vehicle['id'] ?>" class="btn btn-warning">
                                                <i class="fas fa-sync-alt me-2"></i>Renew Registration
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <?php include_once __DIR__ . '/../../../includes/footer.php'; ?>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>