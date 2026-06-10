<?php
// auth/staff_sso_login.php
// ─────────────────────────────────────────────────────────────────────────────
// Initiates Microsoft SSO for STAFF / operators (staff_login.php button).
// Sets login_mode = 'staff' in session so the callback authenticates against
// the staff table and applies the role-based redirect logic.
// ─────────────────────────────────────────────────────────────────────────────
session_start();
require_once __DIR__ . '/sso_config.php';

// Generate a cryptographically secure state token to prevent CSRF
$state = bin2hex(random_bytes(16));
$_SESSION['sso_state']      = $state;
$_SESSION['sso_login_mode'] = 'staff';            // callback will use staff table

// Build the Microsoft authorization URL
$params = http_build_query([
    'client_id'     => SSO_CLIENT_ID,
    'response_type' => 'code',
    'redirect_uri'  => SSO_REDIRECT_URI,
    'response_mode' => 'query',
    'scope'         => SSO_SCOPES,
    'state'         => $state,
]);

header('Location: ' . SSO_AUTH_URL . '?' . $params);
exit;