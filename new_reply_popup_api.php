<?php
// uniKL/complaint/new_reply_popup_api.php
// Polls for: (1) new staff replies, (2) status changes on student's tickets.

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

$studentId = (int)($_SESSION['user_id'] ?? 0);
if ($studentId <= 0) {
    echo json_encode(['has_new' => false]);
    exit;
}

require_once __DIR__ . '/db_connect.php';

// We track two "last seen" IDs — one for replies, one for status log entries
$lastReplyId  = (int)($_SESSION['last_popup_reply_id_'  . $studentId] ?? 0);
$lastLogId    = (int)($_SESSION['last_popup_log_id_'    . $studentId] ?? 0);

// ── ACTION: mark seen ─────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'mark') {
    if (isset($_GET['reply_id'])) {
        $rid = (int)$_GET['reply_id'];
        if ($rid > $lastReplyId) {
            $_SESSION['last_popup_reply_id_' . $studentId] = $rid;
        }
    }
    if (isset($_GET['log_id'])) {
        $lid = (int)$_GET['log_id'];
        if ($lid > $lastLogId) {
            $_SESSION['last_popup_log_id_' . $studentId] = $lid;
        }
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ── CHECK 1: New staff reply ──────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT
        tr.reply_id,
        tr.ticket_id,
        tr.sender_name,
        tr.message,
        tr.created_at,
        c.title AS ticket_title
    FROM ticket_replies tr
    INNER JOIN complaints c ON c.ticket_id = tr.ticket_id
    WHERE c.submitter_id   = ?
      AND c.submitter_type = 'student'
      AND tr.sender_role   = 'staff'
      AND tr.reply_id      > ?
    ORDER BY tr.reply_id DESC
    LIMIT 1
");
$stmt->bind_param("ii", $studentId, $lastReplyId);
$stmt->execute();
$reply = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ── CHECK 2: New status change on student's tickets ───────────────────────────
$stmt = $conn->prepare("
    SELECT
        tl.log_id,
        tl.ticket_id,
        tl.changed_by       AS sender_name,
        tl.old_status,
        tl.new_status,
        tl.changed_at       AS created_at,
        c.title             AS ticket_title
    FROM ticket_logs tl
    INNER JOIN complaints c ON c.ticket_id = tl.ticket_id
    WHERE c.submitter_id   = ?
      AND c.submitter_type = 'student'
      AND tl.field_changed = 'status'
      AND tl.new_status   != tl.old_status
      AND tl.new_status   IS NOT NULL
      AND tl.old_status   IS NOT NULL
      AND tl.log_id        > ?
      AND tl.changed_by   != ''
    ORDER BY tl.log_id DESC
    LIMIT 1
");
$stmt->bind_param("ii", $studentId, $lastLogId);
$stmt->execute();
$statusChange = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ── Decide which event to surface (most recent wins) ─────────────────────────
// If both exist, show whichever happened later.
$showReply  = !empty($reply);
$showStatus = !empty($statusChange);

if (!$showReply && !$showStatus) {
    echo json_encode(['has_new' => false]);
    exit;
}

if ($showReply && $showStatus) {
    // Compare by actual timestamp — most recent event wins
    $useReply = strtotime($reply['created_at']) >= strtotime($statusChange['created_at']);
} else {
    $useReply = $showReply;
}

if ($useReply) {
    echo json_encode([
        'has_new'      => true,
        'type'         => 'reply',
        'reply_id'     => (int)$reply['reply_id'],
        'ticket_id'    => $reply['ticket_id'],
        'ticket_title' => $reply['ticket_title'],
        'sender_name'  => $reply['sender_name'],
        'message'      => $reply['message'],
        'created_at'   => $reply['created_at'],
    ]);
} else {
    // Format a human-readable status label
    $labels = [
        'open'        => 'Open',
        'in_progress' => 'In Progress',
        'closed'      => 'Closed',
    ];
    $oldLabel = $labels[$statusChange['old_status']] ?? $statusChange['old_status'];
    $newLabel = $labels[$statusChange['new_status']] ?? $statusChange['new_status'];

    echo json_encode([
        'has_new'      => true,
        'type'         => 'status',
        'log_id'       => (int)$statusChange['log_id'],
        'ticket_id'    => $statusChange['ticket_id'],
        'ticket_title' => $statusChange['ticket_title'],
        'sender_name'  => $statusChange['sender_name'],
        'old_status'   => $statusChange['old_status'],
        'new_status'   => $statusChange['new_status'],
        'message'      => 'Status changed from ' . $oldLabel . ' to ' . $newLabel,
        'created_at'   => $statusChange['created_at'],
    ]);
}