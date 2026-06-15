<?php
// graph_helper.php  (project root)
require_once __DIR__ . '/auth/sso_config.php';

function getGraphAppToken(): ?string {
    $url = 'https://login.microsoftonline.com/' . SSO_TENANT_ID . '/oauth2/v2.0/token';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => SSO_CLIENT_ID,
            'client_secret' => SSO_CLIENT_SECRET,
            'scope'         => 'https://graph.microsoft.com/.default',
            'grant_type'    => 'client_credentials',
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    $json = json_decode($result, true);
    return $json['access_token'] ?? null;
}

function getGraphUserByOid(string $entraOid): ?array {
    $token = getGraphAppToken();
    if (!$token) return null;

    $url = 'https://graph.microsoft.com/v1.0/users/' . urlencode($entraOid)
         . '?$select=id,displayName,mail,userPrincipalName';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $result  = curl_exec($ch);
    curl_close($ch);
    $profile = json_decode($result, true);

    $email = strtolower(trim($profile['mail'] ?? $profile['userPrincipalName'] ?? ''));
    $name  = trim($profile['displayName'] ?? '');
    if (empty($email)) return null;
    if (empty($name)) {
        $name = ucwords(str_replace(['.','_','-'], ' ', explode('@', $email)[0]));
    }
    return ['email' => $email, 'name' => $name];
}