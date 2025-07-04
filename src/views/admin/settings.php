<?php
// filepath: /Applications/XAMPP/xamppfiles/htdocs/dvlaregister/src/views/admin/settings.php
session_start();
require_once __DIR__ . '/../../../config/database.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../auth/login.php');
    exit;
}

// Initialize variables
$success = '';
$error = '';

// Get current settings
try {
    // Check if settings table exists
    $stmt = $pdo->prepare("SHOW TABLES LIKE 'system_settings'");
    $stmt->execute();
    
    if ($stmt->rowCount() == 0) {
        // Create settings table if it doesn't exist
        $pdo->exec("
            CREATE TABLE system_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(50) UNIQUE NOT NULL,
                setting_value TEXT,
                setting_group VARCHAR(50) NOT NULL,
                display_name VARCHAR(100) NOT NULL,
                field_type VARCHAR(20) NOT NULL,
                options TEXT,
                description TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");
        
        // Insert default settings
        $defaultSettings = [
            // General settings
            ['site_name', 'Vehicle Registration System', 'general', 'Site Name', 'text', '', 'The name of the application displayed in the browser title and header'],
            ['site_description', 'DVLA Ghana Online Vehicle Registration System', 'general', 'Site Description', 'textarea', '', 'A short description of the application'],
            ['maintenance_mode', '0', 'general', 'Maintenance Mode', 'boolean', '', 'Enable to show a maintenance page to all non-admin users'],
            
            // Registration settings
            ['vehicle_reg_expiry_days', '365', 'registration', 'Vehicle Registration Validity (days)', 'number', '', 'Number of days a vehicle registration is valid before renewal'],
            ['registration_prefix', 'GR', 'registration', 'Registration Number Prefix', 'text', '', 'Prefix for vehicle registration numbers (e.g., GR for Ghana Registration)'],
            ['auto_generate_user_accounts', '1', 'registration', 'Auto-generate User Accounts', 'boolean', '', 'Automatically create user accounts for vehicle owners'],
            
            // Email settings
            ['email_notifications', '1', 'email', 'Email Notifications', 'boolean', '', 'Send email notifications for important events'],
            ['admin_email', 'admin@dvla.gov.gh', 'email', 'Admin Email', 'email', '', 'Email address for receiving system notifications'],
            ['smtp_host', 'smtp.gmail.com', 'email', 'SMTP Host', 'text', '', 'SMTP server for sending emails'],
            ['smtp_port', '587', 'email', 'SMTP Port', 'number', '', 'SMTP server port'],
            ['smtp_encryption', 'tls', 'email', 'SMTP Encryption', 'select', 'none,tls,ssl', 'Encryption method for SMTP'],
            
            // Appearance settings
            ['primary_color', '#0d6efd', 'appearance', 'Primary Color', 'color', '', 'Main color theme for the application'],
            ['logo_path', 'assets/img/dvla.png', 'appearance', 'Logo Path', 'text', '', 'Path to the application logo image'],
            ['enable_dark_mode', '0', 'appearance', 'Enable Dark Mode Option', 'boolean', '', 'Allow users to switch to dark mode'],
            
            // Security settings
            ['password_min_length', '8', 'security', 'Minimum Password Length', 'number', '', 'Minimum number of characters required for passwords'],
            ['session_timeout', '30', 'security', 'Session Timeout (minutes)', 'number', '', 'Minutes of inactivity before a user is logged out'],
            ['max_login_attempts', '5', 'security', 'Maximum Login Attempts', 'number', '', 'Number of failed login attempts before account is temporarily locked'],
        ];
        
        $insertStmt = $pdo->prepare("
            INSERT INTO system_settings 
            (setting_key, setting_value, setting_group, display_name, field_type, options, description) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($defaultSettings as $setting) {
            $insertStmt->execute($setting);
        }
    }
    
    // Get all settings grouped by category
    $stmt = $pdo->query("
        SELECT * FROM system_settings 
        ORDER BY setting_group, display_name
    ");
    
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_group']][] = $row;
    }
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
    try {
        $pdo->beginTransaction();
        
        // Loop through all submitted settings
        foreach ($_POST as $key => $value) {
            // Skip the submit button and non-setting fields
            if ($key === 'update_settings' || strpos($key, 'setting_') !== 0) {
                continue;
            }
            
            // Extract the real setting key (remove 'setting_' prefix)
            $setting_key = substr($key, 8);
            
            // Update the setting
            $updateStmt = $pdo->prepare("
                UPDATE system_settings 
                SET setting_value = ? 
                WHERE setting_key = ?
            ");
            
            $updateStmt->execute([$value, $setting_key]);
        }
        
        $pdo->commit();
        $success = "Settings updated successfully";
        
        // Refresh settings after update
        $stmt = $pdo->query("
            SELECT * FROM system_settings 
            ORDER BY setting_group, display_name
        ");
        
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_group']][] = $row;
        }
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = "Error updating settings: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings | Admin | Vehicle Registration System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .settings-header {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 5px solid #0d6efd;
        }
        
        .nav-pills .nav-link.active {
            background-color: #0d6efd;
        }
        
        .settings-card {
            margin-bottom: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
        
        .settings-card .card-header {
            font-weight: bold;
            border-bottom: 2px solid rgba(0,0,0,.125);
        }
        
        .settings-field {
            padding: 1rem;
            border-bottom: 1px solid rgba(0,0,0,.05);
        }
        
        .settings-field:last-child {
            border-bottom: none;
        }
        
        .settings-footer {
            background-color: #f8f9fa;
        }
        
        .form-label .badge {
            font-size: 0.7em;
            vertical-align: middle;
            margin-left: 5px;
        }
        
        .setting-description {
            font-size: 0.875rem;
            color: #6c757d;
            margin-top: 5px;
        }
        
        .color-preview {
            display: inline-block;
            width: 1.5rem;
            height: 1.5rem;
            border-radius: 3px;
            border: 1px solid #dee2e6;
            vertical-align: middle;
            margin-right: 0.5rem;
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
                <li class="breadcrumb-item active">System Settings</li>
            </ol>
        </nav>
        
        <!-- Settings Header -->
        <div class="settings-header d-flex align-items-center">
            <div class="me-4">
                <i class="fas fa-cogs fa-3x text-primary"></i>
            </div>
            <div>
                <h2 class="mb-1">System Settings</h2>
                <p class="mb-0 text-muted">Configure system behavior and appearance</p>
            </div>
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
        
        <div class="row">
            <div class="col-md-3 mb-4">
                <!-- Settings Navigation -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-list me-2"></i>Settings Categories</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="nav flex-column nav-pills" id="settings-tab" role="tablist" aria-orientation="vertical">
                            <?php 
                            $first = true;
                            foreach (array_keys($settings) as $group): 
                                $icon = match($group) {
                                    'general' => 'fa-gear',
                                    'registration' => 'fa-car',
                                    'email' => 'fa-envelope',
                                    'appearance' => 'fa-palette',
                                    'security' => 'fa-shield-alt',
                                    default => 'fa-cog'
                                };
                                $groupDisplayName = ucfirst($group);
                            ?>
                                <button class="nav-link text-start <?= $first ? 'active' : '' ?>" 
                                        id="<?= $group ?>-tab" 
                                        data-bs-toggle="pill" 
                                        data-bs-target="#<?= $group ?>-pane" 
                                        type="button" 
                                        role="tab">
                                    <i class="fas <?= $icon ?> me-2"></i><?= $groupDisplayName ?> Settings
                                </button>
                            <?php 
                                $first = false;
                            endforeach; 
                            ?>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-bolt me-2"></i>Quick Actions</h5>
                    </div>
                    <div class="list-group list-group-flush">
                        <a href="#" class="list-group-item list-group-item-action" id="btnExportSettings">
                            <i class="fas fa-download me-2"></i>Export Settings
                        </a>
                        <a href="#" class="list-group-item list-group-item-action" data-bs-toggle="modal" data-bs-target="#importSettingsModal">
                            <i class="fas fa-upload me-2"></i>Import Settings
                        </a>
                        <a href="#" class="list-group-item list-group-item-action" id="btnResetDefaults" data-bs-toggle="modal" data-bs-target="#resetConfirmModal">
                            <i class="fas fa-undo me-2"></i>Reset to Defaults
                        </a>
                        <a href="dashboard.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="col-md-9">
                <form method="post" action="">
                    <div class="tab-content" id="settings-tabContent">
                        <?php 
                        $first = true;
                        foreach ($settings as $group => $groupSettings): 
                            $groupDisplayName = ucfirst($group);
                        ?>
                            <div class="tab-pane fade <?= $first ? 'show active' : '' ?>" 
                                 id="<?= $group ?>-pane" 
                                 role="tabpanel" 
                                 tabindex="0">
                                <div class="card settings-card">
                                    <div class="card-header bg-light">
                                        <h5 class="mb-0"><?= $groupDisplayName ?> Settings</h5>
                                    </div>
                                    <div class="card-body p-0">
                                        <?php foreach ($groupSettings as $setting): ?>
                                            <div class="settings-field">
                                                <div class="mb-2">
                                                    <label for="setting_<?= $setting['setting_key'] ?>" class="form-label">
                                                        <?= htmlspecialchars($setting['display_name']) ?>
                                                        <?php if ($setting['field_type'] === 'boolean'): ?>
                                                            <span class="badge bg-secondary">On/Off</span>
                                                        <?php endif; ?>
                                                    </label>
                                                    
                                                    <?php if ($setting['field_type'] === 'text'): ?>
                                                        <input type="text" class="form-control" 
                                                               id="setting_<?= $setting['setting_key'] ?>" 
                                                               name="setting_<?= $setting['setting_key'] ?>" 
                                                               value="<?= htmlspecialchars($setting['setting_value']) ?>">
                                                    <?php elseif ($setting['field_type'] === 'textarea'): ?>
                                                        <textarea class="form-control" 
                                                                 id="setting_<?= $setting['setting_key'] ?>" 
                                                                 name="setting_<?= $setting['setting_key'] ?>" 
                                                                 rows="3"><?= htmlspecialchars($setting['setting_value']) ?></textarea>
                                                    <?php elseif ($setting['field_type'] === 'number'): ?>
                                                        <input type="number" class="form-control" 
                                                               id="setting_<?= $setting['setting_key'] ?>" 
                                                               name="setting_<?= $setting['setting_key'] ?>" 
                                                               value="<?= htmlspecialchars($setting['setting_value']) ?>">
                                                    <?php elseif ($setting['field_type'] === 'email'): ?>
                                                        <input type="email" class="form-control" 
                                                               id="setting_<?= $setting['setting_key'] ?>" 
                                                               name="setting_<?= $setting['setting_key'] ?>" 
                                                               value="<?= htmlspecialchars($setting['setting_value']) ?>">
                                                    <?php elseif ($setting['field_type'] === 'boolean'): ?>
                                                        <div class="form-check form-switch">
                                                            <input class="form-check-input" type="checkbox" role="switch" 
                                                                   id="setting_<?= $setting['setting_key'] ?>" 
                                                                   name="setting_<?= $setting['setting_key'] ?>" 
                                                                   value="1" <?= $setting['setting_value'] == '1' ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="setting_<?= $setting['setting_key'] ?>">
                                                                <?= $setting['setting_value'] == '1' ? 'Enabled' : 'Disabled' ?>
                                                            </label>
                                                        </div>
                                                    <?php elseif ($setting['field_type'] === 'select' && !empty($setting['options'])): ?>
                                                        <?php $options = explode(',', $setting['options']); ?>
                                                        <select class="form-select" 
                                                                id="setting_<?= $setting['setting_key'] ?>" 
                                                                name="setting_<?= $setting['setting_key'] ?>">
                                                            <?php foreach ($options as $option): ?>
                                                                <option value="<?= $option ?>" <?= $setting['setting_value'] === $option ? 'selected' : '' ?>>
                                                                    <?= ucfirst($option) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    <?php elseif ($setting['field_type'] === 'color'): ?>
                                                        <div class="input-group">
                                                            <span class="input-group-text p-1">
                                                                <span class="color-preview" id="preview_<?= $setting['setting_key'] ?>" style="background-color: <?= $setting['setting_value'] ?>"></span>
                                                            </span>
                                                            <input type="color" class="form-control form-control-color" 
                                                                   id="setting_<?= $setting['setting_key'] ?>" 
                                                                   name="setting_<?= $setting['setting_key'] ?>" 
                                                                   value="<?= htmlspecialchars($setting['setting_value']) ?>"
                                                                   onInput="document.getElementById('preview_<?= $setting['setting_key'] ?>').style.backgroundColor = this.value">
                                                            <input type="text" class="form-control" 
                                                                   value="<?= htmlspecialchars($setting['setting_value']) ?>"
                                                                   id="text_<?= $setting['setting_key'] ?>" 
                                                                   onInput="document.getElementById('setting_<?= $setting['setting_key'] ?>').value = this.value; document.getElementById('preview_<?= $setting['setting_key'] ?>').style.backgroundColor = this.value">
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if (!empty($setting['description'])): ?>
                                                        <div class="setting-description"><?= htmlspecialchars($setting['description']) ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php 
                            $first = false;
                        endforeach; 
                        ?>
                    </div>
                    
                    <div class="d-grid gap-2 mt-4">
                        <button type="submit" name="update_settings" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save Settings
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Reset Confirmation Modal -->
    <div class="modal fade" id="resetConfirmModal" tabindex="-1" aria-labelledby="resetConfirmModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="resetConfirmModalLabel"><i class="fas fa-exclamation-triangle me-2 text-warning"></i>Reset to Defaults</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to reset all settings to their default values?</p>
                    <p class="text-danger"><strong>Warning:</strong> This action cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmReset">
                        <i class="fas fa-undo me-2"></i>Reset Settings
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Import Settings Modal -->
    <div class="modal fade" id="importSettingsModal" tabindex="-1" aria-labelledby="importSettingsModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="importSettingsModalLabel"><i class="fas fa-upload me-2"></i>Import Settings</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="importFile" class="form-label">Settings JSON File</label>
                        <input class="form-control" type="file" id="importFile" accept=".json">
                        <div class="form-text">Upload a settings JSON file that was previously exported.</div>
                    </div>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>Importing settings will overwrite all current settings. Make sure to export your current settings first if needed.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="btnImportSettings">
                        <i class="fas fa-upload me-2"></i>Import
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <?php include_once __DIR__ . '/../../../includes/footer.php'; ?>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Toggle switch labels
            const toggleSwitches = document.querySelectorAll('.form-check-input');
            toggleSwitches.forEach(function(toggle) {
                toggle.addEventListener('change', function() {
                    const label = this.nextElementSibling;
                    if (this.checked) {
                        label.textContent = 'Enabled';
                    } else {
                        label.textContent = 'Disabled';
                    }
                });
            });
            
            // Export settings
            document.getElementById('btnExportSettings').addEventListener('click', function(e) {
                e.preventDefault();
                
                fetch('export-settings.php')
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(data => {
                        // Create and download file
                        const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
                        const url = URL.createObjectURL(blob);
                        const a = document.createElement('a');
                        a.style.display = 'none';
                        a.href = url;
                        a.download = 'dvla-system-settings-' + new Date().toISOString().split('T')[0] + '.json';
                        document.body.appendChild(a);
                        a.click();
                        window.URL.revokeObjectURL(url);
                        
                        alert('Settings exported successfully');
                    })
                    .catch(error => {
                        console.error('Error exporting settings:', error);
                        alert('Failed to export settings. See console for details.');
                    });
            });
            
            // Reset to defaults
            document.getElementById('confirmReset').addEventListener('click', function() {
                fetch('reset-settings.php')
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            window.location.reload();
                        } else {
                            alert('Error: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error resetting settings:', error);
                        alert('Failed to reset settings. See console for details.');
                    });
            });
            
            // Import settings
            document.getElementById('btnImportSettings').addEventListener('click', function() {
                const fileInput = document.getElementById('importFile');
                
                if (!fileInput.files || fileInput.files.length === 0) {
                    alert('Please select a file to import');
                    return;
                }
                
                const file = fileInput.files[0];
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    try {
                        const settings = JSON.parse(e.target.result);
                        
                        // Send to server
                        fetch('import-settings.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify(settings),
                        })
                        .then(response => {
                            if (!response.ok) {
                                throw new Error('Network response was not ok');
                            }
                            return response.json();
                        })
                        .then(data => {
                            if (data.success) {
                                window.location.reload();
                            } else {
                                alert('Error: ' + data.message);
                            }
                        })
                        .catch(error => {
                            console.error('Error importing settings:', error);
                            alert('Failed to import settings. See console for details.');
                        });
                    } catch (error) {
                        console.error('Error parsing JSON:', error);
                        alert('Invalid settings file. Please make sure the file contains valid JSON.');
                    }
                };
                
                reader.readAsText(file);
            });
        });
    </script>
</body>
</html>