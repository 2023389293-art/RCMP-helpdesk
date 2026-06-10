<?php
// auth/sso_callback.php
// ─────────────────────────────────────────────────────────────────────────────
// Handles the redirect back from Microsoft after the user authenticates.
// Works for BOTH student (sso_login.php) and staff (staff_sso_login.php) flows,
// distinguished by $_SESSION['sso_login_mode'].
//
// Flow:
//  1. Validate state (CSRF protection)
//  2. Exchange authorization code for access token
//  3. Call MS Graph /me to get user's email
//  4. Look up the email in DB (students or staff table depending on mode)
//  5. Create the same session variables as the manual login pages
//  6. Redirect to the appropriate dashboard
// ─────────────────────────────────────────────────────────────────────────────
session_start();
require_once __DIR__ . '/sso_config.php';
require_once __DIR__ . '/../db_connect.php';

// ── Helpers ───────────────────────────────────────────────────────────────────

function sso_error(string $msg): never {
    // Redirect back to the relevant login page with an error flag.
    // You can style this however you like — for now a simple redirect with ?sso_error=1
    $loginPage = ($_SESSION['sso_login_mode'] ?? 'student') === 'staff'
        ? '../staff_login.php'
        : '../login.php';
    header('Location: ' . $loginPage . '?sso_error=' . urlencode($msg));
    exit;
}

