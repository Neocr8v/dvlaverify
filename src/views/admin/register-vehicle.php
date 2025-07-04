<?php
// filepath: /Applications/XAMPP/xamppfiles/htdocs/dvlaregister/src/views/admin/register-vehicle.php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Import required classes if using endroid/qr-code v4+
// These should be at the top level, outside any functions or conditionals
if (file_exists(__DIR__ . '/../../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../../vendor/autoload.php';
}

session_start();
require_once __DIR__ . '/../../../config/database.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../views/auth/login.php');
    exit;
}

// Helper function for password generation
function generateRandomPassword($length = 10) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $password;
}

// Function to generate a vehicle registration number
function generateRegistrationNumber($pdo, $forceUnique = false) {
    $prefix = "GR";
    $year = date('y'); // Last two digits of current year
    
    try {
        // Get the highest registration number for this year
        $stmt = $pdo->prepare("
            SELECT registration_number FROM vehicles 
            WHERE registration_number LIKE :pattern 
            ORDER BY registration_number DESC LIMIT 1
        ");
        $pattern = $prefix . "-%" . $year;
        $stmt->execute(['pattern' => $pattern]);
        
        $lastReg = $stmt->fetch(PDO::FETCH_COLUMN);
        
        if ($lastReg) {
            // Extract the sequence number
            $parts = explode('-', $lastReg);
            if (count($parts) >= 2) {
                $sequence = (int)$parts[1];
                $sequence++;
            } else {
                $sequence = 1;
            }
        } else {
            // No existing registrations for this year
            $sequence = 1;
        }
        
        // If forcing unique (for regenerate button), add a random factor
        if ($forceUnique) {
            // Use the current time in milliseconds to ensure uniqueness
            $timeMs = round(microtime(true) * 1000);
            // Take last 4 digits or avoid conflict with existing sequence
            $randomSequence = $timeMs % 10000;
            if ($randomSequence < $sequence) {
                $randomSequence = $sequence + mt_rand(1, 1000);
            }
            $sequence = $randomSequence;
        }
        
        // Format sequence to 4 digits with leading zeros
        $sequenceFormatted = str_pad($sequence, 4, '0', STR_PAD_LEFT);
        
        // Format as GR-XXXX-YY
        $registrationNumber = $prefix . "-" . $sequenceFormatted . "-" . $year;
        
        // Check if this registration number already exists (it might if we used forceUnique)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM vehicles WHERE registration_number = :reg_num");
        $stmt->execute(['reg_num' => $registrationNumber]);
        
        if ($stmt->fetchColumn() > 0) {
            // If it exists, add a random suffix to make it unique
            $registrationNumber = $prefix . "-" . str_pad(mt_rand($sequence, $sequence + 5000), 4, '0', STR_PAD_LEFT) . "-" . $year;
            
            // Double check this one doesn't exist either
            $stmt->execute(['reg_num' => $registrationNumber]);
            if ($stmt->fetchColumn() > 0) {
                // Extremely unlikely, but if it also exists, add another random factor
                $registrationNumber = $prefix . "-" . str_pad(mt_rand(1000, 9999), 4, '0', STR_PAD_LEFT) . "-" . $year . mt_rand(10, 99);
            }
        }
        
        return $registrationNumber;
        
    } catch (Exception $e) {
        // In case of error, generate a fallback random number
        return $prefix . "-" . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT) . "-" . $year;
    }
}

