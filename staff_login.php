<?php 
// staff_login.php — UniKL CMS Staff Department Login   
session_start();
 
// ── Handle logout ──────────────────────────────────────────────────────────
if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header("Location: staff_login.php");
    exit;
}
// ──────────────────────────────────────────────────────────────────────────

require 'db_connect.php';

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

// ── Helper: build redirect URL based on role ───────────────────────────────
// Admins  → dept_admin/{folder}/dashboard.php
// Staff   → dept/{folder}/dashboard.php
function buildRedirectUrl(string $role, string $folder = ''): string {
    if ($role === 'super_admin') {
        return 'super_admin/dashboard.php';
    }
    $base = in_array($role, ['admin', 'hod']) ? 'dept_admin' : 'dept';
    return $base . '/' . $folder . '/dashboard.php';
}

// ── If already logged in, redirect to their dashboard ─────────────────────
if (!empty($_SESSION['staff_id']) && !empty($_SESSION['staff_role'])) {
    $staffRole = $_SESSION['staff_role'];

    if ($staffRole === 'super_admin') {
        header("Location: super_admin/dashboard.php");
        exit;
    }

if ($staffRole === 'report_viewer') {
    header("Location: super_admin/dashboard.php");
    exit;
}

    $folder = $_SESSION['dept_folder'] ?? '';
    if (in_array($folder, $deptFolders, true) && in_array($staffRole, ['staff', 'admin', 'hod'], true)) {
        header("Location: " . buildRedirectUrl($staffRole, $folder));
        exit;
    }
    // Invalid session data — destroy and let them log in fresh
    session_destroy();
    session_start();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email     = trim($_POST['email']      ?? '');
    $password  =       $_POST['password']   ?? '';
    // 'login_role' comes from the tab the user selected: 'staff' or 'admin'
    $loginRole = trim($_POST['login_role'] ?? 'staff');

// Sanitise loginRole so only accepted values pass through
if (!in_array($loginRole, ['staff', 'admin', 'reports', 'auto'], true)) {
    $loginRole = 'staff';
}

    if (empty($email) || empty($password)) {
        $error = 'Please fill in all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        // TODO: Restore @unikl.edu.my domain check once SSO is live.
        // DUMMY MODE: any valid email (Gmail, etc.) is accepted for testing.

        // Fetch staff record — active accounts only
        $stmt = $conn->prepare(
    "SELECT * FROM staff
      WHERE email = ?
      LIMIT 1"
);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            // Generic message — don't reveal whether the email exists
            $error = 'Invalid email or password.';
        } else {
            $deptId    = !empty($user['dept_id']) ? (int)$user['dept_id'] : null;
            $staffRole = $user['role']; // actual role stored in DB: 'staff' or 'admin'

            // ── STRICT ROLE GATE ──────────────────────────────────────────
            // Admin tab  → only accounts with role = 'admin' may proceed.
            // Staff tab  → only accounts with role = 'staff' may proceed.
            // Cross-login is blocked with a clear error message.
            if ($loginRole === 'auto') {
    $loginRole = $staffRole; // trust the DB role entirely
}

if ($loginRole === 'reports' && !in_array($staffRole, ['super_admin', 'report_viewer'], true)) {
    $error = 'You do not have access to Reports.';
} elseif ($staffRole === 'super_admin') {
    // ── Super Admin success path ──────────────────────────────────
    session_regenerate_id(true);
    $_SESSION['staff_id']    = $user['staff_id'];
    $_SESSION['staff_code']  = $user['staff_code'];
    $_SESSION['staff_name']  = $user['full_name'];
    $_SESSION['staff_email'] = $user['email'];
    $_SESSION['staff_role']  = 'super_admin';
    $_SESSION['dept_id']     = null;
    $_SESSION['dept_name']   = 'Super Admin';
    $_SESSION['dept_folder'] = '';
    header("Location: super_admin/dashboard.php");
    exit;
} elseif ($staffRole === 'report_viewer') {
    session_regenerate_id(true);
    $_SESSION['staff_id']    = $user['staff_id'];
    $_SESSION['staff_code']  = $user['staff_code'];
    $_SESSION['staff_name']  = $user['full_name'];
    $_SESSION['staff_email'] = $user['email'];
    $_SESSION['staff_role']  = 'report_viewer';
    $_SESSION['dept_id']     = null;
    $_SESSION['dept_name']   = 'Report Viewer';
    $_SESSION['dept_folder'] = '';
    header("Location: super_admin/dashboard.php");
    exit;
    } elseif ($staffRole === 'hod') {
    if (empty($deptId) || !isset($deptFolders[$deptId])) {
        $error = 'Your account is not assigned to a valid department.';
    } else {
        session_regenerate_id(true);
        $_SESSION['staff_id']    = $user['staff_id'];
        $_SESSION['staff_code']  = $user['staff_code'];
        $_SESSION['staff_name']  = $user['full_name'];
        $_SESSION['staff_email'] = $user['email'];
        $_SESSION['staff_role']  = 'hod';
        $_SESSION['dept_id']     = $deptId;
        $_SESSION['dept_name']   = $deptNames[$deptId];
        $_SESSION['dept_folder'] = $deptFolders[$deptId];
        header("Location: dept_admin/" . $deptFolders[$deptId] . "/dashboard.php");
        exit;
    }
} elseif (empty($deptId) || !isset($deptFolders[$deptId])) {
    $error = 'Your account is not assigned to a valid department. Please contact the administrator.';
} else {
    // ── All checks passed — create authenticated session ──────
    session_regenerate_id(true);
    $_SESSION['staff_id']    = $user['staff_id'];
    $_SESSION['staff_code']  = $user['staff_code'];
    $_SESSION['staff_name']  = $user['full_name'];
    $_SESSION['staff_email'] = $user['email'];
    $_SESSION['staff_role']  = $staffRole;
    $_SESSION['dept_id']     = $deptId;
    $_SESSION['dept_name']   = $deptNames[$deptId];
    $_SESSION['dept_folder'] = $deptFolders[$deptId];

    header("Location: " . buildRedirectUrl($staffRole, $deptFolders[$deptId]));
    exit;
}
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Staff Portal | UniKL RCMP Help Desk</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --blue:        #003B8E;
      --blue-dark:   #002D6E;
      --blue-deeper: #001E4D;
      --blue-light:  #E6EEF9;
      --blue-mid:    #85B7EB;
      --gold:        #D4A017;
      --gold-light:  #FDF5DC;
      --white:       #FFFFFF;
      --off-white:   #F7F9FC;
      --gray-100:    #EEF1F7;
      --gray-200:    #DDE2EE;
      --gray-300:    #CDD3DF;
      --gray-500:    #7A8499;
      --gray-700:    #3D4560;
      --gray-900:    #1A2038;
      --text:        #111827;
      --error:       #C0392B;
      --error-bg:    #FDECEA;
      --admin-gold:  #B8860B;
      --admin-badge: #FDF3DC;
    }

    html { scroll-behavior: smooth; }
    body {
      font-family: 'DM Sans', sans-serif;
      color: var(--text);
      background: var(--off-white);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }

    /* ── NAV ─────────────────────────────────────────────────────────────── */
    nav {
      position: fixed; top: 0; left: 0; right: 0; z-index: 100;
      display: flex; align-items: center; justify-content: space-between;
      padding: 0 40px; height: 64px;
      background: rgba(255,255,255,0.92);
      backdrop-filter: blur(12px);
      border-bottom: 1px solid var(--gray-300);
    }
    .nav-brand { display: flex; align-items: center; gap: 12px; text-decoration: none; }
    .nav-logo {
      width: 42px; height: 42px; background: var(--blue); border-radius: 8px;
      display: flex; align-items: center; justify-content: center;
    }
    .nav-logo span { font-size: 13px; font-weight: 700; color: white; letter-spacing: -0.5px; }
    .nav-logo span em { color: #F5C44B; font-style: normal; }
    .nav-brand-text .top    { font-size: 11px; color: var(--gray-500); letter-spacing: 0.06em; text-transform: uppercase; }
    .nav-brand-text .bottom { font-size: 14px; font-weight: 600; color: var(--gray-900); line-height: 1.2; }
    .nav-right { display: flex; align-items: center; gap: 10px; }
    .btn-outline {
      font-size: 13px; font-weight: 500; padding: 8px 18px; border-radius: 6px;
      border: 1.5px solid var(--gray-300); background: transparent; color: var(--gray-700);
      cursor: pointer; text-decoration: none; transition: border-color .2s, color .2s; display: inline-block;
    }
    .btn-outline:hover { border-color: var(--blue); color: var(--blue); }
    .btn-primary {
      font-size: 13px; font-weight: 500; padding: 8px 18px; border-radius: 6px;
      border: none; background: var(--blue); color: white;
      cursor: pointer; text-decoration: none; transition: background .2s; display: inline-block;
    }
    .btn-primary:hover { background: var(--blue-dark); }

    /* ── LAYOUT ──────────────────────────────────────────────────────────── */
    .page-wrap {
      flex: 1; display: grid; grid-template-columns: 2fr 3fr;
      min-height: calc(100vh - 64px); margin-top: 64px;
    }

    /* ── LEFT PANEL ──────────────────────────────────────────────────────── */
    .left-panel {
      background: var(--gray-900);
      display: flex; flex-direction: column; justify-content: center; align-items: flex-start;
      padding: 80px 64px; position: relative; overflow: hidden;
    }
    .left-grid {
      position: absolute; inset: 0;
      background-image:
        linear-gradient(rgba(255,255,255,.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,.04) 1px, transparent 1px);
      background-size: 40px 40px;
    }
    .left-glow   { position: absolute; top:-120px; left:-80px; width:500px; height:500px; border-radius:50%; background:radial-gradient(circle,rgba(0,59,142,.35) 0%,transparent 70%); pointer-events:none; }
    .left-glow-2 { position: absolute; bottom:-100px; right:-60px; width:360px; height:360px; border-radius:50%; background:radial-gradient(circle,rgba(212,160,23,.12) 0%,transparent 70%); pointer-events:none; }
    .left-content { position: relative; z-index: 1; max-width: 400px; }

    .left-eyebrow {
      display: inline-flex; align-items: center; gap: 8px;
      background: rgba(0,59,142,.25); border: 1px solid rgba(133,183,235,.3);
      padding: 5px 12px; border-radius: 100px; font-size: 12px; font-weight: 500;
      color: var(--blue-mid); margin-bottom: 28px; letter-spacing: .03em;
    }
    .eyebrow-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--blue-mid); animation: pulse 2s infinite; }
    @keyframes pulse { 0%,100% { opacity:1; } 50% { opacity:.4; } }

    .left-title { font-family: 'DM Serif Display', serif; font-size: clamp(32px, 3.5vw, 48px); line-height: 1.1; color: white; margin-bottom: 18px; }
    .left-title em { font-style: italic; color: #F5C44B; }
    .left-desc { font-size: 15px; color: rgba(255,255,255,.5); line-height: 1.75; font-weight: 300; margin-bottom: 28px; }

    /* ── Role info box ───────────────────────────────────────────────────── */
    .role-info {
      display: flex; flex-direction: column; gap: 8px; margin-bottom: 24px;
    }
    .role-item {
      display: flex; align-items: center; gap: 10px; padding: 10px 14px; border-radius: 8px;
      background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.08);
      font-size: 12px;
    }
    .role-badge {
      font-size: 10px; font-weight: 700; letter-spacing: .06em; padding: 2px 9px;
      border-radius: 4px; text-transform: uppercase; flex-shrink: 0;
    }
    .role-badge.admin {
      background: rgba(212,160,23,.2); color: #F5C44B;
      border: 1px solid rgba(212,160,23,.3);
    }
    .role-badge.staff {
      background: rgba(0,59,142,.3); color: var(--blue-mid);
      border: 1px solid rgba(133,183,235,.3);
    }
    .role-desc { color: rgba(255,255,255,.6); font-weight: 400; }

    .dept-list { display: flex; flex-direction: column; gap: 6px; }
    .dept-list-label {
      font-size: 10px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase;
      color: rgba(255,255,255,.3); margin-bottom: 4px;
    }
    .dept-item {
      display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-radius: 7px;
      background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.06);
      font-size: 12px; color: rgba(255,255,255,.5); transition: background .2s;
    }
    .dept-item:hover { background: rgba(255,255,255,.07); }
    .dept-code {
      font-size: 10px; font-weight: 700; letter-spacing: .06em; padding: 2px 7px;
      border-radius: 4px; text-transform: uppercase; background: rgba(0,59,142,.3);
      color: var(--blue-mid); flex-shrink: 0; min-width: 48px; text-align: center;
    }
    .dept-name { color: rgba(255,255,255,.65); font-weight: 400; }

    /* ── RIGHT PANEL ─────────────────────────────────────────────────────── */
    .right-panel { display: flex; align-items: center; justify-content: center; padding: 60px 40px; background: var(--off-white); }
    .form-card { width: 100%; max-width: 420px; animation: slideUp .6s ease both; }
    @keyframes slideUp { from { opacity:0; transform:translateY(20px); } to { opacity:1; transform:translateY(0); } }

    .form-header { margin-bottom: 28px; }
    .form-title { font-family: 'DM Serif Display', serif; font-size: 32px; color: var(--gray-900); margin-bottom: 8px; line-height: 1.15; }
    .form-sub { font-size: 14px; color: var(--gray-500); font-weight: 300; line-height: 1.6; }
    .form-sub a { color: var(--blue); text-decoration: none; font-weight: 500; }
    .form-sub a:hover { text-decoration: underline; }

