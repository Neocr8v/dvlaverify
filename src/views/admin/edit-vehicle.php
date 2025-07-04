<?php
// filepath: /Applications/XAMPP/xamppfiles/htdocs/dvlaregister/src/views/admin/edit-vehicle.php
session_start();
require_once __DIR__ . '/../../../config/database.php';

// Check if admin is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../views/auth/login.php');
    exit;
}

// Check if vehicle ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: ./manage-vehicles.php');
    exit;
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

// Create upload directories if needed
$projectRoot = $_SERVER['DOCUMENT_ROOT'] . '/';
$passportPhotosDir = $projectRoot . 'uploads/passport_photos/';

if (!file_exists($passportPhotosDir)) {
    if (!@mkdir($passportPhotosDir, 0777, true)) {
        // Try with relative path if absolute path fails
        $passportPhotosDir = __DIR__ . '/../../../uploads/passport_photos/';
        if (!file_exists($passportPhotosDir)) {
            @mkdir($passportPhotosDir, 0777, true);
        }
    }
}

$vehicleId = $_GET['id'];
$vehicle = null;
$owners = [];
$ownerDetails = null;
$success = '';
$error = '';
$photoError = '';

// Check if the passport_photo_path column exists in the vehicle_owners table
$passportPhotoColumnExists = false;
try {
    $checkColumnStmt = $pdo->prepare("SHOW COLUMNS FROM vehicle_owners LIKE 'passport_photo_path'");
    $checkColumnStmt->execute();
    $passportPhotoColumnExists = ($checkColumnStmt->rowCount() > 0);
    
    if (!$passportPhotoColumnExists) {
        // Add the column to the table
        $alterTableStmt = $pdo->prepare("ALTER TABLE vehicle_owners ADD COLUMN passport_photo_path VARCHAR(255) AFTER ghana_card_image_path");
        $alterTableStmt->execute();
        $passportPhotoColumnExists = true;
    }
} catch (PDOException $e) {
    error_log("Failed to check/add passport_photo_path column: " . $e->getMessage());
    // Continue anyway, we'll handle the missing column in our queries
}

// Check if id_document_type column exists
$idDocumentTypeExists = false;
try {
    $checkColumnStmt = $pdo->prepare("SHOW COLUMNS FROM vehicle_owners LIKE 'id_document_type'");
    $checkColumnStmt->execute();
    $idDocumentTypeExists = ($checkColumnStmt->rowCount() > 0);
} catch (PDOException $e) {
    error_log("Failed to check id_document_type column: " . $e->getMessage());
}

// Check if vehicle_use column exists, create it if it doesn't
$vehicleUseExists = false;
try {
    $checkColumnStmt = $pdo->prepare("SHOW COLUMNS FROM vehicles LIKE 'vehicle_use'");
    $checkColumnStmt->execute();
    $vehicleUseExists = ($checkColumnStmt->rowCount() > 0);
    
    if (!$vehicleUseExists) {
        // Add the column to the table
        $alterTableStmt = $pdo->prepare("ALTER TABLE vehicles ADD COLUMN vehicle_use VARCHAR(50) DEFAULT 'Private' AFTER vehicle_type");
        $alterTableStmt->execute();
        $vehicleUseExists = true;
    }
} catch (PDOException $e) {
    error_log("Failed to check/add vehicle_use column: " . $e->getMessage());
}

