<?php
// auth/callbackExample.php
session_start();
require '../vendor/autoload.php';
require 'sso_config.php';
require '../db_connect.php';

if (empty($_GET['state']) || $_GET['state'] !== $_SESSION['oauth2state']) {
    unset($_SESSION['oauth2state']);
    exit('Invalid state.');
}

$provider = new TheNetworg\OAuth2\Client\Provider\Azure([
    'clientId'     => AZURE_CLIENT_ID,
    'clientSecret' => AZURE_CLIENT_SECRET,
    'redirectUri'  => AZURE_REDIRECT_URI,
    'tenant'       => AZURE_TENANT_ID,
]);

try {
    $token    = $provider->getAccessToken('authorization_code', ['code' => $_GET['code']]);
    $me       = $provider->get($provider->getRootMicrosoftGraphUri($token) . '/v1.0/me', $token);
    $userData = json_decode($me->getBody(), true);

    $email    = strtolower($userData['mail'] ?? $userData['userPrincipalName']);
    $fullName = $userData['displayName'] ?? '';
    $domain   = substr(strrchr($email, '@'), 1);

    session_regenerate_id(true);

    if ($domain === 's.unikl.edu.my') {
        // STUDENT LOGIN
        $stmt = $conn->prepare("SELECT * FROM students WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) {
            // First time → auto create student
            $stmt = $conn->prepare("INSERT INTO students (full_name, email, password_hash, faculty, status) VALUES (?, ?, '', '', 'active')");
            $stmt->bind_param("ss", $fullName, $email);
            $stmt->execute();
            $newId = $conn->insert_id;
            $stmt->close();

            $stmt = $conn->prepare("SELECT * FROM students WHERE student_id = ? LIMIT 1");
            $stmt->bind_param("i", $newId);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }

        $_SESSION['user_id']    = $user['student_id'];
        $_SESSION['user_name']  = $user['full_name'] ?: $fullName;
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role']  = 'student';
        $_SESSION['user_dept']  = null;
        unset($_SESSION['staff_id']);
        header('Location: ../complaint/homepage.php');
        exit;

    } elseif ($domain === 'unikl.edu.my') {
        // STAFF LOGIN
        $stmt = $conn->prepare("SELECT * FROM staff WHERE email = ? AND status = 'active' LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) {
            // First time → auto create staff
            $stmt = $conn->prepare("INSERT INTO staff (full_name, email, password_hash, role, status) VALUES (?, ?, '', 'staff', 'active')");
            $stmt->bind_param("ss", $fullName, $email);
            $stmt->execute();
            $newId = $conn->insert_id;
            $stmt->close();

            $stmt = $conn->prepare("SELECT * FROM staff WHERE staff_id = ? LIMIT 1");
            $stmt->bind_param("i", $newId);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }

        $_SESSION['staff_id']   = $user['staff_id'];
        $_SESSION['user_id']    = $user['staff_id'];
        $_SESSION['user_name']  = $user['full_name'] ?: $fullName;
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role']  = $user['role'];

        $deptId = null;
        if (!empty($user['department'])) {
            $dstmt = $conn->prepare("SELECT dept_id FROM departments WHERE dept_name = ? LIMIT 1");
            $dstmt->bind_param("s", $user['department']);
            $dstmt->execute();
            $drow = $dstmt->get_result()->fetch_assoc();
            $dstmt->close();
            if ($drow) $deptId = $drow['dept_id'];
        }
        $_SESSION['user_dept'] = $deptId;
        header('Location: ../complaint/homepage.php');
        exit;

    } else {
        exit('Unauthorized: Please use your UniKL email.');
    }

} catch (Exception $e) {
    exit('SSO Error: ' . $e->getMessage());
}