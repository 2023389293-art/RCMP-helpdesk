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
 */
function sendComplaintEmail($conn, int $dept_id, string $ticketId): bool
{
    $deptStmt = $conn->prepare("SELECT dept_name FROM departments WHERE dept_id = ? LIMIT 1");
    $deptStmt->bind_param("i", $dept_id);
    $deptStmt->execute();
    $deptRow  = $deptStmt->get_result()->fetch_assoc();
    $deptStmt->close();
    $deptName = $deptRow['dept_name'] ?? 'Your Department';

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

    $currentYear = date('Y');
    $currentDate = date('d F Y');
    $anySuccess  = false;

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
  <title>UniKL RCMP Help Desk — New Complaint</title>
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
              <span style="font-size:12px;color:rgba(255,255,255,0.65);letter-spacing:0.06em;text-transform:uppercase;font-weight:500;">&#128203;&nbsp; Official Correspondence — New Complaint</span>
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

        <!-- BODY -->
        <tr><td style="padding:36px 40px 0;">
          <p style="margin:0 0 6px;font-size:12px;color:#9ca3af;">{$currentDate}</p>
          <p style="margin:0 0 20px;font-size:15px;color:#111827;font-weight:600;">Dear {$escapedStaffName},</p>
          <p style="margin:0 0 24px;font-size:14px;color:#374151;line-height:1.75;">
            A new complaint has been submitted through the UniKL RCMP Help Desk and has been routed to your department for attention. Kindly log in to the portal to review the complaint details and take the appropriate action.
          </p>

          <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #e4e7ed;border-radius:4px;overflow:hidden;margin-bottom:24px;">
            <tr><td colspan="2" style="background-color:#00327a;padding:10px 18px;">
              <span style="font-size:11px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:rgba(255,255,255,0.85);">Complaint Particulars</span>
            </td></tr>
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

          <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;">
            <tr><td style="border-left:3px solid #e8b200;background-color:#fffdf0;padding:16px 20px;border-radius:0 4px 4px 0;">
              <p style="margin:0 0 6px;font-size:11px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#92700a;">Action Required</p>
              <p style="margin:0 0 12px;font-size:14px;color:#374151;line-height:1.75;">Please log in to the UniKL RCMP Help Desk portal to view the full complaint details, assign a priority level, and provide a response to the complainant in a timely manner.</p>
              <a href="https://rush.rcmp.edu.my/" style="display:inline-block;padding:10px 22px;background-color:#00327a;color:#ffffff;font-size:13px;font-weight:600;text-decoration:none;border-radius:4px;">Login to Portal</a>
            </td></tr>
          </table>

          <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;">
            <tr><td style="height:1px;background-color:#e4e7ed;"></td></tr>
          </table>

          <p style="margin:0 0 4px;font-size:14px;color:#374151;">Yours sincerely,</p>
          <p style="margin:0 0 2px;font-size:14px;font-weight:700;color:#00327a;">UniKL RCMP Help Desk Team</p>
          <p style="margin:0 0 28px;font-size:12px;color:#9ca3af;">Universiti Kuala Lumpur</p>
        </td></tr>

        <!-- FOOTER -->
        <tr><td style="background-color:#f7f8fa;border-top:1px solid #e4e7ed;padding:20px 40px;">
          <p style="margin:0 0 8px;font-size:11px;color:#9ca3af;line-height:1.6;">
            <strong style="color:#6b7280;">CONFIDENTIALITY NOTICE:</strong> This electronic mail message and any attachments are intended solely for the use of the individual or entity to whom they are addressed.
          </p>
          <p style="margin:0;font-size:11px;color:#9ca3af;">This is a system-generated notification. Please do not reply directly to this email. &nbsp;&bull;&nbsp; &copy; {$currentYear} Universiti Kuala Lumpur. All rights reserved.</p>
        </td></tr>
        <tr><td style="height:4px;background:linear-gradient(90deg,#e8b200 0%,#f5cc30 50%,#e8b200 100%);"></td></tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

        $plainBody =
            "UNIKL RCMP HELP DESK — NEW COMPLAINT\n" .
            "=====================================\n\n" .
            "Reference No.        : {$ticketId}\n" .
            "Assigned Department  : {$deptName}\n" .
            "Date                 : {$currentDate}\n" .
            "Status               : Open — Awaiting Action\n\n" .
            "Dear {$staff['full_name']},\n\n" .
            "A new complaint has been submitted and routed to your department.\n" .
            "Please log in to https://rush.rcmp.edu.my/ to review and respond.\n\n" .
            "This is a system-generated notification. Please do not reply.\n" .
            "© {$currentYear} Universiti Kuala Lumpur.";

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
            $mail->addAddress($staff['email'], $staff['full_name']);
            $mail->isHTML(true);
            $mail->CharSet = 'UTF-8';
            $mail->Subject = "New Complaint — Ref: {$ticketId}";
            $mail->Body    = $htmlBody;
            $mail->AltBody = $plainBody;

            $mail->send();
            $anySuccess = true;
            error_log("[UniKL Mail] SUCCESS [{$ticketId}] -> {$staff['email']}");
        } catch (Exception $e) {
            error_log("[UniKL Mail] FAILED [{$ticketId}] -> {$staff['email']}: " . $mail->ErrorInfo);
        }
    }

    return $anySuccess;
}


