<?php
// complaint/notifications_api.php 
session_start();

// ── Auth guard ────────────────────────────────────────────────
if (empty($_SESSION['user_id']) || empty($_SESSION['user_role'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$allowedRoles = ['user', 'lecturer', 'dept_handler', 'admin', 'super_admin', 'report_viewer', 'staff'];
if (!in_array($_SESSION['user_role'], $allowedRoles)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

require '../db_connect.php';

$userId        = (int)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? 0);
$userRole      = $_SESSION['user_role'];
$submitterType = ($userRole === 'user') ? 'user' : 'staff';

$notifications = [];

// ── 1. Staff REPLIES on this user's tickets ───────────────────
$sql1 = "
    SELECT
        r.reply_id,
        'reply'        AS notif_type,
        r.ticket_id,
        c.title        AS ticket_title,
        r.sender_name,
        r.message,
        r.created_at
    FROM ticket_replies r
    INNER JOIN complaints c
        ON  c.ticket_id      = r.ticket_id
        AND c.submitter_id   = ?
        AND c.submitter_type = ?
    WHERE r.sender_id != ?
    ORDER BY r.reply_id DESC
    LIMIT 20
";
$stmt1 = $conn->prepare($sql1);
if ($stmt1) {
    $stmt1->bind_param("iis", $userId, $userId, $submitterType);
    $stmt1->execute();
    $rows1 = $stmt1->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt1->close();

    foreach ($rows1 as $r) {
        $notifications[] = [
            'reply_id'     => (int)$r['reply_id'],
            'notif_type'   => 'reply',
            'ticket_id'    => $r['ticket_id'],
            'ticket_title' => $r['ticket_title'],
            'sender_name'  => $r['sender_name'],
            'message'      => $r['message'],
            'created_at'   => $r['created_at'],
        ];
    }
}

// ── 2. STATUS CHANGES on this user's tickets ──────────────────
// ticket_logs columns from DB: log_id, ticket_id, changed_by_id, changed_by,
// field_changed, old_priority, new_priority, old_status, new_status, changed_at
$sql2 = "
    SELECT
        tl.log_id,
        tl.ticket_id,
        c.title                                              AS ticket_title,
        COALESCE(NULLIF(tl.changed_by, ''), 'System')       AS sender_name,
        tl.new_status,
        tl.changed_at                                        AS created_at
    FROM ticket_logs tl
    INNER JOIN complaints c
        ON  c.ticket_id      = tl.ticket_id
        AND c.submitter_id   = ?
        AND c.submitter_type = ?
    WHERE tl.field_changed = 'status'
      AND tl.new_status IN ('open', 'in_progress', 'closed')
      AND tl.old_status != tl.new_status
    ORDER BY tl.log_id DESC
    LIMIT 20
";
$stmt2 = $conn->prepare($sql2);
if ($stmt2) {
    $stmt2->bind_param("is", $userId, $submitterType);
    $stmt2->execute();
    $rows2 = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt2->close();

    $statusLabels = [
        'open'        => 'Re-Opened',
        'in_progress' => 'In Progress',
        'closed'      => 'Closed',
    ];
    $statusIcons = [
        'open'        => '🔓',
        'in_progress' => '⏳',
        'closed'      => '✅',
    ];

    foreach ($rows2 as $l) {
        $label = $statusLabels[$l['new_status']] ?? ucfirst($l['new_status']);
        $icon  = $statusIcons[$l['new_status']]  ?? '•';
        $notifications[] = [
            // Offset log IDs by 100000 so they never clash with reply IDs
            // for the unread-badge tracking in localStorage
            'reply_id'     => (int)$l['log_id'] + 100000,
            'notif_type'   => 'status',
            'new_status'   => $l['new_status'],
            'ticket_id'    => $l['ticket_id'],
            'ticket_title' => $l['ticket_title'],
            'sender_name'  => $l['sender_name'],
            'message'      => $icon . ' Ticket marked as ' . $label,
            'created_at'   => $l['created_at'],
        ];
    }
}

// ── 3. Sort merged list newest-first, cap at 25 ───────────────
usort($notifications, function($a, $b) {
    return strcmp($b['created_at'], $a['created_at']);
});
$notifications = array_slice($notifications, 0, 25);

header('Content-Type: application/json');
echo json_encode(['notifications' => $notifications], JSON_UNESCAPED_UNICODE);
exit;