<?php
// auth/sso_login.php
session_start();
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/sso_config.php';

// Preserve the dept param through the OAuth round-trip via session
$dept = $_GET['dept'] ?? '';
$allowedDepts = ['it', 'hc', 'af', 'cc', 'maint'];
$_SESSION['sso_dept_redirect'] = in_array($dept, $allowedDepts) ? $dept : '';

$provider = new TheNetworg\OAuth2\Client\Provider\Azure([
    'clientId'     => AZURE_CLIENT_ID,
    'clientSecret' => AZURE_CLIENT_SECRET,
    'redirectUri'  => AZURE_REDIRECT_URI,
    'tenant'       => AZURE_TENANT_ID,
]);

$authUrl = $provider->getAuthorizationUrl([
    'scope' => ['openid', 'profile', 'email', 'User.Read'],
]);

$_SESSION['oauth2state'] = $provider->getState();
header('Location: ' . $authUrl);
exit;