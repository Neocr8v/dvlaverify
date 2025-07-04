<?php
// filepath: /Applications/XAMPP/xamppfiles/htdocs/dvlaregister/src/views/admin/vehicle-details.php
session_start();
require_once __DIR__ . '/../../../config/database.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../auth/login.php');
    exit;
}

// Initialize variables
$error = '';
$success = '';
$vehicle_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$vehicle = null;
$owner = null;
$history = [];

// Fetch vehicle details
try {
    if ($vehicle_id > 0) {
        // Get vehicle data
        $stmt = $pdo->prepare("
            SELECT v.*, u.full_name, u.email, u.phone_number, u.ghana_card_number, u.address
            FROM vehicles v
            LEFT JOIN users u ON v.owner_id = u.id
            WHERE v.id = :id
        ");
        $stmt->execute(['id' => $vehicle_id]);
        $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$vehicle) {
            $error = "Vehicle not found";
        } else {
            // Format owner data
            $owner = [
                'full_name' => $vehicle['full_name'],
                'email' => $vehicle['email'],
                'phone_number' => $vehicle['phone_number'],
                'ghana_card_number' => $vehicle['ghana_card_number'],
                'address' => $vehicle['address']
            ];
            
            // Check if vehicle_history table exists
            try {
                $tableCheck = $pdo->query("SHOW TABLES LIKE 'vehicle_history'");
                $tableExists = ($tableCheck && $tableCheck->rowCount() > 0);
                
                if (!$tableExists) {
                    // Create the table if it doesn't exist
                    $pdo->exec("
                        CREATE TABLE vehicle_history (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            vehicle_id INT NOT NULL,
                            action VARCHAR(50) NOT NULL,
                            description TEXT,
                            previous_status VARCHAR(50),
                            new_status VARCHAR(50),
                            updated_by INT,
                            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
                            FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
                        )
                    ");
                }
                
                // Get vehicle history
                $historyStmt = $pdo->prepare("
                    SELECT vh.*, u.full_name as updated_by_name
                    FROM vehicle_history vh
                    LEFT JOIN users u ON vh.updated_by = u.id
                    WHERE vh.vehicle_id = :vehicle_id
                    ORDER BY vh.created_at DESC
                ");
                $historyStmt->execute(['vehicle_id' => $vehicle_id]);
                $history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
                
            } catch (PDOException $historyError) {
                // Don't show the error to the user, just continue with empty history
                $history = [];
            }
        }
    } else {
        $error = "Invalid vehicle ID";
    }
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}

// Handle vehicle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $new_status = $_POST['new_status'] ?? '';
    
    if (empty($new_status)) {
        $error = "Status cannot be empty";
    } else {
        try {
            $pdo->beginTransaction();
            
            $prev_status = $vehicle['status'];
            
            // Update status
            $stmt = $pdo->prepare("
                UPDATE vehicles 
                SET status = :status, updated_at = NOW()
                WHERE id = :id
            ");
            
            $stmt->execute([
                'status' => $new_status,
                'id' => $vehicle_id
            ]);
            
            // Check if vehicle_history table exists before trying to insert
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'vehicle_history'");
            $tableExists = ($tableCheck && $tableCheck->rowCount() > 0);
            
            if (!$tableExists) {
                // Create table if it doesn't exist
                $pdo->exec("
                    CREATE TABLE vehicle_history (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        vehicle_id INT NOT NULL,
                        action VARCHAR(50) NOT NULL,
                        description TEXT,
                        previous_status VARCHAR(50),
                        new_status VARCHAR(50),
                        updated_by INT,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
                        FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
                    )
                ");
            }
            
            // Add to history
            $historyStmt = $pdo->prepare("
                INSERT INTO vehicle_history 
                (vehicle_id, action, description, previous_status, new_status, updated_by)
                VALUES (:vehicle_id, :action, :description, :previous_status, :new_status, :updated_by)
            ");
            
            $historyStmt->execute([
                'vehicle_id' => $vehicle_id,
                'action' => 'Status Change',
                'description' => 'Vehicle status changed from ' . $prev_status . ' to ' . $new_status,
                'previous_status' => $prev_status,
                'new_status' => $new_status,
                'updated_by' => $_SESSION['user_id']
            ]);
            
            $pdo->commit();
            $success = "Vehicle status updated successfully";
            
            // Refresh vehicle data
            $stmt = $pdo->prepare("
                SELECT v.*, u.full_name, u.email, u.phone_number, u.ghana_card_number, u.address
                FROM vehicles v
                LEFT JOIN users u ON v.owner_id = u.id
                WHERE v.id = :id
            ");
            $stmt->execute(['id' => $vehicle_id]);
            $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Refresh owner data
            $owner = [
                'full_name' => $vehicle['full_name'],
                'email' => $vehicle['email'],
                'phone_number' => $vehicle['phone_number'],
                'ghana_card_number' => $vehicle['ghana_card_number'],
                'address' => $vehicle['address']
            ];
            
            // Refresh history data
            $historyStmt = $pdo->prepare("
                SELECT vh.*, u.full_name as updated_by_name
                FROM vehicle_history vh
                LEFT JOIN users u ON vh.updated_by = u.id
                WHERE vh.vehicle_id = :vehicle_id
                ORDER BY vh.created_at DESC
            ");
            $historyStmt->execute(['vehicle_id' => $vehicle_id]);
            $history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Error updating vehicle status: " . $e->getMessage();
        }
    }
}

// Handle adding notes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_note'])) {
    $note = trim($_POST['note'] ?? '');
    
    if (empty($note)) {
        $error = "Note cannot be empty";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Check if vehicle_history table exists before trying to insert
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'vehicle_history'");
            $tableExists = ($tableCheck && $tableCheck->rowCount() > 0);
            
            if (!$tableExists) {
                // Create table if it doesn't exist
                $pdo->exec("
                    CREATE TABLE vehicle_history (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        vehicle_id INT NOT NULL,
                        action VARCHAR(50) NOT NULL,
                        description TEXT,
                        previous_status VARCHAR(50),
                        new_status VARCHAR(50),
                        updated_by INT,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
                        FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
                    )
                ");
            }
            
            // Add to history
            $historyStmt = $pdo->prepare("
                INSERT INTO vehicle_history 
                (vehicle_id, action, description, updated_by)
                VALUES (:vehicle_id, :action, :description, :updated_by)
            ");
            
            $historyStmt->execute([
                'vehicle_id' => $vehicle_id,
                'action' => 'Admin Note',
                'description' => $note,
                'updated_by' => $_SESSION['user_id']
            ]);
            
            $pdo->commit();
            $success = "Note added successfully";
            
            // Refresh history data
            $historyStmt = $pdo->prepare("
                SELECT vh.*, u.full_name as updated_by_name
                FROM vehicle_history vh
                LEFT JOIN users u ON vh.updated_by = u.id
                WHERE vh.vehicle_id = :vehicle_id
                ORDER BY vh.created_at DESC
            ");
            $historyStmt->execute(['vehicle_id' => $vehicle_id]);
            $history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Error adding note: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Details | Admin | Vehicle Registration System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .page-header {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 5px solid #0d6efd;
        }
        
        .vehicle-card {
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            border-radius: 10px;
            overflow: hidden;
        }
        
        .vehicle-id-badge {
            font-size: 0.85rem;
            background-color: #e9ecef;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            margin-left: 10px;
        }
        
        .license-plate {
            display: inline-block;
            background-color: #ffc107;
            color: #212529;
            font-weight: bold;
            padding: 5px 15px;
            border-radius: 5px;
            border: 1px solid #212529;
            font-family: 'Courier New', monospace;
            letter-spacing: 1px;
        }
        
        .status-badge {
            text-transform: capitalize;
            font-weight: 500;
            padding: 5px 10px;
            border-radius: 15px;
        }
        
        .active-badge {
            background-color: #d1e7dd;
            color: #0f5132;
        }
        
        .pending-badge {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .expired-badge {
            background-color: #f8d7da;
            color: #842029;
        }
        
        .suspended-badge {
            background-color: #cff4fc;
            color: #055160;
        }
        
        .history-timeline {
            position: relative;
            padding: 1rem 0;
        }
        
        .history-timeline:before {
            content: '';
            position: absolute;
            top: 0;
            left: 1.25rem;
            height: 100%;
            width: 2px;
            background-color: #dee2e6;
        }
        
        .history-item {
            position: relative;
            padding-left: 3rem;
            padding-bottom: 1rem;
        }
        
        .history-icon {
            position: absolute;
            left: 0.5rem;
            top: 0;
            width: 1.5rem;
            height: 1.5rem;
            border-radius: 50%;
            text-align: center;
            line-height: 1.5rem;
            color: white;
            z-index: 1;
        }
        
        .action-status-change {
            background-color: #0d6efd;
        }
        
        .action-registration {
            background-color: #198754;
        }
        
        .action-note {
            background-color: #6c757d;
        }
        
        .action-other {
            background-color: #fd7e14;
        }
        
        .history-content {
            border: 1px solid #dee2e6;
            padding: 0.75rem;
            border-radius: 0.25rem;
            background-color: #f8f9fa;
        }
        
        .document-card {
            transition: transform 0.2s;
        }
        
        .document-card:hover {
            transform: translateY(-5px);
        }
        
        .info-icon {
            color: #6c757d;
            font-size: 0.875rem;
            cursor: help;
        }
        
        .owner-avatar {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 50%;
        }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../../../includes/header.php'; ?>
    
    <div class="container my-4">
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($success)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <!-- Breadcrumb -->
        <nav aria-label="breadcrumb" class="mb-4">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="all-vehicles.php">All Vehicles</a></li>
                <li class="breadcrumb-item active">Vehicle Details</li>
            </ol>
        </nav>
        
        <?php if ($vehicle): ?>
            <!-- Page Header -->
            <div class="page-header d-flex justify-content-between align-items-center flex-wrap">
                <div class="mb-3 mb-md-0">
                    <h2 class="mb-1">
                        <?= htmlspecialchars($vehicle['make']) ?> <?= htmlspecialchars($vehicle['model']) ?>
                        <span class="vehicle-id-badge">ID: <?= $vehicle['id'] ?></span>
                    </h2>
                    <div class="license-plate"><?= htmlspecialchars($vehicle['license_plate']) ?></div>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a href="edit-vehicle.php?id=<?= $vehicle['id'] ?>" class="btn btn-primary">
                        <i class="fas fa-edit me-2"></i>Edit Vehicle
                    </a>
                    <a href="print-certificate.php?id=<?= $vehicle['id'] ?>" class="btn btn-success">
                        <i class="fas fa-print me-2"></i>Print Certificate
                    </a>
                    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#statusModal">
                        <i class="fas fa-toggle-on me-2"></i>Change Status
                    </button>
                </div>
            </div>
            
            <div class="row mt-4">
                <div class="col-lg-8">
                    <!-- Vehicle Details Card -->
                    <div class="card vehicle-card mb-4">
                        <div class="card-header bg-light">
                            <h5 class="mb-0"><i class="fas fa-car me-2"></i>Vehicle Information</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <table class="table table-borderless">
                                        <tr>
                                            <th class="ps-0" width="40%">Status:</th>
                                            <td>
                                                <?php
                                                $statusClass = match($vehicle['status']) {
                                                    'active' => 'active-badge',
                                                    'pending' => 'pending-badge',
                                                    'expired' => 'expired-badge',
                                                    'suspended' => 'suspended-badge',
                                                    default => ''
                                                };
                                                ?>
                                                <span class="status-badge <?= $statusClass ?>">
                                                    <?= htmlspecialchars($vehicle['status']) ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th class="ps-0">Registration Date:</th>
                                            <td><?= date('F j, Y', strtotime($vehicle['registration_date'] ?? $vehicle['created_at'])) ?></td>
                                        </tr>
                                        <tr>
                                            <th class="ps-0">Expiry Date:</th>
                                            <td>
                                                <?php if (!empty($vehicle['expiry_date'])): ?>
                                                    <?= date('F j, Y', strtotime($vehicle['expiry_date'])) ?>
                                                    <?php 
                                                    $today = new DateTime();
                                                    $expiry = new DateTime($vehicle['expiry_date']);
                                                    $days_diff = $today->diff($expiry)->days;
                                                    $expired = $today > $expiry;
                                                    
                                                    if ($expired) {
                                                        echo '<span class="badge bg-danger ms-2">Expired ' . $days_diff . ' days ago</span>';
                                                    } elseif ($days_diff <= 30) {
                                                        echo '<span class="badge bg-warning ms-2">Expires in ' . $days_diff . ' days</span>';
                                                    }
                                                    ?>
                                                <?php else: ?>
                                                    Not set
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                                <div class="col-md-6">
                                    <table class="table table-borderless">
                                        <tr>
                                            <th class="ps-0" width="40%">Created At:</th>
                                            <td><?= date('F j, Y, g:i a', strtotime($vehicle['created_at'])) ?></td>
                                        </tr>
                                        <tr>
                                            <th class="ps-0">Last Updated:</th>
                                            <td><?= date('F j, Y, g:i a', strtotime($vehicle['updated_at'])) ?></td>
                                        </tr>
                                        <tr>
                                            <th class="ps-0">Registration ID:</th>
                                            <td><?= htmlspecialchars($vehicle['registration_id'] ?? 'N/A') ?></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            
                            <hr>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <h6 class="mb-3">Vehicle Specifications</h6>
                                    <table class="table table-borderless">
                                        <tr>
                                            <th class="ps-0" width="40%">Make:</th>
                                            <td><?= htmlspecialchars($vehicle['make']) ?></td>
                                        </tr>
                                        <tr>
                                            <th class="ps-0">Model:</th>
                                            <td><?= htmlspecialchars($vehicle['model']) ?></td>
                                        </tr>
                                        <tr>
                                            <th class="ps-0">Year:</th>
                                            <td><?= htmlspecialchars($vehicle['year']) ?></td>
                                        </tr>
                                        <tr>
                                            <th class="ps-0">Color:</th>
                                            <td>
                                                <span class="d-inline-block rounded-circle me-2" 
                                                     style="width: 18px; height: 18px; background-color: <?= htmlspecialchars($vehicle['color']) ?>; border: 1px solid #dee2e6;"></span>
                                                <?= htmlspecialchars($vehicle['color']) ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th class="ps-0">VIN:</th>
                                            <td><?= htmlspecialchars($vehicle['vin']) ?></td>
                                        </tr>
                                    </table>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="mb-3">Additional Information</h6>
                                    <table class="table table-borderless">
                                        <tr>
                                            <th class="ps-0" width="40%">Engine Number:</th>
                                            <td><?= htmlspecialchars($vehicle['engine_number'] ?? 'N/A') ?></td>
                                        </tr>
                                        <tr>
                                            <th class="ps-0">Engine Type:</th>
                                            <td><?= htmlspecialchars($vehicle['engine_type'] ?? 'N/A') ?></td>
                                        </tr>
                                        <tr>
                                            <th class="ps-0">Fuel Type:</th>
                                            <td><?= htmlspecialchars($vehicle['fuel_type'] ?? 'N/A') ?></td>
                                        </tr>
                                        <tr>
                                            <th class="ps-0">Seating Capacity:</th>
                                            <td><?= htmlspecialchars($vehicle['seating_capacity'] ?? 'N/A') ?></td>
                                        </tr>
                                        <tr>
                                            <th class="ps-0">Vehicle Type:</th>
                                            <td><?= htmlspecialchars($vehicle['vehicle_type'] ?? 'N/A') ?></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Vehicle History Timeline -->
                    <div class="card vehicle-card mb-4">
                        <div class="card-header bg-light d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="fas fa-history me-2"></i>Vehicle History</h5>
                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addNoteModal">
                                <i class="fas fa-plus-circle me-2"></i>Add Note
                            </button>
                        </div>
                        <div class="card-body">
                            <?php if (empty($history)): ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-info-circle text-muted mb-3" style="font-size: 3rem;"></i>
                                    <p class="mb-0">No history records found for this vehicle.</p>
                                </div>
                            <?php else: ?>
                                <div class="history-timeline">
                                    <?php foreach ($history as $record): 
                                        $iconClass = match($record['action']) {
                                            'Status Change' => 'action-status-change',
                                            'Registration' => 'action-registration',
                                            'Admin Note' => 'action-note',
                                            default => 'action-other'
                                        };
                                        
                                        $icon = match($record['action']) {
                                            'Status Change' => 'fa-toggle-on',
                                            'Registration' => 'fa-file-alt',
                                            'Admin Note' => 'fa-sticky-note',
                                            default => 'fa-circle-info'
                                        };
                                    ?>
                                        <div class="history-item">
                                            <div class="history-icon <?= $iconClass ?>">
                                                <i class="fas <?= $icon ?> fa-sm"></i>
                                            </div>
                                            <div class="history-content">
                                                <div class="d-flex justify-content-between">
                                                    <strong><?= htmlspecialchars($record['action']) ?></strong>
                                                    <small class="text-muted"><?= date('M j, Y g:i a', strtotime($record['created_at'])) ?></small>
                                                </div>
                                                <p class="mb-1"><?= htmlspecialchars($record['description']) ?></p>
                                                <?php if (!empty($record['previous_status']) && !empty($record['new_status'])): ?>
                                                    <div class="d-flex align-items-center">
                                                        <span class="status-badge <?= strtolower($record['previous_status']) ?>-badge"><?= htmlspecialchars($record['previous_status']) ?></span>
                                                        <i class="fas fa-long-arrow-alt-right mx-2"></i>
                                                        <span class="status-badge <?= strtolower($record['new_status']) ?>-badge"><?= htmlspecialchars($record['new_status']) ?></span>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($record['updated_by_name'])): ?>
                                                    <div class="mt-1">
                                                        <small class="text-muted">By: <?= htmlspecialchars($record['updated_by_name']) ?></small>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4">
                    <!-- Owner Information -->
                    <div class="card vehicle-card mb-4">
                        <div class="card-header bg-light">
                            <h5 class="mb-0"><i class="fas fa-user me-2"></i>Owner Information</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($owner['full_name'])): ?>
                                <div class="text-center py-3">
                                    <i class="fas fa-user-slash text-muted mb-3" style="font-size: 3rem;"></i>
                                    <p class="mb-0">No owner information available</p>
                                </div>
                            <?php else: ?>
                                <div class="text-center mb-3">
                                    <!-- If you have user avatars, you can use the following: -->
                                    <!-- <img src="/dvlaregister/<?= htmlspecialchars($owner['avatar'] ?? 'assets/img/default-avatar.png') ?>" alt="Owner Avatar" class="owner-avatar"> -->
                                    
                                    <!-- Otherwise, use initials: -->
                                    <div class="bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 80px; height: 80px; font-size: 2rem;">
                                        <?= strtoupper(substr($owner['full_name'] ?? 'U', 0, 1)) ?>
                                    </div>
                                    <h5><?= htmlspecialchars($owner['full_name']) ?></h5>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="d-flex align-items-center mb-2">
                                        <i class="fas fa-envelope text-muted me-2"></i>
                                        <span><?= htmlspecialchars($owner['email']) ?></span>
                                    </div>
                                    <?php if (!empty($owner['phone_number'])): ?>
                                        <div class="d-flex align-items-center mb-2">
                                            <i class="fas fa-phone text-muted me-2"></i>
                                            <span><?= htmlspecialchars($owner['phone_number']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($owner['ghana_card_number'])): ?>
                                        <div class="d-flex align-items-center mb-2">
                                            <i class="fas fa-id-card text-muted me-2"></i>
                                            <span><?= htmlspecialchars($owner['ghana_card_number']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($owner['address'])): ?>
                                        <div class="d-flex mb-2">
                                            <i class="fas fa-home text-muted me-2 mt-1"></i>
                                            <span><?= nl2br(htmlspecialchars($owner['address'])) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="d-grid">
                                    <a href="user-details.php?id=<?= $vehicle['owner_id'] ?>" class="btn btn-outline-primary">
                                        <i class="fas fa-user-circle me-2"></i>View Owner Profile
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Documents -->
                    <div class="card vehicle-card mb-4">
                        <div class="card-header bg-light">
                            <h5 class="mb-0"><i class="fas fa-file-alt me-2"></i>Documents</h5>
                        </div>
                        <div class="card-body">
                            <?php
                            // In a real application, you would fetch documents from a table
                            // This is just a placeholder example
                            $documents = [
                                [
                                    'name' => 'Vehicle Registration Certificate',
                                    'type' => 'PDF',
                                    'icon' => 'fa-file-pdf',
                                    'color' => 'danger',
                                    'date' => $vehicle['registration_date'] ?? $vehicle['created_at']
                                ],
                                [
                                    'name' => 'Insurance Certificate',
                                    'type' => 'PDF',
                                    'icon' => 'fa-file-pdf',
                                    'color' => 'danger',
                                    'date' => $vehicle['created_at']
                                ],
                                [
                                    'name' => 'Vehicle Photos',
                                    'type' => 'ZIP',
                                    'icon' => 'fa-file-archive',
                                    'color' => 'warning',
                                    'date' => $vehicle['created_at']
                                ]
                            ];
                            ?>
                            
                            <div class="row">
                                <?php foreach ($documents as $doc): ?>
                                    <div class="col-md-12 mb-3">
                                        <div class="document-card p-3 border rounded d-flex align-items-center">
                                            <div class="text-<?= $doc['color'] ?> me-3">
                                                <i class="fas <?= $doc['icon'] ?> fa-2x"></i>
                                            </div>
                                            <div class="flex-grow-1">
                                                <h6 class="mb-0"><?= $doc['name'] ?></h6>
                                                <small class="text-muted">Added on <?= date('M j, Y', strtotime($doc['date'])) ?></small>
                                            </div>
                                            <div>
                                                <a href="#" class="btn btn-sm btn-outline-secondary">
                                                    <i class="fas fa-download"></i>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                
                                <div class="col-md-12">
                                    <div class="d-grid">
                                        <a href="#" class="btn btn-outline-primary">
                                            <i class="fas fa-plus-circle me-2"></i>Upload Document
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quick Actions -->
                    <div class="card vehicle-card">
                        <div class="card-header bg-light">
                            <h5 class="mb-0"><i class="fas fa-bolt me-2"></i>Quick Actions</h5>
                        </div>
                        <div class="list-group list-group-flush">
                            <a href="edit-vehicle.php?id=<?= $vehicle['id'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                <span><i class="fas fa-edit me-2"></i>Edit Vehicle</span>
                                <i class="fas fa-chevron-right text-muted"></i>
                            </a>
                            <a href="print-certificate.php?id=<?= $vehicle['id'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                <span><i class="fas fa-print me-2"></i>Print Certificate</span>
                                <i class="fas fa-chevron-right text-muted"></i>
                            </a>
                            <a href="#" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" data-bs-toggle="modal" data-bs-target="#renewModal">
                                <span><i class="fas fa-sync-alt me-2"></i>Renew Registration</span>
                                <i class="fas fa-chevron-right text-muted"></i>
                            </a>
                            <a href="#" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" data-bs-toggle="modal" data-bs-target="#transferModal">
                                <span><i class="fas fa-exchange-alt me-2"></i>Transfer Ownership</span>
                                <i class="fas fa-chevron-right text-muted"></i>
                            </a>
                            <a href="#" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center text-danger" data-bs-toggle="modal" data-bs-target="#deleteModal">
                                <span><i class="fas fa-trash-alt me-2"></i>Delete Vehicle</span>
                                <i class="fas fa-chevron-right text-muted"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Status Modal -->
            <div class="modal fade" id="statusModal" tabindex="-1" aria-labelledby="statusModalLabel" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="statusModalLabel">Change Vehicle Status</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form method="post" action="">
                                <div class="mb-3">
                                    <p><strong>Vehicle:</strong> <?= htmlspecialchars($vehicle['make']) ?> <?= htmlspecialchars($vehicle['model']) ?> (<?= htmlspecialchars($vehicle['license_plate']) ?>)</p>
                                    <p><strong>Current Status:</strong> <span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($vehicle['status']) ?></span></p>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="new_status" class="form-label">New Status</label>
                                    <select class="form-select" id="new_status" name="new_status" required>
                                        <option value="">-- Select Status --</option>
                                        <option value="active" <?= $vehicle['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                        <option value="pending" <?= $vehicle['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                                        <option value="expired" <?= $vehicle['status'] === 'expired' ? 'selected' : '' ?>>Expired</option>
                                        <option value="suspended" <?= $vehicle['status'] === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                                    </select>
                                </div>
                                
                                <div class="text-end">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" name="update_status" class="btn btn-primary">Update Status</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Add Note Modal -->
            <div class="modal fade" id="addNoteModal" tabindex="-1" aria-labelledby="addNoteModalLabel" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="addNoteModalLabel">Add Note</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form method="post" action="">
                                <div class="mb-3">
                                    <label for="note" class="form-label">Note</label>
                                    <textarea class="form-control" id="note" name="note" rows="4" required></textarea>
                                    <div class="form-text">Add a note about this vehicle that will be saved in the history.</div>
                                </div>
                                
                                <div class="text-end">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" name="add_note" class="btn btn-primary">Add Note</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Renew Modal -->
            <div class="modal fade" id="renewModal" tabindex="-1" aria-labelledby="renewModalLabel" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="renewModalLabel">Renew Vehicle Registration</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p>This feature will be implemented soon.</p>
                            <p>Current registration expires on: 
                                <strong>
                                    <?= !empty($vehicle['expiry_date']) ? date('F j, Y', strtotime($vehicle['expiry_date'])) : 'Not set' ?>
                                </strong>
                            </p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Transfer Modal -->
            <div class="modal fade" id="transferModal" tabindex="-1" aria-labelledby="transferModalLabel" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="transferModalLabel">Transfer Vehicle Ownership</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p>This feature will be implemented soon.</p>
                            <p>Current owner: <strong><?= htmlspecialchars($owner['full_name'] ?? 'Not assigned') ?></strong></p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Delete Modal -->
            <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="deleteModalLabel">Delete Vehicle</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>Warning!</strong> This action cannot be undone.
                            </div>
                            <p>Are you sure you want to delete this vehicle?</p>
                            <p><strong>Vehicle:</strong> <?= htmlspecialchars($vehicle['make']) ?> <?= htmlspecialchars($vehicle['model']) ?> (<?= htmlspecialchars($vehicle['license_plate']) ?>)</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <a href="delete-vehicle.php?id=<?= $vehicle['id'] ?>&confirm=true" class="btn btn-danger">
                                <i class="fas fa-trash-alt me-2"></i>Delete Vehicle
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
        <?php else: ?>
            <div class="alert alert-warning" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i>
                Vehicle not found. The vehicle may have been removed or you have specified an invalid ID.
            </div>
            <div class="text-center py-5">
                <i class="fas fa-car-crash mb-3" style="font-size: 4rem; color: #6c757d;"></i>
                <h4>Vehicle Not Found</h4>
                <p class="text-muted">The vehicle you're looking for could not be found in our database.</p>
                <a href="all-vehicles.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left me-2"></i>Back to All Vehicles
                </a>
            </div>
        <?php endif; ?>
    </div>
    
    <?php include_once __DIR__ . '/../../../includes/footer.php'; ?>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>