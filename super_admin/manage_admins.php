<?php
// super_admin/manage_admins.php
session_start();
if (empty($_SESSION['staff_role']) || $_SESSION['staff_role'] !== 'super_admin') {
    header("Location: ../staff_login.php");
    exit;
}

$activePage = 'admins';
$pageTitle  = 'Manage Admins';

require_once '../db_connect.php';

// ── Department list (for dropdown) ────────────────────────────────────────────
$deptResult = $conn->query("SELECT dept_id, dept_name FROM departments ORDER BY dept_id");
$departments = $deptResult->fetch_all(MYSQLI_ASSOC);

// ── Handle POST actions ───────────────────────────────────────────────────────
$successMsg = '';
$errorMsg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

   // ── ADD Admin ──────────────────────────────────────────────────────────────
    if ($action === 'add') {
       $fullName   = trim($_POST['full_name'] ?? '');
$email      = trim($_POST['email'] ?? '');
$phone      = trim($_POST['phone'] ?? '');
$deptId     = (int)($_POST['dept_id'] ?? 0);
$password   = $_POST['password'] ?? '';

if (!$fullName || !$email || !$deptId || !$password) {
    $errorMsg = 'Please fill in all required fields.';
} elseif ($phone !== '' && (!ctype_digit($phone) || strlen($phone) < 10 || strlen($phone) > 11)) {
    $errorMsg = 'Phone number must contain only digits and be 10 to 11 numbers long.';
} else {
    // Check max 2 admins per department
            $cntStmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM staff WHERE role='admin' AND dept_id=?");
            $cntStmt->bind_param('i', $deptId);
            $cntStmt->execute();
            $deptAdminCount = (int)$cntStmt->get_result()->fetch_assoc()['cnt'];

            if ($deptAdminCount >= 2) {
                $errorMsg = 'This department already has 2 admins (maximum). Please remove one before adding another.';
            } else {
                // Check duplicate email
                $check = $conn->prepare("SELECT staff_id FROM staff WHERE email = ?");
                $check->bind_param('s', $email);
                $check->execute();
                $check->store_result();

                if ($check->num_rows > 0) {
                    $errorMsg = 'An account with this email already exists.';
                } else {
                    // Generate staff_code: A00XXXX
                    $maxCode = $conn->query("SELECT MAX(CAST(SUBSTRING(staff_code,2) AS UNSIGNED)) AS m FROM staff WHERE staff_code LIKE 'A%'")->fetch_assoc()['m'] ?? 0;
                    $staffCode = 'A' . str_pad((int)$maxCode + 1, 6, '0', STR_PAD_LEFT);

                    // Get dept_name
                    $deptName = '';
                    foreach ($departments as $d) {
                        if ((int)$d['dept_id'] === $deptId) { $deptName = $d['dept_name']; break; }
                    }

                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $conn->prepare("INSERT INTO staff (staff_code, full_name, email, password_hash, department, dept_id, role, phone, status) VALUES (?, ?, ?, ?, ?, ?, 'admin', ?, 'active')");
                    $stmt->bind_param('sssssss', $staffCode, $fullName, $email, $hash, $deptName, $deptId, $phone);
                    if ($stmt->execute()) {
                        $successMsg = "Admin <strong>" . htmlspecialchars($fullName) . "</strong> added successfully.";
                    } else {
                        $errorMsg = 'Database error: ' . htmlspecialchars($conn->error);
                    }
                }
            }
        }
    }

    // ── TOGGLE STATUS ──
    if ($action === 'toggle_status') {
        $staffId   = (int)($_POST['staff_id'] ?? 0);
        $newStatus = ($_POST['new_status'] ?? '') === 'active' ? 'active' : 'inactive';
        $stmt = $conn->prepare("UPDATE staff SET status = ? WHERE staff_id = ? AND role = 'admin'");
        $stmt->bind_param('si', $newStatus, $staffId);
        $stmt->execute();
        $successMsg = 'Admin status updated.';
    }

    // ── RESET PASSWORD ─────────────────────────────────────────────────────────
    if ($action === 'reset_password') {
        $staffId     = (int)($_POST['staff_id'] ?? 0);
        $newPassword = $_POST['new_password'] ?? '';
        if (strlen($newPassword) < 8 || strlen($newPassword) > 10) {
            $errorMsg = 'Password must be between 8 and 10 characters.';
        } else {
            $hash = password_hash($newPassword, PASSWORD_BCRYPT);
            $stmt = $conn->prepare("UPDATE staff SET password_hash = ? WHERE staff_id = ? AND role = 'admin'");
            $stmt->bind_param('si', $hash, $staffId);
            $stmt->execute();
            $successMsg = 'Password reset successfully.';
        }
    }

    // ── DEMOTE Admin TO STAFF ──────────────────────────────────────────────────
    if ($action === 'demote_to_staff') {
        $staffId = (int)($_POST['staff_id'] ?? 0);

        $deptCheck = $conn->prepare("SELECT dept_id, full_name FROM staff WHERE staff_id=? AND role='admin'");
        $deptCheck->bind_param('i', $staffId);
        $deptCheck->execute();
        $deptRow = $deptCheck->get_result()->fetch_assoc();

        if (!$deptRow) {
            $errorMsg = 'Admin not found.';
        } else {
            $thisDeptId = (int)$deptRow['dept_id'];
            $remainCheck = $conn->prepare("SELECT COUNT(*) AS cnt FROM staff WHERE role='admin' AND dept_id=? AND staff_id != ?");
            $remainCheck->bind_param('ii', $thisDeptId, $staffId);
            $remainCheck->execute();
            $remainCount = (int)$remainCheck->get_result()->fetch_assoc()['cnt'];

            if ($remainCount < 1) {
                $errorMsg = 'Cannot demote — each department must have at least 1 admin. Please assign another admin first.';
            } else {
                $stmt = $conn->prepare("UPDATE staff SET role='staff', category=NULL WHERE staff_id=? AND role='admin'");
                $stmt->bind_param('i', $staffId);
                $stmt->execute();
                $successMsg = 'Admin <strong>' . htmlspecialchars($deptRow['full_name']) . '</strong> has been demoted to Staff.';
            }
        }
    }

    // ── DELETE Admin ───────────────────────────────────────────────────────────
    if ($action === 'delete') {
        $staffId = (int)($_POST['staff_id'] ?? 0);

        // Get this admin's dept_id
        $deptCheck = $conn->prepare("SELECT dept_id FROM staff WHERE staff_id=? AND role='admin'");
        $deptCheck->bind_param('i', $staffId);
        $deptCheck->execute();
        $deptRow = $deptCheck->get_result()->fetch_assoc();

        if (!$deptRow) {
            $errorMsg = 'Admin not found.';
        } else {
            $thisDeptId = (int)$deptRow['dept_id'];

            // ── Check for open/in-progress tickets assigned to this admin ──
            $ticketCheck = $conn->prepare("
                SELECT COUNT(*) AS cnt FROM complaints
                WHERE assigned_to = ? AND status IN ('open', 'in_progress')
            ");
            $ticketCheck->bind_param('i', $staffId);
            $ticketCheck->execute();
            $activeTickets = (int)$ticketCheck->get_result()->fetch_assoc()['cnt'];

            if ($activeTickets > 0) {
                $errorMsg = "Cannot delete — this admin has <strong>{$activeTickets} active ticket(s)</strong> (open or in progress) still assigned to them. Please resolve or reassign all tickets before deleting.";
            } else {
                // Count remaining admins in this department (excluding current)
                $remainCheck = $conn->prepare("SELECT COUNT(*) AS cnt FROM staff WHERE role='admin' AND dept_id=? AND staff_id != ?");
                $remainCheck->bind_param('ii', $thisDeptId, $staffId);
                $remainCheck->execute();
                $remainCount = (int)$remainCheck->get_result()->fetch_assoc()['cnt'];

                if ($remainCount < 1) {
                    $errorMsg = 'Cannot delete — each department must have at least 1 admin. Please assign another admin to this department first.';
                } else {
                    $stmt = $conn->prepare("DELETE FROM staff WHERE staff_id = ? AND role = 'admin'");
                    $stmt->bind_param('i', $staffId);
                    $stmt->execute();
                    $successMsg = 'Admin account removed.';
                }
            }
        }
    }

     // ── PROMOTE STAFF TO ADMIN ────────────────────────────────────────────────
    if ($action === 'promote_staff') {
        $staffId = (int)($_POST['staff_id'] ?? 0);

        // Get this staff member's dept_id first
        $staffDept = $conn->prepare("SELECT dept_id, full_name FROM staff WHERE staff_id=? AND role='staff'");
        $staffDept->bind_param('i', $staffId);
        $staffDept->execute();
        $staffRow = $staffDept->get_result()->fetch_assoc();

        if (!$staffRow) {
            $errorMsg = 'Staff member not found.';
        } else {
            $promoteDeptId = (int)$staffRow['dept_id'];
            // Check 2-admin cap for this department
            $capCheck = $conn->prepare("SELECT COUNT(*) AS cnt FROM staff WHERE role='admin' AND dept_id=?");
            $capCheck->bind_param('i', $promoteDeptId);
            $capCheck->execute();
            $capCount = (int)$capCheck->get_result()->fetch_assoc()['cnt'];

            if ($capCount >= 2) {
                $errorMsg = 'Cannot promote — this department already has 2 admins (maximum).';
            } else {
                $stmt = $conn->prepare("UPDATE staff SET role='admin', category=NULL WHERE staff_id=? AND role='staff'");
                $stmt->bind_param('i', $staffId);
                $stmt->execute();
                $successMsg = 'Staff member <strong>' . htmlspecialchars($staffRow['full_name']) . '</strong> promoted to Admin successfully.';
            }
        }
    }

    // ── SWAP ADMIN WITH STAFF ─────────────────────────────────────────────────
    if ($action === 'swap_admin') {
        $oldAdminId = (int)($_POST['old_admin_id'] ?? 0);
        $newAdminId = (int)($_POST['new_admin_id'] ?? 0);
        $deptId     = (int)($_POST['dept_id'] ?? 0);
        if ($oldAdminId && $newAdminId && $deptId) {
            $stmt1 = $conn->prepare("UPDATE staff SET role = 'staff' WHERE staff_id = ? AND role = 'admin'");
            $stmt1->bind_param('i', $oldAdminId);
            $stmt1->execute();
            $stmt2 = $conn->prepare("UPDATE staff SET role = 'admin' WHERE staff_id = ? AND role = 'staff'");
            $stmt2->bind_param('i', $newAdminId);
            $stmt2->execute();
            $successMsg = 'Admin swapped successfully.';
        } else {
            $errorMsg = 'Invalid swap request.';
        }
    }

} 

