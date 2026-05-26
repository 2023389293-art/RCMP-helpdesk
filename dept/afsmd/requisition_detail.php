<?php
// dept/afsmd/requisition_detail.php
require_once __DIR__ . '/../auth_guard.php';
if (isset($_GET['logout'])) { staffLogout(); }
require_once __DIR__ . '/../../db_connect.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require __DIR__ . '/../../PHPMailer-master/src/Exception.php';
require __DIR__ . '/../../PHPMailer-master/src/PHPMailer.php';
require __DIR__ . '/../../PHPMailer-master/src/SMTP.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// ── Sidebar badge counts (tickets) ───────────────────────────────────────────
$tcStmt = $conn->prepare("SELECT SUM(status='open') AS oc, SUM(status='in_progress') AS ipc, SUM(status='closed') AS cc FROM complaints WHERE dept_id = ?");
$tcStmt->bind_param("i", $deptId);
$tcStmt->execute();
$tc = $tcStmt->get_result()->fetch_assoc(); $tcStmt->close();
$openCount       = (int)($tc['oc']  ?? 0);
$inProgressCount = (int)($tc['ipc'] ?? 0);
$closedCount     = (int)($tc['cc']  ?? 0);

// ── Sidebar badge counts (requisitions) ──────────────────────────────────────
$rcStmt = $conn->prepare("SELECT SUM(status='pending') AS pc, SUM(status='approved') AS ac, SUM(status='rejected') AS rc FROM requisitions");
$rcStmt->execute();
$rc = $rcStmt->get_result()->fetch_assoc(); $rcStmt->close();
$reqPendingCount  = (int)($rc['pc'] ?? 0);
$reqApprovedCount = (int)($rc['ac'] ?? 0);
$rejectedCount    = (int)($rc['rc'] ?? 0);

// ── Fetch requisition ─────────────────────────────────────────────────────────
$refNumber   = trim($_GET['id'] ?? '');
$requisition = null;

// ── Smart back URL ────────────────────────────────────────────────────────────
$backUrl    = 'requisitions.php';
$sessionKey = 'rd_back_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $refNumber);
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
    if ($ref && strpos($ref, 'requisition_detail') === false) {
        $backUrl = $_SERVER['HTTP_REFERER'];
        $_SESSION[$sessionKey] = $backUrl;
    }
}
$backUrlEncoded = urlencode($backUrl);

