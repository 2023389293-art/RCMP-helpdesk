<?php 
// uniKL/complaint/complaint/homepage.php
session_start();

$allowedRoles = ['student', 'lecturer', 'dept_handler', 'admin', 'super_admin', 'report_viewer', 'staff'];
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], $allowedRoles)) {
    header('Location: ../login.php');
    exit;
}

require '../db_connect.php';

$userName  = htmlspecialchars($_SESSION['user_name']  ?? 'User');
$userEmail = htmlspecialchars($_SESSION['user_email'] ?? '');
$userRole  = $_SESSION['user_role'];
$userId = (int)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? 0);

$submitterType = ($userRole === 'student') ? 'student' : 'staff';

$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'open')        AS pending,
        SUM(status = 'in_progress') AS in_progress,
        SUM(status = 'resolved')    AS resolved
    FROM complaints
    WHERE submitter_id = ? AND submitter_type = ?
");
$stmt->bind_param("is", $userId, $submitterType);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Requisition stats (add to totals)
$stmtReq = $conn->prepare("
    SELECT COUNT(*) AS req_total
    FROM requisitions
    WHERE submitter_id = ? AND submitter_type = ?
");
$stmtReq->bind_param("is", $userId, $submitterType);
$stmtReq->execute();
$reqStats = $stmtReq->get_result()->fetch_assoc();
$stmtReq->close();
$stats['total'] = ($stats['total'] ?? 0) + ($reqStats['req_total'] ?? 0);

$stats['pending']     = $stats['pending']     ?? 0;
$stats['in_progress'] = $stats['in_progress'] ?? 0;
$stats['resolved']    = $stats['resolved']    ?? 0;
$stats['total']       = $stats['total']       ?? 0;

// Recent complaints
$stmt2 = $conn->prepare("
    SELECT
        c.ticket_id  AS ref_id,
        c.title,
        c.status,
        c.created_at,
        cat.category_name AS category_name,
        'complaint'  AS row_type
    FROM complaints c
    JOIN categories cat ON c.category_id = cat.category_id
    WHERE c.submitter_id = ? AND c.submitter_type = ?
    ORDER BY c.created_at DESC
    LIMIT 5
");
$stmt2->bind_param("is", $userId, $submitterType);
$stmt2->execute();
$recentComplaints = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt2->close();

// Recent requisitions
$stmt3 = $conn->prepare("
    SELECT
        r.ref_number AS ref_id,
        r.item_name  AS title,
        r.status,
        r.created_at,
        r.category   AS category_name,
        'requisition' AS row_type
    FROM requisitions r
    WHERE r.submitter_id = ? AND r.submitter_type = ?
    ORDER BY r.created_at DESC
    LIMIT 5
");
$stmt3->bind_param("is", $userId, $submitterType);
$stmt3->execute();
$recentRequisitions = $stmt3->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt3->close();

// Merge and sort by created_at DESC, show latest 5
$recentActivity = array_merge($recentComplaints, $recentRequisitions);
usort($recentActivity, function($a, $b) { return strtotime($b['created_at']) - strtotime($a['created_at']); });
$recentActivity = array_slice($recentActivity, 0, 5);

$statusMeta = [
    // Complaint statuses
    'open'        => ['label' => 'Open',        'class' => 'pill-open'],
    'in_progress' => ['label' => 'In Progress',  'class' => 'pill-progress'],
    'resolved'    => ['label' => 'Resolved',     'class' => 'pill-resolved'],
    'closed'      => ['label' => 'Closed',       'class' => 'pill-closed'],
    // Requisition statuses — matched to complaint equivalents
    'pending'     => ['label' => 'Pending',      'class' => 'pill-open'],      // same as open
    'approved'    => ['label' => 'Approved',     'class' => 'pill-resolved'],  // same as resolved (green)
    'completed'   => ['label' => 'Completed',    'class' => 'pill-closed'],    // same as closed (grey)
    'rejected'    => ['label' => 'Rejected',     'class' => 'pill-rejected'],  // same red
];

$pageTitle    = 'Universiti Kuala Lumpur Royal College of Medicine Perak';
$pageSubtitle = date('l, d F Y');
$activeNav    = 'dashboard';

$extraHead = '
<style>

/* ════════════════════════════════
   SECTION HEADER — Inter, Bold
════════════════════════════════ */
.section-header h2 {
  font-family: \'Inter\', sans-serif !important;
  font-size: 16px !important;
  font-weight: 700 !important;
  letter-spacing: -0.01em !important;
  color: #0D1F3C !important;
  font-style: normal !important;
  margin: 0 !important;
}

/* ════════════════════════════════
   QUICK ACTIONS
════════════════════════════════ */
.quick-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 14px;
  margin-bottom: 32px;
}

.qa-card {
  display: inline-flex;
  align-items: center;
  gap: 14px;
  padding: 16px 22px;
  background: #fff;
  border: 0.5px solid #e8eaf2;
  border-radius: 12px;
  text-decoration: none;
  color: inherit;
  transition: box-shadow .18s, border-color .18s, transform .18s;
}
.qa-card:hover {
  box-shadow: 0 6px 20px rgba(59,107,255,.10);
  border-color: #c5d0ff;
  transform: translateY(-1px);
}
.qa-icon {
  width: 40px; height: 40px;
  border-radius: 10px;
  background: #eef1ff;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.qa-icon svg {
  width: 18px; height: 18px;
  stroke: #3b6bff; stroke-width: 2.5; fill: none;
}

.qa-card.qa-my-complaints .qa-icon { background: #e1f5ee; }
.qa-card.qa-my-complaints .qa-icon svg { stroke: #0f6e56; }
.qa-card.qa-my-complaints:hover {
  box-shadow: 0 6px 20px rgba(15,110,86,.10);
  border-color: #9fe1cb;
}

.qa-card.qa-new-complaint .qa-icon { background: #faeeda; }
.qa-card.qa-new-complaint .qa-icon svg { stroke: #854f0b; }
.qa-card.qa-new-complaint:hover {
  box-shadow: 0 6px 20px rgba(133,79,11,.10);
  border-color: #fac775;
}



.qt { font-weight: 600; font-size: 14px; color: #1e2235; }
.qs { font-size: 12px; color: #9399ad; margin-top: 2px; }

/* ════════════════════════════════
   SECTION HEADER WRAPPER
════════════════════════════════ */
.section-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 14px;
}
.section-header a {
  font-size: 13px;
  color: #185FA5;
  text-decoration: none;
  font-weight: 500;
  display: flex; align-items: center; gap: 4px;
  opacity: .85;
  transition: opacity .15s;
}
.section-header a:hover { opacity: 1; }
.section-header a svg {
  width: 12px; height: 12px;
  fill: none; stroke: currentColor; stroke-width: 2.5;
}

/* ════════════════════════════════
   RECENT COMPLAINTS TABLE
════════════════════════════════ */
.rc-card {
  background: #fff;
  border-radius: 16px;
  border: 0.5px solid #e8eaf2;
  overflow: hidden;
  margin-bottom: 28px;
  box-shadow: 0 1px 6px rgba(0,0,0,.04);
}

.rc-table {
  width: 100%;
  border-collapse: collapse;
  table-layout: fixed;
}

.rc-table col.col-id        { width: 22%; }
.rc-table col.col-complaint { width: 31%; }
.rc-table col.col-status    { width: 16%; }
.rc-table col.col-date      { width: 15%; }
.rc-table col.col-action    { width: 16%; }

/* ── TABLE HEADER BACKGROUND (changed) ── */
.rc-table thead tr {
  background: #0D1F3C;
  border-bottom: 0.5px solid #1a3260;
}
.rc-table thead th {
  background: #2d5986 !important;  /* ← force color on the th cells directly */
  padding: 12px 18px;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: .07em;
  text-transform: uppercase;
  color: #fafafa;
  text-align: left;
  white-space: nowrap;
}
.rc-table thead th.th-action { text-align: center; }

.rc-table tbody tr {
  border-top: 0.5px solid #f0f2f8;
  cursor: pointer;
  transition: background .13s ease;
}
.rc-table tbody tr:hover { background: #f5f8ff; }
.rc-table tbody tr:last-child { border-bottom: none; }

.rc-table td {
  padding: 15px 18px;
  vertical-align: middle;
}

.rc-tid {
  font-family: "SFMono-Regular", "SF Mono", Consolas, "Liberation Mono", monospace;
  font-size: 13px;
  color: #5a607a;
  background: #f2f4fa;
  border: 0.5px solid #dde1ef;
  border-radius: 6px;
  padding: 6px 12px;
  display: inline-block;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 100%;
  letter-spacing: .01em;
}

.rc-title {
  font-weight: 600;
  font-size: 13.5px;
  color: #1e2235;
  display: block;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  margin-bottom: 3px;
  letter-spacing: -.01em;
}
.rc-cat {
  font-size: 11.5px;
  color: #adb3c8;
  display: block;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.rc-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 11px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 600;
  white-space: nowrap;
  letter-spacing: .01em;
}
.rc-pill-dot {
  width: 6px; height: 6px;
  border-radius: 50%;
  flex-shrink: 0;
}

.pill-open     { background: #FEF3E2; color: #92520C; }
.pill-open     .rc-pill-dot { background: #F59E0B; }

.pill-progress { background: #EFF6FF; color: #1D4ED8; }
.pill-progress .rc-pill-dot { background: #3B82F6; }

.pill-resolved { background: #F0FDF4; color: #166534; }
.pill-resolved .rc-pill-dot { background: #22C55E; }

.pill-rejected { background: #FEF2F2; color: #991B1B; }
.pill-rejected .rc-pill-dot { background: #DC2626; }

.pill-closed { background: #dcdee1; color: #374151; }
.pill-closed .rc-pill-dot { background: #9a9ea4; }

/* ── Type badges ── */
.type-badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 9px;
  border-radius: 20px;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: .02em;
  white-space: nowrap;
}
.type-complaint   { background: #EEF3FB; color: #185FA5; border: 0.5px solid #C8DCEF; }
.type-requisition { background: #faeeda; color: #854f0b; border: 0.5px solid #f5c97a; }

.rc-date {
  font-size: 12px;
  color: #adb3c8;
  white-space: nowrap;
}

.rc-table td.td-action { text-align: center; }

.rc-view-btn {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 7px 14px;
  border-radius: 8px;
  background: #EEF3FB;
  color: #185FA5;
  font-size: 12px;
  font-weight: 600;
  text-decoration: none;
  white-space: nowrap;
  border: 0.5px solid #C8DCEF;
  transition: background .15s, color .15s, border-color .15s;
  font-family: inherit;
  cursor: pointer;
  letter-spacing: .01em;
}
.rc-view-btn:hover {
  background: #185FA5;
  color: #fff;
  border-color: #185FA5;
}
.rc-view-btn svg {
  width: 11px; height: 11px;
  fill: none; stroke: currentColor; stroke-width: 2.5;
  transition: transform .15s;
}
.rc-view-btn:hover svg { transform: translateX(2px); }

.rc-empty td {
  text-align: center;
  padding: 56px 18px;
  color: #adb3c8;
  font-size: 14px;
}
.rc-empty-icon {
  display: block;
  width: 40px; height: 40px;
  margin: 0 auto 12px;
  opacity: .35;
}

@media (max-width: 640px) {
  .rc-table, .rc-table tbody,
  .rc-table tr, .rc-table td { display: block; width: 100%; }
  .rc-table thead             { display: none; }
  .rc-table tbody tr {
    padding: 14px 16px;
    display: flex; flex-direction: column; gap: 7px;
    border-top: 0.5px solid #f0f2f8;
  }
  .rc-table td               { padding: 0; }
  .rc-table td.td-action     { text-align: left; }
  .rc-view-btn               { width: 100%; justify-content: center; margin-top: 4px; }
  .quick-actions { flex-direction: column; }
  .qa-card       { width: 100%; }
}
</style>
';

require 'layout.php';
?>

<!-- ── Quick Actions ── -->
<div class="section-header"><h2>Quick Actions</h2></div>
<div class="quick-actions">

<a href="new_complaint.php" class="qa-card">
    <div class="qa-icon">
      <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    </div>
    <div class="qa-text">
      <div class="qt">Submit Complaint</div>
      <div class="qs">Report a new issue</div>
    </div>
  </a>

  

  <a href="my_complaints.php" class="qa-card qa-my-complaints">
    <div class="qa-icon">
      <svg viewBox="0 0 24 24">
        <path d="M9 12h6m-6 4h6M5 7h14M5 7a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2"/>
      </svg>
    </div>
    <div class="qa-text">
      <div class="qt">My Submissions</div>
      <div class="qs">View & track all submissions</div>
    </div>
  </a>

</div>

<!-- ── Recent Activity ── -->
<div class="section-header">
  <h2>Recent Activity</h2>
  <a href="my_complaints.php">
    View all
    <svg viewBox="0 0 24 24"><polyline points="9,18 15,12 9,6"/></svg>
  </a>
</div>

<div class="rc-card">
  <table class="rc-table">
    <colgroup>
      <col class="col-id">
      <col style="width:14%"><!-- Type -->
      <col class="col-complaint">
      <col class="col-status">
      <col class="col-date">
      <col class="col-action">
    </colgroup>
    <thead>
      <tr>
        <th>Reference/Ticket ID</th>
        <th>Type</th>
        <th>Title / Item</th>
        <th>Status</th>
        <th>Date</th>
        <th class="th-action">Action</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($recentActivity)): ?>
      <tr class="rc-empty">
        <td colspan="6">
          <svg class="rc-empty-icon" viewBox="0 0 24 24" fill="none" stroke="#adb3c8" stroke-width="1.5">
            <path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
          </svg>
          No activity yet.
          <a href="new_complaint.php" style="color:#3b6bff;font-weight:600;margin-left:4px;">Submit your first complaint &rarr;</a>
        </td>
      </tr>
      <?php else: foreach ($recentActivity as $row):
        $meta     = $statusMeta[$row['status']] ?? ['label' => ucfirst($row['status']), 'class' => 'pill-closed'];
        $isReq    = ($row['row_type'] === 'requisition');
        $detailUrl = $isReq
            ? 'my_requisition_detail.php?ref=' . urlencode($row['ref_id'])
            : 'my_ticket_detail.php?id='       . urlencode($row['ref_id']);
      ?>
      <tr onclick="window.location='<?php echo $detailUrl; ?>'">

        <td>
          <span class="rc-tid"><?php echo htmlspecialchars($row['ref_id']); ?></span>
        </td>

        <td>
          <?php if ($isReq): ?>
            <span class="type-badge type-requisition">⚙ Equipment</span>
          <?php else: ?>
            <span class="type-badge type-complaint">✉ Complaint</span>
          <?php endif; ?>
        </td>

        <td>
          <span class="rc-title"><?php echo htmlspecialchars($row['title']); ?></span>
          <span class="rc-cat"><?php echo htmlspecialchars($row['category_name']); ?></span>
        </td>

        <td>
          <span class="rc-pill <?php echo $meta['class']; ?>">
            <span class="rc-pill-dot"></span>
            <?php echo $meta['label']; ?>
          </span>
        </td>

        <td>
          <span class="rc-date"><?php echo date('d M Y', strtotime($row['created_at'])); ?></span>
        </td>

        <td class="td-action">
          <a href="<?php echo $detailUrl; ?>"
             class="rc-view-btn"
             onclick="event.stopPropagation()">
            View
            <svg viewBox="0 0 24 24"><polyline points="9,18 15,12 9,6"/></svg>
          </a>
        </td>

      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php require 'layout_end.php'; ?>