<?php
// filepath: /Applications/XAMPP/xamppfiles/htdocs/dvlaregister/src/views/admin/edit-owner.php
session_start();
require_once __DIR__ . '/../../../config/database.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../views/auth/login.php');
    exit;
}

// Check if owner ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: ./manage-owners.php');
    exit;
}

$ownerId = $_GET['id'];
$owner = null;
$success = '';
$error = '';
$photoError = '';
$vehicles = [];

// Create upload directories
// Set project root - this is more reliable
$projectRoot = $_SERVER['DOCUMENT_ROOT'] . '/dvlaregister/';

// Define upload directories
$baseUploadDir = $projectRoot . 'uploads/';
$idDocsDir = $baseUploadDir . 'id_documents/';
$passportPhotosDir = $baseUploadDir . 'passport_photos/';

// Create directories if they don't exist
if (!file_exists($baseUploadDir)) {
    mkdir($baseUploadDir, 0777, true);
}
if (!file_exists($idDocsDir)) {
    mkdir($idDocsDir, 0777, true);
}
if (!file_exists($passportPhotosDir)) {
    mkdir($passportPhotosDir, 0777, true);
}

// Check if passport_photo_path column exists
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
}

// Check if ID document type column exists
$idDocumentTypeExists = false;
try {
    $checkColumnStmt = $pdo->prepare("SHOW COLUMNS FROM vehicle_owners LIKE 'id_document_type'");
    $checkColumnStmt->execute();
    $idDocumentTypeExists = ($checkColumnStmt->rowCount() > 0);
    
    if (!$idDocumentTypeExists) {
        // Add the column to the table
        $alterTableStmt = $pdo->prepare("ALTER TABLE vehicle_owners ADD COLUMN id_document_type ENUM('ghana_card', 'drivers_license', 'passport', 'voter_id') DEFAULT 'ghana_card' AFTER ghana_card_number");
        $alterTableStmt->execute();
        $idDocumentTypeExists = true;
    }
} catch (PDOException $e) {
    error_log("Failed to check/add id_document_type column: " . $e->getMessage());
}

// Check if ghana_card_image_path has been renamed to id_document_image_path
$idDocumentImagePathExists = false;
try {
    $checkColumnStmt = $pdo->prepare("SHOW COLUMNS FROM vehicle_owners LIKE 'id_document_image_path'");
    $checkColumnStmt->execute();
    $idDocumentImagePathExists = ($checkColumnStmt->rowCount() > 0);
    
    if (!$idDocumentImagePathExists) {
        // Check if the old column exists
        $checkOldColumnStmt = $pdo->prepare("SHOW COLUMNS FROM vehicle_owners LIKE 'ghana_card_image_path'");
        $checkOldColumnStmt->execute();
        $oldColumnExists = ($checkOldColumnStmt->rowCount() > 0);
        
        if ($oldColumnExists) {
            // Rename the column
            $alterTableStmt = $pdo->prepare("ALTER TABLE vehicle_owners CHANGE COLUMN ghana_card_image_path id_document_image_path VARCHAR(255)");
            $alterTableStmt->execute();
            $idDocumentImagePathExists = true;
        } else {
            // Add the column if it doesn't exist at all
            $alterTableStmt = $pdo->prepare("ALTER TABLE vehicle_owners ADD COLUMN id_document_image_path VARCHAR(255) AFTER id_document_type");
            $alterTableStmt->execute();
            $idDocumentImagePathExists = true;
        }
    }
} catch (PDOException $e) {
    error_log("Failed to check/rename id_document_image_path column: " . $e->getMessage());
}

