<?php
// filepath: /Applications/XAMPP/xamppfiles/htdocs/dvlaregister/src/views/admin/print-certificate.php
session_start();
require_once __DIR__ . '/../../../config/database.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../views/auth/login.php');
    exit;
}

$errors = [];
$vehicleDetails = null;
$ownerDetails = null;

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

// Check if vehicle ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $errors[] = "Invalid vehicle ID";
} else {
    $vehicleId = $_GET['id'];
    
    try {
        // Check if passport_photo_path column exists
        $passportPhotoColumnExists = false;
        try {
            $checkColumnStmt = $pdo->prepare("SHOW COLUMNS FROM vehicle_owners LIKE 'passport_photo_path'");
            $checkColumnStmt->execute();
            $passportPhotoColumnExists = ($checkColumnStmt->rowCount() > 0);
        } catch (PDOException $e) {
            error_log("Failed to check passport_photo_path column: " . $e->getMessage());
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
        
        // Check if vehicle_use column exists
        $vehicleUseExists = false;
        try {
            $checkColumnStmt = $pdo->prepare("SHOW COLUMNS FROM vehicles LIKE 'vehicle_use'");
            $checkColumnStmt->execute();
            $vehicleUseExists = ($checkColumnStmt->rowCount() > 0);
        } catch (PDOException $e) {
            error_log("Failed to check vehicle_use column: " . $e->getMessage());
        }
        
        // Build the SQL query based on whether the columns exist
        $selectFields = "v.*, o.*, v.id as vehicle_id, o.id as owner_id,
                 v.registration_date as vehicle_reg_date,
                 o.name as owner_name, o.address as owner_address";
                 
        if ($passportPhotoColumnExists) {
            $selectFields .= ", o.passport_photo_path";
        }
        
        if ($idDocumentTypeExists) {
            $selectFields .= ", o.id_document_type";
        }
        
        $stmt = $pdo->prepare("
            SELECT $selectFields
            FROM vehicles v
            JOIN vehicle_owners o ON v.owner_id = o.id
            WHERE v.id = :id
        ");
        $stmt->execute(['id' => $vehicleId]);
        $result = $stmt->fetch();
        
        if (!$result) {
            $errors[] = "Vehicle not found";
        } else {
            $vehicleDetails = $result;
            
            // If vehicle_use is not set, determine it from vehicle_type
            if (!isset($vehicleDetails['vehicle_use']) || empty($vehicleDetails['vehicle_use'])) {
                $vehicleDetails['vehicle_use'] = determineVehicleUse($vehicleDetails['vehicle_type']);
            }
            
            // Format dates
            $regDate = new DateTime($vehicleDetails['vehicle_reg_date']);
            $expiryDate = new DateTime($vehicleDetails['expiry_date']);
            
            // Get QR code if exists
            $qrCodePath = !empty($vehicleDetails['qr_code_path']) ? '/' . $vehicleDetails['qr_code_path'] : '';
            
            // Get the document type name based on the id_document_type value
            if ($idDocumentTypeExists && isset($vehicleDetails['id_document_type'])) {
                $idDocumentType = $vehicleDetails['id_document_type'];
            } else {
                $idDocumentType = 'ghana_card'; // Default if column doesn't exist
            }
            $idDocumentLabel = getDocumentTypeDisplay($idDocumentType);
        }
    } catch (PDOException $e) {
        $errors[] = "Database error: " . $e->getMessage();
    }
}

// Get current date for certificate
$currentDate = new DateTime();
$certificateNumber = 'DVLA-' . date('Y') . '-' . str_pad($vehicleId, 6, '0', STR_PAD_LEFT);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Registration Certificate</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Arial', sans-serif;
            background-color: #f8f9fa;
            color: #333;
        }
        
        .certificate-container {
            max-width: 210mm; /* A4 width */
            margin: 20px auto;
            background-color: white;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
            position: relative;
            padding: 20px;
            border: 1px solid #ddd;
        }
        
        @media print {
            body {
                background-color: white;
            }
            
            .certificate-container {
                box-shadow: none;
                margin: 0;
                padding: 0.5cm;
                border: none;
                width: 100%;
                height: 100%;
            }
            
            .no-print {
                display: none !important;
            }
            
            .watermark {
                opacity: 0.07 !important;
            }
        }
        
        .header {
            text-align: center;
            border-bottom: 2px solid #0a3d62;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        
        .title {
            font-size: 24px;
            font-weight: bold;
            color: #0a3d62;
            margin-bottom: 5px;
        }
        
        .subtitle {
            font-size: 16px;
            color: #666;
        }
        
        .certificate-body {
            padding: 20px 10px;
            position: relative;
            z-index: 1;
        }
        
        .certificate-section {
            margin-bottom: 25px;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: bold;
            color: #0a3d62;
            border-bottom: 1px solid #ddd;
            padding-bottom: 5px;
            margin-bottom: 15px;
        }
        
        .info-row {
            display: flex;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }
        
        .info-item {
            width: 50%;
            padding-right: 15px;
            margin-bottom: 10px;
        }
        
        .info-label {
            font-weight: bold;
            color: #555;
            font-size: 14px;
        }
        
        .info-value {
            font-size: 15px;
        }
        
        .certificate-footer {
            margin-top: 30px;
            border-top: 1px solid #ddd;
            padding-top: 20px;
            display: flex;
            justify-content: space-between;
        }
        
        .signature-area {
            width: 45%;
            text-align: center;
        }
        
        .signature-line {
            border-top: 1px solid #777;
            margin-top: 40px;
            margin-bottom: 5px;
        }
        
        .signature-name {
            font-weight: bold;
        }
        
        .signature-title {
            font-size: 14px;
            color: #666;
        }
        
        .official-seal {
            position: absolute;
            bottom: 50px;
            right: 50px;
            width: 120px;
            height: 120px;
            display: flex;
            justify-content: center;
            align-items: center;
            border: 2px solid #0a3d62;
            border-radius: 50%;
            opacity: 0.7;
            font-weight: bold;
            color: #0a3d62;
            transform: rotate(-15deg);
        }
        
        .certificate-number {
            font-size: 14px;
            color: #777;
            margin-bottom: 10px;
        }
        
        .qr-code-area {
            position: absolute;
            right: 25px;
            top: 100px;
            width: 100px;
        }
        
        .qr-code-img {
            width: 100%;
            height: auto;
        }
        
        .qr-code-label {
            font-size: 10px;
            text-align: center;
            margin-top: 5px;
        }
        
        .watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            opacity: 0.05;
            z-index: 0;
            width: 60%;
            height: auto;
            pointer-events: none;
        }
        
        .flag-colors {
            width: 100%;
            height: 10px;
            display: flex;
            margin-bottom: 10px;
        }
        
        .flag-red {
            background-color: #CE1126;
            flex: 1;
        }
        
        .flag-gold {
            background-color: #FCD116;
            flex: 1;
        }
        
        .flag-green {
            background-color: #006B3F;
            flex: 1;
        }
        
        .black-star {
            width: 30px;
            height: 30px;
            position: absolute;
            top: 55px;
            left: 50%;
            transform: translateX(-50%);
        }
        
        .validation-text {
            font-size: 12px;
            font-style: italic;
            text-align: center;
            color: #777;
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px dashed #ddd;
        }
        
        .print-buttons {
            text-align: center;
            margin: 20px 0;
        }
        
        .logo-area {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .dvla-logo {
            height: 60px;
            width: auto;
        }
        
        .coat-of-arms {
            height: 70px;
            width: auto;
            margin: 0 auto;
        }
        
        /* New styles for passport photo */
        .document-sections {
            display: flex;
            margin-bottom: 20px;
        }
        
        .photo-section {
            width: 130px;
            margin-right: 20px;
        }
        
        .details-section {
            flex: 1;
        }
        
        .passport-photo-container {
            width: 120px;
            height: 140px;
            border: 2px solid #0a3d62;
            overflow: hidden;
            display: flex;
            justify-content: center;
            align-items: center;
            background-color: #f8f9fa;
            border-radius: 4px;
            margin-bottom: 10px;
        }
        
        .passport-photo {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .photo-placeholder {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
            background-color: #f1f1f1;
            color: #777;
            font-size: 11px;
            text-align: center;
        }
        
        .photo-placeholder i {
            font-size: 2.5rem;
            margin-bottom: 5px;
        }
        
        .photo-text {
            font-size: 11px;
            text-align: center;
            color: #555;
            font-weight: bold;
        }
        
        .vehicle-use-badge {
            display: inline-block;
            padding: 0.15rem 0.4rem;
            font-size: 0.9rem;
            font-weight: 500;
            border-radius: 0.2rem;
            margin-left: 0.3rem;
            border: 1px solid #666;
        }
        
        .vehicle-use-private {
            background-color: #cfe2ff;
            color: #0a58ca;
            border-color: #0a58ca;
        }
        
        .vehicle-use-commercial {
            background-color: #f8d7da;
            color: #842029;
            border-color: #842029;
        }
        
        .vehicle-use-government {
            background-color: #d1e7dd;
            color: #146c43;
            border-color: #146c43;
        }
        
        .vehicle-use-diplomatic {
            background-color: #fff3cd;
            color: #664d03;
            border-color: #664d03;
        }
    </style>
</head>
<body>
    <div class="container mt-4 no-print">
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i> This is a preview of the vehicle registration certificate. Use the button below to print it.
        </div>
        
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <div class="print-buttons no-print">
            <button onclick="window.print()" class="btn btn-primary">
                <i class="fas fa-print me-2"></i> Print Certificate
            </button>
            <a href="download-certificate-pdf.php?id=<?= $vehicleId ?>" class="btn btn-success">
                <i class="fas fa-file-pdf me-2"></i> Download PDF
            </a>
            <a href="manage-vehicles.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i> Back to Vehicles
            </a>
        </div>
    </div>
    
    <?php if ($vehicleDetails): ?>
    <div class="certificate-container">
        <!-- Ghana flag colors -->
        <div class="flag-colors">
            <div class="flag-red"></div>
            <div class="flag-gold"></div>
            <div class="flag-green"></div>
        </div>
        
        <!-- Logo area with DVLA logo and coat of arms -->
        <div class="logo-area">
            <img src="/assets/img/dvla.png" alt="DVLA Logo" class="dvla-logo">
            <img src="/assets/img/GoG.png" alt="Ghana Coat of Arms" class="coat-of-arms">
            <div style="width: 100px;"></div><!-- This is for spacing balance -->
        </div>
        
        <div class="header">
            <div class="title">REPUBLIC OF GHANA</div>
            <div class="subtitle">DRIVER AND VEHICLE LICENSING AUTHORITY</div>
            <div class="fw-bold mt-2">VEHICLE REGISTRATION CERTIFICATE</div>
        </div>
        
        <div class="certificate-number">
            Certificate Number: <?= htmlspecialchars($certificateNumber) ?>
        </div>
        
        <!-- Watermark -->
        <img src="/assets/img/logo.png" alt="DVLA Watermark" class="watermark">
        
        <!-- QR Code if available -->
        <?php if (!empty($qrCodePath)): ?>
        <div class="qr-code-area">
            <img src="<?= htmlspecialchars($qrCodePath) ?>" alt="Vehicle QR Code" class="qr-code-img">
            <div class="qr-code-label">Scan to verify</div>
        </div>
        <?php endif; ?>
        
        <div class="certificate-body">
            <!-- Split the certificate into photo and details sections -->
            <div class="document-sections">
                <!-- Photo Section -->
                <div class="photo-section">
                    <div class="passport-photo-container">
                        <?php if (isset($vehicleDetails['passport_photo_path']) && !empty($vehicleDetails['passport_photo_path'])): ?>
                            <img src="/<?= htmlspecialchars($vehicleDetails['passport_photo_path']) ?>" 
                                 alt="Owner Photo" class="passport-photo">
                        <?php else: ?>
                            <div class="photo-placeholder">
                                <i class="fas fa-user"></i>
                                <span>No Photo Available</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="photo-text">OWNER PHOTO</div>
                </div>
                
                <!-- Details Section -->
                <div class="details-section">
                    <div class="certificate-section">
                        <div class="section-title">VEHICLE DETAILS</div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="info-row">
                                    <div class="info-item">
                                        <div class="info-label">Registration Number:</div>
                                        <div class="info-value"><?= htmlspecialchars($vehicleDetails['registration_number']) ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Vehicle Type:</div>
                                        <div class="info-value">
                                            <?= htmlspecialchars($vehicleDetails['vehicle_type']) ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="info-row">
                                    <div class="info-item">
                                        <div class="info-label">Vehicle Use:</div>
                                        <div class="info-value">
                                            <?php 
                                            // Determine which badge class to use
                                            $vehicleUse = $vehicleDetails['vehicle_use'];
                                            $badgeClass = 'vehicle-use-private';
                                            
                                            if (strtolower($vehicleUse) === 'commercial') {
                                                $badgeClass = 'vehicle-use-commercial';
                                            } elseif (strtolower($vehicleUse) === 'government') {
                                                $badgeClass = 'vehicle-use-government';
                                            } elseif (strtolower($vehicleUse) === 'diplomatic') {
                                                $badgeClass = 'vehicle-use-diplomatic';
                                            }
                                            ?>
                                            <span class="vehicle-use-badge <?= $badgeClass ?>"><?= htmlspecialchars($vehicleUse) ?></span>
                                        </div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Color:</div>
                                        <div class="info-value"><?= htmlspecialchars($vehicleDetails['color']) ?></div>
                                    </div>
                                </div>
                                <div class="info-row">
                                    <div class="info-item">
                                        <div class="info-label">Make:</div>
                                        <div class="info-value"><?= htmlspecialchars($vehicleDetails['make']) ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Model:</div>
                                        <div class="info-value"><?= htmlspecialchars($vehicleDetails['model']) ?></div>
                                    </div>
                                </div>
                                <div class="info-row">
                                    <div class="info-item">
                                        <div class="info-label">Year of Manufacture:</div>
                                        <div class="info-value"><?= htmlspecialchars($vehicleDetails['year_of_manufacture']) ?></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-row">
                                    <div class="info-item">
                                        <div class="info-label">Engine Number:</div>
                                        <div class="info-value"><?= htmlspecialchars($vehicleDetails['engine_number']) ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Chassis Number:</div>
                                        <div class="info-value"><?= htmlspecialchars($vehicleDetails['chassis_number']) ?></div>
                                    </div>
                                </div>
                                <div class="info-row">
                                    <div class="info-item">
                                        <div class="info-label">Engine Capacity:</div>
                                        <div class="info-value"><?= htmlspecialchars($vehicleDetails['engine_capacity']) ?> cc</div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Seating Capacity:</div>
                                        <div class="info-value"><?= htmlspecialchars($vehicleDetails['seating_capacity']) ?></div>
                                    </div>
                                </div>
                                <div class="info-row">
                                    <div class="info-item">
                                        <div class="info-label">Registration Date:</div>
                                        <div class="info-value"><?= $regDate->format('d F Y') ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Expiry Date:</div>
                                        <div class="info-value"><?= $expiryDate->format('d F Y') ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Owner details section -->
            <div class="certificate-section">
                <div class="section-title">OWNER DETAILS</div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="info-row">
                            <div class="info-item">
                                <div class="info-label">Owner Name:</div>
                                <div class="info-value"><?= htmlspecialchars($vehicleDetails['owner_name']) ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label"><?= isset($idDocumentLabel) ? htmlspecialchars($idDocumentLabel) : 'Ghana Card' ?> Number:</div>
                                <div class="info-value"><?= htmlspecialchars($vehicleDetails['ghana_card_number']) ?></div>
                            </div>
                        </div>
                        <div class="info-row">
                            <div class="info-item">
                                <div class="info-label">Address:</div>
                                <div class="info-value"><?= htmlspecialchars($vehicleDetails['owner_address']) ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="info-row">
                            <div class="info-item">
                                <div class="info-label">Phone Number:</div>
                                <div class="info-value"><?= htmlspecialchars($vehicleDetails['phone']) ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Email:</div>
                                <div class="info-value"><?= htmlspecialchars($vehicleDetails['email'] ?? 'N/A') ?></div>
                            </div>
                        </div>
                        <?php if (!empty($vehicleDetails['date_of_birth'])): ?>
                            <div class="info-row">
                                <div class="info-item">
                                    <div class="info-label">Date of Birth:</div>
                                    <div class="info-value"><?= (new DateTime($vehicleDetails['date_of_birth']))->format('d F Y') ?></div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="validation-text">
                This certificate is an official document issued by the Ghana Driver and Vehicle Licensing Authority.
                The details contained herein are accurate as of the date of issuance. Any alterations render this certificate invalid.
            </div>
            
            <div class="certificate-footer">
                <div class="signature-area">
                    <div class="signature-line"></div>
                    <div class="signature-name">Julius Neequaye Kotey</div>
                    <div class="signature-title">Chief Executive Officer</div>
                    <div class="signature-title">Ghana DVLA</div>
                </div>
                
                <div class="signature-area">
                    <div class="signature-line"></div>
                    <div class="signature-name">Issuing Officer</div>
                    <div class="signature-title">Authorized Signatory</div>
                    <div class="signature-title">Date: <?= $currentDate->format('d F Y') ?></div>
                </div>
            </div>
        </div>
        
        <div class="official-seal">
            OFFICIAL<br>GHANA DVLA<br>SEAL
        </div>
    </div>
    <?php endif; ?>
    
    <div class="print-buttons no-print mt-3 mb-5">
        <button onclick="window.print()" class="btn btn-primary">
            <i class="fas fa-print me-2"></i> Print Certificate
        </button>
        <a href="download-certificate-pdf.php?id=<?= $vehicleId ?>" class="btn btn-success">
            <i class="fas fa-file-pdf me-2"></i> Download PDF
        </a>
        <a href="manage-vehicles.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left me-2"></i> Back to Vehicles
        </a>
    </div>
    
    <script>
        // Set page to portrait for printing
        function adjustForPrinting() {
            var style = document.createElement('style');
            style.type = 'text/css';
            style.media = 'print';
            style.innerHTML = '@page { size: portrait; margin: 1cm; }';
            document.head.appendChild(style);
        }
        
        // Run when the document loads
        document.addEventListener('DOMContentLoaded', adjustForPrinting);
    </script>
</body>
</html>