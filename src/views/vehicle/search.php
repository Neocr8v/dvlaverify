<?php
session_start();
require_once __DIR__ . '/../../../config/database.php';

// Check if user is logged in (admin or regular user)
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../views/auth/login.php');
    exit;
}

// Initialize variables
$results = [];
$errorMessage = '';
$searchPerformed = false;
$totalResults = 0;
$searchType = '';
$searchValue = '';

// Get search parameters
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['search_type']) && !empty($_GET['search_value'])) {
    $searchType = $_GET['search_type'];
    $searchValue = $_GET['search_value'];
    $searchPerformed = true;
    
    try {
        // Build the query based on search type
        $query = "
            SELECT v.*, o.name as owner_name, o.phone, o.email, o.ghana_card_number, o.address as owner_address
            FROM vehicles v
            JOIN vehicle_owners o ON v.owner_id = o.id
            WHERE 1=1
        ";
        
        $params = [];
        
        switch ($searchType) {
            case 'registration_number':
                $query .= " AND v.registration_number LIKE :search_value";
                $params['search_value'] = "%{$searchValue}%";
                break;
                
            case 'chassis_number':
                $query .= " AND v.chassis_number LIKE :search_value";
                $params['search_value'] = "%{$searchValue}%";
                break;
                
            case 'engine_number':
                $query .= " AND v.engine_number LIKE :search_value";
                $params['search_value'] = "%{$searchValue}%";
                break;
                
            case 'owner_name':
                $query .= " AND o.name LIKE :search_value";
                $params['search_value'] = "%{$searchValue}%";
                break;
                
            case 'ghana_card':
                $query .= " AND o.ghana_card_number LIKE :search_value";
                $params['search_value'] = "%{$searchValue}%";
                break;
                
            case 'make_model':
                $query .= " AND (v.make LIKE :search_value OR v.model LIKE :search_value)";
                $params['search_value'] = "%{$searchValue}%";
                break;
                
            default:
                $errorMessage = "Invalid search type";
                break;
        }
        
        if (!$errorMessage) {
            $query .= " ORDER BY v.registration_date DESC";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $results = $stmt->fetchAll();
            $totalResults = count($results);
        }
    } catch (PDOException $e) {
        $errorMessage = "Database error: " . $e->getMessage();
    }
}