/**
 * Send HTML notification to ALL active AFSMD staff when a requisition is submitted.
 */
function sendRequisitionEmail($conn, string $refNumber, string $userName, string $submitterType, string $submitterEmail, string $phone, string $my_department, string $category, string $item_name, int $quantity, string $location, string $reason, string $urgency, string $submittedOn): bool
{
    $staffStmt = $conn->prepare(
        "SELECT full_name, email FROM staff WHERE dept_id = 1 AND status = 'active'"
    );
    $staffStmt->execute();
    $staffList = $staffStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $staffStmt->close();

    if (empty($staffList)) {
        error_log("[UniKL Mail] WARNING: No active AFSMD staff for requisition {$refNumber}");
        return false;
    }

    $currentYear  = date('Y');
    $currentDate  = date('d F Y');
    $urgencyLabel = ucfirst($urgency);
    $urgColours   = [
        'normal'   => ['bg' => '#EFF6FF', 'color' => '#1D4ED8', 'border' => '#93C5FD'],
        'urgent'   => ['bg' => '#FEF3E2', 'color' => '#92520C', 'border' => '#FCD34D'],
        'critical' => ['bg' => '#FDECEA', 'color' => '#B71C1C', 'border' => '#FCA5A5'],
    ];
    $uc = $urgColours[$urgency] ?? $urgColours['normal'];

    $anySuccess = false;

    foreach ($staffList as $staff) {
        $escapedStaffName     = htmlspecialchars($staff['full_name']);
        $escapedRefNumber     = htmlspecialchars($refNumber);
        $escapedSubmitter     = htmlspecialchars($userName);
        $escapedSubmitterType = htmlspecialchars(ucfirst($submitterType));
        $escapedEmail         = htmlspecialchars($submitterEmail);
        $escapedPhone         = htmlspecialchars('+60' . $phone);
        $escapedDept          = htmlspecialchars($my_department);
        $escapedCategory      = htmlspecialchars($category);
        $escapedItem          = htmlspecialchars($item_name);
        $escapedLocation      = htmlspecialchars($location);
        $escapedReason        = nl2br(htmlspecialchars($reason));
        $escapedSubmittedOn   = htmlspecialchars($submittedOn);

        $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>UniKL RCMP Help Desk — New Equipment Request</title>
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
                  <div style="font-size:18px;font-weight:700;color:#ffffff;">RCMP Help Desk</div>
                </td>
              </tr></table>
            </td></tr>
          </table>
          <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr><td style="height:1px;background-color:rgba(255,255,255,0.1);"></td></tr>
          </table>
          <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr><td style="padding:12px 40px 16px;background-color:#002660;">
              <span style="font-size:12px;color:rgba(255,255,255,0.65);letter-spacing:0.06em;text-transform:uppercase;font-weight:500;">&#128203;&nbsp; Official Correspondence — New Equipment Request</span>
            </td></tr>
          </table>
        </td></tr>

        <!-- REFERENCE BAR -->
        <tr><td style="background-color:#f7f8fa;border-bottom:1px solid #e4e7ed;padding:14px 40px;">
          <table width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
            <td style="font-size:12px;color:#6b7280;font-weight:500;">Reference No.</td>
            <td align="right" style="font-size:13px;font-weight:700;color:#854f0b;font-family:'Courier New',Courier,monospace;letter-spacing:0.05em;">{$escapedRefNumber}</td>
          </tr></table>
        </td></tr>

        <!-- BODY -->
        <tr><td style="padding:36px 40px 0;">
          <p style="margin:0 0 6px;font-size:12px;color:#9ca3af;">{$currentDate}</p>
          <p style="margin:0 0 20px;font-size:15px;color:#111827;font-weight:600;">Dear {$escapedStaffName},</p>
          <p style="margin:0 0 24px;font-size:14px;color:#374151;line-height:1.75;">
            A new equipment request has been submitted through the UniKL RCMP Help Desk and routed to the Administration &amp; Facilities Management Department (AFSMD) for processing.
          </p>

          <!-- Submitter Info -->
          <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #e4e7ed;border-radius:4px;overflow:hidden;margin-bottom:16px;">
            <tr><td colspan="2" style="background-color:#00327a;padding:10px 18px;">
              <span style="font-size:11px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:rgba(255,255,255,0.85);">Submitter Information</span>
            </td></tr>
            <tr>
              <td style="width:40%;padding:11px 18px;background-color:#f7f8fa;border-bottom:1px solid #e4e7ed;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;">Submitted By</td>
              <td style="padding:11px 18px;background-color:#ffffff;border-bottom:1px solid #e4e7ed;font-size:13px;color:#111827;">{$escapedSubmitter} <span style="color:#6b7280;font-size:12px;">({$escapedSubmitterType})</span></td>
            </tr>
            <tr>
              <td style="width:40%;padding:11px 18px;background-color:#f7f8fa;border-bottom:1px solid #e4e7ed;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;">Email</td>
              <td style="padding:11px 18px;background-color:#ffffff;border-bottom:1px solid #e4e7ed;font-size:13px;color:#111827;">{$escapedEmail}</td>
            </tr>
            <tr>
              <td style="width:40%;padding:11px 18px;background-color:#f7f8fa;border-bottom:1px solid #e4e7ed;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;">Contact</td>
              <td style="padding:11px 18px;background-color:#ffffff;border-bottom:1px solid #e4e7ed;font-size:13px;color:#111827;">{$escapedPhone}</td>
            </tr>
            <tr>
              <td style="width:40%;padding:11px 18px;background-color:#f7f8fa;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;">Department</td>
              <td style="padding:11px 18px;background-color:#ffffff;font-size:13px;color:#111827;">{$escapedDept}</td>
            </tr>
          </table>

          <!-- Request Details -->
          <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #e4e7ed;border-radius:4px;overflow:hidden;margin-bottom:24px;">
            <tr><td colspan="2" style="background-color:#6d3f08;padding:10px 18px;">
              <span style="font-size:11px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:rgba(255,255,255,0.85);">Request Particulars</span>
            </td></tr>
            <tr>
              <td style="width:40%;padding:11px 18px;background-color:#f7f8fa;border-bottom:1px solid #e4e7ed;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;">Reference No.</td>
              <td style="padding:11px 18px;background-color:#ffffff;border-bottom:1px solid #e4e7ed;font-size:13px;font-weight:700;font-family:'Courier New',Courier,monospace;color:#111827;">{$escapedRefNumber}</td>
            </tr>
            <tr>
              <td style="width:40%;padding:11px 18px;background-color:#f7f8fa;border-bottom:1px solid #e4e7ed;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;">Category</td>
              <td style="padding:11px 18px;background-color:#ffffff;border-bottom:1px solid #e4e7ed;font-size:13px;color:#111827;">{$escapedCategory}</td>
            </tr>
            <tr>
              <td style="width:40%;padding:11px 18px;background-color:#f7f8fa;border-bottom:1px solid #e4e7ed;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;">Item Requested</td>
              <td style="padding:11px 18px;background-color:#ffffff;border-bottom:1px solid #e4e7ed;font-size:13px;color:#111827;">{$escapedItem}</td>
            </tr>
            <tr>
              <td style="width:40%;padding:11px 18px;background-color:#f7f8fa;border-bottom:1px solid #e4e7ed;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;">Quantity</td>
              <td style="padding:11px 18px;background-color:#ffffff;border-bottom:1px solid #e4e7ed;font-size:13px;color:#111827;">{$quantity}</td>
            </tr>
            <tr>
              <td style="width:40%;padding:11px 18px;background-color:#f7f8fa;border-bottom:1px solid #e4e7ed;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;">Location</td>
              <td style="padding:11px 18px;background-color:#ffffff;border-bottom:1px solid #e4e7ed;font-size:13px;color:#111827;">{$escapedLocation}</td>
            </tr>
            <tr>
              <td style="width:40%;padding:11px 18px;background-color:#f7f8fa;border-bottom:1px solid #e4e7ed;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;">Urgency</td>
              <td style="padding:11px 18px;background-color:#ffffff;border-bottom:1px solid #e4e7ed;">
                <span style="display:inline-block;font-size:12px;font-weight:600;padding:3px 12px;border-radius:20px;background:{$uc['bg']};color:{$uc['color']};border:1px solid {$uc['border']};">{$urgencyLabel}</span>
              </td>
            </tr>
            <tr>
              <td style="width:40%;padding:11px 18px;background-color:#f7f8fa;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;">Submitted On</td>
              <td style="padding:11px 18px;background-color:#ffffff;font-size:13px;color:#111827;">{$escapedSubmittedOn} MYT</td>
            </tr>
          </table>

          <!-- Justification -->
          <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
            <tr><td style="border-left:3px solid #e8b200;background-color:#fffdf0;padding:16px 20px;border-radius:0 4px 4px 0;">
              <p style="margin:0 0 6px;font-size:11px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#92700a;">Justification</p>
              <p style="margin:0;font-size:14px;color:#374151;line-height:1.75;">{$escapedReason}</p>
            </td></tr>
          </table>

          <!-- Action Required -->
          <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;">
            <tr><td style="border-left:3px solid #00327a;background-color:#f0f4ff;padding:16px 20px;border-radius:0 4px 4px 0;">
              <p style="margin:0 0 6px;font-size:11px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#00327a;">Action Required</p>
              <p style="margin:0 0 12px;font-size:14px;color:#374151;line-height:1.75;">Please log in to the UniKL RCMP admin portal to review and process this equipment request.</p>
              <a href="https://rush.rcmp.edu.my/" style="display:inline-block;padding:10px 22px;background-color:#00327a;color:#ffffff;font-size:13px;font-weight:600;text-decoration:none;border-radius:4px;">Login to Portal</a>
            </td></tr>
          </table>

          <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;">
            <tr><td style="height:1px;background-color:#e4e7ed;"></td></tr>
          </table>

          <p style="margin:0 0 4px;font-size:14px;color:#374151;">Yours sincerely,</p>
          <p style="margin:0 0 2px;font-size:14px;font-weight:700;color:#00327a;">UniKL RCMP Help Desk Team</p>
          <p style="margin:0 0 28px;font-size:12px;color:#9ca3af;">Universiti Kuala Lumpur</p>
        </td></tr>

        <!-- FOOTER -->
        <tr><td style="background-color:#f7f8fa;border-top:1px solid #e4e7ed;padding:20px 40px;">
          <p style="margin:0 0 8px;font-size:11px;color:#9ca3af;line-height:1.6;">
            <strong style="color:#6b7280;">CONFIDENTIALITY NOTICE:</strong> This electronic mail message and any attachments are intended solely for the use of the individual or entity to whom they are addressed.
          </p>
          <p style="margin:0;font-size:11px;color:#9ca3af;">This is a system-generated notification. Please do not reply directly to this email. &nbsp;&bull;&nbsp; &copy; {$currentYear} Universiti Kuala Lumpur. All rights reserved.</p>
        </td></tr>
        <tr><td style="height:4px;background:linear-gradient(90deg,#e8b200 0%,#f5cc30 50%,#e8b200 100%);"></td></tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

        $plainBody =
            "UNIKL RCMP HELP DESK — NEW EQUIPMENT REQUEST\n" .
            "==============================================\n\n" .
            "Reference No.    : {$refNumber}\n" .
            "Submitted By     : {$userName} ({$submitterType})\n" .
            "Email            : {$submitterEmail}\n" .
            "Contact          : +60{$phone}\n" .
            "Department       : {$my_department}\n" .
            "Category         : {$category}\n" .
            "Item Requested   : {$item_name}\n" .
            "Quantity         : {$quantity}\n" .
            "Location         : {$location}\n" .
            "Urgency          : {$urgencyLabel}\n" .
            "Submitted On     : {$submittedOn} MYT\n\n" .
            str_repeat("-", 50) . "\n" .
            "JUSTIFICATION\n" .
            str_repeat("-", 50) . "\n" .
            "{$reason}\n\n" .
            "Login to portal: https://rush.rcmp.edu.my/\n\n" .
            "© {$currentYear} Universiti Kuala Lumpur.";

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
            $mail->addAddress($staff['email'], $staff['full_name']);
            $mail->isHTML(true);
            $mail->CharSet = 'UTF-8';
            $mail->Subject = "New Equipment Request — Ref: {$refNumber}";
            $mail->Body    = $htmlBody;
            $mail->AltBody = $plainBody;

            $mail->send();
            $anySuccess = true;
            error_log("[UniKL Mail] REQ SUCCESS [{$refNumber}] -> {$staff['email']}");
        } catch (Exception $e) {
            error_log("[UniKL Mail] REQ FAILED [{$refNumber}] -> {$staff['email']}: " . $mail->ErrorInfo);
        }
    }

    return $anySuccess;
}


