<?php
// auth/callback.php
session_start();
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/sso_config.php';
require __DIR__ . '/../db_connect.php';

if (empty($_GET['state']) || $_GET['state'] !== $_SESSION['oauth2state']) {
    unset($_SESSION['oauth2state']);
    exit('Invalid state.');
}

// ── Dept redirect map (mirrors login.php buildRedirectUrl) ──────────────────
$deptMap = [
    'it'    => 'complaint/new_complaint.php?dept_tab=Information+Technology+Department',
    'hc'    => 'complaint/new_complaint.php?dept_tab=Human+Capital+Department',
    'af'    => 'complaint/new_complaint.php?dept_tab=Administration+%26+Facilities+Management+Department',
    'cc'    => 'complaint/new_complaint.php?dept_tab=Corporate+Communication+Unit',
    'maint' => 'complaint/new_complaint.php?dept_tab=Maintenance+Department',
];

// Recover dept param saved before the OAuth redirect, then clear it
$dept        = $_SESSION['sso_dept_redirect'] ?? '';
$studentDest = isset($deptMap[$dept]) ? $deptMap[$dept] : 'complaint/homepage.php';
$staffDest   = isset($deptMap[$dept]) ? $deptMap[$dept] : 'complaint/homepage.php';
unset($_SESSION['sso_dept_redirect']);

// ── Dept folder / name maps ──────────────────────────────────────────────────
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

    // ── STUDENT ─────────────────────────────────────────────────────────────
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

        header('Location: ../' . $studentDest);
        exit;

    // ── STAFF ────────────────────────────────────────────────────────────────
    } elseif ($domain === 'unikl.edu.my') {

        $stmt = $conn->prepare("SELECT * FROM staff WHERE email = ? AND status = 'active' LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) {
            // First-time SSO login — auto-create with no dept/role yet
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

        // ── Staff / Admin / HOD — need a valid department ────────────────────
        // Regular staff submitting a complaint: send to dept redirect or homepage
        if ($staffRole === 'staff') {
            $_SESSION['staff_id']   = $user['staff_id'];
            $_SESSION['user_id']    = $user['staff_id'];
            $_SESSION['user_name']  = $user['full_name'] ?: $fullName;
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role']  = 'staff';

            $deptDbId = null;
            if (!empty($user['department'])) {
                $dstmt = $conn->prepare("SELECT dept_id FROM departments WHERE dept_name = ? LIMIT 1");
                $dstmt->bind_param("s", $user['department']);
                $dstmt->execute();
                $drow = $dstmt->get_result()->fetch_assoc();
                $dstmt->close();
                if ($drow) $deptDbId = $drow['dept_id'];
            }
            $_SESSION['user_dept'] = $deptDbId;

            header('Location: ../' . $staffDest);
            exit;
        }

        // ── Admin / HOD — must have a dept assigned ──────────────────────────
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