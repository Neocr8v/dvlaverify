<?php
// filepath: /Applications/XAMPP/xamppfiles/htdocs/dvlaregister/src/views/admin/all-vehicles.php
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
$vehicles = [];
$search = '';
$status_filter = '';
$sort_by = 'created_at';
$sort_order = 'DESC';
$page = 1;
$limit = 10;
$total_vehicles = 0;

// Get search params
if (isset($_GET['search'])) {
    $search = trim($_GET['search']);
}

if (isset($_GET['status'])) {
    $status_filter = $_GET['status'];
}

if (isset($_GET['sort_by']) && isset($_GET['sort_order'])) {
    $sort_by = $_GET['sort_by'];
    $sort_order = $_GET['sort_order'];
}

if (isset($_GET['page']) && is_numeric($_GET['page'])) {
    $page = (int)$_GET['page'];
    if ($page < 1) $page = 1;
}

if (isset($_GET['limit']) && is_numeric($_GET['limit'])) {
    $limit = (int)$_GET['limit'];
    if ($limit < 1) $limit = 10;
    if ($limit > 100) $limit = 100;
}

// Calculate offset
$offset = ($page - 1) * $limit;

// Handle vehicle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $vehicle_id = $_POST['vehicle_id'] ?? 0;
    $new_status = $_POST['new_status'] ?? '';
    
    if (empty($vehicle_id) || empty($new_status)) {
        $error = "Invalid request";
    } else {
        try {
            // Begin transaction
            $pdo->beginTransaction();
            
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
            
            // If setting to active, update expiry_date to be 1 year from today
            if ($new_status === 'active') {
                $expiryDate = date('Y-m-d', strtotime('+1 year'));
                $updateExpiryStmt = $pdo->prepare("
                    UPDATE vehicles 
                    SET expiry_date = :expiry_date
                    WHERE id = :id
                ");
                $updateExpiryStmt->execute([
                    'expiry_date' => $expiryDate,
                    'id' => $vehicle_id
                ]);
            }
            
            // Commit transaction
            $pdo->commit();
            
            $success = "Vehicle status updated successfully";
            
        } catch (PDOException $e) {
            // Rollback transaction on error
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Error updating vehicle status: " . $e->getMessage();
        }
    }
}

// Check if the table structure has the required columns before proceeding
try {
    $columnsStmt = $pdo->query("SHOW COLUMNS FROM vehicles");
    $columns = $columnsStmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Check if we're using registration_number instead of license_plate
    $usesRegistrationNumber = in_array('registration_number', $columns);
    $licensePlateField = $usesRegistrationNumber ? 'registration_number' : 'license_plate';
    
    // Check if year_of_manufacture exists, otherwise fall back to year
    $usesYearOfManufacture = in_array('year_of_manufacture', $columns);
    $yearField = $usesYearOfManufacture ? 'year_of_manufacture' : 'year';
    
    // Check if chassis_number exists instead of vin
    $usesChassisNumber = in_array('chassis_number', $columns);
    $vinField = $usesChassisNumber ? 'chassis_number' : 'vin';
    
    // Check for expiry_date
    $hasExpiryDate = in_array('expiry_date', $columns);
    
    // Check for status field
    $hasStatusField = in_array('status', $columns);
    
    // Check if owner_id exists (may be joined to users or vehicle_owners)
    $hasOwnerId = in_array('owner_id', $columns);
} catch (PDOException $e) {
    $error = "Error checking table structure: " . $e->getMessage();
    // Set defaults if we can't check
    $usesRegistrationNumber = true;
    $licensePlateField = 'registration_number';
    $usesYearOfManufacture = true;
    $yearField = 'year_of_manufacture';
    $usesChassisNumber = true;
    $vinField = 'chassis_number';
    $hasExpiryDate = true;
    $hasStatusField = true;
    $hasOwnerId = true;
}