if ($refNumber !== '') {
    $stmt = $conn->prepare("SELECT * FROM requisitions WHERE ref_number = ? LIMIT 1");
    $stmt->bind_param("s", $refNumber);
    $stmt->execute();
    $requisition = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// ── HANDLE POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $requisition) {
    $action = trim($_POST['action'] ?? 'update');

    // ── ACTION: reassign ──────────────────────────────────────────────────────
    if ($action === 'reassign') {
        $newStaffId = (int)($_POST['new_staff_id'] ?? 0);
        if ($newStaffId > 0) {
            $oldId = (int)($requisition['assigned_to'] ?? 0);
            $oldQ  = $conn->prepare("SELECT full_name FROM staff WHERE staff_id=? LIMIT 1");
            $oldQ->bind_param("i", $oldId); $oldQ->execute();
            $oldRow  = $oldQ->get_result()->fetch_assoc(); $oldQ->close();
            $oldName = $oldRow['full_name'] ?? 'Unassigned';

            $nsQ = $conn->prepare("SELECT full_name FROM staff WHERE staff_id=? LIMIT 1");
            $nsQ->bind_param("i", $newStaffId); $nsQ->execute();
            $nsRow   = $nsQ->get_result()->fetch_assoc(); $nsQ->close();
            $newName = $nsRow['full_name'] ?? "Staff #$newStaffId";

            $upd = $conn->prepare("UPDATE requisitions SET assigned_to=?, updated_at=NOW() WHERE ref_number=?");
            $upd->bind_param("is", $newStaffId, $refNumber);
            $upd->execute(); $upd->close();

            $assignRemarks = trim($_POST['assign_remarks'] ?? '');
            $logId   = (int)$_SESSION['staff_id'];
            $logName = $_SESSION['staff_name'] ?? 'Staff';
            $logStmt = $conn->prepare("INSERT INTO requisition_logs (ref_number,changed_by_id,changed_by,field_changed,old_status,new_status,remarks,changed_at) VALUES (?,?,?,'assigned',?,?,?,NOW())");
            if ($logStmt) {
                $logStmt->bind_param("sissss", $refNumber, $logId, $logName, $oldName, $newName, $assignRemarks);
                $logStmt->execute(); $logStmt->close();
            }
            $_SESSION['flash_success'] = 'Requisition reassigned to <strong>'.htmlspecialchars($newName).'</strong>.';
        } else {
            $_SESSION['flash_error'] = 'Please select a staff member to assign.';
        }
        header('Location: requisition_detail.php?id='.urlencode($refNumber).'&tab=detail&from='.$backUrlEncoded); exit;
    }

    // ── ACTION: update status ─────────────────────────────────────────────────
    if ($action === 'update') {
        $newStatus  = trim($_POST['status']  ?? '');
        
        $allowedSta = ['pending','approved','rejected'];

        if (!in_array($newStatus, $allowedSta)) {
            $_SESSION['flash_error'] = 'Invalid status.';
            header('Location: requisition_detail.php?id='.urlencode($refNumber).'&tab=detail&from='.$backUrlEncoded); exit;
        }

        $oldStatus = $requisition['status'];

        // Build update query
        if ($newStatus === 'approved' && $oldStatus !== 'approved') {
            $upd = $conn->prepare("UPDATE requisitions SET status=?,approved_at=NOW(),updated_at=NOW() WHERE ref_number=?");
            $upd->bind_param("ss", $newStatus, $refNumber);
        } else {
            $upd = $conn->prepare("UPDATE requisitions SET status=?,updated_at=NOW() WHERE ref_number=?");
            $upd->bind_param("ss", $newStatus, $refNumber);
        }

        if ($upd->execute()) {
            // Log change
            if ($oldStatus !== $newStatus) {
                $logId   = (int)$_SESSION['staff_id'];
                $logName = $_SESSION['staff_name'] ?? 'Staff';
                $logStmt = $conn->prepare("INSERT INTO requisition_logs (ref_number,changed_by_id,changed_by,field_changed,old_status,new_status,changed_at) VALUES (?,?,?,'status',?,?,NOW())");
                if ($logStmt) {
                    $logStmt->bind_param("sisss", $refNumber, $logId, $logName, $oldStatus, $newStatus);
                    $logStmt->execute(); $logStmt->close();
                }
            }

            // Handle inline message
            $inlineMessage = trim($_POST['message'] ?? '');
            if (!empty($inlineMessage)) {
                $senderName = $_SESSION['staff_name'] ?? 'Staff';
                $senderId   = (int)($_SESSION['staff_id'] ?? 0);
                $ins = $conn->prepare("INSERT INTO requisition_replies (ref_number,sender_id,sender_name,sender_role,message) VALUES (?,?,?,'staff',?)");
                $ins->bind_param("siss", $refNumber, $senderId, $senderName, $inlineMessage);
                $ins->execute(); $ins->close();

                // Email submitter
                $subType  = $requisition['submitter_type'] ?? 'student';
                $subTable = $subType === 'student' ? 'students' : 'staff';
                $subPk    = $subType === 'student' ? 'student_id' : 'staff_id';
                $subQ = $conn->prepare("SELECT full_name, email FROM {$subTable} WHERE {$subPk}=? LIMIT 1");
                if ($subQ) {
                    $subQ->bind_param("i", $requisition['submitter_id']);
                    $subQ->execute();
                    $submitterData = $subQ->get_result()->fetch_assoc();
                    $subQ->close();

                    if ($submitterData && !empty($submitterData['email'])) {
                        $toName      = $submitterData['full_name'];
                        $toEmail     = $submitterData['email'];
                        $currentYear = date('Y');
                        $currentDate = date('d F Y');
                        $statusLabel = ucfirst($newStatus);
                        $statBg  = $newStatus==='approved' ? '#D1FAE5' : ($newStatus==='rejected' ? '#FEE2E2' : '#FEF3C7');
                        $statFg  = $newStatus==='approved' ? '#059669' : ($newStatus==='rejected' ? '#DC2626' : '#D97706');
                        $escapedTo   = htmlspecialchars($toName);
                        $escapedRef  = htmlspecialchars($refNumber);
                        $escapedMsg  = nl2br(htmlspecialchars($inlineMessage));
                        $escapedFrom = htmlspecialchars($senderName);
                        $escapedStat = htmlspecialchars($statusLabel);

                        $htmlBody = <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"/></head>
<body style="margin:0;padding:0;background-color:#ffffff;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#ffffff;padding:40px 16px;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:white;border-radius:4px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);border:1px solid #e4e7ed;">
  <tr><td style="background:#00327a;padding:0;">
    <table width="100%"><tr><td style="height:4px;background:linear-gradient(90deg,#e8b200,#f5cc30,#e8b200);"></td></tr></table>
    <table width="100%"><tr><td style="padding:28px 40px 24px;">
      <div style="font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:rgba(255,255,255,.55);margin-bottom:4px;">Universiti Kuala Lumpur</div>
      <div style="font-size:18px;font-weight:700;color:#fff;">RCMP Help Desk</div>
    </td></tr></table>
    <table width="100%"><tr><td style="padding:12px 40px 16px;background:#002660;">
      <span style="font-size:12px;color:rgba(255,255,255,.65);letter-spacing:.06em;text-transform:uppercase;">📦&nbsp; Equipment Request Update</span>
    </td></tr></table>
  </td></tr>
  <tr><td style="background:#f7f8fa;border-bottom:1px solid #e4e7ed;padding:14px 40px;">
    <table width="100%"><tr>
      <td style="font-size:12px;color:#6b7280;">Reference No.</td>
      <td align="right" style="font-size:13px;font-weight:700;color:#00327a;font-family:monospace;">{$escapedRef}</td>
    </tr></table>
  </td></tr>
  <tr><td style="padding:36px 40px 0;">
    <p style="margin:0 0 6px;font-size:12px;color:#9ca3af;">{$currentDate}</p>
    <p style="margin:0 0 20px;font-size:15px;font-weight:600;color:#111827;">Dear {$escapedTo},</p>
    <p style="margin:0 0 24px;font-size:14px;color:#374151;line-height:1.75;">Your equipment request has been updated. Please see the current status and message below.</p>
    <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e4e7ed;border-radius:4px;overflow:hidden;margin-bottom:20px;">
      <tr><td colspan="2" style="background:#00327a;padding:10px 18px;"><span style="font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.85);">Request Status Update</span></td></tr>
      <tr>
        <td style="width:40%;padding:12px 18px;background:#f7f8fa;border-bottom:1px solid #e4e7ed;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;">Reference</td>
        <td style="padding:12px 18px;border-bottom:1px solid #e4e7ed;font-size:13px;font-weight:700;color:#00327a;font-family:monospace;">{$escapedRef}</td>
      </tr>
      <tr>
        <td style="width:40%;padding:12px 18px;background:#f7f8fa;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;">Status</td>
        <td style="padding:12px 18px;"><span style="display:inline-block;font-size:12px;font-weight:600;padding:3px 12px;border-radius:20px;background:{$statBg};color:{$statFg};">{$escapedStat}</span></td>
      </tr>
    </table>
    <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e4e7ed;border-radius:4px;overflow:hidden;margin-bottom:24px;">
      <tr><td style="background:#00327a;padding:10px 18px;"><span style="font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.85);">Message from {$escapedFrom}</span></td></tr>
      <tr><td style="padding:16px 18px;background:#f7f8fa;font-size:14px;color:#374151;line-height:1.75;">{$escapedMsg}</td></tr>
    </table>
    <p style="margin:20px 0 4px;font-size:14px;color:#374151;">Yours sincerely,</p>
    <p style="margin:0 0 2px;font-size:14px;font-weight:700;color:#00327a;">UniKL RCMP Help Desk – AFSMD</p>
    <p style="margin:0 0 28px;font-size:12px;color:#9ca3af;">Universiti Kuala Lumpur</p>
  </td></tr>
  <tr><td style="background:#f7f8fa;border-top:1px solid #e4e7ed;padding:20px 40px;">
    <p style="margin:0;font-size:11px;color:#9ca3af;">This is a system-generated notification. &bull; &copy; {$currentYear} Universiti Kuala Lumpur.</p>
  </td></tr>
  <tr><td style="height:4px;background:linear-gradient(90deg,#e8b200,#f5cc30,#e8b200);"></td></tr>
</table></td></tr></table></body></html>
HTML;
                        $mail = new PHPMailer(true);
                        try {
                            $mail->isSMTP(); $mail->Host='smtp.office365.com'; $mail->SMTPAuth=true;
                            $mail->Username='rush.rcmp@unikl.edu.my'; $mail->Password='Rcmp@4321';
                            $mail->SMTPSecure=PHPMailer::ENCRYPTION_STARTTLS; $mail->Port=587;
                            $mail->SMTPDebug=0; $mail->Debugoutput='error_log';
                            $mail->setFrom('rush.rcmp@unikl.edu.my','UniKL RCMP Help Desk');
                            $mail->addAddress($toEmail, $toName);
                            $mail->isHTML(true); $mail->CharSet='UTF-8';
                            $mail->Subject="Equipment Request Update ({$statusLabel}) — {$refNumber}";
                            $mail->Body=$htmlBody;
                            $mail->AltBody="Status: {$statusLabel}\n\nMessage from {$senderName}:\n\n{$inlineMessage}\n\nRef: {$refNumber}";
                            $mail->send();
                        } catch (Exception $e) {
                            error_log("[UniKL Mail] Req message send failed for {$refNumber}: ".$mail->ErrorInfo);
                        }
                    }
                }
            }

            $statusLabel = ucfirst($newStatus);
            $_SESSION['flash_success'] = 'Requisition updated — status: <strong>'.htmlspecialchars($statusLabel).'</strong>.';
        } else {
            $_SESSION['flash_error'] = 'Failed to update.';
        }
        $upd->close();
        header('Location: requisition_detail.php?id='.urlencode($refNumber).'&tab=detail&from='.$backUrlEncoded); exit;
    }
}

