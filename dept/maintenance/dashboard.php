<?php
// dept/maintenance/dashboard.php
require_once __DIR__ . '/../auth_guard.php';

if (isset($_GET['logout'])) { staffLogout(); }

require_once __DIR__ . '/../../db_connect.php';
require_once __DIR__ . '/../../sla_helper.php';

date_default_timezone_set('Asia/Kuala_Lumpur');

// ── Status Counts (Open / In Progress / Closed) ───────────────────────────────
$stmt = $conn->prepare(
    "SELECT
        SUM(status='open')        AS oc,
        SUM(status='in_progress') AS ic,
        SUM(status='closed')      AS cc
     FROM complaints WHERE dept_id = ?"
);
$stmt->bind_param("i", $deptId);
$stmt->execute();
$counts = $stmt->get_result()->fetch_assoc();
$stmt->close();
$openCount       = (int)($counts['oc'] ?? 0);
$inProgressCount = (int)($counts['ic'] ?? 0);
$closedCount     = (int)($counts['cc'] ?? 0);

// ── Priority breakdown ────────────────────────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT
        SUM(priority='low')    AS pl,
        SUM(priority='medium') AS pm,
        SUM(priority='high')   AS ph
     FROM complaints WHERE dept_id = ?"
);
$stmt->bind_param("i", $deptId);
$stmt->execute();
$priCounts = $stmt->get_result()->fetch_assoc();
$stmt->close();
$priLow    = (int)($priCounts['pl'] ?? 0);
$priMedium = (int)($priCounts['pm'] ?? 0);
$priHigh   = (int)($priCounts['ph'] ?? 0);
$priTotal  = $priLow + $priMedium + $priHigh;

// ── Top Departments Filing Complaints ─────────────────────────────────────────
$topDepts = [];
$stmt = $conn->prepare(
    "SELECT my_department, COUNT(*) AS total
     FROM complaints
     WHERE dept_id = ?
     GROUP BY my_department
     ORDER BY total DESC
     LIMIT 5"
);
$stmt->bind_param("i", $deptId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $topDepts[] = $row;
$stmt->close();
$topDeptsMax = !empty($topDepts) ? (int)$topDepts[0]['total'] : 1;

// ── Today's stats ─────────────────────────────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT
        COUNT(*) AS today_received,
        SUM(status = 'closed') AS today_resolved
     FROM complaints
     WHERE dept_id = ? AND DATE(created_at) = CURDATE()"
);
$stmt->bind_param("i", $deptId);
$stmt->execute();
$todayData     = $stmt->get_result()->fetch_assoc();
$stmt->close();
$todayReceived = (int)($todayData['today_received'] ?? 0);
$todayResolved = (int)($todayData['today_resolved'] ?? 0);

$dashNow = new DateTime();

// ── SLA Breach Count ──────────────────────────────────────────────────────────
$slaAllRaw = [];
$slaStmt = $conn->prepare(
    "SELECT
        c.ticket_id,
        c.status,
        c.created_at,
        c.first_response_at,
        (SELECT MIN(l.changed_at)
         FROM ticket_logs l
         WHERE l.ticket_id = c.ticket_id
           AND l.new_status IN ('in_progress','closed')
           AND l.old_status = 'open'
        ) AS first_log_response_at
     FROM complaints c
     WHERE c.dept_id = ?"
);
$slaStmt->bind_param("i", $deptId);
$slaStmt->execute();
$slaAllRaw = $slaStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$slaStmt->close();

