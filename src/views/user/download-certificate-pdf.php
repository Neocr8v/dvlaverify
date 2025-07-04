<?php
// filepath: /Applications/XAMPP/xamppfiles/htdocs/dvlaregister/src/views/user/download-certificate-pdf.php
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../vendor/autoload.php'; // For TCPDF

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../views/auth/login.php');
    exit;
}

// Check if vehicle ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Invalid vehicle ID");
}

$vehicleId = $_GET['id'];
$userId = $_SESSION['user_id'];
$vehicleDetails = null;

try {
    // First verify the user has access to this vehicle
    $userStmt = $pdo->prepare("
        SELECT u.*, vo.id AS owner_id 
        FROM users u
        LEFT JOIN vehicle_owners vo ON (
            LOWER(u.full_name) = LOWER(vo.name)
            OR (u.email IS NOT NULL AND LOWER(u.email) = LOWER(vo.email))
        )
        WHERE u.id = :user_id
    ");
    $userStmt->execute(['user_id' => $userId]);
    $user = $userStmt->fetch();
    
    if (!$user) {
        die("User not found");
    }
    
    // Check if passport_photo_path column exists
    $passportPhotoColumnExists = false;
    try {
        $checkColumnStmt = $pdo->prepare("SHOW COLUMNS FROM vehicle_owners LIKE 'passport_photo_path'");
        $checkColumnStmt->execute();
        $passportPhotoColumnExists = ($checkColumnStmt->rowCount() > 0);
    } catch (PDOException $e) {
        // Ignore error and continue without passport photo
    }
    
    // Build the SQL query based on whether the column exists
    $selectFields = "v.*, o.*, v.id as vehicle_id, o.id as owner_id,
             v.registration_date as vehicle_reg_date,
             o.name as owner_name, o.address as owner_address";
             
    if ($passportPhotoColumnExists) {
        $selectFields .= ", o.passport_photo_path";
    }
    
    $stmt = $pdo->prepare("
        SELECT $selectFields
        FROM vehicles v
        JOIN vehicle_owners o ON v.owner_id = o.id
        WHERE v.id = :id AND (
            o.id = :owner_id
            OR (
                LOWER(o.name) = LOWER(:user_name)
                OR (o.email IS NOT NULL AND LOWER(o.email) = LOWER(:user_email))
            )
        )
    ");
    
    $stmt->execute([
        'id' => $vehicleId,
        'owner_id' => $user['owner_id'],
        'user_name' => $user['full_name'],
        'user_email' => $user['email'] ?? ''
    ]);
    
    $vehicleDetails = $stmt->fetch();
    
    if (!$vehicleDetails) {
        die("Vehicle not found or you don't have permission to view it");
    }
    
    // Generate certificate number
    $certificateNumber = 'DVLA-' . date('Y') . '-' . str_pad($vehicleId, 6, '0', STR_PAD_LEFT);
    
    // Format dates
    $regDate = new DateTime($vehicleDetails['vehicle_reg_date']);
    $expiryDate = new DateTime($vehicleDetails['expiry_date']);
    $currentDate = new DateTime();

    // Create PDF with TCPDF
    require_once(__DIR__ . '/../../../vendor/tecnickcom/tcpdf/tcpdf.php');
    
    // Create custom PDF class to add header/footer
    class MYPDF extends TCPDF {
        public function Header() {
            // Flag colors bar
            $this->SetFillColor(206, 17, 38); // Red
            $this->Rect(10, 10, 63, 5, 'F');
            $this->SetFillColor(252, 209, 22); // Gold/Yellow
            $this->Rect(73, 10, 63, 5, 'F');
            $this->SetFillColor(0, 107, 63); // Green
            $this->Rect(136, 10, 63, 5, 'F');
            
            // Add logos
            $this->Image(__DIR__ . '/../../../assets/img/dvla.png', 15, 20, 30);
            $this->Image(__DIR__ . '/../../../assets/img/GoG.png', 95, 18, 20);
            
            $this->SetY(40);
            $this->SetFont('helvetica', 'B', 15);
            $this->Cell(0, 10, 'REPUBLIC OF GHANA', 0, false, 'C');
            $this->Ln(7);
            $this->SetFont('helvetica', '', 12);
            $this->Cell(0, 10, 'DRIVER AND VEHICLE LICENSING AUTHORITY', 0, false, 'C');
            $this->Ln(7);
            $this->SetFont('helvetica', 'B', 13);
            $this->Cell(0, 10, 'VEHICLE REGISTRATION CERTIFICATE', 0, false, 'C');
            $this->Line(10, 65, 199, 65);
        }
        
        public function Footer() {
            $this->SetY(-25);
            $this->SetFont('helvetica', 'I', 8);
            $this->Cell(0, 10, 'This certificate is an official document issued by the Ghana Driver and Vehicle Licensing Authority.', 0, false, 'C');
            $this->Ln(4);
            $this->Cell(0, 10, 'The details contained herein are accurate as of the date of issuance. Any alterations render this certificate invalid.', 0, false, 'C');
            $this->Ln(4);
            $this->Cell(0, 10, 'Page '.$this->getAliasNumPage().'/'.$this->getAliasNbPages(), 0, false, 'C');
        }
    }

    // Create new PDF document
    $pdf = new MYPDF('P', 'mm', 'A4', true, 'UTF-8', false);

    // Set document information
    $pdf->SetCreator('DVLA Ghana');
    $pdf->SetAuthor('DVLA');
    $pdf->SetTitle('Vehicle Registration Certificate');
    $pdf->SetSubject('Vehicle Registration Certificate for ' . $vehicleDetails['registration_number']);

    // Set default header/footer data
    $pdf->setHeaderFont(Array('helvetica', '', 10));
    $pdf->setFooterFont(Array('helvetica', '', 8));

    // Set margins
    $pdf->SetMargins(10, 70, 10);
    $pdf->SetHeaderMargin(10);
    $pdf->SetFooterMargin(10);

    // Set auto page breaks
    $pdf->SetAutoPageBreak(TRUE, 25);

    // Add a page
    $pdf->AddPage();
    
    // Add certificate number
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 10, 'Certificate Number: ' . $certificateNumber, 0, 1);
    $pdf->Ln(5);
    
    // Add watermark
    $pdf->SetAlpha(0.07);
    $pdf->Image(__DIR__ . '/../../../assets/img/logo.png', 40, 100, 130);
    $pdf->SetAlpha(1);
    
    // Add QR code if available
    if (!empty($vehicleDetails['qr_code_path'])) {
        $pdf->Image(__DIR__ . '/../../../' . $vehicleDetails['qr_code_path'], 170, 75, 25);
        $pdf->SetXY(170, 101);
        $pdf->SetFont('helvetica', '', 7);
        $pdf->Cell(25, 5, 'Scan to verify', 0, 1, 'C');
    }
    
    // Start content structure - two columns for photo and details
    $pdf->Ln(5);
    $leftX = 15;
    $contentX = 50;
    $contentWidth = 145;
    
    // Add owner photo if available
    if (isset($vehicleDetails['passport_photo_path']) && !empty($vehicleDetails['passport_photo_path'])) {
        $photoPath = __DIR__ . '/../../../' . $vehicleDetails['passport_photo_path'];
        if (file_exists($photoPath)) {
            $pdf->Image($photoPath, 15, $pdf->GetY(), 30, 35, '', '', '', true, 300);
        } else {
            // If file doesn't exist, draw a placeholder box
            $pdf->Rect(15, $pdf->GetY(), 30, 35);
            $pdf->SetXY(15, $pdf->GetY() + 15);
            $pdf->Cell(30, 5, 'No Photo', 0, 1, 'C');
        }
    } else {
        // No photo path, draw a placeholder box
        $pdf->Rect(15, $pdf->GetY(), 30, 35);
        $pdf->SetXY(15, $pdf->GetY() + 15);
        $pdf->Cell(30, 5, 'No Photo', 0, 1, 'C');
    }
    
    // Owner photo label
    $pdf->SetXY(15, $pdf->GetY() + 35);
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->Cell(30, 5, 'OWNER PHOTO', 0, 1, 'C');
    
    // Reset position for vehicle details content
    $pdf->SetXY($contentX, $pdf->GetY() - 40);
    
    // Vehicle Details Section
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell($contentWidth, 10, 'VEHICLE DETAILS', 0, 1);
    $pdf->Line($contentX, $pdf->GetY(), $contentX + $contentWidth, $pdf->GetY());
    $pdf->Ln(2);
    
    // Function to add information rows
    function addInfoRow($pdf, $label, $value, $x, $width, $labelWidth = 50) {
        $pdf->SetX($x);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell($labelWidth, 6, $label . ':', 0, 0);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell($width - $labelWidth, 6, $value, 0, 1);
    }
    
    // Two columns of details
    $columnWidth = $contentWidth / 2;
    
    // First row
    addInfoRow($pdf, 'Registration Number', $vehicleDetails['registration_number'], $contentX, $columnWidth);
    $pdf->SetXY($contentX + $columnWidth, $pdf->GetY() - 6);
    addInfoRow($pdf, 'Vehicle Type', $vehicleDetails['vehicle_type'], $contentX + $columnWidth, $columnWidth);
    
    // Second row
    addInfoRow($pdf, 'Make', $vehicleDetails['make'], $contentX, $columnWidth);
    $pdf->SetXY($contentX + $columnWidth, $pdf->GetY() - 6);
    addInfoRow($pdf, 'Model', $vehicleDetails['model'], $contentX + $columnWidth, $columnWidth);
    
    // Third row
    addInfoRow($pdf, 'Year of Manufacture', $vehicleDetails['year_of_manufacture'], $contentX, $columnWidth);
    $pdf->SetXY($contentX + $columnWidth, $pdf->GetY() - 6);
    addInfoRow($pdf, 'Color', $vehicleDetails['color'], $contentX + $columnWidth, $columnWidth);
    
    // Fourth row
    addInfoRow($pdf, 'Engine Number', $vehicleDetails['engine_number'], $contentX, $columnWidth);
    $pdf->SetXY($contentX + $columnWidth, $pdf->GetY() - 6);
    addInfoRow($pdf, 'Chassis Number', $vehicleDetails['chassis_number'], $contentX + $columnWidth, $columnWidth);
    
    // Fifth row
    $engineCapacity = $vehicleDetails['engine_capacity'] ? $vehicleDetails['engine_capacity'] . ' cc' : 'N/A';
    addInfoRow($pdf, 'Engine Capacity', $engineCapacity, $contentX, $columnWidth);
    $pdf->SetXY($contentX + $columnWidth, $pdf->GetY() - 6);
    addInfoRow($pdf, 'Seating Capacity', $vehicleDetails['seating_capacity'], $contentX + $columnWidth, $columnWidth);
    
    // Sixth row
    addInfoRow($pdf, 'Registration Date', $regDate->format('d F Y'), $contentX, $columnWidth);
    $pdf->SetXY($contentX + $columnWidth, $pdf->GetY() - 6);
    addInfoRow($pdf, 'Expiry Date', $expiryDate->format('d F Y'), $contentX + $columnWidth, $columnWidth);
    
    $pdf->Ln(10);
    
    // Owner Details Section
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'OWNER DETAILS', 0, 1);
    $pdf->Line(10, $pdf->GetY(), 199, $pdf->GetY());
    $pdf->Ln(2);
    
    // First row
    addInfoRow($pdf, 'Owner Name', $vehicleDetails['owner_name'], 10, 90);
    $pdf->SetXY(100, $pdf->GetY() - 6);
    addInfoRow($pdf, 'Ghana Card Number', $vehicleDetails['ghana_card_number'], 100, 90);
    
    // Second row
    addInfoRow($pdf, 'Phone Number', $vehicleDetails['phone'], 10, 90);
    $pdf->SetXY(100, $pdf->GetY() - 6);
    $email = !empty($vehicleDetails['email']) ? $vehicleDetails['email'] : 'N/A';
    addInfoRow($pdf, 'Email', $email, 100, 90);
    
    // Third row
    addInfoRow($pdf, 'Address', $vehicleDetails['owner_address'], 10, 180);
    
    // Date of Birth if available
    if (!empty($vehicleDetails['date_of_birth'])) {
        $dob = new DateTime($vehicleDetails['date_of_birth']);
        addInfoRow($pdf, 'Date of Birth', $dob->format('d F Y'), 10, 90);
    }
    
    $pdf->Ln(20);
    
    // Add signature lines
    $pdf->Line(35, $pdf->GetY(), 85, $pdf->GetY());
    $pdf->Line(125, $pdf->GetY(), 175, $pdf->GetY());
    
    $pdf->SetXY(35, $pdf->GetY() + 1);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(50, 6, 'Julius Neequaye Kotey', 0, 0, 'C');
    
    $pdf->SetXY(125, $pdf->GetY());
    $pdf->Cell(50, 6, 'Issuing Officer', 0, 1, 'C');
    
    $pdf->SetXY(35, $pdf->GetY());
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(50, 5, 'Chief Executive Officer', 0, 0, 'C');
    
    $pdf->SetXY(125, $pdf->GetY());
    $pdf->Cell(50, 5, 'Authorized Signatory', 0, 1, 'C');
    
    $pdf->SetXY(35, $pdf->GetY());
    $pdf->Cell(50, 5, 'Ghana DVLA', 0, 0, 'C');
    
    $pdf->SetXY(125, $pdf->GetY());
    $pdf->Cell(50, 5, 'Date: ' . $currentDate->format('d F Y'), 0, 1, 'C');
    
    // Add official seal
    $pdf->SetXY(150, 220);
    $pdf->StartTransform();
    $pdf->Rotate(-15, 170, 230);
    $pdf->SetLineWidth(0.5);
    $pdf->SetDrawColor(10, 61, 98);
    $pdf->Circle(170, 230, 15);
    $pdf->SetXY(155, 225);
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetTextColor(10, 61, 98);
    $pdf->Cell(30, 5, 'OFFICIAL', 0, 1, 'C');
    $pdf->SetXY(155, 230);
    $pdf->Cell(30, 5, 'GHANA DVLA', 0, 1, 'C');
    $pdf->SetXY(155, 235);
    $pdf->Cell(30, 5, 'SEAL', 0, 1, 'C');
    $pdf->StopTransform();
    
    // Output PDF
    $fileName = 'Vehicle_Registration_Certificate_' . $vehicleDetails['registration_number'] . '.pdf';
    $pdf->Output($fileName, 'D');
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>