// ── Flash messages ────────────────────────────────────────────────────────────
$updateMsg   = $_SESSION['flash_success'] ?? '';
$updateError = $_SESSION['flash_error']   ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// ── Re-fetch fresh data after POST ───────────────────────────────────────────
if ($refNumber !== '') {
    $stmt = $conn->prepare("SELECT * FROM requisitions WHERE ref_number = ? LIMIT 1");
    $stmt->bind_param("s", $refNumber);
    $stmt->execute();
    $requisition = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// ── Fetch submitter info ──────────────────────────────────────────────────────
$submitter = null;
if ($requisition) {
    $type  = $requisition['submitter_type'] ?? 'student';
    $table = $type === 'student' ? 'students' : 'staff';
    $pkCol = $type === 'student' ? 'student_id' : 'staff_id';
    $s2 = $conn->prepare("SELECT full_name AS name, email FROM {$table} WHERE {$pkCol}=? LIMIT 1");
    if ($s2) { $s2->bind_param("i", $requisition['submitter_id']); $s2->execute(); $submitter = $s2->get_result()->fetch_assoc(); $s2->close(); }
}

// ── Fetch assigned staff ──────────────────────────────────────────────────────
$assignedStaff = null;
if ($requisition && !empty($requisition['assigned_to'])) {
    $asQ = $conn->prepare("SELECT staff_id, full_name, email, role FROM staff WHERE staff_id=? LIMIT 1");
    $asQ->bind_param("i", $requisition['assigned_to']);
    $asQ->execute();
    $assignedStaff = $asQ->get_result()->fetch_assoc();
    $asQ->close();
}

// ── Fetch change logs + replies combined ──────────────────────────────────────
$changeLogs = [];
if ($requisition) {
    $lg = $conn->prepare("
        SELECT
            'log' AS source,
            log_id AS row_id,
            changed_by,
            field_changed,
            old_status,
            new_status,
            NULL AS message_content,
            COALESCE(remarks,'') AS remarks,
            changed_at AS event_at
        FROM requisition_logs
        WHERE ref_number = ?
        UNION ALL
        SELECT
            'reply' AS source,
            reply_id AS row_id,
            sender_name AS changed_by,
            'message' AS field_changed,
            NULL AS old_status,
            NULL AS new_status,
            message AS message_content,
            '' AS remarks,
            created_at AS event_at
        FROM requisition_replies
        WHERE ref_number = ? AND sender_role = 'staff'
        ORDER BY event_at DESC
    ");
    $lg->bind_param("ss", $refNumber, $refNumber);
    $lg->execute();
    $changeLogs = $lg->get_result()->fetch_all(MYSQLI_ASSOC);
    $lg->close();
}

// ── Fetch replies (conversation) ──────────────────────────────────────────────
$replies = [];
if ($requisition) {
    $rq = $conn->prepare("SELECT reply_id,sender_id,sender_name,sender_role,message,attachment_path,created_at FROM requisition_replies WHERE ref_number=? ORDER BY created_at ASC");
    $rq->bind_param("s", $refNumber);
    $rq->execute();
    $replies = $rq->get_result()->fetch_all(MYSQLI_ASSOC);
    $rq->close();
}

// ── AFSMD dept staff list (for reassign) ─────────────────────────────────────
$deptStaffList = [];
$dsStmt = $conn->prepare("SELECT staff_id, full_name FROM staff WHERE dept_id = ? AND status = 'active' AND role = 'staff' ORDER BY staff_id ASC");
$dsStmt->bind_param("i", $deptId);
$dsStmt->execute();
$dsRes = $dsStmt->get_result();
while ($row = $dsRes->fetch_assoc()) $deptStaffList[] = $row;
$dsStmt->close();

$currentStaffId   = (int)($_SESSION['staff_id'] ?? 0);
$isAssignedStaff  = ($assignedStaff && (int)$assignedStaff['staff_id'] === $currentStaffId);

// ── Helpers ───────────────────────────────────────────────────────────────────
function reqStatusBadge(string $s): string {
    $map = [
        'pending'  => ['#FEF3C7','#D97706'],
        'approved' => ['#D1FAE5','#059669'],
        'rejected' => ['#FEE2E2','#DC2626'],
    ];
    [$bg,$fg] = $map[strtolower($s)] ?? ['#F3F4F6','#6B7280'];
    return "<span style=\"display:inline-block;font-size:12px;font-weight:600;padding:3px 12px;border-radius:20px;background:{$bg};color:{$fg}\">".htmlspecialchars(ucfirst($s))."</span>";
}
function reqStatChip(string $v): string {
    $map = [
        'pending'  => ['#FEF3C7','#D97706'],
        'approved' => ['#D1FAE5','#059669'],
        'rejected' => ['#FEE2E2','#DC2626'],
    ];
    [$bg,$fg] = $map[strtolower($v)] ?? ['#F3F4F6','#6B7280'];
    return "<span style=\"display:inline-block;font-size:11px;font-weight:600;padding:2px 9px;border-radius:20px;background:{$bg};color:{$fg}\">".htmlspecialchars(ucfirst($v))."</span>";
}
function urgPill(string $u): string {
    $map = [
        'normal'   => ['#EFF6FF','#1D4ED8'],
        'urgent'   => ['#FEF3E2','#92520C'],
        'critical' => ['#FDECEA','#B71C1C'],
    ];
    [$bg,$fg] = $map[strtolower($u)] ?? ['#F3F4F6','#6B7280'];
    return "<span style=\"display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:600;color:{$fg};\"><span style=\"width:7px;height:7px;border-radius:50%;background:{$fg};display:inline-block;\"></span>".htmlspecialchars(ucfirst($u))."</span>";
}
function reqTimeAgo(string $datetime): string {
    $now  = new DateTime('now', new DateTimeZone('Asia/Kuala_Lumpur'));
    $past = new DateTime($datetime, new DateTimeZone('Asia/Kuala_Lumpur'));
    $diff = $now->getTimestamp() - $past->getTimestamp();
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff/60).' min ago';
    if ($diff < 86400)  return floor($diff/3600).' hr ago';
    if ($diff < 604800) { $d = floor($diff/86400); return $d.' day'.($d>1?'s':'').' ago'; }
    return date('d M Y', $past->getTimestamp());
}
function reqGetInitials(string $name): string {
    $parts = explode(' ', trim($name));
    $ini   = strtoupper(substr($parts[0], 0, 1));
    if (count($parts) > 1) $ini .= strtoupper(substr($parts[count($parts)-1], 0, 1));
    return $ini;
}
function reqIsImage(string $path): bool {
    return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg','jpeg','png','gif','webp']);
}
function reqFileIcon(string $path): array {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === 'pdf')              return ['label'=>'PDF','color'=>'#DC2626','bg'=>'#FEF2F2'];
    elseif (in_array($ext,['doc','docx'])) return ['label'=>'DOC','color'=>'#1D4ED8','bg'=>'#EFF6FF'];
    elseif ($ext === 'txt')          return ['label'=>'TXT','color'=>'#374151','bg'=>'#F9FAFB'];
    else                             return ['label'=>strtoupper($ext),'color'=>'#6B7280','bg'=>'#F3F4F6'];
}

