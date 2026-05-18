<?php
// uniKL/complaint/feedback_api.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// ── Auth guard ────────────────────────────────────────────────────────────────
$submitterId   = (int)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? 0);
$submitterType = ($_SESSION['user_role'] ?? '') === 'student' ? 'student' : 'staff';
$submitterId   = (int)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? 0);

if ($submitterId <= 0) {
    echo json_encode(['error' => 'unauthenticated']);
    exit;
}

// PHP 8.2: ensure $submitterType is never null in bind_param
$submitterType = (string)$submitterType;

require __DIR__ . '/db_connect.php';

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: check
// Finds ALL closed tickets with no feedback.
// - Expired (>= 8 office hours): silently auto-submit 5/5 server-side, no popup.
// - Not yet expired: return the oldest one so the popup shows with a countdown.
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'check') {

    $tz          = new DateTimeZone('Asia/Kuala_Lumpur');
    $now         = new DateTime('now', $tz);
    $officeStart = 8;
    $officeEnd   = 17;
    $threshold   = 8 * 3600; // 8 office hours in seconds

    // Fetch ALL closed tickets with no feedback for this student, oldest first
$stmt = $conn->prepare("
    SELECT c.ticket_id, c.title,
           tl.changed_at AS closed_at
    FROM complaints c
    JOIN ticket_logs tl ON tl.ticket_id = c.ticket_id
        AND tl.new_status = 'closed'
        AND tl.log_id = (
            SELECT MAX(tl2.log_id)
            FROM ticket_logs tl2
            WHERE tl2.ticket_id = c.ticket_id
              AND tl2.new_status = 'closed'
        )
    LEFT JOIN ticket_feedback tf ON tf.ticket_id = c.ticket_id
                                 AND tf.submitter_id = ?
    WHERE c.submitter_id   = ?
      AND c.submitter_type = ?
      AND c.status         = 'closed'
      AND tf.feedback_id   IS NULL
    ORDER BY tl.changed_at ASC
");
$stmt->bind_param("iis", $submitterId, $submitterId, $submitterType);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($rows)) {
        echo json_encode(['pending' => false, 'pending_count' => 0]);
        exit;
    }

    // ── NEW: Re-verify each ticket is genuinely still 'closed' right now.
    // Guards against race conditions where a ticket was closed then re-opened
    // between page loads.
    $rows = array_filter($rows, function($row) use ($conn) {
        $chk = $conn->prepare(
            "SELECT ticket_id FROM complaints WHERE ticket_id = ? AND status = 'closed' LIMIT 1"
        );
        $chk->bind_param("s", $row['ticket_id']);
        $chk->execute();
        $found = $chk->get_result()->fetch_assoc();
        $chk->close();
        return $found !== null;
    });
    $rows = array_values($rows);

    if (empty($rows)) {
        echo json_encode(['pending' => false, 'pending_count' => 0]);
        exit;
    }

    // Separate expired vs still-within-window tickets
    $expiredTickets = [];
    $pendingTickets = [];

    foreach ($rows as $row) {
        $closedAt = new DateTime($row['closed_at'], $tz);
        $elapsed  = officeHoursElapsed($closedAt, $now, $officeStart, $officeEnd);

        if ($elapsed >= $threshold) {
            $expiredTickets[] = $row;
        } else {
            $pendingTickets[] = array_merge($row, [
                'remaining_secs' => (int)($threshold - $elapsed),
                'elapsed_secs'   => (int)$elapsed,
            ]);
        }
    }

    // Silently auto-submit 5/5 for ALL expired tickets (no popup ever shown)
    if (!empty($expiredTickets)) {
$ins = $conn->prepare("
    INSERT IGNORE INTO ticket_feedback
        (ticket_id, submitter_id, rating, comment, is_auto_submitted, created_at)
    VALUES (?, ?, 5, '', 1, NOW())
");
foreach ($expiredTickets as $expired) {
    $ins->bind_param("si", $expired['ticket_id'], $submitterId);
            $ins->execute();
        }
        $ins->close();
    }

    // If there are still non-expired tickets, return the oldest for the popup
    if (!empty($pendingTickets)) {
        $oldest       = $pendingTickets[0];
        $pendingCount = count($pendingTickets);

        echo json_encode([
            'pending'        => true,
            'pending_count'  => $pendingCount,
            'ticket_id'      => $oldest['ticket_id'],
            'ticket_title'   => $oldest['title'],
            'closed_at'      => $oldest['closed_at'],
            'auto_ready'     => false,
            'remaining_secs' => $oldest['remaining_secs'],
            'elapsed_secs'   => $oldest['elapsed_secs'],
        ]);
        exit;
    }

    // All tickets were expired and silently submitted → no popup needed
    echo json_encode([
        'pending'        => false,
        'pending_count'  => 0,
        'auto_submitted' => count($expiredTickets),
    ]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: submit
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'submit' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $ticketId = trim($_POST['ticket_id'] ?? '');
    $rating   = (int)($_POST['rating']    ?? 0);
    $comment  = trim($_POST['comment']    ?? '');
    $isAuto   = (string)($_POST['auto'] ?? '0') === '1';

    if (empty($ticketId) || $rating < 1 || $rating > 5) {
        echo json_encode(['success' => false, 'error' => 'invalid_input']);
        exit;
    }

// Confirm ticket is closed (ownership check loosened for staff)
$check = $conn->prepare("
    SELECT ticket_id FROM complaints
    WHERE ticket_id = ? AND submitter_id = ? AND submitter_type = ? AND status = 'closed'
    LIMIT 1
");
$check->bind_param("sis", $ticketId, $submitterId, $submitterType);
$check->execute();
$valid = $check->get_result()->fetch_assoc();
$check->close();

if (!$valid) {
    echo json_encode(['success' => false, 'error' => 'ticket_not_found_or_not_closed']);
    exit;
}

    // Prevent duplicate feedback
    $dup = $conn->prepare("SELECT feedback_id FROM ticket_feedback WHERE ticket_id = ? AND submitter_id = ? LIMIT 1");
    $dup->bind_param("si", $ticketId, $submitterId);
    $dup->execute();
    $existing = $dup->get_result()->fetch_assoc();
    $dup->close();

    if ($existing) {
        echo json_encode(['success' => true, 'duplicate' => true]);
        exit;
    }

    // Insert feedback
    $ins = $conn->prepare("
        INSERT INTO ticket_feedback (ticket_id, submitter_id, rating, comment, is_auto_submitted, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $autoInt  = $isAuto ? 1 : 0;
    $comment  = (string)$comment;   // PHP 8.2: ensure not null
    $rating   = (int)$rating;
    $autoInt  = (int)$autoInt;
    $ins->bind_param("siisi", $ticketId, $submitterId, $rating, $comment, $autoInt);

    if ($ins->execute()) {
        $ins->close();

        // Count remaining non-expired tickets that still need popup feedback
        $tz          = new DateTimeZone('Asia/Kuala_Lumpur');
        $now         = new DateTime('now', $tz);
        $threshold   = 8 * 3600;
        $officeStart = 8;
        $officeEnd   = 17;

$remainStmt = $conn->prepare("
    SELECT c.ticket_id, tl.changed_at AS closed_at
    FROM complaints c
    JOIN ticket_logs tl ON tl.ticket_id = c.ticket_id
        AND tl.new_status = 'closed'
        AND tl.log_id = (
            SELECT MAX(tl2.log_id)
            FROM ticket_logs tl2
            WHERE tl2.ticket_id = c.ticket_id
              AND tl2.new_status = 'closed'
        )
    LEFT JOIN ticket_feedback tf ON tf.ticket_id = c.ticket_id
                                 AND tf.submitter_id = ?
    WHERE c.submitter_id   = ?
      AND c.submitter_type = ?
      AND c.status         = 'closed'
      AND tf.feedback_id   IS NULL
");
$remainStmt->bind_param("iis", $submitterId, $submitterId, $submitterType);
        $remainStmt->execute();
        $remainRows = $remainStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $remainStmt->close();

        // Only count tickets still within the window (expired handled on next check)
        $remainingCount = 0;
        foreach ($remainRows as $r) {
            $closedAt = new DateTime($r['closed_at'], $tz);
            $elapsed  = officeHoursElapsed($closedAt, $now, $officeStart, $officeEnd);
            if ($elapsed < $threshold) {
                $remainingCount++;
            }
        }

        echo json_encode([
            'success'         => true,
            'remaining_count' => $remainingCount,
        ]);
    } else {
        $ins->close();
        echo json_encode(['success' => false, 'error' => 'db_error']);
    }
    exit;
}

// Unknown action
echo json_encode(['error' => 'unknown_action']);
exit;

// ══════════════════════════════════════════════════════════════════════════════
// Helper: count elapsed office-hour seconds between two DateTimes
// Office hours: Mon–Fri 08:00–17:00 MYT
// ══════════════════════════════════════════════════════════════════════════════
function officeHoursElapsed(DateTime $start, DateTime $end, int $dayStart, int $dayEnd): int {
    if ($end <= $start) return 0;

    $elapsed = 0;
    $cursor  = clone $start;
    $cursor  = clampToOffice($cursor, $dayStart, $dayEnd);

    while ($cursor < $end) {
        $dow = (int)$cursor->format('N'); // 1=Mon … 7=Sun
        if ($dow >= 1 && $dow <= 5) {
            $todayClose = clone $cursor;
            $todayClose->setTime($dayEnd, 0, 0);

            $periodEnd = ($end < $todayClose) ? $end : $todayClose;
            $diff      = $periodEnd->getTimestamp() - $cursor->getTimestamp();
            if ($diff > 0) $elapsed += $diff;
        }
        $cursor->modify('+1 day');
        $cursor->setTime($dayStart, 0, 0);
    }
    return $elapsed;
}

function clampToOffice(DateTime $dt, int $dayStart, int $dayEnd): DateTime {
    $h = (int)$dt->format('H');
    if ($h < $dayStart) {
        $dt->setTime($dayStart, 0, 0);
    } elseif ($h >= $dayEnd) {
        $dt->modify('+1 day');
        $dt->setTime($dayStart, 0, 0);
        while ((int)$dt->format('N') > 5) {
            $dt->modify('+1 day');
        }
    }
    return $dt;
}