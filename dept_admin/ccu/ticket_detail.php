<?php
// dept_admin/ccu/ticket_detail.php
require '_layout.php';
require_once __DIR__ . '/../../db_connect.php';
require_once __DIR__ . '/../../assign_helper.php';
require_once __DIR__ . '/../../sla_helper.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// ── Flash messages ────────────────────────────────────────────────────────────
$updateMsg   = $_SESSION['flash_success'] ?? '';
$updateError = $_SESSION['flash_error']   ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// ── Smart back URL ─────────────────────────────────────────────────────────────
$ticketId = trim($_GET['id'] ?? '');
$backUrl  = 'tickets.php';
$sessionKey = 'td_back_admin_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $ticketId);
if (!empty($_GET['from'])) {
    $from = $_GET['from'];
    if (!preg_match('#^https?://#', $from)) {
        $backUrl = $from;
        $_SESSION[$sessionKey] = $backUrl;
    }
} elseif (!empty($_SESSION[$sessionKey])) {
    $backUrl = $_SESSION[$sessionKey];
} elseif (!empty($_SERVER['HTTP_REFERER'])) {
    $ref = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH);
    if ($ref && strpos($ref, 'ticket_detail') === false) {
        $backUrl = $_SERVER['HTTP_REFERER'];
        $_SESSION[$sessionKey] = $backUrl;
    }
}
$backUrlEncoded = urlencode($backUrl);

// ── Fetch ticket ──────────────────────────────────────────────────────────────
$ticket = null;
if ($ticketId !== '') {
    $stmt = $conn->prepare("
        SELECT c.*, cat.category_name
        FROM complaints c
        LEFT JOIN categories cat ON cat.category_id = c.category_id
        WHERE c.ticket_id = ? AND c.dept_id = 3
        LIMIT 1
    ");
    $stmt->bind_param("s", $ticketId);
    $stmt->execute();
    $ticket = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// ── HANDLE POST: Reassign only ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ticket) {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'reassign') {
        $newStaffId = (int)($_POST['new_staff_id'] ?? 0);
        if ($newStaffId > 0) {
            $oldAssigned = getAssignedStaff($conn, $ticketId);
            $oldName = $oldAssigned ? $oldAssigned['full_name'] : 'Unassigned';

            $nsQ = $conn->prepare("SELECT full_name FROM staff WHERE staff_id=? LIMIT 1");
            $nsQ->bind_param("i", $newStaffId); $nsQ->execute();
            $nsRow = $nsQ->get_result()->fetch_assoc(); $nsQ->close();
            $newName = $nsRow['full_name'] ?? "Staff #$newStaffId";

            manualAssignTicket($conn, 3, $ticketId, $newStaffId);

            // Log the reassignment
            $asnLog = $conn->prepare("INSERT INTO ticket_logs (ticket_id,changed_by_id,changed_by,field_changed,old_priority,new_priority) VALUES (?,?,?,'assigned',?,?)");
            if ($asnLog) {
                $alId   = 0; // admin context — no staff session, adjust if needed
                $alName = 'Admin CCU';
                $asnLog->bind_param("sisss", $ticketId, $alId, $alName, $oldName, $newName);
                $asnLog->execute(); $asnLog->close();
            }
            $_SESSION['flash_success'] = 'Ticket reassigned to <strong>' . htmlspecialchars($newName) . '</strong>.';
        } else {
            $_SESSION['flash_error'] = 'Please select a staff member to assign.';
        }
        header('Location: ticket_detail.php?id=' . urlencode($ticketId) . '&tab=detail&from=' . $backUrlEncoded);
        exit;
    }
}

// ── Fetch submitter info ──────────────────────────────────────────────────────
$submitter = null;
if ($ticket) {
    $type  = $ticket['submitter_type'] ?? 'student';
    $table = $type === 'student' ? 'students' : 'staff';
    $pkCol = $type === 'student' ? 'student_id' : 'staff_id';
    $s2 = $conn->prepare("SELECT full_name AS name, email FROM {$table} WHERE {$pkCol} = ? LIMIT 1");
    if ($s2) {
        $s2->bind_param("i", $ticket['submitter_id']);
        $s2->execute();
        $submitter = $s2->get_result()->fetch_assoc();
        $s2->close();
    }
}

// ── Fetch assigned staff ──────────────────────────────────────────────────────
$assignedStaff = null;
if ($ticket) {
    $assignedStaff = getAssignedStaff($conn, $ticketId);
}

$slaData = null;
if ($ticket && !empty($ticket['sla_start_at'])) {
    $slaData = getSlaStatus($ticket['sla_start_at'], $ticket['resolved_at'] ?? null, $ticket['status']);
}

