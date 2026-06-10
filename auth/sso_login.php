<?php
// auth/sso_login.php
// ─────────────────────────────────────────────────────────────────────────────
// Initiates Microsoft SSO for STUDENTS / general users (login.php button).
// Sets login_mode = 'student' in session so the callback knows which table
// to authenticate against.
// ─────────────────────────────────────────────────────────────────────────────
session_start();
require_once __DIR__ . '/sso_config.php';

// Pass along the dept redirect param if present
$deptParam = $_GET['dept'] ?? '';
$allowedDepts = ['it', 'hc', 'af', 'cc', 'maint'];
if (!in_array($deptParam, $allowedDepts, true)) {
    $deptParam = '';
}

// Generate a cryptographically secure state token to prevent CSRF
$state = bin2hex(random_bytes(16));
$_SESSION['sso_state']      = $state;
$_SESSION['sso_login_mode'] = 'student';          // callback will use student table
$_SESSION['sso_dept_param'] = $deptParam;

// Build the Microsoft authorization URL
$params = http_build_query([
    'client_id'     => SSO_CLIENT_ID,
    'response_type' => 'code',
    'redirect_uri'  => SSO_REDIRECT_URI,
    'response_mode' => 'query',
    'scope'         => SSO_SCOPES,
    'state'         => $state,
    // prompt=select_account forces the MS account picker every time (optional)
    // 'prompt'     => 'select_account',
]);

header('Location: ' . SSO_AUTH_URL . '?' . $params);
exit;