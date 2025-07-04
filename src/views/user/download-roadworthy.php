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
    
    // Check if roadworthy certificate exists
    if (empty($vehicle['roadworthy_certificate_path'])) {
        die("Roadworthy certificate not found. Please generate one first.");
    }
    
    // Certificate file path
    $certificateFilePath = __DIR__ . '/../../../' . $vehicle['roadworthy_certificate_path'];
    
    if (!file_exists($certificateFilePath)) {
        die("Certificate file not found.");
    }
    
    // Create a proper filename for download
    $downloadFilename = str_replace(' ', '_', $vehicle['registration_number']) . '_roadworthy_certificate.png';
    
    // Set proper headers for file download
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $downloadFilename . '"');
    header('Content-Length: ' . filesize($certificateFilePath));
    header('Cache-Control: private');
    header('Pragma: private');
    header('Expires: 0');
    
    // Output the file
    readfile($certificateFilePath);
    exit;
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>