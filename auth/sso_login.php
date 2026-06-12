<?php
// auth/sso_login.php
session_start();
require_once __DIR__ . '/sso_config.php';

$deptParam = $_GET['dept'] ?? '';
$allowedDepts = ['it', 'hc', 'af', 'cc', 'maint'];
if (!in_array($deptParam, $allowedDepts, true)) {
    $deptParam = '';
}

$state = bin2hex(random_bytes(16));
$_SESSION['sso_state']      = $state;
$_SESSION['sso_login_mode'] = 'user';   // ← CHANGED: was 'student'
$_SESSION['sso_dept_param'] = $deptParam;

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