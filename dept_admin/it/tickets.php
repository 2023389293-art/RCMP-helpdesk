<?php
// dept_admin/it/tickets.php
require '_layout.php';

$status   = $_GET['status']   ?? '';
$priority = $_GET['priority'] ?? '';
$category = $_GET['category'] ?? '';
$search   = trim($_GET['q']   ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));

// ── PER-PAGE: accept 10 / 25 / 50, default 10 ──
$allowedPerPage = [10, 25, 50, 100];
$perPage = (int)($_GET['per_page'] ?? 10);
if (!in_array($perPage, $allowedPerPage)) $perPage = 10;

$where  = ["c.dept_id = 4"];
$params = [];
$types  = '';

if ($status)   { $where[] = "c.status = ?";           $params[] = $status;   $types .= 's'; }
if ($priority) { $where[] = "c.priority = ?";         $params[] = $priority; $types .= 's'; }
if ($category) { $where[] = "c.category_id = ?";      $params[] = $category; $types .= 'i'; }
if ($search)   { $where[] = "(c.ticket_id LIKE ? OR c.title LIKE ?)";
                 $like = "%$search%"; $params[] = $like; $params[] = $like; $types .= 'ss'; }

$whereSQL = implode(' AND ', $where);

$countStmt = $conn->prepare("SELECT COUNT(*) AS n FROM complaints c WHERE $whereSQL");
if ($types) $countStmt->bind_param($types, ...$params);
$countStmt->execute();
$total   = $countStmt->get_result()->fetch_assoc()['n'];
$pages   = max(1, ceil($total / $perPage));
$page    = min($page, $pages); // clamp page to valid range
$offset  = ($page - 1) * $perPage;

$sql = "
    SELECT c.ticket_id, c.title, c.status, c.priority, c.created_at,
           cat.category_name,
           COALESCE(s.full_name, st.full_name, 'Unknown') AS submitter_name,
           c.submitter_type,
           c.my_department,
           c.description,
           ast.full_name  AS assigned_staff_name
    FROM complaints c
    LEFT JOIN categories cat ON cat.category_id = c.category_id
    LEFT JOIN students s  ON c.submitter_type = 'student' AND c.submitter_id = s.student_id
    LEFT JOIN staff   st  ON c.submitter_type = 'staff'   AND c.submitter_id = st.staff_id
    LEFT JOIN staff   ast ON c.assigned_to = ast.staff_id
    WHERE $whereSQL
    ORDER BY FIELD(c.status,'open','in_progress','closed'), c.priority = 'high' DESC, c.created_at DESC
    LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($sql);
$allParams = array_merge($params, [$perPage, $offset]);
$allTypes  = $types . 'ii';
$stmt->bind_param($allTypes, ...$allParams);
$stmt->execute();
$tickets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$cats = $conn->query("SELECT category_id, category_name FROM categories WHERE dept_id = 4 ORDER BY category_name")->fetch_all(MYSQLI_ASSOC);

/* Build query string helper — preserves all active filters, drops page */
function qstr(array $extra = []): string {
    $p = array_merge($_GET, $extra);
    unset($p['page']);
    $qs = http_build_query(array_filter($p, fn($v) => $v !== ''));
    return $qs ? '?' . $qs : '?';
}

