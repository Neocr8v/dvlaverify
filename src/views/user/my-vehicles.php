<?php
// Start session and include database connection
session_start();
require_once __DIR__ . '/../../../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

// Get user data
$userId = $_SESSION['user_id'];
$username = $_SESSION['username'];
$role = $_SESSION['role'];

// Fetch user details
try {
    $userStmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
    $userStmt->execute(['id' => $userId]);
    $user = $userStmt->fetch();
    
    if (!$user) {
        $_SESSION['error'] = 'User not found';
        header('Location: ../auth/login.php');
        exit;
    }
    
    // Fetch vehicle owner ID associated with this user
    // This assumes there's a link between users and vehicle_owners via name, email or other identifier
    $ownerStmt = $pdo->prepare("
        SELECT vo.id as owner_id, vo.name, vo.ghana_card_number, vo.phone, vo.email, 
               vo.passport_photo_path
        FROM vehicle_owners vo
        WHERE 
            (LOWER(vo.name) = LOWER(:full_name))
            OR (vo.email IS NOT NULL AND LOWER(vo.email) = LOWER(:email))
        LIMIT 1
    ");
    
    $ownerStmt->execute([
        'full_name' => $user['full_name'],
        'email' => $user['email'] ?? ''
    ]);
    
    $ownerData = $ownerStmt->fetch();
    
    // Fetch vehicles associated with this owner
    $vehicles = [];
    if ($ownerData) {
        $vehicleStmt = $pdo->prepare("
            SELECT v.*, 
                   DATEDIFF(v.expiry_date, CURRENT_DATE()) as days_remaining
            FROM vehicles v
            WHERE v.owner_id = :owner_id
            ORDER BY v.registration_date DESC
        ");
        $vehicleStmt->execute(['owner_id' => $ownerData['owner_id']]);
        $vehicles = $vehicleStmt->fetchAll();
    }
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}

// Function to determine status badge class
function getStatusBadgeClass($daysRemaining) {
    if ($daysRemaining < 0) {
        return 'danger';
    } elseif ($daysRemaining <= 30) {
        return 'warning';
    } else {
        return 'success';
    }
}

// Function to determine status text
function getStatusText($daysRemaining) {
    if ($daysRemaining < 0) {
        return 'Expired';
    } elseif ($daysRemaining <= 30) {
        return 'Expiring Soon';
    } else {
        return 'Active';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Vehicles - Vehicle Registration System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .page-header {
            background-color: #fff;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            margin-bottom: 1.5rem;
            padding: 1.5rem 0;
        }
        .user-welcome {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .profile-card {
            border-radius: 10px;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            overflow: hidden;
        }
        .profile-header {
            background-color: #0d6efd;
            color: white;
            padding: 20px;
        }
        .vehicle-card {
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 20px;
            transition: transform 0.2s;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
        .vehicle-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.1);
        }
        .vehicle-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px;
            background-color: #f8f9fa;
            border-bottom: 1px solid #e0e0e0;
        }
        .vehicle-reg-number {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 0;
        }
        .vehicle-body {
            padding: 15px;
        }
        .vehicle-detail {
            margin-bottom: 10px;
            display: flex;
        }
        .vehicle-label {
            font-weight: 500;
            width: 140px;
            color: #6c757d;
        }
        .vehicle-value {
            flex: 1;
        }
        .vehicle-footer {
            padding: 15px;
            background-color: #f8f9fa;
            border-top: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .reg-date {
            color: #6c757d;
            font-size: 0.85rem;
        }
        .no-vehicles {
            padding: 50px 0;
            text-align: center;
        }
        .vehicle-image {
            height: 120px;
            display: flex;
            justify-content: center;
            align-items: center;
            background-color: #f1f3f5;
        }
        .vehicle-image img {
            max-height: 100%;
            max-width: 100%;
            object-fit: contain;
        }
        .vehicle-image-placeholder {
            font-size: 3rem;
            color: #adb5bd;
        }
        .vehicle-actions {
            text-align: center;
        }
        .btn-action {
            margin: 0 5px;
        }
        .expiry-indicator {
            margin-left: 10px;
        }
        @media (max-width: 767px) {
            .vehicle-detail {
                flex-direction: column;
            }
            .vehicle-label {
                width: 100%;
                margin-bottom: 5px;
            }
        }
    </style>
</head>
<body>
<?php include_once __DIR__ . '/../../../includes/header.php'; ?>
    <!-- Header -->
    <div class="container mt-4">
        <div class="row">
            <!-- Sidebar/User Profile -->
            <div class="col-lg-3 mb-4">
                <div class="profile-card">
                    <div class="profile-header text-center">
                        <?php if (!empty($ownerData['passport_photo_path'])): ?>
                            <img src="/<?= htmlspecialchars($ownerData['passport_photo_path']) ?>" alt="Profile Photo" class="rounded-circle mb-3" width="100" height="100" style="object-fit: cover;">
                        <?php else: ?>
                            <div class="mb-3 rounded-circle bg-light d-flex align-items-center justify-content-center mx-auto" style="width: 100px; height: 100px;">
                                <i class="fas fa-user text-secondary" style="font-size: 2.5rem;"></i>
                            </div>
                        <?php endif; ?>
                        <h4><?= htmlspecialchars($user['full_name'] ?? $username) ?></h4>
                        <p class="mb-0"><?= htmlspecialchars($user['email'] ?? '') ?></p>
                    </div>
                    <div class="list-group list-group-flush">
                        <a href="dashboard.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                        </a>
                        <a href="my-vehicles.php" class="list-group-item list-group-item-action active">
                            <i class="fas fa-car me-2"></i>My Vehicles
                        </a>
                        <a href="profile.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-user-circle me-2"></i>My Profile
                        </a>
                        <a href="/src/views/auth/logout.php" class="list-group-item list-group-item-action text-danger">
                            <i class="fas fa-sign-out-alt me-2"></i>Logout
                        </a>
                    </div>
                </div>
                
                <?php if (!empty($ownerData)): ?>
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0">Owner Information</h5>
                    </div>
                    <div class="card-body">
                        <p><strong>Ghana Card:</strong><br><?= htmlspecialchars($ownerData['ghana_card_number'] ?? 'Not provided') ?></p>
                        <p><strong>Phone:</strong><br><?= htmlspecialchars($ownerData['phone'] ?? 'Not provided') ?></p>
                        <p><strong>Email:</strong><br><?= htmlspecialchars($ownerData['email'] ?? 'Not provided') ?></p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Main Content -->
            <div class="col-lg-9">
                <div class="card mb-4">
                    <div class="card-header bg-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="fas fa-car me-2"></i>My Registered Vehicles</h5>
                            <div>
                                <span class="badge bg-primary"><?= count($vehicles) ?> Vehicle<?= count($vehicles) !== 1 ? 's' : '' ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($vehicles)): ?>
                            <?php foreach ($vehicles as $vehicle): ?>
                                <?php 
                                    $daysRemaining = $vehicle['days_remaining'];
                                    $statusClass = getStatusBadgeClass($daysRemaining);
                                    $statusText = getStatusText($daysRemaining);
                                ?>
                                <div class="vehicle-card">
                                    <div class="vehicle-header">
                                        <h5 class="vehicle-reg-number">
                                            <?= htmlspecialchars($vehicle['registration_number']) ?>
                                            <span class="badge bg-<?= $statusClass ?> ms-2"><?= $statusText ?></span>
                                        </h5>
                                        <span>
                                            <?php if ($daysRemaining > 0): ?>
                                                <span class="badge bg-light text-dark border">
                                                    <?= $daysRemaining ?> days remaining
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">
                                                    Expired <?= abs($daysRemaining) ?> days ago
                                                </span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    
                                    <div class="row g-0">
                                        <div class="col-md-3">
                                            <div class="vehicle-image">
                                                <?php if (!empty($vehicle['image_path'])): ?>
                                                    <img src="/dvlaregister/<?= htmlspecialchars($vehicle['image_path']) ?>" alt="<?= htmlspecialchars($vehicle['make'] . ' ' . $vehicle['model']) ?>">
                                                <?php else: ?>
                                                    <div class="vehicle-image-placeholder">
                                                        <i class="fas fa-car"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="col-md-9">
                                            <div class="vehicle-body">
                                                <div class="vehicle-detail">
                                                    <div class="vehicle-label">Make & Model:</div>
                                                    <div class="vehicle-value"><?= htmlspecialchars($vehicle['make'] . ' ' . $vehicle['model']) ?></div>
                                                </div>
                                                <div class="vehicle-detail">
                                                    <div class="vehicle-label">Year:</div>
                                                    <div class="vehicle-value"><?= htmlspecialchars($vehicle['year_of_manufacture']) ?></div>
                                                </div>
                                                <div class="vehicle-detail">
                                                    <div class="vehicle-label">Vehicle Type:</div>
                                                    <div class="vehicle-value"><?= htmlspecialchars($vehicle['vehicle_type']) ?></div>
                                                </div>
                                                <div class="vehicle-detail">
                                                    <div class="vehicle-label">Color:</div>
                                                    <div class="vehicle-value"><?= htmlspecialchars($vehicle['color']) ?></div>
                                                </div>
                                                <div class="vehicle-detail">
                                                    <div class="vehicle-label">Chassis Number:</div>
                                                    <div class="vehicle-value"><?= htmlspecialchars($vehicle['chassis_number']) ?></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="vehicle-footer">
                                        <div class="reg-date">
                                            <i class="fas fa-calendar-alt me-1"></i> 
                                            Registered: <?= date('d M Y', strtotime($vehicle['registration_date'])) ?>
                                        </div>
                                        <div class="vehicle-actions">
                                            <a href="vehicle-details.php?id=<?= $vehicle['id'] ?>" class="btn btn-sm btn-primary btn-action">
                                                <i class="fas fa-info-circle me-1"></i> Details
                                            </a>
                                            <?php if ($statusText == 'Expired' || $statusText == 'Expiring Soon'): ?>
                                                <a href="renew-vehicle.php?id=<?= $vehicle['id'] ?>" class="btn btn-sm btn-warning btn-action">
                                                    <i class="fas fa-sync me-1"></i> Renew
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                        <?php elseif (empty($ownerData)): ?>
                            <div class="no-vehicles text-center">
                                <i class="fas fa-exclamation-circle text-warning mb-3" style="font-size: 3rem;"></i>
                                <h4 class="mb-3">No Owner Record Found</h4>
                                <p class="mb-4">Your user account is not linked to any vehicle owner records in our system.</p>
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>
                                    Please contact DVLA with your Ghana Card details to link your records to your account.
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="no-vehicles text-center">
                                <i class="fas fa-car text-muted mb-3" style="font-size: 3rem;"></i>
                                <h4 class="mb-3">No Vehicles Found</h4>
                                <p>You don't have any registered vehicles in the system yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- How to Register Info Card -->
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>How to Register a Vehicle</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="d-flex mb-3">
                                    <div class="me-3 text-primary">
                                        <i class="fas fa-1 fa-fw"></i>
                                    </div>
                                    <div>
                                        <h6>Visit DVLA Office</h6>
                                        <p class="text-muted small">Visit your nearest DVLA office with your vehicle documents.</p>
                                    </div>
                                </div>
                                <div class="d-flex mb-3">
                                    <div class="me-3 text-primary">
                                        <i class="fas fa-2 fa-fw"></i>
                                    </div>
                                    <div>
                                        <h6>Submit Documentation</h6>
                                        <p class="text-muted small">Submit proof of ownership, technical specifications, and customs documents.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="d-flex mb-3">
                                    <div class="me-3 text-primary">
                                        <i class="fas fa-3 fa-fw"></i>
                                    </div>
                                    <div>
                                        <h6>Vehicle Inspection</h6>
                                        <p class="text-muted small">Complete the required vehicle inspection for roadworthiness.</p>
                                    </div>
                                </div>
                                <div class="d-flex">
                                    <div class="me-3 text-primary">
                                        <i class="fas fa-4 fa-fw"></i>
                                    </div>
                                    <div>
                                        <h6>Payment & Collection</h6>
                                        <p class="text-muted small">Pay the registration fees and collect your vehicle documents.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="alert alert-light border mt-3 mb-0">
                            <i class="fas fa-phone-alt me-2 text-primary"></i>
                            For assistance, contact our helpline at <strong>0302-746-760</strong> or email <strong>info@dvla.gov.gh</strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php include_once __DIR__ . '/../../../includes/footer.php'; ?>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>