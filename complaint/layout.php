<?php
// uniKL/complaint/complaint/layout.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_fbUserId = (int)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? 0);

$_nameParts = explode(' ', $userName ?? 'User X');
$_initials  = strtoupper(substr($_nameParts[0], 0, 1) . substr($_nameParts[1] ?? 'X', 0, 1));
$_firstName = $_nameParts[0];

$_fbApiUrl  = 'feedback_api.php';
$_fbMarkUrl = 'feedback_mark_shown.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?php echo htmlspecialchars($pageTitle ?? 'UniKL CMS'); ?> | UniKL CMS</title>

  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="css/homepage.css"/>
  <?php if (!empty($extraHead)) echo $extraHead; ?>

  <style>
    *, *::before, *::after { font-family: 'Inter', sans-serif; box-sizing: border-box; }
    body     { display: block !important; font-family: 'Inter', sans-serif; }
    .sidebar { display: none !important; }
    .main    { margin-left: 0 !important; width: 100% !important; }

    .topbar-title { display: flex !important; align-items: center !important; gap: 12px !important; }
    .topbar-logo  { height: 48px; width: auto; display: block; flex-shrink: 0; object-fit: contain; }
    .topbar-title-text { display: flex; flex-direction: column; justify-content: center; }

    .topbar-site-title {
      font-family: 'Inter', sans-serif !important; font-weight: 700 !important;
      font-size: 17px !important; letter-spacing: -0.01em !important;
      color: #0D1F3C !important; line-height: 1.3 !important;
      margin: 0 0 2px 0 !important; font-style: normal !important; display: block;
    }
    .topbar-title h1 {
      font-family: 'Inter', sans-serif !important; font-weight: 600 !important;
      font-size: 13px !important; color: #5a607a !important;
      line-height: 1.3 !important; margin: 0 !important; font-style: normal !important;
    }
    .topbar-title p {
      font-family: 'Inter', sans-serif !important; font-size: 11px !important;
      font-weight: 400 !important; color: #8a92ad !important;
      letter-spacing: 0.04em !important; text-transform: uppercase !important;
      margin-top: 3px !important; font-style: normal !important;
    }
    .section-header h2 {
      font-family: 'Inter', sans-serif !important; font-weight: 700 !important;
      font-size: 16px !important; color: #0D1F3C !important;
      letter-spacing: -0.01em !important; font-style: normal !important; margin: 0 !important;
    }

    /* ── NOTIFICATION BELL ── */
    .notif-wrapper { position: relative; }
    .notif-btn {
      width: 40px; height: 40px; border-radius: 10px;
      border: 1.5px solid var(--g200, #E5E7EB); background: white;
      cursor: pointer; display: flex; align-items: center; justify-content: center;
      position: relative; transition: border-color 0.2s, background 0.2s; flex-shrink: 0;
    }
    .notif-btn:hover  { border-color: var(--g400, #9CA3AF); background: var(--g100, #F3F4F6); }
    .notif-btn.active { border-color: var(--blue, #003B8E); background: var(--blue-light, #EFF6FF); }
    .notif-btn svg    { width: 18px; height: 18px; fill: none; stroke: var(--g600, #4B5563); stroke-width: 2; }
    .notif-btn.active svg { stroke: var(--blue, #003B8E); }
    .notif-badge {
      display: none; position: absolute; top: -5px; right: -5px;
      min-width: 18px; height: 18px; background: #E53935; color: white;
      font-family: 'Inter', sans-serif; font-size: 10px; font-weight: 700;
      border-radius: 9px; padding: 0 4px; align-items: center; justify-content: center;
      border: 2px solid white; line-height: 1; pointer-events: none;
    }
    .notif-badge.show { display: flex; }
    .notif-dropdown {
      display: none; position: absolute; top: calc(100% + 10px); right: 0;
      width: 360px; background: white; border: 1px solid var(--g200, #E5E7EB);
      border-radius: 16px; box-shadow: 0 8px 32px rgba(0,0,0,0.12), 0 2px 8px rgba(0,0,0,0.06);
      z-index: 999; overflow: hidden; animation: notifDrop 0.2s cubic-bezier(0.4,0,0.2,1);
    }
    .notif-wrapper.open .notif-dropdown { display: block; }
    @keyframes notifDrop { from{opacity:0;transform:translateY(-8px) scale(0.97)} to{opacity:1;transform:translateY(0) scale(1)} }
    .nd-header {
      padding: 16px 18px 12px; border-bottom: 1px solid var(--g100,#F3F4F6);
      display: flex; align-items: center; justify-content: space-between;
    }
    .nd-header h3 { font-family:'Inter',sans-serif; font-weight:700; font-size:15px; color:var(--g900,#0D1117); margin:0; }
    .nd-header-right { display: flex; align-items: center; gap: 8px; }
    .nd-count-pill {
      font-size:11px; font-weight:700; background:var(--blue-light,#EFF6FF); color:var(--blue,#003B8E);
      padding:2px 8px; border-radius:100px; border:1px solid rgba(0,59,142,0.15);
    }
    .nd-mark-read {
      font-size:12px; font-weight:500; color:var(--blue,#003B8E);
      cursor:pointer; background:none; border:none; font-family:'Inter',sans-serif; padding:0; transition:opacity 0.15s;
    }
    .nd-mark-read:hover { opacity: 0.7; }
    .nd-list { max-height:380px; overflow-y:auto; scroll-behavior:smooth; }
    .nd-list::-webkit-scrollbar { width:4px; }
    .nd-list::-webkit-scrollbar-thumb { background:var(--g200,#E5E7EB); border-radius:2px; }
    .nd-item {
      display:flex; align-items:flex-start; gap:11px; padding:13px 18px;
      border-bottom:1px solid var(--g100,#F3F4F6); text-decoration:none; color:inherit;
      transition:background 0.15s; cursor:pointer; position:relative;
    }
    .nd-item:last-child { border-bottom:none; }
    .nd-item:hover  { background:var(--g100,#F3F4F6); }
    .nd-item.unread { background:var(--blue-light,#EFF6FF); }
    .nd-item.unread:hover { background:#E0EEFF; }
    .nd-unread-dot { position:absolute; top:16px; right:14px; width:7px; height:7px; border-radius:50%; background:var(--blue,#003B8E); }
    .nd-avatar {
      width:36px; height:36px; border-radius:50%;
      background:linear-gradient(135deg,var(--blue,#003B8E),#4A90D9);
      color:white; font-size:12px; font-weight:700;
      display:flex; align-items:center; justify-content:center; flex-shrink:0; font-family:'Inter',sans-serif;
    }
    .nd-content { flex:1; min-width:0; }
    .nd-top { display:flex; align-items:baseline; gap:6px; margin-bottom:3px; flex-wrap:wrap; }
    .nd-sender  { font-size:13px; font-weight:600; color:var(--g900,#0D1117); white-space:nowrap; }
    .nd-action  { font-size:13px; color:var(--g600,#4B5563); }
    .nd-ticket  { font-size:11px; font-weight:600; color:var(--blue,#003B8E); background:rgba(0,59,142,0.08); padding:1px 6px; border-radius:4px; white-space:nowrap; flex-shrink:0; }
    .nd-message { font-size:12px; color:var(--g500,#6B7280); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:270px; line-height:1.4; margin-bottom:4px; }
    .nd-time { font-size:11px; color:var(--g400,#9CA3AF); }
    .nd-empty { display:flex; flex-direction:column; align-items:center; justify-content:center; padding:40px 24px; color:var(--g500,#6B7280); text-align:center; }
    .nd-empty-icon { width:48px; height:48px; border-radius:14px; background:var(--g100,#F3F4F6); display:flex; align-items:center; justify-content:center; margin-bottom:12px; }
    .nd-empty-icon svg { width:22px; height:22px; fill:none; stroke:var(--g300,#D1D5DB); stroke-width:1.8; }
    .nd-empty h4 { font-size:14px; font-weight:600; color:var(--g700,#374151); margin:0 0 4px; }
    .nd-empty p  { font-size:12px; color:var(--g400,#9CA3AF); margin:0; }
    .nd-skeleton { padding:13px 18px; display:flex; align-items:center; gap:11px; border-bottom:1px solid var(--g100,#F3F4F6); }
    .nd-skel-avatar { width:36px; height:36px; border-radius:50%; background:var(--g100,#F3F4F6); flex-shrink:0; animation:skelPulse 1.4s ease-in-out infinite; }
    .nd-skel-lines  { flex:1; display:flex; flex-direction:column; gap:6px; }
    .nd-skel-line   { height:10px; border-radius:5px; background:var(--g100,#F3F4F6); animation:skelPulse 1.4s ease-in-out infinite; }
    .nd-skel-line.short   { width:55%; }
    .nd-skel-line.shorter { width:35%; }
    @keyframes skelPulse { 0%,100%{opacity:1} 50%{opacity:0.4} }

    /* ═══════════════════════════════════════════════════════
       FEEDBACK POPUP STYLES
    ═══════════════════════════════════════════════════════ */
    #fbOverlay {
      display:none; position:fixed; inset:0; z-index:10000;
      background:rgba(10,20,45,0.55);
      backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px);
      align-items:center; justify-content:center;
    }
    #fbOverlay.fb-active { display:flex; animation:fbFadeIn .25s ease; }
    @keyframes fbFadeIn { from{opacity:0} to{opacity:1} }

    #fbModal {
      background:#fff; border-radius:22px;
      box-shadow:0 28px 72px rgba(0,30,80,.24),0 4px 18px rgba(0,0,0,.09);
      width:100%; max-width:500px; margin:16px; overflow:hidden;
      animation:fbSlideIn .32s cubic-bezier(.34,1.26,.64,1); position:relative;
    }
    @keyframes fbSlideIn { from{opacity:0;transform:scale(.86) translateY(24px)} to{opacity:1;transform:scale(1) translateY(0)} }

    .fb-close-x {
      position:absolute; top:14px; right:14px; width:32px; height:32px; border-radius:8px;
      background:rgba(255,255,255,.18); border:1px solid rgba(255,255,255,.28);
      cursor:pointer; display:flex; align-items:center; justify-content:center; z-index:10; transition:background .15s;
    }
    .fb-close-x:hover { background:rgba(255,255,255,.32); }
    .fb-close-x svg { width:14px; height:14px; fill:none; stroke:#fff; stroke-width:2.5; }

    .fb-header {
      background:linear-gradient(135deg,#0D1F3C 0%,#1a3a6e 60%,#1e4a8a 100%);
      padding:30px 30px 24px; position:relative; overflow:hidden;
    }
    .fb-header::before {
      content:''; position:absolute; top:-50px; right:-50px;
      width:160px; height:160px; border-radius:50%; background:rgba(255,255,255,.05);
    }
    .fb-header::after {
      content:''; position:absolute; bottom:-30px; left:-30px;
      width:100px; height:100px; border-radius:50%; background:rgba(255,255,255,.04);
    }
    .fb-header-icon {
      width:56px; height:56px; border-radius:16px;
      background:rgba(255,255,255,.12); border:1.5px solid rgba(255,255,255,.22);
      display:flex; align-items:center; justify-content:center; margin-bottom:16px; position:relative; z-index:1;
    }
    .fb-header-icon svg { width:26px; height:26px; fill:none; stroke:#fff; stroke-width:2; }
    .fb-header-title {
      font-family:'Inter',sans-serif; font-size:18px; font-weight:700;
      color:#fff; line-height:1.4; position:relative; z-index:1; padding-right:44px;
    }
    .fb-header-sub {
      font-family:'Inter',sans-serif; font-size:13px; color:rgba(255,255,255,.6);
      margin-top:6px; line-height:1.5; position:relative; z-index:1;
    }
    .fb-queue-badge {
      display:none; align-items:center; gap:6px; margin-top:10px;
      background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.22);
      border-radius:20px; padding:4px 12px; font-size:11.5px; font-weight:600;
      color:rgba(255,255,255,.9); font-family:'Inter',sans-serif; position:relative; z-index:1;
      width:fit-content;
    }
    .fb-queue-badge svg { width:12px; height:12px; fill:none; stroke:rgba(255,255,255,.7); stroke-width:2; }
    .fb-ticket-pill {
      display:inline-flex; align-items:center; gap:6px; margin-top:14px;
      background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.22);
      border-radius:20px; padding:5px 14px; font-size:12px; font-weight:600;
      color:rgba(255,255,255,.9); font-family:'Inter',sans-serif; position:relative; z-index:1;
    }
    .fb-ticket-pill svg { width:12px; height:12px; fill:none; stroke:rgba(255,255,255,.7); stroke-width:2; }

    .fb-body { padding:26px 30px 30px; }

    .fb-timer-notice {
      display:none; align-items:center; gap:10px;
      background:#FFF8EC; border:1px solid #FFD87A; border-radius:10px;
      padding:11px 14px; margin-bottom:22px; font-size:12.5px; color:#7A4F00;
      font-family:'Inter',sans-serif; line-height:1.5;
    }
    .fb-timer-notice svg { width:15px; height:15px; fill:none; stroke:#D97706; stroke-width:2; flex-shrink:0; }
    #fbTimerText { font-weight:700; }

    .fb-rating-question {
      font-family:'Inter',sans-serif; font-size:14px; font-weight:600;
      color:#0D1F3C; margin-bottom:20px; line-height:1.5;
    }

    .fb-emoji-row { display:flex; justify-content:center; gap:10px; margin-bottom:8px; }
    .fb-emoji-btn {
      cursor:pointer; width:58px; height:58px; border-radius:50%;
      border:2.5px solid transparent; background:#f4f6fb; padding:0;
      display:flex; align-items:center; justify-content:center;
      transition:all .22s cubic-bezier(.34,1.26,.64,1); flex-shrink:0; outline:none;
    }
    .fb-emoji-btn svg { width:36px; height:36px; }
    .fb-emoji-btn:hover { transform:scale(1.18) translateY(-4px); box-shadow:0 8px 20px rgba(0,0,0,.13); }
    .fb-emoji-btn[data-val="1"]{--ec:#EF4444;--eb:#FEE2E2;}
    .fb-emoji-btn[data-val="2"]{--ec:#F97316;--eb:#FFEDD5;}
    .fb-emoji-btn[data-val="3"]{--ec:#EAB308;--eb:#FEF9C3;}
    .fb-emoji-btn[data-val="4"]{--ec:#22C55E;--eb:#DCFCE7;}
    .fb-emoji-btn[data-val="5"]{--ec:#16A34A;--eb:#D1FAE5;}
    .fb-emoji-btn.fb-selected {
      background:var(--eb); border-color:var(--ec);
      transform:scale(1.15) translateY(-4px); box-shadow:0 8px 22px rgba(0,0,0,.14);
    }

    .fb-rating-desc {
      text-align:center; font-size:13px; font-weight:600;
      min-height:20px; margin-bottom:22px; transition:color .15s; font-family:'Inter',sans-serif;
    }

    .fb-comment-label { font-family:'Inter',sans-serif; font-size:13px; font-weight:600; color:#374151; margin-bottom:8px; display:block; }
    .fb-textarea {
      width:100%; min-height:90px; border:1.5px solid #dde1ef; border-radius:10px;
      padding:11px 14px; font-size:13.5px; font-family:'Inter',sans-serif; color:#1e2235;
      resize:vertical; outline:none; box-sizing:border-box; transition:border-color .15s,box-shadow .15s; background:#fff;
    }
    .fb-textarea::placeholder { color:#b0b7cc; }
    .fb-textarea:focus { border-color:#185FA5; box-shadow:0 0 0 3px rgba(24,95,165,.08); }

    .fb-footer { display:flex; align-items:center; justify-content:space-between; margin-top:22px; gap:12px; }
    .fb-skip-btn {
      font-size:13px; font-weight:500; color:#9299b0;
      background:none; border:none; cursor:pointer; font-family:'Inter',sans-serif; padding:0; transition:color .15s;
    }
    .fb-skip-btn:hover { color:#5a607a; }
    .fb-submit-btn {
      display:inline-flex; align-items:center; gap:8px; padding:12px 26px; border-radius:10px;
      background:linear-gradient(135deg,#166534,#15803d); color:#fff; font-size:13.5px; font-weight:600;
      font-family:'Inter',sans-serif; border:none; cursor:pointer;
      transition:opacity .18s,transform .15s,box-shadow .15s; box-shadow:0 2px 10px rgba(22,101,52,.28);
    }
    .fb-submit-btn:hover:not(:disabled) { opacity:.9; transform:translateY(-1px); box-shadow:0 6px 20px rgba(22,101,52,.32); }
    .fb-submit-btn:disabled { opacity:.42; cursor:not-allowed; }
    .fb-submit-btn svg { width:14px; height:14px; fill:none; stroke:#fff; stroke-width:2.5; }

    .fb-success {
      display:none; flex-direction:column; align-items:center; justify-content:center;
      padding:56px 32px; text-align:center;
    }
    .fb-success.fb-show { display:flex; animation:fbSuccessIn .35s cubic-bezier(.34,1.26,.64,1); }
    @keyframes fbSuccessIn { from{opacity:0;transform:scale(.8)} to{opacity:1;transform:scale(1)} }
    .fb-success-ring {
      width:80px; height:80px; border-radius:50%;
      background:linear-gradient(135deg,#E6F9EE,#C6F0D6); border:2px solid #A7E3BB;
      display:flex; align-items:center; justify-content:center; margin-bottom:20px;
    }
    .fb-success-ring svg { width:36px; height:36px; fill:none; stroke:#22C55E; stroke-width:2.5; }
    .fb-success-title { font-family:'Inter',sans-serif; font-size:22px; font-weight:700; color:#0D1F3C; margin-bottom:10px; }
    .fb-success-sub   { font-family:'Inter',sans-serif; font-size:14px; color:#7a82a0; line-height:1.65; max-width:300px; }
    .fb-success-next  { margin-top:18px; font-size:13px; color:#185FA5; font-weight:600; font-family:'Inter',sans-serif; display:none; }

    .nd-item .nd-avatar[style*="166534"] { border: 1.5px solid #bbf7d0; }
    .nd-notif-status-open        { color: #92520c; background: #fef3e2; }
    .nd-notif-status-in_progress { color: #1d4ed8; background: #eff6ff; }
    .nd-notif-status-closed      { color: #166534; background: #f0fdf4; }
  </style>
</head>
<body>

<div class="main">

  <!-- ── TOPBAR ── -->
  <header class="topbar">
    <div class="topbar-title">
      <img src="../img/RCMP.png" alt="UniKL RCMP Logo" class="topbar-logo"/>
      <div class="topbar-title-text">
        <span class="topbar-site-title">UniKL RCMP Help Desk</span>

        <?php if (!empty($breadcrumbs)): ?>
        <div class="topbar-breadcrumb">
          <?php foreach ($breadcrumbs as $i => $crumb): ?>
            <?php if ($i > 0): ?><span class="breadcrumb-sep">›</span><?php endif; ?>
            <?php if (isset($crumb['href'])): ?>
              <a href="<?php echo htmlspecialchars($crumb['href']); ?>"><?php echo htmlspecialchars($crumb['label']); ?></a>
            <?php else: ?>
              <span><?php echo htmlspecialchars($crumb['label']); ?></span>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <p><?php echo htmlspecialchars($pageSubtitle ?? date('l, d F Y')); ?></p>
      </div>
    </div>

    <div class="topbar-actions">

      <!-- ── NOTIFICATION BELL ── -->
      <div class="notif-wrapper" id="notifWrapper">
        <button class="notif-btn" id="notifBtn" onclick="toggleNotif(event)" aria-label="Notifications" title="Notifications">
          <svg viewBox="0 0 24 24">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
          </svg>
          <span class="notif-badge" id="notifBadge">0</span>
        </button>
        <div class="notif-dropdown" id="notifDropdown">
          <div class="nd-header">
            <h3>Notifications</h3>
            <div class="nd-header-right">
              <span class="nd-count-pill" id="ndCountPill">0 new</span>
              <button class="nd-mark-read" onclick="markAllRead()" title="Mark all as read">Mark all read</button>
            </div>
          </div>
          <div class="nd-list" id="ndList">
            <?php for ($s = 0; $s < 3; $s++): ?>
            <div class="nd-skeleton">
              <div class="nd-skel-avatar"></div>
              <div class="nd-skel-lines">
                <div class="nd-skel-line"></div>
                <div class="nd-skel-line short"></div>
                <div class="nd-skel-line shorter"></div>
              </div>
            </div>
            <?php endfor; ?>
          </div>
        </div>
      </div>

      <!-- ── USER CHIP ── -->
      <div class="user-chip-wrapper" id="userChipWrapper">
        <div class="user-chip" id="userChip" onclick="toggleUserDropdown(event)">
          <div class="chip-avatar"><?php echo $_initials; ?></div>
          <span class="chip-name"><?php echo htmlspecialchars($_firstName); ?></span>
          <svg class="chip-caret" id="chipCaret" viewBox="0 0 24 24">
            <polyline points="6 9 12 15 18 9"/>
          </svg>
        </div>
        <div class="user-dropdown" id="userDropdown">
          <div class="ud-header">
            <div class="ud-avatar"><?php echo $_initials; ?></div>
            <div class="ud-info">
              <div class="ud-name"><?php echo htmlspecialchars($userName ?? ''); ?></div>
              <div class="ud-role"><?php echo htmlspecialchars($userEmail ?? ''); ?></div>
            </div>
          </div>
          <div class="ud-divider"></div>
          <a href="logout.php" class="ud-item ud-logout">
            <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Log out
          </a>
        </div>
      </div>

    </div>
  </header>

  <!-- ── PAGE CONTENT START ── -->
  <div class="content">

<script>
/* ── Notification Bell ── */
(function () {
  const STORAGE_KEY = 'unikl_notif_last_seen';
  function getLastSeen(){return parseInt(localStorage.getItem(STORAGE_KEY)||'0',10);}
  function setLastSeen(id){localStorage.setItem(STORAGE_KEY,String(id));}
  let allNotifications=[],isOpen=false,fetched=false;
  const wrapper=document.getElementById('notifWrapper'),btn=document.getElementById('notifBtn'),
        badge=document.getElementById('notifBadge'),list=document.getElementById('ndList'),
        countPill=document.getElementById('ndCountPill');
  function timeAgo(s){const past=new Date(s.replace(' ','T')),now=new Date(),d=Math.floor((now-past)/1000);if(d<60)return 'just now';if(d<3600)return Math.floor(d/60)+' min ago';if(d<86400)return Math.floor(d/3600)+' hr ago';if(d<604800){const dy=Math.floor(d/86400);return dy+' day'+(dy>1?'s':'')+' ago';}return past.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'});}
  function getInitials(n){const p=n.trim().split(' ');let i=p[0][0]||'?';if(p.length>1)i+=p[p.length-1][0];return i.toUpperCase();}
  function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
  function truncate(s,l){return s.length>l?s.slice(0,l)+'…':s;}
  function renderList(n){
    if(!n.length){
      list.innerHTML=`<div class="nd-empty"><div class="nd-empty-icon"><svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg></div><h4>No notifications</h4><p>Replies and status updates will appear here.</p></div>`;
      return;
    }
    const lastSeen=getLastSeen();
    let html='';
    n.forEach(function(x){
      const unread=parseInt(x.reply_id,10)>lastSeen;
      const ini=getInitials(x.sender_name||'?');
      const url='my_ticket_detail.php?id='+encodeURIComponent(x.ticket_id);
      const isStatus = x.notif_type === 'status';
      const avatarStyle = isStatus
        ? 'background:linear-gradient(135deg,#166534,#22c55e);'
        : 'background:linear-gradient(135deg,var(--blue,#003B8E),#4A90D9);';
      const actionLabel = isStatus ? 'updated' : 'replied on';
      html+=`<a class="nd-item${unread?' unread':''}" href="${esc(url)}" data-reply-id="${parseInt(x.reply_id,10)}">
        <div class="nd-avatar" style="${avatarStyle}">${esc(ini)}</div>
        <div class="nd-content">
          <div class="nd-top">
            <span class="nd-sender">${esc(x.sender_name)}</span>
            <span class="nd-action">${actionLabel}</span>
            <span class="nd-ticket">${esc(x.ticket_id)}</span>
          </div>
          <div class="nd-message">${esc(truncate(x.message||'',70))}</div>
          <div class="nd-time">${esc(truncate(x.ticket_title||'',38))} &bull; ${timeAgo(x.created_at)}</div>
        </div>
        ${unread?'<div class="nd-unread-dot"></div>':''}
      </a>`;
    });
    list.innerHTML=html;
    list.querySelectorAll('.nd-item[data-reply-id]').forEach(function(item){
      item.addEventListener('click', function(){
        var rid = parseInt(item.dataset.replyId, 10);
        var lastSeen = getLastSeen();
        if(rid > lastSeen){
          setLastSeen(rid);
          var newCount = allNotifications.filter(function(x){return parseInt(x.reply_id,10) > rid;}).length;
          countPill.textContent = newCount+' new';
          if(newCount > 0){badge.textContent = newCount > 99 ? '99+' : String(newCount);badge.classList.add('show');}
          else{badge.classList.remove('show');}
          list.querySelectorAll('.nd-item').forEach(function(el){
            var elRid = parseInt(el.dataset.replyId, 10);
            if(elRid <= rid){el.classList.remove('unread');var dot=el.querySelector('.nd-unread-dot');if(dot)dot.remove();}
          });
        }
      });
    });
  }
  function updateBadge(n){const lastSeen=getLastSeen(),cnt=n.filter(function(x){return parseInt(x.reply_id,10)>lastSeen;}).length;countPill.textContent=cnt+' new';if(cnt>0){badge.textContent=cnt>99?'99+':String(cnt);badge.classList.add('show');}else{badge.classList.remove('show');}}
  function fetchNotifications(){fetch('notifications_api.php',{credentials:'same-origin'}).then(function(r){if(!r.ok)throw new Error('HTTP '+r.status);return r.json();}).then(function(data){allNotifications=data.notifications||[];fetched=true;updateBadge(allNotifications);if(isOpen)renderList(allNotifications);}).catch(function(){if(isOpen)list.innerHTML=`<div class="nd-empty"><div class="nd-empty-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div><h4>Could not load</h4><p>Check your connection and try again.</p></div>`; });}
  window.toggleNotif=function(e){e.stopPropagation();var uw=document.getElementById('userChipWrapper');if(uw&&uw.classList.contains('open')){uw.classList.remove('open');var c=document.getElementById('chipCaret');if(c)c.style.transform='rotate(0deg)';}isOpen=!isOpen;wrapper.classList.toggle('open',isOpen);btn.classList.toggle('active',isOpen);if(isOpen){if(!fetched)fetchNotifications();else renderList(allNotifications);}};
  window.markAllRead=function(){if(!allNotifications.length)return;const maxId=Math.max.apply(null,allNotifications.map(function(n){return parseInt(n.reply_id,10);}));setLastSeen(maxId);badge.classList.remove('show');countPill.textContent='0 new';renderList(allNotifications);};
  document.addEventListener('click',function(e){if(isOpen&&!wrapper.contains(e.target)){isOpen=false;wrapper.classList.remove('open');btn.classList.remove('active');}});
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() { fetchNotifications(); setInterval(fetchNotifications, 60000); });
  } else {
    fetchNotifications(); setInterval(fetchNotifications, 60000);
  }
})();
</script>

<!-- ══════════════════════════════════════════════════════
     CHAT MESSAGE POPUP TOAST
══════════════════════════════════════════════════════ -->
<div id="replyToast" style="
  display:none; position:fixed; bottom:28px; right:28px; z-index:9999;
  width:340px; background:#fff; border-radius:16px;
  box-shadow:0 12px 40px rgba(0,30,80,.18), 0 2px 8px rgba(0,0,0,.08);
  border:1px solid #e5e7eb; overflow:hidden;
  animation:toastSlideIn .35s cubic-bezier(.34,1.26,.64,1);
  font-family:'Inter',sans-serif;
">
  <div style="
    background:linear-gradient(135deg,#0D1F3C,#1a3a6e);
    padding:12px 16px; display:flex; align-items:center; gap:10px;
  ">
    <div style="
      width:36px; height:36px; border-radius:50%; flex-shrink:0;
      background:linear-gradient(135deg,#003B8E,#4A90D9);
      display:flex; align-items:center; justify-content:center;
      font-size:13px; font-weight:700; color:#fff;
    " id="replyToastInitials">?</div>
    <div style="flex:1; min-width:0;">
      <div style="font-size:13px; font-weight:600; color:#fff; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" id="replyToastSender">Staff</div>
      <div style="font-size:11px; color:rgba(255,255,255,.6); margin-top:1px;" id="replyToastTicket"></div>
<div id="replyToastStatusBadge" style="
  display:none; margin-top:4px;
  font-size:10px; font-weight:700; letter-spacing:0.05em; text-transform:uppercase;
  background:rgba(255,255,255,0.15); color:#fff;
  padding:2px 8px; border-radius:20px; width:fit-content;
"></div>
      
    </div>
    <button onclick="closeReplyToast()" style="
      background:rgba(255,255,255,.15); border:none; border-radius:6px;
      width:26px; height:26px; cursor:pointer; display:flex;
      align-items:center; justify-content:center; flex-shrink:0;
    ">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
  </div>
  <div style="padding:14px 16px;">
    <div style="font-size:13px; color:#374151; line-height:1.5; margin-bottom:12px; max-height:60px; overflow:hidden;" id="replyToastMsg"></div>
    <a id="replyToastLink" href="#" style="
      display:inline-flex; align-items:center; gap:6px;
      background:#003B8E; color:#fff; font-size:12px; font-weight:600;
      padding:8px 16px; border-radius:8px; text-decoration:none;
      transition:opacity .15s;
    " onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
      View Ticket
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
    </a>
  </div>
</div>

<style>
@keyframes toastSlideIn {
  from { opacity:0; transform:translateY(20px) scale(.95); }
  to   { opacity:1; transform:translateY(0)    scale(1);   }
}
</style>

<script>
(function () {
  var POPUP_API    = 'new_reply_popup_api.php';
  var SEEN_KEY     = 'unikl_toast_seen_reply_';   // + reply_id
  var SEEN_LOG_KEY = 'unikl_toast_seen_log_';     // + log_id
  var toast        = document.getElementById('replyToast');
  var autoClose    = null;

  var STATUS_COLORS = {
    open:        '#D97706',
    in_progress: '#2563EB',
    closed:      '#166534',
  };
  var STATUS_ICONS = {
    open:        '📂',
    in_progress: '🔧',
    closed:      '✅',
  };

  function getInitials(name) {
    var p = (name || '?').trim().split(' ');
    var i = (p[0][0] || '?');
    if (p.length > 1) i += (p[p.length - 1][0] || '');
    return i.toUpperCase();
  }

  function truncate(s, n) {
    return s && s.length > n ? s.slice(0, n) + '…' : (s || '');
  }

  /* Check if this specific event was already shown */
  function alreadySeen(data) {
    try {
      if (data.type === 'status') {
        return localStorage.getItem(SEEN_LOG_KEY + data.log_id) === '1';
      } else {
        return localStorage.getItem(SEEN_KEY + data.reply_id) === '1';
      }
    } catch(e) { return false; }
  }

  /* Mark this specific event as shown */
  function markSeen(data) {
    try {
      if (data.type === 'status') {
        localStorage.setItem(SEEN_LOG_KEY + data.log_id, '1');
      } else {
        localStorage.setItem(SEEN_KEY + data.reply_id, '1');
      }
    } catch(e) {}
  }

  function showToast(data) {
    /* If already shown before, skip silently */
    if (alreadySeen(data)) return;

    var isStatus = data.type === 'status';
    var avatarEl = document.getElementById('replyToastInitials');
    var senderEl = document.getElementById('replyToastSender');
    var ticketEl = document.getElementById('replyToastTicket');
    var msgEl    = document.getElementById('replyToastMsg');
    var linkEl   = document.getElementById('replyToastLink');
    var headerEl = toast.querySelector('div[style*="linear-gradient"]');
    var badgeEl  = document.getElementById('replyToastStatusBadge');

    /* Avatar & header colour */
    if (isStatus) {
      var color = STATUS_COLORS[data.new_status] || '#374151';
      avatarEl.textContent      = STATUS_ICONS[data.new_status] || '🔔';
      avatarEl.style.background = color;
      avatarEl.style.fontSize   = '18px';
      headerEl.style.background = 'linear-gradient(135deg, #1e3a5f, ' + color + ')';
    } else {
      avatarEl.textContent      = getInitials(data.sender_name);
      avatarEl.style.background = 'linear-gradient(135deg,#003B8E,#4A90D9)';
      avatarEl.style.fontSize   = '13px';
      headerEl.style.background = 'linear-gradient(135deg,#0D1F3C,#1a3a6e)';
    }

    /* Text */
    senderEl.textContent = data.sender_name;
    ticketEl.textContent = truncate(
      data.ticket_id + (data.ticket_title ? ' — ' + data.ticket_title : ''), 40
    );
    msgEl.textContent = truncate(data.message, 100);
    linkEl.href = 'my_ticket_detail.php?id=' + encodeURIComponent(data.ticket_id);

    /* Status badge */
    if (isStatus) {
      badgeEl.style.display = 'inline-block';
      badgeEl.textContent   = data.old_status.replace('_',' ') + ' → ' + data.new_status.replace('_',' ');
    } else {
      badgeEl.style.display = 'none';
    }

    /* Mark seen immediately — won't show again even if poll fires */
    markSeen(data);

    /* Mark seen on server too */
    if (isStatus) {
      fetch(POPUP_API + '?action=mark&log_id=' + data.log_id, { credentials: 'same-origin' });
    } else {
      fetch(POPUP_API + '?action=mark&reply_id=' + data.reply_id, { credentials: 'same-origin' });
    }

    /* Show — no timer, no auto-close */
    clearTimeout(autoClose);
    toast.style.display = 'block';
  }

  window.closeReplyToast = function () {
    clearTimeout(autoClose);
    toast.style.display = 'none';
  };

  function poll() {
    fetch(POPUP_API, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) { if (data.has_new) showToast(data); })
      .catch(function () {});
  }

  setTimeout(poll, 3000);
  setInterval(poll, 30000);
})();
</script>

<?php if ($_fbUserId > 0): ?>
<!-- ══════════════════════════════════════════════════════════════
     FEEDBACK POPUP
     Only shown for tickets WITHIN the 8 office-hour window.
     Expired tickets are silently auto-submitted 5/5 server-side
     by feedback_api.php and never trigger this popup.
══════════════════════════════════════════════════════════════ -->
<div id="fbOverlay" role="dialog" aria-modal="true" aria-labelledby="fbModalTitle">
  <div id="fbModal">

    <button class="fb-close-x" id="fbCloseX" type="button" aria-label="Close">
      <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>

    <div class="fb-header">
      <div class="fb-header-icon">
        <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
      </div>
      <div class="fb-header-title" id="fbModalTitle">How was your Help Desk experience?</div>
      <div class="fb-header-sub">Your feedback helps us serve you better. Takes less than a minute.</div>
      <div class="fb-queue-badge" id="fbQueueBadge">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        <span id="fbQueueText">2 tickets need feedback</span>
      </div>
      <div class="fb-ticket-pill">
        <svg viewBox="0 0 24 24"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        <span id="fbTicketId">—</span>
      </div>
    </div>

    <div class="fb-body" id="fbBody">
      <!-- Countdown timer: shown while ticket is within the 8 office-hour window -->
      <div class="fb-timer-notice" id="fbTimerNotice">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        <span>Auto-submits 5/5 in <strong><span id="fbTimerText">…</span></strong> if you skip</span>
      </div>

      <div class="fb-rating-question">Rate your overall experience with this ticket:</div>

      <div class="fb-emoji-row" id="fbEmojiRow" role="group" aria-label="Satisfaction rating">
        <!-- 1 — Very Dissatisfied -->
        <button class="fb-emoji-btn" data-val="1" type="button" aria-label="Very dissatisfied">
          <svg viewBox="0 0 48 48" fill="none">
            <circle cx="24" cy="24" r="22" stroke="#EF4444" stroke-width="2.5" fill="#FEE2E2"/>
            <circle cx="17" cy="20" r="2.5" fill="#EF4444"/>
            <circle cx="31" cy="20" r="2.5" fill="#EF4444"/>
            <path d="M16 33c2-4 14-4 16 0" stroke="#EF4444" stroke-width="2.5" stroke-linecap="round"/>
            <path d="M15 15l4 3M33 15l-4 3" stroke="#EF4444" stroke-width="2" stroke-linecap="round"/>
          </svg>
        </button>
        <!-- 2 — Dissatisfied -->
        <button class="fb-emoji-btn" data-val="2" type="button" aria-label="Dissatisfied">
          <svg viewBox="0 0 48 48" fill="none">
            <circle cx="24" cy="24" r="22" stroke="#F97316" stroke-width="2.5" fill="#FFEDD5"/>
            <circle cx="17" cy="20" r="2.5" fill="#F97316"/>
            <circle cx="31" cy="20" r="2.5" fill="#F97316"/>
            <path d="M17 32c2-3 12-3 14 0" stroke="#F97316" stroke-width="2.5" stroke-linecap="round"/>
          </svg>
        </button>
        <!-- 3 — Neutral -->
        <button class="fb-emoji-btn" data-val="3" type="button" aria-label="Neutral">
          <svg viewBox="0 0 48 48" fill="none">
            <circle cx="24" cy="24" r="22" stroke="#EAB308" stroke-width="2.5" fill="#FEF9C3"/>
            <circle cx="17" cy="20" r="2.5" fill="#EAB308"/>
            <circle cx="31" cy="20" r="2.5" fill="#EAB308"/>
            <line x1="17" y1="32" x2="31" y2="32" stroke="#EAB308" stroke-width="2.5" stroke-linecap="round"/>
          </svg>
        </button>
        <!-- 4 — Satisfied -->
        <button class="fb-emoji-btn" data-val="4" type="button" aria-label="Satisfied">
          <svg viewBox="0 0 48 48" fill="none">
            <circle cx="24" cy="24" r="22" stroke="#22C55E" stroke-width="2.5" fill="#DCFCE7"/>
            <circle cx="17" cy="20" r="2.5" fill="#22C55E"/>
            <circle cx="31" cy="20" r="2.5" fill="#22C55E"/>
            <path d="M16 28c2 4 14 4 16 0" stroke="#22C55E" stroke-width="2.5" stroke-linecap="round"/>
          </svg>
        </button>
        <!-- 5 — Very Satisfied -->
        <button class="fb-emoji-btn" data-val="5" type="button" aria-label="Very satisfied">
          <svg viewBox="0 0 48 48" fill="none">
            <circle cx="24" cy="24" r="22" stroke="#16A34A" stroke-width="2.5" fill="#D1FAE5"/>
            <circle cx="17" cy="19" r="2.5" fill="#16A34A"/>
            <circle cx="31" cy="19" r="2.5" fill="#16A34A"/>
            <path d="M14 27c2 6 18 6 20 0" stroke="#16A34A" stroke-width="2.5" stroke-linecap="round"/>
          </svg>
        </button>
      </div>

      <div class="fb-rating-desc" id="fbRatingDesc" style="color:#9299b0;">Click a face to rate</div>

      <label class="fb-comment-label" for="fbComment">
        What could be improved? <span style="font-weight:400;color:#9299b0;">(optional)</span>
      </label>
      <textarea id="fbComment" class="fb-textarea" placeholder="Share any thoughts about the response time, staff helpfulness, or anything else…" maxlength="1000"></textarea>

      <div class="fb-footer">
        <button class="fb-skip-btn" id="fbSkipBtn" type="button">Skip for now</button>
        <button class="fb-submit-btn" id="fbSubmitBtn" type="button" disabled>
          Submit Feedback
          <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
      </div>
    </div>

    <div class="fb-success" id="fbSuccess">
      <div class="fb-success-ring">
        <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
      </div>
      <div class="fb-success-title">Thank you! 🎉</div>
      <div class="fb-success-sub">Your feedback has been recorded and will help us improve our Help Desk service.</div>
      <div class="fb-success-next" id="fbSuccessNext">Loading next ticket…</div>
    </div>

  </div>
</div>

<script>
(function () {
  'use strict';

  /*
   * SESSION KEY — per user + PHP session ID.
   * Skip / X / ESC → sessionStorage flag → hidden for rest of this login session.
   * New login = new session_id() = fresh key = popup can show again.
   */
  var SESSION_KEY = 'fb_skipped_u<?php echo (int)$_fbUserId; ?>_<?php echo session_id(); ?>';

  try { if (sessionStorage.getItem(SESSION_KEY)) return; } catch(e){}

  var FB_API_URL = '<?php echo addslashes($_fbApiUrl); ?>';

  /* ── State ── */
  var currentTicketId = null;
  var selectedRating  = 0;
  var countdownTimer  = null;
  var remainingSecs   = 0;
  var isShown         = false;

  /* ── DOM refs ── */
  var overlay     = document.getElementById('fbOverlay');
  var ticketIdEl  = document.getElementById('fbTicketId');
  var queueBadge  = document.getElementById('fbQueueBadge');
  var queueText   = document.getElementById('fbQueueText');
  var timerNotice = document.getElementById('fbTimerNotice');
  var timerText   = document.getElementById('fbTimerText');
  var ratingDesc  = document.getElementById('fbRatingDesc');
  var commentEl   = document.getElementById('fbComment');
  var submitBtn   = document.getElementById('fbSubmitBtn');
  var skipBtn     = document.getElementById('fbSkipBtn');
  var closeXBtn   = document.getElementById('fbCloseX');
  var bodyEl      = document.getElementById('fbBody');
  var successEl   = document.getElementById('fbSuccess');
  var successNext = document.getElementById('fbSuccessNext');

  var DESCS  = ['','Very Dissatisfied','Dissatisfied','Neutral','Satisfied','Very Satisfied'];
  var COLORS = ['','#EF4444','#F97316','#EAB308','#22C55E','#16A34A'];

  /* ── Helpers ── */
  function clearCountdown() {
    if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
  }

  function updateCountdownDisplay() {
    if (remainingSecs <= 0) { timerText.textContent = '0 min'; return; }
    var h = Math.floor(remainingSecs / 3600);
    var m = Math.floor((remainingSecs % 3600) / 60);
    timerText.textContent = h > 0
      ? h + 'h ' + (m > 0 ? m + 'm' : '')
      : m + ' min' + (m !== 1 ? 's' : '');
  }

  function startCountdown() {
    clearCountdown();
    countdownTimer = setInterval(function () {
      remainingSecs--;
      if (remainingSecs <= 0) {
        /*
         * Countdown hit zero client-side.
         * Close the popup silently — do NOT auto-submit from JS.
         * The server will handle the auto-submit next time checkPending() runs
         * (i.e. on next page load or next 2-second check).
         */
        clearCountdown();
        timerNotice.style.display = 'none';
        closePopup();
      } else {
        updateCountdownDisplay();
      }
    }, 1000);
  }

  function showPopup(ticketId, ticketLabel, remainSecs, pendingCount) {
    clearCountdown();
    currentTicketId = ticketId;
    selectedRating  = 0;
    submitBtn.disabled = true;

    document.querySelectorAll('.fb-emoji-btn').forEach(function (b) {
      b.classList.remove('fb-selected');
    });
    ratingDesc.textContent = 'Click a face to rate';
    ratingDesc.style.color = '#9299b0';
    commentEl.value = '';

    bodyEl.style.display = '';
    successEl.classList.remove('fb-show');
    successNext.style.display = 'none';

    ticketIdEl.textContent = ticketLabel;

    if (pendingCount > 1) {
      queueText.textContent = pendingCount + ' tickets need feedback';
      queueBadge.style.display = 'inline-flex';
    } else {
      queueBadge.style.display = 'none';
    }

    /* Always show countdown — server already silently handled all expired tickets */
    remainingSecs = remainSecs || 0;
    timerNotice.style.display = 'flex';
    updateCountdownDisplay();
    startCountdown();

    isShown = true;
    overlay.classList.add('fb-active');
    document.body.style.overflow = 'hidden';
  }

  function closePopup() {
    clearCountdown();
    isShown = false;
    overlay.classList.remove('fb-active');
    document.body.style.overflow = '';
    try { sessionStorage.setItem(SESSION_KEY, '1'); } catch(e){}
  }

  function setRating(val) {
    selectedRating = val;
    document.querySelectorAll('.fb-emoji-btn').forEach(function (b) {
      b.classList.toggle('fb-selected', parseInt(b.dataset.val, 10) === val);
    });
    ratingDesc.textContent = DESCS[val] || '';
    ratingDesc.style.color = COLORS[val] || '#9299b0';
    submitBtn.disabled = false;
  }

  document.querySelectorAll('.fb-emoji-btn').forEach(function (b) {
    b.addEventListener('click', function () {
      setRating(parseInt(b.dataset.val, 10));
    });
  });

  /* ── Submit ── */
  function submitFeedback() {
    if (!currentTicketId || selectedRating < 1) return;
    clearCountdown();

    submitBtn.disabled    = true;
    submitBtn.textContent = 'Submitting…';

    var fd = new FormData();
    fd.append('action',    'submit');
    fd.append('ticket_id', currentTicketId);
    fd.append('rating',    String(selectedRating));
    fd.append('comment',   commentEl.value.trim());
    fd.append('auto',      '0');

    fetch(FB_API_URL, { method:'POST', credentials:'same-origin', body:fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.success) {
          var remaining = parseInt(data.remaining_count, 10) || 0;

          bodyEl.style.display = 'none';
          successEl.classList.add('fb-show');

          if (remaining > 0) {
            successNext.textContent = remaining + ' more ticket' + (remaining > 1 ? 's' : '') + ' need feedback. Loading next…';
            successNext.style.display = 'block';
            setTimeout(function () {
              var modal = document.getElementById('fbModal');
              modal.style.animation = 'none';
              requestAnimationFrame(function () {
                modal.style.animation = '';
                checkPending();
              });
            }, 2200);
          } else {
            setTimeout(function () {
              overlay.classList.remove('fb-active');
              document.body.style.overflow = '';
              isShown = false;
            }, 2600);
          }
        } else {
          submitBtn.disabled  = false;
          submitBtn.innerHTML = 'Submit Feedback <svg viewBox="0 0 24 24" style="width:14px;height:14px;fill:none;stroke:#fff;stroke-width:2.5"><polyline points="9 18 15 12 9 6"/></svg>';
        }
      })
      .catch(function () {
        submitBtn.disabled  = false;
        submitBtn.innerHTML = 'Submit Feedback <svg viewBox="0 0 24 24" style="width:14px;height:14px;fill:none;stroke:#fff;stroke-width:2.5"><polyline points="9 18 15 12 9 6"/></svg>';
      });
  }

  /* ── Check for pending ticket(s) ── */
  function checkPending() {
    fetch(FB_API_URL + '?action=check', { credentials:'same-origin' })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (data) {
        if (!data.pending) return;
        if (!data.ticket_id) return;

        var ticketId = data.ticket_id;
        var label    = ticketId;
        if (data.ticket_title) {
          var t = data.ticket_title;
          label += ' — ' + (t.length > 36 ? t.slice(0, 34) + '…' : t);
        }

        showPopup(
          ticketId,
          label,
          data.remaining_secs || 0,
          parseInt(data.pending_count, 10) || 1
        );
      })
      .catch(function (err) {
        console.warn('[Feedback] checkPending error:', err);
      });
  }

  /* ── Wire up buttons ── */
  submitBtn.addEventListener('click',  submitFeedback);
  skipBtn.addEventListener('click',    closePopup);
  closeXBtn.addEventListener('click',  closePopup);
  overlay.addEventListener('click',    function (e) { if (e.target === overlay) closePopup(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && isShown) closePopup(); });

  /* 2-second delay so the page renders fully before the API call */
  setTimeout(checkPending, 2000);
})();
</script>
<?php endif; /* end $_fbUserId > 0 */ ?>