/* Build query string for pagination links — keeps page */
function pgstr(array $extra = []): string {
    $p = array_merge($_GET, $extra);
    $qs = http_build_query(array_filter($p, fn($v) => $v !== ''));
    return $qs ? '?' . $qs : '?';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>IT Admin — All Tickets | UniKL Help Desk</title>
  <?php include '_head_assets.php'; ?>
  <style>
    /* ── TABLE CELL FIXES ── */
    .data-table tbody td {
      vertical-align: middle;
    }
    .td-title {
      max-width: 200px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      font-weight: 500;
    }
    .td-cat {
      color: var(--gray-500);
      font-size: 12px;
      white-space: nowrap;
      max-width: 150px;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    /* ── TICKET ID: allow wrap so full ID is always visible ── */
.ticket-id {
  white-space: normal;
  word-break: break-word;
  overflow-wrap: break-word;
  display: inline-block;
  max-width: 80px;
  font-size: 12px;
}

    .td-date {
      color: var(--gray-500);
      font-size: 12px;
      white-space: nowrap;
    }

    /* ── ASSIGNED TO cell ── */
    .td-assigned {
      white-space: nowrap;
    }

    /* ── UNASSIGNED STATE ── */
    .unassigned-text {
      color: var(--gray-500);
      font-style: italic;
      font-size: 11px;
    }

    /* ── VIEW BUTTON ── */
    .btn-view {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 5px 12px;
      background: var(--blue-light);
      color: var(--blue);
      border: 1px solid rgba(107,90,158,.25);
      border-radius: 6px;
      font-size: 12px;
      font-weight: 600;
      cursor: pointer;
      font-family: inherit;
      transition: background .15s, color .15s;
      white-space: nowrap;
      text-decoration: none;
    }
    .btn-view:hover {
      background: var(--blue);
      color: white;
    }
    .btn-view svg {
      width: 13px;
      height: 13px;
      fill: none;
      stroke: currentColor;
      stroke-width: 2;
      flex-shrink: 0;
    }

    /* ══════════════════════════════════════════
       SUBMITTED BY (DEPARTMENT + DATE/TIME) COLUMN
    ══════════════════════════════════════════ */
    .td-submitted-by {
      max-width: 200px;
    }
    .dept-name {
      font-size: 13px;
      font-weight: 600;
      color: var(--gray-700, #374151);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 195px;
      display: block;
    }
    .submitted-datetime {
      display: block;
      font-size: 11px;
      color: var(--gray-500, #6b7280);
      margin-top: 3px;
      white-space: nowrap;
    }

    /* ══════════════════════════════════════════
       ASSIGNED TO — avatar + name (mirrors staff view)
    ══════════════════════════════════════════ */
    .assigned-cell {
      display: flex;
      align-items: center;
      gap: 7px;
      overflow: hidden;
    }
    .staff-avatar-sm {
      width: 28px;
      height: 28px;
      border-radius: 50%;
      background: linear-gradient(135deg, #001f5c, #1a56db);
      color: white;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 10px;
      font-weight: 700;
      flex-shrink: 0;
    }
    .assigned-name {
      font-size: 13px;
      color: var(--gray-700);
      font-weight: 500;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    /* ══════════════════════════════════════════
       FLAG-STYLE PRIORITY  (matches reports tab)
    ══════════════════════════════════════════ */
    .priority-pill {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      font-size: .68rem;
      font-weight: 800;
      letter-spacing: .07em;
      text-transform: uppercase;
      background: none;
      border: none;
      padding: 0;
    }
    .priority-flag-icon {
      width: 13px;
      height: 13px;
      flex-shrink: 0;
      vertical-align: middle;
      position: relative;
      top: -1px;
    }
    .priority-pill.pp-high   { color: #DC2626; }
    .priority-pill.pp-high .priority-flag-icon { fill: #DC2626; stroke: #DC2626; }
    .priority-pill.pp-medium { color: #D97706; }
    .priority-pill.pp-medium .priority-flag-icon { fill: #EAB308; stroke: #EAB308; }
    .priority-pill.pp-low    { color: #2563EB; }
    .priority-pill.pp-low .priority-flag-icon { fill: #3B82F6; stroke: #3B82F6; }

    /* ══════════════════════════════════════════
       PAGINATION — full controls
    ══════════════════════════════════════════ */

    /* TOP BAR: summary + per-page (above table) */
    .pagination-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 10px;
      margin-bottom: 10px;
      padding: 0 2px;
    }

    /* BOTTOM BAR: page numbers only (below table) */
    .pagination-bottom {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      flex-wrap: wrap;
      gap: 6px;
      margin-top: 16px;
      padding: 0 2px;
    }

    /* "Showing X–Y of Z results" */
    .pg-summary {
      font-size: 13px;
      color: var(--gray-500, #6b7280);
    }
    .pg-summary strong {
      color: var(--gray-700, #374151);
    }

    /* Right side of top bar: per-page dropdown */
    .pg-controls {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }

    /* Per-page dropdown */
    .pg-per-page {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 13px;
      color: var(--gray-600, #4b5563);
    }
    .pg-per-page select {
      padding: 5px 28px 5px 10px;
      border: 1px solid var(--gray-300, #d1d5db);
      border-radius: 7px;
      font-size: 13px;
      font-family: inherit;
      background: white;
      color: var(--gray-700, #374151);
      cursor: pointer;
      appearance: none;
      -webkit-appearance: none;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 8px center;
      font-weight: 500;
    }
    .pg-per-page select:focus {
      outline: none;
      border-color: var(--blue, #1a56db);
      box-shadow: 0 0 0 3px rgba(26,86,219,.12);
    }

    /* Page number buttons */
    .pg-pages {
      display: flex;
      align-items: center;
      gap: 4px;
    }
    .pg-btn, .pg-num {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 34px;
      height: 34px;
      padding: 0 8px;
      border-radius: 7px;
      font-size: 13px;
      font-weight: 600;
      text-decoration: none;
      transition: background .15s, color .15s, border-color .15s;
      border: 1px solid var(--gray-300, #d1d5db);
      background: white;
      color: var(--gray-700, #374151);
      cursor: pointer;
      line-height: 1;
    }
    .pg-btn:hover, .pg-num:hover {
      background: var(--gray-100, #f3f4f6);
      border-color: var(--gray-400, #9ca3af);
    }
    .pg-num.active {
      background: var(--blue, #1a56db);
      border-color: var(--blue, #1a56db);
      color: white;
    }
    .pg-btn.disabled {
      opacity: .38;
      pointer-events: none;
    }
    .pg-ellipsis {
      font-size: 13px;
      color: var(--gray-400, #9ca3af);
      padding: 0 4px;
      user-select: none;
    }

    /* ── STATUS BADGE COLOR OVERRIDES ── */
    .badge.status-open {
  background: #FFF7ED;
  color: #C2410C;
  border: 1px solid rgba(194,65,12,.2);
  white-space: nowrap;   /* ← add this */
}
.badge.status-in_progress {
  background: #EFF6FF;
  color: #1D4ED8;
  border: 1px solid rgba(29,78,216,.2);
  white-space: nowrap;   /* ← add this */
}
    #ticketModal .modal {
      max-width: 560px;
    }
    .detail-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
      margin-bottom: 16px;
    }
    .detail-item label {
      display: block;
      font-size: 10px;
      font-weight: 600;
      letter-spacing: .06em;
      text-transform: uppercase;
      color: var(--gray-500);
      margin-bottom: 4px;
    }
    .detail-item .detail-val {
      font-size: 13px;
      font-weight: 500;
      color: var(--gray-900);
    }
    .detail-desc-box {
      background: var(--gray-100);
      border-radius: 8px;
      padding: 12px 14px;
      font-size: 13px;
      color: var(--gray-700);
      line-height: 1.6;
      min-height: 60px;
      white-space: pre-wrap;
      word-break: break-word;
    }
    .detail-desc-label {
      font-size: 10px;
      font-weight: 600;
      letter-spacing: .06em;
      text-transform: uppercase;
      color: var(--gray-500);
      margin-bottom: 6px;
    }
    .modal-ticket-id {
      font-family: monospace;
      font-size: 12px;
      background: var(--gray-100);
      padding: 3px 8px;
      border-radius: 4px;
      color: var(--gray-700);
      font-weight: 600;
    }

    /* ── MOBILE RESPONSIVE ── */
    @media (max-width: 900px) {
      .main-content { padding: 20px 12px; }
      .page-title   { font-size: 22px; }
      .filter-bar   { flex-direction: column; align-items: stretch; }
      .filter-bar select,
      .filter-bar .search-wrap,
      .filter-bar .btn-primary-sm,
      .filter-bar .btn-ghost-sm { width: 100%; }

      .data-table thead th:nth-child(3),
      .data-table thead th:nth-child(4),
      .data-table thead th:nth-child(7),
      .data-table tbody td:nth-child(3),
      .data-table tbody td:nth-child(4),
      .data-table tbody td:nth-child(7) { display: none; }

      .card.no-pad {
  overflow-x: auto;
}
      .data-table  { min-width: 400px; }
      .ticket-id   { max-width: 70px; font-size: 10px; }
      .td-title    { max-width: 100px; }

      .pagination-top  { flex-direction: column; align-items: flex-start; }
      .pg-controls     { width: 100%; justify-content: flex-end; }
      .pagination-bottom { gap: 4px; }
    }
  </style>
</head>
<body>
<?php include '_sidebar.php'; ?>

<main class="main-content">
  <div class="page-header">
    <div>
      <div class="page-eyebrow">IT Department</div>
      <h1 class="page-title">All Tickets <span class="title-count"><?= $total ?></span></h1>
    </div>
  </div>

  <form method="GET" class="filter-bar">
    <div class="search-wrap">
      <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="text" name="q" placeholder="Search ticket ID or title…" value="<?= htmlspecialchars($search) ?>"/>
    </div>

    <select name="status">
      <option value="">All Status</option>
      <?php foreach (['open'=>'Open','in_progress'=>'In Progress','closed'=>'Closed'] as $v=>$l): ?>
      <option value="<?= $v ?>" <?= $status===$v?'selected':'' ?>><?= $l ?></option>
      <?php endforeach; ?>
    </select>

    <select name="priority">
      <option value="">All Priority</option>
      <?php foreach (['high'=>'High','medium'=>'Medium','low'=>'Low'] as $v=>$l): ?>
      <option value="<?= $v ?>" <?= $priority===$v?'selected':'' ?>><?= $l ?></option>
      <?php endforeach; ?>
    </select>

    <select name="category">
      <option value="">All Categories</option>
      <?php foreach ($cats as $c): ?>
      <option value="<?= $c['category_id'] ?>" <?= $category==$c['category_id']?'selected':'' ?>>
        <?= htmlspecialchars(explode(' / ',$c['category_name'])[1] ?? $c['category_name']) ?>
      </option>
      <?php endforeach; ?>
    </select>

    <!-- keep per_page value when re-filtering -->
    <input type="hidden" name="per_page" value="<?= $perPage ?>"/>

    <button type="submit" class="btn-primary-sm">Filter</button>
    <?php if ($status||$priority||$category||$search): ?>
    <a href="tickets.php" class="btn-ghost-sm">Clear</a>
    <?php endif; ?>
  </form>

  <!-- ══════════════════════════════════════════
       TOP BAR: summary + per-page selector
  ══════════════════════════════════════════ -->
  <?php if ($total > 0):
    $from = $offset + 1;
    $to   = min($offset + $perPage, $total);
  ?>
  <div class="pagination-top">
    <div class="pg-summary">
      Showing <strong><?= $from ?>–<?= $to ?></strong> of <strong><?= $total ?></strong> results
    </div>
    <div class="pg-controls">
      <div class="pg-per-page">
        <span>Show</span>
        <select onchange="changePerPage(this.value)">
          <?php foreach ($allowedPerPage as $pp): ?>
          <option value="<?= $pp ?>" <?= $perPage===$pp?'selected':'' ?>><?= $pp ?> per page</option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="card no-pad">
    <table class="data-table full">
      <thead>
        <tr>
          <th>Ticket ID</th>
          <th>Title</th>
          <th>Submitted By</th>
          <th>Category</th>
          <th>Priority</th>
          <th>Status</th>
          <th>Assigned To</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($tickets)): ?>
        <tr><td colspan="8" class="empty-row">No tickets match your filters.</td></tr>
        <?php else: foreach ($tickets as $t): ?>
        <tr class="ticket-row">

          <!-- Ticket ID -->
          <td>
            <span class="ticket-id"><?= htmlspecialchars($t['ticket_id']) ?></span>
          </td>

          <!-- Title -->
          <td class="td-title"><?= htmlspecialchars($t['title']) ?></td>

          <!-- Submitted By: department name + date/time below -->
          <td class="td-submitted-by">
            <span class="dept-name" title="<?= htmlspecialchars($t['my_department'] ?? '') ?>">
              <?= htmlspecialchars($t['my_department'] ?: '—') ?>
            </span>
            <span class="submitted-datetime">
              <?= date('d M Y, g:ia', strtotime($t['created_at'])) ?>
            </span>
          </td>

          <!-- Category -->
          <td class="td-cat">
            <?= htmlspecialchars(explode(' / ',$t['category_name'])[1] ?? $t['category_name']) ?>
          </td>

          <!-- Priority — flag style -->
          <td>
            <?php $p = $t['priority']; $pl = ucfirst($p); ?>
            <span class="priority-pill pp-<?= $p ?>">
              <svg class="priority-flag-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M4 3h13l-3 5 3 5H4V3z" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                <line x1="4" y1="3" x2="4" y2="21" stroke-width="2" stroke-linecap="round"/>
              </svg>
              <?= $pl ?>
            </span>
          </td>

          <!-- Status -->
          <td>
            <?php
              $s  = $t['status'];
              $sl = ['open'=>'Open','in_progress'=>'In Progress','closed'=>'Closed'];
              echo "<span class='badge status-{$s}'>" . ($sl[$s] ?? $s) . "</span>";
            ?>
          </td>

          <!-- Assigned To -->
          <td class="td-assigned">
            <?php if (!empty($t['assigned_staff_name'])): ?>
              <div class="assigned-cell">
                <div class="staff-avatar-sm"><?= staffInitials($t['assigned_staff_name']) ?></div>
                <span class="assigned-name" title="<?= htmlspecialchars($t['assigned_staff_name']) ?>">
                  <?= htmlspecialchars($t['assigned_staff_name']) ?>
                </span>
              </div>
            <?php else: ?>
              <span class="unassigned-text">Unassigned</span>
            <?php endif; ?>
          </td>

          <!-- View Button -->
          <td>
            <a href="ticket_detail.php?id=<?= urlencode($t['ticket_id']) ?>" class="btn-view">
              <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              View
            </a>
          </td>

        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- ══════════════════════════════════════════
       BOTTOM BAR: page numbers only
  ══════════════════════════════════════════ -->
  <?php if ($total > 0 && $pages > 1): ?>
  <div class="pagination-bottom">

    <!-- Prev -->
    <?php if ($page > 1): ?>
      <a href="<?= pgstr(['page'=>$page-1]) ?>" class="pg-btn" title="Previous page">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
      </a>
    <?php else: ?>
      <span class="pg-btn disabled">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
      </span>
    <?php endif; ?>

    <!-- Page numbers with ellipsis -->
    <?php
      $window = 2;
      $shown  = [];
      for ($i = 1; $i <= $pages; $i++) {
        if ($i === 1 || $i === $pages || abs($i - $page) <= $window) {
          $shown[] = $i;
        }
      }
      $shown = array_unique($shown);
      sort($shown);
      $prev = null;
      foreach ($shown as $pg):
        if ($prev !== null && $pg - $prev > 1): ?>
          <span class="pg-ellipsis">…</span>
        <?php endif;
        if ($pg === $page): ?>
          <span class="pg-num active"><?= $pg ?></span>
        <?php else: ?>
          <a href="<?= pgstr(['page'=>$pg]) ?>" class="pg-num"><?= $pg ?></a>
        <?php endif;
        $prev = $pg;
      endforeach;
    ?>

    <!-- Next -->
    <?php if ($page < $pages): ?>
      <a href="<?= pgstr(['page'=>$page+1]) ?>" class="pg-btn" title="Next page">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
      </a>
    <?php else: ?>
      <span class="pg-btn disabled">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
      </span>
    <?php endif; ?>

  </div>
  <?php endif; ?>

</main>

<!-- ── TICKET DETAIL MODAL ── -->
<div class="modal-overlay" id="ticketModal">
  <div class="modal">
    <div class="modal-header">
      <div style="display:flex; align-items:center; gap:10px;">
        <h3>Ticket Details</h3>
        <span class="modal-ticket-id" id="modal-ticket-id"></span>
      </div>
      <button class="modal-close" onclick="closeTicketModal()">✕</button>
    </div>
    <div class="modal-form">

      <div class="detail-grid">
        <div class="detail-item">
          <label>Title</label>
          <div class="detail-val" id="modal-title"></div>
        </div>
        <div class="detail-item">
          <label>Submitted By</label>
          <div class="detail-val" id="modal-submitter"></div>
        </div>
        <div class="detail-item">
          <label>Category</label>
          <div class="detail-val" id="modal-category"></div>
        </div>
        <div class="detail-item">
          <label>Submitted</label>
          <div class="detail-val" id="modal-created"></div>
        </div>
        <div class="detail-item">
          <label>Priority</label>
          <div class="detail-val" id="modal-priority"></div>
        </div>
        <div class="detail-item">
          <label>Status</label>
          <div class="detail-val" id="modal-status"></div>
        </div>
        <div class="detail-item" style="grid-column: span 2;">
          <label>Assigned To</label>
          <div class="detail-val" id="modal-assigned"></div>
        </div>
      </div>

      <div class="detail-desc-label">Description</div>
      <div class="detail-desc-box" id="modal-desc"></div>

    </div>
  </div>
</div>

<?php include '_foot_scripts.php'; ?>

<?php
function staffInitials(string $name): string {
    $parts = explode(' ', trim($name));
    $ini   = strtoupper(substr($parts[0], 0, 1));
    if (count($parts) > 1) $ini .= strtoupper(substr($parts[count($parts) - 1], 0, 1));
    return $ini;
}
?>

<script>
/* ── PER-PAGE CHANGE — reload with page reset ── */
function changePerPage(value) {
  const url = new URL(window.location.href);
  url.searchParams.set('per_page', value);
  url.searchParams.delete('page'); // reset to page 1
  window.location.href = url.toString();
}

/* ── TICKET MODAL ── */
const FLAG_SVG = `<svg class="priority-flag-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" style="width:13px;height:13px;flex-shrink:0;vertical-align:middle;position:relative;top:-1px;">
  <path d="M4 3h13l-3 5 3 5H4V3z" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
  <line x1="4" y1="3" x2="4" y2="21" stroke-width="2" stroke-linecap="round"/>
</svg>`;

const PRIORITY_STYLES = {
  high:   { color: '#DC2626', fillStroke: '#DC2626', label: 'High'   },
  medium: { color: '#D97706', fillStroke: '#EAB308', label: 'Medium' },
  low:    { color: '#2563EB', fillStroke: '#3B82F6', label: 'Low'    },
};

function openTicketModal(t) {
  document.getElementById('modal-ticket-id').textContent = t.ticket_id;
  document.getElementById('modal-title').textContent = t.title || '—';

  const dept = t.my_department || '—';
  document.getElementById('modal-submitter').innerHTML =
    `<div style="font-size:13px;font-weight:600;color:#374151;">${escHtml(dept)}</div>` +
    `<div style="font-size:11px;color:#6b7280;margin-top:3px;">${escHtml(t.created_at || '')}</div>`;

  const catParts = (t.category_name || '').split(' / ');
  document.getElementById('modal-category').textContent = catParts[1] || catParts[0] || '—';

  const p  = t.priority || '';
  const ps = PRIORITY_STYLES[p] || { color: '#64748b', fillStroke: '#64748b', label: capitalize(p) };
  const flagHtml = FLAG_SVG
    .replace('</svg>', '')
    .replace('<path', `<path fill="${ps.fillStroke}" stroke="${ps.fillStroke}"`)
    .replace('<line', `<line stroke="${ps.fillStroke}"`) + '</svg>';
  document.getElementById('modal-priority').innerHTML =
    `<span style="display:inline-flex;align-items:center;gap:5px;font-size:.68rem;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:${ps.color};">
       ${flagHtml}${ps.label}
     </span>`;

  const s = t.status || '';
  const statusLabels = { open:'Open', in_progress:'In Progress', closed:'Closed' };
  document.getElementById('modal-status').innerHTML =
    `<span class="badge status-${s}">${statusLabels[s] || s}</span>`;

  document.getElementById('modal-created').textContent = t.created_at || '—';

  const assignedEl = document.getElementById('modal-assigned');
  if (t.assigned_staff_name) {
    assignedEl.innerHTML =
      `<div style="display:flex;align-items:center;gap:8px;">
         <div style="width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,#001f5c,#1a56db);color:white;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;flex-shrink:0;">
           ${escHtml(initials(t.assigned_staff_name))}
         </div>
         <span style="font-size:13px;font-weight:500;color:#374151;">${escHtml(t.assigned_staff_name)}</span>
       </div>`;
  } else {
    assignedEl.innerHTML = `<span style="color:#9CA3AF;font-style:italic;font-size:13px;">Unassigned</span>`;
  }

  document.getElementById('modal-desc').textContent = t.description || 'No description provided.';
  document.getElementById('ticketModal').classList.add('open');
}

function closeTicketModal() {
  document.getElementById('ticketModal').classList.remove('open');
}

document.getElementById('ticketModal').addEventListener('click', function(e) {
  if (e.target === this) closeTicketModal();
});

document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeTicketModal();
});

function capitalize(str) {
  return str ? str.charAt(0).toUpperCase() + str.slice(1) : '';
}
function escHtml(str) {
  const d = document.createElement('div');
  d.textContent = str;
  return d.innerHTML;
}
function initials(name) {
  const parts = name.trim().split(' ').filter(Boolean);
  if (!parts.length) return '?';
  let ini = parts[0][0].toUpperCase();
  if (parts.length > 1) ini += parts[parts.length - 1][0].toUpperCase();
  return ini;
}
</script>
</body>
</html>