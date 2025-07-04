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
    header('Location: dashboard.php');
    exit;
}

$vehicleId = $_GET['id'];
$forceFresh = isset($_GET['fresh']) && $_GET['fresh'] == '1';

try {
    // Get vehicle details
    $stmt = $pdo->prepare("
        SELECT v.*, vo.name as owner_name
        FROM vehicles v
        JOIN vehicle_owners vo ON v.owner_id = vo.id
        WHERE v.id = :id
    ");
    $stmt->execute(['id' => $vehicleId]);
    $vehicle = $stmt->fetch();
    
    if (!$vehicle) {
        die("Vehicle not found or you don't have permission to access it.");
    }
    
    // Check if QR code exists
    if (empty($vehicle['qr_code_path'])) {
        die("QR code not found. Please contact the administrator.");
    }
    
    // Create directory if it doesn't exist
    $certificateDir = __DIR__ . '/../../../uploads/certificates/';
    if (!is_dir($certificateDir)) {
        mkdir($certificateDir, 0777, true);
    }
    
    // QR code file path
    $qrCodeFilePath = __DIR__ . '/../../../' . $vehicle['qr_code_path'];
    
    if (!file_exists($qrCodeFilePath)) {
        die("QR code file not found at path: " . $qrCodeFilePath);
    }
    
    // Check if we need to regenerate the certificate
    $regenerate = $forceFresh || 
                 empty($vehicle['roadworthy_certificate_path']) || 
                 !file_exists(__DIR__ . '/../../../' . $vehicle['roadworthy_certificate_path']);
    
    if (!$regenerate) {
        // Certificate already exists and we don't need to regenerate
        if (isset($_GET['return']) && $_GET['return'] === 'view') {
            header("Location: view-roadworthy.php?id=$vehicleId&nocache=" . time());
        } else {
            header("Location: vehicle-details.php?id=$vehicleId&success=Roadworthy+certificate+already+exists");
        }
        exit;
    }
    
    // Generate a new Roadworthy Certificate
    $roadworthyFilePath = $certificateDir . str_replace(' ', '_', $vehicle['registration_number']) . '_roadworthy_' . time() . '.png';
    $roadworthyRelativePath = 'uploads/certificates/' . basename($roadworthyFilePath);

    // Generate a unique serial number
    $serialPrefix = "A";
    $serialNumber = $serialPrefix . str_pad(mt_rand(0, 99999999), 8, "0", STR_PAD_LEFT);

    // Create the image
    $roadworthyImg = imagecreatetruecolor(600, 400);
    $white = imagecolorallocate($roadworthyImg, 255, 255, 255);
    $black = imagecolorallocate($roadworthyImg, 0, 0, 0);
    $darkBlue = imagecolorallocate($roadworthyImg, 0, 41, 91);
    $gold = imagecolorallocate($roadworthyImg, 224, 180, 0);
    $darkGray = imagecolorallocate($roadworthyImg, 80, 80, 80);
    $mediumGray = imagecolorallocate($roadworthyImg, 120, 120, 120);
    $lightGray = imagecolorallocate($roadworthyImg, 240, 240, 240);
    $lightText = imagecolorallocate($roadworthyImg, 100, 100, 100);

    // Fill background
    imagefill($roadworthyImg, 0, 0, $white);
    
    // Draw border
    imagerectangle($roadworthyImg, 0, 0, 599, 399, $mediumGray);
    
    // Header bar
    imagefilledrectangle($roadworthyImg, 0, 0, 599, 80, $darkBlue);
    
    // Gold accent line
    imagefilledrectangle($roadworthyImg, 0, 80, 599, 83, $gold);
    
    // Title text
    $titleText = "Driver and Vehicle Licensing Authority";
    $font_size = 5;
    $font_width = imagefontwidth($font_size);
    $text_width = $font_width * strlen($titleText);
    $x = (600 - $text_width) / 2;
    imagestring($roadworthyImg, $font_size, $x, 20, $titleText, $white);

    $subtitleText = "ROADWORTHY CERTIFICATE";
    $font_width = imagefontwidth(4);
    $text_width = $font_width * strlen($subtitleText);
    $x = (600 - $text_width) / 2;
    imagestring($roadworthyImg, 4, $x, 45, $subtitleText, $white);

    // Registration number
    imagestring($roadworthyImg, 5, 40, 100, $vehicle['registration_number'], $black);
    
    // Registration date
    $regDateText = date('d/m/Y', strtotime($vehicle['registration_date']));
    imagestring($roadworthyImg, 4, 40, 130, $regDateText, $black);
    
    // Vehicle details
    $detailY = 170;
    
    // Determine vehicle use type
    $vehicleUse = isset($vehicle['vehicle_type']) && 
                 (strtolower($vehicle['vehicle_type']) == 'commercial' || 
                  strtolower($vehicle['vehicle_type']) == 'taxi' ||
                  strtolower($vehicle['vehicle_type']) == 'bus') ? 'Commercial' : 'Private';

    imagestring($roadworthyImg, 3, 40, $detailY, "Use: " . $vehicleUse, $lightText);
    $detailY += 25;
    
    imagestring($roadworthyImg, 3, 40, $detailY, "Color: " . $vehicle['color'], $lightText);
    $detailY += 25;
    
    imagestring($roadworthyImg, 3, 40, $detailY, "Make: " . $vehicle['make'], $lightText);
    $detailY += 25;
    
    imagestring($roadworthyImg, 3, 40, $detailY, "Model: " . $vehicle['model'], $lightText);

    // Position QR code
    if (file_exists($qrCodeFilePath)) {
        $qrImage = @imagecreatefrompng($qrCodeFilePath);
        if ($qrImage !== false) {
            $qrSize = 150;
            $qrX = ((600 - $qrSize) / 2) + 45;
            $qrY = 150;
            
            // QR code background
            imagefilledrectangle($roadworthyImg, $qrX - 5, $qrY - 5, $qrX + $qrSize + 5, $qrY + $qrSize + 5, $white);
            imagerectangle($roadworthyImg, $qrX - 5, $qrY - 5, $qrX + $qrSize + 5, $qrY + $qrSize + 5, $lightGray);
            
            // Copy QR code to certificate
            imagecopyresampled(
                $roadworthyImg, 
                $qrImage, 
                $qrX, 
                $qrY, 
                0, 
                0, 
                $qrSize, 
                $qrSize, 
                imagesx($qrImage), 
                imagesy($qrImage)
            );
            
            imagedestroy($qrImage);
        }
    }

    // Try multiple possible paths for DVLA logo
    $logoPathOptions = [
        // Option 1: Document root path
        $_SERVER['DOCUMENT_ROOT'] . '/assets/img/dvla.png',
        
        // Option 2: Relative to current directory
        __DIR__ . '/../../../assets/img/dvla.png',
        
        // Option 3: Another common location
        __DIR__ . '/../../../public/assets/img/dvla.png',
        
        // Option 4: Another common path structure
        $_SERVER['DOCUMENT_ROOT'] . '/img/dvla.png'
    ];
    
    $logoImage = false;
    $logoPath = '';
    
    // Try each path option until we find an existing logo file
    foreach ($logoPathOptions as $path) {
        if (file_exists($path)) {
            $logoPath = $path;
            $logoImage = @imagecreatefrompng($path);
            if ($logoImage !== false) {
                break; // Found a working logo file
            }
        }
    }
    
    // Position for the logo
    $logoX = 510;
    $logoY = 90;
    
    if ($logoImage !== false) {
        // Logo loaded successfully - use it
        $logoWidth = imagesx($logoImage);
        $logoHeight = imagesy($logoImage);
        
        // Calculate proper size for the logo
        $maxLogoWidth = 80;
        $maxLogoHeight = 80;
        
        // Calculate proportional size
        if ($logoWidth > $maxLogoWidth) {
            $ratio = $maxLogoWidth / $logoWidth;
            $newLogoWidth = $maxLogoWidth;
            $newLogoHeight = $logoHeight * $ratio;
        } else {
            $newLogoWidth = $logoWidth;
            $newLogoHeight = $logoHeight;
        }
        
        // Copy the logo onto the certificate
        imagecopyresampled(
            $roadworthyImg,
            $logoImage,
            $logoX,
            $logoY,
            0,
            0,
            $newLogoWidth,
            $newLogoHeight,
            $logoWidth,
            $logoHeight
        );
        
        // Free memory
        imagedestroy($logoImage);
    } else {
        // Fallback to a simple DVLA text label instead of the Ghana flag design
        $logoWidth = 70;
        $logoHeight = 60;
        
        // Draw a white rectangle with border
        imagefilledrectangle($roadworthyImg, $logoX, $logoY, $logoX + $logoWidth, $logoY + $logoHeight, $white);
        imagerectangle($roadworthyImg, $logoX, $logoY, $logoX + $logoWidth, $logoY + $logoHeight, $darkGray);
        
        // Add DVLA text
        $dvlaText = "DVLA";
        $font_size = 4;
        $font_width = imagefontwidth($font_size);
        $text_width = $font_width * strlen($dvlaText);
        $x = $logoX + ($logoWidth - $text_width) / 2;
        $y = $logoY + 20;
        imagestring($roadworthyImg, $font_size, $x, $y, $dvlaText, $darkBlue);
    }
    
    // Add serial number at bottom center
    $font_size = 5;
    $font_width = imagefontwidth($font_size);
    $text_width = $font_width * strlen($serialNumber);
    $x = (600 - $text_width) / 2;
    imagestring($roadworthyImg, $font_size, $x, 350, $serialNumber, $black);
    
    // Save the certificate
    imagepng($roadworthyImg, $roadworthyFilePath);
    imagedestroy($roadworthyImg);
    
    // Update the vehicle record with the certificate path
    $updateStmt = $pdo->prepare("UPDATE vehicles SET roadworthy_certificate_path = :path WHERE id = :id");
    $updateStmt->execute([
        'path' => $roadworthyRelativePath,
        'id' => $vehicle['id']
    ]);
    
    // Check if we should redirect to view the certificate
    if (isset($_GET['return']) && $_GET['return'] === 'view') {
        header("Location: view-roadworthy.php?id=$vehicleId&nocache=" . time());
        exit;
    } else {
        header("Location: vehicle-details.php?id=$vehicleId&success=Roadworthy+certificate+generated+successfully");
        exit;
    }
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>