// Handle export to CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    try {
        // Build export query based on table structure
        $selectFields = "v.id, v.$licensePlateField, v.make, v.model, v.$yearField, v.$vinField,
                        v.engine_number, v.color, v.vehicle_type, v.registration_date";
        
        // Add expiry date fields if they exist
        if ($hasExpiryDate) {
            $selectFields .= ", v.expiry_date";
            // Calculate days to expiry
            $selectFields .= ", DATEDIFF(v.expiry_date, CURDATE()) as days_to_expiry";
        }
        
        // Add status only if it exists
        if ($hasStatusField) {
            $selectFields .= ", v.status";
        } else {
            // If no status field, derive status from expiry date
            $selectFields .= ", CASE WHEN DATEDIFF(v.expiry_date, CURDATE()) < 0 THEN 'expired' ELSE 'active' END as status";
        }
        
        $selectFields .= ", v.created_at, v.updated_at";
        
        // Determine owner fields based on table structure
        if ($hasOwnerId) {
            $joinTable = "vehicle_owners";
            $ownerFields = "o.name as owner_name, o.ghana_card_number, o.email as owner_email, o.phone as owner_phone";
            $ownerJoin = "LEFT JOIN $joinTable o ON v.owner_id = o.id";
        } else {
            // Fallback for older structure that might join directly to users
            $joinTable = "users";
            $ownerFields = "o.full_name as owner_name, o.ghana_card_number, o.email as owner_email, o.phone_number as owner_phone";
            $ownerJoin = "LEFT JOIN $joinTable o ON v.owner_id = o.id";
        }
        
        $selectFields .= ", $ownerFields";
        
        $query = "
            SELECT $selectFields
            FROM vehicles v
            $ownerJoin
            WHERE 1=1
        ";
        
        $params = [];
        
        // Add search condition to export
        if (!empty($search)) {
            $query .= " AND (v.$licensePlateField LIKE :search OR v.$vinField LIKE :search OR v.make LIKE :search OR v.model LIKE :search";
            
            // Add owner name to search if available
            if ($hasOwnerId) {
                if ($joinTable === "vehicle_owners") {
                    $query .= " OR o.name LIKE :search";
                } else {
                    $query .= " OR o.full_name LIKE :search";
                }
            }
            
            $query .= ")";
            $params['search'] = "%$search%";
        }
        
        // Add filter condition to export (only if status field exists)
        if (!empty($status_filter) && $hasStatusField) {
            $query .= " AND v.status = :status";
            $params['status'] = $status_filter;
        } elseif (!empty($status_filter) && !$hasStatusField && $hasExpiryDate) {
            // Handle status filtering using expiry date if status field doesn't exist
            if ($status_filter === 'expired') {
                $query .= " AND DATEDIFF(v.expiry_date, CURDATE()) < 0";
            } elseif ($status_filter === 'active') {
                $query .= " AND DATEDIFF(v.expiry_date, CURDATE()) >= 0";
            }
        }
        
        // Add sorting to export - adjust fields based on table structure
        $sortField = $sort_by;
        if ($sort_by === 'license_plate' && $usesRegistrationNumber) {
            $sortField = 'registration_number';
        } else if ($sort_by === 'year' && $usesYearOfManufacture) {
            $sortField = 'year_of_manufacture';
        } else if ($sort_by === 'owner_name') {
            if ($joinTable === "vehicle_owners") {
                $sortField = 'o.name';
            } else {
                $sortField = 'o.full_name';
            }
        } else if ($sort_by === 'status' && !$hasStatusField) {
            // Sort by derived status (expiry date) if status field doesn't exist
            $sortField = "DATEDIFF(v.expiry_date, CURDATE())";
        } else {
            $sortField = "v.$sort_by";
        }
        
        if (in_array($sort_order, ['ASC', 'DESC'])) {
            $query .= " ORDER BY $sortField $sort_order";
        } else {
            $query .= " ORDER BY v.created_at DESC";
        }
        
        // Execute export query
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $exportVehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Set CSV headers
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="vehicles_export_' . date('Y-m-d') . '.csv"');
        
        // Open output stream
        $output = fopen('php://output', 'w');
        
        // Add BOM to fix UTF-8 in Excel
        fputs($output, "\xEF\xBB\xBF");
        
        // Add header row
        $headers = [
            'ID', 'Registration Number', 'Make', 'Model', 'Year', 
            'Chassis Number', 'Engine Number', 'Color', 'Vehicle Type',
            'Registration Date'
        ];
        
        // Add expiry date headers if they exist
        if ($hasExpiryDate) {
            $headers[] = 'Expiry Date';
            $headers[] = 'Days to Expiry';
        }
        
        // Add other standard headers
        $headers = array_merge($headers, [
            'Status', 'Created Date', 'Updated Date',
            'Owner Name', 'Ghana Card', 'Email', 'Phone'
        ]);
        
        fputcsv($output, $headers);
        
        // Add data rows with calculated status
        foreach ($exportVehicles as $vehicle) {
            // Calculate actual status based on expiry date
            $status = $hasStatusField ? $vehicle['status'] : 'active';
            if ($hasExpiryDate && !empty($vehicle['expiry_date'])) {
                $days_to_expiry = $vehicle['days_to_expiry'] ?? 0;
                if ($days_to_expiry < 0) {
                    $status = 'expired';
                } 
            }
            
            // Create row with proper field mapping
            $row = [
                $vehicle['id'],
                $vehicle[$licensePlateField],
                $vehicle['make'],
                $vehicle['model'],
                $vehicle[$yearField],
                $vehicle[$vinField],
                $vehicle['engine_number'] ?? 'N/A',
                $vehicle['color'] ?? 'N/A',
                $vehicle['vehicle_type'] ?? 'N/A',
                $vehicle['registration_date']
            ];
            
            // Add expiry date fields if they exist
            if ($hasExpiryDate) {
                $row[] = $vehicle['expiry_date'] ?? 'N/A';
                $row[] = $vehicle['days_to_expiry'] ?? 'N/A';
            }
            
            // Add remaining fields
            $row = array_merge($row, [
                $status,
                $vehicle['created_at'],
                $vehicle['updated_at'] ?? $vehicle['created_at'],
                $vehicle['owner_name'] ?? 'N/A',
                $vehicle['ghana_card_number'] ?? 'N/A',
                $vehicle['owner_email'] ?? 'N/A',
                $vehicle['owner_phone'] ?? 'N/A'
            ]);
            
            fputcsv($output, $row);
        }
        
        fclose($output);
        exit;
        
    } catch (PDOException $e) {
        $error = "Error exporting data: " . $e->getMessage();
    }
}

