<?php
// dept/ccu/tickets.php 
require_once __DIR__ . '/../auth_guard.php';
if (isset($_GET['logout'])) { staffLogout(); }
require_once __DIR__ . '/../../db_connect.php';

// ── Filter / pagination params ────────────────────────────────────────────────
$filterStatus = $_GET['status'] ?? 'all';
$allowedFilter = ['all', 'open', 'in_progress', 'closed'];
if (!in_array($filterStatus, $allowedFilter)) $filterStatus = 'all';

$allowedPerPage = [10, 25, 50];
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
if (!in_array($perPage, $allowedPerPage)) $perPage = 10;

$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// ── Counts (open / closed) ────────────────────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT SUM(status='open') AS oc, SUM(status='in_progress') AS ipc, SUM(status='closed') AS cc
     FROM complaints WHERE dept_id = ?"
);
$stmt->bind_param("i", $deptId);
$stmt->execute();
$counts          = $stmt->get_result()->fetch_assoc();
$stmt->close();
$openCount       = (int)($counts['oc']  ?? 0);
$inProgressCount = (int)($counts['ipc'] ?? 0);
$closedCount     = (int)($counts['cc']  ?? 0);

// ── Total for current filter ──────────────────────────────────────────────────
if ($filterStatus === 'all') {
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM complaints WHERE dept_id = ?");
    $stmt->bind_param("i", $deptId);
} else {
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM complaints WHERE dept_id = ? AND status = ?");
    $stmt->bind_param("is", $deptId, $filterStatus);
}
$stmt->execute();
$totalTickets = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();

$totalPages = ($totalTickets > 0) ? (int)ceil($totalTickets / $perPage) : 1;
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

// ── Fetch paginated tickets ───────────────────────────────────────────────────
$tickets = [];
if ($filterStatus === 'all') {
    $stmt = $conn->prepare(
        "SELECT complaints.ticket_id, complaints.title, complaints.status,
        complaints.priority, complaints.my_department,
        complaints.created_at, complaints.updated_at,
        s.staff_id AS assigned_staff_id,
        s.full_name AS assigned_staff_name,
        cat.category_name
 FROM complaints
 LEFT JOIN staff s ON s.staff_id = complaints.assigned_to
 LEFT JOIN categories cat ON cat.category_id = complaints.category_id
 WHERE complaints.dept_id = ?
 ORDER BY complaints.created_at DESC LIMIT ? OFFSET ?"
    );
    $stmt->bind_param("iii", $deptId, $perPage, $offset);
} else {
    $stmt = $conn->prepare(
        "SELECT complaints.ticket_id, complaints.title, complaints.status,
        complaints.priority, complaints.my_department,
        complaints.created_at, complaints.updated_at,
        s.staff_id AS assigned_staff_id,
        s.full_name AS assigned_staff_name,
        cat.category_name
 FROM complaints
 LEFT JOIN staff s ON s.staff_id = complaints.assigned_to
 LEFT JOIN categories cat ON cat.category_id = complaints.category_id
 WHERE complaints.dept_id = ? AND complaints.status = ?
 ORDER BY complaints.created_at DESC LIMIT ? OFFSET ?"
    );
    $stmt->bind_param("isii", $deptId, $filterStatus, $perPage, $offset);
}
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $tickets[] = $row;
$stmt->close();

// ── Layout vars ───────────────────────────────────────────────────────────────
$activeNav = match($filterStatus) {
    'open'        => 'tickets-open',
    'in_progress' => 'tickets-inprogress',
    'closed'      => 'tickets-closed',
    default       => 'tickets',
};
$pageTitle    = 'All Tickets';
$pageSubtitle = 'Corporate Communication Unit';

