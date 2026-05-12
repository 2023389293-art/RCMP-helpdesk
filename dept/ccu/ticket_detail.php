<?php
// dept/ccu/ticket_detail.php 
require_once __DIR__ . '/../auth_guard.php';
if (isset($_GET['logout'])) { staffLogout(); }
require_once __DIR__ . '/../../db_connect.php';
require_once __DIR__ . '/../../assign_helper.php';
require_once __DIR__ . '/../../sla_helper.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require __DIR__ . '/../../PHPMailer-master/src/Exception.php';
require __DIR__ . '/../../PHPMailer-master/src/PHPMailer.php';
require __DIR__ . '/../../PHPMailer-master/src/SMTP.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$openCount = $closedCount = $inProgressCount = 0;
$stmt = $conn->prepare("SELECT SUM(status='open') AS oc, SUM(status='in_progress') AS ipc, SUM(status='closed') AS cc FROM complaints WHERE dept_id = ?");
$stmt->bind_param("i", $deptId); $stmt->execute();
$counts = $stmt->get_result()->fetch_assoc(); $stmt->close();
$openCount       = (int)($counts['oc']  ?? 0);
$inProgressCount = (int)($counts['ipc'] ?? 0);
$closedCount     = (int)($counts['cc']  ?? 0);

$ticketId = trim($_GET['id'] ?? '');
$ticket   = null;

// ── Smart back URL ────────────────────────────────────────────────────────────
$backUrl = 'tickets.php';
$sessionKey = 'td_back_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $ticketId);
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

if ($ticketId !== '') {
    $stmt = $conn->prepare("SELECT c.*, cat.category_name FROM complaints c LEFT JOIN categories cat ON cat.category_id = c.category_id WHERE c.ticket_id = ? AND c.dept_id = ? LIMIT 1");
    $stmt->bind_param("si", $ticketId, $deptId); $stmt->execute();
    $ticket = $stmt->get_result()->fetch_assoc(); $stmt->close();
}

// ── AJAX: get logs ────────────────────────────────────────────────────────────
if (!empty($_GET['action']) && $_GET['action'] === 'get_logs' && !empty($_GET['id'])) {
    $ajaxTid = trim($_GET['id']);
    $lg = $conn->prepare("SELECT log_id,changed_by,field_changed,old_priority,new_priority,old_status,new_status,changed_at FROM ticket_logs WHERE ticket_id=? ORDER BY changed_at DESC");
    $lg->bind_param("s", $ajaxTid); $lg->execute();
    $logs = $lg->get_result()->fetch_all(MYSQLI_ASSOC); $lg->close();
    header('Content-Type: application/json'); echo json_encode(['logs' => $logs]); exit;
}

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ticket) {
    $action = trim($_POST['action'] ?? 'update');

    if ($action === 'reply') {
        $message    = trim($_POST['message'] ?? '');
        $senderId   = $_SESSION['staff_id']   ?? 0;
        $senderName = $_SESSION['staff_name'] ?? 'Unknown';
        $senderRole = 'staff';
        $replyAttachPath = null;
        if (!empty($_FILES['reply_attachment']['name'])) {
            $uploadDir = __DIR__ . '/../../uploads/replies/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $ext = strtolower(pathinfo($_FILES['reply_attachment']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','gif','pdf','doc','docx','txt'];
            if (in_array($ext, $allowed) && $_FILES['reply_attachment']['size'] <= 5*1024*1024) {
                $filename = $ticketId.'_reply_'.time().'.'.$ext;
                $replyAttachPath = 'uploads/replies/'.$filename;
                move_uploaded_file($_FILES['reply_attachment']['tmp_name'], $uploadDir.$filename);
            }
        }
        if (!empty($message) || !empty($replyAttachPath)) {
            $ins = $conn->prepare("INSERT INTO ticket_replies (ticket_id,sender_id,sender_name,sender_role,message,attachment_path) VALUES (?,?,?,?,?,?)");
            $ins->bind_param("sissss",$ticketId,$senderId,$senderName,$senderRole,$message,$replyAttachPath);
            if ($ins->execute()) {
                $_SESSION['flash_success'] = 'Reply sent.';
                $countQ = $conn->prepare("SELECT COUNT(*) FROM ticket_replies WHERE ticket_id=? AND sender_role='staff'");
                $countQ->bind_param("s", $ticketId); $countQ->execute();
                $countQ->bind_result($staffReplyCount); $countQ->fetch(); $countQ->close();
                if ($staffReplyCount === 1) {
                    $convLog = $conn->prepare("INSERT INTO ticket_logs (ticket_id,changed_by_id,changed_by,field_changed,old_status,new_status) VALUES (?,?,?,'conversation',?,?)");
                    if ($convLog) {
                        $clId   = (int)($_SESSION['staff_id'] ?? 0);
                        $clName = $_SESSION['staff_name'] ?? 'Unknown';
                        $curSt  = $ticket['status'];
                        $convLog->bind_param("sisss", $ticketId, $clId, $clName, $curSt, $curSt);
                        $convLog->execute(); $convLog->close();
                    }
                }
            } else {
                $_SESSION['flash_error'] = 'Failed to send reply.';
            }
            $ins->close();
        }
        header('Location: ticket_detail.php?id='.urlencode($ticketId).'&tab=conversation&from='.$backUrlEncoded); exit;
    }

    if ($action === 'reassign') {
        $newStaffId = (int)($_POST['new_staff_id'] ?? 0);
        if ($newStaffId > 0) {
            $oldAssigned = getAssignedStaff($conn, $ticketId);
            $oldName = $oldAssigned ? $oldAssigned['full_name'] : 'Unassigned';
            $nsQ = $conn->prepare("SELECT full_name FROM staff WHERE staff_id=? LIMIT 1");
            $nsQ->bind_param("i", $newStaffId); $nsQ->execute();
            $nsRow = $nsQ->get_result()->fetch_assoc(); $nsQ->close();
            $newName = $nsRow['full_name'] ?? "Staff #$newStaffId";
            manualAssignTicket($conn, $deptId, $ticketId, $newStaffId);
            $asnLog = $conn->prepare("INSERT INTO ticket_logs (ticket_id,changed_by_id,changed_by,field_changed,old_priority,new_priority) VALUES (?,?,?,'assigned',?,?)");
            if ($asnLog) {
                $alId   = (int)$_SESSION['staff_id'];
                $alName = $_SESSION['staff_name'];
                $asnLog->bind_param("sisss", $ticketId, $alId, $alName, $oldName, $newName);
                $asnLog->execute(); $asnLog->close();
            }
            $_SESSION['flash_success'] = 'Ticket reassigned to <strong>'.htmlspecialchars($newName).'</strong>.';
        } else {
            $_SESSION['flash_error'] = 'Please select a staff member to assign.';
        }
        header('Location: ticket_detail.php?id='.urlencode($ticketId).'&tab=detail&from='.$backUrlEncoded); exit;
    }

    if ($action === 'update') {
        $assignedNow   = getAssignedStaff($conn, $ticketId);
        $isAssignedNow = ($assignedNow && (int)$assignedNow['staff_id'] === (int)($_SESSION['staff_id'] ?? 0));
        if (!$isAssignedNow) {
            $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH'])==='xmlhttprequest';
            if ($isAjax) { header('Content-Type: application/json'); http_response_code(403); echo json_encode(['success'=>false,'error'=>'not_assigned']); exit; }
            $_SESSION['flash_error'] = 'Only the assigned staff can change priority or status.';
            header('Location: ticket_detail.php?id='.urlencode($ticketId).'&tab=detail&from='.$backUrlEncoded); exit;
        }

        $newPriority = trim($_POST['priority'] ?? '');
        $newStatus   = trim($_POST['status']   ?? '');
        $allowedPri  = ['low','medium','high'];
        $allowedSta  = ['open','in_progress','closed'];

        if (!in_array($newPriority,$allowedPri)) { $_SESSION['flash_error']='Invalid priority.'; }
        elseif (!in_array($newStatus,$allowedSta)) { $_SESSION['flash_error']='Invalid status.'; }
        else {
            $freshStmt = $conn->prepare("SELECT priority, status, sla_start_at FROM complaints WHERE ticket_id = ? AND dept_id = ? LIMIT 1");
            $freshStmt->bind_param("si",$ticketId,$deptId); $freshStmt->execute();
            $freshRow = $freshStmt->get_result()->fetch_assoc(); $freshStmt->close();
            $oldPriority   = $freshRow['priority']     ?? $ticket['priority'];
            $oldStatus     = $freshRow['status']       ?? $ticket['status'];
            $oldSlaStartAt = $freshRow['sla_start_at'] ?? null;

            $slaResetNeeded = (strtolower($oldStatus) === 'closed' && strtolower($newStatus) !== 'closed');
            if (!$slaResetNeeded && strtolower($newStatus) !== 'closed') {
                $slaAge = time() - strtotime($oldSlaStartAt ?? $ticket['created_at']);
                if ($slaAge > (8 * 3600)) { $slaResetNeeded = true; }
            }
            $nowMysql = (new DateTime('now', new DateTimeZone(SLA_TZ)))->format('Y-m-d H:i:s');

            if ($slaResetNeeded) {
                $upd = $conn->prepare("UPDATE complaints SET priority=?,status=?,sla_start_at=?,resolved_at=NULL,updated_at=NOW() WHERE ticket_id=? AND dept_id=?");
                $upd->bind_param("ssssi", $newPriority, $newStatus, $nowMysql, $ticketId, $deptId);
            } else {
                if (strtolower($newStatus) === 'closed' && strtolower($oldStatus) !== 'closed') {
                    $upd = $conn->prepare("UPDATE complaints SET priority=?,status=?,resolved_at=NOW(),updated_at=NOW() WHERE ticket_id=? AND dept_id=?");
                    $upd->bind_param("sssi", $newPriority, $newStatus, $ticketId, $deptId);
                } else {
                    $upd = $conn->prepare("UPDATE complaints SET priority=?,status=?,updated_at=NOW() WHERE ticket_id=? AND dept_id=?");
                    $upd->bind_param("sssi", $newPriority, $newStatus, $ticketId, $deptId);
                }
            }

            if ($upd->execute()) {
                $priChanged  = ($oldPriority !== $newPriority);
                $statChanged = ($oldStatus   !== $newStatus);
                if ($priChanged || $statChanged) {
                    $fc = ($priChanged && $statChanged) ? 'both' : ($priChanged ? 'priority' : 'status');
                    $logStaffId   = (int)$staffId;
                    $logStaffName = $staffName;
                    $logStmt = $conn->prepare("INSERT INTO ticket_logs (ticket_id,changed_by_id,changed_by,field_changed,old_priority,new_priority,old_status,new_status) VALUES (?,?,?,?,?,?,?,?)");
                    if ($logStmt) {
                        $logStmt->bind_param("sissssss",$ticketId,$logStaffId,$logStaffName,$fc,$oldPriority,$newPriority,$oldStatus,$newStatus);
                        $logStmt->execute(); $logStmt->close();
                    }
                }
                $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH'])==='xmlhttprequest';
                if ($isAjax) {
                    $extraData = [];
                    if ($slaResetNeeded) { $extraData['sla_reset'] = true; $extraData['sla_start_at'] = $nowMysql; }
                    header('Content-Type: application/json');
                    echo json_encode(array_merge(['success'=>true], $extraData)); exit;
                }
                $statusLabel = ucfirst(str_replace('_',' ',$newStatus));
                $slaNote     = $slaResetNeeded ? ' <strong>SLA reset — fresh 8-hour window started.</strong>' : '';
                $_SESSION['flash_success'] = 'Ticket updated — status: <strong>'.htmlspecialchars($statusLabel).'</strong>.'.$slaNote;
            } else {
                $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH'])==='xmlhttprequest';
                if ($isAjax) { header('Content-Type: application/json'); http_response_code(500); echo json_encode(['success'=>false]); exit; }
                $_SESSION['flash_error'] = 'Failed to update.';
            }
            $upd->close();
        }
        header('Location: ticket_detail.php?id='.urlencode($ticketId).'&tab=detail&from='.$backUrlEncoded); exit;
    }
}

