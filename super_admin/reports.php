<?php
// super_admin/reports.php
session_start();
if (empty($_SESSION['staff_role']) || !in_array($_SESSION['staff_role'], ['super_admin', 'report_viewer'])) {
    header("Location: ../staff_login.php");
    exit;
}

// Department restriction for report_viewer
$allowedDepts = null;
if ($_SESSION['staff_role'] === 'report_viewer') {
    $allowedDepts = [1, 2, 4]; // AFSMD, Maintenance, IT only
}

$activePage = 'reports';
$pageTitle  = 'Reports';

require_once '../db_connect.php';

// ── Filter inputs ──────────────────────────────────────────────────────────────
$filterDept   = (int)($_GET['dept'] ?? 0);
// Prevent report_viewer from accessing restricted departments via URL manipulation
if ($allowedDepts && $filterDept > 0 && !in_array($filterDept, $allowedDepts)) {
    $filterDept = 0;
}
$filterStatus = trim($_GET['status']      ?? 'all');
$dateFrom     = trim($_GET['date_from']   ?? '');
$dateTo       = trim($_GET['date_to']     ?? '');
$export       = trim($_GET['export']      ?? '');

// ── Department meta ────────────────────────────────────────────────────────────
$deptColors = [
    1 => '#7C3AED',
    2 => '#059669',
    3 => '#DC2626',
    4 => '#2563EB',
    5 => '#D97706',
];
$deptShort = [
    1 => 'AFSMD',
    2 => 'Maintenance',
    3 => 'CCU',
    4 => 'IT Dept',
    5 => 'HCD',
];
$deptFull = [
    1 => 'Administration & Facilities Management',
    2 => 'Maintenance Department',
    3 => 'Corporate Communication Unit',
    4 => 'Information Technology Department',
    5 => 'Human Capital Department',
];

// ── Build WHERE clauses ────────────────────────────────────────────────────────
$where  = ['1=1'];
$params = [];
$types  = '';

// Enforce allowed departments for report_viewer
if ($allowedDepts) {
    $placeholders = implode(',', $allowedDepts);
    $where[] = "c.dept_id IN ($placeholders)";
}

if ($filterDept > 0) {
    $where[] = 'c.dept_id = ?';
    $params[] = $filterDept;
    $types .= 'i';
}
if ($filterStatus !== 'all' && in_array($filterStatus, ['open','in_progress','closed'])) {
    $where[] = 'c.status = ?';
    $params[] = $filterStatus;
    $types .= 's';
}
if ($dateFrom !== '') {
    $where[] = 'DATE(c.created_at) >= ?';
    $params[] = $dateFrom;
    $types .= 's';
}
if ($dateTo !== '') {
    $where[] = 'DATE(c.created_at) <= ?';
    $params[] = $dateTo;
    $types .= 's';
}

$whereSQL = implode(' AND ', $where);

// ── KPI totals (filtered) ──────────────────────────────────────────────────────
$kpiSQL = "
    SELECT
        COUNT(*)                        AS total,
        SUM(c.status = 'open')         AS open_count,
        SUM(c.status = 'in_progress')  AS inprogress_count,
        SUM(c.status = 'closed')       AS closed_count
    FROM complaints c
    WHERE $whereSQL
";
if ($params) {
    $stmt = $conn->prepare($kpiSQL);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $kpi = $stmt->get_result()->fetch_assoc();
} else {
    $kpi = $conn->query($kpiSQL)->fetch_assoc();
}

$kpiTotal    = (int)$kpi['total'];
$kpiOpen     = (int)$kpi['open_count'];
$kpiProgress = (int)$kpi['inprogress_count'];
$kpiClosed   = (int)$kpi['closed_count'];

// ── Per-department breakdown (filtered) ───────────────────────────────────────
$deptLimitSQL = $allowedDepts
    ? "AND d.dept_id IN (" . implode(',', $allowedDepts) . ")"
    : "";

