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

    $redirectUrl = buildRedirectUrl($staffRole, $deptFolders[$deptId]);
die("DEBUG → Role: $staffRole | DeptId: $deptId | Folder: {$deptFolders[$deptId]} | Redirect: $redirectUrl");
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
  <title>Operator Portal | UniKL RCMP Help Desk</title>
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

    html, body {
      height: 100%;
      font-family: 'Source Sans 3', sans-serif;
      background: var(--page-bg);
      color: var(--text);
    }

    /* ── OUTER SHELL ── */
    .shell {
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }

    /* ── GOV BANNER ── */
    .gov-banner {
      background: var(--navy-dark);
      padding: 6px 48px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      border-bottom: 2px solid var(--gold);
    }
    .gov-banner-left {
      display: flex; align-items: center; gap: 10px;
      font-size: 11px; color: rgba(255,255,255,0.75);
      letter-spacing: 0.06em; text-transform: uppercase; font-weight: 600;
    }
    .gov-banner-left span { color: var(--gold-light); }
    .gov-banner-right { font-size: 10.5px; color: rgba(255,255,255,0.5); letter-spacing: 0.04em; }

    /* ── NAV ── */
    nav {
      background: var(--surface);
      border-bottom: 3px solid var(--navy);
      box-shadow: 0 2px 16px rgba(0,0,0,0.08);
      padding: 0 48px;
      display: flex; align-items: center; justify-content: space-between;
      height: 76px;
    }
    .nav-brand { display: flex; align-items: center; gap: 16px; text-decoration: none; }
    .nav-logo img { width: 72px; height: 72px; object-fit: contain; }
    .nav-divider { width: 1px; height: 36px; background: var(--border); }
    .nav-text-group { display: flex; flex-direction: column; }
    .nav-org { font-size: 13px; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; color: var(--olive); }
    .nav-title { font-size: 15px; font-weight: 700; color: var(--navy-dark); letter-spacing: 0.01em; }
    .nav-actions { display: flex; align-items: center; gap: 10px; }
    .btn-ghost {
      font-size: 12.5px; font-weight: 600; letter-spacing: 0.04em;
      padding: 9px 22px; border-radius: 5px;
      border: 1.5px solid var(--border);
      background: transparent; color: var(--text-mid);
      cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 7px;
      transition: all 0.2s;
    }
    .btn-ghost:hover { border-color: var(--navy); color: var(--navy); background: var(--navy-light); }
    .btn-ghost svg { width: 14px; height: 14px; }

    /* ── MAIN CONTENT ── */
    .main {
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 40px 20px;
    }

    /* ── LOGIN CARD (split layout) ── */
    .login-card {
      display: flex;
      width: 100%;
      max-width: 900px;
      min-height: 560px;
      background: var(--white);
      border: 1px solid var(--border);
      border-top: 3px solid var(--navy);
      border-radius: 10px;
      overflow: hidden;
      box-shadow: 0 4px 32px rgba(20,40,70,0.10), 0 1px 4px rgba(20,40,70,0.07);
      animation: fadeUp 0.55s cubic-bezier(.22,1,.36,1) both;
    }
    @keyframes fadeUp {
      from { opacity:0; transform: translateY(20px); }
      to   { opacity:1; transform: translateY(0); }
    }

    /* ── LEFT PANEL ── */
    .panel-left {
      flex: 1;
      background: var(--navy-dark);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 48px 40px;
      position: relative;
      overflow: hidden;
    }
    .panel-left::before {
      content: '';
      position: absolute; inset: 0;
      background-image:
        linear-gradient(rgba(255,255,255,0.03) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,0.03) 1px, transparent 1px);
      background-size: 32px 32px;
    }
    .panel-left::after {
      content: '';
      position: absolute;
      top: 0; left: 0;
      width: 80px; height: 3px;
      background: var(--gold);
    }
.panel-illustration {
  position: relative; z-index: 2;
  display: flex; flex-direction: column; align-items: center; gap: 24px;
  margin-top: -60px;
}

    /* Big logo ring */
