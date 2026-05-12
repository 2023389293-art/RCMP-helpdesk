<?php
// ../dept/it/dashboard.php
require_once __DIR__ . '/../auth_guard.php';

if (isset($_GET['logout'])) { staffLogout(); }

require_once __DIR__ . '/../../db_connect.php';

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

// ── Top Departments Filing Complaints ────────────────────────────────────────
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

// ── SLA Helpers (business hours Mon–Fri 08:00–17:00) ─────────────────────────
function dashAdvanceToBusinessTime(DateTime $dt): DateTime {
    $d = clone $dt;
    while (true) {
        $dow  = (int)$d->format('N');
        $hour = (int)$d->format('G');
        if ($dow >= 6) {
            $d->modify('+' . (8 - $dow) . ' days');
            $d->setTime(8, 0, 0);
            continue;
        }
        if ($hour < 8)  { $d->setTime(8, 0, 0); break; }
        if ($hour >= 17) { $d->modify('+1 day'); $d->setTime(8, 0, 0); continue; }
        break;
    }
    return $d;
}
function dashBusinessMinutesElapsed(DateTime $start, DateTime $now): int {
    $cur = dashAdvanceToBusinessTime(clone $start);
    $total = 0;
    if ($cur >= $now) return 0;
    while ($cur < $now) {
        $dow = (int)$cur->format('N');
        if ($dow >= 6) { $cur->modify('+1 day'); $cur->setTime(8, 0, 0); continue; }
        $dayEnd = clone $cur; $dayEnd->setTime(17, 0, 0);
        $segEnd = ($now < $dayEnd) ? $now : $dayEnd;
        if ($cur < $segEnd)
            $total += (int)(($segEnd->getTimestamp() - $cur->getTimestamp()) / 60);
        if ($segEnd >= $dayEnd) {
            $cur->modify('+1 day'); $cur->setTime(8, 0, 0);
            while ((int)$cur->format('N') >= 6) $cur->modify('+1 day');
        } else break;
    }
    return $total;
}
$dashNow = new DateTime();

// ── My Tasks (tickets assigned to the logged-in staff, not closed) ────────────
$myTasks = [];
$stmt = $conn->prepare(
    "SELECT ticket_id, title, my_department, status, priority, created_at, sla_start_at
FROM complaints
     WHERE dept_id = ? AND assigned_to = ? AND status != 'closed'
     ORDER BY
       FIELD(priority, 'high', 'medium', 'low'),
       created_at ASC
     LIMIT 5"
);
$stmt->bind_param("ii", $deptId, $staffId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $slaStartStr = !empty($row['sla_start_at']) ? $row['sla_start_at'] : $row['created_at'];
    $slaStart = new DateTime($slaStartStr);
    $elapsed         = dashBusinessMinutesElapsed($slaStart, $dashNow);
    $row['sla_elapsed']   = $elapsed;
    $row['sla_breached']  = ($elapsed >= 480);   // 8 hrs = 480 min
    $row['sla_due_soon']  = (!$row['sla_breached'] && $elapsed >= 420); // 7 hrs
    $myTasks[] = $row;
}
$stmt->close();

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

// ── Helper: staff initials (same logic as tickets.php) ───────────────────────
function staffInitials(string $name): string {
    $parts = explode(' ', trim($name));
    $ini   = strtoupper(substr($parts[0], 0, 1));
    if (count($parts) > 1) $ini .= strtoupper(substr($parts[count($parts) - 1], 0, 1));
    return $ini;
}

