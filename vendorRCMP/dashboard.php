<?php
// vendorRCMP/dashboard.php — Vendor Dashboard (placeholder)
session_start();

if (empty($_SESSION['vendor_id'])) {
    header("Location: ../vendor_login.php");
    exit;
}

$vendor_name    = htmlspecialchars($_SESSION['vendor_name']    ?? 'Vendor');
$vendor_company = htmlspecialchars($_SESSION['vendor_company'] ?? '');

// ── Fetch tickets assigned to this vendor ──
require_once __DIR__ . '/../db_connect.php';

$vendor_id = (int)$_SESSION['vendor_id'];

$stmt = $conn->prepare("
    SELECT c.ticket_id, c.title, c.status, c.priority, c.created_at,
           c.my_department, c.description,
           cat.category_name
    FROM complaints c
    LEFT JOIN categories cat ON cat.category_id = c.category_id
    INNER JOIN vendor_departments vd
        ON vd.vendor_id = ? AND vd.dept_id = c.dept_id AND vd.status = 'active'
    WHERE c.assigned_vendor_id = ?
    ORDER BY FIELD(c.status,'open','in_progress','closed'), c.created_at DESC
");
$stmt->bind_param('ii', $vendor_id, $vendor_id);
$stmt->execute();
$tickets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalTickets    = count($tickets);
$inProgressCount = count(array_filter($tickets, fn($t) => $t['status'] === 'in_progress'));
$closedCount     = count(array_filter($tickets, fn($t) => $t['status'] === 'closed'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Vendor Dashboard | UniKL RCMP RUSH</title>
  <link href="https://fonts.googleapis.com/css2?family=Source+Sans+3:wght@300;400;600;700&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --navy: #1E3A5F; --navy-dark: #142845; --gold: #B8860B; --gold-light: #D4A017;
      --olive: #5C6B35; --page-bg: #e8edf5; --surface: #fff;
      --border: #D8D3C8; --text: #1A2332; --text-muted: #7A8899;
      --blue: #1a56db; --blue-light: #EFF6FF;
      --g100: #f3f4f6; --g200: #e5e7eb; --g300: #d1d5db;
      --g400: #9ca3af; --g500: #6b7280; --g700: #374151; --g900: #111827;
    }
    body { font-family: 'Source Sans 3', sans-serif; background: var(--page-bg); color: var(--text); min-height: 100vh; }

    /* ── NAV ── */
    nav {
      background: var(--surface); border-bottom: 3px solid var(--navy);
      box-shadow: 0 2px 16px rgba(0,0,0,0.08);
      padding: 0 48px; display: flex; align-items: center; justify-content: space-between; height: 80px;
    }
    .nav-brand { display: flex; align-items: center; gap: 14px; text-decoration: none; }
    .nav-brand img { width: 60px; height: 60px; object-fit: contain; }
    .nav-brand-text { font-size: 17px; font-weight: 700; color: var(--navy-dark); }
    .nav-brand-sub  { font-size: 11px; font-weight: 600; letter-spacing: 0.1em; text-transform: uppercase; color: var(--olive); }
    .nav-right { display: flex; align-items: center; gap: 12px; }
    .vendor-chip {
      display: flex; align-items: center; gap: 8px;
      background: var(--page-bg); border: 1px solid var(--border);
      border-radius: 100px; padding: 6px 14px;
      font-size: 13px; font-weight: 600; color: var(--navy-dark);
    }
    .btn-logout {
      padding: 8px 18px; border-radius: 6px;
      border: 1.5px solid var(--border); background: transparent;
      font-size: 13px; font-weight: 600; color: var(--text-muted);
      text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
      transition: all 0.2s; cursor: pointer;
    }
    .btn-logout:hover { border-color: var(--navy); color: var(--navy); background: #E8EEF6; }

    /* ── MAIN LAYOUT ── */
    .main { max-width: 1300px; margin: 40px auto; padding: 0 40px; }
    .page-heading { margin-bottom: 24px; }
    .page-heading h1 { font-size: 22px; font-weight: 700; color: var(--navy-dark); }
    .page-heading p  { font-size: 14px; color: var(--text-muted); margin-top: 4px; }

    /* ── KPI CARDS ── */
    .kpi-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 24px; }
    @media (max-width: 700px) { .kpi-grid { grid-template-columns: repeat(2,1fr); } }
    .kpi-card {
      background: var(--surface); border: 2px solid var(--g200); border-radius: 12px;
      padding: 16px 18px; display: flex; align-items: center; gap: 14px;
    }
    .kpi-icon { width: 42px; height: 42px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .kpi-icon svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 2; }
    .kpi-all        { background: var(--blue-light); color: var(--blue); }
    .kpi-open       { background: #FFF7ED; color: #C2410C; }
    .kpi-progress   { background: #EFF6FF; color: #1D4ED8; }
    .kpi-closed     { background: #F0FDF4; color: #15803D; }
    .kpi-val   { font-size: 28px; font-weight: 800; color: var(--g900); line-height: 1.1; }
    .kpi-label { font-size: 14px; font-weight: 600; color: var(--g500); letter-spacing: .02em; text-transform: uppercase; }

    /* ── TABLE CARD ── */
    .tbl-card { background: var(--surface); border: 1px solid var(--g200); border-radius: 12px; overflow: hidden; }
    .tbl-wrap { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; font-size: 15px; }
    thead th {
      background: var(--g100); padding: 10px 14px; text-align: left;
      font-size: 14px; font-weight: 700; color: var(--g500);
      text-transform: uppercase; letter-spacing: .06em;
      border-bottom: 1px solid var(--g200); white-space: nowrap;
    }
    tbody tr { border-bottom: 1px solid var(--g200); transition: background .12s; }
    tbody tr:last-child { border-bottom: none; }
    tbody tr:hover { background: #F8FAFF; }
    tbody td { padding: 11px 14px; color: var(--g700); vertical-align: middle; }

    /* ── STATUS BADGE ── */
    .bdg { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 600; padding: 3px 9px; border-radius: 20px; white-space: nowrap; }
    .bdg::before { content:''; width:6px; height:6px; border-radius:50%; background:currentColor; display:inline-block; flex-shrink:0; }
    .bdg-open        { background:#FEF3C7; color:#D97706; }
    .bdg-in_progress { background:#DBEAFE; color:#1D4ED8; }
    .bdg-closed      { background:#D1FAE5; color:#059669; }

    /* ── PRIORITY FLAG ── */
    .priority-pill { display:inline-flex; align-items:center; gap:4px; font-size:.72rem; font-weight:800; letter-spacing:.07em; text-transform:uppercase; }
    .pflag { width:13px; height:13px; flex-shrink:0; }
    .pp-high   { color:#DC2626; } .pp-high   .pflag { fill:#DC2626; stroke:#DC2626; }
    .pp-medium { color:#D97706; } .pp-medium .pflag { fill:#EAB308; stroke:#EAB308; }
    .pp-low    { color:#2563EB; } .pp-low    .pflag { fill:#3B82F6; stroke:#3B82F6; }

    /* ── DEPT CELL ── */
    .dept-cell .dept-name     { font-size:16px; font-weight:600; color:var(--g700); display:block; }
    .dept-cell .dept-datetime { font-size:14px; color:var(--g400); display:block; margin-top:2px; }

    /* ── TICKET ID ── */
    .tid { font-size:15px; font-weight:700; font-family:monospace; background:#EFF6FF; color:var(--blue); padding:3px 7px; border-radius:5px; white-space:nowrap; }

    /* ── EMPTY STATE ── */
    .empty-state { text-align:center; padding:60px 20px; color:var(--g500); }
    .empty-state svg { width:44px; height:44px; margin:0 auto 14px; display:block; stroke:var(--g300); fill:none; stroke-width:1.5; }
    .empty-state h3 { font-size:17px; font-weight:700; color:var(--g700); margin-bottom:6px; }
    .empty-state p  { font-size:13px; color:var(--g400); line-height:1.6; }

    /* ── SEARCH BAR ── */
    .toolbar { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:14px; flex-wrap:wrap; }
    .search-wrap { position:relative; flex:1; min-width:220px; max-width:380px; }
    .search-wrap svg { position:absolute; left:11px; top:50%; transform:translateY(-50%); width:15px; height:15px; stroke:var(--g400); fill:none; stroke-width:2; pointer-events:none; }
    .search-input { width:100%; padding:8px 12px 8px 34px; border:1.5px solid var(--g300); border-radius:8px; font-size:14px; font-family:inherit; color:var(--g700); background:var(--surface); outline:none; transition:border-color .15s; }
    .search-input:focus { border-color:var(--blue); }
    .search-input::placeholder { color:var(--g400); }

    /* ── KPI as filter buttons ── */
    .kpi-card { cursor:pointer; transition:box-shadow .15s, border-color .15s; }
    .kpi-card:hover { box-shadow:0 0 0 1px var(--blue); border-color:var(--blue); }
    .kpi-card.active-filter { border-color:var(--blue); box-shadow:none; }
    .filter-label { font-size:11px; font-weight:700; letter-spacing:.06em; color:var(--blue); text-transform:uppercase; margin-top:2px; display:none; }
    .kpi-card.active-filter .filter-label { display:block; }

    /* ── PAGINATION ── */
    .pagination-wrap { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-top:14px; }
    .pagination-info { font-size:13px; color:var(--g500); }
    .pagination-btns { display:flex; gap:4px; align-items:center; flex-wrap:wrap; }
    .pg-btn {
      min-width:32px; height:32px; padding:0 10px; border-radius:6px;
      border:1.5px solid var(--g300); background:var(--surface);
      font-size:13px; font-weight:600; color:var(--g700);
      cursor:pointer; transition:all .15s; font-family:inherit;
    }
    .pg-btn:hover:not(:disabled) { border-color:var(--blue); color:var(--blue); background:var(--blue-light); }
    .pg-btn.active { border-color:var(--blue); background:var(--blue); color:#fff; }
    .pg-btn:disabled { opacity:.4; cursor:not-allowed; }
    .per-page-wrap { display:flex; align-items:center; gap:8px; font-size:13px; color:var(--g500); }
    .per-page-select {
      padding:6px 10px; border:1.5px solid var(--g300); border-radius:6px;
      font-size:13px; font-family:inherit; color:var(--g700);
      background:var(--surface); cursor:pointer; outline:none;
    }
    .per-page-select:focus { border-color:var(--blue); }
  </style>
</head>
<body>
<?php require_once __DIR__ . '/includes/header.php'; ?>

<div class="main">

  <div class="page-heading">
    <h1>Work Orders — <?php echo $vendor_company; ?></h1>
    <p>Tickets assigned to your company by staff.</p>
  </div>

  <!-- ── KPI CARDS ── -->
  <div class="kpi-grid">
    <div class="kpi-card active-filter" data-filter="all" onclick="setFilter('all')">
      <div class="kpi-icon kpi-all">
        <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      </div>
      <div><div class="kpi-val"><?php echo $totalTickets; ?></div><div class="kpi-label">Total</div><div class="filter-label">Showing all</div></div>
    </div>
    
    <div class="kpi-card" data-filter="in_progress" onclick="setFilter('in_progress')">
      <div class="kpi-icon kpi-progress">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      </div>
      <div><div class="kpi-val"><?php echo $inProgressCount; ?></div><div class="kpi-label">In Progress</div><div class="filter-label">Filtered</div></div>
    </div>
    <div class="kpi-card" data-filter="closed" onclick="setFilter('closed')">
      <div class="kpi-icon kpi-closed">
        <svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      </div>
      <div><div class="kpi-val"><?php echo $closedCount; ?></div><div class="kpi-label">Closed</div><div class="filter-label">Filtered</div></div>
    </div>
  </div>

  <!-- ── TICKETS TABLE ── -->
  <div class="toolbar">
    <div class="search-wrap">
      <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="text" style="display:none" aria-hidden="true"/>
      <input class="search-input" type="search" id="ticketSearch" placeholder="Search by ticket ID, department, title…" oninput="applyFilters()" autocomplete="off" name="ticket_search_<?php echo time(); ?>"/>
    </div>
    <div class="per-page-wrap">
      Show
      <select class="per-page-select" id="perPageSelect" onchange="applyFilters()">
        <option value="10">10</option>
        <option value="25">25</option>
        <option value="50">50</option>
        <option value="100">100</option>
      </select>
      per page
    </div>
  </div>
  <div class="tbl-card">
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Ticket ID</th>
            <th>From Department</th>
            <th>Category</th>
            <th>Title</th>
            <th>Priority</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($tickets)): ?>
          <tr data-status=""><td colspan="7">
            <div class="empty-state">
              <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
              <h3>No tickets assigned yet</h3>
              <p>Your assigned work orders will appear here once staff assign tickets to your company.</p>
            </div>
          </td></tr>
          <?php else: $loop = 1; foreach ($tickets as $t):
            $s   = $t['status'];
            $pri = $t['priority'] ?? 'medium';
            $statusLabel = ['open'=>'Open','in_progress'=>'In Progress','closed'=>'Closed'][$s] ?? ucfirst($s);
            $catDisplay  = $t['category_name'] ?? '—';
            if (strpos($catDisplay, ' / ') !== false)
                $catDisplay = trim(substr($catDisplay, strpos($catDisplay, ' / ') + 3));
          ?>
          <tr data-status="<?php echo $s; ?>" data-search="<?php echo strtolower(htmlspecialchars($t['ticket_id'].' '.$t['my_department'].' '.$t['title'].' '.($t['category_name']??''))); ?>">
            <td style="font-size:13px; color:var(--g400); font-weight:600; width:36px;"><?php echo $loop++; ?></td>
            <td><span class="tid"><?php echo htmlspecialchars($t['ticket_id']); ?></span></td>
            <td>
              <div class="dept-cell">
                <span class="dept-name"><?php echo htmlspecialchars($t['my_department'] ?? '—'); ?></span>
                <span class="dept-datetime"><?php echo date('d M Y, H:i', strtotime($t['created_at'])); ?></span>
              </div>
            </td>
            <td style="font-size:15px; color:var(--g500);"><?php echo htmlspecialchars($catDisplay); ?></td>
            <td style="font-size:15px; max-width:220px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"
                title="<?php echo htmlspecialchars($t['title']); ?>">
              <?php echo htmlspecialchars($t['title']); ?>
            </td>
            <td>
              <span class="priority-pill pp-<?php echo $pri; ?>">
                <svg class="pflag" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                  <path d="M4 3h13l-3 5 3 5H4V3z" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                  <line x1="4" y1="3" x2="4" y2="21" stroke-width="2" stroke-linecap="round"/>
                </svg>
                <?php echo ucfirst($pri); ?>
              </span>
            </td>
            <td><span class="bdg bdg-<?php echo $s; ?>"><?php echo $statusLabel; ?></span></td>
            <td>
              <a href="ticket_detail.php?id=<?php echo urlencode($t['ticket_id']); ?>" 
                 style="display:inline-flex;align-items:center;gap:5px;padding:6px 14px;font-size:13px;font-weight:600;font-family:inherit;text-decoration:none;border-radius:8px;border:1.5px solid var(--blue);color:var(--blue);background:transparent;white-space:nowrap;transition:background .15s,color .15s;">
                View
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
              </a>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── PAGINATION ── -->
  <div class="pagination-wrap" id="paginationWrap">
    <span class="pagination-info" id="paginationInfo"></span>
    <div class="pagination-btns" id="paginationBtns"></div>
  </div>

  
<script>

  let currentFilter = 'all';
  let currentPage = 1;

  function setFilter(f) {
    currentFilter = f;
    currentPage = 1;
    document.querySelectorAll('.kpi-card').forEach(card => {
      card.classList.toggle('active-filter', card.dataset.filter === f);
    });
    applyFilters();
  }

  function applyFilters() {
    const q = document.getElementById('ticketSearch').value.toLowerCase().trim();
    const perPage = parseInt(document.getElementById('perPageSelect').value);
    const allRows = Array.from(document.querySelectorAll('tbody tr[data-status]'));

    // Filter rows
    const visibleRows = allRows.filter(row => {
      const statusMatch = currentFilter === 'all' || row.dataset.status === currentFilter;
      const searchMatch = !q || (row.dataset.search || '').includes(q);
      return statusMatch && searchMatch;
    });

    // Hide all first
    allRows.forEach(row => row.style.display = 'none');

    // Paginate
    const totalPages = Math.max(1, Math.ceil(visibleRows.length / perPage));
    if (currentPage > totalPages) currentPage = totalPages;
    const start = (currentPage - 1) * perPage;
    const end = start + perPage;
    visibleRows.slice(start, end).forEach(row => row.style.display = '');

    renderPagination(visibleRows.length, totalPages, perPage);
  }

  function renderPagination(total, totalPages, perPage) {
    const wrap = document.getElementById('paginationWrap');
    if (!wrap) return;

    const start = total === 0 ? 0 : (currentPage - 1) * perPage + 1;
    const end = Math.min(currentPage * perPage, total);

    // Info
    document.getElementById('paginationInfo').textContent =
      total === 0 ? 'No results' : `Showing ${start}–${end} of ${total} tickets`;

    // Buttons
    const btns = document.getElementById('paginationBtns');
    btns.innerHTML = '';

    const addBtn = (label, page, disabled, active) => {
      const b = document.createElement('button');
      b.className = 'pg-btn' + (active ? ' active' : '');
      b.textContent = label;
      b.disabled = disabled;
      if (!disabled) b.onclick = () => { currentPage = page; applyFilters(); };
      btns.appendChild(b);
    };

    addBtn('‹', currentPage - 1, currentPage === 1, false);

    // Smart page range
    let pages = [];
    if (totalPages <= 7) {
      for (let i = 1; i <= totalPages; i++) pages.push(i);
    } else {
      pages = [1];
      if (currentPage > 3) pages.push('…');
      for (let i = Math.max(2, currentPage - 1); i <= Math.min(totalPages - 1, currentPage + 1); i++) pages.push(i);
      if (currentPage < totalPages - 2) pages.push('…');
      pages.push(totalPages);
    }

    pages.forEach(p => {
      if (p === '…') {
        const span = document.createElement('span');
        span.textContent = '…';
        span.style.cssText = 'padding:0 4px;color:var(--g400);font-size:13px;';
        btns.appendChild(span);
      } else {
        addBtn(p, p, false, p === currentPage);
      }
    });

    addBtn('›', currentPage + 1, currentPage === totalPages, false);
  }

  // Init — clear any browser autofill on search
  window.addEventListener('load', () => {
    const s = document.getElementById('ticketSearch');
    s.value = '';
    applyFilters();
  });
</script>



</body>
</html>