// ── Flash messages ────────────────────────────────────────────────────────────
$updateMsg   = $_SESSION['flash_success'] ?? '';
$updateError = $_SESSION['flash_error']   ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// ── Re-fetch ticket after POST redirect ───────────────────────────────────────
if ($ticketId !== '') {
    $stmt = $conn->prepare("SELECT c.*, cat.category_name FROM complaints c LEFT JOIN categories cat ON cat.category_id = c.category_id WHERE c.ticket_id = ? AND c.dept_id = ? LIMIT 1");
    $stmt->bind_param("si", $ticketId, $deptId); $stmt->execute();
    $ticket = $stmt->get_result()->fetch_assoc(); $stmt->close();
}

// ── Fetch submitter ───────────────────────────────────────────────────────────
$submitter = null;
if ($ticket) {
    $type  = $ticket['submitter_type'] ?? 'student';
    $table = $type==='student' ? 'students' : 'staff';
    $pkCol = $type==='student' ? 'student_id' : 'staff_id';
    $s2 = $conn->prepare("SELECT full_name AS name, email FROM {$table} WHERE {$pkCol}=? LIMIT 1");
    if ($s2) { $s2->bind_param("i",$ticket['submitter_id']); $s2->execute(); $submitter=$s2->get_result()->fetch_assoc(); $s2->close(); }
}

// ── Fetch change logs ─────────────────────────────────────────────────────────
$changeLogs = [];
if ($ticket) {
    $lg = $conn->prepare("SELECT log_id,changed_by,field_changed,old_priority,new_priority,old_status,new_status,changed_at FROM ticket_logs WHERE ticket_id=? ORDER BY changed_at DESC");
    $lg->bind_param("s",$ticketId); $lg->execute();
    $changeLogs = $lg->get_result()->fetch_all(MYSQLI_ASSOC); $lg->close();
}

// ── Fetch assigned staff ──────────────────────────────────────────────────────
$assignedStaff = null;
if ($ticket) { $assignedStaff = getAssignedStaff($conn, $ticketId); }

$currentStaffId  = (int)($_SESSION['staff_id'] ?? 0);
$isAssignedStaff = ($assignedStaff && (int)$assignedStaff['staff_id'] === $currentStaffId);

// ── Dept staff list ───────────────────────────────────────────────────────────
$deptStaffList = [];
$dsStmt = $conn->prepare("SELECT staff_id, full_name FROM staff WHERE dept_id = ? AND status = 'active' AND role = 'staff' ORDER BY staff_id ASC");
$dsStmt->bind_param("i", $deptId); $dsStmt->execute();
$dsRes = $dsStmt->get_result();
while ($row = $dsRes->fetch_assoc()) $deptStaffList[] = $row;
$dsStmt->close();

// ── Fetch replies ─────────────────────────────────────────────────────────────
$replies = [];
if ($ticket) {
    $rq = $conn->prepare("SELECT reply_id,sender_id,sender_name,sender_role,message,attachment_path,created_at FROM ticket_replies WHERE ticket_id=? ORDER BY created_at ASC");
    $rq->bind_param("s",$ticketId); $rq->execute();
    $replies = $rq->get_result()->fetch_all(MYSQLI_ASSOC); $rq->close();
}

// ── Fetch feedback ────────────────────────────────────────────────────────────
$feedback = null;
if ($ticket && strtolower($ticket['status']) === 'closed') {
    $fq = $conn->prepare("SELECT tf.rating, tf.comment, tf.is_auto_submitted, tf.created_at, s.full_name AS student_name FROM ticket_feedback tf LEFT JOIN students s ON s.student_id = tf.student_id WHERE tf.ticket_id = ? LIMIT 1");
    $fq->bind_param("s", $ticketId); $fq->execute();
    $feedback = $fq->get_result()->fetch_assoc(); $fq->close();
}

// ── SLA data ──────────────────────────────────────────────────────────────────
$slaData = null;
if ($ticket && !empty($ticket['sla_start_at'])) {
    $slaData = getSlaStatus($ticket['sla_start_at'], $ticket['resolved_at'] ?? null, $ticket['status']);
}

// ── Active tab ────────────────────────────────────────────────────────────────
$activeTab = $_GET['tab'] ?? 'detail';
if (!in_array($activeTab, ['detail','conversation','history','feedback'])) $activeTab = 'detail';

