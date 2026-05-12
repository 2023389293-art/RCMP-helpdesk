<?php
// dept_admin/afsmd/ticket_detail.php 
require '_layout.php';
require_once __DIR__ . '/../../db_connect.php';
require_once __DIR__ . '/../../assign_helper.php';
require_once __DIR__ . '/../../sla_helper.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require __DIR__ . '/../../PHPMailer-master/src/Exception.php';
require __DIR__ . '/../../PHPMailer-master/src/PHPMailer.php';
require __DIR__ . '/../../PHPMailer-master/src/SMTP.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// ── Flash messages ────────────────────────────────────────────────────────────
$updateMsg   = $_SESSION['flash_success'] ?? '';
$updateError = $_SESSION['flash_error']   ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// ── Admin identity from session ───────────────────────────────────────────────
$adminStaffId   = (int)($_SESSION['staff_id'] ?? 0);
$adminStaffName = $_SESSION['staff_name'] ?? 'Admin AFSMD';

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
        WHERE c.ticket_id = ? AND c.dept_id = 1
        LIMIT 1
    ");
    $stmt->bind_param("s", $ticketId);
    $stmt->execute();
    $ticket = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// ── HANDLE POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ticket) {
    $action = trim($_POST['action'] ?? '');

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

            manualAssignTicket($conn, 1, $ticketId, $newStaffId);

            $assignRemarks = trim($_POST['assign_remarks'] ?? '');

            // Log with real admin identity + remarks
            $asnLog = $conn->prepare("INSERT INTO ticket_logs (ticket_id,changed_by_id,changed_by,field_changed,old_priority,new_priority,remarks) VALUES (?,?,?,'assigned',?,?,?)");
            if ($asnLog) {
                $asnLog->bind_param("sissss", $ticketId, $adminStaffId, $adminStaffName, $oldName, $newName, $assignRemarks);
                $asnLog->execute(); $asnLog->close();
            }
            $_SESSION['flash_success'] = 'Ticket reassigned to <strong>' . htmlspecialchars($newName) . '</strong>.';
        } else {
            $_SESSION['flash_error'] = 'Please select a staff member to assign.';
        }
        header('Location: ticket_detail.php?id=' . urlencode($ticketId) . '&tab=detail&from=' . $backUrlEncoded);
        exit;
    }

    // ── ACTION: priority_only (admin changes priority without being assigned) ──
    if ($action === 'priority_only') {
        $newPriority = trim($_POST['priority'] ?? '');
        $allowedPri  = ['low', 'medium', 'high'];
        if (in_array($newPriority, $allowedPri)) {
            $freshStmt = $conn->prepare("SELECT priority, status FROM complaints WHERE ticket_id = ? AND dept_id = 1 LIMIT 1");
            $freshStmt->bind_param("s", $ticketId); $freshStmt->execute();
            $freshRow = $freshStmt->get_result()->fetch_assoc(); $freshStmt->close();
            $oldPriority = $freshRow['priority'] ?? 'medium';
            $oldStatus   = $freshRow['status']   ?? 'open';

            if ($oldStatus !== 'closed' && $oldPriority !== $newPriority) {
                $upd = $conn->prepare("UPDATE complaints SET priority=?,updated_at=NOW() WHERE ticket_id=? AND dept_id=1");
                $upd->bind_param("ss", $newPriority, $ticketId);
                $upd->execute(); $upd->close();

$logStmt2 = $conn->prepare("INSERT INTO ticket_logs (ticket_id,changed_by_id,changed_by,field_changed,old_priority,new_priority,old_status,new_status) VALUES (?,?,?,?,?,?,?,?)");
if ($logStmt2) {
    $logChangedById2 = (int)$adminStaffId;
    $fcPri = 'priority';
    $newStatusSame = $oldStatus; // can't pass same var by reference twice
    $logStmt2->bind_param("sissssss", $ticketId, $logChangedById2, $adminStaffName, $fcPri, $oldPriority, $newPriority, $oldStatus, $newStatusSame);
    $logStmt2->execute();
    $logStmt2->close();
}
            }

            // AJAX response
            $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true]);
                exit;
            }
            $_SESSION['flash_success'] = 'Priority updated to <strong>' . ucfirst($newPriority) . '</strong>.';
        } else {
            $_SESSION['flash_error'] = 'Invalid priority.';
        }
        header('Location: ticket_detail.php?id=' . urlencode($ticketId) . '&tab=detail&from=' . $backUrlEncoded);
        exit;
    }

    // ── ACTION: admin_update (admin is assigned, changes priority + status + message) ──
    if ($action === 'admin_update') {
        $assignedNow     = getAssignedStaff($conn, $ticketId);
        $isAdminAssigned = ($assignedNow && (int)$assignedNow['staff_id'] === $adminStaffId);

        if (!$isAdminAssigned) {
            $_SESSION['flash_error'] = 'You must be assigned to this ticket to update its status.';
            header('Location: ticket_detail.php?id=' . urlencode($ticketId) . '&tab=detail&from=' . $backUrlEncoded); exit;
        }

        $newPriority = trim($_POST['priority'] ?? '');
        $newStatus   = trim($_POST['status']   ?? '');
        $allowedPri  = ['low', 'medium', 'high'];
        $allowedSta  = ['open', 'in_progress', 'closed'];

        if (!in_array($newPriority, $allowedPri) || !in_array($newStatus, $allowedSta)) {
            $_SESSION['flash_error'] = 'Invalid priority or status.';
            header('Location: ticket_detail.php?id=' . urlencode($ticketId) . '&tab=detail&from=' . $backUrlEncoded); exit;
        }

        $freshStmt = $conn->prepare("SELECT priority, status, first_response_at FROM complaints WHERE ticket_id = ? AND dept_id = 1 LIMIT 1");
        $freshStmt->bind_param("s", $ticketId); $freshStmt->execute();
        $freshRow = $freshStmt->get_result()->fetch_assoc(); $freshStmt->close();
        $oldPriority      = $freshRow['priority']          ?? 'medium';
        $oldStatus        = $freshRow['status']            ?? 'open';
        $oldFirstResponse = $freshRow['first_response_at'] ?? null;

        if ($oldStatus === 'closed') {
            $_SESSION['flash_error'] = 'This ticket is already closed and cannot be changed.';
            header('Location: ticket_detail.php?id=' . urlencode($ticketId) . '&tab=detail&from=' . $backUrlEncoded); exit;
        }

        $nowMysql = (new DateTime('now', new DateTimeZone('Asia/Kuala_Lumpur')))->format('Y-m-d H:i:s');

        // Stamp sla_start_at if not set
        $slaCheck = $conn->prepare("SELECT sla_start_at FROM complaints WHERE ticket_id=? AND dept_id=1 LIMIT 1");
        $slaCheck->bind_param("s", $ticketId); $slaCheck->execute();
        $slaRow = $slaCheck->get_result()->fetch_assoc(); $slaCheck->close();
        if (empty($slaRow['sla_start_at'])) {
            $slaSet = $conn->prepare("UPDATE complaints SET sla_start_at=? WHERE ticket_id=? AND dept_id=1");
            $slaSet->bind_param("ss", $nowMysql, $ticketId);
            $slaSet->execute(); $slaSet->close();
        }

        // Stamp first_response_at on first status move
        if (empty($oldFirstResponse) && in_array($newStatus, ['in_progress', 'closed'])) {
            $frSet = $conn->prepare("UPDATE complaints SET first_response_at=? WHERE ticket_id=? AND dept_id=1");
            $frSet->bind_param("ss", $nowMysql, $ticketId);
            $frSet->execute(); $frSet->close();
        }

        // Update complaint
        if ($newStatus === 'closed' && $oldStatus !== 'closed') {
            $upd = $conn->prepare("UPDATE complaints SET priority=?,status=?,resolved_at=?,updated_at=NOW() WHERE ticket_id=? AND dept_id=1");
            $upd->bind_param("ssss", $newPriority, $newStatus, $nowMysql, $ticketId);
        } else {
            $upd = $conn->prepare("UPDATE complaints SET priority=?,status=?,updated_at=NOW() WHERE ticket_id=? AND dept_id=1");
            $upd->bind_param("sss", $newPriority, $newStatus, $ticketId);
        }

        $statusLabel = ucfirst(str_replace('_', ' ', $newStatus));

        if ($upd->execute()) {
            $priChanged  = ($oldPriority !== $newPriority);
            $statChanged = ($oldStatus   !== $newStatus);
            if ($priChanged || $statChanged) {
    $fc             = ($priChanged && $statChanged) ? 'both' : ($priChanged ? 'priority' : 'status');
    $logChangedById = (int)$adminStaffId;
    $logOldPri      = (string)$oldPriority;
    $logNewPri      = (string)$newPriority;
    $logOldStat     = (string)$oldStatus;
    $logNewStat     = (string)$newStatus;
    $logStmt = $conn->prepare("INSERT INTO ticket_logs (ticket_id,changed_by_id,changed_by,field_changed,old_priority,new_priority,old_status,new_status) VALUES (?,?,?,?,?,?,?,?)");
    if ($logStmt) {
        $logStmt->bind_param("sissssss", $ticketId, $logChangedById, $adminStaffName, $fc, $logOldPri, $logNewPri, $logOldStat, $logNewStat);
        $logStmt->execute();
        $logStmt->close();
    }
}

            // Save message if provided + send email (same as staff)
            $inlineMessage = trim($_POST['message'] ?? '');
if (!empty($inlineMessage)) {
    $ins = $conn->prepare("INSERT INTO ticket_replies (ticket_id,sender_id,sender_name,sender_role,message) VALUES (?,?,?,?,?)");
    $senderRole = 'staff';
    $ins->bind_param("sisss", $ticketId, $adminStaffId, $adminStaffName, $senderRole, $inlineMessage);
    $ins->execute(); $ins->close();

                // Email to submitter
                $subType  = $ticket['submitter_type'] ?? 'student';
                $subTable = $subType === 'student' ? 'students' : 'staff';
                $subPk    = $subType === 'student' ? 'student_id' : 'staff_id';
                $subQ = $conn->prepare("SELECT full_name, email FROM {$subTable} WHERE {$subPk}=? LIMIT 1");
                if ($subQ) {
                    $subQ->bind_param("i", $ticket['submitter_id']);
                    $subQ->execute();
                    $submitterData = $subQ->get_result()->fetch_assoc();
                    $subQ->close();
                    if ($submitterData && !empty($submitterData['email'])) {
                        $toName      = $submitterData['full_name'];
                        $toEmail     = $submitterData['email'];
                        $currentYear = date('Y');
                        $currentDate = date('d F Y');
                        $escapedTo   = htmlspecialchars($toName);
                        $escapedTid  = htmlspecialchars($ticketId);
                        $escapedMsg  = nl2br(htmlspecialchars($inlineMessage));
                        $escapedFrom = htmlspecialchars($adminStaffName);
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
    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
      <tr><td style="border-left:3px solid #e8b200;background:#fffdf0;padding:16px 20px;border-radius:0 4px 4px 0;">
        <p style="margin:0 0 6px;font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#92700a;">Note</p>
        <p style="margin:0;font-size:14px;color:#374151;line-height:1.75;">Please log in to the UniKL RCMP Help Desk portal to view full details or reply to this ticket.</p>
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
                            $mail->isSMTP(); $mail->Host='smtp.gmail.com'; $mail->SMTPAuth=true;
                            $mail->Username='farahwdi33@gmail.com'; $mail->Password='wvgq vqdn dbiw vcjn';
                            $mail->SMTPSecure=PHPMailer::ENCRYPTION_STARTTLS; $mail->Port=587;
                            $mail->Debugoutput='error_log';
                            $mail->setFrom('farahwdi33@gmail.com','UniKL RCMP Help Desk');
                            $mail->addAddress($toEmail,$toName);
                            $mail->isHTML(true); $mail->CharSet='UTF-8';
                            $mail->Subject="Ticket Update ({$statusLabel}) — {$ticketId}";
                            $mail->Body=$htmlBody;
                            $mail->AltBody="Status: {$statusLabel}\n\nMessage from {$adminStaffName}:\n\n{$inlineMessage}\n\nTicket: {$ticketId}";
                            $mail->send();
                        } catch (Exception $e) {
                            error_log("[UniKL Mail] Admin message send failed for {$ticketId}: ".$mail->ErrorInfo);
                        }
                    }
                }
            } // end if(!empty($inlineMessage))

            
            $_SESSION['flash_success'] = 'Ticket updated — status: <strong>' . htmlspecialchars($statusLabel) . '</strong>.';
        } else {
            $_SESSION['flash_error'] = 'Failed to update ticket.';
        }
        $upd->close();
        session_write_close();
        header('Location: ticket_detail.php?id=' . urlencode($ticketId) . '&tab=detail&from=' . $backUrlEncoded); exit;
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

