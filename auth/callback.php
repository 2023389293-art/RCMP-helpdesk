<?php
// auth/callback.php
session_start();
require_once __DIR__ . '/sso_config.php';
require_once __DIR__ . '/../db_connect.php';

// ── Helpers ───────────────────────────────────────────────────────────────────

function sso_error(string $msg): void {
    // ── UPDATED: mode is now 'user' instead of 'student' ──────────────────────
    $loginPage = ($_SESSION['sso_login_mode'] ?? 'user') === 'staff'
        ? SSO_APP_BASE_URL . '/staff_login.php'
        : SSO_APP_BASE_URL . '/login.php';
    header('Location: ' . $loginPage . '?sso_error=' . urlencode($msg));
    exit;
}

function http_post(string $url, array $fields) {
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

function http_get_bearer(string $url, string $token) {
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

// ── Department routing (unchanged — staff only) ───────────────────────────────

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
        return SSO_APP_BASE_URL . '/super_admin/dashboard.php';
    }
    $dir = in_array($role, ['admin', 'hod'], true) ? 'dept_admin' : 'dept';
    return SSO_APP_BASE_URL . '/' . $dir . '/' . $folder . '/dashboard.php';
}

// ── UPDATED: kept the same redirect logic ────────────────────────────────────
function buildUserRedirect(string $dept): string {
    $map = [
        'it'    => SSO_APP_BASE_URL . '/complaint/new_complaint.php?dept_tab=Information+Technology+Department',
        'hc'    => SSO_APP_BASE_URL . '/complaint/new_complaint.php?dept_tab=Human+Capital+Department',
        'af'    => SSO_APP_BASE_URL . '/complaint/new_complaint.php?dept_tab=Administration+%26+Facilities+Management+Department',
        'cc'    => SSO_APP_BASE_URL . '/complaint/new_complaint.php?dept_tab=Corporate+Communication+Unit',
        'maint' => SSO_APP_BASE_URL . '/complaint/new_complaint.php?dept_tab=Maintenance+Department',
    ];
    return $map[$dept] ?? SSO_APP_BASE_URL . '/complaint/homepage.php';
}

// ── Step 1: Validate state ────────────────────────────────────────────────────

$returnedState = $_GET['state'] ?? '';
$savedState    = $_SESSION['sso_state'] ?? '';

if (empty($returnedState) || !hash_equals($savedState, $returnedState)) {
    sso_error('invalid_state');
}
unset($_SESSION['sso_state']);

if (!empty($_GET['error'])) {
    sso_error(htmlspecialchars($_GET['error_description'] ?? $_GET['error']));
}

$code      = $_GET['code']      ?? '';
$loginMode = $_SESSION['sso_login_mode'] ?? 'user';   // ← default is now 'user'
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

if ($tokenResponse === false) { sso_error('token_request_failed'); }

$tokenData = json_decode($tokenResponse, true);
if (empty($tokenData['access_token'])) { sso_error('no_access_token'); }

$accessToken = $tokenData['access_token'];

// ── Step 3: Get user profile from MS Graph ────────────────────────────────────
// SSO_GRAPH_URL now includes ?$select=id,displayName,mail,userPrincipalName,...
// 'id' here is the Entra Object ID (oid) — unique per user per tenant

$graphResponse = http_get_bearer(SSO_GRAPH_URL, $accessToken);
if ($graphResponse === false) { sso_error('graph_request_failed'); }

$profile = json_decode($graphResponse, true);

// entra_oid: the Graph 'id' field = same as 'oid' claim in the JWT token
$entraOid    = trim($profile['id'] ?? '');
$email       = strtolower(trim($profile['mail'] ?? $profile['userPrincipalName'] ?? ''));
// Display name: comes from Graph, stored in SESSION only — not in DB
$displayName = trim($profile['displayName'] ?? '');

if (empty($entraOid)) { sso_error('no_oid'); }
if (empty($email))    { sso_error('no_email'); }

if (empty($displayName)) {
    // Fallback: derive a readable name from the email prefix
    $displayName = ucwords(str_replace(['.', '_', '-'], ' ', explode('@', $email)[0]));
}

// ── Step 4 & 5: Look up DB and create session ────────────────────────────────