$deptSQL = "
    SELECT
        d.dept_id,
        d.dept_name,
        COUNT(c.ticket_id)              AS total,
        SUM(c.status = 'open')         AS open_count,
        SUM(c.status = 'in_progress')  AS inprogress_count,
        SUM(c.status = 'closed')       AS closed_count
    FROM departments d
    LEFT JOIN complaints c ON c.dept_id = d.dept_id AND $whereSQL
    WHERE 1=1 $deptLimitSQL
    GROUP BY d.dept_id, d.dept_name
    ORDER BY d.dept_id
";
if ($params) {
    $stmt2 = $conn->prepare($deptSQL);
    $stmt2->bind_param($types, ...$params);
    $stmt2->execute();
    $deptRows = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $deptRows = $conn->query($deptSQL)->fetch_all(MYSQLI_ASSOC);
}

// ── CSV export ─────────────────────────────────────────────────────────────────
if ($export === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="unikl_report_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Department', 'Total Tickets', 'Open', 'In Progress', 'Closed', 'Resolution Rate (%)']);
    foreach ($deptRows as $row) {
        $t = (int)$row['total'];
        $cl = (int)$row['closed_count'];
        $rate = $t > 0 ? round($cl / $t * 100) : 0;
        $dId = (int)$row['dept_id'];
        fputcsv($out, [
            $deptFull[$dId] ?? $row['dept_name'],
            $t,
            (int)$row['open_count'],
            (int)$row['inprogress_count'],
            $cl,
            $rate . '%',
        ]);
    }
    fclose($out);
    exit;
}



include 'layout.php';
?>
<!-- ── Topbar (matches dashboard.php pattern with hamburger) ── -->
<div class="dash-topbar" style="
  height:65px; background:white; border-bottom:1px solid var(--gray-200);
  display:flex; align-items:center; justify-content:space-between;
  padding:0 32px; margin:-32px -32px 32px -32px;
  width:calc(100% + 64px); box-sizing:border-box;
">
  <div style="display:flex;align-items:center;gap:8px;font-size:15px;color:var(--gray-500);">
    <button class="hamburger-btn" onclick="toggleSidebar()" aria-label="Toggle menu"
      style="display:none;background:none;border:none;cursor:pointer;color:var(--gray-700);padding:6px;border-radius:8px;align-items:center;justify-content:center;" id="rpt-hamburger">
      <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
        <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>
      </svg>
    </button>
    <span style="color:var(--gray-300);">›</span>
    <span style="font-weight:600;color:var(--gray-900);font-size:15px;">Reports</span>
  </div>
  <div style="font-size:14px;color:var(--gray-500);display:flex;align-items:center;gap:6px;">
    <svg viewBox="0 0 24 24" style="width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:2;">
      <rect x="3" y="4" width="18" height="18" rx="2"/>
      <line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
    </svg>
    <?= date('D, j M Y') ?>
  </div>
