<?php
session_start();
require_once __DIR__ . '/../../../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../views/auth/login.php');
    exit;
}

// Check if vehicle ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: ../vehicle/search.php');
    exit;
}

$vehicleId = $_GET['id'];
$vehicle = null;
$error = '';
$isAdmin = ($_SESSION['role'] === 'admin');

// Check if passport_photo_path column exists
$passportPhotoColumnExists = false;
try {
    $checkColumnStmt = $pdo->prepare("SHOW COLUMNS FROM vehicle_owners LIKE 'passport_photo_path'");
    $checkColumnStmt->execute();
    $passportPhotoColumnExists = ($checkColumnStmt->rowCount() > 0);
} catch (PDOException $e) {
    error_log("Failed to check passport_photo_path column: " . $e->getMessage());
}

// Check if id_document_image_path column exists (new name) or ghana_card_image_path column (old name)
$idDocumentImagePathExists = false;
$ghanaCardImagePathExists = false;
try {
    $checkColumnStmt = $pdo->prepare("SHOW COLUMNS FROM vehicle_owners LIKE 'id_document_image_path'");
    $checkColumnStmt->execute();
    $idDocumentImagePathExists = ($checkColumnStmt->rowCount() > 0);
    
    if (!$idDocumentImagePathExists) {
        $checkColumnStmt = $pdo->prepare("SHOW COLUMNS FROM vehicle_owners LIKE 'ghana_card_image_path'");
        $checkColumnStmt->execute();
        $ghanaCardImagePathExists = ($checkColumnStmt->rowCount() > 0);
    }
} catch (PDOException $e) {
    error_log("Failed to check document image path columns: " . $e->getMessage());
}

// Check if id_document_type column exists
$idDocumentTypeExists = false;
try {
    $checkColumnStmt = $pdo->prepare("SHOW COLUMNS FROM vehicles LIKE 'id_document_type'");
    $checkColumnStmt->execute();
    $idDocumentTypeExists = ($checkColumnStmt->rowCount() > 0);
} catch (PDOException $e) {
    error_log("Failed to check id_document_type column: " . $e->getMessage());
}

// Check if insurance columns exist
$insuranceColumnsExist = false;
try {
    $checkColumnStmt = $pdo->prepare("SHOW COLUMNS FROM vehicles LIKE 'insurance_provider'");
    $checkColumnStmt->execute();
    $insuranceColumnsExist = ($checkColumnStmt->rowCount() > 0);
} catch (PDOException $e) {
    error_log("Failed to check insurance columns: " . $e->getMessage());
}

// Check if vehicle_use column exists
$vehicleUseExists = false;
try {
    $checkColumnStmt = $pdo->prepare("SHOW COLUMNS FROM vehicles LIKE 'vehicle_use'");
    $checkColumnStmt->execute();
    $vehicleUseExists = ($checkColumnStmt->rowCount() > 0);
} catch (PDOException $e) {
    error_log("Failed to check vehicle_use column: " . $e->getMessage());
}

// Check if roadworthy columns exist
$roadworthyColumnsExist = false;
try {
    $checkColumnStmt = $pdo->prepare("SHOW COLUMNS FROM vehicles LIKE 'roadworthy_certificate_path'");
    $checkColumnStmt->execute();
    $roadworthyColumnsExist = ($checkColumnStmt->rowCount() > 0);
} catch (PDOException $e) {
    error_log("Failed to check roadworthy columns: " . $e->getMessage());
}