try {
    // Get owner details
    $ownerStmt = $pdo->prepare("SELECT * FROM vehicle_owners WHERE id = :id");
    $ownerStmt->execute(['id' => $ownerId]);
    $owner = $ownerStmt->fetch();
    
    if (!$owner) {
        $error = "Owner not found";
    } else {
        // If id_document_type doesn't exist in the owner data (old record), set default
        if (!isset($owner['id_document_type'])) {
            $owner['id_document_type'] = 'ghana_card';
        }
        
        // Handle image path compatibility
        if (!isset($owner['id_document_image_path']) && isset($owner['ghana_card_image_path'])) {
            $owner['id_document_image_path'] = $owner['ghana_card_image_path'];
        }
        
        // Get vehicles owned by this owner
        $vehiclesStmt = $pdo->prepare("
            SELECT id, registration_number, make, model, year_of_manufacture, 
                   color, vehicle_type, registration_date, expiry_date,
                   DATEDIFF(expiry_date, CURDATE()) as days_to_expiry
            FROM vehicles 
            WHERE owner_id = :owner_id
            ORDER BY registration_date DESC
        ");
        $vehiclesStmt->execute(['owner_id' => $ownerId]);
        $vehicles = $vehiclesStmt->fetchAll();
    }
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $owner) {
    // Get form data
    $name = trim($_POST['name'] ?? '');
    $idDocumentNumber = trim($_POST['ghana_card_number'] ?? '');
    $idDocumentType = trim($_POST['id_document_type'] ?? 'ghana_card');
    $dateOfBirth = trim($_POST['date_of_birth'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    
    // Validate input
    if (empty($name)) {
        $error = "Full name is required";
    } elseif (empty($idDocumentNumber)) {
        $error = "ID Document Number is required";
    } elseif (empty($dateOfBirth)) {
        $error = "Date of birth is required";
    } elseif (empty($phone)) {
        $error = "Phone number is required";
    } elseif (empty($address)) {
        $error = "Address is required";
    } else {
        // Check if ID document number already exists (except for this owner)
        $checkStmt = $pdo->prepare("
            SELECT COUNT(*) FROM vehicle_owners 
            WHERE ghana_card_number = :document_number 
            AND id != :owner_id
        ");
        $checkStmt->execute([
            'document_number' => $idDocumentNumber,
            'owner_id' => $ownerId
        ]);
        
        if ($checkStmt->fetchColumn() > 0) {
            $error = "ID document number already exists for another owner";
        }
    }
    
    // Process ID Document file upload (if provided)
    $idDocumentImagePath = isset($owner['id_document_image_path']) ? $owner['id_document_image_path'] : 
                          (isset($owner['ghana_card_image_path']) ? $owner['ghana_card_image_path'] : '');
    
    if (isset($_FILES['id_document_image']) && $_FILES['id_document_image']['error'] === UPLOAD_ERR_OK) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
        $maxSize = 5 * 1024 * 1024; // 5MB
        
        $fileType = $_FILES['id_document_image']['type'];
        $fileSize = $_FILES['id_document_image']['size'];
        
        if (!in_array($fileType, $allowedTypes)) {
            $error = "Invalid file type for ID document. Only JPG, JPEG, PNG, and PDF files are allowed.";
        } elseif ($fileSize > $maxSize) {
            $error = "ID document file is too large. Maximum size is 5MB.";
        } else {
            try {
                // Generate a unique filename based on document type
                $fileName = $_FILES['id_document_image']['name'];
                $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $docPrefix = $idDocumentType . '_';
                $newFileName = $docPrefix . uniqid() . '_' . time() . '.' . $fileExtension;
                $fileTmpName = $_FILES['id_document_image']['tmp_name'];
                $uploadPath = $idDocsDir . $newFileName;
                
                // Upload the file
                if (move_uploaded_file($fileTmpName, $uploadPath)) {
                    // Save the relative path
                    $idDocumentImagePath = 'uploads/id_documents/' . $newFileName;
                } else {
                    $error = "Failed to upload ID document file. Please try again.";
                }
            } catch (Exception $e) {
                $error = "Error uploading ID Document: " . $e->getMessage();
            }
        }
    }
    
    // Process Passport Photo upload (if provided)
    $passportPhotoPath = $owner['passport_photo_path'] ?? '';
    
    if (isset($_FILES['passport_photo']) && $_FILES['passport_photo']['error'] === UPLOAD_ERR_OK) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];
        $maxSize = 2 * 1024 * 1024; // 2MB
        
        $fileType = $_FILES['passport_photo']['type'];
        $fileSize = $_FILES['passport_photo']['size'];
        
        if (!in_array($fileType, $allowedTypes)) {
            $photoError = "Invalid file type for passport photo. Only JPG, JPEG, and PNG files are allowed.";
        } elseif ($fileSize > $maxSize) {
            $photoError = "Passport photo is too large. Maximum size is 2MB.";
        } else {
            try {
                // Generate a unique filename
                $fileName = $_FILES['passport_photo']['name'];
                $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $newFileName = 'passport_' . uniqid() . '_' . time() . '.' . $fileExtension;
                $fileTmpName = $_FILES['passport_photo']['tmp_name'];
                $uploadPath = $passportPhotosDir . $newFileName;
                
                // Upload the file
                if (move_uploaded_file($fileTmpName, $uploadPath)) {
                    // Save the relative path
                    $passportPhotoPath = 'uploads/passport_photos/' . $newFileName;
                } else {
                    $photoError = "Failed to upload passport photo. Please try again.";
                }
            } catch (Exception $e) {
                $photoError = "Error uploading passport photo: " . $e->getMessage();
            }
        }
    }
    
    // If no errors, update the owner
    if (empty($error)) {
        try {
            // Build SQL query based on available columns
            $sql = "UPDATE vehicle_owners SET 
                    name = :name,
                    ghana_card_number = :document_number,
                    date_of_birth = :date_of_birth,
                    phone = :phone,
                    email = :email,
                    address = :address,
                    updated_at = NOW()";
                    
            $params = [
                'name' => $name,
                'document_number' => $idDocumentNumber,
                'date_of_birth' => $dateOfBirth,
                'phone' => $phone,
                'email' => $email,
                'address' => $address,
                'owner_id' => $ownerId
            ];
            
            // Add id_document_type if the column exists
            if ($idDocumentTypeExists) {
                $sql .= ", id_document_type = :id_document_type";
                $params['id_document_type'] = $idDocumentType;
            }
            
            // Add appropriate image path column based on database structure
            if ($idDocumentImagePathExists) {
                $sql .= ", id_document_image_path = :id_document_image_path";
                $params['id_document_image_path'] = $idDocumentImagePath;
            } else {
                $sql .= ", ghana_card_image_path = :ghana_card_image_path";
                $params['ghana_card_image_path'] = $idDocumentImagePath;
            }
            
            // Add passport photo if that column exists
            if ($passportPhotoColumnExists) {
                $sql .= ", passport_photo_path = :passport_photo_path";
                $params['passport_photo_path'] = $passportPhotoPath;
            }
            
            // Complete the query
            $sql .= " WHERE id = :owner_id";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $success = $photoError ? "Owner details updated, but there was an issue with the photo upload." : "Owner details updated successfully.";
            
            // Refresh owner data
            $ownerStmt->execute(['id' => $ownerId]);
            $owner = $ownerStmt->fetch();
            
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Helper function to format date for display
function formatDate($date) {
    return $date ? date('F j, Y', strtotime($date)) : 'N/A';
}

// Helper function to format date for input field
function formatDateInput($date) {
    return $date ? date('Y-m-d', strtotime($date)) : '';
}

// Helper function to get document type display name
function getDocumentTypeDisplay($type) {
    switch ($type) {
        case 'ghana_card': return 'Ghana Card';
        case 'drivers_license': return 'Driver\'s License';
        case 'passport': return 'Passport';
        case 'voter_id': return 'Voter ID';
        default: return 'Ghana Card';
    }
}

// Determine which image path field to use
$documentImagePath = isset($owner['id_document_image_path']) ? $owner['id_document_image_path'] : 
                    (isset($owner['ghana_card_image_path']) ? $owner['ghana_card_image_path'] : '');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Vehicle Owner | DVLA Admin</title>
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
        
        .required-field::after {
            content: "*";
            color: red;
            margin-left: 4px;
        }
        
        .owner-card {
            background-color: #f8f9fa;
            border-left: 4px solid #0d6efd;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
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
        
        .image-preview {
            width: 100%;
            max-height: 200px;
            border-radius: 5px;
            border: 1px solid #dee2e6;
            display: none;
            object-fit: cover;
            margin-top: 10px;
        }
        
        .pdf-preview {
            width: 100%;
            height: 200px;
            border-radius: 5px;
            border: 1px solid #dee2e6;
            display: none;
            margin-top: 10px;
        }
        
        .pdf-icon {
            text-align: center;
            padding: 20px;
            background-color: #f8f9fa;
            border-radius: 5px;
            margin-top: 10px;
            display: none;
        }
        
        .pdf-icon i {
            font-size: 80px;
            color: #dc3545;
        }
        
        .view-document {
            display: inline-block;
            margin-top: 10px;
        }
        
        .passport-photo-container {
            position: relative;
            width: 180px;
            height: 180px;
            border-radius: 5px;
            overflow: hidden;
            margin-bottom: 15px;
        }
        
        .passport-photo {
            width: 100%;
            height: 100%;
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
        
        .placeholder-container {
            width: 180px;
            height: 180px;
            background-color: #f8f9fa;
            border: 2px dashed #ccc;
            border-radius: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            color: #6c757d;
            margin-bottom: 15px;
        }
        
        .placeholder-container i {
            font-size: 3rem;
            margin-bottom: 10px;
        }
        
        .photo-guidelines {
            font-size: 0.85rem;
            color: #6c757d;
            margin-top: 10px;
        }
        
        .photo-guidelines li {
            margin-bottom: 5px;
        }
        
        .vehicle-card {
            transition: transform 0.2s;
            margin-bottom: 15px;
        }
        
        .vehicle-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        
        .status-active {
            color: #198754;
        }
        
        .status-warning {
            color: #ffc107;
        }
        
        .status-expired {
            color: #dc3545;
        }
        
        /* Document type selector styles */
        .document-type-selector {
            margin-bottom: 20px;
        }
        
        .document-type-selector .btn-check {
            position: absolute;
            clip: rect(0,0,0,0);
            pointer-events: none;
        }
        
        .document-type-selector .btn-outline-primary {
            border-color: #ced4da;
            color: #495057;
        }
        
        .document-type-selector .btn-check:checked + .btn-outline-primary {
            background-color: #0d6efd;
            border-color: #0d6efd;
            color: #fff;
        }
        
        .document-type-selector .btn-outline-primary:hover {
            background-color: rgba(13, 110, 253, 0.1);
            border-color: #ced4da;
            color: #495057;
        }
        
        .btn-check:checked + .btn-outline-primary:hover {
            background-color: #0d6efd;
            color: #fff;
        }
        
        .document-type-selector .form-icon {
            margin-right: 8px;
        }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../../includes/admin-header.php'; ?>
    
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
                            <li class="breadcrumb-item"><a href="manage-owners.php">Manage Owners</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Edit Owner</li>
                        </ol>
                    </nav>
                </div>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        <?php if (!$owner): ?>
                            <div class="mt-3">
                                <a href="manage-owners.php" class="btn btn-primary">Return to Owners List</a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($photoError): ?>
                    <div class="alert alert-warning alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($photoError) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($owner): ?>
                    <div class="card mb-4">
                        <div class="card-header bg-primary text-white">
                            <h4 class="mb-0">
                                <i class="fas fa-user-edit me-2"></i>Edit Vehicle Owner
                            </h4>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="" enctype="multipart/form-data">
                                <div class="row">
                                    <!-- Left Column: Owner Details Form -->
                                    <div class="col-md-8">
                                        <!-- Basic Information Section -->
                                        <div class="form-section">
                                            <h5 class="form-section-title"><i class="fas fa-user me-2"></i>Personal Information</h5>
                                            <div class="row mb-3">
                                                <div class="col-md-12">
                                                    <label for="name" class="form-label required-field">Full Name</label>
                                                    <input type="text" class="form-control" id="name" name="name" 
                                                           value="<?= htmlspecialchars($owner['name']) ?>" required>
                                                </div>
                                            </div>
                                            
                                            <div class="row mb-3">
                                                <div class="col-md-6">
                                                    <label for="date_of_birth" class="form-label required-field">Date of Birth</label>
                                                    <input type="date" class="form-control" id="date_of_birth" name="date_of_birth" 
                                                           value="<?= formatDateInput($owner['date_of_birth']) ?>" required>
                                                </div>
                                                <div class="col-md-6">
                                                    <label for="phone" class="form-label required-field">Phone Number</label>
                                                    <input type="tel" class="form-control" id="phone" name="phone" 
                                                           value="<?= htmlspecialchars($owner['phone']) ?>" 
                                                           placeholder="+233 XX XXX XXXX" required>
                                                </div>
                                            </div>
                                            
                                            <div class="row mb-3">
                                                <div class="col-md-12">
                                                    <label for="email" class="form-label">Email Address</label>
                                                    <input type="email" class="form-control" id="email" name="email" 
                                                           value="<?= htmlspecialchars($owner['email'] ?? '') ?>">
                                                </div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label for="address" class="form-label required-field">Address</label>
                                                <textarea class="form-control" id="address" name="address" rows="3" required><?= htmlspecialchars($owner['address']) ?></textarea>
                                            </div>
                                        </div>
                                        
                                        <!-- ID Document Section -->
                                        <div class="form-section">
                                            <h5 class="form-section-title"><i class="fas fa-id-card me-2"></i>Identification Document</h5>
                                            
                                            <!-- Document Type Selection -->
                                            <div class="mb-3">
                                                <label class="form-label required-field">ID Document Type</label>
                                                <div class="document-type-selector">
                                                    <input type="radio" class="btn-check" name="id_document_type" id="id_type_ghana_card" value="ghana_card" 
                                                        <?= (!isset($owner['id_document_type']) || $owner['id_document_type'] === 'ghana_card') ? 'checked' : '' ?> autocomplete="off">
                                                    <label class="btn btn-outline-primary" for="id_type_ghana_card">
                                                        <i class="fas fa-id-card form-icon"></i>Ghana Card
                                                    </label>
                                                    
                                                    <input type="radio" class="btn-check" name="id_document_type" id="id_type_drivers_license" value="drivers_license" 
                                                        <?= (isset($owner['id_document_type']) && $owner['id_document_type'] === 'drivers_license') ? 'checked' : '' ?> autocomplete="off">
                                                    <label class="btn btn-outline-primary" for="id_type_drivers_license">
                                                        <i class="fas fa-car form-icon"></i>Driver's License
                                                    </label>
                                                    
                                                    <input type="radio" class="btn-check" name="id_document_type" id="id_type_passport" value="passport" 
                                                        <?= (isset($owner['id_document_type']) && $owner['id_document_type'] === 'passport') ? 'checked' : '' ?> autocomplete="off">
                                                    <label class="btn btn-outline-primary" for="id_type_passport">
                                                        <i class="fas fa-passport form-icon"></i>Passport
                                                    </label>
                                                    
                                                    <input type="radio" class="btn-check" name="id_document_type" id="id_type_voter_id" value="voter_id" 
                                                        <?= (isset($owner['id_document_type']) && $owner['id_document_type'] === 'voter_id') ? 'checked' : '' ?> autocomplete="off">
                                                    <label class="btn btn-outline-primary" for="id_type_voter_id">
                                                        <i class="fas fa-vote-yea form-icon"></i>Voter ID
                                                    </label>
                                                </div>
                                            </div>
                                            
                                            <!-- ID Document Number -->
                                            <div class="mb-3">
                                                <label for="ghana_card_number" class="form-label required-field" id="id_document_label">ID Document Number</label>
                                                <input type="text" class="form-control" id="ghana_card_number" name="ghana_card_number" 
                                                       value="<?= htmlspecialchars($owner['ghana_card_number']) ?>" required>
                                                <div class="form-text" id="id_format_hint">Format depends on selected document type</div>
                                            </div>
                                            
                                            <!-- Document Image Preview & Upload -->
                                            <?php if (!empty($documentImagePath)): 
                                                $filePath = $documentImagePath;
                                                $fileExtension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
                                                $isPdf = $fileExtension === 'pdf';
                                                
                                                // Determine document type display name
                                                $documentTypeName = 'ID Document';
                                                if (isset($owner['id_document_type'])) {
                                                    $documentTypeName = getDocumentTypeDisplay($owner['id_document_type']);
                                                }
                                            ?>
                                                <div class="mb-3">
                                                    <p class="mb-2">Current <?= $documentTypeName ?>:</p>
                                                    <?php if ($isPdf): ?>
                                                        <div class="pdf-icon" style="display: block;">
                                                            <i class="far fa-file-pdf"></i>
                                                            <p class="mt-2 mb-0">PDF Document</p>
                                                        </div>
                                                    <?php else: ?>
                                                        <img src="/dvlaregister/<?= htmlspecialchars($documentImagePath) ?>" 
                                                             alt="ID Document" class="image-preview" style="display: block;">
                                                    <?php endif; ?>
                                                    <a href="/dvlaregister/<?= htmlspecialchars($documentImagePath) ?>" 
                                                       class="btn btn-sm btn-outline-primary view-document" target="_blank">
                                                        <i class="fas fa-eye me-1"></i> View Document
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <div class="file-upload-wrapper">
                                                <input type="file" class="file-upload-input" id="id_document_image" name="id_document_image" 
                                                       accept="image/jpeg,image/png,image/jpg,application/pdf">
                                                <label class="file-upload-label" for="id_document_image">
                                                    <span class="custom-file-label-text">
                                                        <?= !empty($documentImagePath) ? 'Change document...' : 'Choose document...' ?>
                                                    </span>
                                                    <i class="fas fa-upload ms-2"></i>
                                                </label>
                                            </div>
                                            <div class="form-text" id="upload_format_hint">
                                                Upload a clear image or PDF of your ID document. Max size: 5MB. Formats: JPG, PNG, PDF.
                                            </div>
                                            <img id="newDocumentImagePreview" class="image-preview" alt="ID Document Preview">
                                            <div id="newPdfIcon" class="pdf-icon">
                                                <i class="far fa-file-pdf"></i>
                                                <p class="mt-2 mb-0">PDF Document</p>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Right Column: Passport Photo and Owner Summary -->
                                    <div class="col-md-4">
                                        <!-- Passport Photo Section -->
                                        <div class="card mb-4">
                                            <div class="card-header bg-success text-white">
                                                <h5 class="mb-0"><i class="fas fa-camera me-2"></i>Passport Photo</h5>
                                            </div>
                                            <div class="card-body text-center">
                                                <?php if ($passportPhotoColumnExists && !empty($owner['passport_photo_path'])): ?>
                                                    <div class="passport-photo-container" id="photoContainer">
                                                        <img src="/dvlaregister/<?= htmlspecialchars($owner['passport_photo_path']) ?>" 
                                                             alt="Passport Photo" class="passport-photo" id="currentPhoto">
                                                        <div class="photo-edit-overlay">Click to change photo</div>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="placeholder-container" id="photoContainer">
                                                        <i class="fas fa-user-circle"></i>
                                                        <p class="mb-0">No passport photo</p>
                                                        <div class="photo-edit-overlay">Click to add photo</div>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <div class="file-upload-wrapper">
                                                    <input type="file" class="file-upload-input" id="passport_photo" name="passport_photo" 
                                                           accept="image/jpeg,image/png,image/jpg">
                                                    <label class="file-upload-label" for="passport_photo">
                                                        <span class="passport-file-label-text">
                                                            <?= ($passportPhotoColumnExists && !empty($owner['passport_photo_path'])) ? 'Change photo...' : 'Choose photo...' ?>
                                                        </span>
                                                        <i class="fas fa-camera ms-2"></i>
                                                    </label>
                                                </div>
                                                
                                                <ul class="photo-guidelines text-start">
                                                    <li>Recent passport-sized photo</li>
                                                    <li>Clear face visibility</li>
                                                    <li>Plain background</li>
                                                    <li>Max 2MB (JPG/PNG)</li>
                                                </ul>
                                            </div>
                                        </div>
                                        
                                        <!-- Owner Information Summary -->
                                        <div class="card mb-4">
                                            <div class="card-header bg-info text-white">
                                                <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Owner Summary</h5>
                                            </div>
                                            <div class="card-body">
                                                <p class="mb-1"><strong>Created:</strong> <?= formatDate($owner['created_at']) ?></p>
                                                <p><strong>Last Updated:</strong> <?= formatDate($owner['updated_at']) ?></p>
                                                
                                                <hr>
                                                
                                                <h6 class="mb-3">Vehicles Owned (<?= count($vehicles) ?>)</h6>
                                                <?php if (count($vehicles) > 0): ?>
                                                    <?php foreach ($vehicles as $vehicle): 
                                                        $status = 'active';
                                                        $statusIcon = 'fa-check-circle';
                                                        $statusClass = 'status-active';
                                                        
                                                        if ($vehicle['days_to_expiry'] < 0) {
                                                            $status = 'expired';
                                                            $statusIcon = 'fa-times-circle';
                                                            $statusClass = 'status-expired';
                                                        } elseif ($vehicle['days_to_expiry'] <= 30) {
                                                            $status = 'expiring soon';
                                                            $statusIcon = 'fa-exclamation-circle';
                                                            $statusClass = 'status-warning';
                                                        }
                                                    ?>
                                                        <div class="vehicle-card p-2 border rounded">
                                                            <div class="d-flex justify-content-between">
                                                                <div>
                                                                    <strong><?= htmlspecialchars($vehicle['registration_number']) ?></strong>
                                                                    <p class="mb-0 small text-muted">
                                                                        <?= htmlspecialchars($vehicle['make'] . ' ' . $vehicle['model']) ?>
                                                                        (<?= htmlspecialchars($vehicle['year_of_manufacture']) ?>)
                                                                    </p>
                                                                </div>
                                                                <div class="text-end">
                                                                    <span class="<?= $statusClass ?>">
                                                                        <i class="fas <?= $statusIcon ?>"></i> <?= ucfirst($status) ?>
                                                                    </span>
                                                                    <div>
                                                                        <a href="../vehicle/view-details.php?id=<?= $vehicle['id'] ?>" class="btn btn-sm btn-outline-primary mt-1">
                                                                            <i class="fas fa-eye"></i>
                                                                        </a>
                                                                        <a href="edit-vehicle.php?id=<?= $vehicle['id'] ?>" class="btn btn-sm btn-outline-secondary mt-1">
                                                                            <i class="fas fa-edit"></i>
                                                                        </a>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <p class="text-muted">This owner has no registered vehicles.</p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                                        <!-- ID Guidelines Card -->
                                        <div class="card mb-4">
                                            <div class="card-header bg-light">
                                                <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>ID Document Guidelines</h5>
                                            </div>
                                            <div class="card-body">
                                                <h6 class="mb-2">Acceptable Documents:</h6>
                                                <ul class="small">
                                                    <li><strong>Ghana Card:</strong> National ID format GHA-XXXXXXXX-X</li>
                                                    <li><strong>Driver's License:</strong> Complete license number</li>
                                                    <li><strong>Passport:</strong> Passport number from bio page</li>
                                                    <li><strong>Voter ID:</strong> Voter identification number</li>
                                                </ul>
                                                
                                                <h6 class="mb-2 mt-3">Document Requirements:</h6>
                                                <ul class="small mb-0">
                                                    <li>Document must be valid and not expired</li>
                                                    <li>Text must be clearly readable</li>
                                                    <li>All document sides/pages must be included</li>
                                                    <li>No digital alterations or edits allowed</li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Form Actions -->
                                <div class="d-flex justify-content-between mt-4">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i>Update Owner Details
                                    </button>
                                    <div>
                                        <a href="manage-owners.php" class="btn btn-secondary">
                                            <i class="fas fa-arrow-left me-2"></i>Back to Owners List
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
    
    <!-- Custom JavaScript for file uploads and document type handling -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // ID Document Type handling
            const idDocumentRadios = document.querySelectorAll('input[name="id_document_type"]');
            const idDocumentLabel = document.getElementById('id_document_label');
            const idFormatHint = document.getElementById('id_format_hint');
            const uploadFormatHint = document.getElementById('upload_format_hint');
            const idNumberInput = document.getElementById('ghana_card_number');
            
            // Function to update labels based on document type
            function updateLabelsForDocumentType(docType) {
                // Remove old event listeners
                idNumberInput.removeEventListener('input', formatGhanaCard);
                idNumberInput.removeEventListener('input', formatPassport);
                idNumberInput.removeEventListener('input', formatDriversLicense);
                idNumberInput.removeEventListener('input', formatVoterId);
                
                switch(docType) {
                    case 'ghana_card':
                        idDocumentLabel.textContent = 'Ghana Card Number';
                        idFormatHint.textContent = 'Format: GHA-XXXXXXXXX-X';
                        uploadFormatHint.textContent = 'Upload a clear image or PDF of your Ghana Card (front side). Max size: 5MB.';
                        idNumberInput.addEventListener('input', formatGhanaCard);
                        break;
                    case 'drivers_license':
                        idDocumentLabel.textContent = 'Driver\'s License Number';
                        idFormatHint.textContent = 'Enter your complete driver\'s license number';
                        uploadFormatHint.textContent = 'Upload a clear image or PDF of your Driver\'s License. Max size: 5MB.';
                        idNumberInput.addEventListener('input', formatDriversLicense);
                        break;
                    case 'passport':
                        idDocumentLabel.textContent = 'Passport Number';
                        idFormatHint.textContent = 'Enter your passport number';
                        uploadFormatHint.textContent = 'Upload a clear image or PDF of your Passport bio page. Max size: 5MB.';
                        idNumberInput.addEventListener('input', formatPassport);
                        break;
                    case 'voter_id':
                        idDocumentLabel.textContent = 'Voter ID Number';
                        idFormatHint.textContent = 'Enter your voter identification number';
                        uploadFormatHint.textContent = 'Upload a clear image or PDF of your Voter ID Card. Max size: 5MB.';
                        idNumberInput.addEventListener('input', formatVoterId);
                        break;
                }
            }
            
            // Add event listeners to radio buttons
            idDocumentRadios.forEach(radio => {
                radio.addEventListener('change', function() {
                    updateLabelsForDocumentType(this.value);
                });
            });
            
            // Initialize with selected value
            const selectedDocType = document.querySelector('input[name="id_document_type"]:checked').value;
            updateLabelsForDocumentType(selectedDocType);
            
            // Format functions for different document types
            function formatGhanaCard() {
                let value = this.value.toUpperCase().replace(/[^A-Z0-9-]/g, '');
                
                // Add hyphens if they're missing
                if (value.length >= 4 && !value.includes('-')) {
                    // Try to format it as GHA-XXXXXXXXX-X
                    if (value.startsWith('GHA')) {
                        value = 'GHA-' + value.substring(3);
                    }
                }
                
                this.value = value;
            }
            
            function formatPassport() {
                // Simple uppercase formatting for passport numbers
                this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
            }
            
            function formatDriversLicense() {
                // Format for driver's license - uppercase with allowed hyphens and numbers
                this.value = this.value.toUpperCase().replace(/[^A-Z0-9-]/g, '');
            }
            
            function formatVoterId() {
                // Format for voter ID - uppercase with allowed numbers
                this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
            }
            
            // ID Document file upload preview
            const documentInput = document.getElementById('id_document_image');
            const documentLabel = document.querySelector('.custom-file-label-text');
            const newDocumentImagePreview = document.getElementById('newDocumentImagePreview');
            const newPdfIcon = document.getElementById('newPdfIcon');
            
            documentInput.addEventListener('change', function() {
                if (this.files && this.files[0]) {
                    // Update file name in label
                    const fileName = this.files[0].name;
                    documentLabel.textContent = fileName.length > 25 
                        ? fileName.substring(0, 22) + '...' 
                        : fileName;
                    
                    // Determine file type
                    const fileType = this.files[0].type;
                    
                    // Hide both previews initially
                    newDocumentImagePreview.style.display = 'none';
                    newPdfIcon.style.display = 'none';
                    
                    // Show appropriate preview based on file type
                    if (fileType === 'application/pdf') {
                        // Show PDF icon for PDF files
                        newPdfIcon.style.display = 'block';
                    } else if (fileType.startsWith('image/')) {
                        // Show image preview for images
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            newDocumentImagePreview.src = e.target.result;
                            newDocumentImagePreview.style.display = 'block';
                        };
                        reader.readAsDataURL(this.files[0]);
                    }
                } else {
                    documentLabel.textContent = 'Choose document...';
                    newDocumentImagePreview.style.display = 'none';
                    newPdfIcon.style.display = 'none';
                }
            });
            
            // Passport Photo Upload and Preview
            const photoInput = document.getElementById('passport_photo');
            const photoContainer = document.getElementById('photoContainer');
            const photoLabel = document.querySelector('.passport-file-label-text');
            
            // Click on photo container to trigger file input
            if (photoContainer) {
                photoContainer.addEventListener('click', function() {
                    photoInput.click();
                });
            }
            
            // Handle file selection for passport photo
            photoInput.addEventListener('change', function() {
                if (this.files && this.files[0]) {
                    // Update file label text
                    const fileName = this.files[0].name;
                    photoLabel.textContent = fileName.length > 20 
                        ? fileName.substring(0, 17) + '...' 
                        : fileName;
                    
                    // Determine file type
                    const fileType = this.files[0].type;
                    
                    // Only process image files
                    if (fileType.startsWith('image/')) {
                        // Show image preview
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            // Check if photo container needs to be converted from placeholder to actual photo
                            if (photoContainer.classList.contains('placeholder-container')) {
                                // Convert from placeholder to photo container
                                photoContainer.classList.remove('placeholder-container');
                                photoContainer.classList.add('passport-photo-container');
                                photoContainer.innerHTML = ''; // Clear placeholder content
                                
                                // Create new image element
                                const photoImg = document.createElement('img');
                                photoImg.id = 'currentPhoto';
                                photoImg.className = 'passport-photo';
                                photoImg.src = e.target.result;
                                photoContainer.appendChild(photoImg);
                                
                                // Add overlay
                                const overlay = document.createElement('div');
                                overlay.className = 'photo-edit-overlay';
                                overlay.textContent = 'Click to change photo';
                                photoContainer.appendChild(overlay);
                            } else {
                                // If container already has photo, just update the source
                                let photoImg = document.getElementById('currentPhoto');
                                if (photoImg) {
                                    photoImg.src = e.target.result;
                                }
                            }
                        };
                        reader.readAsDataURL(this.files[0]);
                    }
                }
            });
            
            // Format phone number with Ghana country code
            const phoneInput = document.getElementById('phone');
            phoneInput.addEventListener('focus', function() {
                if (!this.value || this.value.trim() === '') {
                    this.value = '+233';
                }
            });
        });
    </script>
</body>
</html>