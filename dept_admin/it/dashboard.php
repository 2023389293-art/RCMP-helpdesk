<?php
// dept_admin/it/dashboard.php
// ══════════════════════════════════════════════════════════════════════
// CHANGES vs old dashboard:
//  • CSS extracted → dashboard.css (link added in <head>)
//  • KPI cards now BUTTONS that switch the chart view (no href)
//  • Calendar section REMOVED entirely
//  • SLA Monitoring card REMOVED entirely
//  • "middle-row" / "bottom-grid" layout replaced by a single
//    full-width .chart-panel that holds 4 views toggled by the KPI cards
//    and a tab row:  Status | Priority | Category | Rating
// ══════════════════════════════════════════════════════════════════════

require '_layout.php';
require_once __DIR__ . '/../../sla_helper.php';

// ── Stats ──────────────────────────────────────────────────────────────
$stats = [];
$r = $conn->query("SELECT COUNT(*) AS n FROM complaints WHERE dept_id = 4");
$stats['total'] = $r->fetch_assoc()['n'];
$r = $conn->query("SELECT COUNT(*) AS n FROM complaints WHERE dept_id = 4 AND status = 'open'");
$stats['open'] = $r->fetch_assoc()['n'];
$r = $conn->query("SELECT COUNT(*) AS n FROM complaints WHERE dept_id = 4 AND status = 'in_progress'");
$stats['in_progress'] = $r->fetch_assoc()['n'];
$r = $conn->query("SELECT COUNT(*) AS n FROM complaints WHERE dept_id = 4 AND status = 'closed'");
$stats['closed'] = $r->fetch_assoc()['n'];

// Priority breakdown (for chart)
$r = $conn->query("SELECT priority, COUNT(*) AS n FROM complaints WHERE dept_id = 4 GROUP BY priority");
$priorityCounts = ['high'=>0,'medium'=>0,'low'=>0];
while($row = $r->fetch_assoc()) $priorityCounts[$row['priority']] = (int)$row['n'];

// Rating
$r = $conn->query("SELECT ROUND(AVG(f.rating),1) AS avg_r, COUNT(*) AS total_r FROM ticket_feedback f JOIN complaints c ON c.ticket_id=f.ticket_id WHERE c.dept_id=4");
$ratingRow = $r->fetch_assoc();
$stats['avg_rating']    = $ratingRow['avg_r']   ?? 'N/A';
$stats['total_reviews'] = $ratingRow['total_r'] ?? 0;

$ratingBreakdown = [];
for ($i = 5; $i >= 1; $i--) {
    $r = $conn->query("SELECT COUNT(*) AS n FROM ticket_feedback f JOIN complaints c ON c.ticket_id=f.ticket_id WHERE c.dept_id=4 AND f.rating=$i");
    $ratingBreakdown[$i] = (int)$r->fetch_assoc()['n'];
}

// Category breakdown (for chart)
$catBreakdown = $conn->query("SELECT cat.category_name, COUNT(*) AS n FROM complaints c JOIN categories cat ON cat.category_id=c.category_id WHERE c.dept_id=4 GROUP BY c.category_id ORDER BY n DESC")->fetch_all(MYSQLI_ASSOC);

