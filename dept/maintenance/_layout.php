<?php
// dept/maintenance/_layout.php 

$openCount        = $openCount        ?? 0;
$inProgressCount  = $inProgressCount  ?? 0;
$closedCount      = $closedCount      ?? 0;
$nav         = $activeNav   ?? 'dashboard';
?>
<link rel="stylesheet" href="css/sidebarr_layout.css?v=<?php echo time(); ?>">

<?php $ticketsNavOpen = in_array($nav, ['tickets', 'tickets-open', 'tickets-inprogress', 'tickets-closed']); ?>

<!-- ══════════════════════════════════════════════════
     LOGOUT MODAL
     ══════════════════════════════════════════════════ -->
<div class="logout-overlay" id="logoutOverlay">
  <div class="logout-modal">
    <div class="logout-icon-wrap">
      <svg viewBox="0 0 24 24">
        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
        <polyline points="16,17 21,12 16,7"/>
        <line x1="21" y1="12" x2="9" y2="12"/>
      </svg>
    </div>
    <h3>Sign out?</h3>
    <p>You will be logged out and returned to the staff login page.</p>
    <div class="logout-modal-btns">
      <button class="btn-cancel"  onclick="closeLogoutModal()">Cancel</button>
      <button class="btn-signout" onclick="confirmLogout()">Sign out</button>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════
     SIDEBAR
     ══════════════════════════════════════════════════ -->
<aside class="sidebar" id="mainSidebar">

  <div class="sb-brand">
    <a class="brand-logo" href="dashboard.php">
      <div class="brand-icon">
        <img src="../../img/RCMP.png" alt="UniKL Logo">
      </div>
      <div class="brand-text">
        <div class="top">UniKL RCMP Help Desk</div>
        <div class="bot">Maintenance Dept</div>
      </div>
    </a>
  </div>

  <span class="dept-badge"><?php echo htmlspecialchars($staffEmail); ?></span>

  <nav class="sb-nav">
    <div class="nav-lbl">Main</div>

    <a href="dashboard.php"
       class="nav-item <?php echo $nav === 'dashboard' ? 'active' : ''; ?>"
       data-tooltip="Dashboard">
      <svg viewBox="0 0 24 24">
        <rect x="3" y="3" width="7" height="7"/>
        <rect x="14" y="3" width="7" height="7"/>
        <rect x="14" y="14" width="7" height="7"/>
        <rect x="3" y="14" width="7" height="7"/>
      </svg>
      <span>Dashboard</span>
    </a>

    <div class="nav-group">
      <div class="nav-group-header <?php echo $ticketsNavOpen ? 'has-active' : ''; ?>"
           data-tooltip="All Tickets">
        <a href="tickets.php" class="nav-group-link">
          <svg class="icon" viewBox="0 0 24 24">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
            <polyline points="14,2 14,8 20,8"/>
            <line x1="16" y1="13" x2="8" y2="13"/>
            <line x1="16" y1="17" x2="8" y2="17"/>
          </svg>
          <span class="link-label">All Tickets</span>
          <?php if ($openCount > 0): ?>
            <span class="n-badge"><?php echo $openCount; ?></span>
          <?php endif; ?>
        </a>
        <button class="nav-group-chevron <?php echo $ticketsNavOpen ? 'open' : ''; ?>"
                onclick="toggleTicketsNav(this)" type="button">
          <svg viewBox="0 0 24 24"><polyline points="6,9 12,15 18,9"/></svg>
        </button>
      </div>

      <div class="nav-sub <?php echo $ticketsNavOpen ? 'open' : ''; ?>" id="tickets-sub">
        <a href="tickets.php?status=open"
           class="nav-sub-item <?php echo $nav === 'tickets-open' ? 'active' : ''; ?>">
          <svg viewBox="0 0 24 24">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="8" x2="12" y2="12"/>
            <line x1="12" y1="16" x2="12.01" y2="16"/>
          </svg>
          Open
          <?php if ($openCount > 0): ?>
            <span class="sub-badge sub-badge-open"><?php echo $openCount; ?></span>
          <?php endif; ?>
        </a>

        <a href="tickets.php?status=in_progress"
           class="nav-sub-item <?php echo $nav === 'tickets-inprogress' ? 'active' : ''; ?>">
          <svg viewBox="0 0 24 24">
            <circle cx="12" cy="12" r="10"/>
            <polyline points="12,6 12,12 16,14"/>
          </svg>
          In Progress
          <?php if (($inProgressCount ?? 0) > 0): ?>
            <span class="sub-badge sub-badge-inprogress"><?php echo $inProgressCount; ?></span>
          <?php endif; ?>
        </a>

        <a href="tickets.php?status=closed"
           class="nav-sub-item <?php echo $nav === 'tickets-closed' ? 'active' : ''; ?>">
          <svg viewBox="0 0 24 24">
            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
            <polyline points="22,4 12,14.01 9,11.01"/>
          </svg>
          Closed
          <?php if ($closedCount > 0): ?>
            <span class="sub-badge sub-badge-closed"><?php echo $closedCount; ?></span>
          <?php endif; ?>
        </a>
      </div>
    </div>

    <div class="nav-lbl">Manage</div>

    <a href="categories.php"
       class="nav-item <?php echo $nav === 'categories' ? 'active' : ''; ?>"
       data-tooltip="Categories">
      <svg viewBox="0 0 24 24">
        <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
      </svg>
      <span>Categories</span>
    </a>

  </nav>

  <div class="sb-footer">
    <div class="staff-card">
      <div class="s-avatar"><?php echo mb_strtoupper(mb_substr($staffName, 0, 1)); ?></div>
      <div class="s-info">
        <div class="name"><?php echo htmlspecialchars($staffName); ?></div>
        <div class="role"><?php echo htmlspecialchars($staffRole); ?></div>
      </div>
      <a href="#" class="logout-btn" title="Logout" onclick="showLogoutModal(event)">
        <svg viewBox="0 0 24 24">
          <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
          <polyline points="16,17 21,12 16,7"/>
          <line x1="21" y1="12" x2="9" y2="12"/>
        </svg>
      </a>
    </div>
  </div>