// Fetch vehicles with pagination, search, filter, and sorting
try {
    // Build query based on table structure
    // Determine owner table and fields
    if ($hasOwnerId) {
        $joinTable = "vehicle_owners";
        $ownerNameField = "o.name as owner_name";
        $ownerJoin = "LEFT JOIN $joinTable o ON v.owner_id = o.id";
    } else {
        $joinTable = "users";
        $ownerNameField = "o.full_name as owner_name";
        $ownerJoin = "LEFT JOIN $joinTable o ON v.owner_id = o.id";
    }
    
    $query = "
        SELECT v.*, $ownerNameField";
    
    // Add days_to_expiry calculation if expiry_date exists
    if ($hasExpiryDate) {
        $query .= ", DATEDIFF(v.expiry_date, CURDATE()) as days_to_expiry";
    }
    
    // Add derived status field if actual status field doesn't exist
    if (!$hasStatusField && $hasExpiryDate) {
        $query .= ", CASE WHEN DATEDIFF(v.expiry_date, CURDATE()) < 0 THEN 'expired' ELSE 'active' END as status";
    }
    
    $query .= " FROM vehicles v
        $ownerJoin
        WHERE 1=1
    ";
    
    $params = [];
    
    // Add search condition
    if (!empty($search)) {
        $query .= " AND (v.$licensePlateField LIKE :search OR v.$vinField LIKE :search OR v.make LIKE :search OR v.model LIKE :search";
        
        // Add owner name to search if available
        if ($hasOwnerId) {
            if ($joinTable === "vehicle_owners") {
                $query .= " OR o.name LIKE :search";
            } else {
                $query .= " OR o.full_name LIKE :search";
            }
        }
        
        $query .= ")";
        $params['search'] = "%$search%";
    }
    
    // Add filter condition based on whether status field exists
    if (!empty($status_filter)) {
        if ($hasStatusField) {
            $query .= " AND v.status = :status";
            $params['status'] = $status_filter;
        } elseif ($hasExpiryDate) {
            // Handle status filtering using expiry date if status field doesn't exist
            if ($status_filter === 'expired') {
                $query .= " AND DATEDIFF(v.expiry_date, CURDATE()) < 0";
            } elseif ($status_filter === 'active') {
                $query .= " AND DATEDIFF(v.expiry_date, CURDATE()) >= 0";
            }
        }
    }
    
    // Count total vehicles
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM ($query) as count_table");
    $count_stmt->execute($params);
    $total_vehicles = $count_stmt->fetchColumn();
    
    // Add sorting - adjust fields based on table structure
    $sortField = $sort_by;
    if ($sort_by === 'license_plate' && $usesRegistrationNumber) {
        $sortField = 'registration_number';
    } else if ($sort_by === 'year' && $usesYearOfManufacture) {
        $sortField = 'year_of_manufacture';
    } else if ($sort_by === 'owner_name') {
        if ($joinTable === "vehicle_owners") {
            $sortField = 'o.name';
        } else {
            $sortField = 'o.full_name';
        }
    } else if ($sort_by === 'status' && !$hasStatusField && $hasExpiryDate) {
        // Sort by derived status (expiry date) if status field doesn't exist
        $sortField = "DATEDIFF(v.expiry_date, CURDATE())";
    } else {
        $sortField = "v.$sort_by";
    }
    
    if (in_array($sort_order, ['ASC', 'DESC'])) {
        $query .= " ORDER BY $sortField $sort_order";
    } else {
        $query .= " ORDER BY v.created_at DESC";
    }
    
    // Add pagination
    $query .= " LIMIT :offset, :limit";
    
    $stmt = $pdo->prepare($query);
    
    // Bind parameters
    foreach ($params as $key => $val) {
        $stmt->bindValue(":$key", $val);
    }
    
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    
    $stmt->execute();
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}

