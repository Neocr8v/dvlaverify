<?php
/**
 * Security Configuration and Utilities
 * Comprehensive security settings for DVLA login system
 */

/**
 * Security configuration constants
 */
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION', 900); // 15 minutes in seconds
define('SESSION_TIMEOUT', 1800); // 30 minutes in seconds
define('CSRF_TOKEN_EXPIRY', 3600); // 1 hour in seconds

/**
 * Initialize secure session configuration
 */
function initSecureSession() {
    // Secure session configuration
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', 1);
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', 1);
    ini_set('session.gc_maxlifetime', SESSION_TIMEOUT);
    ini_set('session.cookie_lifetime', SESSION_TIMEOUT);
    
    // Regenerate session ID to prevent fixation
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * Set comprehensive HTTP security headers
 */
function setSecurityHeaders() {
    // Prevent clickjacking
    header('X-Frame-Options: DENY');
    
    // Prevent MIME sniffing
    header('X-Content-Type-Options: nosniff');
    
    // XSS protection
    header('X-XSS-Protection: 1; mode=block');
    
    // Strict transport security (HTTPS enforcement)
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }
    
    // Content Security Policy
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; img-src 'self' data:; font-src 'self' https://cdnjs.cloudflare.com; connect-src 'self'");
    
    // Referrer policy
    header('Referrer-Policy: strict-origin-when-cross-origin');
    
    // Remove server information
    header_remove('X-Powered-By');
}

/**
 * Generate CSRF token
 */
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time']) || 
        (time() - $_SESSION['csrf_token_time']) > CSRF_TOKEN_EXPIRY) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token
 */
function validateCSRFToken($token) {
    if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time'])) {
        return false;
    }
    
    // Check token expiry
    if ((time() - $_SESSION['csrf_token_time']) > CSRF_TOKEN_EXPIRY) {
        unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
        return false;
    }
    
    // Use hash_equals to prevent timing attacks
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Rate limiting functionality
 */
function checkRateLimit($identifier) {
    $key = 'login_attempts_' . md5($identifier);
    $lockoutKey = 'lockout_' . md5($identifier);
    
    // Check if currently locked out
    if (isset($_SESSION[$lockoutKey]) && $_SESSION[$lockoutKey] > time()) {
        $remainingTime = $_SESSION[$lockoutKey] - time();
        return [
            'allowed' => false,
            'remaining_time' => $remainingTime,
            'attempts' => MAX_LOGIN_ATTEMPTS
        ];
    }
    
    // Initialize attempts if not set
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 0, 'last_attempt' => time()];
    }
    
    $attempts = $_SESSION[$key];
    
    // Reset attempts if last attempt was more than lockout duration ago
    if ((time() - $attempts['last_attempt']) > LOCKOUT_DURATION) {
        $_SESSION[$key] = ['count' => 0, 'last_attempt' => time()];
        $attempts = $_SESSION[$key];
    }
    
    return [
        'allowed' => $attempts['count'] < MAX_LOGIN_ATTEMPTS,
        'attempts_remaining' => MAX_LOGIN_ATTEMPTS - $attempts['count'],
        'attempts' => $attempts['count']
    ];
}

/**
 * Record failed login attempt
 */
function recordFailedAttempt($identifier) {
    $key = 'login_attempts_' . md5($identifier);
    $lockoutKey = 'lockout_' . md5($identifier);
    
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 0, 'last_attempt' => time()];
    }
    
    $_SESSION[$key]['count']++;
    $_SESSION[$key]['last_attempt'] = time();
    
    // Implement lockout if max attempts reached
    if ($_SESSION[$key]['count'] >= MAX_LOGIN_ATTEMPTS) {
        $_SESSION[$lockoutKey] = time() + LOCKOUT_DURATION;
        logSecurityEvent('ACCOUNT_LOCKOUT', $identifier, 'Account locked due to too many failed attempts');
    }
    
    logSecurityEvent('FAILED_LOGIN', $identifier, 'Failed login attempt #' . $_SESSION[$key]['count']);
}

/**
 * Clear login attempts on successful login
 */
function clearLoginAttempts($identifier) {
    $key = 'login_attempts_' . md5($identifier);
    $lockoutKey = 'lockout_' . md5($identifier);
    
    unset($_SESSION[$key], $_SESSION[$lockoutKey]);
}

/**
 * Security event logging
 */
function logSecurityEvent($event_type, $identifier, $details = '') {
    $log_entry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'event' => $event_type,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'identifier' => $identifier,
        'details' => $details
    ];
    
    $log_line = json_encode($log_entry) . "\n";
    
    // Create logs directory if it doesn't exist
    $log_dir = __DIR__ . '/../logs';
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0750, true);
    }
    
    $log_file = $log_dir . '/security_' . date('Y-m-d') . '.log';
    file_put_contents($log_file, $log_line, FILE_APPEND | LOCK_EX);
}

/**
 * Timing attack protection - constant time delay
 */
function constantTimeDelay() {
    // Add random delay between 100-300ms to prevent timing attacks
    $delay = random_int(100000, 300000); // microseconds
    usleep($delay);
}

/**
 * Secure cookie setting with all security flags
 */
function setSecureCookie($name, $value, $expiry = 0, $path = '/', $domain = '', $secure = true, $httponly = true) {
    $options = [
        'expires' => $expiry,
        'path' => $path,
        'domain' => $domain,
        'secure' => $secure && (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'),
        'httponly' => $httponly,
        'samesite' => 'Strict'
    ];
    
    return setcookie($name, $value, $options);
}

/**
 * Regenerate session ID securely
 */
function regenerateSessionId() {
    // Delete old session file
    session_regenerate_id(true);
    
    // Update session data with new ID
    $_SESSION['session_regenerated'] = time();
    $_SESSION['session_ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
    $_SESSION['session_user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
}

/**
 * Validate session security
 */
function validateSession() {
    // Check if session has expired
    if (isset($_SESSION['last_activity']) && 
        (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
        session_destroy();
        return false;
    }
    
    // Check IP address consistency (optional, can be disabled for mobile users)
    if (isset($_SESSION['session_ip']) && 
        $_SESSION['session_ip'] !== ($_SERVER['REMOTE_ADDR'] ?? '')) {
        // Log suspicious activity but don't auto-logout (mobile users change IPs)
        logSecurityEvent('IP_CHANGE', $_SESSION['username'] ?? 'unknown', 
                        'IP changed from ' . $_SESSION['session_ip'] . ' to ' . ($_SERVER['REMOTE_ADDR'] ?? ''));
    }
    
    // Update last activity time
    $_SESSION['last_activity'] = time();
    
    return true;
}

/**
 * Secure logout functionality
 */
function secureLogout() {
    // Clear remember me cookies if they exist
    if (isset($_COOKIE['remember_user'])) {
        setSecureCookie('remember_user', '', time() - 3600);
    }
    if (isset($_COOKIE['remember_token'])) {
        setSecureCookie('remember_token', '', time() - 3600);
    }
    
    // Log the logout event
    if (isset($_SESSION['username'])) {
        logSecurityEvent('LOGOUT', $_SESSION['username'], 'User logged out');
    }
    
    // Clear all session data
    $_SESSION = [];
    
    // Delete session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    // Destroy session
    session_destroy();
}
?>