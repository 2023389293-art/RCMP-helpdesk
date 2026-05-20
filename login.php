<?php
// uniKL/complaint/login.php 
session_start();
require 'db_connect.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Please fill in all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $domain = strtolower(substr(strrchr($email, '@'), 1));

        if ($domain === 's.unikl.edu.my') {
            // ── STUDENT LOGIN (UniKL student email) ──────────────────────
            $stmt = $conn->prepare("SELECT * FROM students WHERE email = ? AND status = 'active' LIMIT 1");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($user && password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user_id']    = $user['student_id'];
                $_SESSION['user_name']  = $user['full_name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_role']  = 'student';
                $_SESSION['user_dept']  = null;
                unset($_SESSION['staff_id']);
                unset($_SESSION['fb_popup_shown']);
                header('Location: complaint/homepage.php');
                exit;
            } else {
                $error = 'Invalid email or password.';
            }

        } elseif ($domain === 'unikl.edu.my') {
            // ── STAFF / ADMIN LOGIN (any UniKL staff email) ──────────────
            $stmt = $conn->prepare("SELECT * FROM staff WHERE email = ? AND status = 'active' LIMIT 1");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($user && password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['staff_id']   = $user['staff_id'];
                $_SESSION['user_id']    = $user['staff_id'];
                $_SESSION['user_name']  = $user['full_name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_role']  = $user['role']; // use actual role from DB
                unset($_SESSION['fb_popup_shown']);

                $deptId = null;
                if (!empty($user['department'])) {
                    $dstmt = $conn->prepare("SELECT dept_id FROM departments WHERE dept_name = ? LIMIT 1");
                    $dstmt->bind_param("s", $user['department']);
                    $dstmt->execute();
                    $drow = $dstmt->get_result()->fetch_assoc();
                    $dstmt->close();
                    if ($drow) $deptId = $drow['dept_id'];
                }
                $_SESSION['user_dept'] = $deptId;

                header('Location: complaint/homepage.php');
                exit;
            } else {
                $error = 'Invalid email or password.';
            }

        } elseif ($domain === 'gmail.com') {
            // ── GMAIL: Try STUDENT first, then STAFF ─────────────────────

            // 1. Check students table first
            $stmt = $conn->prepare("SELECT * FROM students WHERE email = ? AND status = 'active' LIMIT 1");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($user && password_verify($password, $user['password_hash'])) {
                // Logged in as STUDENT
                session_regenerate_id(true);
                $_SESSION['user_id']    = $user['student_id'];
                $_SESSION['user_name']  = $user['full_name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_role']  = 'student';
                $_SESSION['user_dept']  = null;
                unset($_SESSION['staff_id']);
                unset($_SESSION['fb_popup_shown']);
                header('Location: complaint/homepage.php');
                exit;
            }

            // 2. If not a student, check staff table (all roles allowed)
            $stmt = $conn->prepare("SELECT * FROM staff WHERE email = ? AND status = 'active' LIMIT 1");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($user && password_verify($password, $user['password_hash'])) {
                // Logged in as staff/admin/super_admin/report_viewer
                session_regenerate_id(true);
                $_SESSION['staff_id']   = $user['staff_id'];
                $_SESSION['user_id']    = $user['staff_id'];
                $_SESSION['user_name']  = $user['full_name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_role']  = $user['role']; // use actual role from DB
                unset($_SESSION['fb_popup_shown']);

                $deptId = null;
                if (!empty($user['department'])) {
                    $dstmt = $conn->prepare("SELECT dept_id FROM departments WHERE dept_name = ? LIMIT 1");
                    $dstmt->bind_param("s", $user['department']);
                    $dstmt->execute();
                    $drow = $dstmt->get_result()->fetch_assoc();
                    $dstmt->close();
                    if ($drow) $deptId = $drow['dept_id'];
                }
                $_SESSION['user_dept'] = $deptId;

                header('Location: complaint/homepage.php');
                exit;
            } else {
                $error = 'Invalid email or password.';
            }

            } elseif ($domain === 'student.uitm.edu.my') {
    $stmt = $conn->prepare("SELECT * FROM students WHERE email = ? AND status = 'active' LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id']    = $user['student_id'];
        $_SESSION['user_name']  = $user['full_name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role']  = 'student';
        $_SESSION['user_dept']  = null;
        unset($_SESSION['staff_id']);
        unset($_SESSION['fb_popup_shown']);
        header('Location: complaint/homepage.php');
        exit;
    } else {
        $error = 'Invalid email or password.';
    }

        } else {
            $error = 'Please use your UniKL email (@s.unikl.edu.my or @unikl.edu.my) or registered Gmail address.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Log In | UniKL CMS</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,300&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet" />
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --navy:        #000d24;
      --blue:        #012d6b;
      --blue-mid:    #0055a5;
      --gold:        #E9C46A;
      --gold-light:  #f5d98a;
      --gold-dark:   #C9A227;
      --white:       #FFFFFF;
      --white-95:    rgba(255,255,255,0.95);
      --white-80:    rgba(255,255,255,0.80);
      --white-60:    rgba(255,255,255,0.60);
      --white-30:    rgba(255,255,255,0.30);
      --white-12:    rgba(255,255,255,0.12);
      --white-06:    rgba(255,255,255,0.06);
      --error:       #FF6B6B;
      --error-bg:    rgba(255,107,107,0.12);
    }

    html { scroll-behavior: smooth; height: 100%; }

    body {
      font-family: 'DM Sans', sans-serif;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      position: relative;
      overflow: hidden;
    }

    .bg {
      position: fixed; inset: 0; z-index: 0;
      background: url('img/bg.jpg') center/cover no-repeat;
    }
    .bg::before {
      content: '';
      position: absolute; inset: 0;
      background: radial-gradient(ellipse 70% 80% at 50% 50%,
        rgba(0,13,36,0.20) 0%, rgba(0,13,36,0.50) 60%, rgba(0,13,36,0.70) 100%);
    }
    .bg::after {
      content: '';
      position: absolute; inset: 0;
      background: linear-gradient(160deg,
        rgba(0,25,70,0.15) 0%, rgba(0,60,140,0.10) 50%, rgba(0,10,40,0.20) 100%);
    }

    .page-wrap {
      position: relative; z-index: 2;
      display: flex; flex-direction: column; align-items: center;
      width: 100%; padding: 32px 16px;
    }

    .logo-wrap {
      display: flex; flex-direction: column; align-items: center; gap: 12px;
      margin-bottom: 28px;
      animation: fadeDown 0.6s cubic-bezier(.22,1,.36,1) both;
    }
    .logo-badge { display: flex; align-items: center; gap: 12px; }
    .badge-box {
      width: 48px; height: 48px; border-radius: 12px;
      background: linear-gradient(145deg, #001a4d 0%, #0044a3 100%);
      border: 1.5px solid rgba(233,196,106,0.5);
      display: flex; align-items: center; justify-content: center;
      box-shadow: 0 6px 20px rgba(0,0,0,0.5), 0 0 0 4px rgba(233,196,106,0.08);
    }
    .badge-box span { font-size: 15px; font-weight: 700; color: var(--white); letter-spacing: -0.3px; }
    .badge-box span em { color: var(--gold); font-style: normal; }
    .logo-divider { width: 1px; height: 36px; background: rgba(255,255,255,0.25); }
    .logo-text { display: flex; flex-direction: column; }
    .lt1 { font-size: 9px; font-weight: 500; color: var(--white-60); letter-spacing: 0.14em; text-transform: uppercase; }
    .lt2 { font-size: 14px; font-weight: 600; color: var(--white); line-height: 1.25; }

    .card {
      position: relative;
      width: 100%; max-width: 430px;
      background: rgba(0, 55, 140, 0.75);
      backdrop-filter: blur(32px) saturate(1.6) brightness(1.05);
      -webkit-backdrop-filter: blur(32px) saturate(1.6) brightness(1.05);
      border: 1px solid rgba(255,255,255,0.16);
      border-top: 1px solid rgba(233,196,106,0.55);
      border-radius: 22px;
      padding: 44px 40px 38px;
      box-shadow:
        0 0 0 1px rgba(0,0,0,0.4),
        0 2px 4px rgba(0,0,0,0.3),
        0 8px 24px rgba(0,0,0,0.45),
        0 24px 60px rgba(0,0,0,0.55),
        0 0 80px rgba(0,20,70,0.6),
        inset 0 1px 0 rgba(255,255,255,0.10);
      animation: fadeUp 0.65s cubic-bezier(.22,1,.36,1) 0.1s both;
    }
    .card::before {
      content: '';
      position: absolute;
      top: -1px; left: 20%; right: 20%;
      height: 2px;
      background: linear-gradient(90deg, transparent, var(--gold), transparent);
      border-radius: 100px;
    }

    @keyframes fadeDown { from { opacity:0; transform: translateY(-20px); } to { opacity:1; transform: translateY(0); } }
    @keyframes fadeUp   { from { opacity:0; transform: translateY(26px);  } to { opacity:1; transform: translateY(0); } }

    .card-head { margin-bottom: 30px; text-align: center; }
    .card-head h1 {
      font-family: 'DM Serif Display', serif;
      font-size: 34px; font-weight: 400;
      color: var(--white); line-height: 1.12; margin-bottom: 10px;
      text-shadow: 0 2px 12px rgba(0,0,0,0.4);
    }
    .card-head h1 em { font-style: italic; color: var(--gold); }
    .card-head p { font-size: 13px; color: var(--white-60); font-weight: 300; line-height: 1.65; }
    .card-head p a { color: var(--gold-light); font-weight: 500; text-decoration: none; transition: color .2s; }
    .card-head p a:hover { color: var(--white); text-decoration: underline; }

    .card-divider {
      height: 1px;
      background: linear-gradient(90deg, transparent, rgba(255,255,255,0.12), transparent);
      margin-bottom: 26px;
    }

    .alert {
      display: flex; align-items: center; gap: 10px;
      background: var(--error-bg);
      border: 1px solid rgba(255,107,107,0.35);
      color: var(--error); padding: 12px 15px; border-radius: 11px;
      font-size: 12.5px; margin-bottom: 22px;
      animation: fadeUp 0.3s ease both;
    }
    .alert svg { width: 15px; height: 15px; flex-shrink: 0; fill: none; stroke: currentColor; stroke-width: 2.2; }

    .field { margin-bottom: 16px; }
    .field label {
      display: block; font-size: 11px; font-weight: 600;
      color: var(--white-60); margin-bottom: 8px;
      letter-spacing: 0.08em; text-transform: uppercase;
    }
    .input-wrap { position: relative; }
    .field-icon {
      position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
      pointer-events: none;
    }
    .field-icon svg { width: 15px; height: 15px; fill: none; stroke: rgba(233,196,106,0.5); stroke-width: 1.8; display: block; }
    .field input {
      width: 100%; padding: 13px 14px 13px 43px;
      background: rgba(255,255,255,0.07);
      border: 1.5px solid rgba(255,255,255,0.14);
      border-radius: 11px;
      font-family: 'DM Sans', sans-serif;
      font-size: 14px; color: var(--white);
      outline: none;
      transition: border-color .25s, background .25s, box-shadow .25s;
    }
    .field input::placeholder { color: rgba(255,255,255,0.28); }
    .field input:focus {
      border-color: rgba(233,196,106,0.7);
      background: rgba(255,255,255,0.11);
      box-shadow: 0 0 0 3px rgba(233,196,106,0.12), 0 2px 12px rgba(0,0,0,0.2);
    }
    .field input:-webkit-autofill {
      -webkit-box-shadow: 0 0 0px 1000px rgba(1,20,60,0.9) inset;
      -webkit-text-fill-color: var(--white);
    }
    .pw-btn {
      position: absolute; right: 13px; top: 50%; transform: translateY(-50%);
      background: none; border: none; cursor: pointer; color: rgba(255,255,255,0.35);
      display: flex; padding: 3px; transition: color .2s;
    }
    .pw-btn:hover { color: var(--white-80); }
    .pw-btn svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 1.8; }

    .form-extras {
      display: flex; align-items: center; justify-content: space-between;
      margin: 8px 0 26px;
    }
    .check-label {
      display: flex; align-items: center; gap: 8px;
      font-size: 12.5px; color: var(--white-60); cursor: pointer; user-select: none;
    }
    .check-label input { width: 14px; height: 14px; accent-color: var(--gold); cursor: pointer; }
    .forgot {
      font-size: 12.5px; font-weight: 500; color: var(--white-60);
      text-decoration: none; transition: color .2s;
    }
    .forgot:hover { color: var(--gold); }

    .btn-login {
      width: 100%; padding: 15px;
      border: none; border-radius: 11px;
      background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold-light) 100%);
      color: #0a1a3a;
      font-family: 'DM Sans', sans-serif;
      font-size: 14.5px; font-weight: 700;
      cursor: pointer;
      display: flex; align-items: center; justify-content: center; gap: 8px;
      letter-spacing: 0.03em;
      transition: transform .18s, box-shadow .2s, opacity .2s;
      box-shadow: 0 4px 16px rgba(201,162,39,0.45), 0 1px 0 rgba(255,255,255,0.3) inset;
      position: relative; overflow: hidden;
    }
    .btn-login::before {
      content: '';
      position: absolute; inset: 0;
      background: linear-gradient(135deg, rgba(255,255,255,0.18) 0%, transparent 60%);
      pointer-events: none;
    }
    .btn-login:hover { transform: translateY(-2px); box-shadow: 0 8px 28px rgba(201,162,39,0.55), 0 1px 0 rgba(255,255,255,0.3) inset; }
    .btn-login:active { transform: translateY(0); box-shadow: 0 3px 10px rgba(201,162,39,0.35); }
    .btn-login svg { width: 16px; height: 16px; fill: none; stroke: #0a1a3a; stroke-width: 2.5; }

    .bottom-link {
      text-align: center; margin-top: 22px;
      font-size: 12.5px; color: rgba(255,255,255,0.30);
    }
    .bottom-link a { color: var(--white-60); font-weight: 500; text-decoration: none; transition: color .2s; }
    .bottom-link a:hover { color: var(--gold); }

    footer {
      position: relative; z-index: 2;
      margin-top: 24px; text-align: center;
      font-size: 11px; color: rgba(255,255,255,0.28);
      letter-spacing: 0.04em;
      animation: fadeUp 0.7s cubic-bezier(.22,1,.36,1) 0.2s both;
    }

    @media (max-width: 480px) {
      .card { padding: 34px 24px 30px; }
      .card-head h1 { font-size: 28px; }
    }
  </style>
</head>
<body>

<div class="bg"></div>

<div class="page-wrap">

  

  <div class="card">

   <div class="card-head">
      <div class="logo-badge" style="justify-content:center; margin-bottom:20px;">
        <div style="width:82px; height:82px; flex-shrink:0;">
          <img src="img/RCMP.png" alt="UniKL RCMP" style="width:100%; height:100%; object-fit:contain;" />
        </div>
        <div style="width:1px; height:36px; background:rgba(255,255,255,0.25);"></div>
        <div style="display:flex; flex-direction:column; text-align:left;">
          <span style="font-size:9px; font-weight:500; color:rgba(255,255,255,0.6); letter-spacing:0.14em; text-transform:uppercase;">UniKL Royal College of Medicine Perak</span>
          <span style="font-size:14px; font-weight:600; color:#fff; line-height:1.25;">Help Desk System</span>
        </div>
      </div>
      <h1>Welcome <em>Back</em></h1>
      <p>Log In with your UniKL email to access the Help Desk.</p>
    </div>

    <div class="card-divider"></div>

    <?php if ($error): ?>
    <div class="alert">
      <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="login.php" id="loginForm">

      <div class="field">
        <label for="email">Email Address</label>
        <div class="input-wrap">
          <div class="field-icon">
            <svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
          </div>
          <input type="email" id="email" name="email"
            placeholder="yourname@s.unikl.edu.my"
            value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
            required autocomplete="email"/>
        </div>
      </div>

      <div class="field">
        <label for="password">Password</label>
        <div class="input-wrap">
          <div class="field-icon">
            <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          </div>
          <input type="password" id="password" name="password"
            placeholder="Enter your password"
            required autocomplete="current-password"/>
          <button type="button" class="pw-btn" id="pwToggle" aria-label="Toggle password visibility">
            <svg id="eyeIcon" viewBox="0 0 24 24">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
              <circle cx="12" cy="12" r="3"/>
            </svg>
          </button>
        </div>
      </div>

<br>
      <button type="submit" class="btn-login">
        Log In
        <svg viewBox="0 0 24 24"><path d="M5 12h14"/><path d="M12 5l7 7-7 7"/></svg>
      </button>

    </form>

    <!-- Divider -->
    <div style="display:flex; align-items:center; gap:10px; margin:20px 0;">
      <div style="flex:1; height:1px; background:rgba(255,255,255,0.12);"></div>
      <span style="color:rgba(255,255,255,0.35); font-size:12px;">or</span>
      <div style="flex:1; height:1px; background:rgba(255,255,255,0.12);"></div>
    </div>

    <!-- SSO Button -->
    <a href="#" class="btn-login" onclick="return false;" style="
        text-decoration:none;
        background: rgba(255,255,255,0.06);
        color: rgba(255,255,255,0.35);
        border: 1.5px solid rgba(255,255,255,0.10);
        box-shadow: none;
        margin-bottom: 4px;
        cursor: not-allowed;
        pointer-events: none;
        opacity: 0.5;">
      <svg viewBox="0 0 21 21" width="16" height="16" style="flex-shrink:0;">
        <rect x="1" y="1" width="9" height="9" fill="#f25022"/>
        <rect x="11" y="1" width="9" height="9" fill="#7fba00"/>
        <rect x="1" y="11" width="9" height="9" fill="#00a4ef"/>
        <rect x="11" y="11" width="9" height="9" fill="#ffb900"/>
      </svg>
      Log in with Microsoft (UniKL SSO)
    </a>

    <!-- Back link (only once) -->
    <div class="bottom-link">
      <a href="index.php">&larr; Back to Homepage</a>
    </div>

  </div>

 

</div>

<script>
  const pw = document.getElementById('password');
  const eye = document.getElementById('eyeIcon');
  const open = `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>`;
  const shut = `<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19M1 1l22 22"/>`;
  document.getElementById('pwToggle').addEventListener('click', () => {
    const isPw = pw.type === 'password';
    pw.type = isPw ? 'text' : 'password';
    eye.innerHTML = isPw ? shut : open;
  });
</script>

</body>
</html>