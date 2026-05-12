<?php
//dept_admin/hcd/dashboard.php

require '_layout.php';
require_once __DIR__ . '/../../sla_helper.php'; 
// ── Stats ──────────────────────────────────────────────────────────────────
$stats = [];
$r = $conn->query("SELECT COUNT(*) AS n FROM complaints WHERE dept_id = 5");
$stats['total'] = $r->fetch_assoc()['n'];
$r = $conn->query("SELECT COUNT(*) AS n FROM complaints WHERE dept_id = 5 AND status = 'open'");
$stats['open'] = $r->fetch_assoc()['n'];
$r = $conn->query("SELECT COUNT(*) AS n FROM complaints WHERE dept_id = 5 AND status = 'in_progress'");
$stats['in_progress'] = $r->fetch_assoc()['n'];
$r = $conn->query("SELECT COUNT(*) AS n FROM complaints WHERE dept_id = 5 AND status = 'closed'");
$stats['closed'] = $r->fetch_assoc()['n'];
$r = $conn->query("SELECT COUNT(*) AS n FROM staff WHERE dept_id = 5 AND status = 'active' AND role = 'staff'");
$stats['staff'] = $r->fetch_assoc()['n'];
$r = $conn->query("SELECT ROUND(AVG(f.rating),1) AS avg_r, COUNT(*) AS total_r FROM ticket_feedback f JOIN complaints c ON c.ticket_id=f.ticket_id WHERE c.dept_id=5");
$ratingRow = $r->fetch_assoc();
$stats['avg_rating']    = $ratingRow['avg_r']   ?? 'N/A';
$stats['total_reviews'] = $ratingRow['total_r'] ?? 0;

$ratingBreakdown = [];
for ($i = 5; $i >= 1; $i--) {
    $r = $conn->query("SELECT COUNT(*) AS n FROM ticket_feedback f JOIN complaints c ON c.ticket_id=f.ticket_id WHERE c.dept_id=5 AND f.rating=$i");
    $ratingBreakdown[$i] = (int)$r->fetch_assoc()['n'];
}

