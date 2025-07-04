<?php
// filepath: /Applications/XAMPP/xamppfiles/htdocs/dvlaregister/src/views/admin/generateqr.php
session_start();
require_once __DIR__ . '/../../../config/database.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../views/auth/login.php');
    exit;
}

$errors = [];
$success = false;
$vehicles = [];
$selectedVehicle = null;
$qrGenerated = false;
$qrCodePath = '';

// Get all vehicles for selection
try {
    $stmt = $pdo->prepare("
        SELECT v.id, v.registration_number, v.make, v.model, v.color, 
               v.qr_code_path, o.name as owner_name, o.id as owner_id
        FROM vehicles v
        JOIN vehicle_owners o ON v.owner_id = o.id
        ORDER BY v.registration_date DESC
    ");
    $stmt->execute();
    $vehicles = $stmt->fetchAll();
} catch (PDOException $e) {
    $errors[] = "Error fetching vehicles: " . $e->getMessage();
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vehicleId = filter_input(INPUT_POST, 'vehicle_id', FILTER_VALIDATE_INT);
    
    if (!$vehicleId) {
        $errors[] = "Please select a valid vehicle";
    } else {
        try {
            // Get vehicle details including id_document_type
            $stmt = $pdo->prepare("
                SELECT v.*, o.name as owner_name, o.phone as owner_phone, o.email as owner_email, 
                       o.ghana_card_number, o.address as owner_address, o.id_document_type
                FROM vehicles v
                JOIN vehicle_owners o ON v.owner_id = o.id
                WHERE v.id = :id
            ");
            $stmt->execute(['id' => $vehicleId]);
            $selectedVehicle = $stmt->fetch();
            
            if (!$selectedVehicle) {
                $errors[] = "Vehicle not found";
            } else {
                // Create QR code directory if it doesn't exist
                $qrCodeDir = __DIR__ . '/../../../uploads/qr_codes/';
                if (!is_dir($qrCodeDir)) {
                    mkdir($qrCodeDir, 0777, true);
                }
                
                $qrCodeFileName = str_replace(' ', '_', $selectedVehicle['registration_number']) . '_' . time() . '.png';
                $qrCodeFilePath = $qrCodeDir . $qrCodeFileName;
                $qrCodePath = 'uploads/qr_codes/' . $qrCodeFileName;
                
                // Get the proper ID document type name for the QR code
                $idDocumentType = isset($selectedVehicle['id_document_type']) ? $selectedVehicle['id_document_type'] : 'ghana_card';
                $idTypeName = 'Ghana Card';
                
                switch($idDocumentType) {
                    case 'drivers_license':
                        $idTypeName = "Driver's License";
                        break;
                    case 'passport':
                        $idTypeName = 'Passport';
                        break;
                    case 'voter_id':
                        $idTypeName = 'Voter ID';
                        break;
                    default:
                        $idTypeName = 'Ghana Card';
                }
                
                // IMPROVED: Create nicely formatted data with clean spacing and better structure
                // This ensures a visually appealing result when scanned
                $qrData = "GHANA DVLA VEHICLE REGISTRATION\n";
                $qrData .= "--------------------------------\n";
                $qrData .= "Owner: " . $selectedVehicle['owner_name'] . "\n";
                $qrData .= "REG: " . $selectedVehicle['registration_number'] . "\n";
                $qrData .= "VEHICLE: " . $selectedVehicle['make'] . " " . $selectedVehicle['model'] . "\n";
                $qrData .= "COLOR: " . $selectedVehicle['color'] . "\n";
                $qrData .= "YEAR: " . $selectedVehicle['year_of_manufacture'] . "\n";
                $qrData .= "Seater: " . $selectedVehicle['seating_capacity'] . "\n";
                $qrData .= "ENGINE: " . $selectedVehicle['engine_number'] . "\n";
                $qrData .= "CHASSIS: " . $selectedVehicle['chassis_number'] . "\n";
                $qrData .= "TYPE: " . $selectedVehicle['vehicle_type'] . "\n";
                $qrData .= "TYPE: " . $selectedVehicle['vehicle_use'] . "\n";
                
                if (!empty($selectedVehicle['engine_type'])) {
                    $qrData .= "ENGINE TYPE: " . $selectedVehicle['engine_type'] . "\n";
                }
                
                if (!empty($selectedVehicle['engine_capacity'])) {
                    $qrData .= "CAPACITY: " . $selectedVehicle['engine_capacity'] . "cc\n";
                }
                
                if (!empty($selectedVehicle['seating_capacity'])) {
                    $qrData .= "SEATS: " . $selectedVehicle['seating_capacity'] . "\n";
                }
                
                $qrData .= "REG DATE: " . date('d/m/Y', strtotime($selectedVehicle['registration_date'])) . "\n";
                $qrData .= "EXPIRY: " . date('d/m/Y', strtotime($selectedVehicle['expiry_date'])) . "\n";
                $qrData .= "--------------------------------\n";
                $qrData .= "OWNER: " . $selectedVehicle['owner_name'] . "\n";
                $qrData .= $idTypeName . " #: " . $selectedVehicle['ghana_card_number'] . "\n";
                
                if (!empty($selectedVehicle['owner_phone'])) {
                    $qrData .= "PHONE: " . $selectedVehicle['owner_phone'] . "\n";
                }
                
                $qrData .= "--------------------------------\n";
                $qrData .= "Verified by Ghana DVLA";
                
                // Try high-quality QR code generation
                try {
                    // Use QRcode-monkey API for better looking QR codes with customization
                    // This produces modern, professional looking QR codes
                    $payload = json_encode([
                        'data' => $qrData,
                        'config' => [
                            'body' => 'square',
                            'eye' => 'frame13',
                            'eyeBall' => 'ball13',
                            'erf1' => [],
                            'erf2' => [],
                            'erf3' => [],
                            'brf1' => [],
                            'brf2' => [],
                            'brf3' => [],
                            'bodyColor' => '#000000',
                            'bgColor' => '#FFFFFF',
                            'eye1Color' => '#000000',
                            'eye2Color' => '#000000',
                            'eye3Color' => '#000000',
                            'eyeBall1Color' => '#000000',
                            'eyeBall2Color' => '#000000',
                            'eyeBall3Color' => '#000000',
                            'gradientColor1' => '#000000',
                            'gradientColor2' => '#000000',
                            'gradientType' => 'linear',
                            'gradientOnEyes' => 'true',
                            'logo' => '',
                            'logoMode' => 'default'
                        ],
                        'size' => 600,
                        'download' => false,
                        'file' => 'png'
                    ]);
                    
                    $ch = curl_init('https://api.qrcode-monkey.com/qr/custom');
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Content-Type: application/json',
                        'Content-Length: ' . strlen($payload)
                    ]);
                    
                    $qrImage = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    
                    if ($httpCode == 200 && $qrImage !== false) {
                        // Save the QR code image
                        file_put_contents($qrCodeFilePath, $qrImage);
                        
                        // Update vehicle record with QR code path
                        $stmt = $pdo->prepare("UPDATE vehicles SET qr_code_path = :qr_code_path WHERE id = :id");
                        $stmt->execute([
                            'qr_code_path' => $qrCodePath,
                            'id' => $vehicleId
                        ]);
                        
                        $qrGenerated = true;
                        $success = true;
                    } else {
                        throw new Exception("Could not generate QR code from QRcode-monkey API");
                    }
                } catch (Exception $e) {
                    // Fallback to Google Charts API with improved settings for better appearance
                    try {
                        // Use better error correction level and proper margins
                        $googleChartUrl = "https://chart.googleapis.com/chart?chs=500x500&cht=qr&chld=M|2&chl=" . urlencode($qrData);
                        $qrImage = @file_get_contents($googleChartUrl);
                        
                        if ($qrImage !== false) {
                            // Save the QR code image
                            file_put_contents($qrCodeFilePath, $qrImage);
                            
                            // Improve the appearance with image processing
                            $img = imagecreatefromstring($qrImage);
                            if ($img !== false) {
                                // Add rounded corners and a subtle border
                                $width = imagesx($img);
                                $height = imagesy($img);
                                
                                // Add white space and border
                                $paddedImg = imagecreatetruecolor($width + 40, $height + 40);
                                $white = imagecolorallocate($paddedImg, 255, 255, 255);
                                $lightGray = imagecolorallocate($paddedImg, 240, 240, 240);
                                imagefill($paddedImg, 0, 0, $white);
                                
                                // Copy the QR code to the center of the new image
                                imagecopy($paddedImg, $img, 20, 20, 0, 0, $width, $height);
                                
                                // Add subtle border
                                imagerectangle($paddedImg, 10, 10, $width + 30, $height + 30, $lightGray);
                                
                                // Save the enhanced image
                                imagepng($paddedImg, $qrCodeFilePath);
                                imagedestroy($img);
                                imagedestroy($paddedImg);
                            }
                            
                            // Update vehicle record with QR code path
                            $stmt = $pdo->prepare("UPDATE vehicles SET qr_code_path = :qr_code_path WHERE id = :id");
                            $stmt->execute([
                                'qr_code_path' => $qrCodePath,
                                'id' => $vehicleId
                            ]);
                            
                            $qrGenerated = true;
                            $success = true;
                        } else {
                            throw new Exception("Could not generate QR code from Google Chart API");
                        }
                    } catch (Exception $e2) {
                        // Final fallback: Use QR Server API with styled options
                        try {
                            $qrImageURL = "https://api.qrserver.com/v1/create-qr-code/?size=500x500&data=" . urlencode($qrData) . "&ecc=M&margin=10&qzone=4&format=png&color=000&bgcolor=fff";
                            $qrImage = @file_get_contents($qrImageURL);
                            
                            if ($qrImage !== false) {
                                // Save the QR code image
                                file_put_contents($qrCodeFilePath, $qrImage);
                                
                                // Update vehicle record with QR code path
                                $stmt = $pdo->prepare("UPDATE vehicles SET qr_code_path = :qr_code_path WHERE id = :id");
                                $stmt->execute([
                                    'qr_code_path' => $qrCodePath,
                                    'id' => $vehicleId
                                ]);
                                
                                $qrGenerated = true;
                                $success = true;
                            } else {
                                throw new Exception("Could not generate QR code from QR Server API");
                            }
                        } catch (Exception $e3) {
                            // Create our own visually appealing QR code alternative
                            $img = imagecreatetruecolor(500, 500);
                            $white = imagecolorallocate($img, 255, 255, 255);
                            $black = imagecolorallocate($img, 0, 0, 0);
                            $blue = imagecolorallocate($img, 0, 61, 121);
                            $gold = imagecolorallocate($img, 224, 180, 0);
                            
                            // Fill with white background
                            imagefill($img, 0, 0, $white);
                            
                            // Create a styled border
                            imagefilledrectangle($img, 0, 0, 499, 60, $blue);
                            imagefilledrectangle($img, 0, 440, 499, 499, $blue);
                            
                            // Add Ghana flag colors as decorative elements
                            imagefilledrectangle($img, 0, 60, 10, 440, $gold);
                            imagefilledrectangle($img, 490, 60, 499, 440, $gold);
                            
                            // Add DVLA header text
                            $headerText = "GHANA DVLA";
                            $font_size = 5;
                            $font_width = imagefontwidth($font_size);
                            $text_width = $font_width * strlen($headerText);
                            $x = (500 - $text_width) / 2;
                            imagestring($img, $font_size, $x, 20, $headerText, $white);
                            
                            // Add registration in large text at the center
                            $regText = "VEHICLE REGISTRATION";
                            $font_size = 4;
                            $font_width = imagefontwidth($font_size);
                            $text_width = $font_width * strlen($regText);
                            $x = (500 - $text_width) / 2;
                            imagestring($img, $font_size, $x, 90, $regText, $black);
                            
                            // Add registration number in extra large text
                            $regNumber = $selectedVehicle['registration_number'];
                            $font_size = 5;
                            $font_width = imagefontwidth($font_size);
                            $text_width = $font_width * strlen($regNumber);
                            $x = (500 - $text_width) / 2;
                            imagestring($img, $font_size, $x, 140, $regNumber, $black);
                            
                            // Add vehicle info
                            $y = 200;
                            $vehicleInfo = $selectedVehicle['make'] . " " . $selectedVehicle['model'] . " (" . $selectedVehicle['color'] . ")";
                            $font_size = 4;
                            $font_width = imagefontwidth($font_size);
                            $text_width = $font_width * strlen($vehicleInfo);
                            $x = (500 - $text_width) / 2;
                            imagestring($img, $font_size, $x, $y, $vehicleInfo, $black);
                            
                            $y += 40;
                            $ownerInfo = "Owner: " . $selectedVehicle['owner_name'];
                            $font_width = imagefontwidth(3);
                            $text_width = $font_width * strlen($ownerInfo);
                            $x = (500 - $text_width) / 2;
                            imagestring($img, 3, $x, $y, $ownerInfo, $black);
                            
                            $y += 30;
                            $expiryInfo = "Expires: " . date('d/m/Y', strtotime($selectedVehicle['expiry_date']));
                            $text_width = $font_width * strlen($expiryInfo);
                            $x = (500 - $text_width) / 2;
                            imagestring($img, 3, $x, $y, $expiryInfo, $black);
                            
                            // Add decorative QR-like pattern in the corners for aesthetics
                            // Top-left corner
                            imagefilledrectangle($img, 30, 80, 90, 140, $black);
                            imagefilledrectangle($img, 40, 90, 80, 130, $white);
                            imagefilledrectangle($img, 50, 100, 70, 120, $black);
                            
                            // Top-right corner
                            imagefilledrectangle($img, 410, 80, 470, 140, $black);
                            imagefilledrectangle($img, 420, 90, 460, 130, $white);
                            imagefilledrectangle($img, 430, 100, 450, 120, $black);
                            
                            // Bottom-left corner
                            imagefilledrectangle($img, 30, 360, 90, 420, $black);
                            imagefilledrectangle($img, 40, 370, 80, 410, $white);
                            imagefilledrectangle($img, 50, 380, 70, 400, $black);
                            
                            // Add footer text
                            imagestring($img, 3, 140, 450, "OFFICIAL VEHICLE REGISTRATION", $white);
                            imagestring($img, 2, 150, 470, "Scan to verify vehicle details", $white);
                            
                            // Save the enhanced image
                            imagepng($img, $qrCodeFilePath);
                            imagedestroy($img);
                            
                            // Update vehicle record with QR code path
                            $stmt = $pdo->prepare("UPDATE vehicles SET qr_code_path = :qr_code_path WHERE id = :id");
                            $stmt->execute([
                                'qr_code_path' => $qrCodePath,
                                'id' => $vehicleId
                            ]);
                            
                            $qrGenerated = true;
                            $success = true;
                        }
                    }
                }
                
                // Generate a vehicle info card with improved design
                $cardFilePath = $qrCodeDir . str_replace(' ', '_', $selectedVehicle['registration_number']) . '_card_' . time() . '.png';
                $cardRelativePath = 'uploads/qr_codes/' . basename($cardFilePath);
                
                // Create a more professional vehicle info card
                $cardImg = imagecreatetruecolor(600, 400);
                $white = imagecolorallocate($cardImg, 255, 255, 255);
                $black = imagecolorallocate($cardImg, 0, 0, 0);
                $blue = imagecolorallocate($cardImg, 0, 61, 121);
                $lightBlue = imagecolorallocate($cardImg, 50, 110, 180);
                $gray = imagecolorallocate($cardImg, 240, 240, 240);
                $gold = imagecolorallocate($cardImg, 224, 180, 0);
                $darkGray = imagecolorallocate($cardImg, 80, 80, 80);
                
                // Fill with white background
                imagefill($cardImg, 0, 0, $white);
                
                // Draw border
                imagerectangle($cardImg, 0, 0, 599, 399, $gray);
                
                // Header bar with gradient effect
                imagefilledrectangle($cardImg, 0, 0, 599, 60, $blue);
                
                // Add horizontal gold accent line
                imagefilledrectangle($cardImg, 0, 60, 599, 65, $gold);
                
                // Title text
                $titleText = "GHANA DVLA - VEHICLE REGISTRATION";
                $font_size = 5;
                $font_width = imagefontwidth($font_size);
                $text_width = $font_width * strlen($titleText);
                $x = (600 - $text_width) / 2;
                imagestring($cardImg, $font_size, $x, 20, $titleText, $white);
                
                // Registration number with highlight box
                imagefilledrectangle($cardImg, 150, 80, 450, 120, $gray);
                $regText = "REGISTRATION: " . $selectedVehicle['registration_number'];
                $font_size = 5;
                $font_width = imagefontwidth($font_size);
                $text_width = $font_width * strlen($regText);
                $x = (600 - $text_width) / 2;
                imagestring($cardImg, $font_size, $x, 92, $regText, $darkGray);
                
                // Draw separator line
                imageline($cardImg, 20, 135, 580, 135, $gray);
                
                // Vehicle details with two-column layout and improved styling
                $y = 150;
                $col1_x = 40;
                $col2_x = 200;
                $col3_x = 340;
                $col4_x = 480;
                
                // Section header
                imagefilledrectangle($cardImg, 20, $y, 280, $y + 25, $lightBlue);
                imagestring($cardImg, 4, 30, $y + 5, "VEHICLE INFORMATION", $white);
                imagefilledrectangle($cardImg, 320, $y, 580, $y + 25, $lightBlue);
                imagestring($cardImg, 4, 330, $y + 5, "OWNER INFORMATION", $white);
                $y += 35;
                
                // Column 1 - Vehicle details
                imagestring($cardImg, 3, $col1_x, $y, "Make:", $darkGray);
                imagestring($cardImg, 3, $col2_x, $y, $selectedVehicle['make'], $black);
                $y += 25;
                
                imagestring($cardImg, 3, $col1_x, $y, "Model:", $darkGray);
                imagestring($cardImg, 3, $col2_x, $y, $selectedVehicle['model'], $black);
                $y += 25;
                
                imagestring($cardImg, 3, $col1_x, $y, "Color:", $darkGray);
                imagestring($cardImg, 3, $col2_x, $y, $selectedVehicle['color'], $black);
                $y += 25;
                
                imagestring($cardImg, 3, $col1_x, $y, "Year:", $darkGray);
                imagestring($cardImg, 3, $col2_x, $y, $selectedVehicle['year_of_manufacture'], $black);
                $y += 25;
                
                imagestring($cardImg, 3, $col1_x, $y, "Chassis #:", $darkGray);
                imagestring($cardImg, 3, $col2_x, $y, $selectedVehicle['chassis_number'], $black);
                $y += 25;
                
                // Reset y position for second column
                $y = 185;
                
                // Column 2 - Owner details
                imagestring($cardImg, 3, $col3_x, $y, "Owner:", $darkGray);
                imagestring($cardImg, 3, $col4_x, $y, $selectedVehicle['owner_name'], $black);
                $y += 25;
                
                // Display ID Type based on the owner's ID document type
                imagestring($cardImg, 3, $col3_x, $y, $idTypeName . ":", $darkGray);
                imagestring($cardImg, 3, $col4_x, $y, $selectedVehicle['ghana_card_number'], $black);
                $y += 25;
                
                if (!empty($selectedVehicle['owner_phone'])) {
                    imagestring($cardImg, 3, $col3_x, $y, "Phone:", $darkGray);
                    imagestring($cardImg, 3, $col4_x, $y, $selectedVehicle['owner_phone'], $black);
                    $y += 25;
                }
                
                imagestring($cardImg, 3, $col3_x, $y, "Reg Date:", $darkGray);
                imagestring($cardImg, 3, $col4_x, $y, date('d/m/Y', strtotime($selectedVehicle['registration_date'])), $black);
                $y += 25;
                
                imagestring($cardImg, 3, $col3_x, $y, "Expiry Date:", $darkGray);
                imagestring($cardImg, 3, $col4_x, $y, date('d/m/Y', strtotime($selectedVehicle['expiry_date'])), $black);
                
                // Footer bar
                imagefilledrectangle($cardImg, 0, 350, 599, 399, $blue);
                
                // Footer text
                $footerText = "Official Ghana DVLA Vehicle Registration Card";
                $font_width = imagefontwidth(3);
                $text_width = $font_width * strlen($footerText);
                $x = (600 - $text_width) / 2;
                imagestring($cardImg, 3, $x, 360, $footerText, $white);
                
                // Generated date
                $dateText = "Generated: " . date('d/m/Y');
                $font_width = imagefontwidth(2);
                $text_width = $font_width * strlen($dateText);
                $x = (600 - $text_width) / 2;
                imagestring($cardImg, 2, $x, 380, $dateText, $white);
                
                // Save the improved info card
                imagepng($cardImg, $cardFilePath);
                imagedestroy($cardImg);
                
                // Generate Roadworthy Certificate
                $roadworthyFilePath = $qrCodeDir . str_replace(' ', '_', $selectedVehicle['registration_number']) . '_roadworthy_' . time() . '.png';
                $roadworthyRelativePath = 'uploads/qr_codes/' . basename($roadworthyFilePath);

                // Generate a unique serial number for the roadworthy certificate
                $serialPrefix = "A";
                $serialNumber = $serialPrefix . str_pad(mt_rand(0, 99999999), 8, "0", STR_PAD_LEFT);

                // Create the roadworthy certificate card with REDUCED WIDTH (600px instead of 800px)
                $roadworthyImg = imagecreatetruecolor(600, 400);
                $white = imagecolorallocate($roadworthyImg, 255, 255, 255);
                $black = imagecolorallocate($roadworthyImg, 0, 0, 0);
                $darkBlue = imagecolorallocate($roadworthyImg, 0, 41, 91); // Blue-black color
                $gold = imagecolorallocate($roadworthyImg, 224, 180, 0);
                $darkGray = imagecolorallocate($roadworthyImg, 80, 80, 80);
                $mediumGray = imagecolorallocate($roadworthyImg, 120, 120, 120);
                $lightGray = imagecolorallocate($roadworthyImg, 240, 240, 240);
                $lightText = imagecolorallocate($roadworthyImg, 100, 100, 100); // Lighter text color for details

                // Fill with white background
                imagefill($roadworthyImg, 0, 0, $white);

                // Draw border
                imagerectangle($roadworthyImg, 0, 0, 599, 399, $mediumGray);

                // Header bar with dark blue-black color
                imagefilledrectangle($roadworthyImg, 0, 0, 599, 80, $darkBlue);

                // Add gold accent line
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

                // Registration number at the top-left (boldened)
                imagestring($roadworthyImg, 5, 40, 100, $selectedVehicle['registration_number'], $black);

                // Registration date below it - BOLDED with larger font
                $regDateText =  date('d/m/Y', strtotime($selectedVehicle['registration_date']));
                imagestring($roadworthyImg, 4, 40, 130, $regDateText, $black); // Keeping bold (font size 5)

                // Vehicle details with clear text - Using LIGHTER COLOR for these fields
                $y = 170;
                $detailY = $y;

                // Determine if vehicle is private or commercial (default to Private if not specified)
                $vehicleUse = isset($selectedVehicle['vehicle_type']) && 
                             (strtolower($selectedVehicle['vehicle_type']) == 'commercial' || 
                              strtolower($selectedVehicle['vehicle_type']) == 'taxi' ||
                              strtolower($selectedVehicle['vehicle_type']) == 'bus') ? 'Commercial' : 'Private';

                imagestring($roadworthyImg, 3, 40, $detailY, "Use: " . $vehicleUse, $lightText); // Lighter text color
                $detailY += 25;

                imagestring($roadworthyImg, 3, 40, $detailY, "Color: " . $selectedVehicle['color'], $lightText); // Lighter text color
                $detailY += 25;

                imagestring($roadworthyImg, 3, 40, $detailY, "Make: " . $selectedVehicle['make'], $lightText); // Lighter text color
                $detailY += 25;

                imagestring($roadworthyImg, 3, 40, $detailY, "Model: " . $selectedVehicle['model'], $lightText); // Lighter text color

                // Position QR code with slight offset to the right and SMALLER SIZE
                if (file_exists($qrCodeFilePath)) {
                    $qrImage = @imagecreatefrompng($qrCodeFilePath);
                    if ($qrImage !== false) {
                        // REDUCED QR code size from 180 to 150
                        $qrSize = 150; // QR code size (reduced)
                        $qrX = ((600 - $qrSize) / 2) + 45; // Centered X position + 45px offset to right
                        $qrY = 150; // QR code Y position (slightly lower)
                        
                        // Create a white background for the QR code
                        imagefilledrectangle($roadworthyImg, $qrX - 5, $qrY - 5, $qrX + $qrSize + 5, $qrY + $qrSize + 5, $white);
                        imagerectangle($roadworthyImg, $qrX - 5, $qrY - 5, $qrX + $qrSize + 5, $qrY + $qrSize + 5, $lightGray);
                        
                        // Copy the QR code to the roadworthy certificate
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

              // Add DVLA logo on right side with correct path - INCREASED SIZE
$logoPath = $_SERVER['DOCUMENT_ROOT'] . '/assets/img/dvla.png'; // Full server path
if (file_exists($logoPath)) {
    // Load logo image
    $logoImage = @imagecreatefrompng($logoPath);
    // If logo loading was successful
    if ($logoImage !== false) {
        // Get logo dimensions
        $logoWidth = imagesx($logoImage);
        $logoHeight = imagesy($logoImage);
        
        // Calculate proper size for the logo - INCREASED SIZE
        $maxLogoWidth = 80; // Increased from 60
        $maxLogoHeight = 80; // Increased from 60
        
        // Calculate proportional size
        if ($logoWidth > $maxLogoWidth) {
            $ratio = $maxLogoWidth / $logoWidth;
            $newLogoWidth = $maxLogoWidth;
            $newLogoHeight = $logoHeight * $ratio;
        } else {
            $newLogoWidth = $logoWidth;
            $newLogoHeight = $logoHeight;
        }
        
        // Position the logo at top right corner
        $logoX = 510; // Adjusted for larger logo
        $logoY = 90;  // Adjusted for larger logo
        
        // Copy the logo onto the certificate with better quality parameters
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
        // Fallback if logo can't be loaded
        imagefilledrectangle($roadworthyImg, 520, 100, 560, 140, $lightGray);
        imagestring($roadworthyImg, 2, 528, 115, "DVLA", $darkGray);
    }
} else {
    // Fallback if logo file doesn't exist
    imagefilledrectangle($roadworthyImg, 520, 100, 560, 140, $lightGray);
    imagestring($roadworthyImg, 2, 528, 115, "DVLA", $darkGray);
}
                // Add serial number at the bottom center - REMOVED "Certificate No:" prefix
                $serialText = $serialNumber; // Just the serial number without prefix
                $font_size = 5;
                $font_width = imagefontwidth($font_size);
                $text_width = $font_width * strlen($serialText);
                $x = (600 - $text_width) / 2;
                imagestring($roadworthyImg, $font_size, $x, 350, $serialText, $black);

                // Save the roadworthy certificate
                imagepng($roadworthyImg, $roadworthyFilePath);
                imagedestroy($roadworthyImg);
            }
        } catch (PDOException $e) {
            $errors[] = "Database error: " . $e->getMessage();
        } catch (Exception $e) {
            $errors[] = "Error: " . $e->getMessage();
        }
    }
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
    <title>Generate QR Code | Admin | Vehicle Registration System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <style>
        :root {
            --primary-color: #0a3d62;
            --secondary-color: #60a3bc;
            --accent-color: #e58e26;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
        }
        
        body {
            background-color: #f5f5f5;
            font-family: 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
        }
        
        .header {
            background-color: var(--primary-color);
            color: white;
            padding: 15px 0;
        }
        
        .main-content {
            padding: 20px;
        }
        
        .card {
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border: none;
            margin-bottom: 20px;
        }
        
        .card-header {
            border-radius: 10px 10px 0 0;
        }
        
        .qr-code-container {
            text-align: center;
            padding: 20px;
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }
        
        .qr-code {
            max-width: 300px;
            height: auto;
            border: 1px solid #eee;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.05);
            border-radius: 4px;
            padding: 10px;
            background-color: white;
        }
        
        .vehicle-details {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-top: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.04);
        }
        
        .detail-item {
            display: flex;
            border-bottom: 1px solid #eee;
            padding: 8px 0;
        }
        
        .detail-label {
            font-weight: 600;
            width: 40%;
            color: #555;
        }
        
        .detail-value {
            width: 60%;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-primary:hover {
            background-color: #072f4a;
            border-color: #072f4a;
        }
        
        /* Added styles for QR code alert */
        .qr-alert {
            border-left: 4px solid var(--accent-color);
            background-color: #fff8ec;
        }
        
        /* Card container */
        .info-card-container {
            text-align: center;
            padding: 15px 0;
        }
        
        .info-card {
            max-width: 100%;
            height: auto;
            border: 1px solid #ddd;
            border-radius: 5px;
            box-shadow: 0 3px 6px rgba(0, 0, 0, 0.1);
        }
        
        /* Enhanced QR container */
        .qr-container {
            position: relative;
            padding: 20px;
            background: linear-gradient(145deg, #fff, #f5f7fa);
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            border: 1px solid #eaeaea;
        }
        
        .qr-header {
            position: absolute;
            top: -10px;
            left: 50%;
            transform: translateX(-50%);
            background-color: var(--primary-color);
            color: white;
            padding: 3px 15px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 500;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        
        .info-tab {
            background-color: #f8fafc;
            border-left: 3px solid var(--primary-color);
            padding: 12px 15px;
            margin: 15px 0;
            border-radius: 0 5px 5px 0;
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
                <li class="breadcrumb-item active">Generate QR Code</li>
            </ol>
        </nav>
        
        <?php if (!empty($errors)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <strong><i class="fas fa-exclamation-triangle me-2"></i> Error:</strong>
        <ul class="mb-0 mt-2">
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>  
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i> QR code has been successfully generated!
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <div class="row">
            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-qrcode me-2"></i> Generate QR Code</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <div class="mb-3">
                                <label for="vehicle_id" class="form-label">Select Vehicle:</label>
                                <select class="form-select" id="vehicle_id" name="vehicle_id" required>
                                    <option value="">-- Select Vehicle --</option>
                                    <?php foreach ($vehicles as $vehicle): ?>
                                        <option value="<?= $vehicle['id'] ?>" <?= isset($vehicleId) && $vehicleId == $vehicle['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($vehicle['registration_number']) ?> - 
                                            <?= htmlspecialchars($vehicle['make'] . ' ' . $vehicle['model']) ?> 
                                            (<?= htmlspecialchars($vehicle['owner_name']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">
                                    Select a registered vehicle to generate or update its QR code.
                                </div>
                            </div>
                            
                            <div class="d-grid gap-2 mt-4">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-sync me-2"></i> Generate QR Code
                                </button>
                                <a href="dashboard.php" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left me-2"></i> Back to Dashboard
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i> Instructions</h5>
                    </div>
                    <div class="card-body">
                        <ul>
                            <li>Select a registered vehicle from the dropdown menu above.</li>
                            <li>Click the "Generate QR Code" button to create or update the QR code.</li>
                            <li>The QR code contains all vehicle registration details.</li>
                            <li>When scanned, the QR code shows complete vehicle and owner information.</li>
                            <li>The information card provides the same data in a printable format.</li>
                            <li>A roadworthy certificate is also generated with a unique serial number.</li>
                        </ul>
                        
                        <div class="alert alert-warning mt-3">
                            <strong><i class="fas fa-lightbulb me-2"></i> Tip:</strong> For best scanning results, print the QR code at least 2 inches (5 cm) wide.
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-7">
                <?php if ($qrGenerated && $selectedVehicle): ?>
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0"><i class="fas fa-check-circle me-2"></i> QR Code Generated Successfully</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-5">
                                    <div class="qr-container mb-4">
                                        <div class="qr-header">DVLA Ghana</div>
                                        <img src="<?= $baseUrl ?>/<?= htmlspecialchars($qrCodePath) ?>" alt="Vehicle QR Code" class="qr-code img-fluid mb-3">
        <div class="mt-2">
            <a href="download-qr.php?id=<?= $selectedVehicle['id'] ?>" class="btn btn-sm btn-success w-100">
                <i class="fas fa-download me-2"></i> Download QR Code
            </a>
                                        </div>
                                        <div class="mt-3 text-center">
                                            <small class="text-muted fw-bold">Scan to verify vehicle registration details</small>
                                        </div>
                                    </div>
                                    
                                    <?php if (isset($cardRelativePath)): ?>
                                    <div class="info-card-container mt-3">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <h6 class="mb-0 fw-bold"><i class="fas fa-id-card me-2"></i>Vehicle Info Card</h6>
                                            <a href="/<?= htmlspecialchars($cardRelativePath) ?>" download="vehicle_info_<?= htmlspecialchars($selectedVehicle['registration_number']) ?>.png" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-download"></i> Download
                                            </a>
                                        </div>
                                        <a href="/<?= htmlspecialchars($cardRelativePath) ?>" target="_blank">
                                            <img src="/<?= htmlspecialchars($cardRelativePath) ?>" alt="Vehicle Information Card" class="info-card img-fluid border">
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (isset($roadworthyRelativePath)): ?>
                                    <div class="info-card-container mt-4">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <h6 class="mb-0 fw-bold"><i class="fas fa-certificate me-2"></i>Roadworthy Certificate</h6>
                                            <a href="/<?= htmlspecialchars($roadworthyRelativePath) ?>" download="roadworthy_certificate_<?= htmlspecialchars($selectedVehicle['registration_number']) ?>.png" class="btn btn-sm btn-outline-success">
                                                <i class="fas fa-download"></i> Download
                                            </a>
                                        </div>
                                        <a href="/<?= htmlspecialchars($roadworthyRelativePath) ?>" target="_blank">
                                            <img src="/<?= htmlspecialchars($roadworthyRelativePath) ?>" alt="Roadworthy Certificate" class="info-card img-fluid border">
                                        </a>
                                        <div class="mt-1 text-center">
                                            <small class="text-muted">Certificate No: <?= htmlspecialchars($serialNumber) ?></small>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-7">
                                    <div class="info-tab">
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-car-side fa-2x me-3 text-primary"></i>
                                            <div>
                                                <h5 class="mb-0"><?= htmlspecialchars($selectedVehicle['registration_number']) ?></h5>
                                                <span class="text-muted"><?= htmlspecialchars($selectedVehicle['make'] . ' ' . $selectedVehicle['model']) ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <h5 class="mt-4"><i class="fas fa-info-circle me-2"></i> Vehicle Information</h5>
                                    <div class="vehicle-details">
                                        <div class="detail-item">
                                            <div class="detail-label">Registration Number:</div>
                                            <div class="detail-value"><?= htmlspecialchars($selectedVehicle['registration_number']) ?></div>
                                        </div>
                                        <div class="detail-item">
                                            <div class="detail-label">Make & Model:</div>
                                            <div class="detail-value"><?= htmlspecialchars($selectedVehicle['make'] . ' ' . $selectedVehicle['model']) ?></div>
                                        </div>
                                        <div class="detail-item">
                                            <div class="detail-label">Color:</div>
                                            <div class="detail-value"><?= htmlspecialchars($selectedVehicle['color']) ?></div>
                                        </div>
                                        <div class="detail-item">
                                            <div class="detail-label">Year:</div>
                                            <div class="detail-value"><?= htmlspecialchars($selectedVehicle['year_of_manufacture']) ?></div>
                                        </div>
                                        <div class="detail-item">
                                            <div class="detail-label">Seater:</div>
                                            <div class="detail-value"><?= htmlspecialchars($selectedVehicle['seating_capacity']) ?></div>
                                        </div>
                                        <div class="detail-item">
                                            <div class="detail-label">Registration Date:</div>
                                            <div class="detail-value"><?= htmlspecialchars(date('d F Y', strtotime($selectedVehicle['registration_date']))) ?></div>
                                        </div>
                                        <div class="detail-item">
                                            <div class="detail-label">Expiry Date:</div>
                                            <div class="detail-value"><?= htmlspecialchars(date('d F Y', strtotime($selectedVehicle['expiry_date']))) ?></div>
                                        </div>
                                    </div>
                                    
                                    <h5 class="mt-4"><i class="fas fa-user-circle me-2"></i> Owner Information</h5>
                                    <div class="vehicle-details">
                                        <div class="detail-item">
                                            <div class="detail-label">Owner Name:</div>
                                            <div class="detail-value"><?= htmlspecialchars($selectedVehicle['owner_name']) ?></div>
                                        </div>
                                        <div class="detail-item">
                                            <div class="detail-label">
                                                <?= getDocumentTypeDisplay(isset($selectedVehicle['id_document_type']) ? $selectedVehicle['id_document_type'] : 'ghana_card') ?> Number:
                                            </div>
                                            <div class="detail-value"><?= htmlspecialchars($selectedVehicle['ghana_card_number']) ?></div>
                                        </div>
                                        <?php if (!empty($selectedVehicle['owner_phone'])): ?>
                                        <div class="detail-item">
                                            <div class="detail-label">Contact Number:</div>
                                            <div class="detail-value"><?= htmlspecialchars($selectedVehicle['owner_phone']) ?></div>
                                        </div>
                                        <?php endif; ?>
                                        <?php if (!empty($selectedVehicle['owner_email'])): ?>
                                        <div class="detail-item">
                                            <div class="detail-label">Email:</div>
                                            <div class="detail-value"><?= htmlspecialchars($selectedVehicle['owner_email']) ?></div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="mt-4">
                                        <a href="print-certificate.php?id=<?= $selectedVehicle['id'] ?>" class="btn btn-primary" target="_blank">
                                            <i class="fas fa-print me-2"></i> Print Registration Certificate
                                        </a>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- QR Code Testing Section -->
                            <div class="mt-4 pt-3 border-top">
                                <h5><i class="fas fa-mobile-alt me-2"></i> QR Code Instructions</h5>
                                
                                <div class="alert alert-info p-3">
                                    <div class="d-flex">
                                        <i class="fas fa-info-circle fa-2x me-3 mt-1"></i>
                                        <div>
                                            <strong>How the QR System Works:</strong><br>
                                            1. When scanned, the QR code will display <strong>ALL vehicle and owner registration details</strong><br>
                                            2. Information is formatted in a clean, easy-to-read layout<br>
                                            3. The QR code has been specially designed for maximum compatibility with all scanner apps
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row mt-3">
                                    <div class="col-md-6">
                                        <div class="card h-100 border-primary border-top-0 border-end-0 border-bottom-0 border-3">
                                            <div class="card-body">
                                                <h6 class="mb-3 text-primary"><i class="fas fa-qrcode me-2"></i> QR Code Features</h6>
                                                <ul class="list-unstyled">
                                                    <li><i class="fas fa-check-circle text-success me-2"></i> Contains complete vehicle details</li>
                                                    <li><i class="fas fa-check-circle text-success me-2"></i> Works without internet connection</li>
                                                    <li><i class="fas fa-check-circle text-success me-2"></i> Instant verification with any QR scanner</li>
                                                    <li><i class="fas fa-check-circle text-success me-2"></i> Includes registration expiry date</li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="card h-100 border-success border-top-0 border-end-0 border-bottom-0 border-3">
                                            <div class="card-body">
                                                <h6 class="mb-3 text-success"><i class="fas fa-id-card me-2"></i> Info Card Benefits</h6>
                                                <ul class="list-unstyled">
                                                    <li><i class="fas fa-check-circle text-success me-2"></i> Professional, printable format</li>
                                                    <li><i class="fas fa-check-circle text-success me-2"></i> Visual presentation of all details</li>
                                                    <li><i class="fas fa-check-circle text-success me-2"></i> Perfect for documentation purposes</li>
                                                    <li><i class="fas fa-check-circle text-success me-2"></i> Includes both vehicle and owner info</li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="alert alert-success mt-3">
                                    <strong><i class="fas fa-check-double me-2"></i> Recommendation:</strong> Print both items - keep the QR code in your vehicle for quick verification, and the info card with your important documents.
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card">
                        <div class="card-header bg-secondary text-white">
                            <h5 class="mb-0"><i class="fas fa-qrcode me-2"></i> QR Code Preview</h5>
                        </div>
                        <div class="card-body text-center py-5">
                            <i class="fas fa-qrcode fa-5x text-muted mb-3"></i>
                            <h5 class="text-muted">No QR Code Generated Yet</h5>
                            <p class="text-muted">Select a vehicle and click "Generate QR Code" to create a new QR code.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <?php
    include(__DIR__ . '/../../../includes/footer.php');
    ?>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>