// ── SLA breach awareness ───────────────────────────────────────────────────
$slaAlerts = ['breached' => 0, 'at_risk' => 0];
$openTickets = $conn->query("
    SELECT ticket_id, title, created_at, resolved_at, status
    FROM complaints
    WHERE dept_id = 4 AND status = 'open'
")->fetch_all(MYSQLI_ASSOC);

$breachList = [];
foreach ($openTickets as $t) {
    $sla = getSlaStatus(
        $t['created_at'],
        $t['resolved_at'] ?? null,
        $t['status'],
        null
    );
    if ($sla['breached'])                  $slaAlerts['breached']++;
    elseif ($sla['remaining_mins'] <= 240)  $slaAlerts['at_risk']++;

    if ($sla['breached'] || $sla['remaining_mins'] <= 240) {
        $breachList[] = [
            'ticket_id'     => $t['ticket_id'],
            'title'         => $t['title'],
            'status'        => $t['status'],
            'breached'      => $sla['breached'],
            'remaining_str' => $sla['remaining_str'],
            'status_color'  => $sla['status_color'],
            'status_label'  => $sla['status_label'],
        ];
    }
}

// Department breakdown (for chart)
$topDepts = $conn->query("SELECT c.my_department AS dept_name, COUNT(*) AS n FROM complaints c WHERE c.dept_id=4 GROUP BY c.my_department ORDER BY n DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>IT Admin — Dashboard | UniKL Help Desk</title>
  <?php include '_head_assets.php'; ?>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@400;600&display=swap" rel="stylesheet"/>
  <!-- ✅ NEW: separated CSS file (place dashboard.css in same folder) -->
  <link rel="stylesheet" href="css/dashboards.css"/>
</head>
<body>
<?php include '_sidebar.php'; ?>
<main class="main-content">

  <!-- ══ HEADER ══════════════════════════════════════════════════════ -->
  <div class="dash-header">
    <div>
      <div class="dash-eyebrow">IT Help Desk · Admin Panel</div>
      <h1 class="dash-h1">IT Department <span class="accent">Dashboard</span></h1>
    </div>
    <div class="dash-date-pill">
      <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      <span id="dashDateLabel"></span>
    </div>
  </div>

  <!-- ══ KPI CARDS (now buttons — click = change chart) ══════════════ -->
  <!--
    CHANGE from old code:
      • <a href="tickets.php"> removed → replaced with <button>
      • onclick="switchChart('view-name')" added to each card
      • class "active" is toggled via JS to show which view is selected
  -->
  <div class="stats-mini-grid">

    <button class="mini-card active" id="kpi-total" onclick="switchChart('status')">
      <div class="mini-icon indigo">
        <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      </div>
      <div class="mini-card-body">
        <div class="mini-val"><?= $stats['total'] ?></div>
        <div class="mini-label">All Tickets</div>
      </div>
    </button>

    <button class="mini-card" id="kpi-open" onclick="switchChart('status')">
  <div class="mini-icon amber">
    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
  </div>
  <div class="mini-card-body">
    <div class="mini-val"><?= $stats['open'] ?></div>
    <div class="mini-label">
      Open
      <?php if ($slaAlerts['breached'] > 0): ?>
  <span class="sla-pill sla-pill--red"><?= $slaAlerts['breached'] ?> breached</span>
<?php elseif ($slaAlerts['at_risk'] > 0): ?>
  <span class="sla-pill sla-pill--amber"><?= $slaAlerts['at_risk'] ?> at risk</span>
<?php endif; ?>
    </div>
  </div>
</button>

    <button class="mini-card" id="kpi-closed" onclick="switchChart('category')">
      <div class="mini-icon teal">
        <svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      </div>
      <div class="mini-card-body">
        <div class="mini-val"><?= $stats['closed'] ?></div>
        <div class="mini-label">Closed</div>
      </div>
    </button>

    <button class="mini-card" id="kpi-rating" onclick="switchChart('rating')">
      <div class="mini-icon indigo">
        <svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
      </div>
      <div class="mini-card-body">
        <div class="mini-val"><?= $stats['avg_rating'] !== 'N/A' ? $stats['avg_rating'] : 'N/A' ?></div>
        <div class="mini-label">Avg Rating</div>
      </div>
    </button>

  </div><!-- /stats-mini-grid -->


<?php if ($slaAlerts['breached'] > 0 || $slaAlerts['at_risk'] > 0): ?>
<div class="sla-alert-bar <?= $slaAlerts['breached'] > 0 ? 'sla-alert-bar--red' : 'sla-alert-bar--amber' ?>">
  <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
  <?php if ($slaAlerts['breached'] > 0): ?>
    <strong><?= $slaAlerts['breached'] ?> ticket<?= $slaAlerts['breached'] > 1 ? 's have' : ' has' ?> breached SLA</strong>
    <?php if ($slaAlerts['at_risk'] > 0): ?>
      &nbsp;·&nbsp; <?= $slaAlerts['at_risk'] ?> more at risk
    <?php endif; ?>
  <?php else: ?>
    <strong><?= $slaAlerts['at_risk'] ?> ticket<?= $slaAlerts['at_risk'] > 1 ? 's are' : ' is' ?> approaching SLA breach</strong> — action needed within 4 hours
  <?php endif; ?>
  <button class="sla-alert-link" onclick="toggleBreachList()" id="breachToggleBtn">Show details ▾</button>
</div>

<!-- Breach detail table -->
<div id="breachListWrap" style="display:none;margin-bottom:16px;">
  <table class="breach-table">
    <thead>
      <tr>
        <th>Ticket ID</th>
        <th>Title</th>
        <th>Status</th>
        <th>SLA</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($breachList as $b): ?>
      <tr>
        <td class="breach-tid"><?= htmlspecialchars($b['ticket_id']) ?></td>
        <td class="breach-title"><?= htmlspecialchars($b['title']) ?></td>
        <td><span class="breach-status-chip"><?= $b['status'] === 'in_progress' ? 'In Progress' : ucfirst($b['status']) ?></span></td>
        <td><span class="breach-sla-chip" style="color:<?= $b['status_color'] ?>"><?= htmlspecialchars($b['remaining_str']) ?></span></td>
        <td><a href="ticket_detail.php?id=<?= urlencode($b['ticket_id']) ?>" class="breach-view-btn">View →</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

  <!-- ══ MAIN CHART PANEL (full width, replaces calendar + bottom grid) -->
  <!--
    REMOVED: .middle-row (calendar + old chart panel)
    REMOVED: .bottom-grid (SLA Monitoring + right-stack)
    NEW:     Single .chart-panel with tab switcher
  -->
  <div class="chart-panel">
    <div class="chart-panel-top">
      <div>
        <div class="chart-panel-title" id="chartTitle">Ticket Status Overview</div>
        <div class="chart-panel-sub" id="chartSub">Breakdown across all tickets in this department</div>
      </div>
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <!-- Tab switcher — mirrors KPI cards but stays visible -->
        <div class="chart-tabs">
          <button class="chart-tab active" onclick="switchChart('status',this)">Status & Priority</button>
<button class="chart-tab"        onclick="switchChart('category',this)">Category</button>
<button class="chart-tab"        onclick="switchChart('rating',this)">Rating</button>
        </div>
        <!-- Dynamic badge showing current filter label -->
        <div class="filter-badge" id="filterBadge">
          <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
          <span id="filterBadgeText">All Tickets</span>
        </div>
      </div>
    </div>

    <!-- Chart canvas (shown for status / priority / category) -->
    <div class="chart-canvas-wrap" id="chartCanvasWrap">
      <!-- Group labels shown only for status/priority view -->
      <div id="chartGroupLabels" style="display:none; justify-content:center; gap:40px; margin-bottom:8px;">
        <div style="display:flex;align-items:center;gap:10px;">
          <span style="font-size:10px;font-weight:800;letter-spacing:.08em;color:#64748b;text-transform:uppercase;">Status</span>
          <span style="display:flex;gap:6px;align-items:center;">
            <span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;color:#DC2626;"><span style="width:10px;height:10px;border-radius:3px;background:#DC2626;display:inline-block;"></span>Open</span>
            <span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;color:#2563EB;"><span style="width:10px;height:10px;border-radius:3px;background:#2563EB;display:inline-block;"></span>In Progress</span>
            <span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;color:#16A34A;"><span style="width:10px;height:10px;border-radius:3px;background:#16A34A;display:inline-block;"></span>Closed</span>
          </span>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
          <span style="font-size:10px;font-weight:800;letter-spacing:.08em;color:#64748b;text-transform:uppercase;">Priority</span>
          <span style="display:flex;gap:6px;align-items:center;">
            <span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;color:#e64545;"><span style="width:10px;height:10px;border-radius:3px;background:#e64545;display:inline-block;"></span>High</span>
            <span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;color:#e48a36;"><span style="width:10px;height:10px;border-radius:3px;background:#e48a36;display:inline-block;"></span>Medium</span>
            <span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;color:#93c5fd;"><span style="width:10px;height:10px;border-radius:3px;background:#93c5fd;display:inline-block;"></span>Low</span>
          </span>
        </div>
      </div>
      <canvas id="mainChart"></canvas>
    </div>

    <!-- Rating panel (shown only when rating tab active) -->
    <!--
      This replaces the old "Satisfaction Rating" card that was in right-stack.
      It lives right inside the chart panel so the layout stays one-column.
    -->
    <div id="ratingPanel" class="rating-panel" style="display:none;">

      <div class="rating-hero-inner">
        <?php if ($stats['avg_rating'] !== 'N/A'): ?>
          <div class="rating-score"><?= $stats['avg_rating'] ?></div>
          <div class="rating-out">out of 5.0</div>
          <div class="rating-faces">
            <?php
            $avg = floatval($stats['avg_rating']);
            $faceData = [
              1 => ['stroke'=>'#EF4444','fill'=>'#FEE2E2','mouth'=>'<path d="M16 33c2-4 14-4 16 0" stroke="#EF4444" stroke-width="2.5" stroke-linecap="round"/><path d="M15 15l4 3M33 15l-4 3" stroke="#EF4444" stroke-width="2" stroke-linecap="round"/>'],
              2 => ['stroke'=>'#F97316','fill'=>'#FFEDD5','mouth'=>'<path d="M17 32c2-3 12-3 14 0" stroke="#F97316" stroke-width="2.5" stroke-linecap="round"/>'],
              3 => ['stroke'=>'#EAB308','fill'=>'#FEF9C3','mouth'=>'<line x1="17" y1="32" x2="31" y2="32" stroke="#EAB308" stroke-width="2.5" stroke-linecap="round"/>'],
              4 => ['stroke'=>'#22C55E','fill'=>'#DCFCE7','mouth'=>'<path d="M16 28c2 4 14 4 16 0" stroke="#22C55E" stroke-width="2.5" stroke-linecap="round"/>'],
              5 => ['stroke'=>'#16A34A','fill'=>'#D1FAE5','mouth'=>'<path d="M14 27c2 6 18 6 20 0" stroke="#16A34A" stroke-width="2.5" stroke-linecap="round"/>'],
            ];
            foreach ($faceData as $val => $f):
              $active = ($val <= round($avg)) ? 'active' : '';
            ?>
            <svg class="rating-face <?= $active ?>" viewBox="0 0 48 48" fill="none">
              <circle cx="24" cy="24" r="22" stroke="<?= $f['stroke'] ?>" stroke-width="2.5" fill="<?= $f['fill'] ?>"/>
              <circle cx="17" cy="20" r="2.5" fill="<?= $f['stroke'] ?>"/>
              <circle cx="31" cy="20" r="2.5" fill="<?= $f['stroke'] ?>"/>
              <?= $f['mouth'] ?>
            </svg>
            <?php endforeach; ?>
          </div>
          <div class="rating-reviews"><?= $stats['total_reviews'] ?> review<?= $stats['total_reviews'] != 1 ? 's' : '' ?></div>
        <?php else: ?>
          <div class="empty-text" style="padding:20px 0;">No reviews yet</div>
        <?php endif; ?>
      </div>

      <!-- Bar breakdown (5→1) -->
      <div class="rating-bars">
        <?php
        $maxCount = max(array_values($ratingBreakdown)) ?: 1;
        $faceBarData = [
          5 => ['stroke'=>'#16A34A','fill'=>'#D1FAE5','mouth'=>'<path d="M14 27c2 6 18 6 20 0" stroke="#16A34A" stroke-width="2.5" stroke-linecap="round"/>','ey'=>19],
          4 => ['stroke'=>'#22C55E','fill'=>'#DCFCE7','mouth'=>'<path d="M16 28c2 4 14 4 16 0" stroke="#22C55E" stroke-width="2.5" stroke-linecap="round"/>','ey'=>20],
          3 => ['stroke'=>'#EAB308','fill'=>'#FEF9C3','mouth'=>'<line x1="17" y1="32" x2="31" y2="32" stroke="#EAB308" stroke-width="2.5" stroke-linecap="round"/>','ey'=>20],
          2 => ['stroke'=>'#F97316','fill'=>'#FFEDD5','mouth'=>'<path d="M17 32c2-3 12-3 14 0" stroke="#F97316" stroke-width="2.5" stroke-linecap="round"/>','ey'=>20],
          1 => ['stroke'=>'#EF4444','fill'=>'#FEE2E2','mouth'=>'<path d="M16 33c2-4 14-4 16 0" stroke="#EF4444" stroke-width="2.5" stroke-linecap="round"/><path d="M15 15l4 3M33 15l-4 3" stroke="#EF4444" stroke-width="2" stroke-linecap="round"/>','ey'=>20],
        ];
        foreach ($faceBarData as $i => $f):
          $cnt = $ratingBreakdown[$i];
          $pct = round(($cnt / $maxCount) * 100);
        ?>
        <div class="rb-row">
          <svg class="rb-face-icon" viewBox="0 0 48 48" fill="none">
            <circle cx="24" cy="24" r="22" stroke="<?= $f['stroke'] ?>" stroke-width="2.5" fill="<?= $f['fill'] ?>"/>
            <circle cx="17" cy="<?= $f['ey'] ?>" r="2.5" fill="<?= $f['stroke'] ?>"/>
            <circle cx="31" cy="<?= $f['ey'] ?>" r="2.5" fill="<?= $f['stroke'] ?>"/>
            <?= $f['mouth'] ?>
          </svg>
          <div class="rb-track">
            <div class="rb-fill" style="width:<?= $pct ?>%"></div>
          </div>
          <span class="rb-count"><?= $cnt ?></span>
        </div>
        <?php endforeach; ?>
      </div>

    </div><!-- /ratingPanel -->

  </div><!-- /chart-panel -->

</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
// ══ Data from PHP ════════════════════════════════════════════════════
const DEPT_DATA = <?= json_encode(array_values($topDepts)) ?>;
const CAT_DATA  = <?= json_encode(array_values($catBreakdown)) ?>;
const STATUS_DATA = {
  open:        <?= $stats['open'] ?>,
  in_progress: <?= $stats['in_progress'] ?>,
  closed:      <?= $stats['closed'] ?>
};
const PRIORITY_DATA = <?= json_encode($priorityCounts) ?>;

/*
  NOTE for developer:
  Add these queries in the PHP section above (after $stats['closed']):

    $r = $conn->query("SELECT priority, COUNT(*) AS n FROM complaints WHERE dept_id=4 GROUP BY priority");
    $priorityCounts = ['high'=>0,'medium'=>0,'low'=>0];
    while($row = $r->fetch_assoc()) $priorityCounts[$row['priority']] = (int)$row['n'];

  Then replace the placeholder above with:
    const PRIORITY_DATA = <?= json_encode($priorityCounts) ?>;
*/

Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
Chart.defaults.color = '#94a3b8';

document.getElementById('dashDateLabel').textContent =
  new Date().toLocaleDateString('en-GB', {weekday:'short', day:'numeric', month:'long', year:'numeric'});

// Formal distinct palette — no two adjacent colors look similar
const PRO_PALETTE = [
  '#1e3a5f',  // deep navy
  '#0e7490',  // teal
  '#15803d',  // forest green
  '#7c3aed',  // violet
  '#b45309',  // amber brown
  '#be123c',  // crimson
  '#0369a1',  // steel blue
  '#4d7c0f',  // olive green
  '#6d28d9',  // purple
  '#0f766e',  // dark teal
];

// ══ Chart instance ════════════════════════════════════════════════════
let mainChartInstance = null;

function destroyChart() {
  if (mainChartInstance) { mainChartInstance.destroy(); mainChartInstance = null; }
}

function buildGroupedChart(statusLabels, statusValues, priorityLabels, priorityValues) {
  destroyChart();
  const ctx = document.getElementById('mainChart').getContext('2d');

  // Add a blank spacer between status group and priority group
  const labels = [...statusLabels, '', ...priorityLabels];
  const data   = [...statusValues, null, ...priorityValues];
  const colors = [
    '#DC2626', '#2563EB', '#16A34A',   // Open, In Progress, Closed
    'rgba(0,0,0,0)',                    // spacer
    '#e64545', '#e48a36', '#93c5fd'    // High, Medium, Low
  ];

  mainChartInstance = new Chart(ctx, {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        label: 'Count',
        data,
        backgroundColor: colors,
        borderRadius: 8,
        borderSkipped: false,
        maxBarThickness: 52,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: 'rgba(15,23,42,.95)',
          titleColor: '#fff',
          bodyColor: 'rgba(255,255,255,.75)',
          padding: 12,
          cornerRadius: 8,
          filter: item => item.parsed.y !== null && item.label !== '',
          callbacks: {
            label: ctx => `  ${ctx.parsed.y} tickets`
          }
        }
      },
      scales: {
        x: { grid: { display: false }, ticks: { font: { size: 11 } }, border: { display: false } },
        y: { grid: { color: 'rgba(226,232,240,.5)' }, ticks: { font: { size: 11 } }, border: { display: false }, min: 0 }
      }
    }
  });
}

