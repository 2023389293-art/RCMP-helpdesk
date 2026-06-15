<?php
// test_graph.php
require_once __DIR__ . '/graph_helper.php';

$token = getGraphAppToken();
echo "TOKEN: ";
var_dump($token);

if ($token) {
    // Test panggil endpoint /users (list, bukan by OID dulu)
    $ch = curl_init('https://graph.microsoft.com/v1.0/users?$top=1');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
    ]);
    $result = curl_exec($ch);
    $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "HTTP CODE: $code\n";
    echo "RESULT: $result\n";
}