<?php
// super_admin/manage_staff.php
session_start();
if (empty($_SESSION['staff_role']) || $_SESSION['staff_role'] !== 'super_admin') {
    header("Location: ../staff_login.php");
    exit;
}

$activePage = 'staff';
$pageTitle  = 'Manage Staff';

require_once '../db_connect.php';

// ── Departments & Categories ───────────────────────────────────────────────────
$departments = $conn->query("SELECT dept_id, dept_name FROM departments ORDER BY dept_id")->fetch_all(MYSQLI_ASSOC);
$categories  = $conn->query("SELECT category_id, category_name, dept_id FROM categories ORDER BY dept_id, category_name")->fetch_all(MYSQLI_ASSOC);

// ── Handle POST actions ───────────────────────────────────────────────────────
$successMsg = '';
$errorMsg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $fullName   = trim($_POST['full_name']   ?? '');
        $email      = trim($_POST['email']       ?? '');
        $phone      = trim($_POST['phone']       ?? '');
        $deptId     = (int)($_POST['dept_id']    ?? 0);
        $categoryIds = array_map('intval', $_POST['category_ids'] ?? []);
$categoryId  = !empty($categoryIds) ? $categoryIds[0] : 0;
        $password   = $_POST['password'] ?? '';

if (!$fullName || !$email || !$deptId || !$password) {
    $errorMsg = 'Please fill in all required fields.';
} elseif ($phone !== '' && (!ctype_digit($phone) || strlen($phone) < 10 || strlen($phone) > 11)) {
    $errorMsg = 'Phone number must contain only digits and be 10 to 11 numbers long.';
} elseif (strlen($password) < 8 || strlen($password) > 10) {
    $errorMsg = 'Password must be between 8 and 10 characters.';
} else {
            $check = $conn->prepare("SELECT staff_id FROM staff WHERE email = ?");
            $check->bind_param('s', $email);
            $check->execute();
            $check->store_result();
            if ($check->num_rows > 0) {
                $errorMsg = 'An account with this email already exists.';
            } else {
                $maxCode = $conn->query("SELECT MAX(CAST(staff_code AS UNSIGNED)) AS m FROM staff WHERE staff_code REGEXP '^[0-9]+$'")->fetch_assoc()['m'] ?? 0;
                $staffCode = str_pad((int)$maxCode + 1, 6, '0', STR_PAD_LEFT);

                $deptName = '';
                foreach ($departments as $d) {
                    if ((int)$d['dept_id'] === $deptId) { $deptName = $d['dept_name']; break; }
                }

                $categoryName = null;
                if ($categoryId) {
                    foreach ($categories as $cat) {
                        if ((int)$cat['category_id'] === $categoryId) {
                            $parts = explode('/', $cat['category_name'], 2);
                            $categoryName = trim($parts[1] ?? $cat['category_name']);
                            break;
                        }
                    }
                }

                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $conn->prepare("INSERT INTO staff (staff_code, full_name, email, password_hash, department, dept_id, role, category, phone, status) VALUES (?,?,?,?,?,?,'staff',?,?,'active')");
                $stmt->bind_param('ssssssss', $staffCode, $fullName, $email, $hash, $deptName, $deptId, $categoryName, $phone);
                if ($stmt->execute()) {
                    $newStaffId = $conn->insert_id;

                    // Sync ALL selected categories to staff_categories
                    foreach ($categoryIds as $cid) {
                        if (!$cid) continue;
                        $scIns = $conn->prepare("INSERT IGNORE INTO staff_categories (staff_id, category_id) VALUES (?, ?)");
                        $scIns->bind_param("ii", $newStaffId, $cid);
                        $scIns->execute();
                        $scIns->close();
                    }

                    $successMsg = "Staff member <strong>" . htmlspecialchars($fullName) . "</strong> added successfully.";
                } else {
                    $errorMsg = 'Database error: ' . htmlspecialchars($conn->error);
                }
            }
        }
    }

    if ($action === 'edit') {
        $staffId    = (int)($_POST['staff_id']   ?? 0);
        $fullName   = trim($_POST['full_name']   ?? '');
        $email      = trim($_POST['email']       ?? '');
        $phone      = trim($_POST['phone']       ?? '');
        $deptId     = (int)($_POST['dept_id']    ?? 0);
        $categoryIds = array_map('intval', $_POST['category_ids'] ?? []);
$categoryId  = !empty($categoryIds) ? $categoryIds[0] : 0;
        $status     = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';

if (!$fullName || !$email || !$deptId) {
    $errorMsg = 'Please fill in all required fields.';
} elseif ($phone !== '' && (!ctype_digit($phone) || strlen($phone) < 10 || strlen($phone) > 11)) {
    $errorMsg = 'Phone number must contain only digits and be 10 to 11 numbers long.';
} else {
            $check = $conn->prepare("SELECT staff_id FROM staff WHERE email = ? AND staff_id != ?");
            $check->bind_param('si', $email, $staffId);
            $check->execute();
            $check->store_result();
            if ($check->num_rows > 0) {
                $errorMsg = 'Another account with this email already exists.';
            } else {
                $deptName = '';
                foreach ($departments as $d) {
                    if ((int)$d['dept_id'] === $deptId) { $deptName = $d['dept_name']; break; }
                }

                $categoryName = null;
                if (!empty($categoryIds)) {
                    foreach ($categories as $cat) {
                        if ((int)$cat['category_id'] === $categoryIds[0]) {
                            $parts = explode('/', $cat['category_name'], 2);
                            $categoryName = trim($parts[1] ?? $cat['category_name']);
                            break;
                        }
                    }
                }

                $stmt = $conn->prepare("UPDATE staff SET full_name=?, email=?, department=?, dept_id=?, category=?, phone=?, status=? WHERE staff_id=?");
                $stmt->bind_param('sssssssi', $fullName, $email, $deptName, $deptId, $categoryName, $phone, $status, $staffId);
                if ($stmt->execute()) {

                    // Sync staff_categories — delete old then insert new
                    $delSc = $conn->prepare("
                        DELETE sc FROM staff_categories sc
                        JOIN categories c ON c.category_id = sc.category_id
                        WHERE sc.staff_id = ? AND c.dept_id = ?
                    ");
                    $delSc->bind_param("ii", $staffId, $deptId);
                    $delSc->execute();
                    $delSc->close();

                    foreach ($categoryIds as $cid) {
                        if (!$cid) continue;
                        $scIns = $conn->prepare("INSERT IGNORE INTO staff_categories (staff_id, category_id) VALUES (?, ?)");
                        $scIns->bind_param("ii", $staffId, $cid);
                        $scIns->execute();
                        $scIns->close();
                    }

                    $successMsg = "Staff member <strong>" . htmlspecialchars($fullName) . "</strong> updated successfully.";
                } else {
                    $errorMsg = 'Database error: ' . htmlspecialchars($conn->error);
                }
            }
        }
    }

    if ($action === 'reset_password') {
        $staffId     = (int)($_POST['staff_id']     ?? 0);
        $newPassword = $_POST['new_password'] ?? '';
        if (strlen($newPassword) < 8 || strlen($newPassword) > 10) {
            $errorMsg = 'Password must be between 8 and 10 characters.';
        } else {
            $hash = password_hash($newPassword, PASSWORD_BCRYPT);
            $stmt = $conn->prepare("UPDATE staff SET password_hash=? WHERE staff_id=?");
            $stmt->bind_param('si', $hash, $staffId);
            $stmt->execute();
            $successMsg = 'Password reset successfully.';
        }
    }

    if ($action === 'toggle_status') {
        $staffId   = (int)($_POST['staff_id']   ?? 0);
        $newStatus = ($_POST['new_status'] ?? '') === 'active' ? 'active' : 'inactive';
        $stmt = $conn->prepare("UPDATE staff SET status=? WHERE staff_id=? AND role='staff'");
        $stmt->bind_param('si', $newStatus, $staffId);
        $stmt->execute();
        $successMsg = 'Staff status updated.';
    }

    if ($action === 'promote') {
        $staffId = (int)($_POST['staff_id'] ?? 0);
        $maxCode = $conn->query("SELECT MAX(CAST(SUBSTRING(staff_code,2) AS UNSIGNED)) AS m FROM staff WHERE staff_code LIKE 'A%'")->fetch_assoc()['m'] ?? 0;
        $newCode = 'A' . str_pad((int)$maxCode + 1, 6, '0', STR_PAD_LEFT);
        $stmt = $conn->prepare("UPDATE staff SET role='admin', staff_code=?, category=NULL WHERE staff_id=? AND role='staff'");
        $stmt->bind_param('si', $newCode, $staffId);
        $stmt->execute();

        // Remove from staff_categories since admins don't handle tickets
        $delSc = $conn->prepare("DELETE FROM staff_categories WHERE staff_id = ?");
        $delSc->bind_param("i", $staffId);
        $delSc->execute();
        $delSc->close();

        $successMsg = 'Staff member promoted to Admin successfully.';
    }

    if ($action === 'delete') {
        $staffId = (int)($_POST['staff_id'] ?? 0);
        $roleCheck = $conn->prepare("SELECT role FROM staff WHERE staff_id=?");
        $roleCheck->bind_param('i', $staffId);
        $roleCheck->execute();
        $roleResult = $roleCheck->get_result()->fetch_assoc();
        if ($roleResult && $roleResult['role'] === 'admin') {
            $errorMsg = 'Admin accounts cannot be deleted.';
        } else {
            // Block delete if staff has open or in-progress tickets
            $ticketCheck = $conn->prepare("SELECT COUNT(*) AS cnt FROM complaints WHERE assigned_to = ? AND status IN ('open','in_progress')");
            $ticketCheck->bind_param('i', $staffId);
            $ticketCheck->execute();
            $ticketCount = (int)$ticketCheck->get_result()->fetch_assoc()['cnt'];
            if ($ticketCount > 0) {
                $errorMsg = 'Cannot delete this staff member — they have ' . $ticketCount . ' open or in-progress ticket(s). Please reassign those tickets first.';
            } else {
                $stmt = $conn->prepare("DELETE FROM staff WHERE staff_id=? AND role='staff'");
                $stmt->bind_param('i', $staffId);
                $stmt->execute();
                $successMsg = 'Staff member removed successfully.';
            }
        }
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$filterDept   = (int)($_GET['dept'] ?? 0);
$filterStatus = $_GET['status'] ?? 'all';
$filterRole   = $_GET['role'] ?? 'all';   // ← ADD THIS
$search       = trim($_GET['q'] ?? '');

$where  = ["s.role IN ('staff','admin')", "s.dept_id IN (1,2,3,4,5)"];
$params = [];
$types  = '';

if ($filterDept > 0)              { $where[] = "s.dept_id = ?"; $params[] = $filterDept; $types .= 'i'; }
if ($filterStatus === 'active')   { $where[] = "s.status = 'active'"; }
if ($filterStatus === 'inactive') { $where[] = "s.status = 'inactive'"; }
if ($filterRole === 'staff')      { $where[] = "s.role = 'staff'"; }   // ← ADD
if ($filterRole === 'admin')      { $where[] = "s.role = 'admin'"; }   // ← ADD
if ($search !== '')               { $where[] = "(s.full_name LIKE ? OR s.email LIKE ? OR s.staff_code LIKE ?)"; $like = "%$search%"; $params = array_merge($params, [$like,$like,$like]); $types .= 'sss'; }

$whereSQL = implode(' AND ', $where);
$sql = "
    SELECT s.staff_id, s.staff_code, s.full_name, s.email, s.phone,
           s.role, s.category, s.status, s.created_at, s.dept_id, d.dept_name
    FROM staff s
    LEFT JOIN departments d ON d.dept_id = s.dept_id
    WHERE $whereSQL
    ORDER BY s.dept_id, s.role DESC, s.full_name
";

if ($params) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $staffList = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $staffList = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

$countAll      = (int)$conn->query("SELECT COUNT(*) AS n FROM staff WHERE role IN ('staff','admin') AND dept_id IN (1,2,3,4,5)")->fetch_assoc()['n'];
$countActive   = (int)$conn->query("SELECT COUNT(*) AS n FROM staff WHERE role IN ('staff','admin') AND dept_id IN (1,2,3,4,5) AND status='active'")->fetch_assoc()['n'];
$countInactive = $countAll - $countActive;

$deptColors = [1=>'#7C3AED', 2=>'#059669', 3=>'#DC2626', 4=>'#2563EB', 5=>'#D97706'];
$deptShort  = [1=>'AFSMD', 2=>'Maintenance', 3=>'CCU', 4=>'IT Dept', 5=>'HCD'];

// Avatar colour palette (cycles by staff_id)
$avatarPalette = ['#7C3AED','#059669','#DC2626','#2563EB','#D97706','#DB2777','#0891B2','#65A30D'];

// Load staff_categories for all staff
$scStmt = $conn->query("
    SELECT sc.staff_id, c.category_name
    FROM staff_categories sc
    JOIN categories c ON c.category_id = sc.category_id
");
$scRows = $scStmt->fetch_all(MYSQLI_ASSOC);
$staffCategoryMap = [];
foreach ($scRows as $scRow) {
    $parts = explode(' / ', $scRow['category_name'], 2);
    $label = count($parts) === 2 ? trim($parts[1]) : $scRow['category_name'];
    $staffCategoryMap[$scRow['staff_id']][] = $label;
}

include 'layout.php';
?>

<div style="
  height: 65px; background: white;
  border-bottom: 1px solid #DDE1ED;
  display: flex; align-items: center; justify-content: space-between;
  padding: 0 32px;
  margin: -32px -32px 32px -32px;
">
  <div style="display:flex; align-items:center; gap:8px; font-size:15px; color:#7A8399;">
    <span style="color:#BDC3D4;">›</span>
    <span style="font-weight:600; color:#1A2038;">Manage Users</span>
  </div>
  <div style="font-size:14px; color:#7A8399; display:flex; align-items:center; gap:6px;">
    <svg viewBox="0 0 24 24" style="width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:2;">
      <rect x="3" y="4" width="18" height="18" rx="2"/>
      <line x1="16" y1="2" x2="16" y2="6"/>
      <line x1="8" y1="2" x2="8" y2="6"/>
      <line x1="3" y1="10" x2="21" y2="10"/>
    </svg>
    <?= date('D, j M Y') ?>
  </div>
</div>

<style>
  /* ── Page heading ── */
  .page-heading { margin-bottom: 28px; display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
  .page-heading-text h1 { font-family: 'DM Serif Display', serif; font-size: 28px; color: var(--gray-900); margin-bottom: 4px; }
  .page-heading-text p  { font-size: 14px; color: var(--gray-500); }

  .btn-add {
    display: inline-flex; align-items: center; gap: 8px;
    background: var(--maroon); color: white; font-size: 13.5px; font-weight: 600;
    padding: 10px 18px; border-radius: 9px; border: none; cursor: pointer;
    text-decoration: none; transition: background .18s, box-shadow .18s;
    box-shadow: 0 2px 8px rgba(125,17,40,.25); white-space: nowrap; font-family: 'DM Sans', sans-serif;
  }
  .btn-add:hover { background: var(--maroon-dark); box-shadow: 0 4px 14px rgba(125,17,40,.35); }
  .btn-add svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2.2; flex-shrink: 0; }

  /* ── Stat strip ── */
  .stat-strip { display: grid; grid-template-columns: repeat(4,1fr); gap: 14px; margin-bottom: 24px; }
  @media(max-width:900px){ .stat-strip { grid-template-columns: repeat(2,1fr); } }
  @media(max-width:500px){ .stat-strip { grid-template-columns: 1fr; } }

  .strip-card {
    background: white; border: 1px solid var(--gray-200); border-radius: 12px;
    padding: 16px 18px; display: flex; align-items: center; gap: 12px;
    box-shadow: 0 1px 4px rgba(0,0,0,.04); transition: box-shadow .2s;
  }
  .strip-card:hover { box-shadow: 0 4px 14px rgba(0,0,0,.08); }
  .strip-card.strip-active { border-color: var(--maroon); box-shadow: 0 0 0 2px rgba(125,17,40,.15); }
  .strip-card { cursor: pointer; }
  .strip-icon { width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
  .strip-icon svg { width: 19px; height: 19px; fill: none; stroke: currentColor; stroke-width: 2; }
  .strip-icon.blue   { background: #EFF6FF; color: #2563EB; }
  .strip-icon.green  { background: #ECFDF5; color: #059669; }
  .strip-icon.red    { background: #FEF2F2; color: #DC2626; }
  .strip-icon.amber  { background: #FFFBEB; color: #D97706; }
  .strip-val { font-size: 26px; font-weight: 700; color: var(--gray-900); line-height: 1; margin-bottom: 3px; }
  .strip-lbl { font-size: 12px; color: var(--gray-500); }

  /* ── Alert ── */
  .alert { padding: 12px 16px; border-radius: 9px; font-size: 13.5px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
  .alert-success { background: #ECFDF5; color: #065F46; border: 1px solid #A7F3D0; }
  .alert-error   { background: #FEF2F2; color: #991B1B; border: 1px solid #FECACA; }
  .alert svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2.2; flex-shrink: 0; }

  /* ── Filter bar ── */
  .filter-bar {
    background: white; border: 1px solid var(--gray-200); border-radius: 12px;
    padding: 14px 18px; margin-bottom: 18px;
    display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
    box-shadow: 0 1px 4px rgba(0,0,0,.04);
  }
  .filter-search {
    flex: 1; min-width: 200px; display: flex; align-items: center; gap: 8px;
    background: var(--gray-100); border-radius: 8px; padding: 8px 12px;
    border: 1px solid transparent; transition: border .18s, background .18s;
  }
  .filter-search:focus-within { background: white; border-color: var(--gray-300); }
  .filter-search svg { width: 14px; height: 14px; fill: none; stroke: var(--gray-500); stroke-width: 2; flex-shrink: 0; }
  .filter-search input { border: none; background: transparent; outline: none; font-size: 13px; color: var(--gray-900); width: 100%; font-family: 'DM Sans', sans-serif; }
  .filter-search input::placeholder { color: var(--gray-400); }

  .filter-select {
    padding: 8px 12px; border: 1px solid var(--gray-200); border-radius: 8px;
    font-size: 13px; color: var(--gray-700); background: white; outline: none; cursor: pointer;
    font-family: 'DM Sans', sans-serif; transition: border .18s;
  }
  .filter-select:focus { border-color: var(--maroon); }

  

  .btn-filter {
    padding: 8px 16px; background: var(--maroon); color: white;
    border: none; border-radius: 8px; font-size: 13px; font-weight: 600;
    cursor: pointer; font-family: 'DM Sans', sans-serif; transition: background .18s;
  }
  .btn-filter:hover { background: var(--maroon-dark); }
  .btn-reset {
    padding: 8px 14px; background: white; color: var(--gray-600);
    border: 1px solid var(--gray-200); border-radius: 8px; font-size: 13px;
    cursor: pointer; font-family: 'DM Sans', sans-serif; text-decoration: none;
    display: inline-flex; align-items: center; gap: 6px; transition: background .15s;
  }
  .btn-reset:hover { background: var(--gray-100); }
  .btn-reset svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2; }

  /* ── Table card ── */
  .table-card { background: white; border: 1px solid var(--gray-200); border-radius: 14px; box-shadow: 0 1px 4px rgba(0,0,0,.04); overflow-x: auto; -webkit-overflow-scrolling: touch; }
  .table-card-header { padding: 16px 20px; border-bottom: 1px solid var(--gray-100); display: flex; align-items: center; justify-content: space-between; }
  .table-card-title { font-size: 13.5px; font-weight: 600; color: var(--gray-900); }
  .table-showing { font-size: 12px; color: var(--gray-400); }

  /* ── Beautiful Staff Table ── */
  .staff-table { width: 100%; border-collapse: collapse; min-width: 860px; }
  .staff-table th {
    font-size: 11px; font-weight: 600; color: var(--gray-500); text-transform: uppercase;
    letter-spacing: .06em; padding: 11px 16px; text-align: left;
    background: var(--gray-100); border-bottom: 1px solid var(--gray-200);
    white-space: nowrap;
  }
  .staff-table td { font-size: 13.5px; color: var(--gray-700); padding: 13px 16px; border-bottom: 1px solid var(--gray-100); vertical-align: middle; }
  .staff-table tbody tr:last-child td { border-bottom: none; }
  .staff-table tbody tr { transition: background .12s; }
  .staff-table tbody tr:hover { background: #FDF8F9; }

  /* ── Name cell with avatar ── */
  .staff-name-cell { display: flex; align-items: center; gap: 10px; }
  .staff-avatar {
    width: 36px; height: 36px; border-radius: 10px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; font-weight: 700; color: white; letter-spacing: .02em;
  }
  .staff-fullname { font-weight: 600; color: var(--gray-900); font-size: 13.5px; line-height: 1.3; }
  .staff-code-sub { font-size: 11.5px; color: var(--gray-400); margin-top: 1px; }

  /* ── Role badge ── */
  .role-badge {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 11.5px; font-weight: 600; padding: 4px 10px; border-radius: 20px;
  }
  .role-badge.staff { background: #DBEAFE; color: #1D4ED8; }
  .role-badge.admin { background: #7C3AED; color: #ffffff; }
  .role-badge svg { width: 11px; height: 11px; fill: none; stroke: currentColor; stroke-width: 2.2; }

  /* ── Dept pill ── */
  .dept-pill { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 20px; font-size: 11.5px; font-weight: 600; background: var(--gray-100); color: var(--gray-700); white-space: nowrap; }
  .dept-dot  { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }

  /* ── Status badge ── */
  .badge-active   { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; font-weight: 600; padding: 4px 10px; border-radius: 20px; background: #ECFDF5; color: #065F46; }
  .badge-inactive { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; font-weight: 600; padding: 4px 10px; border-radius: 20px; background: #FEF2F2; color: #991B1B; }
  .status-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
  .status-dot.active   { background: #10B981; }
  .status-dot.inactive { background: #EF4444; }

  .cat-chip {
    display: inline-block; font-size: 12px; color: var(--gray-600);
    background: var(--gray-100); border-radius: 6px; padding: 3px 8px;
    max-width: 130px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  .cat-dash { color: var(--gray-300); font-size: 16px; }

  /* ── Email cell ── */
  .email-cell { font-size: 12.5px; color: var(--gray-600); word-break: break-all; }
  .phone-cell { font-size: 13px; color: var(--gray-700); white-space: nowrap; }

  /* ── Action buttons ── */
  .action-group { display: flex; align-items: center; gap: 4px; flex-wrap: nowrap; }
  .btn-icon {
    width: 30px; height: 30px; border-radius: 7px; border: 1px solid var(--gray-200);
    background: white; display: flex; align-items: center; justify-content: center;
    cursor: pointer; transition: background .15s, border-color .15s, color .15s;
    color: var(--gray-400); flex-shrink: 0;
  }
  .btn-icon svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2; }
  .btn-icon:hover        { background: var(--gray-100); color: var(--gray-700); border-color: var(--gray-300); }
  .btn-icon.view:hover   { background: #EFF6FF; color: #2563EB; border-color: #BFDBFE; }
  .btn-icon.edit:hover   { background: #FFFBEB; color: #D97706; border-color: #FDE68A; }
  .btn-icon.lock:hover   { background: #F5F3FF; color: #7C3AED; border-color: #DDD6FE; }
  .btn-icon.danger:hover { background: #FEF2F2; color: #DC2626; border-color: #FECACA; }
  .btn-icon.success:hover{ background: #ECFDF5; color: #059669; border-color: #A7F3D0; }

  .empty-state { text-align: center; padding: 56px 20px; color: var(--gray-400); }
  .empty-state svg { width: 36px; height: 36px; fill: none; stroke: var(--gray-300); stroke-width: 1.5; display: block; margin: 0 auto 12px; }
  .empty-state p { font-size: 14px; }

  /* ── Zebra stripe subtle ── */
  .staff-table tbody tr:nth-child(even) { background: #FAFAFA; }
  .staff-table tbody tr:nth-child(even):hover { background: #FDF8F9; }

  /* ── MODAL ── */
  .modal-overlay {
    position: fixed; inset: 0; z-index: 200;
    background: rgba(17,24,39,.45); backdrop-filter: blur(3px);
    display: flex; align-items: center; justify-content: center;
    opacity: 0; pointer-events: none; transition: opacity .2s;
  }
  .modal-overlay.open { opacity: 1; pointer-events: all; }
  .modal {
    background: white; border-radius: 16px; width: 100%; max-width: 500px;
    box-shadow: 0 20px 60px rgba(0,0,0,.18);
    transform: translateY(12px) scale(.98); transition: transform .22s;
    overflow: hidden; max-height: 92vh; overflow-y: auto;
  }
  .modal-overlay.open .modal { transform: translateY(0) scale(1); }
  .modal-lg { max-width: 560px; }

  .modal-header { padding: 20px 24px 16px; border-bottom: 1px solid var(--gray-100); display: flex; align-items: center; justify-content: space-between; }
  .modal-title    { font-size: 16px; font-weight: 700; color: var(--gray-900); }
  .modal-subtitle { font-size: 12.5px; color: var(--gray-500); margin-top: 2px; }
  .modal-close { width: 30px; height: 30px; border-radius: 7px; border: 1px solid var(--gray-200); background: white; cursor: pointer; display: flex; align-items: center; justify-content: center; color: var(--gray-400); transition: background .15s; }
  .modal-close:hover { background: var(--gray-100); color: var(--gray-700); }
  .modal-close svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2.2; }
  .modal-body   { padding: 20px 24px; }
  .modal-footer { padding: 16px 24px; border-top: 1px solid var(--gray-100); display: flex; justify-content: flex-end; gap: 10px; }

  .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
  @media(max-width:500px){ .form-row { grid-template-columns: 1fr; } }
  .form-group { margin-bottom: 14px; }
  .form-group label { display: block; font-size: 12px; font-weight: 600; color: var(--gray-700); margin-bottom: 5px; }
  .form-group label span { color: #DC2626; margin-left: 2px; }
  .form-control {
    width: 100%; padding: 9px 12px; border: 1px solid var(--gray-200); border-radius: 8px;
    font-size: 13.5px; color: var(--gray-900); background: white; outline: none;
    transition: border-color .18s, box-shadow .18s; font-family: 'DM Sans', sans-serif;
  }
  .form-control:focus { border-color: var(--maroon); box-shadow: 0 0 0 3px rgba(125,17,40,.08); }
  .form-control::placeholder { color: var(--gray-400); }
  select.form-control { cursor: pointer; }

  .category-section { display: none; }
  .category-section.visible { display: block; }

  .pw-wrap { position: relative; }
  .pw-wrap .form-control { padding-right: 40px; }
  .pw-toggle { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--gray-400); padding: 4px; display: flex; align-items: center; }
  .pw-toggle svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2; }
  .pw-toggle:hover { color: var(--gray-700); }

  .divider { height: 1px; background: var(--gray-100); margin: 16px 0; }
  .section-label { font-size: 11px; font-weight: 600; color: var(--gray-400); text-transform: uppercase; letter-spacing: .07em; margin-bottom: 12px; }

  .btn-primary   { padding: 9px 20px; background: var(--maroon); color: white; border: none; border-radius: 8px; font-size: 13.5px; font-weight: 600; cursor: pointer; transition: background .18s; font-family: 'DM Sans', sans-serif; }
  .btn-primary:hover { background: var(--maroon-dark); }
  .btn-secondary { padding: 9px 16px; background: white; color: var(--gray-700); border: 1px solid var(--gray-200); border-radius: 8px; font-size: 13.5px; font-weight: 500; cursor: pointer; transition: background .18s; font-family: 'DM Sans', sans-serif; }
  .btn-secondary:hover { background: var(--gray-100); }
  .btn-danger    { padding: 9px 20px; background: #DC2626; color: white; border: none; border-radius: 8px; font-size: 13.5px; font-weight: 600; cursor: pointer; transition: background .18s; font-family: 'DM Sans', sans-serif; }
  .btn-danger:hover { background: #B91C1C; }
  .btn-success   { padding: 9px 20px; background: #059669; color: white; border: none; border-radius: 8px; font-size: 13.5px; font-weight: 600; cursor: pointer; transition: background .18s; font-family: 'DM Sans', sans-serif; }
  .btn-success:hover { background: #047857; }

  /* View modal */
  .view-row { display: flex; gap: 10px; padding: 10px 0; border-bottom: 1px solid var(--gray-100); font-size: 13.5px; }
  .view-row:last-child { border-bottom: none; }
  .view-label { min-width: 120px; font-weight: 600; color: var(--gray-500); font-size: 12.5px; }
  .view-val   { color: var(--gray-900); flex: 1; }

  /* Danger modal */
  .danger-icon { width: 46px; height: 46px; border-radius: 12px; background: #FEF2F2; display: flex; align-items: center; justify-content: center; margin-bottom: 14px; }
  .danger-icon svg { width: 22px; height: 22px; fill: none; stroke: #DC2626; stroke-width: 2; }
  .danger-icon.success-icon { background: #ECFDF5; }
  .danger-icon.success-icon svg { stroke: #059669; }
  .danger-text { font-size: 14px; color: var(--gray-600); line-height: 1.65; }
  .danger-name { font-weight: 700; color: var(--gray-900); }

  .promote-notice {
    background: #F0FDF4; border: 1px solid #A7F3D0; border-radius: 9px;
    padding: 12px 14px; font-size: 13px; color: #065F46; margin-bottom: 4px;
    display: flex; align-items: flex-start; gap: 9px; line-height: 1.6;
  }
  .promote-notice svg { width: 16px; height: 16px; fill: none; stroke: #059669; stroke-width: 2; flex-shrink: 0; margin-top: 2px; }
</style>

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

<!-- ── Page Heading (same pattern as manage_admins.php) ── -->
<div class="page-heading">
  <div class="page-heading-text">
    <h1>Manage Users</h1>
    <p>Create and manage all user accounts across every department.</p>
  </div>
  <button class="btn-add" onclick="openModal('addModal')">
    <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    Add User
  </button>
</div>

<!-- ── Stat Strip ── -->
<div class="stat-strip">
  <a href="?status=all&dept=0&role=all&q=" class="strip-card <?= (!$filterDept && $filterStatus==='all' && $filterRole==='all' && $search==='') ? 'strip-active' : '' ?>" style="text-decoration:none;">
    <div class="strip-icon blue">
      <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
    </div>
    <div><div class="strip-val"><?= $countAll ?></div><div class="strip-lbl">Total Members</div></div>
  </a>
  <a href="?status=active&dept=0&role=all&q=" class="strip-card <?= ($filterStatus==='active') ? 'strip-active' : '' ?>" style="text-decoration:none;">
    <div class="strip-icon green">
      <svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
    </div>
    <div><div class="strip-val"><?= $countActive ?></div><div class="strip-lbl">Active</div></div>
  </a>
  <a href="?status=inactive&dept=0&role=all&q=" class="strip-card <?= ($filterStatus==='inactive') ? 'strip-active' : '' ?>" style="text-decoration:none;">
    <div class="strip-icon red">
      <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
    </div>
    <div><div class="strip-val"><?= $countInactive ?></div><div class="strip-lbl">Inactive</div></div>
  </a>
  <a href="?status=all&dept=0&role=all&q=" class="strip-card" style="text-decoration:none; cursor:default;">
    <div class="strip-icon amber">
      <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
    </div>
    <div><div class="strip-val"><?= count($departments) ?></div><div class="strip-lbl">Departments</div></div>
  </a>
</div>

<!-- ── Filter Bar ── -->
<form method="GET" class="filter-bar">
  <select name="dept" class="filter-select" onchange="this.form.submit()">
    <option value="0">All Departments</option>
    <?php foreach ($departments as $d):
        $dId = (int)$d['dept_id'];
        $label = $deptShort[$dId] ?? htmlspecialchars($d['dept_name']);
    ?>
    <option value="<?= $dId ?>" <?= $filterDept === $dId ? 'selected' : '' ?>>
        <?= $label ?>
    </option>
    <?php endforeach; ?>
  </select>

  <select name="role" class="filter-select" onchange="this.form.submit()">
    <option value="all"   <?= $filterRole === 'all'   ? 'selected' : '' ?>>All Roles</option>
    <option value="staff" <?= $filterRole === 'staff' ? 'selected' : '' ?>>Staff Only</option>
    <option value="admin" <?= $filterRole === 'admin' ? 'selected' : '' ?>>Admin Only</option>
  </select>

  <div class="filter-search">
    <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
    <input type="text" name="q" placeholder="Search name, email, staff code…" value="<?= htmlspecialchars($search) ?>">
  </div>

  <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">

  <button type="submit" class="btn-filter">Search</button>
  <?php if ($search || $filterDept || $filterStatus !== 'all' || $filterRole !== 'all'): ?>
  <a href="manage_staff.php" class="btn-reset">
    <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    Clear
  </a>
  <?php endif; ?>
</form>

<!-- ── Table ── -->
<div class="table-card">
  <div class="table-card-header">
    <span class="table-card-title">Staff Members</span>
    <span class="table-showing">Showing <?= count($staffList) ?> of <?= $countAll ?> members</span>
  </div>

  <?php if (empty($staffList)): ?>
  <div class="empty-state">
    <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
    <p>No staff found matching your filters.</p>
  </div>
  <?php else: ?>
  <table class="staff-table">
    <thead>
      <tr>
<th style="width:24%;">Member</th>
        <th style="width:8%;">Role</th>
        <th style="width:22%;">Email</th>
        <th style="width:12%;">Phone</th>
        <th style="width:13%;">Department</th>
        <th style="width:10%;">Status</th>
        <th style="width:11%;">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($staffList as $s):
        $dId      = (int)$s['dept_id'];
        $color    = $deptColors[$dId] ?? '#888';
        $short    = $deptShort[$dId]  ?? htmlspecialchars($s['dept_name'] ?? '');
        $isActive = $s['status'] === 'active';
        $isAdmin  = $s['role'] === 'admin';
        $sJson    = htmlspecialchars(json_encode($s), ENT_QUOTES);

        // Avatar initials & colour
        $words    = array_filter(explode(' ', $s['full_name']));
        $initials = strtoupper(implode('', array_map(fn($w) => $w[0], array_slice($words, 0, 2))));
        $avatarBg = $avatarPalette[$s['staff_id'] % count($avatarPalette)];
      ?>
      <tr>
        <!-- Member: avatar + name + code -->
        <td>
          <div class="staff-name-cell">
            <div>
              <div class="staff-fullname"><?= htmlspecialchars($s['full_name']) ?></div>
              <div class="staff-code-sub"><?= htmlspecialchars($s['staff_code']) ?></div>
            </div>
          </div>
        </td>

        <!-- Role -->
        <td>
          <?php if ($isAdmin): ?>
          <span class="role-badge admin">
            <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            Admin
          </span>
          <?php else: ?>
          <span class="role-badge staff">
            <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            Staff
          </span>
          <?php endif; ?>
        </td>

        <!-- Email -->
        <td><span class="email-cell"><?= htmlspecialchars($s['email']) ?></span></td>

        <!-- Phone -->
        <td><span class="phone-cell"><?= htmlspecialchars($s['phone'] ?: '—') ?></span></td>

        <!-- Department -->
        <td>
          <span class="dept-pill">
            <span class="dept-dot" style="background:<?= $color ?>;"></span>
            <?= $short ?>
          </span>
        </td>

        <!-- Status -->
        <td>
          <?php if ($isActive): ?>
            <span class="badge-active"><span class="status-dot active"></span> Active</span>
          <?php else: ?>
            <span class="badge-inactive"><span class="status-dot inactive"></span> Inactive</span>
          <?php endif; ?>
        </td>

        <!-- Actions -->
        <td>
          <div class="action-group">
            <!-- View -->
            <button class="btn-icon view" title="View Details" onclick='openViewModal(<?= $sJson ?>)'>
              <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
            <!-- Edit -->
            <button class="btn-icon edit" title="Edit" onclick='openEditModal(<?= $sJson ?>)'>
              <svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            </button>
            <!-- Reset Password -->
            <button class="btn-icon lock" title="Reset Password" onclick="openResetModal(<?= $s['staff_id'] ?>, '<?= htmlspecialchars(addslashes($s['full_name'])) ?>')">
              <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </button>

            <?php if (!$isAdmin): ?>
          
              <!-- Delete -->
              <button class="btn-icon danger" title="Delete" onclick="openDeleteModal(<?= $s['staff_id'] ?>, '<?= htmlspecialchars(addslashes($s['full_name'])) ?>')">
                <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
              </button>
            <?php else: ?>
              <!-- Admin: show info on delete attempt -->
              <button class="btn-icon danger" title="Cannot delete admin here" onclick="openAdminDeleteInfoModal('<?= htmlspecialchars(addslashes($s['full_name'])) ?>')">
                <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
              </button>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- ══ MODAL: View ══ -->
<div class="modal-overlay" id="viewModal">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div>
        <div class="modal-title" id="viewModalTitle">Staff Details</div>
        <div class="modal-subtitle" id="viewModalSubtitle"></div>
      </div>
      <button class="modal-close" onclick="closeModal('viewModal')">
        <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div class="modal-body" id="viewModalBody"></div>
    <div class="modal-footer">
      <button class="btn-secondary" onclick="closeModal('viewModal')">Close</button>
    </div>
  </div>
</div>

<!-- ══ MODAL: Add Staff ══ -->
<div class="modal-overlay" id="addModal">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div>
        <div class="modal-title">Add New Tecnical</div>
        <div class="modal-subtitle">Create a Tecnical account</div>
      </div>
      <button class="modal-close" onclick="closeModal('addModal')">
        <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="add">
      <div class="modal-body">
        <div class="section-label">Personal Info</div>
        <div class="form-row">
          <div class="form-group">
            <label>Full Name <span>*</span></label>
            <input type="text" name="full_name" class="form-control" placeholder="e.g. Ahmad Razif bin Hamid" required>
          </div>
          <div class="form-group">
            <label>Phone Number</label>
            <input type="text" name="phone" class="form-control" 
       placeholder="e.g. 0187001010"
       inputmode="numeric"
       pattern="[0-9]{10,11}"
       maxlength="11"
       oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0,11)">
          </div>
        </div>
        <div class="form-group">
          <label>Email Address <span>*</span></label>
          <input type="email" name="email" class="form-control" placeholder="staff@unikl.edu.my" required>
        </div>
        <div class="divider"></div>
        <div class="section-label">Assignment</div>
        <div class="form-group">
          <label>Department <span>*</span></label>
          <select name="dept_id" id="addDeptId" class="form-control" required onchange="filterAddCats()">
            <option value="">— Select Department —</option>
            <option value="1">Administration &amp; Facilities Management (AFSMD)</option>
            <option value="2">Maintenance Department</option>
            <option value="3">Corporate Communication Unit (CCU)</option>
            <option value="4">Information Technology Department</option>
            <option value="5">Human Capital Department (HCD)</option>
          </select>
        </div>
        <div class="category-section" id="addCategorySection">
  <div class="form-group">
    <label>Category <small style="color:#9ca3af;font-weight:400;">Select at least one</small></label>
    <div id="addCategoryList" style="border:1.5px solid #e5e7eb;border-radius:8px;padding:10px 12px;display:flex;flex-direction:column;gap:8px;max-height:200px;overflow-y:auto;">
      <!-- filled by JS -->
    </div>
  </div>
</div>
        <div class="divider"></div>
        <div class="section-label">Security</div>
        <div class="form-group">
          <label>Password <span>*</span></label>
          <div class="pw-wrap">
            <input type="password" name="password" id="addPw" class="form-control" placeholder="8–10 characters" required minlength="8" maxlength="10">
            <button type="button" class="pw-toggle" onclick="togglePw('addPw',this)" tabindex="-1">
              <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-secondary" onclick="closeModal('addModal')">Cancel</button>
        <button type="submit" class="btn-primary">Create Staff</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ MODAL: Edit Staff ══ -->
<div class="modal-overlay" id="editModal">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div>
        <div class="modal-title">Edit Staff</div>
        <div class="modal-subtitle" id="editModalSubtitle"></div>
      </div>
      <button class="modal-close" onclick="closeModal('editModal')">
        <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="staff_id" id="editStaffId">
      <div class="modal-body">
        <div class="section-label">Personal Info</div>
        <div class="form-row">
          <div class="form-group">
            <label>Full Name <span>*</span></label>
            <input type="text" name="full_name" id="editFullName" class="form-control" required>
          </div>
          <div class="form-group">
            <label>Phone Number</label>
            <input type="text" name="phone" id="editPhone" class="form-control"
       placeholder="e.g. 0187001010"
       inputmode="numeric"
       pattern="[0-9]{10,11}"
       maxlength="11"
       oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0,11)">
          </div>
        </div>
        <div class="form-group">
          <label>Email Address <span>*</span></label>
          <input type="email" name="email" id="editEmail" class="form-control" required>
        </div>
        <div class="divider"></div>
        <div class="section-label">Assignment</div>
        <div class="form-group">
          <label>Department <span>*</span></label>
          <select name="dept_id" id="editDeptId" class="form-control" required onchange="filterEditCats()">
            <option value="">— Select Department —</option>
            <option value="1">Administration &amp; Facilities Management (AFSMD)</option>
            <option value="2">Maintenance Department</option>
            <option value="3">Corporate Communication Unit (CCU)</option>
            <option value="4">Information Technology Department</option>
            <option value="5">Human Capital Department (HCD)</option>
          </select>
        </div>
        <div class="category-section" id="editCategorySection">
  <div class="form-group">
    <label>Category <small style="color:#9ca3af;font-weight:400;">Select at least one</small></label>
    <div id="editCategoryList" style="border:1.5px solid #e5e7eb;border-radius:8px;padding:10px 12px;display:flex;flex-direction:column;gap:8px;max-height:200px;overflow-y:auto;">
      <!-- filled by JS -->
    </div>
  </div>
</div>
        <div class="divider"></div>
        <div class="section-label">Account Status</div>
        <div class="form-group">
          <label>Status</label>
          <select name="status" id="editStatus" class="form-control">
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-secondary" onclick="closeModal('editModal')">Cancel</button>
        <button type="submit" class="btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ MODAL: Reset Password ══ -->
<div class="modal-overlay" id="resetModal">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title">Reset Password</div>
        <div class="modal-subtitle" id="resetSubtitle"></div>
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
            <button type="button" class="pw-toggle" onclick="togglePw('resetPw',this)" tabindex="-1">
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

<!-- ══ MODAL: Toggle Status ══ -->
<div class="modal-overlay" id="toggleModal">
  <div class="modal">
    <div class="modal-header">
      <div><div class="modal-title" id="toggleTitle">Change Status</div></div>
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
        <p class="danger-text" id="toggleBody"></p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-secondary" onclick="closeModal('toggleModal')">Cancel</button>
        <button type="submit" class="btn-primary" id="toggleBtn">Confirm</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ MODAL: Delete ══ -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal">
    <div class="modal-header">
      <div><div class="modal-title">Delete Staff</div></div>
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
          Permanently delete <span class="danger-name" id="deleteStaffName"></span>?
          This <strong>cannot be undone</strong> and will remove all access for this account.
        </p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-secondary" onclick="closeModal('deleteModal')">Cancel</button>
        <button type="submit" class="btn-danger">Delete Staff</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ MODAL: Admin Delete Info ══ -->
<div class="modal-overlay" id="adminDeleteInfoModal">
  <div class="modal" style="max-width:420px; position:relative;">
    <div class="modal-body" style="padding:36px 32px 28px; text-align:center;">
      <button onclick="closeModal('adminDeleteInfoModal')" style="position:absolute;top:14px;right:14px;width:28px;height:28px;border-radius:6px;border:1px solid var(--gray-200);background:white;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--gray-400);">
        <svg viewBox="0 0 24 24" style="width:13px;height:13px;fill:none;stroke:currentColor;stroke-width:2.2;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
      <div style="width:64px;height:64px;border-radius:50%;background:#FFF7ED;border:2px solid #FED7AA;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;">
        <svg viewBox="0 0 24 24" style="width:28px;height:28px;fill:none;stroke:#D97706;stroke-width:2;">
          <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
      </div>
      <div style="font-size:17px;font-weight:700;color:var(--gray-900);margin-bottom:10px;">Cannot Delete Admin Here</div>
      <p style="font-size:13.5px;color:var(--gray-500);line-height:1.7;margin:0 0 28px;">
        <span style="font-weight:700;color:var(--gray-900);" id="adminDeleteInfoName"></span>
        is an <strong style="color:var(--gray-900);">Admin</strong> account.<br>
        Admin accounts cannot be deleted from this page.
      </p>
      <button type="button" onclick="closeModal('adminDeleteInfoModal')"
        style="width:100%;padding:11px 20px;background:var(--maroon);color:white;border:none;border-radius:9px;font-size:14px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif;">
        Got it
      </button>
    </div>
  </div>
</div>

<!-- Categories JSON for JS -->
<script>
const ALL_CATS = <?= json_encode($categories) ?>;

function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(o => {
  o.addEventListener('click', e => { if (e.target === o) closeModal(o.id); });
});

function filterCats(deptId, listId, secId, checkedIds) {
  const list = document.getElementById(listId);
  const sec  = document.getElementById(secId);
  list.innerHTML = '';
  if (!deptId) { sec.classList.remove('visible'); return; }
  const filtered = ALL_CATS.filter(c => c.dept_id == deptId);
  filtered.forEach(c => {
    const parts = c.category_name.split('/');
    const label = (parts[1] || c.category_name).trim();
    const checked = checkedIds && checkedIds.includes(c.category_id) ? 'checked' : '';
    list.innerHTML += `
      <label style="display:flex;align-items:center;gap:8px;font-size:0.875rem;cursor:pointer;font-weight:400;">
        <input type="checkbox" name="category_ids[]" value="${c.category_id}" ${checked}
               style="width:15px;height:15px;accent-color:#7d1128;cursor:pointer;"/>
        ${label}
      </label>`;
  });
  sec.classList.toggle('visible', filtered.length > 0);
}

function filterAddCats() {
  filterCats(document.getElementById('addDeptId').value, 'addCategoryList', 'addCategorySection', []);
}

function filterEditCats(checkedIds) {
  filterCats(document.getElementById('editDeptId').value, 'editCategoryList', 'editCategorySection', checkedIds || []);
}

function openViewModal(s) {
  document.getElementById('viewModalTitle').textContent = s.full_name;
  document.getElementById('viewModalSubtitle').textContent = s.staff_code + ' · ' + (s.role.charAt(0).toUpperCase() + s.role.slice(1));
  const rows = [
    ['Staff Code', s.staff_code], ['Full Name', s.full_name],
    ['Role', s.role.charAt(0).toUpperCase() + s.role.slice(1)],
    ['Email', s.email], ['Phone', s.phone || '—'],
    ['Department', s.dept_name || '—'], ['Category', s.category || '—'],
    ['Status', s.status.charAt(0).toUpperCase() + s.status.slice(1)],
    ['Joined', s.created_at ? s.created_at.split(' ')[0] : '—'],
  ];
  document.getElementById('viewModalBody').innerHTML = rows.map(([l,v]) =>
    `<div class="view-row"><span class="view-label">${l}</span><span class="view-val">${v}</span></div>`
  ).join('');
  openModal('viewModal');
}

function openEditModal(s) {
  document.getElementById('editModalSubtitle').textContent = 'Editing: ' + s.full_name;
  document.getElementById('editStaffId').value  = s.staff_id;
  document.getElementById('editFullName').value = s.full_name;
  document.getElementById('editEmail').value    = s.email;
  document.getElementById('editPhone').value    = (s.phone || '').replace(/[^0-9]/g, '').slice(0, 11);
  document.getElementById('editStatus').value   = s.status;
  document.getElementById('editDeptId').value   = s.dept_id || '';

  // Fetch current categories then build checkboxes
  fetch('get_staff_categories_sa.php?staff_id=' + s.staff_id)
    .then(r => r.json())
    .then(data => {
      const checkedIds = data.category_ids || [];
      filterEditCats(checkedIds);
    })
    .catch(() => { filterEditCats([]); });

  openModal('editModal');
}

function openResetModal(id, name) {
  document.getElementById('resetStaffId').value = id;
  document.getElementById('resetSubtitle').textContent = 'For: ' + name;
  document.getElementById('resetPw').value = '';
  openModal('resetModal');
}

function openToggleModal(id, name, newStatus) {
  document.getElementById('toggleStaffId').value   = id;
  document.getElementById('toggleNewStatus').value = newStatus;
  const isDeactivate = newStatus === 'inactive';
  document.getElementById('toggleTitle').textContent = isDeactivate ? 'Deactivate Staff' : 'Activate Staff';
  document.getElementById('toggleBody').innerHTML =
    (isDeactivate ? 'This will <strong>disable login access</strong> for ' : 'This will <strong>restore login access</strong> for ')
    + '<span class="danger-name">' + name + '</span>. You can reverse this at any time.';
  document.getElementById('toggleBtn').textContent = isDeactivate ? 'Deactivate' : 'Activate';
  document.getElementById('toggleBtn').style.background = isDeactivate ? '#DC2626' : '#059669';
  openModal('toggleModal');
}

function openAdminDeleteInfoModal(name) {
  document.getElementById('adminDeleteInfoName').textContent = name;
  openModal('adminDeleteInfoModal');
}

function openDeleteModal(id, name) {
  document.getElementById('deleteStaffId').value = id;
  document.getElementById('deleteStaffName').textContent = name;
  openModal('deleteModal');
}

function togglePw(inputId, btn) {
  const input = document.getElementById(inputId);
  const show  = input.type === 'password';
  input.type  = show ? 'text' : 'password';
  btn.innerHTML = show
    ? '<svg viewBox="0 0 24 24" style="width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:2"><line x1="1" y1="1" x2="23" y2="23"/><path d="M10.43 10.45A3 3 0 0 0 12 12a3 3 0 0 0 1.55-5.55M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/></svg>'
    : '<svg viewBox="0 0 24 24" style="width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
}

const alertEl = document.querySelector('.alert');
if (alertEl) setTimeout(() => { alertEl.style.transition='opacity .4s'; alertEl.style.opacity='0'; setTimeout(()=>alertEl.remove(),400); }, 4500);
</script>

</div><!-- /.page-body -->
</div><!-- /.main-content -->
</body>
</html>