/** Make an HTTP POST using cURL */
function http_post(string $url, array $fields): string|false {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

/** Make an HTTP GET using cURL with a Bearer token */
function http_get_bearer(string $url, string $token): string|false {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

// ── Department routing (mirrors staff_login.php) ──────────────────────────────

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

function buildStaffRedirect(string $role, string $folder): string {
    if ($role === 'super_admin' || $role === 'report_viewer') {
        return '../super_admin/dashboard.php';
    }
    $base = in_array($role, ['admin', 'hod'], true) ? 'dept_admin' : 'dept';
    return '../' . $base . '/' . $folder . '/dashboard.php';
}

// ── Dept param redirect (mirrors login.php / complaint flow) ─────────────────

function buildStudentRedirect(string $dept): string {
    $map = [
        'it'    => '../complaint/new_complaint.php?dept_tab=Information+Technology+Department',
        'hc'    => '../complaint/new_complaint.php?dept_tab=Human+Capital+Department',
        'af'    => '../complaint/new_complaint.php?dept_tab=Administration+%26+Facilities+Management+Department',
        'cc'    => '../complaint/new_complaint.php?dept_tab=Corporate+Communication+Unit',
        'maint' => '../complaint/new_complaint.php?dept_tab=Maintenance+Department',
    ];
    return $map[$dept] ?? '../complaint/homepage.php';
}

// ── Step 1: Validate state ─────────────────────────────────────────────────────

$returnedState = $_GET['state'] ?? '';
$savedState    = $_SESSION['sso_state'] ?? '';

if (empty($returnedState) || !hash_equals($savedState, $returnedState)) {
    sso_error('invalid_state');
}
unset($_SESSION['sso_state']); // one-time use

// Check for error from Microsoft
if (!empty($_GET['error'])) {
    sso_error(htmlspecialchars($_GET['error_description'] ?? $_GET['error']));
}

$code      = $_GET['code']      ?? '';
$loginMode = $_SESSION['sso_login_mode'] ?? 'student';
$deptParam = $_SESSION['sso_dept_param'] ?? '';
unset($_SESSION['sso_login_mode'], $_SESSION['sso_dept_param']);

if (empty($code)) {
    sso_error('no_code');
}

// ── Step 2: Exchange code for access token ────────────────────────────────────

$tokenResponse = http_post(SSO_TOKEN_URL, [
    'client_id'     => SSO_CLIENT_ID,
    'client_secret' => SSO_CLIENT_SECRET,
    'code'          => $code,
    'redirect_uri'  => SSO_REDIRECT_URI,
    'grant_type'    => 'authorization_code',
    'scope'         => SSO_SCOPES,
]);

if ($tokenResponse === false) {
    sso_error('token_request_failed');
}

$tokenData = json_decode($tokenResponse, true);

if (empty($tokenData['access_token'])) {
    sso_error('no_access_token');
}

$accessToken = $tokenData['access_token'];

// ── Step 3: Get user profile from MS Graph ────────────────────────────────────

$graphResponse = http_get_bearer(SSO_GRAPH_URL, $accessToken);

if ($graphResponse === false) {
    sso_error('graph_request_failed');
}

$profile = json_decode($graphResponse, true);

// Graph returns 'mail' (Exchange mailbox) or 'userPrincipalName' (UPN).
// UPN is always present; mail may be null for some accounts.
$email = strtolower(trim($profile['mail'] ?? $profile['userPrincipalName'] ?? ''));

if (empty($email)) {
    sso_error('no_email');
}

// ── Step 4 & 5: Look up DB and create session ────────────────────────────────

if ($loginMode === 'student') {
    // ── STUDENT FLOW ──────────────────────────────────────────────────────────
    $stmt = $conn->prepare("SELECT * FROM students WHERE email = ? AND status = 'active' LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        // Not in students table — try staff table as fallback (same as manual login)
        $stmt = $conn->prepare("SELECT * FROM staff WHERE email = ? AND status = 'active' LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) {
            sso_error('account_not_found');
        }

        // Found in staff — treat as staff login
        $loginMode = 'staff';
    }

    if ($loginMode === 'student') {
        session_regenerate_id(true);
        $_SESSION['user_id']    = $user['student_id'];
        $_SESSION['user_name']  = $user['full_name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role']  = 'student';
        $_SESSION['user_dept']  = null;
        unset($_SESSION['staff_id'], $_SESSION['fb_popup_shown']);
        header('Location: ' . buildStudentRedirect($deptParam));
        exit;
    }
    // Fall through to staff handling below if loginMode was switched to 'staff'
}

if ($loginMode === 'staff') {
    // ── STAFF FLOW ────────────────────────────────────────────────────────────
    $stmt = $conn->prepare("SELECT * FROM staff WHERE email = ? AND status = 'active' LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        sso_error('account_not_found');
    }

    $staffRole = $user['role'];
    $deptId    = !empty($user['dept_id']) ? (int)$user['dept_id'] : null;

    session_regenerate_id(true);
    $_SESSION['staff_id']    = $user['staff_id'];
    $_SESSION['user_id']     = $user['staff_id'];
    $_SESSION['staff_code']  = $user['staff_code'];
    $_SESSION['staff_name']  = $user['full_name'];
    $_SESSION['user_name']   = $user['full_name'];
    $_SESSION['staff_email'] = $user['email'];
    $_SESSION['user_email']  = $user['email'];
    $_SESSION['staff_role']  = $staffRole;
    $_SESSION['user_role']   = $staffRole;
    unset($_SESSION['fb_popup_shown']);

    if ($staffRole === 'super_admin' || $staffRole === 'report_viewer') {
        $_SESSION['dept_id']     = null;
        $_SESSION['dept_name']   = $staffRole === 'super_admin' ? 'Super Admin' : 'Report Viewer';
        $_SESSION['dept_folder'] = '';
        $_SESSION['user_dept']   = null;
        header('Location: ../super_admin/dashboard.php');
        exit;
    }

    if (empty($deptId) || !isset($deptFolders[$deptId])) {
        sso_error('no_department');
    }

    $_SESSION['dept_id']     = $deptId;
    $_SESSION['dept_name']   = $deptNames[$deptId];
    $_SESSION['dept_folder'] = $deptFolders[$deptId];
    $_SESSION['user_dept']   = $deptId;

    // Also set user_dept as dept_id integer (mirrors manual login in login.php)
    $dstmt = $conn->prepare("SELECT dept_id FROM departments WHERE dept_name = ? LIMIT 1");
    $dstmt->bind_param('s', $user['department']);
    $dstmt->execute();
    $drow = $dstmt->get_result()->fetch_assoc();
    $dstmt->close();
    if ($drow) $_SESSION['user_dept'] = $drow['dept_id'];

    header('Location: ' . buildStaffRedirect($staffRole, $deptFolders[$deptId]));
    exit;
}

// Should never reach here
sso_error('unknown_flow');