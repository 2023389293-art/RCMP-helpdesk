<?php
session_start();
require '../vendor/autoload.php';
require 'sso_config.php';

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