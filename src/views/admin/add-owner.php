<?php
// filepath: /Applications/XAMPP/xamppfiles/htdocs/dvlaregister/src/views/admin/add-owner.php
session_start();
require_once __DIR__ . '/../../../config/database.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../views/auth/login.php');
    exit;
}

// Create upload directories
// Set project root - this is more reliable
$projectRoot = $_SERVER['DOCUMENT_ROOT'] . '/';

// Define upload directories
$baseUploadDir = $projectRoot . 'uploads/';
$idDocsDir = $baseUploadDir . 'id_documents/'; // Renamed from ghana_cards to id_documents
$passportPhotosDir = $baseUploadDir . 'passport_photos/';

// Create directories if they don't exist
if (!file_exists($baseUploadDir)) {
    if (!@mkdir($baseUploadDir, 0777, true)) {
        // Try with relative path if absolute path fails
        $baseUploadDir = __DIR__ . '/../../../uploads/';
        if (!file_exists($baseUploadDir)) {
            @mkdir($baseUploadDir, 0777, true);
        }
    }
}

if (!file_exists($idDocsDir)) {
    if (!@mkdir($idDocsDir, 0777, true)) {
        // Try with relative path if absolute path fails
        $idDocsDir = __DIR__ . '/../../../uploads/id_documents/';
        if (!file_exists($idDocsDir)) {
            @mkdir($idDocsDir, 0777, true);
        }
    }
}

// Create passport photos directory
if (!file_exists($passportPhotosDir)) {
    if (!@mkdir($passportPhotosDir, 0777, true)) {
        // Try with relative path if absolute path fails
        $passportPhotosDir = __DIR__ . '/../../../uploads/passport_photos/';
        if (!file_exists($passportPhotosDir)) {
            @mkdir($passportPhotosDir, 0777, true);
        }
    }
}

// Ensure directories are writable
if (file_exists($baseUploadDir) && !is_writable($baseUploadDir)) {
    @chmod($baseUploadDir, 0777);
}

if (file_exists($idDocsDir) && !is_writable($idDocsDir)) {
    @chmod($idDocsDir, 0777);
}

if (file_exists($passportPhotosDir) && !is_writable($passportPhotosDir)) {
    @chmod($passportPhotosDir, 0777);
}

// Debug information
$initErrors = [];
if (!file_exists($baseUploadDir) || !is_writable($baseUploadDir)) {
    $initErrors[] = "Upload base directory is not available or writable.";
}

if (!file_exists($idDocsDir) || !is_writable($idDocsDir)) {
    $initErrors[] = "ID Documents directory is not available or writable.";
}

if (!file_exists($passportPhotosDir) || !is_writable($passportPhotosDir)) {
    $initErrors[] = "Passport Photos directory is not available or writable.";
}

$errors = [];
$success = false;
$ownerData = [
    'name' => '',
    'ghana_card_number' => '', // Will be renamed to id_document_number in DB
    'id_document_type' => 'ghana_card', // Default document type
    'date_of_birth' => '',
    'phone' => '',
    'email' => '',
    'address' => '',
    'ghana_card_image_path' => '', // Will be renamed to id_document_image_path in DB
    'passport_photo_path' => ''
];

// Display initialization errors if any
if (!empty($initErrors)) {
    $errors = array_merge($errors, $initErrors);
}

