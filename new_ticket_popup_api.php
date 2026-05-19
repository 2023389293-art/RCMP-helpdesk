<?php
// FILE LOCATION: uniKL/complaint/new_ticket_popup_api.php  (popup for staff)
// (same folder as db_connect.php)
 
$sessionPath = __DIR__ . '/sessions';
if (!is_dir($sessionPath)) {
    mkdir($sessionPath, 0755, true);
}
session_save_path($sessionPath);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');
 
// ── Auth guard ────────────────────────────────────────────────────────────────
$staffId = (int)($_SESSION['staff_id'] ?? 0);
if ($staffId <= 0) {
    echo json_encode(['has_new' => false, 'error' => 'unauthenticated']);
    exit;
}

require_once __DIR__ . '/db_connect.php';

// ── Session cursor key ────────────────────────────────────────────────────────
// We store the last seen log_id in SESSION so it persists across pages
// but resets on logout (new login = fresh session = fresh cursor)
$sessionKey    = 'ntt_cursor_' . $staffId;

// ── ACTION: mark ──────────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'mark') {
    $markLogId = (int)($_GET['log_id'] ?? 0);
    if ($markLogId > (int)($_SESSION[$sessionKey] ?? 0)) {
        $_SESSION[$sessionKey] = $markLogId;
    }
    echo json_encode(['ok' => true, 'cursor' => $_SESSION[$sessionKey]]);
    exit;
}

// ── Get staff full name + dept ────────────────────────────────────────────────
$nameStmt = $conn->prepare("SELECT full_name, dept_id FROM staff WHERE staff_id = ? LIMIT 1");
$nameStmt->bind_param("i", $staffId);
$nameStmt->execute();
$staffRow = $nameStmt->get_result()->fetch_assoc();
$nameStmt->close();

if (!$staffRow) {
    echo json_encode(['has_new' => false, 'error' => 'staff_not_found']);
    exit;
}

$staffFullName = $staffRow['full_name'];
$deptId        = (int)$staffRow['dept_id'];

// ── Get current cursor ────────────────────────────────────────────────────────
$lastSeenLogId = (int)($_SESSION[$sessionKey] ?? -1);

// ── If cursor not set yet (-1), set it to max-1 so the LATEST ticket shows ───
// This means on first load, the most recent assigned ticket will popup once
if ($lastSeenLogId === -1) {
    $_SESSION[$sessionKey] = 0;
    $lastSeenLogId = 0;
}

// ── Find newest assignment log for this staff above cursor ────────────────────
$stmt = $conn->prepare("
    SELECT
        tl.log_id,
        tl.ticket_id,
        tl.changed_at,
        tl.changed_by                              AS assigned_by,
        COALESCE(tl.remarks, '')                   AS remarks,
        c.title                                    AS ticket_title,
        c.priority,
        cat.category_name,
        CASE
            WHEN c.submitter_type = 'student' THEN s.full_name
            WHEN c.submitter_type = 'staff'   THEN sf2.full_name
            ELSE 'Unknown'
        END                                        AS submitter_name,
        c.submitter_type
    FROM ticket_logs tl
    INNER JOIN complaints c
        ON  c.ticket_id = tl.ticket_id
        AND c.dept_id   = ?
    INNER JOIN categories cat
        ON cat.category_id = c.category_id
    LEFT JOIN students s
        ON  s.student_id     = c.submitter_id
        AND c.submitter_type = 'student'
    LEFT JOIN staff sf2
        ON  sf2.staff_id     = c.submitter_id
        AND c.submitter_type = 'staff'
    WHERE tl.field_changed = 'assigned'
  AND tl.new_priority  = ?
  AND tl.log_id        > ?
  AND c.status         = 'open'
    ORDER BY tl.log_id ASC
    LIMIT 10
");
$stmt->bind_param("isi", $deptId, $staffFullName, $lastSeenLogId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (empty($rows)) {
    echo json_encode([
        'has_new' => false,
        'cursor'  => $lastSeenLogId,
        'staff'   => $staffFullName,
    ]);
    exit;
}

// Advance cursor to the highest log_id seen so same toasts never show twice
$_SESSION[$sessionKey] = (int)end($rows)['log_id'];

$tickets = array_map(function($row) {
    return [
        'log_id'         => (int)$row['log_id'],
        'ticket_id'      => $row['ticket_id'],
        'ticket_title'   => $row['ticket_title'],
        'category'       => $row['category_name'],
        'priority'       => $row['priority'],
        'submitter'      => $row['submitter_name'],
        'submitter_type' => $row['submitter_type'],
        'assigned_by'    => $row['assigned_by'],
        'remarks'        => $row['remarks'],
        'created_at'     => $row['changed_at'],
    ];
}, $rows);

echo json_encode([
    'has_new' => true,
    'tickets' => $tickets,
]);