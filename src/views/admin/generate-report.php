<?php
// filepath: /Applications/XAMPP/xamppfiles/htdocs/dvlaregister/src/views/admin/generate-report.php
session_start();
require_once __DIR__ . '/../../../config/database.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../views/auth/login.php');
    exit;
}

// Initialize variables
$reportType = isset($_GET['type']) ? $_GET['type'] : 'registered';
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01'); // First day of current month
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d'); // Today
$searchTerm = isset($_GET['search']) ? $_GET['search'] : '';
$export = isset($_GET['export']) ? $_GET['export'] : false;

// Get report data based on type
$reportData = [];
$reportHeading = '';
$error = null;

try {
    // Common date range filter
    $dateRangeFilter = "AND DATE(";
    
    // Different SQL based on report type
    switch ($reportType) {
        case 'registered':
            $reportHeading = "Newly Registered Vehicles";
            $dateField = "v.registration_date";
            $sql = "SELECT v.id, v.registration_number, v.make, v.model, v.year_of_manufacture, v.color,
                          v.vehicle_type, v.engine_number, v.chassis_number, v.registration_date, 
                          o.name as owner_name, o.phone
                   FROM vehicles v
                   JOIN vehicle_owners o ON v.owner_id = o.id
                   WHERE 1=1 $dateRangeFilter $dateField) BETWEEN :start_date AND :end_date";
            break;
            
        case 'expiring':
            $reportHeading = "Vehicles with Expiring Registration";
            $dateField = "v.expiry_date";
            $sql = "SELECT v.id, v.registration_number, v.make, v.model, v.vehicle_type, 
                          v.expiry_date, o.name as owner_name, o.phone,
                          DATEDIFF(v.expiry_date, CURRENT_DATE()) as days_remaining
                   FROM vehicles v
                   JOIN vehicle_owners o ON v.owner_id = o.id
                   WHERE v.expiry_date IS NOT NULL $dateRangeFilter $dateField) BETWEEN :start_date AND :end_date
                   ORDER BY days_remaining ASC";
            break;
            
        case 'renewed':
            $reportHeading = "Recently Renewed Registrations";
            
            // First check if last_renewal_date column exists
            $columnsCheck = $pdo->query("SHOW COLUMNS FROM vehicles LIKE 'last_renewal_date'");
            $hasRenewalDate = $columnsCheck->rowCount() > 0;
            
            if ($hasRenewalDate) {
                $dateField = "v.last_renewal_date";
                $sql = "SELECT v.id, v.registration_number, v.make, v.model, v.vehicle_type, 
                        v.last_renewal_date, v.expiry_date, o.name as owner_name, o.phone
                    FROM vehicles v
                    JOIN vehicle_owners o ON v.owner_id = o.id
                    WHERE v.last_renewal_date IS NOT NULL $dateRangeFilter $dateField) BETWEEN :start_date AND :end_date";
            } else {
                // Alternative query if last_renewal_date doesn't exist
                // Using updated_at as a proxy for renewal
                $dateField = "v.updated_at";
                $sql = "SELECT v.id, v.registration_number, v.make, v.model, v.vehicle_type, 
                        v.updated_at as last_renewal_date, v.expiry_date, o.name as owner_name, o.phone
                    FROM vehicles v
                    JOIN vehicle_owners o ON v.owner_id = o.id
                    WHERE v.expiry_date IS NOT NULL 
                    AND v.updated_at != v.created_at 
                    $dateRangeFilter $dateField) BETWEEN :start_date AND :end_date";
            }
            break;
            
        case 'vehicle_types':
            $reportHeading = "Registration by Vehicle Type";
            $dateField = "v.registration_date";
            $sql = "SELECT v.vehicle_type, COUNT(*) as vehicle_count, 
                          MIN(v.registration_date) as earliest_registration,
                          MAX(v.registration_date) as latest_registration,
                          AVG(YEAR(NOW()) - v.year_of_manufacture) as average_age
                   FROM vehicles v
                   WHERE 1=1 $dateRangeFilter $dateField) BETWEEN :start_date AND :end_date
                   GROUP BY v.vehicle_type
                   ORDER BY vehicle_count DESC";
            break;
            
        default:
            throw new Exception("Invalid report type");
    }
    
    // Add search filter if provided
    if (!empty($searchTerm)) {
        if ($reportType == 'vehicle_types') {
            $sql .= " HAVING v.vehicle_type LIKE :search";
        } else {
            $sql .= " AND (v.registration_number LIKE :search OR v.make LIKE :search OR v.model LIKE :search OR o.name LIKE :search)";
        }
    }
    
    // Execute query
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':start_date', $startDate);
    $stmt->bindParam(':end_date', $endDate);
    
    if (!empty($searchTerm)) {
        $searchParam = "%" . $searchTerm . "%";
        $stmt->bindParam(':search', $searchParam);
    }
    
    $stmt->execute();
    $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Handle export
    if ($export) {
        $filename = "dvla_" . $reportType . "_report_" . date('Y-m-d_H-i-s') . ".csv";
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        
        $output = fopen('php://output', 'w');
        
        // CSV headers based on report type
        $headers = [];
        switch ($reportType) {
            case 'registered':
                $headers = ['ID', 'Registration Number', 'Make', 'Model', 'Year', 'Color', 'Vehicle Type', 
                           'Engine Number', 'Chassis Number', 'Registration Date', 'Owner', 'Phone'];
                break;
            case 'expiring':
                $headers = ['ID', 'Registration Number', 'Make', 'Model', 'Vehicle Type', 'Expiry Date', 
                           'Owner', 'Phone', 'Days Remaining'];
                break;
            case 'renewed':
                $headers = ['ID', 'Registration Number', 'Make', 'Model', 'Vehicle Type', 'Renewal Date', 
                           'Expiry Date', 'Owner', 'Phone'];
                break;
            case 'vehicle_types':
                $headers = ['Vehicle Type', 'Count', 'Earliest Registration', 'Latest Registration', 'Average Age (Years)'];
                break;
        }
        
        fputcsv($output, $headers);
        
        // Add rows to CSV
        foreach ($reportData as $row) {
            fputcsv($output, $row);
        }
        
        fclose($output);
        exit;
    }
    
} catch (Exception $e) {
    $error = "Error generating report: " . $e->getMessage();
}