$isClosed    = $ticket && strtolower($ticket['status']) === 'closed';
$hasFeedback = $feedback !== null;

// ── Helper functions ──────────────────────────────────────────────────────────
function statusBadge(string $s): string {
    $map=['open'=>['#FEF3C7','#D97706'],'in_progress'=>['#DBEAFE','#1D4ED8'],'closed'=>['#D1FAE5','#059669']];
    [$bg,$fg]=$map[strtolower($s)]??['#F3F4F6','#6B7280'];
    $label=$s==='in_progress'?'In Progress':ucfirst($s);
    return "<span style=\"display:inline-block;font-size:12px;font-weight:600;padding:3px 12px;border-radius:20px;background:{$bg};color:{$fg}\">".htmlspecialchars($label)."</span>";
}
function priFlag(string $v): string {
    $map=['low'=>['#3B82F6','Low'],'medium'=>['#F59E0B','Medium'],'high'=>['#EF4444','High']];
    [$color,$label]=$map[strtolower($v)]??['#6B7280',ucfirst($v)];
    $svg='<svg width="13" height="13" viewBox="0 0 24 24" fill="'.$color.'" xmlns="http://www.w3.org/2000/svg" style="flex-shrink:0"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15" stroke="'.$color.'" stroke-width="2" stroke-linecap="round"/></svg>';
    return '<span style="display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:600;color:'.$color.';">'.$svg.htmlspecialchars($label).'</span>';
}
function priChip(string $v): string {
    $map=['low'=>['#3B82F6','Low'],'medium'=>['#F59E0B','Medium'],'high'=>['#EF4444','High']];
    [$color,$label]=$map[strtolower($v)]??['#6B7280',ucfirst($v)];
    $svg='<svg width="10" height="10" viewBox="0 0 24 24" fill="'.$color.'" style="flex-shrink:0"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15" stroke="'.$color.'" stroke-width="2" stroke-linecap="round"/></svg>';
    return '<span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;color:'.$color.';">'.$svg.htmlspecialchars($label).'</span>';
}
function statChip(string $v): string {
    $map=['open'=>['#FEF3C7','#D97706'],'in_progress'=>['#DBEAFE','#1D4ED8'],'closed'=>['#D1FAE5','#059669']];
    [$bg,$fg]=$map[strtolower($v)]??['#F3F4F6','#6B7280'];
    $label=$v==='in_progress'?'In Progress':ucfirst($v);
    return "<span style=\"display:inline-block;font-size:11px;font-weight:600;padding:2px 9px;border-radius:20px;background:{$bg};color:{$fg}\">".htmlspecialchars($label)."</span>";
}
function timeAgo(string $datetime): string {
    $now=new DateTime('now',new DateTimeZone('Asia/Kuala_Lumpur'));
    $past=new DateTime($datetime,new DateTimeZone('Asia/Kuala_Lumpur'));
    $diff=$now->getTimestamp()-$past->getTimestamp();
    if($diff<60)return 'just now';
    if($diff<3600)return floor($diff/60).' min ago';
    if($diff<86400)return floor($diff/3600).' hr ago';
    if($diff<604800){$d=floor($diff/86400);return $d.' day'.($d>1?'s':'').' ago';}
    return date('d M Y',$past->getTimestamp());
}
function getInitials(string $name): string {
    $parts=explode(' ',trim($name));
    $ini=strtoupper(substr($parts[0],0,1));
    if(count($parts)>1)$ini.=strtoupper(substr($parts[count($parts)-1],0,1));
    return $ini;
}
function isImageFile(string $path): bool {
    return in_array(strtolower(pathinfo($path,PATHINFO_EXTENSION)),['jpg','jpeg','png','gif','webp']);
}
function fileTypeIcon(string $path): array {
    $ext=strtolower(pathinfo($path,PATHINFO_EXTENSION));
    return match($ext){'pdf'=>['label'=>'PDF','color'=>'#DC2626','bg'=>'#FEF2F2'],'doc','docx'=>['label'=>'DOC','color'=>'#1D4ED8','bg'=>'#EFF6FF'],'txt'=>['label'=>'TXT','color'=>'#374151','bg'=>'#F9FAFB'],default=>['label'=>strtoupper($ext),'color'=>'#6B7280','bg'=>'#F3F4F6']};
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
    return match($rating) { 1=>['#FEF2F2','#DC2626','#EF4444'], 2=>['#FFF7ED','#C2410C','#F97316'], 3=>['#FEFCE8','#854D0E','#EAB308'], 4=>['#F0FDF4','#166534','#22C55E'], 5=>['#ECFDF5','#166534','#16A34A'], default=>['#F3F4F6','#374151','#6B7280'] };
}

$activeNav    = 'tickets';
$pageTitle    = 'Ticket Detail';
$pageSubtitle = 'Corporate Communication Unit';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Ticket Detail | UniKL Help Desk – CCU</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../it/css/ticket-details.css">
</head>
<body>
<?php require_once __DIR__ . '/_layout.php'; ?>

  <!-- Breadcrumb -->
  <div class="td-breadcrumb">
    <a href="<?php echo htmlspecialchars($backUrl); ?>">All Tickets</a>
    <span class="td-breadcrumb-sep">›</span>
    <span><?php echo htmlspecialchars($ticketId ?: 'Detail'); ?></span>
  </div>

  <!-- Back button -->
  <a href="<?php echo htmlspecialchars($backUrl); ?>" class="td-back-btn">
    <svg viewBox="0 0 24 24"><polyline points="15,18 9,12 15,6"/></svg>
    Back to All Tickets
  </a>

  <?php if (!$ticket): ?>
  <div class="not-found">
    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <h2>Ticket Not Found</h2>
    <p>Ticket <strong><?php echo htmlspecialchars($ticketId); ?></strong> does not exist or does not belong to this department.</p>
    <a href="<?php echo htmlspecialchars($backUrl); ?>" class="nf-back"><svg viewBox="0 0 24 24"><polyline points="15,18 9,12 15,6"/></svg>Back to All Tickets</a>
  </div>
  <?php else: ?>

  <!-- Flash messages -->
  <?php if ($updateMsg): ?>
  <div class="td-alert td-alert-success"><svg viewBox="0 0 24 24"><polyline points="20,6 9,17 4,12"/></svg><span><?php echo $updateMsg; ?></span></div>
  <?php endif; ?>
  <?php if ($updateError): ?>
  <div class="td-alert td-alert-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><span><?php echo htmlspecialchars($updateError); ?></span></div>
  <?php endif; ?>

  <!-- Ticket header strip (always visible above tabs) -->
<div class="ticket-header-strip">
  <div class="ths-icon">
    <svg viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
  </div>
  <div class="ths-info">
    <div class="ths-title"><?php echo htmlspecialchars($ticket['title']); ?></div>
    <?php if (!empty($ticket['description'])): ?>
    <div class="ths-desc"><?php echo htmlspecialchars($ticket['description']); ?></div>
    <?php endif; ?>
    <div class="ths-bottom-row">
      <div class="ths-badges">
    <?php echo statusBadge($ticket['status']); ?>
    <span id="thsPriorityFlag"><?php echo priFlag($ticket['priority'] ?? 'medium'); ?></span>
</div>
    </div>
  </div>
  <div class="ths-id-badge"><?php echo htmlspecialchars($ticket['ticket_id']); ?></div>