$slaBreachedCount = 0;
foreach ($slaAllRaw as $sRow) {
    $respondTs = null;
    if (!empty($sRow['first_response_at'])) {
        $respondTs = $sRow['first_response_at'];
    } elseif (!empty($sRow['first_log_response_at'])) {
        $respondTs = $sRow['first_log_response_at'];
    }
    $from = new DateTime($sRow['created_at'], new DateTimeZone(SLA_TZ));
    if (!empty($respondTs)) {
        $to   = new DateTime($respondTs, new DateTimeZone(SLA_TZ));
        $mins = workingMinutesBetween($from, $to);
        if ($mins > SLA_WORK_HOURS * 60) $slaBreachedCount++;
    } else {
        $now  = new DateTime('now', new DateTimeZone(SLA_TZ));
        $mins = workingMinutesBetween($from, $now);
        if ($mins > SLA_WORK_HOURS * 60) $slaBreachedCount++;
    }
}

// ── My Tasks (tickets assigned to the logged-in staff, not closed) ────────────
$myTasks = [];
$stmt = $conn->prepare(
    "SELECT c.ticket_id, c.title, c.my_department, c.status, c.priority,
            c.created_at, c.first_response_at,
            (SELECT MIN(l.changed_at)
             FROM ticket_logs l
             WHERE l.ticket_id = c.ticket_id
               AND l.new_status IN ('in_progress','closed')
               AND l.old_status = 'open'
            ) AS first_log_response_at
     FROM complaints c
     WHERE c.dept_id = ? AND c.assigned_to = ? AND c.status != 'closed'
     ORDER BY
       FIELD(priority, 'high', 'medium', 'low'),
       created_at ASC
     LIMIT 5"
);
$stmt->bind_param("ii", $deptId, $staffId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $respondTs = null;
    if (!empty($row['first_response_at'])) {
        $respondTs = $row['first_response_at'];
    } elseif (!empty($row['first_log_response_at'])) {
        $respondTs = $row['first_log_response_at'];
    }
    $from = new DateTime($row['created_at'], new DateTimeZone(SLA_TZ));
    $ticketStatus = strtolower($row['status'] ?? 'open');
    if (!empty($respondTs)) {
        $to      = new DateTime($respondTs, new DateTimeZone(SLA_TZ));
        $elapsed = workingMinutesBetween($from, $to);
        $slaBreached = ($ticketStatus === 'open' && $elapsed > SLA_WORK_HOURS * 60);
    } else {
        $elapsed     = workingMinutesBetween($from, $dashNow);
        $slaBreached = ($ticketStatus === 'open' && $elapsed > SLA_WORK_HOURS * 60);
    }
    $row['sla_elapsed']  = $elapsed;
    $row['sla_breached'] = $slaBreached;
    $row['sla_due_soon'] = (!$slaBreached && $ticketStatus === 'open' && empty($respondTs) && $elapsed >= (SLA_WORK_HOURS * 60 - 60));
    $myTasks[] = $row;
}
$stmt->close();