// Top departments
$topDepts = $conn->query("SELECT c.my_department AS dept_name, COUNT(*) AS n FROM complaints c WHERE c.dept_id=5 GROUP BY c.my_department ORDER BY n DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);
$totalComplaints = max($stats['total'], 1);

// Category breakdown
$catBreakdown = $conn->query("SELECT cat.category_name, COUNT(*) AS n FROM complaints c JOIN categories cat ON cat.category_id=c.category_id WHERE c.dept_id=5 GROUP BY c.category_id ORDER BY n DESC")->fetch_all(MYSQLI_ASSOC);




// Pull all open tickets with sla_start_at for SLA calc
$slaRaw = $conn->query("
    SELECT
        c.ticket_id, c.title, c.status, c.priority,
        c.created_at, c.sla_start_at, c.resolved_at,
        s.full_name AS assigned_staff_name
    FROM complaints c
    LEFT JOIN staff s ON s.staff_id = c.assigned_to
    WHERE c.dept_id = 5
      AND c.status != 'closed'
    ORDER BY c.created_at ASC
")->fetch_all(MYSQLI_ASSOC);

$stats['sla_breached'] = 0;
$stats['sla_due_soon'] = 0;
$slaTickets        = [];
$slaDueSoonTickets = [];

foreach ($slaRaw as $row) {
    $slaStartStr = !empty($row['sla_start_at']) ? $row['sla_start_at'] : $row['created_at'];

    // Use the SAME getSlaStatus() as the staff dashboard
    $slaData = getSlaStatus($slaStartStr, $row['resolved_at'] ?? null, $row['status']);

    $elapsed = $slaData['elapsed_mins'];
    $row['age_hours']   = round($elapsed / 60, 1);
    $row['sla_elapsed'] = $elapsed;

    if ($slaData['breached']) {
        $stats['sla_breached']++;
        $slaTickets[] = $row;
    } elseif (!$slaData['breached'] && $slaData['remaining_mins'] <= 60) {
        // "Due soon" = 1 hour or less remaining (matches "At Risk" in sla_helper)
        $stats['sla_due_soon']++;
        $slaDueSoonTickets[] = $row;
    }
}
// Sort breached: worst first, keep top 5
usort($slaTickets, fn($a,$b) => $b['sla_elapsed'] <=> $a['sla_elapsed']);
$slaTickets = array_slice($slaTickets, 0, 5);

// Sort due soon: closest to breach first, keep top 5
usort($slaDueSoonTickets, fn($a,$b) => $b['sla_elapsed'] <=> $a['sla_elapsed']);
$slaDueSoonTickets = array_slice($slaDueSoonTickets, 0, 5);

// Calendar tickets
$calTickets = $conn->query("SELECT c.ticket_id, c.title, c.status, c.priority, 
    DATE_FORMAT(c.created_at,'%Y-%m-%d') AS ticket_date, cat.category_name 
    FROM complaints c JOIN categories cat ON cat.category_id=c.category_id 
    WHERE c.dept_id=5 ORDER BY c.created_at DESC")->fetch_all(MYSQLI_ASSOC);
$calTicketsJson = json_encode($calTickets);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>HCD Admin — Dashboard | UniKL Help Desk</title>
  <?php include '_head_assets.php'; ?>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@400;600&display=swap" rel="stylesheet"/>
  <style>
    :root {
      --indigo-600:#4338ca; --indigo-500:#4f46e5; --indigo-400:#6366f1;
      --indigo-300:#a5b4fc; --indigo-200:#c7d2fe; --indigo-100:#e0e7ff; --indigo-50:#eef2ff;
      --teal-600:#0d9488; --teal-500:#14b8a6; --teal-100:#ccfbf1;
      --navy-100:#f3f4f6; --navy-300:#d1d5db; --navy-700:#374151;
      --surface:#ffffff; --surface-2:#f8fafc;
      --border:#e2e8f0; --text-primary:#0f172a; --text-secondary:#475569; --text-muted:#94a3b8;
    }
    *{box-sizing:border-box;}
    body{font-family:'Plus Jakarta Sans',system-ui,sans-serif;background:var(--surface-2);color:var(--text-primary);}

    /* HEADER */
    .dash-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;padding-bottom:20px;border-bottom:1px solid var(--border);}
    .dash-eyebrow{font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--indigo-500);margin-bottom:6px;display:flex;align-items:center;gap:8px;}
    .dash-eyebrow::before{content:'';display:inline-block;width:20px;height:2px;background:linear-gradient(to right,var(--indigo-500),var(--teal-500));border-radius:2px;}
    .dash-h1{font-size:28px;font-weight:800;color:var(--text-primary);line-height:1.15;letter-spacing:-.02em;}
    .dash-h1 .accent{color:var(--indigo-600);}
    .dash-subtitle{font-size:13px;color:var(--text-muted);margin-top:4px;font-weight:500;}
    .dash-date-pill{background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:8px 14px;font-size:12px;font-weight:600;color:var(--text-secondary);display:flex;align-items:center;gap:7px;box-shadow:0 1px 3px rgba(0,0,0,.06);}
    .dash-date-pill svg{width:14px;height:14px;stroke:var(--indigo-500);fill:none;stroke-width:2;}

    /* KPI CARDS */
    .stats-mini-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px;}
    @media(max-width:900px){.stats-mini-grid{grid-template-columns:repeat(2,1fr);}}
    .mini-card{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:18px 20px;display:flex;flex-direction:row;align-items:center;gap:16px;transition:box-shadow .2s,transform .15s;}
    .mini-card:hover{box-shadow:0 4px 16px rgba(15,23,42,.1);transform:translateY(-1px);}
    .mini-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
    .mini-icon svg{width:22px;height:22px;fill:none;stroke:currentColor;stroke-width:2;}
    .mini-icon.indigo{background:var(--indigo-100);color:var(--indigo-600);}
    .mini-icon.amber{background:#fef3c7;color:#d97706;}
    .mini-icon.sky{background:#dbeafe;color:#2563eb;}
    .mini-icon.teal{background:var(--teal-100);color:var(--teal-600);}
    .mini-icon.red{background:#fee2e2;color:#dc2626;}
    .mini-card-body{display:flex;flex-direction:column;gap:2px;}
    .mini-val{font-size:30px;font-weight:800;color:var(--text-primary);line-height:1.1;font-variant-numeric:tabular-nums;}
    .mini-label{font-size:12px;color:var(--text-muted);font-weight:600;letter-spacing:.02em;}

    /* CARD */
    .card{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:20px;}
    .card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;}
    .card-title{font-size:13px;font-weight:700;color:var(--text-primary);letter-spacing:-.01em;}
    .card-badge{font-size:10px;font-weight:700;letter-spacing:.06em;padding:3px 9px;border-radius:5px;background:var(--indigo-50);color:var(--indigo-600);text-transform:uppercase;border:1px solid var(--indigo-200);}

    /* MIDDLE ROW */
    .middle-row {
      display: grid;
      grid-template-columns: 310px 1fr;
      gap: 16px;
      margin-bottom: 20px;
      align-items: stretch;
    }
    @media(max-width:900px){.middle-row{grid-template-columns:1fr;}}

    /* CALENDAR */
    .cal-card{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:20px;}
    .cal-header {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 10px;
      margin-bottom: 14px;
    }
    .cal-month-label{font-size:14px;font-weight:700;color:var(--text-primary);}
    .cal-controls {align-self: flex-end;display: flex;align-items: center;gap: 6px;}
    .cal-view-toggle{display:flex;background:var(--surface-2);border:1px solid var(--border);border-radius:8px;padding:2px;gap:2px;}
    .cal-view-btn{font-size:10px;font-weight:700;padding:4px 10px;border-radius:6px;border:none;cursor:pointer;color:var(--text-muted);background:transparent;font-family:inherit;letter-spacing:.03em;transition:all .15s;}
    .cal-view-btn.active{background:var(--indigo-600);color:#fff;box-shadow:0 1px 4px rgba(79,70,229,.3);}
    .cal-nav{display:flex;gap:4px;}
    .cal-nav-btn{width:28px;height:28px;border-radius:6px;border:1px solid var(--border);background:var(--surface);display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--text-secondary);font-size:14px;transition:all .15s;font-family:inherit;}
    .cal-nav-btn:hover{background:var(--indigo-50);border-color:var(--indigo-300);color:var(--indigo-600);}
    .cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:2px;}
    .cal-dow{font-size:9px;font-weight:700;color:var(--text-muted);text-align:center;padding:4px 0;text-transform:uppercase;letter-spacing:.06em;}
    .cal-day{aspect-ratio:1;display:flex;flex-direction:column;align-items:center;justify-content:center;border-radius:7px;font-size:12px;font-weight:500;cursor:pointer;color:var(--text-secondary);transition:all .12s;gap:1px;}
    .cal-day:hover{background:var(--indigo-50);color:var(--indigo-700);}
    .cal-day.has-tickets{color:var(--text-primary);font-weight:700;}
    .cal-day-dot{width:5px;height:5px;border-radius:50%;background:var(--indigo-500);}
    .cal-day.today{background:var(--indigo-50);color:var(--indigo-700);font-weight:700;border:1.5px solid var(--indigo-300);}
    .cal-day.selected{background:var(--indigo-600)!important;color:white!important;font-weight:700;box-shadow:0 3px 10px rgba(79,70,229,.35);}
    .cal-day.selected .cal-day-dot{background:rgba(255,255,255,.8);}
    .cal-day.empty-slot{pointer-events:none;}
    .daily-list{display:flex;flex-direction:column;gap:5px;max-height:290px;overflow-y:auto;}
    .daily-item{display:flex;align-items:center;gap:10px;padding:7px 10px;border-radius:8px;border:1px solid var(--border);background:var(--surface-2);cursor:pointer;transition:all .15s;}
    .daily-item:hover{background:var(--indigo-50);border-color:var(--indigo-200);}
    .daily-item.selected-day{background:var(--indigo-600);border-color:var(--indigo-600);}
    .daily-item.selected-day .daily-date-num,.daily-item.selected-day .daily-date-sub{color:#fff;}
    .daily-item.selected-day .daily-badge{background:rgba(255,255,255,.2);color:#fff;}
    .daily-date-num{font-size:17px;font-weight:800;color:var(--text-primary);min-width:26px;text-align:center;}
    .daily-date-sub{font-size:10px;color:var(--text-muted);font-weight:600;}
    .daily-right{margin-left:auto;}
    .daily-badge{font-size:10px;font-weight:700;background:var(--indigo-100);color:var(--indigo-600);border-radius:20px;padding:2px 8px;}
    .daily-no-tickets{opacity:.4;}
    .cal-legend{display:flex;align-items:center;gap:6px;margin-top:12px;padding-top:12px;border-top:1px solid var(--border);font-size:11px;color:var(--text-muted);}
    .cal-legend-dot{width:7px;height:7px;border-radius:50%;background:var(--indigo-500);display:inline-block;}

    /* CHART PANEL */
    .cal-chart-panel {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 20px;
      display: flex;
      flex-direction: column;
      height: 100%;
    }
    #chartArea {
      flex: 1;
      display: flex;
      flex-direction: column;
      min-height: 0;
    }
    #chartArea > div {
      position: relative;
      height: 320px;
    }

    .cal-chart-top{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:14px;}
    .cal-chart-title{font-size:13px;font-weight:700;color:var(--text-primary);}
    .cal-chart-sub{font-size:12px;color:var(--text-muted);margin-top:3px;}
    .cal-date-badge{display:inline-flex;align-items:center;gap:6px;background:var(--indigo-50);border:1px solid var(--indigo-200);border-radius:20px;padding:4px 12px;font-size:11px;font-weight:700;color:var(--indigo-600);}
    .cal-date-badge svg{width:12px;height:12px;stroke:var(--indigo-500);fill:none;stroke-width:2;}
    .chart-empty {
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      color: var(--text-muted);
      gap: 10px;
      text-align: center;
    }
    .chart-empty svg{width:40px;height:40px;stroke:var(--navy-300);fill:none;stroke-width:1.5;}
    .chart-empty p{font-size:13px;font-weight:600;color:var(--text-secondary);margin-bottom:3px;}
    .chart-empty span{font-size:12px;}

    /* MINI KPI inside chart panel */
    .mini-kpi-row { display:grid; grid-template-columns: repeat(3,1fr); gap:10px; margin-bottom:16px; }
    .mini-kpi-box{border-radius:10px;padding:12px;text-align:center;}
    .mini-kpi-val{font-size:26px;font-weight:800;line-height:1;}
    .mini-kpi-lbl{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-top:3px;}

    /* BOTTOM */
    .bottom-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;}
    @media(max-width:900px){.bottom-grid{grid-template-columns:1fr;}}
    .right-stack{display:flex;flex-direction:column;gap:16px;}

    /* SLA */
    .sla-summary{display:grid;grid-template-columns:1fr;gap:10px;margin-bottom:14px;}
    .sla-stat{border-radius:10px;padding:14px 16px;border:1px solid;}
    .sla-stat.red-card{background:#fef2f2;border-color:#fecaca;}
    .sla-stat.amber-card{background:#fffbeb;border-color:#fde68a;}
    .sla-stat-val{font-size:30px;font-weight:800;line-height:1;margin-bottom:3px;font-variant-numeric:tabular-nums;}
    .sla-stat-label{font-size:11px;font-weight:600;letter-spacing:.03em;}
    .sla-stat.red-card .sla-stat-val{color:#dc2626;}
    .sla-stat.red-card .sla-stat-label{color:#ef4444;}
    .sla-stat.amber-card .sla-stat-val{color:#d97706;}
    .sla-stat.amber-card .sla-stat-label{color:#f59e0b;}
    .sla-list{display:flex;flex-direction:column;gap:7px;}
    .sla-item{display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border-radius:8px;border:1px solid var(--border);background:var(--surface-2);transition:all .15s;}
    .sla-item:hover{background:var(--indigo-50);border-color:var(--indigo-200);}
    .sla-tid{font-size:10px;font-family:'IBM Plex Mono',monospace;color:var(--text-muted);margin-bottom:2px;}
    .sla-title{font-size:12px;font-weight:600;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px;}
    .sla-age{font-size:11px;color:var(--text-muted);margin-top:1px;}

    /* ── NEW: Assigned staff pill inside SLA item ── */
    .sla-assignee {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      margin-top: 5px;
      background: var(--indigo-50);
      border: 1px solid var(--indigo-200);
      border-radius: 20px;
      padding: 2px 8px;
      font-size: 10px;
      font-weight: 600;
      color: var(--indigo-600);
      max-width: 180px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .sla-assignee svg {
      width: 11px;
      height: 11px;
      fill: none;
      stroke: var(--indigo-500);
      stroke-width: 2;
      flex-shrink: 0;
    }
    .sla-unassigned {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      margin-top: 5px;
      background: #fafafa;
      border: 1px dashed var(--navy-300);
      border-radius: 20px;
      padding: 2px 8px;
      font-size: 10px;
      font-weight: 600;
      color: var(--text-muted);
    }
    .sla-unassigned svg {
      width: 11px;
      height: 11px;
      fill: none;
      stroke: var(--navy-300);
      stroke-width: 2;
      flex-shrink: 0;
    }

    /* COMBO */
    .combo-tabs{display:flex;gap:4px;}
    .combo-tab{font-size:11px;font-weight:700;padding:4px 12px;border-radius:6px;border:1px solid var(--border);cursor:pointer;font-family:inherit;transition:all .15s;background:var(--surface-2);color:var(--text-muted);}
    .combo-tab.active{background:var(--indigo-600);color:#fff;border-color:var(--indigo-600);}

    /* SATISFACTION */
    .rating-hero{text-align:center;background:linear-gradient(135deg,#eef2ff,#e0e7ff);border-radius:12px;padding:16px 16px 12px;margin-bottom:14px;border:1px solid var(--indigo-200);}
    .rating-score{font-size:44px;font-weight:800;line-height:1;letter-spacing:-.03em;color:var(--indigo-600);}
    .rating-out{font-size:12px;font-weight:500;color:var(--text-muted);margin-top:2px;}
    .rating-stars{font-size:16px;margin:7px 0 4px;letter-spacing:2px;}
    .rating-reviews{font-size:11px;font-weight:600;color:var(--text-muted);}
    .rating-bars{display:flex;flex-direction:column;gap:7px;}
    .rb-row{display:flex;align-items:center;gap:8px;font-size:11px;}
    .rb-label{min-width:22px;font-weight:700;color:var(--text-secondary);}
    .rb-track{flex:1;height:7px;background:var(--navy-100);border-radius:4px;overflow:hidden;}
    .rb-fill{height:100%;border-radius:4px;background:linear-gradient(to right,#4338ca,#818cf8);transition:width .8s cubic-bezier(.22,1,.36,1);}
    .rb-count{min-width:20px;text-align:right;font-weight:700;color:var(--text-primary);font-size:11px;}

    /* BADGES */
    .badge{display:inline-block;font-size:10px;font-weight:700;letter-spacing:.04em;padding:2px 8px;border-radius:4px;text-transform:uppercase;}
    .priority-high{background:#fef2f2;color:#dc2626;border:1px solid rgba(220,38,38,.2);}
    .priority-medium{background:#fffbeb;color:#d97706;border:1px solid rgba(217,119,6,.2);}
    .priority-low{background:#f0fdf4;color:#16a34a;border:1px solid rgba(22,163,74,.2);}
    .empty-text{color:var(--text-muted);font-size:13px;text-align:center;padding:20px 0;}

    /* Face icons in hero */
    .rating-faces {
      display: flex;
      justify-content: center;
      gap: 6px;
      margin: 10px 0 6px;
    }
    .rating-face {
      width: 28px;
      height: 28px;
      opacity: .25;
      transition: opacity .15s;
    }
    .rating-face.active {
      opacity: 1;
    }

    /* Face icon in bar rows */
    .rb-face-icon {
      width: 20px;
      height: 20px;
      flex-shrink: 0;
    }
  </style>
</head>
<body>
<?php include '_sidebar.php'; ?>
<main class="main-content">

  <!-- HEADER -->
  <div class="dash-header">
    <div>
      <div class="dash-eyebrow">HCD Help Desk · Admin Panel</div>
      <h1 class="dash-h1">Human Capital <span class="accent">Dashboard</span></h1>
    </div>
    <div class="dash-date-pill">
      <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      <span id="dashDateLabel"></span>
    </div>
  </div>

  <!-- KPI CARDS -->
  <div class="stats-mini-grid">
    <div class="mini-card">
      <div class="mini-icon indigo"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
      <div class="mini-card-body"><div class="mini-val"><?= $stats['total'] ?></div><div class="mini-label">All Tickets</div></div>
    </div>
    <div class="mini-card">
      <div class="mini-icon amber"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
      <div class="mini-card-body"><div class="mini-val"><?= $stats['open'] ?></div><div class="mini-label">Open</div></div>
    </div>
    <div class="mini-card">
      <div class="mini-icon teal"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
      <div class="mini-card-body"><div class="mini-val"><?= $stats['closed'] ?></div><div class="mini-label">Closed</div></div>
    </div>
    <div class="mini-card">
      <div class="mini-icon red"><svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>
      <div class="mini-card-body"><div class="mini-val" style="color:#dc2626"><?= $stats['sla_breached'] ?></div><div class="mini-label">SLA Breached</div></div>
    </div>
  </div>

  <!-- MIDDLE: Calendar + Reactive Chart -->
  <div class="middle-row">

    <!-- Calendar -->
    <div class="cal-card">
      <div class="cal-header">
        <div class="cal-controls">
          <div class="cal-view-toggle">
            <button class="cal-view-btn" id="btnDaily" onclick="setCalView('daily')">Daily</button>
            <button class="cal-view-btn active" id="btnMonthly" onclick="setCalView('monthly')">Monthly</button>
            <button class="cal-view-btn" id="btnYearly" onclick="setCalView('yearly')">Yearly</button>
          </div>
          <div class="cal-nav">
            <button class="cal-nav-btn" onclick="calChangeStep(-1)">&#8249;</button>
            <button class="cal-nav-btn" onclick="calChangeStep(1)">&#8250;</button>
          </div>
        </div>
        <div class="cal-month-label" id="calMonthLabel"></div>
      </div>
      <div id="calGridWrap"></div>
      <div class="cal-legend" id="calLegend">
        <span class="cal-legend-dot"></span>
        <span>Days with tickets — click to view details</span>
      </div>
    </div>

    <!-- Chart Panel (reacts to calendar) -->
    <div class="cal-chart-panel">
      <div class="cal-chart-top">
        <div>
          <div class="cal-chart-title">Ticket Status &amp; Priority</div>
          <div class="cal-chart-sub" id="chartSubtitle">Click a date on the calendar to view breakdown</div>
        </div>
        <div id="calDateBadge" style="display:none;" class="cal-date-badge">
          <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          <span id="calDateBadgeText"></span>
        </div>
      </div>

      <!-- Empty state -->
      <div class="chart-empty" id="chartEmptyState">
        <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="14" x2="16" y2="14"/><line x1="8" y1="18" x2="12" y2="18"/></svg>
        <div>
          <p>No date selected</p>
          <span>Select any highlighted date on the calendar<br>to see that day's ticket breakdown</span>
        </div>
      </div>

      <!-- Chart content (shown after date selected) -->
      <div id="chartArea" style="display:none;">
        <div style="flex:1;position:relative;min-height:0;">
          <canvas id="calDetailChart"></canvas>
        </div>
      </div>
    </div>

  </div><!-- /middle-row -->

  <!-- BOTTOM: SLA left | Breakdown + Rating stacked right -->
  <div class="bottom-grid">

    <!-- SLA Monitoring -->
    <div class="card">
      <div class="card-header">
        <h2 class="card-title">SLA Monitoring</h2>
        <span class="card-badge" style="background:#fef2f2;color:#dc2626;border-color:#fecaca;">⏱ Alerts</span>
      </div>
      <div class="sla-summary" style="grid-template-columns:1fr 1fr;">
    <div class="sla-stat red-card">
        <div class="sla-stat-val"><?= $stats['sla_breached'] ?></div>
        <div class="sla-stat-label">SLA Breached</div>
    </div>
    <div class="sla-stat amber-card">
        <div class="sla-stat-val"><?= $stats['sla_due_soon'] ?></div>
        <div class="sla-stat-label">Due Soon</div>
    </div>
</div>

<?php if (!empty($slaTickets)): ?>
<div style="font-size:11px;font-weight:700;color:#dc2626;letter-spacing:.04em;text-transform:uppercase;margin:12px 0 6px;">⚠ Breached</div>
<div class="sla-list">
    <?php foreach ($slaTickets as $t):
        $staffName = !empty($t['assigned_staff_name']) ? htmlspecialchars($t['assigned_staff_name']) : null;
    ?>
    <div class="sla-item">
        <div style="min-width:0;flex:1;">
            <div class="sla-tid"><?= htmlspecialchars($t['ticket_id']) ?></div>
            <div class="sla-title"><?= htmlspecialchars($t['title']) ?></div>
            <div class="sla-age"><?= $t['age_hours'] ?>h (business) · <?= ucfirst($t['priority']) ?> priority</div>
            <?php if ($staffName): ?>
                <div class="sla-assignee">
                    <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <?= $staffName ?>
                </div>
            <?php else: ?>
                <div class="sla-unassigned">
                    <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    Unassigned
                </div>
            <?php endif; ?>
        </div>
        <span class="badge priority-high" style="flex-shrink:0;margin-left:10px;">SLA Breached</span>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!empty($slaDueSoonTickets)): ?>
<div style="font-size:11px;font-weight:700;color:#d97706;letter-spacing:.04em;text-transform:uppercase;margin:12px 0 6px;">⏱ Due Soon</div>
<div class="sla-list">
    <?php foreach ($slaDueSoonTickets as $t):
        $staffName = !empty($t['assigned_staff_name']) ? htmlspecialchars($t['assigned_staff_name']) : null;
        $remaining = round((480 - $t['sla_elapsed']) / 60, 1);
    ?>
    <div class="sla-item" style="border-color:#fde68a;background:#fffbeb;">
        <div style="min-width:0;flex:1;">
            <div class="sla-tid"><?= htmlspecialchars($t['ticket_id']) ?></div>
            <div class="sla-title"><?= htmlspecialchars($t['title']) ?></div>
            <div class="sla-age"><?= $t['age_hours'] ?>h elapsed · <?= $remaining ?>h remaining · <?= ucfirst($t['priority']) ?> priority</div>
            <?php if ($staffName): ?>
                <div class="sla-assignee">
                    <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <?= $staffName ?>
                </div>
            <?php else: ?>
                <div class="sla-unassigned">
                    <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    Unassigned
                </div>
            <?php endif; ?>
        </div>
        <span class="badge priority-medium" style="flex-shrink:0;margin-left:10px;">Due Soon</span>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (empty($slaTickets) && empty($slaDueSoonTickets)): ?>
<div style="text-align:center;padding:24px 0;color:var(--text-muted);font-size:13px;">✅ All tickets within SLA</div>
<?php endif; ?>
    </div>

    <!-- Right stack -->
    <div class="right-stack">

      <!-- Ticket Breakdown -->
      <div class="card">
        <div class="card-header">
          <h2 class="card-title">Ticket Breakdown</h2>
          <div class="combo-tabs">
            <button class="combo-tab active" onclick="showCombo('dept',event)">By Dept</button>
            <button class="combo-tab" onclick="showCombo('cat',event)">By Category</button>
          </div>
        </div>
        <div style="height:200px;position:relative;">
          <canvas id="comboBar"></canvas>
        </div>
      </div>

      <!-- Satisfaction Rating -->
      <div class="card">
        <div class="card-header">
          <h2 class="card-title">Satisfaction Rating</h2>
          <span class="card-badge">Reviews</span>
        </div>
        <?php if ($stats['avg_rating'] !== 'N/A'): ?>
        <div class="rating-hero">
          <div class="rating-score"><?= $stats['avg_rating'] ?></div>
          <div class="rating-out">out of 5.0</div>

          <!-- Face icons replacing stars — faces 1–5, highlight those at/below avg -->
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
            $eyeY = [1=>20,2=>20,3=>20,4=>20,5=>19];
            foreach ($faceData as $val => $f):
              $active = ($val <= round($avg)) ? 'active' : '';
            ?>
            <svg class="rating-face <?= $active ?>" viewBox="0 0 48 48" fill="none" title="<?= $val ?>">
              <circle cx="24" cy="24" r="22" stroke="<?= $f['stroke'] ?>" stroke-width="2.5" fill="<?= $f['fill'] ?>"/>
              <circle cx="17" cy="<?= $eyeY[$val] ?>" r="2.5" fill="<?= $f['stroke'] ?>"/>
              <circle cx="31" cy="<?= $eyeY[$val] ?>" r="2.5" fill="<?= $f['stroke'] ?>"/>
              <?= $f['mouth'] ?>
            </svg>
            <?php endforeach; ?>
          </div>

          <div class="rating-reviews"><?= $stats['total_reviews'] ?> review<?= $stats['total_reviews'] != 1 ? 's' : '' ?></div>
        </div>

        <!-- Breakdown bars: face icon per row (5→1, best first) -->
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
            <div class="rb-track"><div class="rb-fill" style="width:<?= $pct ?>%"></div></div>
            <span class="rb-count"><?= $cnt ?></span>
          </div>
          <?php endforeach; ?>
        </div>

        <?php else: ?>
        <div class="empty-text" style="padding:30px 0;">No reviews yet</div>
        <?php endif; ?>
      </div>

    </div><!-- /right-stack -->
  </div><!-- /bottom-grid -->

</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const ALL_TICKETS = <?= $calTicketsJson ?>;
const DEPT_DATA   = <?= json_encode(array_values($topDepts)) ?>;
const CAT_DATA    = <?= json_encode(array_values($catBreakdown)) ?>;
const PRO_PALETTE = ['#4338ca','#4f46e5','#6366f1','#818cf8','#a5b4fc','#c7d2fe','#3b82f6'];

Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
Chart.defaults.color = '#94a3b8';
document.getElementById('dashDateLabel').textContent = new Date().toLocaleDateString('en-GB',{weekday:'short',day:'numeric',month:'long',year:'numeric'});

// Index tickets by date
const ticketsByDate = {};
ALL_TICKETS.forEach(t => {
  if (!ticketsByDate[t.ticket_date]) ticketsByDate[t.ticket_date] = [];
  ticketsByDate[t.ticket_date].push(t);
});

// ── Combo Bar ─────────────────────────────────────────────────────
let comboChart = null;
function buildCombo(mode) {
  if (comboChart) comboChart.destroy();
  const raw = mode==='dept' ? DEPT_DATA : CAT_DATA;
  const labels = raw.map(r => {
    const n = mode==='dept' ? (r.dept_name||'Unknown') : (r.category_name||'').split('/').pop().trim();
    return n.length>22 ? n.slice(0,21)+'…' : n;
  });
  comboChart = new Chart(document.getElementById('comboBar'),{
    type:'bar',
    data:{ labels, datasets:[{ data:raw.map(r=>r.n), backgroundColor:labels.map((_,i)=>PRO_PALETTE[i%PRO_PALETTE.length]), borderRadius:7, borderSkipped:false }] },
    options:{
      indexAxis:'y', responsive:true, maintainAspectRatio:false,
      plugins:{ legend:{display:false}, tooltip:{backgroundColor:'rgba(15,23,42,.95)',titleColor:'#fff',bodyColor:'rgba(255,255,255,.75)',padding:10,cornerRadius:8,callbacks:{label:ctx=>`  ${ctx.parsed.x} tickets`}} },
      scales:{ x:{grid:{color:'rgba(226,232,240,.6)'},ticks:{stepSize:1,font:{size:10}},border:{display:false},min:0}, y:{grid:{display:false},ticks:{font:{size:11}},border:{display:false}} }
    }
  });
}
function showCombo(mode, e) {
  document.querySelectorAll('.combo-tab').forEach(b=>b.classList.remove('active'));
  e.target.classList.add('active');
  buildCombo(mode);
}
buildCombo('dept');

// ── Shared: render Status+Priority grouped bar chart ──────────────
const MONTHS_SHORT=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

function getTicketsForPrefix(prefix) {
  return ALL_TICKETS.filter(t=>t.ticket_date.startsWith(prefix));
}

let detailChart = null;

function renderDetailChart(tickets, badgeText, subtitleText) {
  const open  =tickets.filter(t=>t.status==='open').length;
  const prog  =tickets.filter(t=>t.status==='in_progress').length;
  const closed=tickets.filter(t=>t.status==='closed').length;
  const high  =tickets.filter(t=>t.priority==='high').length;
  const medium=tickets.filter(t=>t.priority==='medium').length;
  const low   =tickets.filter(t=>t.priority==='low').length;
  const total =tickets.length;

  document.getElementById('calDateBadge').style.display='inline-flex';
  document.getElementById('calDateBadgeText').textContent=badgeText;
  document.getElementById('chartSubtitle').textContent=subtitleText;
  document.getElementById('chartEmptyState').style.display='none';
  document.getElementById('chartArea').style.display='block';

  if (detailChart) detailChart.destroy();
  detailChart = new Chart(document.getElementById('calDetailChart'),{
    type:'bar',
    data:{
      labels:['Open','Closed','High','Medium','Low'],
      datasets:[
        { label:'Status',   data:[open,closed,null,null,null], backgroundColor:['#4338ca','#a5b4fc',null,null,null], borderRadius:7, borderSkipped:false },
        { label:'Priority', data:[null,null,high,medium,low],  backgroundColor:[null,null,'#1e3a8a','#3b82f6','#93c5fd'], borderRadius:7, borderSkipped:false }
      ]
    },
    options:{
      responsive:true, maintainAspectRatio:false,
      plugins:{
        legend:{display:true,position:'top',labels:{boxWidth:10,boxHeight:10,font:{size:11},padding:12,color:'#475569'}},
        tooltip:{backgroundColor:'rgba(15,23,42,.95)',titleColor:'#fff',bodyColor:'rgba(255,255,255,.75)',padding:10,cornerRadius:8,
          callbacks:{ label:ctx=>ctx.parsed.y!==null?`  ${ctx.dataset.label}: ${ctx.parsed.y}`:null, filter:item=>item.parsed.y!==null }}
      },
      scales:{
        x:{grid:{display:false},ticks:{font:{size:11}},border:{display:false}},
        y:{grid:{color:'rgba(226,232,240,.6)'},ticks:{stepSize:1,precision:0,font:{size:10}},border:{display:false},min:0}
      }
    }
  });
}

// ── Yearly view: stacked bar per month ────────────────────────────
function renderYearlyChart(year) {
  const open_m=[],closed_m=[];
  for(let m=0;m<12;m++){
    const ts=getTicketsForPrefix(`${year}-${pad(m+1)}`);
    open_m.push(ts.filter(t=>t.status==='open').length);
    closed_m.push(ts.filter(t=>t.status==='closed').length);
  }
  const total = open_m.concat(closed_m).reduce((a,b)=>a+b,0);

  document.getElementById('calDateBadge').style.display='inline-flex';
  document.getElementById('calDateBadgeText').textContent=`Year ${year}`;
  document.getElementById('chartSubtitle').textContent=`${total} ticket${total!==1?'s':''} across all of ${year}`;
  document.getElementById('chartEmptyState').style.display='none';
  document.getElementById('chartArea').style.display='block';

  if (detailChart) detailChart.destroy();
  detailChart = new Chart(document.getElementById('calDetailChart'),{
    type:'bar',
    data:{
      labels:MONTHS_SHORT,
      datasets:[
        { label:'Open',   data:open_m,   backgroundColor:'#1e3a8a', borderRadius:4, borderSkipped:false },
        { label:'Closed', data:closed_m, backgroundColor:'#a5b4fc', borderRadius:4, borderSkipped:false }
      ]
    },
    options:{
      responsive:true, maintainAspectRatio:false,
      plugins:{
        legend:{display:true,position:'top',labels:{boxWidth:10,boxHeight:10,font:{size:11},padding:12,color:'#475569'}},
        tooltip:{backgroundColor:'rgba(15,23,42,.95)',titleColor:'#fff',bodyColor:'rgba(255,255,255,.75)',padding:10,cornerRadius:8}
      },
      scales:{
        x:{grid:{display:false},ticks:{font:{size:10}},border:{display:false}},
        y:{grid:{color:'rgba(226,232,240,.6)'},ticks:{stepSize:1,precision:0,font:{size:10}},border:{display:false},min:0}
      }
    }
  });
}

function showMonthChart() {
  const year=calCurrent.getFullYear(), month=calCurrent.getMonth();
  const prefix=`${year}-${pad(month+1)}`;
  const tickets=getTicketsForPrefix(prefix);
  const mLabel=MONTHS[month]+' '+year;
  renderDetailChart(tickets, mLabel, `${tickets.length} ticket${tickets.length!==1?'s':''} in ${mLabel}`);
}

// ── Calendar state ────────────────────────────────────────────────
const MONTHS=['January','February','March','April','May','June','July','August','September','October','November','December'];
const DAYS=['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
let calView='monthly', calCurrent=new Date(), calSelected=null;
const TODAY=new Date();

function pad(n){ return String(n).padStart(2,'0'); }
function dateKey(d){ return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`; }

function setCalView(v) {
  calView=v; calSelected=null;
  document.getElementById('btnMonthly').classList.toggle('active',v==='monthly');
  document.getElementById('btnDaily').classList.toggle('active',  v==='daily');
  document.getElementById('btnYearly').classList.toggle('active', v==='yearly');
  calRender();
  if (v==='monthly')     showMonthChart();
  else if (v==='yearly') renderYearlyChart(calCurrent.getFullYear());
}

function calChangeStep(dir) {
  if (calView==='yearly') {
    calCurrent=new Date(calCurrent.getFullYear()+dir,0,1);
    calRender(); renderYearlyChart(calCurrent.getFullYear());
  } else {
    calCurrent=new Date(calCurrent.getFullYear(),calCurrent.getMonth()+dir,1);
    calSelected=null; calRender();
    if (calView==='monthly') showMonthChart();
  }
}

function calRender() {
  const wrap=document.getElementById('calGridWrap');
  if (calView==='monthly')     renderMonthly(wrap);
  else if (calView==='daily')  renderDaily(wrap);
  else                          renderYearlyGrid(wrap);
}

function renderMonthly(wrap) {
  const year=calCurrent.getFullYear(),month=calCurrent.getMonth();
  document.getElementById('calMonthLabel').textContent=MONTHS[month]+' '+year;
  document.getElementById('calLegend').style.display='';
  const firstDow=new Date(year,month,1).getDay(),daysInM=new Date(year,month+1,0).getDate(),todayKey=dateKey(TODAY);
  let html='<div class="cal-grid">';
  DAYS.forEach(d=>{html+=`<div class="cal-dow">${d}</div>`;});
  for(let i=0;i<firstDow;i++) html+='<div class="cal-day empty-slot"></div>';
  for(let d=1;d<=daysInM;d++){
    const key=`${year}-${pad(month+1)}-${pad(d)}`,tickets=ticketsByDate[key]||[];
    const isToday=key===todayKey,isSel=calSelected&&dateKey(calSelected)===key;
    let cls='cal-day'+(tickets.length?' has-tickets':'')+(isToday?' today':'')+(isSel?' selected':'');
    html+=`<div class="${cls}" onclick="calSelectDate('${key}',${year},${month},${d})"><span>${d}</span>${tickets.length?'<span class="cal-day-dot"></span>':''}</div>`;
  }
  const rem=(firstDow+daysInM)%7;
  if(rem) for(let i=0;i<7-rem;i++) html+='<div class="cal-day empty-slot"></div>';
  wrap.innerHTML=html+'</div>';
}

function renderDaily(wrap) {
  const year=calCurrent.getFullYear(),month=calCurrent.getMonth();
  document.getElementById('calMonthLabel').textContent=MONTHS[month]+' '+year;
  document.getElementById('calLegend').style.display='none';
  const daysInM=new Date(year,month+1,0).getDate();
  let html='<div class="daily-list">';
  for(let d=1;d<=daysInM;d++){
    const key=`${year}-${pad(month+1)}-${pad(d)}`,date=new Date(year,month,d),tickets=ticketsByDate[key]||[];
    const isSel=calSelected&&dateKey(calSelected)===key;
    let cls='daily-item'+(isSel?' selected-day':'')+(tickets.length===0?' daily-no-tickets':'');
    html+=`<div class="${cls}" onclick="calSelectDate('${key}',${year},${month},${d})">
      <div><div class="daily-date-num">${d}</div><div class="daily-date-sub">${DAYS[date.getDay()]}</div></div>
      <div style="flex:1;font-size:11px;padding:0 8px;color:${tickets.length?'var(--text-secondary)':'var(--text-muted)'}">
        ${tickets.length?tickets.slice(0,2).map(t=>`<div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${t.title}</div>`).join('')+(tickets.length>2?`<div style="color:var(--text-muted)">+${tickets.length-2} more</div>`:''):'No tickets'}
      </div>
      <div class="daily-right">${tickets.length?`<span class="daily-badge">${tickets.length}</span>`:''}</div>
    </div>`;
  }
  wrap.innerHTML=html+'</div>';
}

function renderYearlyGrid(wrap) {
  const year=calCurrent.getFullYear();
  document.getElementById('calMonthLabel').textContent=year;
  document.getElementById('calLegend').style.display='none';
  let html='<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px;">';
  for(let m=0;m<12;m++){
    const count=getTicketsForPrefix(`${year}-${pad(m+1)}`).length;
    const isCurrent=(year===TODAY.getFullYear()&&m===TODAY.getMonth());
    html+=`<div onclick="yearlySelectMonth(${year},${m})"
      style="border-radius:8px;padding:10px 8px;text-align:center;cursor:pointer;
        border:1.5px solid ${isCurrent?'var(--indigo-300)':'var(--border)'};
        background:${count>0?'var(--surface-2)':'var(--surface)'};transition:all .15s;"
      onmouseover="this.style.background='var(--indigo-50)';this.style.borderColor='var(--indigo-300)';"
      onmouseout="this.style.background='${count>0?'var(--surface-2)':'var(--surface)'}';this.style.borderColor='${isCurrent?'var(--indigo-300)':'var(--border)'}';">
      <div style="font-size:11px;font-weight:700;color:${isCurrent?'var(--indigo-600)':'var(--text-secondary)'};">${MONTHS_SHORT[m]}</div>
      ${count>0
        ?`<div style="font-size:18px;font-weight:800;color:var(--indigo-600);margin-top:3px;">${count}</div><div style="font-size:9px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;">tickets</div>`
        :`<div style="font-size:11px;color:var(--text-muted);margin-top:4px;">—</div>`}
    </div>`;
  }
  wrap.innerHTML=html+'</div>';
}

function yearlySelectMonth(year, month) {
  const prefix=`${year}-${pad(month+1)}`, tickets=getTicketsForPrefix(prefix);
  const mLabel=MONTHS[month]+' '+year;
  renderDetailChart(tickets, mLabel, `${tickets.length} ticket${tickets.length!==1?'s':''} in ${mLabel}`);
}

function calSelectDate(key, year, month, d) {
  calSelected = new Date(year, month, d);
  calRender();
  const tickets = ticketsByDate[key]||[];
  const label = calSelected.toLocaleDateString('en-GB',{weekday:'long',day:'numeric',month:'long',year:'numeric'});
  renderDetailChart(tickets, label, `${tickets.length} ticket${tickets.length!==1?'s':''} on this date`);
}

calRender();
showMonthChart();
</script>
<?php include '_foot_scripts.php'; ?>
</body>
</html>