</aside>

<!-- ══════════════════════════════════════════════════
     MAIN WRAPPER
     ══════════════════════════════════════════════════ -->
<main class="main" id="mainContent">

  <div class="topbar">

    <button class="sb-hamburger" onclick="toggleSidebar()" title="Toggle sidebar" aria-label="Toggle sidebar">
      <div class="hbars"><span></span><span></span><span></span></div>
    </button>

    <div class="topbar-titles">
      <h1><?php echo htmlspecialchars($pageTitle ?? 'Dashboard'); ?></h1>
      <p><?php echo htmlspecialchars($pageSubtitle ?? ('Welcome back, ' . $staffName)); ?></p>
    </div>

    <div class="topbar-right">
      <div class="notif-wrapper" id="staffNotifWrapper">
        <button class="notif-btn" id="staffNotifBtn" onclick="staffToggleNotif(event)" aria-label="Notifications">
          <svg viewBox="0 0 24 24">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
          </svg>
          <span class="notif-badge" id="staffNotifBadge">0</span>
        </button>
        <div class="notif-dropdown" id="staffNotifDropdown">
          <div class="nd-header">
            <h3>Notifications</h3>
            <div class="nd-header-right">
              <span class="nd-count-pill" id="staffNdCountPill">0 new</span>
              <button class="nd-mark-read" onclick="staffMarkAllRead()">Mark all read</button>
            </div>
          </div>
          <div class="nd-tabs">
            <button class="nd-tab active" onclick="staffSetTab('all', this)">All</button>
            <button class="nd-tab" onclick="staffSetTab('assignment', this)">Assigned to Me</button>
            <button class="nd-tab nd-tab-sla" onclick="staffSetTab('sla_alert', this)">
              Deadlines
              <span class="nd-tab-dot nd-tab-dot-sla" id="ndTabDotSla" style="display:none"></span>
            </button>
          </div>
          <div class="nd-list" id="staffNdList">
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
      <span class="topbar-date"><?php echo date('D, d M Y'); ?></span>
    </div>
  </div>

  <div class="content">

