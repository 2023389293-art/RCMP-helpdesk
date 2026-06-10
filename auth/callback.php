<?php
// auth/callback.php — unified SSO callback router
session_start();
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/sso_config.php';
require __DIR__ . '/../db_connect.php';

// ── State check ──────────────────────────────────────────────────────────
if (empty($_GET['state']) || $_GET['state'] !== $_SESSION['oauth2state']) {
    unset($_SESSION['oauth2state']);
    exit('Invalid state. Please try logging in again.');
}
unset($_SESSION['oauth2state']);

// ── Which flow triggered this? ───────────────────────────────────────────
$flow = $_SESSION['sso_flow'] ?? 'student';
unset($_SESSION['sso_flow']);

// ── Department maps ──────────────────────────────────────────────────────
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

// ── Exchange code for token ──────────────────────────────────────────────
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

    $email    = strtolower(trim($userData['mail'] ?? $userData['userPrincipalName'] ?? ''));
    $fullName = trim($userData['displayName'] ?? '');
    $domain   = strtolower(substr(strrchr($email, '@'), 1));

    if (empty($email)) {
        exit('SSO Error: Could not retrieve your email from Microsoft. Please contact IT support.');
    }

    session_regenerate_id(true);

    // ════════════════════════════════════════════════════════════════════
    // STAFF FLOW  (triggered from staff_sso_login.php)
    // ════════════════════════════════════════════════════════════════════
    if ($flow === 'staff') {

        if ($domain !== 'unikl.edu.my') {
            exit('Unauthorized: Staff login requires a @unikl.edu.my email. You used: ' . htmlspecialchars($email));
        }

        // Look up or auto-create staff record
        $stmt = $conn->prepare("SELECT * FROM staff WHERE email = ? AND status = 'active' LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) {
            // First-time SSO — create with default 'staff' role, no dept yet
            $stmt = $conn->prepare("INSERT INTO staff (full_name, email, password_hash, role, status) VALUES (?, ?, '', 'staff', 'active')");
            $stmt->bind_param('ss', $fullName, $email);
            $stmt->execute();
            $newId = $conn->insert_id;
            $stmt->close();

            $stmt = $conn->prepare("SELECT * FROM staff WHERE staff_id = ? LIMIT 1");
            $stmt->bind_param('i', $newId);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }

        $staffRole = $user['role'];
        $deptId    = !empty($user['dept_id']) ? (int)$user['dept_id'] : null;

        // Super Admin
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

        // Report Viewer
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

        // All other roles require a department
        if (empty($deptId) || !isset($deptFolders[$deptId])) {
            exit('Your staff account has no department assigned yet. Please contact the administrator.');
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

        if (in_array($staffRole, ['admin', 'hod'])) {
            header('Location: ../dept_admin/' . $folder . '/dashboard.php');
            exit;
        }

        header('Location: ../dept/' . $folder . '/dashboard.php');
        exit;
    }

    // ════════════════════════════════════════════════════════════════════
    // STUDENT FLOW  (triggered from sso_login.php)
    // ════════════════════════════════════════════════════════════════════

    // Accept student domains
    $allowedStudentDomains = ['s.unikl.edu.my', 'student.uitm.edu.my'];

    // Staff using student portal? redirect them properly
    if ($domain === 'unikl.edu.my') {
        $stmt = $conn->prepare("SELECT * FROM staff WHERE email = ? AND status = 'active' LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) {
            $stmt = $conn->prepare("INSERT INTO staff (full_name, email, password_hash, role, status) VALUES (?, ?, '', 'staff', 'active')");
            $stmt->bind_param('ss', $fullName, $email);
            $stmt->execute();
            $newId = $conn->insert_id;
            $stmt->close();

            $stmt = $conn->prepare("SELECT * FROM staff WHERE staff_id = ? LIMIT 1");
            $stmt->bind_param('i', $newId);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }

        $staffRole = $user['role'];
        $deptId    = !empty($user['dept_id']) ? (int)$user['dept_id'] : null;

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

        if (empty($deptId) || !isset($deptFolders[$deptId])) {
            exit('Your staff account has no department assigned yet. Please contact the administrator.');
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

        if (in_array($staffRole, ['admin', 'hod'])) {
            header('Location: ../dept_admin/' . $folder . '/dashboard.php');
            exit;
        }

        header('Location: ../dept/' . $folder . '/dashboard.php');
        exit;
    }

    // Student domain check
    if (!in_array($domain, $allowedStudentDomains)) {
        exit('Unauthorized: Please use your UniKL or UiTM student email. You used: ' . htmlspecialchars($email));
    }

    // Look up or auto-create student record
    $stmt = $conn->prepare("SELECT * FROM students WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        $stmt = $conn->prepare("INSERT INTO students (full_name, email, password_hash, faculty, status) VALUES (?, ?, '', '', 'active')");
        $stmt->bind_param('ss', $fullName, $email);
        $stmt->execute();
        $newId = $conn->insert_id;
        $stmt->close();

        $stmt = $conn->prepare("SELECT * FROM students WHERE student_id = ? LIMIT 1");
        $stmt->bind_param('i', $newId);
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

} catch (Exception $e) {
    exit('SSO Error: ' . htmlspecialchars($e->getMessage()) . ' — Please contact IT support.');
}