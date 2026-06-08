<?php
// dept_admin/afsmd/requisitions.php
require '_layout.php';

if (!function_exists('staffInitials')) {
    function staffInitials(string $name): string {
        $parts = explode(' ', trim($name));
        $ini   = strtoupper(substr($parts[0], 0, 1));
        if (count($parts) > 1) $ini .= strtoupper(substr($parts[count($parts) - 1], 0, 1));
        return $ini;
    }
}

$status   = $_GET['status']   ?? '';
$urgency  = $_GET['urgency']  ?? '';
$category = $_GET['category'] ?? '';
$search   = trim($_GET['q']   ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));

// ── PER-PAGE: accept 10 / 25 / 50 / 100, default 10 ──
$allowedPerPage = [10, 25, 50, 100];
$perPage = (int)($_GET['per_page'] ?? 10);
if (!in_array($perPage, $allowedPerPage)) $perPage = 10;

// ── AFSMD only handles requisitions with dept_id = 1 ──
$where  = [];
$params = [];
$types  = '';

if ($status)   { $where[] = "r.status = ?";           $params[] = $status;   $types .= 's'; }
if ($urgency)  { $where[] = "r.urgency = ?";          $params[] = $urgency;  $types .= 's'; }
if ($category) { $where[] = "r.category = ?";         $params[] = $category; $types .= 's'; }
if ($search)   {
    $where[] = "(r.ref_number LIKE ? OR r.item_name LIKE ? OR r.my_department LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'sss';
}

$whereSQL = $where ? implode(' AND ', $where) : '1=1';

// ── COUNT ──
$countStmt = $conn->prepare("SELECT COUNT(*) AS n FROM requisitions r WHERE $whereSQL");
if ($types) $countStmt->bind_param($types, ...$params);
$countStmt->execute();
$total  = $countStmt->get_result()->fetch_assoc()['n'];
$pages  = max(1, ceil($total / $perPage));
$page   = min($page, $pages);
$offset = ($page - 1) * $perPage;

// ── MAIN QUERY ──
$sql = "
    SELECT r.req_id, r.ref_number, r.my_department, r.category,
           r.item_name, r.quantity, r.location, r.reason,
           r.urgency, r.status, r.created_at,
           COALESCE(st.full_name, 'Unknown') AS submitter_name,
           r.submitter_type,
           ast.full_name AS assigned_staff_name
    FROM requisitions r
    LEFT JOIN staff  st  ON r.submitter_type = 'staff'  AND r.submitter_id = st.staff_id
    LEFT JOIN staff  ast ON r.assigned_to = ast.staff_id
    WHERE $whereSQL
    ORDER BY r.created_at DESC
    LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($sql);
$allParams = array_merge($params, [$perPage, $offset]);
$allTypes  = $types . 'ii';
$stmt->bind_param($allTypes, ...$allParams);
$stmt->execute();
$reqs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── DISTINCT CATEGORIES for filter dropdown ──
$catResult = $conn->query("SELECT DISTINCT category FROM requisitions WHERE category IS NOT NULL AND category <> '' ORDER BY category");
$categories = $catResult ? $catResult->fetch_all(MYSQLI_ASSOC) : [];

// ── KPI STATUS COUNTS (always unfiltered, for the cards) ──
$kpiCounts = ['pending'=>0,'approved'=>0,'rejected'=>0,'completed'=>0];
$kpiRes = $conn->query("SELECT status, COUNT(*) AS n FROM requisitions GROUP BY status");
while ($kpiRow = $kpiRes->fetch_assoc()) {
    if (isset($kpiCounts[$kpiRow['status']])) {
        $kpiCounts[$kpiRow['status']] = (int)$kpiRow['n'];
    }
}

/* Build query string helper — preserves all active filters, drops page */
if (!function_exists('qstr')) {
    function qstr(array $extra = []): string {
        $p = array_merge($_GET, $extra);
        unset($p['page']);
        $filtered = array_filter($p, function($v) { return $v !== ''; });
        $qs = http_build_query($filtered);
        return $qs ? '?' . $qs : '?';
    }
}

