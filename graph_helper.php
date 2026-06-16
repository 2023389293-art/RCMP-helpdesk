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
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $result = curl_exec($ch);
    // ADD THIS:
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode($result, true);
    // ADD THIS:
    if (empty($json['access_token'])) {
        error_log("[Graph] getGraphAppToken failed. HTTP={$httpCode} Response=" . $result);
    }
    return $json['access_token'] ?? null;
}

function getGraphUserByOid(string $entraOid): ?array {
    if (empty($entraOid)) return null;

    // Session cache — avoid repeated Graph calls per page load
    if (session_status() === PHP_SESSION_NONE) session_start();
    $cacheKey = 'graph_user_' . $entraOid;
    if (array_key_exists($cacheKey, $_SESSION)) {
        // Only successes are cached — return the cached data
        return is_array($_SESSION[$cacheKey]) ? $_SESSION[$cacheKey] : null;
    }

    $token = getGraphAppToken();
    if (!$token) {
        error_log("[Graph] Failed to get app token for OID: {$entraOid}");
        return null;
    }

    $url = 'https://graph.microsoft.com/v1.0/users/' . urlencode($entraOid)
         . '?$select=id,displayName,mail,userPrincipalName';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $result   = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log("[Graph] HTTP {$httpCode} for OID {$entraOid}: {$result}");
        // Do NOT cache failures — let it retry on next page load
        return null;
    }

    $profile = json_decode($result, true);
    $email   = strtolower(trim($profile['mail'] ?? $profile['userPrincipalName'] ?? ''));
    $name    = trim($profile['displayName'] ?? '');

    if (empty($email)) {
        error_log("[Graph] No email returned for OID: {$entraOid}");
        return null;
    }
    if (empty($name)) {
        $name = ucwords(str_replace(['.','_','-'], ' ', explode('@', $email)[0]));
    }

    $data = ['email' => $email, 'name' => $name];
    $_SESSION[$cacheKey] = $data;
    return $data;
}