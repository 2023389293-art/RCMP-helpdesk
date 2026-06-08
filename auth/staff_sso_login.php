<?php
// auth/staff_sso_login.php — for STAFF portal (staff_login.php)
session_start();
require '../vendor/autoload.php';
require 'sso_config.php';

$provider = new TheNetworg\OAuth2\Client\Provider\Azure([
    'clientId'     => AZURE_CLIENT_ID,
    'clientSecret' => AZURE_CLIENT_SECRET,
    'redirectUri'  => 'http://localhost/uniKL/complaint/auth/staff_callback.php',
    'tenant'       => AZURE_TENANT_ID,
]);

$authUrl = $provider->getAuthorizationUrl([
    'scope' => ['openid', 'profile', 'email', 'User.Read'],
]);

$_SESSION['oauth2state'] = $provider->getState();
header('Location: ' . $authUrl);
exit;