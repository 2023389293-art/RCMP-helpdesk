<?php
// dept/it/staff_notifications_api.php  
// Returns JSON: { notifications: [...] }
//
// Notification types:
//   - assigned_to_me  : tickets where assigned_to = $staffId (active open/in_progress)
//   - assignment      : when another staff member assigns a ticket to me (from ticket_logs)
//   - sla_alert       : tickets assigned to me that are overdue OR due within 1 hour

 
require_once __DIR__ . '/../auth_guard.php';
require_once __DIR__ . '/../../db_connect.php';

header('Content-Type: application/json');

date_default_timezone_set('Asia/Kuala_Lumpur'); // ← ADD THIS LINE

$deptId  = (int)($deptId  ?? $_SESSION['dept_id']  ?? 0);
$staffId = (int)($staffId ?? $_SESSION['staff_id'] ?? 0);

if (!$staffId || !$deptId) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorised']);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// SLA HELPERS
// SLA = 8 business hours (Mon–Fri, 08:00–17:00 local server time)
// ─────────────────────────────────────────────────────────────────────────────
function advanceToBusinessTime(DateTime $dt): DateTime
{
    $d = clone $dt;

    // Loop until we land on a valid business moment
    while (true) {
        $dow  = (int)$d->format('N'); // 1=Mon … 7=Sun
        $hour = (int)$d->format('G');
        $min  = (int)$d->format('i');
        $sec  = (int)$d->format('s');

        // Weekend → jump to next Monday 08:00
        if ($dow >= 6) {
            $daysToAdd = 8 - $dow; // Sat→2, Sun→1
            $d->modify("+{$daysToAdd} days");
            $d->setTime(8, 0, 0);
            continue;
        }

        // Weekday before 08:00 → snap to 08:00
        if ($hour < 8) {
            $d->setTime(8, 0, 0);
            break;
        }

        // Weekday at or after 17:00 → next business day 08:00
        if ($hour >= 17) {
            $d->modify('+1 day');
            $d->setTime(8, 0, 0);
            continue; // re-check (might be Friday → Saturday)
        }

        // Valid business moment
        break;
    }

    return $d;
}

function businessMinutesElapsed(DateTime $start, DateTime $now): int
{
    // Snap the start to the next valid business moment
    $cur   = advanceToBusinessTime(clone $start);
    $total = 0;

    // If ticket hasn't even reached business time yet, elapsed = 0
    if ($cur >= $now) {
        return 0;
    }

    while ($cur < $now) {
        $dow = (int)$cur->format('N');

        // Safety: skip weekends (shouldn't happen after advanceToBusinessTime,
        // but guards against edge cases in the loop)
        if ($dow >= 6) {
            $cur->modify('+1 day');
            $cur->setTime(8, 0, 0);
            continue;
        }

        // End of this business day segment
        $dayEnd = clone $cur;
        $dayEnd->setTime(17, 0, 0);

        // Count up to whichever comes first: $now or end of business day
        $segEnd = ($now < $dayEnd) ? $now : $dayEnd;

        if ($cur < $segEnd) {
            $total += (int)(($segEnd->getTimestamp() - $cur->getTimestamp()) / 60);
        }

        // If we haven't reached $now yet, move to next business day
        if ($segEnd >= $dayEnd) {
            $cur->modify('+1 day');
            $cur->setTime(8, 0, 0);

            // Skip weekends
            while ((int)$cur->format('N') >= 6) {
                $cur->modify('+1 day');
            }
        } else {
            // $now fell within today's business hours — we're done
            break;
        }
    }

    return $total;
}

// SLA thresholds (business minutes)
define('SLA_TOTAL_MIN',   8 * 60);   // 480 min = 8 business hours  → OVERDUE
define('SLA_WARNING_MIN', 7 * 60);   // 420 min = 7 hours elapsed   → 1 hour warning (due_soon)

$notifications = [];
$now = new DateTime();


