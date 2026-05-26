<?php
// assign_helper.php
// Place in project ROOT (same folder as db_connect.php)

/**
 * Auto-assign a ticket to staff based on category match.
 *
 * Routing priority:
 *   1. If category name ends with "/ Others"  → assign to dept admin/hod (queue-aware)
 *   2. Find staff whose staff_categories match the sub-category
 *   3. If NO matching staff exist at all      → assign to dept admin/hod (queue-aware)
 *   4. Among matched staff, pick the first one with ZERO open tickets
 *   5. If all matched staff are busy          → add to ticket_queue (normal queue)
 *
 * "Assign to dept admin" means: find an active staff member with role = 'admin'
 * or 'hod' in the same dept_id, pick the one with the fewest open tickets
 * (round-robin fallback). If the admin is also busy, still assign to them —
 * admins are never queued, they always get the ticket immediately.
 *
 * Returns the staff_id assigned, or 0 if assignment was impossible.
 */
function autoAssignTicket(mysqli $conn, int $deptId, string $ticketId): int
{
    // ── 1. Get category info for this ticket ──────────────────────────────────
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

    // ── 2. Detect "Others" category ───────────────────────────────────────────
    $isOthers = _isOthersCategory($categoryName);

    if ($isOthers) {
        // Route directly to dept admin — skip the staff queue entirely
        return _assignToAdmin($conn, $deptId, $ticketId, $categoryId);
    }

    // ── 3. Extract sub-category label ─────────────────────────────────────────
    $subCategory = $categoryName;
    if (strpos($categoryName, ' / ') !== false) {
        $subCategory = trim(explode(' / ', $categoryName, 2)[1]);
    }

    // ── 4. Find staff whose staff_categories include this sub-category ────────
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

    // ── 5. No staff configured for this category → route to admin ─────────────
    if (empty($staffList)) {
        return _assignToAdmin($conn, $deptId, $ticketId, $categoryId);
    }

    // ── 6. Find a free staff member (zero open tickets) ───────────────────────
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
            break;
        }
    }

    // ── 7. All matched staff are busy → add to normal queue ───────────────────
    if ($freeStaffId === 0) {
        $qStmt = $conn->prepare("
            INSERT IGNORE INTO ticket_queue (ticket_id, dept_id, category_id, queued_at)
            VALUES (?, ?, ?, NOW())
        ");
        $qStmt->bind_param("sii", $ticketId, $deptId, $categoryId);
        $qStmt->execute();
        $qStmt->close();
        return 0;
    }

    // ── 8. Assign to the free staff member ────────────────────────────────────
    return assignToStaff($conn, $ticketId, $freeStaffId);
}

// ─────────────────────────────────────────────────────────────────────────────
// INTERNAL HELPERS
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Returns true if the category name ends with "/ Others" (case-insensitive).
 */
function _isOthersCategory(string $categoryName): bool
{
    return (bool) preg_match('/\/\s*Others\s*$/i', $categoryName);
}

/**
 * Assign a ticket directly to the department admin (role = 'admin' or 'hod').
 *
 * Picks the admin/hod with the fewest currently open tickets so the load is
 * spread when there are multiple admins. Always assigns immediately — admins
 * are never put into the ticket_queue.
 *
 * If no admin/hod exists for the department, falls back to the normal queue.
 */
