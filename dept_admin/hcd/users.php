<?php
// dept_admin/hcd/users.php
require '_layout.php';

$msg   = '';
$error = '';

// Read success messages passed via redirect
$successMessages = [
    'password_reset' => 'Password reset successfully.',
    'status_updated' => 'Status updated.',
    'deleted'        => 'Staff member removed.',
    'added'          => 'Staff member added successfully.',
];
if (!empty($_GET['success']) && isset($successMessages[$_GET['success']])) {
    $msg = $successMessages[$_GET['success']];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $code  = trim($_POST['staff_code'] ?? '');
        $name  = trim($_POST['full_name']  ?? '');
        $email = trim($_POST['email']      ?? '');
        $phone = trim($_POST['phone']      ?? '');
        $role  = 'staff';
        $rawPw = trim($_POST['password']   ?? '');

        if (!$code || !$name || !$email || !$rawPw) {
            $error = 'Please fill in all required fields.';
        } elseif (!preg_match('/^\d{6}$/', $code)) {
            $error = 'Staff code must be exactly 6 digits (numbers only).';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email address.';
        } else {
            $chk = $conn->prepare("SELECT staff_id FROM staff WHERE email = ? OR staff_code = ?");
            $chk->bind_param("ss", $email, $code);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $error = 'A staff member with this email or staff code already exists.';
            } else {
                $hash = password_hash($rawPw, PASSWORD_BCRYPT);
                $dept = 'Human Capital Department';
                $ins  = $conn->prepare(
                    "INSERT INTO staff (staff_code, full_name, email, password_hash, department, dept_id, role, phone, status)
                     VALUES (?, ?, ?, ?, ?, 5, ?, ?, 'active')"
                );
                $ins->bind_param("sssssss", $code, $name, $email, $hash, $dept, $role, $phone);
                if ($ins->execute()) {
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
            $u = $conn->prepare("UPDATE staff SET status = ? WHERE staff_id = ? AND dept_id = 5");
            $u->bind_param("si", $newSt, $sid);
            if ($u->execute()) {
                header('Location: users.php?success=status_updated');
                exit;
            } else {
                $error = 'Update failed.';
            }
        }
    }

    if ($action === 'reset_password') {
        $sid   = (int)($_POST['staff_id']    ?? 0);
        $newPw = trim($_POST['new_password'] ?? '');
        if (strlen($newPw) < 6) {
            $error = 'Password must be at least 6 characters.';
        } else {
            $hash = password_hash($newPw, PASSWORD_BCRYPT);
            $u = $conn->prepare("UPDATE staff SET password_hash = ? WHERE staff_id = ? AND dept_id = 5");
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
            $d = $conn->prepare("DELETE FROM staff WHERE staff_id = ? AND dept_id = 5");
            $d->bind_param("i", $sid);
            if ($d->execute()) {
                header('Location: users.php?success=deleted');
                exit;
            } else {
                $error = 'Delete failed.';
            }
        }
    }
} // ← end of POST block (this was missing before!)

$search = trim($_GET['q']    ?? '');
$roleF  = $_GET['role']      ?? '';

$where  = ["dept_id = 5"];
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>HCD Admin — Manage Users | UniKL Help Desk</title>
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
  </style>
</head>
<body>
<?php include '_sidebar.php'; ?>

<main class="main-content">
  <div class="page-header">
    <div>
      <div class="page-eyebrow">Human Capital Department</div>
      <h1 class="page-title">Manage Users <span class="title-count"><?= count($staffList) ?></span></h1>
    </div>
    <button class="btn-primary-sm" onclick="openModal('addModal')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14">
        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
      </svg>
      Add Staff
    </button>
  </div>

  <?php if ($msg):  ?><div class="alert alert-success"><?= $msg ?></div><?php endif; ?>
  <?php if ($error):?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

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
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($staffList)): ?>
        <tr><td colspan="7" class="empty-row">No staff found.</td></tr>
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
            <span class="status-dot <?= $s['status'] ?>"></span>
            <?= ucfirst($s['status']) ?>
          </td>
          <td class="actions-cell">

            <!-- View button -->
            <button type="button" class="icon-btn btn-view" title="View"
                    onclick="openViewModal(<?= $s['staff_id'] ?>, '<?= htmlspecialchars(addslashes($s['full_name'])) ?>', '<?= htmlspecialchars(addslashes($s['email'])) ?>', '<?= htmlspecialchars(addslashes($s['phone'] ?? '')) ?>', '<?= $s['role'] ?>', '<?= $s['status'] ?>', '<?= htmlspecialchars(addslashes($s['staff_code'])) ?>')">
              <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>

            <!-- Reset Password button -->
            <button type="button" class="icon-btn btn-edit" title="Reset Password"
                    onclick="openResetModal(<?= $s['staff_id'] ?>, '<?= htmlspecialchars(addslashes($s['full_name'])) ?>')">
              <svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            </button>

            <!-- Toggle Status (hidden for own account) -->
            <?php if ($s['staff_id'] !== (int)$_SESSION['staff_id']): ?>
            <form method="POST">
              <input type="hidden" name="action"     value="toggle_status"/>
              <input type="hidden" name="staff_id"   value="<?= $s['staff_id'] ?>"/>
              <input type="hidden" name="new_status" value="<?= $s['status']==='active'?'inactive':'active' ?>"/>
              <?php if ($s['status'] === 'active'): ?>
              <button type="submit" class="icon-btn btn-deactivate" title="Deactivate">
                <svg viewBox="0 0 24 24"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>
              </button>
              <?php else: ?>
              <button type="submit" class="icon-btn btn-activate" title="Activate">
                <svg viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"/></svg>
              </button>
              <?php endif; ?>
            </form>
            <?php endif; ?>

            <!-- Delete (hidden for own account) -->
            <?php if ($s['staff_id'] !== (int)$_SESSION['staff_id']): ?>
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
          <input type="text" name="phone" placeholder="0187001007"/>
        </div>
         <div class="field">
          <label>Password <span class="req">*</span></label>
          <div style="position:relative;">
            <input type="password" name="password" id="addPasswordInput"
                   placeholder="Min. 6 characters" required autocomplete="new-password"
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
                 placeholder="Min. 6 characters" required
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
  document.getElementById('viewStatus').textContent = status.charAt(0).toUpperCase() + status.slice(1);
  document.getElementById('viewAvatar').textContent = name.charAt(0).toUpperCase();
  document.getElementById('viewAvatar').style.background = role === 'admin' ? '#6366f1' : '#0ea5e9';
  openModal('viewModal');
}

function confirmDelete(id, name) {
  if (!confirm('Delete ' + name + '? This cannot be undone.')) return;
  document.getElementById('deleteStaffId').value = id;
  document.getElementById('deleteForm').submit();
}

function validateAddForm() {
  const code = document.getElementById('staffCodeInput').value.trim();
  if (!/^\d{6}$/.test(code)) {
    alert('Staff code must be exactly 6 digits (numbers only).');
    document.getElementById('staffCodeInput').focus();
    return false;
  }
  return true;
}

document.getElementById('staffCodeInput').addEventListener('input', function () {
  this.value = this.value.replace(/\D/g, '').slice(0, 6);
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
</script>
</body>
</html>