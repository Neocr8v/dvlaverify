<?php
/**
 * Secure Logout Functionality
 * Enhanced with comprehensive security measures
 */

// Include security utilities
require_once __DIR__ . '/../../../config/security.php';
require_once __DIR__ . '/../../../config/database.php';

// Initialize secure session
initSecureSession();

// Set security headers
setSecurityHeaders();

// Perform secure logout
secureLogout();

// Redirect to login page with logout message
header('Location: login.php?message=logged_out');
exit;
?>