function ticketUrl(array $overrides = []): string {
    $params = array_merge([
        'status'   => $_GET['status']   ?? 'all',
        'per_page' => $_GET['per_page'] ?? 10,
        'page'     => $_GET['page']     ?? 1,
    ], $overrides);
    return 'tickets.php?' . http_build_query($params);
}
function staffInitials(string $name): string {
    $parts = explode(' ', trim($name));
    $ini = strtoupper(substr($parts[0],0,1));
    if (count($parts) > 1) $ini .= strtoupper(substr($parts[count($parts)-1],0,1));
    return $ini;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>All Tickets | UniKL Help Desk – CCU</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet"/>
  <style>
    .filter-bar{display:flex;align-items:center;gap:8px;margin-bottom:20px;flex-wrap:wrap}
    .filter-tab{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:8px;font-size:14px;font-weight:500;text-decoration:none;border:1.5px solid var(--g200);color:var(--g500);background:white;transition:border-color .15s,color .15s,background .15s}
    .filter-tab:hover{border-color:var(--g300);color:var(--g700)}
    .filter-tab.active{background:var(--accent);border-color:var(--accent);color:white}
    .filter-tab .ft-count{font-size:12px;font-weight:700;padding:1px 6px;border-radius:20px;background:rgba(0,0,0,.08)}
    .filter-tab.active .ft-count{background:rgba(255,255,255,.25)}

    .toolbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:10px}
    .toolbar-left{display:flex;align-items:center;gap:8px}
    .toolbar-right{display:flex;align-items:center;gap:8px}
    .sec-title{font-size:16px;font-weight:600;color:var(--g900)}
    .result-count{font-size:13px;color:var(--g500)}

    .search-wrap{position:relative;display:flex;align-items:center}
    .search-wrap svg{position:absolute;left:10px;width:14px;height:14px;stroke:var(--g400);fill:none;stroke-width:2;pointer-events:none}
    .search-input{padding:8px 12px 8px 32px;font-size:13px;border-radius:8px;border:1.5px solid var(--g200);background:white;color:var(--g900);width:220px;outline:none;transition:border-color .15s}
    .search-input:focus{border-color:var(--accent)}
    .search-input::placeholder{color:var(--g400)}

    .perpage-select{padding:8px 10px;font-size:13px;border-radius:8px;border:1.5px solid var(--g200);background:white;color:var(--g700);cursor:pointer;outline:none}
    .perpage-select:focus{border-color:var(--accent)}

    .tbl-card{background:white;border-radius:12px;border:1px solid var(--g200);overflow:hidden}
    .tbl-wrap{overflow-x:auto}
    table{width:100%;border-collapse:collapse;font-size:14px}
    thead th{background:var(--g100);padding:11px 16px;text-align:left;font-size:12px;font-weight:600;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid var(--g200)}
    tbody tr{border-bottom:1px solid var(--g200);transition:background .12s}
    tbody tr:last-child{border-bottom:none}
    tbody tr:hover{background:var(--off)}
    tbody td{padding:13px 16px;color:var(--g700);vertical-align:middle}

    .tid-link{font-weight:600;color:var(--accent);font-size:13px;text-decoration:none;font-family:monospace;letter-spacing:.03em;transition:color .15s}
    .tid-link:hover{color:#5B21B6;text-decoration:underline}
    .dept-text{font-size:13px;color:var(--g700);max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:inline-block}
    .bdg{display:inline-block;font-size:12px;font-weight:600;padding:3px 10px;border-radius:20px;text-transform:capitalize}
    .bdg-open{background:#EDE9FE;color:#7C3AED}
    .bdg-in_progress{background:#DBEAFE;color:#1D4ED8;}
    .date-text{font-size:13px;color:var(--g600);white-space:nowrap}
    .date-sub{font-size:11px;color:var(--g400);margin-top:2px}

    .empty{text-align:center;padding:60px 20px;color:var(--g500)}
    .empty svg{width:40px;height:40px;margin:0 auto 12px;display:block;stroke:var(--g300);fill:none;stroke-width:1.5}
    .empty p{font-size:13px}

    .pagination-bar{display:flex;align-items:center;justify-content:space-between;margin-top:16px;flex-wrap:wrap;gap:10px}
    .pg-info{font-size:13px;color:var(--g500)}
    .pg-btns{display:flex;align-items:center;gap:4px}
    .pg-btn{display:inline-flex;align-items:center;justify-content:center;padding:6px 12px;font-size:13px;font-weight:500;border-radius:8px;border:1.5px solid var(--g200);background:white;color:var(--g700);text-decoration:none;transition:border-color .15s,background .15s,color .15s;min-width:36px}
    .pg-btn:hover{border-color:var(--g300);background:var(--g100)}
    .pg-btn.active{background:var(--accent);border-color:var(--accent);color:white}
    .pg-btn.disabled{opacity:.4;pointer-events:none}

    #no-search-row{display:none}
  </style>
</head>
<body>

<?php require_once __DIR__ . '/_layout.php'; ?>

    <!-- Filter tabs -->
    <div class="filter-bar">
      <a href="<?php echo ticketUrl(['status'=>'all','page'=>1]); ?>" class="filter-tab <?php echo $filterStatus==='all'?'active':''; ?>">
        All <span class="ft-count"><?php echo $openCount + $inProgressCount + $closedCount; ?></span>
      </a>
      <a href="<?php echo ticketUrl(['status'=>'open','page'=>1]); ?>" class="filter-tab <?php echo $filterStatus==='open'?'active':''; ?>">
        Open <span class="ft-count"><?php echo $openCount; ?></span>
      </a>
      <a href="<?php echo ticketUrl(['status'=>'in_progress','page'=>1]); ?>" class="filter-tab tab-inprogress <?php echo $filterStatus==='in_progress'?'active':''; ?>">
  <span class="tab-dot tab-dot-inprogress"></span>In Progress <span class="ft-count"><?php echo $inProgressCount; ?></span>
</a>
      <a href="<?php echo ticketUrl(['status'=>'closed','page'=>1]); ?>" class="filter-tab <?php echo $filterStatus==='closed'?'active':''; ?>">
        Closed <span class="ft-count"><?php echo $closedCount; ?></span>
      </a>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
      <div class="toolbar-left">
        <span class="sec-title">
          <?php echo match($filterStatus) { 'open'=>'Open Tickets', 'in_progress'=>'In Progress Tickets', 'closed'=>'Closed Tickets', default=>'All Tickets' }; ?>
        </span>
        <span class="result-count" id="result-label">&mdash; <?php echo $totalTickets; ?> ticket<?php echo $totalTickets!==1?'s':''; ?></span>
      </div>
      <div class="toolbar-right">
        <div class="search-wrap">
          <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
          <input
            type="text"
            id="ticket-search"
            class="search-input"
            placeholder="Search tickets…"
            autocomplete="off"
            value="<?php echo htmlspecialchars($_GET['q'] ?? ''); ?>"
          />
        </div>
        <form method="get" id="perpage-form">
          <input type="hidden" name="status" value="<?php echo htmlspecialchars($filterStatus); ?>">
          <input type="hidden" name="page" value="1">
          <select name="per_page" class="perpage-select" onchange="this.form.submit()">
            <?php foreach ([10, 25, 50] as $n): ?>
            <option value="<?php echo $n; ?>" <?php echo $perPage===$n?'selected':''; ?>><?php echo $n; ?> per page</option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>
    </div>

    <!-- Table -->
    <div class="tbl-card">
      <div class="tbl-wrap">
        <table id="ticket-table">
          <thead>
            <tr>
  <th>Ticket ID</th>
  <th>Title</th>
  <th>From Department</th>
  <th>Status</th>
  <th>Priority</th>
  <th>Category</th>
  <th>Assigned To</th>
  <th>Action</th>
</tr>
          </thead>
          <tbody id="ticket-tbody">
            <?php if (empty($tickets)): ?>
            <tr><td colspan="8">
              <div class="empty">
                <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14,2 14,8 20,8"/></svg>
                <p>No <?php echo $filterStatus!=='all'?$filterStatus.' ':''; ?>tickets found.</p>
              </div>
            </td></tr>
            <?php else: ?>
            <?php foreach ($tickets as $t):
              $s = strtolower($t['status']);
            ?>
            <tr
  data-id="<?php echo strtolower(htmlspecialchars($t['ticket_id'])); ?>"
  data-title="<?php echo strtolower(htmlspecialchars($t['title'])); ?>"
  data-dept="<?php echo strtolower(htmlspecialchars($t['my_department'] ?? '')); ?>"
  data-cat="<?php echo strtolower(htmlspecialchars($t['category_name'] ?? '')); ?>"
>
              <td>
  <a class="tid-link" href="ticket_detail.php?id=<?php echo urlencode($t['ticket_id']); ?>">
    <?php echo htmlspecialchars($t['ticket_id']); ?>
  </a>
</td>
<td><?php echo htmlspecialchars($t['title']); ?></td>
<td>
  <div style="display:flex;flex-direction:column;gap:2px;">
    <span style="font-size:14px;color:var(--g700);font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($t['my_department'] ?? '—'); ?>">
      <?php echo htmlspecialchars($t['my_department'] ?? '—'); ?>
    </span>
    <span style="font-size:12px;color:var(--g400);white-space:nowrap;">
      <?php echo date('d M Y, H:i', strtotime($t['created_at'])); ?>
    </span>
  </div>
</td>
<td><span class="bdg bdg-<?php echo $s; ?>"><?php echo $s === 'in_progress' ? 'In Progress' : ucfirst($s); ?></span></td>
<td>
  <?php
    $pri = strtolower($t['priority'] ?? 'medium');
    $flagFill = match($pri) { 'high'=>'#DC2626', 'medium'=>'#EAB308', 'low'=>'#3B82F6', default=>'#64748b' };
  ?>
  <span style="display:inline-flex;align-items:center;gap:4px;font-size:.75rem;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:<?php echo $flagFill; ?>">
    <svg style="width:13px;height:13px;flex-shrink:0;" viewBox="0 0 24 24" fill="<?php echo $flagFill; ?>" stroke="<?php echo $flagFill; ?>">
      <path d="M4 3h13l-3 5 3 5H4V3z" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
      <line x1="4" y1="3" x2="4" y2="21" stroke-width="2" stroke-linecap="round"/>
    </svg>
    <?php echo ucfirst($pri); ?>
  </span>
</td>
<td>
  <?php if (!empty($t['category_name'])): ?>
    <span style="font-size:14px;font-weight:500;color:var(--g700);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:inline-block;max-width:100%;" title="<?php echo htmlspecialchars($t['category_name']); ?>">
      <?php
        $catDisplay = $t['category_name'];
        if (strpos($catDisplay, ' / ') !== false)
          $catDisplay = trim(substr($catDisplay, strpos($catDisplay, ' / ') + 3));
        echo htmlspecialchars($catDisplay);
      ?>
    </span>
  <?php else: ?>
    <span style="font-size:12px;color:#9CA3AF;font-style:italic;">—</span>
  <?php endif; ?>
</td>
<td>
  <?php if (!empty($t['assigned_staff_name'])): ?>
    <div style="display:flex;align-items:center;gap:7px;overflow:hidden;">
      <div style="width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,#001f5c,#1a56db);color:white;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;flex-shrink:0;">
        <?php echo staffInitials($t['assigned_staff_name']); ?>
      </div>
      <span style="font-size:13px;color:var(--g700);font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
        <?php echo htmlspecialchars($t['assigned_staff_name']); ?>
      </span>
    </div>
  <?php else: ?>
    <span style="font-size:13px;color:#9CA3AF;font-style:italic;">Unassigned</span>
  <?php endif; ?>
</td>
<td>
  <a style="display:inline-flex;align-items:center;gap:4px;padding:5px 12px;font-size:13px;font-weight:600;font-family:'DM Sans',sans-serif;text-decoration:none;border-radius:8px;border:1.5px solid var(--accent);color:var(--accent);background:transparent;white-space:nowrap;transition:background .15s,color .15s;" 
     href="ticket_detail.php?id=<?php echo urlencode($t['ticket_id']); ?>"
     onmouseover="this.style.background='var(--accent)';this.style.color='white'"
     onmouseout="this.style.background='transparent';this.style.color='var(--accent)'">
    View →
  </a>
</td>
            </tr>
            <?php endforeach; ?>
            <tr id="no-search-row"><td colspan="8">
              <div class="empty">
                <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                <p>No tickets match your search.</p>
              </div>
            </td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination-bar">
      <span class="pg-info">
        Showing <?php echo $offset + 1; ?>–<?php echo min($offset + $perPage, $totalTickets); ?> of <?php echo $totalTickets; ?> tickets
      </span>
      <div class="pg-btns">
        <?php if ($page <= 1): ?>
          <span class="pg-btn disabled">&larr; Prev</span>
        <?php else: ?>
          <a class="pg-btn" href="<?php echo ticketUrl(['page' => $page - 1]); ?>">&larr; Prev</a>
        <?php endif; ?>

        <?php
          $window = 2;
          $start  = max(1, $page - $window);
          $end    = min($totalPages, $page + $window);
          if ($start > 1): ?>
            <a class="pg-btn" href="<?php echo ticketUrl(['page'=>1]); ?>">1</a>
            <?php if ($start > 2): ?><span class="pg-btn disabled" style="border:none">…</span><?php endif; ?>
          <?php endif;
          for ($p = $start; $p <= $end; $p++): ?>
            <a class="pg-btn <?php echo $p===$page?'active':''; ?>" href="<?php echo ticketUrl(['page'=>$p]); ?>"><?php echo $p; ?></a>
          <?php endfor;
          if ($end < $totalPages): ?>
            <?php if ($end < $totalPages - 1): ?><span class="pg-btn disabled" style="border:none">…</span><?php endif; ?>
            <a class="pg-btn" href="<?php echo ticketUrl(['page'=>$totalPages]); ?>"><?php echo $totalPages; ?></a>
          <?php endif; ?>

        <?php if ($page >= $totalPages): ?>
          <span class="pg-btn disabled">Next &rarr;</span>
        <?php else: ?>
          <a class="pg-btn" href="<?php echo ticketUrl(['page' => $page + 1]); ?>">Next &rarr;</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /.content -->
</main>

<script>
(function () {
  const input = document.getElementById('ticket-search');
  const tbody = document.getElementById('ticket-tbody');
  const noRow = document.getElementById('no-search-row');
  const label = document.getElementById('result-label');

  if (!input || !tbody) return;

  input.addEventListener('input', function () {
    const q = this.value.trim().toLowerCase();
    let visible = 0;

    tbody.querySelectorAll('tr[data-id]').forEach(function (row) {
      const match = !q
  || row.dataset.id.includes(q)
  || row.dataset.title.includes(q)
  || row.dataset.dept.includes(q)
  || row.dataset.cat.includes(q);

      row.style.display = match ? '' : 'none';
      if (match) visible++;
    });

    noRow.style.display = (q && visible === 0) ? '' : 'none';
    label.textContent = q
      ? '\u2014 ' + visible + ' result' + (visible !== 1 ? 's' : '')
      : '\u2014 <?php echo $totalTickets; ?> ticket<?php echo $totalTickets !== 1 ? "s" : ""; ?>';
  });
})();
</script>
</body>
</html>