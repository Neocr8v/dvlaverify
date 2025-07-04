<?php
// filepath: /Applications/XAMPP/xamppfiles/htdocs/dvlaregister/src/views/user/download-qr.php
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../vendor/autoload.php'; // For TCPDF

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

// Check if vehicle ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Invalid vehicle ID");
}

$vehicleId = $_GET['id'];
$userId = $_SESSION['user_id'];

try {
    // First verify the user has access to this vehicle
    $userStmt = $pdo->prepare("
        SELECT u.*, vo.id AS owner_id 
        FROM users u
        LEFT JOIN vehicle_owners vo ON (
            LOWER(u.full_name) = LOWER(vo.name)
            OR (u.email IS NOT NULL AND LOWER(vo.email) = LOWER(u.email))
        )
        WHERE u.id = :user_id
    ");
    $userStmt->execute(['user_id' => $userId]);
    $user = $userStmt->fetch();
    
    if (!$user) {
        die("User not found");
    }
    
    // Get vehicle and owner details
    $stmt = $pdo->prepare("
        SELECT v.*, o.name as owner_name
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
    
    $vehicle = $stmt->fetch();
    
    if (!$vehicle) {
        die("Vehicle not found or you don't have permission to access it");
    }
    
    // Check if QR code exists
    if (empty($vehicle['qr_code_path'])) {
        die("QR code not available for this vehicle");
    }
    
    // Get the QR code file path
    $qrCodeFilePath = __DIR__ . '/../../../' . $vehicle['qr_code_path'];
    
    if (!file_exists($qrCodeFilePath)) {
        die("QR code file not found");
    }
    
    // Load TCPDF library
    require_once(__DIR__ . '/../../../vendor/tecnickcom/tcpdf/tcpdf.php');
    
    // Create new PDF document
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator('DVLA Ghana');
    $pdf->SetAuthor('DVLA Vehicle Registration System');
    $pdf->SetTitle('Vehicle QR Code');
    $pdf->SetSubject('QR Code for ' . $vehicle['registration_number']);
    
    // Remove header and footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Set margins
    $pdf->SetMargins(15, 15, 15);
    
    // Set auto page breaks
    $pdf->SetAutoPageBreak(true, 15);
    
    // Add a page
    $pdf->AddPage();
    
        // Add DVLA logo at top-left corner with reduced size - FIXED POSITION
    $logoPath = __DIR__ . '/../../../assets/img/dvla.png';
    if (file_exists($logoPath)) {
        $pdf->Image($logoPath, 15, 10, 25); // Moved up and reduced size further
    }
    
    // Set font and add title (moved to right to make room for logo)
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->SetXY(45, 10); // Adjusted X and Y position
    $pdf->Cell(0, 8, 'Vehicle QR Code', 0, 1, 'L'); // Reduced height
    
    $pdf->SetXY(45, 18); // Adjusted Y position
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(0, 8, 'Ghana Driver and Vehicle Licensing Authority', 0, 1, 'L'); // Reduced height
    
    // Add horizontal line to separate header - MOVED DOWN
    $pdf->SetY(35); // Moved down to ensure no overlap with logo
    $pdf->SetDrawColor(200, 200, 200);
    $pageWidth = $pdf->getPageWidth();
    $pdf->Line(15, $pdf->GetY(), $pageWidth - 15, $pdf->GetY());
    $pdf->Ln(5);
    
    // Add vehicle details in a more compact format
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 8, 'Vehicle Information', 0, 1);
    $pdf->Ln(2);
    
    // Create a more compact two-column layout for vehicle details
    $pdf->SetFont('helvetica', '', 10);
    
    // Left column
    $leftX = 15;
    $rightX = $pageWidth/2;
    $lineHeight = 7;
    
    // Row 1
    $pdf->SetXY($leftX, $pdf->GetY());
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(40, $lineHeight, 'Registration Number:', 0);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(40, $lineHeight, $vehicle['registration_number'], 0);
    
    $pdf->SetXY($rightX, $pdf->GetY());
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(40, $lineHeight, 'Owner:', 0);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(40, $lineHeight, $vehicle['owner_name'], 0);
    $pdf->Ln($lineHeight);
    
    // Row 2
    $pdf->SetXY($leftX, $pdf->GetY());
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(40, $lineHeight, 'Make & Model:', 0);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(40, $lineHeight, $vehicle['make'] . ' ' . $vehicle['model'], 0);
    
    $pdf->SetXY($rightX, $pdf->GetY());
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(40, $lineHeight, 'Year of Manufacture:', 0);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(40, $lineHeight, $vehicle['year_of_manufacture'], 0);
    $pdf->Ln($lineHeight);
    
    // Row 3
    $pdf->SetXY($leftX, $pdf->GetY());
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(40, $lineHeight, 'Chassis Number:', 0);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(40, $lineHeight, $vehicle['chassis_number'], 0);
    
    $pdf->SetXY($rightX, $pdf->GetY());
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(40, $lineHeight, 'Color:', 0);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(40, $lineHeight, $vehicle['color'], 0);
    $pdf->Ln($lineHeight);
    
    // Row 4
    $pdf->SetXY($leftX, $pdf->GetY());
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(40, $lineHeight, 'Registration Date:', 0);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(40, $lineHeight, date('d F Y', strtotime($vehicle['registration_date'])), 0);
    
    $pdf->SetXY($rightX, $pdf->GetY());
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(40, $lineHeight, 'Expiry Date:', 0);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(40, $lineHeight, date('d F Y', strtotime($vehicle['expiry_date'])), 0);
    $pdf->Ln($lineHeight+3);
    
    // Add separator line
    $pdf->SetDrawColor(230, 230, 230);
    $pdf->Line(15, $pdf->GetY(), $pageWidth - 15, $pdf->GetY());
    $pdf->Ln(5);
    
    // Add QR Code - centered
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'Vehicle QR Code', 0, 1, 'C');
    $pdf->Ln(2);
    
    // Center the QR code
    $qrSize = 75; // Slightly reduced size
    $qrX = ($pageWidth - $qrSize) / 2;
    
    $pdf->Image($qrCodeFilePath, $qrX, $pdf->GetY(), $qrSize, $qrSize);
    
    // Add scan instructions
    $pdf->SetY($pdf->GetY() + $qrSize + 5); // Reduced spacing
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 6, 'Scan to verify vehicle registration', 0, 1, 'C');
    
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(0, 6, 'This QR code can be scanned by law enforcement and DVLA officers to verify vehicle information.', 0, 1, 'C');
    
    // Add footer note with less spacing
    $pdf->Ln(2);
    $pdf->SetDrawColor(200, 200, 200);
    $pdf->Line(15, $pdf->GetY(), $pageWidth - 15, $pdf->GetY());
    $pdf->Ln(3);
    
    $pdf->SetFont('helvetica', 'I', 9);
    $pdf->Cell(0, 5, 'Generated on: ' . date('d F Y'), 0, 1);
    $pdf->Cell(0, 5, 'DVLA Ghana - Official Vehicle Registration Document', 0, 1);
    $pdf->Cell(0, 5, 'For inquiries, contact: info@dvla.gov.gh | 0302-746-760', 0, 1);
    
    // Output PDF
    $pdfFileName = 'Vehicle_QR_Code_' . $vehicle['registration_number'] . '.pdf';
    $pdf->Output($pdfFileName, 'D');
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>