// ─────────────────────────────────────────────────────────────────────────────
// 1. TICKETS ASSIGNED TO ME
//    Shows all open/in_progress tickets where assigned_to = $staffId
//    Fetched from complaints table directly.
// ─────────────────────────────────────────────────────────────────────────────
$sql = "
    SELECT
    c.ticket_id,
    c.title         AS ticket_title,
    c.created_at,
    c.sla_start_at,
    c.status,
        c.priority,
        -- Who submitted the ticket
        COALESCE(c.submitter_name, 'Unknown') AS submitter_name,
        c.submitter_type   AS sender_role,
        -- When was the ticket last assigned (i.e. the log where assigned_to changed to me)
        -- We'll use the ticket_logs changed_at for event_at where possible
        (
            SELECT MAX(tl.changed_at)
            FROM ticket_logs tl
            WHERE tl.ticket_id    = c.ticket_id
              AND tl.new_status   = 'open'
        ) AS last_opened_at,
        -- Grab the name of whoever last assigned this ticket (from staff table via assigned_to)
        assigner.full_name AS assigned_by_name
    FROM complaints c
    -- Self-join to get the assigner's name (assigned_to stores the staff_id of the assignee,
    -- but we want who did the assigning — that's in ticket_logs.changed_by_id)
    LEFT JOIN staff assigner ON assigner.staff_id = (
        SELECT tl2.changed_by_id
        FROM ticket_logs tl2
        WHERE tl2.ticket_id    = c.ticket_id
          AND tl2.field_changed = 'assigned'
        ORDER BY tl2.changed_at DESC
        LIMIT 1
    )
    WHERE c.dept_id    = ?
      AND c.assigned_to = ?
      AND c.status     IN ('open', 'in_progress')
    ORDER BY c.created_at DESC
    LIMIT 50
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $deptId, $staffId);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $slaStart   = new DateTime($row['created_at']);
$elapsedMin = businessMinutesElapsed($slaStart, $now);

    // Only add as a plain "assigned" notification if it's NOT already going
    // to appear as an SLA alert — we'll handle SLA separately below.
    // (We still include it here so the "All" tab always shows it.)
    $notifications[] = [
        'notif_key'    => 'MY-' . $row['ticket_id'],
        'notif_type'   => 'assigned_to_me',
        'ticket_id'    => $row['ticket_id'],
        'ticket_title' => $row['ticket_title'],
        'sender_name'  => 'New Ticket',
        'sender_role'  => $row['sender_role'],
        'message'      => 'Ticket assigned to you — ' . ucfirst($row['status']),
        'event_at'     => $row['created_at'],
        'status'       => $row['status'],
        'priority'     => $row['priority'],
        'elapsed_min'  => $elapsedMin,
        'sla_min'      => SLA_TOTAL_MIN,
        'remaining_min'=> SLA_TOTAL_MIN - $elapsedMin,
    ];
}
$stmt->close();


// ─────────────────────────────────────────────────────────────────────────────
// 2. ASSIGNMENT NOTIFICATIONS
//    When any OTHER staff member assigns a ticket to ME.
//    We look at ticket_logs for field_changed = 'assigned' where the new
//    assigned value points to $staffId, last 30 days.
//
//    NOTE: Your current ticket_logs schema (from the PDF) stores:
//      changed_by_id, changed_by (name), field_changed, old_status, new_status
//    It does NOT have an explicit "new_assigned_id" column in the dump shown.
//
//    Two strategies:
//      A) If you add a new_assigned_id column to ticket_logs — ideal.
//      B) Cross-reference: look at complaints.assigned_to = $staffId and
//         find logs where field_changed = 'assigned' (if you log it).
//
//    Since your current schema only shows status/priority changes in field_changed,
//    we use a practical fallback:
//      — Find tickets where assigned_to = $staffId AND the assigner ≠ $staffId
//        (i.e. someone else set it), limited to last 30 days by created_at.
//      — This catches initial assignments made by others.
//
//    When you add an 'assigned' log type, replace the query below with a proper
//    ticket_logs join on new_assigned_id = $staffId.
// ─────────────────────────────────────────────────────────────────────────────
$sql = "
    SELECT
    c.ticket_id,
    c.title            AS ticket_title,
    c.created_at,
    c.sla_start_at,
    c.status,
        c.priority,
        COALESCE(c.submitter_name, 'Unknown') AS submitter_name,
        c.submitter_type AS sender_role,
        -- The person who last changed this ticket (assigner)
        assigner.full_name AS assigner_name,
        assigner.staff_id  AS assigner_id,
        -- When the assignment log was recorded (latest status-change log as proxy)
        (
            SELECT MAX(tl.changed_at)
            FROM ticket_logs tl
            WHERE tl.ticket_id = c.ticket_id
        ) AS last_log_at
    FROM complaints c
    -- Assigner = whoever last touched this ticket (from logs)
    LEFT JOIN staff assigner ON assigner.staff_id = (
        SELECT tl2.changed_by_id
        FROM ticket_logs tl2
        WHERE tl2.ticket_id = c.ticket_id
        ORDER BY tl2.changed_at DESC
        LIMIT 1
    )
    WHERE c.dept_id     = ?
  AND c.assigned_to = ?
  AND (assigner.staff_id IS NULL OR assigner.staff_id != ?)
  AND c.created_at >= NOW() - INTERVAL 30 DAY
  AND EXISTS (
      SELECT 1 FROM ticket_logs tl3
      WHERE tl3.ticket_id    = c.ticket_id
        AND tl3.field_changed = 'assigned'
        AND tl3.changed_by_id != 0
  )
    ORDER BY c.created_at DESC
    LIMIT 30
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iii", $deptId, $staffId, $staffId);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $assigner = $row['assigner_name'] ?? 'System';  // keep for message only
    $eventAt  = $row['last_log_at']   ?? $row['created_at'];

    // Fetch remarks from the latest 'assigned' log for this ticket
        $remarksStmt = $conn->prepare("
            SELECT remarks FROM ticket_logs
            WHERE ticket_id = ? AND field_changed = 'assigned'
            ORDER BY changed_at DESC LIMIT 1
        ");
        $remarksStmt->bind_param("s", $row['ticket_id']);
        $remarksStmt->execute();
        $remarksRow  = $remarksStmt->get_result()->fetch_assoc();
        $remarksStmt->close();
        $assignRemarks = $remarksRow['remarks'] ?? '';

        $notifications[] = [
        'notif_key'    => 'ASGN-' . $row['ticket_id'],
        'notif_type'   => 'assignment',
        'ticket_id'    => $row['ticket_id'],
        'ticket_title' => $row['ticket_title'],
        'sender_name'  => 'New Ticket',
        'sender_role'  => 'staff',
        'message' => $assigner . ' assigned this ticket to you',
        'remarks'      => $assignRemarks,
        'event_at'     => $eventAt,
        'status'       => $row['status'],
        'priority'     => $row['priority'],
        'elapsed_min'  => null,
        'sla_min'      => SLA_TOTAL_MIN,
        'remaining_min'=> null,
    ];
}
$stmt->close();


