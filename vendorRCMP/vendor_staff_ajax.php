<?php
// vendorRCMP/vendor_staff_ajax.php
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['vendor_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit;
}

require_once __DIR__ . '/../db_connect.php';
$vendor_id = (int)$_SESSION['vendor_id'];
$action    = $_POST['action'] ?? $_GET['action'] ?? '';

// ── LIST ──────────────────────────────────────────────────────────────────
if ($action === 'list') {
    $stmt = $conn->prepare("SELECT * FROM vendor_staff WHERE vendor_id = ? ORDER BY is_primary DESC, created_at ASC");
    $stmt->bind_param('i', $vendor_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    echo json_encode(['success' => true, 'staff' => $rows]);
    exit;
}

// ── ADD / EDIT ─────────────────────────────────────────────────────────────
if ($action === 'save') {
    $staff_id  = (int)($_POST['staff_id'] ?? 0);
    $full_name = trim($_POST['full_name'] ?? '');
    $position  = trim($_POST['position']  ?? '');

    if (!$full_name) {
        echo json_encode(['success' => false, 'error' => 'Full name is required.']); exit;
    }

    if ($staff_id > 0) {
        // Edit — make sure it belongs to this vendor
        $stmt = $conn->prepare("UPDATE vendor_staff SET full_name=?, position=? WHERE staff_id=? AND vendor_id=?");
        $stmt->bind_param('ssii', $full_name, $position, $staff_id, $vendor_id);
    } else {
        // Add new
        $stmt = $conn->prepare("INSERT INTO vendor_staff (vendor_id, full_name, position) VALUES (?,?,?)");
        $stmt->bind_param('iss', $vendor_id, $full_name, $position);
    }

    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => $ok, 'error' => $ok ? null : 'Database error.']);
    exit;
}

// ── DELETE ────────────────────────────────────────────────────────────────
if ($action === 'delete') {
    $staff_id = (int)($_POST['staff_id'] ?? 0);
    // is_primary = 0 guard: the PIC can never be removed from here, only edited.
    $stmt = $conn->prepare("DELETE FROM vendor_staff WHERE staff_id=? AND vendor_id=? AND is_primary = 0");
    $stmt->bind_param('ii', $staff_id, $vendor_id);
    $ok = $stmt->execute() && $stmt->affected_rows > 0;
    $stmt->close();
    echo json_encode(['success' => $ok, 'error' => $ok ? null : 'The Person In Charge cannot be removed here.']);
    exit;
}

// ── LIST VENDOR DEPARTMENTS ───────────────────────────────────────────────
if ($action === 'list_departments') {
    $stmt = $conn->prepare("
        SELECT vd.id, vd.dept_id, vd.status, vd.created_at, d.dept_name
        FROM vendor_departments vd
        JOIN departments d ON d.dept_id = vd.dept_id
        WHERE vd.vendor_id = ?
        ORDER BY FIELD(vd.status, 'active', 'pending'), vd.created_at ASC
    ");
    $stmt->bind_param('i', $vendor_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    echo json_encode(['success' => true, 'departments' => $rows]);
    exit;
}

// ── DEPARTMENTS NOT YET APPLIED FOR ──────────────────────────────────────
if ($action === 'available_departments') {
    $stmt = $conn->prepare("
        SELECT d.dept_id, d.dept_name
        FROM departments d
        WHERE d.dept_id NOT IN (
            SELECT dept_id FROM vendor_departments WHERE vendor_id = ?
        )
        ORDER BY d.dept_name ASC
    ");
    $stmt->bind_param('i', $vendor_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    echo json_encode(['success' => true, 'departments' => $rows]);
    exit;
}

// ── REQUEST NEW DEPARTMENT ────────────────────────────────────────────────
if ($action === 'request_department') {
    $dept_ids = array_map('intval', $_POST['dept_ids'] ?? []);
    if (empty($dept_ids)) {
        echo json_encode(['success' => false, 'error' => 'Select at least one department.']); exit;
    }
    $chk = $conn->prepare("SELECT id FROM vendor_departments WHERE vendor_id=? AND dept_id=? LIMIT 1");
    $ins = $conn->prepare("INSERT INTO vendor_departments (vendor_id, dept_id, status, created_at) VALUES (?, ?, 'pending', NOW())");
    $inserted = 0;
    foreach ($dept_ids as $did) {
        $chk->bind_param('ii', $vendor_id, $did);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) continue; // already exists, skip
        $ins->bind_param('ii', $vendor_id, $did);
        if ($ins->execute()) $inserted++;
    }
    $chk->close();
    $ins->close();
    echo json_encode(['success' => true, 'inserted' => $inserted]);
    exit;
}

// ── CANCEL PENDING DEPARTMENT REQUEST ────────────────────────────────────
if ($action === 'cancel_department') {
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $conn->prepare("DELETE FROM vendor_departments WHERE id=? AND vendor_id=? AND status='pending'");
    $stmt->bind_param('ii', $id, $vendor_id);
    $ok = $stmt->execute() && $stmt->affected_rows > 0;
    $stmt->close();
    echo json_encode(['success' => $ok]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action.']);