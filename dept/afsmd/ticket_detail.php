<?php
// dept/afsmd/ticket_detail.php 
require_once __DIR__ . '/../auth_guard.php';
if (isset($_GET['logout'])) { staffLogout(); }
require_once __DIR__ . '/../../db_connect.php';
require_once __DIR__ . '/../../assign_helper.php';
require_once __DIR__ . '/../../sla_helper.php';
require_once __DIR__ . '/../../graph_helper.php';

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
$backUrl = 'tickets.php'; // safe default
$sessionKey = 'td_back_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $ticketId);
if (!empty($_GET['from'])) {
    $from = $_GET['from'];
    // Only trust relative URLs (no protocol = safe, no open redirect)
    if (!preg_match('#^https?://#', $from)) {
        $backUrl = $from;
        $_SESSION[$sessionKey] = $backUrl;
    }
} elseif (!empty($_SESSION[$sessionKey])) {
    $backUrl = $_SESSION[$sessionKey];
} elseif (!empty($_SERVER['HTTP_REFERER'])) {
    $ref = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH);
    // Only use referrer if it's NOT ticket_detail itself (avoids tab-switch self-referral)
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

// ── HANDLE POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ticket) {
    $action = trim($_POST['action'] ?? 'update');

    // ── ACTION: reassign ──────────────────────────────────────────────────────
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

            $assignRemarks = trim($_POST['assign_remarks'] ?? '');

            $asnLog = $conn->prepare("INSERT INTO ticket_logs (ticket_id,changed_by_id,changed_by,field_changed,old_priority,new_priority,remarks) VALUES (?,?,?,'assigned',?,?,?)");
            if ($asnLog) {
                $alId   = (int)$_SESSION['staff_id'];
                $alName = $_SESSION['staff_name'];
                $asnLog->bind_param("sissss", $ticketId, $alId, $alName, $oldName, $newName, $assignRemarks);
                $asnLog->execute(); $asnLog->close();
            }
            $_SESSION['flash_success'] = 'Ticket reassigned to <strong>'.htmlspecialchars($newName).'</strong>.';
        } else {
            $_SESSION['flash_error'] = 'Please select a staff member to assign.';
        }
        header('Location: ticket_detail.php?id='.urlencode($ticketId).'&tab=detail&from='.$backUrlEncoded); exit;
    }

    

    // ── ACTION: update ────────────────────────────────────────────────────────
   if ($action === 'update' || $action === 'update_with_message') {
    $assignedNow   = getAssignedStaff($conn, $ticketId);
    $isAssignedNow = ($assignedNow && (int)$assignedNow['staff_id'] === (int)($_SESSION['staff_id'] ?? 0));

    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    // Allow any staff to change priority via AJAX (priority-only = action 'update' via AJAX)
    $isPriorityOnlyAjax = $isAjax && $action === 'update';

    if (!$isAssignedNow && !$isPriorityOnlyAjax) {
        if ($isAjax) { header('Content-Type: application/json'); http_response_code(403); echo json_encode(['success'=>false,'error'=>'not_assigned']); exit; }
        $_SESSION['flash_error'] = 'Only the assigned staff can change priority or status.';
        header('Location: ticket_detail.php?id='.urlencode($ticketId).'&tab=detail&from='.$backUrlEncoded); exit;
    }

        $newPriority = trim($_POST['priority'] ?? '');
        $newStatus   = trim($_POST['status']   ?? '');
        $allowedPri  = ['low','medium','high'];
        $allowedSta  = ['open','in_progress','closed'];

        // Fetch fresh DB row for old values
        $freshStmt = $conn->prepare("SELECT priority, status, sla_start_at, first_response_at FROM complaints WHERE ticket_id = ? AND dept_id = ? LIMIT 1");
        $freshStmt->bind_param("si", $ticketId, $deptId); $freshStmt->execute();
        $freshRow = $freshStmt->get_result()->fetch_assoc(); $freshStmt->close();
        $oldPriority      = $freshRow['priority']          ?? $ticket['priority'];
        $oldStatus        = $freshRow['status']            ?? $ticket['status'];
        $oldSlaStartAt    = $freshRow['sla_start_at']      ?? null;
        $oldFirstResponse = $freshRow['first_response_at'] ?? null;

        // Prevent any change on a closed ticket (closed is final)
        if ($oldStatus === 'closed') {
            $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
            if ($isAjax) { header('Content-Type: application/json'); http_response_code(403); echo json_encode(['success'=>false,'error'=>'ticket_closed']); exit; }
            $_SESSION['flash_error'] = 'This ticket is closed and cannot be changed.';
            header('Location: ticket_detail.php?id='.urlencode($ticketId).'&tab=detail&from='.$backUrlEncoded); exit;
        }

        if (!in_array($newPriority, $allowedPri)) {
            $_SESSION['flash_error'] = 'Invalid priority.';
            header('Location: ticket_detail.php?id='.urlencode($ticketId).'&tab=detail&from='.$backUrlEncoded); exit;
        }
        if (!in_array($newStatus, $allowedSta)) {
            $_SESSION['flash_error'] = 'Invalid status.';
            header('Location: ticket_detail.php?id='.urlencode($ticketId).'&tab=detail&from='.$backUrlEncoded); exit;
        }

        $nowMysql = (new DateTime('now', new DateTimeZone(SLA_TZ)))->format('Y-m-d H:i:s');

        // ── Stamp sla_start_at on very first in_progress ──────────────────
        if (empty($oldSlaStartAt)) {
            $slaSet = $conn->prepare("UPDATE complaints SET sla_start_at=? WHERE ticket_id=? AND dept_id=?");
            $slaSet->bind_param("ssi", $nowMysql, $ticketId, $deptId);
            $slaSet->execute(); $slaSet->close();
            $oldSlaStartAt = $nowMysql; // keep in sync for logic below
        }

// ── Stamp first_response_at on first staff action (open → anything) ──────
// Covers: open→in_progress AND open→closed directly
if (empty($oldFirstResponse) && in_array($newStatus, ['in_progress', 'closed'])) {
    $frSet = $conn->prepare("UPDATE complaints SET first_response_at=? WHERE ticket_id=? AND dept_id=?");
    $frSet->bind_param("ssi", $nowMysql, $ticketId, $deptId);
    $frSet->execute(); $frSet->close();
}

        // ── Build the UPDATE complaints query ─────────────────────────────
        if ($newStatus === 'closed' && $oldStatus !== 'closed') {
            // Closing: stamp resolved_at
            $upd = $conn->prepare("UPDATE complaints SET priority=?,status=?,resolved_at=?,updated_at=NOW() WHERE ticket_id=? AND dept_id=?");
            $upd->bind_param("ssssi", $newPriority, $newStatus, $nowMysql, $ticketId, $deptId);
        } else {
            // open → in_progress or priority-only change
            $upd = $conn->prepare("UPDATE complaints SET priority=?,status=?,updated_at=NOW() WHERE ticket_id=? AND dept_id=?");
            $upd->bind_param("sssi", $newPriority, $newStatus, $ticketId, $deptId);
        }

        if ($upd->execute()) {
            // Log the change
            $priChanged  = ($oldPriority !== $newPriority);
            $statChanged = ($oldStatus   !== $newStatus);
            if ($priChanged || $statChanged) {
                $fc           = ($priChanged && $statChanged) ? 'both' : ($priChanged ? 'priority' : 'status');
                $logStaffId   = (int)$staffId;
                $logStaffName = $staffName;
                $logStmt = $conn->prepare("INSERT INTO ticket_logs (ticket_id,changed_by_id,changed_by,field_changed,old_priority,new_priority,old_status,new_status) VALUES (?,?,?,?,?,?,?,?)");
                if ($logStmt) {
                    $logStmt->bind_param("sissssss", $ticketId, $logStaffId, $logStaffName, $fc, $oldPriority, $newPriority, $oldStatus, $newStatus);
                    $logStmt->execute(); $logStmt->close();
                }
            }

            $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true]); exit;
            }

            $statusLabel = ucfirst(str_replace('_', ' ', $newStatus));

            // ── Always notify submitter on status change ───────────────────────
            // Build feedback section once — used by both email branches
            $feedbackSection = '';
            if ($newStatus === 'closed') {
                $feedbackSection = <<<FBHTML
    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
      <tr><td style="border-left:3px solid #22C55E;background:#F0FDF4;padding:16px 20px;border-radius:0 4px 4px 0;">
        <p style="margin:0 0 6px;font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#166534;">Your Feedback Matters</p>
        <p style="margin:0 0 12px;font-size:14px;color:#374151;line-height:1.75;">Your ticket has been resolved. We would appreciate it if you could take a moment to rate your experience so we can continue to improve our service.</p>
        <a href="https://rush.rcmp.edu.my/" style="display:inline-block;padding:10px 22px;background-color:#16A34A;color:#ffffff;font-size:13px;font-weight:600;text-decoration:none;border-radius:4px;">Give Feedback</a>
      </td></tr>
    </table>
FBHTML;
            }

            // Fetch submitter data once for both email branches
            $subType = $ticket['submitter_type'] ?? 'user';
            $submitterData = null;
            $emailSkippedReason = '';
            if ($subType === 'user') {
                $subQ = $conn->prepare("SELECT entra_oid FROM users WHERE user_id = ? LIMIT 1");
                $subQ->bind_param("i", $ticket['submitter_id']);
                $subQ->execute();
                $oidRow = $subQ->get_result()->fetch_assoc();
                $subQ->close();
                if ($oidRow && !empty($oidRow['entra_oid'])) {
                    $graphData = getGraphUserByOid($oidRow['entra_oid']);
                    if ($graphData) {
                        $submitterData = ['email' => $graphData['email'], 'full_name' => $graphData['name']];
                    } else {
                        $emailSkippedReason = 'graph_unavailable';
                        error_log("[UniKL Mail] No email sent for {$ticketId}: Graph lookup failed for OID {$oidRow['entra_oid']}");
                    }
                } else {
                    $emailSkippedReason = 'no_oid';
                    error_log("[UniKL Mail] No email sent for {$ticketId}: submitter (user_id={$ticket['submitter_id']}) has no entra_oid");
                }
            } else {
                $subQ = $conn->prepare("SELECT full_name, email FROM staff WHERE staff_id = ? LIMIT 1");
                $subQ->bind_param("i", $ticket['submitter_id']);
                $subQ->execute();
                $submitterData = $subQ->get_result()->fetch_assoc();
                $subQ->close();
            }

            // ── Status-only email (no message typed) ─────────────────────────
            if ($statChanged && empty(trim($_POST['message'] ?? ''))) {
                if ($submitterData && !empty($submitterData['email'])) {
                        $toName      = $submitterData['full_name'];
                        $toEmail     = $submitterData['email'];
                        $currentYear = date('Y');
                        $currentDate = date('d F Y');
                        $escapedTo   = htmlspecialchars($toName);
                        $escapedTid  = htmlspecialchars($ticketId);
                        $escapedStat = htmlspecialchars($statusLabel);
                        $statBg  = $newStatus==='closed' ? '#D1FAE5' : ($newStatus==='in_progress' ? '#DBEAFE' : '#FEF3C7');
                        $statFg  = $newStatus==='closed' ? '#059669' : ($newStatus==='in_progress' ? '#1D4ED8' : '#D97706');

                        $statusOnlyHtml = <<<HTML
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
      <span style="font-size:12px;color:rgba(255,255,255,.65);letter-spacing:.06em;text-transform:uppercase;">&#128203;&nbsp; Ticket Update — Status Changed</span>
    </td></tr></table>
  </td></tr>
  <tr><td style="background:#f7f8fa;border-bottom:1px solid #e4e7ed;padding:14px 40px;">
    <table width="100%"><tr>
      <td style="font-size:12px;color:#6b7280;">Reference No.</td>
      <td align="right" style="font-size:13px;font-weight:700;color:#00327a;font-family:monospace;">{$escapedTid}</td>
    </tr></table>
  </td></tr>
  <tr><td style="padding:36px 40px 0;">
    <p style="margin:0 0 6px;font-size:12px;color:#9ca3af;">{$currentDate}</p>
    <p style="margin:0 0 20px;font-size:15px;font-weight:600;color:#111827;">Dear {$escapedTo},</p>
    <p style="margin:0 0 24px;font-size:14px;color:#374151;line-height:1.75;">Your complaint ticket status has been updated. Please find the current status below.</p>
    <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e4e7ed;border-radius:4px;overflow:hidden;margin-bottom:24px;">
      <tr><td colspan="2" style="background:#00327a;padding:10px 18px;"><span style="font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.85);">Ticket Status Update</span></td></tr>
      <tr>
        <td style="width:40%;padding:12px 18px;background:#f7f8fa;border-bottom:1px solid #e4e7ed;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;">Ticket Reference</td>
        <td style="padding:12px 18px;border-bottom:1px solid #e4e7ed;font-size:13px;font-weight:700;color:#00327a;font-family:monospace;">{$escapedTid}</td>
      </tr>
      <tr>
        <td style="width:40%;padding:12px 18px;background:#f7f8fa;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;">Current Status</td>
        <td style="padding:12px 18px;"><span style="display:inline-block;font-size:12px;font-weight:600;padding:3px 12px;border-radius:20px;background:{$statBg};color:{$statFg};">{$escapedStat}</span></td>
      </tr>
    </table>
    {$feedbackSection}
    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
      <tr><td style="border-left:3px solid #e8b200;background:#fffdf0;padding:16px 20px;border-radius:0 4px 4px 0;">
        <p style="margin:0 0 6px;font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#92700a;">Note</p>
        <p style="margin:0 0 12px;font-size:14px;color:#374151;line-height:1.75;">Please log in to the UniKL RCMP Help Desk portal to view full details of this ticket.</p>
        <a href="https://rush.rcmp.edu.my/" style="display:inline-block;padding:10px 22px;background-color:#00327a;color:#ffffff;font-size:13px;font-weight:600;text-decoration:none;border-radius:4px;">Login to Portal</a>
      </td></tr>
    </table>
    <table width="100%"><tr><td style="height:1px;background:#e4e7ed;"></td></tr></table>
    <p style="margin:20px 0 4px;font-size:14px;color:#374151;">Yours sincerely,</p>
    <p style="margin:0 0 2px;font-size:14px;font-weight:700;color:#00327a;">UniKL RCMP Help Desk Team</p>
    <p style="margin:0 0 28px;font-size:12px;color:#9ca3af;">Universiti Kuala Lumpur</p>
  </td></tr>
  <tr><td style="background:#f7f8fa;border-top:1px solid #e4e7ed;padding:20px 40px;">
    <p style="margin:0;font-size:11px;color:#9ca3af;">This is a system-generated notification. Please do not reply directly to this email. &bull; &copy; {$currentYear} Universiti Kuala Lumpur.</p>
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
                            $mail->addAddress($toEmail,$toName);
                            $mail->isHTML(true); $mail->CharSet='UTF-8';
                            $mail->Subject="Ticket Status Updated ({$statusLabel}) — {$ticketId}";
                            $mail->Body=$statusOnlyHtml;
                            $mail->AltBody="Status updated to: {$statusLabel}\n\nTicket: {$ticketId}\n\nLogin: https://rush.rcmp.edu.my/";
                            $mail->send();
                        } catch (Exception $e) {
                            error_log("[UniKL Mail] Status-only email failed for {$ticketId}: ".$mail->ErrorInfo);
                        }
                }
            } // end status-only email

            // ── Send message if provided ──────────────────────────────────────
            $inlineMessage = trim($_POST['message'] ?? '');
            if (!empty($inlineMessage)) {
                $senderName = $_SESSION['staff_name'] ?? 'Staff';
                $senderRole = 'staff';
                $senderId   = (int)($_SESSION['staff_id'] ?? 0);
                $ins = $conn->prepare("INSERT INTO ticket_replies (ticket_id,sender_id,sender_name,sender_role,message) VALUES (?,?,?,?,?)");
                $ins->bind_param("sisss", $ticketId, $senderId, $senderName, $senderRole, $inlineMessage);
                $ins->execute(); $ins->close();

                if ($submitterData && !empty($submitterData['email'])) {
                        $toName      = $submitterData['full_name'];
                        $toEmail     = $submitterData['email'];
                        $currentYear = date('Y');
                        $currentDate = date('d F Y');
                        $escapedTo   = htmlspecialchars($toName);
                        $escapedTid  = htmlspecialchars($ticketId);
                        $escapedMsg  = nl2br(htmlspecialchars($inlineMessage));
                        $escapedFrom = htmlspecialchars($senderName);
                        $escapedStat = htmlspecialchars($statusLabel);
                        $statBg  = $newStatus==='closed' ? '#D1FAE5' : ($newStatus==='in_progress' ? '#DBEAFE' : '#FEF3C7');
                        $statFg  = $newStatus==='closed' ? '#059669' : ($newStatus==='in_progress' ? '#1D4ED8' : '#D97706');

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
      <span style="font-size:12px;color:rgba(255,255,255,.65);letter-spacing:.06em;text-transform:uppercase;">&#128203;&nbsp; Ticket Update — Message from Staff</span>
    </td></tr></table>
  </td></tr>
  <tr><td style="background:#f7f8fa;border-bottom:1px solid #e4e7ed;padding:14px 40px;">
    <table width="100%"><tr>
      <td style="font-size:12px;color:#6b7280;">Reference No.</td>
      <td align="right" style="font-size:13px;font-weight:700;color:#00327a;font-family:monospace;">{$escapedTid}</td>
    </tr></table>
  </td></tr>
  <tr><td style="padding:36px 40px 0;">
    <p style="margin:0 0 6px;font-size:12px;color:#9ca3af;">{$currentDate}</p>
    <p style="margin:0 0 20px;font-size:15px;font-weight:600;color:#111827;">Dear {$escapedTo},</p>
    <p style="margin:0 0 24px;font-size:14px;color:#374151;line-height:1.75;">Your complaint ticket has been updated by a staff member. Please find the current status and message below.</p>
    <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e4e7ed;border-radius:4px;overflow:hidden;margin-bottom:20px;">
      <tr><td colspan="2" style="background:#00327a;padding:10px 18px;"><span style="font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.85);">Ticket Status Update</span></td></tr>
      <tr>
        <td style="width:40%;padding:12px 18px;background:#f7f8fa;border-bottom:1px solid #e4e7ed;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;">Ticket Reference</td>
        <td style="padding:12px 18px;border-bottom:1px solid #e4e7ed;font-size:13px;font-weight:700;color:#00327a;font-family:monospace;">{$escapedTid}</td>
      </tr>
      <tr>
        <td style="width:40%;padding:12px 18px;background:#f7f8fa;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;">Current Status</td>
        <td style="padding:12px 18px;"><span style="display:inline-block;font-size:12px;font-weight:600;padding:3px 12px;border-radius:20px;background:{$statBg};color:{$statFg};">{$escapedStat}</span></td>
      </tr>
    </table>
    <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e4e7ed;border-radius:4px;overflow:hidden;margin-bottom:24px;">
      <tr><td style="background:#00327a;padding:10px 18px;"><span style="font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.85);">Message from {$escapedFrom}</span></td></tr>
      <tr><td style="padding:16px 18px;background:#f7f8fa;font-size:14px;color:#374151;line-height:1.75;">{$escapedMsg}</td></tr>
    </table>
{$feedbackSection}
<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
  <tr><td style="border-left:3px solid #e8b200;background:#fffdf0;padding:16px 20px;border-radius:0 4px 4px 0;">
    <p style="margin:0 0 6px;font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#92700a;">Note</p>
    <p style="margin:0 0 12px;font-size:14px;color:#374151;line-height:1.75;">Please log in to the UniKL RCMP Help Desk portal to view full details of this ticket.</p>
    <a href="https://rush.rcmp.edu.my/" style="display:inline-block;padding:10px 22px;background-color:#00327a;color:#ffffff;font-size:13px;font-weight:600;text-decoration:none;border-radius:4px;">Login to Portal</a>
  </td></tr>
</table>
    <table width="100%"><tr><td style="height:1px;background:#e4e7ed;"></td></tr></table>
    <p style="margin:20px 0 4px;font-size:14px;color:#374151;">Yours sincerely,</p>
    <p style="margin:0 0 2px;font-size:14px;font-weight:700;color:#00327a;">UniKL RCMP Help Desk Team</p>
    <p style="margin:0 0 28px;font-size:12px;color:#9ca3af;">Universiti Kuala Lumpur</p>
  </td></tr>
  <tr><td style="background:#f7f8fa;border-top:1px solid #e4e7ed;padding:20px 40px;">
    <p style="margin:0;font-size:11px;color:#9ca3af;">This is a system-generated notification. Please do not reply directly to this email. &bull; &copy; {$currentYear} Universiti Kuala Lumpur.</p>
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
                            $mail->addAddress($toEmail,$toName);
                            $mail->isHTML(true); $mail->CharSet='UTF-8';
                            $mail->Subject="Ticket Update ({$statusLabel}) — {$ticketId}";
                            $mail->Body=$htmlBody;
                            $mail->AltBody="Status: {$statusLabel}\n\nMessage from {$senderName}:\n\n{$inlineMessage}\n\nTicket: {$ticketId}";
                            $mail->send();
                        } catch (Exception $e) {
                            error_log("[UniKL Mail] Merged message send failed for {$ticketId}: ".$mail->ErrorInfo);
                        }
                }
            } // end if(!empty($inlineMessage))

            // ── Fire processQueue ALWAYS (not just when message sent) ──────────
            if ($statChanged && in_array($newStatus, ['in_progress', 'closed'])) {
                processQueue($conn, $deptId, (int)$staffId);
            }

            $_SESSION['flash_success'] = 'Ticket updated — status: <strong>'.htmlspecialchars($statusLabel).'</strong>.';

            if (!empty($emailSkippedReason)) {
                $_SESSION['flash_warning'] = 'Note: notification email could not be sent to the submitter (their Microsoft account info is currently unavailable). The status/message has been saved successfully.';
            }

        } else {
            $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
            if ($isAjax) { header('Content-Type: application/json'); http_response_code(500); echo json_encode(['success'=>false]); exit; }
            $_SESSION['flash_error'] = 'Failed to update.';
        }
        $upd->close();
        header('Location: ticket_detail.php?id='.urlencode($ticketId).'&tab=detail&from='.$backUrlEncoded); exit;
    } // end update

} // end POST handler