// ── Fetch all admins ───────────────────────────────────────────────────────────
$admins = $conn->query("
    SELECT s.staff_id, s.staff_code, s.full_name, s.email, s.phone, s.status,
           s.dept_id, d.dept_name, s.created_at
    FROM staff s
    LEFT JOIN departments d ON d.dept_id = s.dept_id
    WHERE s.role = 'admin'
    ORDER BY s.dept_id, s.staff_id
")->fetch_all(MYSQLI_ASSOC);

// Fetch staff who are NOT admins (eligible for promotion)
$eligibleStaff = $conn->query("
    SELECT s.staff_id, s.full_name, s.staff_code, s.department, s.dept_id
    FROM staff s
    WHERE s.role = 'staff'
    ORDER BY s.dept_id, s.full_name
")->fetch_all(MYSQLI_ASSOC);

// Fetch staff grouped by department (for swap dropdown)
$staffByDept = [];
$staffRows = $conn->query("
    SELECT staff_id, full_name, staff_code, dept_id
    FROM staff
    WHERE role = 'staff' AND dept_id IS NOT NULL
    ORDER BY dept_id, full_name
")->fetch_all(MYSQLI_ASSOC);
foreach ($staffRows as $row) {
    $staffByDept[(int)$row['dept_id']][] = $row;
}

// Count helpers
$totalAdmins    = count($admins);
$activeAdmins   = count(array_filter($admins, function($a){ return $a['status'] === 'active'; }));
$inactiveAdmins = $totalAdmins - $activeAdmins;

$deptColors = [
    1 => '#7C3AED',
    2 => '#059669',
    3 => '#DC2626',
    4 => '#2563EB',
    5 => '#D97706',
];
$deptShort = [
    1 => 'AFSMD',
    2 => 'Maintenance',
    3 => 'CCU',
    4 => 'IT Dept',
    5 => 'HCD',
];

include 'layout.php';
?>

<style>
  /* ── Page heading ── */
  .page-heading { margin-bottom: 28px; display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
  .page-heading-text h1 { font-family: 'DM Serif Display', serif; font-size: 28px; color: var(--gray-900); margin-bottom: 4px; }
  .page-heading-text p  { font-size: 14px; color: var(--gray-500); }

  /* ── Add button ── */
  .btn-add {
    display: inline-flex; align-items: center; gap: 8px;
    background: var(--maroon); color: white;
    font-size: 13.5px; font-weight: 600; padding: 10px 18px;
    border-radius: 9px; border: none; cursor: pointer;
    text-decoration: none; transition: background .18s, box-shadow .18s;
    box-shadow: 0 2px 8px rgba(125,17,40,.25);
    white-space: nowrap;
  }
  .btn-add:hover { background: var(--maroon-dark); box-shadow: 0 4px 14px rgba(125,17,40,.35); }
  .btn-add svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2.2; flex-shrink: 0; }

  /* ── Stat strip ── */
  .stat-strip { display: grid; grid-template-columns: repeat(3,1fr); gap: 16px; margin-bottom: 28px; }
  @media (max-width: 640px) { .stat-strip { grid-template-columns: 1fr; } }

  .strip-card {
    background: white; border: 1px solid var(--gray-200); border-radius: 12px;
    padding: 18px 20px; display: flex; align-items: center; gap: 14px;
    box-shadow: 0 1px 4px rgba(0,0,0,.04);
  }
  .strip-icon { width: 44px; height: 44px; border-radius: 11px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
  .strip-icon svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 2; }
  .strip-icon.purple  { background: #F5F3FF; color: #7C3AED; }
  .strip-icon.green   { background: #ECFDF5; color: #059669; }
  .strip-icon.red     { background: #FEF2F2; color: #DC2626; }
  .strip-val  { font-size: 28px; font-weight: 700; color: var(--gray-900); line-height: 1; margin-bottom: 3px; }
  .strip-lbl  { font-size: 12.5px; color: var(--gray-500); }

  /* ── Alert ── */
  .alert {
    padding: 12px 16px; border-radius: 9px; font-size: 13.5px;
    margin-bottom: 20px; display: flex; align-items: center; gap: 10px;
  }
  .alert-success { background: #ECFDF5; color: #065F46; border: 1px solid #A7F3D0; }
  .alert-error   { background: #FEF2F2; color: #991B1B; border: 1px solid #FECACA; }
  .alert svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2.2; flex-shrink: 0; }

  /* ── Table card ── */
  .table-card {
  background: white; border: 1px solid var(--gray-200); border-radius: 14px;
  box-shadow: 0 1px 4px rgba(0,0,0,.04); overflow: visible;
}
  .table-card-header {
    padding: 16px 20px; border-bottom: 1px solid var(--gray-100);
    display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;
  }
  .table-card-title { font-size: 13.5px; font-weight: 600; color: var(--gray-900); }
  .table-search {
    display: flex; align-items: center; gap: 8px;
    background: var(--gray-100); border-radius: 8px; padding: 7px 12px;
    border: 1px solid transparent; transition: border .18s, background .18s;
  }
  .table-search:focus-within { background: white; border-color: var(--gray-300); }
  .table-search svg { width: 14px; height: 14px; fill: none; stroke: var(--gray-500); stroke-width: 2; flex-shrink: 0; }
  .table-search input { border: none; background: transparent; outline: none; font-size: 13px; color: var(--gray-900); width: 200px; }
  .table-search input::placeholder { color: var(--gray-400); }

  .admins-table { width: 100%; border-collapse: collapse; }
  .admins-table th {
    font-size: 11px; font-weight: 600; color: var(--gray-500); text-transform: uppercase;
    letter-spacing: .06em; padding: 11px 20px; text-align: left;
    background: var(--gray-100); border-bottom: 1px solid var(--gray-200);
  }
  .admins-table td {
    font-size: 13.5px; color: var(--gray-700); padding: 14px 20px;
    border-bottom: 1px solid var(--gray-100); vertical-align: middle;
  }
  .admins-table tbody tr:last-child td { border-bottom: none; }
  .admins-table tbody tr:hover { background: var(--off-white); }

  .admin-name-cell { display: flex; align-items: center; gap: 10px; }
  .admin-avatar {
    width: 34px; height: 34px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; font-weight: 700; color: white; flex-shrink: 0;
  }
  .admin-fullname { font-weight: 600; color: var(--gray-900); font-size: 13.5px; }
  .admin-code     { font-size: 11.5px; color: var(--gray-400); margin-top: 1px; }

  .dept-pill {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 10px; border-radius: 20px;
    font-size: 11.5px; font-weight: 600;
    background: var(--gray-100); color: var(--gray-700);
  }
  .dept-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }

  .badge-active   { display:inline-block; font-size:11.5px; font-weight:600; padding:3px 10px; border-radius:20px; background:#ECFDF5; color:#065F46; }
  .badge-inactive { display:inline-block; font-size:11.5px; font-weight:600; padding:3px 10px; border-radius:20px; background:#FEF2F2; color:#991B1B; }

  /* ── Action buttons ── */
  .action-group { display: flex; align-items: center; gap: 6px; }
  .btn-icon {
    width: 32px; height: 32px; border-radius: 7px; border: 1px solid var(--gray-200);
    background: white; display: flex; align-items: center; justify-content: center;
    cursor: pointer; transition: background .15s, border-color .15s, color .15s;
    color: var(--gray-500); flex-shrink: 0;
  }
  .btn-icon svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; }
  .btn-icon:hover { background: var(--gray-100); color: var(--gray-900); border-color: var(--gray-300); }
 

/* Warning-style toggle button for Remove dropdown */
.btn-action-toggle--warn {
  border-color: #D97706;
  color: #D97706;
}
.btn-action-toggle--warn:hover {
  background: #D97706;
  color: white;
}

/* Orange top border for the Remove dropdown */
.btn-action-toggle--warn + .action-dropdown {
  border-top-color: #D97706;
  box-shadow: 0 8px 28px rgba(217,119,6,.13), 0 2px 8px rgba(0,0,0,.06);
}

/* Warning item in dropdown */
.dropdown-item--warning { color: #D97706; }
.dropdown-item--warning:hover {
  background: #FFF7ED;
  color: #D97706;
  border-left-color: #D97706;
}

  .empty-row td { text-align: center; padding: 48px 20px; color: var(--gray-400); font-size: 14px; }
  .empty-row svg { width: 32px; height: 32px; fill: none; stroke: var(--gray-300); stroke-width: 1.5; display: block; margin: 0 auto 10px; }

  /* ── MODAL ── */
  .modal-overlay {
    position: fixed; inset: 0; z-index: 200;
    background: rgba(17,24,39,.45); backdrop-filter: blur(3px);
    display: flex; align-items: center; justify-content: center;
    opacity: 0; pointer-events: none; transition: opacity .2s;
  }
  .modal-overlay.open { opacity: 1; pointer-events: all; }
  .modal {
    background: white; border-radius: 16px; width: 100%; max-width: 480px;
    box-shadow: 0 20px 60px rgba(0,0,0,.18);
    transform: translateY(12px) scale(.98); transition: transform .22s;
    overflow: hidden; max-height: 90vh; overflow-y: auto;
  }
  .modal-overlay.open .modal { transform: translateY(0) scale(1); }

  .modal-header {
    padding: 20px 24px 16px; border-bottom: 1px solid var(--gray-100);
    display: flex; align-items: center; justify-content: space-between;
  }
  .modal-title { font-size: 16px; font-weight: 700; color: var(--gray-900); }
  .modal-subtitle { font-size: 12.5px; color: var(--gray-500); margin-top: 2px; }
  .modal-close {
    width: 30px; height: 30px; border-radius: 7px; border: 1px solid var(--gray-200);
    background: white; cursor: pointer; display: flex; align-items: center; justify-content: center;
    color: var(--gray-400); transition: background .15s;
  }
  .modal-close:hover { background: var(--gray-100); color: var(--gray-700); }
  .modal-close svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2.2; }
  .modal-body  { padding: 20px 24px; }
  .modal-footer{ padding: 16px 24px; border-top: 1px solid var(--gray-100); display: flex; justify-content: flex-end; gap: 10px; }

  .form-group { margin-bottom: 16px; }
  .form-group label { display: block; font-size: 12.5px; font-weight: 600; color: var(--gray-700); margin-bottom: 6px; }
  .form-group label span { color: #DC2626; margin-left: 2px; }
  .form-control {
    width: 100%; padding: 9px 12px; border: 1px solid var(--gray-200); border-radius: 8px;
    font-size: 13.5px; color: var(--gray-900); background: white; outline: none;
    transition: border-color .18s, box-shadow .18s; font-family: 'DM Sans', sans-serif;
  }
  .form-control:focus { border-color: var(--maroon); box-shadow: 0 0 0 3px rgba(125,17,40,.08); }
  .form-control::placeholder { color: var(--gray-400); }
  select.form-control { cursor: pointer; }

  .btn-primary {
    padding: 9px 20px; background: var(--maroon); color: white;
    border: none; border-radius: 8px; font-size: 13.5px; font-weight: 600;
    cursor: pointer; transition: background .18s; font-family: 'DM Sans', sans-serif;
  }
  .btn-primary:hover { background: var(--maroon-dark); }
  .btn-secondary {
    padding: 9px 16px; background: white; color: var(--gray-700);
    border: 1px solid var(--gray-200); border-radius: 8px; font-size: 13.5px;
    font-weight: 500; cursor: pointer; transition: background .18s; font-family: 'DM Sans', sans-serif;
  }
  .btn-secondary:hover { background: var(--gray-100); }
  .btn-danger {
    padding: 9px 20px; background: #DC2626; color: white;
    border: none; border-radius: 8px; font-size: 13.5px; font-weight: 600;
    cursor: pointer; transition: background .18s; font-family: 'DM Sans', sans-serif;
  }
  .btn-danger:hover { background: #B91C1C; }

  .pw-wrap { position: relative; }
  .pw-wrap .form-control { padding-right: 40px; }
  .pw-toggle {
    position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
    background: none; border: none; cursor: pointer; color: var(--gray-400);
    padding: 4px; display: flex; align-items: center;
  }
  .pw-toggle svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2; }
  .pw-toggle:hover { color: var(--gray-700); }

  /* Confirm danger modal */
.danger-modal .modal-header { border-bottom-color: #FEE2E2; }
/* Demote warning modal */
#demoteModal .modal-header  { border-bottom-color: #FED7AA; }
  .danger-icon {
    width: 46px; height: 46px; border-radius: 12px; background: #FEF2F2;
    display: flex; align-items: center; justify-content: center; margin-bottom: 14px;
  }
  .danger-icon svg { width: 22px; height: 22px; fill: none; stroke: #DC2626; stroke-width: 2; }
  .danger-text { font-size: 14px; color: var(--gray-600); line-height: 1.6; }
  .danger-name { font-weight: 600; color: var(--gray-900); }
.btn-action-toggle {
  display: inline-flex; align-items: center;
  padding: 6px 12px; font-size: 12.5px; font-weight: 600;
  background: white; border: 1.5px solid var(--maroon);
  border-radius: 7px; cursor: pointer; color: var(--maroon);
  transition: background .15s, border-color .15s, color .15s;
  white-space: nowrap;
}
.btn-action-toggle:hover {
  background: var(--maroon); color: white;
}

.action-dropdown {
  display: none; position: absolute; right: 0; top: calc(100% + 6px);
  background: white;
  border-top: 3px solid var(--maroon);      /* ← maroon top accent */
  border-left: 1px solid var(--gray-200);
  border-right: 1px solid var(--gray-200);
  border-bottom: 1px solid var(--gray-200);
  border-radius: 0 0 12px 12px;
  box-shadow: 0 8px 28px rgba(125,17,40,.13), 0 2px 8px rgba(0,0,0,.06);
  min-width: 240px; z-index: 100;
  overflow: hidden; max-height: 260px; overflow-y: auto;
  padding: 4px 0;
}
.action-dropdown.open { display: block; }

/* Section label inside dropdown */
.action-dropdown .dropdown-section-label {
  padding: 8px 14px 4px;
  font-size: 10.5px; font-weight: 700;
  color: var(--maroon); text-transform: uppercase;
  letter-spacing: .07em;
  border-bottom: 1px solid #F3E8EB;
  margin-bottom: 2px;
}

.dropdown-item {
  display: block; width: 100%; text-align: left;
  padding: 9px 16px; font-size: 13px; font-weight: 500;
  background: none; border: none; cursor: pointer;
  color: var(--gray-700); transition: background .12s, color .12s;
  line-height: 1.4; border-left: 3px solid transparent;
}
.dropdown-item:hover {
  background: #FDF5F7;
  color: var(--maroon);
  border-left-color: var(--maroon);
}

/* Empty state */
.dropdown-item--empty {
  color: var(--gray-400); cursor: default; font-size: 12.5px;
}
.dropdown-item--empty:hover {
  background: none; color: var(--gray-400); border-left-color: transparent;
}
.dropdown-item--danger { color: #DC2626; }
.dropdown-item--danger:hover { background: #FEF2F2; }
.dropdown-item--success { color: #059669; }
.dropdown-item--success:hover { background: #ECFDF5; }
.dropdown-divider { height: 1px; background: var(--gray-100); margin: 3px 0; }

  /* ── Add Admin mode toggle ── */
  .add-mode-btn {
    flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    padding: 9px 14px; font-size: 13px; font-weight: 600; border-radius: 8px;
    border: 1.5px solid var(--gray-200); background: white; color: var(--gray-500);
    cursor: pointer; transition: all .15s; font-family: 'DM Sans', sans-serif;
  }
  .add-mode-btn:hover { background: var(--gray-100); color: var(--gray-700); }
  .add-mode-btn.active { background: var(--maroon); color: white; border-color: var(--maroon); }
</style>

<!-- ── Alert ── -->
<?php if ($successMsg): ?>
<div class="alert alert-success">
  <svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
  <?= $successMsg ?>
</div>
<?php elseif ($errorMsg): ?>
<div class="alert alert-error">
  <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
  <?= htmlspecialchars($errorMsg) ?>
</div>
<?php endif; ?>

<!-- ── Page heading ── -->
<div class="page-heading">
  <div class="page-heading-text">
    <h1>Manage Admins</h1>
    <p>Create and manage department administrator accounts.</p>
  </div>
  <button class="btn-add" onclick="openModal('addModal')">
    <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    Add Admin
  </button>
</div>



<!-- ── Table card ── -->
<div class="table-card">
  <div class="table-card-header">
    <span class="table-card-title">All Admins (<?= $totalAdmins ?>)</span>
    <div class="table-search">
      <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="text" id="searchInput" placeholder="Search by name, email or department…" oninput="filterTable()">
    </div>
  </div>

  <table class="admins-table" id="adminTable">
    <thead>
      <tr>
        <th>Admin</th>
        <th>Email</th>
        <th>Phone</th>
        <th>Department</th>
        <th>Status</th>
        <th>Added</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($admins)): ?>
      <tr class="empty-row">
        <td colspan="7">
          <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          No admin accounts found. Click <strong>Add Admin</strong> to create one.
        </td>
      </tr>
      <?php else: ?>
        <?php foreach ($admins as $admin):
          $dId    = (int)$admin['dept_id'];
          $color  = $deptColors[$dId] ?? '#888';
          $short  = $deptShort[$dId] ?? htmlspecialchars($admin['dept_name']);
          $words = array_slice(explode(' ', $admin['full_name']), 0, 2);
$initials = strtoupper(implode('', array_map(function($w){ return $w[0]; }, $words)));
          $isActive = $admin['status'] === 'active';
          $addedDate = date('j M Y', strtotime($admin['created_at']));
        ?>
        <tr data-search="<?= strtolower($admin['full_name'] . ' ' . $admin['email'] . ' ' . $admin['dept_name']) ?>">
          <td>
           <div>
  <div class="admin-fullname"><?= htmlspecialchars($admin['full_name']) ?></div>
  <div class="admin-code"><?= htmlspecialchars($admin['staff_code']) ?></div>
</div>
          </td>
          <td><?= htmlspecialchars($admin['email']) ?></td>
          <td><?= htmlspecialchars($admin['phone'] ?: '—') ?></td>
          <td>
            <span class="dept-pill">
              <span class="dept-dot" style="background:<?= $color ?>;"></span>
              <?= $short ?>
            </span>
          </td>
          <td>
            <?php if ($isActive): ?>
              <span class="badge-active">Active</span>
            <?php else: ?>
              <span class="badge-inactive">Inactive</span>
            <?php endif; ?>
          </td>
          <td><?= $addedDate ?></td>
          <td>
  <div style="display:flex; align-items:center; gap:6px;">
<!-- Remove / Delete dropdown -->
<div class="action-dropdown-wrap" style="position:relative;">
  <button class="btn-action-toggle btn-action-toggle--warn" onclick="toggleDropdown(this)" type="button">
    Remove
    <svg viewBox="0 0 24 24" style="width:12px;height:12px;fill:none;stroke:currentColor;stroke-width:2.5;margin-left:5px;">
      <polyline points="6 9 12 15 18 9"/>
    </svg>
  </button>
  <div class="action-dropdown">
    <div class="dropdown-section-label">Choose action:</div>
    <!-- Demote option -->
    <button type="button" class="dropdown-item dropdown-item--warning"
      onclick="closeAllDropdowns(); openDemoteModal(<?= $admin['staff_id'] ?>, '<?= htmlspecialchars(addslashes($admin['full_name'])) ?>')">
      👤 Demote to Staff
      <span style="font-size:11px;color:var(--gray-400);display:block;">Keep account, remove admin role</span>
    </button>
    <div class="dropdown-divider"></div>
    <!-- Delete option -->
    <button type="button" class="dropdown-item dropdown-item--danger"
      onclick="closeAllDropdowns(); openDeleteModal(<?= $admin['staff_id'] ?>, '<?= htmlspecialchars(addslashes($admin['full_name'])) ?>')">
      🗑️ Delete User Account
      <span style="font-size:11px;color:#FCA5A5;display:block;">Permanently removes the account</span>
    </button>
  </div>
</div>
  <div class="action-dropdown-wrap" style="position:relative;">
    <button class="btn-action-toggle" onclick="toggleDropdown(this)" type="button">
      Change Admin
      <svg viewBox="0 0 24 24" style="width:12px;height:12px;fill:none;stroke:currentColor;stroke-width:2.5;margin-left:5px;">
        <polyline points="6 9 12 15 18 9"/>
      </svg>
    </button>
    <div class="action-dropdown">
      <?php if (!empty($staffByDept[$dId])): ?>
        <div class="dropdown-section-label">Replace with:</div>
        <?php foreach ($staffByDept[$dId] as $s): ?>
        <form method="POST" style="margin:0;">
          <input type="hidden" name="action"       value="swap_admin">
          <input type="hidden" name="old_admin_id" value="<?= $admin['staff_id'] ?>">
          <input type="hidden" name="new_admin_id" value="<?= $s['staff_id'] ?>">
          <input type="hidden" name="dept_id"      value="<?= $dId ?>">
          <button type="submit" class="dropdown-item">
            👤 <?= htmlspecialchars($s['full_name']) ?>
            <span style="font-size:11px;color:var(--gray-400);display:block;"><?= htmlspecialchars($s['staff_code']) ?></span>
          </button>
        </form>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="dropdown-item" style="color:var(--gray-400);cursor:default;">
          No staff in this department
        </div>
      <?php endif; ?>
    </div>
  </div>
  </div><!-- /.flex wrapper -->
</td>



        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- ══════════════════════════════════════════════════════════
     MODAL: Add Admin (3-mode)
════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="addModal">
  <div class="modal" style="max-width:520px;">
    <div class="modal-header">
      <div>
        <div class="modal-title">Add Admin</div>
        <div class="modal-subtitle" id="addModalSubtitle">Choose how to add a new admin</div>
      </div>
      <button class="modal-close" onclick="closeModal('addModal')">
        <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>

    <!-- Mode selector -->
    <div style="padding:16px 24px 0; display:flex; gap:8px;">
      <button type="button" class="add-mode-btn active" id="modeBtn0" onclick="switchAddMode(0)">
        <svg viewBox="0 0 24 24" style="width:14px;height:14px;fill:none;stroke:currentColor;stroke-width:2;"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
        New User
      </button>
      <button type="button" class="add-mode-btn" id="modeBtn1" onclick="switchAddMode(1)">
        <svg viewBox="0 0 24 24" style="width:14px;height:14px;fill:none;stroke:currentColor;stroke-width:2;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        From Existing Staff
      </button>
    </div>

    <!-- ── MODE 0: Brand new user as admin ── -->
    <form method="POST" id="addFormNew">
      <input type="hidden" name="action" value="add">
      <div class="modal-body">
        <div class="form-group">
          <label>Full Name <span>*</span></label>
          <input type="text" name="full_name" class="form-control" placeholder="e.g. Ahmad Razif bin Hamid" required>
        </div>
        <div class="form-group">
          <label>Email Address <span>*</span></label>
          <input type="email" name="email" class="form-control" placeholder="admin@unikl.edu.my" required>
        </div>
        <div class="form-group">
          <label>Phone Number</label>
          <input type="text" name="phone" id="addPhone" class="form-control" 
       placeholder="e.g. 0187001010"
       inputmode="numeric"
       pattern="[0-9]{10,11}"
       maxlength="11"
       oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0,11)">
        </div>
        <div class="form-group">
          <label>Department <span>*</span></label>
          <select name="dept_id" class="form-control" required>
            <option value="">— Select Department —</option>
            <?php foreach ($departments as $d): ?>
            <option value="<?= $d['dept_id'] ?>"><?= htmlspecialchars($d['dept_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Password <span>*</span></label>
          <div class="pw-wrap">
            <input type="password" name="password" id="addPw" class="form-control" placeholder="8–10 characters" required minlength="8" maxlength="10">
            <button type="button" class="pw-toggle" onclick="togglePw('addPw', this)">
              <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-secondary" onclick="closeModal('addModal')">Cancel</button>
        <button type="submit" class="btn-primary">Create Admin</button>
      </div>
    </form>

    <!-- ── MODE 1: Promote existing staff ── -->
    <form method="POST" id="addFormStaff" style="display:none;">
      <input type="hidden" name="action" value="promote_staff">
      <div class="modal-body">
        <div style="background:#FFF7ED;border:1px solid #FED7AA;border-radius:9px;padding:11px 14px;font-size:13px;color:#92400E;margin-bottom:16px;display:flex;align-items:flex-start;gap:8px;">
          <svg viewBox="0 0 24 24" style="width:15px;height:15px;flex-shrink:0;margin-top:1px;fill:none;stroke:#D97706;stroke-width:2;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          The selected staff member will be <strong>promoted to Admin</strong>. Their existing account and tickets will be kept.
        </div>
        <div class="form-group">
          <label>Select Staff Member <span>*</span></label>
          <select name="staff_id" id="promoteStaffSelect" class="form-control" required onchange="updatePromoteInfo(this)">
            <option value="">— Choose a staff member —</option>
            <?php 
            $deptShortMap = [1=>'AFSMD', 2=>'Maintenance', 3=>'CCU', 4=>'IT Dept', 5=>'HCD'];
            foreach ($eligibleStaff as $s): 
              $deptLabel = $deptShortMap[(int)$s['dept_id']] ?? htmlspecialchars($s['department']);
            ?>
            <option value="<?= $s['staff_id'] ?>"
                    data-dept="<?= htmlspecialchars($s['department']) ?>"
                    data-code="<?= htmlspecialchars($s['staff_code']) ?>">
              <?= htmlspecialchars($s['full_name']) ?> (<?= $deptLabel ?>)
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <!-- Info card shown after selection -->
        <div id="promoteInfoCard" style="display:none;background:#F0FDF4;border:1px solid #A7F3D0;border-radius:9px;padding:12px 14px;font-size:13px;color:#065F46;">
          <div style="font-weight:700;margin-bottom:3px;" id="promoteInfoName"></div>
          <div style="color:#6B7280;font-size:12px;" id="promoteInfoMeta"></div>
        </div>
        <?php if (empty($eligibleStaff)): ?>
        <p style="font-size:13px;color:var(--gray-400);text-align:center;padding:12px 0;">No eligible staff found. All staff may already be admins.</p>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-secondary" onclick="closeModal('addModal')">Cancel</button>
        <button type="submit" class="btn-primary" <?= empty($eligibleStaff) ? 'disabled' : '' ?>>Promote to Admin</button>
      </div>
    </form>

  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     MODAL: Reset Password
════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="resetModal">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title">Reset Password</div>
        <div class="modal-subtitle" id="resetModalSubtitle"></div>
      </div>
      <button class="modal-close" onclick="closeModal('resetModal')">
        <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="reset_password">
      <input type="hidden" name="staff_id" id="resetStaffId">
      <div class="modal-body">
        <div class="form-group">
          <label>New Password <span>*</span></label>
          <div class="pw-wrap">
            <input type="password" name="new_password" id="resetPw" class="form-control" placeholder="8–10 characters" required minlength="8" maxlength="10">
            <button type="button" class="pw-toggle" onclick="togglePw('resetPw', this)">
              <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-secondary" onclick="closeModal('resetModal')">Cancel</button>
        <button type="submit" class="btn-primary">Reset Password</button>
      </div>
    </form>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     MODAL: Toggle Status (Activate / Deactivate)
════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="toggleModal">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title" id="toggleModalTitle">Change Status</div>
      </div>
      <button class="modal-close" onclick="closeModal('toggleModal')">
        <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="toggle_status">
      <input type="hidden" name="staff_id" id="toggleStaffId">
      <input type="hidden" name="new_status" id="toggleNewStatus">
      <div class="modal-body">
        <div class="danger-icon" id="toggleIcon">
          <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
        </div>
        <p class="danger-text" id="toggleModalBody"></p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-secondary" onclick="closeModal('toggleModal')">Cancel</button>
        <button type="submit" class="btn-primary" id="toggleSubmitBtn">Confirm</button>
      </div>
    </form>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     MODAL: Demote to Staff
════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="demoteModal">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title">Demote to Staff</div>
      </div>
      <button class="modal-close" onclick="closeModal('demoteModal')">
        <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="demote_to_staff">
      <input type="hidden" name="staff_id" id="demoteStaffId">
      <div class="modal-body">
        <div class="danger-icon" style="background:#FFF7ED;">
          <svg viewBox="0 0 24 24" style="stroke:#D97706;">
            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
            <circle cx="9" cy="7" r="4"/>
            <line x1="23" y1="11" x2="17" y2="11"/>
          </svg>
        </div>
        <p class="danger-text">
          This will remove the admin role from <span class="danger-name" id="demoteAdminName"></span>
          and convert them back to a <strong>regular staff member</strong>.
          Their account and ticket history will be <strong>kept</strong>.
        </p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-secondary" onclick="closeModal('demoteModal')">Cancel</button>
        <button type="submit" class="btn-primary" style="background:#D97706;">Demote to Staff</button>
      </div>
    </form>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     MODAL: Delete Admin
════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal danger-modal">
    <div class="modal-header">
      <div>
        <div class="modal-title">Delete User Account</div>
      </div>
      <button class="modal-close" onclick="closeModal('deleteModal')">
        <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="staff_id" id="deleteStaffId">
      <div class="modal-body">
        <div class="danger-icon">
          <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
        </div>
        <p class="danger-text">
          Are you sure you want to <strong>permanently delete</strong> the account of 
<span class="danger-name" id="deleteAdminName"></span>? 
This simulates the user <strong>leaving the organisation</strong> — their account will be fully removed and cannot be recovered.
        </p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-secondary" onclick="closeModal('deleteModal')">Cancel</button>
        <button type="submit" class="btn-danger">Delete User</button>
      </div>
    </form>
  </div>
</div>

<script>
// ── Modal helpers ──────────────────────────────────────────
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

// Close on overlay click
document.querySelectorAll('.modal-overlay').forEach(overlay => {
  overlay.addEventListener('click', e => { if (e.target === overlay) closeModal(overlay.id); });
});

// ── Reset password ─────────────────────────────────────────
function openResetModal(id, name) {
  document.getElementById('resetStaffId').value = id;
  document.getElementById('resetModalSubtitle').textContent = 'For: ' + name;
  document.getElementById('resetPw').value = '';
  openModal('resetModal');
}

// ── Toggle status ──────────────────────────────────────────
function openToggleModal(id, name, newStatus) {
  document.getElementById('toggleStaffId').value   = id;
  document.getElementById('toggleNewStatus').value = newStatus;

  const isDeactivate = newStatus === 'inactive';
  document.getElementById('toggleModalTitle').textContent = isDeactivate ? 'Deactivate Admin' : 'Activate Admin';
  document.getElementById('toggleModalBody').innerHTML =
    (isDeactivate
      ? 'This will <strong>disable login access</strong> for '
      : 'This will <strong>restore login access</strong> for ')
    + '<span class="danger-name">' + name + '</span>. You can reverse this at any time.';
  document.getElementById('toggleSubmitBtn').textContent = isDeactivate ? 'Deactivate' : 'Activate';
  document.getElementById('toggleSubmitBtn').style.background = isDeactivate ? '#DC2626' : '#059669';
  openModal('toggleModal');
}

function openDemoteModal(id, name) {
  document.getElementById('demoteStaffId').value = id;
  document.getElementById('demoteAdminName').textContent = name;
  openModal('demoteModal');
}

// ── Delete ─────────────────────────────────────────────────
function openDeleteModal(id, name) {
  document.getElementById('deleteStaffId').value = id;
  document.getElementById('deleteAdminName').textContent = name;
  openModal('deleteModal');
}

// ── Password toggle ────────────────────────────────────────
function togglePw(inputId, btn) {
  const input = document.getElementById(inputId);
  const show  = input.type === 'password';
  input.type  = show ? 'text' : 'password';
  btn.innerHTML = show
    ? '<svg viewBox="0 0 24 24" style="width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:2"><line x1="1" y1="1" x2="23" y2="23"/><path d="M10.43 10.45A3 3 0 0 0 12 12a3 3 0 0 0 1.55-5.55M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/></svg>'
    : '<svg viewBox="0 0 24 24" style="width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
}

// ── Table search ───────────────────────────────────────────
function filterTable() {
  const q = document.getElementById('searchInput').value.toLowerCase();
  document.querySelectorAll('#adminTable tbody tr:not(.empty-row)').forEach(row => {
    row.style.display = (row.dataset.search || '').includes(q) ? '' : 'none';
  });
}

// ── Auto-dismiss success alert ─────────────────────────────
const alertEl = document.querySelector('.alert');
if (alertEl) setTimeout(() => { alertEl.style.transition='opacity .4s'; alertEl.style.opacity='0'; setTimeout(()=>alertEl.remove(),400); }, 4000);

function toggleDropdown(btn) {
  const wrap = btn.closest('.action-dropdown-wrap');
  const dd = wrap.querySelector('.action-dropdown');
  const isOpen = dd.classList.contains('open');
  closeAllDropdowns();
  if (!isOpen) {
    dd.classList.add('open');
    // Check if dropdown goes below screen, if so open upward
    const rect = dd.getBoundingClientRect();
    if (rect.bottom > window.innerHeight) {
      dd.style.top = 'auto';
      dd.style.bottom = 'calc(100% + 4px)';
    } else {
      dd.style.top = 'calc(100% + 4px)';
      dd.style.bottom = 'auto';
    }
  }
}
// ── Add Admin mode switch ──────────────────────────────────
function switchAddMode(mode) {
  document.getElementById('addFormNew').style.display   = mode === 0 ? '' : 'none';
  document.getElementById('addFormStaff').style.display = mode === 1 ? '' : 'none';
  document.getElementById('modeBtn0').classList.toggle('active', mode === 0);
  document.getElementById('modeBtn1').classList.toggle('active', mode === 1);
  const subtitles = ['Create a brand new admin account', 'Promote an existing staff member'];
  document.getElementById('addModalSubtitle').textContent = subtitles[mode];
}

function updatePromoteInfo(sel) {
  const card = document.getElementById('promoteInfoCard');
  if (!sel.value) { card.style.display = 'none'; return; }
  const opt = sel.options[sel.selectedIndex];
  document.getElementById('promoteInfoName').textContent = opt.text.split(' — ')[0];
  document.getElementById('promoteInfoMeta').textContent =
    'Code: ' + opt.dataset.code + '  ·  Dept: ' + opt.dataset.dept;
  card.style.display = 'block';
}

// Reset modal state when reopened
const addModalEl = document.getElementById('addModal');
addModalEl.addEventListener('transitionend', function(e) {
  // Only fire once (opacity transition) and only when opening
  if (e.propertyName !== 'opacity') return;
  if (this.classList.contains('open')) {
    switchAddMode(0);
    document.getElementById('promoteStaffSelect').value = '';
    document.getElementById('promoteInfoCard').style.display = 'none';
  }
});

function closeAllDropdowns() {
  document.querySelectorAll('.action-dropdown.open').forEach(d => d.classList.remove('open'));
}
document.addEventListener('click', e => {
  if (!e.target.closest('.action-dropdown-wrap')) closeAllDropdowns();
});


</script>




</div><!-- /.page-body -->
</div><!-- /.main-content -->

</body>
</html>