.role-radios {
  display: flex; gap: 10px; margin-bottom: 24px;
}
.role-radio-label { flex: 1; cursor: pointer; }
.role-radio-label input[type="radio"] { display: none; }
.role-radio-box {
  display: flex; align-items: center; justify-content: center; gap: 8px;
  padding: 10px 14px; border-radius: 8px; border: 2px solid var(--gray-200);
  background: white; font-size: 13px; font-weight: 500; color: var(--gray-500);
  transition: all .2s; user-select: none;
}
.role-radio-box svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; }
.role-radio-label input:checked + .role-radio-box {
  border-color: var(--blue); color: var(--blue); background: var(--blue-light);
}
body.admin-mode #radioLabelAdmin input:checked + .role-radio-box {
  border-color: var(--admin-gold); color: var(--admin-gold); background: var(--admin-badge);
}


    /* ── Admin mode visual cues ──────────────────────────────────────────── */
    .admin-mode-banner {
      display: none; align-items: center; gap: 8px; padding: 10px 14px; border-radius: 8px;
      background: var(--admin-badge); border: 1px solid rgba(184,134,11,.25);
      font-size: 12px; font-weight: 500; color: var(--admin-gold); margin-bottom: 20px;
    }
    .admin-mode-banner svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; flex-shrink: 0; }
    body.admin-mode .admin-mode-banner { display: flex; }
    body.admin-mode .btn-submit { background: var(--gray-900); }
    body.admin-mode .btn-submit:hover { background: #111; }
    body.admin-mode .field input:focus {
      border-color: var(--admin-gold);
      box-shadow: 0 0 0 3px rgba(184,134,11,.08);
    }

    /* ── Super Admin mode visual cues ────────────────────────────────────── */


    /* ── Alerts ──────────────────────────────────────────────────────────── */
    .alert { display: flex; align-items: flex-start; gap: 10px; padding: 12px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 24px; animation: fadeIn .3s ease; }
    .alert-error { background: var(--error-bg); color: var(--error); border: 1px solid rgba(192,57,43,.2); }
    .alert-success { background: #ECFDF5; color: #065F46; border: 1px solid rgba(6,95,70,.2); }
    .alert svg { width:16px; height:16px; flex-shrink:0; margin-top:1px; fill:none; stroke:currentColor; stroke-width:2; }
    @keyframes fadeIn { from { opacity:0; } to { opacity:1; } }

    /* ── Fields ──────────────────────────────────────────────────────────── */
    .field { margin-bottom: 18px; }
    .field label { display: block; font-size: 13px; font-weight: 500; color: var(--gray-700); margin-bottom: 7px; }

    .input-wrap { position: relative; display: flex; align-items: center; }
    .input-icon {
      position: absolute; left: 13px; top: 50%; transform: translateY(-50%);
      width: 16px; height: 16px; pointer-events: none;
      display: flex; align-items: center; justify-content: center; z-index: 2;
    }
    .input-icon svg { width: 16px; height: 16px; fill: none; stroke: var(--gray-500); stroke-width: 1.8; display: block; }

    .field input[type="email"],
    .field input[type="password"],
    .field input[type="text"] {
      width: 100%;
      padding: 11px 44px 11px 40px !important;
      border: 1.5px solid var(--gray-300); border-radius: 8px;
      font-family: 'DM Sans', sans-serif; font-size: 14px; color: var(--text);
      background: white; transition: border-color .2s, box-shadow .2s; outline: none;
      -ms-reveal: none;
    }
    .field input[type="password"]::-ms-reveal,
    .field input[type="password"]::-ms-clear { display: none; }
    .field input:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(0,59,142,.08); }
    .field input::placeholder { color: var(--gray-300); }

    .pw-toggle {
      position: absolute; right: 13px; top: 50%; transform: translateY(-50%);
      background: none; border: none; cursor: pointer; padding: 2px; color: var(--gray-500);
      display: flex; align-items: center; justify-content: center; z-index: 2;
    }
    .pw-toggle svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 1.8; display: block; }
    .pw-toggle:hover { color: var(--gray-700); }

    input[name="login_role"] { display: none; }

    .form-row { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
    .checkbox-label { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--gray-700); cursor: pointer; }
    .checkbox-label input[type="checkbox"] { width: 15px; height: 15px; accent-color: var(--blue); cursor: pointer; }
    .forgot-link { font-size: 13px; color: var(--blue); text-decoration: none; font-weight: 500; }
    .forgot-link:hover { text-decoration: underline; }

    .btn-submit {
      width: 100%; padding: 13px; background: var(--blue); color: white; border: none; border-radius: 8px;
      font-family: 'DM Sans', sans-serif; font-size: 15px; font-weight: 500; cursor: pointer;
      transition: background .2s, transform .15s; display: flex; align-items: center; justify-content: center; gap: 8px;
    }
    .btn-submit:hover  { background: var(--blue-dark); transform: translateY(-1px); }
    .btn-submit:active { transform: translateY(0); }
    .btn-submit svg { width: 16px; height: 16px; fill: none; stroke: white; stroke-width: 2; }

    .student-prompt { text-align: center; font-size: 13px; color: var(--gray-500); margin-top: 20px; }
    .student-prompt a { color: var(--blue); font-weight: 500; text-decoration: none; }
    .student-prompt a:hover { text-decoration: underline; }

    @media (max-width: 860px) {
      .page-wrap { grid-template-columns: 1fr; }
      .left-panel { display: none; }
      nav { padding: 0 20px; }
      .right-panel { padding: 40px 24px; }
    }
  </style>