// ── High Priority Tickets (open/in_progress) ─────────────────────────────────
$highPriTickets = [];
$stmt = $conn->prepare(
    "SELECT c.ticket_id, c.title, c.my_department, c.status, c.assigned_to,
            s.full_name AS handled_by
     FROM complaints c
     LEFT JOIN staff s ON s.staff_id = c.assigned_to
     WHERE c.dept_id = ? AND c.priority = 'high' AND c.status != 'closed'
     ORDER BY c.created_at ASC"
);
$stmt->bind_param("i", $deptId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $highPriTickets[] = $row;
$stmt->close();

// ── SLA Breached Tickets detail list ─────────────────────────────────────────
$slaBreachedTickets = [];
foreach ($slaAllRaw as $sRow) {
    $respondTs = null;
    if (!empty($sRow['first_response_at'])) $respondTs = $sRow['first_response_at'];
    elseif (!empty($sRow['first_log_response_at'])) $respondTs = $sRow['first_log_response_at'];
    $from = new DateTime($sRow['created_at'], new DateTimeZone(SLA_TZ));
    if (!empty($respondTs)) {
        $to   = new DateTime($respondTs, new DateTimeZone(SLA_TZ));
        $mins = workingMinutesBetween($from, $to);
    } else {
        $now  = new DateTime('now', new DateTimeZone(SLA_TZ));
        $mins = workingMinutesBetween($from, $now);
    }
    if ($mins > SLA_WORK_HOURS * 60) $slaBreachedTickets[] = $sRow['ticket_id'];
}

$breachedDetails = [];
if (!empty($slaBreachedTickets)) {
    $placeholders = implode(',', array_fill(0, count($slaBreachedTickets), '?'));
    $types = str_repeat('s', count($slaBreachedTickets));
    $stmt = $conn->prepare(
        "SELECT c.ticket_id, c.title, c.my_department, c.status, c.created_at,
                s.full_name AS handled_by
         FROM complaints c
         LEFT JOIN staff s ON s.staff_id = c.assigned_to
         WHERE c.ticket_id IN ($placeholders)
         ORDER BY c.created_at ASC"
    );
    $stmt->bind_param($types, ...$slaBreachedTickets);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $breachedDetails[] = $row;
    $stmt->close();
}

// ── All open tickets with assigned staff name ─────────────────────────────────
$recentTickets = [];
$stmt = $conn->prepare(
    "SELECT c.ticket_id, c.title, c.status, c.priority, c.my_department,
            c.created_at, c.assigned_to,
            s.full_name AS handled_by,
            s.staff_code AS handled_by_code,
            cat.category_name
     FROM complaints c
     LEFT JOIN staff s ON s.staff_id = c.assigned_to
     LEFT JOIN categories cat ON cat.category_id = c.category_id
     WHERE c.dept_id = ? AND c.status != 'closed'
     ORDER BY c.created_at DESC"
);
$stmt->bind_param("i", $deptId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $recentTickets[] = $row;
$stmt->close();

// ── Helper: staff initials ────────────────────────────────────────────────────
function staffInitials(string $name): string {
    $parts = explode(' ', trim($name));
    $ini   = strtoupper(substr($parts[0], 0, 1));
    if (count($parts) > 1) $ini .= strtoupper(substr($parts[count($parts) - 1], 0, 1));
    return $ini;
}

// ── Layout variables ──────────────────────────────────────────────────────────
$activeNav    = 'dashboard';
$pageTitle    = 'Maintenance Department';
$pageSubtitle = 'Welcome back, ' . $staffName;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Dashboard | UniKL Help Desk – Maintenance</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="css/dashboards.css"/>
</head>
<body>

<?php require_once __DIR__ . '/_layout.php'; ?>

    <!-- ══ TOP: Open / In Progress / Closed / SLA Breached ══ -->
<div class="stats" style="grid-template-columns: repeat(4, 1fr);">

  <a class="stat" href="tickets.php?status=open">
    <div class="stat-icon si-open">
      <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    </div>
    <div class="stat-body">
      <div class="num"><?php echo $openCount; ?></div>
      <div class="lbl">Open Tickets</div>
    </div>
    <span class="stat-arrow">›</span>
  </a>

  <a class="stat" href="tickets.php?status=in_progress">
    <div class="stat-icon si-progress">
      <svg viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
    </div>
    <div class="stat-body">
      <div class="num"><?php echo $inProgressCount; ?></div>
      <div class="lbl">In Progress</div>
    </div>
    <span class="stat-arrow">›</span>
  </a>

  <a class="stat" href="tickets.php?status=closed">
    <div class="stat-icon si-closed">
      <svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22,4 12,14.01 9,11.01"/></svg>
    </div>
    <div class="stat-body">
      <div class="num"><?php echo $closedCount; ?></div>
      <div class="lbl">Closed Tickets</div>
    </div>
    <span class="stat-arrow">›</span>
  </a>

  <a class="stat" href="tickets.php" style="border-color:<?php echo $slaBreachedCount > 0 ? '#FECACA' : 'var(--g200)'; ?>;background:<?php echo $slaBreachedCount > 0 ? '#FFF1F1' : 'white'; ?>">
    <div class="stat-icon" style="background:#FEE2E2;color:#DC2626;border:1px solid #FECACA;">
      <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    </div>
    <div class="stat-body">
      <div class="num" style="color:<?php echo $slaBreachedCount > 0 ? '#DC2626' : 'var(--g900)'; ?>"><?php echo $slaBreachedCount; ?></div>
      <div class="lbl">SLA Breached</div>
    </div>
    <span class="stat-arrow">›</span>
  </a>

</div>

     <!-- ══ MIDDLE ROW: Tabbed Card + Top Departments ══ -->
<div style="display:flex; gap:16px; margin-bottom:20px; align-items:stretch;">

<!-- LEFT: Tabbed Card -->
<div class="card-box tab-card" style="flex:1; min-width:0;">

  <div class="tab-nav">
    <button class="tab-btn active" onclick="switchTab('tab-tasks', this)">
      My Tasks
      <span class="tab-count"><?php echo count($myTasks); ?></span>
    </button>
    <button class="tab-btn" onclick="switchTab('tab-priority', this)">
      High Priority
      <span class="tab-count tab-count-high"><?php echo count($highPriTickets); ?></span>
    </button>
    <button class="tab-btn" onclick="switchTab('tab-breach', this)">
      SLA Breached
      <span class="tab-count tab-count-breach"><?php echo count($breachedDetails); ?></span>
    </button>
  </div>

  <div class="tab-divider"></div>

  <!-- TAB 1: My Tasks -->
  <div id="tab-tasks" class="tab-pane active">
    <?php if (empty($myTasks)): ?>
      <div class="mytasks-empty">No tasks assigned to you. ✅</div>
    <?php else: ?>
    <div class="mytasks-list">
      <?php foreach ($myTasks as $t):
        $pri = strtolower($t['priority'] ?? 'medium');
        $s   = strtolower($t['status']);
        $statusLabel = $s === 'in_progress' ? 'In Progress' : ucfirst($s);
      ?>
      <div class="mytask-item pri-<?php echo $pri; ?>">
        <div class="mt-info">
          <div class="mt-tid"><?php echo htmlspecialchars($t['ticket_id']); ?></div>
          <div class="mt-title" title="<?php echo htmlspecialchars($t['title']); ?>">
            <?php echo htmlspecialchars($t['title']); ?>
          </div>
          <div class="mt-meta">
            <span class="mt-dept"><?php echo htmlspecialchars($t['my_department'] ?? '—'); ?></span>
            <span class="mt-status-bdg mt-status-<?php echo $s; ?>"><?php echo $statusLabel; ?></span>
            <?php if ($t['sla_breached']): ?>
              <span class="mt-sla-badge mt-sla-overdue">⚠ SLA Breached</span>
            <?php elseif ($t['sla_due_soon']): ?>
              <span class="mt-sla-badge mt-sla-soon">⏱ Due Soon</span>
            <?php endif; ?>
          </div>
        </div>
        <a class="mt-link" href="ticket_detail.php?id=<?php echo urlencode($t['ticket_id']); ?>">View →</a>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- TAB 2: High Priority -->
  <div id="tab-priority" class="tab-pane">
    <?php if (empty($highPriTickets)): ?>
      <div class="mytasks-empty">No high priority tickets. 🎉</div>
    <?php else: ?>
    <div class="mytasks-list">
      <?php foreach ($highPriTickets as $t):
        $s = strtolower($t['status']);
        $statusLabel = $s === 'in_progress' ? 'In Progress' : ucfirst($s);
      ?>
      <div class="mytask-item pri-high">
        <div class="mt-info">
          <div class="mt-tid"><?php echo htmlspecialchars($t['ticket_id']); ?></div>
          <div class="mt-title" title="<?php echo htmlspecialchars($t['title']); ?>">
            <?php echo htmlspecialchars($t['title']); ?>
          </div>
          <div class="mt-meta">
            <span class="mt-dept"><?php echo htmlspecialchars($t['my_department'] ?? '—'); ?></span>
            <span class="mt-status-bdg mt-status-<?php echo $s; ?>"><?php echo $statusLabel; ?></span>
            <?php if ($t['handled_by']): ?>
              <span class="mt-dept">· <?php echo htmlspecialchars($t['handled_by']); ?></span>
            <?php else: ?>
              <span class="mt-dept" style="color:#F87171;">· Unassigned</span>
            <?php endif; ?>
          </div>
        </div>
        <a class="mt-link" href="ticket_detail.php?id=<?php echo urlencode($t['ticket_id']); ?>">View →</a>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- TAB 3: SLA Breached -->
  <div id="tab-breach" class="tab-pane">
    <?php if (empty($breachedDetails)): ?>
      <div class="mytasks-empty">No SLA breaches. ✅</div>
    <?php else: ?>
    <div class="mytasks-list">
      <?php foreach ($breachedDetails as $t):
        $s = strtolower($t['status']);
        $statusLabel = $s === 'in_progress' ? 'In Progress' : ucfirst($s);
      ?>
      <div class="mytask-item" style="border-left:3px solid #E02424;background:#FFF5F5;border-color:#FECACA;">
        <div class="mt-info">
          <div class="mt-tid"><?php echo htmlspecialchars($t['ticket_id']); ?></div>
          <div class="mt-title" title="<?php echo htmlspecialchars($t['title']); ?>">
            <?php echo htmlspecialchars($t['title']); ?>
          </div>
          <div class="mt-meta">
            <span class="mt-dept"><?php echo htmlspecialchars($t['my_department'] ?? '—'); ?></span>
            <span class="mt-status-bdg mt-status-<?php echo $s; ?>"><?php echo $statusLabel; ?></span>
            <span class="mt-sla-badge mt-sla-overdue">⚠ SLA Breached</span>
            <?php if ($t['handled_by']): ?>
              <span class="mt-dept">· <?php echo htmlspecialchars($t['handled_by']); ?></span>
            <?php endif; ?>
          </div>
        </div>
        <a class="mt-link" href="ticket_detail.php?id=<?php echo urlencode($t['ticket_id']); ?>">View →</a>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

</div><!-- /.tab-card -->

<!-- RIGHT: Top Departments Pie Chart -->
<div class="card-box" style="flex:1; min-width:0; display:flex; flex-direction:column;">
  <div class="card-box-title">
    <svg viewBox="0 0 24 24"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg>
    Top Departments Filing Complaints
  </div>
  <?php if (empty($topDepts)): ?>
    <div class="topdept-empty">No complaints data yet.</div>
  <?php else: ?>
  <div style="flex:1; display:flex; flex-direction:row; align-items:center; justify-content:center; gap:20px;">
    <canvas id="deptPieChart" width="160" height="160" style="flex-shrink:0; width:160px; height:160px;"></canvas>
    <div style="display:flex; flex-direction:column; gap:8px; flex:1; min-width:0; max-width:280px;">
      <?php
        $pieColors = ['#2563EB','#7C3AED','#DB2777','#D97706','#059669'];
        foreach ($topDepts as $i => $dept):
          $color = $pieColors[$i % count($pieColors)];
      ?>
      <div style="display:flex; align-items:center; gap:6px;">
        <span style="width:10px;height:10px;border-radius:50%;background:<?php echo $color; ?>;flex-shrink:0;"></span>
        <span style="font-size:12px;color:var(--g700);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1;"
              title="<?php echo htmlspecialchars($dept['my_department'] ?? '—'); ?>">
          <?php echo htmlspecialchars($dept['my_department'] ?? '—'); ?>
        </span>
        <span style="font-size:12px;font-weight:700;color:var(--g500);margin-left:6px;flex-shrink:0;min-width:16px;text-align:right;"><?php echo (int)$dept['total']; ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <script>
  (function(){
    const data   = [<?php echo implode(',', array_column($topDepts, 'total')); ?>];
    const labels = [<?php echo implode(',', array_map(fn($d) => json_encode($d['my_department'] ?? '—'), $topDepts)); ?>];
    const colors = ['#2563EB','#7C3AED','#DB2777','#D97706','#059669'];
const canvas = document.getElementById('deptPieChart');
const ctx    = canvas.getContext('2d');
const total  = data.reduce((a,b)=>a+b,0);
const cx=80, cy=80, r=68;

    // Store slices for tooltip hit-testing
    const slices = [];
    let start = -Math.PI/2;

    const innerR = 40; // donut hole radius

    function drawChart(highlightIndex = -1) {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      start = -Math.PI/2;

      data.forEach((val, i) => {
        const slice = (val / total) * (2 * Math.PI);
        const isHovered = i === highlightIndex;
        const offset = isHovered ? 8 : 0;
        const midAngle = start + slice / 2;
        const ox = Math.cos(midAngle) * offset;
        const oy = Math.sin(midAngle) * offset;

        // Draw donut slice
        ctx.beginPath();
        ctx.moveTo(cx + ox + Math.cos(start) * innerR, cy + oy + Math.sin(start) * innerR);
        ctx.arc(cx + ox, cy + oy, r, start, start + slice);
        ctx.arc(cx + ox, cy + oy, innerR, start + slice, start, true);
        ctx.closePath();
        ctx.fillStyle = colors[i % colors.length];
        ctx.fill();
        ctx.strokeStyle = '#fff';
        ctx.lineWidth = 2;
        ctx.stroke();

        slices[i] = { start, end: start + slice, ox, oy };
        start += slice;
      });

      // Draw center white circle
      ctx.beginPath();
      ctx.arc(cx, cy, innerR - 2, 0, 2 * Math.PI);
      ctx.fillStyle = '#fff';
      ctx.fill();

      // Draw total number in center
      ctx.fillStyle = '#111827';
      ctx.font = 'bold 22px DM Serif Display, serif';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillText(total, cx, cy - 8);

      // Draw "total" label below number
      ctx.fillStyle = '#9CA3AF';
      ctx.font = '11px DM Sans, sans-serif';
      ctx.fillText('total', cx, cy + 10);
    }

    drawChart();

    // ── Tooltip setup ──
    const tooltip = document.createElement('div');
    tooltip.style.cssText = `
      position:fixed; pointer-events:none; display:none;
      background:#1e293b; color:#fff; border-radius:8px;
      padding:8px 12px; font-size:12px; font-family:DM Sans,sans-serif;
      box-shadow:0 4px 16px rgba(0,0,0,.2); line-height:1.5; z-index:9999;
      white-space:nowrap;
    `;
    document.body.appendChild(tooltip);

    function getHoveredSlice(mx, my) {
      const rect = canvas.getBoundingClientRect();
      const x = mx - rect.left - cx;
      const y = my - rect.top  - cy;
      const dist = Math.sqrt(x*x + y*y);
      if (dist > r + 10 || dist < innerR) return -1;
      let angle = Math.atan2(y, x);
      if (angle < -Math.PI/2) angle += 2*Math.PI;
      for (let i = 0; i < slices.length; i++) {
        let s = slices[i].start, e = slices[i].end;
        if (s > e) e += 2*Math.PI;
        let a = angle < s ? angle + 2*Math.PI : angle;
        if (a >= s && a <= e) return i;
      }
      return -1;
    }

    let lastHover = -1;
    canvas.addEventListener('mousemove', e => {
      const idx = getHoveredSlice(e.clientX, e.clientY);
      if (idx !== lastHover) { lastHover = idx; drawChart(idx); }
      if (idx >= 0) {
        tooltip.innerHTML =
          `<span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:${colors[idx]};margin-right:6px;vertical-align:middle;"></span>` +
          `<strong>${labels[idx]}</strong><br>` +
          `<span style="color:#94a3b8;font-size:11px;">🎫 ${data[idx]} ticket${data[idx]>1?'s':''}</span>`;
        tooltip.style.display = 'block';
        tooltip.style.left = (e.clientX + 14) + 'px';
        tooltip.style.top  = (e.clientY - 10) + 'px';
      } else {
        tooltip.style.display = 'none';
      }
    });
    canvas.addEventListener('mouseleave', () => {
      lastHover = -1; drawChart(); tooltip.style.display = 'none';
    });
  })();
  </script>
  <?php endif; ?>
</div><!-- /.top-departments -->

</div><!-- /.middle-row flex -->


    <!-- ══ BOTTOM: All Open Tickets ══ -->
    <div class="sec-hdr">
      <span class="sec-title">All Open Tickets</span>
      <a href="tickets.php" class="view-all">View all &rarr;</a>
    </div>

    <div class="tbl-card">
      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th>Ticket ID</th>
              <th>Title</th>
              <th>From Department</th>
              <th>Status</th>
              <th>Priority</th>
              <th>Category</th>
              <th>Assign To</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($recentTickets)): ?>
            <tr><td colspan="7">
              <div class="empty">
                <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14,2 14,8 20,8"/></svg>
                No active tickets at the moment.
              </div>
            </td></tr>
            <?php else: ?>
            <?php foreach ($recentTickets as $t):
              $s             = strtolower($t['status']);
              $pri           = strtolower($t['priority'] ?? 'medium');
              $tid           = htmlspecialchars($t['ticket_id']);
              $statusLabel   = $s === 'in_progress' ? 'In Progress' : ucfirst($s);
              $handledBy     = $t['handled_by'] ?? null;
              $handledByCode = $t['handled_by_code'] ?? null;
              $flagFill      = match($pri) {
                'high'   => '#DC2626',
                'medium' => '#EAB308',
                'low'    => '#3B82F6',
                default  => '#64748b',
              };
            ?>
            <tr>
              <td>
                <a class="tid-link" href="ticket_detail.php?id=<?php echo urlencode($t['ticket_id']); ?>">
                  <?php echo $tid; ?>
                </a>
              </td>
              <td><?php echo htmlspecialchars($t['title']); ?></td>
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
              <td>
                <span class="bdg bdg-<?php echo $s; ?>"><?php echo $statusLabel; ?></span>
              </td>
              <td>
                <span class="priority-pill pp-<?php echo $pri; ?>">
                  <svg class="priority-flag-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"
                       fill="<?php echo $flagFill; ?>" stroke="<?php echo $flagFill; ?>">
                    <path d="M4 3h13l-3 5 3 5H4V3z" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <line x1="4" y1="3" x2="4" y2="21" stroke-width="2" stroke-linecap="round"/>
                  </svg>
                  <?php echo ucfirst($pri); ?>
                </span>
              </td>
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
                <?php else: ?>
                  <span style="font-size:12px;color:#9CA3AF;font-style:italic;">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($handledBy): ?>
                  <div class="assigned-cell">
                    <div class="staff-avatar-sm">
                      <?php echo staffInitials($handledBy); ?>
                    </div>
                    <div class="assigned-info">
                      <span class="assigned-name"><?php echo htmlspecialchars($handledBy); ?></span>
                      <?php if ($handledByCode): ?>
                        <span class="assigned-code"><?php echo htmlspecialchars($handledByCode); ?></span>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php else: ?>
                  <span class="unassigned-tag">Unassigned</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div><!-- /.content -->
</main>
<script>
function switchTab(tabId, btn) {
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById(tabId).classList.add('active');
  btn.classList.add('active');
}
</script>
</body>
</html>