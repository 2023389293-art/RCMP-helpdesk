<?php
// dept/afsmd/tickets.php
require_once __DIR__ . '/../auth_guard.php';
if (isset($_GET['logout'])) { staffLogout(); }
require_once __DIR__ . '/../../db_connect.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$filterStatus  = $_GET['status'] ?? 'all';
$allowedFilter = ['all','open','in_progress','closed'];
if (!in_array($filterStatus, $allowedFilter)) $filterStatus = 'all';

$allowedPerPage = [10,25,50];
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
if (!in_array($perPage, $allowedPerPage)) $perPage = 10;

$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$filterPriority = $_GET['priority'] ?? '';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo   = $_GET['date_to']   ?? '';
$filterDept     = $_GET['dept']      ?? '';
$filterCategory = $_GET['category']  ?? '';

$allowedPriority = ['','low','medium','high'];
if (!in_array($filterPriority, $allowedPriority)) $filterPriority = '';

$extraWhere  = '';
$extraParams = [];
$extraTypes  = '';

if ($filterPriority !== '') { $extraWhere .= " AND complaints.priority = ?"; $extraParams[] = $filterPriority; $extraTypes .= 's'; }
if ($filterDateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDateFrom)) { $extraWhere .= " AND DATE(complaints.created_at) >= ?"; $extraParams[] = $filterDateFrom; $extraTypes .= 's'; }
if ($filterDateTo   !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDateTo))   { $extraWhere .= " AND DATE(complaints.created_at) <= ?"; $extraParams[] = $filterDateTo;   $extraTypes .= 's'; }
if ($filterDept !== '') { $extraWhere .= " AND complaints.my_department = ?"; $extraParams[] = $filterDept; $extraTypes .= 's'; }
if ($filterCategory !== '') { $extraWhere .= " AND complaints.category_id = ?"; $extraParams[] = (int)$filterCategory; $extraTypes .= 'i'; }

function bindAdvanced($stmt, string $baseTypes, array $baseRefs, string $extraTypes, array $extraParams): void {
    if (empty($extraTypes)) return;
    $types = $baseTypes . $extraTypes;
    $allParams = array_merge($baseRefs, $extraParams);
    $bindArgs = [$types];
    $refs = [];
    foreach ($allParams as $key => $val) {
        $refs[$key] = $val;
        $bindArgs[] = &$refs[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $bindArgs);
}

$stmt = $conn->prepare(
    "SELECT SUM(complaints.status='open') AS oc,
            SUM(complaints.status='in_progress') AS ipc,
            SUM(complaints.status='closed') AS cc
     FROM complaints
     LEFT JOIN staff s ON s.staff_id = complaints.assigned_to
     WHERE complaints.dept_id = ? $extraWhere"
);
if (empty($extraParams)) { $stmt->bind_param("i", $deptId); }
else { bindAdvanced($stmt,"i",[$deptId],$extraTypes,$extraParams); }
$stmt->execute();
$counts = $stmt->get_result()->fetch_assoc(); $stmt->close();
$openCount       = (int)($counts['oc']  ?? 0);
$inProgressCount = (int)($counts['ipc'] ?? 0);
$closedCount     = (int)($counts['cc']  ?? 0);

if ($filterStatus === 'all') {
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM complaints LEFT JOIN staff s ON s.staff_id = complaints.assigned_to WHERE complaints.dept_id = ? $extraWhere");
    if (empty($extraParams)) { $stmt->bind_param("i",$deptId); }
    else { bindAdvanced($stmt,"i",[$deptId],$extraTypes,$extraParams); }
} else {
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM complaints LEFT JOIN staff s ON s.staff_id = complaints.assigned_to WHERE complaints.dept_id = ? AND complaints.status = ? $extraWhere");
    if (empty($extraParams)) { $stmt->bind_param("is",$deptId,$filterStatus); }
    else { bindAdvanced($stmt,"is",[$deptId,$filterStatus],$extraTypes,$extraParams); }
}
$stmt->execute();
$totalTickets = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0); $stmt->close();