// ─────────────────────────────────────────────────────────────────────────────
// 3. SLA ALERTS — for tickets assigned to ME
//    Overdue  : elapsed >= 8 business hours
//    Due Soon : elapsed >= 7 business hours (1 hour before breach)
//
//    SLA clock starts from the LAST TIME the ticket became 'open'
//    (most recent ticket_logs row where new_status = 'open').
//    Falls back to complaints.created_at if no such log exists.
// ─────────────────────────────────────────────────────────────────────────────
$sql = "
    SELECT
    c.ticket_id,
    c.title         AS ticket_title,
    c.created_at,
    c.sla_start_at,
    c.status,
        c.priority,
        -- Submitter (for context, kept but not used as sender_name for SLA)
        COALESCE(c.submitter_name, 'Unknown') AS submitter_name,
        c.submitter_type AS sender_role,
        -- ✅ NEW: the assigned staff's name
        assigned_staff.full_name AS assigned_staff_name,
        (
            SELECT MAX(tl.changed_at)
            FROM ticket_logs tl
            WHERE tl.ticket_id  = c.ticket_id
              AND tl.new_status = 'open'
        ) AS last_opened_at
    FROM complaints c
    LEFT JOIN staff assigned_staff ON assigned_staff.staff_id = c.assigned_to   -- ✅ NEW join
    WHERE c.dept_id     = ?
  AND c.assigned_to = ?
  AND c.status      IN ('open', 'in_progress')
  AND c.first_response_at IS NULL
ORDER BY c.created_at ASC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $deptId, $staffId);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    // ✅ Use sla_start_at — it is reset when a ticket is reopened.
    // This matches the logic in ticket_detail.php and sla_helper.php.
   $slaStartStr  = $row['created_at'];