$isClosed  = $requisition && in_array($requisition['status'], ['approved','rejected']);
$activeTab = $_GET['tab'] ?? 'detail';
if (!in_array($activeTab, ['detail','history'])) $activeTab = 'detail';

$activeNav    = 'requisitions';
$pageTitle    = 'Requisition Detail';
$pageSubtitle = 'Administration & Facilities Management Department';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Requisition Detail | UniKL Help Desk – AFSMD</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="css/tickets_details.css">
  <style>
    /* ── Requisition-specific overrides ── */
.req-meta-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:0;border-radius:12px;overflow:hidden;margin-bottom:20px;}
.req-meta-cell{padding:14px 18px;}
    @media(max-width:640px){.req-meta-grid{grid-template-columns:1fr 1fr;}.req-meta-cell:nth-child(2n){border-right:none;}.req-meta-cell:nth-child(3n){border-right:1px solid var(--g200,#E5E7EB);}}
    .req-meta-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--g500,#6B7280);margin-bottom:5px;}
    .req-meta-value{font-size:13.5px;color:var(--g900,#0D1F3C);font-weight:500;line-height:1.4;}

    /* Status update select styled like ticket */
    .status-select-styled{width:100%;padding:9px 12px;font-size:13px;font-family:'DM Sans',sans-serif;border-radius:9px;border:1.5px solid var(--g200,#E5E7EB);background:#FAFAFA;color:var(--g700,#374151);outline:none;appearance:none;cursor:pointer;transition:border-color .15s,background .15s;}
    .status-select-styled:focus{border-color:#5B8CCC;background:white;box-shadow:0 0 0 3px rgba(91,140,204,.07);}

    /* Remarks textarea */
    .remarks-textarea{width:100%;padding:10px 12px;font-size:13px;font-family:'DM Sans',sans-serif;border-radius:9px;border:1.5px solid var(--g200,#E5E7EB);background:#FAFAFA;color:var(--g700,#374151);outline:none;resize:vertical;min-height:80px;box-sizing:border-box;transition:border-color .15s,background .15s;}
    .remarks-textarea:focus{border-color:#5B8CCC;background:white;box-shadow:0 0 0 3px rgba(91,140,204,.07);}

    /* Rejected notice banner */
    .rejected-notice{display:flex;align-items:center;gap:12px;padding:14px 18px;background:#FEF2F2;border:1px solid #FCA5A5;border-radius:12px;margin-bottom:20px;}
    .approved-notice{display:flex;align-items:center;gap:12px;padding:14px 18px;background:#D1FAE5;border:1px solid #6EE7B7;border-radius:12px;margin-bottom:20px;}
  </style>
</head>
<body>
<?php require_once __DIR__ . '/_layout.php'; ?>

  <!-- Breadcrumb -->
  <div class="td-breadcrumb">
    <a href="<?php echo htmlspecialchars($backUrl); ?>">All Requisitions</a>
    <span class="td-breadcrumb-sep">›</span>
    <span><?php echo htmlspecialchars($refNumber ?: 'Detail'); ?></span>
  </div>

  <!-- Back button -->
  <a href="<?php echo htmlspecialchars($backUrl); ?>" class="td-back-btn">
    <svg viewBox="0 0 24 24"><polyline points="15,18 9,12 15,6"/></svg>
    Back to All Requisitions
  </a>

  <?php if (!$requisition): ?>
  <div class="not-found">
    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <h2>Requisition Not Found</h2>
    <p>Reference <strong><?php echo htmlspecialchars($refNumber); ?></strong> does not exist.</p>
    <a href="<?php echo htmlspecialchars($backUrl); ?>" class="nf-back"><svg viewBox="0 0 24 24"><polyline points="15,18 9,12 15,6"/></svg>Back to All Requisitions</a>
  </div>
  <?php else: ?>

  <!-- Flash messages -->
  <?php if ($updateMsg): ?>
  <div class="td-alert td-alert-success"><svg viewBox="0 0 24 24"><polyline points="20,6 9,17 4,12"/></svg><span><?php echo $updateMsg; ?></span></div>
  <?php endif; ?>
  <?php if ($updateError): ?>
  <div class="td-alert td-alert-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><span><?php echo htmlspecialchars($updateError); ?></span></div>
  <?php endif; ?>

  <!-- ── Ticket header strip ── -->
  <div class="ticket-header-strip">
    <div class="ths-icon">
      <svg viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/><path d="M16 3H8a1 1 0 0 0-1 1v3h10V4a1 1 0 0 0-1-1z"/></svg>
    </div>
    <div class="ths-info">
      <div class="ths-title"><?php echo htmlspecialchars($requisition['item_name']); ?> &times;<?php echo (int)$requisition['quantity']; ?></div>
      <div class="ths-desc"><?php echo htmlspecialchars($requisition['category']); ?> — <?php echo htmlspecialchars($requisition['my_department']); ?></div>
      <div class="ths-bottom-row">
        <div class="ths-badges">
          <?php echo reqStatusBadge($requisition['status']); ?>
          <?php echo urgPill($requisition['urgency'] ?? 'normal'); ?>
        </div>
      </div>
    </div>
    <div class="ths-id-badge"><?php echo htmlspecialchars($requisition['ref_number']); ?></div>
  </div>

  <!-- ── TAB BAR ── -->
  <div class="td-tab-bar" role="tablist">
    <a href="?id=<?php echo urlencode($refNumber); ?>&tab=detail&from=<?php echo $backUrlEncoded; ?>"
       class="td-tab-btn <?php echo $activeTab==='detail'?'active':''; ?>" role="tab">
      <svg viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
      Detail
    </a>
    <a href="?id=<?php echo urlencode($refNumber); ?>&tab=history&from=<?php echo $backUrlEncoded; ?>"
       class="td-tab-btn <?php echo $activeTab==='history'?'active':''; ?>" role="tab">
      <svg viewBox="0 0 24 24"><polyline points="12,8 12,12 14,14"/><path d="M3.05 11a9 9 0 1 1 .5 4"/></svg>
      History
      <?php if (count($changeLogs) > 0): ?>
      <span class="td-tab-badge"><?php echo count($changeLogs); ?></span>
      <?php endif; ?>
    </a>
  </div>


  <!-- ══════════════════════════════════════════════════
       TAB 1: DETAIL
  ══════════════════════════════════════════════════ -->
  <div class="td-panel <?php echo $activeTab==='detail'?'active':''; ?>">
    <div class="detail-tab-grid">

      <!-- LEFT: Requisition info -->
      <div class="detail-left">

        <!-- Status notices -->
        <?php if ($requisition['status'] === 'approved'): ?>
        <div class="approved-notice">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
          <div>
            <div style="font-size:13.5px;font-weight:600;color:#065F46;">This requisition has been Approved</div>
            <div style="font-size:12px;color:#047857;margin-top:2px;">
              <?php if (!empty($requisition['approved_at'])): ?>
                Approved on <?php echo date('d M Y, H:i', strtotime($requisition['approved_at'])); ?>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php elseif ($requisition['status'] === 'rejected'): ?>
        <div class="rejected-notice">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#DC2626" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
          <div>
            <div style="font-size:13.5px;font-weight:600;color:#7F1D1D;">This requisition has been Rejected</div>
            <div style="font-size:12px;color:#B91C1C;margin-top:2px;">The submitter has been notified.</div>
          </div>
        </div>
        <?php endif; ?>

        <!-- Requisition Info Card -->
        <div class="td-card ticket-info-card">
          <div class="td-card-header">
            <div class="td-card-header-icon ticket-info-header-icon">
              <svg viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/><path d="M16 3H8a1 1 0 0 0-1 1v3h10V4a1 1 0 0 0-1-1z"/></svg>
            </div>
            <div>
              <div class="td-card-header-title">Requisition Information</div>
              <div class="td-card-header-sub">Full details of this equipment request</div>
            </div>
          </div>
          <div class="td-card-body">

            <!-- Meta grid -->
            <div class="req-meta-grid">
              <div class="req-meta-cell">
                <div class="req-meta-label">Category</div>
                <div class="req-meta-value"><?php echo htmlspecialchars($requisition['category']); ?></div>
              </div>
              <div class="req-meta-cell">
                <div class="req-meta-label">Item Requested</div>
                <div class="req-meta-value"><?php echo htmlspecialchars($requisition['item_name']); ?></div>
              </div>
              <div class="req-meta-cell">
                <div class="req-meta-label">Quantity</div>
                <div class="req-meta-value">
                  <?php $qty = (int)$requisition['quantity']; echo $qty.' '.($qty===1?'unit':'units'); ?>
                </div>
              </div>
              <div class="req-meta-cell">
                <div class="req-meta-label">Urgency</div>
                <div class="req-meta-value"><?php echo urgPill($requisition['urgency'] ?? 'normal'); ?></div>
              </div>
              <div class="req-meta-cell">
                <div class="req-meta-label">From Department</div>
                <div class="req-meta-value"><?php echo htmlspecialchars($requisition['my_department']); ?></div>
              </div>
              <div class="req-meta-cell">
                <div class="req-meta-label">Location</div>
                <div class="req-meta-value"><?php echo htmlspecialchars($requisition['location']); ?></div>
              </div>
              <div class="req-meta-cell">
                <div class="req-meta-label">Submitted</div>
                <div class="req-meta-value"><?php echo date('d M Y, H:i', strtotime($requisition['created_at'])); ?></div>
              </div>
              <div class="req-meta-cell">
                <div class="req-meta-label">Last Updated</div>
                <div class="req-meta-value"><?php echo date('d M Y, H:i', strtotime($requisition['updated_at'] ?? $requisition['created_at'])); ?></div>
              </div>
              <div class="req-meta-cell">
                <div class="req-meta-label">Status</div>
                <div class="req-meta-value"><?php echo reqStatusBadge($requisition['status']); ?></div>
              </div>
            </div>

            <!-- Reason / Justification -->
            <div class="ti-desc-label">Reason / Justification</div>
            <div class="ti-desc-box"><?php echo htmlspecialchars($requisition['reason']); ?></div>

            <!-- Admin Remarks (if any) -->
            <?php if (!empty($requisition['remarks'])): ?>
            <div class="ti-desc-label" style="margin-top:16px;">Admin Remarks</div>
            <div class="ti-desc-box" style="background:<?php echo $requisition['status']==='rejected'?'#FEF2F2':'#F0FDF4'; ?>;border-left:3px solid <?php echo $requisition['status']==='rejected'?'#FCA5A5':'#6EE7B7'; ?>;padding:12px 14px;border-radius:0 8px 8px 0;">
              <?php echo nl2br(htmlspecialchars($requisition['remarks'])); ?>
            </div>
            <?php endif; ?>

            <!-- Attachment -->
            <?php if (!empty($requisition['attachment_path'])): ?>
            <?php $attPath = $requisition['attachment_path']; ?>
            <a class="ti-attach-link" href="../../<?php echo htmlspecialchars($attPath); ?>" target="_blank">
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
                <div class="ti-submitter-val"><?php echo htmlspecialchars($submitter['name'] ?? '—'); ?></div>
              </div>
              <div class="ti-submitter-cell">
                <div class="ti-submitter-lbl">Email</div>
                <div class="ti-submitter-val"><?php echo htmlspecialchars($submitter['email'] ?? '—'); ?></div>
              </div>
              <div class="ti-submitter-cell" style="border-right:none">
                <div class="ti-submitter-lbl">Phone</div>
                <div class="ti-submitter-val">+60 <?php echo htmlspecialchars($requisition['phone'] ?? '—'); ?></div>
              </div>
            </div>

          </div>
        </div>

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
              <div class="td-card-header-sub">AFSMD staff handler</div>
            </div>
          </div>
          <div class="td-card-body">
            <?php if ($assignedStaff): ?>
            <div class="assigned-pill">
              <div class="assigned-avatar"><?php echo reqGetInitials($assignedStaff['full_name']); ?></div>
              <div style="flex:1;min-width:0;">
                <div class="assigned-name"><?php echo htmlspecialchars($assignedStaff['full_name']); ?></div>
                <div class="assigned-role-tag">AFSMD Staff</div>
              </div>
            </div>
            <?php else: ?>
            <div class="unassigned-pill">⚠️ No staff assigned yet.</div>
            <?php endif; ?>

            <?php if (!empty($deptStaffList)): ?>
            <form method="POST" action="requisition_detail.php?id=<?php echo urlencode($refNumber); ?>">
              <input type="hidden" name="action" value="reassign"/>
              <div class="reassign-label">Reassign to</div>
              <div class="reassign-row">
                <select name="new_staff_id" class="reassign-select" id="reassignSelect" required onchange="handleReassignChange(this)">
                  <option value="">— Select staff —</option>
                  <?php foreach ($deptStaffList as $s): ?>
                  <?php if ((int)$s['staff_id'] === (int)($assignedStaff['staff_id'] ?? 0)) continue; ?>
                  <option value="<?php echo $s['staff_id']; ?>"><?php echo htmlspecialchars($s['full_name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div id="reassignRemarksBox" style="display:none;">
                <div class="reassign-label" style="margin-top:10px;">Remarks <span style="color:#9CA3AF;font-weight:400;font-size:10px;">(optional)</span></div>
                <textarea name="assign_remarks" class="msg-inline-textarea" placeholder="Reason for reassignment…" maxlength="500" rows="2"></textarea>
              </div>
              <button type="submit" id="reassignSaveBtn" class="reassign-btn" style="width:100%;margin-top:8px;display:none;">Save Assignment</button>
            </form>
            <?php endif; ?>
          </div>
        </div>

        <!-- Update Requisition Status -->
        <div class="td-card">
          <div class="td-card-header">
            <div class="td-card-header-icon update-header-icon">
              <svg viewBox="0 0 24 24"><polyline points="23,4 23,10 17,10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
            </div>
            <div>
              <div class="td-card-header-title">Update Requisition</div>
              <div class="td-card-header-sub">Change status &amp; add remarks</div>
            </div>
          </div>
          <div class="td-card-body">
            <?php $curStatus = strtolower($requisition['status'] ?? 'pending'); ?>

            <?php if ($curStatus === 'rejected'): ?>
            <!-- Rejected: locked -->
            <div class="no-permission-box">
              <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              <div>
                <div class="no-permission-title">Requisition rejected</div>
                <div class="no-permission-desc">This requisition has been rejected and is now locked. No further changes are allowed.</div>
              </div>
            </div>
            <?php else: ?>

            <form method="POST" action="requisition_detail.php?id=<?php echo urlencode($refNumber); ?>" id="updateReqForm">
              <input type="hidden" name="action" value="update"/>

              <!-- Status select -->
              <div class="pri-label-sm">Status</div>
              <div class="status-select-wrap" style="margin-bottom:12px;">
                <select name="status" id="reqStatus" class="status-select-styled" onchange="handleReqStatusChange(this.value)">
                  <?php if ($curStatus === 'pending'): ?>
                    <option value="pending"  selected>Pending</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
                  <?php elseif ($curStatus === 'approved'): ?>
                    <option value="approved" selected>Approved</option>
                    <option value="rejected">Rejected</option>
                  <?php endif; ?>
                </select>
              </div>

              <!-- Message to Submitter (always visible) -->
              <div class="msg-section-label" style="margin-bottom:8px;">
                <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                Message to Submitter <span style="color:#9CA3AF;font-weight:400;font-size:10px;margin-left:4px;">(optional)</span>
              </div>
              <textarea name="message" class="msg-inline-textarea" placeholder="Type a message to the submitter…" maxlength="2000" rows="3"></textarea>

              <button type="button" class="btn-update-save" onclick="openReqConfirmModal()" style="margin-top:14px;">Save Changes</button>
            </form>

            <?php endif; ?>
          </div>
        </div>

      </div><!-- /.detail-right -->
    </div><!-- /.detail-tab-grid -->
  </div><!-- /.td-panel detail -->


  <!-- ══════════════════════════════════════════════════
       TAB 2: HISTORY
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
        </select>
        <?php endif; ?>
      </div>

      <?php if (empty($changeLogs)): ?>
      <div class="history-empty">
        <div class="history-empty-icon">
          <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><polyline points="12,8 12,12 14,14"/></svg>
        </div>
        <div class="history-empty-title">No changes yet</div>
        <div class="history-empty-sub">Status updates and messages will appear here.</div>
      </div>
      <?php else: ?>
      <div class="timeline" id="timelineContainer">
        <?php foreach($changeLogs as $idx=>$log):
          $fc = $log['field_changed'];
          if ($fc === 'assigned') $dotCls = 'asgn';
          elseif ($fc === 'message') $dotCls = 'msg';
          else $dotCls = 'stat';
        ?>
        <div class="tl-item" data-log-index="<?php echo $idx; ?>" style="<?php echo $idx>=5?'display:none':''; ?>">
          <div class="tl-dot <?php echo $dotCls; ?>">
            <?php if($fc==='assigned'):?>
              <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <?php elseif($fc==='message'):?>
              <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            <?php else:?>
              <svg viewBox="0 0 24 24"><rect x="1" y="5" width="22" height="14" rx="7" ry="7"/><circle cx="16" cy="12" r="3"/></svg>
            <?php endif; ?>
          </div>
          <div class="tl-body">
            <div class="tl-header">
              <span class="tl-who"><?php echo htmlspecialchars($log['changed_by']); ?></span>
              <span class="tl-when"><?php echo reqTimeAgo($log['event_at']); ?></span>
            </div>
            <span class="tl-when-full"><?php echo date('d M Y, H:i',strtotime($log['event_at'])); ?></span>
            <div class="tl-changes" style="margin-top:5px">
              <?php if($fc==='status' && $log['old_status'] && $log['new_status']): ?>
              <div class="tl-row"><span class="tl-row-label">Status</span><?php echo reqStatChip($log['old_status']); ?><span class="tl-arrow">→</span><?php echo reqStatChip($log['new_status']); ?></div>
              <?php if(!empty($log['remarks'])): ?>
              <div class="tl-msg-bubble" style="border-left-color:#6366F1;background:#EEF2FF;color:#3730A3;margin-top:6px;">
                <span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#818CF8;display:block;margin-bottom:3px;">Remarks</span>
                <?php echo nl2br(htmlspecialchars($log['remarks'])); ?>
              </div>
              <?php endif; ?>
              <?php elseif($fc==='assigned'): ?>
              <div class="tl-row">
                <span class="tl-row-label">Action</span>
                <span class="tl-chip-name" style="background:#EFF6FF;color:#1D4ED8;">Reassigned</span>
              </div>
              <div class="tl-row" style="margin-top:4px;">
                <span class="tl-row-label">To</span>
                <span class="tl-chip-name tl-chip-name--new"><?php echo htmlspecialchars($log['new_status'] ?? '—'); ?></span>
              </div>
              <?php if(!empty($log['remarks'])): ?>
              <div class="tl-msg-bubble" style="border-left-color:#6366F1;background:#EEF2FF;color:#3730A3;margin-top:6px;">
                <span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#818CF8;display:block;margin-bottom:3px;">Remarks</span>
                <?php echo nl2br(htmlspecialchars($log['remarks'])); ?>
              </div>
              <?php endif; ?>
              <?php elseif($fc==='message'): ?>
              <div class="tl-row">
                <span class="tl-row-label">Message</span>
                <span class="tl-chip-conv" style="background:#EEF2FF;color:#3730A3;">Sent a message</span>
              </div>
              <?php if(!empty($log['message_content'])): ?>
              <div class="tl-msg-bubble"><?php echo nl2br(htmlspecialchars($log['message_content'])); ?></div>
              <?php endif; ?>
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

  <?php endif; // end if $requisition ?>

  </div></main>



<!-- ── Confirm Modal ── -->
<div class="modal-backdrop" id="reqConfirmModal">
  <div class="td-modal">
    <div class="td-modal-icon save"><svg viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17,21 17,13 7,13 7,21"/><polyline points="7,3 7,8 15,8"/></svg></div>
    <h3 id="reqConfirmTitle">Update Status?</h3>
    <p id="reqConfirmDesc">Confirm the changes you are about to apply.</p>
    <div class="td-modal-summary">
      <div class="td-modal-summary-row"><span class="td-modal-summary-label">Status</span><span id="reqModalStatVal">—</span></div>
    </div>
    <div class="td-modal-actions">
      <button type="button" class="btn-modal-cancel" onclick="closeReqConfirmModal()">Cancel</button>
      <button type="button" class="btn-modal-confirm" onclick="submitReqUpdate()">
        <svg viewBox="0 0 24 24"><polyline points="20,6 9,17 4,12"/></svg>Yes, Save
      </button>
    </div>
  </div>
</div>

<!-- ── Lightbox ── -->
<div id="lightboxBackdrop">
  <button class="lightbox-close" id="lightboxClose"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
  <div class="lightbox-img-wrap"><img id="lightboxImg" src="" alt=""/></div>
</div>

<script>
// ── Reassign ──────────────────────────────────────────────────────────────────
function handleReassignChange(sel) {
  var b = document.getElementById('reassignRemarksBox');
  var s = document.getElementById('reassignSaveBtn');
  var show = sel.value !== '';
  b.style.display = show ? 'block' : 'none';
  s.style.display = show ? 'block' : 'none';
}

// ── Status change: show message box ──────────────────────────────────────────
var _reqCurrentStatus = '<?php echo addslashes($curStatus ?? 'pending'); ?>';
function handleReqStatusChange(val) {
  // Message box is always visible now — nothing to toggle
}

// ── Confirm modal ─────────────────────────────────────────────────────────────
function openReqConfirmModal() {
  var status    = document.getElementById('reqStatus')?.value;
  
  var curStat   = _reqCurrentStatus;

  // No remarks field — nothing to validate here

  if (!status || status === curStat) {
    // No change — still allow saving remarks only (no status chip change)
  }

  var map = {
    pending:  '<span style="display:inline-block;font-size:12.5px;font-weight:600;padding:2px 10px;border-radius:20px;background:#FEF3C7;color:#D97706">Pending</span>',
    approved: '<span style="display:inline-block;font-size:12.5px;font-weight:600;padding:2px 10px;border-radius:20px;background:#D1FAE5;color:#059669">Approved</span>',
    rejected: '<span style="display:inline-block;font-size:12.5px;font-weight:600;padding:2px 10px;border-radius:20px;background:#FEE2E2;color:#DC2626">Rejected</span>',
  };
  document.getElementById('reqModalStatVal').innerHTML = map[status] || status;

  var titleEl = document.getElementById('reqConfirmTitle');
  var descEl  = document.getElementById('reqConfirmDesc');
  if (status === 'rejected') {
    if (titleEl) titleEl.textContent = 'Reject Requisition?';
    if (descEl)  descEl.textContent  = 'This will mark the requisition as rejected. The submitter will be notified.';
  } else if (status === 'approved') {
    if (titleEl) titleEl.textContent = 'Approve Requisition?';
    if (descEl)  descEl.textContent  = 'This will approve the request. The submitter will be notified.';
  } else {
    if (titleEl) titleEl.textContent = 'Update Requisition?';
    if (descEl)  descEl.textContent  = 'Confirm the changes you are about to apply.';
  }

  document.getElementById('reqConfirmModal').classList.add('show');
}
function closeReqConfirmModal()   { document.getElementById('reqConfirmModal').classList.remove('show'); }

function submitReqUpdate()        { closeReqConfirmModal(); document.getElementById('updateReqForm').submit(); }

document.getElementById('reqConfirmModal').addEventListener('click',function(e){if(e.target===this)closeReqConfirmModal();});


// ── Lightbox ──────────────────────────────────────────────────────────────────
var lb=document.getElementById('lightboxBackdrop'),li=document.getElementById('lightboxImg');
var lbClose=document.getElementById('lightboxClose');
if(lbClose)lbClose.addEventListener('click',function(){lb.classList.remove('show');setTimeout(function(){if(li)li.src='';},300);});
if(lb)lb.addEventListener('click',function(e){if(e.target===this){lb.classList.remove('show');setTimeout(function(){if(li)li.src='';},300);}});

document.addEventListener('keydown',function(e){
  if(e.key==='Escape'){
    closeReqConfirmModal();
    if(lb)lb.classList.remove('show');
  }
});

// ── Timeline pagination ───────────────────────────────────────────────────────
(function(){
  var logs = document.querySelectorAll('.tl-item');
  if (!logs.length) return;
  var total = logs.length, perPage = 5, currentPage = 1;

  function totalPages() { return Math.ceil(total / perPage); }

  function showPage(page) {
    currentPage = Math.max(1, Math.min(page, totalPages()));
    var start = (currentPage - 1) * perPage;
    var end   = Math.min(start + perPage, total);
    logs.forEach(function(el, i) { el.style.display = (i >= start && i < end) ? '' : 'none'; });
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