// ── Layout variables ──────────────────────────────────────────────────────────
$activeNav    = 'dashboard';
$pageTitle = 'Corporate Communication Unit';
$pageSubtitle = 'Welcome back, ' . $staffName;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Dashboard | UniKL Help Desk – CCU</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet"/>
  <style>

    /* ══ TOP: 3 Status Cards ══ */
    .stats {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 14px;
      margin-bottom: 20px;
    }
    .stat {
      background: white;
      border-radius: 12px;
      padding: 18px 20px;
      border: 1px solid var(--g200);
      display: flex;
      align-items: flex-start;
      gap: 14px;
    }
    .stat-icon {
      width: 44px; height: 44px;
      border-radius: 10px;
      flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
    }
    .stat-icon svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 2; }
    .si-open     { background: #FEF3C7; color: #D97706; }
    .si-progress { background: #DBEAFE; color: #2563EB; }
    .si-closed   { background: #D1FAE5; color: #059669; }
    .stat .num   { font-family: 'DM Serif Display', serif; font-size: 34px; color: var(--g900); line-height: 1; }
    .stat .lbl   { font-size: 14px; color: var(--g500); margin-top: 4px; }

    /* ══ Middle Row 2-col ══ */
    .mid-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
      margin-bottom: 20px;
    }

    /* ══ Middle Row 2: Priority Breakdown + Top Departments ══ */
    .mid-row-2 {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
      margin-bottom: 20px;
    }

    /* ══ Shared card box ══ */
    .card-box {
      background: white;
      border-radius: 12px;
      border: 1px solid var(--g200);
      padding: 18px 20px;
    }
    .card-box-title {
      font-size: 11px; font-weight: 700;
      color: var(--g500);
      text-transform: uppercase; letter-spacing: .08em;
      margin-bottom: 16px;
      display: flex; align-items: center; gap: 7px;
    }
    .card-box-title svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2; }

    /* ── Donut chart (priority) ── */
    .donut-layout { display: flex; align-items: center; gap: 16px; }
    .donut-wrap   { position: relative; width: 108px; height: 108px; flex-shrink: 0; }
    .donut-wrap svg { width: 108px; height: 108px; display: block; }
    .donut-center {
      position: absolute; inset: 0;
      display: flex; flex-direction: column; align-items: center; justify-content: center;
      pointer-events: none;
    }
    .donut-total { font-family: 'DM Serif Display', serif; font-size: 24px; color: var(--g900); line-height: 1; }
    .donut-sub   { font-size: 11px; color: var(--g500); margin-top: 3px; letter-spacing: .04em; }

    .pill-list { display: flex; flex-direction: column; gap: 8px; flex: 1; justify-content: center; }
    .pill-row  { display: flex; align-items: center; justify-content: space-between; gap: 6px; }
    .pill-name { font-size: 12px; color: var(--g700); display: flex; align-items: center; gap: 6px; font-weight: 500; }
    .pill-dot  { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
    .dot-low    { background: #639922; }
    .dot-medium { background: #BA7517; }
    .dot-high   { background: #E24B4A; }
    .pill-num { font-size: 12px; font-weight: 600; padding: 2px 9px; border-radius: 99px; flex-shrink: 0; }
    .num-low    { background: #EAF3DE; color: #3B6D11; }
    .num-medium { background: #FAEEDA; color: #854F0B; }
    .num-high   { background: #FCEBEB; color: #A32D2D; }

    /* ── Top Departments Card ── */
    .topdept-empty {
      font-size: 13px; color: var(--g400); text-align: center; padding: 20px 0;
    }
    .topdept-list { display: flex; flex-direction: column; gap: 10px; }
    .topdept-row  { display: flex; align-items: center; gap: 10px; }
    .topdept-rank {
      font-size: 12px; font-weight: 600; color: var(--g400);
      width: 16px; text-align: right; flex-shrink: 0;
    }
    .topdept-name {
      font-size: 13px; color: var(--g700);
      flex: 1; min-width: 0;
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .topdept-bar-wrap {
      width: 90px; flex-shrink: 0; height: 6px;
      background: #EEF2FF; border-radius: 99px; overflow: hidden;
    }
    .topdept-bar {
      height: 100%; background: #2563EB;
      border-radius: 99px; transition: width 0.5s ease;
    }
    .topdept-count {
      font-size: 12px; font-weight: 700; color: var(--g700);
      width: 18px; text-align: right; flex-shrink: 0;
    }

    /* ══ My Tasks Card ══ */
    .mytasks-empty { font-size: 13px; color: var(--g400); text-align: center; padding: 20px 0; }
    .mytasks-list  { display: flex; flex-direction: column; gap: 8px; }
    .mytask-item {
      display: flex; align-items: flex-start; gap: 10px;
      padding: 9px 11px; border-radius: 8px;
      background: #F0F9FF; border: 1px solid #BAE6FD;
      transition: background .12s;
    }
    .mytask-item:hover { background: #E0F2FE; }
    .mytask-item.pri-high   { border-left: 3px solid #E24B4A; background: #FFF1F2; border-color: #FECACA; }
    .mytask-item.pri-medium { border-left: 3px solid #EF9F27; background: #FFFBEB; border-color: #FDE68A; }
    .mytask-item.pri-low    { border-left: 3px solid #639922; background: #F0FDF4; border-color: #BBF7D0; }

    .mt-info  { flex: 1; min-width: 0; }
    .mt-tid   { font-size: 11px; font-weight: 600; color: #1D4ED8; font-family: monospace; }
    .mt-title { font-size: 12px; color: var(--g700); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 1px; }
    .mt-meta  { display: flex; align-items: center; gap: 6px; margin-top: 4px; }
    .mt-dept  { font-size: 11px; color: var(--g400); }
    .mt-status-bdg { font-size: 10px; font-weight: 600; padding: 1px 7px; border-radius: 99px; text-transform: capitalize; }
    .mt-status-open        { background: #FEF3C7; color: #D97706; }
    .mt-status-in_progress { background: #DBEAFE; color: #2563EB; }
    /* ── SLA badges in My Tasks ── */
.mt-sla-badge {
  font-size: 10px; font-weight: 700; padding: 1px 7px;
  border-radius: 99px; letter-spacing: .03em; white-space: nowrap;
}
.mt-sla-overdue { background: #FEE2E2; color: #E02424; }
.mt-sla-soon    { background: #FEF3C7; color: #D97706; }
    .mt-link {
      font-size: 11px; font-weight: 600; color: #2563EB;
      text-decoration: none; flex-shrink: 0; align-self: center;
    }
    .mt-link:hover { text-decoration: underline; }

    /* ── Today's Stats ── */
    .today-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
    .today-cell {
      background: #F9FAFB; border-radius: 8px; border: 1px solid var(--g200);
      padding: 10px 12px; text-align: center;
    }
    .today-num { font-family: 'DM Serif Display', serif; font-size: 26px; color: var(--g900); line-height: 1; }
    .today-lbl { font-size: 11px; color: var(--g500); margin-top: 3px; }

    /* ══ Section header ══ */
    .sec-hdr    { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
    .sec-title  { font-size: 16px; font-weight: 600; color: var(--g900); }
    .view-all   { font-size: 13px; color: var(--accent); text-decoration: none; font-weight: 500; }
    .view-all:hover { text-decoration: underline; }

    /* ══ Table card ══ */
    .tbl-card { background: white; border-radius: 12px; border: 1px solid var(--g200); overflow: hidden; }
    .tbl-wrap { overflow-x: auto; }
    table     { width: 100%; border-collapse: collapse; font-size: 14px; }
    thead th  {
      background: var(--g100); padding: 10px 16px;
      text-align: left; font-size: 12px; font-weight: 600;
      color: var(--g500); text-transform: uppercase; letter-spacing: .05em;
      border-bottom: 1px solid var(--g200);
    }
    tbody tr { border-bottom: 1px solid var(--g200); transition: background .12s; }
    tbody tr:last-child { border-bottom: none; }
    tbody tr:hover { background: var(--off); }
    tbody td { padding: 12px 16px; color: var(--g700); vertical-align: middle; }

    /* Ticket ID link */
    .tid-link {
      font-weight: 600; color: var(--accent); font-size: 13px;
      text-decoration: none; font-family: monospace; letter-spacing: .03em; transition: color .15s;
    }
    .tid-link:hover { color: #1240b0; text-decoration: underline; }

    /* ── From Department: name + datetime stacked ── */
    .dept-cell { display: flex; flex-direction: column; gap: 2px; }
    .dept-cell .dept-name {
      font-size: 13px; color: var(--g700); font-weight: 500;
      max-width: 180px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .dept-cell .dept-datetime { font-size: 11px; color: var(--g400); white-space: nowrap; }

    /* Status badges */
    .bdg { display: inline-block; font-size: 12px; font-weight: 600; padding: 3px 10px; border-radius: 20px; text-transform: capitalize; }
    .bdg-open        { background: #FEF3C7; color: #D97706; }
    .bdg-in_progress { background: #DBEAFE; color: #2563EB; }
    .bdg-closed      { background: #D1FAE5; color: #059669; }
    .bdg-pending     { background: #DBEAFE; color: #2563EB; }
    .bdg-resolved    { background: #D1FAE5; color: #059669; }

    /* ── Flag-style priority — no background, no border ── */
    .priority-pill {
      display: inline-flex; align-items: center; gap: 5px;
      font-size: .68rem; font-weight: 800; letter-spacing: .07em; text-transform: uppercase;
      background: none; border: none; padding: 0;
    }
    .priority-flag-icon {
      width: 13px; height: 13px; flex-shrink: 0;
      vertical-align: middle; position: relative; top: -1px;
    }
    .priority-pill.pp-high   { color: #DC2626; }
    .priority-pill.pp-high   .priority-flag-icon { fill: #DC2626; stroke: #DC2626; }
    .priority-pill.pp-medium { color: #D97706; }
    .priority-pill.pp-medium .priority-flag-icon { fill: #EAB308; stroke: #EAB308; }
    .priority-pill.pp-low    { color: #2563EB; }
    .priority-pill.pp-low    .priority-flag-icon { fill: #3B82F6; stroke: #3B82F6; }

    /* ── Assign To: gradient avatar — identical to tickets.php .staff-avatar-sm ── */
    .assigned-cell {
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .staff-avatar-sm {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      background: linear-gradient(135deg, #001f5c, #1a56db);
      color: white;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 11px;
      font-weight: 700;
      flex-shrink: 0;
      text-transform: uppercase;
      letter-spacing: .03em;
    }
    .assigned-info { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
    .assigned-name {
      font-size: 13px; color: var(--g700); font-weight: 500;
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 130px;
    }
    .assigned-code { font-size: 11px; color: var(--g400); font-family: monospace; }

    /* Unassigned — italic muted, matching tickets.php */
    .unassigned-tag { font-size: 12px; color: #9CA3AF; font-style: italic; }

    .empty { text-align: center; padding: 40px 20px; color: var(--g500); }
    .empty svg { width: 38px; height: 38px; margin: 0 auto 10px; display: block; stroke: var(--g300); fill: none; stroke-width: 1.5; }
    .dept-empty { font-size: 13px; color: var(--g400); text-align: center; padding: 20px 0; }

    @media (max-width: 900px) {
      .stats    { grid-template-columns: 1fr 1fr; }
      .mid-row  { grid-template-columns: 1fr; }
      .mid-row-2{ grid-template-columns: 1fr; }
    }
    @media (max-width: 600px) {
      .stats { grid-template-columns: 1fr; }
    }

    /* ── Category badge (matches tickets.php) ── */
.cat-badge {
  display: inline-block;
  font-size: 13px;
  font-weight: 500;
  color: var(--g700);
  white-space: nowrap;
  max-width: 180px;
  overflow: hidden;
  text-overflow: ellipsis;
  vertical-align: middle;
}

  </style>
</head>
<body>

<?php require_once __DIR__ . '/_layout.php'; ?>

    <!-- ══ TOP: Open / In Progress / Closed ══ -->
    <div class="stats">
      <div class="stat">
        <div class="stat-icon si-open">
          <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        </div>
        <div>
          <div class="num"><?php echo $openCount; ?></div>
          <div class="lbl">Open Tickets</div>
        </div>
      </div>
      <div class="stat">
        <div class="stat-icon si-progress">
          <svg viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
        </div>
        <div>
          <div class="num"><?php echo $inProgressCount; ?></div>
          <div class="lbl">In Progress</div>
        </div>
      </div>
      <div class="stat">
        <div class="stat-icon si-closed">
          <svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22,4 12,14.01 9,11.01"/></svg>
        </div>
        <div>
          <div class="num"><?php echo $closedCount; ?></div>
          <div class="lbl">Closed Tickets</div>
        </div>
      </div>
    </div>

    <!-- ══ MIDDLE: My Tasks | Today's Activity ══ -->
    <div class="mid-row">

      <!-- My Tasks -->
      <div class="card-box">
        <div class="card-box-title" style="color:#1D4ED8;">
          <svg viewBox="0 0 24 24" style="stroke:#1D4ED8;">
            <rect x="2" y="7" width="20" height="14" rx="2" ry="2"/>
            <path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/>
            <line x1="12" y1="12" x2="12" y2="12"/>
          </svg>
          My Tasks
          <span style="margin-left:auto; background:#DBEAFE; color:#1D4ED8; font-size:11px; padding:2px 8px; border-radius:99px;">
            <?php echo count($myTasks); ?>
          </span>
        </div>

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

      <!-- Today's Activity -->
      <div class="card-box">
        <div class="card-box-title">
          <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          Today's Activity
        </div>
        <div class="today-grid">
          <div class="today-cell">
            <div class="today-num"><?php echo $todayReceived; ?></div>
            <div class="today-lbl">Received</div>
          </div>
          <div class="today-cell">
            <div class="today-num" style="color:#059669;"><?php echo $todayResolved; ?></div>
            <div class="today-lbl">Resolved</div>
          </div>
        </div>
      </div>

    </div><!-- /.mid-row -->

    <!-- ══ MIDDLE 2: Priority Breakdown | Top Departments ══ -->
    <div class="mid-row-2">

      <!-- Priority Breakdown -->
      <div class="card-box">
        <div class="card-box-title">
          <svg viewBox="0 0 24 24"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>
          Priority Breakdown
        </div>
        <?php if ($priTotal === 0): ?>
          <div class="dept-empty">No tickets yet.</div>
        <?php else:
          $r    = 38;
          $c    = round(2 * M_PI * $r, 2);
          $pLow  = $priTotal > 0 ? $priLow   / $priTotal * $c : 0;
          $pMed  = $priTotal > 0 ? $priMedium / $priTotal * $c : 0;
          $pHigh = $priTotal > 0 ? $priHigh   / $priTotal * $c : 0;
        ?>
        <div class="donut-layout">
          <div class="donut-wrap">
            <svg viewBox="0 0 108 108" role="img" aria-label="Priority donut chart">
              <circle cx="54" cy="54" r="<?php echo $r; ?>" fill="none" stroke="#F3F4F6" stroke-width="13"/>
              <?php if ($pLow > 0): ?>
              <circle cx="54" cy="54" r="<?php echo $r; ?>" fill="none" stroke="#97C459" stroke-width="13"
                stroke-dasharray="<?php echo round($pLow,2).' '.round($c-$pLow,2); ?>"
                stroke-dashoffset="<?php echo round($c/4, 2); ?>"
                transform="rotate(-90 54 54)" stroke-linecap="butt"/>
              <?php endif; if ($pMed > 0): ?>
              <circle cx="54" cy="54" r="<?php echo $r; ?>" fill="none" stroke="#EF9F27" stroke-width="13"
                stroke-dasharray="<?php echo round($pMed,2).' '.round($c-$pMed,2); ?>"
                stroke-dashoffset="<?php echo round($c/4 - $pLow + $c, 2) % round($c,2); ?>"
                transform="rotate(-90 54 54)" stroke-linecap="butt"/>
              <?php endif; if ($pHigh > 0): ?>
              <circle cx="54" cy="54" r="<?php echo $r; ?>" fill="none" stroke="#E24B4A" stroke-width="13"
                stroke-dasharray="<?php echo round($pHigh,2).' '.round($c-$pHigh,2); ?>"
                stroke-dashoffset="<?php echo round(($c/4 - $pLow - $pMed + $c*2), 2) % round($c,2); ?>"
                transform="rotate(-90 54 54)" stroke-linecap="butt"/>
              <?php endif; ?>
            </svg>
            <div class="donut-center">
              <span class="donut-total"><?php echo $priTotal; ?></span>
              <span class="donut-sub">tickets</span>
            </div>
          </div>
          <div class="pill-list">
            <div class="pill-row">
              <span class="pill-name"><span class="pill-dot dot-low"></span>Low</span>
              <span class="pill-num num-low"><?php echo $priLow; ?></span>
            </div>
            <div class="pill-row">
              <span class="pill-name"><span class="pill-dot dot-medium"></span>Medium</span>
              <span class="pill-num num-medium"><?php echo $priMedium; ?></span>
            </div>
            <div class="pill-row">
              <span class="pill-name"><span class="pill-dot dot-high"></span>High</span>
              <span class="pill-num num-high"><?php echo $priHigh; ?></span>
            </div>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Top Departments Filing Complaints -->
      <div class="card-box">
        <div class="card-box-title">
          <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
          Top Departments Filing Complaints
        </div>
        <?php if (empty($topDepts)): ?>
          <div class="topdept-empty">No complaints data yet.</div>
        <?php else: ?>
        <div class="topdept-list">
          <?php foreach ($topDepts as $i => $dept):
            $barPct = $topDeptsMax > 0 ? round(($dept['total'] / $topDeptsMax) * 100) : 0;
          ?>
          <div class="topdept-row">
            <span class="topdept-rank"><?php echo $i + 1; ?></span>
            <span class="topdept-name" title="<?php echo htmlspecialchars($dept['my_department'] ?? '—'); ?>">
              <?php echo htmlspecialchars($dept['my_department'] ?? '—'); ?>
            </span>
            <div class="topdept-bar-wrap">
              <div class="topdept-bar" style="width:<?php echo $barPct; ?>%"></div>
            </div>
            <span class="topdept-count"><?php echo (int)$dept['total']; ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div><!-- /.mid-row-2 -->

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
            <tr><td colspan="6">
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

              <!-- Ticket ID -->
              <td>
                <a class="tid-link" href="ticket_detail.php?id=<?php echo urlencode($t['ticket_id']); ?>">
                  <?php echo $tid; ?>
                </a>
              </td>

              <!-- Title -->
              <td><?php echo htmlspecialchars($t['title']); ?></td>

              <!-- From Department + date/time stacked (Created column removed) -->
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
              <td>
                <span class="bdg bdg-<?php echo $s; ?>"><?php echo $statusLabel; ?></span>
              </td>

              <!-- Priority — flag style, no bg/border -->
              <td>
                <span class="priority-pill pp-<?php echo $pri; ?>">
                  <svg class="priority-flag-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"
                       fill="<?php echo $flagFill; ?>" stroke="<?php echo $flagFill; ?>">
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
                <?php else: ?>
                  <span style="font-size:12px;color:#9CA3AF;font-style:italic;">—</span>
                <?php endif; ?>
              </td>

              <!-- Assign To — gradient circle avatar + name + staff code, same as tickets.php -->
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

</body>
</html>