<?php
// dept_admin/it/categories.php
require_once __DIR__ . '/_layout.php';

// ── Session for flash messages ────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();

// ── Sidebar badge counts ──────────────────────────────────────────────────────
$openCount = $closedCount = 0;
$stmt = $conn->prepare(
    "SELECT SUM(status='open') AS oc, SUM(status='closed') AS cc
     FROM complaints WHERE dept_id = 4"
);
$stmt->execute();
$stmt->bind_result($ocVal, $ccVal);
$stmt->fetch();
$stmt->close();
$openCount   = (int)($ocVal ?? 0);
$closedCount = (int)($ccVal ?? 0);

// ── Handle POST (PRG pattern) ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $deptId = 4;

    if ($action === 'add') {
        $suffix = trim($_POST['category_name'] ?? '');
        $name   = $suffix !== '' ? 'IT Dept / ' . $suffix : '';
        if (empty($suffix)) {
            $_SESSION['flash_error'] = 'Category name cannot be empty.';
        } else {
$chk = $conn->prepare("SELECT COUNT(*) FROM categories WHERE category_name = ? AND dept_id = ? LIMIT 1");
$chk->bind_param("si", $name, $deptId);
$chk->execute();
$chk->bind_result($dupCount);
$chk->fetch();
$chk->close();
if ($dupCount > 0) {
    $_SESSION['flash_error'] = 'A category with that name already exists.';
            } else {
                $ins = $conn->prepare("INSERT INTO categories (category_name, dept_id, created_at) VALUES (?, ?, NOW())");
                $ins->bind_param("si", $name, $deptId);
                if ($ins->execute()) {
                    $_SESSION['flash_success'] = 'Category <strong>' . htmlspecialchars($name) . '</strong> added successfully.';
                } else {
                    $_SESSION['flash_error'] = 'Failed to add category. Please try again.';
                }
                $ins->close();
            }
        }
    }

    elseif ($action === 'edit') {
        $id     = (int)($_POST['category_id'] ?? 0);
        $suffix = trim($_POST['category_name'] ?? '');
        $suffix = trim(preg_replace('/^(IT\s+Dept\s*\/\s*)+/i', '', $suffix));
        $name   = $suffix !== '' ? 'IT Dept / ' . $suffix : '';
        if (!$id || empty($suffix)) {
            $_SESSION['flash_error'] = 'Invalid data submitted.';
        } else {
            $upd = $conn->prepare("UPDATE categories SET category_name = ? WHERE category_id = ? AND dept_id = ?");
            $upd->bind_param("sii", $name, $id, $deptId);
            if ($upd->execute()) {
                $_SESSION['flash_success'] = $upd->affected_rows > 0
                    ? 'Category updated successfully.'
                    : 'No changes were made.';
            } else {
                $_SESSION['flash_error'] = 'Could not update category.';
            }
            $upd->close();
        }
    }

    elseif ($action === 'delete') {
        $id = (int)($_POST['category_id'] ?? 0);
        if (!$id) {
            $_SESSION['flash_error'] = 'Invalid category.';
        } else {
$chk = $conn->prepare("SELECT COUNT(*) FROM complaints WHERE category_id = ?");
$chk->bind_param("i", $id);
$chk->execute();
$chk->bind_result($cnt);
$chk->fetch();
$chk->close();
$cnt = (int)($cnt ?? 0);
            if ($cnt > 0) {
                $_SESSION['flash_error'] = "Cannot delete: this category is used by <strong>{$cnt}</strong> complaint(s).";
            } else {
                $del = $conn->prepare("DELETE FROM categories WHERE category_id = ? AND dept_id = ?");
                $del->bind_param("ii", $id, $deptId);
                if ($del->execute() && $del->affected_rows > 0) {
                    $_SESSION['flash_success'] = 'Category deleted.';
                } else {
                    $_SESSION['flash_error'] = 'Could not delete category.';
                }
                $del->close();
            }
        }
    }

    header('Location: categories.php');
    exit;
}