// Check if the DB has been updated for ID document types
try {
    // Check if id_document_type column exists
    $columnCheck = $pdo->query("SHOW COLUMNS FROM vehicle_owners LIKE 'id_document_type'");
    $hasDocumentTypeColumn = $columnCheck->rowCount() > 0;
    
    if (!$hasDocumentTypeColumn) {
        // Add necessary columns for multiple ID types
        $pdo->exec("ALTER TABLE vehicle_owners ADD COLUMN id_document_type ENUM('ghana_card', 'drivers_license', 'passport', 'voter_id') DEFAULT 'ghana_card' AFTER ghana_card_number");
    }
    
    // Check if ghana_card_image_path has been renamed to id_document_image_path
    $columnCheck = $pdo->query("SHOW COLUMNS FROM vehicle_owners LIKE 'id_document_image_path'");
    $hasNewImagePathColumn = $columnCheck->rowCount() > 0;
    
    if (!$hasNewImagePathColumn) {
        // Check if old column exists first
        $oldColumnCheck = $pdo->query("SHOW COLUMNS FROM vehicle_owners LIKE 'ghana_card_image_path'");
        if ($oldColumnCheck->rowCount() > 0) {
            // Rename the column
            $pdo->exec("ALTER TABLE vehicle_owners CHANGE COLUMN ghana_card_image_path id_document_image_path VARCHAR(255)");
        } else {
            // Add the column if it doesn't exist
            $pdo->exec("ALTER TABLE vehicle_owners ADD COLUMN id_document_image_path VARCHAR(255) AFTER ghana_card_number");
        }
    }
    
} catch (PDOException $e) {
    $errors[] = "Database schema update failed: " . $e->getMessage();
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate and sanitize input
    $ownerData = [
        'name' => trim($_POST['name'] ?? ''),
        'ghana_card_number' => trim($_POST['ghana_card_number'] ?? ''),
        'id_document_type' => trim($_POST['id_document_type'] ?? 'ghana_card'),
        'date_of_birth' => trim($_POST['date_of_birth'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'address' => trim($_POST['address'] ?? ''),
        'ghana_card_image_path' => '',
        'passport_photo_path' => ''
    ];
    
    // Validation
    if (empty($ownerData['name'])) {
        $errors[] = "Full name is required";
    }
    
    if (empty($ownerData['ghana_card_number'])) {
        $errors[] = "ID document number is required";
    } else {
        // Check if ID document number already exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM vehicle_owners WHERE ghana_card_number = :ghana_card_number");
        $stmt->execute(['ghana_card_number' => $ownerData['ghana_card_number']]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = "This ID document number already exists in the system";
        }
    }
    
    if (empty($ownerData['date_of_birth'])) {
        $errors[] = "Date of birth is required";
    } else {
        // Calculate age from date of birth
        $dob = new DateTime($ownerData['date_of_birth']);
        $today = new DateTime('now');
        $age = $dob->diff($today)->y;
        
        // Check if user is at least 18 years old
        if ($age < 18) {
            $errors[] = "Vehicle owner must be at least 18 years old";
        }
    }
    
    if (empty($ownerData['phone'])) {
        $errors[] = "Phone number is required";
    } else if (!preg_match('/^\+?[0-9]{10,15}$/', $ownerData['phone'])) {
        $errors[] = "Phone number format is invalid";
    }
    
    if (!empty($ownerData['email']) && !filter_var($ownerData['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Email format is invalid";
    }
    
    if (empty($ownerData['address'])) {
        $errors[] = "Address is required";
    }
    
    // Process ID Document upload
    if (isset($_FILES['id_document_image']) && $_FILES['id_document_image']['error'] === UPLOAD_ERR_OK) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
        $maxSize = 5 * 1024 * 1024; // 5MB
        
        $fileType = $_FILES['id_document_image']['type'];
        $fileSize = $_FILES['id_document_image']['size'];
        $fileTmpName = $_FILES['id_document_image']['tmp_name'];
        $fileName = $_FILES['id_document_image']['name'];
        
        // Debug info
        error_log("File upload info - Type: $fileType, Size: $fileSize, Name: $fileName");
        
        // Validate file type
        if (!in_array($fileType, $allowedTypes)) {
            $errors[] = "Invalid file type for ID document. Only JPG, JPEG, PNG, and PDF files are allowed.";
        }
        
        // Validate file size
        if ($fileSize > $maxSize) {
            $errors[] = "ID document file is too large. Maximum size is 5MB.";
        }
        
        if (empty($errors)) {
            try {
                // Generate a unique filename based on document type
                $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $docTypePrefix = $ownerData['id_document_type'] . '_';
                $newFileName = $docTypePrefix . uniqid() . '_' . time() . '.' . $fileExtension;
                
                // Use system temp directory as fallback if needed
                if (!is_writable($idDocsDir)) {
                    $uploadDir = sys_get_temp_dir() . '/dvla_uploads/';
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    $uploadPath = $uploadDir . $newFileName;
                    error_log("Using system temp directory: $uploadPath");
                } else {
                    $uploadPath = $idDocsDir . $newFileName;
                }
                
                // Debug info
                error_log("Attempting to move uploaded file to: $uploadPath");
                
                // Upload the file with direct copy instead of move_uploaded_file
                if (copy($fileTmpName, $uploadPath)) {
                    // Save the relative path to database
                    $ownerData['ghana_card_image_path'] = 'uploads/id_documents/' . $newFileName;
                    error_log("File uploaded successfully to: $uploadPath");
                } else {
                    // Fallback to move_uploaded_file
                    if (move_uploaded_file($fileTmpName, $uploadPath)) {
                        $ownerData['ghana_card_image_path'] = 'uploads/id_documents/' . $newFileName;
                        error_log("File moved successfully to: $uploadPath");
                    } else {
                        $errors[] = "Failed to upload the ID document. Technical details have been logged.";
                        error_log("Failed to upload file. Error: " . error_get_last()['message']);
                    }
                }
            } catch (Exception $e) {
                $errors[] = "Exception during ID document file upload: " . $e->getMessage();
                error_log("Exception in file upload: " . $e->getMessage());
            }
        }
    } else {
        // Handle file upload errors for ID Document
        if (!isset($_FILES['id_document_image'])) {
            $errors[] = "No ID document file was uploaded.";
        } else {
            switch ($_FILES['id_document_image']['error']) {
                case UPLOAD_ERR_INI_SIZE:
                    $errors[] = "The uploaded ID document file exceeds the upload_max_filesize directive in php.ini.";
                    break;
                case UPLOAD_ERR_FORM_SIZE:
                    $errors[] = "The uploaded ID document file exceeds the MAX_FILE_SIZE directive in the HTML form.";
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $errors[] = "The ID document file was only partially uploaded.";
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $errors[] = "ID document is required.";
                    break;
                case UPLOAD_ERR_NO_TMP_DIR:
                    $errors[] = "Missing a temporary folder for file uploads.";
                    break;
                case UPLOAD_ERR_CANT_WRITE:
                    $errors[] = "Failed to write file to disk. Check server permissions.";
                    break;
                case UPLOAD_ERR_EXTENSION:
                    $errors[] = "A PHP extension stopped the file upload.";
                    break;
                default:
                    $errors[] = "Unknown upload error (Code: " . $_FILES['id_document_image']['error'] . ").";
            }
        }
    }
    
    // Process Passport Photo upload
    if (isset($_FILES['passport_photo']) && $_FILES['passport_photo']['error'] === UPLOAD_ERR_OK) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];
        $maxSize = 2 * 1024 * 1024; // 2MB
        
        $fileType = $_FILES['passport_photo']['type'];
        $fileSize = $_FILES['passport_photo']['size'];
        $fileTmpName = $_FILES['passport_photo']['tmp_name'];
        $fileName = $_FILES['passport_photo']['name'];
        
        // Debug info
        error_log("Passport photo upload info - Type: $fileType, Size: $fileSize, Name: $fileName");
        
        // Validate file type
        if (!in_array($fileType, $allowedTypes)) {
            $errors[] = "Invalid file type for passport photo. Only JPG, JPEG, and PNG files are allowed.";
        }
        
        // Validate file size
        if ($fileSize > $maxSize) {
            $errors[] = "Passport photo is too large. Maximum size is 2MB.";
        }
        
        if (empty($errors)) {
            try {
                // Generate a unique filename
                $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $newFileName = 'passport_' . uniqid() . '_' . time() . '.' . $fileExtension;
                
                // Use system temp directory as fallback if needed
                if (!is_writable($passportPhotosDir)) {
                    $uploadDir = sys_get_temp_dir() . '/dvla_uploads/passport_photos/';
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    $uploadPath = $uploadDir . $newFileName;
                    error_log("Using system temp directory for passport photo: $uploadPath");
                } else {
                    $uploadPath = $passportPhotosDir . $newFileName;
                }
                
                // Debug info
                error_log("Attempting to move uploaded passport photo to: $uploadPath");
                
                // Upload the file with direct copy instead of move_uploaded_file
                if (copy($fileTmpName, $uploadPath)) {
                    // Save the relative path to database
                    $ownerData['passport_photo_path'] = 'uploads/passport_photos/' . $newFileName;
                    error_log("Passport photo uploaded successfully to: $uploadPath");
                } else {
                    // Fallback to move_uploaded_file
                    if (move_uploaded_file($fileTmpName, $uploadPath)) {
                        $ownerData['passport_photo_path'] = 'uploads/passport_photos/' . $newFileName;
                        error_log("Passport photo moved successfully to: $uploadPath");
                    } else {
                        $errors[] = "Failed to upload the passport photo. Technical details have been logged.";
                        error_log("Failed to upload passport photo. Error: " . error_get_last()['message']);
                    }
                }
            } catch (Exception $e) {
                $errors[] = "Exception during passport photo upload: " . $e->getMessage();
                error_log("Exception in passport photo upload: " . $e->getMessage());
            }
        }
    } else {
        // Passport photo is not required, so only show error if an attempt was made but failed
        if (isset($_FILES['passport_photo']) && $_FILES['passport_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
            switch ($_FILES['passport_photo']['error']) {
                case UPLOAD_ERR_INI_SIZE:
                    $errors[] = "The uploaded passport photo exceeds the upload_max_filesize directive in php.ini.";
                    break;
                case UPLOAD_ERR_FORM_SIZE:
                    $errors[] = "The uploaded passport photo exceeds the MAX_FILE_SIZE directive in the HTML form.";
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $errors[] = "The passport photo was only partially uploaded.";
                    break;
                case UPLOAD_ERR_NO_TMP_DIR:
                    $errors[] = "Missing a temporary folder for file uploads.";
                    break;
                case UPLOAD_ERR_CANT_WRITE:
                    $errors[] = "Failed to write file to disk. Check server permissions.";
                    break;
                case UPLOAD_ERR_EXTENSION:
                    $errors[] = "A PHP extension stopped the file upload.";
                    break;
                default:
                    $errors[] = "Unknown upload error for passport photo (Code: " . $_FILES['passport_photo']['error'] . ").";
            }
        }
    }
    
    // If no errors, proceed to save
    if (empty($errors)) {
        try {
            // First, check if we need to modify the database table to add the passport photo column
            try {
                $columnExists = false;
                $checkColumnStmt = $pdo->prepare("SHOW COLUMNS FROM vehicle_owners LIKE 'passport_photo_path'");
                $checkColumnStmt->execute();
                $columnExists = $checkColumnStmt->rowCount() > 0;
                
                if (!$columnExists) {
                    // Add the column to the table
                    $alterTableStmt = $pdo->prepare("ALTER TABLE vehicle_owners ADD COLUMN passport_photo_path VARCHAR(255) AFTER ghana_card_image_path");
                    $alterTableStmt->execute();
                }
            } catch (PDOException $e) {
                // If this fails, it's not critical, just log it
                error_log("Failed to check/add passport_photo_path column: " . $e->getMessage());
            }
            
            // Use prepared statements with named parameters for better security
            $sql = "INSERT INTO vehicle_owners (
                        name, 
                        ghana_card_number, 
                        id_document_type,
                        date_of_birth, 
                        address, 
                        phone, 
                        email";
            
            // Dynamic handling of column names based on what's available in the DB
            if ($hasNewImagePathColumn) {
                $sql .= ", id_document_image_path";
            } else {
                $sql .= ", ghana_card_image_path";
            }
            
            $sql .= ", passport_photo_path
                    ) VALUES (
                        :name, 
                        :ghana_card_number, 
                        :id_document_type,
                        :date_of_birth, 
                        :address, 
                        :phone, 
                        :email";
                        
            if ($hasNewImagePathColumn) {
                $sql .= ", :id_document_image_path";
            } else {
                $sql .= ", :ghana_card_image_path";
            }
            
            $sql .= ", :passport_photo_path
                    )";
            
            $stmt = $pdo->prepare($sql);
            
            $params = [
                'name' => $ownerData['name'],
                'ghana_card_number' => $ownerData['ghana_card_number'],
                'id_document_type' => $ownerData['id_document_type'],
                'date_of_birth' => $ownerData['date_of_birth'],
                'address' => $ownerData['address'],
                'phone' => $ownerData['phone'],
                'email' => $ownerData['email'],
                'passport_photo_path' => $ownerData['passport_photo_path']
            ];
            
            // Add the correct image path parameter based on the DB structure
            if ($hasNewImagePathColumn) {
                $params['id_document_image_path'] = $ownerData['ghana_card_image_path'];
            } else {
                $params['ghana_card_image_path'] = $ownerData['ghana_card_image_path'];
            }
            
            $stmt->execute($params);
            
            $ownerId = $pdo->lastInsertId();
            $success = true;
            
            // Reset form after successful submission
            $ownerData = [
                'name' => '',
                'ghana_card_number' => '',
                'id_document_type' => 'ghana_card',
                'date_of_birth' => '',
                'phone' => '',
                'email' => '',
                'address' => '',
                'ghana_card_image_path' => '',
                'passport_photo_path' => ''
            ];
            
        } catch (PDOException $e) {
            $errors[] = "Error adding vehicle owner: " . $e->getMessage();
            error_log("Database error: " . $e->getMessage());
        }
    }
}

// Get recent owners
try {
    $stmt = $pdo->query("
        SELECT * FROM vehicle_owners 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $recentOwners = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    error_log("Error fetching recent owners: " . $e->getMessage());
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Vehicle Owner | Admin | Vehicle Registration System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        :root {
            --primary-color: #0a3d62;
            --secondary-color: #60a3bc;
            --accent-color: #e58e26;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
        }
        
        .form-card {
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border: none;
        }
        
        .form-card .card-header {
            background-color: var(--primary-color);
            color: white;
            border-radius: 10px 10px 0 0;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-primary:hover {
            background-color: #072f4a;
            border-color: #072f4a;
        }
        
        .form-label {
            font-weight: 500;
        }
        
        .required-field::after {
            content: "*";
            color: red;
            margin-left: 4px;
        }
        
        .recent-owners-table {
            font-size: 0.9rem;
        }
        
        .file-upload-wrapper {
            position: relative;
            margin-bottom: 15px;
        }
        
        .file-upload-input {
            position: relative;
            z-index: 2;
            width: 100%;
            height: calc(1.5em + 1rem + 2px);
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
            padding: 0.5rem 1rem;
            border: 1px solid #ced4da;
            border-radius: 0.25rem;
            background-color: #fff;
            height: calc(1.5em + 1rem + 2px);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
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
        
        /* Passport photo specific styles */
        .passport-preview-container {
            position: relative;
            width: 100%;
            height: 180px;
            border: 2px dashed #ccc;
            display: flex;
            justify-content: center;
            align-items: center;
            overflow: hidden;
            margin-top: 10px;
            border-radius: 5px;
            background-color: #f8f9fa;
        }
        
        .passport-preview {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            display: none;
        }
        
        .passport-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            background-color: rgba(0,0,0,0.04);
        }
        
        .passport-overlay i {
            font-size: 2rem;
            color: #6c757d;
            margin-bottom: 10px;
        }
        
        .passport-overlay p {
            margin: 0;
            color: #6c757d;
        }
        
        .photo-guidelines {
            margin-top: 15px;
            padding: 10px;
            background-color: #f8f9fa;
            border-radius: 5px;
        }
        
        .photo-guidelines ul {
            padding-left: 20px;
            margin-bottom: 0;
        }
        
        .owner-photo-card {
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 10px;
        }
        
        /* ID document type selector styles */
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
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: #fff;
        }
        
        .document-type-selector .btn-outline-primary:hover {
            background-color: rgba(10, 61, 98, 0.1);
            border-color: #ced4da;
            color: #495057;
        }
        
        .btn-check:checked + .btn-outline-primary:hover {
            background-color: var(--primary-color);
            color: #fff;
        }
        
        .document-type-selector .form-icon {
            margin-right: 8px;
        }

        /* Age requirement badge */
        .age-requirement {
            font-size: 0.8rem;
            background-color: #f8d7da;
            color: #721c24;
            padding: 0.2rem 0.5rem;
            border-radius: 0.25rem;
            margin-left: 0.5rem;
            display: inline-block;
            vertical-align: middle;
        }
    </style>
</head>
<body>
<?php
include(__DIR__ . '/../../../includes/header.php');
?>
    
    <div class="container main-content">
        <!-- Breadcrumb -->
        <nav aria-label="breadcrumb" class="mb-4">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item active">Add Vehicle Owner</li>
            </ol>
        </nav>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i> Vehicle owner has been added successfully!
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <strong><i class="fas fa-exclamation-triangle me-2"></i> Please correct the following errors:</strong>
                <ul class="mb-0 mt-2">
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <div class="row">
            <div class="col-lg-7">
                <div class="card form-card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-user-plus me-2"></i> Vehicle Owner Information</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" enctype="multipart/form-data" id="ownerForm">
                            <div class="row mb-3">
                                <div class="col-md-12">
                                    <label for="name" class="form-label required-field">Full Name</label>
                                    <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($ownerData['name']) ?>" required>
                                </div>
                            </div>
                            
                            <!-- ID Document Type Selector -->
                            <div class="row mb-3">
                                <div class="col-md-12">
                                    <label class="form-label required-field">ID Document Type</label>
                                    <div class="document-type-selector">
                                        <input type="radio" class="btn-check" name="id_document_type" id="id_type_ghana_card" value="ghana_card" <?= $ownerData['id_document_type'] === 'ghana_card' ? 'checked' : '' ?> autocomplete="off">
                                        <label class="btn btn-outline-primary" for="id_type_ghana_card">
                                            <i class="fas fa-id-card form-icon"></i>Ghana Card
                                        </label>
                                        
                                        <input type="radio" class="btn-check" name="id_document_type" id="id_type_drivers_license" value="drivers_license" <?= $ownerData['id_document_type'] === 'drivers_license' ? 'checked' : '' ?> autocomplete="off">
                                        <label class="btn btn-outline-primary" for="id_type_drivers_license">
                                            <i class="fas fa-car form-icon"></i>Driver's License
                                        </label>
                                        
                                        <input type="radio" class="btn-check" name="id_document_type" id="id_type_passport" value="passport" <?= $ownerData['id_document_type'] === 'passport' ? 'checked' : '' ?> autocomplete="off">
                                        <label class="btn btn-outline-primary" for="id_type_passport">
                                            <i class="fas fa-passport form-icon"></i>Passport
                                        </label>
                                        
                                        <input type="radio" class="btn-check" name="id_document_type" id="id_type_voter_id" value="voter_id" <?= $ownerData['id_document_type'] === 'voter_id' ? 'checked' : '' ?> autocomplete="off">
                                        <label class="btn btn-outline-primary" for="id_type_voter_id">
                                            <i class="fas fa-vote-yea form-icon"></i>Voter ID
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="ghana_card_number" class="form-label required-field" id="id_document_label">Ghana Card Number</label>
                                    <input type="text" class="form-control" id="ghana_card_number" name="ghana_card_number" value="<?= htmlspecialchars($ownerData['ghana_card_number']) ?>" required>
                                    <div class="form-text" id="id_format_hint">Format: GHA-XXXXXXXXX-X</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="date_of_birth" class="form-label required-field">Date of Birth
                                        <span class="age-requirement"><i class="fas fa-exclamation-triangle"></i> Must be 18+</span>
                                    </label>
                                    <input type="date" class="form-control" id="date_of_birth" name="date_of_birth" value="<?= htmlspecialchars($ownerData['date_of_birth']) ?>" required>
                                    <div class="form-text">Vehicle owner must be at least 18 years old</div>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="phone" class="form-label required-field">Phone Number</label>
                                    <input type="tel" class="form-control" id="phone" name="phone" value="<?= htmlspecialchars($ownerData['phone']) ?>" placeholder="+233 XX XXX XXXX" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="email" class="form-label required-field">Email Address</label>
                                    <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($ownerData['email']) ?>">
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="address" class="form-label">Address</label>
                                <textarea class="form-control" id="address" name="address" rows="3" required><?= htmlspecialchars($ownerData['address']) ?></textarea>
                            </div>
                            
                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <label for="id_document_image" class="form-label required-field" id="upload_document_label">Upload ID Document</label>
                                    <div class="file-upload-wrapper">
                                        <input type="file" class="file-upload-input" id="id_document_image" name="id_document_image" accept="image/jpeg,image/png,image/jpg,application/pdf" required>
                                        <label class="file-upload-label" for="id_document_image">
                                            <span class="custom-file-label-text">Choose file...</span>
                                            <i class="fas fa-upload ms-2"></i>
                                        </label>
                                    </div>
                                    <img id="imagePreview" class="image-preview" src="#" alt="Document Preview">
                                    <div id="pdfIcon" class="pdf-icon">
                                        <i class="far fa-file-pdf"></i>
                                        <p class="mt-2 mb-0">PDF Document</p>
                                    </div>
                                    <div class="form-text" id="upload_format_hint">Upload a clear image or PDF of your ID document (front side). Max size: 5MB. Formats: JPG, PNG, PDF.</div>
                                </div>
                                
                                <!-- Passport Photo Upload Section -->
                                <div class="col-md-6">
                                    <label for="passport_photo" class="form-label">Passport Photo</label>
                                    <div class="file-upload-wrapper">
                                        <input type="file" class="file-upload-input" id="passport_photo" name="passport_photo" accept="image/jpeg,image/png,image/jpg">
                                        <label class="file-upload-label" for="passport_photo">
                                            <span class="passport-file-label-text">Choose photo...</span>
                                            <i class="fas fa-camera ms-2"></i>
                                        </label>
                                    </div>
                                    
                                    <div class="passport-preview-container">
                                        <img id="passportPreview" class="passport-preview" src="#" alt="Passport Photo Preview">
                                        <div id="passportOverlay" class="passport-overlay">
                                            <i class="fas fa-user-circle"></i>
                                            <p>Upload passport photo</p>
                                        </div>
                                    </div>
                                    
                                    <div class="form-text">Upload a recent passport-sized photograph. Max size: 2MB. Formats: JPG, PNG.</div>
                                </div>
                            </div>
                            
                            <div class="photo-guidelines mb-4">
                                <h6><i class="fas fa-info-circle me-2"></i>Passport Photo Requirements</h6>
                                <ul>
                                    <li>Recent photo (taken within the last 6 months)</li>
                                    <li>Plain white or light background</li>
                                    <li>Face the camera directly with neutral expression</li>
                                    <li>No hats or head coverings (except for religious purposes)</li>
                                    <li>No glasses that obscure the eyes</li>
                                </ul>
                            </div>
                            
                            <div class="mt-4 d-flex justify-content-between">
                                <a href="dashboard.php" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left me-2"></i> Back to Dashboard
                                </a>
                                <button type="submit" class="btn btn-primary" id="submitButton">
                                    <i class="fas fa-save me-2"></i> Add Vehicle Owner
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-5">
                <div class="card mb-4">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="fas fa-users me-2"></i> Recently Added Owners</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($recentOwners)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover recent-owners-table">
                                    <thead>
                                        <tr>
                                            <th>Photo</th>
                                            <th>Name</th>
                                            <th>ID Type</th>
                                            <th>ID Number</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentOwners as $owner): ?>
                                            <tr>
                                                <td>
                                                    <?php if (!empty($owner['passport_photo_path'])): ?>
                                                        <img src="/<?= htmlspecialchars($owner['passport_photo_path']) ?>" 
                                                             alt="Photo" width="30" height="30" class="rounded-circle">
                                                    <?php else: ?>
                                                        <span class="avatar-placeholder rounded-circle bg-secondary text-white d-inline-flex align-items-center justify-content-center" 
                                                              style="width: 30px; height: 30px; font-size: 12px;">
                                                            <?= strtoupper(substr($owner['name'], 0, 1)) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars($owner['name']) ?></td>
                                                <td>
                                                    <?php 
                                                        $idType = isset($owner['id_document_type']) ? $owner['id_document_type'] : 'ghana_card';
                                                        echo htmlspecialchars(getDocumentTypeDisplay($idType));
                                                    ?>
                                                </td>
                                                <td><?= htmlspecialchars($owner['ghana_card_number']) ?></td>
                                                <td>
                                                    <?php
                                                        // Check which image path column to use
                                                        $docImagePath = isset($owner['id_document_image_path']) 
                                                            ? $owner['id_document_image_path'] 
                                                            : (isset($owner['ghana_card_image_path']) ? $owner['ghana_card_image_path'] : '');
                                                    
                                                        if(!empty($docImagePath)): 
                                                            $filePath = $docImagePath;
                                                            $fileExtension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
                                                            $isPdf = $fileExtension === 'pdf';
                                                    ?>
                                                        <a href="/<?= $docImagePath ?>" target="_blank" 
                                                           class="btn btn-sm <?= $isPdf ? 'btn-outline-danger' : 'btn-outline-info' ?>">
                                                            <i class="fas <?= $isPdf ? 'fa-file-pdf' : 'fa-id-card' ?>"></i>
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">No document</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="text-center mb-0">No vehicle owners added yet.</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="card mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i> Guidelines</h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i> <strong>Age Requirement:</strong> Vehicle owners must be at least 18 years old.
                        </div>
                        
                        <h6>Acceptable ID Documents</h6>
                        <ul>
                            <li>Ghana Card (National ID)</li>
                            <li>Driver's License</li>
                            <li>Passport</li>
                            <li>Voter ID</li>
                        </ul>
                        
                        <h6>ID Document Requirements</h6>
                        <ul>
                            <li>Upload either a clear image or PDF scan</li>
                            <li>All text must be readable</li>
                            <li>The entire document must be visible</li>
                            <li>Maximum file size: 5MB</li>
                            <li>Accepted formats: JPG, PNG, PDF</li>
                        </ul>
                        
                        <h6>Important Notes</h6>
                        <ul>
                            <li>Ensure ID numbers are entered correctly</li>
                            <li>Phone numbers should include country code (e.g., +233 for Ghana)</li>
                            <li>Address should be complete and include city and region</li>
                        </ul>
                        
                        <h6>Privacy Policy</h6>
                        <p class="small">All personal information collected is used solely for vehicle registration purposes and is protected according to DVLA's privacy policy.</p>
                    </div>
                </div>
                
                <!-- Passport Photo Example Section -->
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-image me-2"></i> Passport Photo Example</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="owner-photo-card text-center mb-3">
                                    <p class="fw-bold text-success mb-2">Acceptable Photo</p>
                                    <img src="https://www.passport-photo-online.com/blog/wp-content/uploads/2020/06/passport_photo_usa_blue_background.jpg" 
                                         alt="Acceptable Passport Photo" class="img-fluid mb-2 border" style="max-height: 140px;">
                                    <ul class="text-start small">
                                        <li>Neutral expression</li>
                                        <li>Plain background</li>
                                        <li>Good lighting</li>
                                        <li>Direct face view</li>
                                    </ul>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="owner-photo-card text-center">
                                    <p class="fw-bold text-danger mb-2">Unacceptable Photo</p>
                                    <img src="https://www.passport-photo-online.com/blog/wp-content/uploads/2020/06/bad_passport_photo_usa.jpg" 
                                         alt="Unacceptable Passport Photo" class="img-fluid mb-2 border" style="max-height: 140px;">
                                    <ul class="text-start small">
                                        <li>Wearing glasses</li>
                                        <li>Improper background</li>
                                        <li>Shadows on face</li>
                                        <li>Not facing camera</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
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
    
    <!-- Custom JS for file upload preview and document type handling -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // ID Document Type handling
            const idDocumentRadios = document.querySelectorAll('input[name="id_document_type"]');
            const idDocumentLabel = document.getElementById('id_document_label');
            const idFormatHint = document.getElementById('id_format_hint');
            const uploadDocumentLabel = document.getElementById('upload_document_label');
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
                        uploadDocumentLabel.textContent = 'Upload Ghana Card';
                        uploadFormatHint.textContent = 'Upload a clear image or PDF of your Ghana Card (front side). Max size: 5MB.';
                        idNumberInput.addEventListener('input', formatGhanaCard);
                        break;
                    case 'drivers_license':
                        idDocumentLabel.textContent = 'Driver\'s License Number';
                        idFormatHint.textContent = 'Enter your complete driver\'s license number';
                        uploadDocumentLabel.textContent = 'Upload Driver\'s License';
                        uploadFormatHint.textContent = 'Upload a clear image or PDF of your Driver\'s License. Max size: 5MB.';
                        idNumberInput.addEventListener('input', formatDriversLicense);
                        break;
                    case 'passport':
                        idDocumentLabel.textContent = 'Passport Number';
                        idFormatHint.textContent = 'Enter your passport number';
                        uploadDocumentLabel.textContent = 'Upload Passport';
                        uploadFormatHint.textContent = 'Upload a clear image or PDF of your Passport bio page. Max size: 5MB.';
                        idNumberInput.addEventListener('input', formatPassport);
                        break;
                    case 'voter_id':
                        idDocumentLabel.textContent = 'Voter ID Number';
                        idFormatHint.textContent = 'Enter your voter identification number';
                        uploadDocumentLabel.textContent = 'Upload Voter ID Card';
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
            
            // ID Document file upload preview
            const fileInput = document.getElementById('id_document_image');
            const fileLabel = document.querySelector('.custom-file-label-text');
            const imagePreview = document.getElementById('imagePreview');
            const pdfIcon = document.getElementById('pdfIcon');
            
            fileInput.addEventListener('change', function() {
                if (this.files && this.files[0]) {
                    // Update file name in label
                    const fileName = this.files[0].name;
                    fileLabel.textContent = fileName.length > 25 
                        ? fileName.substring(0, 22) + '...' 
                        : fileName;
                    
                    // Determine file type
                    const fileType = this.files[0].type;
                    
                    // Hide both previews initially
                    imagePreview.style.display = 'none';
                    pdfIcon.style.display = 'none';
                    
                    // Show appropriate preview based on file type
                    if (fileType === 'application/pdf') {
                        // Show PDF icon for PDF files
                        pdfIcon.style.display = 'block';
                    } else if (fileType.startsWith('image/')) {
                        // Show image preview for images
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            imagePreview.src = e.target.result;
                            imagePreview.style.display = 'block';
                        };
                        reader.readAsDataURL(this.files[0]);
                    }
                } else {
                    fileLabel.textContent = 'Choose file...';
                    imagePreview.style.display = 'none';
                    pdfIcon.style.display = 'none';
                }
            });
            
            // Passport Photo file upload preview
            const passportInput = document.getElementById('passport_photo');
            const passportLabel = document.querySelector('.passport-file-label-text');
            const passportPreview = document.getElementById('passportPreview');
            const passportOverlay = document.getElementById('passportOverlay');
            
            passportInput.addEventListener('change', function() {
                if (this.files && this.files[0]) {
                    // Update file name in label
                    const fileName = this.files[0].name;
                    passportLabel.textContent = fileName.length > 25 
                        ? fileName.substring(0, 22) + '...' 
                        : fileName;
                    
                    // Determine file type
                    const fileType = this.files[0].type;
                    
                    // Only process image files
                    if (fileType.startsWith('image/')) {
                        // Show image preview
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            passportPreview.src = e.target.result;
                            passportPreview.style.display = 'block';
                            passportOverlay.style.display = 'none';
                        };
                        reader.readAsDataURL(this.files[0]);
                    }
                } else {
                    passportLabel.textContent = 'Choose photo...';
                    passportPreview.style.display = 'none';
                    passportOverlay.style.display = 'flex';
                }
            });
            
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
                // Format for driver's license - uppercase with allowed hyphens
                this.value = this.value.toUpperCase().replace(/[^A-Z0-9-]/g, '');
            }
            
            function formatVoterId() {
                // Generic format for voter ID - uppercase with allowed numbers
                this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
            }
            
            // Format phone number with Ghana country code
            const phoneInput = document.getElementById('phone');
            phoneInput.addEventListener('focus', function() {
                if (!this.value) {
                    this.value = '+233';
                }
            });

            // Age validation for 18+ years
            const dobInput = document.getElementById('date_of_birth');
            const submitButton = document.getElementById('submitButton');
            dobInput.addEventListener('change', validateAge);

            function validateAge() {
                const dob = new Date(this.value);
                const today = new Date();
                
                // Calculate age
                let age = today.getFullYear() - dob.getFullYear();
                const monthDiff = today.getMonth() - dob.getMonth();
                
                // Adjust age if birthday hasn't occurred yet this year
                if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dob.getDate())) {
                    age--;
                }
                
                // Check if age is less than 18
                if (age < 18) {
                    // Add error styling
                    this.classList.add('is-invalid');
                    
                    // Create error message if it doesn't exist
                    let errorDiv = this.nextElementSibling;
                    if (!errorDiv || !errorDiv.classList.contains('invalid-feedback')) {
                        errorDiv = document.createElement('div');
                        errorDiv.className = 'invalid-feedback';
                        errorDiv.textContent = 'Vehicle owner must be at least 18 years old';
                        this.parentNode.appendChild(errorDiv);
                    }
                    
                    // Disable submit button
                    submitButton.disabled = true;
                } else {
                    // Remove error styling
                    this.classList.remove('is-invalid');
                    
                    // Remove error message if it exists
                    const errorDiv = this.nextElementSibling;
                    if (errorDiv && errorDiv.classList.contains('invalid-feedback')) {
                        errorDiv.remove();
                    }
                    
                    // Enable submit button
                    submitButton.disabled = false;
                }
            }

            // Add max date attribute to date input to prevent future dates and set max to 18 years ago
            const today = new Date();
            
            // Calculate date for 18 years ago
            const maxDate = new Date();
            maxDate.setFullYear(today.getFullYear() - 18);
            
            // Format date as YYYY-MM-DD for input max attribute
            const formattedDate = maxDate.toISOString().split('T')[0];
            
            // Set max attribute to 18 years ago
            dobInput.setAttribute('max', formattedDate);

            // Validate the date field on load in case it's prefilled
            if (dobInput.value) {
                validateAge.call(dobInput);
            }
        });
    </script>
</body>
</html>