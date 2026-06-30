<?php
// vendor_login.php — RUSH Vendor Portal (Register + Login)
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
    header("Location: vendor_login.php");
    exit;
}

// ── If already logged in as vendor, redirect ──────────────────────────────
if (!empty($_SESSION['vendor_id'])) {
    header("Location: vendorRCMP/dashboard.php");
    exit;
}

require 'db_connect.php';


$error        = '';
$success      = '';
$active_tab = 'login';




// ══════════════════════════════════════════════════════════════════════════
// LOGIN
// ══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $active_tab = 'login';
    $email      = trim($_POST['email']    ?? '');
    $password   =       $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Please fill in all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $stmt = $conn->prepare(
            "SELECT vendor_id, company_name, address, city, state, postcode, email, phone, password_hash, status, first_login
               FROM vendors WHERE email = ? LIMIT 1"
        );
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $vendor = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$vendor || !password_verify($password, $vendor['password_hash'])) {
            $error = 'Invalid email or password.';
        } elseif ($vendor['status'] === 'suspended') {
            $error = 'Your account has been suspended. Please contact ITD for assistance.';
        } else {
            // Check if at least one department has approved this vendor
            $chk_dept = $conn->prepare(
                "SELECT COUNT(*) FROM vendor_departments
                  WHERE vendor_id = ? AND status = 'active'"
            );
            $chk_dept->bind_param("i", $vendor['vendor_id']);
            $chk_dept->execute();
            $chk_dept->bind_result($active_dept_count);
            $chk_dept->fetch();
            $chk_dept->close();

            if ($active_dept_count === 0) {
                $error = 'Your account is still awaiting approval. Please wait for a department administrator to approve your registration.';
            } else {
                // Active — create session
                session_regenerate_id(true);
            $_SESSION['vendor_id']       = $vendor['vendor_id'];
            $_SESSION['vendor_name']     = $vendor['company_name'];
            $_SESSION['vendor_company']  = $vendor['company_name'];
            $_SESSION['vendor_email']    = $vendor['email'];
            $_SESSION['vendor_phone']    = $vendor['phone'];
            $_SESSION['vendor_address']  = $vendor['address']  ?? '';
            $_SESSION['vendor_city']     = $vendor['city']     ?? '';
            $_SESSION['vendor_state']    = $vendor['state']    ?? '';
            $_SESSION['vendor_postcode'] = $vendor['postcode'] ?? '';
            $_SESSION['vendor_first_login'] = (int)$vendor['first_login'];
            header("Location: vendorRCMP/dashboard.php");
            exit;
            }  // end active_dept_count check
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Vendor Portal | UniKL RCMP RUSH</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,700;1,700&family=Source+Sans+3:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
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
      --page-bg:     #e8edf5;
      --surface:     #FFFFFF;
      --border:      #D8D3C8;
      --text:        #1A2332;
      --text-mid:    #3D4F63;
      --text-muted:  #7A8899;
      --error:       #C0392B;
      --error-bg:    #FDF0EE;
      --error-border:#E8C5C0;
      --success:     #1B6E49;
      --success-bg:  #EDF7F2;
      --success-border:#A8D5BC;
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

    /* ── MAIN ── */
    .main {
      min-height: calc(100vh - 38px - 96px);
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

    /* ── CARD ── */
    .vendor-card {
      position: relative; z-index: 2;
      width: 100%; max-width: 500px;
      background: var(--surface);
      border: 1px solid var(--border);
      border-top: 3px solid var(--gold);
      border-radius: 10px;
      overflow: hidden;
      box-shadow: 0 4px 32px rgba(20,40,70,0.10), 0 1px 4px rgba(20,40,70,0.07);
      animation: fadeUp 0.5s cubic-bezier(.22,1,.36,1) both;
    }
    @keyframes fadeUp { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:translateY(0)} }

    .card-body { padding: 40px 44px 32px; }

    /* ── CARD HEADER ── */
    .card-header { text-align: center; margin-bottom: 28px; }
    .card-logo {
      display: inline-flex; align-items: center; gap: 14px;
      margin-bottom: 18px;
    }
    .card-logo img { width: 80px; height: 80px; object-fit: contain; }
    .card-logo-divider { width: 1px; height: 40px; background: var(--border); }
    .card-logo-text { text-align: left; }
    .card-logo-org  { font-size: 12px; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; color: var(--olive); }
    .card-logo-name { font-size: 16px; font-weight: 700; color: var(--navy-dark); line-height: 1.3; }

    .card-header h1 {
      font-family: 'Playfair Display', serif;
      font-size: 26px; font-weight: 700;
      color: var(--navy-dark); line-height: 1.2; margin-bottom: 6px;
    }
    .card-header h1 em { font-style: italic; color: var(--gold-light); }
    .card-header p { font-size: 13px; color: var(--text-muted); line-height: 1.6; }

    /* ── TABS ── */
    .tab-row {
      display: flex;
      border: 1.5px solid var(--border);
      border-radius: 7px;
      overflow: hidden;
      margin-bottom: 24px;
    }
    .tab-btn {
      flex: 1; padding: 10px 14px;
      background: transparent; border: none;
      font-family: 'Source Sans 3', sans-serif;
      font-size: 13.5px; font-weight: 600;
      color: var(--text-muted);
      cursor: pointer; transition: all 0.2s;
      display: flex; align-items: center; justify-content: center; gap: 7px;
    }
    .tab-btn svg { width: 14px; height: 14px; }
    .tab-btn:first-child { border-right: 1.5px solid var(--border); }
    .tab-btn.active {
      background: var(--navy);
      color: #fff;
    }
    .tab-btn:not(.active):hover { background: var(--navy-light); color: var(--navy); }

    /* ── TAB PANELS ── */
    .tab-panel { display: none; }
    .tab-panel.active { display: block; }

    /* ── ALERTS ── */
    .alert {
      display: flex; align-items: flex-start; gap: 10px;
      padding: 11px 14px; border-radius: 6px;
      margin-bottom: 18px; font-size: 13px; line-height: 1.5;
      animation: fadeUp 0.3s ease both;
    }
    .alert svg { width: 15px; height: 15px; fill:none; stroke:currentColor; stroke-width:2; flex-shrink:0; margin-top:1px; }
    .alert-error {
      background: var(--error-bg); border: 1px solid var(--error-border);
      border-left: 3px solid var(--error); color: var(--error);
    }
    .alert-success {
      background: var(--success-bg); border: 1px solid var(--success-border);
      border-left: 3px solid var(--success); color: var(--success);
    }

    /* ── FORM FIELDS ── */
    .field { margin-bottom: 14px; }
    .field label {
      display: block; font-size: 12px; font-weight: 600;
      color: var(--text-mid); margin-bottom: 6px; letter-spacing: 0.03em;
    }
    .input-wrap { position: relative; }
    .input-icon {
      position: absolute; left: 13px; top: 50%; transform: translateY(-50%);
      pointer-events: none; color: #B0BAC8;
    }
    .input-icon svg { width: 15px; height: 15px; fill:none; stroke:currentColor; stroke-width:1.8; display:block; }
    .field input {
      width: 100%; padding: 11px 14px 11px 40px;
      background: var(--input-bg); border: 1.5px solid var(--border); border-radius: 7px;
      font-family: 'Source Sans 3', sans-serif; font-size: 14px; color: var(--text);
      outline: none; transition: border-color .2s, background .2s, box-shadow .2s;
    }
    .field input::placeholder { color: #C0C7D0; }
    .field input:focus {
      border-color: var(--navy); background: var(--input-focus);
      box-shadow: 0 0 0 3px rgba(30,58,95,0.10);
    }
    .pw-toggle {
      position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
      background: none; border: none; cursor: pointer;
      color: var(--text-muted); padding: 4px; display: flex; transition: color .2s;
    }
    .pw-toggle:hover { color: var(--navy); }
    .pw-toggle svg { width: 15px; height: 15px; fill:none; stroke:currentColor; stroke-width:1.8; }

    .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

    /* ── SUBMIT BUTTON ── */
    .btn-submit {
      width: 100%; padding: 13px 20px;
      background: var(--gold); color: #fff;
      border: none; border-radius: 7px;
      font-family: 'Source Sans 3', sans-serif;
      font-size: 14.5px; font-weight: 700; letter-spacing: 0.02em;
      cursor: pointer;
      display: flex; align-items: center; justify-content: center; gap: 8px;
      transition: background .2s, transform .15s, box-shadow .2s;
      box-shadow: 0 3px 12px rgba(184,134,11,0.35);
      margin-top: 4px;
    }
    .btn-submit:hover { background: var(--gold-light); transform: translateY(-1px); box-shadow: 0 6px 20px rgba(184,134,11,0.40); }
    .btn-submit:active { transform: translateY(0); }
    .btn-submit svg { width: 15px; height: 15px; fill:none; stroke:currentColor; stroke-width:2.5; }

    .btn-submit.navy {
      background: var(--navy);
      box-shadow: 0 3px 12px rgba(30,58,95,0.25);
    }
    .btn-submit.navy:hover { background: var(--navy-dark); box-shadow: 0 6px 20px rgba(30,58,95,0.30); }

    /* ── HINT TEXT ── */
    .field-hint { font-size: 11.5px; color: var(--text-muted); margin-top: 5px; line-height: 1.5; }

    /* ── DIVIDER ── */
    .card-divider { height: 1px; background: var(--border); margin-bottom: 22px; }

    /* ── FOOTER ── */
    .card-footer {
      border-top: 1px solid var(--border);
      padding: 15px 44px;
      display: flex; align-items: center; justify-content: center; gap: 6px;
      font-size: 12px; color: var(--text-muted);
    }
    .card-footer a { color: var(--navy); font-weight: 600; text-decoration: none; }
    .card-footer a:hover { color: var(--gold); text-decoration: underline; }

    /* ── PENDING BADGE ── */
    .status-badge {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 4px 12px; border-radius: 100px;
      font-size: 11.5px; font-weight: 600; letter-spacing: 0.04em;
    }
    .badge-gold { background: #FBF5E6; border: 1px solid #D4A017; color: var(--gold); }

    /* ── RESPONSIVE ── */
    @media (max-width: 640px) {
      .gov-banner { display: none; }
      nav { padding: 0 16px; height: 60px; }
      .nav-logo img { width: 44px; height: 44px; }
      .nav-org { font-size: 10px; }
      .nav-title { font-size: 12px; }
      .nav-divider { display: none; }
      .main { padding: 24px 12px; align-items: flex-start; padding-top: 32px; }
      .card-body { padding: 28px 20px 22px; }
      .card-footer { padding: 14px 20px; }
      .field-row { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>

<!-- ── GOV BANNER ── -->
<div class="gov-banner">
  <div class="gov-banner-left">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.5)" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
    <span>UniKL Royal College of Medicine Perak</span>
    <span style="color:rgba(255,255,255,0.25)">·</span>
    <span>Vendor Portal</span>
  </div>
  <div class="gov-banner-right">Doc Ref: UniKL/RCMP/CD/ITD-01-01</div>
</div>

<!-- ── NAV ── -->
<nav>
  <a class="nav-brand" href="index.php">
    <div class="nav-logo"><img src="img/RCMP.png" alt="UniKL RCMP Logo"/></div>
    <div class="nav-divider"></div>
    <div class="nav-text-group">
      <span class="nav-org">UniKL RCMP</span>
      <span class="nav-title">RUSH — Vendor Portal</span>
    </div>
  </a>
  <a href="index.php" class="nav-back">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5"/><path d="M12 5l-7 7 7 7"/></svg>
    Back to Home
  </a>
</nav>

<!-- ── MAIN ── -->
<div class="main">
  <div class="vendor-card">
    <div class="card-body">

      <!-- Header -->
      <div class="card-header">
        <div class="card-logo">
          <img src="img/RCMP.png" alt="UniKL RCMP"/>
          <div class="card-logo-divider"></div>
          <div class="card-logo-text">
            <div class="card-logo-org">UniKL RCMP</div>
            <div class="card-logo-name">Vendor Portal</div>
          </div>
        </div>
        <h1>Vendor <em>Access</em></h1>
        <p>Sign in to manage your assigned work orders.</p>
      </div>

      <div class="card-divider"></div>

      

      

      <!-- ── LOGIN PANEL ── -->
      <div class="tab-panel active" id="panel-login">

        <?php if ($error && $active_tab === 'login'): ?>
        <div class="alert alert-error">
          <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <?php echo htmlspecialchars($error); ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="vendor_login.php">
          <input type="hidden" name="action" value="login"/>

          <div class="field">
            <label for="login_email">Company Email</label>
            <div class="input-wrap">
              <div class="input-icon">
                <svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
              </div>
              <input type="email" id="login_email" name="email"
                     placeholder="vendor@company.com"
                     value="<?php echo $active_tab === 'login' ? htmlspecialchars($_POST['email'] ?? '') : ''; ?>"
                     required autocomplete="email"/>
            </div>
          </div>

          <div class="field">
            <label for="login_password">Password</label>
            <div class="input-wrap">
              <div class="input-icon">
                <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              </div>
              <input type="password" id="login_password" name="password"
                     placeholder="Enter your password"
                     required autocomplete="current-password"/>
              <button type="button" class="pw-toggle" onclick="togglePw('login_password', this)" aria-label="Show/hide password">
                <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
            </div>
          </div>

          <button type="submit" class="btn-submit navy">
            Sign In
            <svg viewBox="0 0 24 24"><path d="M5 12h14"/><path d="M12 5l7 7-7 7"/></svg>
          </button>
        </form>

        
      </div>

      

    </div><!-- /card-body -->

    <div class="card-footer">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      Need help? Contact ITD Ext.&nbsp;<a href="#"><strong>142</strong></a>&nbsp;/&nbsp;<a href="#"><strong>140</strong></a>
    </div>
  </div><!-- /vendor-card -->
</div><!-- /main -->

<script>
  

  function togglePw(inputId, btn) {
    const input = document.getElementById(inputId);
    const isHidden = input.type === 'password';
    input.type = isHidden ? 'text' : 'password';
    const openEye  = `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>`;
    const shutEye  = `<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19M1 1l22 22"/>`;
    btn.querySelector('svg').innerHTML = isHidden ? shutEye : openEye;
  }

  
</script>

</body>
</html>