<?php
// super_admin/dashboard.php
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
$deptWhereSQL = $allowedDepts
    ? "AND d.dept_id IN (" . implode(',', $allowedDepts) . ")"
    : "";
$complaintWhereSQL = $allowedDepts
    ? "WHERE dept_id IN (" . implode(',', $allowedDepts) . ")"
    : "";

$activePage = 'dashboard';
$pageTitle  = 'Dashboard';

require_once '../db_connect.php';

// ── 1. Stat cards ──────────────────────────────────────────────────────────────
$r      = $conn->query("SELECT COUNT(*) AS n FROM complaints $complaintWhereSQL");
$total  = (int)$r->fetch_assoc()['n'];

$sep = $allowedDepts ? "WHERE dept_id IN (" . implode(',', $allowedDepts) . ") AND" : "WHERE";
$r         = $conn->query("SELECT COUNT(*) AS n FROM complaints $sep status = 'open'");
$openCount = (int)$r->fetch_assoc()['n'];

$r          = $conn->query("SELECT COUNT(*) AS n FROM complaints $sep status = 'in_progress'");
$inProgress = (int)$r->fetch_assoc()['n'];

$r      = $conn->query("SELECT COUNT(*) AS n FROM complaints $sep status = 'closed'");
$closed = (int)$r->fetch_assoc()['n'];

// ── 2. Per-department breakdown ────────────────────────────────────────────────
$deptResult = $conn->query("
    SELECT
        d.dept_id,
        d.dept_name,
        COUNT(c.ticket_id)              AS total,
        SUM(c.status = 'open')          AS open_count,
        SUM(c.status = 'in_progress')   AS inprogress_count,
        SUM(c.status = 'closed')        AS closed_count
    FROM departments d
    LEFT JOIN complaints c ON c.dept_id = d.dept_id
    WHERE 1=1 $deptWhereSQL
    GROUP BY d.dept_id, d.dept_name
    ORDER BY d.dept_id
");
$deptRows = $deptResult->fetch_all(MYSQLI_ASSOC);

// Resolution rate per department
foreach ($deptRows as &$row) {
    $t = (int)$row['total'];
    $cl = (int)$row['closed_count'];
    $row['resolution_rate'] = $t > 0 ? round($cl / $t * 100) : 0;
}
unset($row);

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
    4 => 'IT Department',
    5 => 'HCD',
];

$pieData = [];
foreach ($deptRows as $row) {
    $dId = (int)$row['dept_id'];
    $pieData[] = [
        'label' => $deptShort[$dId] ?? $row['dept_name'],
        'value' => (int)$row['total'],
        'color' => $deptColors[$dId] ?? '#888',
    ];
}

include 'layout.php';
?>