</head>
<body>

<nav>
  <a class="nav-brand" href="index.php">
    <div class="nav-logo"><span>Uni<em>KL</em></span></div>
    <div class="nav-brand-text">
      <div class="top">UniKL Royal College of Medicine Perak</div>
      <div class="bottom">Help Desk</div>
    </div>
  </a>
  <div class="nav-right">
    <a href="index.php"  class="btn-outline">← Back to Home</a>
    <a href="login.php"  class="btn-primary">User Portal</a>
  </div>
</nav>

<div class="page-wrap">

  <!-- ── LEFT PANEL ─────────────────────────────────────────────────────── -->
  <div class="left-panel">
    <div class="left-grid"></div>
    <div class="left-glow"></div>
    <div class="left-glow-2"></div>
    <div class="left-content">

      <div class="left-eyebrow"><span class="eyebrow-dot"></span>Staff &amp; Admin Portal</div>
      <h2 class="left-title">Staff<br>Department<br><em>Dashboard</em></h2>
      <p class="left-desc">Sign in with your UniKL credentials. Staff manage tickets; Admins oversee full department operations.</p>

      <div class="role-info">
        <div class="role-item">
          <span class="role-badge staff">Staff</span>
          <span class="role-desc">View &amp; manage assigned tickets → <code style="font-size:10px;opacity:.7;">dept/</code></span>
        </div>
        <div class="role-item">
          <span class="role-badge admin">Admin</span>
          <span class="role-desc">Full oversight &amp; reports → <code style="font-size:10px;opacity:.7;">dept_admin/</code></span>
        </div>
      </div>

      <div class="dept-list">
        <div class="dept-list-label">Departments</div>
        <div class="dept-item"><span class="dept-code">AFSMD</span><span class="dept-name">Administration &amp; Facilities</span></div>
        <div class="dept-item"><span class="dept-code">MAINT</span><span class="dept-name">Maintenance Department</span></div>
        <div class="dept-item"><span class="dept-code">CCU</span>  <span class="dept-name">Corporate Communication Unit</span></div>
        <div class="dept-item"><span class="dept-code">IT</span>   <span class="dept-name">Information Technology</span></div>
        <div class="dept-item"><span class="dept-code">HCD</span>  <span class="dept-name">Human Capital Department</span></div>
      </div>

    </div>
  </div>

  <!-- ── RIGHT PANEL ────────────────────────────────────────────────────── -->
  <div class="right-panel">
    <div class="form-card">

      <div class="form-header">
        <h1 class="form-title" id="formTitle">Staff Sign In</h1>
        <p class="form-sub">Submitting a request? <a href="login.php">Use the user portal →</a></p>
      </div>

      

     



      <!-- Error message -->
      <?php if ($error): ?>
      <div class="alert alert-error">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?php echo htmlspecialchars($error); ?>
      </div>
      <?php endif; ?>

      <form method="POST" action="staff_login.php" novalidate>

        <!-- Hidden: tracks which tab (staff/admin) was active when submitted -->
        <input type="hidden" name="login_role" id="loginRoleField" value="auto"/>

        <div class="field">
          <label for="email">Staff Email</label>
          <div class="input-wrap">
            <div class="input-icon">
              <svg viewBox="0 0 24 24">
                <rect x="2" y="4" width="20" height="16" rx="2"/>
                <polyline points="2,4 12,13 22,4"/>
              </svg>
            </div>
            <input type="email" id="email" name="email"
              placeholder="yourname@unikl.edu.my"
              value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
              required autocomplete="email"/>
          </div>
        </div>

        <div class="field">
          <label for="password">Password</label>
          <div class="input-wrap">
            <div class="input-icon">
              <svg viewBox="0 0 24 24">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
              </svg>
            </div>
            <input type="password" id="password" name="password"
              placeholder="Enter your password"
              required autocomplete="current-password"/>
            <button type="button" class="pw-toggle" id="pwToggle" aria-label="Toggle password visibility">
              <svg id="eyeIcon" viewBox="0 0 24 24">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                <circle cx="12" cy="12" r="3"/>
              </svg>
            </button>
          </div>
        </div>

        <div class="form-row">
          <label class="checkbox-label">
            <input type="checkbox" name="remember" <?php echo isset($_POST['remember']) ? 'checked' : ''; ?>/>
            Remember me
          </label>
          <a href="forgot-password.php" class="forgot-link">Forgot password?</a>
        </div>

        <button type="submit" class="btn-submit" id="submitBtn">
          <span id="submitLabel">Sign In</span>
          <svg viewBox="0 0 24 24"><path d="M5 12h14"/><path d="M12 5l7 7-7 7"/></svg>
        </button>

      </form>

      <div class="student-prompt">Not a staff member? <a href="login.php">Go to user portal</a></div>

    </div>
  </div>

</div>

<script>
  // ── Password toggle ────────────────────────────────────────────────────
  const pwToggle = document.getElementById('pwToggle');
  const pwInput  = document.getElementById('password');
  const eyeIcon  = document.getElementById('eyeIcon');

  const eyeOpen   = `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>`;
  const eyeClosed = `<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19M1 1l22 22"/>`;

  pwToggle.addEventListener('click', () => {
    const isHidden    = pwInput.type === 'password';
    pwInput.type      = isHidden ? 'text' : 'password';
    eyeIcon.innerHTML = isHidden ? eyeClosed : eyeOpen;
    pwInput.focus();
  });


</script>

</body>
</html>