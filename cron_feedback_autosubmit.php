<?php
// uniKL/complaint/cron_feedback_autosubmit.php
// Run every hour via crontab:
//   0 * * * * php /var/www/html/uniKL/complaint/cron_feedback_autosubmit.php

require __DIR__ . '/db_connect.php';

define('SLA_TZ', 'Asia/Kuala_Lumpur');

$tz        = new DateTimeZone(SLA_TZ);
$now       = new DateTime('now', $tz);
$threshold = 8 * 3600; // 8 office hours in seconds

// Find all closed tickets with no feedback yet
$stmt = $conn->query("
    SELECT c.ticket_id,
           c.submitter_id,
           c.submitter_type,
           COALESCE(c.resolved_at, c.updated_at, c.created_at) AS closed_at
    FROM complaints c
    LEFT JOIN ticket_feedback tf
           ON tf.ticket_id      = c.ticket_id
          AND tf.submitter_id   = c.submitter_id
          AND tf.submitter_type = c.submitter_type
    WHERE c.status        = 'closed'
      AND tf.feedback_id  IS NULL
");

if (!$stmt) {
    echo "[cron] Query failed: " . $conn->error . "\n";
    exit(1);
}

$ins = $conn->prepare("
    INSERT IGNORE INTO ticket_feedback
        (ticket_id, submitter_id, submitter_type, rating, comment, is_auto_submitted, created_at)
    VALUES (?, ?, ?, 5, '', 1, NOW())
");

$autoCount = 0;

while ($row = $stmt->fetch_assoc()) {
    $closedAt = new DateTime($row['closed_at'], $tz);
    $elapsed  = officeHoursElapsed($closedAt, $now, 8, 17);

    if ($elapsed >= $threshold) {
        $ins->bind_param("sis",
            $row['ticket_id'],
            $row['submitter_id'],
            $row['submitter_type']
        );
        $ins->execute();

        if ($conn->affected_rows > 0) {
            $autoCount++;
            echo "[cron] Auto-submitted 5/5 for ticket {$row['ticket_id']}\n";
        }
    }
}

$ins->close();
echo "[cron] Done. Auto-submitted: {$autoCount} ticket(s).\n";


// ── Helpers (same logic as feedback_api.php) ──────────────────────────────────

function officeHoursElapsed(DateTime $start, DateTime $end, int $dayStart, int $dayEnd): int {
    if ($end <= $start) return 0;

    $elapsed = 0;
    $cursor  = clone $start;
    $cursor  = clampToOffice($cursor, $dayStart, $dayEnd);

    while ($cursor < $end) {
        $dow = (int)$cursor->format('N');
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