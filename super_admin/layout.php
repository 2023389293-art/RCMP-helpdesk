<?php
// super_admin/layout.php — Shared sidebar layout for Super Admin
if (!isset($activePage)) $activePage = '';
$staffName = $_SESSION['staff_name'] ?? 'Super Admin';

$navItems = [
    'dashboard'  => ['label' => 'Dashboard',      'icon' => 'grid',    'href' => 'dashboard.php'],
    'tickets'    => ['label' => 'All Tickets',     'icon' => 'ticket',  'href' => 'tickets.php'],
    'staff'      => ['label' => 'Manage Staff',    'icon' => 'users',   'href' => 'manage_staff.php'],
    'admins'     => ['label' => 'Manage Admins',   'icon' => 'shield',  'href' => 'manage_admins.php'],
    'reports'    => ['label' => 'Reports',         'icon' => 'chart',   'href' => 'reports.php'],
];

function navIcon(string $name): string {
    $icons = [
        'grid'   => '<svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>',
        'ticket' => '<svg viewBox="0 0 24 24"><path d="M2 9a1 1 0 0 1 1-1h18a1 1 0 0 1 1 1v2a2 2 0 0 0 0 4v2a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1v-2a2 2 0 0 0 0-4V9z"/><line x1="9" y1="8" x2="9" y2="16"/></svg>',
        'shield' => '<svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
        'users'  => '<svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'tag'    => '<svg viewBox="0 0 24 24"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>',
        'chart'  => '<svg viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/><line x1="2" y1="20" x2="22" y2="20"/></svg>',
    ];
    return $icons[$name] ?? '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) . ' | ' : ''; ?>Super Admin | UniKL Help Desk</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --maroon:        #7D1128;
      --maroon-dark:   #5C0D1E;
      --maroon-deeper: #3E0813;
      --maroon-mid:    #A8304A;
      --maroon-light:  #C85470;
      --maroon-pale:   rgba(255,255,255,0.07);
      --maroon-border: rgba(255,255,255,0.10);
      --gold:          #E8B84B;
      --gold-dim:      rgba(232,184,75,0.15);
      --white:         #FFFFFF;
      --off-white:     #F7F8FA;
      --gray-100:      #EEF0F5;
      --gray-200:      #DDE1ED;
      --gray-300:      #BDC3D4;
      --gray-500:      #7A8399;
      --gray-700:      #3D4560;
      --gray-900:      #1A2038;
      --text:          #111827;
      --sidebar-w:     240px;
    }

    html, body { height: 100%; font-family: 'DM Sans', sans-serif; color: var(--text); }
    body { display: flex; background: var(--off-white); }

    /* ── SIDEBAR ── */
    .sidebar {
      width: var(--sidebar-w);
      min-height: 100vh;
      background: var(--maroon-deeper);
      display: flex; flex-direction: column;
      position: fixed; top: 0; left: 0; bottom: 0;
      z-index: 50; overflow: hidden;
    }
    .sidebar::before {
      content: ''; position: absolute; inset: 0;
      background-image:
        linear-gradient(rgba(255,255,255,.03) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,.03) 1px, transparent 1px);
      background-size: 24px 24px; pointer-events: none;
    }
    .sidebar::after {
      content: ''; position: absolute; top: -80px; left: -60px;
      width: 300px; height: 300px; border-radius: 50%;
      background: radial-gradient(circle, rgba(168,48,74,.4) 0%, transparent 70%);
      pointer-events: none;
    }
    .sidebar-inner { position: relative; z-index: 1; display: flex; flex-direction: column; height: 100%; }

    .sidebar-brand {
      padding: 18px 20px 16px;
      border-bottom: 1px solid var(--maroon-border);
      display: flex; align-items: center; gap: 11px; text-decoration: none;
    }
    .brand-logo { width: 65px; height: 60px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; }
    .brand-logo img { width: 100%; height: 100%; object-fit: contain; filter: drop-shadow(0 1px 3px rgba(0,0,0,.4)); }
    .brand-text .top    { font-size: 9.5px; color: rgba(255,255,255,.4); letter-spacing: .08em; text-transform: uppercase; }
    .brand-text .bottom { font-size: 13px; font-weight: 600; color: white; line-height: 1.3; }

    .sa-badge {
      margin: 12px 16px 8px; padding: 7px 12px; border-radius: 8px;
      background: var(--gold-dim); border: 1px solid rgba(232,184,75,.25);
      display: flex; align-items: center; gap: 8px;
    }
    .sa-badge svg { width: 13px; height: 13px; fill: none; stroke: var(--gold); stroke-width: 2; flex-shrink: 0; }
    .sa-badge span { font-size: 11px; font-weight: 600; color: var(--gold); letter-spacing: .04em; text-transform: uppercase; }

    .nav-list { list-style: none; padding: 10px 10px 0; }
    .nav-list li { margin-bottom: 2px; }
    .nav-divider { height: 1px; background: var(--maroon-border); margin: 6px 10px; }

    .nav-link {
      display: flex; align-items: center; gap: 11px;
      padding: 10px 12px; border-radius: 8px; text-decoration: none;
      font-size: 13.5px; font-weight: 500; color: rgba(255,255,255,.65);
      transition: background .18s, color .18s; position: relative;
    }
    .nav-link svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 1.8; flex-shrink: 0; }
    .nav-link:hover { background: var(--maroon-pale); color: white; }
    .nav-link.active { background: var(--maroon-mid); color: white; box-shadow: 0 2px 10px rgba(0,0,0,.2); }
    .nav-link.active::before {
      content: ''; position: absolute; left: 0; top: 50%; transform: translateY(-50%);
      width: 3px; height: 60%; border-radius: 0 3px 3px 0; background: var(--gold);
    }

    .sidebar-user {
      margin-top: auto; border-top: 1px solid var(--maroon-border);
      padding: 14px; display: flex; align-items: center; gap: 10px;
    }
    .user-avatar {
      width: 34px; height: 34px; border-radius: 8px; background: var(--maroon-mid);
      display: flex; align-items: center; justify-content: center;
      font-size: 14px; font-weight: 700; color: white; flex-shrink: 0;
    }
    .user-info { flex: 1; overflow: hidden; }
    .user-name { font-size: 12.5px; font-weight: 600; color: white; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .user-role { font-size: 10.5px; color: rgba(255,255,255,.4); letter-spacing: .03em; }
    .logout-btn {
      width: 28px; height: 28px; border-radius: 7px;
      background: rgba(255,255,255,.06); border: 1px solid var(--maroon-border);
      display: flex; align-items: center; justify-content: center;
      color: rgba(255,255,255,.5); text-decoration: none;
      transition: background .18s, color .18s; flex-shrink: 0;
    }
    .logout-btn svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; }
    .logout-btn:hover { background: rgba(255,255,255,.12); color: white; }

    /* ── MAIN CONTENT ── */
    .main-content { margin-left: var(--sidebar-w); flex: 1; min-width: 0; min-height: 100vh; display: flex; flex-direction: column; overflow-x: hidden; }

    /* ── TOP BAR — font sizes increased to match tickets.php ── */
    .topbar {
      height: 65px; background: white;
      border-bottom: 1px solid var(--gray-200);
      display: flex; align-items: center; justify-content: space-between;
      padding: 0 32px; position: sticky; top: 0; z-index: 40;
    }
    .topbar-breadcrumb {
      display: flex; align-items: center; gap: 8px;
      font-size: 15px;          /* ← was 13px */
      color: var(--gray-500);
    }
    .topbar-breadcrumb .sep     { color: var(--gray-300); }
    .topbar-breadcrumb .current { font-weight: 600; color: var(--gray-900); font-size: 15px; }
    .topbar-right { display: flex; align-items: center; gap: 10px; }
    .topbar-date {
      font-size: 14px;          /* ← was 12.5px */
      color: var(--gray-500);
      display: flex; align-items: center; gap: 6px;
    }
    .topbar-date svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2; }

    /* Page area */
    .page-body { padding: 32px; flex: 1; overflow-x: hidden; }

    /* ── Mobile ── */
    .sidebar-overlay {
      display: none;
      position: fixed; inset: 0;
      background: rgba(0,0,0,0.45);
      z-index: 49;
    }
    .sidebar-overlay.active { display: block; }

    .hamburger-btn {
      display: none;
      background: none; border: none; cursor: pointer;
      color: var(--gray-700); padding: 6px; border-radius: 8px;
      align-items: center; justify-content: center;
      transition: background .15s;
    }
    .hamburger-btn:hover { background: var(--gray-100); }

    @media (max-width: 768px) {
      .sidebar {
        transform: translateX(-100%);
        transition: transform .3s ease;
        z-index: 50;
      }
      .sidebar.open { transform: translateX(0); }
      .main-content { margin-left: 0; }
      .page-body { padding: 20px 16px; overflow-x: hidden; }
      .hamburger-btn { display: flex; }
      .topbar { padding: 0 16px; }
      .topbar-breadcrumb { gap: 6px; }
    }
  </style>