if (!empty($_GET['action']) && $_GET['action']==='get_logs' && !empty($_GET['id'])) {
    $ajaxTid = trim($_GET['id']); $logs = [];
    $lg = $conn->prepare("
        SELECT 
            'log' AS source,
            log_id AS row_id,
            changed_by,
            field_changed,
            old_priority,
            new_priority,
            old_status,
            new_status,
            NULL AS message_content,
            COALESCE(remarks, '') AS remarks,
            changed_at AS event_at
        FROM ticket_logs
        WHERE ticket_id = ?
        UNION ALL
        SELECT
            'reply' AS source,
            reply_id AS row_id,
            sender_name AS changed_by,
            'message' AS field_changed,
            NULL AS old_priority,
            NULL AS new_priority,
            NULL AS old_status,
            NULL AS new_status,
            message AS message_content,
            '' AS remarks,
            created_at AS event_at
        FROM ticket_replies
        WHERE ticket_id = ? AND sender_role = 'staff'
        ORDER BY event_at DESC
    ");
    $lg->bind_param("ss",$ajaxTid,$ajaxTid); $lg->execute();
    $logs = $lg->get_result()->fetch_all(MYSQLI_ASSOC); $lg->close();
    header('Content-Type: application/json'); echo json_encode(['logs'=>$logs]); exit;
}

$updateMsg     = $_SESSION['flash_success'] ?? '';
$updateError   = $_SESSION['flash_error']   ?? '';
$updateWarning = $_SESSION['flash_warning'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error'], $_SESSION['flash_warning']);

if ($ticketId !== '') {
    $stmt = $conn->prepare("SELECT c.*, cat.category_name FROM complaints c LEFT JOIN categories cat ON cat.category_id = c.category_id WHERE c.ticket_id = ? AND c.dept_id = ? LIMIT 1");
    $stmt->bind_param("si", $ticketId, $deptId); $stmt->execute();
    $ticket = $stmt->get_result()->fetch_assoc(); $stmt->close();
}

$submitter = null;
if ($ticket) {
$type  = $ticket['submitter_type'] ?? 'user';
if ($type === 'user') {
    $s2 = $conn->prepare("SELECT entra_oid FROM users WHERE user_id = ? LIMIT 1");
} else {
    $s2 = $conn->prepare("SELECT full_name AS name, email FROM staff WHERE staff_id = ? LIMIT 1");
}
if ($s2) {
    $s2->bind_param("i", $ticket['submitter_id']);
    $s2->execute();
    $submitter = $s2->get_result()->fetch_assoc();
    $s2->close();
}

// ── Resolve name/email from MS Graph for 'user' submitters ────────────────
if ($type === 'user') {
    $oid = $submitter['entra_oid'] ?? '';
    if (!empty($oid)) {
        $graphData = getGraphUserByOid($oid);
        if ($graphData) {
            $submitter['name']  = $graphData['name'];
            $submitter['email'] = $graphData['email'];
        } else {
            // Graph failed — show partial info so page isn't blank
            $submitter['name']  = 'User (OID: ' . substr($oid, 0, 8) . '…)';
            $submitter['email'] = '— (Graph lookup failed)';
        }
    } else {
        $submitter['name']  = '— (No OID on record)';
        $submitter['email'] = '—';
    }
}

}

$changeLogs = [];
if ($ticket) {
    $lg = $conn->prepare("
        SELECT 
            'log' AS source,
            log_id AS row_id,
            changed_by,
            field_changed,
            old_priority,
            new_priority,
            old_status,
            new_status,
            NULL AS message_content,
            COALESCE(remarks, '') AS remarks,
            changed_at AS event_at
        FROM ticket_logs
        WHERE ticket_id = ?
        UNION ALL
        SELECT
            'reply' AS source,
            reply_id AS row_id,
            sender_name AS changed_by,
            'message' AS field_changed,
            NULL AS old_priority,
            NULL AS new_priority,
            NULL AS old_status,
            NULL AS new_status,
            message AS message_content,
            '' AS remarks,
            created_at AS event_at
        FROM ticket_replies
        WHERE ticket_id = ? AND sender_role = 'staff'
        ORDER BY event_at DESC
    ");
    $lg->bind_param("ss",$ticketId,$ticketId); $lg->execute();
    $changeLogs = $lg->get_result()->fetch_all(MYSQLI_ASSOC); $lg->close();
}

$assignedStaff = null;
if ($ticket) { $assignedStaff = getAssignedStaff($conn, $ticketId); }

$currentStaffId  = (int)($_SESSION['staff_id'] ?? 0);
$isAssignedStaff = ($assignedStaff && (int)$assignedStaff['staff_id'] === $currentStaffId);

$deptStaffList = [];
$dsStmt = $conn->prepare("SELECT staff_id, full_name FROM staff WHERE dept_id = ? AND status = 'active' AND role = 'staff' ORDER BY staff_id ASC");
$dsStmt->bind_param("i", $deptId); $dsStmt->execute();
$dsRes = $dsStmt->get_result();
while ($row = $dsRes->fetch_assoc()) $deptStaffList[] = $row;
$dsStmt->close();

$replies = [];
if ($ticket) {
    $rq = $conn->prepare("SELECT reply_id,sender_id,sender_name,sender_role,message,attachment_path,created_at FROM ticket_replies WHERE ticket_id=? ORDER BY created_at ASC");
    $rq->bind_param("s",$ticketId); $rq->execute();
    $replies = $rq->get_result()->fetch_all(MYSQLI_ASSOC); $rq->close();
}

$feedback = null;
if ($ticket && strtolower($ticket['status']) === 'closed') {
    $fq = $conn->prepare("
    SELECT rating, comment, is_auto_submitted, created_at, submitter_name
    FROM v_ticket_feedback_summary
    WHERE ticket_id = ?
    LIMIT 1
");
    if ($fq) {
        $fq->bind_param("s", $ticketId);
        $fq->execute();
        $feedback = $fq->get_result()->fetch_assoc();
        $fq->close();
    }
}

$slaData = null;
if ($ticket && !empty($ticket['created_at'])) {

    // Also get first_log_response_at (same fallback as dashboard/reports)
    $firstLogResponse = null;
    $flrStmt = $conn->prepare("
        SELECT MIN(changed_at) AS first_log_ts
        FROM ticket_logs
        WHERE ticket_id = ?
          AND new_status IN ('in_progress','closed')
          AND old_status = 'open'
    ");
    $flrStmt->bind_param("s", $ticketId);
    $flrStmt->execute();
    $flrRow = $flrStmt->get_result()->fetch_assoc();
    $flrStmt->close();
    $firstLogResponse = $flrRow['first_log_ts'] ?? null;

    // Best respond timestamp: column first, then logs fallback
    $bestRespondTs = null;
    if (!empty($ticket['first_response_at'])) {
        $bestRespondTs = $ticket['first_response_at'];
    } elseif (!empty($firstLogResponse)) {
        $bestRespondTs = $firstLogResponse;
    }

    // For OPEN tickets with no response: clock runs from created_at, no stop
    $slaFirstResponse = null;
    if (strtolower($ticket['status']) !== 'open') {
        $slaFirstResponse = $bestRespondTs;
    }

    $slaData = getSlaStatus(
        $ticket['created_at'],        // ← FIX: use created_at (matches reports/dashboard)
        $ticket['resolved_at'] ?? null,
        $ticket['status'],
        $slaFirstResponse
    );
}

// Active tab from URL
$activeTab = $_GET['tab'] ?? 'detail';
if (!in_array($activeTab, ['detail','history','feedback'])) $activeTab = 'detail';

// ── Helpers (all unchanged) ────────────────────────────────────────────────────
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
    if ($ext === 'pdf') { return ['label'=>'PDF','color'=>'#DC2626','bg'=>'#FEF2F2']; }
    elseif ($ext === 'doc' || $ext === 'docx') { return ['label'=>'DOC','color'=>'#1D4ED8','bg'=>'#EFF6FF']; }
    elseif ($ext === 'txt') { return ['label'=>'TXT','color'=>'#374151','bg'=>'#F9FAFB']; }
    else { return ['label'=>strtoupper($ext),'color'=>'#6B7280','bg'=>'#F3F4F6']; }
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
    if ($rating === 1) { return 'Very Unsatisfied'; }
    elseif ($rating === 2) { return 'Unsatisfied'; }
    elseif ($rating === 3) { return 'Neutral'; }
    elseif ($rating === 4) { return 'Satisfied'; }
    elseif ($rating === 5) { return 'Very Satisfied'; }
    else { return 'Unknown'; }
}
function ratingColors(int $rating): array {
    if ($rating === 1) { return ['#FEF2F2','#DC2626','#EF4444']; }
    elseif ($rating === 2) { return ['#FFF7ED','#C2410C','#F97316']; }
    elseif ($rating === 3) { return ['#FEFCE8','#854D0E','#EAB308']; }
    elseif ($rating === 4) { return ['#F0FDF4','#166534','#22C55E']; }
    elseif ($rating === 5) { return ['#ECFDF5','#166534','#16A34A']; }
    else { return ['#F3F4F6','#374151','#6B7280']; }
}

$isClosed   = $ticket && strtolower($ticket['status']) === 'closed';
$hasFeedback= $feedback !== null;

$activeNav    = 'tickets';
$pageTitle    = 'Ticket Detail';
$pageSubtitle = 'Administration & Facilities Management Department';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Ticket Detail | UniKL Help Desk – AFSMD</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="css/tickets_details.css">
  
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

  <?php if ($updateWarning): ?>
  <div class="td-alert" style="background:#FFFBEB;border:1px solid #FDE68A;color:#92400E;display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:13.5px;">
    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#D97706" stroke-width="2" style="flex-shrink:0"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    <span><?php echo htmlspecialchars($updateWarning); ?></span>
  </div>
  <?php endif; ?>

  <!-- Ticket header strip (always visible above tabs) -->
<div class="ticket-header-strip">
  <div class="ths-icon">
    <svg viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
  </div>
  <div class="ths-info">
    <div class="ths-title"><?php echo htmlspecialchars(preg_replace('/^[A-Z]+\s*\/\s*/', '', $ticket['title'])); ?></div>
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
                <div class="ti-submitter-val">
    <?php echo htmlspecialchars($submitter['name'] ?? '—'); ?>
</div>
              </div>
              <div class="ti-submitter-cell">
                <div class="ti-submitter-lbl">Email</div>
                <div class="ti-submitter-val">
    <?php echo htmlspecialchars($submitter['email'] ?? '—'); ?>
</div>
              </div>
              <div class="ti-submitter-cell" style="border-right:none">
  <div class="ti-submitter-lbl">Phone</div>
  <div class="ti-submitter-val">+60 <?php echo htmlspecialchars($ticket['phone']??'—'); ?></div>
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
                <?php if (strtolower($ticket['status']) !== 'in_progress' || empty($ticket['first_response_at'])): ?>
<div class="sla-status-chip" style="background:<?php echo $slaData['status_bg']; ?>;color:<?php echo $slaData['status_color']; ?>">
  <?php echo htmlspecialchars($slaData['status_label']); ?>
</div>
<?php else: ?>
<div class="sla-status-chip" style="background:#D1FAE5;color:#059669;">
  SLA Stopped
</div>
<?php endif; ?>
                <?php if (strtolower($ticket['status']) !== 'closed' && empty($ticket['first_response_at'])): ?>
<div class="sla-remaining-big" style="color:<?php echo $slaData['status_color']; ?>">
  <?php echo htmlspecialchars($slaData['remaining_str']); ?>
</div>
<div class="sla-remaining-sub">until SLA deadline</div>
<?php elseif (strtolower($ticket['status']) === 'in_progress' && !empty($ticket['first_response_at'])): ?>
<div class="sla-remaining-big" style="color:#059669;">
  <?php 
    $em = $slaData['elapsed_mins'];
    $eh = intdiv($em, 60); $emm = $em % 60;
    echo $eh > 0 ? "{$eh}h {$emm}m used" : "{$emm}m used";
  ?>
</div>
<div class="sla-remaining-sub"></div>
<?php endif; ?>
              </div>
              <div class="sla-inline-right">
                <?php $fillPct = min($slaData['percent_used'], 100); ?>
                <div class="sla-progress-wrap">
                  <div class="sla-progress-fill" style="width:<?php echo $fillPct; ?>%;background:<?php echo $slaData['status_color']; ?>"></div>
                </div>
                <div class="sla-tick-row"><span>0h</span><span>4h</span><span>8h</span></div>
                <?php
  $ticketStatus = strtolower($ticket['status']);
  $em = $slaData['elapsed_mins'];
  $eh = intdiv($em, 60); $emm = $em % 60;
  if ($ticketStatus === 'open' && empty($ticket['first_response_at'])) {
    $timeUsedStr = ($eh > 0 ? "{$eh}h {$emm}m" : "{$emm}m") . ' / ' . SLA_WORK_HOURS . 'h';
} else {
    $timeUsedStr = ($eh > 0 ? "{$eh}h {$emm}m" : "{$emm}m") . ' used';
}

  if ($ticketStatus === 'closed') {
      $timeUsedNote = 'Working hours from submission to close';
  } elseif (!empty($ticket['first_response_at'])) {
      $timeUsedNote = 'Working hours from submission to first response';
  } else {
      $timeUsedNote = 'Working hours elapsed since submission';
  }

  $respondedVal  = !empty($ticket['first_response_at'])
      ? date('d M Y, H:i', strtotime($ticket['first_response_at'])) : '—';
  $respondedNote = !empty($ticket['first_response_at'])
      ? 'Staff first moved ticket to In Progress or Closed' : 'No staff response yet';

  $closedVal  = ($ticketStatus === 'closed' && !empty($ticket['resolved_at']))
      ? date('d M Y, H:i', strtotime($ticket['resolved_at'])) : '—';
  $closedNote = ($ticketStatus === 'closed' && !empty($ticket['resolved_at']))
      ? 'Ticket was marked as closed'
      : ($ticketStatus === 'closed' ? 'Closed (no timestamp)' : 'Ticket not yet closed');
?>
<div class="sla-info-grid" style="grid-template-columns: repeat(5,1fr);">

  <div>
    <div class="sla-info-label">Submitted</div>
    <div class="sla-info-value"><?php echo date('d M Y, H:i', strtotime($ticket['created_at'])); ?></div>
    <div style="font-size:10px;color:#9CA3AF;margin-top:3px;line-height:1.4;">Ticket submission date &amp; time</div>
  </div>

  <div>
    <div class="sla-info-label">Deadline</div>
    <?php if ($ticketStatus === 'open' && empty($ticket['first_response_at'])): ?>
      <div class="sla-info-value"><?php echo $slaData['deadline_str']; ?></div>
      <div style="font-size:10px;color:#9CA3AF;margin-top:3px;line-height:1.4;">Must respond before this time</div>
    <?php else: ?>
      <div class="sla-info-value" style="color:#9CA3AF;">—</div>
      <div style="font-size:10px;color:#9CA3AF;margin-top:3px;line-height:1.4;">SLA clock stopped after response</div>
    <?php endif; ?>
  </div>

  <div>
    <div class="sla-info-label">Time Used</div>
    <div class="sla-info-value"><?php echo $timeUsedStr; ?></div>
    <div style="font-size:10px;color:#9CA3AF;margin-top:3px;line-height:1.4;"><?php echo $timeUsedNote; ?></div>
  </div>

  <div>
    <div class="sla-info-label">Responded At</div>
    <div class="sla-info-value" style="<?php echo empty($ticket['first_response_at']) ? 'color:#9CA3AF;' : ''; ?>">
      <?php echo $respondedVal; ?>
    </div>
    <div style="font-size:10px;color:#9CA3AF;margin-top:3px;line-height:1.4;"><?php echo $respondedNote; ?></div>
  </div>

  <div>
    <div class="sla-info-label">Closed At</div>
    <div class="sla-info-value" style="<?php echo ($ticketStatus !== 'closed') ? 'color:#9CA3AF;' : ''; ?>">
      <?php echo $closedVal; ?>
    </div>
    <div style="font-size:10px;color:#9CA3AF;margin-top:3px;line-height:1.4;"><?php echo $closedNote; ?></div>
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
                <div class="assigned-role-tag">AFSMD Staff</div>
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
                <select name="new_staff_id" class="reassign-select" id="reassignSelect" required onchange="handleReassignChange(this)">
                  <option value="">— Select staff —</option>
                  <?php foreach ($deptStaffList as $s): ?>
                  <?php if ((int)$s['staff_id'] === (int)($assignedStaff['staff_id'] ?? 0)) continue; ?>
                  <option value="<?php echo $s['staff_id']; ?>">
                    <?php echo htmlspecialchars($s['full_name']); ?>
                  </option>
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
              <div class="pri-status-divider"><span>Status</span></div>
<form method="POST" action="ticket_detail.php?id=<?php echo urlencode($ticketId); ?>" id="updateForm">
  <input type="hidden" name="action" value="update_with_message"/>
  <input type="hidden" name="priority" id="priorityInput" value="<?php echo htmlspecialchars($curPri); ?>"/>
                
<div class="status-select-wrap">
  <select name="status" id="status" class="status-select-styled" onchange="handleStatusChange(this.value)">
    <?php if ($curStat === 'open'): ?>
  <option value="" disabled selected>— Select action —</option>
  <option value="in_progress">In Progress</option>
  <option value="closed">Closed</option>
<?php elseif ($curStat === 'in_progress'): ?>
  <option value="" disabled selected>— Select action —</option>
  <option value="closed">Closed</option>
<?php else: ?>
  <option value="closed" selected>Closed</option>
<?php endif; ?>
  </select>
</div>

<?php if ($curStat !== 'closed'): ?>
<div id="msgBox" style="display:none;">
  <div class="msg-section-divider"></div>
  <div class="msg-section-label">
    <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
    Message to Submitter <span style="color:#9CA3AF;font-weight:400;font-size:10px;margin-left:4px;">(optional)</span>
  </div>
  <textarea name="message" class="msg-inline-textarea" placeholder="Type a message to the submitter…" maxlength="2000" rows="3"></textarea>
</div>
<?php endif; ?>

<button type="button" class="btn-update-save" onclick="openConfirmModal()">Save Changes</button>
</form>



            <?php else: ?>
  <div class="no-permission-box">
    <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
    <div>
      <div class="no-permission-title">Limited permission</div>
      <div class="no-permission-desc">You can change <strong>priority</strong>. Only <strong><?php echo htmlspecialchars($assignedStaff['full_name'] ?? 'the assigned staff'); ?></strong> can change the status.</div>
    </div>
  </div>

  <div class="pri-label-sm">Priority <span id="priSavingSpinner" style="display:none;font-size:10px;color:#9CA3AF;font-weight:400;margin-left:3px">saving…</span></div>
  <div class="pri-btn-group">
    <?php foreach(['low'=>'Low','medium'=>'Medium','high'=>'High'] as $val=>$label): ?>
    <button type="button" class="pri-btn <?php echo $curPri===$val?'active':''; ?>" data-pri="<?php echo $val; ?>" onclick="selectPriorityAutoSave('<?php echo $val; ?>',this)">
      <span class="pri-dot"></span><?php echo $label; ?>
    </button>
    <?php endforeach; ?>
  </div>

  <input type="hidden" id="priorityInput" value="<?php echo htmlspecialchars($curPri); ?>"/>
<div class="pri-status-divider"><span>Status</span></div>
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
          if ($fc === 'priority') { $dotCls = 'pri'; }
          elseif ($fc === 'status') { $dotCls = 'stat'; }
          elseif ($fc === 'assigned') { $dotCls = 'asgn'; }
          elseif ($fc === 'conversation') { $dotCls = 'conv'; }
          elseif ($fc === 'message') { $dotCls = 'msg'; }
          else { $dotCls = 'both'; }
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
              <?php elseif($fc==='message'):?>
  <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><line x1="9" y1="10" x2="15" y2="10" stroke-linecap="round"/><line x1="9" y1="14" x2="13" y2="14" stroke-linecap="round"/></svg>
            <?php else:?>
              <svg viewBox="0 0 24 24"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/></svg>
            <?php endif; ?>
          </div>
          <div class="tl-body">
            <div class="tl-header">
  <span class="tl-who"><?php echo htmlspecialchars($log['changed_by']); ?></span>
  <span class="tl-when"><?php echo timeAgo($log['event_at']); ?></span>
</div>
<span class="tl-when-full"><?php echo date('d M Y, H:i',strtotime($log['event_at'])); ?></span>
            <div class="tl-changes" style="margin-top:5px">
              <?php if(in_array($fc,['priority','both'])&&$log['old_priority']&&$log['new_priority']):?>
              <div class="tl-row"><span class="tl-row-label">Priority</span><?php echo priChip($log['old_priority']); ?><span class="tl-arrow">→</span><?php echo priChip($log['new_priority']); ?></div>
              <?php endif; ?>
              <?php if(in_array($fc,['status','both'])&&$log['old_status']&&$log['new_status']):?>
              <div class="tl-row"><span class="tl-row-label">Status</span><?php echo statChip($log['old_status']); ?><span class="tl-arrow">→</span><?php echo statChip($log['new_status']); ?></div>
              <?php endif; ?>
              <?php if($fc==='assigned'): ?>
<div class="tl-row">
    <span class="tl-row-label">Action</span>
    <span class="tl-chip-name" style="background:#EFF6FF;color:#1D4ED8;">Ticket Reassigned</span>
</div>
<div class="tl-row" style="margin-top:4px;">
    <span class="tl-row-label">Assigned to</span>
    <?php $fromName = $log['old_priority'] ?? null; $toName = $log['new_priority'] ?? null; ?>
    <?php if ($fromName && $fromName !== 'Unassigned'): ?>
        <span class="tl-chip-name"><?php echo htmlspecialchars($fromName); ?></span>
        <span class="tl-arrow">→</span>
    <?php else: ?>
        <span class="tl-chip-name" style="color:#9CA3AF;background:#F9FAFB;">Unassigned</span>
        <span class="tl-arrow">→</span>
    <?php endif; ?>
    <span class="tl-chip-name tl-chip-name--new"><?php echo htmlspecialchars($toName ?? '—'); ?></span>
</div>
<?php if (!empty($log['remarks'])): ?>
<div class="tl-msg-bubble" style="border-left-color:#6366F1;background:#EEF2FF;color:#3730A3;margin-top:6px;">
    <span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#818CF8;display:block;margin-bottom:3px;">Remarks</span>
    <?php echo nl2br(htmlspecialchars($log['remarks'])); ?>
</div>
<?php endif; ?>
<?php endif; ?>
              <?php if($fc==='conversation'): ?>
              <div class="tl-row">
                <span class="tl-row-label">Chat</span>
                <span class="tl-chip-conv">Started first reply to ticket</span>
              </div>
              <?php endif; ?>

              <?php if($fc==='message'): ?>
<div class="tl-row">
  <span class="tl-row-label">Message</span>
  <span class="tl-chip-conv" style="background:#EEF2FF;color:#3730A3;">
    <?php echo $log['source'] === 'reply' ? 'Sent a message' : 'Sent message with status update'; ?>
  </span>
</div>
<?php if(!empty($log['message_content'])): ?>
<div class="tl-msg-bubble">
  <?php echo nl2br(htmlspecialchars($log['message_content'])); ?>
</div>
<?php endif; ?>
<?php if(!empty($log['new_status']) && $log['source'] === 'log'): ?>
<div class="tl-row" style="margin-top:4px;">
  <span class="tl-row-label">Status</span>
  <?php echo statChip($log['new_status']); ?>
</div>
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
    <?php
    if ($hasFeedback) {
        if ($feedback['submitter_type'] === 'user') {
            $fbName = 'User';
            if (!empty($submitter['entra_oid'])) {
                $fbGraph = getGraphUserByOid($submitter['entra_oid']);
                if ($fbGraph) $fbName = $fbGraph['name'];
            }
            echo 'Submitted by ' . htmlspecialchars($fbName);
        } else {
            echo 'Submitted by ' . htmlspecialchars($feedback['submitter_name'] ?? 'Staff');
        }
    } else {
        echo 'Awaiting feedback';
    }
    ?>
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
                <?php echo htmlspecialchars($feedback['submitter_name'] ?? '—'); ?> &nbsp;·&nbsp;
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
              <div class="fb-no-feedback-sub">The submitter hasn't submitted feedback for this ticket.</div>
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

<!-- ══ Validation Alert Modal ══ -->
<div class="modal-backdrop" id="validationModal">
  <div class="td-modal" style="max-width:360px;position:relative;overflow:hidden;padding:0;text-align:left;">
    
    <!-- Red alert header band -->
    <div style="background:#DC2626;padding:20px 24px 18px;display:flex;align-items:center;gap:12px;">
      <div style="width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,0.2);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
      </div>
      <div>
        <div style="font-size:10px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:rgba(255,255,255,0.7);margin-bottom:3px;">Warning</div>
        <div style="font-size:16px;font-weight:700;color:white;line-height:1.2;">No Status Selected</div>
      </div>
    </div>

    <!-- Body -->
    <div style="padding:20px 24px 22px;">
      <p style="font-size:13.5px;color:#374151;line-height:1.65;margin:0 0 14px;">You must select a <strong>new status</strong> from the dropdown before saving. The status cannot stay the same.</p>
      
      <!-- Current status highlight box -->
      <div style="background:#FEF2F2;border:1.5px solid #FECACA;border-left:4px solid #DC2626;border-radius:0 8px 8px 0;padding:10px 14px;margin-bottom:18px;display:flex;align-items:center;gap:9px;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#DC2626" stroke-width="2.5" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        <span style="font-size:12.5px;color:#991B1B;line-height:1.5;">Current status is already <strong id="validationCurrentStatus"></strong> — you must pick a different one.</span>
      </div>

      <!-- Action button -->
      <button onclick="closeValidationModal()" style="width:100%;padding:11px;border-radius:8px;border:1.5px solid #E5E7EB;background:white;color:#374151;font-family:'DM Sans',sans-serif;font-size:14px;font-weight:600;cursor:pointer;" onmouseover="this.style.background='#F9FAFB'" onmouseout="this.style.background='white'">Dismiss</button>
    </div>

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
// ── Reassign select — show remarks + button only when a staff is chosen ───────
function handleReassignChange(sel) {
  var remarksBox = document.getElementById('reassignRemarksBox');
  var saveBtn    = document.getElementById('reassignSaveBtn');
  var show = sel.value !== '';
  remarksBox.style.display = show ? 'block' : 'none';
  saveBtn.style.display    = show ? 'block' : 'none';
}

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

function handleStatusChange(val) {
  document.querySelector('.btn-update-save').style.display = 'block';
  var msgBox = document.getElementById('msgBox');
  if (msgBox) {
    msgBox.style.display = (val === 'in_progress' || val === 'closed') ? 'block' : 'none';
  }
}
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
    // Update sidebar chip (already exists)
    var ce=document.getElementById('ticketPriorityChip');
    if(ce&&priFlagMap[priority])ce.innerHTML=priFlagMap[priority];
    // ← ADD THIS: Update header strip priority flag
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


// ── Confirm modal ─────────────────────────────────────────────────────────────
function openConfirmModal(){
  var status = document.getElementById('status').value;
  var currentStatus = '<?php echo addslashes($curStat); ?>';

  if (!status || status === currentStatus) {
    var label = {'open':'Open','in_progress':'In Progress','closed':'Closed'};
    var el = document.getElementById('validationCurrentStatus');
    if (el) el.textContent = label[currentStatus] || currentStatus;
    document.getElementById('validationModal').classList.add('show');
    return;
  }

  var isReopening=(currentTicketStatus==='closed'&&status!=='closed');
  var map={
    open:'<span style="display:inline-block;font-size:12.5px;font-weight:600;padding:2px 10px;border-radius:20px;background:#FEF3C7;color:#D97706">Open</span>',
    in_progress:'<span style="display:inline-block;font-size:12.5px;font-weight:600;padding:2px 10px;border-radius:20px;background:#DBEAFE;color:#1D4ED8">In Progress</span>',
    closed:'<span style="display:inline-block;font-size:12.5px;font-weight:600;padding:2px 10px;border-radius:20px;background:#D1FAE5;color:#059669">Closed</span>'
  };
  document.getElementById('modalStatVal').innerHTML=map[status]||status;
  var slaRow=document.getElementById('slaResetRow');
  if(slaRow)slaRow.style.display=isReopening?'flex':'none';
  var titleEl=document.getElementById('confirmModalTitle');
  var descEl=document.getElementById('confirmModalDesc');
  if(isReopening){if(titleEl)titleEl.textContent='Reopen & Reset SLA?';if(descEl)descEl.textContent='This will reopen the ticket and start a fresh 8-hour SLA window from now.';}
  else{if(titleEl)titleEl.textContent='Update Status?';if(descEl)descEl.textContent='Confirm the status change you are about to apply.';}
  document.getElementById('confirmModal').classList.add('show');
}
function closeConfirmModal(){document.getElementById('confirmModal').classList.remove('show');}
function closeValidationModal(){document.getElementById('validationModal').classList.remove('show');}
document.getElementById('validationModal').addEventListener('click',function(e){if(e.target===this)closeValidationModal();});
function submitUpdate(){closeConfirmModal();document.getElementById('updateForm').submit();}
document.getElementById('confirmModal').addEventListener('click',function(e){if(e.target===this)closeConfirmModal();});



// ── Lightbox ──────────────────────────────────────────────────────────────────
var lb=document.getElementById('lightboxBackdrop'),li=document.getElementById('lightboxImg'),lc=document.getElementById('lightboxCaption'),lo=document.getElementById('lightboxOpenFull');
function openLightbox(url,filename){if(!lb)return;li.src=url;li.alt=filename||'';lc.textContent=filename||'';lo.href=url;lb.classList.add('show');document.body.style.overflow='hidden';}
function closeLightbox(){if(!lb)return;lb.classList.remove('show');document.body.style.overflow='';setTimeout(function(){if(li)li.src='';},300);}
var lbClose=document.getElementById('lightboxClose');
if(lbClose)lbClose.addEventListener('click',closeLightbox);
if(lb)lb.addEventListener('click',function(e){if(e.target===this)closeLightbox();});
document.addEventListener('keydown',function(e){if(e.key==='Escape'){closeLightbox();closeConfirmModal();closePriModal();closeValidationModal();}});


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