</div>
<script>
// Show hamburger only on mobile
(function(){
  var btn = document.getElementById('rpt-hamburger');
  function checkW(){ if(btn) btn.style.display = window.innerWidth <= 768 ? 'flex' : 'none'; }
  checkW();
  window.addEventListener('resize', checkW);
})();
</script>
<style>
  /* ── Page heading ── */
  .page-heading { margin-bottom: 26px; display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap; }
  .page-heading-left h1 { font-family:'DM Serif Display',serif; font-size:28px; color:var(--gray-900); margin-bottom:4px; }
  .page-heading-left p  { font-size:14px; color:var(--gray-500); }

  /* ── Filter bar ── */
  .filter-card {
    background:white; border:1px solid var(--gray-200); border-radius:13px;
    padding:18px 20px; margin-bottom:24px;
    box-shadow:0 1px 4px rgba(0,0,0,.04);
  }
  .filter-label {
    font-size:10.5px; font-weight:700; color:var(--gray-500);
    text-transform:uppercase; letter-spacing:.07em; margin-bottom:10px;
    display:flex; align-items:center; gap:6px;
  }
  .filter-label svg { width:12px; height:12px; fill:none; stroke:currentColor; stroke-width:2; }
  .filter-row { display:flex; align-items:flex-end; gap:12px; flex-wrap:wrap; }
  .filter-group { display:flex; flex-direction:column; gap:5px; }
  .filter-group label { font-size:11.5px; font-weight:600; color:var(--gray-600); }
  .filter-select, .filter-input {
    padding:8px 12px; border:1px solid var(--gray-200); border-radius:8px;
    font-size:13px; color:var(--gray-900); background:white; outline:none; cursor:pointer;
    font-family:'DM Sans',sans-serif; transition:border .18s;
    min-width:150px;
  }
  .filter-select:focus, .filter-input:focus { border-color:var(--maroon); box-shadow:0 0 0 3px rgba(125,17,40,.08); }
  .filter-btn {
    padding:8px 18px; background:var(--maroon); color:white;
    border:none; border-radius:8px; font-size:13px; font-weight:600;
    cursor:pointer; font-family:'DM Sans',sans-serif; transition:background .18s;
    white-space:nowrap;
  }
  .filter-btn:hover { background:var(--maroon-dark); }
  .filter-reset {
    padding:8px 14px; background:white; color:var(--gray-600);
    border:1px solid var(--gray-200); border-radius:8px; font-size:13px;
    cursor:pointer; font-family:'DM Sans',sans-serif; text-decoration:none;
    display:inline-flex; align-items:center; gap:5px; transition:background .15s;
  }
  .filter-reset:hover { background:var(--gray-100); }
  .filter-reset svg { width:13px; height:13px; fill:none; stroke:currentColor; stroke-width:2; }

  /* ── KPI cards ── */
  .kpi-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:24px; }
  @media(max-width:1000px){ .kpi-grid { grid-template-columns:repeat(2,1fr); } }
  @media(max-width:560px) { .kpi-grid { grid-template-columns:1fr; } }

  .kpi-card {
    background:white; border:1px solid var(--gray-200); border-radius:13px;
    padding:20px 22px; display:flex; align-items:center; gap:16px;
    box-shadow:0 1px 4px rgba(0,0,0,.04); transition:box-shadow .2s;
  }
  .kpi-card:hover { box-shadow:0 4px 14px rgba(0,0,0,.08); }
  .kpi-icon { width:50px; height:50px; border-radius:12px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
  .kpi-icon svg { width:22px; height:22px; fill:none; stroke:currentColor; stroke-width:2; }
  .kpi-icon.blue   { background:#EFF6FF; color:#2563EB; }
  .kpi-icon.amber  { background:#FFFBEB; color:#D97706; }
  .kpi-icon.yellow { background:#FEF3C7; color:#92400E; }
  .kpi-icon.green  { background:#ECFDF5; color:#059669; }
  .kpi-val   { font-size:32px; font-weight:700; color:var(--gray-900); line-height:1; margin-bottom:4px; }
  .kpi-label { font-size:12.5px; color:var(--gray-500); }

  /* ── Chart + Table grid ── */
  .bottom-section { display:flex; flex-direction:column; gap:20px; }

  /* ── Chart card ── */
  .chart-card {
    background:white; border:1px solid var(--gray-200); border-radius:13px;
    padding:20px; box-shadow:0 1px 4px rgba(0,0,0,.04);
  }
  .card-header {
    display:flex; align-items:center; justify-content:space-between;
    margin-bottom:18px; flex-wrap:wrap; gap:10px;
  }
  .card-title { font-size:14px; font-weight:700; color:var(--gray-900); }
  .card-subtitle { font-size:12px; color:var(--gray-500); margin-top:2px; }

  .chart-legend {
    display:flex; gap:14px; flex-wrap:wrap;
  }
  .legend-item { display:flex; align-items:center; gap:5px; font-size:12px; color:var(--gray-600); }
  .legend-dot  { width:10px; height:10px; border-radius:3px; flex-shrink:0; }

  .chart-wrap { position:relative; height:280px; }
  #barChart { width:100%; height:100%; }

  /* ── Export buttons ── */
  .export-group { display:flex; gap:8px; }
  .btn-export {
    display:inline-flex; align-items:center; gap:6px;
    padding:7px 14px; border-radius:8px; font-size:12.5px; font-weight:600;
    border:1.5px solid var(--gray-200); background:white; color:var(--gray-700);
    cursor:pointer; text-decoration:none; transition:all .15s; white-space:nowrap;
    font-family:'DM Sans',sans-serif;
  }
  .btn-export svg { width:14px; height:14px; fill:none; stroke:currentColor; stroke-width:2; flex-shrink:0; }
  .btn-export:hover { background:var(--gray-100); border-color:var(--gray-300); }
  .btn-export.csv:hover  { background:#ECFDF5; border-color:#6EE7B7; color:#065F46; }
  .btn-export.pdf-btn:hover { background:#FEF2F2; border-color:#FCA5A5; color:#991B1B; }

  /* ── Table card ── */
  .table-card {
    background:white; border:1px solid var(--gray-200); border-radius:13px;
    box-shadow:0 1px 4px rgba(0,0,0,.04); overflow:hidden;
  }
  .table-card .card-header { padding:16px 20px; border-bottom:1px solid var(--gray-100); margin-bottom:0; }

  /* ── Formal Report Table ── */
  .report-table { width:100%; border-collapse:collapse; }
  
  .report-table thead tr {
    background: var(--maroon);
  }
  .report-table th {
    font-size:11px; font-weight:700; color:white; text-transform:uppercase;
    letter-spacing:.07em; padding:13px 20px; text-align:left;
    border:none;
  }
  .report-table th:not(:first-child) { text-align:center; }

  .report-table tbody td {
    font-size:13.5px; color:var(--gray-700); padding:13px 20px;
    border-bottom:1px solid var(--gray-200); vertical-align:middle;
    border-left: none; border-right: none;
  }
  .report-table tbody tr:nth-child(even) td { background:#FAFAFA; }
  .report-table tbody tr:last-child td { border-bottom: 1px solid var(--gray-200); }
  .report-table tbody tr:hover td { background:#F5F0F2; }
  .report-table td:not(:first-child) { text-align:center; }

  /* Totals footer row */
  .totals-row td {
    background: #F9F0F2;
    font-weight:700; color:var(--gray-900);
    border-top:2px solid var(--maroon);
    border-bottom: none;
    font-size: 13.5px;
    padding: 14px 20px;
  }

  .dept-name-cell { display:flex; align-items:center; gap:9px; }
  .dept-dot  { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
  .dept-name-text { font-weight:600; color:var(--gray-900); }

  .badge-open       { font-size:13px; font-weight:600; color:#B45309; }
.badge-inprogress { font-size:13px; font-weight:600; color:#92400E; }
.badge-closed     { font-size:13px; font-weight:600; color:#059669; }
.badge-zero       { font-size:13px; font-weight:400; color:var(--gray-400); }

  /* Resolution rate bar */
  .rate-cell { display:flex; align-items:center; gap:8px; justify-content:center; }
  .rate-bar-wrap { width:60px; height:6px; background:var(--gray-200); border-radius:3px; overflow:hidden; }
  .rate-bar      { height:100%; border-radius:3px; background:#059669; transition:width .3s; }
  .rate-pct      { font-size:12.5px; font-weight:700; color:var(--gray-900); min-width:36px; text-align:right; }

  

  /* ── Mobile responsive ── */
  @media (max-width: 768px) {
    .dash-topbar {
      margin: -20px -16px 20px -16px !important;
      padding: 0 16px !important;
      width: calc(100% + 32px) !important;
    }
    .page-heading { flex-direction: column; gap: 10px; }
    .page-heading-left h1 { font-size: 22px; }
    .filter-row { flex-direction: column; align-items: stretch; }
    .filter-select, .filter-input { min-width: unset; width: 100%; }
    .filter-btn, .filter-reset { width: 100%; justify-content: center; text-align: center; }
    .kpi-grid { grid-template-columns: repeat(2,1fr); }
    .card-header { flex-direction: column; align-items: flex-start; }
    .export-group { width: 100%; }
    .btn-export { flex: 1; justify-content: center; }
    /* Table horizontal scroll */
    .table-card { overflow-x: auto; }
    .report-table { min-width: 560px; }
    .report-table th, .report-table td { padding: 10px 12px; font-size: 12px; }
    .totals-row td { padding: 11px 12px; font-size: 12px; }
    .rate-bar-wrap { width: 40px; }
    .chart-wrap { height: 220px; }
    .chart-legend { gap: 8px; }
  }
  @media (max-width: 480px) {
    .kpi-grid { grid-template-columns: 1fr; }
    .kpi-val { font-size: 26px; }
  }

  /* Active filter pills */
  .active-filters { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:16px; }
  .filter-pill {
    display:inline-flex; align-items:center; gap:5px;
    padding:3px 10px; border-radius:20px; font-size:12px; font-weight:500;
    background:var(--maroon); color:white;
  }
  .filter-pill svg { width:11px; height:11px; fill:none; stroke:currentColor; stroke-width:2.5; }
</style>

<!-- ── Page heading ── -->
<div class="page-heading">
  <div class="page-heading-left">
    <h1>Reports</h1>
    <p>Filterable performance overview across all departments.</p>
  </div>
</div>

<!-- ── Filter card ── -->
<div class="filter-card">
  <div class="filter-label">
    <svg viewBox="0 0 24 24"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
    Filters
  </div>
  <form method="GET" class="filter-row">
    <div class="filter-group">
      <label>Department</label>
      <select name="dept" class="filter-select">
        <option value="0">All Departments</option>
        <?php foreach ($deptFull as $id => $name):
            if ($allowedDepts && !in_array($id, $allowedDepts)) continue;
        ?>
        <option value="<?= $id ?>" <?= $filterDept === $id ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="filter-group">
      <label>Status</label>
      <select name="status" class="filter-select">
        <option value="all"  <?= $filterStatus === 'all'         ? 'selected' : '' ?>>All Statuses</option>
        <option value="open" <?= $filterStatus === 'open'        ? 'selected' : '' ?>>Open</option>
        <option value="in_progress" <?= $filterStatus === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
        <option value="closed" <?= $filterStatus === 'closed'   ? 'selected' : '' ?>>Closed</option>
      </select>
    </div>

    <div class="filter-group">
      <label>Date From</label>
      <input type="date" name="date_from" class="filter-input" value="<?= htmlspecialchars($dateFrom) ?>">
    </div>

    <div class="filter-group">
      <label>Date To</label>
      <input type="date" name="date_to" class="filter-input" value="<?= htmlspecialchars($dateTo) ?>">
    </div>

    <button type="submit" class="filter-btn">Apply</button>

    <?php if ($filterDept || $filterStatus !== 'all' || $dateFrom || $dateTo): ?>
    <a href="reports.php" class="filter-reset">
      <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      Clear
    </a>
    <?php endif; ?>
  </form>
</div>

<!-- Active filter pills -->
<?php if ($filterDept || $filterStatus !== 'all' || $dateFrom || $dateTo): ?>
<div class="active-filters">
  <?php if ($filterDept): ?>
  <span class="filter-pill"><?= htmlspecialchars($deptShort[$filterDept] ?? '') ?></span>
  <?php endif; ?>
  <?php if ($filterStatus !== 'all'): ?>
  <span class="filter-pill"><?= ucfirst(str_replace('_',' ',$filterStatus)) ?></span>
  <?php endif; ?>
  <?php if ($dateFrom): ?>
  <span class="filter-pill">From: <?= htmlspecialchars($dateFrom) ?></span>
  <?php endif; ?>
  <?php if ($dateTo): ?>
  <span class="filter-pill">To: <?= htmlspecialchars($dateTo) ?></span>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── KPI Cards ── -->
<div class="kpi-grid">
  <div class="kpi-card">
    <div class="kpi-icon blue">
      <svg viewBox="0 0 24 24"><path d="M2 9a1 1 0 0 1 1-1h18a1 1 0 0 1 1 1v2a2 2 0 0 0 0 4v2a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1v-2a2 2 0 0 0 0-4V9z"/><line x1="9" y1="8" x2="9" y2="16"/></svg>
    </div>
    <div><div class="kpi-val"><?= $kpiTotal ?></div><div class="kpi-label">Total Tickets</div></div>
  </div>
  <div class="kpi-card">
    <div class="kpi-icon amber">
      <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
    </div>
    <div><div class="kpi-val"><?= $kpiOpen ?></div><div class="kpi-label">Open</div></div>
  </div>
  <div class="kpi-card">
    <div class="kpi-icon yellow">
      <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
    </div>
    <div><div class="kpi-val"><?= $kpiProgress ?></div><div class="kpi-label">In Progress</div></div>
  </div>
  <div class="kpi-card">
    <div class="kpi-icon green">
      <svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
    </div>
    <div><div class="kpi-val"><?= $kpiClosed ?></div><div class="kpi-label">Closed</div></div>
  </div>
</div>

<!-- ── Bottom Section ── -->
<div class="bottom-section">

  <!-- Bar chart -->
  <div class="chart-card">
    <div class="card-header">
      <div>
        <div class="card-title">Department Performance</div>
        <div class="card-subtitle">Ticket breakdown by department and status</div>
      </div>
      <div class="chart-legend">
        <div class="legend-item"><div class="legend-dot" style="background:#F59E0B;"></div> Open</div>
        <div class="legend-item"><div class="legend-dot" style="background:#92400E;"></div> In Progress</div>
        <div class="legend-item"><div class="legend-dot" style="background:#059669;"></div> Closed</div>
      </div>
    </div>
    <div class="chart-wrap">
      <canvas id="barChart"></canvas>
    </div>
  </div>

  <!-- Table card -->
  <div class="table-card">
    <div class="card-header">
      <div>
        <div class="card-title">Department Breakdown</div>
        <div class="card-subtitle">Resolution Rate = Closed ÷ Total × 100</div>
      </div>
      <div class="export-group">
        <?php
        $qs = http_build_query(array_filter([
          'dept'      => $filterDept ?: null,
          'status'    => $filterStatus !== 'all' ? $filterStatus : null,
          'date_from' => $dateFrom ?: null,
          'date_to'   => $dateTo   ?: null,
        ]));
        ?>
        <a href="reports.php?export=csv<?= $qs ? '&'.$qs : '' ?>" class="btn-export csv">
          <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
          Download CSV
        </a>
        <button class="btn-export pdf-btn" onclick="downloadSuperAdminPDF()">
  <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
  Download PDF
</button>
      </div>
    </div>

    <table class="report-table">
      <thead>
        <tr>
          <th>Department</th>
          <th>Total</th>
          <th>Open</th>
          <th>In Progress</th>
          <th>Closed</th>
          <th>Resolution Rate</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $grandTotal = $grandOpen = $grandIp = $grandClosed = 0;
        foreach ($deptRows as $row):
          $dId   = (int)$row['dept_id'];
          $t     = (int)$row['total'];
          $op    = (int)$row['open_count'];
          $ip    = (int)$row['inprogress_count'];
          $cl    = (int)$row['closed_count'];
          $rate  = $t > 0 ? round($cl / $t * 100) : 0;
          $color = $deptColors[$dId] ?? '#888';
          $short = $deptShort[$dId]  ?? htmlspecialchars($row['dept_name']);
          $grandTotal  += $t;
          $grandOpen   += $op;
          $grandIp     += $ip;
          $grandClosed += $cl;
        ?>
        <tr>
          <td>
            <div class="dept-name-cell">
              <div class="dept-dot" style="background:<?= $color ?>;"></div>
              <span class="dept-name-text"><?= $short ?></span>
            </div>
          </td>
          <td style="font-weight:600;color:var(--gray-900);"><?= $t ?></td>
          <td><?= $op > 0 ? "<span class='badge-open'>$op</span>"    : "<span class='badge-zero'>0</span>" ?></td>
          <td><?= $ip > 0 ? "<span class='badge-inprogress'>$ip</span>" : "<span class='badge-zero'>0</span>" ?></td>
          <td><?= $cl > 0 ? "<span class='badge-closed'>$cl</span>"     : "<span class='badge-zero'>0</span>" ?></td>
          <td>
            <div class="rate-cell">
              <div class="rate-bar-wrap"><div class="rate-bar" style="width:<?= $rate ?>%;"></div></div>
              <span class="rate-pct"><?= $rate ?>%</span>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <?php
        $grandRate = $grandTotal > 0 ? round($grandClosed / $grandTotal * 100) : 0;
        ?>
        <tr class="totals-row">
          <td>All Departments</td>
          <td><?= $grandTotal ?></td>
          <td><?= $grandOpen ?></td>
          <td><?= $grandIp ?></td>
          <td><?= $grandClosed ?></td>
          <td>
            <div class="rate-cell">
              <div class="rate-bar-wrap"><div class="rate-bar" style="width:<?= $grandRate ?>%;"></div></div>
              <span class="rate-pct"><?= $grandRate ?>%</span>
            </div>
          </td>
        </tr>
      </tfoot>
    </table>
  </div>

</div><!-- /.bottom-section -->

<!-- ── Bar Chart JS ── -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
  const labels = <?= json_encode(array_map(function($r) use ($deptShort){ return isset($deptShort[(int)$r['dept_id']]) ? $deptShort[(int)$r['dept_id']] : $r['dept_name']; }, $deptRows)) ?>;
  const openData   = <?= json_encode(array_map(function($r){ return (int)$r['open_count']; },       $deptRows)) ?>;
  const ipData     = <?= json_encode(array_map(function($r){ return (int)$r['inprogress_count']; }, $deptRows)) ?>;
  const closedData = <?= json_encode(array_map(function($r){ return (int)$r['closed_count']; },     $deptRows)) ?>;

  const ctx = document.getElementById('barChart').getContext('2d');
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels,
      datasets: [
        {
          label: 'Open',
          data: openData,
          backgroundColor: '#F59E0B',
          borderRadius: 5,
          borderSkipped: false,
        },
        {
          label: 'In Progress',
          data: ipData,
          backgroundColor: '#92400E',
          borderRadius: 5,
          borderSkipped: false,
        },
        {
          label: 'Closed',
          data: closedData,
          backgroundColor: '#059669',
          borderRadius: 5,
          borderSkipped: false,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: 'rgba(17,24,39,.92)',
          padding: 10,
          cornerRadius: 8,
          titleFont: { size: 13, weight: '700' },
          bodyFont:  { size: 12 },
          callbacks: {
            title: items => items[0].label,
            label: item => ' ' + item.dataset.label + ': ' + item.parsed.y + ' tickets',
          },
        },
      },
      scales: {
        x: {
          grid: { display: false },
          ticks: { font: { size: 12 }, color: '#6B7280' },
        },
        y: {
          beginAtZero: true,
          grid: { color: '#F3F4F6' },
          ticks: {
            stepSize: 1,
            font: { size: 12 },
            color: '#6B7280',
            callback: v => Number.isInteger(v) ? v : '',
          },
        },
      },
    },
  });
})();
</script>

</div><!-- /.page-body -->
</div><!-- /.main-content -->

<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
<script>
function downloadSuperAdminPDF() {
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });

  // ── Header bar ──
  doc.setFillColor(125, 17, 40);
  doc.rect(0, 0, 210, 22, 'F');

  // ── Logo ──
  const logo = new Image();
  logo.src = '../img/RCMP.png';
  logo.onload = function() {
    const canvas = document.createElement('canvas');
    canvas.width = logo.naturalWidth; canvas.height = logo.naturalHeight;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(logo, 0, 0);
    doc.addImage(canvas.toDataURL('image/png'), 'PNG', 5, 2, 24, 18);
    buildPDF(doc);
  };
  logo.onerror = function() { buildPDF(doc); };
}

function buildPDF(doc) {
  // ── Header text ──
  doc.setTextColor(255, 255, 255);
  doc.setFont('helvetica', 'bold'); doc.setFontSize(12);
  doc.text('UniKL RCMP — Department Report', 34, 10);
doc.setFontSize(8); doc.setFont('helvetica', 'normal');
doc.text('Generated: ' + new Date().toLocaleString(), 34, 16);

  // ── Summary line ──
  const total    = <?= $kpiTotal ?>;
  const open     = <?= $kpiOpen ?>;
  const inProg   = <?= $kpiProgress ?>;
  const closed   = <?= $kpiClosed ?>;

  doc.setTextColor(30, 41, 59);
  doc.setFontSize(9); doc.setFont('helvetica', 'bold');
  doc.text(
    `Total: ${total}  |  Open: ${open}  |  In Progress: ${inProg}  |  Closed: ${closed}`,
    14, 32
  );

  // ── Filter pills ──
  const filterParts = [];
  <?php if ($filterDept): ?>filterParts.push('Dept: <?= htmlspecialchars($deptShort[$filterDept] ?? '') ?>');<?php endif; ?>
  <?php if ($filterStatus !== 'all'): ?>filterParts.push('Status: <?= ucfirst(str_replace('_',' ',$filterStatus)) ?>');<?php endif; ?>
  <?php if ($dateFrom): ?>filterParts.push('From: <?= htmlspecialchars($dateFrom) ?>');<?php endif; ?>
  <?php if ($dateTo): ?>filterParts.push('To: <?= htmlspecialchars($dateTo) ?>');<?php endif; ?>

  doc.setTextColor(100, 100, 100);
  doc.setFontSize(8); doc.setFont('helvetica', 'normal');
  doc.text(filterParts.length ? filterParts.join('  |  ') : 'All Departments · All Statuses · All time', 14, 39);

  // ── Table ──
  const deptRows = <?= json_encode(array_map(function($row) use ($deptShort, $deptColors) {
    $dId  = (int)$row['dept_id'];
    $t    = (int)$row['total'];
    $cl   = (int)$row['closed_count'];
    $rate = $t > 0 ? round($cl / $t * 100) : 0;
    return [
      'name'   => $deptShort[$dId] ?? $row['dept_name'],
      'total'  => $t,
      'open'   => (int)$row['open_count'],
      'inprog' => (int)$row['inprogress_count'],
      'closed' => $cl,
      'rate'   => $rate,
    ];
  }, $deptRows)) ?>;

  const grandTotal  = <?= $grandTotal ?? 0 ?>;
  const grandOpen   = <?= $grandOpen ?? 0 ?>;
  const grandIp     = <?= $grandIp ?? 0 ?>;
  const grandClosed = <?= $grandClosed ?? 0 ?>;
  const grandRate   = grandTotal > 0 ? Math.round(grandClosed / grandTotal * 100) : 0;

  doc.autoTable({
    startY: 45,
    head: [['Department', 'Total', 'Open', 'In Progress', 'Closed', 'Resolution Rate']],
    body: [
      ...deptRows.map(r => [r.name, r.total, r.open, r.inprog, r.closed, r.rate + '%']),
      ['All Departments', grandTotal, grandOpen, grandIp, grandClosed, grandRate + '%'],
    ],
    styles: { fontSize: 9, cellPadding: 3 },
    headStyles: { fillColor: [125, 17, 40], textColor: 255, fontStyle: 'bold' },
    alternateRowStyles: { fillColor: [249, 240, 242] },
    didParseCell: data => {
      if (data.section === 'body' && data.row.index === deptRows.length) {
        data.cell.styles.fontStyle  = 'bold';
        data.cell.styles.fillColor  = [249, 240, 242];
      }
      if (data.section === 'body' && data.column.index === 5) {
        const v = parseFloat(data.cell.raw);
        data.cell.styles.textColor  = v >= 70 ? [22,163,74] : v >= 40 ? [249,115,22] : [220,38,38];
        data.cell.styles.fontStyle  = 'bold';
      }
    }
  });

  // ── Footer ──
  doc.setFontSize(7); doc.setTextColor(148, 163, 184);
  doc.text('UniKL RCMP Help Desk System', 14, 287);
  doc.text('Confidential — Super Admin Use Only', 140, 287);

  doc.save('unikl_report_' + new Date().toISOString().slice(0,10) + '.pdf');
}
</script>

</body>
</html>