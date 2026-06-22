<?php
/**
 * ticket_reminder.php
 * -------------------------------------------------------------------
 * Run via cron job every 5–15 minutes.
 * Sends an email reminder to ALL active staff in the responsible
 * department when a ticket has been OPEN for >= 1 hour and no
 * reminder has been sent yet.
 *
 * Cron example (every 10 minutes):
 * * /10 * * * * php /var/www/html/uniKL/complaint/ticket_reminder.php
 *
 * -------------------------------------------------------------------
 */

// ── CONFIG ────────────────────────────────────────────────────────────────────
define('REMINDER_AFTER_MINUTES', 60);   // Send reminder after 60 minutes open
define('SLA_TIMEZONE', 'Asia/Kuala_Lumpur');

// ── BOOTSTRAP ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/db_connect.php';   // provides $conn (MySQLi)

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/PHPMailer-master/src/Exception.php';
require __DIR__ . '/PHPMailer-master/src/PHPMailer.php';
require __DIR__ . '/PHPMailer-master/src/SMTP.php';

date_default_timezone_set(SLA_TIMEZONE);

// ── DEBUG LOG ─────────────────────────────────────────────────────────────────
$logFile = __DIR__ . '/reminder_debug.log';
file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Script started\n", FILE_APPEND);

// ── STEP 1: (column reminder_sent_at already exists in DB — no action needed) ──