</div>
 <!-- ══ TAB BAR ══ -->
  <div class="td-tab-bar" role="tablist">

    <!-- Tab 1: Detail -->
    <a href="?id=<?php echo urlencode($ticketId); ?>&tab=detail&from=<?php echo $backUrlEncoded; ?>"
       class="td-tab-btn <?php echo $activeTab==='detail'?'active':''; ?>"
       role="tab">
      <svg viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
      Detail
    </a>

    <!-- Tab 2: Conversation -->
    <a href="?id=<?php echo urlencode($ticketId); ?>&tab=conversation&from=<?php echo $backUrlEncoded; ?>"
       class="td-tab-btn <?php echo $activeTab==='conversation'?'active':''; ?>"
       role="tab">
      <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
      Conversation
      <?php if (count($replies) > 0): ?>
      <span class="td-tab-badge"><?php echo count($replies); ?></span>
      <?php endif; ?>
    </a>

    <!-- Tab 3: History -->
    <a href="?id=<?php echo urlencode($ticketId); ?>&tab=history&from=<?php echo $backUrlEncoded; ?>"
       class="td-tab-btn <?php echo $activeTab==='history'?'active':''; ?>"
       role="tab">
      <svg viewBox="0 0 24 24"><polyline points="12,8 12,12 14,14"/><path d="M3.05 11a9 9 0 1 1 .5 4"/></svg>
      History
      <?php if (count($changeLogs) > 0): ?>
      <span class="td-tab-badge"><?php echo count($changeLogs); ?></span>
      <?php endif; ?>
    </a>

    <!-- Tab 4: Feedback -->
    <?php if (!$isClosed): ?>
    <span class="td-tab-btn disabled" title="Ticket must be closed for feedback">
      <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      Feedback
    </span>
    <?php else: ?>
    <a href="?id=<?php echo urlencode($ticketId); ?>&tab=feedback&from=<?php echo $backUrlEncoded; ?>"
       class="td-tab-btn <?php echo $activeTab==='feedback'?'active':''; ?>"
       role="tab">
      <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
      Feedback
      <?php if ($hasFeedback): ?>
      <span class="td-tab-dot"></span>
      <?php endif; ?>
    </a>
    <?php endif; ?>

  </div><!-- /.td-tab-bar -->


  <!-- ══════════════════════════════════════════════════
       TAB 1: DETAIL
  ══════════════════════════════════════════════════ -->
  <div class="td-panel <?php echo $activeTab==='detail'?'active':''; ?>">
    <div class="detail-tab-grid">

      <!-- LEFT: Ticket info -->
      <div class="detail-left">

        <!-- Ticket Info Card -->
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

            <!-- Meta grid -->
            <div class="ti-meta-grid">
              <div>
                <div class="ti-meta-label">Category</div>
                <div class="ti-meta-value"><?php echo htmlspecialchars($ticket['category_name']??'—'); ?></div>
              </div>
              <div>
                <div class="ti-meta-label">From Department</div>
                <div class="ti-meta-value"><?php echo htmlspecialchars($ticket['my_department']??'—'); ?></div>
              </div>
              <div>
                <div class="ti-meta-label">Priority</div>
                <div class="ti-meta-value" id="ticketPriorityChip"><?php echo priFlag($ticket['priority']??'medium'); ?></div>
              </div>
              <div>
                <div class="ti-meta-label">Submitted</div>
                <div class="ti-meta-value"><?php echo date('d M Y, H:i',strtotime($ticket['created_at'])); ?></div>
              </div>
              <div>
                <div class="ti-meta-label">Last Updated</div>
                <div class="ti-meta-value"><?php echo date('d M Y, H:i',strtotime($ticket['updated_at'])); ?></div>
              </div>
              <?php if(!empty($ticket['sla_start_at'])): ?>
              <div>
                <div class="ti-meta-label">SLA Started</div>
                <div class="ti-meta-value"><?php echo date('d M Y, H:i',strtotime($ticket['sla_start_at'])); ?></div>
              </div>
              <?php endif; ?>
            </div>

            <!-- Description -->
            <div class="ti-desc-label">Description</div>
            <div class="ti-desc-box"><?php echo htmlspecialchars($ticket['description']); ?></div>
            <?php if(!empty($ticket['attachment_path'])): ?>
            <a class="ti-attach-link" href="../../<?php echo htmlspecialchars($ticket['attachment_path']); ?>" target="_blank">
              <svg viewBox="0 0 24 24"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
              View Attachment
            </a>
            <?php endif; ?>

            <div class="ti-divider"></div>

            <!-- Submitted by -->
            <div class="ti-section-label">
              <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              Submitted By
            </div>
            <div class="ti-submitter-grid">
              <div class="ti-submitter-cell">
                <div class="ti-submitter-lbl">Name</div>
                <div class="ti-submitter-val"><?php echo htmlspecialchars($submitter['name']??'—'); ?></div>
              </div>
              <div class="ti-submitter-cell">
                <div class="ti-submitter-lbl">Email</div>
                <div class="ti-submitter-val"><?php echo htmlspecialchars($submitter['email']??'—'); ?></div>
              </div>
              <div class="ti-submitter-cell">
                <div class="ti-submitter-lbl">Phone</div>
                <div class="ti-submitter-val">+60 <?php echo htmlspecialchars($ticket['phone']??'—'); ?></div>
              </div>
              <div class="ti-submitter-cell" style="border-right:none">
                <div class="ti-submitter-lbl">Type</div>
                <div class="ti-submitter-val" style="text-transform:capitalize"><?php echo htmlspecialchars($ticket['submitter_type']??'—'); ?></div>
              </div>
            </div>

          </div>
        </div>

        <!-- SLA Status (moved from sidebar) -->
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
                <div class="sla-status-chip" style="background:<?php echo $slaData['status_bg']; ?>;color:<?php echo $slaData['status_color']; ?>">
                  <?php echo htmlspecialchars($slaData['status_label']); ?>
                </div>
                <?php if (strtolower($ticket['status']) !== 'closed'): ?>
                <div class="sla-remaining-big" style="color:<?php echo $slaData['status_color']; ?>">
                  <?php echo htmlspecialchars($slaData['remaining_str']); ?>
                </div>
                <div class="sla-remaining-sub">until SLA deadline</div>
                <?php endif; ?>
              </div>
              <div class="sla-inline-right">
                <?php $fillPct = min($slaData['percent_used'], 100); ?>
                <div class="sla-progress-wrap">
                  <div class="sla-progress-fill" style="width:<?php echo $fillPct; ?>%;background:<?php echo $slaData['status_color']; ?>"></div>
                </div>
                <div class="sla-tick-row"><span>0h</span><span>4h</span><span>8h</span></div>
                <div class="sla-info-grid" style="grid-template-columns: repeat(4,1fr);">
                  <div>
                    <div class="sla-info-label">SLA Started</div>
                    <div class="sla-info-value"><?php echo date('d M, H:i',strtotime($ticket['sla_start_at'])); ?></div>
                  </div>
                  <div>
                    <div class="sla-info-label">Deadline</div>
                    <div class="sla-info-value"><?php echo $slaData['deadline_str']; ?></div>
                  </div>
                  <div>
                    <div class="sla-info-label">Time Used</div>
                    <div class="sla-info-value">
                      <?php $em=$slaData['elapsed_mins']; $eh=intdiv($em,60); $emm=$em%60; echo $eh>0?"{$eh}h {$emm}m":"{$emm}m"; ?> / <?php echo SLA_WORK_HOURS; ?>h
                    </div>
                  </div>
                  <div>
                    <div class="sla-info-label"><?php echo strtolower($ticket['status'])==='closed'?'Closed At':'Updated'; ?></div>
                    <div class="sla-info-value">
                      <?php if(strtolower($ticket['status'])==='closed'&&!empty($ticket['resolved_at'])): ?>
                        <?php echo date('d M, H:i',strtotime($ticket['resolved_at'])); ?>
                      <?php else: ?>
                        <?php echo date('d M, H:i',strtotime($ticket['updated_at'])); ?>
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
              <div><strong>SLA was reset</strong> when this ticket was reopened. Fresh 8-hour window started <?php echo date('d M Y, H:i',$slaStartTs); ?>.</div>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

      </div><!-- /.detail-left -->

      <!-- RIGHT: Sidebar cards -->
      <div class="detail-right">

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
              <div class="assigned-avatar"><?php echo getInitials($assignedStaff['full_name']); ?></div>
              <div style="flex:1;min-width:0;">
                <div class="assigned-name"><?php echo htmlspecialchars($assignedStaff['full_name']); ?></div>
                <div class="assigned-role-tag">CCU Staff</div>
              </div>
            </div>
            <?php else: ?>
            <div class="unassigned-pill">⚠️ No staff assigned yet.</div>
            <?php endif; ?>

            <?php if (!empty($deptStaffList)): ?>
            <form method="POST" action="ticket_detail.php?id=<?php echo urlencode($ticketId); ?>">
              <input type="hidden" name="action" value="reassign"/>
              <div class="reassign-label">Reassign to</div>
              <div class="reassign-row">
                <select name="new_staff_id" class="reassign-select" required>
                  <option value="">— Select staff —</option>
                  <?php foreach ($deptStaffList as $s): ?>
                  <option value="<?php echo $s['staff_id']; ?>"
                    <?php echo (($assignedStaff['staff_id'] ?? 0) == $s['staff_id']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($s['full_name']); ?>
                  </option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="reassign-btn">Save</button>
              </div>
            </form>
            <?php endif; ?>
          </div>
        </div>

       

        <!-- Update Ticket -->
        <div class="td-card">
          <div class="td-card-header">
            <div class="td-card-header-icon update-header-icon">
              <svg viewBox="0 0 24 24"><polyline points="23,4 23,10 17,10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
            </div>
            <div>
              <div class="td-card-header-title">Update Ticket</div>
              <div class="td-card-header-sub"><?php echo $isAssignedStaff ? 'Change priority &amp; status' : 'View only — not assigned'; ?></div>
            </div>
          </div>
          <div class="td-card-body">
            <?php $curPri=strtolower($ticket['priority']??'medium'); $curStat=strtolower($ticket['status']??'open'); ?>

            <?php if ($isAssignedStaff): ?>
              <div class="pri-label-sm">Priority <span id="priSavingSpinner" style="display:none;font-size:10px;color:#9CA3AF;font-weight:400;margin-left:3px">saving…</span></div>
              <div class="pri-btn-group">
                <?php foreach(['low'=>'Low','medium'=>'Medium','high'=>'High'] as $val=>$label): ?>
                <button type="button" class="pri-btn <?php echo $curPri===$val?'active':''; ?>" data-pri="<?php echo $val; ?>" onclick="selectPriorityAutoSave('<?php echo $val; ?>',this)">
                  <span class="pri-dot"></span><?php echo $label; ?>
                </button>
                <?php endforeach; ?>
              </div>
              <div class="form-divider"></div>
              <form method="POST" action="ticket_detail.php?id=<?php echo urlencode($ticketId); ?>" id="updateForm">
                <input type="hidden" name="action" value="update"/>
                <input type="hidden" name="priority" id="priorityInput" value="<?php echo htmlspecialchars($curPri); ?>"/>
                <div class="update-status-label">Status</div>
                <select name="status" id="status" required class="update-status-select">
                  <option value="open"        <?php echo $curStat==='open'       ?'selected':''; ?>>Open</option>
                  <option value="in_progress" <?php echo $curStat==='in_progress'?'selected':''; ?>>In Progress</option>
                  <option value="closed"      <?php echo $curStat==='closed'     ?'selected':''; ?>>Closed</option>
                </select>
                <div id="slaResetWarning" class="sla-warning-box">
                  <svg viewBox="0 0 24 24"><polyline points="23,4 23,10 17,10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                  <span>Reopening this ticket will <strong>reset the SLA</strong> — a fresh 8-hour window starts from now.</span>
                </div>
                <button type="button" class="btn-update-save" onclick="openConfirmModal()">Save Changes</button>
              </form>

            <?php else: ?>
              <div class="no-permission-box">
                <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <div>
                  <div class="no-permission-title">No permission</div>
                  <div class="no-permission-desc">Only <strong><?php echo htmlspecialchars($assignedStaff['full_name'] ?? 'the assigned staff'); ?></strong> can change priority or status.</div>
                </div>
              </div>
              <div class="pri-label-sm">Priority</div>
              <div class="pri-btn-group" style="pointer-events:none;opacity:0.45;">
                <?php foreach(['low'=>'Low','medium'=>'Medium','high'=>'High'] as $val=>$label): ?>
                <button type="button" class="pri-btn <?php echo $curPri===$val?'active':''; ?>" disabled>
                  <span class="pri-dot"></span><?php echo $label; ?>
                </button>
                <?php endforeach; ?>
              </div>
              <div class="form-divider"></div>
              <div class="update-status-label">Status</div>
              <div style="padding:9px 11px;border:1.5px solid #E5E7EB;border-radius:7px;font-size:13px;color:#9CA3AF;background:#F9FAFB;">
                <?php echo $curStat==='in_progress'?'In Progress':ucfirst($curStat); ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

      </div><!-- /.detail-right -->
    </div><!-- /.detail-tab-grid -->
  </div><!-- /.td-panel detail -->


  <!-- ══════════════════════════════════════════════════
       TAB 2: CONVERSATION
  ══════════════════════════════════════════════════ -->
  <div class="td-panel <?php echo $activeTab==='conversation'?'active':''; ?>">
    <div class="conv-card">
      <div class="conv-header">
        <div class="conv-header-icon">
          <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        </div>
        <div>
          <div class="conv-header-title">Complaint Chat</div>
          <div class="conv-header-sub">Reply to <?php echo htmlspecialchars($submitter['name']??'the user'); ?></div>
        </div>
        <?php if (count($replies) > 0): ?><div class="conv-badge"><?php echo count($replies); ?></div><?php endif; ?>
      </div>

      <div class="conv-messages" id="chatMessages">
        <?php if (empty($replies)): ?>
        <div class="conv-empty">
          <div class="conv-empty-icon"><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div>
          <div><strong style="display:block;font-size:13.5px;font-weight:600;color:#374151;margin-bottom:3px;">No messages yet</strong>
          <p style="font-size:12.5px;color:#9CA3AF;margin:0;max-width:200px;">Start the conversation by sending a reply below.</p></div>
        </div>
        <?php else:
          $prevDate='';
          foreach ($replies as $r):
            $msgDate  = date('d M Y',strtotime($r['created_at']));
            $isStaff  = in_array($r['sender_role'],['staff','dept_handler','admin']);
            $rowClass = $isStaff?'from-staff':'from-user';
            $avClass  = $isStaff?'staff-av':'user-av';
            $initials = getInitials($r['sender_name']);
            $hasAttach= !empty($r['attachment_path']);
            $attachPath=$hasAttach?$r['attachment_path']:'';
            $isImg    = $hasAttach&&isImageFile($attachPath);
            $fileName = $hasAttach?basename($attachPath):'';
            $fileIcon = ($hasAttach&&!$isImg)?fileTypeIcon($attachPath):[];
        ?>
          <?php if($msgDate!==$prevDate):$prevDate=$msgDate;?><div class="date-sep"><span><?php echo $msgDate===date('d M Y')?'Today':$msgDate; ?></span></div><?php endif; ?>
          <div class="msg-row <?php echo $rowClass; ?>">
            <div class="msg-avatar <?php echo $avClass; ?>"><?php echo htmlspecialchars($initials); ?></div>
            <div class="msg-content">
              <div class="msg-meta"><span class="msg-sender"><?php echo htmlspecialchars($r['sender_name']); ?></span>· <?php echo date('H:i',strtotime($r['created_at'])); ?></div>
              <?php if($isImg): ?>
                <a href="../../<?php echo htmlspecialchars($attachPath); ?>" class="msg-img-bubble" onclick="openLightbox(this.href,'<?php echo htmlspecialchars(addslashes($fileName)); ?>');return false;">
                  <img src="../../<?php echo htmlspecialchars($attachPath); ?>" alt="<?php echo htmlspecialchars($fileName); ?>" loading="lazy"/>
                </a>
                <?php if(!empty($r['message'])): ?><div class="msg-bubble"><?php echo nl2br(htmlspecialchars($r['message'])); ?></div><?php endif; ?>
              <?php elseif($hasAttach): ?>
                <?php if(!empty($r['message'])): ?><div class="msg-bubble"><?php echo nl2br(htmlspecialchars($r['message'])); ?></div><?php endif; ?>
                <a href="../../<?php echo htmlspecialchars($attachPath); ?>" class="msg-file-card" target="_blank">
                  <div class="msg-file-icon" style="<?php if(!$isStaff):echo 'background:'.htmlspecialchars($fileIcon['bg']).';color:'.htmlspecialchars($fileIcon['color']).';';endif;?>"><?php echo htmlspecialchars($fileIcon['label']??'?'); ?></div>
                  <div class="msg-file-info">
                    <span class="msg-file-name"><?php echo htmlspecialchars($fileName); ?></span>
                    <span class="msg-file-meta"><?php echo strtoupper(pathinfo($attachPath,PATHINFO_EXTENSION)); ?> file</span>
                  </div>
                  <svg class="msg-file-dl-icon" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                </a>
              <?php else: ?>
                <?php if(!empty($r['message'])): ?><div class="msg-bubble"><?php echo nl2br(htmlspecialchars($r['message'])); ?></div><?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <?php if ($ticket['status'] !== 'closed'): ?>
      <div class="conv-input-area">
        <form method="POST" action="ticket_detail.php?id=<?php echo urlencode($ticketId); ?>" enctype="multipart/form-data" id="replyForm">
          <input type="hidden" name="action" value="reply"/>
          <div class="conv-input-row">
            <label class="conv-attach-label" for="replyAttachment" title="Attach file">
              <svg viewBox="0 0 24 24"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
            </label>
            <input type="file" id="replyAttachment" name="reply_attachment" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt" style="display:none"/>
            <textarea class="conv-textarea" name="message" id="chatInput" placeholder="Type your reply here…" rows="1" maxlength="3000"></textarea>
            <button type="submit" class="conv-send-btn">
              <svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
            </button>
          </div>
          <div class="conv-attach-preview" id="attachFilePreview">
            <svg viewBox="0 0 24 24" style="width:11px;height:11px;fill:none;stroke:currentColor;stroke-width:2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <span id="attachFileName"></span>
            <button type="button" class="conv-attach-remove" id="attachRemove">×</button>
          </div>
          <div id="attachImgPreviewWrap"><img id="attachImgPreview" src="" alt=""/></div>
          <div class="conv-hint">
            <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            Message saved to chat thread.
          </div>
        </form>
      </div>
      <?php else: ?>
      <div class="conv-closed-bar">
        <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        This ticket is <strong style="margin:0 4px">closed</strong>. Reopen it to reply.
      </div>
      <?php endif; ?>
    </div>
  </div><!-- /.td-panel conversation -->


  <!-- ══════════════════════════════════════════════════
       TAB 3: HISTORY
  ══════════════════════════════════════════════════ -->
  <div class="td-panel <?php echo $activeTab==='history'?'active':''; ?>">
    <div class="history-card">
      <div class="td-card-header" style="padding:14px 20px;border-bottom:1px solid #F3F4F6;">
  <div class="td-card-header-icon" style="background:#F5F3FF;">
    <svg viewBox="0 0 24 24" style="width:16px;height:16px;fill:none;stroke:#7C3AED;stroke-width:1.8"><polyline points="12,8 12,12 14,14"/><path d="M3.05 11a9 9 0 1 1 .5 4"/></svg>
  </div>
  <div style="flex:1;min-width:0;">
    <div class="td-card-header-title">Change History</div>
    <div class="td-card-header-sub"><?php echo count($changeLogs); ?> change<?php echo count($changeLogs)!==1?'s':''; ?> recorded</div>
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
        <?php foreach($changeLogs as $idx=>$log):
          $fc=$log['field_changed'];
          $dotCls=match($fc){
            'priority'=>'pri',
            'status'=>'stat',
            'assigned'=>'asgn',
            'conversation'=>'conv',
            default=>'both'
          };
        ?>
        <div class="tl-item" data-log-index="<?php echo $idx; ?>" style="<?php echo $idx>=10?'display:none':''; ?>">
          <div class="tl-dot <?php echo $dotCls; ?>">
            <?php if($fc==='priority'):?>
              <svg viewBox="0 0 24 24"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>
            <?php elseif($fc==='status'):?>
              <svg viewBox="0 0 24 24"><rect x="1" y="5" width="22" height="14" rx="7" ry="7"/><circle cx="16" cy="12" r="3"/></svg>
            <?php elseif($fc==='assigned'):?>
              <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <?php elseif($fc==='conversation'):?>
              <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            <?php else:?>
              <svg viewBox="0 0 24 24"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/></svg>
            <?php endif; ?>
          </div>
          <div class="tl-body">
            <div class="tl-header">
              <span class="tl-who"><?php echo htmlspecialchars($log['changed_by']); ?></span>
              <span class="tl-when"><?php echo timeAgo($log['changed_at']); ?></span>
            </div>
            <span class="tl-when-full"><?php echo date('d M Y, H:i',strtotime($log['changed_at'])); ?></span>
            <div class="tl-changes" style="margin-top:5px">
              <?php if(in_array($fc,['priority','both'])&&$log['old_priority']&&$log['new_priority']):?>
              <div class="tl-row"><span class="tl-row-label">Priority</span><?php echo priChip($log['old_priority']); ?><span class="tl-arrow">→</span><?php echo priChip($log['new_priority']); ?></div>
              <?php endif; ?>
              <?php if(in_array($fc,['status','both'])&&$log['old_status']&&$log['new_status']):?>
              <div class="tl-row"><span class="tl-row-label">Status</span><?php echo statChip($log['old_status']); ?><span class="tl-arrow">→</span><?php echo statChip($log['new_status']); ?></div>
              <?php endif; ?>
              <?php if($fc==='assigned'): ?>
<div class="tl-row">
    <span class="tl-row-label">Assigned by</span>
    <span class="tl-chip-name" style="background:#EFF6FF;color:#1D4ED8;"><?php echo htmlspecialchars($log['changed_by']); ?></span>
</div>
<div class="tl-row" style="margin-top:4px;">
    <span class="tl-row-label">From</span>
    <span class="tl-chip-name"><?php echo htmlspecialchars($log['old_priority'] ?? 'Unassigned'); ?></span>
    <span class="tl-arrow">→</span>
    <span class="tl-chip-name tl-chip-name--new"><?php echo htmlspecialchars($log['new_priority'] ?? '—'); ?></span>
</div>
<?php endif; ?>
              <?php if($fc==='conversation'): ?>
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
  <div class="td-panel <?php echo $activeTab==='feedback'?'active':''; ?>">

    <?php if (!$isClosed): ?>
    <!-- Ticket still open — locked state -->
    <div class="feedback-locked-state">
      <div class="feedback-lock-icon">
        <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      </div>
      <div class="feedback-lock-emojis">
        <?php for($i=1;$i<=5;$i++) echo feedbackEmojiSvg($i, 22); ?>
      </div>
      <div class="feedback-lock-title">Awaiting Closure</div>
      <div class="feedback-lock-sub">Customer feedback will be available once this ticket is marked as <strong>Closed</strong>.</div>
    </div>

    <?php else: ?>
    <!-- Ticket closed -->
    <div class="feedback-card">
      <div class="feedback-card-header">
        <div class="feedback-card-header-icon">
          <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        </div>
        <div>
          <div class="feedback-card-header-title">Customer Feedback</div>
          <div class="feedback-card-header-sub">
            <?php echo $hasFeedback ? 'Submitted by '.htmlspecialchars($feedback['student_name'] ?? 'student') : 'Awaiting student feedback'; ?>
          </div>
        </div>
        <?php if ($hasFeedback): ?>
        <div class="feedback-card-header-score"><?php echo (int)$feedback['rating']; ?> / 5</div>
        <?php endif; ?>
      </div>
      <div class="feedback-card-body">
        <?php if ($hasFeedback):
          $r = (int)$feedback['rating'];
          [$chipBg, $chipFg, $chipDot] = ratingColors($r);
        ?>
          <div class="fb-compact-row">
            <div style="flex-shrink:0;line-height:0"><?php echo feedbackEmojiSvg($r, 38); ?></div>
            <div style="flex:1;min-width:0">
              <div class="fb-compact-label" style="color:<?php echo $chipFg; ?>"><?php echo ratingLabel($r); ?></div>
              <div class="fb-compact-meta">
                <?php echo htmlspecialchars($feedback['student_name'] ?? '—'); ?> &nbsp;·&nbsp;
                <?php echo date('d M Y, H:i',strtotime($feedback['created_at'])); ?>
                <?php if($feedback['is_auto_submitted']): ?>&nbsp;<span class="fb-auto-chip">Auto</span><?php endif; ?>
              </div>
            </div>
            <div class="fb-compact-score">
              <div class="fb-compact-score-num" style="color:<?php echo $chipFg; ?>"><?php echo $r; ?></div>
              <div class="fb-compact-score-denom">/ 5</div>
            </div>
          </div>
          <div class="fb-mini-faces">
            <?php for($i=1;$i<=5;$i++): ?>
            <div class="fb-mini-face<?php echo $i===$r?' active':''; ?>"><?php echo feedbackEmojiSvg($i,22); ?></div>
            <?php endfor; ?>
          </div>
          <?php if(!empty($feedback['comment'])): ?>
          <div class="fb-compact-comment" style="border-left-color:<?php echo $chipFg; ?>">
            <?php echo htmlspecialchars($feedback['comment']); ?>
          </div>
          <?php else: ?>
          <div class="fb-compact-no-comment">No comment provided.</div>
          <?php endif; ?>
          <div class="fb-compact-footer">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12,6 12,12 16,14"/></svg>
            <?php echo date('d M Y, H:i',strtotime($feedback['created_at'])); ?>
            <?php if($feedback['is_auto_submitted']): ?><span class="fb-auto-chip">Auto-submitted</span><?php endif; ?>
          </div>

        <?php else: ?>
          <div class="fb-no-feedback-wrap">
            <div class="fb-no-feedback-faces">
              <?php for($i=1;$i<=5;$i++) echo feedbackEmojiSvg($i,22); ?>
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
  </div></main>


<!-- ══ Priority Success Modal ══ -->
<div id="priModalBackdrop" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(10,20,50,.45);backdrop-filter:blur(4px);align-items:center;justify-content:center;">
  <div id="priModal" style="background:white;border-radius:14px;padding:32px 28px 24px;max-width:380px;width:90%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.16);position:relative;animation:priModalIn .28s cubic-bezier(.34,1.56,.64,1);">
    <button onclick="closePriModal()" style="position:absolute;top:13px;right:15px;background:none;border:none;cursor:pointer;color:#9CA3AF;font-size:19px;line-height:1;">&#215;</button>
    <div id="priModalIcon" style="width:60px;height:60px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:26px;"></div>
    <div style="font-size:10px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#9CA3AF;margin-bottom:7px">Priority Updated</div>
    <div style="font-family:'DM Serif Display',serif;font-size:20px;color:#0D1F3C;margin-bottom:5px">Changes Saved!</div>
    <div id="priModalSubtext" style="font-size:13.5px;color:#6B7280;margin-bottom:20px;line-height:1.55"></div>
    <div style="background:#F9FAFB;border-radius:9px;padding:3px 0;margin-bottom:20px;border:1px solid #E5E7EB;">
      <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 16px;font-size:12.5px;"><span style="color:#9CA3AF;">Ticket</span><span id="priModalTicketId" style="font-family:monospace;font-weight:700;color:#001f5c;font-size:11px;background:#E2E8F7;padding:2px 9px;border-radius:5px;"></span></div>
      <div style="height:1px;background:#E5E7EB;"></div>
      <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 16px;font-size:12.5px;"><span style="color:#9CA3AF;">Priority</span><span id="priModalChip" style="font-weight:700;padding:2px 13px;border-radius:20px;font-size:12.5px;"></span></div>
    </div>
    <button onclick="closePriModal()" style="width:100%;padding:11px;border-radius:9px;border:none;background:#001f5c;color:white;font-family:'DM Sans',sans-serif;font-size:14px;font-weight:600;cursor:pointer;">Close</button>
    <div id="priModalProgressBar" style="position:absolute;bottom:0;left:0;right:0;height:4px;background:#E2E8F7;border-radius:0 0 14px 14px;overflow:hidden;"><div id="priModalProgressFill" style="height:100%;width:100%;background:#001f5c;border-radius:0 0 14px 14px;"></div></div>
  </div>
</div>

<!-- ══ Lightbox ══ -->
<div id="lightboxBackdrop">
  <button class="lightbox-close" id="lightboxClose"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
  <div class="lightbox-img-wrap"><img id="lightboxImg" src="" alt=""/></div>
  <div class="lightbox-meta">
    <div id="lightboxCaption"></div>
    <a id="lightboxOpenFull" href="#" target="_blank" class="lightbox-open-link">
      <svg viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
      Open full size
    </a>
  </div>
</div>

<!-- ══ Confirm Modal ══ -->
<div class="modal-backdrop" id="confirmModal">
  <div class="td-modal">
    <div class="td-modal-icon save"><svg viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17,21 17,13 7,13 7,21"/><polyline points="7,3 7,8 15,8"/></svg></div>
    <h3 id="confirmModalTitle">Update Status?</h3>
    <p id="confirmModalDesc">Confirm the status change you are about to apply.</p>
    <div class="td-modal-summary">
      <div class="td-modal-summary-row"><span class="td-modal-summary-label">Status</span><span id="modalStatVal">—</span></div>
      <div class="td-modal-summary-row" id="slaResetRow" style="display:none">
        <span class="td-modal-summary-label">SLA</span>
        <span style="font-size:12.5px;font-weight:600;color:#1D4ED8;">⟳ Will reset to 8h</span>
      </div>
    </div>
    <div class="td-modal-actions">
      <button type="button" class="btn-modal-cancel" onclick="closeConfirmModal()">Cancel</button>
      <button type="button" class="btn-modal-confirm" onclick="submitUpdate()">
        <svg viewBox="0 0 24 24"><polyline points="20,6 9,17 4,12"/></svg>Yes, Save
      </button>
    </div>
  </div>
</div>

<script>
// ── Priority auto-save ────────────────────────────────────────────────────────
var priConfig={
  low:{emoji:'🔵',label:'Low',chip:'#EFF6FF',chipColor:'#3B82F6',subtext:'Marked as low priority.'},
  medium:{emoji:'🟡',label:'Medium',chip:'#FFFBEB',chipColor:'#F59E0B',subtext:'Marked as medium priority.'},
  high:{emoji:'🔴',label:'High',chip:'#FFF1F2',chipColor:'#EF4444',subtext:'Escalated to high priority.'}
};
var priModalAutoClose=null;

function openPriModal(priority){
  var cfg=priConfig[priority]||priConfig['medium'];
  document.getElementById('priModalIcon').textContent=cfg.emoji;
  document.getElementById('priModalIcon').style.background=cfg.chip;
  document.getElementById('priModalSubtext').textContent=cfg.subtext;
  document.getElementById('priModalTicketId').textContent='<?php echo addslashes($ticketId); ?>';
  var chip=document.getElementById('priModalChip');
  chip.textContent=cfg.label; chip.style.background=cfg.chip; chip.style.color=cfg.chipColor;
  var bd=document.getElementById('priModalBackdrop'); bd.style.display='flex';
  var fill=document.getElementById('priModalProgressFill');
  fill.style.transition='none'; fill.style.width='100%';
  requestAnimationFrame(function(){requestAnimationFrame(function(){fill.style.transition='width 4s linear';fill.style.width='0%';});});
  clearTimeout(priModalAutoClose); priModalAutoClose=setTimeout(closePriModal,4000);
}
function closePriModal(){clearTimeout(priModalAutoClose);document.getElementById('priModalBackdrop').style.display='none';}
document.getElementById('priModalBackdrop').addEventListener('click',function(e){if(e.target===this)closePriModal();});

var priFlagMap={
  low:'<span style="display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:600;color:#3B82F6;"><svg width="13" height="13" viewBox="0 0 24 24" fill="#3B82F6" xmlns="http://www.w3.org/2000/svg"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15" stroke="#3B82F6" stroke-width="2" stroke-linecap="round"/></svg>Low</span>',
  medium:'<span style="display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:600;color:#F59E0B;"><svg width="13" height="13" viewBox="0 0 24 24" fill="#F59E0B" xmlns="http://www.w3.org/2000/svg"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15" stroke="#F59E0B" stroke-width="2" stroke-linecap="round"/></svg>Medium</span>',
  high:'<span style="display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:600;color:#EF4444;"><svg width="13" height="13" viewBox="0 0 24 24" fill="#EF4444" xmlns="http://www.w3.org/2000/svg"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15" stroke="#EF4444" stroke-width="2" stroke-linecap="round"/></svg>High</span>'
};

function selectPriorityAutoSave(priority,btn){
  if(!document.getElementById('priorityInput'))return;
  document.querySelectorAll('.pri-btn').forEach(function(b){b.classList.remove('active');b.classList.add('saving');});
  btn.classList.add('active'); document.getElementById('priorityInput').value=priority;
  var spinner=document.getElementById('priSavingSpinner'); if(spinner)spinner.style.display='inline';
  var currentStat=document.getElementById('status')?document.getElementById('status').value:'<?php echo $curStat ?? 'open'; ?>';
  var fd=new FormData(); fd.append('action','update'); fd.append('priority',priority); fd.append('status',currentStat);
  var xhr=new XMLHttpRequest();
  xhr.open('POST','ticket_detail.php?id=<?php echo urlencode($ticketId); ?>',true);
  xhr.setRequestHeader('X-Requested-With','XMLHttpRequest');
  xhr.onreadystatechange=function(){
    if(xhr.readyState!==4)return;
    document.querySelectorAll('.pri-btn').forEach(function(b){b.classList.remove('saving');});
    if(spinner)spinner.style.display='none';
    if(xhr.status===200){
      try{
        var r=JSON.parse(xhr.responseText);
        if(r.success){
    openPriModal(priority);
    var ce=document.getElementById('ticketPriorityChip');
    if(ce&&priFlagMap[priority])ce.innerHTML=priFlagMap[priority];
    // ← ADD THESE TWO LINES: Update header strip priority flag
    var thsPri=document.getElementById('thsPriorityFlag');
    if(thsPri&&priFlagMap[priority])thsPri.innerHTML=priFlagMap[priority];
}else{alert('Failed to update.');}
      }catch(e){openPriModal(priority);}
    }else{alert('Failed to update.');}
  };
  xhr.send(fd);
}

// ── SLA reset warning ─────────────────────────────────────────────────────────
var currentTicketStatus='<?php echo addslashes($curStat ?? 'open'); ?>';
(function(){
  var statusEl=document.getElementById('status');
  if(!statusEl)return;
  statusEl.addEventListener('change',function(){
    var warn=document.getElementById('slaResetWarning');
    if(!warn)return;
    warn.style.display=(currentTicketStatus==='closed'&&this.value!=='closed')?'flex':'none';
  });
})();

// ── Confirm modal ─────────────────────────────────────────────────────────────
function openConfirmModal(){
  var statusEl=document.getElementById('status');
  if(!statusEl)return;
  var status=statusEl.value;
  var map={
    open:'<span style="display:inline-block;font-size:12.5px;font-weight:600;padding:2px 10px;border-radius:20px;background:#FEF3C7;color:#D97706">Open</span>',
    in_progress:'<span style="display:inline-block;font-size:12.5px;font-weight:600;padding:2px 10px;border-radius:20px;background:#DBEAFE;color:#1D4ED8">In Progress</span>',
    closed:'<span style="display:inline-block;font-size:12.5px;font-weight:600;padding:2px 10px;border-radius:20px;background:#D1FAE5;color:#059669">Closed</span>'
  };
  document.getElementById('modalStatVal').innerHTML=map[status]||status;
  var isReopening=(currentTicketStatus==='closed'&&status!=='closed');
  var slaRow=document.getElementById('slaResetRow');
  if(slaRow)slaRow.style.display=isReopening?'flex':'none';
  var titleEl=document.getElementById('confirmModalTitle');
  var descEl=document.getElementById('confirmModalDesc');
  if(isReopening){if(titleEl)titleEl.textContent='Reopen & Reset SLA?';if(descEl)descEl.textContent='This will reopen the ticket and start a fresh 8-hour SLA window from now.';}
  else{if(titleEl)titleEl.textContent='Update Status?';if(descEl)descEl.textContent='Confirm the status change you are about to apply.';}
  document.getElementById('confirmModal').classList.add('show');
}
function closeConfirmModal(){document.getElementById('confirmModal').classList.remove('show');}
function submitUpdate(){closeConfirmModal();document.getElementById('updateForm').submit();}
document.getElementById('confirmModal').addEventListener('click',function(e){if(e.target===this)closeConfirmModal();});

// ── Chat textarea auto-resize ─────────────────────────────────────────────────
var chatInput=document.getElementById('chatInput');
if(chatInput){
  function autoResize(el){el.style.height='auto';el.style.height=Math.min(el.scrollHeight,120)+'px';}
  chatInput.addEventListener('input',function(){autoResize(chatInput);});
  chatInput.addEventListener('keydown',function(e){
    if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();
    var hT=this.value.trim().length>0,hF=document.getElementById('replyAttachment').files.length>0;
    if(hT||hF)document.getElementById('replyForm').submit();}
  });
}

