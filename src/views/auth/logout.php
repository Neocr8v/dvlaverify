/XAMPP/xamppfiles/htdocs/dvlaregister/src/views/auth/logout.php
<?php
session_start();

// Clear all session variables
$_SESSION = array();

// If it's desired to kill the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Clear remember me cookies if they exist
setcookie('remember_user', '', time() - 3600, '/');
setcookie('remember_token', '', time() - 3600, '/');

// Destroy the session
session_destroy();

// Redirect to login page
header('Location: login.php');
exit;
?>