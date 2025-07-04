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
    
    // Check if roadworthy certificate exists and is valid
    if (empty($vehicle['roadworthy_certificate_path']) || !file_exists(__DIR__ . '/../../../' . $vehicle['roadworthy_certificate_path'])) {
        // Certificate doesn't exist or file is missing, generate it first
        header("Location: generate-roadworthy.php?id=$vehicleId&return=view&fresh=1");
        exit;
    }
    
    // Certificate file path
    $certificateFilePath = __DIR__ . '/../../../' . $vehicle['roadworthy_certificate_path'];
    
    // Output the certificate as an image
    header('Content-Type: image/png');
    header('Content-Disposition: inline; filename="roadworthy_' . $vehicle['registration_number'] . '.png"');
    header('Content-Length: ' . filesize($certificateFilePath));
    readfile($certificateFilePath);
    exit;
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>