// ── Attachment preview ────────────────────────────────────────────────────────
var ra=document.getElementById('replyAttachment'),afp=document.getElementById('attachFilePreview'),afn=document.getElementById('attachFileName'),ar=document.getElementById('attachRemove'),aiw=document.getElementById('attachImgPreviewWrap'),aip=document.getElementById('attachImgPreview');
if(ra){
  ra.addEventListener('change',function(){if(this.files.length>0){var f=this.files[0],ext=f.name.split('.').pop().toLowerCase();afn.textContent=f.name;afp.style.display='flex';if(['jpg','jpeg','png','gif','webp'].indexOf(ext)!==-1){var r=new FileReader();r.onload=function(e){aip.src=e.target.result;aiw.style.display='block';};r.readAsDataURL(f);}else{aiw.style.display='none';aip.src='';}}});
  ar.addEventListener('click',function(){ra.value='';afp.style.display='none';afn.textContent='';aiw.style.display='none';aip.src='';});
}

// ── Scroll chat to bottom ─────────────────────────────────────────────────────
(function(){var c=document.getElementById('chatMessages');if(c)c.scrollTop=c.scrollHeight;})();

// ── Lightbox ──────────────────────────────────────────────────────────────────
var lb=document.getElementById('lightboxBackdrop'),li=document.getElementById('lightboxImg'),lc=document.getElementById('lightboxCaption'),lo=document.getElementById('lightboxOpenFull');
function openLightbox(url,filename){li.src=url;li.alt=filename||'';lc.textContent=filename||'';lo.href=url;lb.classList.add('show');document.body.style.overflow='hidden';}
function closeLightbox(){lb.classList.remove('show');document.body.style.overflow='';setTimeout(function(){li.src='';},300);}
document.getElementById('lightboxClose').addEventListener('click',closeLightbox);
lb.addEventListener('click',function(e){if(e.target===this)closeLightbox();});
document.addEventListener('keydown',function(e){if(e.key==='Escape'){closeLightbox();closeConfirmModal();closePriModal();}});