$totalPages = ($totalTickets > 0) ? (int)ceil($totalTickets / $perPage) : 1;
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$tickets = [];
if ($filterStatus === 'all') {
    $stmt = $conn->prepare(
        "SELECT complaints.ticket_id, complaints.title, complaints.status,
                complaints.priority, complaints.my_department,
                complaints.created_at, complaints.updated_at,
                s.staff_id AS assigned_staff_id,
                s.full_name AS assigned_staff_name,
                cat.category_name
         FROM complaints
         LEFT JOIN staff s ON s.staff_id = complaints.assigned_to
         LEFT JOIN categories cat ON cat.category_id = complaints.category_id
         WHERE complaints.dept_id = ? $extraWhere
         ORDER BY complaints.created_at DESC LIMIT ? OFFSET ?"
    );
    if (empty($extraParams)) { $stmt->bind_param("iii",$deptId,$perPage,$offset); }
    else { bindAdvanced($stmt,"i",[$deptId],$extraTypes.'ii',array_merge($extraParams,[$perPage,$offset])); }
} else {
    $stmt = $conn->prepare(
        "SELECT complaints.ticket_id, complaints.title, complaints.status,
                complaints.priority, complaints.my_department,
                complaints.created_at, complaints.updated_at,
                s.staff_id AS assigned_staff_id,
                s.full_name AS assigned_staff_name,
                cat.category_name
         FROM complaints
         LEFT JOIN staff s ON s.staff_id = complaints.assigned_to
         LEFT JOIN categories cat ON cat.category_id = complaints.category_id
         WHERE complaints.dept_id = ? AND complaints.status = ? $extraWhere
         ORDER BY complaints.created_at DESC LIMIT ? OFFSET ?"
    );
    if (empty($extraParams)) { $stmt->bind_param("isii",$deptId,$filterStatus,$perPage,$offset); }
    else { bindAdvanced($stmt,"is",[$deptId,$filterStatus],$extraTypes.'ii',array_merge($extraParams,[$perPage,$offset])); }
}
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $tickets[] = $row;
$stmt->close();

if ($filterStatus === 'open') { $activeNav = 'tickets-open'; }
elseif ($filterStatus === 'in_progress') { $activeNav = 'tickets-inprogress'; }
elseif ($filterStatus === 'closed') { $activeNav = 'tickets-closed'; }
else { $activeNav = 'tickets'; }
$pageTitle    = 'All Tickets';
$pageSubtitle = 'Administration & Facilities Management Department';

function ticketUrl(array $overrides = []): string {
    $params = array_merge(['status'=>$_GET['status']??'all','per_page'=>$_GET['per_page']??10,'page'=>$_GET['page']??1,'priority'=>$_GET['priority']??'','date_from'=>$_GET['date_from']??'','date_to'=>$_GET['date_to']??'','dept'=>$_GET['dept']??'','category'=>$_GET['category']??''], $overrides);
    $params = array_filter($params, fn($v) => $v !== '');
    return 'tickets.php?' . http_build_query($params);
}

$deptOptions = [];
$dStmt = $conn->prepare("SELECT name FROM my_departments ORDER BY sort_order ASC");
$dStmt->execute(); $dRes = $dStmt->get_result();
while ($row = $dRes->fetch_assoc()) $deptOptions[] = $row['name'];
$dStmt->close();

$categoryOptions = [];
$cStmt = $conn->prepare("SELECT category_id, category_name FROM categories WHERE dept_id = ? ORDER BY category_name ASC");
$cStmt->bind_param("i", $deptId);
$cStmt->execute(); $cRes = $cStmt->get_result();
while ($row = $cRes->fetch_assoc()) $categoryOptions[] = $row;
$cStmt->close();

$activeFilterCount = (int)($filterPriority!=='')+(int)($filterDateFrom!=='')+(int)($filterDateTo!=='')+(int)($filterDept!=='')+(int)($filterCategory!=='');