// Handle AJAX request for registration number generation
if (isset($_GET['action']) && $_GET['action'] == 'generate_reg_number') {
    header('Content-Type: application/json');
    try {
        // Force unique when regenerating (true), use sequential for initial generation (false)
        $forceUnique = isset($_GET['regenerate']) && $_GET['regenerate'] === 'true';
        $registrationNumber = generateRegistrationNumber($pdo, $forceUnique);
        
        echo json_encode(['success' => true, 'registration_number' => $registrationNumber]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Error generating registration number: ' . $e->getMessage()]);
    }
    exit;
}

$errors = [];
$success = false;
$vehicleData = [
    'registration_number' => '',
    'make' => '',
    'model' => '',
    'color' => '',
    'year_of_manufacture' => '',
    'engine_number' => '',
    'chassis_number' => '',
    'owner_id' => '',
    'registration_date' => date('Y-m-d'),
    'expiry_date' => date('Y-m-d', strtotime('+1 year')),
    'vehicle_type' => 'Car', // Default value
    'vehicle_use' => 'Private', // New field: Default is Private
    'engine_type' => 'petrol', // Default value
    'engine_capacity' => '', // Added engine capacity field
    'seating_capacity' => '', // Added seating capacity field
];

// Get all vehicle owners for the dropdown
try {
    $stmt = $pdo->query("SELECT id, name, ghana_card_number FROM vehicle_owners ORDER BY name");
    $vehicleOwners = $stmt->fetchAll();
} catch (PDOException $e) {
    $errors[] = "Error fetching vehicle owners: " . $e->getMessage();
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate and sanitize input
    $vehicleData = [
        'registration_number' => trim($_POST['registration_number'] ?? ''),
        'make' => trim($_POST['make'] ?? ''),
        'model' => trim($_POST['model'] ?? ''),
        'color' => trim($_POST['color'] ?? ''),
        'year_of_manufacture' => (int)($_POST['year_of_manufacture'] ?? ''),
        'engine_number' => trim($_POST['engine_number'] ?? ''),
        'chassis_number' => trim($_POST['chassis_number'] ?? ''),
        'owner_id' => (int)($_POST['owner_id'] ?? ''),
        'registration_date' => trim($_POST['registration_date'] ?? date('Y-m-d')),
        'expiry_date' => trim($_POST['expiry_date'] ?? date('Y-m-d', strtotime('+1 year'))),
        'vehicle_type' => trim($_POST['vehicle_type'] ?? 'Car'),
        'vehicle_use' => trim($_POST['vehicle_use'] ?? 'Private'), // New field: Vehicle Use (Private/Commercial)
        'engine_type' => trim($_POST['engine_type'] ?? 'petrol'),
        'engine_capacity' => trim($_POST['engine_capacity'] ?? ''),
        'seating_capacity' => trim($_POST['seating_capacity'] ?? ''),
    ];
    
    // Validation
    if (empty($vehicleData['registration_number'])) {
        $errors[] = "Registration number is required";
    } else {
        // Check if registration number already exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM vehicles WHERE registration_number = :reg_num");
        $stmt->execute(['reg_num' => $vehicleData['registration_number']]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = "Registration number already exists. Please regenerate a new one.";
        }
    }
    
    if (empty($vehicleData['make'])) {
        $errors[] = "Make is required";
    }
    
    if (empty($vehicleData['model'])) {
        $errors[] = "Model is required";
    }
    
    if (empty($vehicleData['color'])) {
        $errors[] = "Color is required";
    }
    
    if (empty($vehicleData['year_of_manufacture']) || $vehicleData['year_of_manufacture'] < 1900 || $vehicleData['year_of_manufacture'] > date('Y') + 1) {
        $errors[] = "Valid year of manufacture is required";
    }
    
    if (empty($vehicleData['engine_number'])) {
        $errors[] = "Engine number is required";
    }
    
    if (empty($vehicleData['chassis_number'])) {
        $errors[] = "Chassis number is required";
    }
    
    if (empty($vehicleData['owner_id'])) {
        $errors[] = "Vehicle owner is required";
    }
    
    if (empty($vehicleData['registration_date'])) {
        $errors[] = "Registration date is required";
    }
    
    if (empty($vehicleData['expiry_date'])) {
        $errors[] = "Expiry date is required";
    }
    
    if (empty($vehicleData['vehicle_type'])) {
        $errors[] = "Vehicle type is required";
    }

    if (empty($vehicleData['vehicle_use'])) {
        $errors[] = "Vehicle use is required";
    }
    
    if (empty($vehicleData['engine_type'])) {
        $errors[] = "Engine type is required";
    }
    
    // Engine capacity validation
    if (empty($vehicleData['engine_capacity'])) {
        $errors[] = "Engine capacity is required";
    } elseif (!is_numeric($vehicleData['engine_capacity'])) {
        $errors[] = "Engine capacity must be a number";
    }
    
    // Seating capacity validation
    if (empty($vehicleData['seating_capacity'])) {
        $errors[] = "Seating capacity is required";
    } elseif (!is_numeric($vehicleData['seating_capacity']) || $vehicleData['seating_capacity'] < 1) {
        $errors[] = "Seating capacity must be a positive number";
    }
    
    // If no errors, proceed to save
    if (empty($errors)) {
        try {
            // Generate QR code data
            $qrCodeData = json_encode([
                'reg_number' => $vehicleData['registration_number'],
                'make' => $vehicleData['make'],
                'model' => $vehicleData['model'],
                'color' => $vehicleData['color'],
                'year_of_manufacture' => $vehicleData['year_of_manufacture'],
                'registration_date' => $vehicleData['registration_date'],
                'expiry_date' => $vehicleData['expiry_date'],
                'vehicle_type' => $vehicleData['vehicle_type'],
                'vehicle_use' => $vehicleData['vehicle_use'], // Include vehicle use in QR code
                'engine_type' => $vehicleData['engine_type'],
                'engine_capacity' => $vehicleData['engine_capacity'],
                'seating_capacity' => $vehicleData['seating_capacity']
            ]);
            
            // Create QR code directory if it doesn't exist
            $qrCodeDir = __DIR__ . '/../../../uploads/qr_codes/';
            if (!is_dir($qrCodeDir)) {
                mkdir($qrCodeDir, 0777, true);
            }
            
            $qrCodeFileName = str_replace(' ', '_', $vehicleData['registration_number']) . '.png';
            $qrCodeFilePath = $qrCodeDir . $qrCodeFileName;
            $qrCodePath = 'uploads/qr_codes/' . $qrCodeFileName;
            
            // Generate QR code using a library (you need to install this via Composer)
            try {
                // Check if the library is available
                if (file_exists(__DIR__ . '/../../../vendor/autoload.php') && 
                    class_exists('Endroid\QrCode\Builder\Builder')) {
                    
                    // Generate the QR code using Endroid QR Code library v4+
                    $builder = new \Endroid\QrCode\Builder\Builder();
                    $result = $builder
                        ->data($qrCodeData)
                        ->encoding(new \Endroid\QrCode\Encoding\Encoding('UTF-8'))
                        ->errorCorrectionLevel(new \Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh())
                        ->size(300)
                        ->margin(10)
                        ->roundBlockSizeMode(new \Endroid\QrCode\RoundBlockSizeMode\RoundBlockSizeModeMargin())
                        ->build();
                    
                    // Save the QR code to file
                    $result->saveToFile($qrCodeFilePath);
                
                } else if (file_exists(__DIR__ . '/../../../vendor/autoload.php') && 
                           class_exists('Endroid\QrCode\QrCode')) {
                    // For older versions of the library
                    $qrCode = new \Endroid\QrCode\QrCode($qrCodeData);
                    $qrCode->setSize(300);
                    $qrCode->setMargin(10);
                    $qrCode->writeFile($qrCodeFilePath);
                } else {
                    // Fallback if library not available
                    throw new Exception("QR code library not available");
                }
            } catch (Exception $e) {
                // Fallback method if library fails
                // Create a simple placeholder image
                $qrImage = imagecreate(300, 300);
                $bgColor = imagecolorallocate($qrImage, 255, 255, 255);
                $textColor = imagecolorallocate($qrImage, 0, 0, 0);
                imagestring($qrImage, 5, 10, 10, "QR: " . $vehicleData['registration_number'], $textColor);
                imagepng($qrImage, $qrCodeFilePath);
                imagedestroy($qrImage);
            }
            
            // Begin a transaction
            $pdo->beginTransaction();
            
            // Check if vehicle_use column exists, create it if it doesn't
            try {
                $checkColumnStmt = $pdo->prepare("SHOW COLUMNS FROM vehicles LIKE 'vehicle_use'");
                $checkColumnStmt->execute();
                
                if ($checkColumnStmt->rowCount() === 0) {
                    // Column doesn't exist, add it
                    $addColumnStmt = $pdo->prepare("ALTER TABLE vehicles ADD COLUMN vehicle_use VARCHAR(50) DEFAULT 'Private' AFTER vehicle_type");
                    $addColumnStmt->execute();
                }
            } catch (PDOException $e) {
                // Ignore error if column check fails
            }
            
            // Insert vehicle data
            $stmt = $pdo->prepare("
                INSERT INTO vehicles (
                    registration_number, make, model, color, year_of_manufacture, 
                    engine_number, chassis_number, owner_id, registration_date, 
                    expiry_date, qr_code_path, user_id, vehicle_type, vehicle_use,
                    engine_type, engine_capacity, seating_capacity
                ) VALUES (
                    :registration_number, :make, :model, :color, :year_of_manufacture,
                    :engine_number, :chassis_number, :owner_id, :registration_date,
                    :expiry_date, :qr_code_path, :user_id, :vehicle_type, :vehicle_use,
                    :engine_type, :engine_capacity, :seating_capacity
                )
            ");
            
            $stmt->execute([
                'registration_number' => $vehicleData['registration_number'],
                'make' => $vehicleData['make'],
                'model' => $vehicleData['model'],
                'color' => $vehicleData['color'],
                'year_of_manufacture' => $vehicleData['year_of_manufacture'],
                'engine_number' => $vehicleData['engine_number'],
                'chassis_number' => $vehicleData['chassis_number'],
                'owner_id' => $vehicleData['owner_id'],
                'registration_date' => $vehicleData['registration_date'],
                'expiry_date' => $vehicleData['expiry_date'],
                'qr_code_path' => $qrCodePath,
                'user_id' => $_SESSION['user_id'],
                'vehicle_type' => $vehicleData['vehicle_type'],
                'vehicle_use' => $vehicleData['vehicle_use'], // Save vehicle use to database
                'engine_type' => $vehicleData['engine_type'],
                'engine_capacity' => $vehicleData['engine_capacity'],
                'seating_capacity' => $vehicleData['seating_capacity']
            ]);
            
            $vehicleId = $pdo->lastInsertId();
            
            // Get owner details
            $stmt = $pdo->prepare("SELECT name, email, phone FROM vehicle_owners WHERE id = :id");
            $stmt->execute(['id' => $vehicleData['owner_id']]);
            $owner = $stmt->fetch();
            
            // Check if owner already has a user account
            if ($owner) {
                // Check by email or name to see if this owner already has a user account
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM users 
                    WHERE (email = :email AND email IS NOT NULL AND email != '') 
                    OR full_name = :full_name
                ");
                $stmt->execute([
                    'email' => $owner['email'],
                    'full_name' => $owner['name']
                ]);
                
                $ownerHasAccount = $stmt->fetchColumn() > 0;
                
                // If owner doesn't have an account, create one
                if (!$ownerHasAccount) {
                    // Generate username and password
                    $username = strtolower(explode(' ', $owner['name'])[0]) . rand(100, 999);
                    $password = generateRandomPassword();
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    
                    // Insert user - without the owner_id field
                    $stmt = $pdo->prepare("
                        INSERT INTO users (username, password, email, full_name, role, created_at, updated_at)
                        VALUES (:username, :password, :email, :full_name, 'user', NOW(), NOW())
                    ");
                    
                    $stmt->execute([
                        'username' => $username,
                        'password' => $hashedPassword,
                        'email' => $owner['email'] ?? null,
                        'full_name' => $owner['name']
                    ]);
                    
                    $userId = $pdo->lastInsertId();
                    
                    // Try to update the vehicle_owners table with the user_id if that column exists
                    try {
                        // First check if the column exists
                        $stmt = $pdo->prepare("SHOW COLUMNS FROM vehicle_owners LIKE 'user_id'");
                        $stmt->execute();
                        
                        if ($stmt->rowCount() > 0) {
                            // Column exists, update the record
                            $stmt = $pdo->prepare("
                                UPDATE vehicle_owners 
                                SET user_id = :user_id 
                                WHERE id = :owner_id
                            ");
                            $stmt->execute([
                                'user_id' => $userId,
                                'owner_id' => $vehicleData['owner_id']
                            ]);
                        }
                    } catch (Exception $e) {
                        // Log the error but continue
                        error_log("Failed to update vehicle_owners with user_id: " . $e->getMessage());
                    }
                    
                    // Store login credentials in session to display to admin
                    $_SESSION['new_user_credentials'] = [
                        'username' => $username,
                        'password' => $password,
                        'owner_name' => $owner['name']
                    ];
                }
            }
            
            // Commit transaction
            $pdo->commit();
            
            $success = true;
            
            // Reset form after successful submission
            $vehicleData = [
                'registration_number' => '',
                'make' => '',
                'model' => '',
                'color' => '',
                'year_of_manufacture' => '',
                'engine_number' => '',
                'chassis_number' => '',
                'owner_id' => '',
                'registration_date' => date('Y-m-d'),
                'expiry_date' => date('Y-m-d', strtotime('+1 year')),
                'vehicle_type' => 'Car',
                'vehicle_use' => 'Private',
                'engine_type' => 'petrol',
                'engine_capacity' => '',
                'seating_capacity' => '',
            ];
            
        } catch (PDOException $e) {
            // Roll back transaction if anything failed
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = "Error registering vehicle: " . $e->getMessage();
        } catch (Exception $e) {
            // Roll back transaction if anything failed
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = "Error: " . $e->getMessage();
        }
    }
}

// Common makes and models for autocomplete
$commonMakes = ['Toyota', 'Honda', 'Nissan', 'Ford', 'Hyundai', 'Kia', 'Chevrolet', 'Mercedes-Benz', 'BMW', 'Volkswagen', 'Mazda', 'Subaru', 'Mitsubishi', 'Peugeot', 'Audi'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Vehicle | Admin | Vehicle Registration System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="/assets/css/style.css">
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
                <li class="breadcrumb-item active">Register New Vehicle</li>
            </ol>
        </nav>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i> Vehicle has been registered successfully!
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            
            <?php if (isset($_SESSION['new_user_credentials'])): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <h5><i class="fas fa-user-plus me-2"></i> User Account Created</h5>
                <p>A new user account has been created for <strong><?= htmlspecialchars($_SESSION['new_user_credentials']['owner_name']) ?></strong></p>
                <hr>
                <div class="row">
                    <div class="col-md-6">
                        <div class="input-group mb-3">
                            <span class="input-group-text">Username</span>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($_SESSION['new_user_credentials']['username']) ?>" readonly>
                            <button class="btn btn-outline-secondary copy-btn" type="button" data-copy="<?= htmlspecialchars($_SESSION['new_user_credentials']['username']) ?>">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="input-group mb-3">
                            <span class="input-group-text">Password</span>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($_SESSION['new_user_credentials']['password']) ?>" readonly>
                            <button class="btn btn-outline-secondary copy-btn" type="button" data-copy="<?= htmlspecialchars($_SESSION['new_user_credentials']['password']) ?>">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <p class="mb-0"><strong>Important:</strong> Please provide these credentials to the vehicle owner. They will need them to log in to the system.</p>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php 
                // Clear the credentials from session after displaying
                unset($_SESSION['new_user_credentials']);
            endif; 
            ?>
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
            <div class="col-lg-8">
                <div class="card form-card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-car me-2"></i> Vehicle Registration Form</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" id="registrationForm">
                            <h5 class="form-section-title">Registration Information</h5>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="owner_id" class="form-label required-field">Vehicle Owner</label>
                                    <select class="form-select" id="owner_id" name="owner_id" required>
                                        <option value="">Select Owner</option>
                                        <?php foreach ($vehicleOwners as $owner): ?>
                                            <option value="<?= $owner['id'] ?>" <?= $vehicleData['owner_id'] == $owner['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($owner['name']) ?> (<?= htmlspecialchars($owner['ghana_card_number']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Select from existing owners or <a href="add-owner.php" class="fw-bold">add new owner</a></div>
                                </div>
                                <div class="col-md-6">
                                    <label for="registration_number" class="form-label required-field">Registration Number</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="registration_number" name="registration_number" value="<?= htmlspecialchars($vehicleData['registration_number']) ?>" readonly>
                                        <button class="btn btn-outline-secondary" type="button" id="generate_reg_btn" title="Generate a new, unique registration number">
                                            <i class="fas fa-sync-alt"></i> Re-generate
                                        </button>
                                    </div>
                                    <div class="form-text">
                                        <span id="reg_number_status">Auto-generated when owner is selected</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="registration_date" class="form-label required-field">Registration Date</label>
                                    <input type="date" class="form-control" id="registration_date" name="registration_date" value="<?= htmlspecialchars($vehicleData['registration_date']) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="expiry_date" class="form-label required-field">Expiry Date</label>
                                    <input type="date" class="form-control" id="expiry_date" name="expiry_date" value="<?= htmlspecialchars($vehicleData['expiry_date']) ?>" required>
                                </div>
                            </div>
                            
                            <h5 class="form-section-title mt-4">Vehicle Details</h5>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="make" class="form-label required-field">Make</label>
                                    <input type="text" class="form-control" id="make" name="make" list="makes-list" value="<?= htmlspecialchars($vehicleData['make']) ?>" required>
                                    <datalist id="makes-list">
                                        <?php foreach ($commonMakes as $make): ?>
                                            <option value="<?= htmlspecialchars($make) ?>">
                                        <?php endforeach; ?>
                                    </datalist>
                                </div>
                                <div class="col-md-6">
                                    <label for="model" class="form-label required-field">Model</label>
                                    <input type="text" class="form-control" id="model" name="model" value="<?= htmlspecialchars($vehicleData['model']) ?>" required>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <label for="vehicle_type" class="form-label required-field">Vehicle Type</label>
                                    <select class="form-select" id="vehicle_type" name="vehicle_type" required>
                                        <option value="Car" <?= $vehicleData['vehicle_type'] == 'Car' ? 'selected' : '' ?>>Car</option>
                                        <option value="SUV" <?= $vehicleData['vehicle_type'] == 'SUV' ? 'selected' : '' ?>>SUV</option>
                                        <option value="Truck" <?= $vehicleData['vehicle_type'] == 'Truck' ? 'selected' : '' ?>>Truck</option>
                                        <option value="Bus" <?= $vehicleData['vehicle_type'] == 'Bus' ? 'selected' : '' ?>>Bus</option>
                                        <option value="Motorcycle" <?= $vehicleData['vehicle_type'] == 'Motorcycle' ? 'selected' : '' ?>>Motorcycle</option>
                                        <option value="Trailer" <?= $vehicleData['vehicle_type'] == 'Trailer' ? 'selected' : '' ?>>Trailer</option>
                                        <option value="Taxi" <?= $vehicleData['vehicle_type'] == 'Taxi' ? 'selected' : '' ?>>Taxi</option>
                                        <option value="Other" <?= $vehicleData['vehicle_type'] == 'Other' ? 'selected' : '' ?>>Other</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="vehicle_use" class="form-label required-field">Vehicle Use</label>
                                    <select class="form-select" id="vehicle_use" name="vehicle_use" required>
                                        <option value="Private" <?= $vehicleData['vehicle_use'] == 'Private' ? 'selected' : '' ?>>Private</option>
                                        <option value="Commercial" <?= $vehicleData['vehicle_use'] == 'Commercial' ? 'selected' : '' ?>>Commercial</option>
                                        <option value="Government" <?= $vehicleData['vehicle_use'] == 'Government' ? 'selected' : '' ?>>Government</option>
                                        <option value="Diplomatic" <?= $vehicleData['vehicle_use'] == 'Diplomatic' ? 'selected' : '' ?>>Diplomatic</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="color" class="form-label required-field">Color</label>
                                    <input type="text" class="form-control" id="color" name="color" value="<?= htmlspecialchars($vehicleData['color']) ?>" required>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <label for="year_of_manufacture" class="form-label required-field">Year of Manufacture</label>
                                    <input type="number" class="form-control" id="year_of_manufacture" name="year_of_manufacture" min="1900" max="<?= date('Y') + 1 ?>" value="<?= htmlspecialchars($vehicleData['year_of_manufacture']) ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label for="engine_capacity" class="form-label required-field">Engine Capacity (cc)</label>
                                    <input type="text" class="form-control" id="engine_capacity" name="engine_capacity" value="<?= htmlspecialchars($vehicleData['engine_capacity']) ?>" placeholder="e.g., 1600" required>
                                </div>
                                <div class="col-md-4">
                                    <label for="seating_capacity" class="form-label required-field">Seating Capacity</label>
                                    <input type="number" class="form-control" id="seating_capacity" name="seating_capacity" min="1" max="100" value="<?= htmlspecialchars($vehicleData['seating_capacity']) ?>" placeholder="e.g., 5" required>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <label for="engine_type" class="form-label required-field">Engine Type</label>
                                    <select class="form-select" id="engine_type" name="engine_type" required>
                                        <option value="petrol" <?= $vehicleData['engine_type'] == 'petrol' ? 'selected' : '' ?>>Petrol</option>
                                        <option value="diesel" <?= $vehicleData['engine_type'] == 'diesel' ? 'selected' : '' ?>>Diesel</option>
                                        <option value="electric" <?= $vehicleData['engine_type'] == 'electric' ? 'selected' : '' ?>>Electric</option>
                                        <option value="hybrid" <?= $vehicleData['engine_type'] == 'hybrid' ? 'selected' : '' ?>>Hybrid</option>
                                        <option value="other" <?= $vehicleData['engine_type'] == 'other' ? 'selected' : '' ?>>Other</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="engine_number" class="form-label required-field">Engine Number</label>
                                    <input type="text" class="form-control" id="engine_number" name="engine_number" value="<?= htmlspecialchars($vehicleData['engine_number']) ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label for="chassis_number" class="form-label required-field">Chassis Number</label>
                                    <input type="text" class="form-control" id="chassis_number" name="chassis_number" value="<?= htmlspecialchars($vehicleData['chassis_number']) ?>" required>
                                </div>
                            </div>
                            
                            <div class="mt-4 d-flex justify-content-between">
                                <a href="dashboard.php" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left me-2"></i> Back to Dashboard
                                </a>
                                <button type="submit" class="btn btn-primary" id="submit-btn">
                                    <i class="fas fa-save me-2"></i> Register Vehicle
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4">
                <div class="card mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i> Registration Guidelines</h5>
                    </div>
                    <div class="card-body">
                        <h6>Required Documents</h6>
                        <ul>
                            <li>Vehicle purchase receipt or proof of ownership</li>
                            <li>Valid identification of the vehicle owner</li>
                            <li>Import documentation (for imported vehicles)</li>
                            <li>Certificate of roadworthiness</li>
                        </ul>
                        
                        <h6>Vehicle Use Categories</h6>
                        <ul>
                            <li><strong>Private:</strong> Personal/family use only</li>
                            <li><strong>Commercial:</strong> For business operations, taxis, etc.</li>
                            <li><strong>Government:</strong> Official government vehicles</li>
                            <li><strong>Diplomatic:</strong> For diplomatic missions</li>
                        </ul>
                        
                        <h6>Registration Number Format</h6>
                        <p>Registration numbers are automatically generated in the format:<br>
                        <code>GR-XXXX-YY</code> where:</p>
                        <ul>
                            <li>GR = Ghana Registration</li>
                            <li>XXXX = 4-digit sequential number</li>
                            <li>YY = Last two digits of the registration year</li>
                        </ul>
                        
                        <h6>Notes</h6>
                        <ul>
                            <li>All required fields are marked with an asterisk (*)</li>
                            <li>The vehicle owner must be registered in the system before registering their vehicle</li>
                            <li>Registration is valid for one year from the date of registration</li>
                            <li>Engine capacity should be entered in cubic centimeters (cc)</li>
                            <li>Seating capacity refers to the maximum number of occupants including the driver</li>
                            <li>Each registration number is unique and cannot be reused</li>
                        </ul>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0"><i class="fas fa-folder-plus me-2"></i> Related Actions</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <a href="add-owner.php" class="list-group-item list-group-item-action">
                                <i class="fas fa-user-plus me-2"></i> Add New Vehicle Owner
                            </a>
                            <a href="print-certificate.php" class="list-group-item list-group-item-action">
                                <i class="fas fa-print me-2"></i> Print Registration Certificate
                            </a>
                            <a href="generateqr.php" class="list-group-item list-group-item-action">
                                <i class="fas fa-qrcode me-2"></i> Generate QR Code
                            </a>
                            <a href="../vehicle/search.php" class="list-group-item list-group-item-action">
                                <i class="fas fa-search me-2"></i> Search Vehicle Database
                            </a>
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
    
    <!-- Custom JS -->
    <script>
        // Function to ensure unique registration numbers
        let lastGeneratedNumber = '';
        
        // Auto-calculate expiry date as 1 year after registration date
        document.getElementById('registration_date').addEventListener('change', function() {
            const regDate = new Date(this.value);
            if (!isNaN(regDate.getTime())) {
                const expiryDate = new Date(regDate);
                expiryDate.setFullYear(expiryDate.getFullYear() + 1);
                
                // Format the date as YYYY-MM-DD
                const formattedDate = expiryDate.toISOString().split('T')[0];
                document.getElementById('expiry_date').value = formattedDate;
            }
        });
        
        // Add validation for engine capacity to ensure it's numeric
        document.getElementById('engine_capacity').addEventListener('input', function() {
            const value = this.value;
            if (value && !(/^\d+$/.test(value))) {
                this.setCustomValidity('Please enter a numeric value for engine capacity');
            } else {
                this.setCustomValidity('');
            }
        });
        
        // Add validation for seating capacity to ensure it's a positive number
        document.getElementById('seating_capacity').addEventListener('input', function() {
            const value = parseInt(this.value);
            if (isNaN(value) || value < 1) {
                this.setCustomValidity('Please enter a positive number for seating capacity');
            } else {
                this.setCustomValidity('');
            }
        });
        
        // Update suggested seating capacity based on vehicle type
        document.getElementById('vehicle_type').addEventListener('change', function() {
            const vehicleType = this.value;
            const seatingCapacityField = document.getElementById('seating_capacity');
            
            // Set default seating capacity based on vehicle type if field is empty
            if (!seatingCapacityField.value) {
                switch(vehicleType) {
                    case 'Car':
                        seatingCapacityField.value = '5';
                        break;
                    case 'SUV':
                        seatingCapacityField.value = '7';
                        break;
                    case 'Bus':
                        seatingCapacityField.value = '30';
                        break;
                    case 'Truck':
                        seatingCapacityField.value = '3';
                        break;
                    case 'Motorcycle':
                        seatingCapacityField.value = '2';
                        break;
                    case 'Taxi':
                        seatingCapacityField.value = '5';
                        break;
                    default:
                        // Don't change existing value if it exists
                        break;
                }
            }
        });
        
        // Auto-select vehicle use based on vehicle type
        document.getElementById('vehicle_type').addEventListener('change', function() {
            const vehicleType = this.value;
            const vehicleUseField = document.getElementById('vehicle_use');
            
            // Set default vehicle use based on vehicle type
            if (vehicleType === 'Taxi' || vehicleType === 'Bus' || vehicleType === 'Truck') {
                vehicleUseField.value = 'Commercial';
            } else {
                // Default to 'Private' for other vehicle types if use hasn't been explicitly changed
                if (!vehicleUseField.dataset.userChanged) {
                    vehicleUseField.value = 'Private';
                }
            }
        });
        
        // Track if user has explicitly changed the vehicle use
        document.getElementById('vehicle_use').addEventListener('change', function() {
            this.dataset.userChanged = 'true';
        });
        
        // Copy to clipboard functionality
        document.querySelectorAll('.copy-btn').forEach(button => {
            button.addEventListener('click', function() {
                const textToCopy = this.getAttribute('data-copy');
                navigator.clipboard.writeText(textToCopy).then(() => {
                    // Change button text/icon temporarily
                    const originalHTML = this.innerHTML;
                    this.innerHTML = '<i class="fas fa-check"></i>';
                    setTimeout(() => {
                        this.innerHTML = originalHTML;
                    }, 1500);
                });
            });
        });

        // Function to generate registration number
        function generateRegistrationNumber(isRegenerate = false) {
            const statusElement = document.getElementById('reg_number_status');
            const regNumberField = document.getElementById('registration_number');
            const generateBtn = document.getElementById('generate_reg_btn');
            const submitBtn = document.getElementById('submit-btn');
            
            // Disable buttons during processing
            generateBtn.disabled = true;
            submitBtn.disabled = true;
            
            // Show loading state
            statusElement.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating unique registration number...';
            
            // URL parameters
            const params = new URLSearchParams({
                action: 'generate_reg_number',
                regenerate: isRegenerate.toString()
            });
            
            // Make AJAX request
            fetch(`?${params.toString()}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! Status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        // Ensure we're not getting the same number again
                        if (isRegenerate && data.registration_number === lastGeneratedNumber) {
                            statusElement.innerHTML = '<i class="fas fa-exclamation-circle text-warning"></i> Generated number was a duplicate, retrying...';
                            setTimeout(() => generateRegistrationNumber(true), 200);
                            return;
                        }
                        
                        // Set and store the new number
                        regNumberField.value = data.registration_number;
                        lastGeneratedNumber = data.registration_number;
                        statusElement.innerHTML = '<i class="fas fa-check-circle text-success"></i> Unique registration number generated';
                    } else {
                        statusElement.innerHTML = '<i class="fas fa-exclamation-circle text-danger"></i> Error: ' + (data.error || 'Failed to generate number');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    
                    // In case of error, generate on client side
                    const prefix = "GR";
                    const year = new Date().getFullYear().toString().substr(-2);
                    
                    // Use timestamp to ensure uniqueness
                    const timestamp = new Date().getTime().toString();
                    const uniqueDigits = timestamp.substr(timestamp.length - 4);
                    const regNumber = `${prefix}-${uniqueDigits}-${year}`;
                    
                    regNumberField.value = regNumber;
                    lastGeneratedNumber = regNumber;
                    statusElement.innerHTML = '<i class="fas fa-check-circle text-warning"></i> Registration number generated (offline mode)';
                })
                .finally(() => {
                    // Re-enable buttons
                    generateBtn.disabled = false;
                    submitBtn.disabled = false;
                });
        }
        
        // Auto-generate registration number when owner is selected
        document.getElementById('owner_id').addEventListener('change', function() {
            if (this.value) {
                generateRegistrationNumber(false);
            } else {
                document.getElementById('registration_number').value = '';
                document.getElementById('reg_number_status').textContent = 'Auto-generated when owner is selected';
            }
        });
        
        // Manual regenerate button - force unique number
        document.getElementById('generate_reg_btn').addEventListener('click', function() {
            generateRegistrationNumber(true);
        });

        // Form submission validation check
        document.getElementById('registrationForm').addEventListener('submit', function(e) {
            // Ensure registration number is provided
            const regNumber = document.getElementById('registration_number').value;
            if (!regNumber.trim()) {
                e.preventDefault();
                alert('Please select a vehicle owner to generate registration number first.');
                document.getElementById('owner_id').focus();
            }
        });
        
        // If owner is already selected on page load, generate the registration number
        document.addEventListener('DOMContentLoaded', function() {
            const ownerSelect = document.getElementById('owner_id');
            if (ownerSelect.value) {
                generateRegistrationNumber(false);
            }
        });
    </script>
</body>
</html>