/**
 * Send a HTML confirmation email to the person who submitted the requisition.
 * Same UniKL blue/gold design — green confirmation theme.
 */
function sendRequisitionConfirmationEmail(string $toEmail, string $toName, string $refNumber, string $category, string $item_name, int $quantity, string $my_department, string $location, string $urgency, string $submittedOn): bool
{
    if (empty($toEmail) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) return false;

    $currentYear  = date('Y');
    $currentDate  = date('d F Y');
    $urgencyLabel = ucfirst($urgency);

    $escapedName        = htmlspecialchars($toName);
    $escapedRefNumber   = htmlspecialchars($refNumber);
    $escapedCategory    = htmlspecialchars($category);
    $escapedItem        = htmlspecialchars($item_name);
    $escapedDept        = htmlspecialchars($my_department);
    $escapedLocation    = htmlspecialchars($location);
    $escapedSubmittedOn = htmlspecialchars($submittedOn);

    $urgColours = [
        'normal'   => ['bg' => '#EFF6FF', 'color' => '#1D4ED8', 'border' => '#93C5FD'],
        'urgent'   => ['bg' => '#FEF3E2', 'color' => '#92520C', 'border' => '#FCD34D'],
        'critical' => ['bg' => '#FDECEA', 'color' => '#B71C1C', 'border' => '#FCA5A5'],
    ];
    $uc = $urgColours[$urgency] ?? $urgColours['normal'];

    $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>UniKL RCMP — Equipment Request Received</title>
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
                  <div style="font-size:18px;font-weight:700;color:#ffffff;">RCMP Help Desk</div>
                </td>
              </tr></table>
            </td></tr>
          </table>
          <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr><td style="height:1px;background-color:rgba(255,255,255,0.1);"></td></tr>
          </table>
          <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr><td style="padding:12px 40px 16px;background-color:#002660;">
              <span style="font-size:12px;color:rgba(255,255,255,0.65);letter-spacing:0.06em;text-transform:uppercase;font-weight:500;">&#9989;&nbsp; Equipment Request — Submission Confirmation</span>
            </td></tr>
          </table>
        </td></tr>

        <!-- REFERENCE BAR -->
        <tr><td style="background-color:#f7f8fa;border-bottom:1px solid #e4e7ed;padding:14px 40px;">
          <table width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
            <td style="font-size:12px;color:#6b7280;font-weight:500;">Reference No.</td>
            <td align="right" style="font-size:13px;font-weight:700;color:#854f0b;font-family:'Courier New',Courier,monospace;letter-spacing:0.05em;">{$escapedRefNumber}</td>
          </tr></table>
        </td></tr>

        <!-- SUCCESS BANNER -->
        <tr><td style="background-color:#f0fdf4;border-bottom:1px solid #bbf7d0;padding:20px 40px;">
          <table cellpadding="0" cellspacing="0" border="0"><tr>
            <td style="vertical-align:middle;padding-right:14px;">
              <div style="width:40px;height:40px;background-color:#22c55e;border-radius:50%;text-align:center;line-height:40px;"><span style="font-size:20px;">✓</span></div>
            </td>
            <td style="vertical-align:middle;">
              <div style="font-size:15px;font-weight:700;color:#15803d;margin-bottom:2px;">Request Successfully Submitted</div>
              <div style="font-size:13px;color:#166534;">Your request has been forwarded to Administration &amp; Facilities Management Department.</div>
            </td>
          </tr></table>
        </td></tr>

        <!-- BODY -->
        <tr><td style="padding:36px 40px 0;">
          <p style="margin:0 0 6px;font-size:12px;color:#9ca3af;">{$currentDate}</p>
          <p style="margin:0 0 20px;font-size:15px;color:#111827;font-weight:600;">Dear {$escapedName},</p>
          <p style="margin:0 0 24px;font-size:14px;color:#374151;line-height:1.75;">
            Thank you for submitting your equipment request through the UniKL RCMP Help Desk. Your request has been successfully recorded and forwarded to the Administration &amp; Facilities Management Department (AFSMD) for review and processing.
          </p>

          <!-- Request Summary -->
          <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #e4e7ed;border-radius:4px;overflow:hidden;margin-bottom:24px;">
            <tr><td colspan="2" style="background-color:#6d3f08;padding:10px 18px;">
              <span style="font-size:11px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:rgba(255,255,255,0.85);">Your Request Summary</span>
            </td></tr>
            <tr>
              <td style="width:40%;padding:11px 18px;background-color:#f7f8fa;border-bottom:1px solid #e4e7ed;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;">Reference No.</td>
              <td style="padding:11px 18px;background-color:#ffffff;border-bottom:1px solid #e4e7ed;font-size:13px;font-weight:700;font-family:'Courier New',Courier,monospace;color:#854f0b;">{$escapedRefNumber}</td>
            </tr>
            <tr>
              <td style="width:40%;padding:11px 18px;background-color:#f7f8fa;border-bottom:1px solid #e4e7ed;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;">Category</td>
              <td style="padding:11px 18px;background-color:#ffffff;border-bottom:1px solid #e4e7ed;font-size:13px;color:#111827;">{$escapedCategory}</td>
            </tr>
            <tr>
              <td style="width:40%;padding:11px 18px;background-color:#f7f8fa;border-bottom:1px solid #e4e7ed;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;">Item Requested</td>
              <td style="padding:11px 18px;background-color:#ffffff;border-bottom:1px solid #e4e7ed;font-size:13px;color:#111827;">{$escapedItem}</td>
            </tr>
            <tr>
              <td style="width:40%;padding:11px 18px;background-color:#f7f8fa;border-bottom:1px solid #e4e7ed;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;">Quantity</td>
              <td style="padding:11px 18px;background-color:#ffffff;border-bottom:1px solid #e4e7ed;font-size:13px;color:#111827;">{$quantity}</td>
            </tr>
            <tr>
              <td style="width:40%;padding:11px 18px;background-color:#f7f8fa;border-bottom:1px solid #e4e7ed;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;">Your Department</td>
              <td style="padding:11px 18px;background-color:#ffffff;border-bottom:1px solid #e4e7ed;font-size:13px;color:#111827;">{$escapedDept}</td>
            </tr>
            <tr>
              <td style="width:40%;padding:11px 18px;background-color:#f7f8fa;border-bottom:1px solid #e4e7ed;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;">Delivery Location</td>
              <td style="padding:11px 18px;background-color:#ffffff;border-bottom:1px solid #e4e7ed;font-size:13px;color:#111827;">{$escapedLocation}</td>
            </tr>
            <tr>
              <td style="width:40%;padding:11px 18px;background-color:#f7f8fa;border-bottom:1px solid #e4e7ed;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;">Urgency</td>
              <td style="padding:11px 18px;background-color:#ffffff;border-bottom:1px solid #e4e7ed;">
                <span style="display:inline-block;font-size:12px;font-weight:600;padding:3px 12px;border-radius:20px;background:{$uc['bg']};color:{$uc['color']};border:1px solid {$uc['border']};">{$urgencyLabel}</span>
              </td>
            </tr>
            <tr>
              <td style="width:40%;padding:11px 18px;background-color:#f7f8fa;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;">Submitted On</td>
              <td style="padding:11px 18px;background-color:#ffffff;font-size:13px;color:#111827;">{$escapedSubmittedOn} MYT</td>
            </tr>
          </table>

          <!-- What Happens Next -->
          <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
            <tr><td style="border-left:3px solid #e8b200;background-color:#fffdf0;padding:16px 20px;border-radius:0 4px 4px 0;">
              <p style="margin:0 0 8px;font-size:11px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#92700a;">What Happens Next?</p>
              <p style="margin:0 0 6px;font-size:13px;color:#374151;line-height:1.7;">&#8226;&nbsp; AFSMD staff will review your request during working hours (Mon–Fri, 8:00 AM – 5:00 PM MYT).</p>
              <p style="margin:0 0 6px;font-size:13px;color:#374151;line-height:1.7;">&#8226;&nbsp; They may contact you for additional information if required.</p>
              <p style="margin:0;font-size:13px;color:#374151;line-height:1.7;">&#8226;&nbsp; Please keep your reference number for future correspondence.</p>
            </td></tr>
          </table>

          <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;">
            <tr><td style="height:1px;background-color:#e4e7ed;"></td></tr>
          </table>

          <p style="margin:0 0 4px;font-size:14px;color:#374151;">Yours sincerely,</p>
          <p style="margin:0 0 2px;font-size:14px;font-weight:700;color:#00327a;">UniKL RCMP Help Desk Team</p>
          <p style="margin:0 0 28px;font-size:12px;color:#9ca3af;">Universiti Kuala Lumpur — Administration &amp; Facilities Management Department</p>
        </td></tr>

        <!-- FOOTER -->
        <tr><td style="background-color:#f7f8fa;border-top:1px solid #e4e7ed;padding:20px 40px;">
          <p style="margin:0 0 8px;font-size:11px;color:#9ca3af;line-height:1.6;">
            <strong style="color:#6b7280;">NOTE:</strong> This is a system-generated confirmation. Please do not reply directly to this email. If you have questions, please contact AFSMD through the portal.
          </p>
          <p style="margin:0;font-size:11px;color:#9ca3af;">&copy; {$currentYear} Universiti Kuala Lumpur. All rights reserved.</p>
        </td></tr>
        <tr><td style="height:4px;background:linear-gradient(90deg,#e8b200 0%,#f5cc30 50%,#e8b200 100%);"></td></tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

    $plainBody =
        "UNIKL RCMP HELP DESK — REQUEST CONFIRMATION\n" .
        "=============================================\n\n" .
        "Dear {$toName},\n\n" .
        "Your equipment request has been successfully submitted.\n\n" .
        "Reference No.    : {$refNumber}\n" .
        "Category         : {$category}\n" .
        "Item Requested   : {$item_name}\n" .
        "Quantity         : {$quantity}\n" .
        "Your Department  : {$my_department}\n" .
        "Delivery Location: {$location}\n" .
        "Urgency          : {$urgencyLabel}\n" .
        "Submitted On     : {$submittedOn} MYT\n\n" .
        "AFSMD staff will review your request during working hours (Mon-Fri, 8AM-5PM MYT).\n" .
        "Please keep your reference number for future correspondence.\n\n" .
        "© {$currentYear} Universiti Kuala Lumpur.";

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
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = "[UniKL RCMP] Equipment Request Received — {$refNumber}";
        $mail->Body    = $htmlBody;
        $mail->AltBody = $plainBody;

        $mail->send();
        error_log("[UniKL Mail] CONFIRM SUCCESS [{$refNumber}] -> {$toEmail}");
        return true;
    } catch (Exception $e) {
        error_log("[UniKL Mail] CONFIRM FAILED [{$refNumber}] -> {$toEmail}: " . $mail->ErrorInfo);
        return false;
    }
}


/**
 * Send a plain-text email (kept for backward compatibility).
 */
function sendRawEmail(string $to, string $subject, string $body): bool
{
    if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;

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
        $mail->addAddress($to);
        $mail->isHTML(false);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("[UniKL Mail] sendRawEmail FAILED -> {$to}: " . $mail->ErrorInfo);
        return false;
    }
}