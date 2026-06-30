<?php
// vendorRCMP/vendor_staff.php — Company Staff management (standalone page)
session_start();

if (empty($_SESSION['vendor_id'])) {
    header("Location: ../vendor_login.php");
    exit;
}

// ── Smart back URL ──
$backUrl = 'dashboard.php'; // safe default
if (!empty($_GET['from'])) {
    $from = $_GET['from'];
    if (!preg_match('#^https?://#', $from)) {
        $backUrl = $from;
        $_SESSION['staff_back_url'] = $backUrl;
    }
} elseif (!empty($_SESSION['staff_back_url'])) {
    $backUrl = $_SESSION['staff_back_url'];
} elseif (!empty($_SERVER['HTTP_REFERER'])) {
    $ref = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH);
    if ($ref && strpos($ref, 'vendor_staff') === false) {
        $backUrl = $_SERVER['HTTP_REFERER'];
        $_SESSION['staff_back_url'] = $backUrl;
    }
}

$vendor_name    = htmlspecialchars($_SESSION['vendor_name']    ?? 'Vendor');
$vendor_company = htmlspecialchars($_SESSION['vendor_company'] ?? '');

require_once __DIR__ . '/../db_connect.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Company Staff | UniKL RCMP RUSH</title>
  <link href="https://fonts.googleapis.com/css2?family=Source+Sans+3:wght@300;400;600;700&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --navy: #1E3A5F; --navy-dark: #142845; --gold: #B8860B; --gold-light: #D4A017;
      --olive: #5C6B35; --page-bg: #e8edf5; --surface: #fff;
      --border: #D8D3C8; --text: #1A2332; --text-muted: #7A8899;
      --blue: #1a56db; --blue-light: #EFF6FF;
      --g100: #f3f4f6; --g200: #e5e7eb; --g300: #d1d5db;
      --g400: #9ca3af; --g500: #6b7280; --g700: #374151; --g900: #111827;
    }
    body { font-family: 'Source Sans 3', sans-serif; background: var(--page-bg); color: var(--text); min-height: 100vh; }

    /* ── NAV ── */
    nav {
      background: var(--surface); border-bottom: 3px solid var(--navy);
      box-shadow: 0 2px 16px rgba(0,0,0,0.08);
      padding: 0 48px; display: flex; align-items: center; justify-content: space-between; height: 80px;
    }
    .nav-brand { display: flex; align-items: center; gap: 14px; text-decoration: none; }
    .nav-brand img { width: 60px; height: 60px; object-fit: contain; }
    .nav-brand-text { font-size: 17px; font-weight: 700; color: var(--navy-dark); }
    .nav-brand-sub  { font-size: 11px; font-weight: 600; letter-spacing: 0.1em; text-transform: uppercase; color: var(--olive); }
    .nav-right { display: flex; align-items: center; gap: 12px; }
    .vendor-chip {
      display: flex; align-items: center; gap: 8px;
      background: var(--page-bg); border: 1px solid var(--border);
      border-radius: 100px; padding: 6px 14px;
      font-size: 13px; font-weight: 600; color: var(--navy-dark);
    }
    .btn-logout {
      padding: 8px 18px; border-radius: 6px;
      border: 1.5px solid var(--border); background: transparent;
      font-size: 13px; font-weight: 600; color: var(--text-muted);
      text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
      transition: all 0.2s; cursor: pointer;
    }
    .btn-logout:hover { border-color: var(--navy); color: var(--navy); background: #E8EEF6; }

    /* ── MAIN LAYOUT ── */
    .main { max-width: 1300px; margin: 40px auto; padding: 0 40px; }
    .page-heading { margin-bottom: 24px; display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap; }
    .page-heading h1 { font-size: 22px; font-weight: 700; color: var(--navy-dark); }
    .page-heading p  { font-size: 14px; color: var(--text-muted); margin-top: 4px; }
    .back-link { font-size:13px; font-weight:600; color:var(--blue); text-decoration:none; display:inline-flex; align-items:center; gap:6px; margin-bottom:14px; }
    .back-link:hover { text-decoration:underline; }

    /* ── TABLE CARD ── */
    .tbl-card { background: var(--surface); border: 1px solid var(--g200); border-radius: 12px; overflow: hidden; }
    .tbl-wrap { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; font-size: 15px; }
    thead th {
      background: var(--g100); padding: 10px 14px; text-align: left;
      font-size: 14px; font-weight: 700; color: var(--g500);
      text-transform: uppercase; letter-spacing: .06em;
      border-bottom: 1px solid var(--g200); white-space: nowrap;
    }
    tbody tr { border-bottom: 1px solid var(--g200); transition: background .12s; }
    tbody tr:last-child { border-bottom: none; }
    tbody tr:hover { background: #F8FAFF; }
    tbody td { padding: 11px 14px; color: var(--g700); vertical-align: middle; }

    /* ── MAIN TABS ── */
    .main-tab-nav { display:flex; border-bottom:2px solid var(--g200); margin-bottom:24px; }
    .main-tab-btn {
      padding:10px 22px 11px; border:none; background:none;
      font-family:'Source Sans 3',sans-serif; font-size:14px; font-weight:600; color:var(--g500);
      border-bottom:2.5px solid transparent; margin-bottom:-2px; cursor:pointer;
      display:flex; align-items:center; gap:7px; transition:color .15s,border-color .15s;
    }
    .main-tab-btn:hover { color:var(--navy-dark); }
    .main-tab-btn.active { color:var(--blue); border-bottom-color:var(--blue); }
    .main-pane { display:none; }
    .main-pane.active { display:block; }
    .badge { display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600; }
    .badge-green { background:#D1FAE5; color:#065F46; }
    .badge-amber { background:#FEF3C7; color:#D97706; }
  </style>
</head>
<body>
<?php require_once __DIR__ . '/includes/header.php'; ?>

<div class="main">

  <a href="<?php echo htmlspecialchars($backUrl); ?>" class="back-link" style="display:inline-flex;align-items:center;gap:7px;font-size:13px;font-weight:500;color:#374151;text-decoration:none;padding:9px 16px;border:1.5px solid #E5E7EB;border-radius:8px;background:white;transition:border-color .15s,color .15s;margin-bottom:16px;"
   onmouseover="this.style.borderColor='#1A56DB';this.style.color='#1A56DB'" 
   onmouseout="this.style.borderColor='#E5E7EB';this.style.color='#374151'">
  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15,18 9,12 15,6"/></svg>
  Back
</a>

  <!-- ── TAB NAV ── -->
  <div class="main-tab-nav">
    <button class="main-tab-btn active" id="mainTabStaff" onclick="switchMainTab('staff')">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="16" y1="11" x2="22" y2="11"/></svg>
      Company Staff
    </button>
    <button class="main-tab-btn" id="mainTabDept" onclick="switchMainTab('dept')">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
      Departments
      <span id="deptPendingBadge" style="display:none;background:#D97706;color:#fff;font-size:9px;font-weight:800;min-width:16px;height:16px;border-radius:20px;align-items:center;justify-content:center;line-height:1;padding:0 4px;"></span>
    </button>
  </div>

  <!-- ── PANE: STAFF ── -->
  <div id="pane-staff" class="main-pane active">
    <div class="page-heading">
      <div>
        <h1>Company Staff</h1>
        <p>People from your company who handle work orders — <?php echo $vendor_company; ?>.</p>
      </div>
      <button onclick="openStaffModal()" style="display:inline-flex;align-items:center;gap:7px;padding:9px 20px;border-radius:8px;border:none;background:var(--navy);color:#fff;font-size:13.5px;font-weight:700;font-family:inherit;cursor:pointer;height:fit-content;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Add Staff
      </button>
    </div>
    <div class="tbl-card">
      <div class="tbl-wrap">
        <table id="staffTable">
          <thead>
            <tr>
              <th>#</th>
              <th>Full Name</th>
              <th>Position / Job Title</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="staffTbody">
            <tr><td colspan="4" style="text-align:center;padding:40px;color:var(--text-muted);">Loading…</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ── PANE: DEPARTMENTS ── -->
  <div id="pane-dept" class="main-pane">
    <div class="page-heading">
      <div>
        <h1>Departments</h1>
        <p>Departments your company is approved or pending to serve — <?php echo $vendor_company; ?>.</p>
      </div>
      <button onclick="openDeptModal()" style="display:inline-flex;align-items:center;gap:7px;padding:9px 20px;border-radius:8px;border:none;background:var(--navy);color:#fff;font-size:13.5px;font-weight:700;font-family:inherit;cursor:pointer;height:fit-content;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Request Department
      </button>
    </div>
    <div class="tbl-card">
      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Department Name</th>
              <th>Status</th>
              <th>Requested On</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="deptTbody">
            <tr><td colspan="5" style="text-align:center;padding:40px;color:var(--text-muted);">Loading…</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<!-- ── REQUEST DEPARTMENT MODAL ── -->
<div id="deptModal" style="display:none;position:fixed;inset:0;z-index:1000;align-items:center;justify-content:center;">
  <div onclick="closeDeptModal()" style="position:absolute;inset:0;background:rgba(0,0,0,0.35);backdrop-filter:blur(2px);"></div>
  <div style="position:relative;background:#fff;border-radius:16px;padding:32px 28px;width:100%;max-width:480px;margin:16px;box-shadow:0 20px 60px rgba(0,0,0,0.18);">
    <button onclick="closeDeptModal()" style="position:absolute;top:14px;right:14px;background:none;border:none;cursor:pointer;color:var(--g400);padding:4px;border-radius:6px;display:flex;">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
    <h2 style="font-size:17px;font-weight:800;color:var(--g900);margin-bottom:6px;padding-bottom:16px;border-bottom:1px solid var(--g200);">Request New Department</h2>
    <p style="font-size:13px;color:var(--g500);margin-bottom:18px;">Select departments you'd like to serve. Each request will be reviewed by the department administrator before activation.</p>
    <div id="deptCheckList" style="display:flex;flex-direction:column;gap:10px;margin-bottom:20px;min-height:60px;">
      <p style="color:var(--g400);font-size:13px;">Loading…</p>
    </div>
    <div id="deptModalMsg" style="display:none;font-size:13px;font-weight:600;padding:8px 12px;border-radius:8px;margin-bottom:12px;"></div>
    <div style="display:flex;gap:10px;">
      <button onclick="closeDeptModal()" style="flex:1;padding:11px;border-radius:10px;background:transparent;border:1.5px solid var(--g300);color:var(--g700);font-size:14px;font-weight:700;font-family:inherit;cursor:pointer;">Cancel</button>
      <button onclick="saveDeptRequest()" id="deptSaveBtn" style="flex:2;padding:11px;border-radius:10px;background:var(--navy);border:none;color:#fff;font-size:14px;font-weight:700;font-family:inherit;cursor:pointer;">Submit Request</button>
    </div>
  </div>
</div>



<!-- ── ADD/EDIT STAFF MODAL ── -->
<div id="staffModal" style="display:none;position:fixed;inset:0;z-index:1000;align-items:center;justify-content:center;">
  <div onclick="closeStaffModal()" style="position:absolute;inset:0;background:rgba(0,0,0,0.35);backdrop-filter:blur(2px);"></div>
  <div style="position:relative;background:#fff;border-radius:16px;padding:32px 28px;width:100%;max-width:440px;margin:16px;box-shadow:0 20px 60px rgba(0,0,0,0.18);">
    <button onclick="closeStaffModal()" style="position:absolute;top:14px;right:14px;background:none;border:none;cursor:pointer;color:var(--g400);padding:4px;border-radius:6px;display:flex;">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
    <h2 id="staffModalTitle" style="font-size:17px;font-weight:800;color:var(--g900);margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid var(--g200);">Add Staff</h2>
    <input type="hidden" id="editStaffId" value="0"/>

    <div style="display:flex;flex-direction:column;gap:14px;margin-bottom:20px;">
      <div>
        <label style="font-size:12px;font-weight:700;color:var(--g500);letter-spacing:.05em;text-transform:uppercase;display:block;margin-bottom:5px;">Full Name *</label>
        <input id="sFullName" type="text" placeholder="e.g. Ahmad Faizal"
          style="width:100%;padding:9px 12px;border:1.5px solid var(--g300);border-radius:8px;font-size:14px;font-family:inherit;color:var(--g700);outline:none;"/>
      </div>
      <div>
        <label style="font-size:12px;font-weight:700;color:var(--g500);letter-spacing:.05em;text-transform:uppercase;display:block;margin-bottom:5px;">Position / Job Title</label>
        <input id="sPosition" type="text" placeholder="e.g. Network Technician"
          style="width:100%;padding:9px 12px;border:1.5px solid var(--g300);border-radius:8px;font-size:14px;font-family:inherit;color:var(--g700);outline:none;"/>
      </div>
      
    </div>

    <div id="staffModalMsg" style="display:none;font-size:13px;font-weight:600;padding:8px 12px;border-radius:8px;margin-bottom:12px;"></div>
    <div style="display:flex;gap:10px;">
      <button onclick="closeStaffModal()" style="flex:1;padding:11px;border-radius:10px;background:transparent;border:1.5px solid var(--g300);color:var(--g700);font-size:14px;font-weight:700;font-family:inherit;cursor:pointer;">Cancel</button>
      <button onclick="saveStaff()" id="staffSaveBtn" style="flex:2;padding:11px;border-radius:10px;background:var(--navy);border:none;color:#fff;font-size:14px;font-weight:700;font-family:inherit;cursor:pointer;">Save</button>
    </div>
  </div>
</div>

<script>
// ── STAFF MANAGEMENT ────────────────────────────────────────────────
async function loadStaff() {
  const res  = await fetch('vendor_staff_ajax.php?action=list');
  const data = await res.json();
  const tbody = document.getElementById('staffTbody');
  if (!data.success || data.staff.length === 0) {
    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:40px;color:var(--g400);">No staff added yet. Click "Add Staff" to get started.</td></tr>';
    return;
  }
  tbody.innerHTML = data.staff.map((s, i) => `
    <tr>
      <td style="font-size:13px;color:var(--g400);font-weight:600;">${i+1}</td>
      <td style="font-size:15px;font-weight:600;color:var(--g700);">
        ${esc(s.full_name)}
        ${s.is_primary == 1 ? '<span style="margin-left:8px;font-size:10px;font-weight:700;color:#B8860B;background:#FEF3C7;padding:2px 8px;border-radius:10px;letter-spacing:.04em;">PIC</span>' : ''}
      </td>
      <td style="font-size:14px;color:var(--g500);">${esc(s.position||'—')}</td>
      <td>
        <div style="display:flex;gap:6px;">
          <button onclick="editStaff(${s.staff_id},'${esc(s.full_name)}','${esc(s.position||'')}')"
            style="padding:5px 12px;border-radius:7px;border:1.5px solid var(--blue);color:var(--blue);background:transparent;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;">Edit</button>
          ${s.is_primary == 1 ? '' : `
          <button onclick="deleteStaff(${s.staff_id},'${esc(s.full_name)}')"
            style="padding:5px 12px;border-radius:7px;border:1.5px solid #DC2626;color:#DC2626;background:transparent;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;">Remove</button>`}
        </div>
      </td>
    </tr>
  `).join('');
}

function esc(str) {
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function openStaffModal(reset=true) {
  if (reset) {
    document.getElementById('staffModalTitle').textContent = 'Add Staff';
    document.getElementById('editStaffId').value = '0';
    ['sFullName','sPosition'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('staffModalMsg').style.display = 'none';
  }
  document.getElementById('staffModal').style.display = 'flex';
  document.body.style.overflow = 'hidden';
}

function closeStaffModal() {
  document.getElementById('staffModal').style.display = 'none';
  document.body.style.overflow = '';
}

function editStaff(id, name, pos) {
  document.getElementById('staffModalTitle').textContent = 'Edit Staff';
  document.getElementById('editStaffId').value = id;
  document.getElementById('sFullName').value  = name;
  document.getElementById('sPosition').value  = pos;
  document.getElementById('staffModalMsg').style.display = 'none';
  openStaffModal(false);
}

function showStaffMsg(msg, ok) {
  const el = document.getElementById('staffModalMsg');
  el.textContent = msg;
  el.style.display = 'block';
  el.style.background = ok ? '#D1FAE5' : '#FEE2E2';
  el.style.color      = ok ? '#065F46' : '#991B1B';
}

async function saveStaff() {
  const btn = document.getElementById('staffSaveBtn');
  btn.textContent = 'Saving…'; btn.disabled = true;
  const body = new FormData();
  body.append('action',    'save');
  body.append('staff_id',  document.getElementById('editStaffId').value);
  body.append('full_name', document.getElementById('sFullName').value.trim());
  body.append('position',  document.getElementById('sPosition').value.trim());
  
  try {
    const res  = await fetch('vendor_staff_ajax.php', { method:'POST', body });
    const data = await res.json();
    if (data.success) {
      showStaffMsg('Staff saved!', true);
      await loadStaff();
      setTimeout(closeStaffModal, 900);
    } else {
      showStaffMsg(data.error || 'Failed to save.', false);
    }
  } catch(e) { showStaffMsg('Network error.', false); }
  btn.textContent = 'Save'; btn.disabled = false;
}

function deleteStaff(id, name) {
  document.getElementById('deleteStaffName').textContent = name;
  document.getElementById('confirmDeleteBtn').onclick = async () => {
    closeDeleteModal();
    const body = new FormData();
    body.append('action', 'delete');
    body.append('staff_id', id);
    const res  = await fetch('vendor_staff_ajax.php', { method:'POST', body });
    const data = await res.json();
    if (data.success) loadStaff();
    else alert('Failed to remove staff.');
  };
  document.getElementById('deleteModal').style.display = 'flex';
  document.body.style.overflow = 'hidden';
}
function closeDeleteModal() {
  document.getElementById('deleteModal').style.display = 'none';
  document.body.style.overflow = '';
}
document.addEventListener('keydown', e => { if (e.key==='Escape') { closeStaffModal(); closeDeleteModal(); closeDeptModal(); } });
loadStaff(); // auto-load on page ready

// Pre-load department count for tab badge on page load
(async function() {
  const res  = await fetch('vendor_staff_ajax.php?action=list_departments');
  const data = await res.json();
  const badge = document.getElementById('deptPendingBadge');
  if (badge && data.success) {
    const pendingCount = data.departments.filter(d => d.status === 'pending').length;
    if (pendingCount > 0) {
      badge.textContent = pendingCount;
      badge.style.display = 'inline-flex';
    } else {
      badge.style.display = 'none';
    }
  }
})();

// ── MAIN TAB SWITCHING ───────────────────────────────────────────────────
function switchMainTab(tab) {
  document.querySelectorAll('.main-tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.main-pane').forEach(p => p.classList.remove('active'));
  const key = tab.charAt(0).toUpperCase() + tab.slice(1);
  document.getElementById('mainTab' + key).classList.add('active');
  document.getElementById('pane-' + tab).classList.add('active');
  if (tab === 'dept') loadDepartments();
}

// ── DEPARTMENT MANAGEMENT ────────────────────────────────────────────────
async function loadDepartments() {
  const res  = await fetch('vendor_staff_ajax.php?action=list_departments');
  const data = await res.json();
  const tbody = document.getElementById('deptTbody');

  // Update pending badge on tab
  const badge = document.getElementById('deptPendingBadge');
  if (badge && data.success) {
    const pendingCount = data.departments.filter(d => d.status === 'pending').length;
    if (pendingCount > 0) {
      badge.textContent = pendingCount;
      badge.style.display = 'inline-flex';
    } else {
      badge.style.display = 'none';
    }
  }

  if (!data.success || data.departments.length === 0) {
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:40px;color:var(--g400);">No departments yet. Click "Request Department" to get started.</td></tr>';
    return;
  }
  tbody.innerHTML = data.departments.map((d, i) => {
    const badgeClass = d.status === 'active' ? 'badge-green' : 'badge-amber';
    const badgeLabel = d.status === 'active' ? '✓ Active' : '⏳ Pending Approval';
    const date = new Date(d.created_at).toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'});
    const actions = d.status === 'pending'
      ? `<button onclick="cancelDeptRequest(${d.id},'${esc(d.dept_name)}')"
           style="padding:5px 12px;border-radius:7px;border:1.5px solid #DC2626;color:#DC2626;background:transparent;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;">
           Cancel
         </button>`
      : '<span style="color:var(--g400);font-size:13px;">—</span>';
    return `
      <tr>
        <td style="font-size:13px;color:var(--g400);font-weight:600;">${i + 1}</td>
        <td style="font-size:15px;font-weight:600;color:var(--g700);">${esc(d.dept_name)}</td>
        <td><span class="badge ${badgeClass}">${badgeLabel}</span></td>
        <td style="font-size:14px;color:var(--g500);">${date}</td>
        <td>${actions}</td>
      </tr>`;
  }).join('');
}

async function openDeptModal() {
  document.getElementById('deptCheckList').innerHTML = '<p style="color:var(--g400);font-size:13px;">Loading available departments…</p>';
  document.getElementById('deptModalMsg').style.display = 'none';
  document.getElementById('deptModal').style.display = 'flex';
  document.body.style.overflow = 'hidden';
  const res  = await fetch('vendor_staff_ajax.php?action=available_departments');
  const data = await res.json();
  const list = document.getElementById('deptCheckList');
  if (!data.success || data.departments.length === 0) {
    list.innerHTML = '<p style="color:var(--g400);font-size:13px;">Your company has already been registered for all available departments.</p>';
    return;
  }
  list.innerHTML = data.departments.map(d => `
    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:13.5px;color:var(--g700);padding:10px 14px;border:1.5px solid var(--g200);border-radius:8px;transition:border-color .12s;"
      onmouseover="this.style.borderColor='var(--blue)'" onmouseout="this.style.borderColor='var(--g200)'">
      <input type="checkbox" name="dept_request[]" value="${d.dept_id}"
        style="width:16px;height:16px;accent-color:var(--navy);cursor:pointer;flex-shrink:0;"/>
      <span style="font-weight:600;">${esc(d.dept_name)}</span>
    </label>`).join('');
}

function closeDeptModal() {
  document.getElementById('deptModal').style.display = 'none';
  document.body.style.overflow = '';
}

function showDeptMsg(msg, ok) {
  const el = document.getElementById('deptModalMsg');
  el.textContent = msg;
  el.style.display = 'block';
  el.style.background = ok ? '#D1FAE5' : '#FEE2E2';
  el.style.color      = ok ? '#065F46' : '#991B1B';
}

async function saveDeptRequest() {
  const checks = document.querySelectorAll('input[name="dept_request[]"]:checked');
  if (!checks.length) { showDeptMsg('Please select at least one department.', false); return; }
  const btn = document.getElementById('deptSaveBtn');
  btn.textContent = 'Submitting…'; btn.disabled = true;
  const body = new FormData();
  body.append('action', 'request_department');
  checks.forEach(c => body.append('dept_ids[]', c.value));
  try {
    const res  = await fetch('vendor_staff_ajax.php', { method: 'POST', body });
    const data = await res.json();
    if (data.success) {
      showDeptMsg('Request submitted! Awaiting department admin approval.', true);
      await loadDepartments();
      setTimeout(closeDeptModal, 1200);
    } else {
      showDeptMsg(data.error || 'Failed to submit.', false);
    }
  } catch(e) { showDeptMsg('Network error.', false); }
  btn.textContent = 'Submit Request'; btn.disabled = false;
}

function cancelDeptRequest(id, name) {
  if (!confirm(`Cancel the request to join "${name}"? This cannot be undone.`)) return;
  const body = new FormData();
  body.append('action', 'cancel_department');
  body.append('id', id);
  fetch('vendor_staff_ajax.php', { method: 'POST', body })
    .then(r => r.json())
    .then(data => { if (data.success) loadDepartments(); else alert('Failed to cancel.'); });
}
</script>

<!-- ── DELETE CONFIRM MODAL ── -->
<div id="deleteModal" style="display:none;position:fixed;inset:0;z-index:1100;align-items:center;justify-content:center;">
  <div onclick="closeDeleteModal()" style="position:absolute;inset:0;background:rgba(0,0,0,0.4);backdrop-filter:blur(3px);"></div>
  <div style="position:relative;background:#fff;border-radius:16px;padding:32px 28px 28px;width:100%;max-width:400px;margin:16px;box-shadow:0 24px 64px rgba(0,0,0,0.18);text-align:center;">
    <div style="width:56px;height:56px;border-radius:50%;background:#FEE2E2;display:flex;align-items:center;justify-content:center;margin:0 auto 18px;">
      <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#DC2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
      </svg>
    </div>
    <h3 style="font-size:17px;font-weight:800;color:#111827;margin-bottom:8px;">Remove staff member?</h3>
    <p style="font-size:14px;color:#6B7280;line-height:1.55;margin-bottom:24px;">
      <strong id="deleteStaffName" style="color:#374151;font-weight:700;"></strong> will be removed from your company's staff list. This cannot be undone.
    </p>
    <div style="display:flex;gap:10px;">
      <button onclick="closeDeleteModal()" style="flex:1;padding:11px;border-radius:10px;background:transparent;border:1.5px solid #E5E7EB;color:#374151;font-size:14px;font-weight:700;font-family:inherit;cursor:pointer;transition:border-color .15s,background .15s;"
        onmouseover="this.style.borderColor='#9CA3AF';this.style.background='#F9FAFB'"
        onmouseout="this.style.borderColor='#E5E7EB';this.style.background='transparent'">
        Cancel
      </button>
      <button id="confirmDeleteBtn" style="flex:1;padding:11px;border-radius:10px;background:#DC2626;border:none;color:#fff;font-size:14px;font-weight:700;font-family:inherit;cursor:pointer;transition:background .15s;"
        onmouseover="this.style.background='#B91C1C'"
        onmouseout="this.style.background='#DC2626'">
        Remove
      </button>
    </div>
  </div>
</div>

</body>
</html>