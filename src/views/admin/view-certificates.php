<?php
// filepath: /Applications/XAMPP/xamppfiles/htdocs/dvlaregister/src/views/admin/view-certificates.php
session_start();
require_once __DIR__ . '/../../../config/database.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../views/auth/login.php');
    exit;
}

// Determine base URL dynamically for assets
$baseUrl = '';
$scriptDir = dirname(dirname(dirname(dirname($_SERVER['SCRIPT_NAME']))));
if ($scriptDir !== '/' && $scriptDir !== '\\') {
    $baseUrl = $scriptDir;
}

// Initialize variables
$search = $_GET['search'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 10; // Vehicles per page
$offset = ($page - 1) * $perPage;
$error = '';
$totalVehicles = 0;
$totalPages = 1;

try {
    // Use only columns we know exist in your database
    $baseQuery = "
        SELECT v.id, v.registration_number, v.make, v.model, v.color, v.year_of_manufacture,
               v.chassis_number, v.engine_number, v.registration_date, v.expiry_date, 
               o.name AS owner_name, o.ghana_card_number, o.email AS owner_email, o.phone AS owner_phone
        FROM vehicles v
        JOIN vehicle_owners o ON v.owner_id = o.id
        WHERE 1=1
    ";
    
    $countQuery = "SELECT COUNT(*) FROM vehicles v JOIN vehicle_owners o ON v.owner_id = o.id WHERE 1=1";
    $params = [];
    
    // Add search filter if provided
    if (!empty($search)) {
        $searchCondition = " AND (v.registration_number LIKE :search OR o.name LIKE :search 
                             OR o.ghana_card_number LIKE :search OR v.make LIKE :search 
                             OR v.model LIKE :search OR v.chassis_number LIKE :search)";
        $baseQuery .= $searchCondition;
        $countQuery .= $searchCondition;
        $params['search'] = "%$search%";
    }
    
    // Complete the query with ordering and pagination
    $baseQuery .= " ORDER BY v.registration_date DESC LIMIT :offset, :perPage";
    
    // Get total count for pagination
    $stmt = $pdo->prepare($countQuery);
    foreach ($params as $key => $value) {
        $stmt->bindValue(":$key", $value);
    }
    $stmt->execute();
    $totalVehicles = $stmt->fetchColumn();
    $totalPages = ceil($totalVehicles / $perPage);
    
    // Get vehicles for current page
    $stmt = $pdo->prepare($baseQuery);
    foreach ($params as $key => $value) {
        $stmt->bindValue(":$key", $value);
    }
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':perPage', $perPage, PDO::PARAM_INT);
    $stmt->execute();
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}

