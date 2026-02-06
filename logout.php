<?php
/**
 * Spacemount WorkHub - SECURE LOGOUT HANDLER
 * File: logout.php
 * * DESCRIPTION:
 * Destroys the authenticated session, clears security cookies, 
 * logs the manual exit in the audit trail, and redirects to login.
 */

// 1. INTEGRATION & INITIALIZATION
require_once 'core.php';
use Spacemount\WorkHub\CoreEngine;

$core = CoreEngine::getInstance();

// 2. AUDIT LOGGING (Pre-destruction)
// We capture the logout event while the session context is still available
try {
    if (isset($_SESSION['user_id'])) {
        $core->logAction(
            $_SESSION['user_id'], 
            null, 
            'Security', 
            'LOGOUT_MANUAL', 
            'User initiated manual logout from session.'
        );
    }
} catch (Exception $e) {
    // Fail silently to ensure logout proceeds even if logging fails
}

// 3. SESSION CLEARANCE
// Unset all session variables
$_SESSION = array();

// 4. COOKIE CLEANUP
// If it's desired to kill the session, also delete the session cookie.
// Note: This completely destroys the session, not just the session data!
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), 
        '', 
        time() - 42000,
        $params["path"], 
        $params["domain"],
        $params["secure"], 
        $params["httponly"]
    );
}

// 5. SESSION DESTRUCTION
if (session_status() === PHP_SESSION_ACTIVE) {
    session_unset();
    session_destroy();
}

// 6. REDIRECTION
// Redirect to the index page with a success flag for UI feedback
header("Location: index.php?message=logged_out");
exit;