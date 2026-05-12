<?php
// complaint/logout.php
// ── Destroys the student session and redirects to login ───────────────────────

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Clear the feedback popup flag so it shows again on next login
// (if there are new closed tickets without feedback)
unset($_SESSION['fb_popup_shown']);

// Destroy the entire session
session_destroy();

// Clear the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Redirect to login
header('Location: ../login.php');
exit;