try {
    // Get all vehicle owners for dropdown
    $ownerStmt = $pdo->prepare("SELECT id, name, ghana_card_number, phone FROM vehicle_owners ORDER BY name");
    $ownerStmt->execute();
    $owners = $ownerStmt->fetchAll();
    
    // Get vehicle details - adjust query based on passport_photo_path existence
    if ($passportPhotoColumnExists) {
        $vehicleStmt = $pdo->prepare("
            SELECT v.*, o.name as owner_name, o.id as owner_id, o.passport_photo_path
            FROM vehicles v
            JOIN vehicle_owners o ON v.owner_id = o.id
            WHERE v.id = :id
        ");
    } else {
        $vehicleStmt = $pdo->prepare("
            SELECT v.*, o.name as owner_name, o.id as owner_id
            FROM vehicles v
            JOIN vehicle_owners o ON v.owner_id = o.id
            WHERE v.id = :id
        ");
    }
    
    $vehicleStmt->execute(['id' => $vehicleId]);
    $vehicle = $vehicleStmt->fetch();
    
    if (!$vehicle) {
        $error = "Vehicle not found";
    } else {
        // Get detailed owner information
        $ownerDetailStmt = $pdo->prepare("SELECT * FROM vehicle_owners WHERE id = :id");
        $ownerDetailStmt->execute(['id' => $vehicle['owner_id']]);
        $ownerDetails = $ownerDetailStmt->fetch();
    }
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $vehicle) {
    $ownerId = $_POST['owner_id'] ?? $vehicle['owner_id'];
    $regNumber = strtoupper($_POST['registration_number'] ?? '');
    $make = $_POST['make'] ?? '';
    $model = $_POST['model'] ?? '';
    $year = $_POST['year'] ?? '';
    $color = $_POST['color'] ?? '';
    $vehicleType = $_POST['vehicle_type'] ?? '';
    $vehicleUse = $_POST['vehicle_use'] ?? 'Private'; // Get vehicle use from form
    $chassisNumber = $_POST['chassis_number'] ?? '';
    $engineNumber = $_POST['engine_number'] ?? '';
    $engineCapacity = $_POST['engine_capacity'] ?? '';
    $seatingCapacity = $_POST['seating_capacity'] ?? '';
    $regDate = $_POST['registration_date'] ?? $vehicle['registration_date'];
    $expiryDate = $_POST['expiry_date'] ?? $vehicle['expiry_date'];
    
    // Process passport photo upload if provided
    $passportPhotoPath = isset($ownerDetails['passport_photo_path']) ? $ownerDetails['passport_photo_path'] : '';
    
    if (isset($_FILES['passport_photo']) && $_FILES['passport_photo']['error'] === UPLOAD_ERR_OK) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];
        $maxSize = 2 * 1024 * 1024; // 2MB
        
        $fileType = $_FILES['passport_photo']['type'];
        $fileSize = $_FILES['passport_photo']['size'];
        $fileTmpName = $_FILES['passport_photo']['tmp_name'];
        $fileName = $_FILES['passport_photo']['name'];
        
        // Validate file type
        if (!in_array($fileType, $allowedTypes)) {
            $photoError = "Invalid file type for passport photo. Only JPG, JPEG, and PNG files are allowed.";
        }
        
        // Validate file size
        if ($fileSize > $maxSize) {
            $photoError = "Passport photo is too large. Maximum size is 2MB.";
        }
        
        if (empty($photoError)) {
            try {
                // Generate a unique filename
                $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $newFileName = 'passport_' . uniqid() . '_' . time() . '.' . $fileExtension;
                $uploadPath = $passportPhotosDir . $newFileName;
                
                if (move_uploaded_file($fileTmpName, $uploadPath)) {
                    $passportPhotoPath = 'uploads/passport_photos/' . $newFileName;
                    
                    // Update the passport photo path in the owner record
                    if ($passportPhotoColumnExists) {
                        $updatePhotoStmt = $pdo->prepare("
                            UPDATE vehicle_owners 
                            SET passport_photo_path = :photo_path 
                            WHERE id = :owner_id
                        ");
                        $updatePhotoStmt->execute([
                            'photo_path' => $passportPhotoPath,
                            'owner_id' => $ownerId
                        ]);
                    }
                } else {
                    $photoError = "Failed to upload passport photo. Please try again.";
                }
            } catch (Exception $e) {
                $photoError = "Error uploading passport photo: " . $e->getMessage();
            }
        }
    }
    
    // Validate inputs
    if (empty($ownerId) || empty($regNumber) || empty($make) || empty($model) || empty($year)) {
        $error = 'Owner, Registration Number, Make, Model and Year are required fields';
    } else {
        try {
            // Check if registration number already exists (except for this vehicle)
            $checkStmt = $pdo->prepare("
                SELECT COUNT(*) FROM vehicles 
                WHERE registration_number = :reg_number 
                AND id != :vehicle_id
            ");
            $checkStmt->execute([
                'reg_number' => $regNumber,
                'vehicle_id' => $vehicleId
            ]);
            
            if ($checkStmt->fetchColumn() > 0) {
                $error = 'A vehicle with this registration number already exists';
            } else {
                // Update vehicle information
                $stmt = $pdo->prepare("
                    UPDATE vehicles SET
                        owner_id = :owner_id, 
                        registration_number = :reg_number, 
                        make = :make, 
                        model = :model, 
                        year_of_manufacture = :year, 
                        color = :color, 
                        vehicle_type = :vehicle_type,
                        vehicle_use = :vehicle_use, 
                        chassis_number = :chassis_number, 
                        engine_number = :engine_number, 
                        engine_capacity = :engine_capacity, 
                        seating_capacity = :seating_capacity,
                        registration_date = :reg_date, 
                        expiry_date = :expiry_date,
                        updated_at = NOW()
                    WHERE id = :vehicle_id
                ");
                
                $stmt->execute([
                    'owner_id' => $ownerId,
                    'reg_number' => $regNumber,
                    'make' => $make,
                    'model' => $model,
                    'year' => $year,
                    'color' => $color,
                    'vehicle_type' => $vehicleType,
                    'vehicle_use' => $vehicleUse, // Add vehicle use to update query
                    'chassis_number' => $chassisNumber,
                    'engine_number' => $engineNumber,
                    'engine_capacity' => $engineCapacity,
                    'seating_capacity' => $seatingCapacity,
                    'reg_date' => $regDate,
                    'expiry_date' => $expiryDate,
                    'vehicle_id' => $vehicleId
                ]);
                
                // Check if we need to update QR code due to changed data
                if ($vehicle['registration_number'] !== $regNumber || 
                    $vehicle['make'] !== $make ||
                    $vehicle['model'] !== $model ||
                    $vehicle['year_of_manufacture'] != $year ||
                    $vehicle['registration_date'] !== $regDate ||
                    $vehicle['expiry_date'] !== $expiryDate) {
                        
                    // Generate new QR code with updated registration information
                    $qrData = json_encode([
                        'registration_number' => $regNumber,
                        'make' => $make,
                        'model' => $model,
                        'year' => $year,
                        'vehicle_use' => $vehicleUse, // Include vehicle use in QR data
                        'registration_date' => $regDate,
                        'expiry_date' => $expiryDate,
                        'verification_url' => 'https://dvla.gov.gh/verify/' . $regNumber
                    ]);
                    
                    // If QR path already exists, use it, otherwise generate new path
                    $qrCodeFilename = !empty($vehicle['qr_code_path']) ? 
                        $vehicle['qr_code_path'] : 
                        'assets/qrcodes/' . strtolower(str_replace(' ', '_', $regNumber)) . '.png';
                    
                    $fullQrPath = __DIR__ . '/../../../' . $qrCodeFilename;
                    
                    // Ensure directory exists
                    if (!file_exists(dirname($fullQrPath))) {
                        mkdir(dirname($fullQrPath), 0755, true);
                    }
                    
                    // If we have a QR code generator utility, use it
                    if (file_exists(__DIR__ . '/../../utils/qrcode-generator.php')) {
                        require_once __DIR__ . '/../../utils/qrcode-generator.php';
                        generateQRCode($qrData, $fullQrPath);
                        
                        // Update QR code path in database if needed
                        if (empty($vehicle['qr_code_path'])) {
                            $qrUpdateStmt = $pdo->prepare("
                                UPDATE vehicles SET qr_code_path = :qr_path WHERE id = :vehicle_id
                            ");
                            $qrUpdateStmt->execute([
                                'qr_path' => $qrCodeFilename,
                                'vehicle_id' => $vehicleId
                            ]);
                        }
                    }
                }
                
                $success = $photoError ? 'Vehicle information updated, but there was an issue with the photo upload.' : 'Vehicle information updated successfully!';
                
                // Refresh vehicle data
                $vehicleStmt->execute(['id' => $vehicleId]);
                $vehicle = $vehicleStmt->fetch();
                
                // Refresh owner details
                $ownerDetailStmt->execute(['id' => $vehicle['owner_id']]);
                $ownerDetails = $ownerDetailStmt->fetch();
            }
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Vehicle | DVLA Admin</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .breadcrumb-container {
            background-color: #f8f9fa;
            border-radius: 5px;
            padding: 10px 15px;
            margin-bottom: 20px;
        }
        
        .form-section {
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #eee;
        }
        
        .form-section:last-child {
            border-bottom: none;
        }
        
        .form-section-title {
            margin-bottom: 1.5rem;
            color: #0d6efd;
            font-weight: 600;
        }
        
        .passport-photo-container {
            position: relative;
            width: 150px;
            height: 150px;
            border: 2px dashed #ccc;
            border-radius: 5px;
            margin-bottom: 15px;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: #f8f9fa;
        }
        
        .passport-photo {
            max-width: 100%;
            max-height: 100%;
            object-fit: cover;
        }
        
        .photo-edit-overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 8px;
            background-color: rgba(0,0,0,0.6);
            color: white;
            text-align: center;
            font-size: 0.8rem;
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        .passport-photo-container:hover .photo-edit-overlay {
            opacity: 1;
        }
        
        .file-upload-wrapper {
            position: relative;
            margin-bottom: 15px;
        }
        
        .file-upload-input {
            position: relative;
            z-index: 2;
            width: 100%;
            height: calc(1.5em + 0.75rem + 2px);
            margin: 0;
            opacity: 0;
            cursor: pointer;
        }
        
        .file-upload-label {
            position: absolute;
            top: 0;
            right: 0;
            left: 0;
            z-index: 1;
            display: flex;
            align-items: center;
            padding: 0.375rem 0.75rem;
            font-weight: 400;
            line-height: 1.5;
            color: #495057;
            background-color: #fff;
            border: 1px solid #ced4da;
            border-radius: 0.25rem;
            height: calc(1.5em + 0.75rem + 2px);
            overflow: hidden;
        }
        
        .custom-file-label-text {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            flex-grow: 1;
        }
        
        .photo-guidelines {
            font-size: 0.85rem;
            color: #6c757d;
            margin-top: 10px;
            padding-left: 15px;
        }
        
        .photo-guidelines li {
            margin-bottom: 5px;
        }
        
        .owner-info-box {
            background-color: #f8f9fa;
            border-left: 4px solid #0d6efd;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        
        .owner-name {
            font-weight: bold;
            margin-bottom: 10px;
            color: #0d6efd;
        }
        
        /* Styles for Vehicle Use badges */
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
    </style>
</head>
<body>
    <?php
    include(__DIR__ . '/../../../includes/header.php');
    ?>
    
    <div class="container mt-4">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-lg-3 mb-4">
                <?php include_once __DIR__ . '/../../includes/admin-sidebar.php'; ?>
            </div>
            
            <!-- Main Content -->
            <div class="col-lg-9">
                <!-- Breadcrumb Navigation -->
                <div class="breadcrumb-container">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0">
                            <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="manage-vehicles.php">Manage Vehicles</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Edit Vehicle</li>
                        </ol>
                    </nav>
                </div>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i><?= $error ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        <?php if (!$vehicle): ?>
                            <div class="mt-3">
                                <a href="manage-vehicles.php" class="btn btn-primary">Return to Vehicle List</a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($photoError): ?>
                    <div class="alert alert-warning alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i><?= $photoError ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?= $success ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($vehicle): ?>
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h4 class="mb-0">
                                <i class="fas fa-edit me-2"></i>Edit Vehicle: <?= htmlspecialchars($vehicle['registration_number']) ?>
                            </h4>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="" enctype="multipart/form-data">
                                <!-- Vehicle Owner Information -->
                                <div class="form-section">
                                    <h5 class="form-section-title"><i class="fas fa-user me-2"></i>Vehicle Owner</h5>
                                    
                                    <div class="row mb-4">
                                        <div class="col-md-8">
                                            <div class="mb-3">
                                                <label for="owner_id" class="form-label">Vehicle Owner *</label>
                                                <select class="form-select" id="owner_id" name="owner_id" required>
                                                    <?php foreach ($owners as $owner): ?>
                                                        <option value="<?= $owner['id'] ?>" <?= $owner['id'] == $vehicle['owner_id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($owner['name']) ?> - 
                                                            <?= htmlspecialchars($owner['ghana_card_number']) ?> 
                                                            (<?= htmlspecialchars($owner['phone'] ?? 'No phone') ?>)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <div class="form-text">
                                                    If you need to change owner details, please 
                                                    <a href="edit-owner.php?id=<?= $vehicle['owner_id'] ?>">edit the owner directly</a>.
                                                </div>
                                            </div>
                                            
                                            <?php if ($ownerDetails): ?>
                                                <div class="owner-info-box">
                                                    <div class="owner-name">
                                                        <i class="fas fa-user-circle me-2"></i>
                                                        <?= htmlspecialchars($ownerDetails['name']) ?>
                                                    </div>
                                                    <div class="row">
                                                        <div class="col-md-6">
                                                            <small class="text-muted">
                                                                <?php
                                                                // Display the correct ID document type based on the owner's data
                                                                if ($idDocumentTypeExists && isset($ownerDetails['id_document_type'])) {
                                                                    echo getDocumentTypeDisplay($ownerDetails['id_document_type']) . ':';
                                                                } else {
                                                                    echo 'Ghana Card:';
                                                                }
                                                                ?>
                                                            </small>
                                                            <div><?= htmlspecialchars($ownerDetails['ghana_card_number']) ?></div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <small class="text-muted">Phone:</small>
                                                            <div><?= htmlspecialchars($ownerDetails['phone']) ?></div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <!-- New: Passport Photo Upload/Edit Section -->
                                        <div class="col-md-4">
                                            <label class="form-label d-block">Owner Photo</label>
                                            <div class="passport-photo-container" id="photoContainer">
                                                <?php if ($passportPhotoColumnExists && !empty($ownerDetails['passport_photo_path'])): ?>
                                                    <img src="/dvlaregister/<?= htmlspecialchars($ownerDetails['passport_photo_path']) ?>" 
                                                         alt="Owner Photo" class="passport-photo" id="currentPhoto">
                                                <?php else: ?>
                                                    <div class="text-center text-muted">
                                                        <i class="fas fa-user-circle fa-4x mb-2"></i>
                                                        <p class="mb-0">No photo</p>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="photo-edit-overlay">Click to change photo</div>
                                            </div>
                                            
                                            <div class="file-upload-wrapper">
                                                <input type="file" class="file-upload-input" id="passport_photo" 
                                                       name="passport_photo" accept="image/jpeg,image/png,image/jpg">
                                                <label class="file-upload-label" for="passport_photo">
                                                    <span class="custom-file-label-text">Choose photo...</span>
                                                    <i class="fas fa-camera ms-2"></i>
                                                </label>
                                            </div>
                                            
                                            <ul class="photo-guidelines">
                                                <li>Recent passport-sized photo</li>
                                                <li>Clear face visibility</li>
                                                <li>Plain background</li>
                                                <li>Max 2MB (JPG/PNG)</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Vehicle Registration Information -->
                                <div class="form-section">
                                    <h5 class="form-section-title"><i class="fas fa-id-card me-2"></i>Registration Details</h5>
                                    <div class="row">
                                        <div class="col-md-4 mb-3">
                                            <label for="registration_number" class="form-label">Registration Number *</label>
                                            <input type="text" class="form-control" id="registration_number" name="registration_number" 
                                                   value="<?= htmlspecialchars($vehicle['registration_number']) ?>" required>
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label for="registration_date" class="form-label">Registration Date</label>
                                            <input type="date" class="form-control" id="registration_date" name="registration_date"
                                                   value="<?= htmlspecialchars(date('Y-m-d', strtotime($vehicle['registration_date']))) ?>">
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label for="expiry_date" class="form-label">Expiry Date</label>
                                            <input type="date" class="form-control" id="expiry_date" name="expiry_date"
                                                   value="<?= htmlspecialchars(date('Y-m-d', strtotime($vehicle['expiry_date']))) ?>">
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Vehicle Basic Information -->
                                <div class="form-section">
                                    <h5 class="form-section-title"><i class="fas fa-car me-2"></i>Vehicle Details</h5>
                                    <div class="row">
                                        <div class="col-md-4 mb-3">
                                            <label for="make" class="form-label">Make *</label>
                                            <input type="text" class="form-control" id="make" name="make" 
                                                   value="<?= htmlspecialchars($vehicle['make']) ?>" required>
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label for="model" class="form-label">Model *</label>
                                            <input type="text" class="form-control" id="model" name="model" 
                                                   value="<?= htmlspecialchars($vehicle['model']) ?>" required>
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label for="year" class="form-label">Year of Manufacture *</label>
                                            <input type="number" class="form-control" id="year" name="year" 
                                                   value="<?= htmlspecialchars($vehicle['year_of_manufacture']) ?>" 
                                                   min="1900" max="<?= date('Y') ?>" required>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-4 mb-3">
                                            <label for="color" class="form-label">Color</label>
                                            <input type="text" class="form-control" id="color" name="color" 
                                                   value="<?= htmlspecialchars($vehicle['color'] ?? '') ?>">
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label for="vehicle_type" class="form-label">Vehicle Type</label>
                                            <select class="form-select" id="vehicle_type" name="vehicle_type">
                                                <option value="" <?= empty($vehicle['vehicle_type']) ? 'selected' : '' ?>>-- Select --</option>
                                                <option value="Car" <?= $vehicle['vehicle_type'] === 'Car' ? 'selected' : '' ?>>Car</option>
                                                <option value="Sedan" <?= $vehicle['vehicle_type'] === 'Sedan' ? 'selected' : '' ?>>Sedan</option>
                                                <option value="SUV" <?= $vehicle['vehicle_type'] === 'SUV' ? 'selected' : '' ?>>SUV</option>
                                                <option value="Pickup" <?= $vehicle['vehicle_type'] === 'Pickup' ? 'selected' : '' ?>>Pickup</option>
                                                <option value="Truck" <?= $vehicle['vehicle_type'] === 'Truck' ? 'selected' : '' ?>>Truck</option>
                                                <option value="Bus" <?= $vehicle['vehicle_type'] === 'Bus' ? 'selected' : '' ?>>Bus</option>
                                                <option value="Taxi" <?= $vehicle['vehicle_type'] === 'Taxi' ? 'selected' : '' ?>>Taxi</option>
                                                <option value="Motorcycle" <?= $vehicle['vehicle_type'] === 'Motorcycle' ? 'selected' : '' ?>>Motorcycle</option>
                                                <option value="Other" <?= $vehicle['vehicle_type'] === 'Other' ? 'selected' : '' ?>>Other</option>
                                            </select>
                                        </div>
                                        <!-- Vehicle Use Field -->
                                        <div class="col-md-4 mb-3">
                                            <label for="vehicle_use" class="form-label">Vehicle Use</label>
                                            <select class="form-select" id="vehicle_use" name="vehicle_use">
                                                <option value="Private" <?= (!isset($vehicle['vehicle_use']) || $vehicle['vehicle_use'] === 'Private') ? 'selected' : '' ?>>Private</option>
                                                <option value="Commercial" <?= (isset($vehicle['vehicle_use']) && $vehicle['vehicle_use'] === 'Commercial') ? 'selected' : '' ?>>Commercial</option>
                                                <option value="Government" <?= (isset($vehicle['vehicle_use']) && $vehicle['vehicle_use'] === 'Government') ? 'selected' : '' ?>>Government</option>
                                                <option value="Diplomatic" <?= (isset($vehicle['vehicle_use']) && $vehicle['vehicle_use'] === 'Diplomatic') ? 'selected' : '' ?>>Diplomatic</option>
                                            </select>
                                            <div class="form-text">How the vehicle is used (private, business, etc.)</div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="engine_capacity" class="form-label">Engine Capacity (cc)</label>
                                            <input type="number" class="form-control" id="engine_capacity" name="engine_capacity" 
                                                   value="<?= htmlspecialchars($vehicle['engine_capacity'] ?? '') ?>">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="seating_capacity" class="form-label">Seating Capacity</label>
                                            <input type="number" class="form-control" id="seating_capacity" name="seating_capacity" 
                                                   value="<?= htmlspecialchars($vehicle['seating_capacity'] ?? '') ?>" min="1">
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Technical Details -->
                                <div class="form-section">
                                    <h5 class="form-section-title"><i class="fas fa-cogs me-2"></i>Technical Information</h5>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="chassis_number" class="form-label">Chassis Number</label>
                                            <input type="text" class="form-control" id="chassis_number" name="chassis_number" 
                                                   value="<?= htmlspecialchars($vehicle['chassis_number'] ?? '') ?>">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="engine_number" class="form-label">Engine Number</label>
                                            <input type="text" class="form-control" id="engine_number" name="engine_number" 
                                                   value="<?= htmlspecialchars($vehicle['engine_number'] ?? '') ?>">
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Form Actions -->
                                <div class="d-flex justify-content-between mt-4">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i>Update Vehicle
                                    </button>
                                    <div>
                                        <a href="../vehicle/view-details.php?id=<?= $vehicleId ?>" class="btn btn-info me-2">
                                            <i class="fas fa-eye me-2"></i>View Details
                                        </a>
                                        <a href="manage-vehicles.php" class="btn btn-secondary">
                                            <i class="fas fa-arrow-left me-2"></i>Back to Vehicle List
                                        </a>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <?php include_once __DIR__ . '/../../includes/footer.php'; ?>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Custom JS for photo preview and vehicle type/use relationship -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const photoInput = document.getElementById('passport_photo');
            const photoContainer = document.getElementById('photoContainer');
            const fileLabel = document.querySelector('.custom-file-label-text');
            
            // Click on photo container to trigger file input
            photoContainer.addEventListener('click', function() {
                photoInput.click();
            });
            
            // Handle file selection
            photoInput.addEventListener('change', function() {
                if (this.files && this.files[0]) {
                    // Update file label text
                    const fileName = this.files[0].name;
                    fileLabel.textContent = fileName.length > 20 
                        ? fileName.substring(0, 17) + '...' 
                        : fileName;
                    
                    // Preview the new photo
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        // Check if photo container already has an image
                        let photoImg = document.getElementById('currentPhoto');
                        
                        if (!photoImg) {
                            // If no image exists, create one
                            photoContainer.innerHTML = ''; // Clear placeholder content
                            photoImg = document.createElement('img');
                            photoImg.id = 'currentPhoto';
                            photoImg.className = 'passport-photo';
                            photoContainer.appendChild(photoImg);
                            
                            // Add the overlay
                            const overlay = document.createElement('div');
                            overlay.className = 'photo-edit-overlay';
                            overlay.textContent = 'Click to change photo';
                            photoContainer.appendChild(overlay);
                        }
                        
                        // Update image source
                        photoImg.src = e.target.result;
                    };
                    reader.readAsDataURL(this.files[0]);
                }
            });
            
            // Auto-select vehicle use based on vehicle type
            const vehicleTypeSelect = document.getElementById('vehicle_type');
            const vehicleUseSelect = document.getElementById('vehicle_use');
            
            if (vehicleTypeSelect && vehicleUseSelect) {
                vehicleTypeSelect.addEventListener('change', function() {
                    const vehicleType = this.value.toLowerCase();
                    
                    // Set default vehicle use based on vehicle type
                    if (vehicleType === 'taxi' || vehicleType === 'bus' || vehicleType === 'truck') {
                        vehicleUseSelect.value = 'Commercial';
                    }
                    // Don't automatically change to Private to avoid overriding user's choice
                });
            }
        });
    </script>
    <?php
    include(__DIR__ . '/../../../includes/footer.php');
    ?>
</body>
</html>