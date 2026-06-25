<?php
// dept_admin/maintenance/reports.php
require '_layout.php';

require_once __DIR__ . '/../../sla_helper.php';
require_once __DIR__ . '/../../db_connect.php';

$range       = $_GET['range']   ?? '30';
$activeTab   = $_GET['tab']     ?? 'tickets';
$days        = in_array($range, ['7','30','90']) ? (int)$range : null;
$dateWhere   = $days ? "AND c.created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)" : '';
 
/* ══════════════════════════════════════════════
   TAB 1 — TICKETS
══════════════════════════════════════════════ */
$summary = $conn->query("
    SELECT COUNT(*) AS total, SUM(status='open') AS open,
           SUM(status='in_progress') AS in_progress, SUM(status='closed') AS closed,
           SUM(priority='high') AS high, SUM(priority='medium') AS medium, SUM(priority='low') AS low
    FROM complaints c WHERE dept_id = 2 $dateWhere
")->fetch_assoc();

// Fetch all non-closed tickets for SLA calculation (same as dashboard)
$slaRawReport = $conn->query("
    SELECT
        c.ticket_id, c.status, c.priority,
        c.created_at, c.sla_start_at, c.resolved_at,
        c.first_response_at,
        c.assigned_to,
        (SELECT MIN(l.changed_at)
         FROM ticket_logs l
         WHERE l.ticket_id = c.ticket_id
           AND l.new_status IN ('in_progress','closed')
           AND l.old_status = 'open'
        ) AS first_log_response_at
    FROM complaints c
    WHERE c.dept_id = 2
")->fetch_all(MYSQLI_ASSOC);

$breaches = 0;
foreach ($slaRawReport as $slaRow) {
    // Best respond timestamp: column first, then logs fallback
    $respondTs = null;
    if (!empty($slaRow['first_response_at'])) {
        $respondTs = $slaRow['first_response_at'];
    } elseif (!empty($slaRow['first_log_response_at'])) {
        $respondTs = $slaRow['first_log_response_at'];
    }

    $from = new DateTime($slaRow['created_at'], new DateTimeZone(SLA_TZ));

    if (!empty($respondTs)) {
        $to = new DateTime($respondTs, new DateTimeZone(SLA_TZ));
        $mins = workingMinutesBetween($from, $to);
        if ($mins > SLA_WORK_HOURS * 60) $breaches++;
    } else {
        $now  = new DateTime('now', new DateTimeZone(SLA_TZ));
        $mins = workingMinutesBetween($from, $now);
        if ($mins >= SLA_WORK_HOURS * 60) $breaches++;
    }
}

/* ── Per-staff avg respond time ── */
$staffRespondStats = [];
foreach ($slaRawReport as $slaRow) {
    $staffId = $slaRow['assigned_to'] ?? null;
    if (!$staffId) continue;

    if (!isset($staffRespondStats[$staffId])) {
        $staffRespondStats[$staffId] = [
            'total'              => 0,
            'responded_count'    => 0,
            'total_respond_mins' => 0,
        ];
    }
    $staffRespondStats[$staffId]['total']++;

    $respondTs = null;
    if (!empty($slaRow['first_response_at'])) {
        $respondTs = $slaRow['first_response_at'];
    } elseif (!empty($slaRow['first_log_response_at'])) {
        $respondTs = $slaRow['first_log_response_at'];
    }

    if (!empty($respondTs)) {
        $from = new DateTime($slaRow['created_at'], new DateTimeZone(SLA_TZ));
        $to   = new DateTime($respondTs,            new DateTimeZone(SLA_TZ));
        $mins = workingMinutesBetween($from, $to);
        $staffRespondStats[$staffId]['total_respond_mins'] += $mins;
        $staffRespondStats[$staffId]['responded_count']++;
    }
}

$avgHours = $conn->query("
    SELECT ROUND(AVG(TIMESTAMPDIFF(HOUR,c.created_at,l.changed_at)),1) AS avg_hours
    FROM complaints c
    JOIN ticket_logs l ON l.ticket_id=c.ticket_id AND l.new_status='closed'
    WHERE c.dept_id=2 $dateWhere
")->fetch_assoc()['avg_hours'] ?? null;

$prevTotal = 0;
if ($days) {
    $prevWhere = "AND c.created_at >= DATE_SUB(NOW(), INTERVAL ".($days*2)." DAY) AND c.created_at < DATE_SUB(NOW(), INTERVAL {$days} DAY)";
    $prevTotal = $conn->query("SELECT COUNT(*) AS cnt FROM complaints c WHERE dept_id=2 $prevWhere")->fetch_assoc()['cnt'] ?? 0;
}
$currentTotal = (int)($summary['total'] ?? 0);
$trendDir  = $prevTotal > 0 ? ($currentTotal > $prevTotal ? 'up' : ($currentTotal < $prevTotal ? 'down' : 'same')) : 'same';
$trendPct  = $prevTotal > 0 ? round(abs($currentTotal - $prevTotal) / $prevTotal * 100) : 0;

$allTickets = $conn->query("
    SELECT
        c.ticket_id,
        c.title,
        cat.category_name,
        c.status,
        c.priority,
        c.created_at,
        c.sla_start_at,
        c.resolved_at,
        c.first_response_at,
        TIMESTAMPDIFF(HOUR, c.created_at, NOW()) AS age_hours,
        0 AS is_breached,
        NULL AS resolution_hours,
        s.full_name AS assigned_staff_name,
        c.submitter_type,
        c.submitter_id,
        c.submitter_name,
        c.submitter_email,
        sub_s.full_name AS submitter_staff_name,
        sub_s.email     AS submitter_staff_email,
        /* Fallback: get first response from ticket_logs if first_response_at is NULL */
        (SELECT MIN(l.changed_at)
         FROM ticket_logs l
         WHERE l.ticket_id = c.ticket_id
           AND l.new_status IN ('in_progress','closed')
           AND l.old_status = 'open'
        ) AS first_log_response_at
    FROM complaints c
    JOIN categories cat ON cat.category_id = c.category_id
    LEFT JOIN staff s    ON s.staff_id    = c.assigned_to
    LEFT JOIN staff sub_s ON sub_s.staff_id = c.submitter_id
    WHERE c.dept_id = 2
    GROUP BY c.ticket_id, c.title, cat.category_name, c.status,
             c.priority, c.created_at, c.sla_start_at, c.resolved_at,
             c.first_response_at, s.full_name, c.submitter_type, c.submitter_id,
             c.submitter_name, c.submitter_email, sub_s.full_name, sub_s.email
    ORDER BY c.created_at DESC
")->fetch_all(MYSQLI_ASSOC);

foreach ($allTickets as &$t) {

    // ── Determine best respond timestamp ──────────────────────────────────
    // Priority: first_response_at (DB column) → first_log_response_at (from logs)
    $respondTimestamp = null;
    if (!empty($t['first_response_at'])) {
        $respondTimestamp = $t['first_response_at'];
    } elseif (!empty($t['first_log_response_at'])) {
        $respondTimestamp = $t['first_log_response_at'];
    }

    // ── Respond Time ──────────────────────────────────────────────────────
    // Working hours from created_at → first response (all tickets)
    $t['respond_hours'] = null;
    if (!empty($respondTimestamp)) {
        $from = new DateTime($t['created_at'],   new DateTimeZone(SLA_TZ));
        $to   = new DateTime($respondTimestamp,  new DateTimeZone(SLA_TZ));
        $t['respond_hours'] = round(workingMinutesBetween($from, $to) / 60, 1);
    }

    // ── Resolution Time ───────────────────────────────────────────────────
    // Working hours from created_at → resolved_at (closed tickets only)
    $t['resolution_hours'] = null;
    if ($t['status'] === 'closed' && !empty($t['resolved_at'])) {
        $from = new DateTime($t['created_at'],  new DateTimeZone(SLA_TZ));
        $to   = new DateTime($t['resolved_at'], new DateTimeZone(SLA_TZ));
        $t['resolution_hours'] = round(workingMinutesBetween($from, $to) / 60, 1);
    }

    // ── SLA Breach ────────────────────────────────────────────────────────
    // Rule: no response within 8 working hours of created_at = BREACHED
    // "Response" = first time status changed away from open
    $from = new DateTime($t['created_at'], new DateTimeZone(SLA_TZ));

    if (!empty($respondTimestamp)) {
        // Ticket was responded to — check if response was WITHIN 8 working hours
        $to = new DateTime($respondTimestamp, new DateTimeZone(SLA_TZ));
        $respondMins = workingMinutesBetween($from, $to);
        $t['is_breached'] = ($respondMins > SLA_WORK_HOURS * 60) ? 1 : 0;
    } else {
        // No response at all yet — check if 8 working hours have passed since created_at
        $now = new DateTime('now', new DateTimeZone(SLA_TZ));
        $elapsedMins = workingMinutesBetween($from, $now);
        $t['is_breached'] = ($elapsedMins >= SLA_WORK_HOURS * 60) ? 1 : 0;
    }

    // ── Resolve submitter name/email based on type ────────────────────────
    if (($t['submitter_type'] ?? '') === 'staff') {
        $t['submitter_name']  = $t['submitter_staff_name']  ?? null;
        $t['submitter_email'] = $t['submitter_staff_email'] ?? null;
    } else {
        $t['submitter_name']  = !empty($t['submitter_name'])  ? decryptField($t['submitter_name'])  : null;
        $t['submitter_email'] = !empty($t['submitter_email']) ? decryptField($t['submitter_email']) : null;
    }

    // ── Always hide submitter name in this report — email only ────────────
    $t['submitter_name'] = null;
}
unset($t);

$avgFirstResponse = $conn->query("
    SELECT ROUND(AVG(first_resp_min) / 60.0, 1) AS avg_fr_h
    FROM (
        SELECT c.ticket_id,
               COALESCE(
                   MIN(TIMESTAMPDIFF(MINUTE, c.created_at, r.created_at)),
                   (SELECT TIMESTAMPDIFF(MINUTE, c.created_at, MIN(l.changed_at))
                    FROM ticket_logs l
                    JOIN staff s ON s.staff_id = l.changed_by_id
                    WHERE l.ticket_id = c.ticket_id
                      AND s.dept_id = 2)
               ) AS first_resp_min
        FROM complaints c
        LEFT JOIN ticket_replies r
            ON r.ticket_id = c.ticket_id
            AND r.sender_role IN ('staff', 'dept_handler', 'admin')
        WHERE c.dept_id = 2 $dateWhere
        GROUP BY c.ticket_id
    ) sub
")->fetch_assoc()['avg_fr_h'] ?? null;


/* ══════════════════════════════════════════════
   TAB 2 — STAFF ACTIVITY
══════════════════════════════════════════════ */
$staffDateWhere = $days
    ? "AND c.created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)"
    : '';

$staffActivity = $conn->query("
    SELECT
        s.staff_id,
        s.full_name,
        s.staff_code,
        s.role,
        COUNT(c.ticket_id)                                                      AS tickets_handled,
        SUM(c.status = 'closed')                                                AS resolved,
        SUM(c.status = 'in_progress')                                           AS in_progress_count,
        SUM(c.status = 'open')                                                  AS open_count,
        ROUND(
            AVG(
                CASE
                    WHEN c.status = 'closed'
                    THEN TIMESTAMPDIFF(HOUR, c.created_at,
                            (SELECT l2.changed_at
                             FROM ticket_logs l2
                             WHERE l2.ticket_id = c.ticket_id
                               AND l2.new_status = 'closed'
                             ORDER BY l2.changed_at DESC
                             LIMIT 1))
                END
            ), 1
        )                                                                       AS avg_resolution_h
    FROM staff s
    LEFT JOIN complaints c
           ON c.assigned_to = s.staff_id
          AND c.dept_id = 2
          $staffDateWhere
    WHERE s.dept_id = 2
      AND s.role IN ('staff', 'admin')
    GROUP BY s.staff_id, s.full_name, s.staff_code, s.role
    ORDER BY resolved DESC, tickets_handled DESC
")->fetch_all(MYSQLI_ASSOC);


/* ══════════════════════════════════════════════
   TAB 3 — CATEGORY
══════════════════════════════════════════════ */
$catRange     = $_GET['cat_range'] ?? '30';
$catDays      = in_array($catRange, ['7','30','60','90','180','365']) ? (int)$catRange : null;
$catDateWhere = $catDays ? "AND c.created_at >= DATE_SUB(NOW(), INTERVAL {$catDays} DAY)" : '';

$categoryStats = $conn->query("
    SELECT cat.category_name, 
           COUNT(c.ticket_id) AS total,
           SUM(c.status='open') AS open, 
           SUM(c.status='in_progress') AS in_progress,
           SUM(c.status='closed') AS closed, 
           SUM(c.priority='high') AS high,
           ROUND(AVG(TIMESTAMPDIFF(HOUR, c.created_at, NOW())), 1) AS avg_age_h,
           ROUND(
               CASE WHEN COUNT(c.ticket_id) > 0 
               THEN SUM(c.status='closed') / COUNT(c.ticket_id) * 100 
               ELSE 0 END
           , 1) AS resolution_rate
    FROM categories cat
    LEFT JOIN complaints c 
        ON c.category_id = cat.category_id 
        AND c.dept_id = 2
        $catDateWhere
    WHERE cat.dept_id = 2
    GROUP BY cat.category_id, cat.category_name 
    ORDER BY total DESC
")->fetch_all(MYSQLI_ASSOC);


$topIssues = $conn->query("
    SELECT c.title, COUNT(*) AS cnt, cat.category_name
    FROM complaints c JOIN categories cat ON cat.category_id=c.category_id
    WHERE c.dept_id=2 $catDateWhere
    GROUP BY c.title,cat.category_name ORDER BY cnt DESC LIMIT 10
")->fetch_all(MYSQLI_ASSOC);


/* ══════════════════════════════════════════════
   TAB 4 — FEEDBACK
══════════════════════════════════════════════ */
$feedbackStats = $conn->query("
    SELECT COUNT(*) AS total_feedback, ROUND(AVG(f.rating),2) AS avg_rating,
        SUM(f.rating>=4) AS positive, SUM(f.rating=3) AS neutral, SUM(f.rating<=2) AS negative,
        SUM(f.rating=5) AS five_star, SUM(f.rating=4) AS four_star,
        SUM(f.rating=3) AS three_star, SUM(f.rating=2) AS two_star, SUM(f.rating=1) AS one_star
    FROM ticket_feedback f JOIN complaints c ON c.ticket_id=f.ticket_id
    WHERE c.dept_id=2 ".($days ? "AND f.created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)" : '')
)->fetch_assoc();

$feedbackList = $conn->query("
    SELECT
        f.ticket_id,
        c.title             AS ticket_title,
        f.rating,
        f.comment,
        f.is_auto_submitted,
        f.created_at,
        c.submitter_type,
        c.my_department     AS submitted_by_dept,
        cat.category_name,
        COALESCE(ast.full_name, 'Unassigned') AS assigned_staff_name
    FROM ticket_feedback f
    JOIN complaints c   ON c.ticket_id   = f.ticket_id
    JOIN categories cat ON cat.category_id = c.category_id
    LEFT JOIN staff ast ON ast.staff_id  = c.assigned_to
    WHERE c.dept_id = 2
    " . ($days ? "AND f.created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)" : '') . "
    ORDER BY f.created_at DESC
")->fetch_all(MYSQLI_ASSOC);

/* ══════════════════════════════════════════════
   FACE EMOJI SVG HELPER
══════════════════════════════════════════════ */
function feedbackFaceSvg(int $rating, int $size = 28): string {
    $faces = [
        1 => [
            'stroke' => '#EF4444', 'fill' => '#FEE2E2',
            'eyes'   => '<circle cx="17" cy="20" r="2.5" fill="#EF4444"/><circle cx="31" cy="20" r="2.5" fill="#EF4444"/>',
            'mouth'  => '<path d="M16 33c2-4 14-4 16 0" stroke="#EF4444" stroke-width="2.5" stroke-linecap="round"/>
                         <path d="M15 15l4 3M33 15l-4 3" stroke="#EF4444" stroke-width="2" stroke-linecap="round"/>',
        ],
        2 => [
            'stroke' => '#F97316', 'fill' => '#FFEDD5',
            'eyes'   => '<circle cx="17" cy="20" r="2.5" fill="#F97316"/><circle cx="31" cy="20" r="2.5" fill="#F97316"/>',
            'mouth'  => '<path d="M17 32c2-3 12-3 14 0" stroke="#F97316" stroke-width="2.5" stroke-linecap="round"/>',
        ],
        3 => [
            'stroke' => '#EAB308', 'fill' => '#FEF9C3',
            'eyes'   => '<circle cx="17" cy="20" r="2.5" fill="#EAB308"/><circle cx="31" cy="20" r="2.5" fill="#EAB308"/>',
            'mouth'  => '<line x1="17" y1="32" x2="31" y2="32" stroke="#EAB308" stroke-width="2.5" stroke-linecap="round"/>',
        ],
        4 => [
            'stroke' => '#22C55E', 'fill' => '#DCFCE7',
            'eyes'   => '<circle cx="17" cy="20" r="2.5" fill="#22C55E"/><circle cx="31" cy="20" r="2.5" fill="#22C55E"/>',
            'mouth'  => '<path d="M16 28c2 4 14 4 16 0" stroke="#22C55E" stroke-width="2.5" stroke-linecap="round"/>',
        ],
        5 => [
            'stroke' => '#16A34A', 'fill' => '#D1FAE5',
            'eyes'   => '<circle cx="17" cy="19" r="2.5" fill="#16A34A"/><circle cx="31" cy="19" r="2.5" fill="#16A34A"/>',
            'mouth'  => '<path d="M14 27c2 6 18 6 20 0" stroke="#16A34A" stroke-width="2.5" stroke-linecap="round"/>',
        ],
    ];
    $f = $faces[$rating] ?? $faces[3];
    return '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">'
         . '<circle cx="24" cy="24" r="22" stroke="'.htmlspecialchars($f['stroke']).'" stroke-width="2.5" fill="'.htmlspecialchars($f['fill']).'"/>'
         . $f['eyes']
         . $f['mouth']
         . '</svg>';
}

function ratingLabelText(int $r): string {
    $labels = [
        5 => 'Very Satisfied',
        4 => 'Satisfied',
        3 => 'Neutral',
        2 => 'Dissatisfied',
        1 => 'Very Dissatisfied',
    ];
    return $labels[$r] ?? 'Unknown';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Maintenance Admin — Reports | UniKL Help Desk</title>
  <?php include '_head_assets.php'; ?>
  <link rel="stylesheet" href="css/tickets-report.css"/>
  <link rel="stylesheet" href="css/reports-tabs.css"/>
  <link rel="stylesheet" href="css/feedback.css"/>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>

  <style>
    .filter-panel, .fb2-filter-panel {
  margin-top: 1.25rem;
}
    /* ── Resolution Rate column: inline formula note ── */
    .col-rate-note {
      display: block;
      font-size: .62rem;
      font-weight: 500;
      color: #b0a1c8;
      letter-spacing: 0;
      text-transform: none;
      margin-top: 2px;
      white-space: nowrap;
    }

    /* ── Summary strip: live calc note beside the rate value ── */
    .ss-rate-note {
      font-size: .72rem;
      color: #94a3b8;
      font-weight: 400;
      margin-left: .3rem;
    }

    /* ── Staff Activity: status breakdown mini-badges ── */
    .staff-status-wrap {
      display: flex;
      flex-direction: column;
      gap: 3px;
    }
    .staff-status-row {
      display: flex;
      align-items: center;
      gap: 5px;
      font-size: .72rem;
    }
    .staff-status-dot {
      width: 7px; height: 7px;
      border-radius: 50%;
      flex-shrink: 0;
    }
    .staff-status-val {
      font-weight: 700;
      min-width: 18px;
      text-align: right;
    }
    .staff-status-label {
      color: #94a3b8;
    }

    /* ── Staff unassigned note ── */
    .staff-none-note {
      font-size: .72rem;
      color: #94a3b8;
      font-style: italic;
    }

    /* ── Tab badge: face + number ── */
    .tab-badge.rating {
      display: inline-flex;
      align-items: center;
      gap: 3px;
    }

    /* ══ FEEDBACK KPI: face icon beside label at BOTTOM ══ */
    /* The big number sits at top, label+icon row at bottom  */
    #tab-feedback .chart-kpi-row .chart-kpi-lbl {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      font-size: .67rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .07em;
      line-height: 1;
      flex-wrap: nowrap;
    }
    #tab-feedback .chart-kpi-row .chart-kpi-lbl svg {
      flex-shrink: 0;
      vertical-align: middle;
    }
    /* No icon floating above the number */
    .fb-kpi-face {
      display: none !important;
    }

    /* ══ TABLE RATING CELL — clean, no large face icon ══ */
    .fb-rating-cell {
      display: flex;
      flex-direction: column;
      gap: 3px;
    }
    .fb-rating-top {
      display: flex;
      align-items: baseline;
      gap: 3px;
    }
    .fb-rating-num {
      font-weight: 900;
      font-size: 1rem;
      line-height: 1;
    }
    .fb-rating-denom {
      font-size: .72rem;
      font-weight: 600;
      color: #94a3b8;
    }
    .fb-rating-label-text {
      font-size: .68rem;
      font-weight: 700;
      letter-spacing: .01em;
      white-space: nowrap;
    }

   /* Face icon wrap in table rating cell */
/* Face icon wrap in table rating cell */
.fb-face-icon-wrap {
  display: flex;
  align-items: center;
  margin-top: 4px;
}

.fb-face-icon-wrap svg {
  display: block;
  filter: drop-shadow(0 1px 2px rgba(0,0,0,.10));
  transition: transform .15s ease;
}

.fb-face-icon-wrap svg:hover {
  transform: scale(1.18);
}
    .fb-face-cell { display: none !important; }
.fb-mini-strip { display: none !important; }
/* dot strip is replaced by face icon — hide if still present */
.fb-dot-strip { display: none !important; }
/* ── All-5-faces row in rating cell ── */
.fb-face-row {
  display: flex;
  align-items: center;
  gap: 2px;  /* was 3px */
  margin-top: 5px;
}
.fb-face-item {
  display: flex;
  align-items: center;
  transition: transform .15s ease, opacity .15s ease;
}

/* Active face: full color, slightly larger */
.fb-face-item.fb-face-active svg {
  opacity: 1;
  filter: drop-shadow(0 1px 3px rgba(0,0,0,.18));
  transform: scale(1.25);
}

/* Dimmed faces: washed out */
.fb-face-item.fb-face-dim svg {
  opacity: 0.22;
  filter: grayscale(0.4);
}

.fb-face-row:hover .fb-face-item.fb-face-active svg {
  transform: scale(1.35);
}
  </style>
</head>
<body>
<?php include '_sidebar.php'; ?>

<main class="main-content">

  <!-- Page Header -->
  <div class="page-header">
    <div>
      <div class="page-eyebrow">Maintenance Department</div>
      <h1 class="page-title">Reports &amp; Analytics</h1>
    </div>
  </div>

  <!-- Section Tabs -->
  <div class="section-tabs">
    <a href="?tab=tickets&range=<?= $range ?>" class="section-tab <?= $activeTab==='tickets'?'active':'' ?>">
      <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      Tickets
      <?php if ($breaches > 0): ?><span class="tab-badge breach"><?= $breaches ?> Breaches</span><?php endif; ?>
    </a>
    <a href="?tab=staff&range=<?= $range ?>" class="section-tab <?= $activeTab==='staff'?'active':'' ?>">
      <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      Staff Activity
    </a>
    <a href="?tab=feedback&range=<?= $range ?>" class="section-tab <?= $activeTab==='feedback'?'active':'' ?>">
      <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
      Feedback
      <?php if ($feedbackStats['avg_rating']): ?>
        <span class="tab-badge rating">
          <?= feedbackFaceSvg((int)round((float)$feedbackStats['avg_rating']), 14) ?>
          <?= $feedbackStats['avg_rating'] ?>
        </span>
      <?php endif; ?>
    </a>
    <a href="?tab=category&range=<?= $range ?>&cat_range=<?= $catRange ?>" class="section-tab <?= $activeTab==='category'?'active':'' ?>">
      <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      Category
    </a>
  </div>


  <!-- ════════════════════════════════════════
       TAB: TICKETS
  ════════════════════════════════════════ -->
  <?php if ($activeTab === 'tickets'): ?>
  <div class="tab-panel" id="tab-tickets">

    

  <!-- ── KPI ROW ── -->
<div class="chart-kpi-row kpi-above-chart">
  <div class="chart-kpi-box">
    <div class="chart-kpi-val" id="ckpi-total">—</div>
    <div class="chart-kpi-lbl">Total</div>
  </div>
  <div class="chart-kpi-box" style="background:#EFF6FF;border-top-color:#4338ca;">
    <div class="chart-kpi-val" id="ckpi-open" style="color:#4338ca;">—</div>
    <div class="chart-kpi-lbl" style="color:#4338ca;">Open</div>
  </div>
  <div class="chart-kpi-box" style="background:#EEF2FF;border-top-color:#6366f1;">
    <div class="chart-kpi-val" id="ckpi-prog" style="color:#6366f1;">—</div>
    <div class="chart-kpi-lbl" style="color:#6366f1;">In Progress</div>
  </div>
  <div class="chart-kpi-box" style="background:#F0FDF4;border-top-color:#16A34A;">
    <div class="chart-kpi-val" id="ckpi-closed" style="color:#16A34A;">—</div>
    <div class="chart-kpi-lbl" style="color:#16A34A;">Closed</div>
  </div>
  <div class="chart-kpi-box" style="background:#FEF2F2;border-top-color:#DC2626;">
    <div class="chart-kpi-val" id="ckpi-high" style="color:#DC2626;">—</div>
    <div class="chart-kpi-lbl" style="color:#DC2626;">High Priority</div>
  </div>
  <div class="chart-kpi-box" style="background:#FFF5F5;border-top-color:#DC2626;border-color:#FECACA;">
    <div class="chart-kpi-val" style="color:#DC2626;"><?= $breaches ?></div>
    <div class="chart-kpi-lbl" style="color:#DC2626;">SLA Breached</div>
  </div>
</div>

    <div class="chart-card">
      <div class="chart-card-header">
        <div>
          <h2 class="chart-title">Ticket Status &amp; Priority</h2>
          <div class="chart-subtitle-text" id="chartSubtitleText">Loading…</div>
        </div>
        <div style="display:flex;flex-direction:column;gap:.5rem;align-items:flex-end;">
          <div class="chart-legend-group">
            <span class="legend-section-label">STATUS</span>
<span class="legend-pill"><span class="legend-dot" style="background:#DC2626"></span>Open</span>
<span class="legend-pill"><span class="legend-dot" style="background:#16A34A"></span>Closed</span>
<span class="legend-pill"><span class="legend-dot" style="background:#3503aa"></span>In Progress</span>
          </div>
          <div class="chart-legend-group">
            <span class="legend-section-label">PRIORITY</span>
            <span class="legend-pill"><span class="legend-dot" style="background:#e64545"></span>High</span>
            <span class="legend-pill"><span class="legend-dot" style="background:#e48a36"></span>Medium</span>
            <span class="legend-pill"><span class="legend-dot" style="background:#93c5fd"></span>Low</span>
          </div>
        </div>
      </div>
      <div class="chart-canvas-wrap">
        <canvas id="ticketsBarChart"></canvas>
      </div>
    </div>

    <div class="filter-panel">
      <div class="filter-panel-title">
        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M3 4a1 1 0 0 1 1-1h16a1 1 0 0 1 .8 1.6L14 12.4V20a1 1 0 0 1-1.45.9l-4-2A1 1 0 0 1 8 18v-5.6L3.2 5.6A1 1 0 0 1 3 4z"/></svg>
        Chart &amp; Table Filters
      </div>
      <div class="filter-panel-row">
        <div class="filter-group">
          <span class="filter-group-label">Analysis Period</span>
          <div class="period-select-wrap">
            <svg class="period-select-icon" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <select class="filter-select period-select" id="filterPeriod">
              <option value="7">Last 7 days</option>
              <option value="30" selected>Last 30 days</option>
              <option value="60">Last 60 days</option>
              <option value="90">Last 90 days</option>
              <option value="180">Last 6 months</option>
              <option value="365">Last year</option>
              <option value="all">All time</option>
              <option value="custom">Custom date range</option>
            </select>
          </div>
        </div>
        <div class="filter-group custom-range-group" id="customRangeGroup" style="display:none;">
          <span class="filter-group-label">From</span>
          <input type="date" class="filter-date-input" id="customDateFrom"/>
        </div>
        <div class="filter-group custom-range-group" id="customRangeGroupTo" style="display:none;">
          <span class="filter-group-label">To</span>
          <input type="date" class="filter-date-input" id="customDateTo"/>
        </div>
        <div class="filter-group">
          <span class="filter-group-label">Status</span>
          <select class="filter-select" id="filterStatus">
            <option value="all">All Statuses</option>
            <option value="open">Open</option>
            <option value="in_progress">In Progress</option>
            <option value="closed">Closed</option>
          </select>
        </div>
        <div class="filter-group">
  <span class="filter-group-label">Priority</span>
  <select class="filter-select" id="filterPriority">
    <option value="all">All Priorities</option>
    <option value="high">High</option>
    <option value="medium">Medium</option>
    <option value="low">Low</option>
  </select>
</div>
<div class="filter-group">
  <span class="filter-group-label">Assigned To</span>
  <select class="filter-select" id="filterStaff">
    <option value="all">All Staff</option>
    <?php
      // Collect unique assigned staff names from allTickets
      $uniqueStaff = array_unique(array_filter(
        array_column($allTickets, 'assigned_staff_name')
      ));
      sort($uniqueStaff);
      foreach ($uniqueStaff as $sName):
    ?>
    <option value="<?= htmlspecialchars($sName) ?>"><?= htmlspecialchars($sName) ?></option>
    <?php endforeach; ?>
  </select>
</div>
        <div class="filter-group" style="justify-content:flex-end;">
          <span class="filter-group-label">&nbsp;</span>
          <button class="filter-reset-btn" id="filterResetBtn">
            <svg viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.5"/></svg>
            Reset Filters
          </button>
        </div>
      </div>
      <div id="activeFilterSummary" class="active-filter-summary" style="display:none;"></div>
    </div>

    <div class="table-card">
      <div class="table-card-header">
        <h2 class="table-title">🎫 Ticket List</h2>
        <div class="table-actions">
          <input type="text" class="tbl-search tbl-search-fb" id="ticketSearch" placeholder="Search tickets…"/>
          <button class="dl-btn pdf" onclick="downloadPDF()">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            Download PDF
          </button>
          <button class="dl-btn csv" onclick="downloadCSV()">
            <svg viewBox="0 0 24 24"><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Download CSV
          </button>
        </div>
      </div>
      <div style="overflow-x:auto;">
        <table class="data-table" id="ticketTable">
          <thead>
  <tr>
    <th onclick="sortTable(0)">Ticket ID <span class="sort-icon">⇅</span></th>
    <th onclick="sortTable(1)">Category <span class="sort-icon">⇅</span></th>
    <th onclick="sortTable(2)">Status <span class="sort-icon">⇅</span></th>
    <th onclick="sortTable(3)">Priority <span class="sort-icon">⇅</span></th>
    <th onclick="sortTable(4)">Closed By <span class="sort-icon">⇅</span></th>
    <th onclick="sortTable(5)">Complaint By <span class="sort-icon">⇅</span></th>
    <th onclick="sortTable(6)" title="Working hours (Mon-Fri 08:00-17:00 only). Format: Xd Yh or Zh Wm">
        Resolution Time <span class="col-hint">⏱</span>
    </th>
    <th onclick="sortTable(7)" title="Working hours from ticket open until first staff response (in-progress or closed)">
        Respond Time <span class="col-hint">⏱</span>
    </th>
    <th onclick="sortTable(8)">SLA <span class="sort-icon">⇅</span></th>
  </tr>
</thead>
          <tbody id="ticketTableBody">
            <?php if (empty($allTickets)): ?>
            <tr><td colspan="8" class="empty-state">No tickets found.</td></tr>
            <?php else: foreach ($allTickets as $t):
              $catShort = explode(' / ', $t['category_name'])[1] ?? $t['category_name'];
              // Format Respond Time
$respH = $t['respond_hours'] ?? null;
if ($respH === null) {
    $respFmt   = '—';
    $respClass = 'fr-none';
} elseif ($respH < 1) {
    $mins      = (int)round($respH * 60);
    $respFmt   = ($mins === 0 ? '< 1m' : $mins . 'm');
    $respClass = 'fr-fast';
} elseif ($respH <= 8) {
    $wholeH    = (int)floor($respH);
    $remMins   = (int)round(($respH - $wholeH) * 60);
    $respFmt   = $remMins > 0 ? $wholeH . 'h ' . $remMins . 'm' : $wholeH . 'h';
    $respClass = ($respH <= 2) ? 'fr-fast' : 'fr-ok';
} else {
    $days      = (int)floor($respH / 8);
    $remH      = (int)round($respH - ($days * 8));
    $respFmt   = $remH > 0 ? $days . 'd ' . $remH . 'h' : $days . 'd';
    $respClass = $days <= 1 ? 'fr-warn' : 'fr-slow';
}

              $resH = $t['resolution_hours'];
if ($resH === null || $t['status'] !== 'closed') {
    $resFmt   = '—';
    $resClass = 'fr-none';
} elseif ($resH < 1) {
    $mins     = (int)round($resH * 60);
    $resFmt   = ($mins === 0 ? '< 1m' : $mins . 'm');
    $resClass = 'fr-fast';
} elseif ($resH <= 8) {
    // Under 1 working day — show as Xh Ym
    $wholeH   = (int)floor($resH);
    $remMins  = (int)round(($resH - $wholeH) * 60);
    $resFmt   = $remMins > 0 ? $wholeH . 'h ' . $remMins . 'm' : $wholeH . 'h';
    $resClass = ($resH <= 4) ? 'fr-fast' : 'fr-ok';
} else {
    // Over 1 working day
    $days    = (int)floor($resH / 8);
    $remH    = (int)round($resH - ($days * 8));
    $resFmt  = $remH > 0 ? $days . 'd ' . $remH . 'h' : $days . 'd';
    $resClass = $days <= 3 ? 'fr-warn' : 'fr-slow'; // amber if ≤3 days, red if >3 days
}
            ?>
            <?php $assignedName = $t['assigned_staff_name'] ?? null; ?>
<tr data-id="<?= htmlspecialchars($t['ticket_id']) ?>"
    data-title="<?= htmlspecialchars($t['title']) ?>"
    data-category="<?= htmlspecialchars($catShort) ?>"
    data-status="<?= $t['status'] ?>"
    data-priority="<?= $t['priority'] ?>"
    data-submitted="<?= date('d M Y', strtotime($t['created_at'])) ?>"
    data-submitted-ts="<?= strtotime($t['created_at']) ?>"
    data-assigned="<?= htmlspecialchars($assignedName ?? '—') ?>"
    data-firstresponse="<?= $resFmt ?>"
    data-firstresponse-raw="<?= $resH ?? 9999 ?>"
    data-sla="<?= $t['is_breached'] ? 'Breached' : 'OK' ?>"
    data-respondtime="<?= $respFmt ?>"
    data-respondtime-raw="<?= $respH ?? 9999 ?>"
    data-complaintby="<?= htmlspecialchars($t['submitter_email'] ?? '—') ?>">
  <td><span class="ticket-id"><?= htmlspecialchars($t['ticket_id']) ?></span></td>
  <td><?= htmlspecialchars($catShort) ?></td>
  <td><span class="status-pill sp-<?= str_replace(' ','_',$t['status']) ?>"><?= ucfirst(str_replace('_',' ',$t['status'])) ?></span></td>
  <td>
    <span class="priority-pill pp-<?= $t['priority'] ?>">
      <svg class="priority-flag-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <path d="M4 3h13l-3 5 3 5H4V3z"/>
        <line x1="4" y1="3" x2="4" y2="21"/>
      </svg>
      <?= ucfirst($t['priority']) ?>
    </span>
  </td>
  <td>
    <?php if ($assignedName): ?>
      <span class="assigned-staff-pill"><?= htmlspecialchars($assignedName) ?></span>
    <?php else: ?>
      <span style="color:#94a3b8;font-size:.75rem;font-style:italic;">Unassigned</span>
    <?php endif; ?>
  </td>
  <td style="font-size:.82rem;color:#334155;">
    <?php if (!empty($t['submitter_email'])): ?>
      <?= htmlspecialchars($t['submitter_email']) ?>
    <?php else: ?>
      <span style="color:#94a3b8;">—</span>
    <?php endif; ?>
  </td>
  <td>
    <?php if ($resH === null || $t['status'] !== 'closed'): ?>
      <span class="fr-badge fr-none">—</span>
    <?php else: ?>
      <span class="fr-badge <?= $resClass ?>"><?= $resFmt ?></span>
    <?php endif; ?>
  </td>
  <td>
    <span class="fr-badge <?= $respClass ?>"><?= $respFmt ?></span>
  </td>
  <td>
    <?php if ($t['is_breached']): ?>
      <span class="overdue-badge">⚠ Breached</span>
    <?php else: ?>
      <span class="sla-ok">✓ OK</span>
    <?php endif; ?>
  </td>
</tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <div class="pagination">
        <div class="page-info" id="pageInfo"></div>
        <div class="page-btns" id="pageBtns"></div>
      </div>
      <div class="summary-strip" id="tableSummaryStrip">
  <div class="ss-item">Total: <strong id="ss-total"><?= count($allTickets) ?></strong></div>
  <div class="ss-item">Open: <strong id="ss-open"><?= $summary['open'] ?></strong></div>
  <div class="ss-item">In Progress: <strong id="ss-prog"><?= $summary['in_progress'] ?></strong></div>
  <div class="ss-item">Closed: <strong id="ss-closed"><?= $summary['closed'] ?></strong></div>
  <div class="ss-item">SLA Breached: <strong id="ss-breach" style="color:#DC2626"><?= $breaches ?></strong></div>
  <div class="ss-note">
    <span class="ss-note-icon">⏱</span>
    <span>
        <strong>Resolution Time</strong> — only shown for <strong>closed</strong> tickets. 
        Counts working hours (Mon–Fri 08:00–17:00) from ticket submission to final close. 
        Open or in-progress tickets show <code>—</code>.
    </span>
</div>
<div class="ss-note">
    <span class="ss-note-icon">💬</span>
    <span>
        <strong>Respond Time</strong> — shown for <strong>all</strong> tickets once staff responds. 
        Counts working hours from submission until staff first moved the ticket to in-progress or closed. 
        Shows <code>—</code> only if no staff action has been taken yet.
    </span>
</div>
<div class="ss-note">
    <span class="ss-note-icon">🛡</span>
    <span>
        <strong>SLA Breach</strong> — triggered when staff takes more than <strong>8 working hours</strong> 
        to first respond from ticket submission. 
        <span style="color:#DC2626;font-weight:700;">⚠ Breached</span> = response exceeded the 8-hour limit. 
        <span style="color:#16A34A;font-weight:700;">✓ OK</span> = responded within 8 working hours.
    </span>
</div>
</div>
    </div>

  </div><!-- /tab-tickets -->
  <?php endif; ?>


  <!-- ════════════════════════════════════════
       TAB: STAFF ACTIVITY
  ════════════════════════════════════════ -->
  <?php if ($activeTab === 'staff'): ?>
  <div class="tab-panel" id="tab-staff">

    

    <!-- ── KPI ROW ── -->
    <div class="chart-kpi-row kpi-above-chart">
      <div class="chart-kpi-box">
        <div class="chart-kpi-val" id="skpi-staff">—</div>
        <div class="chart-kpi-lbl">Total Staff</div>
      </div>
      <div class="chart-kpi-box" style="background:#F0FDF4;border-top-color:#16A34A;">
        <div class="chart-kpi-val" id="skpi-resolved" style="color:#16A34A;">—</div>
        <div class="chart-kpi-lbl" style="color:#16A34A;">Total Resolved</div>
      </div>
      <div class="chart-kpi-box" style="background:#EEF2FF;border-top-color:#6366F1;">
        <div class="chart-kpi-val" id="skpi-actions" style="color:#6366F1;">—</div>
        <div class="chart-kpi-lbl" style="color:#6366F1;">Tickets Assigned</div>
      </div>
      <div class="chart-kpi-box" style="background:#EFF6FF;border-top-color:#0EA5E9;">
        <div class="chart-kpi-val" id="skpi-avg-respond" style="color:#0EA5E9;">—</div>
        <div class="chart-kpi-lbl" style="color:#0EA5E9;">Avg Respond Time</div>
        <div style="font-size:.60rem;color:#94a3b8;margin-top:4px;line-height:1.4;">
          Total working hrs all tickets ÷ total tickets responded
        </div>
      </div>
    </div>

    <div class="chart-card">
      <div class="chart-card-header">
        <div>
          <h2 class="chart-title">Staff Performance Overview</h2>
          <div class="chart-subtitle-text" id="staffChartSubtitle">Showing all staff activity</div>
        </div>
        <div class="chart-legend-group">
          <span class="legend-pill"><span class="legend-dot" style="background:#16A34A"></span>Resolved (Closed)</span>
        </div>
      </div>
      <div id="staffChartWrap" style="position:relative; min-height:340px;">
        <canvas id="staffChart"></canvas>
      </div>
    </div>

    <div class="filter-panel">
      <div class="filter-panel-title">
        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M3 4a1 1 0 0 1 1-1h16a1 1 0 0 1 .8 1.6L14 12.4V20a1 1 0 0 1-1.45.9l-4-2A1 1 0 0 1 8 18v-5.6L3.2 5.6A1 1 0 0 1 3 4z"/></svg>
        Chart &amp; Table Filters
      </div>
      <div class="filter-panel-row">
        <div class="filter-group">
          <span class="filter-group-label">Analysis Period</span>
          <div class="period-select-wrap">
            <svg class="period-select-icon" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <select class="filter-select period-select" id="staffFilterPeriod">
              <option value="7">Last 7 days</option>
              <option value="30" selected>Last 30 days</option>
              <option value="60">Last 60 days</option>
              <option value="90">Last 90 days</option>
              <option value="180">Last 6 months</option>
              <option value="365">Last year</option>
              <option value="all">All time</option>
              <option value="custom">Custom date range</option>
            </select>
          </div>
        </div>
        <!-- ADD THESE TWO BLOCKS -->
        <div class="filter-group custom-range-group" id="staffCustomRangeFrom" style="display:none;">
          <span class="filter-group-label">From</span>
          <input type="date" class="filter-date-input" id="staffCustomDateFrom"/>
        </div>
        <div class="filter-group custom-range-group" id="staffCustomRangeTo" style="display:none;">
          <span class="filter-group-label">To</span>
          <input type="date" class="filter-date-input" id="staffCustomDateTo"/>
        </div>
        <div class="filter-group">
          <span class="filter-group-label">Staff Name</span>
          <select class="filter-select" id="staffFilterName">
            <option value="all">All Staff</option>
            <?php foreach ($staffActivity as $s): ?>
            <option value="<?= htmlspecialchars($s['full_name']) ?>">
              <?= htmlspecialchars($s['full_name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="filter-group" style="justify-content:flex-end;">
          <span class="filter-group-label">&nbsp;</span>
          <button class="filter-reset-btn" id="staffFilterResetBtn">
            <svg viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.5"/></svg>
            Reset Filters
          </button>
        </div>
      </div>
      <div id="staffActiveFilterSummary" class="active-filter-summary"></div>
    </div>

    <div class="table-card">
      <div class="table-card-header">
        <h2 class="table-title">Staff Activity</h2>
        <div class="table-actions">
          <input type="text" class="tbl-search tbl-search-fb" id="staffSearch" placeholder="Search staff…"/>
          <button class="dl-btn pdf" onclick="staffDownloadPDF()">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            Download PDF
          </button>
          <button class="dl-btn csv" onclick="staffDownloadCSV()">
            <svg viewBox="0 0 24 24"><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Download CSV
          </button>
        </div>
      </div>
      <div style="overflow-x:auto;">
        <table class="data-table" id="staffTable">
          <thead>
            <tr>
              <th onclick="sortStaffTable(0)">Rank <span class="sort-icon">⇅</span></th>
              <th onclick="sortStaffTable(1)">Staff Name <span class="sort-icon">⇅</span></th>
              <th>Role</th>
              <th onclick="sortStaffTable(3)">
                Tickets Assigned
                <span class="col-rate-note">via complaints.assigned_to</span>
                <span class="sort-icon">⇅</span>
              </th>
              <th onclick="sortStaffTable(4)">Resolved (Closed) <span class="sort-icon">⇅</span></th>
              <th onclick="sortStaffTable(5)">Status Breakdown <span class="sort-icon">⇅</span></th>
              <th onclick="sortStaffTable(6)">
                Resolution Rate
                <span class="col-rate-note">Closed ÷ Assigned × 100%</span>
                <span class="sort-icon">⇅</span>
              </th>
              <th onclick="sortStaffTable(7)">
                Avg Respond Time
                <span class="col-rate-note">Working hrs from open → first response</span>
                <span class="sort-icon">⇅</span>
              </th>
            </tr>
          </thead>
          <tbody id="staffTableBody">
            <?php if (empty($staffActivity)): ?>
            <tr><td colspan="8" class="empty-state">No staff activity found.</td></tr>
            <?php else: foreach ($staffActivity as $i => $s):
              $handled  = (int)$s['tickets_handled'];
              $resolved = (int)$s['resolved'];
              $inProg   = (int)$s['in_progress_count'];
              $openCnt  = (int)$s['open_count'];
              $rate     = $handled > 0 ? round($resolved / $handled * 100, 1) : 0;
              $rateCol  = $rate >= 70 ? '#16A34A' : ($rate >= 40 ? '#F97316' : '#DC2626');
            ?>
            <?php
              $staffId    = (int)$s['staff_id'];
              $rStats     = $staffRespondStats[$staffId] ?? ['responded_count' => 0, 'total_respond_mins' => 0];
              $avgRespondH = $rStats['responded_count'] > 0
                  ? round($rStats['total_respond_mins'] / $rStats['responded_count'] / 60, 1)
                  : null;
              $avgRespondCol = $avgRespondH === null ? '#94a3b8'
                  : ($avgRespondH <= 2 ? '#16A34A' : ($avgRespondH <= 6 ? '#F97316' : '#DC2626'));
              if ($avgRespondH === null) {
                  $avgRespondFmt = '—';
              } elseif ($avgRespondH < 1) {
                  $mins = (int)round($avgRespondH * 60);
                  $avgRespondFmt = ($mins === 0 ? '< 1m' : $mins . 'm');
              } else {
                  $wholeH = (int)floor($avgRespondH);
                  $remMin = (int)round(($avgRespondH - $wholeH) * 60);
                  $avgRespondFmt = $remMin > 0 ? $wholeH . 'h ' . $remMin . 'm' : $wholeH . 'h';
              }
            ?>
            <tr data-name="<?= htmlspecialchars($s['full_name']) ?>"
    data-code="<?= htmlspecialchars($s['staff_code']) ?>"
    data-role="<?= htmlspecialchars($s['role']) ?>"
    data-handled="<?= $handled ?>"
                data-resolved="<?= $resolved ?>"
                data-inprog="<?= $inProg ?>"
                data-open="<?= $openCnt ?>"
                data-rate="<?= $rate ?>"
                data-avgrespond="<?= $avgRespondH ?? '' ?>"
                data-avgrespond-fmt="<?= htmlspecialchars($avgRespondFmt) ?>"
data-rank="<?= $i+1 ?>">
              <td style="font-weight:700;color:#64748b;font-size:.88rem;"><?= $i+1 ?></td>
              <td style="font-weight:600;color:#0f172a;"><?= htmlspecialchars($s['full_name']) ?></td>
              <td>
                <?php if ($s['role'] === 'admin'): ?>
                  <span style="font-size:.72rem;font-weight:700;color:#574476;background:#F3F0F9;padding:2px 9px;border-radius:99px;border:1px solid #D4C8E8;">Admin</span>
                <?php else: ?>
                  <span style="font-size:.72rem;font-weight:700;color:#16A34A;background:#F0FDF4;padding:2px 9px;border-radius:99px;border:1px solid #BBF7D0;">Staff</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($handled > 0): ?>
                  <span style="font-weight:700;color:#6366F1;font-size:.95rem;"><?= $handled ?></span>
                  <span style="font-size:.72rem;color:#94a3b8;margin-left:3px;">ticket<?= $handled != 1 ? 's' : '' ?></span>
                <?php else: ?>
                  <span class="staff-none-note">No tickets assigned</span>
                <?php endif; ?>
              </td>
              <td>
                <span style="font-weight:700;color:#16A34A;font-size:.95rem;"><?= $resolved ?></span>
                <span style="font-size:.72rem;color:#94a3b8;margin-left:3px;">ticket<?= $resolved != 1 ? 's' : '' ?></span>
              </td>
              <td>
                <div class="staff-status-wrap">
                  <div class="staff-status-row">
                    <span class="staff-status-dot" style="background:#16A34A;"></span>
                    <span class="staff-status-val" style="color:#16A34A;"><?= $resolved ?></span>
                    <span class="staff-status-label">closed</span>
                  </div>
                  <div class="staff-status-row">
                    <span class="staff-status-dot" style="background:#6366F1;"></span>
                    <span class="staff-status-val" style="color:#6366F1;"><?= $inProg ?></span>
                    <span class="staff-status-label">in progress</span>
                  </div>
                  <div class="staff-status-row">
                    <span class="staff-status-dot" style="background:#F59E0B;"></span>
                    <span class="staff-status-val" style="color:#F59E0B;"><?= $openCnt ?></span>
                    <span class="staff-status-label">open</span>
                  </div>
                </div>
              </td>
              <td>
                <?php if ($handled > 0): ?>
                  <div style="display:flex;align-items:center;gap:.5rem;">
                    <div class="perf-bar-bg">
                      <div class="perf-bar-fill" style="width:<?= $rate ?>%;background:<?= $rateCol ?>;"></div>
                    </div>
                    <span style="font-size:.73rem;font-weight:700;color:<?= $rateCol ?>;"><?= $rate ?>%</span>
                  </div>
                <?php else: ?>
                  <span style="color:#94a3b8;font-size:.72rem;">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($avgRespondH !== null): ?>
                  <div style="display:flex;flex-direction:column;gap:3px;">
                    <span class="fr-badge <?= $avgRespondH <= 2 ? 'fr-fast' : ($avgRespondH <= 6 ? 'fr-ok' : 'fr-slow') ?>">
                      ⏱ <?= $avgRespondFmt ?>
                    </span>
                    <span style="font-size:.65rem;color:#94a3b8;"><?= $rStats['responded_count'] ?> ticket<?= $rStats['responded_count'] != 1 ? 's' : '' ?> responded</span>
                  </div>
                <?php else: ?>
                  <span style="color:#94a3b8;font-size:.72rem;">—</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <div class="pagination">
        <div class="page-info" id="staffPageInfo"></div>
        <div class="page-btns" id="staffPageBtns"></div>
      </div>
      <div class="summary-strip">
        <div class="ss-item">Staff Shown: <strong id="ss-staff-total">...</strong></div>
        <div class="ss-item">Total Assigned: <strong id="ss-staff-actions">...</strong></div>
        <div class="ss-item">Total Resolved: <strong id="ss-staff-resolved">...</strong></div>
        <div class="ss-item">Total In Progress: <strong id="ss-staff-inprog">...</strong></div>
        <div class="ss-item">Total Open: <strong id="ss-staff-open">...</strong></div>
        <div class="ss-note">
          <span class="ss-note-icon">🎫</span>
          <span>
              <strong>Tickets Assigned</strong> — count of tickets where <code>complaints.assigned_to</code> points to this staff member,
              within the selected period. Includes open, in-progress, and closed tickets.
          </span>
        </div>
        <div class="ss-note">
          <span class="ss-note-icon">✅</span>
          <span>
              <strong>Resolution Rate</strong> — percentage of assigned tickets that reached <strong>closed</strong> status.
              Formula: <code>Closed ÷ Tickets Assigned × 100%</code>.
              <span style="color:#16A34A;font-weight:700;">Green</span> ≥70%,
              <span style="color:#F97316;font-weight:700;">Orange</span> 40–69%,
              <span style="color:#DC2626;font-weight:700;">Red</span> &lt;40%.
          </span>
        </div>
        <div class="ss-note">
          <span class="ss-note-icon">⏱</span>
          <span>
              <strong>Avg Respond Time</strong> — average working hours from ticket submission until the staff member's
              first response (moved to in-progress or closed). Only tickets that have been responded to are counted.
              Working hours: Mon–Fri 08:00–17:00. ≤2h is fast, 2–6h is moderate, &gt;6h is slow.
          </span>
        </div>
        <div class="ss-note">
          <span class="ss-note-icon">📊</span>
          <span>
              <strong>Status Breakdown</strong> — shows how many of the staff member's assigned tickets are currently
              <strong>closed</strong>, <strong>in progress</strong>, or <strong>open</strong>, based on live ticket status (not historical).
          </span>
        </div>
      </div>
    </div>
  </div><!-- /tab-staff -->
  <?php endif; ?>


  <!-- ════════════════════════════════════════
       TAB: FEEDBACK
  ════════════════════════════════════════ -->
  <?php if ($activeTab === 'feedback'):
    $avgR        = (float)($feedbackStats['avg_rating']    ?? 0);
    $totF        = (int)  ($feedbackStats['total_feedback'] ?? 0);
    $pos         = (int)  ($feedbackStats['positive']       ?? 0);
    $neu         = (int)  ($feedbackStats['neutral']        ?? 0);
    $neg         = (int)  ($feedbackStats['negative']       ?? 0);
    $satP        = $totF > 0 ? round($pos / $totF * 100) : 0;
    $fiveS       = (int)($feedbackStats['five_star']  ?? 0);
    $fourS       = (int)($feedbackStats['four_star']  ?? 0);
    $threeS      = (int)($feedbackStats['three_star'] ?? 0);
    $twoS        = (int)($feedbackStats['two_star']   ?? 0);
    $oneS        = (int)($feedbackStats['one_star']   ?? 0);
    $autoCount   = (int)array_sum(array_column(
        array_filter($feedbackList, function($f) { return $f['is_auto_submitted']; }), 'is_auto_submitted'
    ));
    $manualCount = $totF - $autoCount;
    $ratingCol   = $avgR >= 4 ? '#16A34A' : ($avgR >= 3 ? '#D97706' : '#DC2626');

    $deptOptions = array_unique(array_filter(
        array_column($feedbackList, 'submitted_by_dept')
    ));
    sort($deptOptions);

    $faceColors = [
        5 => ['val' => '#15803D', 'bg' => '#F0FDF4', 'stroke' => '#16A34A'],
        4 => ['val' => '#16A34A', 'bg' => '#DCFCE7', 'stroke' => '#22C55E'],
        3 => ['val' => '#B45309', 'bg' => '#FEF9C3', 'stroke' => '#EAB308'],
        2 => ['val' => '#EA580C', 'bg' => '#FFEDD5', 'stroke' => '#F97316'],
        1 => ['val' => '#B91C1C', 'bg' => '#FEE2E2', 'stroke' => '#EF4444'],
    ];
    $faceLabels = [
        5 => 'Very Satisfied',
        4 => 'Satisfied',
        3 => 'Neutral',
        2 => 'Dissatisfied',
        1 => 'Very Dissatisfied',
    ];
    $faceCount = [5 => $fiveS, 4 => $fourS, 3 => $threeS, 2 => $twoS, 1 => $oneS];
  ?>
  <div class="tab-panel" id="tab-feedback">


    <!-- ══════════════════════════════════════════════
         KPI ROW — big number top, face icon + label at bottom
    ══════════════════════════════════════════════ -->
    <div class="chart-kpi-row kpi-above-chart fb-kpi-row">

      <!-- Total (no face) -->
      <div class="chart-kpi-box">
        <div class="chart-kpi-val"><?= $totF ?></div>
        <div class="chart-kpi-lbl">Total Feedback</div>
      </div>

      <!-- Per-rating KPI: number on top, face icon BESIDE label text at bottom -->
      <?php foreach ([5,4,3,2,1] as $starVal):
        $fc = $faceColors[$starVal];
      ?>
      <div class="chart-kpi-box" style="background:<?= $fc['bg'] ?>;border-top-color:<?= $fc['val'] ?>;">
        <div class="chart-kpi-val" style="color:<?= $fc['val'] ?>;"><?= $faceCount[$starVal] ?></div>
        <div class="chart-kpi-lbl" style="color:<?= $fc['val'] ?>;">
          <?= feedbackFaceSvg($starVal, 14) ?>&nbsp;<?= $starVal ?>&nbsp;<?= $faceLabels[$starVal] ?>
        </div>
      </div>
      <?php endforeach; ?>

    </div>

    <!-- ── Rating Distribution Chart ── -->
    <div class="fb2-chart-card">
      <div class="fb2-chart-header">
        <div>
          <h2 class="fb2-chart-title">Rating Distribution Overview</h2>
          <p class="fb2-chart-sub">
            Breakdown of all feedback responses by rating ·
            <?= $days ? 'Last '.$days.' days' : 'All time' ?>
          </p>
        </div>
        <div class="fb2-chart-legend">
          <?php foreach ([5,4,3,2,1] as $sv):
            $fc = $faceColors[$sv]; ?>
          <span class="fb2-legend-item">
            <?= feedbackFaceSvg($sv, 13) ?>
            <?= $sv ?> <?= $faceLabels[$sv] ?>
          </span>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="fb2-chart-wrap">
        <canvas id="fbRatingChart" role="img" aria-label="Horizontal bar chart showing feedback rating distribution"></canvas>
      </div>
      
    </div>

    <!-- ── Filters ── -->
    <div class="fb2-filter-panel">
      <div class="fb2-filter-title">
        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
          <path d="M3 4a1 1 0 0 1 1-1h16a1 1 0 0 1 .8 1.6L14 12.4V20a1 1 0 0 1-1.45.9l-4-2A1 1 0 0 1 8 18v-5.6L3.2 5.6A1 1 0 0 1 3 4z"/>
        </svg>
        Feedback Filters
      </div>
      <div class="fb2-filter-row">
        <!-- ADD THIS ENTIRE BLOCK — period selector -->
        <div class="fb2-filter-group">
          <span class="fb2-filter-label">Analysis Period</span>
          <div class="period-select-wrap">
            <svg class="period-select-icon" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <select class="fb2-filter-select period-select" id="fbFilterPeriod">
              <option value="7">Last 7 days</option>
              <option value="30" selected>Last 30 days</option>
              <option value="60">Last 60 days</option>
              <option value="90">Last 90 days</option>
              <option value="180">Last 6 months</option>
              <option value="365">Last year</option>
              <option value="all">All time</option>
              <option value="custom">Custom date range</option>
            </select>
          </div>
        </div>
        <div class="fb2-filter-group" id="fbCustomRangeFrom" style="display:none;">
          <span class="fb2-filter-label">From</span>
          <input type="date" class="filter-date-input" id="fbCustomDateFrom"/>
        </div>
        <div class="fb2-filter-group" id="fbCustomRangeTo" style="display:none;">
          <span class="fb2-filter-label">To</span>
          <input type="date" class="filter-date-input" id="fbCustomDateTo"/>
        </div>
        <div class="fb2-filter-group">
          <span class="fb2-filter-label">Rating</span>
          <select class="fb2-filter-select" id="fbFilterRating">
            <option value="all">All Ratings</option>
            <option value="5">5 — Very Satisfied</option>
            <option value="4">4 — Satisfied</option>
            <option value="3">3 — Neutral</option>
            <option value="2">2 — Dissatisfied</option>
            <option value="1">1 — Very Dissatisfied</option>
          </select>
        </div>
        <div class="fb2-filter-group">
          <span class="fb2-filter-label">Sentiment</span>
          <select class="fb2-filter-select" id="fbFilterSentiment">
            <option value="all">All Sentiments</option>
            <option value="Positive">Positive (4–5)</option>
            <option value="Neutral">Neutral (3)</option>
            <option value="Negative">Negative (1–2)</option>
          </select>
        </div>
        <div class="fb2-filter-group">
          <span class="fb2-filter-label">Dept / Faculty</span>
          <select class="fb2-filter-select" id="fbFilterDept">
            <option value="all">All Departments</option>
            <?php foreach ($deptOptions as $dept): ?>
            <option value="<?= htmlspecialchars($dept) ?>"><?= htmlspecialchars($dept) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fb2-filter-group">
          <span class="fb2-filter-label">Submission Type</span>
          <select class="fb2-filter-select" id="fbFilterType">
            <option value="all">All Types</option>
            <option value="Manual">Manual</option>
            <option value="Auto">Auto</option>
          </select>
        </div>
        <div class="fb2-filter-group" style="justify-content:flex-end;">
          <span class="fb2-filter-label">&nbsp;</span>
          <button class="fb2-filter-reset-btn" id="fbFilterResetBtn">
            <svg viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.5"/></svg>
            Reset Filters
          </button>
        </div>
      </div>
      <div id="fbActiveFilters" class="fb2-active-filters"></div>
    </div>

    <!-- ── Feedback Table ── -->
    <div class="fb2-table-card">
      <div class="fb2-table-header">
        <div>
          <h2 class="fb2-table-title">Feedback Records</h2>
          <p class="fb2-table-sub">All submitted feedback entries (students &amp; staff)</p>
        </div>
        <div class="fb2-table-actions">
          <input type="text" class="tbl-search" id="fbSearch" placeholder="Search feedback…"/>
          <button class="dl-btn pdf" onclick="fbDownloadPDF()">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            Download PDF
          </button>
          <button class="dl-btn csv" onclick="fbDownloadCSV()">
            <svg viewBox="0 0 24 24"><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Download CSV
          </button>
        </div>
      </div>

      <div style="overflow-x:auto;">
        <table class="fb2-formal-table" id="fbTable">
          <thead>
            <tr>
              <th onclick="fbSortTable(0)">Ticket ID <span class="sort-icon">⇅</span></th>
              <th onclick="fbSortTable(1)">Assigned Staff <span class="sort-icon">⇅</span></th>
              <th onclick="fbSortTable(2)">Category <span class="sort-icon">⇅</span></th>
              <th onclick="fbSortTable(3)">Submitted By <span class="sort-icon">⇅</span></th>
              <th onclick="fbSortTable(4)">Rating <span class="sort-icon">⇅</span></th>
              <th onclick="fbSortTable(5)">Sentiment <span class="sort-icon">⇅</span></th>
              <th onclick="fbSortTable(6)">Comment</th>
              <th onclick="fbSortTable(7)">Date <span class="sort-icon">⇅</span></th>
              <th onclick="fbSortTable(8)">Type <span class="sort-icon">⇅</span></th>
            </tr>
          </thead>
          <tbody id="fbTableBody">
            <?php if (empty($feedbackList)): ?>
            <tr><td colspan="8" class="empty-state">No feedback received for this period.</td></tr>
            <?php else: foreach ($feedbackList as $fb):
              $r         = (int)$fb['rating'];
              $sentiment = $r >= 4 ? 'Positive' : ($r === 3 ? 'Neutral' : 'Negative');
              $sentClass = $r >= 4 ? 'fb2-sent-pos' : ($r === 3 ? 'fb2-sent-neu' : 'fb2-sent-neg');
              $dateFmt   = date('d M Y', strtotime($fb['created_at']));
              $typeLabel = $fb['is_auto_submitted'] ? 'Auto' : 'Manual';
              $rlabel    = $faceLabels[$r] ?? '';
              $fc        = $faceColors[$r] ?? ['val'=>'#334155','bg'=>'#f8fafc'];
            ?>
            <?php
              $r         = (int)$fb['rating'];
              $sentiment = $r >= 4 ? 'Positive' : ($r === 3 ? 'Neutral' : 'Negative');
              $sentClass = $r >= 4 ? 'fb2-sent-pos' : ($r === 3 ? 'fb2-sent-neu' : 'fb2-sent-neg');
              $dateFmt   = date('d M Y', strtotime($fb['created_at']));
              $typeLabel = $fb['is_auto_submitted'] ? 'Auto' : 'Manual';
              $rlabel    = $faceLabels[$r] ?? '';
              $fc        = $faceColors[$r] ?? ['val'=>'#334155','bg'=>'#f8fafc'];
              $catShort  = explode(' / ', $fb['category_name'])[1] ?? $fb['category_name'];
            ?>
            <tr data-ticket-id="<?= htmlspecialchars($fb['ticket_id']) ?>"
                data-assigned="<?= htmlspecialchars($fb['assigned_staff_name']) ?>"
                data-category="<?= htmlspecialchars($catShort) ?>"
                data-submitted-by="<?= htmlspecialchars($fb['submitted_by_dept'] ?? '') ?>"
                data-rating="<?= $r ?>"
                data-rating-label="<?= htmlspecialchars($rlabel) ?>"
                data-sentiment="<?= $sentiment ?>"
                data-comment="<?= htmlspecialchars($fb['comment'] ?? '') ?>"
                data-date="<?= $dateFmt ?>"
                data-date-ts="<?= strtotime($fb['created_at']) ?>"
                data-type="<?= $typeLabel ?>">
              <td><span class="ticket-id"><?= htmlspecialchars($fb['ticket_id']) ?></span></td>
              <td style="font-size:.82rem;font-weight:600;color:#334155;">
                <?= htmlspecialchars($fb['assigned_staff_name']) ?>
              </td>
              <td style="font-size:.82rem;color:#475569;"><?= htmlspecialchars($catShort) ?></td>
              <td style="font-size:.82rem;color:#334155;"><?= htmlspecialchars($fb['submitted_by_dept'] ?? '—') ?></td>
              <td>
                <div class="fb-rating-cell">
                  <div class="fb-rating-top">
                    <span class="fb-rating-num" style="color:<?= $fc['val'] ?>;"><?= $r ?></span>
                    <span class="fb-rating-denom">/5</span>
                  </div>
                  <span class="fb-rating-label-text" style="color:<?= $fc['val'] ?>;"><?= $rlabel ?></span>
                  <div class="fb-face-row">
                    <?php for ($fi = 1; $fi <= 5; $fi++): ?>
                      <span class="fb-face-item <?= $fi === $r ? 'fb-face-active' : 'fb-face-dim' ?>">
                        <?= feedbackFaceSvg($fi, 14) ?>
                      </span>
                    <?php endfor; ?>
                  </div>
                </div>
              </td>
              <td><span class="fb2-sent-badge <?= $sentClass ?>"><?= $sentiment ?></span></td>
              <td>
                <?php if (!empty($fb['comment'])): ?>
                  <span class="fb2-comment" title="<?= htmlspecialchars($fb['comment']) ?>"><?= htmlspecialchars($fb['comment']) ?></span>
                <?php else: ?>
                  <span class="fb2-comment-empty">—</span>
                <?php endif; ?>
              </td>
              <td class="fb2-td-date"><?= $dateFmt ?></td>
              <td>
                <?php if ($fb['is_auto_submitted']): ?>
                  <span class="fb2-auto-badge">Auto</span>
                <?php else: ?>
                  <span class="fb2-manual-badge">Manual</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <div class="pagination">
        <div class="page-info" id="fbPageInfo"></div>
        <div class="page-btns" id="fbPageBtns"></div>
      </div>

      <div class="fb2-summary-strip">
        <span>Total: <strong id="fbss-total"><?= count($feedbackList) ?></strong></span>
        <span class="fb2-divider">|</span>
        <span style="color:#16A34A;">Positive: <strong id="fbss-pos"><?= $pos ?></strong>
          <span style="font-size:.70rem;font-weight:400;color:#94a3b8;">(Very Satisfied + Satisfied)</span>
        </span>
        <span class="fb2-divider">|</span>
        <span style="color:#D97706;">Neutral: <strong id="fbss-neu"><?= $neu ?></strong>
          <span style="font-size:.70rem;font-weight:400;color:#94a3b8;">(Neutral)</span>
        </span>
        <span class="fb2-divider">|</span>
        <span style="color:#DC2626;">Negative: <strong id="fbss-neg"><?= $neg ?></strong>
          <span style="font-size:.70rem;font-weight:400;color:#94a3b8;">(Dissatisfied + Very Dissatisfied)</span>
        </span>
        <span class="fb2-divider">|</span>
        <span>Manual: <strong id="fbss-manual"><?= $manualCount ?></strong></span>
        <span class="fb2-divider">|</span>
        <span>Auto: <strong id="fbss-auto"><?= $autoCount ?></strong></span>
      </div>
    </div>

  </div><!-- /tab-feedback -->
  <?php endif; ?>


  <!-- ════════════════════════════════════════
       TAB: CATEGORY
  ════════════════════════════════════════ -->
  <?php if ($activeTab === 'category'): ?>
  <div class="tab-panel" id="tab-category">

    <?php
      $totCat = array_sum(array_column($categoryStats,'total'));
      $overallRate = $totCat > 0
        ? round(array_sum(array_column($categoryStats,'closed')) / $totCat * 100, 1)
        : 0;
      $catPeriodLabels = [
        '7'   => 'Last 7 days',
        '30'  => 'Last 30 days',
        '60'  => 'Last 60 days',
        '90'  => 'Last 90 days',
        '180' => 'Last 6 months',
        '365' => 'Last year',
      ];
      $catPeriodLabel = $catPeriodLabels[$catRange] ?? 'All time';
      $rateColor = $overallRate >= 70 ? '#16A34A' : ($overallRate >= 40 ? '#F97316' : '#DC2626');
    ?>

    

    <?php
  // Kira Rate by Tickets
  $rateByTickets = $totCat > 0
    ? round(array_sum(array_column($categoryStats,'closed')) / $totCat * 100, 1)
    : 0;
  $rateTicketColor = $rateByTickets >= 70 ? '#16A34A' : ($rateByTickets >= 40 ? '#F97316' : '#DC2626');

  // Kira Rate by Category Average
  $catRates = array_map(function($cat) {
    return $cat['total'] > 0
      ? round($cat['closed'] / $cat['total'] * 100, 1)
      : 0;  // zero-ticket categories count as 0%
}, $categoryStats);
$rateByCategory = count($catRates) > 0
    ? round(array_sum($catRates) / count($catRates), 1)
    : 0;
  $rateCatColor = $rateByCategory >= 70 ? '#16A34A' : ($rateByCategory >= 40 ? '#F97316' : '#DC2626');
?>

<div class="chart-kpi-row kpi-above-chart" style="grid-template-columns:repeat(7,1fr);">

  <div class="chart-kpi-box">
    <div class="chart-kpi-val" id="catkpi-cats"><?= count($categoryStats) ?></div>
<div class="chart-kpi-lbl">Active Categories</div>
  </div>  <!-- ← THIS closing div was missing -->

  <div class="chart-kpi-box">
    <div class="chart-kpi-val" id="catkpi-total"><?= $totCat ?></div>
    <div class="chart-kpi-lbl">Total Tickets</div>
  </div>
  <div class="chart-kpi-box" style="background:#EEF2FF;border-top-color:#6366f1;">
    <div class="chart-kpi-val" id="catkpi-open" style="color:#6366f1;"><?= array_sum(array_column($categoryStats,'open')) ?></div>
    <div class="chart-kpi-lbl" style="color:#6366f1;">Open</div>
  </div>
  <div class="chart-kpi-box" style="background:#FFFBEB;border-top-color:#F59E0B;">
    <div class="chart-kpi-val" id="catkpi-inprog" style="color:#F59E0B;"><?= array_sum(array_column($categoryStats,'in_progress')) ?></div>
    <div class="chart-kpi-lbl" style="color:#F59E0B;">In Progress</div>
  </div>
  <div class="chart-kpi-box" style="background:#F0FDF4;border-top-color:#16A34A;">
    <div class="chart-kpi-val" id="catkpi-closed" style="color:#16A34A;"><?= array_sum(array_column($categoryStats,'closed')) ?></div>
    <div class="chart-kpi-lbl" style="color:#16A34A;">Closed</div>
  </div>

  <!-- Rate by Total Tickets -->
  <div class="chart-kpi-box" style="background:#F8F7FC;border-top-color:#574476;">
    <div class="chart-kpi-val" id="catkpi-rate-tickets" style="color:<?= $rateTicketColor ?>;"><?= $rateByTickets ?>%</div>
    <div class="chart-kpi-lbl" style="color:#574476;">
      Resolution Rate
      <span style="display:block;font-size:.6rem;color:#b0a1c8;margin-top:2px;">Closed ÷ Total Tickets x 100</span>
    </div>
  </div>

  <!-- Rate by Category Average (BARU) -->
  <div class="chart-kpi-box" style="background:#FFF7ED;border-top-color:#F97316;">
    <div class="chart-kpi-val" id="catkpi-rate-category" style="color:<?= $rateCatColor ?>;"><?= $rateByCategory ?>%</div>
    <div class="chart-kpi-lbl" style="color:#F97316;">
      Resolution Rate
      <span style="display:block;font-size:.6rem;color:#fdba74;margin-top:2px;">Avg per Category</span>
    </div>
  </div>
</div>

    <div class="chart-card">
      <div class="chart-card-header">
        <div>
          <h2 class="chart-title">📂 Tickets by Category</h2>
          <div class="chart-subtitle-text" id="catChartSubtitle">
            <?= count($categoryStats) ?> categories · <?= $totCat ?> tickets · <?= $catPeriodLabel ?>
          </div>
        </div>
        <div class="chart-legend-group">
          <span class="legend-pill"><span class="legend-dot" style="background:rgba(99,102,241,.80)"></span>Open</span>
          <span class="legend-pill"><span class="legend-dot" style="background:rgba(22,163,74,.80)"></span>Closed</span>
        </div>
      </div>
      <div id="catChartWrap" style="position:relative; min-height:<?= max(200, count($categoryStats) * 52 + 80) ?>px;">
        <canvas id="categoryChart"></canvas>
      </div>
    </div>

    <div class="filter-panel">
      <div class="filter-panel-title">
        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M3 4a1 1 0 0 1 1-1h16a1 1 0 0 1 .8 1.6L14 12.4V20a1 1 0 0 1-1.45.9l-4-2A1 1 0 0 1 8 18v-5.6L3.2 5.6A1 1 0 0 1 3 4z"/></svg>
        Chart &amp; Table Filters
      </div>
      <div class="filter-panel-row">
        <div class="filter-group">
          <span class="filter-group-label">Analysis Period</span>
          <div class="period-select-wrap">
            <svg class="period-select-icon" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <select class="filter-select period-select" id="catPeriodSelect">
  <option value="7"   <?= $catRange==='7'   ?'selected':'' ?>>Last 7 days</option>
  <option value="30"  <?= $catRange==='30'  ?'selected':'' ?>>Last 30 days</option>
  <option value="60"  <?= $catRange==='60'  ?'selected':'' ?>>Last 60 days</option>
  <option value="90"  <?= $catRange==='90'  ?'selected':'' ?>>Last 90 days</option>
  <option value="180" <?= $catRange==='180' ?'selected':'' ?>>Last 6 months</option>
  <option value="365" <?= $catRange==='365' ?'selected':'' ?>>Last year</option>
  <option value="all" <?= $catRange==='all' ?'selected':'' ?>>All time</option>
  <option value="custom">Custom date range</option>
</select>
          </div>
        </div>
        <div class="filter-group custom-range-group" id="catCustomRangeFrom" style="display:none;">
          <span class="filter-group-label">From</span>
          <input type="date" class="filter-date-input" id="catCustomDateFrom"/>
        </div>
        <div class="filter-group custom-range-group" id="catCustomRangeTo" style="display:none;">
          <span class="filter-group-label">To</span>
          <input type="date" class="filter-date-input" id="catCustomDateTo"/>
        </div>

        <div class="filter-group">
          <span class="filter-group-label">Status</span>
          <select class="filter-select" id="catFilterStatus">
            <option value="all">All Statuses</option>
            <option value="open">Has Open Tickets</option>
            <option value="closed">Has Closed Tickets</option>
          </select>
        </div>
        <div class="filter-group" style="justify-content:flex-end;">
          <span class="filter-group-label">&nbsp;</span>
          <button class="filter-reset-btn" id="catFilterResetBtn">
            <svg viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.5"/></svg>
            Reset Filters
          </button>
        </div>
      </div>
      <div id="catActiveFilterSummary" class="active-filter-summary" style="display:none;"></div>
    </div>

    <div class="table-card">
      <div class="table-card-header">
        <h2 class="table-title">📊 Category Breakdown</h2>
        <div class="table-actions">
          <input type="text" class="tbl-search tbl-search-fb" id="catSearch" placeholder="Search categories…"/>
          <button class="dl-btn pdf" onclick="catDownloadPDF()">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            Download PDF
          </button>
          <button class="dl-btn csv" onclick="catDownloadCSV()">
            <svg viewBox="0 0 24 24"><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Download CSV
          </button>
        </div>
      </div>

      <div style="overflow-x:auto;">
        <table class="data-table" id="catTable">
          <thead>
            <tr>
              <th onclick="sortCatTable(0)">Category <span class="sort-icon">⇅</span></th>
              <th onclick="sortCatTable(1)">Total <span class="sort-icon">⇅</span></th>
              <th onclick="sortCatTable(2)">Open <span class="sort-icon">⇅</span></th>
              <th onclick="sortCatTable(3)">In Progress <span class="sort-icon">⇅</span></th>
              <th onclick="sortCatTable(4)">Closed <span class="sort-icon">⇅</span></th>
              <th onclick="sortCatTable(5)">High Priority <span class="sort-icon">⇅</span></th>
              <th onclick="sortCatTable(6)">
                Resolution Rate
                <span class="col-rate-note">Closed ÷ Total × 100%</span>
                <span class="sort-icon">⇅</span>
              </th>
            </tr>
          </thead>
          <tbody id="catTableBody">
            <?php if(empty($categoryStats)): ?>
            <tr><td colspan="7" class="empty-state">No category data found.</td></tr>
            <?php else: foreach ($categoryStats as $cat):
    $cS  = explode(' / ', $cat['category_name'])[1] ?? $cat['category_name'];
    $rR  = (float)($cat['resolution_rate'] ?? 0);   // ← add ?? 0
    $rCol = $rR >= 70 ? '#16A34A' : ($rR >= 40 ? '#F97316' : '#DC2626');
    $inP  = (int)($cat['in_progress'] ?? 0);
            ?>
            <?php $inP = (int)($cat['in_progress'] ?? 0); ?>
    <tr data-category="<?= htmlspecialchars($cS) ?>"
        data-total="<?= (int)$cat['total'] ?>"
        data-open="<?= (int)$cat['open'] ?>"
        data-inprogress="<?= $inP ?>"
        data-closed="<?= (int)$cat['closed'] ?>"
                data-high="<?= (int)$cat['high'] ?>"
                data-avg-age="<?= $cat['avg_age_h'] ?? 0 ?>"
                data-rate="<?= $rR ?>">
              <td style="font-weight:600;color:#334155;"><?= htmlspecialchars($cS) ?></td>
              <td><strong><?= $cat['total'] ?></strong></td>
              <td><span class="status-pill sp-open"><?= $cat['open'] ?></span></td>
              <td>
                <?php if ($inP > 0): ?>
                  <span class="status-pill sp-in_progress"><?= $inP ?></span>
                <?php else: ?>
                  <span style="color:#94a3b8;">—</span>
                <?php endif; ?>
              </td>
              <td><span class="status-pill sp-closed"><?= $cat['closed'] ?></span></td>
              <td><?= $cat['high']>0?'<span class="priority-pill pp-high">'.$cat['high'].' high</span>':'<span style="color:#94a3b8;">—</span>' ?></td>
              <td>
                <div style="display:flex;align-items:center;gap:.5rem;">
                  <div class="perf-bar-bg"><div class="perf-bar-fill" style="width:<?= $rR ?>%;background:<?= $rCol ?>;"></div></div>
                  <span style="font-size:.73rem;font-weight:700;color:<?= $rCol ?>;"><?= $rR ?>%</span>
                </div>
              </td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <div class="pagination">
        <div class="page-info" id="catPageInfo"></div>
        <div class="page-btns" id="catPageBtns"></div>
      </div>

     <div class="summary-strip">
  <div class="ss-item">Categories: <strong id="catss-cats"><?= count($categoryStats) ?></strong></div>
  <div class="ss-item">Total Tickets: <strong id="catss-total"><?= $totCat ?></strong></div>
  <div class="ss-item">Open: <strong id="catss-open" style="color:#6366f1;"><?= array_sum(array_column($categoryStats,'open')) ?></strong></div>
  <div class="ss-item">In Progress: <strong id="catss-inprog" style="color:#F59E0B;"><?= array_sum(array_column($categoryStats,'in_progress')) ?></strong></div>
  <div class="ss-item">Closed: <strong id="catss-closed" style="color:#16A34A;"><?= array_sum(array_column($categoryStats,'closed')) ?></strong></div>

  <!-- Rate by Tickets -->
  <div class="ss-item">
    Rate (By Tickets): <strong id="catss-rate-tickets" style="color:<?= $rateTicketColor ?>;"><?= $rateByTickets ?>%</strong>
    <span class="ss-rate-note">(<span id="catcalc-closed"><?= array_sum(array_column($categoryStats,'closed')) ?></span> closed ÷ <span id="catcalc-total"><?= $totCat ?></span> total × 100)</span>
  </div>

  <!-- Rate by Category Average (BARU) -->
  <div class="ss-item">
    Rate (By Category Avg): <strong id="catss-rate-category" style="color:<?= $rateCatColor ?>;"><?= $rateByCategory ?>%</strong>
    <span class="ss-rate-note">(<?= implode(' + ', array_map(function($r) { return $r.'%'; }, $catRates)) ?>) ÷ <?= count($catRates) ?> categories</span>
  </div>
</div>

    </div>

  </div><!-- /tab-category -->
  <?php endif; ?>

</main>

<?php include '_foot_scripts.php'; ?>

<!-- ════════════════════════════════════════
     DATA INJECTION + SCRIPT LOADING
════════════════════════════════════════ -->

<?php if ($activeTab === 'tickets'): ?>
<script>
window.TICKET_DATA = {
  allTickets: <?= json_encode(array_map(function($t) {
    return [
      'ticket_id'            => $t['ticket_id'],
      'category'             => (explode(' / ', $t['category_name'])[1] ?? $t['category_name']),
      'status'               => $t['status'],
      'priority'             => $t['priority'],
      'created_at'           => $t['created_at'],
      'submitted_ts'         => strtotime($t['created_at']),
      'is_breached'          => (int)$t['is_breached'],
      'resolution_hours'    => $t['resolution_hours'],
'respond_hours'       => $t['respond_hours'] ?? null,
'assigned_staff_name' => $t['assigned_staff_name'] ?? null,
    ];
  }, $allTickets)) ?>,
  summary:  { total:<?= (int)$summary['total'] ?>, open:<?= (int)$summary['open'] ?>, in_progress:<?= (int)$summary['in_progress'] ?>, closed:<?= (int)$summary['closed'] ?> },
  breaches: <?= (int)$breaches ?>,
  avgHours: <?= $avgHours ? (float)$avgHours : 'null' ?>
};
</script>
<script src="js/tickets_reportt.js"></script>
<?php endif; ?>

<?php if ($activeTab === 'staff'): ?>
<script>
window.STAFF_DATA = {
  period: '<?= $days ?? 'all' ?>',
  staff: <?= json_encode(array_map(function($s) use ($staffRespondStats) {
    $handled  = (int)$s['tickets_handled'];
    $resolved = (int)$s['resolved'];
    $rate     = $handled > 0 ? round($resolved / $handled * 100, 1) : 0;
    $sId    = (int)$s['staff_id'];
    $rSt    = $staffRespondStats[$sId] ?? ['responded_count' => 0, 'total_respond_mins' => 0];
    return [
      'full_name'        => $s['full_name'],
      'staff_code'       => $s['staff_code'],
      'role'             => $s['role'],
      'tickets_handled'  => $handled,
      'resolved'         => $resolved,
      'in_progress_count'=> (int)$s['in_progress_count'],
      'open_count'       => (int)$s['open_count'],
      'resolution_rate'  => $rate,
      'avg_respond_h'    => $rSt['responded_count'] > 0
          ? round($rSt['total_respond_mins'] / $rSt['responded_count'] / 60, 1)
          : null,
      'responded_count'  => $rSt['responded_count'],
      'avg_resolution_h' => $s['avg_resolution_h'],
    ];
  }, $staffActivity)) ?>
};
</script>
<script src="js/staff_report.js"></script>
<?php endif; ?>

<?php if ($activeTab === 'feedback'): ?>
<script>
window.FEEDBACK_DATA = {
  pos:         <?= $pos ?>,
  neu:         <?= $neu ?>,
  neg:         <?= $neg ?>,
  totF:        <?= $totF ?>,
  fiveS:       <?= $fiveS ?>,
  fourS:       <?= $fourS ?>,
  threeS:      <?= $threeS ?>,
  twoS:        <?= $twoS ?>,
  oneS:        <?= $oneS ?>,
  periodLabel: <?= json_encode($days ? 'Last '.$days.' days' : 'All time') ?>
};
</script>
<script src="js/feedbacks_report.js"></script>
<?php endif; ?>

<?php if ($activeTab === 'category' && !empty($categoryStats)): ?>
<script>
window.CATEGORY_DATA = {
  labels:     <?= json_encode(array_map(function($c) { return explode(' / ', $c['category_name'])[1] ?? $c['category_name']; }, $categoryStats)) ?>,
  open:       <?= json_encode(array_map('intval', array_column($categoryStats, 'open'))) ?>,
  inProgress: <?= json_encode(array_map('intval', array_column($categoryStats, 'in_progress'))) ?>,
  closed:     <?= json_encode(array_map('intval', array_column($categoryStats, 'closed'))) ?>,
  periodLabel: <?= json_encode($catPeriodLabel) ?>
};
</script>
<script src="js/category-report.js"></script>
<?php endif; ?>

</body>
</html>