try {
    // Build the query based on which columns exist in the database
    $selectFields = "v.*, 
               DATE_FORMAT(v.registration_date, '%d %b %Y') as formatted_reg_date,
               DATE_FORMAT(v.expiry_date, '%d %b %Y') as formatted_expiry_date,
               DATEDIFF(v.expiry_date, CURDATE()) as days_to_expiry,
               o.id as owner_id,
               o.name as owner_name,
               o.ghana_card_number,
               o.phone as owner_phone,
               o.email as owner_email,
               o.address as owner_address,
               o.date_of_birth";
    
    // Add the appropriate document image path field
    if ($idDocumentImagePathExists) {
        $selectFields .= ", o.id_document_image_path";
    } elseif ($ghanaCardImagePathExists) {
        $selectFields .= ", o.ghana_card_image_path";
    }
    
    // Add id_document_type if it exists
    if ($idDocumentTypeExists) {
        $selectFields .= ", o.id_document_type";
    }
    
    // Add passport photo path to query if column exists
    if ($passportPhotoColumnExists) {
        $selectFields .= ", o.passport_photo_path";
    }
    
    // Add insurance-related fields if they exist
    if ($insuranceColumnsExist) {
        $selectFields .= ", v.insurance_provider, v.policy_number, v.insurance_expiry, v.insurance_certificate_path";
    }
    
    // Add roadworthy-related fields if they exist
    if ($roadworthyColumnsExist) {
        $selectFields .= ", v.roadworthy_certificate_path, v.roadworthy_expiry_date";
    }
    
    // Get vehicle details with owner information
    $vehicleStmt = $pdo->prepare("
        SELECT $selectFields
        FROM vehicles v
        JOIN vehicle_owners o ON v.owner_id = o.id
        WHERE v.id = :vehicle_id
    ");
    $vehicleStmt->execute(['vehicle_id' => $vehicleId]);
    $vehicle = $vehicleStmt->fetch();
    
    if (!$vehicle) {
        $error = "Vehicle not found";
    } else {
        // Calculate the actual status based on dates
        $today = new DateTime();
        
        // If expiry_date is not set, calculate it as 1 year from registration_date
        if (empty($vehicle['expiry_date']) && !empty($vehicle['registration_date'])) {
            $regDate = new DateTime($vehicle['registration_date']);
            $expDate = clone $regDate;
            $expDate->modify('+1 year');
            
            $vehicle['expiry_date'] = $expDate->format('Y-m-d');
            $vehicle['formatted_expiry_date'] = $expDate->format('d M Y');
            
            // Also recalculate days_to_expiry
            $interval = $today->diff($expDate);
            $vehicle['days_to_expiry'] = $interval->invert ? -$interval->days : $interval->days;
        } elseif (empty($vehicle['days_to_expiry']) && !empty($vehicle['expiry_date'])) {
            // If days_to_expiry is not calculated but we have expiry_date
            $expDate = new DateTime($vehicle['expiry_date']);
            $interval = $today->diff($expDate);
            $vehicle['days_to_expiry'] = $interval->invert ? -$interval->days : $interval->days;
        }
        
        // Calculate roadworthy status if it exists
        if ($roadworthyColumnsExist && !empty($vehicle['roadworthy_expiry_date'])) {
            $roadworthyExpiry = new DateTime($vehicle['roadworthy_expiry_date']);
            $interval = $today->diff($roadworthyExpiry);
            $vehicle['roadworthy_days_to_expiry'] = $interval->invert ? -$interval->days : $interval->days;
        }
        
        // Determine the document image path (use the new field name if it exists, otherwise fallback to the old field)
        if (isset($vehicle['id_document_image_path'])) {
            $vehicle['document_image_path'] = $vehicle['id_document_image_path'];
        } elseif (isset($vehicle['ghana_card_image_path'])) {
            $vehicle['document_image_path'] = $vehicle['ghana_card_image_path'];
        } else {
            $vehicle['document_image_path'] = null;
        }
    }
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}

// Helper function to format date
function formatDate($date) {
    if (!$date) return 'N/A';
    return date('d M Y', strtotime($date));
}

// Helper function to get document type display name
function getDocumentTypeDisplay($type) {
    switch ($type) {
        case 'drivers_license': return "Driver's License";
        case 'passport': return 'Passport';
        case 'voter_id': return 'Voter ID';
        case 'ghana_card': 
        default: return 'Ghana Card';
    }
}

// Helper function to determine vehicle use if not explicitly set
function determineVehicleUse($vehicleType) {
    $commercialTypes = ['taxi', 'bus', 'truck', 'commercial'];
    
    if (in_array(strtolower($vehicleType), $commercialTypes)) {
        return 'Commercial';
    }
    
    return 'Private';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Details | DVLA Vehicle Registration System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
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
            border-left: 4px solid #0d6efd;
            padding-left: 10px;
        }
        
        .info-row {
            margin-bottom: 0.5rem;
        }
        
        .info-label {
            font-weight: 600;
            color: #495057;
        }
        
        .vehicle-header {
            background: linear-gradient(to right, #0d6efd, #0a58ca);
            color: white;
            padding: 1.5rem;
            border-radius: 10px 10px 0 0;
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
        
        .owner-image-container {
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 10px;
            text-align: center;
            background-color: #f8f9fa;
            margin-bottom: 15px;
        }
        
        .owner-image {
            max-width: 100%;
            max-height: 200px;
            border-radius: 5px;
        }
        
        .passport-photo-container {
            width: 150px;
            height: 150px;
            border-radius: 75px;
            overflow: hidden;
            margin: 0 auto 15px;
            border: 3px solid #0d6efd;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        
        .passport-photo {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .breadcrumb-container {
            background-color: #f8f9fa;
            border-radius: 5px;
            padding: 10px 15px;
            margin-bottom: 20px;
        }
        
        .owner-header {
            background: linear-gradient(to right, #198754, #157347);
            color: white;
            padding: 1.5rem;
            border-radius: 10px 10px 0 0;
        }
        
        .owner-info-pill {
            background-color: rgba(255,255,255,0.2);
            border-radius: 50px;
            padding: 2px 12px;
            font-size: 0.9rem;
            margin-top: 5px;
            display: inline-block;
        }
        
        .owner-modal .modal-header {
            background: linear-gradient(to right, #198754, #157347);
            color: white;
        }
        
        .owner-modal .modal-body {
            padding: 20px;
        }
        
        .avatar-placeholder {
            width: 150px;
            height: 150px;
            border-radius: 75px;
            background-color: #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            color: #6c757d;
            font-size: 3rem;
            border: 3px solid #dee2e6;
        }
        
        .badge-premium {
            position: absolute;
            top: -10px;
            right: -10px;
            border-radius: 50%;
            width: 25px;
            height: 25px;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: #ffc107;
            color: #212529;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            font-size: 0.8rem;
        }
        
        .vehicle-use-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
            font-weight: 500;
            border-radius: 0.25rem;
            margin-left: 0.5rem;
        }
        
        .vehicle-use-private {
            background-color: #cfe2ff;
            color: #0a58ca;
        }
        
        .vehicle-use-commercial {
            background-color: #f8d7da;
            color: #842029;
        }
        
        .vehicle-use-government {
            background-color: #d1e7dd;
            color: #146c43;
        }
        
        .vehicle-use-diplomatic {
            background-color: #fff3cd;
            color: #664d03;
        }
        
        /* Roadworthy certificate styles */
        .roadworthy-section {
            border: 1px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 15px;
        }
        
        .roadworthy-header {
            background-color: #6610f2;
            color: white;
            padding: 10px 15px;
            font-weight: bold;
        }
        
        .roadworthy-body {
            padding: 15px;
            background-color: #f8f9fa;
        }
        
        .roadworthy-expired {
            background-color: #f8d7da;
            border-color: #f5c2c7;
        }
        
        .roadworthy-expiring-soon {
            background-color: #fff3cd;
            border-color: #ffecb5;
        }
        
        .roadworthy-valid {
            background-color: #d1e7dd;
            border-color: #badbcc;
        }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../../../includes/header.php'; ?>
    
    <div class="container mt-4">
        <!-- Breadcrumb Navigation -->
        <div class="breadcrumb-container">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?= $isAdmin ? '../admin/dashboard.php' : '../user/dashboard.php' ?>">Home</a></li>
                    <li class="breadcrumb-item"><a href="../vehicle/search.php">Vehicle Search</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Vehicle Details</li>
                </ol>
            </nav>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i><?= $error ?>
                <div class="mt-3">
                    <a href="../vehicle/search.php" class="btn btn-primary">Return to Search</a>
                </div>
            </div>
        <?php elseif ($vehicle): ?>
            <!-- Main Content -->
            <div class="row">
                <div class="col-12">
                    <!-- Vehicle Details Card -->
                    <div class="card mb-4">
                        <div class="vehicle-header">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h3 class="mb-0">
                                        <i class="fas fa-car me-2"></i><?= htmlspecialchars($vehicle['registration_number']) ?>
                                    </h3>
                                    <p class="mb-0 mt-2">
                                        <strong><?= htmlspecialchars($vehicle['make'] . ' ' . $vehicle['model']) ?></strong> 
                                        (<?= htmlspecialchars($vehicle['year_of_manufacture']) ?>)
                                    </p>
                                </div>
                                
                                <?php 
                                // Calculate vehicle status based on days_to_expiry
                                // Make sure days_to_expiry is treated as an integer
                                $days_to_expiry = intval($vehicle['days_to_expiry']);
                                $statusClass = 'success';
                                $statusText = 'Active';
                                
                                if ($days_to_expiry < 0) {
                                    $statusClass = 'danger';
                                    $statusText = 'Expired';
                                } elseif ($days_to_expiry <= 30) {
                                    $statusClass = 'warning';
                                    $statusText = 'Expiring Soon';
                                }
                                ?>
                                <div class="text-end">
                                    <span class="badge bg-<?= $statusClass ?> fs-6">
                                        <?= $statusText ?>
                                    </span>
                                    <p class="mb-0 small text-white mt-1">
                                        <?php if ($days_to_expiry > 0): ?>
                                            <?= $days_to_expiry ?> days until expiry
                                        <?php elseif ($days_to_expiry < 0): ?>
                                            Expired <?= abs($days_to_expiry) ?> days ago
                                        <?php else: ?>
                                            Expires today
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <!-- Left Column: Vehicle Information -->
                                <div class="col-md-8">
                                    <!-- Admin Actions -->
                                    <?php if ($isAdmin): ?>
                                        <div class="mb-4 d-flex gap-2">
                                            <a href="../admin/edit-vehicle.php?id=<?= $vehicle['id'] ?>" class="btn btn-primary">
                                                <i class="fas fa-edit me-2"></i>Edit Vehicle
                                            </a>
                                            <a href="../admin/print-certificate.php?id=<?= $vehicle['id'] ?>" class="btn btn-success">
                                                <i class="fas fa-print me-2"></i>Print Certificate
                                            </a>
                                            <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#ownerDetailsModal">
                                                <i class="fas fa-user me-2"></i>View Owner Details
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- Basic Vehicle Information -->
                                    <div class="detail-section">
                                        <h4><i class="fas fa-info-circle me-2"></i>Vehicle Information</h4>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="info-row">
                                                    <span class="info-label">Registration Number:</span>
                                                    <div><?= htmlspecialchars($vehicle['registration_number']) ?></div>
                                                </div>
                                                <div class="info-row">
                                                    <span class="info-label">Make:</span>
                                                    <div><?= htmlspecialchars($vehicle['make']) ?></div>
                                                </div>
                                                <div class="info-row">
                                                    <span class="info-label">Model:</span>
                                                    <div><?= htmlspecialchars($vehicle['model']) ?></div>
                                                </div>
                                                <div class="info-row">
                                                    <span class="info-label">Year:</span>
                                                    <div><?= htmlspecialchars($vehicle['year_of_manufacture']) ?></div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="info-row">
                                                    <span class="info-label">Color:</span>
                                                    <div><?= htmlspecialchars($vehicle['color'] ?? 'N/A') ?></div>
                                                </div>
                                                <div class="info-row">
                                                    <span class="info-label">Vehicle Type:</span>
                                                    <div><?= htmlspecialchars($vehicle['vehicle_type'] ?? 'N/A') ?></div>
                                                </div>
                                                
                                                <!-- Vehicle Use Field -->
                                                <div class="info-row">
                                                    <span class="info-label">Vehicle Use:</span>
                                                    <div>
                                                        <?php 
                                                        // Determine vehicle use - from DB or derived
                                                        if ($vehicleUseExists && !empty($vehicle['vehicle_use'])) {
                                                            $vehicleUse = $vehicle['vehicle_use'];
                                                        } else {
                                                            $vehicleUse = determineVehicleUse($vehicle['vehicle_type'] ?? '');
                                                        }
                                                        
                                                        echo htmlspecialchars($vehicleUse);
                                                        ?>
                                                    </div>
                                                </div>
                                                
                                                <div class="info-row">
                                                    <span class="info-label">Engine Capacity:</span>
                                                    <div><?= $vehicle['engine_capacity'] ? htmlspecialchars($vehicle['engine_capacity'].' cc') : 'N/A' ?></div>
                                                </div>
                                                <div class="info-row">
                                                    <span class="info-label">Seating Capacity:</span>
                                                    <div><?= $vehicle['seating_capacity'] ? htmlspecialchars($vehicle['seating_capacity']) : 'N/A' ?></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Technical Details -->
                                    <div class="detail-section">
                                        <h4><i class="fas fa-cogs me-2"></i>Technical Details</h4>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="info-row">
                                                    <span class="info-label">Chassis Number:</span>
                                                    <div><?= htmlspecialchars($vehicle['chassis_number'] ?? 'N/A') ?></div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="info-row">
                                                    <span class="info-label">Engine Number:</span>
                                                    <div><?= htmlspecialchars($vehicle['engine_number'] ?? 'N/A') ?></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Registration Information -->
                                    <div class="detail-section">
                                        <h4><i class="fas fa-calendar-alt me-2"></i>Registration Information</h4>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="info-row">
                                                    <span class="info-label">Registration Date:</span>
                                                    <div><?= $vehicle['formatted_reg_date'] ?></div>
                                                </div>
                                                <div class="info-row">
                                                    <span class="info-label">Expiry Date:</span>
                                                    <div><?= $vehicle['formatted_expiry_date'] ?></div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="info-row">
                                                    <span class="info-label">Status:</span>
                                                    <div>
                                                        <span class="badge bg-<?= $statusClass ?>"><?= $statusText ?></span>
                                                        <?php if ($days_to_expiry > 0): ?>
                                                            (<?= $days_to_expiry ?> days left)
                                                        <?php elseif ($days_to_expiry < 0): ?>
                                                            (<?= abs($days_to_expiry) ?> days ago)
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <?php if ($days_to_expiry < 30): ?>
                                                    <div class="alert alert-warning mt-2 p-2 mb-0">
                                                        <small>
                                                            <i class="fas fa-exclamation-triangle me-1"></i>
                                                            <?php if ($days_to_expiry < 0): ?>
                                                                This vehicle's registration has expired and requires renewal.
                                                            <?php else: ?>
                                                                This vehicle's registration will expire soon. Please renew.
                                                            <?php endif; ?>
                                                        </small>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Right Column: QR Code and Owner Details -->
                                <div class="col-md-4">
                                    <!-- QR Code Section -->
                                    <?php if (!empty($vehicle['qr_code_path'])): ?>
                                        <div class="qr-code-container mb-4">
                                            <p class="fw-bold mb-2">Vehicle QR Code</p>
                                            <img src="/<?= htmlspecialchars($vehicle['qr_code_path']) ?>" alt="QR Code" class="img-fluid mb-3" style="max-width: 150px;">
                                            <div>
                                                <?php if ($isAdmin): ?>
                                                    <a href="../admin/download-qr.php?id=<?= $vehicle['id'] ?>" class="btn btn-sm btn-primary">
                                                        <i class="fas fa-download me-1"></i> Download QR
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- Roadworthy Certificate Section -->
                                    <?php 
                                    $hasRoadworthy = $roadworthyColumnsExist && !empty($vehicle['roadworthy_certificate_path']);
                                    $roadworthyStatusClass = 'roadworthy-valid';
                                    $roadworthyStatusText = 'Valid';
                                    $roadworthyIconClass = 'text-success';
                                    
                                    if ($hasRoadworthy && isset($vehicle['roadworthy_days_to_expiry'])) {
                                        if ($vehicle['roadworthy_days_to_expiry'] < 0) {
                                            $roadworthyStatusClass = 'roadworthy-expired';
                                            $roadworthyStatusText = 'Expired';
                                            $roadworthyIconClass = 'text-danger';
                                        } elseif ($vehicle['roadworthy_days_to_expiry'] <= 30) {
                                            $roadworthyStatusClass = 'roadworthy-expiring-soon';
                                            $roadworthyStatusText = 'Expiring Soon';
                                            $roadworthyIconClass = 'text-warning';
                                        }
                                    }
                                    ?>
                                    
                                    <div class="roadworthy-section mb-4 <?= $hasRoadworthy ? $roadworthyStatusClass : '' ?>">
                                        <div class="roadworthy-header">
                                            <i class="fas fa-clipboard-check me-2"></i>Roadworthy Certificate
                                        </div>
                                        <div class="roadworthy-body">
                                            <?php if ($hasRoadworthy): ?>
                                                <div class="d-flex justify-content-between align-items-center mb-3">
                                                    <span class="fw-bold">Status:</span>
                                                    <span class="badge <?= $roadworthyStatusText == 'Valid' ? 'bg-success' : ($roadworthyStatusText == 'Expiring Soon' ? 'bg-warning text-dark' : 'bg-danger') ?>">
                                                        <?= $roadworthyStatusText ?>
                                                    </span>
                                                </div>
                                                
                                                <?php if (!empty($vehicle['roadworthy_expiry_date'])): ?>
                                                <div class="mb-3">
                                                    <small class="text-muted">Expiry Date:</small>
                                                    <div class="fw-bold"><?= formatDate($vehicle['roadworthy_expiry_date']) ?></div>
                                                    
                                                    <?php if (isset($vehicle['roadworthy_days_to_expiry'])): ?>
                                                        <small class="<?= $roadworthyIconClass ?>">
                                                            <?php if ($vehicle['roadworthy_days_to_expiry'] > 0): ?>
                                                                <i class="fas fa-clock me-1"></i> <?= $vehicle['roadworthy_days_to_expiry'] ?> days remaining
                                                            <?php elseif ($vehicle['roadworthy_days_to_expiry'] < 0): ?>
                                                                <i class="fas fa-exclamation-triangle me-1"></i> Expired <?= abs($vehicle['roadworthy_days_to_expiry']) ?> days ago
                                                            <?php else: ?>
                                                                <i class="fas fa-exclamation-circle me-1"></i> Expires today
                                                            <?php endif; ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </div>
                                                <?php endif; ?>
                                                
                                                <div class="text-center">
                                                    <a href="/<?= htmlspecialchars($vehicle['roadworthy_certificate_path']) ?>" 
                                                       class="btn btn-sm btn-primary" target="_blank">
                                                        <i class="fas fa-eye me-1"></i> View Certificate
                                                    </a>
                                                    
                                                    <?php if ($isAdmin): ?>
                                                    <a href="../admin/generate-roadworthy.php?id=<?= $vehicle['id'] ?>" 
                                                       class="btn btn-sm btn-outline-secondary ms-2">
                                                        <i class="fas fa-sync-alt me-1"></i> Regenerate
                                                    </a>
                                                    <?php endif; ?>
                                                </div>
                                                
                                            <?php elseif ($isAdmin): ?>
                                                <div class="text-center">
                                                    <div class="mb-3 text-center">
                                                        <i class="fas fa-clipboard fa-2x text-muted mb-2"></i>
                                                        <p class="mb-3">No roadworthy certificate found for this vehicle.</p>
                                                    </div>
                                                    
                                                    <a href="../admin/generate-roadworthy.php?id=<?= $vehicle['id'] ?>" class="btn btn-primary btn-sm">
                                                        <i class="fas fa-file-medical me-1"></i> Generate Roadworthy Certificate
                                                    </a>
                                                </div>
                                            <?php else: ?>
                                                <div class="text-center text-muted">
                                                    <i class="fas fa-clipboard-list fa-2x mb-2"></i>
                                                    <p>No roadworthy certificate information available</p>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <!-- Insurance Certificate Section -->
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
                                    
                                    <div class="card mb-4">
                                        <div class="card-header bg-info text-white">
                                            <h5 class="mb-0"><i class="fas fa-file-contract me-2"></i>Insurance Information</h5>
                                        </div>
                                        <div class="card-body">
                                            <?php if ($hasInsurance): ?>
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
                                                
                                            <?php elseif ($isAdmin): ?>
                                                <div class="text-center p-2">
                                                    <p class="mb-3 text-muted">No insurance certificate uploaded for this vehicle.</p>
                                                    <a href="../admin/upload-insurance.php?vehicle_id=<?= $vehicle['id'] ?>" class="btn btn-primary btn-sm">
                                                        <i class="fas fa-file-upload me-1"></i> Upload Insurance Certificate
                                                    </a>
                                                </div>
                                            <?php else: ?>
                                                <div class="text-center text-muted">
                                                    <i class="fas fa-file-excel fa-2x mb-2"></i>
                                                    <p>No insurance information available</p>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <!-- Document Links -->
                                    <?php if (!empty($vehicle['certificate_pdf_path'])): ?>
                                        <div class="document-box mb-4">
                                            <div class="d-flex align-items-center">
                                                <i class="fas fa-file-pdf fa-2x text-danger me-3"></i>
                                                <div>
                                                    <h6 class="mb-1">Registration Certificate</h6>
                                                    <small class="text-muted">PDF Document</small>
                                                </div>
                                            </div>
                                            <div class="mt-3">
                                                <a href="/<?= htmlspecialchars($vehicle['certificate_pdf_path']) ?>" class="btn btn-sm btn-outline-primary me-2" target="_blank">
                                                    <i class="fas fa-eye me-1"></i> View
                                                </a>
                                                <?php if ($isAdmin): ?>
                                                    <a href="../admin/download-certificate.php?id=<?= $vehicle['id'] ?>" class="btn btn-sm btn-outline-success">
                                                        <i class="fas fa-download me-1"></i> Download
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- Owner Summary Card -->
                                    <div class="card border-success mb-4">
                                        <div class="card-header bg-success text-white">
                                            <h5 class="mb-0"><i class="fas fa-user me-2"></i>Vehicle Owner</h5>
                                        </div>
                                        <div class="card-body text-center">
                                            <!-- Display passport photo if available -->
                                            <?php if ($passportPhotoColumnExists && !empty($vehicle['passport_photo_path'])): ?>
                                                <div class="position-relative d-inline-block">
                                                    <div class="passport-photo-container">
                                                        <img src="/<?= htmlspecialchars($vehicle['passport_photo_path']) ?>" 
                                                             alt="Owner Photo" class="passport-photo">
                                                    </div>
                                                    <span class="badge-premium">
                                                        <i class="fas fa-check"></i>
                                                    </span>
                                                </div>
                                            <?php else: ?>
                                                <div class="avatar-placeholder">
                                                    <i class="fas fa-user"></i>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <h5 class="mb-1 mt-2"><?= htmlspecialchars($vehicle['owner_name']) ?></h5>
                                            
                                            <?php
                                            // Get proper ID type label
                                            $idDocumentType = $idDocumentTypeExists && isset($vehicle['id_document_type']) ? $vehicle['id_document_type'] : 'ghana_card';
                                            $idDocumentLabel = getDocumentTypeDisplay($idDocumentType);
                                            ?>
                                            <p class="text-muted mb-1">
                                                <span class="fw-bold"><?= htmlspecialchars($idDocumentLabel) ?>:</span> 
                                                <?= htmlspecialchars($vehicle['ghana_card_number']) ?>
                                            </p>
                                            
                                            <?php if (!empty($vehicle['owner_phone'])): ?>
                                                <div class="owner-info-pill">
                                                    <i class="fas fa-phone me-1"></i> <?= htmlspecialchars($vehicle['owner_phone']) ?>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($isAdmin): ?>
                                                <div class="mt-3">
                                                    <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#ownerDetailsModal">
                                                        <i class="fas fa-id-card me-1"></i> View Full Details
                                                    </button>
                                                    <a href="../admin/edit-owner.php?id=<?= $vehicle['owner_id'] ?>" class="btn btn-sm btn-outline-primary">
                                                        <i class="fas fa-user-edit me-1"></i> Edit Owner
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="d-flex justify-content-between mb-4">
                        <a href="../vehicle/search.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back to Search
                        </a>
                        
                        <?php if ($isAdmin): ?>
                            <div>
                                <a href="../admin/edit-vehicle.php?id=<?= $vehicle['id'] ?>" class="btn btn-primary me-2">
                                    <i class="fas fa-edit me-2"></i>Edit Vehicle
                                </a>
                                <a href="../admin/print-certificate.php?id=<?= $vehicle['id'] ?>" class="btn btn-success">
                                    <i class="fas fa-print me-2"></i>Print Certificate
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Owner Details Modal -->
                    <?php if ($isAdmin): ?>
                        <div class="modal fade owner-modal" id="ownerDetailsModal" tabindex="-1" aria-labelledby="ownerDetailsModalLabel" aria-hidden="true">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="ownerDetailsModalLabel">
                                            <i class="fas fa-user-circle me-2"></i>Owner Details: <?= htmlspecialchars($vehicle['owner_name']) ?>
                                        </h5>
                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="row">
                                            <!-- Owner Info Column -->
                                            <div class="col-md-7">
                                                <h5 class="border-bottom pb-2 mb-3">Personal Information</h5>
                                                <div class="row mb-3">
                                                    <div class="col-md-6">
                                                        <div class="info-row">
                                                            <span class="info-label">Full Name:</span>
                                                            <div><?= htmlspecialchars($vehicle['owner_name']) ?></div>
                                                        </div>
                                                        <div class="info-row">
                                                            <span class="info-label">
                                                                <?= htmlspecialchars($idDocumentLabel) ?>:
                                                            </span>
                                                            <div><?= htmlspecialchars($vehicle['ghana_card_number']) ?></div>
                                                        </div>
                                                        <?php if (!empty($vehicle['date_of_birth'])): ?>
                                                            <div class="info-row">
                                                                <span class="info-label">Date of Birth:</span>
                                                                <div><?= formatDate($vehicle['date_of_birth']) ?></div>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="info-row">
                                                            <span class="info-label">Phone Number:</span>
                                                            <div><?= htmlspecialchars($vehicle['owner_phone'] ?? 'N/A') ?></div>
                                                        </div>
                                                        <div class="info-row">
                                                            <span class="info-label">Email Address:</span>
                                                            <div><?= htmlspecialchars($vehicle['owner_email'] ?? 'N/A') ?></div>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="info-row">
                                                    <span class="info-label">Address:</span>
                                                    <div><?= htmlspecialchars($vehicle['owner_address'] ?? 'N/A') ?></div>
                                                </div>
                                                
                                                <h5 class="border-bottom pb-2 mb-3 mt-4">Owned Vehicles</h5>
                                                <?php
                                                try {
                                                    $ownedVehiclesStmt = $pdo->prepare("
                                                        SELECT id, registration_number, make, model, year_of_manufacture
                                                        FROM vehicles
                                                        WHERE owner_id = :owner_id
                                                        ORDER BY registration_date DESC
                                                    ");
                                                    $ownedVehiclesStmt->execute(['owner_id' => $vehicle['owner_id']]);
                                                    $ownedVehicles = $ownedVehiclesStmt->fetchAll();
                                                    
                                                    if (count($ownedVehicles) > 0):
                                                ?>
                                                    <div class="table-responsive">
                                                        <table class="table table-sm table-hover table-striped">
                                                            <thead>
                                                                <tr>
                                                                    <th>Reg Number</th>
                                                                    <th>Make & Model</th>
                                                                    <th>Year</th>
                                                                    <th>Action</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php foreach ($ownedVehicles as $ownedVehicle): ?>
                                                                    <tr<?= ($ownedVehicle['id'] == $vehicleId) ? ' class="table-primary"' : '' ?>>
                                                                        <td><?= htmlspecialchars($ownedVehicle['registration_number']) ?></td>
                                                                        <td><?= htmlspecialchars($ownedVehicle['make'] . ' ' . $ownedVehicle['model']) ?></td>
                                                                        <td><?= htmlspecialchars($ownedVehicle['year_of_manufacture']) ?></td>
                                                                        <td>
                                                                            <a href="view-details.php?id=<?= $ownedVehicle['id'] ?>" class="btn btn-sm btn-outline-info">
                                                                                <i class="fas fa-eye"></i>
                                                                            </a>
                                                                        </td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                <?php 
                                                    else:
                                                        echo '<p class="text-muted">No vehicles found for this owner.</p>';
                                                    endif;
                                                } catch (PDOException $e) {
                                                    echo '<p class="text-danger">Unable to load owner\'s vehicles.</p>';
                                                }
                                                ?>
                                            </div>
                                            
                                            <!-- Owner Documents Column -->
                                            <div class="col-md-5">
                                                <!-- Photo Section -->
                                                <div class="text-center mb-4">
                                                    <h5 class="border-bottom pb-2 mb-3">Photo ID</h5>
                                                    
                                                    <?php if ($passportPhotoColumnExists && !empty($vehicle['passport_photo_path'])): ?>
                                                        <div class="passport-photo-container" style="width: 180px; height: 180px; border-radius: 5px;">
                                                            <img src="/<?= htmlspecialchars($vehicle['passport_photo_path']) ?>" 
                                                                 alt="Owner Photo" class="passport-photo">
                                                        </div>
                                                        <p class="text-muted mt-2 mb-0">Passport Photo</p>
                                                    <?php else: ?>
                                                        <div class="avatar-placeholder" style="width: 180px; height: 180px; border-radius: 5px;">
                                                            <i class="fas fa-user-circle"></i>
                                                        </div>
                                                        <p class="text-muted mt-2 mb-0">No passport photo available</p>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <!-- ID Document Section -->
                                                <div class="mb-3">
                                                    <h5 class="border-bottom pb-2 mb-3"><?= htmlspecialchars($idDocumentLabel) ?></h5>
                                                    
                                                    <?php if (!empty($vehicle['document_image_path'])): 
                                                        $filePath = $vehicle['document_image_path'];
                                                        $fileExtension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
                                                        $isPdf = $fileExtension === 'pdf';
                                                    ?>
                                                        <?php if ($isPdf): ?>
                                                            <div class="document-box text-center">
                                                                <i class="fas fa-file-pdf fa-4x text-danger mb-3"></i>
                                                                <p class="mb-2"><?= htmlspecialchars($idDocumentLabel) ?> Document (PDF)</p>
                                                                <a href="/<?= htmlspecialchars($vehicle['document_image_path']) ?>" 
                                                                   class="btn btn-sm btn-outline-danger" target="_blank">
                                                                    <i class="fas fa-eye me-1"></i> View PDF Document
                                                                </a>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="owner-image-container">
                                                                <img src="/<?= htmlspecialchars($vehicle['document_image_path']) ?>" 
                                                                     alt="<?= htmlspecialchars($idDocumentLabel) ?>" class="owner-image">
                                                                <p class="mt-2 mb-0"><?= htmlspecialchars($idDocumentLabel) ?> Image</p>
                                                            </div>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <div class="alert alert-warning">
                                                            <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($idDocumentLabel) ?> document not available
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <a href="../admin/edit-owner.php?id=<?= $vehicle['owner_id'] ?>" class="btn btn-primary">
                                            <i class="fas fa-user-edit me-2"></i>Edit Owner Information
                                        </a>
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <?php include_once __DIR__ . '/../../../includes/footer.php'; ?>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>