function _assignToAdmin(mysqli $conn, int $deptId, string $ticketId, int $categoryId): int
{
    // Find all active admin / hod accounts for this dept
    $adminStmt = $conn->prepare("
        SELECT s.staff_id,
               (SELECT COUNT(*) FROM complaints
                WHERE assigned_to = s.staff_id AND status = 'open') AS open_count
        FROM staff s
        WHERE s.dept_id = ?
          AND s.status  = 'active'
          AND s.role    IN ('admin', 'hod')
        ORDER BY open_count ASC, s.staff_id ASC
        LIMIT 1
    ");
    $adminStmt->bind_param("i", $deptId);
    $adminStmt->execute();
    $adminRow = $adminStmt->get_result()->fetch_assoc();
    $adminStmt->close();

    if (!$adminRow) {
        // No admin found — fall back to normal queue so ticket isn't lost
        $qStmt = $conn->prepare("
            INSERT IGNORE INTO ticket_queue (ticket_id, dept_id, category_id, queued_at)
            VALUES (?, ?, ?, NOW())
        ");
        $qStmt->bind_param("sii", $ticketId, $deptId, $categoryId);
        $qStmt->execute();
        $qStmt->close();
        return 0;
    }

    return assignToStaff($conn, $ticketId, (int)$adminRow['staff_id']);
}

// ─────────────────────────────────────────────────────────────────────────────
// PUBLIC FUNCTIONS (unchanged signatures — existing callers are not broken)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Process the ticket queue for a newly freed staff member.
 * Called after a ticket is closed/resolved so the next queued ticket is picked up.
 */
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

    if ((int)$chkRow['cnt'] > 0) return;

    $nextTicketId = null;

    // Try to find a queued ticket matching this staff's categories
    // Exclude "Others" tickets from the normal staff queue — those go to admin
    if (!empty($staffCategories)) {
        $orClauses  = implode(' OR ', array_fill(0, count($staffCategories), 'cat.category_name LIKE ?'));
        $likeParams = array_map(function ($c) { return '%/ ' . $c; }, $staffCategories);

        $qStmt = $conn->prepare("
            SELECT tq.ticket_id FROM ticket_queue tq
            JOIN categories cat ON cat.category_id = tq.category_id
            WHERE tq.dept_id = ?
              AND ({$orClauses})
              AND cat.category_name NOT REGEXP '/ Others[[:space:]]*$'
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

    // Fallback: any non-Others queued ticket in this dept
    if (!$nextTicketId) {
        $qStmt = $conn->prepare("
            SELECT tq.ticket_id FROM ticket_queue tq
            JOIN categories cat ON cat.category_id = tq.category_id
            WHERE tq.dept_id = ?
              AND cat.category_name NOT REGEXP '/ Others[[:space:]]*$'
            ORDER BY tq.queued_at ASC
            LIMIT 1
        ");
        $qStmt->bind_param("i", $deptId);
        $qStmt->execute();
        $qRow = $qStmt->get_result()->fetch_assoc();
        $qStmt->close();
        $nextTicketId = $qRow['ticket_id'] ?? null;
    }

    if (!$nextTicketId) return;

    $del = $conn->prepare("DELETE FROM ticket_queue WHERE ticket_id = ?");
    $del->bind_param("s", $nextTicketId);
    $del->execute();
    $del->close();

    assignToStaff($conn, $nextTicketId, $staffId);
}

/**
 * Manually reassign a ticket to a specific staff member.
 */
function manualAssignTicket(mysqli $conn, int $deptId, string $ticketId, int $staffId): bool
{
    $del = $conn->prepare("DELETE FROM ticket_queue WHERE ticket_id = ?");
    $del->bind_param("s", $ticketId);
    $del->execute();
    $del->close();

    $upd = $conn->prepare("
        UPDATE complaints SET assigned_to = ?, updated_at = NOW()
        WHERE ticket_id = ? AND dept_id = ?
    ");
    $upd->bind_param("isi", $staffId, $ticketId, $deptId);
    $result = $upd->execute();
    $upd->close();
    return $result;
}

/**
 * Core assignment: update the complaint row, write a ticket_log entry.
 * Returns the staff_id that was assigned.
 */
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

    // Log the assignment
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
 * Get the currently assigned staff for a ticket.
 */
function getAssignedStaff(mysqli $conn, string $ticketId): ?array
{
    $stmt = $conn->prepare("
        SELECT s.staff_id, s.full_name, s.email, s.role
        FROM complaints c
        JOIN staff s ON s.staff_id = c.assigned_to
        WHERE c.ticket_id = ? LIMIT 1
    ");
    $stmt->bind_param("s", $ticketId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Get staff initials from full name.
 */
function getInitialsFromName(string $name): string
{
    $parts = explode(' ', trim($name));
    $ini   = strtoupper(substr($parts[0], 0, 1));
    if (count($parts) > 1) $ini .= strtoupper(substr($parts[count($parts) - 1], 0, 1));
    return $ini;
}