// Function to determine vehicle status based on dates
function getVehicleStatus($expiryDate) {
    if (empty($expiryDate)) {
        return 'pending';
    }
    
    $today = new DateTime();
    $expiry = new DateTime($expiryDate);
    
    if ($expiry < $today) {
        return 'expired';
    }
    
    return 'active';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Registrations | Admin Dashboard</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?= $baseUrl ?>/assets/css/style.css">
    <style>
        .vehicle-card {
            transition: all 0.3s ease;
            border-radius: 10px;
            overflow: hidden;
            height: 100%;
            border: 1px solid #dee2e6;
            margin-bottom: 20px;
        }
        
        .vehicle-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }
        
        .vehicle-preview {
            height: 130px;
            background-color: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            border-bottom: 1px solid #dee2e6;
        }
        
        .vehicle-info {
            padding: 15px;
        }
        
        .btn-view {
            background-color: #0d6efd;
            color: white;
        }
        
        .btn-print {
            background-color: #198754;
            color: white;
        }
        
        .btn-view:hover, .btn-print:hover {
            opacity: 0.9;
            color: white;
        }
        
        .search-box {
            background-color: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .vehicle-meta {
            font-size: 0.85rem;
            color: #6c757d;
            margin-bottom: 10px;
        }
        
        .status-badge {
            font-size: 0.75rem;
            padding: 4px 8px;
            border-radius: 12px;
        }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../../../includes/header.php'; ?>
    
    <div class="container my-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-car me-2"></i>Vehicle Registrations</h2>
            <a href="dashboard.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
            </a>
        </div>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <div class="search-box">
            <form action="" method="GET" class="row g-3">
                <div class="col-md-10">
                    <div class="input-group">
                        <input type="text" class="form-control" placeholder="Search by registration number, chassis number, owner name..." name="search" value="<?= htmlspecialchars($search) ?>">
                        <button class="btn btn-primary" type="submit">
                            <i class="fas fa-search"></i> Search
                        </button>
                    </div>
                </div>
                <div class="col-md-2">
                    <?php if (!empty($search)): ?>
                        <a href="view-certificates.php" class="btn btn-outline-secondary w-100">Reset</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        
        <?php if (empty($vehicles)): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                <?php if (!empty($search)): ?>
                    No vehicles found matching your search criteria. Please try a different search.
                <?php else: ?>
                    No vehicles have been registered yet.
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="row">
                <?php foreach ($vehicles as $vehicle): ?>
                    <?php $status = getVehicleStatus($vehicle['expiry_date']); ?>
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="vehicle-card">
                            <div class="vehicle-preview">
                                <div class="text-center">
                                    <i class="fas fa-car fa-3x text-primary"></i>
                                    <p class="mt-2 mb-0"><?= htmlspecialchars($vehicle['make'] . ' ' . $vehicle['model']) ?></p>
                                    <small class="text-muted"><?= htmlspecialchars($vehicle['color'] . ' - ' . $vehicle['year_of_manufacture']) ?></small>
                                </div>
                            </div>
                            <div class="vehicle-info">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h5 class="mb-0"><?= htmlspecialchars($vehicle['registration_number']) ?></h5>
                                    <span class="badge bg-<?= getStatusBadgeClass($status) ?> status-badge">
                                        <?= ucfirst($status) ?>
                                    </span>
                                </div>
                                
                                <div class="vehicle-meta">
                                    <div><i class="fas fa-user me-2"></i><?= htmlspecialchars($vehicle['owner_name']) ?></div>
                                    <?php if (!empty($vehicle['chassis_number'])): ?>
                                        <div><i class="fas fa-cog me-2"></i>Chassis: <?= htmlspecialchars($vehicle['chassis_number']) ?></div>
                                    <?php endif; ?>
                                    <div class="mt-1">
                                        <small>
                                            <i class="fas fa-calendar me-1"></i> Registered: 
                                            <?= !empty($vehicle['registration_date']) ? date('d M Y', strtotime($vehicle['registration_date'])) : 'N/A' ?>
                                        </small>
                                    </div>
                                    <div>
                                        <small>
                                            <i class="fas fa-clock me-1"></i> Expires: 
                                            <?= !empty($vehicle['expiry_date']) ? date('d M Y', strtotime($vehicle['expiry_date'])) : 'N/A' ?>
                                        </small>
                                    </div>
                                </div>
                                
                                <div class="d-flex justify-content-between mt-3">
                                    <a href="/src/views/vehicle/view-details.php?id=<?= $vehicle['id'] ?>" class="btn btn-view">
                                        <i class="fas fa-eye me-1"></i> View Details
                                    </a>
                                    <a href="print-certificate.php?id=<?= $vehicle['id'] ?>" target="_blank" class="btn btn-print">
                                        <i class="fas fa-print me-1"></i> Print
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <nav aria-label="Page navigation" class="mt-4">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>" aria-label="Previous">
                                <span aria-hidden="true">&laquo;</span>
                            </a>
                        </li>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
                                <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>">
                                    <?= $i ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>" aria-label="Next">
                                <span aria-hidden="true">&raquo;</span>
                            </a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
            
            <div class="text-center text-muted mt-3">
                Showing <?= count($vehicles) ?> of <?= $totalVehicles ?> vehicles
            </div>
        <?php endif; ?>
    </div>
    
    <?php include_once __DIR__ . '/../../../includes/footer.php'; ?>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
// Helper function to determine badge color based on status
function getStatusBadgeClass($status) {
    switch (strtolower($status)) {
        case 'active':
            return 'success';
        case 'expired':
            return 'danger';
        case 'pending':
            return 'warning';
        default:
            return 'secondary';
    }
}
?>