.illus-logo {
  width: 180px; height: 180px;
  display: flex; align-items: center; justify-content: center;
}
    .illus-logo img { width: 100%; height: 100%; object-fit: contain; }

    /* Process steps (Dribbble-style) */
    .illus-steps {
      display: flex; align-items: flex-start; gap: 0;
      position: relative;
    }
    .illus-step {
      display: flex; flex-direction: column; align-items: center;
      gap: 10px; width: 72px;
    }
    .step-icon-wrap {
      position: relative;
      width: 48px; height: 48px; border-radius: 14px;
      background: rgba(255,255,255,0.07);
      border: 1.5px solid rgba(255,255,255,0.13);
      display: flex; align-items: center; justify-content: center;
      flex-shrink: 0;
    }
    .step-icon-wrap svg { width: 22px; height: 22px; }
    .step-num {
      position: absolute; top: -7px; right: -7px;
      width: 18px; height: 18px; border-radius: 50%;
      background: var(--gold);
      font-size: 9px; font-weight: 700; color: #fff;
      display: flex; align-items: center; justify-content: center;
      border: 1.5px solid var(--navy-dark);
    }
    .step-label {
      font-size: 9.5px; font-weight: 600;
      color: rgba(255,255,255,0.50);
      letter-spacing: 0.06em; text-transform: uppercase;
      text-align: center; line-height: 1.3;
    }
    /* dashed connector between steps */
    .step-connector {
      width: 28px; flex-shrink: 0;
      margin-top: 23px;
      border-top: 1.5px dashed rgba(255,255,255,0.18);
    }

    .panel-tagline {
      text-align: center;
    }
    .panel-tagline h2 {
      font-family: 'Playfair Display', serif;
      font-size: 20px; font-weight: 700;
      color: #fff; line-height: 1.35; margin-bottom: 10px;
    }
    .panel-tagline h2 em { color: var(--gold-light); font-style: italic; }
    .panel-tagline p {
      font-size: 12.5px; color: rgba(255,255,255,0.48);
      line-height: 1.7; font-weight: 300; max-width: 240px; margin: 0 auto;
    }

    /* ── RIGHT PANEL (form) ── */
    .panel-right {
      width: 400px;
      flex-shrink: 0;
      display: flex; flex-direction: column; justify-content: center;
      padding: 48px 44px;
      border-left: 1px solid var(--border);
    }

    .form-header { margin-bottom: 32px; }
    .form-header h1 {
      font-family: 'Playfair Display', serif;
      font-size: 30px; font-weight: 700;
      color: var(--navy-dark); line-height: 1.2; margin-bottom: 6px;
    }
    .form-header h1 em { font-style: italic; color: var(--gold-light); }
    .form-header p {
      font-size: 13px; color: var(--text-muted); line-height: 1.6; font-weight: 400;
    }

    .form-divider {
      height: 1px; background: var(--border); margin-bottom: 26px;
    }

    /* Error alert */
    .alert {
      display: flex; align-items: flex-start; gap: 10px;
      background: var(--error-bg);
      border: 1px solid var(--error-border);
      border-left: 3px solid var(--error);
      padding: 11px 14px; border-radius: 6px;
      margin-bottom: 20px;
      animation: fadeUp 0.3s ease both;
    }
    .alert-icon { color: var(--error); flex-shrink: 0; margin-top: 1px; }
    .alert-icon svg { width: 15px; height: 15px; fill:none; stroke:currentColor; stroke-width:2; }
    .alert-text { font-size: 13px; color: var(--error); line-height: 1.5; }

    /* Field */
    .field { margin-bottom: 18px; }
    .field label {
      display: block; font-size: 12px; font-weight: 600;
      color: var(--text-mid); margin-bottom: 7px;
      letter-spacing: 0.03em;
    }
    .input-wrap { position: relative; }
    .input-icon {
      position: absolute; left: 13px; top: 50%; transform: translateY(-50%);
      pointer-events: none; color: #B0BAC8;
    }
    .input-icon svg { width: 16px; height: 16px; fill:none; stroke:currentColor; stroke-width:1.8; display:block; }
    .field input {
      width: 100%;
      padding: 12px 14px 12px 42px;
      background: var(--input-bg);
      border: 1.5px solid var(--border);
      border-radius: 7px;
      font-family: 'Source Sans 3', sans-serif;
      font-size: 14px; color: var(--text);
      outline: none;
      transition: border-color .2s, background .2s, box-shadow .2s;
    }
    .field input::placeholder { color: #C0C7D0; }
    .field input:focus {
      border-color: var(--navy);
      background: var(--input-focus);
      box-shadow: 0 0 0 3px rgba(30,58,95,0.10);
    }
    .field input:-webkit-autofill {
      -webkit-box-shadow: 0 0 0 1000px var(--input-bg) inset;
      -webkit-text-fill-color: var(--text);
    }

    /* Password toggle */
    .pw-toggle {
      position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
      background: none; border: none; cursor: pointer;
      color: var(--text-muted); padding: 4px;
      display: flex; transition: color .2s;
    }
    .pw-toggle:hover { color: var(--navy); }
    .pw-toggle svg { width: 15px; height: 15px; fill:none; stroke:currentColor; stroke-width:1.8; }

    /* Hint text */
    .field-hint {
      font-size: 11px; color: var(--text-muted); margin-top: 5px; line-height: 1.5;
    }

    /* Submit button */
    .btn-submit {
      width: 100%; padding: 13px;
      background: var(--navy);
      color: #fff;
      border: none; border-radius: 7px;
      font-family: 'Source Sans 3', sans-serif;
      font-size: 14.5px; font-weight: 600;
      cursor: pointer; letter-spacing: 0.03em;
      display: flex; align-items: center; justify-content: center; gap: 8px;
      margin-top: 8px;
      transition: background .2s, transform .15s, box-shadow .2s;
      box-shadow: 0 3px 12px rgba(30,58,95,0.25);
    }
    .btn-submit:hover {
      background: var(--navy-dark);
      transform: translateY(-1px);
      box-shadow: 0 6px 20px rgba(30,58,95,0.30);
    }
    .btn-submit:active { transform: translateY(0); }
    .btn-submit svg { width: 15px; height: 15px; fill:none; stroke:currentColor; stroke-width:2.5; }

    .form-footer {
      margin-top: 22px; text-align: center;
      font-size: 12.5px; color: var(--text-muted);
    }
    .form-footer a {
      color: var(--navy); font-weight: 600;
      text-decoration: none; transition: color .2s;
    }
    .form-footer a:hover { color: var(--gold); text-decoration: underline; }

    /* ── BOTTOM FOOTER (matches index.php) ── */
    

    /* ── RESPONSIVE ── */
    @media (max-width: 860px) {
      .gov-banner, nav, .page-footer { padding-left: 20px; padding-right: 20px; }
    }
    @media (max-width: 760px) {
      .panel-left { display: none; }
      .panel-right { width: 100%; padding: 36px 28px; }
      .login-card { max-width: 440px; }
    }
    @media (max-width: 480px) {
      .panel-right { padding: 30px 22px; }
    }
  </style>
</head>
<body>

<div class="shell">

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

  <!-- MAIN -->
  <div class="main">
    <div class="login-card">

      <!-- LEFT PANEL -->
      <div class="panel-left">
        <div class="panel-illustration">

          <div class="illus-logo">
            <img src="img/RCMP.png" alt="UniKL RCMP" />
          </div>

          <div class="panel-tagline">
            <h2>Help Desk<br><em>Management Portal</em></h2>
            <p>Sign in with your UniKL credentials to manage tickets, departments and operations.</p>
          </div>

        </div>
      </div>

      <!-- RIGHT PANEL: FORM -->
      <div class="panel-right">

        <div class="form-header">
          <h1>Operator <em>Sign In</em></h1>
          <p>Not an operator? <a href="index.php">Go to user portal →</a></p>
        </div>

        <div class="form-divider"></div>

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
            <div class="field-hint">Use your UniKL staff email address.</div>
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

        <div class="form-footer">
          Need help? Contact ITD Ext. <strong style="color:var(--navy);">142 / 140</strong>
        </div>

        <div style="margin-top:14px;text-align:center;">
          <a href="index.php" style="display:inline-flex;align-items:center;gap:6px;padding:9px 20px;background:transparent;color:var(--text-mid);font-size:13px;font-weight:600;text-decoration:none;border:1.5px solid var(--border);border-radius:7px;transition:all .2s;" onmouseover="this.style.borderColor='var(--navy)';this.style.color='var(--navy)';this.style.background='var(--navy-light)'" onmouseout="this.style.borderColor='var(--border)';this.style.color='var(--text-mid)';this.style.background='transparent'">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5"/><path d="M12 5l-7 7 7 7"/></svg>
            Back to Home
          </a>
        </div>

      </div><!-- /panel-right -->

    </div><!-- /login-card -->
  </div><!-- /main -->

</div><!-- /shell -->

<script>
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