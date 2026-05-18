<?php
// assign_helper.php    
// Place in project ROOT (same folder as db_connect.php)
 
/**
 * Auto-assign a ticket to staff based on category match.
 * If no staff matches the category, falls back to round-robin.
 * Returns the staff_id assigned, or 0 if no active staff found.
 */
function autoAssignTicket(mysqli $conn, int $deptId, string $ticketId): int
{
    // 1. Get category info for this ticket
    $catStmt = $conn->prepare("
        SELECT c.category_id, c.category_name
        FROM complaints co
        JOIN categories c ON c.category_id = co.category_id
        WHERE co.ticket_id = ? LIMIT 1
    ");
    $catStmt->bind_param("s", $ticketId);
    $catStmt->execute();
    $catRow = $catStmt->get_result()->fetch_assoc();
    $catStmt->close();

    $categoryName = $catRow['category_name'] ?? '';
    $categoryId   = (int)($catRow['category_id'] ?? 0);

    // 2. Extract sub-category
    $subCategory = $categoryName;
if (strpos($categoryName, ' / ') !== false) {
    $subCategory = trim(explode(' / ', $categoryName, 2)[1]);
}

    // 3. Find matching staff list (by staff_categories junction table)
    $staffList = [];
    $staffStmt = $conn->prepare("
        SELECT s.staff_id
        FROM staff s
        JOIN staff_categories sc ON sc.staff_id = s.staff_id
        JOIN categories c        ON c.category_id = sc.category_id
        WHERE s.dept_id = ?
          AND s.status  = 'active'
          AND s.role    = 'staff'
          AND c.category_name LIKE ?
        ORDER BY s.staff_id ASC
    ");
    $likePattern = '%/ ' . $subCategory;
    $staffStmt->bind_param("is", $deptId, $likePattern);
    $staffStmt->execute();
    $staffRes = $staffStmt->get_result();
    while ($row = $staffRes->fetch_assoc()) {
        $staffList[] = (int)$row['staff_id'];
    }
    $staffStmt->close();

    // 4. Fallback to all active staff if no category match
    if (empty($staffList)) {
        $fallbackStmt = $conn->prepare("
            SELECT staff_id FROM staff
            WHERE dept_id = ? AND status = 'active' AND role = 'staff'
            ORDER BY staff_id ASC
        ");
        $fallbackStmt->bind_param("i", $deptId);
        $fallbackStmt->execute();
        $fallbackRes = $fallbackStmt->get_result();
        while ($row = $fallbackRes->fetch_assoc()) {
            $staffList[] = (int)$row['staff_id'];
        }
        $fallbackStmt->close();
    }

    if (empty($staffList)) return 0;

    // 5. Find FREE staff — free means ZERO open tickets assigned to them
    $freeStaffId = 0;
    foreach ($staffList as $sid) {
        $chk = $conn->prepare("
            SELECT COUNT(*) AS cnt FROM complaints
            WHERE assigned_to = ? AND status = 'open'
        ");
        $chk->bind_param("i", $sid);
        $chk->execute();
        $chkRow = $chk->get_result()->fetch_assoc();
        $chk->close();

        if ((int)$chkRow['cnt'] === 0) {
            $freeStaffId = $sid;
            break; // first free staff found
        }
    }

    // 6. Nobody is free — add to queue and return
    if ($freeStaffId === 0) {
        $qStmt = $conn->prepare("
            INSERT IGNORE INTO ticket_queue (ticket_id, dept_id, category_id, queued_at)
            VALUES (?, ?, ?, NOW())
        ");
        $qStmt->bind_param("sii", $ticketId, $deptId, $categoryId);
        $qStmt->execute();
        $qStmt->close();
        return 0; // ticket is queued, not assigned yet
    }

    // 7. Assign to the free staff
    return assignToStaff($conn, $ticketId, $freeStaffId);
}

function processQueue(mysqli $conn, int $deptId, int $staffId): void
{
    // Get ALL categories for this staff from junction table
    $catStmt = $conn->prepare("
        SELECT c.category_name
        FROM staff_categories sc
        JOIN categories c ON c.category_id = sc.category_id
        WHERE sc.staff_id = ?
    ");
    $catStmt->bind_param("i", $staffId);
    $catStmt->execute();
    $catRes = $catStmt->get_result();
    $staffCategories = [];
    while ($catRow = $catRes->fetch_assoc()) {
        $parts = explode(' / ', $catRow['category_name'], 2);
        if (count($parts) === 2) {
            $staffCategories[] = trim($parts[1]);
        }
    }
    $catStmt->close();

    // Check staff is actually free (zero open tickets)
    $chk = $conn->prepare("
        SELECT COUNT(*) AS cnt FROM complaints
        WHERE assigned_to = ? AND status = 'open'
    ");
    $chk->bind_param("i", $staffId);
    $chk->execute();
    $chkRow = $chk->get_result()->fetch_assoc();
    $chk->close();

    if ((int)$chkRow['cnt'] > 0) return; // staff still has open ticket, do nothing

    // Find oldest queued ticket matching staff's category
    $nextTicketId = null;

    if (!empty($staffCategories)) {
    $orClauses  = implode(' OR ', array_fill(0, count($staffCategories), 'cat.category_name LIKE ?'));
    $likeParams = array_map(function($c) { return '%/ ' . $c; }, $staffCategories);

    $qStmt = $conn->prepare("
        SELECT tq.ticket_id FROM ticket_queue tq
        JOIN categories cat ON cat.category_id = tq.category_id
        WHERE tq.dept_id = ?
          AND ({$orClauses})
        ORDER BY tq.queued_at ASC
        LIMIT 1
    ");

    $types      = 'i' . str_repeat('s', count($likeParams));
    $bindValues = array_merge([$deptId], $likeParams);
    $bindArgs   = [$types];
    foreach ($bindValues as &$val) {
        $bindArgs[] = &$val;
    }
    unset($val);
    call_user_func_array([$qStmt, 'bind_param'], $bindArgs);

    $qStmt->execute();
    $qRow         = $qStmt->get_result()->fetch_assoc();
    $qStmt->close();
    $nextTicketId = $qRow['ticket_id'] ?? null;
}
    // Fallback: any queued ticket in this dept if no category match
    if (!$nextTicketId) {
        $qStmt = $conn->prepare("
            SELECT ticket_id FROM ticket_queue
            WHERE dept_id = ?
            ORDER BY queued_at ASC
            LIMIT 1
        ");
        $qStmt->bind_param("i", $deptId);
        $qStmt->execute();
        $qRow = $qStmt->get_result()->fetch_assoc();
        $qStmt->close();
        $nextTicketId = $qRow['ticket_id'] ?? null;
    }

    if (!$nextTicketId) return; // nothing in queue

    // Remove from queue and assign
    $del = $conn->prepare("DELETE FROM ticket_queue WHERE ticket_id = ?");
    $del->bind_param("s", $nextTicketId);
    $del->execute();
    $del->close();

    assignToStaff($conn, $nextTicketId, $staffId);
}

function assignToStaff(mysqli $conn, string $ticketId, int $staffId): int
{
    // Get old assignee name for log
    $oldQ = $conn->prepare("
        SELECT s.full_name FROM complaints c
        LEFT JOIN staff s ON s.staff_id = c.assigned_to
        WHERE c.ticket_id = ? LIMIT 1
    ");
    $oldQ->bind_param("s", $ticketId);
    $oldQ->execute();
    $oldRow = $oldQ->get_result()->fetch_assoc();
    $oldQ->close();
    $oldName = $oldRow['full_name'] ?? '';

    // Get new assignee name
    $newQ = $conn->prepare("SELECT full_name FROM staff WHERE staff_id = ? LIMIT 1");
    $newQ->bind_param("i", $staffId);
    $newQ->execute();
    $newRow = $newQ->get_result()->fetch_assoc();
    $newQ->close();
    $newName = $newRow['full_name'] ?? '';

    // Update complaint
    $upd = $conn->prepare("UPDATE complaints SET assigned_to = ?, updated_at = NOW() WHERE ticket_id = ?");
    $upd->bind_param("is", $staffId, $ticketId);
    $upd->execute();
    $upd->close();

    // Log it
    $log = $conn->prepare("
        INSERT INTO ticket_logs
            (ticket_id, changed_by_id, changed_by, field_changed, old_priority, new_priority, changed_at)
        VALUES (?, 0, 'System', 'assigned', ?, ?, NOW())
    ");
    $log->bind_param("sss", $ticketId, $oldName, $newName);
    $log->execute();
    $log->close();

    return $staffId;
}

/**
 * Manually reassign a ticket to a specific staff member.
 * NOTE: Do NOT add ticket_logs here — manual assign already logs
 * elsewhere in your codebase (confirmed by logs 108-110 in your DB).
 * Adding it here would create duplicate log entries.
 */
function manualAssignTicket(mysqli $conn, int $deptId, string $ticketId, int $staffId): bool
{
    // Remove from queue if waiting
    $del = $conn->prepare("DELETE FROM ticket_queue WHERE ticket_id = ?");
    $del->bind_param("s", $ticketId);
    $del->execute();
    $del->close();

    $upd = $conn->prepare("UPDATE complaints SET assigned_to = ?, updated_at = NOW() WHERE ticket_id = ? AND dept_id = ?");
    $upd->bind_param("isi", $staffId, $ticketId, $deptId);
    $result = $upd->execute();
    $upd->close();
    return $result;
}

/**
 * Get the currently assigned staff for a ticket.
 */
function getAssignedStaff(mysqli $conn, string $ticketId): ?array
{
    $stmt = $conn->prepare(
        "SELECT s.staff_id, s.full_name, s.email, s.role
         FROM complaints c
         JOIN staff s ON s.staff_id = c.assigned_to
         WHERE c.ticket_id = ? LIMIT 1"
    );
    $stmt->bind_param("s", $ticketId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Get staff initials from full name
 */
function getInitialsFromName(string $name): string
{
    $parts = explode(' ', trim($name));
    $ini = strtoupper(substr($parts[0], 0, 1));
    if (count($parts) > 1) $ini .= strtoupper(substr($parts[count($parts)-1], 0, 1));
    return $ini;
}