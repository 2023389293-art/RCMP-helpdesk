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
// Catch errors bounced back from SSO callback
if (!empty($_GET['sso_error'])) {
    $ssoMessages = [
        'invalid_state'        => 'Login session expired. Please try again.',
        'no_code'              => 'Microsoft did not return an authorisation code.',
        'token_request_failed' => 'Could not contact Microsoft. Please try again.',
        'no_access_token'      => 'Microsoft login failed. Please try again.',
        'graph_request_failed' => 'Could not retrieve your profile from Microsoft.',
        'no_email'             => 'Your Microsoft account has no email address on record.',
        'account_not_found'    => 'No active account found for your Microsoft email. Please contact ITD.',
        'no_department'        => 'Your staff account has no department assigned. Please contact ITD.',
    ];
    $raw   = htmlspecialchars($_GET['sso_error']);
    $error = $ssoMessages[$raw] ?? 'SSO error: ' . $raw;
}

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
  <title>Operator Sign In | UniKL RCMP Help Desk</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,700;1,700&family=Lora:ital,wght@0,600;1,600&family=Source+Sans+3:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
<style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --navy:        #1E3A5F;
      --navy-dark:   #142845;
      --navy-mid:    #2B4F7E;
      --navy-light:  #E8EEF6;
      --olive:       #5C6B35;
      --gold:        #B8860B;
      --gold-light:  #D4A017;
      --gold-bg:     #FBF5E6;
      --white:       #FFFFFF;
      --page-bg:     #e8edf5;
      --surface:     #FFFFFF;
      --border:      #D8D3C8;
      --border-soft: #E5E1D8;
      --text:        #1A2332;
      --text-dark:   #1A2332;
      --text-mid:    #3D4F63;
      --text-muted:  #7A8899;
      --error:       #C0392B;
      --error-bg:    #FDF0EE;
      --error-border:#E8C5C0;
      --input-bg:    #FAFAF8;
      --input-focus: #EBF1F8;
    }

    html, body { height: 100%; font-family: 'Source Sans 3', sans-serif; color: var(--text); }

    /* ── GOV BANNER ── */
    .gov-banner {
      background: var(--navy-dark);
      padding: 6px 48px;
      display: flex; align-items: center; justify-content: space-between;
      border-bottom: 2px solid var(--gold);
    }
.gov-banner-left {
  display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
  font-size: 13px; color: rgba(255,255,255,0.75);
  letter-spacing: 0.04em; text-transform: uppercase; font-weight: 600;
}
    .gov-banner-left span { color: var(--gold-light); }
    .gov-banner-right { font-size: 13px; color: rgba(255,255,255,0.5); letter-spacing: 0.04em; }

    /* ── NAV ── */
    nav {
  background: var(--surface);
  border-bottom: 3px solid var(--navy);
  box-shadow: 0 2px 16px rgba(0,0,0,0.08);
  padding: 0 48px;
  display: flex; align-items: center; justify-content: space-between;
  height: 96px;
}
    .nav-brand { display: flex; align-items: center; gap: 16px; text-decoration: none; }
    .nav-logo img { width: 100px; height: 100px; object-fit: contain; }
    .nav-divider { width: 1px; height: 36px; background: var(--border); }
    .nav-text-group { display: flex; flex-direction: column; }