// Calculate pagination info
$total_pages = ceil($total_vehicles / $limit);
$showing_from = min($total_vehicles, $offset + 1);
$showing_to = min($total_vehicles, $offset + $limit);

// Helper function to determine the actual status
function calculateStatus($vehicle, $hasExpiryDate = true) {
    // Check if status field exists in the vehicle data
    $has_status_field = array_key_exists('status', $vehicle);
    $stored_status = $has_status_field ? ($vehicle['status'] ?? 'unknown') : 'active';
    
    // If we don't have expiry date info, just return the stored status (or a default)
    if (!$hasExpiryDate || empty($vehicle['days_to_expiry'])) {
        $status_text = ucfirst($stored_status != '' ? $stored_status : 'active');
        $status_class = match(strtolower($status_text)) {
            'active' => 'active-badge',
            'pending' => 'pending-badge',
            'expired' => 'expired-badge',
            'suspended' => 'suspended-badge',
            default => 'secondary-badge'
        };
        
        return [
            'text' => $status_text,
            'class' => $status_class
        ];
    }
    
    // With expiry date, calculate actual status
    $days_to_expiry = intval($vehicle['days_to_expiry']);
    
    // If days_to_expiry is negative, the vehicle is expired regardless of stored status
    if ($days_to_expiry < 0) {
        return [
            'text' => 'Expired',
            'class' => 'expired-badge'
        ];
    }
    
    // Otherwise use the stored status or determine based on days to expiry
    if ($stored_status == '' || $stored_status == 'unknown') {
        $status_text = 'Active';
    } else {
        $status_text = ucfirst($stored_status);
    }
    
    $status_class = match(strtolower($status_text)) {
        'active' => 'active-badge',
        'pending' => 'pending-badge',
        'expired' => 'expired-badge',
        'suspended' => 'suspended-badge',
        default => 'secondary-badge'
    };
    
    // If expiring soon (within 30 days) but still marked as active
    if (strtolower($status_text) === 'active' && $days_to_expiry <= 30 && $days_to_expiry >= 0) {
        $status_text = 'Expiring Soon';
        $status_class = 'pending-badge';
    }
    
    return [
        'text' => $status_text,
        'class' => $status_class
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Vehicles | Admin | Vehicle Registration System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
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
        
        .table-responsive {
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
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
        
        .secondary-badge {
            background-color: #e9ecef;
            color: #41464b;
        }
        
        .vehicle-card {
            transition: transform 0.2s;
        }
        
        .vehicle-card:hover {
            transform: translateY(-5px);
        }
        
        .filter-section {
            background-color: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .sort-icon {
            font-size: 0.75rem;
            margin-left: 5px;
        }
        
        .days-indicator {
            font-size: 0.75rem;
            display: block;
            margin-top: 3px;
        }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../../../includes/header.php'; ?>
    
    <div class="container my-4">
        <!-- Breadcrumb -->
        <nav aria-label="breadcrumb" class="mb-4">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item active">All Vehicles</li>
            </ol>
        </nav>
        
        <!-- Page Header -->
        <div class="page-header d-flex align-items-center justify-content-between">
            <div>
                <h2 class="mb-1"><i class="fas fa-car me-2"></i>All Registered Vehicles</h2>
                <p class="mb-0 text-muted">Manage and monitor all vehicles in the system</p>
            </div>
            <a href="register-vehicle.php" class="btn btn-primary">
                <i class="fas fa-plus-circle me-2"></i>Register New Vehicle
            </a>
        </div>
        
        <?php if (!empty($success)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <!-- Filter Section -->
        <div class="filter-section">
            <form action="" method="get" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label for="search" class="form-label">Search</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" class="form-control" id="search" name="search" 
                               value="<?= htmlspecialchars($search) ?>" 
                               placeholder="Registration number, chassis number, make, model...">
                    </div>
                </div>
                
                <div class="col-md-3">
                    <label for="status" class="form-label">Status</label>
                    <select class="form-select" id="status" name="status">
                        <option value="">All Statuses</option>
                        <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="expired" <?= $status_filter === 'expired' ? 'selected' : '' ?>>Expired</option>
                        <option value="suspended" <?= $status_filter === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label for="limit" class="form-label">Show Entries</label>
                    <select class="form-select" id="limit" name="limit">
                        <option value="10" <?= $limit === 10 ? 'selected' : '' ?>>10 per page</option>
                        <option value="25" <?= $limit === 25 ? 'selected' : '' ?>>25 per page</option>
                        <option value="50" <?= $limit === 50 ? 'selected' : '' ?>>50 per page</option>
                        <option value="100" <?= $limit === 100 ? 'selected' : '' ?>>100 per page</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter me-2"></i>Apply Filters
                    </button>
                </div>
                
                <!-- Hidden sort fields -->
                <input type="hidden" name="sort_by" value="<?= htmlspecialchars($sort_by) ?>">
                <input type="hidden" name="sort_order" value="<?= htmlspecialchars($sort_order) ?>">
                <input type="hidden" name="page" value="1">
            </form>
        </div>
        
        <?php if (empty($vehicles)): ?>
            <div class="alert alert-info" role="alert">
                <i class="fas fa-info-circle me-2"></i>No vehicles found matching your criteria.
            </div>
        <?php else: ?>
            <!-- Showing entries info -->
            <div class="d-flex justify-content-between align-items-center mb-3">
                <p class="m-0">
                    Showing <?= $showing_from ?> to <?= $showing_to ?> of <?= $total_vehicles ?> vehicles
                </p>
                <div>
                    <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-file-export me-2"></i>Export to CSV
                    </a>
                </div>
            </div>
        
            <!-- Vehicles Table -->
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>
                                <a href="?search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>&sort_by=<?= $usesRegistrationNumber ? 'registration_number' : 'license_plate' ?>&sort_order=<?= ($sort_by === ($usesRegistrationNumber ? 'registration_number' : 'license_plate') && $sort_order === 'ASC') ? 'DESC' : 'ASC' ?>&limit=<?= $limit ?>" class="text-decoration-none text-dark">
                                    Registration Number
                                    <?php if ($sort_by === ($usesRegistrationNumber ? 'registration_number' : 'license_plate')): ?>
                                        <i class="fas fa-sort-<?= $sort_order === 'ASC' ? 'up' : 'down' ?> sort-icon"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th>
                                <a href="?search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>&sort_by=make&sort_order=<?= ($sort_by === 'make' && $sort_order === 'ASC') ? 'DESC' : 'ASC' ?>&limit=<?= $limit ?>" class="text-decoration-none text-dark">
                                    Make & Model
                                    <?php if ($sort_by === 'make'): ?>
                                        <i class="fas fa-sort-<?= $sort_order === 'ASC' ? 'up' : 'down' ?> sort-icon"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th>
                                <a href="?search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>&sort_by=<?= $usesYearOfManufacture ? 'year_of_manufacture' : 'year' ?>&sort_order=<?= ($sort_by === ($usesYearOfManufacture ? 'year_of_manufacture' : 'year') && $sort_order === 'ASC') ? 'DESC' : 'ASC' ?>&limit=<?= $limit ?>" class="text-decoration-none text-dark">
                                    Year
                                    <?php if ($sort_by === ($usesYearOfManufacture ? 'year_of_manufacture' : 'year')): ?>
                                        <i class="fas fa-sort-<?= $sort_order === 'ASC' ? 'up' : 'down' ?> sort-icon"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th>
                                <a href="?search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>&sort_by=owner_name&sort_order=<?= ($sort_by === 'owner_name' && $sort_order === 'ASC') ? 'DESC' : 'ASC' ?>&limit=<?= $limit ?>" class="text-decoration-none text-dark">
                                    Owner
                                    <?php if ($sort_by === 'owner_name'): ?>
                                        <i class="fas fa-sort-<?= $sort_order === 'ASC' ? 'up' : 'down' ?> sort-icon"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th>
                                <a href="?search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>&sort_by=status&sort_order=<?= ($sort_by === 'status' && $sort_order === 'ASC') ? 'DESC' : 'ASC' ?>&limit=<?= $limit ?>" class="text-decoration-none text-dark">
                                    Status
                                    <?php if ($sort_by === 'status'): ?>
                                        <i class="fas fa-sort-<?= $sort_order === 'ASC' ? 'up' : 'down' ?> sort-icon"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th>Registration Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($vehicles as $vehicle): 
                            // Calculate the actual status based on expiry date
                            $actualStatus = calculateStatus($vehicle, $hasExpiryDate);
                        ?>
                            <tr>
                                <td><?= htmlspecialchars($vehicle[$licensePlateField]) ?></td>
                                <td>
                                    <?= htmlspecialchars($vehicle['make']) ?> <?= htmlspecialchars($vehicle['model']) ?>
                                    <div class="small text-muted">Chassis: <?= htmlspecialchars($vehicle[$vinField] ?? 'N/A') ?></div>
                                </td>
                                <td><?= htmlspecialchars($vehicle[$yearField]) ?></td>
                                <td>
                                    <?= htmlspecialchars($vehicle['owner_name'] ?? 'N/A') ?>
                                </td>
                                <td>
                                    <span class="status-badge <?= $actualStatus['class'] ?>">
                                        <?= htmlspecialchars($actualStatus['text']) ?>
                                    </span>
                                    <?php if ($hasExpiryDate && isset($vehicle['days_to_expiry'])): ?>
                                        <?php if (intval($vehicle['days_to_expiry']) < 0): ?>
                                            <span class="days-indicator text-danger">
                                                <?= abs(intval($vehicle['days_to_expiry'])) ?> days ago
                                            </span>
                                        <?php elseif (intval($vehicle['days_to_expiry']) <= 30): ?>
                                            <span class="days-indicator text-warning">
                                                <?= intval($vehicle['days_to_expiry']) ?> days left
                                            </span>
                                        <?php else: ?>
                                            <span class="days-indicator text-muted">
                                                <?= intval($vehicle['days_to_expiry']) ?> days left
                                            </span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= date('M d, Y', strtotime($vehicle['registration_date'] ?? $vehicle['created_at'])) ?></td>
                                <td>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            Actions
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li>
                                                <a class="dropdown-item" href="../vehicle/view-details.php?id=<?= $vehicle['id'] ?>">
                                                    <i class="fas fa-eye me-2"></i>View Details
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="edit-vehicle.php?id=<?= $vehicle['id'] ?>">
                                                    <i class="fas fa-edit me-2"></i>Edit Vehicle
                                                </a>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#statusModal<?= $vehicle['id'] ?>">
                                                    <i class="fas fa-toggle-on me-2"></i>Change Status
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="vehicle-history.php?id=<?= $vehicle['id'] ?>">
                                                    <i class="fas fa-history me-2"></i>View History
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="print-certificate.php?id=<?= $vehicle['id'] ?>">
                                                    <i class="fas fa-print me-2"></i>Print Certificate
                                                </a>
                                            </li>
                                        </ul>
                                    </div>
                                    
                                    <!-- Status Change Modal -->
                                    <div class="modal fade" id="statusModal<?= $vehicle['id'] ?>" tabindex="-1" aria-labelledby="statusModalLabel<?= $vehicle['id'] ?>" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title" id="statusModalLabel<?= $vehicle['id'] ?>">Change Vehicle Status</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <form method="post" action="" id="statusForm<?= $vehicle['id'] ?>">
                                                        <input type="hidden" name="vehicle_id" value="<?= $vehicle['id'] ?>">
                                                        
                                                        <div class="mb-3">
                                                            <p><strong>Vehicle:</strong> <?= htmlspecialchars($vehicle['make']) ?> <?= htmlspecialchars($vehicle['model']) ?> (<?= htmlspecialchars($vehicle[$licensePlateField]) ?>)</p>
                                                            <p><strong>Current Status:</strong> <span class="status-badge <?= $actualStatus['class'] ?>"><?= htmlspecialchars($actualStatus['text']) ?></span></p>
                                                            
                                                            <?php if ($hasExpiryDate && isset($vehicle['days_to_expiry'])): ?>
                                                                <p><strong>Days to Expiry:</strong> 
                                                                    <?php if (intval($vehicle['days_to_expiry']) < 0): ?>
                                                                        <span class="text-danger">Expired <?= abs(intval($vehicle['days_to_expiry'])) ?> days ago</span>
                                                                    <?php elseif (intval($vehicle['days_to_expiry']) <= 30): ?>
                                                                        <span class="text-warning"><?= intval($vehicle['days_to_expiry']) ?> days remaining</span>
                                                                    <?php else: ?>
                                                                        <span class="text-success"><?= intval($vehicle['days_to_expiry']) ?> days remaining</span>
                                                                    <?php endif; ?>
                                                                </p>
                                                            <?php endif; ?>
                                                            
                                                            <div class="alert alert-info">
                                                                <i class="fas fa-info-circle me-2"></i>
                                                                Setting status to "active" will update the expiry date to one year from today.
                                                            </div>
                                                        </div>
                                                        
                                                        <div class="mb-3">
                                                            <label for="new_status<?= $vehicle['id'] ?>" class="form-label">New Status</label>
                                                            <select class="form-select" id="new_status<?= $vehicle['id'] ?>" name="new_status" required>
                                                                <option value="">-- Select Status --</option>
                                                                <option value="active" <?= $actualStatus['text'] === 'Active' ? 'selected' : '' ?>>Active</option>
                                                                <option value="pending" <?= $actualStatus['text'] === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                                                <option value="expired" <?= $actualStatus['text'] === 'Expired' ? 'selected' : '' ?>>Expired</option>
                                                                <option value="suspended" <?= $actualStatus['text'] === 'Suspended' ? 'selected' : '' ?>>Suspended</option>
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
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="d-flex justify-content-center mt-4">
                    <nav aria-label="Page navigation">
                        <ul class="pagination">
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="?search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>&sort_by=<?= urlencode($sort_by) ?>&sort_order=<?= urlencode($sort_order) ?>&page=<?= $page - 1 ?>&limit=<?= $limit ?>" aria-label="Previous">
                                    <span aria-hidden="true">&laquo;</span>
                                </a>
                            </li>
                            
                            <?php
                            // Calculate range of pages to display
                            $range = 2;
                            $start_page = max(1, $page - $range);
                            $end_page = min($total_pages, $page + $range);
                            
                            // Always show first page
                            if ($start_page > 1) {
                                echo '<li class="page-item"><a class="page-link" href="?search=' . urlencode($search) . '&status=' . urlencode($status_filter) . '&sort_by=' . urlencode($sort_by) . '&sort_order=' . urlencode($sort_order) . '&page=1&limit=' . $limit . '">1</a></li>';
                                if ($start_page > 2) {
                                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                }
                            }
                            
                            // Display page numbers
                            for ($i = $start_page; $i <= $end_page; $i++) {
                                echo '<li class="page-item ' . ($page == $i ? 'active' : '') . '"><a class="page-link" href="?search=' . urlencode($search) . '&status=' . urlencode($status_filter) . '&sort_by=' . urlencode($sort_by) . '&sort_order=' . urlencode($sort_order) . '&page=' . $i . '&limit=' . $limit . '">' . $i . '</a></li>';
                            }
                            
                            // Always show last page
                            if ($end_page < $total_pages) {
                                if ($end_page < $total_pages - 1) {
                                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                }
                                echo '<li class="page-item"><a class="page-link" href="?search=' . urlencode($search) . '&status=' . urlencode($status_filter) . '&sort_by=' . urlencode($sort_by) . '&sort_order=' . urlencode($sort_order) . '&page=' . $total_pages . '&limit=' . $limit . '">' . $total_pages . '</a></li>';
                            }
                            ?>
                            
                            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                <a class="page-link" href="?search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>&sort_by=<?= urlencode($sort_by) ?>&sort_order=<?= urlencode($sort_order) ?>&page=<?= $page + 1 ?>&limit=<?= $limit ?>" aria-label="Next">
                                    <span aria-hidden="true">&raquo;</span>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
            
        <?php endif; ?>
        
        <!-- Vehicle Statistics Cards -->
        <div class="row mt-4">
            <?php
            try {
                // Build the statistics query based on table structure
                $statsQuery = "SELECT ";
                
                if ($hasExpiryDate) {
                    // Calculate the actual status based on dates
                    $statsQuery .= "
                        CASE 
                            WHEN DATEDIFF(expiry_date, CURDATE()) < 0 THEN 'expired'
                            ELSE " . ($hasStatusField ? "COALESCE(status, 'active')" : "'active'") . "
                        END as actual_status, ";
                } else {
                    // Just use the stored status if available, default to active
                    $statsQuery .= ($hasStatusField ? "COALESCE(status, 'active')" : "'active'") . " as actual_status, ";
                }
                
                $statsQuery .= "COUNT(*) as count FROM vehicles GROUP BY actual_status";
                
                // Count vehicles by status
                $stmt = $pdo->query($statsQuery);
                $status_counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                
                // Get total count
                $total_count = array_sum($status_counts);
                
                // Prepare statistics data
                $stats = [
                    [
                        'status' => 'active',
                        'label' => 'Active Vehicles',
                        'count' => $status_counts['active'] ?? 0,
                        'icon' => 'fa-check-circle',
                        'color' => 'success'
                    ],
                    [
                        'status' => 'pending',
                        'label' => 'Pending Vehicles',
                        'count' => $status_counts['pending'] ?? 0,
                        'icon' => 'fa-clock',
                        'color' => 'warning'
                    ],
                    [
                        'status' => 'expired',
                        'label' => 'Expired Vehicles',
                        'count' => $status_counts['expired'] ?? 0,
                        'icon' => 'fa-exclamation-circle',
                        'color' => 'danger'
                    ],
                    [
                        'status' => 'suspended',
                        'label' => 'Suspended Vehicles',
                        'count' => $status_counts['suspended'] ?? 0,
                        'icon' => 'fa-ban',
                        'color' => 'info'
                    ]
                ];
                
                // Display statistics cards
                foreach ($stats as $stat):
                    $percentage = $total_count > 0 ? round(($stat['count'] / $total_count) * 100) : 0;
            ?>
                <div class="col-md-3 mb-3">
                    <div class="card border-<?= $stat['color'] ?> h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="text-<?= $stat['color'] ?> mb-2"><?= $stat['label'] ?></h6>
                                    <h3 class="mb-0"><?= number_format($stat['count']) ?></h3>
                                </div>
                                <div class="text-<?= $stat['color'] ?> fs-3">
                                    <i class="fas <?= $stat['icon'] ?>"></i>
                                </div>
                            </div>
                            <div class="progress mt-3" style="height: 6px;">
                                <div class="progress-bar bg-<?= $stat['color'] ?>" role="progressbar" 
                                     style="width: <?= $percentage ?>%;" aria-valuenow="<?= $percentage ?>" 
                                     aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                            <small class="text-muted mt-2 d-block"><?= $percentage ?>% of total vehicles</small>
                        </div>
                        <div class="card-footer bg-transparent border-0 py-2">
                            <a href="?status=<?= $stat['status'] ?>" class="text-<?= $stat['color'] ?> text-decoration-none small">
                                View all <?= strtolower($stat['label']) ?> <i class="fas fa-arrow-right ms-1"></i>
                            </a>
                        </div>
                    </div>
                </div>
            <?php
                endforeach;
            } catch (PDOException $e) {
                // Silently handle error for stats
            }
            ?>
        </div>
        
    </div>
    
    <?php include_once __DIR__ . '/../../../includes/footer.php'; ?>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-submit on limit change
            document.getElementById('limit').addEventListener('change', function() {
                this.form.submit();
            });
            
            // Auto-submit on status change
            document.getElementById('status').addEventListener('change', function() {
                this.form.submit();
            });
        });
    </script>
</body>
</html>