<?php
// auth/callback.php
session_start();
require '../vendor/autoload.php';
require 'sso_config.php';
require '../db_connect.php';

if (empty($_GET['state']) || $_GET['state'] !== $_SESSION['oauth2state']) {
    unset($_SESSION['oauth2state']);
    exit('Invalid state.');
}

$deptFolders = [
    1 => 'afsmd',
    2 => 'maintenance',
    3 => 'ccu',
    4 => 'it',
    5 => 'hcd',
];

$deptNames = [
    1 => 'Administration & Facilities Management',
    2 => 'Maintenance Department',
    3 => 'Corporate Communication Unit',
    4 => 'Information Technology',
    5 => 'Human Capital Department',
];

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

    // ── STUDENT ─────────────────────────────────────────────────────────
    if ($domain === 's.unikl.edu.my') {

        $stmt = $conn->prepare("SELECT * FROM students WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) {
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

    // ── STAFF ────────────────────────────────────────────────────────────
    } elseif ($domain === 'unikl.edu.my') {

        $stmt = $conn->prepare("SELECT * FROM staff WHERE email = ? AND status = 'active' LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) {
            // First-time SSO login — auto-create with no dept/role yet
            // Super admin will need to assign dept and role
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

        $staffRole = $user['role'];
        $deptId    = !empty($user['dept_id']) ? (int)$user['dept_id'] : null;

        // ── Super Admin ──────────────────────────────────────────────────
        if ($staffRole === 'super_admin') {
            $_SESSION['staff_id']    = $user['staff_id'];
            $_SESSION['staff_code']  = $user['staff_code'];
            $_SESSION['staff_name']  = $user['full_name'] ?: $fullName;
            $_SESSION['staff_email'] = $user['email'];
            $_SESSION['staff_role']  = 'super_admin';
            $_SESSION['dept_id']     = null;
            $_SESSION['dept_name']   = 'Super Admin';
            $_SESSION['dept_folder'] = '';
            header('Location: ../super_admin/dashboard.php');
            exit;
        }

        // ── Report Viewer ────────────────────────────────────────────────
        if ($staffRole === 'report_viewer') {
            $_SESSION['staff_id']    = $user['staff_id'];
            $_SESSION['staff_code']  = $user['staff_code'];
            $_SESSION['staff_name']  = $user['full_name'] ?: $fullName;
            $_SESSION['staff_email'] = $user['email'];
            $_SESSION['staff_role']  = 'report_viewer';
            $_SESSION['dept_id']     = null;
            $_SESSION['dept_name']   = 'Report Viewer';
            $_SESSION['dept_folder'] = '';
            header('Location: ../super_admin/dashboard.php');
            exit;
        }

        // ── All other roles need a valid department ──────────────────────
        if (empty($deptId) || !isset($deptFolders[$deptId])) {
            exit('Your account has no department assigned. Please contact the administrator.');
        }

        $folder = $deptFolders[$deptId];

        $_SESSION['staff_id']    = $user['staff_id'];
        $_SESSION['staff_code']  = $user['staff_code'];
        $_SESSION['staff_name']  = $user['full_name'] ?: $fullName;
        $_SESSION['staff_email'] = $user['email'];
        $_SESSION['staff_role']  = $staffRole;
        $_SESSION['dept_id']     = $deptId;
        $_SESSION['dept_name']   = $deptNames[$deptId];
        $_SESSION['dept_folder'] = $folder;

        // HOD + Admin → dept_admin, Staff → dept
        if (in_array($staffRole, ['admin', 'hod'])) {
            header('Location: ../dept_admin/' . $folder . '/dashboard.php');
            exit;
        }

        header('Location: ../dept/' . $folder . '/dashboard.php');
        exit;

    } else {
        exit('Unauthorized: Please use your UniKL email.');
    }

} catch (Exception $e) {
    exit('SSO Error: ' . $e->getMessage());
}