// ── STEP 2: Find tickets that need a reminder ─────────────────────────────────
//
// Conditions:
//   • status = 'open'
//   • created_at <= NOW() - 60 minutes  (ticket is old enough)
//   • reminder_sent_at IS NULL          (reminder not yet sent)
//
$stmt = $conn->prepare("
    SELECT
        c.ticket_id,
c.dept_id,
c.created_at,
c.title,
c.category_id,
cat.category_name,
c.my_department,
c.priority,
c.sla_start_at,
        c.reminder_sent_at,
        d.dept_name
    FROM complaints c
    LEFT JOIN categories cat ON cat.category_id = c.category_id
    LEFT JOIN departments d  ON d.dept_id = c.dept_id
    WHERE c.status = 'open'
      AND c.created_at <= DATE_SUB(NOW(), INTERVAL ? MINUTE)
      AND (
            c.reminder_sent_at IS NULL
            OR c.reminder_sent_at <= DATE_SUB(NOW(), INTERVAL ? MINUTE)
          )
    ORDER BY c.created_at ASC
");

$remindAfter = REMINDER_AFTER_MINUTES;
$stmt->bind_param("ii", $remindAfter, $remindAfter);
$stmt->execute();
$tickets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (empty($tickets)) {
    echo "[" . date('Y-m-d H:i:s') . "] No tickets need reminders.\n";
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] Found " . count($tickets) . " ticket(s) needing reminder.\n";

// ── STEP 3: Process each ticket ───────────────────────────────────────────────
foreach ($tickets as $ticket) {
    $ticketId  = $ticket['ticket_id'];
    $deptId    = (int)$ticket['dept_id'];
    $deptName  = $ticket['dept_name']     ?? 'Your Department';
    $catName   = $ticket['category_name'] ?? $ticket['title'];
    $fromDept  = $ticket['my_department'] ?? '—';
    $priority  = ucfirst($ticket['priority'] ?? 'medium');
    $createdAt = $ticket['created_at'];

    // How long has it been open?
    $createdDt  = new DateTime($createdAt, new DateTimeZone(SLA_TIMEZONE));
    $nowDt      = new DateTime('now',      new DateTimeZone(SLA_TIMEZONE));
    $diffMins   = (int)(($nowDt->getTimestamp() - $createdDt->getTimestamp()) / 60);
    $diffHours  = floor($diffMins / 60);
    $diffMinsR  = $diffMins % 60;
    $timeOpenStr = $diffHours > 0
        ? "{$diffHours}h {$diffMinsR}m"
        : "{$diffMins}m";

    // Format dates for email
    $createdFormatted = $createdDt->format('d M Y, g:i A');
    $currentDate      = $nowDt->format('d F Y');
    $currentYear      = $nowDt->format('Y');

    // Clean up category display (remove "DEPT / " prefix if present)
    $catDisplay = $catName;
    if (strpos($catName, ' / ') !== false) {
        $catDisplay = trim(substr($catName, strpos($catName, ' / ') + 3));
    }

    // Priority colors for the email badge
    $priColors = [
        'high'   => ['bg' => '#FEE2E2', 'color' => '#DC2626'],
        'medium' => ['bg' => '#FEF3C7', 'color' => '#D97706'],
        'low'    => ['bg' => '#DBEAFE', 'color' => '#2563EB'],
    ];
    $priKey  = strtolower($ticket['priority'] ?? 'medium');
    $priBg   = $priColors[$priKey]['bg']    ?? $priColors['medium']['bg'];
    $priClr  = $priColors[$priKey]['color'] ?? $priColors['medium']['color'];

    // ── Fetch all active staff in this department ──────────────────────────
    $staffStmt = $conn->prepare("
        SELECT staff_id, full_name, email
        FROM staff
        WHERE dept_id = ?
          AND status  = 'active'
          AND role    IN ('staff', 'admin', 'hod')
        ORDER BY staff_id ASC
    ");
    $staffStmt->bind_param("i", $deptId);
    $staffStmt->execute();
    $staffList = $staffStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $staffStmt->close();

    if (empty($staffList)) {
        error_log("[Reminder] No active staff for dept_id={$deptId}, ticket={$ticketId}");
        // Still mark as sent to avoid looping forever
        markReminderSent($conn, $ticketId);
        continue;
    }

    // ── Fetch department group email ────────────────────────────────────────
    $deptEmailStmt = $conn->prepare("SELECT dept_email, dept_name FROM departments WHERE dept_id = ? LIMIT 1");
    $deptEmailStmt->bind_param("i", $deptId);
    $deptEmailStmt->execute();
    $deptEmailRow = $deptEmailStmt->get_result()->fetch_assoc();
    $deptEmailStmt->close();
    $deptGroupEmail = $deptEmailRow['dept_email'] ?? 'rush.rcmp@unikl.edu.my';
    $deptGroupName  = $deptEmailRow['dept_name']  ?? $deptName;

    // ── Fetch assigned staff name for this ticket ───────────────────────────
    $assignedStmt = $conn->prepare("
        SELECT s.full_name FROM complaints c
        LEFT JOIN staff s ON s.staff_id = c.assigned_to
        WHERE c.ticket_id = ? LIMIT 1
    ");
    $assignedStmt->bind_param("s", $ticketId);
    $assignedStmt->execute();
    $assignedRow     = $assignedStmt->get_result()->fetch_assoc();
    $assignedStmt->close();
    $assignedName    = $assignedRow['full_name'] ?? null;
    $escapedAssigned = $assignedName ? htmlspecialchars($assignedName) : 'Unassigned';

    // ── Build the HTML email body ──────────────────────────────────────────
    $escapedTicketId  = htmlspecialchars($ticketId);
    $escapedCat       = htmlspecialchars($catDisplay);
    $escapedFromDept  = htmlspecialchars($fromDept);
    $escapedDeptName  = htmlspecialchars($deptName);
    $escapedPriority  = htmlspecialchars($priority);
    $escapedTimeOpen  = htmlspecialchars($timeOpenStr);
    $escapedCreated   = htmlspecialchars($createdFormatted);

$htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>UniKL RCMP Help Desk — Ticket Reminder</title>
</head>
<body style="margin:0;padding:0;background-color:#f0f2f5;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f0f2f5;padding:40px 16px;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:4px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">

        <!-- HEADER -->
        <tr><td style="background-color:#00327a;padding:0;">
          <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr><td style="height:4px;background:linear-gradient(90deg,#e8b200 0%,#f5cc30 50%,#e8b200 100%);"></td></tr>
          </table>
          <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr><td style="padding:28px 40px 24px;">
              <table cellpadding="0" cellspacing="0" border="0"><tr>
                <td style="vertical-align:middle;padding-right:16px;">
                  <div style="width:48px;height:48px;background-color:rgba(255,255,255,0.12);border-radius:4px;text-align:center;line-height:48px;"><span style="font-size:24px;">🏛️</span></div>
                </td>
                <td style="vertical-align:middle;">
                  <div style="font-size:11px;font-weight:600;letter-spacing:0.12em;text-transform:uppercase;color:rgba(255,255,255,0.55);margin-bottom:4px;">Universiti Kuala Lumpur</div>
                  <div style="font-size:18px;font-weight:700;color:#ffffff;letter-spacing:0.01em;">RCMP Help Desk</div>
                </td>
              </tr></table>
            </td></tr>
          </table>
          <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr><td style="height:1px;background-color:rgba(255,255,255,0.1);"></td></tr>
          </table>
          <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr><td style="padding:12px 40px 16px;background-color:#002660;">
              <span style="font-size:12px;color:rgba(255,255,255,0.65);letter-spacing:0.06em;text-transform:uppercase;font-weight:500;">&#128203;&nbsp; Official Correspondence — Open Ticket Reminder</span>
            </td></tr>
          </table>
        </td></tr>

        <!-- REFERENCE BAR -->
        <tr><td style="background-color:#f7f8fa;border-bottom:1px solid #e4e7ed;padding:14px 40px;">
          <table width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
            <td style="font-size:12px;color:#6b7280;font-weight:500;">Reference No.</td>
            <td align="right" style="font-size:13px;font-weight:700;color:#00327a;font-family:'Courier New',Courier,monospace;letter-spacing:0.05em;">{$escapedTicketId}</td>
          </tr></table>
        </td></tr>

        <!-- ALERT BANNER -->
        <tr><td style="background-color:#FFFBEB;border-bottom:1px solid #FDE68A;padding:18px 40px;">
          <table cellpadding="0" cellspacing="0" border="0"><tr>
            <td style="padding-right:14px;vertical-align:middle;">
              <div style="width:40px;height:40px;background-color:#FEF3C7;border:1.5px solid #FCD34D;border-radius:50%;text-align:center;line-height:40px;font-size:20px;">⚠️</div>
            </td>
            <td>
              <div style="font-size:15px;font-weight:700;color:#92400E;margin-bottom:3px;">This ticket has been open for {$escapedTimeOpen}</div>
              <div style="font-size:13px;color:#78350F;">No action has been taken yet. Please attend to this ticket immediately.</div>
            </td>
          </tr></table>
        </td></tr>

        <!-- BODY -->
        <tr><td style="padding:36px 40px 0;">
          <p style="margin:0 0 6px;font-size:12px;color:#9ca3af;">{$currentDate}</p>
          <p style="margin:0 0 20px;font-size:15px;color:#111827;font-weight:600;">Dear {$escapedDeptName} Team,</p>
          <p style="margin:0 0 24px;font-size:14px;color:#374151;line-height:1.75;">
            A complaint ticket assigned to <strong>{$escapedDeptName}</strong> has been open for <strong>{$escapedTimeOpen}</strong> without any action. Please log in to the portal and attend to it immediately.
          </p>

          <!-- Ticket Particulars -->
          <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #e4e7ed;border-radius:4px;overflow:hidden;margin-bottom:24px;">
<tr><td colspan="2" style="background-color:#00327a;padding:10px 18px;">
  <span style="font-size:11px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:rgba(255,255,255,0.85);">Complaint Particulars</span>
            </td></tr>
            <tr>
              <td style="width:40%;padding:12px 18px;background-color:#f7f8fa;border-bottom:1px solid #e4e7ed;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;">Ticket Reference</td>
              <td style="padding:12px 18px;background-color:#ffffff;border-bottom:1px solid #e4e7ed;font-size:13px;color:#111827;font-weight:700;font-family:'Courier New',Courier,monospace;">{$escapedTicketId}</td>
            </tr>
            <tr>
              <td style="width:40%;padding:12px 18px;background-color:#f7f8fa;border-bottom:1px solid #e4e7ed;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;">Category</td>
              <td style="padding:12px 18px;background-color:#ffffff;border-bottom:1px solid #e4e7ed;font-size:13px;color:#111827;">{$escapedCat}</td>
            </tr>
            <tr>
              <td style="width:40%;padding:12px 18px;background-color:#f7f8fa;border-bottom:1px solid #e4e7ed;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;">From Department</td>
              <td style="padding:12px 18px;background-color:#ffffff;border-bottom:1px solid #e4e7ed;font-size:13px;color:#111827;">{$escapedFromDept}</td>
            </tr>
            <tr>
              <td style="width:40%;padding:12px 18px;background-color:#f7f8fa;border-bottom:1px solid #e4e7ed;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;">Priority</td>
              <td style="padding:12px 18px;background-color:#ffffff;border-bottom:1px solid #e4e7ed;">
                <span style="display:inline-block;font-size:12px;font-weight:600;padding:3px 12px;border-radius:20px;background:{$priBg};color:{$priClr};">{$escapedPriority}</span>
              </td>
            </tr>
            <tr>
              <td style="width:40%;padding:12px 18px;background-color:#f7f8fa;border-bottom:1px solid #e4e7ed;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;">Status</td>
              <td style="padding:12px 18px;background-color:#ffffff;border-bottom:1px solid #e4e7ed;">
                <span style="display:inline-block;font-size:12px;font-weight:600;padding:3px 12px;border-radius:20px;background:#FEF3C7;color:#D97706;">Open — Awaiting Action</span>
              </td>
            </tr>
            <tr>
              <td style="width:40%;padding:12px 18px;background-color:#f7f8fa;border-bottom:1px solid #e4e7ed;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;">Handled By</td>
              <td style="padding:12px 18px;background-color:#ffffff;border-bottom:1px solid #e4e7ed;font-size:13px;color:#111827;">{$escapedAssigned}</td>
            </tr>
            <tr>
              <td style="width:40%;padding:12px 18px;background-color:#f7f8fa;border-bottom:1px solid #e4e7ed;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;">Submitted At</td>
              <td style="padding:12px 18px;background-color:#ffffff;border-bottom:1px solid #e4e7ed;font-size:13px;color:#111827;">{$escapedCreated}</td>
            </tr>
            <tr>
              <td style="width:40%;padding:12px 18px;background-color:#f7f8fa;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;">Time Open</td>
              <td style="padding:12px 18px;background-color:#ffffff;font-size:13px;font-weight:700;color:#D97706;">{$escapedTimeOpen}</td>
            </tr>
          </table>

          <!-- Action Required -->
          <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;">
            <tr><td style="border-left:3px solid #e8b200;background-color:#fffdf0;padding:16px 20px;border-radius:0 4px 4px 0;">
              <p style="margin:0 0 6px;font-size:11px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#92700a;">Action Required</p>
              <p style="margin:0 0 12px;font-size:14px;color:#374151;line-height:1.75;">Please log in to the UniKL RCMP Help Desk portal, review this ticket, and update the status to <strong>In Progress</strong> once you begin attending to it.</p>
              <a href="https://rush.rcmp.edu.my/" style="display:inline-block;padding:10px 22px;background-color:#00327a;color:#ffffff;font-size:13px;font-weight:600;text-decoration:none;border-radius:4px;">View Ticket in Portal</a>
            </td></tr>
          </table>

          <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;">
            <tr><td style="height:1px;background-color:#e4e7ed;"></td></tr>
          </table>

          <p style="margin:0 0 4px;font-size:14px;color:#374151;">Yours sincerely,</p>
          <p style="margin:0 0 2px;font-size:14px;font-weight:700;color:#00327a;">UniKL RCMP Help Desk — Automated Reminder</p>
          <p style="margin:0 0 28px;font-size:12px;color:#9ca3af;">Universiti Kuala Lumpur</p>
        </td></tr>

        <!-- FOOTER -->
        <tr><td style="background-color:#f7f8fa;border-top:1px solid #e4e7ed;padding:20px 40px;">
          <p style="margin:0 0 8px;font-size:11px;color:#9ca3af;line-height:1.6;">
            <strong style="color:#6b7280;">CONFIDENTIALITY NOTICE:</strong> This electronic mail message and any attachments are intended solely for the use of the individual or entity to whom they are addressed.
          </p>
          <p style="margin:0;font-size:11px;color:#9ca3af;">
            This is an automated reminder. You will continue to receive hourly reminders until this ticket status is changed from <strong>Open</strong>.
            &nbsp;&bull;&nbsp; &copy; {$currentYear} Universiti Kuala Lumpur. All rights reserved.
          </p>
        </td></tr>
        <tr><td style="height:4px;background:linear-gradient(90deg,#e8b200 0%,#f5cc30 50%,#e8b200 100%);"></td></tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

    $plainBody =
        "UNIKL RCMP HELP DESK — TICKET REMINDER\n" .
        "========================================\n\n" .
        "Ticket {$ticketId} has been OPEN for {$timeOpenStr} and has not been attended to.\n\n" .
        "Category  : {$catDisplay}\n" .
        "From Dept : {$fromDept}\n" .
        "Priority  : {$priority}\n" .
        "Handled By: " . ($assignedName ?? 'Unassigned') . "\n" .
        "Submitted : {$createdFormatted}\n" .
        "Time Open : {$timeOpenStr}\n\n" .
        "Please log in and update this ticket:\n" .
        "https://rush.rcmp.edu.my/\n\n" .
        "You will continue to receive hourly reminders until the ticket status is changed.\n" .
        "(c) {$currentYear} Universiti Kuala Lumpur.";

    // ── Send ONE email to the department group, BCC each active staff ───────
    $anySent = false;
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.office365.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'rush.rcmp@unikl.edu.my';
        $mail->Password   = 'Rcmp@4321';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->SMTPDebug  = 0;

        $mail->setFrom('rush.rcmp@unikl.edu.my', 'UniKL RCMP Help Desk');
        $mail->addAddress($deptGroupEmail, $deptGroupName);

        foreach ($staffList as $staff) {
            if (!empty($staff['email'])) {
                $mail->addBCC($staff['email'], $staff['full_name']);
            }
        }

        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = "[REMINDER] Open Ticket ({$timeOpenStr}) — Ref: {$ticketId}";
        $mail->Body    = $htmlBody;
        $mail->AltBody = $plainBody;

        $mail->send();
        $anySent = true;
        error_log("[Reminder] SENT [{$ticketId}] -> {$deptGroupEmail} (BCC " . count($staffList) . " staff)");
        echo "[" . date('H:i:s') . "]  ✓ Sent to {$deptGroupEmail} (BCC " . count($staffList) . " staff)\n";

    } catch (Exception $e) {
        error_log("[Reminder] FAILED [{$ticketId}] -> {$deptGroupEmail}: " . $mail->ErrorInfo);
        echo "[" . date('H:i:s') . "]  ✗ Failed for {$deptGroupEmail}: " . $mail->ErrorInfo . "\n";
    }

    // ── Mark ticket as reminded (even if some emails failed) so we
    //    don't keep retrying every cron cycle ───────────────────────────────
    markReminderSent($conn, $ticketId);
    echo "[" . date('H:i:s') . "] Reminder marked for {$ticketId}\n";
}

echo "[" . date('Y-m-d H:i:s') . "] Done.\n";

// ── Helper ────────────────────────────────────────────────────────────────────
function markReminderSent(mysqli $conn, string $ticketId): void
{
    $upd = $conn->prepare(
        "UPDATE complaints SET reminder_sent_at = NOW() WHERE ticket_id = ? LIMIT 1"
    );
    $upd->bind_param("s", $ticketId);
    $upd->execute();
    $upd->close();
}