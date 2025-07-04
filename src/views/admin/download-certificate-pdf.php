<?php
session_start();
require_once __DIR__ . '/../../../config/database.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../views/auth/login.php');
    exit;
}

// Check if TCPDF is installed
if (file_exists(__DIR__ . '/../../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../../vendor/autoload.php';
} else {
    // Direct include for manual installations
    if (file_exists(__DIR__ . '/../../../lib/tcpdf/tcpdf.php')) {
        require_once __DIR__ . '/../../../lib/tcpdf/tcpdf.php';
    } else {
        die('TCPDF library not found. Please install it using Composer with: composer require tecnickcom/tcpdf');
    }
}

// Check if vehicle ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die('Invalid vehicle ID');
}

$vehicleId = $_GET['id'];

// Helper function to determine vehicle use if not explicitly set
function determineVehicleUse($vehicleType) {
    $commercialTypes = ['taxi', 'bus', 'truck', 'commercial'];
    
    if (in_array(strtolower($vehicleType), $commercialTypes)) {
        return 'Commercial';
    }
    
    return 'Private';
}

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
    
    // Build the SQL query based on whether the column exists
    $selectFields = "v.*, o.*, v.id as vehicle_id, o.id as owner_id,
                 v.registration_date as vehicle_reg_date,
                 o.name as owner_name, o.address as owner_address";
                 
    if ($passportPhotoColumnExists) {
        $selectFields .= ", o.passport_photo_path";
    }
    
    // Get vehicle details with owner information
    $stmt = $pdo->prepare("
        SELECT $selectFields
        FROM vehicles v
        JOIN vehicle_owners o ON v.owner_id = o.id
        WHERE v.id = :id
    ");
    $stmt->execute(['id' => $vehicleId]);
    $vehicleDetails = $stmt->fetch();
    
    if (!$vehicleDetails) {
        die('Vehicle not found');
    }
    
    // Format dates
    $regDate = new DateTime($vehicleDetails['vehicle_reg_date']);
    $expiryDate = new DateTime($vehicleDetails['expiry_date']);
    $currentDate = new DateTime();
    
    // Certificate number
    $certificateNumber = 'DVLA-' . date('Y') . '-' . str_pad($vehicleId, 6, '0', STR_PAD_LEFT);
    
    // Get QR code if exists
    $qrCodePath = !empty($vehicleDetails['qr_code_path']) ? __DIR__ . '/../../../' . $vehicleDetails['qr_code_path'] : '';
    
    // Fix for passport photo - improved path handling
    $passportPhotoPath = '';
    $hasPassportPhoto = false;
    if ($passportPhotoColumnExists && !empty($vehicleDetails['passport_photo_path'])) {
        // First try with the original path
        $path1 = __DIR__ . '/../../../' . $vehicleDetails['passport_photo_path'];
        $path2 = __DIR__ . '/../../../' . ltrim($vehicleDetails['passport_photo_path'], '/');
        $path3 = $vehicleDetails['passport_photo_path']; // Try absolute path if it was stored that way
        
        if (file_exists($path1) && is_readable($path1)) {
            $passportPhotoPath = $path1;
            $hasPassportPhoto = true;
        } elseif (file_exists($path2) && is_readable($path2)) {
            $passportPhotoPath = $path2;
            $hasPassportPhoto = true;
        } elseif (file_exists($path3) && is_readable($path3)) {
            $passportPhotoPath = $path3;
            $hasPassportPhoto = true;
        }
    }
    
    // Create new PDF document (portrait orientation)
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator('Ghana DVLA');
    $pdf->SetAuthor('Ghana DVLA');
    $pdf->SetTitle('Vehicle Registration Certificate - ' . $vehicleDetails['registration_number']);
    $pdf->SetSubject('Vehicle Registration Certificate');
    
    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Set margins
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 25);
    
    // Add a page
    $pdf->AddPage();
    
    // Ghana flag colors
    $pdf->SetFillColor(206, 17, 38); // Red
    $pdf->Rect(15, 15, 60, 5, 'F');
    $pdf->SetFillColor(252, 209, 22); // Yellow/Gold
    $pdf->Rect(75, 15, 60, 5, 'F');
    $pdf->SetFillColor(0, 107, 63); // Green
    $pdf->Rect(135, 15, 60, 5, 'F');
    
    // Logo path - check if exists and add it
    $logoPath = __DIR__ . '/../../../assets/img/dvla.png';
    if (file_exists($logoPath)) {
        $pdf->Image($logoPath, 15, 25, 30, 0, '', '', '', false, 300);
    } else {
        // If image doesn't exist, add text placeholder
        $pdf->SetXY(15, 25);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(30, 10, 'DVLA LOGO', 1, 0, 'C');
    }
    
    // Add coat of arms at the center
    $coatOfArmsPath = __DIR__ . '/../../../assets/img/GoG.png';
    if (file_exists($coatOfArmsPath)) {
        $pdf->Image($coatOfArmsPath, 85, 25, 30, 0, '', '', '', false, 300);
    } else {
        // If image doesn't exist, add text placeholder
        $pdf->SetXY(85, 25);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(30, 10, 'COAT OF ARMS', 1, 0, 'C');
    }
    
    // Add QR code if available - positioned in top right corner
    if (!empty($qrCodePath) && file_exists($qrCodePath)) {
        $pdf->Image($qrCodePath, 155, 25, 25, 0, 'PNG');
        $pdf->SetXY(155, 51);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->Cell(25, 5, 'Scan to verify', 0, 1, 'C');
    }
    
    // Header
    $pdf->SetY(60);
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'REPUBLIC OF GHANA', 0, 1, 'C');
    
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 8, 'DRIVER AND VEHICLE LICENSING AUTHORITY', 0, 1, 'C');
    
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 8, 'VEHICLE REGISTRATION CERTIFICATE', 0, 1, 'C');
    
    // Certificate number
    $pdf->SetY($pdf->GetY() + 3);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 8, 'Certificate Number: ' . $certificateNumber, 0, 1, 'L');
    
    // Divider
    $pdf->Line(15, $pdf->GetY() + 2, 195, $pdf->GetY() + 2);
    $pdf->SetY($pdf->GetY() + 8);
    
    // Add watermark in the background
    $pdf->SetAlpha(0.07);
    $watermarkPath = __DIR__ . '/../../../assets/img/logo.png';
    if (file_exists($watermarkPath)) {
        $pdf->Image($watermarkPath, 65, 130, 80, 0, 'PNG');
    }
    $pdf->SetAlpha(1);
    
    // Start content area
    $startY = $pdf->GetY();
    
    // Define photo dimensions
    $photoX = 15;
    $photoY = $startY;
    $photoWidth = 40;
    $photoHeight = 45;
    
    // Add passport photo if available with improved error handling
    if ($hasPassportPhoto) {
        // Draw border
        $pdf->SetDrawColor(10, 61, 98);
        $pdf->Rect($photoX, $photoY, $photoWidth, $photoHeight, 'D');
        
        // Try to determine image type
        $imgType = '';
        $imgInfo = @getimagesize($passportPhotoPath);
        if ($imgInfo) {
            switch($imgInfo[2]) {
                case IMAGETYPE_JPEG:
                    $imgType = 'JPEG';
                    break;
                case IMAGETYPE_PNG:
                    $imgType = 'PNG';
                    break;
                case IMAGETYPE_GIF:
                    $imgType = 'GIF';
                    break;
            }
        }
        
        // Insert the photo with error handling
        try {
            $pdf->Image($passportPhotoPath, $photoX, $photoY, $photoWidth, $photoHeight, $imgType);
        } catch (Exception $e) {
            // If image loading fails, create a placeholder
            $pdf->SetFillColor(240, 240, 240);
            $pdf->Rect($photoX, $photoY, $photoWidth, $photoHeight, 'DF');
            $pdf->SetXY($photoX, $photoY + 20);
            $pdf->SetFont('helvetica', '', 8);
            $pdf->Cell($photoWidth, 5, 'Photo Not Available', 0, 1, 'C');
        }
        
        // Add label
        $pdf->SetY($photoY + $photoHeight + 1);
        $pdf->SetX($photoX);
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->Cell($photoWidth, 5, 'OWNER PHOTO', 0, 1, 'C');
        
        // Vehicle details will start to the right of the photo
        $vehicleDetailsX = $photoX + $photoWidth + 5;
        $vehicleDetailsWidth = 180 - $vehicleDetailsX + 15; // Page width minus start position plus left margin
    } else {
        // If no photo, vehicle details use the full width
        $vehicleDetailsX = 15;
        $vehicleDetailsWidth = 180;
        $photoHeight = 0; // No photo height to consider
    }
    
    // Set position for vehicle details
    $pdf->SetY($startY);
    $pdf->SetX($vehicleDetailsX);
    
    // Vehicle Details Section
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetFillColor(10, 61, 98);
    $pdf->SetTextColor(255);
    $pdf->Cell($vehicleDetailsWidth, 8, 'VEHICLE DETAILS', 0, 1, 'L', true);
    $pdf->SetTextColor(0);
    
    // Add space after heading
    $pdf->SetY($pdf->GetY() + 2);
    
    // Calculate column widths for balanced layout
    $leftColWidth = 40;
    $valueColWidth = $vehicleDetailsWidth / 2 - $leftColWidth;
    
    $detailsStartY = $pdf->GetY();
    
    // First column of vehicle details
    $pdf->SetXY($vehicleDetailsX, $detailsStartY);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell($leftColWidth, 7, 'Registration Number:', 0, 0);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell($valueColWidth, 7, $vehicleDetails['registration_number'], 0, 1);
    
    $pdf->SetX($vehicleDetailsX);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell($leftColWidth, 7, 'Make:', 0, 0);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell($valueColWidth, 7, $vehicleDetails['make'], 0, 1);
    
    $pdf->SetX($vehicleDetailsX);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell($leftColWidth, 7, 'Model:', 0, 0);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell($valueColWidth, 7, $vehicleDetails['model'], 0, 1);
    
    $pdf->SetX($vehicleDetailsX);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell($leftColWidth, 7, 'Year of Manufacture:', 0, 0);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell($valueColWidth, 7, $vehicleDetails['year_of_manufacture'], 0, 1);
    
    // Reset for second column if space allows
    if ($vehicleDetailsWidth > 100) { // Check if we have enough space for two columns
        // Second column
        $secondColX = $vehicleDetailsX + $leftColWidth + $valueColWidth + 10;
        
        $pdf->SetXY($secondColX, $detailsStartY);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell($leftColWidth, 6, 'Engine Number:', 0, 0);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell($valueColWidth, 6, $vehicleDetails['engine_number'], 0, 1);
        
        $pdf->SetXY($secondColX, $pdf->GetY());
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell($leftColWidth, 4, 'Chassis Number:', 0, 0);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell($valueColWidth, 2, $vehicleDetails['chassis_number'], 0, 1);
        
        $pdf->SetXY($secondColX, $pdf->GetY());
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell($leftColWidth, 6, 'Vehicle Type:', 0, 0);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell($valueColWidth, 6, $vehicleDetails['vehicle_type'], 0, 1);
        
        // Add Vehicle Use after Vehicle Type
        $pdf->SetXY($secondColX, $pdf->GetY());
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell($leftColWidth, 6, 'Vehicle Use:', 0, 0);
        $pdf->SetFont('helvetica', '', 10);
        $vehicleUse = isset($vehicleDetails['vehicle_use']) && !empty($vehicleDetails['vehicle_use']) 
            ? $vehicleDetails['vehicle_use'] 
            : determineVehicleUse($vehicleDetails['vehicle_type']);
        $pdf->Cell($valueColWidth, 6, $vehicleUse, 0, 1);
        
        $pdf->SetXY($secondColX, $pdf->GetY());
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell($leftColWidth, 6, 'Color:', 0, 0);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell($valueColWidth, 6, $vehicleDetails['color'], 0, 1);
    } else {
        // If space is limited, continue in a single column
        $pdf->SetX($vehicleDetailsX);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell($leftColWidth, 6, 'Engine Number:', 0, 0);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell($valueColWidth, 6, $vehicleDetails['engine_number'], 0, 1);
        
        $pdf->SetX($vehicleDetailsX);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell($leftColWidth, 6, 'Chassis Number:', 0, 0);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell($valueColWidth, 6, $vehicleDetails['chassis_number'], 0, 1);
        
        $pdf->SetX($vehicleDetailsX);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell($leftColWidth, 6, 'Vehicle Type:', 0, 0);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell($valueColWidth, 6, $vehicleDetails['vehicle_type'], 0, 1);
        
        // Add Vehicle Use after Vehicle Type
        $pdf->SetX($vehicleDetailsX);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell($leftColWidth, 6, 'Vehicle Use:', 0, 0);
        $pdf->SetFont('helvetica', '', 10);
        $vehicleUse = isset($vehicleDetails['vehicle_use']) && !empty($vehicleDetails['vehicle_use']) 
            ? $vehicleDetails['vehicle_use'] 
            : determineVehicleUse($vehicleDetails['vehicle_type']);
        $pdf->Cell($valueColWidth, 6, $vehicleUse, 0, 1);
    }
    
    // Reset to next section - calculate where to put the registration dates
    // Find maximum Y position between photo and vehicle details
    $maxY = max(
        ($hasPassportPhoto) ? $photoY + $photoHeight + 8 : $startY,
        $pdf->GetY() + 2
    );
    
    // Registration dates
    $pdf->SetY($maxY);
    $pdf->SetX(15);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(40, 7, 'Registration Date:', 0, 0);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(60, 7, $regDate->format('d F Y'), 0, 0);
    
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(40, 7, 'Expiry Date:', 0, 0);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(40, 7, $expiryDate->format('d F Y'), 0, 1);
    
    // Owner Details Section - use full width
    $pdf->SetY($pdf->GetY() + 8);
    $pdf->SetX(15);
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetFillColor(10, 61, 98);
    $pdf->SetTextColor(255);
    $pdf->Cell(180, 8, 'OWNER DETAILS', 0, 1, 'L', true);
    $pdf->SetTextColor(0);
    
    // Add space after heading
    $pdf->SetY($pdf->GetY() + 2);
    
    // Two-column layout for owner details
    $ownerY = $pdf->GetY();
    
    // Left column of owner details
    $pdf->SetXY(15, $ownerY);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(40, 7, 'Owner Name:', 0, 0);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(50, 7, $vehicleDetails['owner_name'], 0, 1);
    
    $pdf->SetX(15);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(40, 7, 'Ghana Card Number:', 0, 0);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(50, 7, $vehicleDetails['ghana_card_number'], 0, 1);
    
    // Truncate address if too long to prevent overflow
    $address = $vehicleDetails['owner_address'];
    if (strlen($address) > 40) {
        $address = substr($address, 0, 37) . '...';
    }
    
    $pdf->SetX(15);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(40, 7, 'Address:', 0, 0);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(50, 7, $address, 0, 1);
    
    // Right column of owner details
    $pdf->SetXY(105, $ownerY);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(40, 7, 'Phone Number:', 0, 0);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(50, 7, $vehicleDetails['phone'], 0, 1);
    
    // Only show email if it exists
    if (!empty($vehicleDetails['email'])) {
        // Truncate email if too long to prevent overflow
        $email = $vehicleDetails['email'];
        if (strlen($email) > 30) {
            $email = substr($email, 0, 27) . '...';
        }
        
        $pdf->SetXY(105, $pdf->GetY());
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(40, 7, 'Email:', 0, 0);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(50, 7, $email, 0, 1);
    }
    
    // Only show date of birth if it exists
    if (!empty($vehicleDetails['date_of_birth'])) {
        $pdf->SetXY(105, $pdf->GetY());
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(40, 7, 'Date of Birth:', 0, 0);
        $pdf->SetFont('helvetica', '', 10);
        $birthDate = new DateTime($vehicleDetails['date_of_birth']);
        $pdf->Cell(50, 7, $birthDate->format('d F Y'), 0, 1);
    }
    
    // Calculate space remaining on page for validation text and signatures
    $availableSpace = $pdf->getPageHeight() - $pdf->GetY() - $pdf->getBreakMargin();
    
    // If less than 65mm is available, add a new page
    if ($availableSpace < 65) {
        $pdf->AddPage();
    } else {
        // Add space before validation text
        $pdf->SetY($pdf->GetY() + 10);
    }
    
    // Validation text
    $pdf->SetFont('helvetica', 'I', 9);
    $pdf->MultiCell(0, 5, 'This certificate is an official document issued by the Ghana Driver and Vehicle Licensing Authority. 
The details contained herein are accurate as of the date of issuance. Any alterations render this certificate invalid.', 0, 'C');
    
    // Calculate proper spacing for signatures
    $signatureY = $pdf->GetY() + 15;
    
    // Signature lines
    $pdf->Line(25, $signatureY, 85, $signatureY);
    $pdf->Line(125, $signatureY, 185, $signatureY);
    
    // Left signature area
    $pdf->SetXY(25, $signatureY + 3);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(60, 5, 'Julius Neequaye Kotey', 0, 1, 'C');
    
    $pdf->SetXY(25, $pdf->GetY() + 1);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell(60, 4, 'Chief Executive Officer', 0, 1, 'C');
    
    $pdf->SetXY(25, $pdf->GetY() + 1);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell(60, 4, 'Ghana DVLA', 0, 1, 'C');
    
    // Right signature area
    $pdf->SetXY(125, $signatureY + 3);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(60, 5, 'Issuing Officer', 0, 1, 'C');
    
    $pdf->SetXY(125, $pdf->GetY() + 1);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell(60, 4, 'Authorized Signatory', 0, 1, 'C');
    
    $pdf->SetXY(125, $pdf->GetY() + 1);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell(60, 4, 'Date: ' . $currentDate->format('d F Y'), 0, 1, 'C');
    
    // Add official seal - check if we're on first or second page
    $sealY = ($pdf->getPage() == 1) ? 230 : 130;
    $sealX = 155;
    
    $pdf->SetDrawColor(10, 61, 98);
    $pdf->SetAlpha(0.7);
    $pdf->Ellipse($sealX, $sealY, 20, 20, 0, 0, 360, '', array('width' => 1, 'dash' => 0), array(10, 61, 98));
    $pdf->SetAlpha(1);
    
    // Add text to seal
    $pdf->StartTransform();
    $pdf->Rotate(-15, $sealX, $sealY);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColor(10, 61, 98);
    $pdf->MultiCell(40, 5, "OFFICIAL\nGHANA DVLA\nSEAL", 0, 'C', false, 1, $sealX - 20, $sealY - 10);
    $pdf->StopTransform();
    
    // Create output directory for PDF
    $outputDir = __DIR__ . '/../../../uploads/certificates/';
    if (!is_dir($outputDir)) {
        if (!mkdir($outputDir, 0777, true)) {
            error_log("Warning: Unable to create certificates directory");
        }
    }
    
    // Certificate file name
    $certificatePdfFileName = 'certificate_' . $vehicleDetails['registration_number'] . '_' . time() . '.pdf';
    $certificateRelativePath = 'uploads/certificates/' . $certificatePdfFileName;
    
    // Output file name for download
    $fileName = 'DVLA_Certificate_' . $vehicleDetails['registration_number'] . '.pdf';
    
    // Update the database with certificate path
    try {
        $stmtUpdate = $pdo->prepare("UPDATE vehicles SET certificate_pdf_path = :path WHERE id = :id");
        $stmtUpdate->execute([
            'path' => $certificateRelativePath,
            'id' => $vehicleId
        ]);
    } catch (PDOException $e) {
        error_log("Error updating certificate path: " . $e->getMessage());
    }
    
    // Output PDF to browser for download
    $pdf->Output($fileName, 'D'); // 'D' means download
    
} catch (PDOException $e) {
    die('Database error: ' . $e->getMessage());
} catch (Exception $e) {
    die('Error generating PDF: ' . $e->getMessage());
}