function staffInitials(string $name): string {
    $parts = explode(' ', trim($name));
    $ini = strtoupper(substr($parts[0],0,1));
    if (count($parts) > 1) $ini .= strtoupper(substr($parts[count($parts)-1],0,1));
    return $ini;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>All Tickets | UniKL Help Desk – AFSMD</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet"/>
  <style>
/* ── Filter tabs ── */
.filter-bar{display:flex;align-items:center;gap:6px;margin-bottom:20px;flex-wrap:wrap;}
.filter-tab{display:inline-flex;align-items:center;gap:7px;padding:8px 18px;border-radius:9px;font-size:14px;font-weight:500;text-decoration:none;border:1.5px solid var(--g200);color:var(--g500);background:white;transition:border-color .15s,color .15s,background .15s,box-shadow .15s;white-space:nowrap;}
.filter-tab:hover:not(.active){border-color:var(--g300);color:var(--g700);background:var(--g100);}
.filter-tab.active.tab-all{background:var(--accent);border-color:var(--accent);color:white;font-weight:600;box-shadow:0 2px 10px rgba(26,86,219,.22);}
.filter-tab.active.tab-open{background:#D97706;border-color:#D97706;color:white;font-weight:600;}
.filter-tab.active.tab-inprogress{background:#2563EB;border-color:#2563EB;color:white;font-weight:600;}
.filter-tab.active.tab-closed{background:#059669;border-color:#059669;color:white;font-weight:600;}
.ft-count{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:18px;padding:0 5px;border-radius:9px;font-size:11px;font-weight:700;background:var(--g100);color:var(--g500);}
.filter-tab.active .ft-count{background:rgba(255,255,255,.28);color:white;}
.tab-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0;}
.tab-dot-open{background:#F59E0B;}.tab-dot-inprogress{background:#3B82F6;}.tab-dot-closed{background:#10B981;}
.filter-tab.active .tab-dot{background:rgba(255,255,255,.75);}
    

    /* ── Toolbar ── */
    .toolbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:10px;}
    .toolbar-left,.toolbar-right{display:flex;align-items:center;gap:8px;}
    .sec-title{font-size:16px;font-weight:600;color:var(--g900);}
    .result-count{font-size:13px;color:var(--g500);}
    .search-wrap{position:relative;display:flex;align-items:center;}
    .search-wrap svg{position:absolute;left:10px;width:14px;height:14px;stroke:var(--g400);fill:none;stroke-width:2;pointer-events:none;}
    .search-input{padding:8px 12px 8px 32px;font-size:13px;border-radius:8px;border:1.5px solid var(--g200);background:white;color:var(--g900);width:200px;outline:none;font-family:'DM Sans',sans-serif;}
    .search-input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(26,86,219,.08);}
    .perpage-select{padding:8px 10px;font-size:13px;border-radius:8px;border:1.5px solid var(--g200);background:white;color:var(--g700);cursor:pointer;outline:none;font-family:'DM Sans',sans-serif;}

/* ── Advanced filter bar ── */
.adv-filter-bar{background:white;border:1px solid var(--g200);border-radius:14px;padding:0;margin-bottom:16px;display:flex;flex-direction:column;gap:0;box-shadow:0 1px 4px rgba(0,0,0,.05);}
.adv-filter-bar-header{display:flex;align-items:center;gap:8px;padding:13px 20px;border-bottom:1px solid var(--g200);}
.adv-filter-bar-header svg{width:15px;height:15px;stroke:var(--accent);fill:none;stroke-width:2;flex-shrink:0;}
.adv-filter-bar-header span{font-size:13px;font-weight:700;color:var(--accent);letter-spacing:.01em;}
.adv-filter-bar-body{display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;padding:16px 20px;}
.adv-filter-group{display:flex;flex-direction:column;gap:5px;}
.adv-filter-group label{font-size:11px;font-weight:700;color:var(--g400);text-transform:uppercase;letter-spacing:.07em;}
.adv-filter-select,.adv-filter-input{padding:8px 12px;font-size:13px;border-radius:9px;border:1.5px solid var(--g200);background:#FAFAFA;color:var(--g700);font-family:'DM Sans',sans-serif;outline:none;min-width:155px;transition:border-color .15s,background .15s;}
.adv-filter-select:focus,.adv-filter-input:focus{border-color:var(--accent);background:white;box-shadow:0 0 0 3px rgba(26,86,219,.07);}
.adv-filter-actions{display:flex;align-items:center;gap:8px;margin-left:auto;padding-bottom:1px;}
.btn-apply-filter{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;font-size:13px;font-weight:600;font-family:'DM Sans',sans-serif;border-radius:9px;border:none;background:var(--accent);color:white;cursor:pointer;box-shadow:0 2px 8px rgba(26,86,219,.18);transition:background .15s,box-shadow .15s;}
.btn-apply-filter:hover{background:#1240b0;box-shadow:0 4px 14px rgba(26,86,219,.28);}
.btn-reset-filter{display:inline-flex;align-items:center;gap:5px;padding:9px 14px;font-size:13px;font-weight:500;font-family:'DM Sans',sans-serif;border-radius:9px;border:1.5px solid var(--g200);background:white;color:var(--g500);text-decoration:none;transition:border-color .15s,color .15s;}
.btn-reset-filter:hover{border-color:var(--g300);color:var(--g700);}

    /* ── Active filter chips ── */
    .active-chips{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:14px;}
    .chip{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;background:#EFF6FF;border:1.5px solid #BFDBFE;border-radius:20px;font-size:12px;font-weight:600;color:var(--accent);}
    .chip-x{font-size:14px;font-weight:700;color:var(--accent);text-decoration:none;line-height:1;opacity:.6;}

    /* ── Table card ── */
    .tbl-card{background:white;border-radius:12px;border:1px solid var(--g200);overflow:hidden;width:100%;}
    .tbl-wrap{width:100%;}
    table{width:100%;border-collapse:collapse;font-size:14px;table-layout:fixed;}
    thead th{background:var(--g100);padding:10px 12px;text-align:left;font-size:12px;font-weight:700;color:var(--g500);text-transform:uppercase;letter-spacing:.06em;border-bottom:1px solid var(--g200);white-space:nowrap;overflow:hidden;}
    tbody tr{border-bottom:1px solid var(--g200);transition:background .12s;}
    tbody tr:last-child{border-bottom:none;}
    tbody tr:hover{background:#F8FAFF;}
    tbody td{padding:11px 12px;color:var(--g700);vertical-align:middle;overflow:hidden;}

    /* ── Column widths ── */
    col.col-id       { width: 16%; }
    col.col-title    { width: 13%; }
    col.col-dept     { width: 16%; }
    col.col-status   { width: 9%;  }
    col.col-priority { width: 8%;  }
    col.col-category { width: 14%; }
    col.col-assigned { width: 16%; }
    col.col-action   { width: 8%;  }

    /* ── Ticket ID ── */
    .tid-link{font-weight:600;color:var(--accent);font-size:13px;text-decoration:none;font-family:monospace;letter-spacing:.03em;background:#EFF6FF;padding:3px 8px;border-radius:5px;white-space:nowrap;display:inline-block;max-width:100%;overflow:visible;}
    .tid-link:hover{color:#1240b0;background:#DBEAFE;}

    /* ── From Department cell ── */
    .dept-cell{display:flex;flex-direction:column;gap:2px;}
    .dept-cell .dept-name{font-size:14px;color:var(--g700);font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .dept-cell .dept-datetime{font-size:12px;color:var(--g400);white-space:nowrap;}

    /* ── Title cell ── */
    .title-cell{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:14px;}

    /* ── Status badges ── */
    .bdg{display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:600;padding:3px 8px;border-radius:20px;text-transform:capitalize;white-space:nowrap;}
    .bdg::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor;display:inline-block;flex-shrink:0;}
    .bdg-open{background:#FEF3C7;color:#D97706;}.bdg-in_progress{background:#DBEAFE;color:#1D4ED8;}.bdg-closed{background:#D1FAE5;color:#059669;}

    /* ── Flag-style priority ── */
    .priority-pill{display:inline-flex;align-items:center;gap:4px;font-size:.75rem;font-weight:800;letter-spacing:.07em;text-transform:uppercase;background:none;border:none;padding:0;white-space:nowrap;}
    .priority-flag-icon{width:13px;height:13px;flex-shrink:0;vertical-align:middle;position:relative;top:-1px;}
    .priority-pill.pp-high{color:#DC2626;}
    .priority-pill.pp-high .priority-flag-icon{fill:#DC2626;stroke:#DC2626;}
    .priority-pill.pp-medium{color:#D97706;}
    .priority-pill.pp-medium .priority-flag-icon{fill:#EAB308;stroke:#EAB308;}
    .priority-pill.pp-low{color:#2563EB;}
    .priority-pill.pp-low .priority-flag-icon{fill:#3B82F6;stroke:#3B82F6;}

    /* ── Assigned To ── */
    .assigned-cell{display:flex;align-items:center;gap:7px;overflow:hidden;}
    .staff-avatar-sm{width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,#001f5c,#1a56db);color:white;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;flex-shrink:0;}
    .assigned-name{font-size:13px;color:var(--g700);font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .unassigned-tag{font-size:13px;color:#9CA3AF;font-style:italic;}

    /* ── Category text ── */
    .cat-badge{display:inline-block;font-size:14px;font-weight:500;color:var(--g700);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%;vertical-align:middle;}

    /* ── High-open pulse dot ── */
    .high-dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:#F43F5E;margin-right:4px;vertical-align:middle;animation:pulse 1.4s ease-in-out infinite;}
    @keyframes pulse{0%,100%{opacity:1;transform:scale(1);}50%{opacity:.4;transform:scale(1.3);}}

    /* ── View button ── */
    .btn-view{display:inline-flex;align-items:center;gap:4px;padding:5px 12px;font-size:13px;font-weight:600;font-family:'DM Sans',sans-serif;text-decoration:none;border-radius:8px;border:1.5px solid var(--accent);color:var(--accent);background:transparent;white-space:nowrap;transition:background .15s,color .15s,transform .1s;}
    .btn-view:hover{background:var(--accent);color:white;transform:translateY(-1px);}
    .btn-view svg{width:12px;height:12px;stroke:currentColor;fill:none;stroke-width:2.2;}

    /* ── Empty state ── */
    .empty{text-align:center;padding:60px 20px;color:var(--g500);}
    .empty svg{width:44px;height:44px;margin:0 auto 14px;display:block;stroke:var(--g300);fill:none;stroke-width:1.5;}
    .empty h3{font-family:'DM Serif Display',serif;font-size:18px;color:var(--g700);margin-bottom:6px;}
    .empty p{font-size:13px;color:var(--g400);line-height:1.6;}

    /* ── Pagination ── */
    .pagination-bar{display:flex;align-items:center;justify-content:space-between;margin-top:16px;flex-wrap:wrap;gap:10px;}
    .pg-info{font-size:13px;color:var(--g500);}
    .pg-btns{display:flex;align-items:center;gap:4px;}
    .pg-btn{display:inline-flex;align-items:center;justify-content:center;padding:6px 12px;font-size:13px;font-weight:500;border-radius:8px;border:1.5px solid var(--g200);background:white;color:var(--g700);text-decoration:none;min-width:36px;font-family:'DM Sans',sans-serif;transition:border-color .15s,background .15s,color .15s;}
    .pg-btn:hover{border-color:var(--g300);background:var(--g100);}
    .pg-btn.active{background:var(--accent);border-color:var(--accent);color:white;}
    .pg-btn.disabled{opacity:.4;pointer-events:none;}
    #no-search-row{display:none;}
  </style>
</head>
<body>
<?php require_once __DIR__ . '/_layout.php'; ?>

<!-- ── Filter tabs ── -->
<div class="filter-bar">
  <a href="<?php echo ticketUrl(['status'=>'all','page'=>1]); ?>" class="filter-tab tab-all <?php echo $filterStatus==='all'?'active':''; ?>">All Tickets <span class="ft-count"><?php echo $openCount+$inProgressCount+$closedCount; ?></span></a>
  <a href="<?php echo ticketUrl(['status'=>'open','page'=>1]); ?>" class="filter-tab tab-open <?php echo $filterStatus==='open'?'active':''; ?>"><span class="tab-dot tab-dot-open"></span>Open <span class="ft-count"><?php echo $openCount; ?></span></a>
  <a href="<?php echo ticketUrl(['status'=>'in_progress','page'=>1]); ?>" class="filter-tab tab-inprogress <?php echo $filterStatus==='in_progress'?'active':''; ?>"><span class="tab-dot tab-dot-inprogress"></span>In Progress <span class="ft-count"><?php echo $inProgressCount; ?></span></a>
  <a href="<?php echo ticketUrl(['status'=>'closed','page'=>1]); ?>" class="filter-tab tab-closed <?php echo $filterStatus==='closed'?'active':''; ?>"><span class="tab-dot tab-dot-closed"></span>Closed <span class="ft-count"><?php echo $closedCount; ?></span></a>
</div>

    <!-- ── Advanced filters ── -->
    <form method="get" class="adv-filter-bar">
      <div class="adv-filter-bar-header">
        <svg viewBox="0 0 24 24"><line x1="4" y1="6" x2="20" y2="6"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="11" y1="18" x2="13" y2="18"/></svg>
        <span>Filters &amp; Options</span>
      </div>
      <div class="adv-filter-bar-body">
      <input type="hidden" name="status" value="<?php echo htmlspecialchars($filterStatus); ?>">
      <input type="hidden" name="per_page" value="<?php echo $perPage; ?>">
      <input type="hidden" name="page" value="1">
      <div class="adv-filter-group"><label>Priority</label>
        <select name="priority" class="adv-filter-select">
          <option value="">All priorities</option>
          <option value="high" <?php echo $filterPriority==='high'?'selected':''; ?>>🔴 High</option>
          <option value="medium" <?php echo $filterPriority==='medium'?'selected':''; ?>>🟡 Medium</option>
          <option value="low" <?php echo $filterPriority==='low'?'selected':''; ?>>🟢 Low</option>
        </select>
      </div>
      <div class="adv-filter-group"><label>From Department</label>
        <select name="dept" class="adv-filter-select">
          <option value="">All departments</option>
          <?php foreach ($deptOptions as $d): ?><option value="<?php echo htmlspecialchars($d); ?>" <?php echo $filterDept===$d?'selected':''; ?>><?php echo htmlspecialchars($d); ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="adv-filter-group"><label>Category</label>
        <select name="category" class="adv-filter-select">
          <option value="">All categories</option>
          <?php foreach ($categoryOptions as $cat):
            $catShort = strpos($cat['category_name'],' / ') !== false ? trim(substr($cat['category_name'], strpos($cat['category_name'],' / ')+3)) : $cat['category_name'];
          ?><option value="<?php echo (int)$cat['category_id']; ?>" <?php echo $filterCategory==(string)$cat['category_id']?'selected':''; ?>><?php echo htmlspecialchars($catShort); ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="adv-filter-group"><label>Date From</label><input type="date" name="date_from" class="adv-filter-input" value="<?php echo htmlspecialchars($filterDateFrom); ?>" max="<?php echo date('Y-m-d'); ?>"></div>
      <div class="adv-filter-group"><label>Date To</label><input type="date" name="date_to" class="adv-filter-input" value="<?php echo htmlspecialchars($filterDateTo); ?>" max="<?php echo date('Y-m-d'); ?>"></div>
      <div class="adv-filter-actions">
        <button type="submit" class="btn-apply-filter">Apply Filters</button>
        <a href="tickets.php?status=<?php echo htmlspecialchars($filterStatus); ?>" class="btn-reset-filter">Reset</a>
      </div>
      </div><!-- /.adv-filter-bar-body -->
    </form>

    <!-- ── Active chips ── -->
    <?php if ($activeFilterCount > 0): ?>
    <div class="active-chips">
      <span style="font-size:12px;color:var(--g500);font-weight:500;">Active:</span>
      <?php if ($filterPriority): ?><span class="chip">Priority: <?php echo ucfirst($filterPriority); ?><a class="chip-x" href="<?php echo ticketUrl(['priority'=>'','page'=>1]); ?>">×</a></span><?php endif; ?>
      <?php if ($filterDept): ?><span class="chip">Dept: <?php echo htmlspecialchars($filterDept); ?><a class="chip-x" href="<?php echo ticketUrl(['dept'=>'','page'=>1]); ?>">×</a></span><?php endif; ?>
      <?php if ($filterCategory):
        $activeCatName = '';
        foreach ($categoryOptions as $cat) { if ((string)$cat['category_id'] === $filterCategory) { $activeCatName = strpos($cat['category_name'],' / ')!==false ? trim(substr($cat['category_name'],strpos($cat['category_name'],' / ')+3)) : $cat['category_name']; break; } }
      ?><span class="chip">Category: <?php echo htmlspecialchars($activeCatName); ?><a class="chip-x" href="<?php echo ticketUrl(['category'=>'','page'=>1]); ?>">×</a></span><?php endif; ?>
      <?php if ($filterDateFrom): ?><span class="chip">From: <?php echo date('d M Y',strtotime($filterDateFrom)); ?><a class="chip-x" href="<?php echo ticketUrl(['date_from'=>'','page'=>1]); ?>">×</a></span><?php endif; ?>
      <?php if ($filterDateTo): ?><span class="chip">To: <?php echo date('d M Y',strtotime($filterDateTo)); ?><a class="chip-x" href="<?php echo ticketUrl(['date_to'=>'','page'=>1]); ?>">×</a></span><?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── Toolbar ── -->
    <div class="toolbar">
      <div class="toolbar-left">
        <span class="sec-title"><?php
  if ($filterStatus === 'open') echo 'Open Tickets';
  elseif ($filterStatus === 'in_progress') echo 'In Progress Tickets';
  elseif ($filterStatus === 'closed') echo 'Closed Tickets';
  else echo 'All Tickets';
?></span>
        <span class="result-count" id="result-label">— <?php echo $totalTickets; ?> ticket<?php echo $totalTickets!==1?'s':''; ?></span>
      </div>
      <div class="toolbar-right">
        <div class="search-wrap">
          <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
          <input type="text" id="ticket-search" class="search-input" placeholder="Search tickets…" autocomplete="off"/>
        </div>
        <form method="get">
          <input type="hidden" name="status" value="<?php echo htmlspecialchars($filterStatus); ?>">
          <input type="hidden" name="page" value="1">
          <select name="per_page" class="perpage-select" onchange="this.form.submit()">
            <?php foreach([10,25,50] as $n): ?><option value="<?php echo $n; ?>" <?php echo $perPage===$n?'selected':''; ?>><?php echo $n; ?> per page</option><?php endforeach; ?>
          </select>
        </form>
      </div>
    </div>

    <!-- ── Table ── -->
    <div class="tbl-card"><div class="tbl-wrap">
      <table id="ticket-table">
        <colgroup>
          <col class="col-id">
          <col class="col-title">
          <col class="col-dept">
          <col class="col-status">
          <col class="col-priority">
          <col class="col-category">
          <col class="col-assigned">
          <col class="col-action">
        </colgroup>
        <thead><tr>
          <th>Ticket ID</th>
          <th>Title</th>
          <th>From Department</th>
          <th>Status</th>
          <th>Priority</th>
          <th>Category</th>
          <th>Assigned To</th>
          <th>Action</th>
        </tr></thead>
        <tbody id="ticket-tbody">
          <?php if (empty($tickets)): ?>
          <tr><td colspan="8"><div class="empty">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14,2 14,8 20,8"/></svg>
            <h3>No tickets found</h3><p>No complaints match your current filter.</p>
          </div></td></tr>
          <?php else: ?>
          <?php foreach ($tickets as $t):
            $s           = strtolower($t['status']);
            $pri         = strtolower($t['priority'] ?? 'medium');
            $isHighOpen  = ($pri === 'high' && $s === 'open');
            $statusLabel = $s === 'in_progress' ? 'In Progress' : ucfirst($s);
            if ($pri === 'high') { $flagFill = '#DC2626'; }
            elseif ($pri === 'medium') { $flagFill = '#EAB308'; }
            elseif ($pri === 'low') { $flagFill = '#3B82F6'; }
            else { $flagFill = '#64748b'; }
          ?>
          <tr data-id="<?php echo strtolower(htmlspecialchars($t['ticket_id'])); ?>"
              data-title="<?php echo strtolower(htmlspecialchars($t['title'])); ?>"
              data-dept="<?php echo strtolower(htmlspecialchars($t['my_department']??'')); ?>"
              data-cat="<?php echo strtolower(htmlspecialchars($t['category_name']??'')); ?>"
              style="<?php echo $isHighOpen ? 'background:#FFFAFA;' : ''; ?>">

            <!-- Ticket ID -->
            <td>
              <a class="tid-link" href="ticket_detail.php?id=<?php echo urlencode($t['ticket_id']); ?>">
                <?php echo htmlspecialchars($t['ticket_id']); ?>
              </a>
            </td>

            <!-- Title -->
            <td>
              <div class="title-cell">
                <?php if ($isHighOpen): ?><span class="high-dot"></span><?php endif; ?>
                <?php echo htmlspecialchars($t['title']); ?>
              </div>
            </td>

            <!-- From Department + date/time stacked -->
            <td>
              <div class="dept-cell">
                <span class="dept-name" title="<?php echo htmlspecialchars($t['my_department'] ?? '—'); ?>">
                  <?php echo htmlspecialchars($t['my_department'] ?? '—'); ?>
                </span>
                <span class="dept-datetime">
                  <?php echo date('d M Y, H:i', strtotime($t['created_at'])); ?>
                </span>
              </div>
            </td>

            <!-- Status -->
            <td><span class="bdg bdg-<?php echo $s; ?>"><?php echo $statusLabel; ?></span></td>

            <!-- Priority — flag style -->
            <td>
              <span class="priority-pill pp-<?php echo $pri; ?>">
                <svg class="priority-flag-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"
                     fill="<?php echo $flagFill; ?>" stroke="<?php echo $flagFill; ?>" aria-hidden="true">
                  <path d="M4 3h13l-3 5 3 5H4V3z" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                  <line x1="4" y1="3" x2="4" y2="21" stroke-width="2" stroke-linecap="round"/>
                </svg>
                <?php echo ucfirst($pri); ?>
              </span>
            </td>

            <!-- Category -->
            <td>
              <?php if (!empty($t['category_name'])): ?>
              <span class="cat-badge" title="<?php echo htmlspecialchars($t['category_name']); ?>">
                <?php
                  $catDisplay = $t['category_name'];
                  if (strpos($catDisplay, ' / ') !== false) {
                      $catDisplay = trim(substr($catDisplay, strpos($catDisplay, ' / ') + 3));
                  }
                  echo htmlspecialchars($catDisplay);
                ?>
              </span>
              <?php else: ?><span style="font-size:12px;color:#9CA3AF;font-style:italic;">—</span><?php endif; ?>
            </td>

            <!-- Assigned To -->
            <td>
              <?php if (!empty($t['assigned_staff_name'])): ?>
              <div class="assigned-cell">
                <div class="staff-avatar-sm"><?php echo staffInitials($t['assigned_staff_name']); ?></div>
                <span class="assigned-name" title="<?php echo htmlspecialchars($t['assigned_staff_name']); ?>"><?php echo htmlspecialchars($t['assigned_staff_name']); ?></span>
              </div>
              <?php else: ?>
              <span class="unassigned-tag">Unassigned</span>
              <?php endif; ?>
            </td>

            <!-- Action -->
            <td>
              <a class="btn-view" href="ticket_detail.php?id=<?php echo urlencode($t['ticket_id']); ?>">
                View
                <svg viewBox="0 0 24 24"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
              </a>
            </td>

          </tr>
          <?php endforeach; ?>
          <tr id="no-search-row"><td colspan="8"><div class="empty">
            <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
            <h3>No results</h3><p>No tickets match your search.</p>
          </div></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div></div>

    <!-- ── Pagination ── -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination-bar">
      <span class="pg-info">Showing <?php echo $offset+1; ?>–<?php echo min($offset+$perPage,$totalTickets); ?> of <?php echo $totalTickets; ?> tickets</span>
      <div class="pg-btns">
        <?php if ($page<=1): ?><span class="pg-btn disabled">← Prev</span><?php else: ?><a class="pg-btn" href="<?php echo ticketUrl(['page'=>$page-1]); ?>">← Prev</a><?php endif; ?>
        <?php $window=2;$start=max(1,$page-$window);$end=min($totalPages,$page+$window);
          if($start>1){echo '<a class="pg-btn" href="'.ticketUrl(['page'=>1]).'">1</a>';if($start>2)echo '<span class="pg-btn disabled" style="border:none">…</span>';}
          for($p=$start;$p<=$end;$p++) echo '<a class="pg-btn'.($p===$page?' active':'').'" href="'.ticketUrl(['page'=>$p]).'">'.$p.'</a>';
          if($end<$totalPages){if($end<$totalPages-1)echo '<span class="pg-btn disabled" style="border:none">…</span>';echo '<a class="pg-btn" href="'.ticketUrl(['page'=>$totalPages]).'">'.$totalPages.'</a>';}
        ?>
        <?php if ($page>=$totalPages): ?><span class="pg-btn disabled">Next →</span><?php else: ?><a class="pg-btn" href="<?php echo ticketUrl(['page'=>$page+1]); ?>">Next →</a><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

  </div></main>
<script>
(function(){
  var input  = document.getElementById('ticket-search'),
      tbody  = document.getElementById('ticket-tbody'),
      noRow  = document.getElementById('no-search-row'),
      label  = document.getElementById('result-label'),
      total  = <?php echo $totalTickets; ?>;
  if (!input || !tbody) return;
  input.addEventListener('input', function(){
    var q = this.value.trim().toLowerCase(), visible = 0;
    tbody.querySelectorAll('tr[data-id]').forEach(function(row){
      var match = !q || row.dataset.id.includes(q) || row.dataset.title.includes(q) || row.dataset.dept.includes(q) || row.dataset.cat.includes(q);
      row.style.display = match ? '' : 'none';
      if (match) visible++;
    });
    noRow.style.display = (q && visible === 0) ? '' : 'none';
    label.textContent   = q
      ? ('— ' + visible + ' result' + (visible !== 1 ? 's' : ''))
      : ('— ' + total  + ' ticket' + (total  !== 1 ? 's' : ''));
  });
})();
</script>
</body>
</html>