/* Build query string for pagination links — keeps page */
if (!function_exists('pgstr')) {
    function pgstr(array $extra = []): string {
        $p = array_merge($_GET, $extra);
        $filtered = array_filter($p, function($v) { return $v !== ''; });
        $qs = http_build_query($filtered);
        return $qs ? '?' . $qs : '?';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>AFSMD Admin — Requisitions | UniKL Help Desk</title>
  <?php include '_head_assets.php'; ?>
  <style>
    /* ── KPI STATUS CARDS ── */
    .req-kpi-grid {
      display: grid;
      grid-template-columns: repeat(5, 1fr);
      gap: 12px;
      margin-bottom: 20px;
    }
    @media (max-width: 900px) {
      .req-kpi-grid { grid-template-columns: repeat(3, 1fr); }
    }
    @media (max-width: 500px) {
      .req-kpi-grid { grid-template-columns: repeat(2, 1fr); }
    }

    button.req-kpi-card {
      appearance: none; -webkit-appearance: none;
      font-family: inherit; text-align: left; cursor: pointer;
    }
    .req-kpi-card {
      background: var(--white);
      border: 2px solid var(--gray-200);
      border-radius: 14px;
      padding: 16px 18px;
      display: flex;
      flex-direction: row;
      align-items: center;
      gap: 14px;
      transition: box-shadow .2s, transform .15s, border-color .2s;
      color: inherit;
      position: relative;
      overflow: hidden;
    }
    .req-kpi-card::after {
      content: '';
      position: absolute;
      bottom: 0; left: 0; right: 0;
      height: 3px;
      opacity: 0;
      transition: opacity .2s;
    }
    .req-kpi-card:hover {
      box-shadow: 0 4px 16px rgba(107,90,158,.15);
      transform: translateY(-2px);
    }
    .req-kpi-card.active {
      box-shadow: 0 4px 16px rgba(107,90,158,.18);
      transform: translateY(-2px);
    }
    .req-kpi-card.active::after { opacity: 1; }

    .req-kpi-icon {
      width: 44px; height: 44px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .req-kpi-icon svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 2; }

    .req-kpi-all      { background: var(--blue-light); color: var(--blue); }
    .req-kpi-pending  { background: #FFF7ED; color: #C2410C; }
    .req-kpi-approved { background: #F0FDF4; color: #15803D; }
    .req-kpi-rejected { background: #FFF1F2; color: #BE123C; }
    .req-kpi-completed{ background: #EFF6FF; color: #1D4ED8; }

    

    .req-kpi-card.active::after { background: var(--blue); opacity: 1; }
    .req-kpi-card.active:has(.req-kpi-pending)::after  { background: #C2410C; }
    .req-kpi-card.active:has(.req-kpi-approved)::after { background: #15803D; }
    .req-kpi-card.active:has(.req-kpi-rejected)::after { background: #BE123C; }
    .req-kpi-card.active:has(.req-kpi-completed)::after{ background: #1D4ED8; }

    .req-kpi-card.active .req-kpi-all      { border-color: var(--blue); }
    .req-kpi-card.active .req-kpi-pending  { border-color: #C2410C; }
    .req-kpi-card.active .req-kpi-approved { border-color: #15803D; }
    .req-kpi-card.active .req-kpi-rejected { border-color: #BE123C; }
    .req-kpi-card.active .req-kpi-completed{ border-color: #1D4ED8; }

    .req-kpi-body { display: flex; flex-direction: column; gap: 2px; }
    .req-kpi-val {
      font-size: 26px; font-weight: 800;
      color: var(--gray-900); line-height: 1.1;
      font-variant-numeric: tabular-nums;
    }
    .req-kpi-label {
      font-size: 11px; color: var(--gray-500);
      font-weight: 600; letter-spacing: .02em;
    }

    /* ── TABLE CELL FIXES ── */
    .data-table tbody td {
      vertical-align: middle;
    }

    /* ── REF NUMBER: allow wrap so full ref is always visible ── */
    .ref-number {
      white-space: normal;
      word-break: break-word;
      overflow-wrap: break-word;
      display: inline-block;
      max-width: 110px;
      font-size: 12px;
      font-family: monospace;
    }

    /* ── ITEM NAME ── */
    .td-item {
      max-width: 180px;
    }
    .item-name {
      font-size: 13px;
      font-weight: 600;
      color: var(--gray-800, #1f2937);
      display: block;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .item-meta {
      display: block;
      font-size: 11px;
      color: var(--gray-500, #6b7280);
      margin-top: 2px;
    }

    /* ── SUBMITTED BY (DEPARTMENT + DATE/TIME) COLUMN ── */
    .td-submitted-by {
      max-width: 200px;
    }
    .dept-name {
      font-size: 13px;
      font-weight: 600;
      color: var(--gray-700, #374151);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 195px;
      display: block;
    }
    .submitted-datetime {
      display: block;
      font-size: 11px;
      color: var(--gray-500, #6b7280);
      margin-top: 3px;
      white-space: nowrap;
    }

    /* ── CATEGORY ── */
    .td-cat {
      color: var(--gray-500);
      font-size: 12px;
      white-space: nowrap;
      max-width: 150px;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    /* ── FLAG-STYLE URGENCY (mirrors priority in tickets.php) ── */
    .urgency-pill {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      font-size: .68rem;
      font-weight: 800;
      letter-spacing: .07em;
      text-transform: uppercase;
      background: none;
      border: none;
      padding: 0;
    }
    .urgency-flag-icon {
      width: 13px;
      height: 13px;
      flex-shrink: 0;
      vertical-align: middle;
      position: relative;
      top: -1px;
    }
    .urgency-pill.up-urgent { color: #DC2626; }
    .urgency-pill.up-urgent .urgency-flag-icon { fill: #DC2626; stroke: #DC2626; }
    .urgency-pill.up-normal { color: #2563EB; }
    .urgency-pill.up-normal .urgency-flag-icon { fill: #3B82F6; stroke: #3B82F6; }

    /* ── STATUS BADGES ── */
    .badge.status-pending  {
      background: #FFF7ED; color: #C2410C;
      border: 1px solid rgba(194,65,12,.2);
      white-space: nowrap;
    }
    .badge.status-approved {
      background: #F0FDF4; color: #15803D;
      border: 1px solid rgba(21,128,61,.2);
      white-space: nowrap;
    }
    .badge.status-rejected {
      background: #FFF1F2; color: #BE123C;
      border: 1px solid rgba(190,18,60,.2);
      white-space: nowrap;
    }
    .badge.status-completed {
  background: #EFF6FF; color: #1D4ED8;
  border: 1px solid rgba(29,78,216,.2);
  white-space: nowrap;
}

    /* ── ASSIGNED TO — avatar + name ── */
    .td-assigned {
      white-space: nowrap;
    }
    .assigned-cell {
      display: flex;
      align-items: center;
      gap: 7px;
      overflow: hidden;
    }
    .staff-avatar-sm {
      width: 28px;
      height: 28px;
      border-radius: 50%;
      background: linear-gradient(135deg, #001f5c, #1a56db);
      color: white;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 10px;
      font-weight: 700;
      flex-shrink: 0;
    }
    .assigned-name {
      font-size: 13px;
      color: var(--gray-700);
      font-weight: 500;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .unassigned-text {
      color: var(--gray-500);
      font-style: italic;
      font-size: 11px;
    }

    /* ── VIEW BUTTON ── */
    .btn-view {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 5px 12px;
      background: var(--blue-light);
      color: var(--blue);
      border: 1px solid rgba(107,90,158,.25);
      border-radius: 6px;
      font-size: 12px;
      font-weight: 600;
      cursor: pointer;
      font-family: inherit;
      transition: background .15s, color .15s;
      white-space: nowrap;
      text-decoration: none;
    }
    .btn-view:hover {
      background: var(--blue);
      color: white;
    }
    .btn-view svg {
      width: 13px;
      height: 13px;
      fill: none;
      stroke: currentColor;
      stroke-width: 2;
      flex-shrink: 0;
    }

    /* ══════════════════════════════════════════
       PAGINATION — full controls
    ══════════════════════════════════════════ */
    .pagination-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 10px;
      margin-bottom: 10px;
      padding: 0 2px;
    }
    .pagination-bottom {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      flex-wrap: wrap;
      gap: 6px;
      margin-top: 16px;
      padding: 0 2px;
    }
    .pg-summary {
      font-size: 13px;
      color: var(--gray-500, #6b7280);
    }
    .pg-summary strong {
      color: var(--gray-700, #374151);
    }
    .pg-controls {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }
    .pg-per-page {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 13px;
      color: var(--gray-600, #4b5563);
    }
    .pg-per-page select {
      padding: 5px 28px 5px 10px;
      border: 1px solid var(--gray-300, #d1d5db);
      border-radius: 7px;
      font-size: 13px;
      font-family: inherit;
      background: white;
      color: var(--gray-700, #374151);
      cursor: pointer;
      appearance: none;
      -webkit-appearance: none;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 8px center;
      font-weight: 500;
    }
    .pg-per-page select:focus {
      outline: none;
      border-color: var(--blue, #1a56db);
      box-shadow: 0 0 0 3px rgba(26,86,219,.12);
    }
    .pg-pages {
      display: flex;
      align-items: center;
      gap: 4px;
    }
    .pg-btn, .pg-num {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 34px;
      height: 34px;
      padding: 0 8px;
      border-radius: 7px;
      font-size: 13px;
      font-weight: 600;
      text-decoration: none;
      transition: background .15s, color .15s, border-color .15s;
      border: 1px solid var(--gray-300, #d1d5db);
      background: white;
      color: var(--gray-700, #374151);
      cursor: pointer;
      line-height: 1;
    }
    .pg-btn:hover, .pg-num:hover {
      background: var(--gray-100, #f3f4f6);
      border-color: var(--gray-400, #9ca3af);
    }
    .pg-num.active {
      background: var(--blue, #1a56db);
      border-color: var(--blue, #1a56db);
      color: white;
    }
    .pg-btn.disabled {
      opacity: .38;
      pointer-events: none;
    }
    .pg-ellipsis {
      font-size: 13px;
      color: var(--gray-400, #9ca3af);
      padding: 0 4px;
      user-select: none;
    }

    /* ── DETAIL MODAL ── */
    #reqModal .modal {
      max-width: 580px;
    }
    .detail-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
      margin-bottom: 16px;
    }
    .detail-item label {
      display: block;
      font-size: 10px;
      font-weight: 600;
      letter-spacing: .06em;
      text-transform: uppercase;
      color: var(--gray-500);
      margin-bottom: 4px;
    }
    .detail-item .detail-val {
      font-size: 13px;
      font-weight: 500;
      color: var(--gray-900);
    }
    .detail-desc-box {
      background: var(--gray-100);
      border-radius: 8px;
      padding: 12px 14px;
      font-size: 13px;
      color: var(--gray-700);
      line-height: 1.6;
      min-height: 60px;
      white-space: pre-wrap;
      word-break: break-word;
    }
    .detail-desc-label {
      font-size: 10px;
      font-weight: 600;
      letter-spacing: .06em;
      text-transform: uppercase;
      color: var(--gray-500);
      margin-bottom: 6px;
    }
    .modal-ref-id {
      font-family: monospace;
      font-size: 12px;
      background: var(--gray-100);
      padding: 3px 8px;
      border-radius: 4px;
      color: var(--gray-700);
      font-weight: 600;
    }

    /* ── MOBILE RESPONSIVE ── */
    @media (max-width: 900px) {
      .main-content { padding: 20px 12px; }
      .page-title   { font-size: 22px; }
      .filter-bar   { flex-direction: column; align-items: stretch; }
      .filter-bar select,
      .filter-bar .search-wrap,
      .filter-bar .btn-primary-sm,
      .filter-bar .btn-ghost-sm { width: 100%; }

      .data-table thead th:nth-child(3),
      .data-table thead th:nth-child(6),
      .data-table tbody td:nth-child(3),
      .data-table tbody td:nth-child(6) { display: none; }

      .card.no-pad { overflow-x: auto; }
      .data-table  { min-width: 420px; }
      .ref-number  { max-width: 80px; font-size: 10px; }

      .pagination-top  { flex-direction: column; align-items: flex-start; }
      .pg-controls     { width: 100%; justify-content: flex-end; }
      .pagination-bottom { gap: 4px; }
    }
  </style>
</head>
<body>
<?php include '_sidebar.php'; ?>

<main class="main-content">
  <div class="page-header">
    <div>
      <div class="page-eyebrow">Administration & Facilities Management Department</div>
      <h1 class="page-title">Requisitions <span class="title-count"><?= $total ?></span></h1>
    </div>
  </div>

  <!-- ── KPI STATUS CARDS ── -->
  <div class="req-kpi-grid">

    <button type="button" class="req-kpi-card <?= $status===''?'active':'' ?>" onclick="filterByStatus('')">
      <div class="req-kpi-icon req-kpi-all">
        <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      </div>
      <div class="req-kpi-body">
        <div class="req-kpi-val"><?= array_sum($kpiCounts) ?></div>
        <div class="req-kpi-label">All</div>
      </div>
    </button>

    <button type="button" class="req-kpi-card <?= $status==='pending'?'active':'' ?>" onclick="filterByStatus('pending')">
      <div class="req-kpi-icon req-kpi-pending">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      </div>
      <div class="req-kpi-body">
        <div class="req-kpi-val"><?= $kpiCounts['pending'] ?></div>
        <div class="req-kpi-label">Pending</div>
      </div>
    </button>

    <button type="button" class="req-kpi-card <?= $status==='approved'?'active':'' ?>" onclick="filterByStatus('approved')">
      <div class="req-kpi-icon req-kpi-approved">
        <svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      </div>
      <div class="req-kpi-body">
        <div class="req-kpi-val"><?= $kpiCounts['approved'] ?></div>
        <div class="req-kpi-label">Approved</div>
      </div>
    </button>

    <button type="button" class="req-kpi-card <?= $status==='rejected'?'active':'' ?>" onclick="filterByStatus('rejected')">
      <div class="req-kpi-icon req-kpi-rejected">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
      </div>
      <div class="req-kpi-body">
        <div class="req-kpi-val"><?= $kpiCounts['rejected'] ?></div>
        <div class="req-kpi-label">Rejected</div>
      </div>
    </button>

    <button type="button" class="req-kpi-card <?= $status==='completed'?'active':'' ?>" onclick="filterByStatus('completed')">
      <div class="req-kpi-icon req-kpi-completed">
        <svg viewBox="0 0 24 24"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
      </div>
      <div class="req-kpi-body">
        <div class="req-kpi-val"><?= $kpiCounts['completed'] ?></div>
        <div class="req-kpi-label">Completed</div>
      </div>
    </button>

  </div>

  <!-- ── FILTER BAR ── -->
  <form method="GET" class="filter-bar">
    <div class="search-wrap">
      <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="text" name="q" placeholder="Search ref number, item or department…" value="<?= htmlspecialchars($search) ?>"/>
    </div>



    <select name="urgency" onchange="this.form.submit()">
      <option value="">All Urgency</option>
      <?php foreach (['urgent'=>'Urgent','normal'=>'Normal'] as $v=>$l): ?>
      <option value="<?= $v ?>" <?= $urgency===$v?'selected':'' ?>><?= $l ?></option>
      <?php endforeach; ?>
    </select>

    <select name="category" onchange="this.form.submit()">
      <option value="">All Categories</option>
      <?php foreach ($categories as $c): ?>
      <option value="<?= htmlspecialchars($c['category']) ?>" <?= $category===$c['category']?'selected':'' ?>>
        <?= htmlspecialchars($c['category']) ?>
      </option>
      <?php endforeach; ?>
    </select>

    <!-- keep per_page value when re-filtering -->
    <input type="hidden" name="per_page" value="<?= $perPage ?>"/>

    <button type="submit" class="btn-primary-sm">Filter</button>
    <?php if ($urgency||$category||$search): ?>
    <a href="requisitions.php" class="btn-ghost-sm">Clear</a>
    <?php endif; ?>
  </form>

  <!-- ── TOP BAR: summary + per-page selector ── -->
  <?php if ($total > 0):
    $from = $offset + 1;
    $to   = min($offset + $perPage, $total);
  ?>
  <div class="pagination-top">
    <div class="pg-summary">
      Showing <strong><?= $from ?>–<?= $to ?></strong> of <strong><?= $total ?></strong> results
    </div>
    <div class="pg-controls">
      <div class="pg-per-page">
        <span>Show</span>
        <select onchange="changePerPage(this.value)">
          <?php foreach ($allowedPerPage as $pp): ?>
          <option value="<?= $pp ?>" <?= $perPage===$pp?'selected':'' ?>><?= $pp ?> per page</option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── TABLE ── -->
  <div class="card no-pad">
    <table class="data-table full">
      <thead>
        <tr>
          <th>Ref Number</th>
          <th>Submitted By</th>
          <th>Category</th>
          <th>Item</th>
          <th>Urgency</th>
          <th>Status</th>
          <th>Assigned To</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($reqs)): ?>
        <tr><td colspan="8" class="empty-row">No requisitions match your filters.</td></tr>
        <?php else: foreach ($reqs as $r): ?>
        <tr>

          <!-- Ref Number -->
          <td>
            <span class="ref-number"><?= htmlspecialchars($r['ref_number']) ?></span>
          </td>

          <!-- Submitted By: department + date/time -->
          <td class="td-submitted-by">
            <span class="dept-name" title="<?= htmlspecialchars($r['my_department'] ?? '') ?>">
              <?= htmlspecialchars($r['my_department'] ?: '—') ?>
            </span>
            <span class="submitted-datetime">
              <?= date('d M Y, g:ia', strtotime($r['created_at'])) ?>
            </span>
          </td>

          <!-- Category -->
          <td class="td-cat">
            <?= htmlspecialchars($r['category'] ?? '—') ?>
          </td>

          <!-- Item Name + Qty + Location -->
          <td class="td-item">
            <span class="item-name" title="<?= htmlspecialchars($r['item_name'] ?? '') ?>">
              <?= htmlspecialchars($r['item_name'] ?: '—') ?>
            </span>
            <span class="item-meta">
              Qty: <?= (int)$r['quantity'] ?>
              <?php if (!empty($r['location'])): ?>
                &nbsp;·&nbsp; <?= htmlspecialchars($r['location']) ?>
              <?php endif; ?>
            </span>
          </td>

          <!-- Urgency — flag style -->
          <td>
            <?php $u = $r['urgency']; $ul = ucfirst($u); ?>
            <span class="urgency-pill up-<?= $u ?>">
              <svg class="urgency-flag-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M4 3h13l-3 5 3 5H4V3z" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                <line x1="4" y1="3" x2="4" y2="21" stroke-width="2" stroke-linecap="round"/>
              </svg>
              <?= $ul ?>
            </span>
          </td>

          <!-- Status -->
          <td>
            <?php
              $s  = $r['status'];
              $sl = ['pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected','completed'=>'Completed'];
              echo "<span class='badge status-{$s}'>" . ($sl[$s] ?? ucfirst($s)) . "</span>";
            ?>
          </td>

          <!-- Assigned To -->
          <td class="td-assigned">
            <?php if (!empty($r['assigned_staff_name'])): ?>
              <div class="assigned-cell">
                <div class="staff-avatar-sm"><?= staffInitials($r['assigned_staff_name']) ?></div>
                <span class="assigned-name" title="<?= htmlspecialchars($r['assigned_staff_name']) ?>">
                  <?= htmlspecialchars($r['assigned_staff_name']) ?>
                </span>
              </div>
            <?php else: ?>
              <span class="unassigned-text">Unassigned</span>
            <?php endif; ?>
          </td>

          <!-- View Button -->
          <td>
            <a href="requisition_detail.php?id=<?= urlencode($r['ref_number']) ?>" class="btn-view">
              <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              View
            </a>
          </td>

        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- ── BOTTOM PAGINATION ── -->
  <?php if ($total > 0 && $pages > 1): ?>
  <div class="pagination-bottom">

    <!-- Prev -->
    <?php if ($page > 1): ?>
      <a href="<?= pgstr(['page'=>$page-1]) ?>" class="pg-btn" title="Previous page">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
      </a>
    <?php else: ?>
      <span class="pg-btn disabled">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
      </span>
    <?php endif; ?>

    <!-- Page numbers with ellipsis -->
    <?php
      $window = 2;
      $shown  = [];
      for ($i = 1; $i <= $pages; $i++) {
        if ($i === 1 || $i === $pages || abs($i - $page) <= $window) {
          $shown[] = $i;
        }
      }
      $shown = array_unique($shown);
      sort($shown);
      $prev = null;
      foreach ($shown as $pg):
        if ($prev !== null && $pg - $prev > 1): ?>
          <span class="pg-ellipsis">…</span>
        <?php endif;
        if ($pg === $page): ?>
          <span class="pg-num active"><?= $pg ?></span>
        <?php else: ?>
          <a href="<?= pgstr(['page'=>$pg]) ?>" class="pg-num"><?= $pg ?></a>
        <?php endif;
        $prev = $pg;
      endforeach;
    ?>

    <!-- Next -->
    <?php if ($page < $pages): ?>
      <a href="<?= pgstr(['page'=>$page+1]) ?>" class="pg-btn" title="Next page">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
      </a>
    <?php else: ?>
      <span class="pg-btn disabled">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
      </span>
    <?php endif; ?>

  </div>
  <?php endif; ?>

</main>

<?php include '_foot_scripts.php'; ?>

<script>
/* ── KPI CARD FILTER — reload with status filter ── */
function filterByStatus(status) {
  const url = new URL(window.location.href);
  if (status === '') {
    url.searchParams.delete('status');
  } else {
    url.searchParams.set('status', status);
  }
  url.searchParams.delete('page');
  window.location.href = url.toString();
}

/* ── PER-PAGE CHANGE — reload with page reset ── */
function changePerPage(value) {
  const url = new URL(window.location.href);
  url.searchParams.set('per_page', value);
  url.searchParams.delete('page');
  window.location.href = url.toString();
}
</script>
</body>
</html>