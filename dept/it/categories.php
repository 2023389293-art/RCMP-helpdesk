<?php
// dept/it/categories.php
require_once __DIR__ . '/../auth_guard.php';
if (isset($_GET['logout'])) { staffLogout(); }
require_once __DIR__ . '/../../db_connect.php';

// ── Counts (for sidebar badges) ───────────────────────────────────────────────
$openCount = $closedCount = 0;
$stmt = $conn->prepare(
    "SELECT SUM(status='open') AS oc, SUM(status='closed') AS cc
     FROM complaints WHERE dept_id = ?"
);
$stmt->bind_param("i", $deptId);
$stmt->execute();
$stmt->bind_result($openCount, $closedCount);
$stmt->fetch();
$openCount   = (int)($openCount  ?? 0);
$closedCount = (int)($closedCount ?? 0);
$stmt->close();

// ── Handle POST actions (PRG pattern) ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── ADD ──────────────────────────────────────────────────────────────────
    if ($action === 'add') {
        $suffix = trim($_POST['category_name'] ?? '');
        $name   = $suffix !== '' ? 'IT Dept / ' . $suffix : '';
        if (empty($suffix)) {
            $_SESSION['flash_error'] = 'Category name cannot be empty.';
        } else {
            $chk = $conn->prepare("SELECT category_id FROM categories WHERE category_name = ? AND dept_id = ? LIMIT 1");
$chk->bind_param("si", $name, $deptId);
$chk->execute();
$chk->store_result();
$chkRows = $chk->num_rows;
$chk->free_result();
$chk->close();
if ($chkRows > 0) {
                $_SESSION['flash_error'] = 'A category with that name already exists in this department.';
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

    // ── EDIT ─────────────────────────────────────────────────────────────────
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
                $_SESSION['flash_error'] = 'Could not update category. Please try again.';
            }
            $upd->close();
        }
    }

    // ── DELETE ────────────────────────────────────────────────────────────────
    elseif ($action === 'delete') {
        $id = (int)($_POST['category_id'] ?? 0);
        if (!$id) {
            $_SESSION['flash_error'] = 'Invalid category.';
        } else {
            $chk = $conn->prepare("SELECT COUNT(*) AS cnt FROM complaints WHERE category_id = ?");
$chk->bind_param("i", $id);
$chk->execute();
$chk->bind_result($cnt);
$chk->fetch();
$cnt = (int)($cnt ?? 0);
$chk->close();
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

    session_write_close();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// ── Read and clear flash messages ─────────────────────────────────────────────
$successMsg = $_SESSION['flash_success'] ?? '';
$errorMsg   = $_SESSION['flash_error']   ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// ── Fetch all categories for this dept ────────────────────────────────────────
$categories = [];
$result = $conn->query(
    "SELECT c.category_id, c.category_name, c.created_at,
            COUNT(comp.ticket_id) AS usage_count
     FROM categories c
     LEFT JOIN complaints comp ON comp.category_id = c.category_id
     WHERE c.dept_id = " . (int)$deptId . "
     GROUP BY c.category_id
     ORDER BY c.created_at ASC"
);
while ($row = $result->fetch_assoc()) $categories[] = $row;
$result->free();

// ── Layout vars ───────────────────────────────────────────────────────────────
$activeNav    = 'categories';
$pageTitle    = 'Categories';
$pageSubtitle = 'Information Technology Department';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Categories | UniKL Help Desk – IT</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet"/>
  <style>
    /* ── Alerts ── */
    .alert{display:flex;align-items:flex-start;gap:10px;padding:13px 16px;border-radius:10px;font-size:14px;margin-bottom:20px;line-height:1.5;animation:fadeSlideIn .25s ease}
    .alert svg{width:17px;height:17px;flex-shrink:0;margin-top:1px;fill:none;stroke-width:2}
    .alert-success{background:#ECFDF5;color:#065F46;border:1px solid rgba(5,150,105,.15)}
    .alert-success svg{stroke:#059669}
    .alert-error{background:#FEF2F2;color:#991B1B;border:1px solid rgba(220,38,38,.15)}
    .alert-error svg{stroke:#DC2626}
    @keyframes fadeSlideIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}

    /* ── Page layout ── */
.page-grid{display:grid;grid-template-columns:1fr 340px;gap:24px;align-items:start}
@media(max-width:900px){.page-grid{grid-template-columns:1fr}}
@media(max-width:900px){.page-grid > div:last-child{order:-1}}

    /* ── Cards ── */
    .card{background:white;border-radius:14px;border:1px solid var(--g200);overflow:hidden;margin-bottom:20px;transition:border-color .2s,box-shadow .2s}
    .card:last-child{margin-bottom:0}
    .card-header{padding:18px 24px;border-bottom:1px solid var(--g100);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
@media(max-width:640px){.card-header{padding:14px 16px;gap:10px}}
@media(max-width:640px){.card-body{padding:16px}}
    .card-header-left{display:flex;align-items:center;gap:12px}
    .card-header-icon{width:40px;height:40px;border-radius:9px;background:var(--g100);display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .card-header-icon svg{width:19px;height:19px;fill:none;stroke:var(--g500);stroke-width:1.8}
    .card-header-title{font-size:15px;font-weight:600;color:var(--g900)}
    .card-header-sub{font-size:13px;color:var(--g500);margin-top:2px}
    .card-body{padding:24px}

    /* ── Category count badge ── */
    .count-badge{background:var(--accent);color:white;font-size:12px;font-weight:700;padding:3px 10px;border-radius:20px}

    /* ── Search bar in card-header ── */
    .search-wrap{position:relative;display:flex;align-items:center}
    .search-wrap svg{position:absolute;left:10px;width:14px;height:14px;stroke:var(--g400);fill:none;stroke-width:2;pointer-events:none}
    .search-input{padding:8px 12px 8px 32px;font-size:13px;border-radius:8px;border:1.5px solid var(--g200);background:white;color:var(--g900);width:200px;outline:none;transition:border-color .15s}
@media(max-width:640px){.search-input{width:130px}}
    .search-input:focus{border-color:var(--accent)}
    .search-input::placeholder{color:var(--g400)}

    /* ── Categories table ── */
    .tbl-card{background:white;border-radius:14px;border:1px solid var(--g200);overflow:hidden}
    .tbl-wrap{overflow-x:auto}
    table{width:100%;border-collapse:collapse;font-size:14px}
    thead th{background:var(--g100);padding:11px 16px;text-align:left;font-size:12px;font-weight:600;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid var(--g200)}
    tbody tr{border-bottom:1px solid var(--g200);transition:background .12s}
    tbody tr:last-child{border-bottom:none}
    tbody tr:hover{background:var(--off)}
    tbody td{padding:13px 16px;color:var(--g700);vertical-align:middle}

    /* ── Category name cell ── */
    .cat-name{font-weight:500;color:var(--g900);font-size:14px}

    /* ── Usage pill ── */
    .usage-pill{display:inline-block;font-size:12px;font-weight:600;padding:3px 10px;border-radius:20px}
    .usage-zero{background:var(--g100);color:var(--g500)}
    .usage-some{background:#DBEAFE;color:#1D4ED8}

    /* ── Action buttons ── */
    .action-btns{display:flex;align-items:center;gap:8px}
    .btn-icon{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:8px;border:1.5px solid var(--g200);background:white;cursor:pointer;transition:border-color .15s,background .15s,color .15s;color:var(--g500);text-decoration:none}
    .btn-icon svg{width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:2}
    .btn-icon:hover{border-color:var(--accent);color:var(--accent);background:var(--accent-light, #EEF3FD)}
    .btn-icon.delete:hover{border-color:#DC2626;color:#DC2626;background:#FEF2F2}

    /* ── Empty state ── */
    .empty{text-align:center;padding:52px 20px;color:var(--g500)}
    .empty svg{width:40px;height:40px;margin:0 auto 12px;display:block;stroke:var(--g300);fill:none;stroke-width:1.5}
    .empty p{font-size:13px;margin-top:6px}

    /* ── Add / Edit form card ── */
    .form-label{display:block;font-size:13px;font-weight:600;color:var(--g700);margin-bottom:7px}
    .form-input{width:100%;padding:11px 14px;border:1.5px solid var(--g300);border-radius:9px;font-family:'DM Sans',sans-serif;font-size:14px;color:var(--g900);background:white;outline:none;transition:border-color .2s,box-shadow .2s;box-sizing:border-box}
    .form-input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(26,86,219,.08)}
    .form-input::placeholder{color:#C0C0C0}
    .form-hint{font-size:12px;color:var(--g500);margin-top:6px}
    .btn-primary{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:12px;border-radius:9px;border:none;background:var(--accent);color:white;font-family:'DM Sans',sans-serif;font-size:15px;font-weight:600;cursor:pointer;transition:background .2s,transform .15s;margin-top:14px}
    .btn-primary:hover{background:#1240b0;transform:translateY(-1px)}
    .btn-primary svg{width:16px;height:16px;fill:none;stroke:white;stroke-width:2}
    .btn-secondary{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:11px;border-radius:9px;border:1.5px solid var(--g200);background:white;color:var(--g700);font-family:'DM Sans',sans-serif;font-size:14px;font-weight:500;cursor:pointer;transition:border-color .2s,color .2s;margin-top:8px;text-decoration:none}
    .btn-secondary:hover{border-color:var(--g400);color:var(--g900)}
    .btn-secondary svg{width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:2}

    /* ── Edit mode indicator ── */
    .edit-mode-bar{display:none;align-items:center;gap:10px;padding:10px 14px;background:#FEF3C7;border:1px solid rgba(217,119,6,.2);border-radius:9px;margin-bottom:14px;font-size:13px;color:#92400E;font-weight:500}
    .edit-mode-bar svg{width:15px;height:15px;fill:none;stroke:#D97706;stroke-width:2;flex-shrink:0}
    .edit-mode-bar.show{display:flex}

    /* ── Glow on form card when in edit mode ── */
    .card.editing-active{border-color:#D97706 !important;box-shadow:0 0 0 3px rgba(217,119,6,.13)}

    /* ── Modals ── */
    .modal-backdrop{display:none;position:fixed;inset:0;z-index:200;background:rgba(10,20,50,.55);backdrop-filter:blur(4px);align-items:center;justify-content:center;animation:fadeIn .2s ease}
    .modal-backdrop.show{display:flex}
    .modal{background:white;border-radius:16px;padding:32px 28px;max-width:400px;width:90%;text-align:center;animation:scaleIn .25s cubic-bezier(.34,1.56,.64,1);box-shadow:0 20px 60px rgba(0,0,0,.15)}
    .modal-icon{width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px}
    .modal-icon svg{width:24px;height:24px;fill:none;stroke-width:2}
    .modal-icon.danger{background:#FEE2E2} .modal-icon.danger svg{stroke:#DC2626}
    .modal-icon.warning{background:#FEF3C7} .modal-icon.warning svg{stroke:#D97706}
    .modal h3{font-family:'DM Serif Display',serif;font-size:21px;color:var(--g900);margin-bottom:8px}
    .modal p{font-size:14px;color:var(--g500);line-height:1.6;margin-bottom:24px}
    .modal-actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}

    .btn-cancel-modal{padding:10px 22px;border-radius:8px;border:1.5px solid var(--g200);background:white;color:var(--g700);font-family:'DM Sans',sans-serif;font-size:14px;font-weight:500;cursor:pointer;transition:border-color .15s}
    .btn-cancel-modal:hover{border-color:var(--g400)}
    .btn-delete-modal{padding:10px 22px;border-radius:8px;border:none;background:#DC2626;color:white;font-family:'DM Sans',sans-serif;font-size:14px;font-weight:600;cursor:pointer;transition:background .15s}
    .btn-delete-modal:hover{background:#B91C1C}

    /* ── Unsaved modal buttons ── */
    .btn-stay-modal{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border-radius:8px;border:none;background:var(--accent);color:white;font-family:'DM Sans',sans-serif;font-size:14px;font-weight:600;cursor:pointer;transition:background .15s}
    .btn-stay-modal:hover{background:#1240b0}
    .btn-stay-modal svg{width:14px;height:14px;fill:none;stroke:white;stroke-width:2;flex-shrink:0}
    .btn-leave-modal{padding:10px 20px;border-radius:8px;border:1.5px solid var(--g200);background:white;color:var(--g700);font-family:'DM Sans',sans-serif;font-size:14px;font-weight:500;cursor:pointer;transition:border-color .15s,color .15s}
    .btn-leave-modal:hover{border-color:#DC2626;color:#DC2626}

    @keyframes fadeIn{from{opacity:0}to{opacity:1}}
    @keyframes scaleIn{from{opacity:0;transform:scale(.88)}to{opacity:1;transform:scale(1)}}

    /* ── No search match ── */
    #no-search-row{display:none}

    /* ── Date cell ── */
    .date-text{font-size:13px;color:var(--g600);white-space:nowrap}
    .date-sub{font-size:11px;color:var(--g400);margin-top:2px}
    @media(max-width:480px){
  thead th:nth-child(4),
  tbody td:nth-child(4){ display:none; }
}
  </style>
</head>
<body>

<?php require_once __DIR__ . '/_layout.php'; ?>

    <!-- Alerts -->
    <?php if ($successMsg): ?>
    <div class="alert alert-success">
      <svg viewBox="0 0 24 24"><polyline points="20,6 9,17 4,12"/></svg>
      <span><?php echo $successMsg; ?></span>
    </div>
    <?php endif; ?>
    <?php if ($errorMsg): ?>
    <div class="alert alert-error">
      <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <span><?php echo $errorMsg; ?></span>
    </div>
    <?php endif; ?>

    <div class="page-grid">

      <!-- ── Left: categories table ── -->
      <div>
        <div class="tbl-card">
          <div class="card-header">
            <div class="card-header-left">
              <div class="card-header-icon">
                <svg viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
              </div>
              <div>
                <div class="card-header-title">IT Department Categories</div>
                <div class="card-header-sub">Manage complaint categories for this department</div>
              </div>
              <span class="count-badge"><?php echo count($categories); ?></span>
            </div>
            <!-- Search -->
            <div class="search-wrap">
              <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
              <input type="text" id="cat-search" class="search-input" placeholder="Search categories…" autocomplete="off"/>
            </div>
          </div>

          <div class="tbl-wrap">
            <table id="cat-table">
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
                  <div class="empty">
                    <svg viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                    <p>No categories yet. Add your first one →</p>
                  </div>
                </td></tr>
                <?php else: ?>
                <?php foreach ($categories as $i => $cat): ?>
                <tr data-name="<?php echo strtolower(htmlspecialchars($cat['category_name'])); ?>">
                  <td style="color:var(--g400);font-size:13px;font-family:monospace"><?php echo $i + 1; ?></td>
                  <td>
                    <div class="cat-name"><?php echo htmlspecialchars(preg_replace('/^IT Dept\s*\/\s*/i', '', $cat['category_name'])); ?></div>
                  </td>
                  <td>
                    <?php $u = (int)$cat['usage_count']; ?>
                    <span class="usage-pill <?php echo $u > 0 ? 'usage-some' : 'usage-zero'; ?>">
                      <?php echo $u; ?> complaint<?php echo $u !== 1 ? 's' : ''; ?>
                    </span>
                  </td>
                  <td>
                    <div class="date-text"><?php echo date('d M Y', strtotime($cat['created_at'])); ?></div>
                    <div class="date-sub"><?php echo date('H:i', strtotime($cat['created_at'])); ?></div>
                  </td>
                  <td>
                    <div class="action-btns">
                      <!-- Edit button -->
                      <button
                        type="button"
                        class="btn-icon"
                        title="Edit"
                        onclick="requestEdit(<?php echo $cat['category_id']; ?>, <?php echo htmlspecialchars(json_encode($cat['category_name'])); ?>)"
                      >
                        <svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                      </button>
                      <!-- Delete button -->
                      <button
                        type="button"
                        class="btn-icon delete"
                        title="Delete"
                        onclick="requestDelete(<?php echo $cat['category_id']; ?>, <?php echo htmlspecialchars(json_encode($cat['category_name'])); ?>, <?php echo (int)$cat['usage_count']; ?>)"
                      >
                        <svg viewBox="0 0 24 24"><polyline points="3,6 5,6 21,6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                      </button>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
                <!-- No search match row -->
                <tr id="no-search-row"><td colspan="5">
                  <div class="empty">
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

      <!-- ── Right: Add / Edit form ── -->
      <div>
        <div class="card" id="formCard">
          <div class="card-header">
            <div class="card-header-left">
              <div class="card-header-icon" id="formIcon">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
              </div>
              <div>
                <div class="card-header-title" id="formCardTitle">Add Category</div>
                <div class="card-header-sub" id="formCardSub">Create a new complaint category</div>
              </div>
            </div>
          </div>

          <div class="card-body">

            <!-- Edit mode indicator -->
            <div class="edit-mode-bar" id="editModeBar">
              <svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              <span>Editing: <strong id="editingName">—</strong></span>
            </div>

            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>" id="catForm">
              <input type="hidden" name="action" id="formAction" value="add"/>
              <input type="hidden" name="category_id" id="formCategoryId" value=""/>

              <label class="form-label" for="category_name">
                Category Name <span style="color:#E53935">*</span>
              </label>
              <div style="display:flex;align-items:stretch;gap:0">
                <input
                  type="text"
                  id="category_name"
                  name="category_name"
                  class="form-input"
                  placeholder="e.g. Hardware &amp; Software"
                  maxlength="140"
                  required
                  autocomplete="off"
                  style="border-radius:9px"
                />
              </div>

              <button type="submit" class="btn-primary" id="submitBtn">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                <span id="submitBtnLabel">Add Category</span>
              </button>

              <button type="button" class="btn-secondary" id="cancelEditBtn" style="display:none" onclick="cancelEdit()">
                <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                Cancel Edit
              </button>
            </form>

          </div>
        </div>
      </div>

    </div><!-- /.page-grid -->

  </div><!-- /.content -->
</main>

<!-- ── Delete confirm modal ── -->
<div class="modal-backdrop" id="deleteModal">
  <div class="modal">
    <div class="modal-icon danger">
      <svg viewBox="0 0 24 24"><polyline points="3,6 5,6 21,6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
    </div>
    <h3>Delete Category?</h3>
    <p id="deleteModalText">Are you sure you want to delete this category? This action cannot be undone.</p>
    <div class="modal-actions">
      <button type="button" class="btn-cancel-modal" onclick="closeDeleteModal()">Cancel</button>
      <form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>" id="deleteForm" style="display:inline">        <input type="hidden" name="action" value="delete"/>
        <input type="hidden" name="category_id" id="deleteModalId" value=""/>
        <button type="submit" class="btn-delete-modal">Yes, Delete</button>
      </form>
    </div>
  </div>
</div>

<!-- ── ⚠️ Unsaved Changes modal ── -->
<div class="modal-backdrop" id="unsavedModal">
  <div class="modal">
    <div class="modal-icon warning">
      <svg viewBox="0 0 24 24">
        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
        <line x1="12" y1="9" x2="12" y2="13"/>
        <line x1="12" y1="17" x2="12.01" y2="17"/>
      </svg>
    </div>
    <h3>You Have Unsaved Changes</h3>
    <p>You are currently editing <strong id="unsavedEditingName" style="color:var(--g900)"></strong>.<br>
    Please <strong>Save Changes</strong> or <strong>Cancel Edit</strong> before continuing.</p>
    <div class="modal-actions">
      <button type="button" class="btn-stay-modal" onclick="closeUnsavedModal(); scrollToForm();">
        <svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        Back to Edit
      </button>
      <button type="button" class="btn-leave-modal" onclick="closeUnsavedModal(); forceCancelEdit(); runPending();">
        Discard &amp; Continue
      </button>
    </div>
  </div>
</div>

<script>
// ─── State ────────────────────────────────────────────────────────────────────
var isEditMode = false;   // true while an edit is in progress
var pendingFn  = null;    // callback to run after "Discard & Continue"

// ─── Search ───────────────────────────────────────────────────────────────────
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

// ─── Guard helper: shows unsaved-changes popup if in edit mode ────────────────
function guardAction(callback) {
  if (!isEditMode) {
    callback();
    return;
  }
  pendingFn = callback;
  document.getElementById('unsavedEditingName').textContent =
    document.getElementById('editingName').textContent;
  document.getElementById('unsavedModal').classList.add('show');
}

// ─── Show custom unsaved modal (used for refresh / nav interception) ──────────
function showUnsavedModal(afterDiscardFn) {
  pendingFn = afterDiscardFn || null;
  document.getElementById('unsavedEditingName').textContent =
    document.getElementById('editingName').textContent;
  document.getElementById('unsavedModal').classList.add('show');
}

// ─── Intercept sidebar / nav links while editing ──────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('nav a, .sidebar a, aside a, [id*=sidebar] a, .nav a').forEach(function (link) {
    link.addEventListener('click', function (e) {
      if (!isEditMode) return;
      e.preventDefault();
      var href = this.href;
      guardAction(function () { window.location.href = href; });
    });
  });
});

// ─── Intercept keyboard refresh (F5, Ctrl+R, Cmd+R) ─────────────────────────
// Shows custom modal instead of browser's native "Reload site?" dialog.
document.addEventListener('keydown', function (e) {
  if (!isEditMode) return;
  var isRefresh = (e.key === 'F5') ||
                  ((e.ctrlKey || e.metaKey) && (e.key === 'r' || e.key === 'R'));
  if (isRefresh) {
    e.preventDefault();
    showUnsavedModal(function () {
      // After "Discard & Continue" — do the reload
      isEditMode = false;
      window.location.reload();
    });
  }
});

// ─── NOTE: The native browser "Reload site?" dialog (beforeunload) is intentionally
//     NOT used here. Keyboard refreshes are handled above. The browser toolbar
//     reload button cannot be intercepted without triggering the native dialog,
//     so we leave it unblocked to avoid the unwanted native popup.
// ─────────────────────────────────────────────────────────────────────────────

// ─── Row buttons (wrapped with guard) ────────────────────────────────────────
function requestEdit(id, fullName) {
  guardAction(function () { startEdit(id, fullName); });
}

function requestDelete(id, name, usageCount) {
  guardAction(function () { confirmDelete(id, name, usageCount); });
}

// ─── startEdit ────────────────────────────────────────────────────────────────
function startEdit(id, fullName) {
  var suffix = fullName.replace(/^(IT\s+Dept\s*\/\s*)+/i, '').trim();

  document.getElementById('formAction').value        = 'edit';
  document.getElementById('formCategoryId').value    = id;
  document.getElementById('category_name').value     = suffix;
  document.getElementById('editingName').textContent = fullName;
  document.getElementById('editModeBar').classList.add('show');
  document.getElementById('cancelEditBtn').style.display = 'flex';
  document.getElementById('formCard').classList.add('editing-active');

  document.getElementById('category_name').style.borderRadius = '9px';

  document.getElementById('submitBtn').innerHTML =
    '<svg viewBox="0 0 24 24" width="16" height="16" style="fill:none;stroke:white;stroke-width:2"><polyline points="20,6 9,17 4,12"/></svg> Save Changes';
  document.getElementById('formCardTitle').textContent = 'Edit Category';
  document.getElementById('formCardSub').textContent   = 'Update the category name below';

  isEditMode = true; // 🔒 lock navigation
  document.getElementById('category_name').focus();
  scrollToForm();
}

// ─── cancelEdit (user-initiated) ─────────────────────────────────────────────
function cancelEdit() {
  forceCancelEdit();
}

// ─── forceCancelEdit (used by discard flow too) ───────────────────────────────
function forceCancelEdit() {
  isEditMode = false; // 🔓 unlock navigation

  document.getElementById('formAction').value       = 'add';
  document.getElementById('formCategoryId').value   = '';
  document.getElementById('category_name').value    = '';
  document.getElementById('editModeBar').classList.remove('show');
  document.getElementById('cancelEditBtn').style.display = 'none';
  document.getElementById('formCard').classList.remove('editing-active');

  document.getElementById('category_name').style.borderRadius = '9px';

  document.getElementById('formCardTitle').textContent = 'Add Category';
  document.getElementById('formCardSub').textContent   = 'Create a new complaint category';
  document.getElementById('submitBtn').innerHTML =
    '<svg viewBox="0 0 24 24" width="16" height="16" style="fill:none;stroke:white;stroke-width:2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg> Add Category';
}

// Unlock when form is actually submitted (Save Changes click)
document.getElementById('catForm').addEventListener('submit', function () {
  isEditMode = false;
});

// ─── Delete modal ─────────────────────────────────────────────────────────────
function confirmDelete(id, name, usageCount) {
  if (usageCount > 0) {
    alert('Cannot delete "' + name + '" — it is used by ' + usageCount + ' complaint(s).');
    return;
  }
  document.getElementById('deleteModalId').value = id;
  document.getElementById('deleteModalText').textContent =
    'Are you sure you want to delete "' + name + '"? This action cannot be undone.';
  document.getElementById('deleteModal').classList.add('show');
}

function closeDeleteModal() {
  document.getElementById('deleteModal').classList.remove('show');
}

document.getElementById('deleteModal').addEventListener('click', function (e) {
  if (e.target === this) closeDeleteModal();
});

// ─── Unsaved modal ────────────────────────────────────────────────────────────
function closeUnsavedModal() {
  document.getElementById('unsavedModal').classList.remove('show');
}

function runPending() {
  if (typeof pendingFn === 'function') {
    var fn = pendingFn;
    pendingFn = null;
    fn();
  }
}

// Clicking the backdrop closes unsaved modal (stays on page)
document.getElementById('unsavedModal').addEventListener('click', function (e) {
  if (e.target === this) closeUnsavedModal();
});

// ─── Helpers ──────────────────────────────────────────────────────────────────
function scrollToForm() {
  document.getElementById('catForm').scrollIntoView({ behavior: 'smooth', block: 'center' });
}
</script>
</body>
</html>