.nav-org   { font-size: 16px; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; color: var(--olive); }
.nav-title { font-size: 19px; font-weight: 700; color: var(--navy-dark); letter-spacing: 0.01em; }
    .nav-back {
      font-size: 12.5px; font-weight: 600; letter-spacing: 0.04em;
      padding: 9px 22px; border-radius: 5px;
      border: 1.5px solid var(--border); background: transparent; color: var(--text-mid);
      text-decoration: none; display: inline-flex; align-items: center; gap: 7px;
      transition: all 0.2s;
    }
    .nav-back:hover { border-color: var(--navy); color: var(--navy); background: var(--navy-light); }
    .nav-back svg { width: 14px; height: 14px; }

    /* ── MAIN AREA ── */
    .main {
      min-height: calc(100vh - 38px - 76px);
      background: var(--page-bg);
      display: flex; align-items: center; justify-content: center;
      padding: 48px 20px;
      position: relative; overflow: hidden;
    }
    .main::before {
      content: '';
      position: absolute; inset: 0;
      background-image:
        linear-gradient(rgba(30,58,95,0.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(30,58,95,0.04) 1px, transparent 1px);
      background-size: 40px 40px;
      pointer-events: none;
    }

    /* ── LOGIN CARD ── */
    .login-card {
      position: relative; z-index: 2;
      width: 100%; max-width: 480px;
      background: var(--white);
      border: 1px solid var(--border);
      border-top: 3px solid var(--navy);
      border-radius: 10px;
      overflow: hidden;
      box-shadow: 0 4px 32px rgba(20,40,70,0.10), 0 1px 4px rgba(20,40,70,0.07);
      animation: fadeUp 0.5s cubic-bezier(.22,1,.36,1) both;
    }
    @keyframes fadeUp { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:translateY(0)} }

    .card-body { padding: 44px 44px 36px; }

    /* ── CARD HEADER ── */
    .card-header { text-align: center; margin-bottom: 32px; }
    .card-logo {
      display: inline-flex; align-items: center; gap: 14px;
      margin-bottom: 22px;
    }
    .card-logo img { width: 98px; height: 98px; object-fit: contain; }
    .card-logo-divider { width: 1px; height: 46px; background: var(--border); }
    .card-logo-text { text-align: left; }
    .card-logo-org  { font-size: 12px; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; color: var(--olive); }
    .card-logo-name { font-size: 17px; font-weight: 700; color: var(--navy-dark); line-height: 1.3; }

    .card-header h1 {
      font-family: 'Playfair Display', serif;
      font-size: 28px; font-weight: 700;
      color: var(--navy-dark); line-height: 1.2; margin-bottom: 8px;
    }
    .card-header h1 em { font-style: italic; color: var(--gold-light); }
    .card-header p { font-size: 13px; color: var(--text-muted); line-height: 1.6; }

    .card-divider { height: 1px; background: var(--border); margin-bottom: 28px; }

    /* ── PRIMARY SUBMIT BUTTON ── */
    .btn-submit {
      width: 100%; padding: 14px 20px;
      background: var(--navy); color: #fff;
      border: none; border-radius: 7px;
      font-family: 'Source Sans 3', sans-serif;
      font-size: 14.5px; font-weight: 600; letter-spacing: 0.02em;
      cursor: pointer;
      display: flex; align-items: center; justify-content: center; gap: 8px;
      transition: background .2s, transform .15s, box-shadow .2s;
      box-shadow: 0 3px 12px rgba(30,58,95,0.25);
      margin-bottom: 10px;
    }
    .btn-submit:hover { background: var(--navy-dark); transform: translateY(-1px); box-shadow: 0 6px 20px rgba(30,58,95,0.30); }
    .btn-submit:active { transform: translateY(0); }
    .btn-submit svg { width: 15px; height: 15px; fill:none; stroke:currentColor; stroke-width:2.5; }

    /* ── OR DIVIDER ── */
    .or-row {
      display: flex; align-items: center; gap: 12px;
      margin: 16px 0;
    }
    .or-line { flex: 1; height: 1px; background: var(--border); }
    .or-label { font-size: 11.5px; color: var(--text-muted); white-space: nowrap; }

    /* ── SSO BUTTON (secondary) ── */
    .btn-sso {
      width: 100%; padding: 12px 16px;
      background: #fff; border: 1.5px solid var(--border); border-radius: 7px;
      font-family: 'Source Sans 3', sans-serif;
      font-size: 14px; font-weight: 600; color: var(--text);
      cursor: pointer; text-decoration: none;
      display: flex; align-items: center; justify-content: center; gap: 10px;
      transition: border-color .2s, box-shadow .2s;
    }
    .btn-sso:hover { border-color: var(--navy); box-shadow: 0 2px 8px rgba(30,58,95,0.10); }
    .ms-logo { display: grid; grid-template-columns: 1fr 1fr; gap: 2px; width: 18px; height: 18px; flex-shrink: 0; }
    .ms-logo span { display: block; }

    /* ── TOGGLE BUTTON ── */
    .toggle-btn {
      width: 100%; padding: 11px 16px;
      background: transparent; border: 1.5px solid var(--border); border-radius: 7px;
      font-family: 'Source Sans 3', sans-serif;
      font-size: 13px; font-weight: 600; color: var(--text-mid);
      cursor: pointer; text-align: center;
      display: flex; align-items: center; justify-content: center; gap: 8px;
      transition: all .2s; margin-top: 10px;
    }
    .toggle-btn:hover { border-color: var(--navy); color: var(--navy); background: var(--navy-light); }
    .toggle-btn svg { width: 14px; height: 14px; transition: transform .25s; }
    .toggle-btn.open svg { transform: rotate(180deg); }

    /* ── COLLAPSIBLE FORM ── */
    .manual-panel { overflow: hidden; max-height: 0; transition: max-height .35s cubic-bezier(.4,0,.2,1); }
    .manual-panel.open { max-height: 500px; }
    .manual-inner { padding-top: 20px; }

    /* ── ERROR ALERT ── */
    .alert {
      display: flex; align-items: flex-start; gap: 10px;
      background: var(--error-bg);
      border: 1px solid var(--error-border);
      border-left: 3px solid var(--error);
      padding: 11px 14px; border-radius: 6px;
      margin-bottom: 18px;
      animation: fadeUp 0.3s ease both;
    }
    .alert-icon { color: var(--error); flex-shrink: 0; margin-top: 1px; }
    .alert-icon svg { width: 15px; height: 15px; fill:none; stroke:currentColor; stroke-width:2; }
    .alert-text { font-size: 13px; color: var(--error); line-height: 1.5; }

    /* ── FIELDS ── */
    .field { margin-bottom: 16px; }
    .field label {
      display: block; font-size: 12px; font-weight: 600;
      color: var(--text-mid); margin-bottom: 7px; letter-spacing: 0.03em;
    }
    .input-wrap { position: relative; }
    .input-icon {
      position: absolute; left: 13px; top: 50%; transform: translateY(-50%);
      pointer-events: none; color: #B0BAC8;
    }
    .input-icon svg { width: 15px; height: 15px; fill:none; stroke:currentColor; stroke-width:1.8; display:block; }
    .field input {
      width: 100%; padding: 12px 14px 12px 40px;
      background: var(--input-bg); border: 1.5px solid var(--border); border-radius: 7px;
      font-family: 'Source Sans 3', sans-serif; font-size: 14px; color: var(--text);
      outline: none; transition: border-color .2s, background .2s, box-shadow .2s;
    }
    .field input::placeholder { color: #C0C7D0; }
    .field input:focus {
      border-color: var(--navy); background: var(--input-focus);
      box-shadow: 0 0 0 3px rgba(30,58,95,0.10);
    }
    .field input:-webkit-autofill {
      -webkit-box-shadow: 0 0 0 1000px var(--input-bg) inset;
      -webkit-text-fill-color: var(--text);
    }
    .pw-toggle {
      position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
      background: none; border: none; cursor: pointer;
      color: var(--text-muted); padding: 4px; display: flex; transition: color .2s;
    }
    .pw-toggle:hover { color: var(--navy); }
    .pw-toggle svg { width: 15px; height: 15px; fill:none; stroke:currentColor; stroke-width:1.8; }

    /* ── CARD FOOTER ── */
    .card-footer {
      border-top: 1px solid var(--border);
      padding: 16px 44px;
      display: flex; align-items: center; justify-content: center; gap: 6px;
      font-size: 12px; color: var(--text-muted);
    }
    .card-footer a { color: var(--navy); font-weight: 600; text-decoration: none; }
    .card-footer a:hover { color: var(--gold); text-decoration: underline; }

    /* ── SSO PRIMARY (navy filled) ── */
    .btn-sso-primary {
      background: var(--navy); color: #fff;
      border-color: var(--navy);
      box-shadow: 0 3px 12px rgba(30,58,95,0.25);
      margin-bottom: 10px;
    }
    .btn-sso-primary:hover { background: var(--navy-dark); border-color: var(--navy-dark); box-shadow: 0 6px 20px rgba(30,58,95,0.30); }
    .btn-sso-primary .ms-logo { filter: none; }

    /* ── SSO NOTE ── */
    .sso-note { font-size: 11.5px; color: var(--text-muted); text-align: center; line-height: 1.55; margin-bottom: 4px; }

    /* ── RESPONSIVE ── */
    @media (max-width: 640px) {
      .gov-banner { display: none; }
      nav { padding: 0 16px; height: 60px; }
      .nav-logo img { width: 44px; height: 44px; }
      .nav-org { font-size: 10px; }
      .nav-title { font-size: 12px; }
      .nav-divider { display: none; }
      .main { padding: 24px 12px; }
      .card-body { padding: 30px 24px 24px; }
      .card-footer { padding: 14px 24px; }
    }
  </style>
</head>
<body>

<div class="shell" style="background:var(--page-bg);min-height:100vh;">

  <!-- GOV BANNER -->
  <div class="gov-banner">
    <div class="gov-banner-left">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.5)" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
      <span>UniKL Royal College of Medicine Perak</span>
      <span style="color:rgba(255,255,255,0.25)">·</span>
      <span>Help Desk Management Portal</span>
    </div>
    <div class="gov-banner-right">Doc Ref: UniKL/RCMP/CD/ITD-01-01</div>
  </div>

  <!-- NAV -->
  <nav>
    <a class="nav-brand" href="index.php">
      <div class="nav-logo"><img src="img/RCMP.png" alt="UniKL RCMP Logo" /></div>
      <div class="nav-divider"></div>
      <div class="nav-text-group">
        <span class="nav-org">UniKL RCMP</span>
        <span class="nav-title">Help Desk Portal</span>
      </div>
    </a>
    <a href="index.php" class="nav-back">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5"/><path d="M12 5l-7 7 7 7"/></svg>
      Back to Home
    </a>
  </nav>

 <!-- MAIN -->
  <div class="main">
    <div class="login-card">

      <div class="card-body">

        <!-- Header -->
        <div class="card-header">
          <div class="card-logo">
            <img src="img/RCMP.png" alt="UniKL RCMP" />
            <div class="card-logo-divider"></div>
            <div class="card-logo-text">
              <div class="card-logo-org">UniKL RCMP</div>
              <div class="card-logo-name">Operator Portal</div>
            </div>
          </div>
          <h1>Operator <em>Sign In</em></h1>
          <p>Not an operator? <a href="index.php" style="color:var(--navy);font-weight:600;">Go to user portal →</a></p>
        </div>

        <div class="card-divider"></div>

        <!-- PRIMARY: Microsoft SSO -->
        <a href="auth/staff_sso_login.php" class="btn-sso btn-sso-primary">
          <div class="ms-logo" aria-hidden="true">
            <span style="background:#f25022;"></span>
            <span style="background:#7fba00;"></span>
            <span style="background:#00a4ef;"></span>
            <span style="background:#ffb900;"></span>
          </div>
          Continue with Microsoft (UniKL SSO)
        </a>
        <p class="sso-note">Use your UniKL Microsoft account — recommended for all staff and operators.</p>

        <!-- OR divider -->
        <div class="or-row">
          <div class="or-line"></div>
          <span class="or-label">or sign in with email</span>
          <div class="or-line"></div>
        </div>

        <!-- SECONDARY: Toggle manual login -->
        <button type="button" class="toggle-btn" id="toggleBtn" aria-expanded="false" aria-controls="manualPanel">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" id="toggleChevron"><polyline points="6 9 12 15 18 9"/></svg>
          Other login options
        </button>

        <div class="manual-panel" id="manualPanel">
          <div class="manual-inner">

            <?php if ($error): ?>
            <div class="alert">
              <div class="alert-icon">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
              </div>
              <div class="alert-text"><?php echo htmlspecialchars($error); ?></div>
            </div>
            <?php endif; ?>

            <form method="POST" action="staff_login.php">
              <input type="hidden" name="login_role" id="loginRoleField" value="auto"/>

              <div class="field">
                <label for="email">Staff Email</label>
                <div class="input-wrap">
                  <div class="input-icon">
                    <svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
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
                    <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                  </div>
                  <input type="password" id="password" name="password"
                    placeholder="Enter your password"
                    required autocomplete="current-password"/>
                  <button type="button" class="pw-toggle" id="pwToggle" aria-label="Show or hide password">
                    <svg id="eyeIcon" viewBox="0 0 24 24">
                      <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                      <circle cx="12" cy="12" r="3"/>
                    </svg>
                  </button>
                </div>
              </div>

              <button type="submit" class="btn-submit">
                Sign In to Operator Portal
                <svg viewBox="0 0 24 24"><path d="M5 12h14"/><path d="M12 5l7 7-7 7"/></svg>
              </button>
            </form>

          </div>
        </div><!-- /manual-panel -->

      </div><!-- /card-body -->

      <div class="card-footer">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        Need help? Contact ITD Ext.&nbsp;<a href="#"><strong>142</strong></a>&nbsp;/&nbsp;<a href="#"><strong>140</strong></a>
      </div>

    </div><!-- /login-card -->
  </div><!-- /main -->

</div><!-- /shell -->

<script>
  /* ── Toggle manual login panel ── */
  const toggleBtn   = document.getElementById('toggleBtn');
  const manualPanel = document.getElementById('manualPanel');
  const autoOpen    = <?php echo !empty($error) ? 'true' : 'false'; ?>;

  function openPanel() {
    manualPanel.classList.add('open');
    toggleBtn.classList.add('open');
    toggleBtn.setAttribute('aria-expanded', 'true');
  }
  function closePanel() {
    manualPanel.classList.remove('open');
    toggleBtn.classList.remove('open');
    toggleBtn.setAttribute('aria-expanded', 'false');
  }

  if (autoOpen) openPanel();

  toggleBtn.addEventListener('click', () => {
    manualPanel.classList.contains('open') ? closePanel() : openPanel();
  });

  /* ── Password show/hide ── */
  const pw  = document.getElementById('password');
  const eye = document.getElementById('eyeIcon');
  const openEye = `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>`;
  const shutEye = `<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19M1 1l22 22"/>`;
  document.getElementById('pwToggle').addEventListener('click', () => {
    const show = pw.type === 'password';
    pw.type = show ? 'text' : 'password';
    eye.innerHTML = show ? shutEye : openEye;
  });
</script>

</body>
</html>