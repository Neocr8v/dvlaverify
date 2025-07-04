<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../../../config/database.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../views/auth/login.php');
    exit;
}

// Check if insurance columns exist, and if not, create them
try {
    // Check if insurance_provider column exists
    $stmt = $pdo->prepare("SHOW COLUMNS FROM vehicles LIKE 'insurance_provider'");
    $stmt->execute();
    $columnExists = $stmt->fetch();
    
    // If columns don't exist, add them
    if (!$columnExists) {
        $pdo->exec("
            ALTER TABLE vehicles
            ADD COLUMN insurance_provider VARCHAR(100) NULL,
            ADD COLUMN policy_number VARCHAR(50) NULL,
            ADD COLUMN insurance_expiry DATE NULL,
            ADD COLUMN insurance_certificate_path VARCHAR(255) NULL
        ");
    }
} catch (Exception $e) {
    // Log the error but continue with the script
    error_log("Error checking or adding insurance columns: " . $e->getMessage());
}

$errors = [];
$success = false;
$vehicle = null;
$vehicles = [];

// Get all vehicles for the dropdown
try {
    // Join with vehicle_owners to get owner names
    $stmt = $pdo->query("
        SELECT v.id, v.registration_number, v.make, v.model, 
               v.color, o.name as owner_name
        FROM vehicles v
        JOIN vehicle_owners o ON v.owner_id = o.id
        ORDER BY v.registration_number
    ");
    $vehicles = $stmt->fetchAll();
} catch (PDOException $e) {
    $errors[] = "Error fetching vehicles: " . $e->getMessage();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if we're getting vehicle details first
    if (isset($_POST['vehicle_id']) && !isset($_POST['submit_insurance'])) {
        $vehicleId = $_POST['vehicle_id'];
        
        try {
            $stmt = $pdo->prepare("
                SELECT v.*, o.name as owner_name
                FROM vehicles v
                JOIN vehicle_owners o ON v.owner_id = o.id
                WHERE v.id = :id
            ");
            $stmt->execute(['id' => $vehicleId]);
            $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$vehicle) {
                $errors[] = "Vehicle not found";
            }
        } catch (PDOException $e) {
            $errors[] = "Error fetching vehicle details: " . $e->getMessage();
        }
    }
    // Handling insurance upload
    else if (isset($_POST['submit_insurance'])) {
        $vehicleId = $_POST['vehicle_id'];
        $insuranceProvider = trim($_POST['insurance_provider']);
        $policyNumber = trim($_POST['policy_number']);
        $insuranceExpiry = trim($_POST['insurance_expiry']);
        
        // Validate inputs
        if (empty($insuranceProvider)) {
            $errors[] = "Insurance provider is required";
        }
        
        if (empty($policyNumber)) {
            $errors[] = "Policy number is required";
        }
        
        if (empty($insuranceExpiry)) {
            $errors[] = "Insurance expiry date is required";
        }
        
        // Validate insurance date is in the future
        if (strtotime($insuranceExpiry) < strtotime('today')) {
            $errors[] = "Insurance expiry date must be in the future";
        }
        
        // File upload validation
        if (!isset($_FILES['insurance_certificate']) || $_FILES['insurance_certificate']['error'] != 0) {
            $errors[] = "Insurance certificate upload is required";
        } else {
            // File validation
            $fileInfo = pathinfo($_FILES['insurance_certificate']['name']);
            $extension = strtolower($fileInfo['extension']);
            
            // Validate file extension
            $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];
            if (!in_array($extension, $allowedExtensions)) {
                $errors[] = "Invalid file format. Only PDF, JPG, and PNG files are allowed";
            }
            
            // Validate file size (max 5MB)
            if ($_FILES['insurance_certificate']['size'] > 5 * 1024 * 1024) {
                $errors[] = "File size exceeds the maximum limit of 5MB";
            }
        }
        
        // If no errors, proceed to save
        if (empty($errors)) {
            try {
                // Create insurance documents directory if it doesn't exist
                $insuranceDir = __DIR__ . '/../../../uploads/insurance_certificates/';
                if (!is_dir($insuranceDir)) {
                    mkdir($insuranceDir, 0777, true);
                }
                
                // Get registration number for the filename
                $stmt = $pdo->prepare("SELECT registration_number FROM vehicles WHERE id = :id");
                $stmt->execute(['id' => $vehicleId]);
                $registrationNumber = $stmt->fetchColumn();
                
                // Generate unique filename
                $insuranceFileName = 'insurance_' . $registrationNumber . '_' . time() . '.' . $extension;
                $insuranceFilePath = $insuranceDir . $insuranceFileName;
                $insurancePath = 'uploads/insurance_certificates/' . $insuranceFileName;
                
                // Move uploaded file to destination
                if (!move_uploaded_file($_FILES['insurance_certificate']['tmp_name'], $insuranceFilePath)) {
                    throw new Exception("Failed to upload insurance certificate");
                }
                
                // Begin transaction
                $pdo->beginTransaction();
                
                // Check which columns exist in the vehicles table
                $columnsExist = [];
                $requiredColumns = ['insurance_provider', 'policy_number', 'insurance_expiry', 'insurance_certificate_path'];
                
                foreach ($requiredColumns as $column) {
                    $stmt = $pdo->prepare("SHOW COLUMNS FROM vehicles LIKE :column");
                    $stmt->execute(['column' => $column]);
                    $columnsExist[$column] = $stmt->fetch() !== false;
                }
                
                // Build SQL dynamically based on existing columns
                $setClause = [];
                $params = ['id' => $vehicleId];
                
                if ($columnsExist['insurance_provider']) {
                    $setClause[] = "insurance_provider = :insurance_provider";
                    $params['insurance_provider'] = $insuranceProvider;
                }
                
                if ($columnsExist['policy_number']) {
                    $setClause[] = "policy_number = :policy_number";
                    $params['policy_number'] = $policyNumber;
                }
                
                if ($columnsExist['insurance_expiry']) {
                    $setClause[] = "insurance_expiry = :insurance_expiry";
                    $params['insurance_expiry'] = $insuranceExpiry;
                }
                
                if ($columnsExist['insurance_certificate_path']) {
                    $setClause[] = "insurance_certificate_path = :insurance_certificate_path";
                    $params['insurance_certificate_path'] = $insurancePath;
                }
                
                // Update timestamp if column exists
                try {
                    $stmt = $pdo->prepare("SHOW COLUMNS FROM vehicles LIKE 'updated_at'");
                    $stmt->execute();
                    if ($stmt->fetch() !== false) {
                        $setClause[] = "updated_at = NOW()";
                    }
                } catch (Exception $e) {
                    // Ignore errors related to checking column existence
                }
                
                // Execute the update if we have columns to update
                if (!empty($setClause)) {
                    $sql = "UPDATE vehicles SET " . implode(", ", $setClause) . " WHERE id = :id";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                }
                
                // Store insurance info in session for display on success page (in case columns don't exist)
                $_SESSION['insurance_data'] = [
                    'provider' => $insuranceProvider,
                    'policy_number' => $policyNumber,
                    'expiry_date' => $insuranceExpiry,
                    'certificate_path' => $insurancePath
                ];
                
                // Commit transaction
                $pdo->commit();
                
                $success = true;
                
                // Get updated vehicle details to show success message
                $stmt = $pdo->prepare("
                    SELECT v.*, o.name as owner_name
                    FROM vehicles v
                    JOIN vehicle_owners o ON v.owner_id = o.id
                    WHERE v.id = :id
                ");
                $stmt->execute(['id' => $vehicleId]);
                $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);
                
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = "Database error: " . $e->getMessage();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = "Error: " . $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Insurance Certificate | Admin | Vehicle Registration System</title>
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
                <li class="breadcrumb-item active">Upload Insurance Certificate</li>
            </ol>
        </nav>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i> Insurance certificate for vehicle <?= htmlspecialchars($vehicle['registration_number']) ?> has been uploaded successfully!
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
            <div class="col-lg-8">
                <div class="card form-card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-file-upload me-2"></i> Upload Insurance Certificate</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($vehicle && !$success): ?>
                            <!-- Vehicle details and insurance upload form -->
                            <div class="mb-4">
                                <h6>Vehicle Information</h6>
                                <table class="table table-bordered">
                                    <tr>
                                        <th>Registration Number</th>
                                        <td><?= htmlspecialchars($vehicle['registration_number']) ?></td>
                                        <th>Owner</th>
                                        <td><?= htmlspecialchars($vehicle['owner_name']) ?></td>
                                    </tr>
                                    <tr>
                                        <th>Make</th>
                                        <td><?= htmlspecialchars($vehicle['make']) ?></td>
                                        <th>Model</th>
                                        <td><?= htmlspecialchars($vehicle['model']) ?></td>
                                    </tr>
                                </table>
                            </div>
                            
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="vehicle_id" value="<?= $vehicle['id'] ?>">
                                <h6 class="mb-3">Insurance Details</h6>
                                
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="insurance_provider" class="form-label required-field">Insurance Provider</label>
                                        <input type="text" class="form-control" id="insurance_provider" name="insurance_provider" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="policy_number" class="form-label required-field">Policy Number</label>
                                        <input type="text" class="form-control" id="policy_number" name="policy_number" required>
                                    </div>
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="insurance_expiry" class="form-label required-field">Insurance Expiry Date</label>
                                        <input type="date" class="form-control" id="insurance_expiry" name="insurance_expiry" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="insurance_certificate" class="form-label required-field">Insurance Certificate</label>
                                        <input type="file" class="form-control" id="insurance_certificate" name="insurance_certificate" accept=".pdf,.jpg,.jpeg,.png" required>
                                        <div class="form-text">Upload insurance certificate (PDF, JPG, PNG formats accepted, max 5MB)</div>
                                    </div>
                                </div>
                                
                                <div class="mt-4 d-flex justify-content-between">
                                    <a href="upload-insurance.php" class="btn btn-secondary">
                                        <i class="fas fa-arrow-left me-2"></i> Back to Vehicle Selection
                                    </a>
                                    <button type="submit" name="submit_insurance" class="btn btn-primary">
                                        <i class="fas fa-upload me-2"></i> Upload Insurance Certificate
                                    </button>
                                </div>
                            </form>
                        <?php elseif ($success): ?>
                            <!-- Success state with vehicle and insurance info -->
                            <div class="text-center mb-4">
                                <div class="mb-3">
                                    <i class="fas fa-check-circle text-success" style="font-size: 3rem;"></i>
                                </div>
                                <h5>Insurance certificate uploaded successfully</h5>
                                <p>Insurance details for <strong><?= htmlspecialchars($vehicle['registration_number']) ?></strong> have been updated.</p>
                            </div>
                            
                            <div class="card mb-3">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0">Insurance Details</h6>
                                </div>
                                <div class="card-body">
                                    <table class="table">
                                        <tr>
                                            <th>Provider</th>
                                            <td>
                                                <?php if (isset($vehicle['insurance_provider'])): ?>
                                                    <?= htmlspecialchars($vehicle['insurance_provider']) ?>
                                                <?php elseif (isset($_SESSION['insurance_data']['provider'])): ?>
                                                    <?= htmlspecialchars($_SESSION['insurance_data']['provider']) ?>
                                                <?php else: ?>
                                                    <?= htmlspecialchars($insuranceProvider ?? '') ?>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Policy Number</th>
                                            <td>
                                                <?php if (isset($vehicle['policy_number'])): ?>
                                                    <?= htmlspecialchars($vehicle['policy_number']) ?>
                                                <?php elseif (isset($_SESSION['insurance_data']['policy_number'])): ?>
                                                    <?= htmlspecialchars($_SESSION['insurance_data']['policy_number']) ?>
                                                <?php else: ?>
                                                    <?= htmlspecialchars($policyNumber ?? '') ?>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Expiry Date</th>
                                            <td>
                                                <?php if (isset($vehicle['insurance_expiry'])): ?>
                                                    <?= date('d M Y', strtotime($vehicle['insurance_expiry'])) ?>
                                                <?php elseif (isset($_SESSION['insurance_data']['expiry_date'])): ?>
                                                    <?= date('d M Y', strtotime($_SESSION['insurance_data']['expiry_date'])) ?>
                                                <?php else: ?>
                                                    <?= isset($insuranceExpiry) ? date('d M Y', strtotime($insuranceExpiry)) : '' ?>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Certificate</th>
                                            <td>
                                                <?php
                                                $certificatePath = $vehicle['insurance_certificate_path'] ?? 
                                                                  ($_SESSION['insurance_data']['certificate_path'] ?? 
                                                                  $insurancePath ?? '');
                                                if ($certificatePath):
                                                ?>
                                                <a href="/<?= htmlspecialchars($certificatePath) ?>" target="_blank" class="btn btn-sm btn-info">
                                                    <i class="fas fa-file-alt me-1"></i> View Certificate
                                                </a>
                                                <?php else: ?>
                                                <span class="text-muted">Certificate uploaded but path not stored in database</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            
                            <div class="mt-4 text-center">
                                <a href="upload-insurance.php" class="btn btn-primary">
                                    <i class="fas fa-file-upload me-2"></i> Upload Another Certificate
                                </a>
                                <a href="dashboard.php" class="btn btn-secondary ms-2">
                                    <i class="fas fa-home me-2"></i> Return to Dashboard
                                </a>
                            </div>

                            <?php
                            // Clear session data after using it
                            if (isset($_SESSION['insurance_data'])) {
                                unset($_SESSION['insurance_data']);
                            }
                            ?>
                        <?php else: ?>
                            <!-- Initial state - vehicle selection form -->
                            <form method="POST">
                                <div class="mb-4">
                                    <label for="vehicle_id" class="form-label required-field">Select Vehicle</label>
                                    <select class="form-select" id="vehicle_id" name="vehicle_id" required>
                                        <option value="">-- Select a vehicle --</option>
                                        <?php foreach ($vehicles as $v): ?>
                                            <option value="<?= $v['id'] ?>">
                                                <?= htmlspecialchars($v['registration_number']) ?> - 
                                                <?= htmlspecialchars($v['make']) ?> 
                                                <?= htmlspecialchars($v['model']) ?> 
                                                (<?= htmlspecialchars($v['owner_name']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Select the vehicle for which you want to upload an insurance certificate</div>
                                </div>
                                
                                <div class="d-flex justify-content-between">
                                    <a href="dashboard.php" class="btn btn-secondary">
                                        <i class="fas fa-arrow-left me-2"></i> Back to Dashboard
                                    </a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-arrow-right me-2"></i> Continue
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4">
                <div class="card mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i> About Insurance Certificates</h5>
                    </div>
                    <div class="card-body">
                        <h6>Requirements</h6>
                        <ul>
                            <li>Insurance certificate must be valid and not expired</li>
                            <li>Certificate must clearly show the policy number and expiration date</li>
                            <li>Certificate must be issued by a recognized insurance provider</li>
                            <li>Upload must be in PDF, JPG, or PNG format</li>
                            <li>Maximum file size: 5MB</li>
                        </ul>
                        
                        <h6>Insurance Types</h6>
                        <ul>
                            <li><strong>Third Party:</strong> Covers damages to other vehicles/property</li>
                            <li><strong>Third Party, Fire & Theft:</strong> Additionally covers your vehicle against fire and theft</li>
                            <li><strong>Comprehensive:</strong> Includes all of the above plus damage to your own vehicle</li>
                        </ul>
                        
                        <div class="alert alert-warning mt-3">
                            <i class="fas fa-exclamation-triangle me-2"></i> <strong>Important:</strong> Vehicle insurance is mandatory by law. Operating a vehicle without valid insurance is illegal and may result in fines or vehicle impoundment.
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0"><i class="fas fa-folder-plus me-2"></i> Related Actions</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <a href="register-vehicle.php" class="list-group-item list-group-item-action">
                                <i class="fas fa-car me-2"></i> Register New Vehicle
                            </a>
                            <a href="print-certificate.php" class="list-group-item list-group-item-action">
                                <i class="fas fa-print me-2"></i> Print Registration Certificate
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
        document.addEventListener('DOMContentLoaded', function() {
            // Set default insurance expiry date - 1 year from today
            const today = new Date();
            const nextYear = new Date();
            nextYear.setFullYear(today.getFullYear() + 1);
            
            const insuranceExpiryField = document.getElementById('insurance_expiry');
            if (insuranceExpiryField) {
                const defaultExpiryDate = nextYear.toISOString().split('T')[0];
                insuranceExpiryField.value = defaultExpiryDate;
                insuranceExpiryField.min = today.toISOString().split('T')[0]; // Prevent past dates
            }
            
            // Add validation for insurance certificate file
            const fileInput = document.getElementById('insurance_certificate');
            if (fileInput) {
                fileInput.addEventListener('change', function() {
                    const maxSize = 5 * 1024 * 1024; // 5MB
                    
                    if (this.files.length > 0) {
                        // Check file size
                        if (this.files[0].size > maxSize) {
                            alert('File size exceeds 5MB. Please select a smaller file.');
                            this.value = '';
                            return;
                        }
                        
                        // Check file extension
                        const fileName = this.files[0].name;
                        const fileExt = fileName.split('.').pop().toLowerCase();
                        const allowedExts = ['pdf', 'jpg', 'jpeg', 'png'];
                        
                        if (!allowedExts.includes(fileExt)) {
                            alert('Invalid file type. Only PDF, JPG, JPEG and PNG files are allowed.');
                            this.value = '';
                            return;
                        }
                    }
                });
            }
            
            // Optional: Add auto-completion for common insurance providers
            const insuranceProviders = [
                'State Insurance Company',
                'Enterprise Insurance',
                'SIC Insurance',
                'Star Assurance',
                'Vanguard Assurance',
                'Phoenix Insurance',
                'Hollard Insurance',
                'Metropolitan Insurance',
                'Activa International',
                'Allianz Insurance'
            ];
            
            const providerField = document.getElementById('insurance_provider');
            if (providerField) {
                // Create datalist element
                const datalist = document.createElement('datalist');
                datalist.id = 'insurance-providers-list';
                
                // Add options to datalist
                insuranceProviders.forEach(provider => {
                    const option = document.createElement('option');
                    option.value = provider;
                    datalist.appendChild(option);
                });
                
                // Add datalist to document
                document.body.appendChild(datalist);
                
                // Link datalist to input
                providerField.setAttribute('list', 'insurance-providers-list');
            }
        });
    </script>
</body>
</html>