<style>
  /* ── Topbar (inlined into dashboard) ── */
  .dash-topbar {
    height: 65px; background: white;
    border-bottom: 1px solid var(--gray-200);
    display: flex; align-items: center; justify-content: space-between;
    padding: 0 32px;
    margin: -32px -32px 32px -32px; /* bleed to page-body edges */
  }
  .dash-topbar-breadcrumb {
    display: flex; align-items: center; gap: 8px;
    font-size: 15px; color: var(--gray-500);
  }
  .dash-topbar-breadcrumb .sep     { color: var(--gray-300); }
  .dash-topbar-breadcrumb .current { font-weight: 600; color: var(--gray-900); font-size: 15px; }
  .dash-topbar-date {
    font-size: 14px; color: var(--gray-500);
    display: flex; align-items: center; gap: 6px;
  }
  .dash-topbar-date svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2; }

  /* ── Heading ── */
  .dash-heading { margin-bottom: 28px; }
  .dash-heading h1 { font-family: 'DM Serif Display', serif; font-size: 30px; color: var(--gray-900); margin-bottom: 4px; }
  .dash-heading p  { font-size: 14px; color: var(--gray-500); font-weight: 300; }

  /* ── Stat cards — bigger ── */
  .stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; margin-bottom: 32px; }
  @media (max-width: 1100px) { .stat-grid { grid-template-columns: repeat(2, 1fr); } }
  @media (max-width: 600px)  { .stat-grid { grid-template-columns: 1fr; } }

  .stat-card {
    background: white; border-radius: 14px; padding: 22px 24px;
    border: 1px solid var(--gray-200);
    box-shadow: 0 1px 4px rgba(0,0,0,.04);
    transition: box-shadow .2s;
    display: flex; align-items: center; gap: 18px;
    min-height: 100px;
  }
  .stat-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.08); }

  .stat-card.active { 
  box-shadow: 0 4px 16px rgba(0,0,0,.12);
  outline: 2px solid currentColor;
  outline-offset: -2px;
}
.stat-card.active.blue-card   { outline-color: #2563EB; }
.stat-card.active.amber-card  { outline-color: #D97706; }
.stat-card.active.yellow-card { outline-color: #92400E; }
.stat-card.active.green-card  { outline-color: #059669; }
.stat-card { cursor: pointer; }

  .stat-icon { width: 54px; height: 54px; border-radius: 13px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
  .stat-icon svg { width: 24px; height: 24px; fill: none; stroke: currentColor; stroke-width: 2; }
  .stat-icon.blue   { background: #EFF6FF; color: #2563EB; }
  .stat-icon.amber  { background: #FFFBEB; color: #D97706; }
  .stat-icon.yellow { background: #FEF3C7; color: #92400E; }
  .stat-icon.green  { background: #ECFDF5; color: #059669; }

  .stat-body { display: flex; flex-direction: column; }
  .stat-value { font-size: 36px; font-weight: 700; color: var(--gray-900); line-height: 1; margin-bottom: 5px; }
  .stat-label { font-size: 13px; color: var(--gray-500); }

  /* ── Section title ── */
  .section-title { font-size: 11px; font-weight: 600; color: var(--gray-500); text-transform: uppercase; letter-spacing: .07em; margin-bottom: 14px; }

  /* ── Bottom two-column layout ── */
  .bottom-grid {
    display: grid;
    grid-template-columns: 300px 1fr;
    gap: 20px;
    align-items: stretch;
    margin-bottom: 28px;
  }
  @media (max-width: 960px) { .bottom-grid { grid-template-columns: 1fr; } }

  /* ── Pie chart card ── */
  .pie-card {
    background: white; border-radius: 12px;
    border: 1px solid var(--gray-200);
    box-shadow: 0 1px 4px rgba(0,0,0,.04);
    padding: 20px; box-sizing: border-box; height: 100%;
  }
  .pie-wrap { width: 260px; height: 260px; margin: 0 auto 20px; }
  .pie-wrap canvas { display: block; cursor: pointer; }

  /* Pie tooltip */
  #pie-tooltip {
    position: fixed; background: rgba(17,24,39,0.92); color: #fff;
    font-size: 13px; padding: 7px 12px; border-radius: 7px;
    pointer-events: none; display: none; z-index: 9999;
    white-space: nowrap; box-shadow: 0 4px 14px rgba(0,0,0,.18);
  }
  #pie-tooltip .tt-title { font-weight: 700; font-size: 13px; margin-bottom: 2px; }
  #pie-tooltip .tt-body  { display: flex; align-items: center; gap: 7px; font-size: 12px; }
  #pie-tooltip .tt-swatch { width: 10px; height: 10px; border-radius: 2px; flex-shrink: 0; }

  /* Pie legend */
  .pie-legend { display: flex; flex-direction: column; gap: 4px; }
  .legend-item {
    display: flex; align-items: center; gap: 10px;
    padding: 6px 8px; border-radius: 7px; transition: background .15s;
  }
  .legend-item:hover { background: var(--gray-100); }
  .legend-dot   { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
  .legend-label { font-size: 13px; color: var(--gray-700); flex: 1; }
  .legend-val   { font-size: 13px; font-weight: 700; color: var(--gray-900); min-width: 24px; text-align: right; }
  .legend-share { font-size: 11px; color: var(--gray-400); min-width: 36px; text-align: right; }
  .legend-share-label {
    font-size: 10px; font-weight: 600; color: var(--gray-400);
    text-transform: uppercase; letter-spacing: .05em;
  }

  /* ── Dept table card ── */
  .dept-card {
    background: white; border-radius: 12px;
    border: 1px solid var(--gray-200);
    box-shadow: 0 1px 4px rgba(0,0,0,.04);
    overflow: hidden; box-sizing: border-box; height: 100%;
  }
  .dept-card-header { padding: 16px 20px 0; }
  .dept-table { width: 100%; border-collapse: collapse; }
  .dept-table th {
    font-size: 11px; font-weight: 600; color: var(--gray-500);
    text-transform: uppercase; letter-spacing: .06em;
    padding: 12px 20px; text-align: left;
    background: var(--gray-100); border-bottom: 1px solid var(--gray-200);
  }
  .dept-table th:not(:first-child) { text-align: center; }
  .dept-table td {
    font-size: 13.5px; color: var(--gray-700);
    padding: 13px 20px; border-bottom: 1px solid var(--gray-100);
  }
  .dept-table tbody tr:last-child td { border-bottom: none; }
  .dept-table td:not(:first-child) { text-align: center; }
  .dept-table tbody tr:hover { background: var(--off-white); }
  .dept-name { display: flex; align-items: center; gap: 10px; font-weight: 500; color: var(--gray-900); }
  .dept-dot  { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }

  .badge-open       { display:inline-block; font-size:12px; font-weight:600; padding:3px 10px; border-radius:20px; background:#FFFBEB; color:#B45309; }
  .badge-inprogress { display:inline-block; font-size:12px; font-weight:600; padding:3px 10px; border-radius:20px; background:#FEF3C7; color:#92400E; }
  .badge-closed     { display:inline-block; font-size:12px; font-weight:600; padding:3px 10px; border-radius:20px; background:#ECFDF5; color:#065F46; }
  .badge-zero       { display:inline-block; font-size:12px; font-weight:400; padding:3px 10px; border-radius:20px; background:var(--gray-100); color:var(--gray-500); }
  /* Resolution rate in dashboard table */
.dash-rate-cell { display:flex; align-items:center; gap:8px; justify-content:center; }
.dash-rate-bar-wrap { width:60px; height:6px; background:var(--gray-200); border-radius:3px; overflow:hidden; }
.dash-rate-bar { height:100%; border-radius:3px; background:#059669; }
.dash-rate-pct { font-size:12px; font-weight:700; color:var(--gray-900); min-width:32px; }
</style>

<!-- ── Inlined Topbar ── -->
<div class="dash-topbar">
  <div class="dash-topbar-breadcrumb">
    <span class="sep">›</span>
    <span class="current">Dashboard</span>
  </div>
  <div class="dash-topbar-date">
    <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
    <?= date('D, j M Y') ?>
  </div>
</div>

<!-- Pie tooltip -->
<div id="pie-tooltip">
  <div class="tt-title" id="tt-title"></div>
  <div class="tt-body">
    <div class="tt-swatch" id="tt-swatch"></div>
    <span id="tt-text"></span>
  </div>
</div>

<!-- Heading -->
<div class="dash-heading">
  <h1>Welcome back, <?= htmlspecialchars(explode(' ', $_SESSION['staff_name'])[0]) ?></h1>
  <p>Here's what's happening across all departments today.</p>
</div>

<!-- Stat cards -->
<div class="stat-grid">

  <div class="stat-card blue-card" data-filter="all" onclick="filterDashboard('all')">
    <div class="stat-icon blue">
      <svg viewBox="0 0 24 24"><path d="M2 9a1 1 0 0 1 1-1h18a1 1 0 0 1 1 1v2a2 2 0 0 0 0 4v2a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1v-2a2 2 0 0 0 0-4V9z"/><line x1="9" y1="8" x2="9" y2="16"/></svg>
    </div>
    <div class="stat-body">
      <div class="stat-value"><?= $total ?></div>
      <div class="stat-label">Total Tickets</div>
    </div>
  </div>

  <div class="stat-card amber-card" data-filter="open" onclick="filterDashboard('open')">
    <div class="stat-icon amber">
      <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
    </div>
    <div class="stat-body">
      <div class="stat-value"><?= $openCount ?></div>
      <div class="stat-label">Open</div>
    </div>
  </div>

  <div class="stat-card yellow-card" data-filter="in_progress" onclick="filterDashboard('in_progress')">
    <div class="stat-icon yellow">
      <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
    </div>
    <div class="stat-body">
      <div class="stat-value"><?= $inProgress ?></div>
      <div class="stat-label">In Progress</div>
    </div>
  </div>

  <div class="stat-card green-card" data-filter="closed" onclick="filterDashboard('closed')">
    <div class="stat-icon green">
      <svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
    </div>
    <div class="stat-body">
      <div class="stat-value"><?= $closed ?></div>
      <div class="stat-label">Closed</div>
    </div>
  </div>

</div>

<!-- Bottom: Pie LEFT + Table RIGHT -->
<div class="bottom-grid">

  <!-- LEFT: Pie chart -->
  <div class="pie-card">
    <div class="section-title">Complaints by Department</div>
    <div class="pie-wrap">
      <canvas id="pieChart" width="260" height="260"></canvas>
    </div>
    <div class="pie-legend" id="pie-legend">
      <!-- Legend header -->
      <div style="display:flex;align-items:center;gap:10px;padding:0 8px 6px;border-bottom:1px solid var(--gray-100);margin-bottom:4px;">
        <span style="flex:1;"></span>
        <span class="legend-share-label" style="min-width:24px;text-align:right;">Tickets</span>
        <span class="legend-share-label" style="min-width:36px;text-align:right;position:relative;cursor:help;" id="share-help">
          % Share
          <span style="
            display:none;
            position:absolute;
            right:0; top:20px;
            background:#1F2937;
            color:#fff;
            font-size:11px;
            font-weight:400;
            text-transform:none;
            letter-spacing:0;
            padding:8px 10px;
            border-radius:7px;
            width:190px;
            line-height:1.6;
            z-index:999;
            box-shadow:0 4px 14px rgba(0,0,0,.25);
            white-space:normal;
          " id="share-tooltip">
            <strong>% Share</strong><br>
            Formula:<br>
            (Dept Tickets ÷ Total Tickets) × 100
          </span>
        </span>
      </div>
      <?php foreach ($pieData as $seg):
        $share = $total > 0 ? round($seg['value'] / $total * 100) : 0;
      ?>
      <div class="legend-item">
        <div class="legend-dot" style="background:<?= $seg['color'] ?>;"></div>
        <span class="legend-label"><?= htmlspecialchars($seg['label']) ?></span>
        <span class="legend-val"><?= $seg['value'] ?></span>
        <span class="legend-share"><?= $share ?>%</span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- RIGHT: Status Breakdown table -->
  <div class="dept-card">
    <div class="dept-card-header"><br>
      <div class="section-title">Status Breakdown per Department</div>
    </div>
    <br>
    <table class="dept-table">
      <thead>
  <tr>
    <th>Department</th>
    <th>Open</th>
    <th>In Progress</th>
    <th>Closed</th>
    <th>Total</th>
    <th style="position:relative; cursor:help; text-align:center;" id="rate-help">
      Resolution Rate ℹ
      <span style="
        display:none;
        position:absolute;
        right:0; top:28px;
        background:#1F2937;
        color:#fff;
        font-size:11px;
        font-weight:400;
        text-transform:none;
        letter-spacing:0;
        padding:8px 10px;
        border-radius:7px;
        width:190px;
        line-height:1.6;
        z-index:999;
        box-shadow:0 4px 14px rgba(0,0,0,.25);
        white-space:normal;
        text-align:left;
      " id="rate-tooltip">
        <strong>Resolution Rate</strong><br>
        Formula:<br>
        (Closed Tickets ÷ Total Tickets) × 100
      </span>
    </th>
  </tr>
</thead>
      <tbody id="dept-tbody">
        <?php foreach ($deptRows as $row):
          $dId    = (int)$row['dept_id'];
          $color  = $deptColors[$dId] ?? '#888';
          $short  = $deptShort[$dId]  ?? htmlspecialchars($row['dept_name']);
          $dOpen  = (int)$row['open_count'];
          $dIp    = (int)$row['inprogress_count'];
          $dCl    = (int)$row['closed_count'];
          $dTotal = (int)$row['total'];
          $dRate  = (int)$row['resolution_rate'];
        ?>
        <tr>
          <td>
            <div class="dept-name">
              <div class="dept-dot" style="background:<?= $color ?>;"></div>
              <?= $short ?>
            </div>
          </td>
          <td><?= $dOpen > 0 ? "<span class='badge-open'>$dOpen</span>"    : "<span class='badge-zero'>0</span>" ?></td>
          <td><?= $dIp  > 0 ? "<span class='badge-inprogress'>$dIp</span>" : "<span class='badge-zero'>0</span>" ?></td>
          <td><?= $dCl  > 0 ? "<span class='badge-closed'>$dCl</span>"     : "<span class='badge-zero'>0</span>" ?></td>
          <td style="font-weight:600;color:var(--gray-900);"><?= $dTotal ?></td>
<td>
  <div style="display:flex;align-items:center;gap:8px;justify-content:center;">
    <div style="width:60px;height:6px;background:var(--gray-200);border-radius:3px;overflow:hidden;">
      <div style="width:<?= $row['resolution_rate'] ?>%;height:100%;background:#059669;border-radius:3px;"></div>
    </div>
    <span style="font-size:12px;font-weight:700;color:var(--gray-900);"><?= $row['resolution_rate'] ?>%</span>
  </div>
</td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</div>

<!-- Pie chart canvas JS -->
<script>
// ── Full dataset from PHP ──
const ALL_DEPT_ROWS = <?= json_encode(array_map(function($row) use ($deptColors, $deptShort) {
  $dId = (int)$row['dept_id'];
  return [
    'dept_id'          => $dId,
    'label'            => $deptShort[$dId] ?? $row['dept_name'],
    'color'            => $deptColors[$dId] ?? '#888',
    'total'            => (int)$row['total'],
    'open_count'       => (int)$row['open_count'],
    'inprogress_count' => (int)$row['inprogress_count'],
    'closed_count'     => (int)$row['closed_count'],
    'resolution_rate'  => (int)$row['resolution_rate'],
  ];
}, $deptRows)) ?>;

const TOTALS = {
  all:         <?= $total ?>,
  open:        <?= $openCount ?>,
  in_progress: <?= $inProgress ?>,
  closed:      <?= $closed ?>
};

let currentFilter = 'all';
let currentHovered = -1;

// ── Pie chart setup ──
const canvas  = document.getElementById('pieChart');
const ctx     = canvas ? canvas.getContext('2d') : null;
const tooltip = document.getElementById('pie-tooltip');
const ttTitle = document.getElementById('tt-title');
const ttText  = document.getElementById('tt-text');
const ttSwatch= document.getElementById('tt-swatch');

function buildSegments(filter) {
  return ALL_DEPT_ROWS.map(row => {
    let value;
    if      (filter === 'open')        value = row.open_count;
    else if (filter === 'in_progress') value = row.inprogress_count;
    else if (filter === 'closed')      value = row.closed_count;
    else                               value = row.total;
    return { label: row.label, value, color: row.color };
  });
}

function computeSlices(segments) {
  const total = segments.reduce((s, x) => s + x.value, 0);
  if (total === 0) return { slices: [], total: 0 };
  const gap = 0.018;
  const slices = [];
  let angle = -Math.PI / 2;
  segments.forEach(seg => {
    if (seg.value === 0) return;
    const sweep = (seg.value / total) * (2 * Math.PI);
    slices.push({
      ...seg,
      start: angle + gap / 2,
      end:   angle + sweep - gap / 2,
      pct:   Math.round(seg.value / total * 100)
    });
    angle += sweep;
  });
  return { slices, total };
}

let activeSlices = [];
let activeTotal  = 0;

function drawPie(hovered = -1) {
  if (!ctx) return;
  const cx = 130, cy = 130, r = 105;
  ctx.clearRect(0, 0, canvas.width, canvas.height);
  if (activeSlices.length === 0) {
    ctx.beginPath();
    ctx.arc(cx, cy, r, 0, 2 * Math.PI);
    ctx.fillStyle = '#EEF0F5';
    ctx.fill();
    return;
  }

  // ── Draw slices ──
  activeSlices.forEach((s, i) => {
    const expand = (i === hovered) ? 6 : 0;
    const mid    = (s.start + s.end) / 2;
    const ox = Math.cos(mid) * expand;
    const oy = Math.sin(mid) * expand;
    ctx.beginPath();
    ctx.moveTo(cx + ox, cy + oy);
    ctx.arc(cx + ox, cy + oy, r, s.start, s.end);
    ctx.closePath();
    ctx.fillStyle = s.color;
    ctx.fill();
    ctx.strokeStyle = '#fff';
    ctx.lineWidth = 2.5;
    ctx.stroke();
  });

  // ── Draw % inside slices ──
  activeSlices.forEach((s, i) => {
    if (s.pct < 5) return; // skip tiny slices
    const expand = (i === hovered) ? 6 : 0;
    const mid    = (s.start + s.end) / 2;
    const ox = Math.cos(mid) * expand;
    const oy = Math.sin(mid) * expand;
    const lx = cx + ox + Math.cos(mid) * r * 0.62;
    const ly = cy + oy + Math.sin(mid) * r * 0.62;
    ctx.fillStyle = '#fff';
    ctx.font = 'bold 11px DM Sans, sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(s.pct + '%', lx, ly);
  });

  
}

function hitSlice(mx, my) {
  const cx = 150, cy = 150, r = 90;
  const dx = mx - cx, dy = my - cy;
  if (Math.sqrt(dx * dx + dy * dy) > r + 10) return -1;
  let a = Math.atan2(dy, dx);
  return activeSlices.findIndex(s => {
    let st = s.start, en = s.end;
    while (a < st - Math.PI) a += 2 * Math.PI;
    while (a > st + Math.PI) a -= 2 * Math.PI;
    return a >= st && a <= en;
  });
}

function updateLegend(segments, total) {
  const legend = document.getElementById('pie-legend');
  if (!legend) return;
  // Preserve the header row (first child)
  const header = legend.querySelector('div[style*="border-bottom"]');
  legend.innerHTML = '';
  if (header) legend.appendChild(header);
  segments.forEach(seg => {
    const share = total > 0 ? Math.round(seg.value / total * 100) : 0;
    const item = document.createElement('div');
    item.className = 'legend-item';
    item.innerHTML = `
      <div class="legend-dot" style="background:${seg.color};"></div>
      <span class="legend-label">${seg.label}</span>
      <span class="legend-val">${seg.value}</span>
      <span class="legend-share">${share}%</span>`;
    legend.appendChild(item);
  });
}

function updateTable(filter) {
  const tbody = document.getElementById('dept-tbody');
  if (!tbody) return;
  tbody.innerHTML = '';
  ALL_DEPT_ROWS.forEach(row => {
    let dOpen  = row.open_count;
    let dIp    = row.inprogress_count;
    let dCl    = row.closed_count;
    let dTotal = row.total;
    let dRate  = row.resolution_rate;

    if (filter === 'open') {
      dTotal = dOpen; dIp = null; dCl = null;
      dRate  = 0;
    } else if (filter === 'in_progress') {
      dTotal = dIp; dOpen = null; dCl = null;
      dRate  = 0;
    } else if (filter === 'closed') {
      dTotal = dCl; dOpen = null; dIp = null;
      dRate  = dCl > 0 ? 100 : 0;
    }

    function badge(val, type) {
      if (val === null || val === undefined) return `<span class="badge-zero">—</span>`;
      if (val === 0)                         return `<span class="badge-zero">0</span>`;
      if (type === 'open')        return `<span class="badge-open">${val}</span>`;
      if (type === 'in_progress') return `<span class="badge-inprogress">${val}</span>`;
      if (type === 'closed')      return `<span class="badge-closed">${val}</span>`;
      return val;
    }

    const rateBar = filter === 'open' || filter === 'in_progress' ? '—' : `
      <div style="display:flex;align-items:center;gap:8px;justify-content:center;">
        <div style="width:60px;height:6px;background:var(--gray-200);border-radius:3px;overflow:hidden;">
          <div style="width:${dRate}%;height:100%;background:#059669;border-radius:3px;"></div>
        </div>
        <span style="font-size:12px;font-weight:700;color:var(--gray-900);">${dRate}%</span>
      </div>`;

    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>
        <div class="dept-name">
          <div class="dept-dot" style="background:${row.color};"></div>
          ${row.label}
        </div>
      </td>
      <td>${badge(filter === 'in_progress' || filter === 'closed' ? null : dOpen, 'open')}</td>
      <td>${badge(filter === 'open' || filter === 'closed' ? null : dIp, 'in_progress')}</td>
      <td>${badge(filter === 'open' || filter === 'in_progress' ? null : dCl, 'closed')}</td>
      <td style="font-weight:600;color:var(--gray-900);">${dTotal ?? 0}</td>
      <td>${rateBar}</td>`;
    tbody.appendChild(tr);
  });
}

function filterDashboard(filter) {
  currentFilter = filter;
  currentHovered = -1;

  // Update active card styling
  document.querySelectorAll('.stat-card').forEach(card => {
    card.classList.toggle('active', card.dataset.filter === filter);
  });

  // Rebuild pie
  const segments = buildSegments(filter);
  const { slices, total } = computeSlices(segments);
  activeSlices = slices;
  activeTotal  = total;
  drawPie();
  updateLegend(segments, total);

  // Rebuild table
  updateTable(filter);

  // Update section title
  const titles = { all: 'Status Breakdown per Department', open: 'Open Tickets per Department', in_progress: 'In Progress per Department', closed: 'Closed Tickets per Department' };
  const pieLabel = { all: 'Complaints by Department', open: 'Open by Department', in_progress: 'In Progress by Department', closed: 'Closed by Department' };
  document.querySelector('.dept-card .section-title').textContent  = titles[filter]   || titles.all;
  document.querySelector('.pie-card  .section-title').textContent  = pieLabel[filter] || pieLabel.all;
}

// ── Init on page load ──
(function init() {
  if (!canvas) return;
  filterDashboard('all');

  canvas.addEventListener('mousemove', e => {
    const rect = canvas.getBoundingClientRect();
    const idx  = hitSlice(e.clientX - rect.left, e.clientY - rect.top);
    if (idx !== currentHovered) { currentHovered = idx; drawPie(idx); }
    if (idx >= 0) {
      const s = activeSlices[idx];
      ttTitle.textContent       = s.label;
      ttSwatch.style.background = s.color;
      ttText.textContent        = s.value + ' tickets (' + s.pct + '% of total)';
      tooltip.style.display     = 'block';
      tooltip.style.left        = (e.clientX + 14) + 'px';
      tooltip.style.top         = (e.clientY - 10) + 'px';
    } else { tooltip.style.display = 'none'; }
  });
  canvas.addEventListener('mouseleave', () => {
    currentHovered = -1; drawPie(); tooltip.style.display = 'none';
  });
})();
</script>
  

<!-- % Share tooltip toggle -->
<script>
(function(){
  const el  = document.getElementById('share-help');
  const tip = document.getElementById('share-tooltip');
  if (!el || !tip) return;
  el.addEventListener('mouseenter', () => tip.style.display = 'block');
  el.addEventListener('mouseleave', () => tip.style.display = 'none');
})();

(function(){
  const el  = document.getElementById('rate-help');
  const tip = document.getElementById('rate-tooltip');
  if (!el || !tip) return;
  el.addEventListener('mouseenter', () => tip.style.display = 'block');
  el.addEventListener('mouseleave', () => tip.style.display = 'none');
})();
</script>

</div><!-- /.page-body -->
</div><!-- /.main-content -->
</body>
</html>