// ── Timeline pagination ───────────────────────────────────────────────────────
(function(){
  var logs = document.querySelectorAll('.tl-item');
  if (!logs.length) return;
  var total = logs.length, perPage = 5, currentPage = 1;

  function totalPages() { return Math.ceil(total / perPage); }

  function showPage(page) {
    currentPage = Math.max(1, Math.min(page, totalPages()));
    var start = (currentPage - 1) * perPage;
    var end = Math.min(start + perPage, total);
    logs.forEach(function(el, i) {
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
    prev.onclick = function() { showPage(currentPage - 1); };
    btns.appendChild(prev);

    for (var p = 1; p <= tp; p++) {
      var b = document.createElement('button');
      b.className = 'tl-page-btn' + (p === currentPage ? ' active' : '');
      b.textContent = p;
      b.dataset.page = p;
      b.onclick = (function(pg) { return function() { showPage(pg); }; })(p);
      btns.appendChild(b);
    }

    var next = document.createElement('button');
    next.className = 'tl-page-btn';
    next.innerHTML = '<svg viewBox="0 0 24 24"><polyline points="9,18 15,12 9,6"/></svg>';
    next.disabled = currentPage === totalPages();
    next.onclick = function() { showPage(currentPage + 1); };
    btns.appendChild(next);
  }

  // Per-page selector
  var perPageEl = document.getElementById('tlPerPage');
  if (perPageEl) {
    perPageEl.addEventListener('change', function() {
      perPage = parseInt(this.value, 10);
      showPage(1);
    });
  }

  showPage(1);
})();
</script>
</body>
</html>