// Get the current date range for the form
$currentStartDate = $startDate;
$currentEndDate = $endDate;

// Page title
$pageTitle = 'Generate Reports';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - DVLA Vehicle Registration</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.5/css/dataTables.bootstrap5.min.css">
    
    <link rel="stylesheet" href="/assets/css/style.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .report-controls {
            background-color: #f8f9fa;
            border-radius: 5px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .report-title {
            margin-bottom: 20px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 10px;
        }
        .report-card {
            margin-bottom: 30px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            border-radius: 5px;
        }
        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 30px;
        }
        .summary-card {
            background-color: #f8f9fa;
            border-left: 4px solid #0d6efd;
            margin-bottom: 20px;
            padding: 15px;
        }
        .summary-title {
            font-weight: bold;
            color: #495057;
        }
        .summary-value {
            font-size: 24px;
            color: #0d6efd;
        }
        .main-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px 15px;
        }
        @media (max-width: 768px) {
            .main-content {
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <?php include(__DIR__ . '/../../../includes/header.php'); ?>
    
    <div class="container-fluid">
        <div class="main-content">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2"><?= $pageTitle ?></h1>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-danger" role="alert">
                    <?= $error ?>
                </div>
            <?php endif; ?>
            
            <!-- Report controls -->
            <div class="report-controls">
                <form method="get" action="generate-report.php" id="reportForm">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label for="type" class="form-label">Report Type</label>
                            <select name="type" id="type" class="form-select" required onchange="this.form.submit()">
                                <option value="registered" <?= $reportType == 'registered' ? 'selected' : '' ?>>Newly Registered Vehicles</option>
                                <option value="expiring" <?= $reportType == 'expiring' ? 'selected' : '' ?>>Expiring Registrations</option>
                                <option value="renewed" <?= $reportType == 'renewed' ? 'selected' : '' ?>>Renewed Registrations</option>
                                <option value="vehicle_types" <?= $reportType == 'vehicle_types' ? 'selected' : '' ?>>Registration by Vehicle Type</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="start_date" class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="start_date" name="start_date" 
                                   value="<?= $currentStartDate ?>">
                        </div>
                        <div class="col-md-2">
                            <label for="end_date" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="end_date" name="end_date" 
                                   value="<?= $currentEndDate ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="search" class="form-label">Search</label>
                            <input type="text" class="form-control" id="search" name="search" 
                                   placeholder="Registration #, Make, Model..." value="<?= htmlspecialchars($searchTerm) ?>">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-filter me-2"></i>Apply Filters
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Report output -->
            <div class="card report-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><?= $reportHeading ?></h5>
                    <div class="btn-group">
                        <button class="btn btn-sm btn-outline-secondary" onclick="printReport()">
                            <i class="fas fa-print me-1"></i> Print
                        </button>
                        <a href="<?= $_SERVER['REQUEST_URI'] . (strpos($_SERVER['REQUEST_URI'], '?') ? '&' : '?') ?>export=true" 
                           class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-file-csv me-1"></i> Export CSV
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($reportData)): ?>
                        <div class="alert alert-info">
                            No data found for the selected criteria.
                        </div>
                    <?php else: ?>
                        <?php if ($reportType == 'vehicle_types'): ?>
                            <!-- Vehicle Types Chart -->
                            <div class="chart-container mb-4">
                                <canvas id="vehicleTypeChart"></canvas>
                            </div>
                        <?php endif; ?>
                        
                        <div class="table-responsive">
                            <table class="table table-striped table-hover" id="reportTable">
                                <thead class="table-dark">
                                    <?php switch ($reportType): 
                                        case 'registered': ?>
                                            <tr>
                                                <th>ID</th>
                                                <th>Registration #</th>
                                                <th>Make & Model</th>
                                                <th>Year</th>
                                                <th>Vehicle Type</th>
                                                <th>Registration Date</th>
                                                <th>Owner</th>
                                                <th>Actions</th>
                                            </tr>
                                        <?php break; 
                                        case 'expiring': ?>
                                            <tr>
                                                <th>Registration #</th>
                                                <th>Make & Model</th>
                                                <th>Vehicle Type</th>
                                                <th>Expiry Date</th>
                                                <th>Days Remaining</th>
                                                <th>Owner</th>
                                                <th>Contact</th>
                                                <th>Actions</th>
                                            </tr>
                                        <?php break; 
                                        case 'renewed': ?>
                                            <tr>
                                                <th>Registration #</th>
                                                <th>Make & Model</th>
                                                <th>Vehicle Type</th>
                                                <th>Renewal Date</th>
                                                <th>Expiry Date</th>
                                                <th>Owner</th>
                                                <th>Actions</th>
                                            </tr>
                                        <?php break;
                                        case 'vehicle_types': ?>
                                            <tr>
                                                <th>Vehicle Type</th>
                                                <th>Count</th>
                                                <th>Earliest Registration</th>
                                                <th>Latest Registration</th>
                                                <th>Average Age (Years)</th>
                                            </tr>
                                        <?php break; 
                                    endswitch; ?>
                                </thead>
                                <tbody>
                                    <?php foreach ($reportData as $row): ?>
                                        <?php switch ($reportType):
                                            case 'registered': ?>
                                                <tr>
                                                    <td><?= $row['id'] ?></td>
                                                    <td><?= $row['registration_number'] ?></td>
                                                    <td><?= $row['make'] . ' ' . $row['model'] ?></td>
                                                    <td><?= $row['year_of_manufacture'] ?></td>
                                                    <td><?= $row['vehicle_type'] ?></td>
                                                    <td><?= date('d M Y', strtotime($row['registration_date'])) ?></td>
                                                    <td><?= $row['owner_name'] ?></td>
                                                    <td>
                                                        <a href="../vehicle/view-details.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <a href="print-certificate.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-success">
                                                            <i class="fas fa-print"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php break;
                                            case 'expiring': ?>
                                                <tr>
                                                    <td><?= $row['registration_number'] ?></td>
                                                    <td><?= $row['make'] . ' ' . $row['model'] ?></td>
                                                    <td><?= $row['vehicle_type'] ?></td>
                                                    <td><?= date('d M Y', strtotime($row['expiry_date'])) ?></td>
                                                    <td class="<?= $row['days_remaining'] <= 30 ? 'text-danger fw-bold' : ($row['days_remaining'] <= 60 ? 'text-warning' : '') ?>">
                                                        <?= $row['days_remaining'] ?> days
                                                    </td>
                                                    <td><?= $row['owner_name'] ?></td>
                                                    <td><?= $row['phone'] ?></td>
                                                    <td>
                                                        <a href="renew-registration.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                            <i class="fas fa-sync"></i> Renew
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php break;
                                            case 'renewed': ?>
                                                <tr>
                                                    <td><?= $row['registration_number'] ?></td>
                                                    <td><?= $row['make'] . ' ' . $row['model'] ?></td>
                                                    <td><?= $row['vehicle_type'] ?></td>
                                                    <td><?= date('d M Y', strtotime($row['last_renewal_date'])) ?></td>
                                                    <td><?= date('d M Y', strtotime($row['expiry_date'])) ?></td>
                                                    <td><?= $row['owner_name'] ?></td>
                                                    <td>
                                                        <a href="../vehicle/view-details.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <a href="print-certificate.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-success">
                                                            <i class="fas fa-print"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php break;
                                            case 'vehicle_types': ?>
                                                <tr>
                                                    <td><?= $row['vehicle_type'] ?></td>
                                                    <td><?= $row['vehicle_count'] ?></td>
                                                    <td><?= date('d M Y', strtotime($row['earliest_registration'])) ?></td>
                                                    <td><?= date('d M Y', strtotime($row['latest_registration'])) ?></td>
                                                    <td><?= number_format($row['average_age'], 1) ?></td>
                                                </tr>
                                            <?php break;
                                        endswitch; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- jQuery first, then Bootstrap Bundle with Popper -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.13.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.5/js/dataTables.bootstrap5.min.js"></script>
    
    <script>
        $(document).ready(function() {
            // Initialize DataTable for report
            $('#reportTable').DataTable({
                "pageLength": 25,
                "order": [],
                "responsive": true
            });
            
            // Initialize date pickers
            const today = new Date();
            const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
            
            if (!$('#start_date').val()) {
                $('#start_date').val(firstDay.toISOString().split('T')[0]);
            }
            
            if (!$('#end_date').val()) {
                $('#end_date').val(today.toISOString().split('T')[0]);
            }
            
            <?php if ($reportType == 'vehicle_types' && !empty($reportData)): ?>
            // Vehicle types chart
            const vehicleTypeChartCtx = document.getElementById('vehicleTypeChart').getContext('2d');
            new Chart(vehicleTypeChartCtx, {
                type: 'bar',
                data: {
                    labels: [<?php echo "'" . implode("', '", array_column($reportData, 'vehicle_type')) . "'"; ?>],
                    datasets: [{
                        label: 'Number of Vehicles',
                        data: [<?php echo implode(", ", array_column($reportData, 'vehicle_count')); ?>],
                        backgroundColor: 'rgba(54, 162, 235, 0.7)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
            <?php endif; ?>
        });
        
        // Print report function
        function printReport() {
            const reportHeader = document.querySelector('.card-header').innerHTML;
            const reportContent = document.querySelector('.card-body').innerHTML;
            const startDate = document.getElementById('start_date').value;
            const endDate = document.getElementById('end_date').value;
            
            let printWindow = window.open('', '', 'height=800,width=1200');
            printWindow.document.write('<html><head><title>DVLA Report</title>');
            printWindow.document.write('<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">');
            printWindow.document.write('<style>body { padding: 20px; } .btn { display: none; }</style>');
            printWindow.document.write('</head><body>');
            
            printWindow.document.write('<div class="container">');
            printWindow.document.write('<div class="d-flex justify-content-between align-items-center mb-4">');
            printWindow.document.write('<img src="/assets/img/dvla.png" style="height: 60px;">');
            printWindow.document.write('<div class="text-center">');
            printWindow.document.write('<h2>Driver and Vehicle Licensing Authority</h2>');
            printWindow.document.write('<h4>' + '<?= $reportHeading ?>' + '</h4>');
            printWindow.document.write('<p>Period: ' + startDate + ' to ' + endDate + '</p>');
            printWindow.document.write('<p>Generated on: ' + new Date().toLocaleDateString() + '</p>');
            printWindow.document.write('</div>');
            printWindow.document.write('<div style="width: 60px;"></div>');
            printWindow.document.write('</div>');
            
            printWindow.document.write('<hr>');
            printWindow.document.write(reportContent);
            printWindow.document.write('</div>');
            
            printWindow.document.write('</body></html>');
            printWindow.document.close();
            
            // Give the browser a moment to render the content before printing
            setTimeout(function() {
                printWindow.print();
            }, 500);
        }
    </script>
    <?php include_once __DIR__ . '/../../../includes/footer.php'; ?>
</body>
</html>