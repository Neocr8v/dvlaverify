<?php
// filepath: /Applications/XAMPP/xamppfiles/htdocs/dvlaregister/includes/header.php
// If session hasn't been started yet, start it
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Determine the appropriate dashboard based on user role
$dashboardUrl = '/index.php'; // Default for logged out users
if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
        $dashboardUrl = '/src/views/admin/dashboard.php';
    } else {
        $dashboardUrl = '/src/views/user/dashboard.php'; 
    }
}

// Set page title based on role
$pageTitle = isset($_SESSION['role']) && $_SESSION['role'] === 'admin' ? 'Admin Dashboard' : 'User Dashboard';
?>

<header class="header">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center">
                <a href="<?= $dashboardUrl ?>" class="text-decoration-none">
                    <div class="d-flex align-items-center">
                        <img src="/assets/img/dvla.png" alt="DVLA Logo" height="50" class="me-3">
                        <div>
                            <h4 class="mb-0"><?= isset($_SESSION['role']) ? ($pageTitle) : 'Vehicle Registration System' ?></h4>
                            <p class="mb-0">DVLA Ghana</p>
                        </div>
                    </div>
                </a>
            </div>
            <div>
                <?php if (isset($_SESSION['user_id'])): ?>
                    <div class="dropdown">
                        <button class="btn btn-light dropdown-toggle" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-user-circle me-2"></i>
                            <?= htmlspecialchars($_SESSION['full_name']) ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <li>
                                <a class="dropdown-item" href="<?= isset($_SESSION['role']) && $_SESSION['role'] === 'admin' ? 
                                    '/src/views/admin/profile.php' : 
                                    '/src/views/user/profile.php' 
                                ?>">
                                    <i class="fas fa-user me-2"></i> Profile
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="<?= isset($_SESSION['role']) && $_SESSION['role'] === 'admin' ? 
                                    '/src/views/admin/settings.php' : 
                                    '/src/views/user/settings.php' 
                                ?>">
                                    <i class="fas fa-cog me-2"></i> Settings
                                </a>
                            </li>
                            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                                <li>
                                    <a class="dropdown-item" href="/src/views/admin/admin-tools.php">
                                        <i class="fas fa-tools me-2"></i> Admin Tools
                                    </a>
                                </li>
                            <?php endif; ?>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item" href="/src/views/auth/logout.php">
                                    <i class="fas fa-sign-out-alt me-2"></i> Logout
                                </a>
                            </li>
                        </ul>
                    </div>
                <?php else: ?>
                    <div>
                        <a href="/src/views/auth/login.php" class="btn btn-outline-primary me-2">
                            <i class="fas fa-sign-in-alt me-1"></i> Login
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</header>