<?php
// dept_admin/afsmd/users.php 
require '_layout.php';

$msg   = '';
$error = '';

// Read success messages passed via redirect
$successMessages = [
    'password_reset' => 'Password reset successfully.',
    'status_updated' => 'Staff status updated. Any pending unassigned tickets have been reassigned.',
    'deleted'        => 'Staff member removed.',
    'added'          => 'Staff member added successfully.',
    'edited'         => 'Staff details updated successfully.',
];
if (!empty($_GET['success']) && isset($successMessages[$_GET['success']])) {
    $msg = $successMessages[$_GET['success']];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
    $code     = trim($_POST['staff_code'] ?? '');
    $name     = trim($_POST['full_name']  ?? '');
    $email    = trim($_POST['email']      ?? '');
    $phone    = trim($_POST['phone']      ?? '');
    $role     = 'staff';
    $rawPw    = trim($_POST['password']   ?? '');
$categories = $_POST['categories'] ?? [];
$category   = !empty($categories) ? $categories[0] : ''; // first one for staff.category display

if (!$code || !$name || !$email || !$rawPw || empty($categories)) {
        $error = 'Please fill in all required fields.';
    } elseif (strlen($rawPw) < 8 || strlen($rawPw) > 10) {
        $error = 'Password must be between 8 and 10 characters.';
    } elseif (!preg_match('/^\d{6}$/', $code)) {
        $error = 'Staff code must be exactly 6 digits (numbers only).';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $error = 'Invalid email address.';
} elseif ($phone !== '' && !preg_match('/^\d{10,11}$/', $phone)) {
    $error = 'Phone number must be 10 or 11 digits (numbers only).';
} else {
        $chk = $conn->prepare("SELECT staff_id FROM staff WHERE email = ? OR staff_code = ?");
        $chk->bind_param("ss", $email, $code);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $error = 'A staff member with this email or staff code already exists.';
        } else {
            $hash = password_hash($rawPw, PASSWORD_BCRYPT);
            $dept = 'Administration & Facilities Management Department';
            $ins  = $conn->prepare(
                "INSERT INTO staff (staff_code, full_name, email, password_hash, department, dept_id, role, category, phone, status)
                 VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, 'active')"
            );
            $ins->bind_param("ssssssss", $code, $name, $email, $hash, $dept, $role, $category, $phone);
            if ($ins->execute()) {
    $newStaffId = $conn->insert_id;

    // Insert ALL selected categories into staff_categories
    foreach ($categories as $cat) {
        $cat = trim($cat);
        if (!$cat) continue;
        $catIdStmt = $conn->prepare("
            SELECT category_id FROM categories
            WHERE dept_id = 1 AND category_name LIKE CONCAT('%/ ', ?)
            LIMIT 1
        ");
        $catIdStmt->bind_param("s", $cat);
        $catIdStmt->execute();
        $catIdRow = $catIdStmt->get_result()->fetch_assoc();
        $catIdStmt->close();

        if ($catIdRow) {
            $scIns = $conn->prepare("
                INSERT IGNORE INTO staff_categories (staff_id, category_id)
                VALUES (?, ?)
            ");
            $scIns->bind_param("ii", $newStaffId, $catIdRow['category_id']);
            $scIns->execute();
            $scIns->close();
        }
    }

    header('Location: users.php?success=added');
    exit;
            } else {
                $error = 'Database error: ' . $conn->error;
            }
        }
    }
}

    if ($action === 'toggle_status') {
    $sid   = (int)($_POST['staff_id']   ?? 0);
    $newSt = $_POST['new_status'] === 'active' ? 'active' : 'inactive';
    if ($sid === (int)$_SESSION['staff_id']) {
        $error = 'You cannot change your own account status.';
    } else {
        $u = $conn->prepare("UPDATE staff SET status = ? WHERE staff_id = ? AND dept_id = 1");
        $u->bind_param("si", $newSt, $sid);
        if ($u->execute()) {

            // ── When ACTIVATING: assign unassigned tickets to this staff ──
            if ($newSt === 'active') {
                require_once __DIR__ . '/../../assign_helper.php';

                // Step 1: Process queued tickets first
                processQueue($conn, 1, $sid);

// Get ALL categories for this staff from junction table
$catQ = $conn->prepare("
    SELECT c.category_name
    FROM staff_categories sc
    JOIN categories c ON c.category_id = sc.category_id
    WHERE sc.staff_id = ?
");
$catQ->bind_param("i", $sid);
$catQ->execute();
$catRes = $catQ->get_result();
$staffCategories = [];
while ($catRow = $catRes->fetch_assoc()) {
    $parts = explode(' / ', $catRow['category_name'], 2);
    if (count($parts) === 2) {
        $staffCategories[] = trim($parts[1]);
    }
}
$catQ->close();

// Step 2: Find unassigned complaints matching this staff's categories
if (!empty($staffCategories)) {
    $orClauses = implode(' OR ', array_fill(0, count($staffCategories), 'cat.category_name LIKE ?'));
    $likeParams = array_map(function($c) { return '%/ ' . $c; }, $staffCategories);

    $unassignedStmt = $conn->prepare("
        SELECT c.ticket_id 
        FROM complaints c
        JOIN categories cat ON cat.category_id = c.category_id
        WHERE c.dept_id = 1
          AND c.assigned_to IS NULL
          AND c.status != 'closed'
          AND ({$orClauses})
        ORDER BY c.created_at ASC
    ");

    $types = str_repeat('s', count($likeParams));
    $unassignedStmt->bind_param($types, ...$likeParams);
                    $unassignedStmt->execute();
                    $unassignedTickets = $unassignedStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $unassignedStmt->close();

    foreach ($unassignedTickets as $ut) {
                        // Check staff is still free before each assignment
                        $chk = $conn->prepare("
                            SELECT COUNT(*) AS cnt FROM complaints
                            WHERE assigned_to = ? AND status = 'open'
                        ");
                        $chk->bind_param("i", $sid);
                        $chk->execute();
                        $chkRow = $chk->get_result()->fetch_assoc();
                        $chk->close();

                        if ((int)$chkRow['cnt'] === 0) {
                            assignToStaff($conn, $ut['ticket_id'], $sid);
                        } else {
                            break; // staff now has a ticket, stop
                        }
                    }
                }
            }
            // ─────────────────────────────────────────────────────────────

            header('Location: users.php?success=status_updated');
            exit;
        } else {
            $error = 'Update failed.';
        }
    }
}

if ($action === 'edit') {
        $sid   = (int)($_POST['staff_id'] ?? 0);
        $name  = trim($_POST['full_name']   ?? '');
        $email = trim($_POST['email']       ?? '');
        $phone = trim($_POST['phone']       ?? '');
        $code  = trim($_POST['staff_code']  ?? '');

        if (!$name || !$email || !$code) {
            $error = 'Name, email and staff code are required.';
        } elseif (!preg_match('/^\d{6}$/', $code)) {
            $error = 'Staff code must be exactly 6 digits (numbers only).';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email address.';
        } elseif ($phone !== '' && !preg_match('/^\d{10,11}$/', $phone)) {
            $error = 'Phone number must be 10 or 11 digits.';
        } else {
            // Check duplicate email (excluding self)
            $chk = $conn->prepare("SELECT staff_id FROM staff WHERE email = ? AND staff_id != ?");
            $chk->bind_param("si", $email, $sid);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $error = 'Another staff member already uses this email.';
            } else {
                // Check duplicate staff code (excluding self)
                $chkCode = $conn->prepare("SELECT staff_id FROM staff WHERE staff_code = ? AND staff_id != ?");
                $chkCode->bind_param("si", $code, $sid);
                $chkCode->execute();
                if ($chkCode->get_result()->num_rows > 0) {
                    $error = 'Another staff member already uses this staff code.';
                } else {
                $categories = $_POST['categories'] ?? [];
$category   = !empty($categories) ? $categories[0] : '';

$u = $conn->prepare("UPDATE staff SET staff_code = ?, full_name = ?, email = ?, phone = ?, category = ? WHERE staff_id = ? AND dept_id = 1");
$u->bind_param("sssssi", $code, $name, $email, $phone, $category, $sid);
if ($u->execute()) {

    // Delete old staff_categories for this dept
    $delSc = $conn->prepare("
        DELETE sc FROM staff_categories sc
        JOIN categories c ON c.category_id = sc.category_id
        WHERE sc.staff_id = ? AND c.dept_id = 1
    ");
    $delSc->bind_param("i", $sid);
    $delSc->execute();
    $delSc->close();

    // Insert all selected categories
    foreach ($categories as $cat) {
        $cat = trim($cat);
        if (!$cat) continue;
        $catIdStmt = $conn->prepare("
            SELECT category_id FROM categories
            WHERE dept_id = 1 AND category_name LIKE CONCAT('%/ ', ?)
            LIMIT 1
        ");
        $catIdStmt->bind_param("s", $cat);
        $catIdStmt->execute();
        $catIdRow = $catIdStmt->get_result()->fetch_assoc();
        $catIdStmt->close();

        if ($catIdRow) {
            $scIns = $conn->prepare("
                INSERT IGNORE INTO staff_categories (staff_id, category_id)
                VALUES (?, ?)
            ");
            $scIns->bind_param("ii", $sid, $catIdRow['category_id']);
            $scIns->execute();
            $scIns->close();
        }
    }

    header('Location: users.php?success=edited');
                    exit;
                } else {
                    $error = 'Update failed: ' . $conn->error;
                }
                } // close duplicate-code else
            } // close duplicate-email else
        } // close main validation else
    } // close edit action


    if ($action === 'reset_password') {
        $sid   = (int)($_POST['staff_id']    ?? 0);
        $newPw = trim($_POST['new_password'] ?? '');
if (strlen($newPw) < 8 || strlen($newPw) > 10) {
    $error = 'Password must be between 8 and 10 characters.';
        } else {
            $hash = password_hash($newPw, PASSWORD_BCRYPT);
            $u = $conn->prepare("UPDATE staff SET password_hash = ? WHERE staff_id = ? AND dept_id = 1");
            $u->bind_param("si", $hash, $sid);
            if ($u->execute()) {
                header('Location: users.php?success=password_reset');
                exit;
            } else {
                $error = 'Reset failed.';
            }
        }
    }

    if ($action === 'delete') {
        $sid = (int)($_POST['staff_id'] ?? 0);
        if ($sid === (int)$_SESSION['staff_id']) {
            $error = 'You cannot delete your own account.';
        } else {
            // Block deletion if staff has open or in-progress tickets
            $chkTickets = $conn->prepare("
                SELECT COUNT(*) AS cnt FROM complaints
                WHERE assigned_to = ? AND status IN ('open', 'in_progress')
            ");
            $chkTickets->bind_param("i", $sid);
            $chkTickets->execute();
            $ticketCount = (int)$chkTickets->get_result()->fetch_assoc()['cnt'];
            $chkTickets->close();

            if ($ticketCount > 0) {
                $error = "Cannot delete: this staff has {$ticketCount} active ticket(s). Please reassign all open and in-progress tickets to another staff member first.";
            } else {
                $d = $conn->prepare("DELETE FROM staff WHERE staff_id = ? AND dept_id = 1");
                $d->bind_param("i", $sid);
                if ($d->execute()) {
                    header('Location: users.php?success=deleted');
                    exit;
                } else {
                    $error = 'Delete failed.';
                }
            }
        }
    }
} // ← end of POST block (this was missing before!)

$search = trim($_GET['q']    ?? '');
$roleF  = $_GET['role']      ?? '';

$where  = ["dept_id = 1"];
$params = []; $types = '';
if ($search) {
    $where[] = "(full_name LIKE ? OR email LIKE ? OR staff_code LIKE ?)";
    $l = "%$search%"; $params = [$l, $l, $l]; $types = 'sss';
}
if ($roleF) { $where[] = "role = ?"; $params[] = $roleF; $types .= 's'; }

$sql  = "SELECT * FROM staff WHERE " . implode(' AND ', $where) . " ORDER BY role DESC, full_name ASC";
$stmt = $conn->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$staffList = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Load categories for IT dept from database
$catStmt = $conn->prepare("SELECT category_id, category_name FROM categories WHERE dept_id = 1 ORDER BY category_name ASC");
$catStmt->execute();
$allCategories = $catStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$catStmt->close();

// Load staff_categories for all staff in this dept
$scStmt = $conn->prepare("
    SELECT sc.staff_id, c.category_name
    FROM staff_categories sc
    JOIN categories c ON c.category_id = sc.category_id
    JOIN staff s ON s.staff_id = sc.staff_id
    WHERE s.dept_id = 1
");
$scStmt->execute();
$scRows = $scStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$scStmt->close();

// Group by staff_id → array of category names
$staffCategoryMap = [];
foreach ($scRows as $scRow) {
    $sid = $scRow['staff_id'];
    $parts = explode(' / ', $scRow['category_name'], 2);
    $label = count($parts) === 2 ? trim($parts[1]) : $scRow['category_name'];
    $staffCategoryMap[$sid][] = $label;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>AFSMD Admin — Manage Users | UniKL Help Desk</title>
  <?php include '_head_assets.php'; ?>
  <style>
    .icon-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 32px;
      height: 32px;
      border: 1.5px solid #d1d5db;
      border-radius: 6px;
      background: #fff;
      color: #6b7280;
      cursor: pointer;
      transition: border-color 0.15s, color 0.15s, background 0.15s;
      padding: 0;
      flex-shrink: 0;
    }
    .icon-btn svg {
      width: 15px; height: 15px;
      stroke: currentColor; fill: none;
      stroke-width: 1.75; stroke-linecap: round; stroke-linejoin: round;
      display: block;
    }
    .icon-btn:hover                { background: #f9fafb; border-color: #9ca3af; color: #374151; }
    .icon-btn.btn-view:hover       { border-color: #3b82f6; color: #3b82f6; background: #eff6ff; }
    .icon-btn.btn-edit:hover       { border-color: #8b5cf6; color: #8b5cf6; background: #f5f3ff; }
    .icon-btn.btn-reset:hover      { border-color: #f59e0b; color: #d97706; background: #fffbeb; }
    .icon-btn.btn-activate:hover   { border-color: #10b981; color: #10b981; background: #ecfdf5; }
    .icon-btn.btn-deactivate:hover { border-color: #f59e0b; color: #d97706; background: #fffbeb; }
    .icon-btn.btn-delete:hover     { border-color: #ef4444; color: #ef4444; background: #fef2f2; }
    .actions-cell { display: flex; align-items: center; gap: 5px; flex-wrap: nowrap; }
    .actions-cell form { display: inline-flex; }


  /* Fix hover — use a visually distinct color from thead gray-100 */
  .data-table tbody tr:hover td {
    background-color: #ede9fe !important; /* light purple, distinct from header */
    transition: background-color 0.15s ease;
  }
  </style>
</head>
<body>
<?php include '_sidebar.php'; ?>

<main class="main-content">
  <div class="page-header">
    <div>
      <div class="page-eyebrow">Administration & Facilities Management Department</div>
      <h1 class="page-title">Manage Users <span class="title-count"><?= count($staffList) ?></span></h1>
    </div>
    <button class="btn-primary-sm" onclick="openModal('addModal')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14">
        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
      </svg>
      Add Staff
    </button>
  </div>

<?php if ($msg): ?>
<div class="alert alert-success" id="alertSuccess"><?= $msg ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-error" id="alertError"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

  <form method="GET" class="filter-bar">
    <div class="search-wrap">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
      </svg>
      <input type="text" name="q" placeholder="Search name, email, code…" value="<?= htmlspecialchars($search) ?>"/>
    </div>
    <select name="role">
      <option value="">All Roles</option>
      <option value="staff" <?= $roleF==='staff'?'selected':'' ?>>Staff</option>
      <option value="admin" <?= $roleF==='admin'?'selected':'' ?>>Admin</option>
    </select>
    <button type="submit" class="btn-primary-sm">Search</button>
    <?php if ($search||$roleF): ?><a href="users.php" class="btn-ghost-sm">Clear</a><?php endif; ?>
  </form>

  <div class="card no-pad">
    <table class="data-table full">
      <thead>
        <tr>
          <th>Name</th>
          <th>Staff Code</th>
          <th>Email</th>
          <th>Phone</th>
          <th>Role</th>
          <th>Category</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($staffList)): ?>
        <tr><td colspan="8" class="empty-row">No staff found.</td></tr>
        <?php else: foreach ($staffList as $s): ?>
        <tr>
          <td>
            <div class="user-cell">
              <div class="user-avatar <?= $s['role'] ?>"><?= strtoupper(substr($s['full_name'],0,1)) ?></div>
              <div>
                <div class="user-name"><?= htmlspecialchars($s['full_name']) ?></div>
                <?php if ($s['staff_id'] === (int)$_SESSION['staff_id']): ?>
                <div class="user-you">You</div>
                <?php endif; ?>
              </div>
            </div>
          </td>
          <td><span style="font-family:monospace;font-size:0.85rem;color:#6b7280;"><?= htmlspecialchars($s['staff_code']) ?></span></td>
          <td class="td-email"><?= htmlspecialchars($s['email']) ?></td>
          <td><?= htmlspecialchars($s['phone'] ?? '—') ?></td>
          <td><span class="role-pill <?= $s['role'] ?>"><?= ucfirst($s['role']) ?></span></td>
<td>
  <?php
    $cats = $staffCategoryMap[$s['staff_id']] ?? [];
    if (empty($cats)) {
        echo '<span style="color:#9ca3af;">—</span>';
    } else {
        foreach ($cats as $cat) {
            echo '<span style="display:inline-block;font-size:11px;background:#f0f9ff;color:#0369a1;border:1px solid #bae6fd;border-radius:4px;padding:1px 7px;margin:1px 2px 1px 0;">' . htmlspecialchars($cat) . '</span>';
        }
    }
  ?>
</td>
          <td style="white-space:nowrap;">
  <span class="status-dot <?= $s['status'] ?>"></span>
<?= $s['status'] === 'inactive' ? 'Paused' : 'Active' ?>
</td>
          <td class="actions-cell">

            <!-- View button -->
            <button type="button" class="icon-btn btn-view" title="View"
                    onclick="openViewModal(<?= $s['staff_id'] ?>, '<?= htmlspecialchars(addslashes($s['full_name'])) ?>', '<?= htmlspecialchars(addslashes($s['email'])) ?>', '<?= htmlspecialchars(addslashes($s['phone'] ?? '')) ?>', '<?= $s['role'] ?>', '<?= $s['status'] ?>', '<?= htmlspecialchars(addslashes($s['staff_code'])) ?>')">
              <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>

            <!-- Edit Staff button -->
            <button type="button" class="icon-btn btn-edit" title="Edit Staff"
                    onclick="openEditModal(<?= $s['staff_id'] ?>, '<?= htmlspecialchars(addslashes($s['full_name'])) ?>', '<?= htmlspecialchars(addslashes($s['email'])) ?>', '<?= htmlspecialchars(addslashes($s['phone'] ?? '')) ?>', '<?= $s['role'] ?>', '<?= htmlspecialchars(addslashes($s['staff_code'])) ?>')">
              <svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            </button>

            <!-- Reset Password button -->
            <button type="button" class="icon-btn btn-reset" title="Reset Password"
                    onclick="openResetModal(<?= $s['staff_id'] ?>, '<?= htmlspecialchars(addslashes($s['full_name'])) ?>')">
              <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </button>

            <!-- Toggle Status (hidden for own account) -->
            <?php if ($s['staff_id'] !== (int)$_SESSION['staff_id'] && $s['role'] !== 'hod'): ?>
            <form method="POST">
              <input type="hidden" name="action"     value="toggle_status"/>
              <input type="hidden" name="staff_id"   value="<?= $s['staff_id'] ?>"/>
              <input type="hidden" name="new_status" value="<?= $s['status']==='active'?'inactive':'active' ?>"/>
              <?php if ($s['status'] === 'active'): ?>
              <button type="submit" class="icon-btn btn-deactivate" title="Pause new ticket assignments">
                <svg viewBox="0 0 24 24"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>
              </button>
              <?php else: ?>
              <button type="submit" class="icon-btn btn-activate" title="Resume ticket assignments">
                <svg viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"/></svg>
              </button>
              <?php endif; ?>
            </form>
            <?php endif; ?>

            <!-- Delete (hidden for own account) -->
            <?php if ($s['staff_id'] !== (int)$_SESSION['staff_id'] && $s['role'] !== 'hod'): ?>
            <button type="button" class="icon-btn btn-delete" title="Delete"
                    onclick="confirmDelete(<?= $s['staff_id'] ?>, '<?= htmlspecialchars(addslashes($s['full_name'])) ?>')">
              <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
            </button>
            <?php endif; ?>

          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</main>

<!-- ── View Staff Modal ───────────────────────────────────────────────── -->
<div class="modal-overlay" id="viewModal">
  <div class="modal">
    <div class="modal-header">
      <h3>Staff Details</h3>
      <button class="modal-close" onclick="closeModal('viewModal')">✕</button>
    </div>
    <div class="modal-form" style="display:grid; gap:12px;">
      <div style="display:flex; align-items:center; gap:14px; padding-bottom:8px; border-bottom:1px solid #f1f5f9;">
        <div id="viewAvatar" style="width:48px;height:48px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1.2rem;color:#fff;background:#6366f1;flex-shrink:0;"></div>
        <div>
          <div id="viewName" style="font-weight:600;font-size:1rem;"></div>
          <div id="viewCode" style="font-size:0.78rem;color:#6b7280;font-family:monospace;"></div>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div>
          <div style="font-size:0.72rem;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Email</div>
          <div id="viewEmail" style="font-size:0.9rem;margin-top:2px;"></div>
        </div>
        <div>
          <div style="font-size:0.72rem;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Phone</div>
          <div id="viewPhone" style="font-size:0.9rem;margin-top:2px;"></div>
        </div>
         <div>
          <div style="font-size:0.72rem;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Role</div>
          <div id="viewRole" style="font-size:0.9rem;margin-top:2px;"></div>
        </div>
        <div>
          <div style="font-size:0.72rem;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Category</div>
          <div id="viewCategory" style="font-size:0.9rem;margin-top:2px;"></div>
        </div>
        <div>
          <div style="font-size:0.72rem;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Status</div>
          <div id="viewStatus" style="font-size:0.9rem;margin-top:2px;"></div>
        </div>
      </div>
      <div class="modal-footer" style="padding-top:8px;">
        <button type="button" class="btn-ghost-sm" onclick="closeModal('viewModal')">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- ── Add Staff Modal ────────────────────────────────────────────────── -->
<div class="modal-overlay" id="addModal">
  <div class="modal">
    <div class="modal-header">
      <h3>Add New Staff</h3>
      <button class="modal-close" onclick="closeModal('addModal')">✕</button>
    </div>
    <form method="POST" class="modal-form" onsubmit="return validateAddForm()" autocomplete="off">
      <input type="hidden" name="action" value="add"/>
      <div class="field">
        <label>Staff Code <span class="req">*</span></label>
        <input type="text" name="staff_code" id="staffCodeInput"
               placeholder="e.g. 001007" maxlength="6" pattern="\d{6}"
               inputmode="numeric"
               title="Staff code must be exactly 6 digits (numbers only)" required/>
        <small style="color:#9ca3af;font-size:0.75rem;">Must be exactly 6 digits (numbers only).</small>
      </div>
      <div class="field">
        <label>Full Name <span class="req">*</span></label>
        <input type="text" name="full_name" placeholder="Full name as per ID" required/>
      </div>
      <div class="field">
        <label>Email <span class="req">*</span></label>
        <input type="email" name="email" placeholder="staff@unikl.edu.my" required autocomplete="new-password"/>
      </div>
      <div class="form-grid-2">
        <div class="field">
          <label>Phone</label>
          <input type="text" name="phone" id="phoneInput"
       placeholder="e.g. 0187001007"
       inputmode="numeric"
       maxlength="11"
       pattern="\d{10,11}"
       title="Phone number must be 10 or 11 digits (numbers only)"/>
        </div>
         <div class="field">
          <label>Password <span class="req">*</span></label>
          <div style="position:relative;">
            <input type="password" name="password" id="addPasswordInput"
                   placeholder="8–10 characters" required minlength="8" maxlength="10" autocomplete="new-password"
                   style="width:100%; padding-right:42px;"/>
            <button type="button" id="addPwToggle"
                    style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#9ca3af;display:flex;align-items:center;padding:3px;transition:color .2s;"
                    onmouseenter="this.style.color='#6b7280'" onmouseleave="this.style.color='#9ca3af'"
                    aria-label="Toggle password visibility">
              <svg id="addEyeIcon" viewBox="0 0 24 24" width="16" height="16" fill="none"
                   stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                <circle cx="12" cy="12" r="3"/>
              </svg>
            </button>
          </div>
        </div>
      </div>
      <div class="field">
  <label>Category <span class="req">*</span> <small style="color:#9ca3af;font-weight:400;">Select at least one</small></label>
  <div style="border:1.5px solid #e5e7eb;border-radius:8px;padding:10px 12px;display:flex;flex-direction:column;gap:8px;">
    <?php foreach ($allCategories as $cat):
      $parts = explode(' / ', $cat['category_name'], 2);
      $label = count($parts) === 2 ? trim($parts[1]) : $cat['category_name'];
    ?>
    <label style="display:flex;align-items:center;gap:8px;font-size:0.875rem;cursor:pointer;font-weight:400;">
      <input type="checkbox" name="categories[]" value="<?= htmlspecialchars($label) ?>"
             style="width:15px;height:15px;accent-color:#3b82f6;cursor:pointer;"/>
      <?= htmlspecialchars($label) ?>
    </label>
    <?php endforeach; ?>
  </div>
</div>
<input type="hidden" name="role" value="staff"/>
      <div class="modal-footer">
        <button type="button" class="btn-ghost-sm" onclick="closeModal('addModal')">Cancel</button>
        <button type="submit" class="btn-primary-sm">Add Staff</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Reset Password Modal ───────────────────────────────────────────── -->
<div class="modal-overlay" id="resetModal">
  <div class="modal">
    <div class="modal-header">
      <h3>Reset Password — <span id="resetName"></span></h3>
      <button class="modal-close" onclick="closeModal('resetModal')">✕</button>
    </div>
    <form method="POST" class="modal-form">
      <input type="hidden" name="action"   value="reset_password"/>
      <input type="hidden" name="staff_id" id="resetStaffId"/>
      <div class="field">
        <label>New Password</label>
        <div style="position:relative;">
          <input type="password" name="new_password" id="resetPasswordInput"
                 placeholder="8–10 characters" required minlength="8" maxlength="10"
                 style="width:100%; padding-right:42px;"/>
          <button type="button" id="resetPwToggle"
                  style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#9ca3af;display:flex;align-items:center;padding:3px;transition:color .2s;"
                  onmouseenter="this.style.color='#6b7280'" onmouseleave="this.style.color='#9ca3af'"
                  aria-label="Toggle password visibility">
            <svg id="resetEyeIcon" viewBox="0 0 24 24" width="16" height="16" fill="none"
                 stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
              <circle cx="12" cy="12" r="3"/>
            </svg>
          </button>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-ghost-sm" onclick="closeModal('resetModal')">Cancel</button>
        <button type="submit" class="btn-primary-sm">Reset</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Edit Staff Modal ──────────────────────────────────────────────── -->
<div class="modal-overlay" id="editModal">
  <div class="modal">
    <div class="modal-header">
      <h3>Edit Staff — <span id="editModalName"></span></h3>
      <button class="modal-close" onclick="closeModal('editModal')">✕</button>
    </div>
    <form method="POST" class="modal-form" id="editForm" onsubmit="return validateEditForm()">
      <input type="hidden" name="action"   value="edit"/>
      <input type="hidden" name="staff_id" id="editStaffId"/>
      <div class="field">
        <label>Staff Code <span class="req">*</span></label>
        <input type="text" name="staff_code" id="editStaffCode"
               placeholder="e.g. 001007" maxlength="6" pattern="\d{6}"
               inputmode="numeric"
               title="Staff code must be exactly 6 digits (numbers only)" required/>
        <small style="color:#9ca3af;font-size:0.75rem;">Must be exactly 6 digits (numbers only).</small>
      </div>
      <div class="field">
        <label>Full Name <span class="req">*</span></label>
        <input type="text" name="full_name" id="editFullName" placeholder="Full name as per ID" required/>
      </div>
      <div class="field">
        <label>Email <span class="req">*</span></label>
        <input type="email" name="email" id="editEmail" placeholder="staff@unikl.edu.my" required/>
      </div>
      <div class="field">
        <label>Phone</label>
        <input type="text" name="phone" id="editPhone"
               placeholder="e.g. 0187001007" inputmode="numeric" maxlength="11"
               pattern="\d{10,11}" title="10 or 11 digits only"/>
      </div>
      <div class="form-grid-2">
        <div class="field">
          <label>Role</label>
          <input type="text" id="editRoleDisplay" disabled
                 style="background:#f9fafb; color:#6b7280; cursor:not-allowed;"/>
          <small style="color:#9ca3af; font-size:0.75rem;">Role cannot be changed here.</small>
        </div>
        <div class="field">
  <label>Category <span class="req">*</span> <small style="color:#9ca3af;font-weight:400;">Select at least one</small></label>
  <div id="editCategoryList" style="border:1.5px solid #e5e7eb;border-radius:8px;padding:10px 12px;display:flex;flex-direction:column;gap:8px;">
    <?php foreach ($allCategories as $cat):
      $parts = explode(' / ', $cat['category_name'], 2);
      $label = count($parts) === 2 ? trim($parts[1]) : $cat['category_name'];
    ?>
    <label style="display:flex;align-items:center;gap:8px;font-size:0.875rem;cursor:pointer;font-weight:400;">
      <input type="checkbox" name="categories[]" value="<?= htmlspecialchars($label) ?>"
             class="edit-cat-checkbox"
             style="width:15px;height:15px;accent-color:#3b82f6;cursor:pointer;"/>
      <?= htmlspecialchars($label) ?>
    </label>
    <?php endforeach; ?>
  </div>
</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-ghost-sm" onclick="closeModal('editModal')">Cancel</button>
        <button type="submit" class="btn-primary-sm">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Deactivate Confirmation Modal ─────────────────────────────────── -->
<div class="modal-overlay" id="deactivateModal">
  <div class="modal" style="max-width:420px;">
    <div class="modal-header" style="border-bottom:1px solid #fef3c7; background:#fffbeb;">
      <h3 style="color:#92400e; display:flex; align-items:center; gap:8px;">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#d97706"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
          <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
        </svg>
        Pause Ticket Assignments
      </h3>
      <button class="modal-close" onclick="closeModal('deactivateModal')">✕</button>
    </div>
    <div class="modal-form" style="padding:20px 24px;">
      <div style="display:flex; gap:14px; align-items:flex-start; margin-bottom:18px;">
        <div style="width:44px;height:44px;border-radius:50%;background:#fef3c7;display:flex;align-items:center;
                    justify-content:center;flex-shrink:0;">
          <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#d97706"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/>
          </svg>
        </div>
        <div>
          <p style="margin:0 0 6px;font-weight:600;color:#1f2937;font-size:0.95rem;">
            Pause <span id="deactivateName" style="color:#d97706;"></span>?
          </p>
          <p style="margin:0;font-size:0.85rem;color:#6b7280;line-height:1.55;">
            This staff member can still <strong>log in</strong> and handle their existing tickets,
            but will <strong>stop receiving new ticket assignments</strong>.
          </p>
        </div>
      </div>
      <div style="background:#fef9ec;border:1px solid #fde68a;border-radius:8px;
                  padding:10px 14px;font-size:0.8rem;color:#92400e;display:flex;gap:8px;align-items:flex-start;">
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="#d97706"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px;">
          <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/>
          <line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        You can reactivate this staff at any time to resume assignments.
      </div>
    </div>
    <div class="modal-footer" style="border-top:1px solid #f1f5f9; padding:14px 24px;">
      <button type="button" class="btn-ghost-sm" onclick="closeModal('deactivateModal')">Cancel</button>
      <button type="button" id="deactivateConfirmBtn"
              style="background:#d97706;color:#fff;border:none;padding:7px 18px;border-radius:8px;
                     font-size:0.82rem;font-weight:600;cursor:pointer;transition:background .15s;"
              onmouseenter="this.style.background='#b45309'"
              onmouseleave="this.style.background='#d97706'">
        Yes, Pause Assignments
      </button>
    </div>
  </div>
</div>

<!-- ── Delete Confirmation Modal ──────────────────────────────────────── -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal" style="max-width:420px;">
    <div class="modal-header" style="border-bottom:1px solid #fee2e2; background:#fef2f2;">
      <h3 style="color:#991b1b; display:flex; align-items:center; gap:8px;">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#ef4444"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <polyline points="3 6 5 6 21 6"/>
          <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
          <path d="M10 11v6"/><path d="M14 11v6"/>
          <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
        </svg>
        Delete Staff Member
      </h3>
      <button class="modal-close" onclick="closeModal('deleteModal')">✕</button>
    </div>
    <div class="modal-form" style="padding:20px 24px;">
      <div style="display:flex; gap:14px; align-items:flex-start; margin-bottom:18px;">
        <div style="width:44px;height:44px;border-radius:50%;background:#fee2e2;display:flex;align-items:center;
                    justify-content:center;flex-shrink:0;">
          <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#ef4444"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="3 6 5 6 21 6"/>
            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
            <path d="M10 11v6"/><path d="M14 11v6"/>
            <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
          </svg>
        </div>
        <div>
          <p style="margin:0 0 6px; font-weight:600; color:#1f2937; font-size:0.95rem;">
            Delete <span id="deleteModalName" style="color:#ef4444;"></span>?
          </p>
          <p style="margin:0; font-size:0.85rem; color:#6b7280; line-height:1.55;">
            This action is <strong>permanent</strong> and cannot be undone. The staff member will be completely removed from the system.
          </p>
        </div>
      </div>
      <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:8px;
                  padding:10px 14px; font-size:0.8rem; color:#991b1b; display:flex; gap:8px; align-items:flex-start;">
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="#ef4444"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0; margin-top:1px;">
          <circle cx="12" cy="12" r="10"/>
          <line x1="12" y1="8" x2="12" y2="12"/>
          <line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        Make sure all open and in-progress tickets are reassigned before deleting.
      </div>
    </div>
    <div class="modal-footer" style="border-top:1px solid #f1f5f9; padding:14px 24px;">
      <button type="button" class="btn-ghost-sm" onclick="closeModal('deleteModal')">Cancel</button>
      <button type="button" id="deleteConfirmBtn"
              style="background:#ef4444; color:#fff; border:none; padding:7px 18px; border-radius:8px;
                     font-size:0.82rem; font-weight:600; cursor:pointer; transition:background .15s;"
              onmouseenter="this.style.background='#dc2626'"
              onmouseleave="this.style.background='#ef4444'">
        Yes, Delete Staff
      </button>
    </div>
  </div>
</div>

<!-- Hidden delete form -->
<form method="POST" id="deleteForm" style="display:none">
  <input type="hidden" name="action"   value="delete"/>
  <input type="hidden" name="staff_id" id="deleteStaffId"/>
</form>

<?php include '_foot_scripts.php'; ?>
<script>
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

function openViewModal(id, name, email, phone, role, status, code) {
  document.getElementById('viewName').textContent   = name;
  document.getElementById('viewCode').textContent   = code;
  document.getElementById('viewEmail').textContent  = email || '—';
  document.getElementById('viewPhone').textContent  = phone || '—';
  document.getElementById('viewRole').textContent   = role.charAt(0).toUpperCase() + role.slice(1);
  // Fetch categories for view modal too
document.getElementById('viewCategory').textContent = 'Loading...';
fetch('get_staff_categories.php?staff_id=' + id)
  .then(r => r.json())
  .then(data => {
    const el = document.getElementById('viewCategory');
    if (!data.categories.length) {
      el.textContent = '—';
    } else {
      el.innerHTML = data.categories.map(c =>
        `<span style="display:inline-block;font-size:11px;background:#f0f9ff;color:#0369a1;border:1px solid #bae6fd;border-radius:4px;padding:1px 7px;margin:1px 2px 1px 0;">${c}</span>`
      ).join('');
    }
  });
  document.getElementById('viewStatus').textContent = status === 'inactive' ? 'Paused' : 'Active';
  document.getElementById('viewAvatar').textContent = name.charAt(0).toUpperCase();
  document.getElementById('viewAvatar').style.background = role === 'admin' ? '#6366f1' : '#0ea5e9';
  openModal('viewModal');
}

function confirmDelete(id, name) {
  document.getElementById('deleteModalName').textContent = name;
  document.getElementById('deleteStaffId').value = id;
  openModal('deleteModal');
}

document.getElementById('deleteConfirmBtn').addEventListener('click', function() {
  closeModal('deleteModal');
  document.getElementById('deleteForm').submit();
});

function validateAddForm() {
  const code = document.getElementById('staffCodeInput').value.trim();
  if (!/^\d{6}$/.test(code)) {
    alert('Staff code must be exactly 6 digits (numbers only).');
    document.getElementById('staffCodeInput').focus();
    return false;
  }
  return true;
}

document.getElementById('phoneInput').addEventListener('input', function () {
  this.value = this.value.replace(/\D/g, '').slice(0, 11);
});

// Close modal on backdrop click
document.querySelectorAll('.modal-overlay').forEach(el => {
  el.addEventListener('click', e => { if (e.target === el) el.classList.remove('open'); });
});

// ── Reset Password eye toggle
const resetPwInput = document.getElementById('resetPasswordInput');
const resetEyeIcon = document.getElementById('resetEyeIcon');
const eyeOpen = `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>`;
const eyeShut = `<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19M1 1l22 22"/>`;

// Validate reset password length on submit
document.getElementById('resetPasswordInput').closest('form').addEventListener('submit', function(e) {
  const pw = document.getElementById('resetPasswordInput').value;
  if (pw.length < 8 || pw.length > 10) {
    e.preventDefault();
    alert('Password must be between 8 and 10 characters.');
    document.getElementById('resetPasswordInput').focus();
  }
});

document.getElementById('resetPwToggle').addEventListener('click', function () {
  const isPw = resetPwInput.type === 'password';
  resetPwInput.type = isPw ? 'text' : 'password';
  resetEyeIcon.innerHTML = isPw ? eyeShut : eyeOpen;
});

// Reset modal — clear field and restore eye icon each time it opens
function openResetModal(id, name) {
  document.getElementById('resetStaffId').value = id;
  document.getElementById('resetName').textContent = name;
  resetPwInput.type = 'password';
  resetPwInput.value = '';
  resetEyeIcon.innerHTML = eyeOpen;
  openModal('resetModal');
}

// Auto-clear Add modal fields on open (prevent browser autofill lingering)
const _baseOpenModal = openModal;
function openModal(id) {
  document.getElementById(id).classList.add('open');
  if (id === 'addModal') {
    document.querySelector('#addModal input[name="email"]').value = '';
    addPwInput.value = '';
    addPwInput.type = 'password';
    addEyeIcon.innerHTML = eyeOpen;
  }
}

// ── Add Staff password eye toggle
const addPwInput = document.getElementById('addPasswordInput');
const addEyeIcon = document.getElementById('addEyeIcon');

document.getElementById('addPwToggle').addEventListener('click', function () {
  const isPw = addPwInput.type === 'password';
  addPwInput.type = isPw ? 'text' : 'password';
  addEyeIcon.innerHTML = isPw ? eyeShut : eyeOpen;
});

// ── Edit Staff modal ───────────────────────────────────────────────────
function openEditModal(id, name, email, phone, role, code) {
  document.getElementById('editStaffId').value         = id;
  document.getElementById('editModalName').textContent = name;
  document.getElementById('editStaffCode').value       = code || '';
  document.getElementById('editFullName').value        = name;
  document.getElementById('editEmail').value           = email;
  document.getElementById('editPhone').value           = phone || '';
  document.getElementById('editRoleDisplay').value     = role.charAt(0).toUpperCase() + role.slice(1);

  // Uncheck all first
  // Hide category section for HOD
  const catField = document.getElementById('editCategoryList').closest('.field');
  if (role === 'hod') {
    catField.style.display = 'none';
  } else {
    catField.style.display = '';
  }

  document.querySelectorAll('.edit-cat-checkbox').forEach(cb => cb.checked = false);

  // Fetch this staff's current categories from server and tick them
  fetch('get_staff_categories.php?staff_id=' + id)
    .then(r => r.json())
    .then(data => {
      data.categories.forEach(function(cat) {
        document.querySelectorAll('.edit-cat-checkbox').forEach(cb => {
          if (cb.value === cat) cb.checked = true;
        });
      });
    });

  openModal('editModal');
}

function validateEditForm() {
  const code = document.getElementById('editStaffCode').value.trim();
  if (!/^\d{6}$/.test(code)) {
    alert('Staff code must be exactly 6 digits (numbers only).');
    document.getElementById('editStaffCode').focus();
    return false;
  }
  const phone = document.getElementById('editPhone').value.trim();
  if (phone !== '' && !/^\d{10,11}$/.test(phone)) {
    alert('Phone number must be 10 or 11 digits (numbers only).');
    document.getElementById('editPhone').focus();
    return false;
  }
  return true;
}

document.getElementById('editPhone').addEventListener('input', function () {
  this.value = this.value.replace(/\D/g, '').slice(0, 11);
});

// ── Deactivate confirmation modal ──────────────────────────────────────
let _deactivateForm = null;

document.querySelectorAll('form').forEach(function(form) {
  var actionInput = form.querySelector('input[name="action"]');
  if (actionInput && actionInput.value === 'toggle_status') {
    var newStatus = form.querySelector('input[name="new_status"]');
    if (newStatus && newStatus.value === 'inactive') {
      form.addEventListener('submit', function(e) {
        e.preventDefault();
        // Grab the staff name from the same table row
        var row   = form.closest('tr');
        var name  = row ? row.querySelector('.user-name')?.textContent.trim() : 'this staff';
        document.getElementById('deactivateName').textContent = name;
        _deactivateForm = form;
        openModal('deactivateModal');
      });
    }
  }
});

document.getElementById('deactivateConfirmBtn').addEventListener('click', function() {
  if (_deactivateForm) {
    closeModal('deactivateModal');
    _deactivateForm.submit();
    _deactivateForm = null;
  }
});

['alertSuccess', 'alertError'].forEach(function(id) {
  const el = document.getElementById(id);
  if (!el) return;       // ✅ safely skips if alert isn't shown
  setTimeout(function() {
    el.style.transition = 'opacity 0.6s ease';
    el.style.opacity = '0';         // ✅ fades out smoothly
    setTimeout(function() { el.style.display = 'none'; }, 600); // ✅ removes space after fade
  }, 4000);              // ✅ 4 second delay
});

</script>
</body>
</html>