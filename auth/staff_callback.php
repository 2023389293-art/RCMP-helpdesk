<?php
// auth/staff_callback.php
session_start();
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/sso_config.php';
require __DIR__ . '/../db_connect.php';

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
    'redirectUri' => 'https://rush.rcmp.edu.my/complaint/auth/staff_callback.php',
    'tenant'       => AZURE_TENANT_ID,
]);

try {
    $token    = $provider->getAccessToken('authorization_code', ['code' => $_GET['code']]);
    $me       = $provider->get($provider->getRootMicrosoftGraphUri($token) . '/v1.0/me', $token);
    $userData = json_decode($me->getBody(), true);

    $email    = strtolower($userData['mail'] ?? $userData['userPrincipalName']);
    $fullName = $userData['displayName'] ?? '';
    $domain   = substr(strrchr($email, '@'), 1);

    // Staff SSO only accepts @unikl.edu.my
    if ($domain !== 'unikl.edu.my') {
        exit('Unauthorized: Staff SSO requires a @unikl.edu.my email.');
    }

    // Look up the staff record — role is whatever is in the DB
    $stmt = $conn->prepare("SELECT * FROM staff WHERE email = ? AND status = 'active' LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        // First-time SSO: auto-create with default 'staff' role, no dept yet
        // Admin will need to assign dept + role manually
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

    $staffRole = $user['role'];   // 'staff', 'admin', 'hod', 'super_admin', 'report_viewer'
    $deptId    = !empty($user['dept_id']) ? (int)$user['dept_id'] : null;

    session_regenerate_id(true);

    // ── Super Admin ──────────────────────────────────────────────────────
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

    // ── Report Viewer ────────────────────────────────────────────────────
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

    // ── All other roles need a valid department ──────────────────────────
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

    // ── HOD / Admin → dept_admin/{folder}/dashboard.php ─────────────────
    if (in_array($staffRole, ['admin', 'hod'])) {
        header('Location: ../dept_admin/' . $folder . '/dashboard.php');
        exit;
    }

    // ── Staff → dept/{folder}/dashboard.php ─────────────────────────────
    header('Location: ../dept/' . $folder . '/dashboard.php');
    exit;

} catch (Exception $e) {
    exit('SSO Error: ' . $e->getMessage());
}