// ── Fetch dept staff list for reassign dropdown ───────────────────────────────
$deptStaffList = [];
$dsStmt = $conn->prepare("
    SELECT staff_id, full_name
    FROM staff
    WHERE dept_id = 3 AND status = 'active' AND role = 'staff'
    ORDER BY full_name ASC
");
$dsStmt->execute();
$dsRes = $dsStmt->get_result();
while ($row = $dsRes->fetch_assoc()) $deptStaffList[] = $row;
$dsStmt->close();

// ── Fetch change history ──────────────────────────────────────────────────────
$changeLogs = [];
if ($ticket) {
    $lg = $conn->prepare("
        SELECT log_id, changed_by, field_changed,
               old_priority, new_priority,
               old_status, new_status,
               changed_at
        FROM ticket_logs
        WHERE ticket_id = ?
        ORDER BY changed_at DESC
    ");
    $lg->bind_param("s", $ticketId);
    $lg->execute();
    $changeLogs = $lg->get_result()->fetch_all(MYSQLI_ASSOC);
    $lg->close();
}

// ── Fetch chat replies ─────────────────────────────────────────────────────────
$replies = [];
if ($ticket) {
    $rq = $conn->prepare("
        SELECT reply_id, sender_id, sender_name, sender_role, message, attachment_path, created_at
        FROM ticket_replies
        WHERE ticket_id = ?
        ORDER BY created_at ASC
    ");
    $rq->bind_param("s", $ticketId);
    $rq->execute();
    $replies = $rq->get_result()->fetch_all(MYSQLI_ASSOC);
    $rq->close();
}

// ── Fetch customer feedback (only when closed) ────────────────────────────────
$feedback = null;
if ($ticket && strtolower($ticket['status']) === 'closed') {
    $fq = $conn->prepare("
        SELECT tf.rating, tf.comment, tf.is_auto_submitted, tf.created_at,
               s.full_name AS student_name
        FROM ticket_feedback tf
        LEFT JOIN students s ON s.student_id = tf.student_id
        WHERE tf.ticket_id = ?
        LIMIT 1
    ");
    $fq->bind_param("s", $ticketId);
    $fq->execute();
    $feedback = $fq->get_result()->fetch_assoc();
    $fq->close();
}

// ── Active tab ────────────────────────────────────────────────────────────────
$activeTab = $_GET['tab'] ?? 'detail';
if (!in_array($activeTab, ['detail', 'conversation', 'history', 'feedback'])) $activeTab = 'detail';

$isClosed    = $ticket && strtolower($ticket['status']) === 'closed';
$hasFeedback = $feedback !== null;

// ── Helpers ───────────────────────────────────────────────────────────────────
function statusBadge(string $s): string {
    $map = ['open'=>['#FEF3C7','#D97706'],'in_progress'=>['#DBEAFE','#1D4ED8'],'closed'=>['#D1FAE5','#059669']];
    [$bg,$fg] = $map[strtolower($s)] ?? ['#F3F4F6','#6B7280'];
    $label = $s === 'in_progress' ? 'In Progress' : ucfirst($s);
    return "<span style=\"display:inline-block;font-size:12px;font-weight:600;padding:3px 12px;border-radius:20px;background:{$bg};color:{$fg}\">" . htmlspecialchars($label) . "</span>";
}
function priFlag(string $v): string {
    $map = ['low'=>['#3B82F6','Low'],'medium'=>['#F59E0B','Medium'],'high'=>['#EF4444','High']];
    [$color,$label] = $map[strtolower($v)] ?? ['#6B7280',ucfirst($v)];
    $svg = '<svg width="13" height="13" viewBox="0 0 24 24" fill="'.$color.'" xmlns="http://www.w3.org/2000/svg" style="flex-shrink:0"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15" stroke="'.$color.'" stroke-width="2" stroke-linecap="round"/></svg>';
    return '<span style="display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:600;color:'.$color.';">'.$svg.htmlspecialchars($label).'</span>';
}
function priChip(string $v): string {
    $map = ['low'=>['#3B82F6','Low'],'medium'=>['#F59E0B','Medium'],'high'=>['#EF4444','High']];
    [$color,$label] = $map[strtolower($v)] ?? ['#6B7280',ucfirst($v)];
    $svg = '<svg width="10" height="10" viewBox="0 0 24 24" fill="'.$color.'" style="flex-shrink:0"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15" stroke="'.$color.'" stroke-width="2" stroke-linecap="round"/></svg>';
    return '<span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;color:'.$color.';">'.$svg.htmlspecialchars($label).'</span>';
}
function statChip(string $v): string {
    $map = ['open'=>['#FEF3C7','#D97706'],'in_progress'=>['#DBEAFE','#1D4ED8'],'closed'=>['#D1FAE5','#059669']];
    [$bg,$fg] = $map[strtolower($v)] ?? ['#F3F4F6','#6B7280'];
    $label = $v === 'in_progress' ? 'In Progress' : ucfirst($v);
    return "<span style=\"display:inline-block;font-size:11px;font-weight:600;padding:2px 9px;border-radius:20px;background:{$bg};color:{$fg}\">" . htmlspecialchars($label) . "</span>";
}
function timeAgo(string $datetime): string {
    $now  = new DateTime('now', new DateTimeZone('Asia/Kuala_Lumpur'));
    $past = new DateTime($datetime, new DateTimeZone('Asia/Kuala_Lumpur'));
    $diff = $now->getTimestamp() - $past->getTimestamp();
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff/60) . ' min ago';
    if ($diff < 86400)  return floor($diff/3600) . ' hr ago';
    if ($diff < 604800) { $d = floor($diff/86400); return $d . ' day' . ($d > 1 ? 's' : '') . ' ago'; }
    return date('d M Y', $past->getTimestamp());
}
function getInitials(string $name): string {
    $parts = explode(' ', trim($name));
    $ini   = strtoupper(substr($parts[0], 0, 1));
    if (count($parts) > 1) $ini .= strtoupper(substr($parts[count($parts)-1], 0, 1));
    return $ini;
}
function isImageFile(string $path): bool {
    return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg','jpeg','png','gif','webp']);
}
function fileTypeIcon(string $path): array {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return match($ext) {
        'pdf'        => ['label' => 'PDF',  'color' => '#DC2626', 'bg' => '#FEF2F2'],
        'doc','docx' => ['label' => 'DOC',  'color' => '#1D4ED8', 'bg' => '#EFF6FF'],
        'txt'        => ['label' => 'TXT',  'color' => '#374151', 'bg' => '#F9FAFB'],
        default      => ['label' => strtoupper($ext), 'color' => '#6B7280', 'bg' => '#F3F4F6'],
    };
}
function feedbackEmojiSvg(int $rating, int $size = 32): string {
    $emojis = [
        1 => ['stroke'=>'#EF4444','fill'=>'#FEE2E2','face'=>'<circle cx="17" cy="20" r="2.5" fill="#EF4444"/><circle cx="31" cy="20" r="2.5" fill="#EF4444"/><path d="M16 33c2-4 14-4 16 0" stroke="#EF4444" stroke-width="2.5" stroke-linecap="round"/><path d="M15 15l4 3M33 15l-4 3" stroke="#EF4444" stroke-width="2" stroke-linecap="round"/>'],
        2 => ['stroke'=>'#F97316','fill'=>'#FFEDD5','face'=>'<circle cx="17" cy="20" r="2.5" fill="#F97316"/><circle cx="31" cy="20" r="2.5" fill="#F97316"/><path d="M17 32c2-3 12-3 14 0" stroke="#F97316" stroke-width="2.5" stroke-linecap="round"/>'],
        3 => ['stroke'=>'#EAB308','fill'=>'#FEF9C3','face'=>'<circle cx="17" cy="20" r="2.5" fill="#EAB308"/><circle cx="31" cy="20" r="2.5" fill="#EAB308"/><line x1="17" y1="32" x2="31" y2="32" stroke="#EAB308" stroke-width="2.5" stroke-linecap="round"/>'],
        4 => ['stroke'=>'#22C55E','fill'=>'#DCFCE7','face'=>'<circle cx="17" cy="20" r="2.5" fill="#22C55E"/><circle cx="31" cy="20" r="2.5" fill="#22C55E"/><path d="M16 28c2 4 14 4 16 0" stroke="#22C55E" stroke-width="2.5" stroke-linecap="round"/>'],
        5 => ['stroke'=>'#16A34A','fill'=>'#D1FAE5','face'=>'<circle cx="17" cy="19" r="2.5" fill="#16A34A"/><circle cx="31" cy="19" r="2.5" fill="#16A34A"/><path d="M14 27c2 6 18 6 20 0" stroke="#16A34A" stroke-width="2.5" stroke-linecap="round"/>'],
    ];
    $e = $emojis[$rating] ?? $emojis[3];
    return '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="24" cy="24" r="22" stroke="'.htmlspecialchars($e['stroke']).'" stroke-width="2.5" fill="'.htmlspecialchars($e['fill']).'"/>'.$e['face'].'</svg>';
}
function ratingLabel(int $rating): string {
    return match($rating) { 1=>'Very Unsatisfied', 2=>'Unsatisfied', 3=>'Neutral', 4=>'Satisfied', 5=>'Very Satisfied', default=>'Unknown' };
}
function ratingColors(int $rating): array {
    return match($rating) {
        1 => ['#FEF2F2','#DC2626','#EF4444'],
        2 => ['#FFF7ED','#C2410C','#F97316'],
        3 => ['#FEFCE8','#854D0E','#EAB308'],
        4 => ['#F0FDF4','#166534','#22C55E'],
        5 => ['#ECFDF5','#166534','#16A34A'],
        default => ['#F3F4F6','#374151','#6B7280'],
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Ticket Detail | UniKL Help Desk – CCU Admin</title>
  <?php include '_head_assets.php'; ?>
  <link rel="stylesheet" href="css/ticket_detail.css"/>
</head>
<body>
<?php include '_sidebar.php'; ?>

<main class="main-content">

  <!-- Breadcrumb -->
  <div class="td-breadcrumb">
    <a href="<?= htmlspecialchars($backUrl) ?>">All Tickets</a>
    <span class="td-breadcrumb-sep">›</span>
    <span><?= htmlspecialchars($ticketId ?: 'Detail') ?></span>
  </div>

  <!-- Back button -->
  <a href="<?= htmlspecialchars($backUrl) ?>" class="td-back-btn">
    <svg viewBox="0 0 24 24"><polyline points="15,18 9,12 15,6"/></svg>
    Back to All Tickets
  </a>

  <?php if ($updateMsg): ?>
  <div class="td-alert td-alert-success">
    <svg viewBox="0 0 24 24"><polyline points="20,6 9,17 4,12"/></svg>
    <span><?= $updateMsg ?></span>
  </div>
  <?php endif; ?>
  <?php if ($updateError): ?>
  <div class="td-alert td-alert-error">
    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <span><?= htmlspecialchars($updateError) ?></span>
  </div>
  <?php endif; ?>

  <?php if (!$ticket): ?>
  <!-- Not Found -->
  <div class="not-found">
    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <h2>Ticket Not Found</h2>
    <p>The ticket <strong><?= htmlspecialchars($ticketId) ?></strong> does not exist or does not belong to the IT Department.</p>
    <a href="<?= htmlspecialchars($backUrl) ?>" class="nf-back">
      <svg viewBox="0 0 24 24"><polyline points="15,18 9,12 15,6"/></svg>
      Back to All Tickets
    </a>
  </div>

  <?php else: ?>

  <!-- Ticket header strip (always visible above tabs) -->
  <div class="ticket-header-strip">
    <div class="ths-icon">
      <svg viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
    </div>
    <div class="ths-info">
      <div class="ths-title"><?= htmlspecialchars($ticket['title']) ?></div>
      <?php if (!empty($ticket['description'])): ?>
      <div class="ths-desc"><?= htmlspecialchars($ticket['description']) ?></div>
      <?php endif; ?>
      <div class="ths-bottom-row">
        <div class="ths-badges">
          <?= statusBadge($ticket['status']) ?>
          <?= priFlag($ticket['priority'] ?? 'medium') ?>
        </div>
      </div>
    </div>
    <div class="ths-id-badge"><?= htmlspecialchars($ticket['ticket_id']) ?></div>
  </div>

  <!-- Tab Bar -->
  <div class="td-tab-bar" role="tablist">

    <a href="?id=<?= urlencode($ticketId) ?>&tab=detail&from=<?= $backUrlEncoded ?>"
       class="td-tab-btn <?= $activeTab === 'detail' ? 'active' : '' ?>" role="tab">
      <svg viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
      Detail
    </a>

    <a href="?id=<?= urlencode($ticketId) ?>&tab=conversation&from=<?= $backUrlEncoded ?>"
       class="td-tab-btn <?= $activeTab === 'conversation' ? 'active' : '' ?>" role="tab">
      <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
      Conversation
      <?php if (count($replies) > 0): ?>
      <span class="td-tab-badge"><?= count($replies) ?></span>
      <?php endif; ?>
    </a>

    <a href="?id=<?= urlencode($ticketId) ?>&tab=history&from=<?= $backUrlEncoded ?>"
       class="td-tab-btn <?= $activeTab === 'history' ? 'active' : '' ?>" role="tab">
      <svg viewBox="0 0 24 24"><polyline points="12,8 12,12 14,14"/><path d="M3.05 11a9 9 0 1 1 .5 4"/></svg>
      History
      <?php if (count($changeLogs) > 0): ?>
      <span class="td-tab-badge"><?= count($changeLogs) ?></span>
      <?php endif; ?>
    </a>

    <?php if (!$isClosed): ?>
    <span class="td-tab-btn disabled" title="Ticket must be closed for feedback">
      <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      Feedback
    </span>
    <?php else: ?>
    <a href="?id=<?= urlencode($ticketId) ?>&tab=feedback&from=<?= $backUrlEncoded ?>"
       class="td-tab-btn <?= $activeTab === 'feedback' ? 'active' : '' ?>" role="tab">
      <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
      Feedback
      <?php if ($hasFeedback): ?><span class="td-tab-dot"></span><?php endif; ?>
    </a>
    <?php endif; ?>

  </div>


  <!-- ══════════════════════════════════════════════════
       TAB 1: DETAIL
  ══════════════════════════════════════════════════ -->
  <div class="td-panel <?= $activeTab === 'detail' ? 'active' : '' ?>">
    <div class="detail-tab-grid">

      <!-- LEFT: Ticket info -->
      <div class="detail-left">
        <div class="td-card ticket-info-card">
          <div class="td-card-header">
            <div class="td-card-header-icon ticket-info-header-icon">
              <svg viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
            </div>
            <div>
              <div class="td-card-header-title">Ticket Information</div>
              <div class="td-card-header-sub">Full details of this complaint</div>
            </div>
          </div>
          <div class="td-card-body">

            <div class="ti-meta-grid">
              <div>
                <div class="ti-meta-label">Category</div>
                <div class="ti-meta-value"><?= htmlspecialchars($ticket['category_name'] ?? '—') ?></div>
              </div>
              <div>
                <div class="ti-meta-label">From Department</div>
                <div class="ti-meta-value"><?= htmlspecialchars($ticket['my_department'] ?? '—') ?></div>
              </div>
              <div>
                <div class="ti-meta-label">Priority</div>
                <div class="ti-meta-value"><?= priFlag($ticket['priority'] ?? 'medium') ?></div>
              </div>
              <div>
                <div class="ti-meta-label">Status</div>
                <div class="ti-meta-value"><?= statusBadge($ticket['status'] ?? 'open') ?></div>
              </div>
              <div>
                <div class="ti-meta-label">Submitted</div>
                <div class="ti-meta-value"><?= date('d M Y, H:i', strtotime($ticket['created_at'])) ?></div>
              </div>
              <div>
                <div class="ti-meta-label">Last Updated</div>
                <div class="ti-meta-value"><?= date('d M Y, H:i', strtotime($ticket['updated_at'])) ?></div>
              </div>
            </div>

            <div class="ti-desc-label">Description</div>
            <div class="ti-desc-box"><?= htmlspecialchars($ticket['description']) ?></div>
            <?php if (!empty($ticket['attachment_path'])): ?>
            <a class="ti-attach-link" href="../../<?= htmlspecialchars($ticket['attachment_path']) ?>" target="_blank" rel="noopener">
              <svg viewBox="0 0 24 24"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
              View Attachment
            </a>
            <?php endif; ?>

            <div class="ti-divider"></div>

            <div class="ti-section-label">
              <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              Submitted By
            </div>
            <div class="ti-submitter-grid">
              <div class="ti-submitter-cell">
                <div class="ti-submitter-lbl">Name</div>
                <div class="ti-submitter-val"><?= htmlspecialchars($submitter['name'] ?? '—') ?></div>
              </div>
              <div class="ti-submitter-cell">
                <div class="ti-submitter-lbl">Email</div>
                <div class="ti-submitter-val"><?= htmlspecialchars($submitter['email'] ?? '—') ?></div>
              </div>
              <div class="ti-submitter-cell">
                <div class="ti-submitter-lbl">Phone</div>
                <div class="ti-submitter-val">+60 <?= htmlspecialchars($ticket['phone'] ?? '—') ?></div>
              </div>
              <div class="ti-submitter-cell" style="border-right:none">
                <div class="ti-submitter-lbl">Type</div>
                <div class="ti-submitter-val" style="text-transform:capitalize"><?= htmlspecialchars($ticket['submitter_type'] ?? '—') ?></div>
              </div>
            </div>

          </div>
        </div><!-- /.ticket-info-card -->

        <!-- SLA Status -->
        <?php if ($slaData): ?>
        <div class="td-card">
          <div class="td-card-header">
            <div class="td-card-header-icon sla-header-icon">
              <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12,6 12,12 16,14"/></svg>
            </div>
            <div>
              <div class="td-card-header-title">SLA Status</div>
              <div class="td-card-header-sub">8 hrs · Mon–Fri 08:00–17:00</div>
            </div>
          </div>
          <div class="td-card-body">
            <div class="sla-inline-grid">
              <div>
                <div class="sla-status-chip" style="background:<?= $slaData['status_bg']; ?>;color:<?= $slaData['status_color']; ?>">
                  <?= htmlspecialchars($slaData['status_label']); ?>
                </div>
                <?php if (strtolower($ticket['status']) !== 'closed'): ?>
                <div class="sla-remaining-big" style="color:<?= $slaData['status_color']; ?>">
                  <?= htmlspecialchars($slaData['remaining_str']); ?>
                </div>
                <div class="sla-remaining-sub">until SLA deadline</div>
                <?php endif; ?>
              </div>
              <div class="sla-inline-right">
                <?php $fillPct = min($slaData['percent_used'], 100); ?>
                <div class="sla-progress-wrap">
                  <div class="sla-progress-fill" style="width:<?= $fillPct; ?>%;background:<?= $slaData['status_color']; ?>"></div>
                </div>
                <div class="sla-tick-row"><span>0h</span><span>4h</span><span>8h</span></div>
                <div class="sla-info-grid" style="grid-template-columns: repeat(4,1fr);">
                  <div>
                    <div class="sla-info-label">SLA Started</div>
                    <div class="sla-info-value"><?= date('d M, H:i', strtotime($ticket['sla_start_at'])); ?></div>
                  </div>
                  <div>
                    <div class="sla-info-label">Deadline</div>
                    <div class="sla-info-value"><?= $slaData['deadline_str']; ?></div>
                  </div>
                  <div>
                    <div class="sla-info-label">Time Used</div>
                    <div class="sla-info-value">
                      <?php $em = $slaData['elapsed_mins']; $eh = intdiv($em, 60); $emm = $em % 60; echo $eh > 0 ? "{$eh}h {$emm}m" : "{$emm}m"; ?> / <?= SLA_WORK_HOURS; ?>h
                    </div>
                  </div>
                  <div>
                    <div class="sla-info-label"><?= strtolower($ticket['status']) === 'closed' ? 'Closed At' : 'Updated'; ?></div>
                    <div class="sla-info-value">
                      <?php if (strtolower($ticket['status']) === 'closed' && !empty($ticket['resolved_at'])): ?>
                        <?= date('d M, H:i', strtotime($ticket['resolved_at'])); ?>
                      <?php else: ?>
                        <?= date('d M, H:i', strtotime($ticket['updated_at'])); ?>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <?php
              $slaStartTs  = strtotime($ticket['sla_start_at']);
              $createdTs   = strtotime($ticket['created_at']);
              $wasReopened = ($slaStartTs > $createdTs + 60);
            ?>
            <?php if ($wasReopened && strtolower($ticket['status']) !== 'closed'): ?>
            <div class="sla-reset-notice" style="margin-top:12px;">
              <svg viewBox="0 0 24 24"><polyline points="23,4 23,10 17,10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
              <div><strong>SLA was reset</strong> when this ticket was reopened. Fresh 8-hour window started <?= date('d M Y, H:i', $slaStartTs); ?>.</div>
            </div>
            <?php endif; ?>
          </div>
        </div><!-- /.sla-card -->
        <?php endif; ?>

      </div><!-- /.detail-left -->

      <!-- RIGHT: Sidebar -->
      <div class="detail-right">

        <!-- Admin view-only notice -->
        <div class="admin-view-notice">
          <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <div>You are viewing as <strong>CCU Admin</strong>. Priority and status changes can only be made by the assigned staff.</div>
        </div>

        <!-- Assigned To -->
        <div class="td-card">
          <div class="td-card-header">
            <div class="td-card-header-icon assigned-header-icon">
              <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            </div>
            <div>
              <div class="td-card-header-title">Assigned To</div>
              <div class="td-card-header-sub">Auto round-robin assignment</div>
            </div>
          </div>
          <div class="td-card-body">

            <?php if ($assignedStaff): ?>
            <div class="assigned-pill">
              <div class="assigned-avatar"><?= getInitials($assignedStaff['full_name']) ?></div>
              <div style="flex:1;min-width:0;">
                <div class="assigned-name"><?= htmlspecialchars($assignedStaff['full_name']) ?></div>
                <div class="assigned-role-tag">CCU Staff</div>
              </div>
            </div>
            <?php else: ?>
            <div class="unassigned-pill">⚠️ No staff assigned yet.</div>
            <?php endif; ?>

            <?php if (!empty($deptStaffList)): ?>
            <form method="POST" action="ticket_detail.php?id=<?= urlencode($ticketId) ?>">
              <input type="hidden" name="action" value="reassign"/>
              <div class="reassign-label">Reassign to</div>
              <div class="reassign-row">
                <select name="new_staff_id" class="reassign-select" required>
                  <option value="">— Select staff —</option>
                  <?php foreach ($deptStaffList as $s): ?>
                  <option value="<?= $s['staff_id'] ?>"
                    <?= (($assignedStaff['staff_id'] ?? 0) == $s['staff_id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($s['full_name']) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="reassign-btn">Save</button>
              </div>
            </form>
            <?php endif; ?>

          </div>
        </div>

        <!-- Status overview (read-only) -->
        <div class="td-card">
          <div class="td-card-header">
            <div class="td-card-header-icon" style="background:#F3F4F6;">
              <svg viewBox="0 0 24 24" style="width:16px;height:16px;fill:none;stroke:#6B7280;stroke-width:1.8"><rect x="1" y="5" width="22" height="14" rx="7"/><circle cx="16" cy="12" r="3"/></svg>
            </div>
            <div>
              <div class="td-card-header-title">Status Overview</div>
              <div class="td-card-header-sub">Read-only — admin view</div>
            </div>
          </div>
          <div class="td-card-body">
            <?php $curPri = strtolower($ticket['priority'] ?? 'medium'); $curStat = strtolower($ticket['status'] ?? 'open'); ?>
            <div style="display:flex;flex-direction:column;gap:10px;">
              <div>
                <div style="font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:#9CA3AF;margin-bottom:6px;">Priority</div>
                <div style="display:flex;gap:6px;">
                  <?php foreach(['low'=>'Low','medium'=>'Medium','high'=>'High'] as $val=>$lbl): ?>
                  <?php $priColors=['low'=>['#EFF6FF','#3B82F6'],'medium'=>['#FFFBEB','#F59E0B'],'high'=>['#FEF2F2','#EF4444']]; [$pBg,$pFg]=$priColors[$val]; ?>
                  <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;padding:8px 5px;border-radius:8px;border:1.5px solid <?= $curPri===$val ? $pFg : '#E5E7EB' ?>;background:<?= $curPri===$val ? $pBg : '#F9FAFB' ?>;opacity:<?= $curPri===$val ? '1' : '.45' ?>;">
                    <div style="width:7px;height:7px;border-radius:50%;background:<?= $pFg ?>;"></div>
                    <div style="font-size:11.5px;font-weight:<?= $curPri===$val ? '700' : '500' ?>;color:<?= $curPri===$val ? $pFg : '#9CA3AF' ?>;"><?= $lbl ?></div>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
              <div style="height:1px;background:#F3F4F6;"></div>
              <div>
                <div style="font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:#9CA3AF;margin-bottom:6px;">Status</div>
                <div style="padding:9px 11px;border:1.5px solid #E5E7EB;border-radius:7px;font-size:13px;color:#9CA3AF;background:#F9FAFB;">
                  <?= $curStat === 'in_progress' ? 'In Progress' : ucfirst($curStat) ?>
                </div>
              </div>
            </div>
          </div>
        </div>

      </div><!-- /.detail-right -->
    </div><!-- /.detail-tab-grid -->
  </div><!-- /.td-panel detail -->


  <!-- ══════════════════════════════════════════════════
       TAB 2: CONVERSATION (read-only for admin)
  ══════════════════════════════════════════════════ -->
  <div class="td-panel <?= $activeTab === 'conversation' ? 'active' : '' ?>">
    <div class="conv-card">
      <div class="conv-header">
        <div class="conv-header-icon">
          <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        </div>
        <div>
          <div class="conv-header-title">Complaint Chat</div>
          <div class="conv-header-sub">Read-only — conversation between staff and <?= htmlspecialchars($submitter['name'] ?? 'user') ?></div>
        </div>
        <?php if (count($replies) > 0): ?>
        <div class="conv-badge"><?= count($replies) ?></div>
        <?php endif; ?>
      </div>

      <div class="conv-messages" id="chatMessages">

        <?php if (empty($replies)): ?>
        <div class="conv-empty">
          <div class="conv-empty-icon">
            <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
          </div>
          <div>
            <strong style="display:block;font-size:13.5px;font-weight:600;color:#374151;margin-bottom:3px;">No messages yet</strong>
            <p style="font-size:12.5px;color:#9CA3AF;margin:0;max-width:200px;">No conversation has been started on this ticket.</p>
          </div>
        </div>

        <?php else:
          $prevDate = '';
          foreach ($replies as $r):
            $msgDate  = date('d M Y', strtotime($r['created_at']));
            $isStaff  = in_array($r['sender_role'], ['staff','dept_handler','admin']);
            $rowClass = $isStaff ? 'from-staff' : 'from-user';
            $avClass  = $isStaff ? 'staff-av' : 'user-av';
            $initials = getInitials($r['sender_name']);
            $hasAttach  = !empty($r['attachment_path']);
            $attachPath = $hasAttach ? $r['attachment_path'] : '';
            $isImg      = $hasAttach && isImageFile($attachPath);
            $fileName   = $hasAttach ? basename($attachPath) : '';
            $fileIcon   = ($hasAttach && !$isImg) ? fileTypeIcon($attachPath) : [];
        ?>
          <?php if ($msgDate !== $prevDate): $prevDate = $msgDate; ?>
          <div class="date-sep"><span><?= $msgDate === date('d M Y') ? 'Today' : $msgDate ?></span></div>
          <?php endif; ?>

          <div class="msg-row <?= $rowClass ?>">
            <div class="msg-avatar <?= $avClass ?>"><?= htmlspecialchars($initials) ?></div>
            <div class="msg-content">
              <div class="msg-meta">
                <span class="msg-sender"><?= htmlspecialchars($r['sender_name']) ?></span>
                · <?= date('H:i', strtotime($r['created_at'])) ?>
              </div>

              <?php if ($isImg): ?>
                <a href="../../<?= htmlspecialchars($attachPath) ?>"
                   class="msg-img-bubble"
                   onclick="openLightbox(this.href,'<?= htmlspecialchars(addslashes($fileName)) ?>'); return false;">
                  <img src="../../<?= htmlspecialchars($attachPath) ?>" alt="<?= htmlspecialchars($fileName) ?>" loading="lazy"/>
                </a>
                <?php if (!empty($r['message'])): ?>
                <div class="msg-bubble"><?= nl2br(htmlspecialchars($r['message'])) ?></div>
                <?php endif; ?>

              <?php elseif ($hasAttach): ?>
                <?php if (!empty($r['message'])): ?>
                <div class="msg-bubble"><?= nl2br(htmlspecialchars($r['message'])) ?></div>
                <?php endif; ?>
                <a href="../../<?= htmlspecialchars($attachPath) ?>" class="msg-file-card" target="_blank" rel="noopener">
                  <div class="msg-file-icon"
                       style="<?php if (!$isStaff): echo 'background:'.htmlspecialchars($fileIcon['bg']).';color:'.htmlspecialchars($fileIcon['color']).';'; endif; ?>">
                    <?= htmlspecialchars($fileIcon['label'] ?? '?') ?>
                  </div>
                  <div class="msg-file-info">
                    <span class="msg-file-name"><?= htmlspecialchars($fileName) ?></span>
                    <span class="msg-file-meta"><?= strtoupper(pathinfo($attachPath, PATHINFO_EXTENSION)) ?> file &nbsp;·&nbsp; tap to open</span>
                  </div>
                  <svg class="msg-file-dl-icon" viewBox="0 0 24 24">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="7 10 12 15 17 10"/>
                    <line x1="12" y1="15" x2="12" y2="3"/>
                  </svg>
                </a>

              <?php else: ?>
                <?php if (!empty($r['message'])): ?>
                <div class="msg-bubble"><?= nl2br(htmlspecialchars($r['message'])) ?></div>
                <?php endif; ?>
              <?php endif; ?>

            </div>
          </div>
        <?php endforeach; ?>
        <?php endif; ?>

      </div><!-- /conv-messages -->

      <!-- Read-only footer -->
      <div class="conv-readonly-bar">
        <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        You do not have permission to reply. Contact CCU staff for updates.
      </div>

    </div><!-- /conv-card -->
  </div><!-- /.td-panel conversation -->


  <!-- ══════════════════════════════════════════════════
       TAB 3: HISTORY
  ══════════════════════════════════════════════════ -->
  <div class="td-panel <?= $activeTab === 'history' ? 'active' : '' ?>">
    <div class="history-card">
      <div class="td-card-header" style="padding:14px 20px;border-bottom:1px solid #F3F4F6;">
        <div class="td-card-header-icon" style="background:#F5F3FF;">
          <svg viewBox="0 0 24 24" style="width:16px;height:16px;fill:none;stroke:#7C3AED;stroke-width:1.8"><polyline points="12,8 12,12 14,14"/><path d="M3.05 11a9 9 0 1 1 .5 4"/></svg>
        </div>
        <div style="flex:1;min-width:0;">
          <div class="td-card-header-title">Change History</div>
          <div class="td-card-header-sub"><?= count($changeLogs) ?> change<?= count($changeLogs) !== 1 ? 's' : '' ?> recorded</div>
        </div>
        <?php if (count($changeLogs) > 0): ?>
        <select id="tlPerPage" class="tl-perpage-select">
          <option value="5">5 per page</option>
          <option value="10">10 per page</option>
          <option value="25">25 per page</option>
          <option value="50">50 per page</option>
        </select>
        <?php endif; ?>
      </div>

      <?php if (empty($changeLogs)): ?>
      <div class="history-empty">
        <div class="history-empty-icon">
          <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><polyline points="12,8 12,12 14,14"/></svg>
        </div>
        <div class="history-empty-title">No changes yet</div>
        <div class="history-empty-sub">Priority or status updates will appear here.</div>
      </div>

      <?php else: ?>
      <div class="timeline" id="timelineContainer">
        <?php foreach ($changeLogs as $idx => $log):
          $fc = $log['field_changed'];
          $dotCls = match($fc) {
            'priority'     => 'pri',
            'status'       => 'stat',
            'assigned'     => 'asgn',
            'conversation' => 'conv',
            default        => 'both'
          };
        ?>
        <div class="tl-item" data-log-index="<?= $idx ?>">
          <div class="tl-dot <?= $dotCls ?>">
            <?php if ($fc === 'priority'): ?>
              <svg viewBox="0 0 24 24"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>
            <?php elseif ($fc === 'status'): ?>
              <svg viewBox="0 0 24 24"><rect x="1" y="5" width="22" height="14" rx="7"/><circle cx="16" cy="12" r="3"/></svg>
            <?php elseif ($fc === 'assigned'): ?>
              <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <?php elseif ($fc === 'conversation'): ?>
              <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            <?php else: ?>
              <svg viewBox="0 0 24 24"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/></svg>
            <?php endif; ?>
          </div>
          <div class="tl-body">
            <div class="tl-header">
              <span class="tl-who"><?= htmlspecialchars($log['changed_by']) ?></span>
              <span class="tl-when"><?= timeAgo($log['changed_at']) ?></span>
            </div>
            <span class="tl-when-full"><?= date('d M Y, H:i', strtotime($log['changed_at'])) ?></span>
            <div class="tl-changes" style="margin-top:5px">
              <?php if (in_array($fc, ['priority','both']) && $log['old_priority'] && $log['new_priority']): ?>
              <div class="tl-row">
                <span class="tl-row-label">Priority</span>
                <?= priChip($log['old_priority']) ?>
                <span class="tl-arrow">→</span>
                <?= priChip($log['new_priority']) ?>
              </div>
              <?php endif; ?>
              <?php if (in_array($fc, ['status','both']) && $log['old_status'] && $log['new_status']): ?>
              <div class="tl-row">
                <span class="tl-row-label">Status</span>
                <?= statChip($log['old_status']) ?>
                <span class="tl-arrow">→</span>
                <?= statChip($log['new_status']) ?>
              </div>
              <?php endif; ?>
              <?php if ($fc === 'assigned'): ?>
              <div class="tl-row">
                <span class="tl-row-label">Assigned by</span>
                <span class="tl-chip-name" style="background:#EFF6FF;color:#1D4ED8;"><?= htmlspecialchars($log['changed_by']) ?></span>
              </div>
              <div class="tl-row" style="margin-top:4px;">
                <span class="tl-row-label">From</span>
                <span class="tl-chip-name"><?= htmlspecialchars($log['old_priority'] ?? 'Unassigned') ?></span>
                <span class="tl-arrow">→</span>
                <span class="tl-chip-name tl-chip-name--new"><?= htmlspecialchars($log['new_priority'] ?? '—') ?></span>
              </div>
              <?php endif; ?>
              <?php if ($fc === 'conversation'): ?>
              <div class="tl-row">
                <span class="tl-row-label">Chat</span>
                <span class="tl-chip-conv">Started first reply to ticket</span>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <?php if (count($changeLogs) > 0): ?>
      <div class="tl-pagination" id="tlPagination">
        <span class="tl-page-info" id="tlPageInfo"></span>
        <div class="tl-page-btns" id="tlPageBtns"></div>
      </div>
      <?php endif; ?>

      <?php endif; ?>
    </div>
  </div><!-- /.td-panel history -->


  <!-- ══════════════════════════════════════════════════
       TAB 4: FEEDBACK
  ══════════════════════════════════════════════════ -->
  <div class="td-panel <?= $activeTab === 'feedback' ? 'active' : '' ?>">

    <?php if (!$isClosed): ?>
    <div class="feedback-locked-state">
      <div class="feedback-lock-icon">
        <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      </div>
      <div class="feedback-lock-emojis">
        <?php for ($i = 1; $i <= 5; $i++) echo feedbackEmojiSvg($i, 22); ?>
      </div>
      <div class="feedback-lock-title">Awaiting Closure</div>
      <div class="feedback-lock-sub">Customer feedback will be available once this ticket is marked as <strong>Closed</strong>.</div>
    </div>

    <?php else: ?>
    <div class="feedback-card">
      <div class="feedback-card-header">
        <div class="feedback-card-header-icon">
          <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        </div>
        <div>
          <div class="feedback-card-header-title">Customer Feedback</div>
          <div class="feedback-card-header-sub">
            <?= $hasFeedback ? 'Submitted by ' . htmlspecialchars($feedback['student_name'] ?? 'student') : 'Awaiting student feedback' ?>
          </div>
        </div>
        <?php if ($hasFeedback): ?>
        <div class="feedback-card-header-score"><?= (int)$feedback['rating'] ?> / 5</div>
        <?php endif; ?>
      </div>
      <div class="feedback-card-body">
        <?php if ($hasFeedback):
          $r = (int)$feedback['rating'];
          [$chipBg, $chipFg, $chipDot] = ratingColors($r);
        ?>
          <div class="fb-compact-row">
            <div style="flex-shrink:0;line-height:0"><?= feedbackEmojiSvg($r, 38) ?></div>
            <div style="flex:1;min-width:0">
              <div class="fb-compact-label" style="color:<?= $chipFg ?>"><?= ratingLabel($r) ?></div>
              <div class="fb-compact-meta">
                <?= htmlspecialchars($feedback['student_name'] ?? '—') ?> &nbsp;·&nbsp;
                <?= date('d M Y, H:i', strtotime($feedback['created_at'])) ?>
                <?php if ($feedback['is_auto_submitted']): ?>&nbsp;<span class="fb-auto-chip">Auto</span><?php endif; ?>
              </div>
            </div>
            <div class="fb-compact-score">
              <div class="fb-compact-score-num" style="color:<?= $chipFg ?>"><?= $r ?></div>
              <div class="fb-compact-score-denom">/ 5</div>
            </div>
          </div>
          <div class="fb-mini-faces">
            <?php for ($i = 1; $i <= 5; $i++): ?>
            <div class="fb-mini-face<?= ($i === $r) ? ' active' : '' ?>"><?= feedbackEmojiSvg($i, 22) ?></div>
            <?php endfor; ?>
          </div>
          <?php if (!empty($feedback['comment'])): ?>
          <div class="fb-compact-comment" style="border-left-color:<?= $chipFg ?>">
            <?= htmlspecialchars($feedback['comment']) ?>
          </div>
          <?php else: ?>
          <div class="fb-compact-no-comment">No comment provided.</div>
          <?php endif; ?>
          <div class="fb-compact-footer">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12,6 12,12 16,14"/></svg>
            <?= date('d M Y, H:i', strtotime($feedback['created_at'])) ?>
            <?php if ($feedback['is_auto_submitted']): ?><span class="fb-auto-chip">Auto-submitted</span><?php endif; ?>
          </div>

        <?php else: ?>
          <div class="fb-no-feedback-wrap">
            <div class="fb-no-feedback-faces">
              <?php for ($i = 1; $i <= 5; $i++) echo feedbackEmojiSvg($i, 22); ?>
            </div>
            <div>
              <div class="fb-no-feedback-title">No feedback yet</div>
              <div class="fb-no-feedback-sub">The student hasn't submitted feedback for this ticket.</div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /.td-panel feedback -->

  <?php endif; ?>

</main>

<!-- Lightbox -->
<div id="lightboxBackdrop">
  <button class="lightbox-close" id="lightboxClose">
    <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
  </button>
  <div class="lightbox-img-wrap"><img id="lightboxImg" src="" alt=""/></div>
  <div class="lightbox-meta">
    <div id="lightboxCaption"></div>
    <a id="lightboxOpenFull" href="#" target="_blank" rel="noopener" class="lightbox-open-link">
      <svg viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
      Open full size
    </a>
  </div>
</div>

<?php include '_foot_scripts.php'; ?>

<script>
// ── Lightbox ──────────────────────────────────────────────────────────────────
var lb  = document.getElementById('lightboxBackdrop');
var li  = document.getElementById('lightboxImg');
var lc  = document.getElementById('lightboxCaption');
var lo  = document.getElementById('lightboxOpenFull');

function openLightbox(url, filename) {
  li.src = url; li.alt = filename || '';
  lc.textContent = filename || '';
  lo.href = url;
  lb.classList.add('show');
  document.body.style.overflow = 'hidden';
}
function closeLightbox() {
  lb.classList.remove('show');
  document.body.style.overflow = '';
  setTimeout(function () { li.src = ''; }, 300);
}
document.getElementById('lightboxClose').addEventListener('click', closeLightbox);
lb.addEventListener('click', function (e) { if (e.target === this) closeLightbox(); });
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeLightbox(); });

// ── Scroll chat to bottom ─────────────────────────────────────────────────────
(function () {
  var c = document.getElementById('chatMessages');
  if (c) c.scrollTop = c.scrollHeight;
})();

// ── Timeline pagination ───────────────────────────────────────────────────────
(function () {
  var logs = document.querySelectorAll('.tl-item');
  if (!logs.length) return;
  var total = logs.length, perPage = 5, currentPage = 1;

  function totalPages() { return Math.ceil(total / perPage); }

  function showPage(page) {
    currentPage = Math.max(1, Math.min(page, totalPages()));
    var start = (currentPage - 1) * perPage;
    var end   = Math.min(start + perPage, total);
    logs.forEach(function (el, i) {
      el.style.display = (i >= start && i < end) ? '' : 'none';
    });
    var info = document.getElementById('tlPageInfo');
    if (info) info.textContent = (start + 1) + '–' + end + ' of ' + total;
    renderBtns();
  }

  function renderBtns() {
    var btns = document.getElementById('tlPageBtns');
    if (!btns) return;
    btns.innerHTML = '';
    var tp = totalPages();

    var prev = document.createElement('button');
    prev.className = 'tl-page-btn';
    prev.innerHTML = '<svg viewBox="0 0 24 24"><polyline points="15,18 9,12 15,6"/></svg>';
    prev.disabled = currentPage === 1;
    prev.onclick = function () { showPage(currentPage - 1); };
    btns.appendChild(prev);

    for (var p = 1; p <= tp; p++) {
      var b = document.createElement('button');
      b.className = 'tl-page-btn' + (p === currentPage ? ' active' : '');
      b.textContent = p;
      b.onclick = (function (pg) { return function () { showPage(pg); }; })(p);
      btns.appendChild(b);
    }

    var next = document.createElement('button');
    next.className = 'tl-page-btn';
    next.innerHTML = '<svg viewBox="0 0 24 24"><polyline points="9,18 15,12 9,6"/></svg>';
    next.disabled = currentPage === totalPages();
    next.onclick = function () { showPage(currentPage + 1); };
    btns.appendChild(next);
  }

  var perPageEl = document.getElementById('tlPerPage');
  if (perPageEl) {
    perPageEl.addEventListener('change', function () {
      perPage = parseInt(this.value, 10);
      showPage(1);
    });
  }

  showPage(1);
})();
</script>
</body>
</html>