if ($loginMode === 'user') {
    // ── USER FLOW (formerly 'student') ───────────────────────────────────────

    // Validate: only allow UniKL Microsoft-authenticated domains
    $emailDomain    = strtolower(substr(strrchr($email, '@'), 1));
    $allowedDomains = ['unikl.edu.my', 's.unikl.edu.my', 'rcmp.edu.my'];

    if (!in_array($emailDomain, $allowedDomains, true)) {
        sso_error('account_not_found');
    }

    // ── Check staff table first (staff submitting complaints via login.php) ───
    $stmt = $conn->prepare("SELECT * FROM staff WHERE email = ? AND status = 'active' LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $staffUser = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($staffUser) {
        session_regenerate_id(true);
        $_SESSION['staff_id']    = $staffUser['staff_id'];
        $_SESSION['user_id']     = $staffUser['staff_id'];
        $_SESSION['staff_code']  = $staffUser['staff_code'];
        // ── Name comes from Graph (session only), NOT from staff table ─────────
        // But staff table still has full_name for backend display — keep using it
        // for staff since they are not PDPA-sensitive submitters in this context.
        // If your boss also wants to strip staff names, handle separately.
        $_SESSION['staff_name']  = $staffUser['full_name'];
        $_SESSION['user_name']   = $displayName;           // Graph name in session
        $_SESSION['staff_email'] = $staffUser['email'];
        $_SESSION['user_email']  = $email;
        $_SESSION['staff_role']  = $staffUser['role'];
        $_SESSION['user_role']   = $staffUser['role'];
        $_SESSION['user_dept']   = $staffUser['dept_id'] ?? null;
        $_SESSION['entra_oid']   = $entraOid;              // ← NEW: store oid
        unset($_SESSION['fb_popup_shown']);
        header('Location: ' . buildUserRedirect($deptParam));
        exit;
    }

    // ── Check users table by entra_oid (primary) or email (fallback) ─────────
    $stmt = $conn->prepare("SELECT * FROM users WHERE entra_oid = ? AND status = 'active' LIMIT 1");
    $stmt->bind_param('s', $entraOid);
    $stmt->execute();
    $existingUser = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Fallback: look up by email in case entra_oid was not stored before
   // No email fallback — entra_oid is the sole identifier

    if ($existingUser) {
        // Known user — log in
        session_regenerate_id(true);
        $_SESSION['user_id']    = $existingUser['user_id'];
        $_SESSION['user_name']  = $displayName;    // from Graph, NOT from DB
        $_SESSION['user_email'] = $email;          // from Graph, NOT from DB
        $_SESSION['user_role']  = 'user';          // ← CHANGED: was 'student'
        $_SESSION['user_dept']  = null;
        $_SESSION['entra_oid']  = $entraOid;
        unset($_SESSION['staff_id'], $_SESSION['fb_popup_shown']);
        header('Location: ' . buildUserRedirect($deptParam));
        exit;
    }

    // ── Auto-provision: valid UniKL domain, not yet in users table ───────────
    // Store ONLY entra_oid + email — no name, no phone, no faculty (PDPA clean)
    $stmt = $conn->prepare("
    INSERT INTO users (entra_oid, status, created_at)
    VALUES (?, 'active', NOW())
");
$stmt->bind_param('s', $entraOid);
    $stmt->execute();
    $newUserId = $conn->insert_id;
    $stmt->close();

    session_regenerate_id(true);
    $_SESSION['user_id']    = $newUserId;
    $_SESSION['user_name']  = $displayName;    // from Graph, session only
    $_SESSION['user_email'] = $email;          // from Graph, session only
    $_SESSION['user_role']  = 'user';          // ← CHANGED: was 'student'
    $_SESSION['user_dept']  = null;
    $_SESSION['entra_oid']  = $entraOid;
    unset($_SESSION['staff_id'], $_SESSION['fb_popup_shown']);
    header('Location: ' . buildUserRedirect($deptParam));
    exit;
}

if ($loginMode === 'staff') {
    // ── STAFF FLOW (unchanged) ────────────────────────────────────────────────
    $stmt = $conn->prepare("SELECT * FROM staff WHERE email = ? AND status = 'active' LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) { sso_error('account_not_found'); }

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
    $_SESSION['entra_oid']   = $entraOid;    // ← NEW: store for reference
    unset($_SESSION['fb_popup_shown']);

    if ($staffRole === 'super_admin' || $staffRole === 'report_viewer') {
        $_SESSION['dept_id']     = null;
        $_SESSION['dept_name']   = $staffRole === 'super_admin' ? 'Super Admin' : 'Report Viewer';
        $_SESSION['dept_folder'] = '';
        $_SESSION['user_dept']   = null;
        header('Location: ' . SSO_APP_BASE_URL . '/super_admin/dashboard.php');
        exit;
    }

    if (empty($deptId) || !isset($deptFolders[$deptId])) {
        sso_error('no_department');
    }

    $_SESSION['dept_id']     = $deptId;
    $_SESSION['dept_name']   = $deptNames[$deptId];
    $_SESSION['dept_folder'] = $deptFolders[$deptId];
    $_SESSION['user_dept']   = $deptId;

    $dstmt = $conn->prepare("SELECT dept_id FROM departments WHERE dept_name = ? LIMIT 1");
    $dstmt->bind_param('s', $user['department']);
    $dstmt->execute();
    $drow = $dstmt->get_result()->fetch_assoc();
    $dstmt->close();
    if ($drow) $_SESSION['user_dept'] = $drow['dept_id'];

    header('Location: ' . buildStaffRedirect($staffRole, $deptFolders[$deptId]));
    exit;
}

sso_error('unknown_flow');