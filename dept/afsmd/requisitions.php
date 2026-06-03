<?php
// dept/afsmd/requisitions.php
require_once __DIR__ . '/../auth_guard.php';
if (isset($_GET['logout'])) { staffLogout(); }
require_once __DIR__ . '/../../db_connect.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$filterStatus  = $_GET['status'] ?? 'all';
$allowedFilter = ['all','pending','approved','rejected','completed'];
if (!in_array($filterStatus, $allowedFilter)) $filterStatus = 'all';

$allowedPerPage = [10,25,50];
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
if (!in_array($perPage, $allowedPerPage)) $perPage = 10;

$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$filterUrgency  = $_GET['urgency']   ?? '';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo   = $_GET['date_to']   ?? '';
$filterDept     = $_GET['dept']      ?? '';
$filterCategory = $_GET['category']  ?? '';

$allowedUrgency = ['','normal','urgent'];
if (!in_array($filterUrgency, $allowedUrgency)) $filterUrgency = '';

$extraWhere  = '';
$extraParams = [];
$extraTypes  = '';

if ($filterUrgency !== '')  { $extraWhere .= " AND r.urgency = ?";                           $extraParams[] = $filterUrgency;       $extraTypes .= 's'; }
if ($filterDateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDateFrom)) { $extraWhere .= " AND DATE(r.created_at) >= ?"; $extraParams[] = $filterDateFrom; $extraTypes .= 's'; }
if ($filterDateTo   !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDateTo))   { $extraWhere .= " AND DATE(r.created_at) <= ?"; $extraParams[] = $filterDateTo;   $extraTypes .= 's'; }
if ($filterDept !== '')     { $extraWhere .= " AND r.my_department = ?";                     $extraParams[] = $filterDept;          $extraTypes .= 's'; }
if ($filterCategory !== '') { $extraWhere .= " AND r.category = ?";                          $extraParams[] = $filterCategory;      $extraTypes .= 's'; }

function bindAll($stmt, string $types, array $params): void {
    $refs = [];
    foreach ($params as $key => $val) {
        $refs[$key] = &$params[$key];
    }
    $stmt->bind_param($types, ...$refs);
}


// Count by status (for sidebar + tabs) — only dept_id = 1 (AFSMD)
$cStmt = $conn->prepare(
    "SELECT
        SUM(r.status='pending')   AS pc,
        SUM(r.status='approved')  AS ac,
        SUM(r.status='rejected')  AS rc,
        SUM(r.status='completed') AS cc
     FROM requisitions r
     LEFT JOIN staff s ON s.staff_id = r.assigned_to
     WHERE 1=1 $extraWhere"
);
if (!empty($extraParams)) {
    bindAll($cStmt, $extraTypes, $extraParams);
}
$cStmt->execute();
$counts = $cStmt->get_result()->fetch_assoc(); $cStmt->close();
$reqPendingCount   = (int)($counts['pc'] ?? 0);
$reqApprovedCount  = (int)($counts['ac'] ?? 0);
$reqRejectedCount  = (int)($counts['rc'] ?? 0);
$reqCompletedCount = (int)($counts['cc'] ?? 0);

// Total for pagination
$statusWhere = $filterStatus !== 'all' ? " AND r.status = ?" : '';
$tStmt = $conn->prepare("SELECT COUNT(*) AS total FROM requisitions r LEFT JOIN staff s ON s.staff_id = r.assigned_to WHERE 1=1 $extraWhere $statusWhere");
if ($filterStatus !== 'all') {
    bindAll($tStmt, $extraTypes . 's', array_merge($extraParams, [$filterStatus]));
} elseif (!empty($extraParams)) {
    bindAll($tStmt, $extraTypes, $extraParams);
}
$tStmt->execute();
$totalReqs = (int)($tStmt->get_result()->fetch_assoc()['total'] ?? 0); $tStmt->close();