// Is the currently logged-in admin the one assigned to this ticket?
$isAdminAssigned = ($assignedStaff && (int)$assignedStaff['staff_id'] === $adminStaffId);

// ── SLA data ──────────────────────────────────────────────────────────────────
$slaData = null;
if ($ticket && !empty($ticket['created_at'])) {
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

    $bestRespondTs = null;
    if (!empty($ticket['first_response_at'])) {
        $bestRespondTs = $ticket['first_response_at'];
    } elseif (!empty($firstLogResponse)) {
        $bestRespondTs = $firstLogResponse;
    }

    $slaFirstResponse = null;
    if (strtolower($ticket['status']) !== 'open') {
        $slaFirstResponse = $bestRespondTs;
    }

    $slaData = getSlaStatus(
        $ticket['created_at'],
        $ticket['resolved_at'] ?? null,
        $ticket['status'],
        $slaFirstResponse
    );
}

// ── Fetch dept staff list for reassign dropdown (staff + admins of dept 4) ───
// We show both staff and admin roles so admin can assign to themselves
$deptStaffList = [];
$dsStmt = $conn->prepare("
    SELECT staff_id, full_name, role
    FROM staff
    WHERE dept_id = 1 AND status = 'active' AND role IN ('staff','admin')
    ORDER BY role ASC, full_name ASC
");
$dsStmt->execute();
$dsRes = $dsStmt->get_result();
while ($row = $dsRes->fetch_assoc()) $deptStaffList[] = $row;
$dsStmt->close();

// ── Fetch change history ──────────────────────────────────────────────────────
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
    $lg->bind_param("ss", $ticketId, $ticketId);
    $lg->execute();
    $changeLogs = $lg->get_result()->fetch_all(MYSQLI_ASSOC);
    $lg->close();
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
if (!in_array($activeTab, ['detail', 'history', 'feedback'])) $activeTab = 'detail';

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
  <title>Ticket Detail | UniKL Help Desk – AFSMD Admin</title>
  <?php include '_head_assets.php'; ?>
  <link rel="stylesheet" href="css/tickets_detail.css"/>
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
    <p>The ticket <strong><?= htmlspecialchars($ticketId) ?></strong> does not exist or does not belong to the Administration & Facilities Management Department.</p>
    <a href="<?= htmlspecialchars($backUrl) ?>" class="nf-back">
      <svg viewBox="0 0 24 24"><polyline points="15,18 9,12 15,6"/></svg>
      Back to All Tickets
    </a>
  </div>

  <?php else: ?>

  <!-- Ticket header strip -->
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
          <span id="thsPriorityFlag"><?= priFlag($ticket['priority'] ?? 'medium') ?></span>
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


  <!-- ══ TAB 1: DETAIL ══ -->
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
                <div class="ti-meta-value" id="ticketPriorityChip"><?= priFlag($ticket['priority'] ?? 'medium') ?></div>
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
                <?php if (strtolower($ticket['status']) !== 'in_progress' || empty($ticket['first_response_at'])): ?>
                <div class="sla-status-chip" style="background:<?= $slaData['status_bg']; ?>;color:<?= $slaData['status_color']; ?>">
                  <?= htmlspecialchars($slaData['status_label']); ?>
                </div>
                <?php else: ?>
                <div class="sla-status-chip" style="background:#D1FAE5;color:#059669;">SLA Stopped</div>
                <?php endif; ?>
                <?php if (strtolower($ticket['status']) !== 'closed' && empty($ticket['first_response_at'])): ?>
                <div class="sla-remaining-big" style="color:<?= $slaData['status_color']; ?>"><?= htmlspecialchars($slaData['remaining_str']); ?></div>
                <div class="sla-remaining-sub">until SLA deadline</div>
                <?php elseif (strtolower($ticket['status']) === 'in_progress' && !empty($ticket['first_response_at'])): ?>
                <div class="sla-remaining-big" style="color:#059669;">
                  <?php $em=$slaData['elapsed_mins']; $eh=intdiv($em,60); $emm=$em%60; echo $eh>0?"{$eh}h {$emm}m used":"{$emm}m used"; ?>
                </div>
                <?php endif; ?>
              </div>
              <div class="sla-inline-right">
                <?php $fillPct = min($slaData['percent_used'], 100); ?>
                <div class="sla-progress-wrap">
                  <div class="sla-progress-fill" style="width:<?= $fillPct; ?>%;background:<?= $slaData['status_color']; ?>"></div>
                </div>
                <div class="sla-tick-row"><span>0h</span><span>4h</span><span>8h</span></div>
                <?php
                  $ticketStatus = strtolower($ticket['status']);
                  $em = $slaData['elapsed_mins'];
                  $eh = intdiv($em, 60); $emm = $em % 60;
                  // Only show /8h when ticket is still open and no response yet
if ($ticketStatus === 'open' && empty($ticket['first_response_at'])) {
    $timeUsedStr = ($eh > 0 ? "{$eh}h {$emm}m" : "{$emm}m") . ' / ' . SLA_WORK_HOURS . 'h';
} else {
    $timeUsedStr = ($eh > 0 ? "{$eh}h {$emm}m" : "{$emm}m") . ' used';
}
                  if ($ticketStatus === 'closed') $timeUsedNote = 'Working hours from submission to close';
                  elseif (!empty($ticket['first_response_at'])) $timeUsedNote = 'Working hours from submission to first response';
                  else $timeUsedNote = 'Working hours elapsed since submission';
                  $respondedVal  = !empty($ticket['first_response_at']) ? date('d M Y, H:i', strtotime($ticket['first_response_at'])) : '—';
                  $respondedNote = !empty($ticket['first_response_at']) ? 'Staff first moved ticket to In Progress or Closed' : 'No staff response yet';
                  $closedVal  = ($ticketStatus === 'closed' && !empty($ticket['resolved_at'])) ? date('d M Y, H:i', strtotime($ticket['resolved_at'])) : '—';
                  $closedNote = ($ticketStatus === 'closed' && !empty($ticket['resolved_at'])) ? 'Ticket was marked as closed' : ($ticketStatus === 'closed' ? 'Closed (no timestamp)' : 'Ticket not yet closed');
                ?>
                <div class="sla-info-grid" style="grid-template-columns: repeat(5,1fr);">
                  <div>
                    <div class="sla-info-label">Submitted</div>
                    <div class="sla-info-value"><?= date('d M Y, H:i', strtotime($ticket['created_at'])); ?></div>
                    <div style="font-size:10px;color:#9CA3AF;margin-top:3px;line-height:1.4;">Ticket submission date &amp; time</div>
                  </div>
                  <div>
                    <div class="sla-info-label">Deadline</div>
                    <?php if ($ticketStatus === 'open' && empty($ticket['first_response_at'])): ?>
                      <div class="sla-info-value"><?= $slaData['deadline_str']; ?></div>
                      <div style="font-size:10px;color:#9CA3AF;margin-top:3px;line-height:1.4;">Must respond before this time</div>
                    <?php else: ?>
                      <div class="sla-info-value" style="color:#9CA3AF;">—</div>
                      <div style="font-size:10px;color:#9CA3AF;margin-top:3px;line-height:1.4;">SLA clock stopped after response</div>
                    <?php endif; ?>
                  </div>
                  <div>
                    <div class="sla-info-label">Time Used</div>
                    <div class="sla-info-value"><?= $timeUsedStr; ?></div>
                    <div style="font-size:10px;color:#9CA3AF;margin-top:3px;line-height:1.4;"><?= $timeUsedNote; ?></div>
                  </div>
                  <div>
                    <div class="sla-info-label">Responded At</div>
                    <div class="sla-info-value" style="<?= empty($ticket['first_response_at']) ? 'color:#9CA3AF;' : ''; ?>"><?= $respondedVal; ?></div>
                    <div style="font-size:10px;color:#9CA3AF;margin-top:3px;line-height:1.4;"><?= $respondedNote; ?></div>
                  </div>
                  <div>
                    <div class="sla-info-label">Closed At</div>
                    <div class="sla-info-value" style="<?= ($ticketStatus !== 'closed') ? 'color:#9CA3AF;' : ''; ?>"><?= $closedVal; ?></div>
                    <div style="font-size:10px;color:#9CA3AF;margin-top:3px;line-height:1.4;"><?= $closedNote; ?></div>
                  </div>
                </div>
              </div>
            </div>
            <?php
              $slaStartTs  = strtotime($ticket['sla_start_at'] ?? $ticket['created_at']);
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
        </div>
        <?php endif; ?>

      </div><!-- /.detail-left -->

      <!-- RIGHT: Sidebar -->
      <div class="detail-right">

        <!-- ── ASSIGNED TO CARD ── -->
        <div class="td-card">
          <div class="td-card-header">
            <div class="td-card-header-icon assigned-header-icon">
              <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            </div>
            <div>
              <div class="td-card-header-title">Assigned To</div>
              <div class="td-card-header-sub">Manage ticket assignment</div>
            </div>
          </div>
          <div class="td-card-body">

            <?php if ($assignedStaff): ?>
            <div class="assigned-pill">
              <div class="assigned-avatar"><?= getInitials($assignedStaff['full_name']) ?></div>
              <div style="flex:1;min-width:0;">
                <div class="assigned-name"><?= htmlspecialchars($assignedStaff['full_name']) ?></div>
                <div class="assigned-role-tag"><?= $assignedStaff['role'] === 'admin' ? 'AFSMD Admin' : 'AFSMD Staff' ?></div>
              </div>
            </div>
            <?php else: ?>
            <div class="unassigned-pill">
              ⏳ Waiting in queue — no staff assigned yet.
            </div>
            <?php endif; ?>

            <?php if (!$isClosed): ?>

            

            <?php if (!empty($deptStaffList)): ?>
            <form method="POST" action="ticket_detail.php?id=<?= urlencode($ticketId) ?>">
  <input type="hidden" name="action" value="reassign"/>
  <div class="reassign-label">Reassign to</div>
  <div class="reassign-row">
    <select name="new_staff_id" class="reassign-select" id="reassignSelect" required onchange="handleReassignChange(this)">
      <option value="">— Select staff —</option>
      <?php foreach ($deptStaffList as $s): ?>
      <?php if ((int)$s['staff_id'] === (int)($assignedStaff['staff_id'] ?? 0)) continue; ?>
      <option value="<?= $s['staff_id'] ?>">
        <?= htmlspecialchars($s['full_name']) ?><?= $s['role'] === 'admin' ? ' (Admin)' : '' ?>
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

            <?php else: ?>
            <div style="margin-top:8px;font-size:11.5px;color:#9CA3AF;text-align:center;">Ticket is closed — reassignment locked</div>
            <?php endif; ?>

          </div>
        </div>

        <!-- ── UPDATE TICKET / STATUS OVERVIEW (original style) ── -->
        <?php $curPri = strtolower($ticket['priority'] ?? 'medium'); $curStat = strtolower($ticket['status'] ?? 'open'); ?>

        <?php if ($isAdminAssigned && !$isClosed): ?>
        <!-- Admin is assigned — full update form matching original staff view -->
        <div class="td-card">
          <div class="td-card-header">
            <div class="td-card-header-icon" style="background:#F5F3FF;">
              <svg viewBox="0 0 24 24" style="width:16px;height:16px;fill:none;stroke:#7C3AED;stroke-width:1.8"><polyline points="23,4 23,10 17,10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
            </div>
            <div>
              <div class="td-card-header-title">Update Ticket</div>
              <div class="td-card-header-sub">Change priority &amp; status</div>
            </div>
          </div>
          <div class="td-card-body">

            <!-- Priority buttons -->
            <div class="pri-label-sm">
              Priority
              <span id="priSavingSpinner" style="display:none;font-size:10px;color:#9CA3AF;font-weight:400;margin-left:3px">saving…</span>
            </div>
            <div class="pri-btn-group">
              <?php foreach(['low'=>'Low','medium'=>'Medium','high'=>'High'] as $val=>$label): ?>
              <button type="button" class="pri-btn <?= $curPri===$val?'active':'' ?>"
                data-pri="<?= $val ?>"
                onclick="adminChangePriority('<?= $val ?>',this)">
                <span class="pri-dot"></span><?= $label ?>
              </button>
              <?php endforeach; ?>
            </div>
            <input type="hidden" id="priorityInputAdmin" value="<?= htmlspecialchars($curPri) ?>"/>

            <div class="pri-status-divider"><span>Status</span></div>

            <form method="POST" action="ticket_detail.php?id=<?= urlencode($ticketId) ?>" id="adminUpdateForm">
              <input type="hidden" name="action" value="admin_update"/>
              <input type="hidden" name="priority" id="priorityForUpdate" value="<?= htmlspecialchars($curPri) ?>"/>

              <div class="status-select-wrap">
                <select name="status" id="adminStatusSelect" class="status-select-styled" onchange="handleAdminStatusChange(this.value)">
                  <?php if ($curStat === 'open'): ?>
                    <option value="open" selected>Open</option>
                    <option value="in_progress">In Progress</option>
                    <option value="closed">Closed</option>
                  <?php elseif ($curStat === 'in_progress'): ?>
                    <option value="in_progress" selected>In Progress</option>
                    <option value="closed">Closed</option>
                  <?php endif; ?>
                </select>
              </div>

              <div id="adminMsgBox" style="display:none;">
                <div class="msg-section-divider"></div>
                <div class="msg-section-label">
                  <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                  Message to Submitter <span style="color:#9CA3AF;font-weight:400;font-size:10px;margin-left:4px;">(optional)</span>
                </div>
                <textarea name="message" class="msg-inline-textarea"
                  placeholder="Type a message to the submitter…"
                  maxlength="2000" rows="3"></textarea>
              </div>

              <button type="submit" class="btn-update-save" style="display:block;">Save Changes</button>
            </form>

          </div>
        </div>

        <?php else: ?>
        <!-- Admin not assigned — priority editable, status read-only (original style) -->
        <div class="td-card">
          <div class="td-card-header">
            <div class="td-card-header-icon" style="background:#F3F4F6;">
              <svg viewBox="0 0 24 24" style="width:16px;height:16px;fill:none;stroke:#6B7280;stroke-width:1.8"><rect x="1" y="5" width="22" height="14" rx="7"/><circle cx="16" cy="12" r="3"/></svg>
            </div>
            <div>
              <div class="td-card-header-title">Update Ticket</div>
              <div class="td-card-header-sub">Reassign to yourself to update status</div>
            </div>
          </div>
          <div class="td-card-body">

            <?php if (!$isClosed): ?>
            <!-- Priority editable by admin always -->
            <div class="pri-label-sm">
              Priority
              <span id="priSavingSpinner" style="display:none;font-size:10px;color:#9CA3AF;font-weight:400;margin-left:3px">saving…</span>
            </div>
            <div class="pri-btn-group">
              <?php foreach(['low'=>'Low','medium'=>'Medium','high'=>'High'] as $val=>$label): ?>
              <button type="button" class="pri-btn <?= $curPri===$val?'active':'' ?>"
                data-pri="<?= $val ?>"
                onclick="adminChangePriority('<?= $val ?>',this)">
                <span class="pri-dot"></span><?= $label ?>
              </button>
              <?php endforeach; ?>
            </div>
            <input type="hidden" id="priorityInputAdmin" value="<?= htmlspecialchars($curPri) ?>"/>
            <?php else: ?>
            <!-- Ticket closed — priority read-only -->
            <div style="font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:#9CA3AF;margin-bottom:6px;">Priority</div>
            <div style="display:flex;gap:6px;margin-bottom:4px;">
              <?php foreach(['low'=>['Low','#3B82F6','#EFF6FF'],'medium'=>['Medium','#F59E0B','#FFFBEB'],'high'=>['High','#EF4444','#FEF2F2']] as $val=>[$lbl,$fg,$bg]): ?>
              <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;padding:8px 5px;border-radius:8px;border:1.5px solid <?= $curPri===$val?$fg:'#E5E7EB' ?>;background:<?= $curPri===$val?$bg:'#F9FAFB' ?>;opacity:<?= $curPri===$val?'1':'.45' ?>;">
                <div style="width:7px;height:7px;border-radius:50%;background:<?= $fg ?>;"></div>
                <div style="font-size:11.5px;font-weight:<?= $curPri===$val?'700':'500' ?>;color:<?= $curPri===$val?$fg:'#9CA3AF' ?>;"><?= $lbl ?></div>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div style="height:1px;background:#F3F4F6;margin:14px 0;"></div>

            <div style="font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:#9CA3AF;margin-bottom:6px;">Status</div>
            <div style="padding:9px 11px;border:1.5px solid #E5E7EB;border-radius:7px;font-size:13px;color:#9CA3AF;background:#F9FAFB;">
              <?= $curStat === 'in_progress' ? 'In Progress' : ucfirst($curStat) ?>
            </div>
            <?php if (!$isClosed): ?>
            <div style="margin-top:8px;display:flex;align-items:flex-start;gap:7px;padding:9px 11px;background:#F0F9FF;border:1px solid #BAE6FD;border-radius:8px;font-size:11.5px;color:#0369A1;line-height:1.5;">
              <svg viewBox="0 0 24 24" style="width:14px;height:14px;flex-shrink:0;margin-top:1px;fill:none;stroke:#0EA5E9;stroke-width:2;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
              <div>To update status, reassign this ticket to yourself from the dropdown above.</div>
            </div>
            <?php endif; ?>

          </div>
        </div>
        <?php endif; ?>

      </div><!-- /.detail-right -->
    </div><!-- /.detail-tab-grid -->
  </div><!-- /.td-panel detail -->


  <!-- ══ TAB 2: HISTORY ══ -->
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
            'message'      => 'msg',
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
            <?php elseif ($fc === 'message'): ?>
              <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><line x1="9" y1="10" x2="15" y2="10" stroke-linecap="round"/><line x1="9" y1="14" x2="13" y2="14" stroke-linecap="round"/></svg>
            <?php else: ?>
              <svg viewBox="0 0 24 24"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/></svg>
            <?php endif; ?>
          </div>
          <div class="tl-body">
            <div class="tl-header">
              <span class="tl-who"><?= htmlspecialchars($log['changed_by']) ?></span>
              <span class="tl-when"><?= timeAgo($log['event_at']) ?></span>
            </div>
            <span class="tl-when-full"><?= date('d M Y, H:i', strtotime($log['event_at'])) ?></span>
            <div class="tl-changes" style="margin-top:5px">
              <?php if (in_array($fc, ['priority','both']) && $log['old_priority'] && $log['new_priority']): ?>
              <div class="tl-row">
                <span class="tl-row-label">Priority</span>
                <?= priChip($log['old_priority']) ?><span class="tl-arrow">→</span><?= priChip($log['new_priority']) ?>
              </div>
              <?php endif; ?>
              <?php if (in_array($fc, ['status','both']) && $log['old_status'] && $log['new_status']): ?>
              <div class="tl-row">
                <span class="tl-row-label">Status</span>
                <?= statChip($log['old_status']) ?><span class="tl-arrow">→</span><?= statChip($log['new_status']) ?>
              </div>
              <?php endif; ?>
              <?php if ($fc === 'assigned'): ?>
              <div class="tl-row">
                <span class="tl-row-label">Action</span>
                <span class="tl-chip-name" style="background:#EFF6FF;color:#1D4ED8;">Ticket Reassigned</span>
              </div>
              <div class="tl-row" style="margin-top:4px;">
                <span class="tl-row-label">Assigned to</span>
                <?php $fromName = $log['old_priority'] ?? null; $toName = $log['new_priority'] ?? null; ?>
                <?php if ($fromName && $fromName !== 'Unassigned'): ?>
                  <span class="tl-chip-name"><?= htmlspecialchars($fromName) ?></span>
                  <span class="tl-arrow">→</span>
                <?php else: ?>
                  <span class="tl-chip-name" style="color:#9CA3AF;background:#F9FAFB;">Unassigned</span>
                  <span class="tl-arrow">→</span>
                <?php endif; ?>
                <span class="tl-chip-name tl-chip-name--new"><?= htmlspecialchars($toName ?? '—') ?></span>
              </div>
              <?php if (!empty($log['remarks'])): ?>
              <div class="tl-msg-bubble" style="border-left-color:#6366F1;background:#EEF2FF;color:#3730A3;margin-top:6px;">
                <span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#818CF8;display:block;margin-bottom:3px;">Remarks</span>
                <?= nl2br(htmlspecialchars($log['remarks'])) ?>
              </div>
              <?php endif; ?>
              <?php endif; ?>
              <?php if ($fc === 'conversation'): ?>
              <div class="tl-row">
                <span class="tl-row-label">Chat</span>
                <span class="tl-chip-conv">Started first reply to ticket</span>
              </div>
              <?php endif; ?>
              <?php if ($fc === 'message'): ?>
              <div class="tl-row">
                <span class="tl-row-label">Message</span>
                <span class="tl-chip-conv" style="background:#EEF2FF;color:#3730A3;">
                  <?= $log['source'] === 'reply' ? 'Sent a message' : 'Sent message with status update'; ?>
                </span>
              </div>
              <?php if (!empty($log['message_content'])): ?>
              <div class="tl-msg-bubble"><?= nl2br(htmlspecialchars($log['message_content'])) ?></div>
              <?php endif; ?>
              <?php if (!empty($log['new_status']) && $log['source'] === 'log'): ?>
              <div class="tl-row" style="margin-top:4px;">
                <span class="tl-row-label">Status</span>
                <?= statChip($log['new_status']); ?>
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


  <!-- ══ TAB 3: FEEDBACK ══ -->
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
          <div class="fb-compact-comment" style="border-left-color:<?= $chipFg ?>"><?= htmlspecialchars($feedback['comment']) ?></div>
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
// ── Priority flag HTML map ────────────────────────────────────────────────────
var priFlagMap = {
  low:    '<span style="display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:600;color:#3B82F6;"><svg width="13" height="13" viewBox="0 0 24 24" fill="#3B82F6"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15" stroke="#3B82F6" stroke-width="2" stroke-linecap="round"/></svg>Low</span>',
  medium: '<span style="display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:600;color:#F59E0B;"><svg width="13" height="13" viewBox="0 0 24 24" fill="#F59E0B"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15" stroke="#F59E0B" stroke-width="2" stroke-linecap="round"/></svg>Medium</span>',
  high:   '<span style="display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:600;color:#EF4444;"><svg width="13" height="13" viewBox="0 0 24 24" fill="#EF4444"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15" stroke="#EF4444" stroke-width="2" stroke-linecap="round"/></svg>High</span>'
};

// ── Reassign select — show remarks + button only when a staff is chosen ───────
function handleReassignChange(sel) {
  var remarksBox = document.getElementById('reassignRemarksBox');
  var saveBtn    = document.getElementById('reassignSaveBtn');
  var show = sel.value !== '';
  remarksBox.style.display = show ? 'block' : 'none';
  saveBtn.style.display    = show ? 'block' : 'none';
}

// ── Admin priority change (AJAX, no reassign needed) ─────────────────────────
function adminChangePriority(priority, btn) {
  var spinner = document.getElementById('priSavingSpinner');
  if (spinner) spinner.style.display = 'inline';

  // Update active state on buttons using original CSS classes
  document.querySelectorAll('.pri-btn').forEach(function(b) { b.classList.remove('active'); });
  btn.classList.add('active');

  // Update hidden inputs so status form uses updated priority
  var pi = document.getElementById('priorityInputAdmin');
  if (pi) pi.value = priority;
  var pi2 = document.getElementById('priorityForUpdate');
  if (pi2) pi2.value = priority;

  var fd = new FormData();
  fd.append('action', 'priority_only');
  fd.append('priority', priority);

  var xhr = new XMLHttpRequest();
  xhr.open('POST', 'ticket_detail.php?id=<?= urlencode($ticketId) ?>', true);
  xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
  xhr.onreadystatechange = function() {
    if (xhr.readyState !== 4) return;
    if (spinner) spinner.style.display = 'none';
    if (xhr.status === 200) {
      // Update priority chip in header strip and meta grid
      var thsPri = document.getElementById('thsPriorityFlag');
      if (thsPri && priFlagMap[priority]) thsPri.innerHTML = priFlagMap[priority];
      var metaChip = document.getElementById('ticketPriorityChip');
      if (metaChip && priFlagMap[priority]) metaChip.innerHTML = priFlagMap[priority];
    }
  };
  xhr.send(fd);
}

// ── Show message box when status changes ──────────────────────────────────────
function handleAdminStatusChange(val) {
  var msgBox = document.getElementById('adminMsgBox');
  if (msgBox) {
    msgBox.style.display = (val === 'in_progress' || val === 'closed') ? 'block' : 'none';
  }
}

// ── Lightbox ──────────────────────────────────────────────────────────────────
var lb = document.getElementById('lightboxBackdrop');
var li = document.getElementById('lightboxImg');
var lc = document.getElementById('lightboxCaption');
var lo = document.getElementById('lightboxOpenFull');

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
  setTimeout(function() { li.src = ''; }, 300);
}
document.getElementById('lightboxClose').addEventListener('click', closeLightbox);
lb.addEventListener('click', function(e) { if (e.target === this) closeLightbox(); });
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeLightbox(); });

// ── Timeline pagination ───────────────────────────────────────────────────────
(function() {
  var logs = document.querySelectorAll('.tl-item');
  if (!logs.length) return;
  var total = logs.length, perPage = 5, currentPage = 1;

  function totalPages() { return Math.ceil(total / perPage); }

  function showPage(page) {
    currentPage = Math.max(1, Math.min(page, totalPages()));
    var start = (currentPage - 1) * perPage;
    var end   = Math.min(start + perPage, total);
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