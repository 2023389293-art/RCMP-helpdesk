<!-- dept_admin/afsmd/_sidebar.php -->

<!-- Hamburger button (mobile only) -->
<button class="mob-toggle" id="mobToggle" aria-label="Open menu">
  <svg viewBox="0 0 24 24">
    <line x1="3" y1="6" x2="21" y2="6"/>
    <line x1="3" y1="12" x2="21" y2="12"/>
    <line x1="3" y1="18" x2="21" y2="18"/>
  </svg>
</button>

<!-- Dark overlay that appears behind the sidebar -->
<div class="mob-overlay" id="mobOverlay"></div>

<!-- YOUR EXISTING ASIDE — only change is adding id="sidebar" -->
<aside class="sidebar" id="sidebar">
<a class="sidebar-brand" href="../../index.php">
  <img src="../../img/RCMP.png" alt="RCMP Logo" style="width:58px;height:58px;object-fit:contain;border-radius:7px;flex-shrink:0;">
  <div class="brand-text">
    <div class="t1" style="font-size:13px;">UniKL RCMP</div>
    <div class="t2" style="font-size:16px;">Help Desk</div>
  </div>
</a>

  <div class="sidebar-dept">
    <div class="dept-chip">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
      Admin AFSMD
    </div>
  </div>

<nav class="sidebar-nav">
    <div class="nav-section-label">Main Menu</div>

    <?php echo nav_item('dashboard.php',
      '<svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>',
      'Dashboard', 'dashboard', $currentPage); ?>

    <?php if ($_SESSION['staff_role'] !== 'hod'): ?>
    <?php echo nav_item('tickets.php',
      '<svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>',
      'All Tickets', 'tickets', $currentPage); ?>
      
     <!--
    <span class="nav-item" style="opacity:0.4;cursor:not-allowed;pointer-events:none;" title="Coming soon">
      <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><line x1="9" y1="12" x2="15" y2="12"/><line x1="9" y1="16" x2="13" y2="16"/></svg></span>
      <span class="nav-label">Requisitions</span>
    </span>
    <?php endif; ?>
    -->

    <?php echo nav_item('users.php',
      '<svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
      'Manage Users', 'users', $currentPage); ?>

    <?php echo nav_item('categories.php',
      '<svg viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>',
      'Categories', 'categories', $currentPage); ?>

    <?php echo nav_item('reports.php',
      '<svg viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
      'Reports', 'reports', $currentPage); ?>

  </nav>

  <div class="sidebar-footer">
    <div class="user-info">
      <div class="user-av"><?= strtoupper(substr($_SESSION['staff_name'] ?? 'A', 0, 1)) ?></div>
      <div style="flex:1; min-width:0;">
        <div class="user-n"><?= htmlspecialchars($_SESSION['staff_name'] ?? '') ?></div>
        <div class="user-role"><?= $_SESSION['staff_role'] === 'hod' ? 'HOD · AFSMD' : 'Dept Admin · AFSMD' ?></div>
      </div>
      <a href="../../staff_login.php?logout=1" class="btn-logout" style="width:auto; padding:7px 9px; flex-shrink:0;" title="Sign Out">
        <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      </a>
    </div>
  </div>

</aside>

<script>
(function () {
  var toggle  = document.getElementById('mobToggle');
  var sidebar = document.getElementById('sidebar');
  var overlay = document.getElementById('mobOverlay');

  function openSidebar() {
    sidebar.classList.add('open');
    overlay.classList.add('open');
    toggle.setAttribute('aria-label', 'Close menu');
  }

  function closeSidebar() {
    sidebar.classList.remove('open');
    overlay.classList.remove('open');
    toggle.setAttribute('aria-label', 'Open menu');
  }

  toggle.addEventListener('click', function () {
    sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
  });

  overlay.addEventListener('click', closeSidebar);

  // Close sidebar when a nav link is tapped on mobile
  sidebar.querySelectorAll('a').forEach(function (link) {
    link.addEventListener('click', function () {
      if (window.innerWidth <= 900) closeSidebar();
    });
  });
})();
</script>