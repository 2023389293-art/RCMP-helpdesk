<?php
// ============================================================
// FILE LOCATION: www/uniKL/complaint/mail_helper.php
// ============================================================

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/PHPMailer-master/src/Exception.php';
require __DIR__ . '/PHPMailer-master/src/PHPMailer.php';
require __DIR__ . '/PHPMailer-master/src/SMTP.php';

/**
 * Send a formal ticket assignment notification to ALL active staff
 * in the responsible department when a new complaint is submitted.
 *
 * @param mysqli $conn     Active DB connection
 * @param int    $dept_id  Department ID (from categories → complaints)
 * @param string $ticketId e.g. RCMP-07042026-00001
 * @return bool            true if at least one email succeeded
 */
function sendComplaintEmail($conn, int $dept_id, string $ticketId): bool
{
    // ── 1. Get department name ────────────────────────────────────────────────
    $deptStmt = $conn->prepare("SELECT dept_name FROM departments WHERE dept_id = ? LIMIT 1");
    $deptStmt->bind_param("i", $dept_id);
    $deptStmt->execute();
    $deptRow  = $deptStmt->get_result()->fetch_assoc();
    $deptStmt->close();
    $deptName = $deptRow['dept_name'] ?? 'Your Department';

    // ── 2. Get ALL active staff in this department ────────────────────────────
    $staffStmt = $conn->prepare(
        "SELECT full_name, email FROM staff WHERE dept_id = ? AND status = 'active'"
    );
    $staffStmt->bind_param("i", $dept_id);
    $staffStmt->execute();
    $staffList = $staffStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $staffStmt->close();

    if (empty($staffList)) {
        error_log("[UniKL Mail] WARNING: No active staff found for dept_id={$dept_id}, ticket={$ticketId}");
        return false;
    }

    error_log("[UniKL Mail] Sending ticket {$ticketId} to " . count($staffList) . " staff in dept_id={$dept_id} ({$deptName})");

    $currentYear = date('Y');
    $currentDate = date('d F Y');

    // ── 3. Send one email per staff member ────────────────────────────────────
    $anySuccess = false;

    foreach ($staffList as $staff) {

        $escapedStaffName = htmlspecialchars($staff['full_name']);
        $escapedDeptName  = htmlspecialchars($deptName);
        $escapedTicketId  = htmlspecialchars($ticketId);

        $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>UniKL RCMP Help Desk — New Complaint Assignment</title>
</head>
<body style="margin:0;padding:0;background-color:#f0f2f5;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;">

  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f0f2f5;padding:40px 16px;">
    <tr>
      <td align="center">

        <!-- Email container -->
        <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:4px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">

          <!-- ══ HEADER ══ -->
          <tr>
            <td style="background-color:#00327a;padding:0;">
              <!-- Top accent line -->
              <table width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td style="height:4px;background:linear-gradient(90deg,#e8b200 0%,#f5cc30 50%,#e8b200 100%);"></td>
                </tr>
              </table>
              <!-- Header content -->
              <table width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td style="padding:28px 40px 24px;">
                    <table cellpadding="0" cellspacing="0" border="0">
                      <tr>
                        <td style="vertical-align:middle;padding-right:16px;">
                          <div style="width:48px;height:48px;background-color:rgba(255,255,255,0.12);border-radius:4px;text-align:center;line-height:48px;">
                            <span style="font-size:24px;">🏛️</span>
                          </div>
                        </td>
                        <td style="vertical-align:middle;">
                          <div style="font-size:11px;font-weight:600;letter-spacing:0.12em;text-transform:uppercase;color:rgba(255,255,255,0.55);margin-bottom:4px;">Universiti Kuala Lumpur</div>
                          <div style="font-size:18px;font-weight:700;color:#ffffff;letter-spacing:0.01em;">RCMP Help Desk</div>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
              </table>
              <!-- Sub-header strip -->
              <table width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td style="height:1px;background-color:rgba(255,255,255,0.1);"></td>
                </tr>
              </table>
              <table width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td style="padding:12px 40px 16px;background-color:#002660;">
                    <span style="font-size:12px;color:rgba(255,255,255,0.65);letter-spacing:0.06em;text-transform:uppercase;font-weight:500;">&#128203;&nbsp; Official Correspondence — New Complaint Assignment</span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- ══ REFERENCE BAR ══ -->
          <tr>
            <td style="background-color:#f7f8fa;border-bottom:1px solid #e4e7ed;padding:14px 40px;">
              <table width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td style="font-size:12px;color:#6b7280;font-weight:500;">Reference No.</td>
                  <td align="right" style="font-size:13px;font-weight:700;color:#00327a;font-family:'Courier New',Courier,monospace;letter-spacing:0.05em;">{$escapedTicketId}</td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- ══ BODY ══ -->
          <tr>
            <td style="padding:36px 40px 0;">

              <p style="margin:0 0 6px;font-size:12px;color:#9ca3af;">{$currentDate}</p>
              <p style="margin:0 0 20px;font-size:15px;color:#111827;font-weight:600;">Dear {$escapedStaffName},</p>

              <p style="margin:0 0 24px;font-size:14px;color:#374151;line-height:1.75;">
                A new complaint has been submitted through the UniKL RCMP Help Desk and has been routed to your department for attention. Kindly log in to the portal to review the complaint details and take the appropriate action.
              </p>

              <!-- Complaint Detail Table -->
              <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #e4e7ed;border-radius:4px;overflow:hidden;margin-bottom:24px;">
                <tr>
                  <td colspan="2" style="background-color:#00327a;padding:10px 18px;">
                    <span style="font-size:11px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:rgba(255,255,255,0.85);">Assignment Particulars</span>
                  </td>
                </tr>
                <tr>
                  <td style="width:40%;padding:12px 18px;background-color:#f7f8fa;border-bottom:1px solid #e4e7ed;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;">Ticket Reference</td>
                  <td style="padding:12px 18px;background-color:#ffffff;border-bottom:1px solid #e4e7ed;font-size:13px;color:#111827;font-weight:700;font-family:'Courier New',Courier,monospace;">{$escapedTicketId}</td>
                </tr>
                <tr>
                  <td style="width:40%;padding:12px 18px;background-color:#f7f8fa;border-bottom:1px solid #e4e7ed;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;">Assigned Department</td>
                  <td style="padding:12px 18px;background-color:#ffffff;border-bottom:1px solid #e4e7ed;font-size:13px;color:#111827;">{$escapedDeptName}</td>
                </tr>
                <tr>
                  <td style="width:40%;padding:12px 18px;background-color:#f7f8fa;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;">Status</td>
                  <td style="padding:12px 18px;background-color:#ffffff;">
                    <span style="display:inline-block;font-size:12px;font-weight:600;padding:3px 12px;border-radius:20px;background:#FEF3C7;color:#D97706;">Open — Awaiting Action</span>
                  </td>
                </tr>
              </table>

              <!-- Action required notice -->
              <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;">
                <tr>
                  <td style="border-left:3px solid #e8b200;background-color:#fffdf0;padding:16px 20px;border-radius:0 4px 4px 0;">
                    <p style="margin:0 0 6px;font-size:11px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#92700a;">Action Required</p>
                    <p style="margin:0;font-size:14px;color:#374151;line-height:1.75;">Please log in to the UniKL RCMP Help Desk portal to view the full complaint details, assign a priority level, and provide a response to the complainant in a timely manner.</p>
                  </td>
                </tr>
              </table>

              <!-- Divider -->
              <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;">
                <tr><td style="height:1px;background-color:#e4e7ed;"></td></tr>
              </table>

              <!-- Sign-off -->
              <p style="margin:0 0 4px;font-size:14px;color:#374151;">Yours sincerely,</p>
              <p style="margin:0 0 2px;font-size:14px;font-weight:700;color:#00327a;">UniKL RCMP Help Desk Team</p>
              <p style="margin:0 0 28px;font-size:12px;color:#9ca3af;">Universiti Kuala Lumpur</p>

            </td>
          </tr>

          <!-- ══ FOOTER ══ -->
          <tr>
            <td style="background-color:#f7f8fa;border-top:1px solid #e4e7ed;padding:20px 40px;">
              <p style="margin:0 0 8px;font-size:11px;color:#9ca3af;line-height:1.6;">
                <strong style="color:#6b7280;">CONFIDENTIALITY NOTICE:</strong>
                This electronic mail message and any attachments are intended solely for the use of the individual or entity to whom they are addressed. If you have received this message in error, please notify the sender immediately and delete all copies.
              </p>
              <p style="margin:0;font-size:11px;color:#9ca3af;">
                This is a system-generated notification. Please do not reply directly to this email.
                &nbsp;&bull;&nbsp; &copy; {$currentYear} Universiti Kuala Lumpur. All rights reserved.
              </p>
            </td>
          </tr>

          <!-- Bottom accent line -->
          <tr>
            <td style="height:4px;background:linear-gradient(90deg,#e8b200 0%,#f5cc30 50%,#e8b200 100%);"></td>
          </tr>

        </table>

      </td>
    </tr>
  </table>

</body>
</html>
HTML;

        $plainBody =
            "UNIKL RCMP HELP DESK — NEW COMPLAINT ASSIGNMENT\n" .
            "=================================================\n\n" .
            "Reference No.        : {$ticketId}\n" .
            "Assigned Department  : {$deptName}\n" .
            "Date                 : {$currentDate}\n" .
            "Status               : Open — Awaiting Action\n\n" .
            "Dear {$staff['full_name']},\n\n" .
            "A new complaint has been submitted through the UniKL RCMP Help Desk and has been\n" .
            "routed to your department for attention. Kindly log in to the portal to review the\n" .
            "complaint details and take the appropriate action.\n\n" .
            str_repeat("-", 50) . "\n" .
            "ACTION REQUIRED\n" .
            str_repeat("-", 50) . "\n" .
            "Please log in to the UniKL RCMP Help Desk portal to view the full complaint details,\n" .
            "assign a priority level, and respond to the complainant in a timely manner.\n\n" .
            "This is a system-generated notification. Please do not reply directly to this email.\n" .
            "© {$currentYear} Universiti Kuala Lumpur. All rights reserved.";

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host        = 'smtp.gmail.com';
            $mail->SMTPAuth    = true;
            $mail->Username    = 'farahwdi33@gmail.com';
            $mail->Password    = 'wvgq vqdn dbiw vcjn';
            $mail->SMTPSecure  = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port        = 587;
            $mail->SMTPDebug   = 2;
            $mail->Debugoutput = 'error_log';

            $mail->setFrom('farahwdi33@gmail.com', 'UniKL RCMP Help Desk');
            $mail->addAddress($staff['email'], $staff['full_name']);

            $mail->isHTML(true);
            $mail->CharSet = 'UTF-8';
            $mail->Subject = "New Complaint Assignment — Ref: {$ticketId}";
            $mail->Body    = $htmlBody;
            $mail->AltBody = $plainBody;

            $mail->send();
            $anySuccess = true;
            error_log("[UniKL Mail] SUCCESS [{$ticketId}] -> {$staff['email']} ({$staff['full_name']})");

        } catch (Exception $e) {
            error_log("[UniKL Mail] FAILED  [{$ticketId}] -> {$staff['email']} ({$staff['full_name']}): " . $mail->ErrorInfo);
        }
    }

    return $anySuccess;
}