</head>
<body>

<aside class="sidebar" id="sidebar">
  <div class="sidebar-inner">

    <a class="sidebar-brand" href="dashboard.php">
      <div class="brand-logo">
        <img src="../img/RCMP.png" alt="UniKL RCMP Logo"/>
      </div>
      <div class="brand-text">
        <div class="top">UniKL RCMP</div>
        <div class="bottom">Help Desk</div>
      </div>
    </a>


    <br>

    <ul class="nav-list">
  <li>
    <a href="dashboard.php" class="nav-link <?= $activePage === 'dashboard' ? 'active' : '' ?>">
      <?= navIcon('grid') ?> Dashboard
    </a>
  </li>
</ul>

<?php if ($_SESSION['staff_role'] !== 'report_viewer'): ?>
    <ul class="nav-list">
      <li>
        <a href="manage_staff.php" class="nav-link <?= $activePage === 'staff' ? 'active' : '' ?>">
          <?= navIcon('users') ?> Manage Users
        </a>
      </li>
      <li>
        <a href="manage_admins.php" class="nav-link <?= $activePage === 'admins' ? 'active' : '' ?>">
          <?= navIcon('shield') ?> Manage Admins
        </a>
      </li>
    </ul>
<?php endif; ?>

    

    <ul class="nav-list">
      
      <li>
        <a href="reports.php" class="nav-link <?= $activePage === 'reports' ? 'active' : '' ?>">
          <?= navIcon('chart') ?> Reports
        </a>
      </li>
    </ul>

    <div class="sidebar-user">
      <div class="user-avatar"><?= strtoupper(substr($staffName, 0, 1)) ?></div>
      <div class="user-info">
        <div class="user-name"><?= htmlspecialchars($staffName) ?></div>
        <div class="user-role"><?= $_SESSION['staff_role'] === 'report_viewer' ? 'Report Viewer' : 'Super Admin' ?></div>
      </div>
      <a href="../staff_login.php?logout=1" class="logout-btn" title="Logout">
        <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      </a>
    </div>

  </div>
</aside>

<!-- Overlay for mobile sidebar -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<div class="main-content">

  <?php if ($activePage !== 'dashboard' && $activePage !== 'reports' && $activePage !== 'staff' && $activePage !== 'admins'): ?>
<div class="topbar">
    <div class="topbar-breadcrumb">
      <button class="hamburger-btn" id="hamburgerBtn" onclick="toggleSidebar()" aria-label="Toggle menu">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <span class="sep">›</span>
      <span class="current"><?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) : 'Dashboard'; ?></span>
    </div>
    <div class="topbar-right">
      <div class="topbar-date">
        <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        <?= date('D, j M Y') ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <script>
    function toggleSidebar() {
      document.getElementById('sidebar').classList.toggle('open');
      document.getElementById('sidebarOverlay').classList.toggle('active');
    }
    function closeSidebar() {
      document.getElementById('sidebar').classList.remove('open');
      document.getElementById('sidebarOverlay').classList.remove('active');
    }
  </script>

  <div class="page-body">