<!-- ══════════════════════════════════════════════════
     NEW TICKET TOAST POPUP
     ══════════════════════════════════════════════════ -->
<style>
.ntt-toast {
  display: block;
  width: 360px;
  background: #fff;
  border-radius: 10px;
  box-shadow: 0 20px 60px rgba(0,30,80,.18), 0 4px 16px rgba(0,0,0,.08);
  border: 1px solid #e4e8f0;
  overflow: hidden;
  font-family: 'DM Sans', sans-serif;
  pointer-events: all;
}
@keyframes nttSlideIn {
  from { opacity:0; transform:translateY(24px) scale(.95); }
  to   { opacity:1; transform:translateY(0) scale(1); }
}
@keyframes nttSlideOut {
  from { opacity:1; transform:translateY(0) scale(1); }
  to   { opacity:0; transform:translateY(16px) scale(.96); }
}
.ntt-header {
  background: linear-gradient(135deg,#0F1629 0%,#1A2F5E 55%,#1e3a7a 100%);
  padding: 14px 16px 13px;
  display: flex; align-items: center; gap: 11px; position: relative;
}
.ntt-stripe { position:absolute;top:0;left:0;right:0;height:3px;border-radius:10px 10px 0 0; }
.ntt-avatar {
  width:34px;height:34px;border-radius:4px;
  background:linear-gradient(135deg,#1A56DB,#60A5FA);
  display:flex;align-items:center;justify-content:center;
  font-size:13px;font-weight:700;color:#fff;flex-shrink:0;
}
.ntt-header-text { flex:1;min-width:0; }
.ntt-header-label { font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.45);margin-bottom:2px; }
.ntt-ticket-id { font-size:13px;font-weight:700;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
.ntt-priority-badge { font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;padding:3px 9px;border-radius:6px;flex-shrink:0;border:1px solid transparent; }
.ntt-close-btn {
  background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18);
  border-radius:50%;width:24px;height:24px;cursor:pointer;
  display:flex;align-items:center;justify-content:center;flex-shrink:0;padding:0;
}
.ntt-close-btn:hover { background:rgba(255,255,255,.22); }
.ntt-close-btn svg { width:12px;height:12px;fill:none;stroke:#fff;stroke-width:2.5; }
.ntt-body { padding:14px 16px 16px; }
.ntt-title { font-size:14px;font-weight:600;color:#1a2038;line-height:1.45;margin-bottom:6px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden; }
.ntt-meta { display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:12px; }
.ntt-meta-chip { display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;padding:2px 8px;border-radius:5px; }
.ntt-meta-chip svg { width:10px;height:10px;fill:none;stroke:currentColor;stroke-width:2; }
.ntt-chip-category { background:#EEF1F7;color:#3D4560; }
.ntt-chip-from     { background:#F0FDF4;color:#166534; }
.ntt-actions { display:flex;gap:8px; }
.ntt-view-btn {
  flex:none;display:inline-flex;align-items:center;justify-content:center;gap:5px;
  background:#1A56DB;color:#fff;font-size:12px;font-weight:600;
  padding:7px 14px;border-radius:4px;text-decoration:none;border:none;
  cursor:pointer;font-family:'DM Sans',sans-serif;transition:background .15s,transform .12s;
}
.ntt-view-btn:hover { background:#1348be;transform:translateY(-1px); }
.ntt-view-btn svg { width:11px;height:11px;fill:none;stroke:#fff;stroke-width:2.5; }
.ntt-dismiss-btn {
  padding:7px 12px;border-radius:4px;border:1px solid #DDE2EE;
  background:transparent;font-size:12px;font-weight:500;color:#7A8499;
  cursor:pointer;font-family:'DM Sans',sans-serif;transition:border-color .15s,color .15s;
}
.ntt-dismiss-btn:hover { border-color:#CDD3DF;color:#3D4560; }
.ntt-progress { height:3px;width:100%;transform-origin:left;transition:transform linear; }
.ntt-remarks {
  font-size:11px; color:#5B21B6;
  background:#EDE9FE; border-left:2px solid #7C3AED;
  padding:5px 10px; border-radius:0 5px 5px 0;
  margin-bottom:10px; line-height:1.5;
  word-break:break-word;
}
</style>

<div id="nttStack" style="position:fixed;bottom:28px;right:28px;z-index:9998;display:flex;flex-direction:column;gap:10px;align-items:flex-end;pointer-events:none;"></div>


<!-- ═══════════════════════════════════════════════════
     JAVASCRIPT
     ═══════════════════════════════════════════════════ -->
<script>
/* ── Sidebar toggle ── */
(function () {
  var sidebar = document.getElementById('mainSidebar');
  var body    = document.body;
  if (localStorage.getItem('sidebarCollapsed') === '1') {
    sidebar.classList.add('collapsed');
    body.classList.add('sidebar-collapsed');
  }
  window.toggleSidebar = function () {
    var isCollapsed = sidebar.classList.toggle('collapsed');
    body.classList.toggle('sidebar-collapsed', isCollapsed);
    localStorage.setItem('sidebarCollapsed', isCollapsed ? '1' : '0');
    if (isCollapsed) {
      var sub = document.getElementById('tickets-sub');
      var chevron = sidebar.querySelector('.nav-group-chevron');
      if (sub) sub.classList.remove('open');
      if (chevron) chevron.classList.remove('open');
    }
  };
})();

function toggleTicketsNav(btn) {
  var sidebar = document.getElementById('mainSidebar');
  if (sidebar.classList.contains('collapsed')) { toggleSidebar(); return; }
  btn.classList.toggle('open');
  document.getElementById('tickets-sub').classList.toggle('open');
}

/* ── Logout modal ── */
function showLogoutModal(e) { e.preventDefault(); document.getElementById('logoutOverlay').classList.add('active'); }
function closeLogoutModal() { document.getElementById('logoutOverlay').classList.remove('active'); }
function confirmLogout()    { window.location.href = '../../staff_login.php?logout=1'; }
document.getElementById('logoutOverlay').addEventListener('click', function(e){ if(e.target===this) closeLogoutModal(); });
document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeLogoutModal(); });
</script>

<!-- ═══════════════════════════════════════════════════
     NOTIFICATION JAVASCRIPT
     ═══════════════════════════════════════════════════ -->
<script>
(function () {
  var lastSeenAt=null,allNotifications=[],activeTab='all',isOpen=false,fetched=false;
  var wrapper=document.getElementById('staffNotifWrapper'),btn=document.getElementById('staffNotifBtn'),
      badge=document.getElementById('staffNotifBadge'),list=document.getElementById('staffNdList'),
      countPill=document.getElementById('staffNdCountPill'),dotSla=document.getElementById('ndTabDotSla');

  function parseDate(s){return s?new Date(s.replace(' ','T')):null;}
  function isUnread(n){return !lastSeenAt||parseDate(n.event_at)>parseDate(lastSeenAt);}
  function timeAgo(s){var d=Math.floor((Date.now()-parseDate(s))/1000);if(d<60)return'just now';if(d<3600)return Math.floor(d/60)+' min ago';if(d<86400)return Math.floor(d/3600)+' hr ago';if(d<604800){var x=Math.floor(d/86400);return x+' day'+(x>1?'s':'')+' ago';}return parseDate(s).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'});}
  function getInitials(n){var p=(n||'?').trim().split(' '),i=(p[0]||'?')[0];if(p.length>1)i+=(p[p.length-1]||'?')[0];return i.toUpperCase();}
  function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
  function trunc(s,n){s=s||'';return s.length>n?s.slice(0,n)+'…':s;}

  function filtered(){
    if(activeTab==='all') return allNotifications;
    if(activeTab==='assignment') return allNotifications.filter(function(n){return n.notif_type==='assignment'||n.notif_type==='assigned_to_me';});
    if(activeTab==='sla_alert') return allNotifications.filter(function(n){return n.notif_type==='sla_alert';});
    return allNotifications;
  }

  function renderList(){
    var items=filtered();
    if(!items.length){list.innerHTML='<div class="nd-empty"><div class="nd-empty-icon"><svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg></div><h4>No notifications</h4><p>You\'re all caught up!</p></div>';return;}
    var html='';
    items.forEach(function(n){
      var u=isUnread(n),url='ticket_detail.php?id='+encodeURIComponent(n.ticket_id);
      var avatarClass,chipClass,chipLabel;
      if(n.notif_type==='sla_alert'){if(n.sla_severity==='overdue'){avatarClass='type-overdue';chipClass='chip-overdue';chipLabel='Overdue';}else{avatarClass='type-due-soon';chipClass='chip-due-soon';chipLabel='Due Soon';}}
      else if(n.notif_type==='assignment'){avatarClass='type-assignment';chipClass='chip-assignment';chipLabel='Assigned';}
      else{avatarClass='type-my-ticket';chipClass='chip-my-ticket';chipLabel='My Ticket';}
      html+='<a class="nd-item'+(u?' unread':'')+'" href="'+esc(url)+'">'
        +'<div class="nd-avatar '+avatarClass+'">'+esc(getInitials(n.sender_name))+'</div>'
        +'<div class="nd-content"><div class="nd-top"><span class="nd-sender">'+esc(trunc(n.sender_name,22))+'</span>'
        +'<span class="nd-type-chip '+chipClass+'">'+chipLabel+'</span></div>'
        +'<div class="nd-ticket-id">'+esc(n.ticket_id)+'</div>'
        +'<div class="nd-message">'+esc(trunc(n.message,80))+'</div>'
+(n.notif_type==='sla_alert'&&n.status?'<div style="margin-top:3px"><span style="display:inline-block;font-size:10px;font-weight:700;padding:1px 7px;border-radius:99px;'+(n.status==='open'?'background:#FEF3C7;color:#D97706;':'background:#DBEAFE;color:#2563EB;')+'">'+esc(n.status==='in_progress'?'In Progress':n.status.charAt(0).toUpperCase()+n.status.slice(1))+'</span></div>':'')
        +(n.notif_type==='assignment'&&n.remarks?'<div class="nd-remarks">'+esc(trunc(n.remarks,80))+'</div>':'')
        +'<div class="nd-time">'+esc(trunc(n.ticket_title,34))+' &bull; '+timeAgo(n.event_at)+'</div>'
        +'</div>'+(u?'<div class="nd-unread-dot"></div>':'')+'</a>';
    });
    list.innerHTML=html;
  }

  function updateBadge(){
    var unreadAll=allNotifications.filter(isUnread).length;
    var hasSla=allNotifications.some(function(n){return n.notif_type==='sla_alert';});
    countPill.textContent=unreadAll+' new';
    if(unreadAll>0){badge.textContent=unreadAll>99?'99+':String(unreadAll);badge.classList.add('show');}
    else{badge.classList.remove('show');}
    dotSla.style.display=hasSla?'inline-block':'none';
    if(hasSla){var hasOverdue=allNotifications.some(function(n){return n.notif_type==='sla_alert'&&n.sla_severity==='overdue';});dotSla.style.background=hasOverdue?'#E02424':'#F59E0B';}
  }

  function loadLastSeen(){return fetch('staff_notif_seen.php',{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){lastSeenAt=d.last_seen||null;}).catch(function(){lastSeenAt=null;});}

  function fetchNotifications(){
    fetch('staff_notifications_api.php',{credentials:'same-origin'})
      .then(function(r){if(!r.ok)throw 0;return r.json();})
      .then(function(d){allNotifications=d.notifications||[];fetched=true;updateBadge();if(isOpen)renderList();})
      .catch(function(){if(isOpen){list.innerHTML='<div class="nd-empty"><div class="nd-empty-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div><h4>Could not load</h4><p>Check your connection.</p></div>';}});
  }

  window.staffToggleNotif=function(e){e.stopPropagation();isOpen=!isOpen;wrapper.classList.toggle('open',isOpen);btn.classList.toggle('active',isOpen);if(isOpen){if(!fetched)fetchNotifications();else renderList();}};
  window.staffSetTab=function(t,el){activeTab=t;document.querySelectorAll('.nd-tab').forEach(function(x){x.classList.remove('active');});el.classList.add('active');renderList();};
  window.staffMarkAllRead=function(){if(!allNotifications.length)return;lastSeenAt=new Date().toISOString().replace('T',' ').slice(0,19);updateBadge();renderList();fetch('staff_notif_seen.php',{method:'POST',credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){lastSeenAt=d.last_seen||lastSeenAt;updateBadge();renderList();}).catch(function(){});};

  document.addEventListener('click',function(e){if(isOpen&&!wrapper.contains(e.target)){isOpen=false;wrapper.classList.remove('open');btn.classList.remove('active');}});
  document.addEventListener('keydown',function(e){if(e.key==='Escape'&&isOpen){isOpen=false;wrapper.classList.remove('open');btn.classList.remove('active');}});

  loadLastSeen().then(fetchNotifications);
  setInterval(fetchNotifications,60000);
})();
</script>

<!-- ═══════════════════════════════════════════════════
     NEW TICKET TOAST — JAVASCRIPT
     ═══════════════════════════════════════════════════ -->
<script>
(function () {
  'use strict';

  var POPUP_API  = window.location.origin + '/uniKL/complaint/new_ticket_popup_api.php';
  var DETAIL_URL = 'ticket_detail.php';
  var DURATION   = 10000;
  var MAX_TOASTS = 5;

  var PRIORITY_COLORS = {
    high:   { stripe:'#E02424', badge:'background:#FEE2E2;color:#E02424;border-color:#FCA5A5;' },
    medium: { stripe:'#F59E0B', badge:'background:#FEF3C7;color:#D97706;border-color:#FCD34D;' },
    low:    { stripe:'#22C55E', badge:'background:#DCFCE7;color:#16A34A;border-color:#86EFAC;' },
  };

  var stack    = document.getElementById('nttStack');
  var toastMap = {};

  function getInitials(name) {
    var p = (name||'?').trim().split(' ');
    var i = (p[0][0]||'?');
    if (p.length > 1) i += (p[p.length-1][0]||'');
    return i.toUpperCase();
  }
  function truncate(s, n) { return s && s.length > n ? s.slice(0,n)+'…' : (s||''); }
  function capitalize(s)  { return s ? s.charAt(0).toUpperCase()+s.slice(1) : ''; }
  function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

  function dismissToast(id) {
    var entry = toastMap[id];
    if (!entry) return;
    clearTimeout(entry.timer);
    var el = entry.el;
    el.style.animation = 'nttSlideOut .28s ease forwards';
    setTimeout(function () {
      if (el.parentNode) el.parentNode.removeChild(el);
      delete toastMap[id];
    }, 300);
  }

  function showToast(data) {
    var id       = data.log_id;
    var priority = (data.priority||'medium').toLowerCase();
    var colors   = PRIORITY_COLORS[priority] || PRIORITY_COLORS.medium;
    var submitter = data.submitter || 'Unknown';
    var rawCat    = data.category || '';
    var catParts  = rawCat.split('/');
    var catLabel  = truncate((catParts[catParts.length-1]||rawCat).trim(), 30);

    var keys = Object.keys(toastMap);
    if (keys.length >= MAX_TOASTS) dismissToast(parseInt(keys[0]));

    var el = document.createElement('div');
    el.className = 'ntt-toast';
    el.setAttribute('role', 'alert');
    el.innerHTML =
      '<div class="ntt-header">'
        +'<div class="ntt-stripe" style="background:'+colors.stripe+'"></div>'
        +'<div class="ntt-avatar">'+esc(getInitials(submitter))+'</div>'
        +'<div class="ntt-header-text">'
          +'<div class="ntt-header-label">New ticket assigned to you</div>'
          +'<div class="ntt-ticket-id">'+esc(data.ticket_id||'—')+'</div>'
        +'</div>'
        +'<span class="ntt-priority-badge" style="'+colors.badge+'">'+esc(capitalize(priority))+'</span>'
        +'<button class="ntt-close-btn" type="button" aria-label="Dismiss">'
          +'<svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>'
        +'</button>'
      +'</div>'
      +'<div class="ntt-body">'
        +'<div class="ntt-title">'+esc(truncate(data.ticket_title||'New Ticket', 80))+'</div>'
        +'<div class="ntt-meta">'
          +'<span class="ntt-meta-chip ntt-chip-category">'
            +'<svg viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>'
            +esc(catLabel)
          +'</span>'
          +'<span class="ntt-meta-chip ntt-chip-from">'
            +'<svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>'
            +esc(truncate(submitter, 24))
          +'</span>'
        +'</div>'
        +(data.remarks ? '<div class="ntt-remarks">'+esc(truncate(data.remarks,100))+'</div>' : '')
        +'<div class="ntt-actions">'
          +'<a class="ntt-view-btn" href="'+esc(DETAIL_URL+'?id='+encodeURIComponent(data.ticket_id||''))+'">'
            +'View Ticket'
            +'<svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>'
          +'</a>'
          +'<button class="ntt-dismiss-btn" type="button">Dismiss</button>'
        +'</div>'
      +'</div>'
      +'<div class="ntt-progress" style="background:'+colors.stripe+'"></div>';

    el.querySelector('.ntt-close-btn').addEventListener('click',  function(){ dismissToast(id); });
    el.querySelector('.ntt-dismiss-btn').addEventListener('click', function(){ dismissToast(id); });
    el.querySelector('.ntt-view-btn').addEventListener('click',    function(){ clearTimeout(toastMap[id] && toastMap[id].timer); });

    stack.appendChild(el);
    el.style.animation = 'nttSlideIn .38s cubic-bezier(.34,1.26,.64,1) forwards';

    var bar = el.querySelector('.ntt-progress');
    bar.style.transition = 'none';
    bar.style.transform  = 'scaleX(1)';
    requestAnimationFrame(function(){
      requestAnimationFrame(function(){
        bar.style.transition = 'transform '+DURATION+'ms linear';
        bar.style.transform  = 'scaleX(0)';
      });
    });

    var timer = setTimeout(function(){ dismissToast(id); }, DURATION);
    toastMap[id] = { el: el, timer: timer };
  }

  function poll() {
    fetch(POPUP_API, { credentials:'same-origin' })
      .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
      .then(function(data){
        if (!data.has_new) return;
        data.tickets.forEach(function(ticket, i){
          setTimeout(function(){ showToast(ticket); }, i * 400);
        });
      })
      .catch(function(err){ console.warn('[NewTicketPopup]', err); });
  }

  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') {
      Object.keys(toastMap).forEach(function(id){ dismissToast(parseInt(id)); });
    }
  });

  setTimeout(poll, 3000);
  setInterval(poll, 30000);
})();
</script>