function buildChart(labels, values, colors, isHorizontal = false) {
  destroyChart();
  const ctx = document.getElementById('mainChart').getContext('2d');
  mainChartInstance = new Chart(ctx, {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        data: values,
        backgroundColor: colors,
        borderRadius: 8,
        borderSkipped: false,
        maxBarThickness: 28,
      }]
    },
    options: {
      indexAxis: isHorizontal ? 'y' : 'x',
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: 'rgba(15,23,42,.95)',
          titleColor: '#fff',
          bodyColor: 'rgba(255,255,255,.75)',
          padding: 12,
          cornerRadius: 8,
          callbacks: { label: ctx => `  ${ctx.parsed[isHorizontal?'x':'y']} tickets` }
        }
      },
      scales: {
        x: { grid: { color: isHorizontal ? 'rgba(226,232,240,.5)' : 'none' }, ticks: { font:{size:11} }, border:{display:false}, min: 0 },
        y: { grid: { color: isHorizontal ? 'none' : 'rgba(226,232,240,.5)' }, ticks: { font:{size:11} }, border:{display:false}, min: 0 }
      }
    }
  });
}

// ══ View definitions ══════════════════════════════════════════════════
const VIEWS = {
  status: {
    title:  'Ticket Status & Priority Overview',
    sub:    'Combined breakdown of status and priority across all tickets',
    badge:  'All Tickets',
    render() {
      showCanvas(true);
      buildGroupedChart(
        ['Open', 'In Progress', 'Closed'],
        [STATUS_DATA.open, STATUS_DATA.in_progress, STATUS_DATA.closed],
        ['High', 'Medium', 'Low'],
        [PRIORITY_DATA.high, PRIORITY_DATA.medium, PRIORITY_DATA.low]
      );
    }
  },
  priority: {
    title:  'Ticket Status & Priority Overview',
    sub:    'Combined breakdown of status and priority across all tickets',
    badge:  'By Priority',
    render() {
      showCanvas(true);
      buildGroupedChart(
        ['Open', 'In Progress', 'Closed'],
        [STATUS_DATA.open, STATUS_DATA.in_progress, STATUS_DATA.closed],
        ['High', 'Medium', 'Low'],
        [PRIORITY_DATA.high, PRIORITY_DATA.medium, PRIORITY_DATA.low]
      );
    }
  },
  category: {
    title:  'Tickets by Category',
    sub:    'Which categories receive the most complaints',
    badge:  'By Category',
    render() {
      showCanvas(false, true);
      const labels = CAT_DATA.map(r => {
        const n = (r.category_name || '').split('/').pop().trim();
        return n.length > 24 ? n.slice(0, 23) + '…' : n;
      });
      buildChart(labels, CAT_DATA.map(r => r.n), labels.map((_, i) => PRO_PALETTE[i % PRO_PALETTE.length]), true);
    }
  },
  rating: {
    title:  'Satisfaction Rating',
    sub:    'Feedback scores submitted by users',
    badge:  'Rating',
    render() {
      destroyChart();
      document.getElementById('chartCanvasWrap').style.display = 'none';
      document.getElementById('ratingPanel').style.display = 'grid';
    }
  }
};