// ── Read & clear flash ────────────────────────────────────────────────────────
$successMsg = $_SESSION['flash_success'] ?? '';
$errorMsg   = $_SESSION['flash_error']   ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// ── Fetch IT categories (dept_id = 4) ────────────────────────────────────────
$categories = [];
$deptId = 4;
$stmt = $conn->prepare(
    "SELECT c.category_id, c.category_name, c.created_at,
            COUNT(comp.ticket_id) AS usage_count
     FROM categories c
     LEFT JOIN complaints comp ON comp.category_id = c.category_id
     WHERE c.dept_id = ?
     GROUP BY c.category_id
     ORDER BY c.created_at ASC"
);
$stmt->bind_param("i", $deptId);
$stmt->execute();
$stmt->bind_result($cId, $cName, $cCreated, $cUsage);
while ($stmt->fetch()) {
    $categories[] = [
        'category_id'   => $cId,
        'category_name' => $cName,
        'created_at'    => $cCreated,
        'usage_count'   => $cUsage,
    ];
}
$stmt->close();

$currentPage = 'categories';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Categories | UniKL Help Desk – IT Admin</title>
  <?php include __DIR__ . '/_head_assets.php'; ?>
  <style>
    /* ── Page grid ── */
    .page-grid { display: grid; grid-template-columns: 1fr 340px; gap: 24px; align-items: start; }
    @media (max-width: 900px) { .page-grid { grid-template-columns: 1fr; } }

    /* ── Alert animations ── */
    .alert { animation: fadeSlideIn .25s ease; }
    @keyframes fadeSlideIn { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:translateY(0); } }

    /* ── Table card ── */
    .tbl-card { background: white; border-radius: 14px; border: 1px solid var(--gray-200); overflow: hidden; }
    .tbl-wrap  { overflow-x: auto; }

    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    thead th {
      background: var(--gray-100); padding: 11px 16px;
      text-align: left; font-size: 11px; font-weight: 600;
      color: var(--gray-500); text-transform: uppercase;
      letter-spacing: .05em; border-bottom: 1px solid var(--gray-200);
      white-space: nowrap;
    }
    tbody tr { border-bottom: 1px solid var(--gray-200); transition: background .12s; }
    tbody tr:last-child { border-bottom: none; }
    tbody tr:hover { background: var(--off-white); }
    tbody td { padding: 13px 16px; color: var(--gray-700); vertical-align: middle; }

    /* ── Category name cell ── */
    .cat-name { font-weight: 500; color: var(--gray-900); font-size: 13px; }
    .cat-id   { font-family: monospace; font-size: 11px; color: var(--gray-500); margin-top: 2px; }

    /* ── Usage pill ── */
    .usage-pill { display: inline-block; font-size: 11px; font-weight: 600; padding: 3px 10px; border-radius: 20px; }
    .usage-zero { background: var(--gray-100); color: var(--gray-500); }
    .usage-some { background: #DBEAFE; color: #1D4ED8; }

    /* ── Date cell ── */
    .date-text { font-size: 12px; color: var(--gray-500); white-space: nowrap; }
    .date-sub  { font-size: 11px; color: var(--gray-300); margin-top: 2px; }

    /* ── Action buttons ── */
    .action-btns { display: flex; align-items: center; gap: 6px; }
    .btn-icon {
      display: inline-flex; align-items: center; justify-content: center;
      width: 30px; height: 30px; border-radius: 7px;
      border: 1.5px solid var(--gray-200); background: white;
      cursor: pointer; color: var(--gray-500); transition: all .15s;
    }
    .btn-icon svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; }
    .btn-icon:hover { border-color: var(--blue); color: var(--blue); background: var(--blue-light); }
    .btn-icon.delete:hover { border-color: #DC2626; color: #DC2626; background: #FEF2F2; }

    /* ── Empty state ── */
    .empty-state { text-align: center; padding: 48px 20px; color: var(--gray-500); }
    .empty-state svg { width: 36px; height: 36px; margin: 0 auto 10px; display: block; stroke: var(--gray-300); fill: none; stroke-width: 1.5; }
    .empty-state p { font-size: 13px; margin-top: 4px; }

    /* ── Form card ── */
    .form-card { background: white; border-radius: 14px; border: 1px solid var(--gray-200); overflow: hidden; transition: border-color .2s, box-shadow .2s; }
    .form-card.editing-active { border-color: #D97706; box-shadow: 0 0 0 3px rgba(217,119,6,.12); }
    .form-card-header { padding: 16px 20px; border-bottom: 1px solid var(--gray-200); display: flex; align-items: center; gap: 10px; }
    .form-card-icon { width: 36px; height: 36px; border-radius: 8px; background: var(--blue-light); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .form-card-icon svg { width: 16px; height: 16px; fill: none; stroke: var(--blue); stroke-width: 2; }
    .form-card-title { font-size: 14px; font-weight: 600; color: var(--gray-900); }
    .form-card-sub   { font-size: 12px; color: var(--gray-500); margin-top: 1px; }
    .form-card-body  { padding: 20px; }

    /* ── Form fields ── */
    .field label { display: block; font-size: 12px; font-weight: 600; color: var(--gray-700); margin-bottom: 6px; }
    .field input { width: 100%; padding: 10px 12px; border: 1.5px solid var(--gray-300); border-radius: 8px; font-family: inherit; font-size: 13px; outline: none; box-sizing: border-box; }
    .field input:focus { border-color: var(--blue); }
    .field-hint { font-size: 11px; color: var(--gray-500); margin-top: 5px; }
    .prefix-wrap { display: flex; align-items: stretch; }
    .prefix-label {
      display: flex; align-items: center; padding: 10px 12px;
      background: var(--gray-100); border: 1.5px solid var(--gray-300);
      border-right: none; border-radius: 8px 0 0 8px;
      font-size: 12px; font-weight: 600; color: var(--blue);
      white-space: nowrap; flex-shrink: 0;
    }
    .prefix-input { border-radius: 0 8px 8px 0 !important; }

    /* ── Edit mode bar ── */
    .edit-mode-bar {
      display: none; align-items: center; gap: 8px;
      padding: 9px 12px; background: #FEF3C7;
      border: 1px solid rgba(217,119,6,.2); border-radius: 8px;
      margin-bottom: 14px; font-size: 12px; color: #92400E; font-weight: 500;
    }
    .edit-mode-bar svg { width: 14px; height: 14px; fill: none; stroke: #D97706; stroke-width: 2; flex-shrink: 0; }
    .edit-mode-bar.show { display: flex; }

    /* ── Submit button ── */
    .btn-submit {
      display: flex; align-items: center; justify-content: center; gap: 7px;
      width: 100%; padding: 11px; border: none; border-radius: 8px;
      background: var(--blue); color: white;
      font-family: inherit; font-size: 13px; font-weight: 600;
      cursor: pointer; margin-top: 14px; transition: background .15s;
    }
    .btn-submit:hover { background: var(--blue-dark); }
    .btn-submit svg { width: 14px; height: 14px; fill: none; stroke: white; stroke-width: 2; }

    .btn-cancel-edit {
      display: none; align-items: center; justify-content: center; gap: 6px;
      width: 100%; padding: 10px; border: 1.5px solid var(--gray-200);
      background: white; color: var(--gray-700); border-radius: 8px;
      font-family: inherit; font-size: 13px; font-weight: 500;
      cursor: pointer; margin-top: 8px; transition: border-color .15s;
    }
    .btn-cancel-edit:hover { border-color: var(--gray-300); }
    .btn-cancel-edit svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2; }

    /* ── Search in header ── */
    .tbl-header { padding: 16px 20px; border-bottom: 1px solid var(--gray-200); display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
    .tbl-header-left { display: flex; align-items: center; gap: 10px; }
    .tbl-icon { width: 36px; height: 36px; border-radius: 8px; background: var(--gray-100); display: flex; align-items: center; justify-content: center; }
    .tbl-icon svg { width: 16px; height: 16px; fill: none; stroke: var(--gray-500); stroke-width: 1.8; }
    .count-badge { background: var(--blue); color: white; font-size: 11px; font-weight: 700; padding: 2px 9px; border-radius: 20px; }
    .search-box { position: relative; }
    .search-box svg { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 13px; height: 13px; stroke: var(--gray-500); fill: none; stroke-width: 2; pointer-events: none; }
    .search-box input { padding: 8px 12px 8px 30px; font-size: 12px; border: 1.5px solid var(--gray-200); border-radius: 7px; font-family: inherit; outline: none; width: 190px; }
    .search-box input:focus { border-color: var(--blue); }

    /* ── Modals ── */
    .modal-overlay { display: none; position: fixed; inset: 0; z-index: 300; background: rgba(0,0,0,.5); align-items: center; justify-content: center; }
    .modal-overlay.open { display: flex; }
    .modal-box { background: white; border-radius: 14px; padding: 28px 24px; max-width: 380px; width: 90%; text-align: center; box-shadow: 0 20px 60px rgba(0,0,0,.15); animation: scaleIn .2s ease; }
    @keyframes scaleIn { from { opacity:0; transform:scale(.9); } to { opacity:1; transform:scale(1); } }
    .modal-ico { width: 52px; height: 52px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 14px; }
    .modal-ico.danger  { background: #FEE2E2; } .modal-ico.danger svg  { stroke: #DC2626; }
    .modal-ico.warning { background: #FEF3C7; } .modal-ico.warning svg { stroke: #D97706; }
    .modal-ico svg { width: 22px; height: 22px; fill: none; stroke-width: 2; }
    .modal-box h3 { font-family: 'DM Serif Display', serif; font-size: 19px; color: var(--gray-900); margin-bottom: 6px; }
    .modal-box p  { font-size: 13px; color: var(--gray-500); line-height: 1.6; margin-bottom: 20px; }
    .modal-actions { display: flex; gap: 10px; justify-content: center; }
    .btn-modal-cancel  { padding: 9px 20px; border-radius: 7px; border: 1.5px solid var(--gray-200); background: white; color: var(--gray-700); font-family: inherit; font-size: 13px; cursor: pointer; }
    .btn-modal-cancel:hover { border-color: var(--gray-400); }
    .btn-modal-delete  { padding: 9px 20px; border-radius: 7px; border: none; background: #DC2626; color: white; font-family: inherit; font-size: 13px; font-weight: 600; cursor: pointer; }
    .btn-modal-delete:hover { background: #B91C1C; }
    .btn-modal-primary { padding: 9px 20px; border-radius: 7px; border: none; background: var(--blue); color: white; font-family: inherit; font-size: 13px; font-weight: 600; cursor: pointer; }
    .btn-modal-primary:hover { background: var(--blue-dark); }
    .btn-modal-discard { padding: 9px 20px; border-radius: 7px; border: 1.5px solid var(--gray-200); background: white; color: var(--gray-700); font-family: inherit; font-size: 13px; cursor: pointer; }
    .btn-modal-discard:hover { border-color: #DC2626; color: #DC2626; }

    #no-search-row { display: none; }
  </style>
</head>
<body>

<?php include __DIR__ . '/_sidebar.php'; ?>

<!-- ══ MAIN ══════════════════════════════════════════════════════════════════ -->
<main class="main-content">

  <!-- Page Header -->
  <div class="page-header">
    <div>
      <div class="page-eyebrow">IT Department</div>
      <h1 class="page-title">
  Categories
  <span class="title-count"><?= count($categories) ?></span>
</h1>
    </div>
  </div>

  <!-- Flash Messages -->
  <?php if ($successMsg): ?>
  <div class="alert alert-success" style="display:flex;align-items:flex-start;gap:9px;padding:12px 16px;border-radius:9px;font-size:13px;margin-bottom:18px;background:#ECFDF5;color:#065F46;border:1px solid rgba(5,150,105,.15);">
    <svg style="width:16px;height:16px;flex-shrink:0;fill:none;stroke:#059669;stroke-width:2;margin-top:1px;" viewBox="0 0 24 24"><polyline points="20,6 9,17 4,12"/></svg>
    <span><?= $successMsg ?></span>
  </div>
  <?php endif; ?>
  <?php if ($errorMsg): ?>
  <div class="alert alert-error" style="display:flex;align-items:flex-start;gap:9px;padding:12px 16px;border-radius:9px;font-size:13px;margin-bottom:18px;background:#FEF2F2;color:#991B1B;border:1px solid rgba(220,38,38,.15);">
    <svg style="width:16px;height:16px;flex-shrink:0;fill:none;stroke:#DC2626;stroke-width:2;margin-top:1px;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <span><?= $errorMsg ?></span>
  </div>
  <?php endif; ?>

  <!-- Page Grid -->
  <div class="page-grid">

    <!-- ── LEFT: Table ── -->
    <div>
      <div class="tbl-card">
        <div class="tbl-header">
          <div class="tbl-header-left">
            <div class="tbl-icon">
              <svg viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
            </div>
            <div>
              <div style="font-size:14px;font-weight:600;color:var(--gray-900);">IT Department Categories</div>
              <div style="font-size:12px;color:var(--gray-500);margin-top:1px;">Manage complaint categories</div>
            </div>
            <span class="count-badge"><?= count($categories) ?></span>
          </div>
          <div class="search-box">
            <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
            <input type="text" id="cat-search" placeholder="Search categories…" autocomplete="off"/>
          </div>
        </div>

        <div class="tbl-wrap">
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Category Name</th>
                <th>Usage</th>
                <th>Created</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="cat-tbody">
              <?php if (empty($categories)): ?>
              <tr><td colspan="5">
                <div class="empty-state">
                  <svg viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                  <p>No categories yet. Add your first one →</p>
                </div>
              </td></tr>
              <?php else: ?>
              <?php foreach ($categories as $i => $cat): ?>
              <tr data-name="<?= strtolower(htmlspecialchars($cat['category_name'])) ?>">
                <td style="color:var(--gray-500);font-size:12px;font-family:monospace;"><?= $i + 1 ?></td>
                <td>
                  <div class="cat-name"><?= htmlspecialchars(preg_replace('/^IT\s+Dept\s*\/\s*/i', '', $cat['category_name'])) ?></div>
                </td>
                <td>
                  <?php $u = (int)$cat['usage_count']; ?>
                  <span class="usage-pill <?= $u > 0 ? 'usage-some' : 'usage-zero' ?>">
                    <?= $u ?> complaint<?= $u !== 1 ? 's' : '' ?>
                  </span>
                </td>
                <td>
                  <div class="date-text"><?= date('d M Y', strtotime($cat['created_at'])) ?></div>
                  <div class="date-sub"><?= date('H:i', strtotime($cat['created_at'])) ?></div>
                </td>
                <td>
                  <div class="action-btns">
                    <button type="button" class="btn-icon" title="Edit"
                      onclick="requestEdit(<?= $cat['category_id'] ?>, <?= htmlspecialchars(json_encode($cat['category_name'])) ?>)">
                      <svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    </button>
                    <button type="button" class="btn-icon delete" title="Delete"
                      onclick="requestDelete(<?= $cat['category_id'] ?>, <?= htmlspecialchars(json_encode($cat['category_name'])) ?>, <?= (int)$cat['usage_count'] ?>)">
                      <svg viewBox="0 0 24 24"><polyline points="3,6 5,6 21,6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                    </button>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
              <tr id="no-search-row"><td colspan="5">
                <div class="empty-state">
                  <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                  <p>No categories match your search.</p>
                </div>
              </td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ── RIGHT: Add / Edit Form ── -->
    <div>
      <div class="form-card" id="formCard">
        <div class="form-card-header">
          <div class="form-card-icon" id="formIcon">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
          </div>
          <div>
            <div class="form-card-title" id="formCardTitle">Add Category</div>
            <div class="form-card-sub"   id="formCardSub">Create a new complaint category</div>
          </div>
        </div>

        <div class="form-card-body">

          <!-- Edit mode indicator -->
          <div class="edit-mode-bar" id="editModeBar">
            <svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            <span>Editing: <strong id="editingName">—</strong></span>
          </div>

          <form method="POST" action="categories.php" id="catForm">
            <input type="hidden" name="action"      id="formAction"     value="add"/>
            <input type="hidden" name="category_id" id="formCategoryId" value=""/>

            <div class="field">
              <label for="category_name">Category Name <span style="color:#DC2626;">*</span></label>
              <div class="prefix-wrap">
                <input type="text" id="category_name" name="category_name"
                  style="border-radius:8px !important;"
                  placeholder="e.g. Hardware &amp; Software"
                  maxlength="140" required autocomplete="off"/>
              </div>
            </div>

            <button type="submit" class="btn-submit" id="submitBtn">
              <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
              <span id="submitBtnLabel">Add Category</span>
            </button>

            <button type="button" class="btn-cancel-edit" id="cancelEditBtn" onclick="cancelEdit()">
              <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
              Cancel Edit
            </button>
          </form>

        </div>
      </div>
    </div>

  </div><!-- /.page-grid -->

</main>

<!-- ══ DELETE MODAL ══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal-box">
    <div class="modal-ico danger">
      <svg viewBox="0 0 24 24"><polyline points="3,6 5,6 21,6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
    </div>
    <h3>Delete Category?</h3>
    <p id="deleteModalText">Are you sure? This action cannot be undone.</p>
    <div class="modal-actions">
      <button type="button" class="btn-modal-cancel" onclick="closeDeleteModal()">Cancel</button>
      <form method="POST" action="categories.php" id="deleteForm" style="display:inline">
        <input type="hidden" name="action" value="delete"/>
        <input type="hidden" name="category_id" id="deleteModalId" value=""/>
        <button type="submit" class="btn-modal-delete">Yes, Delete</button>
      </form>
    </div>
  </div>
</div>

<!-- ══ UNSAVED CHANGES MODAL ═════════════════════════════════════════════════ -->
<div class="modal-overlay" id="unsavedModal">
  <div class="modal-box">
    <div class="modal-ico warning">
      <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    </div>
    <h3>Unsaved Changes</h3>
    <p>You're editing <strong id="unsavedEditingName" style="color:var(--gray-900);"></strong>. Save or cancel before continuing.</p>
    <div class="modal-actions">
      <button type="button" class="btn-modal-primary" onclick="closeUnsavedModal(); scrollToForm();">Back to Edit</button>
      <button type="button" class="btn-modal-discard" onclick="closeUnsavedModal(); forceCancelEdit(); runPending();">Discard &amp; Continue</button>
    </div>
  </div>
</div>

<script>
var isEditMode = false;
var pendingFn  = null;

// ── Search ────────────────────────────────────────────────────────────────────
(function () {
  var input = document.getElementById('cat-search');
  var noRow = document.getElementById('no-search-row');
  if (!input) return;
  input.addEventListener('input', function () {
    var q = this.value.trim().toLowerCase();
    var visible = 0;
    document.querySelectorAll('#cat-tbody tr[data-name]').forEach(function (row) {
      var match = !q || row.dataset.name.includes(q);
      row.style.display = match ? '' : 'none';
      if (match) visible++;
    });
    if (noRow) noRow.style.display = (q && visible === 0) ? '' : 'none';
  });
})();

// ── Guard: block action if unsaved edit in progress ───────────────────────────
function guardAction(callback) {
  if (!isEditMode) { callback(); return; }
  pendingFn = callback;
  document.getElementById('unsavedEditingName').textContent =
    document.getElementById('editingName').textContent;
  document.getElementById('unsavedModal').classList.add('open');
}

// ── Intercept sidebar nav clicks while editing ────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.sidebar a, .sidebar-nav a').forEach(function (link) {
    link.addEventListener('click', function (e) {
      if (!isEditMode) return;
      e.preventDefault();
      var href = this.href;
      guardAction(function () { window.location.href = href; });
    });
  });
});

// ── Intercept keyboard refresh ────────────────────────────────────────────────
document.addEventListener('keydown', function (e) {
  if (!isEditMode) return;
  var isRefresh = (e.key === 'F5') || ((e.ctrlKey || e.metaKey) && (e.key === 'r' || e.key === 'R'));
  if (isRefresh) {
    e.preventDefault();
    guardAction(function () { isEditMode = false; window.location.reload(); });
  }
});

// ── Row button wrappers ───────────────────────────────────────────────────────
function requestEdit(id, fullName)           { guardAction(function () { startEdit(id, fullName); }); }
function requestDelete(id, name, usageCount) { guardAction(function () { confirmDelete(id, name, usageCount); }); }

// ── Start edit ────────────────────────────────────────────────────────────────
function startEdit(id, fullName) {
  var suffix = fullName.replace(/^(IT\s+Dept\s*\/\s*)+/i, '').trim();

  document.getElementById('formAction').value        = 'edit';
  document.getElementById('formCategoryId').value    = id;
  document.getElementById('category_name').value     = suffix;
  document.getElementById('editingName').textContent = fullName;
  document.getElementById('editModeBar').classList.add('show');
  document.getElementById('cancelEditBtn').style.display     = 'flex';
  document.getElementById('formCard').classList.add('editing-active');
  document.getElementById('category_name').style.borderRadius = '8px';
  document.getElementById('formCardTitle').textContent = 'Edit Category';
  document.getElementById('formCardSub').textContent   = 'Update the category name below';
  document.getElementById('submitBtn').innerHTML =
    '<svg viewBox="0 0 24 24" width="14" height="14" style="fill:none;stroke:white;stroke-width:2;"><polyline points="20,6 9,17 4,12"/></svg> Save Changes';

  isEditMode = true;
  document.getElementById('category_name').focus();
  scrollToForm();
}

// ── Cancel edit ───────────────────────────────────────────────────────────────
function cancelEdit()      { forceCancelEdit(); }
function forceCancelEdit() {
  isEditMode = false;
  document.getElementById('formAction').value       = 'add';
  document.getElementById('formCategoryId').value   = '';
  document.getElementById('category_name').value    = '';
  document.getElementById('editModeBar').classList.remove('show');
  document.getElementById('cancelEditBtn').style.display     = 'none';
  document.getElementById('formCard').classList.remove('editing-active');
  document.getElementById('category_name').style.borderRadius = '8px';
  document.getElementById('formCardTitle').textContent = 'Add Category';
  document.getElementById('formCardSub').textContent   = 'Create a new complaint category';
  document.getElementById('submitBtn').innerHTML =
    '<svg viewBox="0 0 24 24" width="14" height="14" style="fill:none;stroke:white;stroke-width:2;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg> Add Category';
}

document.getElementById('catForm').addEventListener('submit', function () { isEditMode = false; });

// ── Delete modal ──────────────────────────────────────────────────────────────
function confirmDelete(id, name, usageCount) {
  if (usageCount > 0) {
    alert('Cannot delete "' + name + '" — it is used by ' + usageCount + ' complaint(s).');
    return;
  }
  document.getElementById('deleteModalId').value    = id;
  document.getElementById('deleteModalText').textContent =
    'Are you sure you want to delete "' + name + '"? This action cannot be undone.';
  document.getElementById('deleteModal').classList.add('open');
}
function closeDeleteModal() { document.getElementById('deleteModal').classList.remove('open'); }
document.getElementById('deleteModal').addEventListener('click', function (e) { if (e.target === this) closeDeleteModal(); });

// ── Unsaved modal ─────────────────────────────────────────────────────────────
function closeUnsavedModal() { document.getElementById('unsavedModal').classList.remove('open'); }
function runPending() { if (typeof pendingFn === 'function') { var fn = pendingFn; pendingFn = null; fn(); } }
document.getElementById('unsavedModal').addEventListener('click', function (e) { if (e.target === this) closeUnsavedModal(); });

// ── Helpers ───────────────────────────────────────────────────────────────────
function scrollToForm() {
  document.getElementById('catForm').scrollIntoView({ behavior: 'smooth', block: 'center' });
}
</script>
</body>
</html>