// Helper function to format date
function formatDate($date) {
    return date('d M Y', strtotime($date));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search Vehicle Database | DVLA Vehicle Registration System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php
include(__DIR__ . '/../../../includes/header.php');
    ?>
    
    <div class="container main-content">
        <div class="search-box">
            <h4 class="search-title"><i class="fas fa-search me-2"></i>Search Vehicle Database</h4>
            
            <form method="GET" action="" class="row g-3">
                <div class="col-md-4">
                    <label for="search_type" class="form-label">Search By</label>
                    <select class="form-select" id="search_type" name="search_type" required>
                        <option value="" disabled <?= empty($searchType) ? 'selected' : '' ?>>Select search criteria</option>
                        <option value="registration_number" <?= $searchType === 'registration_number' ? 'selected' : '' ?>>Registration Number</option>
                        <option value="chassis_number" <?= $searchType === 'chassis_number' ? 'selected' : '' ?>>Chassis Number</option>
                        <option value="engine_number" <?= $searchType === 'engine_number' ? 'selected' : '' ?>>Engine Number</option>
                        <option value="owner_name" <?= $searchType === 'owner_name' ? 'selected' : '' ?>>Owner Name</option>
                        <option value="ghana_card" <?= $searchType === 'ghana_card' ? 'selected' : '' ?>>Ghana Card Number</option>
                        <option value="make_model" <?= $searchType === 'make_model' ? 'selected' : '' ?>>Make/Model</option>
                    </select>
                </div>
                
                <div class="col-md-6">
                    <label for="search_value" class="form-label">Search Term</label>
                    <input type="text" class="form-control" id="search_value" name="search_value" 
                           value="<?= htmlspecialchars($searchValue) ?>" placeholder="Enter search term" required>
                </div>
                
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search me-2"></i>Search
                    </button>
                </div>
            </form>
        </div>
        
        <?php if ($searchPerformed): ?>
            <div class="results-box">
                <?php if ($errorMessage): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i><?= $errorMessage ?>
                    </div>
                <?php elseif (empty($results)): ?>
                    <div class="no-results">
                        <i class="fas fa-search"></i>
                        <h4>No Results Found</h4>
                        <p>No vehicles match your search criteria. Please try with different search terms.</p>
                    </div>
                <?php else: ?>
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5 class="search-title mb-0">Search Results</h5>
                        <div class="result-count">Found <?= $totalResults ?> vehicles</div>
                    </div>
                    
                    <table id="vehiclesTable" class="table table-striped table-hover">
                        <thead class="bg-light-primary">
                            <tr>
                                <th>Reg Number</th>
                                <th>Make/Model</th>
                                <th>Owner</th>
                                <th>Reg Date</th>
                                <th>Expiry Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $vehicle): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($vehicle['registration_number']) ?></strong></td>
                                    <td><?= htmlspecialchars($vehicle['make']) ?> <?= htmlspecialchars($vehicle['model']) ?> (<?= htmlspecialchars($vehicle['color']) ?>)</td>
                                    <td><?= htmlspecialchars($vehicle['owner_name']) ?></td>
                                    <td><?= formatDate($vehicle['registration_date']) ?></td>
                                    <td>
                                        <?php 
                                            $expiryDate = new DateTime($vehicle['expiry_date']);
                                            $today = new DateTime();
                                            $expired = $expiryDate < $today;
                                            $expiringSoon = !$expired && $expiryDate->diff($today)->days <= 30;
                                            
                                            if ($expired) {
                                                echo '<span class="badge bg-danger">Expired</span> ';
                                            } elseif ($expiringSoon) {
                                                echo '<span class="badge bg-warning">Expiring Soon</span> ';
                                            }
                                            
                                            echo formatDate($vehicle['expiry_date']);
                                        ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="../vehicle/view-details.php?id=<?= $vehicle['id'] ?>" class="btn btn-view action-btn" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            
                                            <?php if ($_SESSION['role'] === 'admin'): ?>
                                                <a href="../admin/edit-vehicle.php?id=<?= $vehicle['id'] ?>" class="btn btn-edit action-btn" title="Edit Vehicle">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="../admin/print-certificate.php?id=<?= $vehicle['id'] ?>" class="btn btn-print action-btn" title="Print Certificate">
                                                    <i class="fas fa-print"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <!-- If no search performed yet, show helpful tips -->
        <?php if (!$searchPerformed): ?>
            <div class="results-box">
                <div class="text-center py-4">
                    <i class="fas fa-search fa-3x mb-3" style="color: #ccc;"></i>
                    <h4>Search for Vehicle Information</h4>
                    <p class="text-muted">
                        Use the search form above to find vehicles in the database.<br>
                        You can search by registration number, chassis number, engine number, or owner details.
                    </p>
                </div>
                
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="card mb-3">
                            <div class="card-header bg-light-primary">
                                <i class="fas fa-info-circle me-2"></i> Search Tips
                            </div>
                            <div class="card-body">
                                <ul class="mb-0">
                                    <li>Use complete or partial registration numbers</li>
                                    <li>Search by vehicle make or model</li>
                                    <li>Find vehicles by owner's name or Ghana Card</li>
                                    <li>Results will show all matching vehicles</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-light-primary">
                                <i class="fas fa-key me-2"></i> Quick Search Examples
                            </div>
                            <div class="card-body">
                                <ul class="mb-0">
                                    <li>Registration Number: <code>GR-123-21</code></li>
                                    <li>Owner Name: <code>John Doe</code></li>
                                    <li>Make/Model: <code>Toyota Corolla</code></li>
                                    <li>Ghana Card: <code>GHA-123456789-0</code></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <?php
    include(__DIR__ . '/../../../includes/footer.php');
    ?>
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    
    <script>
        // Initialize DataTables for better table functionality
        $(document).ready(function() {
            <?php if (!empty($results)): ?>
            $('#vehiclesTable').DataTable({
                "pageLength": 10,
                "lengthMenu": [[10, 25, 50, -1], [10, 25, 50, "All"]],
                "order": [[3, 'desc']], // Sort by registration date by default
                "language": {
                    "search": "Filter results:",
                    "lengthMenu": "Show _MENU_ entries",
                    "info": "Showing _START_ to _END_ of _TOTAL_ vehicles"
                }
            });
            <?php endif; ?>
        });
    </script>
</body>
</html>