$totalPages = max(1, (int)ceil($totalReqs / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

// Fetch rows
$rows = [];
$dataParams = $filterStatus !== 'all' ? array_merge($extraParams, [$filterStatus, $perPage, $offset]) : array_merge($extraParams, [$perPage, $offset]);
$dataTypes  = $filterStatus !== 'all' ? $extraTypes . 'sii' : $extraTypes . 'ii';
$dataStmt = $conn->prepare(
    "SELECT r.req_id, r.ref_number, r.submitter_id, r.submitter_type,
            r.my_department, r.category, r.item_name, r.quantity,
            r.location, r.urgency, r.status, r.assigned_to,
            r.remarks, r.created_at,
            s.full_name AS assigned_staff_name
     FROM requisitions r
     LEFT JOIN staff s ON s.staff_id = r.assigned_to
     WHERE 1=1 $extraWhere $statusWhere
     ORDER BY r.created_at DESC LIMIT ? OFFSET ?"
);
if (!empty($dataParams)) {
    bindAll($dataStmt, $dataTypes, $dataParams);
}
$dataStmt->execute();
$res = $dataStmt->get_result();
while ($row = $res->fetch_assoc()) $rows[] = $row;
$dataStmt->close();

// Dept options
$deptOptions = [];
$dStmt = $conn->prepare("SELECT name FROM my_departments ORDER BY sort_order ASC");
$dStmt->execute(); $dRes = $dStmt->get_result();
while ($row = $dRes->fetch_assoc()) $deptOptions[] = $row['name'];
$dStmt->close();

// Category options (unique from requisitions table)
$catOptions = [];
$catStmt = $conn->query("SELECT DISTINCT category FROM requisitions ORDER BY category ASC");
while ($row = $catStmt->fetch_assoc()) $catOptions[] = $row['category'];

// Nav state
if ($filterStatus === 'pending')       $activeNav = 'requisitions-pending';
elseif ($filterStatus === 'approved')  $activeNav = 'requisitions-approved';
elseif ($filterStatus === 'rejected')  $activeNav = 'requisitions-rejected';
elseif ($filterStatus === 'completed') $activeNav = 'requisitions-completed';
else $activeNav = 'requisitions';

$pageTitle    = 'Requisitions';
$pageSubtitle = 'Administration & Facilities Management Department';

// Also pass ticket counts so sidebar badges still work
$tcStmt = $conn->prepare("SELECT SUM(status='open') AS oc, SUM(status='in_progress') AS ipc, SUM(status='closed') AS cc FROM complaints WHERE dept_id = ?");
$tcStmt->bind_param("i", $deptId);
$tcStmt->execute();
$tc = $tcStmt->get_result()->fetch_assoc(); $tcStmt->close();
$openCount       = (int)($tc['oc']  ?? 0);
$inProgressCount = (int)($tc['ipc'] ?? 0);
$closedCount     = (int)($tc['cc']  ?? 0);

function reqUrl(array $overrides = []): string {
    $params = array_merge([
        'status'    => $_GET['status']    ?? 'all',
        'per_page'  => $_GET['per_page']  ?? 10,
        'page'      => $_GET['page']      ?? 1,
        'urgency'   => $_GET['urgency']   ?? '',
        'date_from' => $_GET['date_from'] ?? '',
        'date_to'   => $_GET['date_to']   ?? '',
        'dept'      => $_GET['dept']      ?? '',
        'category'  => $_GET['category']  ?? '',
    ], $overrides);
    $params = array_filter($params, fn($v) => $v !== '');
    return 'requisitions.php?' . http_build_query($params);
}

if (!function_exists('staffInitials')) {
function staffInitials(string $name): string {
    $parts = explode(' ', trim($name));
    $ini = strtoupper(substr($parts[0],0,1));
    if (count($parts) > 1) $ini .= strtoupper(substr($parts[count($parts)-1],0,1));
    return $ini;
}
} // ← closing the if (!function_exists) block

$activeFilterCount = (int)($filterUrgency!=='')+(int)($filterDateFrom!=='')+(int)($filterDateTo!=='')+(int)($filterDept!=='')+(int)($filterCategory!=='');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Requisitions | UniKL Help Desk – AFSMD</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet"/>
  <style>
/* ── Filter tabs ── */
.filter-bar{display:flex;align-items:center;gap:6px;margin-bottom:20px;flex-wrap:wrap;}
.filter-tab{display:inline-flex;align-items:center;gap:7px;padding:8px 18px;border-radius:9px;font-size:14px;font-weight:500;text-decoration:none;border:1.5px solid var(--g200);color:var(--g500);background:white;transition:border-color .15s,color .15s,background .15s,box-shadow .15s;white-space:nowrap;}
.filter-tab:hover:not(.active){border-color:var(--g300);color:var(--g700);background:var(--g100);}
.filter-tab.active.tab-all      {background:var(--accent);border-color:var(--accent);color:white;font-weight:600;box-shadow:0 2px 10px rgba(91,140,204,.22);}
.filter-tab.active.tab-pending  {background:#D97706;border-color:#D97706;color:white;font-weight:600;}
.filter-tab.active.tab-approved {background:#059669;border-color:#059669;color:white;font-weight:600;}
.filter-tab.active.tab-rejected   {background:#DC2626;border-color:#DC2626;color:white;font-weight:600;}
.filter-tab.active.tab-completed  {background:#7C3AED;border-color:#7C3AED;color:white;font-weight:600;}
.ft-count{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:18px;padding:0 5px;border-radius:9px;font-size:11px;font-weight:700;background:var(--g100);color:var(--g500);}
.filter-tab.active .ft-count{background:rgba(255,255,255,.28);color:white;}
.tab-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0;}
.tab-dot-pending{background:#F59E0B;}.tab-dot-approved{background:#10B981;}.tab-dot-rejected{background:#EF4444;}.tab-dot-completed{background:#7C3AED;}
.filter-tab.active .tab-dot{background:rgba(255,255,255,.75);}

/* ── Toolbar ── */
.toolbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:10px;}
.toolbar-left,.toolbar-right{display:flex;align-items:center;gap:8px;}
.sec-title{font-size:16px;font-weight:600;color:var(--g900);}
.result-count{font-size:13px;color:var(--g500);}
.search-wrap{position:relative;display:flex;align-items:center;}
.search-wrap svg{position:absolute;left:10px;width:14px;height:14px;stroke:var(--g400);fill:none;stroke-width:2;pointer-events:none;}
.search-input{padding:8px 12px 8px 32px;font-size:13px;border-radius:8px;border:1.5px solid var(--g200);background:white;color:var(--g900);width:200px;outline:none;font-family:'DM Sans',sans-serif;}
.search-input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(91,140,204,.08);}
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
.adv-filter-select:focus,.adv-filter-input:focus{border-color:var(--accent);background:white;box-shadow:0 0 0 3px rgba(91,140,204,.07);}
.adv-filter-actions{display:flex;align-items:center;gap:8px;margin-left:auto;padding-bottom:1px;}
.btn-apply-filter{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;font-size:13px;font-weight:600;font-family:'DM Sans',sans-serif;border-radius:9px;border:none;background:var(--accent);color:white;cursor:pointer;box-shadow:0 2px 8px rgba(91,140,204,.18);transition:background .15s,box-shadow .15s;}
.btn-apply-filter:hover{background:#4a7ab8;box-shadow:0 4px 14px rgba(91,140,204,.28);}
.btn-reset-filter{display:inline-flex;align-items:center;gap:5px;padding:9px 14px;font-size:13px;font-weight:500;font-family:'DM Sans',sans-serif;border-radius:9px;border:1.5px solid var(--g200);background:white;color:var(--g500);text-decoration:none;transition:border-color .15s,color .15s;}
.btn-reset-filter:hover{border-color:var(--g300);color:var(--g700);}

/* ── Active filter chips ── */
.active-chips{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:14px;}
.chip{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;background:#EFF6FF;border:1.5px solid #BFDBFE;border-radius:20px;font-size:12px;font-weight:600;color:var(--accent);}
.chip-x{font-size:14px;font-weight:700;color:var(--accent);text-decoration:none;line-height:1;opacity:.6;}

/* ── Table card ── */
.tbl-card{background:white;border-radius:12px;border:1px solid var(--g200);overflow:hidden;width:100%;}
.tbl-wrap{width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch;}
table{width:100%;min-width:800px;border-collapse:collapse;font-size:14px;table-layout:fixed;}
thead th{background:var(--g100);padding:10px 12px;text-align:left;font-size:12px;font-weight:700;color:var(--g500);text-transform:uppercase;letter-spacing:.06em;border-bottom:1px solid var(--g200);white-space:nowrap;overflow:hidden;}
tbody tr{border-bottom:1px solid var(--g200);transition:background .12s;}
tbody tr:last-child{border-bottom:none;}
tbody tr:hover{background:#F8FAFF;}
tbody td{padding:11px 12px;color:var(--g700);vertical-align:middle;overflow:hidden;}

col.col-ref      { width: 14%; }
col.col-dept     { width: 16%; }
col.col-cat      { width: 11%; }
col.col-item     { width: 16%; }
col.col-urgency  { width: 8%;  }
thead th:nth-child(5) { text-align: left; padding-left: 6px; }
tbody td:nth-child(5) { padding-left: 6px; }
col.col-status   { width: 11%; }
col.col-assigned { width: 16%; }
col.col-action   { width: 9%;  }

/* ── Ref number ── */
.ref-link{font-weight:600;color:var(--accent);font-size:12px;text-decoration:none;font-family:monospace;letter-spacing:.01em;background:#EFF6FF;padding:3px 6px;border-radius:5px;white-space:nowrap;display:inline-block;max-width:100%;overflow:hidden;text-overflow:ellipsis;}
.ref-link:hover{color:#4a7ab8;background:#DBEAFE;}

/* ── Dept cell ── */
.dept-cell{display:flex;flex-direction:column;gap:2px;}
.dept-cell .dept-name{font-size:14px;color:var(--g700);font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.dept-cell .dept-datetime{font-size:12px;color:var(--g400);white-space:nowrap;}

/* ── Item cell ── */
.item-cell{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:14px;}

/* ── Status badges (matched to homepage pill style) ── */
.bdg{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;padding:4px 11px;border-radius:20px;white-space:nowrap;letter-spacing:.01em;}
.bdg-dot{display:none;}
.bdg-pending   {background:#FEF3E2;color:#92520C;}
.bdg-pending   .bdg-dot{background:#F59E0B;}
.bdg-approved  {background:#F0FDF4;color:#166534;}
.bdg-approved  .bdg-dot{background:#22C55E;}
.bdg-rejected  {background:#FEF2F2;color:#991B1B;}
.bdg-rejected  .bdg-dot{background:#DC2626;}
.bdg-completed {background:#dcdee1;color:#374151;}
.bdg-completed .bdg-dot{background:#9a9ea4;}

/* ── Urgency pill ── */
.urgency-pill{display:inline-flex;align-items:center;gap:4px;font-size:.75rem;font-weight:600;letter-spacing:.01em;text-transform:capitalize;background:none;border:none;padding:0;white-space:nowrap;}
.urgency-flag-icon{width:13px;height:13px;flex-shrink:0;vertical-align:middle;position:relative;top:-1px;}
.urgency-pill.up-urgent{color:#DC2626;}
.urgency-pill.up-urgent .urgency-flag-icon{fill:#DC2626;stroke:#DC2626;}
.urgency-pill.up-normal{color:#2563EB;}
.urgency-pill.up-normal .urgency-flag-icon{fill:#3B82F6;stroke:#3B82F6;}
.urgency-dot{display:none;}

/* ── Qty badge ── */
.qty-badge{display:inline-flex;align-items:center;justify-content:center;font-size:13px;font-weight:600;color:var(--g700);padding:0;}

/* ── Assigned ── */
.assigned-cell{display:flex;align-items:center;gap:7px;overflow:hidden;}
.staff-avatar-sm{width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,#001f5c,#1a56db);color:white;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;flex-shrink:0;}
.assigned-name{font-size:13px;color:var(--g700);font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.unassigned-tag{font-size:13px;color:#9CA3AF;font-style:italic;}

/* ── Cat text ── */
.cat-text{font-size:14px;font-weight:500;color:var(--g700);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;max-width:100%;}

/* ── Item cell ── */
.item-main{font-size:14px;font-weight:600;color:var(--g900);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.item-meta{font-size:12px;color:var(--g400);margin-top:2px;white-space:nowrap;}
.item-meta span+span::before{content:' · ';}

/* ── View button ── */
.btn-view{display:inline-flex;align-items:center;gap:4px;padding:5px 12px;font-size:13px;font-weight:600;font-family:'DM Sans',sans-serif;text-decoration:none;border-radius:8px;border:1.5px solid var(--accent);color:var(--accent);background:transparent;white-space:nowrap;transition:background .15s,color .15s,transform .1s;}
.btn-view:hover{background:var(--accent);color:white;transform:translateY(-1px);}
.btn-view svg{width:12px;height:12px;stroke:currentColor;fill:none;stroke-width:2.2;}

/* ── Empty ── */
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
  <a href="<?php echo reqUrl(['status'=>'all','page'=>1]); ?>" class="filter-tab tab-all <?php echo $filterStatus==='all'?'active':''; ?>">
    All Requisitions <span class="ft-count"><?php echo $reqPendingCount+$reqApprovedCount+$reqRejectedCount+$reqCompletedCount; ?></span>
  </a>
  <a href="<?php echo reqUrl(['status'=>'pending','page'=>1]); ?>" class="filter-tab tab-pending <?php echo $filterStatus==='pending'?'active':''; ?>">
    <span class="tab-dot tab-dot-pending"></span>Pending <span class="ft-count"><?php echo $reqPendingCount; ?></span>
  </a>
  <a href="<?php echo reqUrl(['status'=>'approved','page'=>1]); ?>" class="filter-tab tab-approved <?php echo $filterStatus==='approved'?'active':''; ?>">
    <span class="tab-dot tab-dot-approved"></span>Approved <span class="ft-count"><?php echo $reqApprovedCount; ?></span>
  </a>
  <a href="<?php echo reqUrl(['status'=>'rejected','page'=>1]); ?>" class="filter-tab tab-rejected <?php echo $filterStatus==='rejected'?'active':''; ?>">
    <span class="tab-dot tab-dot-rejected"></span>Rejected <span class="ft-count"><?php echo $reqRejectedCount; ?></span>
  </a>
  <a href="<?php echo reqUrl(['status'=>'completed','page'=>1]); ?>" class="filter-tab tab-completed <?php echo $filterStatus==='completed'?'active':''; ?>">
    <span class="tab-dot tab-dot-completed"></span>Completed <span class="ft-count"><?php echo $reqCompletedCount; ?></span>
  </a>
</div>

<!-- ── Advanced filters ── -->
<form method="get" class="adv-filter-bar">
  <div class="adv-filter-bar-header">
    <svg viewBox="0 0 24 24"><line x1="4" y1="6" x2="20" y2="6"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="11" y1="18" x2="13" y2="18"/></svg>
    <span>Filters &amp; Options</span>
  </div>
  <div class="adv-filter-bar-body">
    <input type="hidden" name="status"   value="<?php echo htmlspecialchars($filterStatus); ?>">
    <input type="hidden" name="per_page" value="<?php echo $perPage; ?>">
    <input type="hidden" name="page"     value="1">

    <div class="adv-filter-group"><label>Urgency</label>
      <select name="urgency" class="adv-filter-select">
        <option value="">All urgency</option>
        <option value="urgent" <?php echo $filterUrgency==='urgent'?'selected':''; ?>>🔴 Urgent</option>
        <option value="normal" <?php echo $filterUrgency==='normal'?'selected':''; ?>>🔵 Normal</option>
      </select>
    </div>

    <div class="adv-filter-group"><label>From Department</label>
      <select name="dept" class="adv-filter-select">
        <option value="">All departments</option>
        <?php foreach ($deptOptions as $d): ?>
          <option value="<?php echo htmlspecialchars($d); ?>" <?php echo $filterDept===$d?'selected':''; ?>><?php echo htmlspecialchars($d); ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="adv-filter-group"><label>Category</label>
      <select name="category" class="adv-filter-select">
        <option value="">All categories</option>
        <?php foreach ($catOptions as $cat): ?>
          <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $filterCategory===$cat?'selected':''; ?>><?php echo htmlspecialchars($cat); ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="adv-filter-group"><label>Date From</label>
      <input type="date" name="date_from" class="adv-filter-input" value="<?php echo htmlspecialchars($filterDateFrom); ?>" max="<?php echo date('Y-m-d'); ?>">
    </div>
    <div class="adv-filter-group"><label>Date To</label>
      <input type="date" name="date_to" class="adv-filter-input" value="<?php echo htmlspecialchars($filterDateTo); ?>" max="<?php echo date('Y-m-d'); ?>">
    </div>

    <div class="adv-filter-actions">
  <a href="requisitions.php?status=<?php echo htmlspecialchars($filterStatus); ?>" class="btn-reset-filter">Reset</a>
</div>
  </div>
</form>

<br>

<!-- ── Active chips ── -->
<?php if ($activeFilterCount > 0): ?>
<div class="active-chips">
  <span style="font-size:12px;color:var(--g500);font-weight:500;">Active:</span>
  <?php if ($filterUrgency):  ?><span class="chip">Urgency: <?php echo ucfirst($filterUrgency); ?><a class="chip-x" href="<?php echo reqUrl(['urgency'=>'','page'=>1]); ?>">×</a></span><?php endif; ?>
  <?php if ($filterDept):     ?><span class="chip">Dept: <?php echo htmlspecialchars($filterDept); ?><a class="chip-x" href="<?php echo reqUrl(['dept'=>'','page'=>1]); ?>">×</a></span><?php endif; ?>
  <?php if ($filterCategory): ?><span class="chip">Category: <?php echo htmlspecialchars($filterCategory); ?><a class="chip-x" href="<?php echo reqUrl(['category'=>'','page'=>1]); ?>">×</a></span><?php endif; ?>
  <?php if ($filterDateFrom): ?><span class="chip">From: <?php echo date('d M Y',strtotime($filterDateFrom)); ?><a class="chip-x" href="<?php echo reqUrl(['date_from'=>'','page'=>1]); ?>">×</a></span><?php endif; ?>
  <?php if ($filterDateTo):   ?><span class="chip">To: <?php echo date('d M Y',strtotime($filterDateTo)); ?><a class="chip-x" href="<?php echo reqUrl(['date_to'=>'','page'=>1]); ?>">×</a></span><?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Toolbar ── -->
<div class="toolbar">
  <div class="toolbar-left">
    <span class="sec-title"><?php
      if ($filterStatus==='pending')  echo 'Pending Requisitions';
      elseif ($filterStatus==='approved') echo 'Approved Requisitions';
      elseif ($filterStatus==='rejected')  echo 'Rejected Requisitions';
      elseif ($filterStatus==='completed') echo 'Completed Requisitions';
      else echo 'All Requisitions';
    ?></span>
    <span class="result-count" id="result-label">— <?php echo $totalReqs; ?> requisition<?php echo $totalReqs!==1?'s':''; ?></span>
  </div>
  <div class="toolbar-right">
    <div class="search-wrap">
      <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
      <input type="text" id="req-search" class="search-input" placeholder="Search requisitions…" autocomplete="off"/>
    </div>
    <form method="get">
      <input type="hidden" name="status" value="<?php echo htmlspecialchars($filterStatus); ?>">
      <input type="hidden" name="page"   value="1">
      <select name="per_page" class="perpage-select" onchange="this.form.submit()">
        <?php foreach([10,25,50] as $n): ?><option value="<?php echo $n; ?>" <?php echo $perPage===$n?'selected':''; ?>><?php echo $n; ?> per page</option><?php endforeach; ?>
      </select>
    </form>
  </div>
</div>


<!-- ── Table ── -->
<div class="tbl-card"><div class="tbl-wrap">
  <table id="req-table">
    <colgroup>
      <col class="col-ref">
      <col class="col-dept">
      <col class="col-cat">
      <col class="col-item">
      <col class="col-urgency">
      <col class="col-status">
      <col class="col-assigned">
      <col class="col-action">
    </colgroup>
    <thead><tr>
      <th>Ref Number</th>
      <th>From Department</th>
      <th>Category</th>
      <th>Item</th>
      <th>Urgency</th>
      <th>Status</th>
      <th>Assigned To</th>
      <th>Action</th>
    </tr></thead>
    <tbody id="req-tbody">
      <?php if (empty($rows)): ?>
      <tr><td colspan="8"><div class="empty">
        <svg viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/><path d="M16 3H8a1 1 0 0 0-1 1v3h10V4a1 1 0 0 0-1-1z"/></svg>
        <h3>No requisitions found</h3><p>No requests match your current filter.</p>
      </div></td></tr>
      <?php else: ?>
      <?php foreach ($rows as $r):
        $st  = strtolower($r['status']);
        $urg = strtolower($r['urgency'] ?? 'normal');
      ?>
      <tr data-ref="<?php echo strtolower(htmlspecialchars($r['ref_number'])); ?>"
          data-item="<?php echo strtolower(htmlspecialchars($r['item_name'])); ?>"
          data-dept="<?php echo strtolower(htmlspecialchars($r['my_department']??'')); ?>"
          data-cat="<?php echo strtolower(htmlspecialchars($r['category']??'')); ?>">

        <!-- Ref Number -->
        <td>
          <a class="ref-link" href="requisition_detail.php?id=<?php echo urlencode($r['ref_number']); ?>"><?php echo htmlspecialchars($r['ref_number']); ?></a>
        </td>

        <!-- From Department + date -->
        <td>
          <div class="dept-cell">
            <span class="dept-name" title="<?php echo htmlspecialchars($r['my_department']??'—'); ?>"><?php echo htmlspecialchars($r['my_department']??'—'); ?></span>
            <span class="dept-datetime"><?php echo date('d M Y, H:i', strtotime($r['created_at'])); ?></span>
          </div>
        </td>

        <!-- Category -->
        <td><span class="cat-text" title="<?php echo htmlspecialchars($r['category']??''); ?>"><?php echo htmlspecialchars($r['category']??'—'); ?></span></td>

        <!-- Item -->
        <td>
          <div class="item-main" title="<?php echo htmlspecialchars($r['item_name']??''); ?>"><?php echo htmlspecialchars($r['item_name']??'—'); ?></div>
          <div class="item-meta">
            <span>Qty: <?php echo (int)$r['quantity']; ?></span>
            <?php if (!empty($r['location'])): ?><span><?php echo htmlspecialchars($r['location']); ?></span><?php endif; ?>
          </div>
        </td>

<!-- Urgency -->
<td>
  <span class="urgency-pill up-<?php echo $urg; ?>">
    <svg class="urgency-flag-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
      <path d="M4 3h13l-3 5 3 5H4V3z" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
      <line x1="4" y1="3" x2="4" y2="21" stroke-width="2" stroke-linecap="round"/>
    </svg>
    <?php echo ucfirst($urg); ?>
  </span>
</td>
        <!-- Status -->
        <td><span class="bdg bdg-<?php echo $st; ?>"><span class="bdg-dot"></span><?php echo ucfirst($st); ?></span></td>

        <!-- Assigned To -->
        <td>
          <?php if (!empty($r['assigned_staff_name'])): ?>
          <div class="assigned-cell">
            <div class="staff-avatar-sm"><?php echo staffInitials($r['assigned_staff_name']); ?></div>
            <span class="assigned-name" title="<?php echo htmlspecialchars($r['assigned_staff_name']); ?>"><?php echo htmlspecialchars($r['assigned_staff_name']); ?></span>
          </div>
          <?php else: ?><span class="unassigned-tag">Unassigned</span><?php endif; ?>
        </td>

        <!-- Action -->
        <td>
          <a class="btn-view" href="requisition_detail.php?id=<?php echo urlencode($r['ref_number']); ?>">
            View<svg viewBox="0 0 24 24"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
          </a>
        </td>

      </tr>
      <?php endforeach; ?>
      <tr id="no-search-row"><td colspan="8"><div class="empty">
        <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
        <h3>No results</h3><p>No requisitions match your search.</p>
      </div></td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div></div>

<!-- ── Pagination ── -->
<?php if ($totalPages > 1): ?>
<div class="pagination-bar">
  <span class="pg-info">Showing <?php echo $offset+1; ?>–<?php echo min($offset+$perPage,$totalReqs); ?> of <?php echo $totalReqs; ?> requisitions</span>
  <div class="pg-btns">
    <?php if ($page<=1): ?><span class="pg-btn disabled">← Prev</span><?php else: ?><a class="pg-btn" href="<?php echo reqUrl(['page'=>$page-1]); ?>">← Prev</a><?php endif; ?>
    <?php $window=2;$start=max(1,$page-$window);$end=min($totalPages,$page+$window);
      if($start>1){echo '<a class="pg-btn" href="'.reqUrl(['page'=>1]).'">1</a>';if($start>2)echo '<span class="pg-btn disabled" style="border:none">…</span>';}
      for($p=$start;$p<=$end;$p++) echo '<a class="pg-btn'.($p===$page?' active':'').'" href="'.reqUrl(['page'=>$p]).'">'.$p.'</a>';
      if($end<$totalPages){if($end<$totalPages-1)echo '<span class="pg-btn disabled" style="border:none">…</span>';echo '<a class="pg-btn" href="'.reqUrl(['page'=>$totalPages]).'">'.$totalPages.'</a>';}
    ?>
    <?php if ($page>=$totalPages): ?><span class="pg-btn disabled">Next →</span><?php else: ?><a class="pg-btn" href="<?php echo reqUrl(['page'=>$page+1]); ?>">Next →</a><?php endif; ?>
  </div>
</div>
<?php endif; ?>

</div></main>
<script>
(function(){
  var input = document.getElementById('req-search'),
      tbody = document.getElementById('req-tbody'),
      noRow = document.getElementById('no-search-row'),
      label = document.getElementById('result-label'),
      total = <?php echo $totalReqs; ?>;
  if (!input || !tbody) return;
  input.addEventListener('input', function(){
    var q = this.value.trim().toLowerCase(), visible = 0;
    tbody.querySelectorAll('tr[data-ref]').forEach(function(row){
      var match = !q || row.dataset.ref.includes(q) || row.dataset.item.includes(q) || row.dataset.dept.includes(q) || row.dataset.cat.includes(q);
      row.style.display = match ? '' : 'none';
      if (match) visible++;
    });
    noRow.style.display = (q && visible === 0) ? '' : 'none';
    label.textContent = q
      ? ('— ' + visible + ' result' + (visible !== 1 ? 's' : ''))
      : ('— ' + total  + ' requisition' + (total  !== 1 ? 's' : ''));
  });
})();
</script>

<script>
(function(){
  var form = document.querySelector('.adv-filter-bar');
  if (!form) return;
  // Auto-submit on any select or date input change
  form.querySelectorAll('select, input[type="date"]').forEach(function(el){
    el.addEventListener('change', function(){
      form.submit();
    });
  });
})();
</script>

</body>
</html>