function showCanvas(showGroupLabels = false, isCategory = false) {
  const wrap = document.getElementById('chartCanvasWrap');
  wrap.style.display = 'block';
  document.getElementById('ratingPanel').style.display = 'none';
  document.getElementById('chartGroupLabels').style.display = showGroupLabels ? 'flex' : 'none';

  // Category uses dynamic height; others use fixed 340px
  if (isCategory) {
    wrap.classList.add('category-view');
    const barH = Math.max(160, CAT_DATA.length * 52 + 80);
    wrap.style.height = barH + 'px';
  } else {
    wrap.classList.remove('category-view');
    wrap.style.height = '340px';
  }

  // Force canvas to re-measure after display:block (fixes mobile Safari blank chart)
  const canvas = document.getElementById('mainChart');
  canvas.style.width  = '100%';
  canvas.style.height = '100%';
}

// ══ switchChart — called by KPI cards AND tabs ════════════════════════
let currentView = 'status';

function switchChart(view, tabEl) {
  currentView = view;
  const def = VIEWS[view];
  if (!def) return;

  // Update header text
  document.getElementById('chartTitle').textContent    = def.title;
  document.getElementById('chartSub').textContent      = def.sub;
  document.getElementById('filterBadgeText').textContent = def.badge;

  // Update KPI card active state — highlight the card that was clicked (if any)
  document.querySelectorAll('.mini-card').forEach(c => c.classList.remove('active'));
  // If called from a KPI card button, highlight it directly
  if (document.activeElement && document.activeElement.classList.contains('mini-card')) {
    document.activeElement.classList.add('active');
  } else {
    // Called from tab — highlight the default card for this view
    const kpiMap = { status:'kpi-total', category:'kpi-closed', rating:'kpi-rating' };
    const kpiEl = document.getElementById(kpiMap[view]);
    if (kpiEl) kpiEl.classList.add('active');
  }

  // Update tab active state
  document.querySelectorAll('.chart-tab').forEach(t => t.classList.remove('active'));
  if (tabEl) {
    tabEl.classList.add('active');
  } else {
    // called from KPI card — sync the matching tab
    const tabMap = { status:0, priority:0, category:1, rating:2 };
    const tabs = document.querySelectorAll('.chart-tab');
    if (tabs[tabMap[view]]) tabs[tabMap[view]].classList.add('active');
  }

  def.render();
}

// ══ Boot ══════════════════════════════════════════════════════════════
// Delay init so canvas has real dimensions on mobile before Chart.js measures it
requestAnimationFrame(() => {
  switchChart('status');
});
</script>
<?php include '_foot_scripts.php'; ?>
<script>
function toggleBreachList() {
  var wrap = document.getElementById('breachListWrap');
  var btn  = document.getElementById('breachToggleBtn');
  if (wrap.style.display === 'none') {
    wrap.style.display = 'block';
    btn.textContent = 'Hide details ▴';
  } else {
    wrap.style.display = 'none';
    btn.textContent = 'Show details ▾';
  }
}
</script>
</body>
</html>