$slaStart     = new DateTime($slaStartStr);
$elapsedMin   = businessMinutesElapsed($slaStart, $now);
$remainingMin = SLA_TOTAL_MIN - $elapsedMin;

    if ($elapsedMin >= SLA_TOTAL_MIN) {
        // ── OVERDUE ──
        $overdueMin = $elapsedMin - SLA_TOTAL_MIN;
        $hrs  = floor($overdueMin / 60);
        $mins = $overdueMin % 60;
        $label = $hrs > 0
            ? "Overdue by {$hrs}h" . ($mins > 0 ? " {$mins}m" : "")
            : "Overdue by {$mins}m";

        // In the OVERDUE block — change sender_name:
$notifications[] = [
    'notif_key'    => 'OD-' . $row['ticket_id'],
    'notif_type'   => 'sla_alert',
    'sla_severity' => 'overdue',
    'ticket_id'    => $row['ticket_id'],
    'ticket_title' => $row['ticket_title'],
    'sender_name'  => $row['assigned_staff_name'] ?? 'Unknown Staff',  // ✅ CHANGED
    'sender_role'  => 'staff',                                          // ✅ CHANGED
    'message'      => trim($label) . ' — SLA breached (8 business hours) · ' . ucfirst($row['status']),
    'event_at'     => $slaStartStr,
    'status'       => $row['status'],
    'priority'     => $row['priority'],
    'elapsed_min'  => $elapsedMin,
    'sla_min'      => SLA_TOTAL_MIN,
    'remaining_min'=> $remainingMin,
];


    } elseif ($elapsedMin >= SLA_WARNING_MIN) {
        // ── DUE SOON (≤ 1 hour remaining) ──
        $remHrs  = floor($remainingMin / 60);
        $remMins = $remainingMin % 60;
        $label = $remHrs > 0
            ? "Due in {$remHrs}h" . ($remMins > 0 ? " {$remMins}m" : "")
            : "Due in {$remMins}m";

        $notifications[] = [
    'notif_key'    => 'DS-' . $row['ticket_id'],
    'notif_type'   => 'sla_alert',
    'sla_severity' => 'due_soon',
    'ticket_id'    => $row['ticket_id'],
    'ticket_title' => $row['ticket_title'],
    'sender_name'  => $row['assigned_staff_name'] ?? 'Unknown Staff',  // ✅ CHANGED
    'sender_role'  => 'staff',                                          // ✅ CHANGED
    'message'      => trim($label) . ' — SLA deadline approaching (1 hour left) · ' . ucfirst($row['status']),
    'event_at'     => $slaStartStr,
    'status'       => $row['status'],
    'priority'     => $row['priority'],
    'elapsed_min'  => $elapsedMin,
    'sla_min'      => SLA_TOTAL_MIN,
    'remaining_min'=> $remainingMin,
];
    }
    // tickets with < 7 hrs elapsed are fine — no SLA notification
}
$stmt->close();


// ─────────────────────────────────────────────────────────────────────────────
// DE-DUPLICATE by notif_key
// (A ticket that is both "assigned_to_me" AND has an SLA alert will appear
//  as BOTH entries — one in the My Tickets tab and one in the Deadlines tab.
//  The notif_key keeps them unique so the frontend can render both.)
// ─────────────────────────────────────────────────────────────────────────────
$seen = [];
$deduped = [];
foreach ($notifications as $n) {
    $key = $n['notif_key'];
    if (!isset($seen[$key])) {
        $seen[$key]  = true;
        $deduped[]   = $n;
    }
}
$notifications = $deduped;


// ─────────────────────────────────────────────────────────────────────────────
// SORT
//   1. sla_alert / overdue  (worst first, most elapsed first within severity)
//   2. sla_alert / due_soon
//   3. assignment           (newest first)
//   4. assigned_to_me       (newest first)
// ─────────────────────────────────────────────────────────────────────────────
$typePriority = [
    'sla_alert'    => 0,
    'assignment'   => 1,
    'assigned_to_me' => 2,
];
$severityOrder = ['overdue' => 0, 'due_soon' => 1];

usort($notifications, function ($a, $b) use ($typePriority, $severityOrder) {
    $pa = $typePriority[$a['notif_type']] ?? 99;
    $pb = $typePriority[$b['notif_type']] ?? 99;
    if ($pa !== $pb) return $pa - $pb;

    if ($a['notif_type'] === 'sla_alert') {
        $sa = $severityOrder[$a['sla_severity'] ?? ''] ?? 99;
        $sb = $severityOrder[$b['sla_severity'] ?? ''] ?? 99;
        if ($sa !== $sb) return $sa - $sb;
        // Within same severity: most elapsed first
        return ($b['elapsed_min'] ?? 0) - ($a['elapsed_min'] ?? 0);
    }

    // assignment / assigned_to_me: newest first
    return strcmp($b['event_at'] ?? '', $a['event_at'] ?? '');
});

// Cap at 60 results
$notifications = array_slice($notifications, 0, 60);

echo json_encode(['notifications' => $notifications], JSON_UNESCAPED_UNICODE);