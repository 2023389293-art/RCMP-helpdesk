<?php
// dept_admin/it/vendors.php
// IT Admin — Manage Vendors (approve/suspend/delete vendors serving IT dept)
require '_layout.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require __DIR__ . '/../../PHPMailer-master/src/Exception.php';
require __DIR__ . '/../../PHPMailer-master/src/PHPMailer.php';
require __DIR__ . '/../../PHPMailer-master/src/SMTP.php';

$msg   = '';
$error = '';

// ── Send vendor decision email (approved / rejected) ───────────────────────
function sendVendorDecisionEmail(string $toEmail, string $toName, string $companyName, bool $isApproved, bool $stillServesOtherDepts = false): bool {
    $currentYear = date('Y');
    $currentDate = date('d F Y');
    $escapedTo      = htmlspecialchars($toName);
    $escapedCompany = htmlspecialchars($companyName);

    if ($isApproved) {
        $statusLabel = 'Approved';
        $statBg = '#D1FAE5'; $statFg = '#059669';
        $messageBody = '<p style="margin:0 0 12px;font-size:14px;color:#374151;line-height:1.75;">Congratulations! Your vendor application to serve the <strong>IT Department</strong> has been reviewed and <strong>approved</strong>. You may now log in to the vendor portal using your registered email and password to receive and manage assigned tickets.</p>';
        $btnLabel = 'Login to Vendor Portal';
    } else {
        $statusLabel = 'Not Approved';
        $statBg = '#FEF2F2'; $statFg = '#DC2626';
        $messageBody = '<p style="margin:0 0 12px;font-size:14px;color:#374151;line-height:1.75;">We regret to inform you that your vendor application to serve the <strong>IT Department</strong> was <strong>not approved</strong> at this time. If you believe this was in error or would like more information, please contact the IT Department directly.</p>';
        if ($stillServesOtherDepts) {
            $messageBody .= '<p style="margin:0 0 12px;font-size:14px;color:#374151;line-height:1.75;">This decision only affects your application to the <strong>IT Department</strong>. Your account remains active for any other department(s) you currently serve, and you can continue to log in to the vendor portal as usual.</p>';
        } else {
            $messageBody .= '<p style="margin:0 0 12px;font-size:14px;color:#374151;line-height:1.75;">As you do not currently serve any other department, your vendor account has been removed from the system.</p>';
        }
        $btnLabel = 'Visit Help Desk';
    }

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
      <span style="font-size:12px;color:rgba(255,255,255,.65);letter-spacing:.06em;text-transform:uppercase;">&#128203;&nbsp; Vendor Application Update</span>
    </td></tr></table>
  </td></tr>
  <tr><td style="background:#f7f8fa;border-bottom:1px solid #e4e7ed;padding:14px 40px;">
    <table width="100%"><tr>
      <td style="font-size:12px;color:#6b7280;">Company</td>
      <td align="right" style="font-size:13px;font-weight:700;color:#00327a;">{$escapedCompany}</td>
    </tr></table>
  </td></tr>
  <tr><td style="padding:36px 40px 0;">
    <p style="margin:0 0 6px;font-size:12px;color:#9ca3af;">{$currentDate}</p>
    <p style="margin:0 0 20px;font-size:15px;font-weight:600;color:#111827;">Dear {$escapedTo},</p>
    <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e4e7ed;border-radius:4px;overflow:hidden;margin-bottom:20px;">
      <tr><td colspan="2" style="background:#00327a;padding:10px 18px;"><span style="font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.85);">Application Status</span></td></tr>
      <tr>
        <td style="width:40%;padding:12px 18px;background:#f7f8fa;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;">Status</td>
        <td style="padding:12px 18px;"><span style="display:inline-block;font-size:12px;font-weight:600;padding:3px 12px;border-radius:20px;background:{$statBg};color:{$statFg};">{$statusLabel}</span></td>
      </tr>
    </table>
    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
      <tr><td style="padding:0;">{$messageBody}</td></tr>
    </table>
    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
      <tr><td style="border-left:3px solid #e8b200;background:#fffdf0;padding:16px 20px;border-radius:0 4px 4px 0;">
        <a href="https://rush.rcmp.edu.my/" style="display:inline-block;padding:10px 22px;background-color:#00327a;color:#ffffff;font-size:13px;font-weight:600;text-decoration:none;border-radius:4px;">{$btnLabel}</a>
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
        $mail->isSMTP();
        $mail->Host       = 'smtp.office365.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'rush.rcmp@unikl.edu.my';
        $mail->Password   = 'Rcmp@4321';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->SMTPDebug  = 0;
        $mail->Debugoutput = 'error_log';
        $mail->setFrom('rush.rcmp@unikl.edu.my', 'UniKL RCMP Help Desk');
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = "Vendor Application {$statusLabel} — {$companyName}";
        $mail->Body    = $htmlBody;
        $mail->AltBody = "Status: {$statusLabel}\n\nCompany: {$companyName}";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("[UniKL Mail] Vendor decision email failed for {$companyName}: " . $mail->ErrorInfo);
        return false;
    }
}

// ── Generate a random vendor password like "RcmpV1xY9" (8–10 chars) ───────
function generateVendorPassword(): string {
    $prefix = 'RcmpV1';
    $charset = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $suffixLen = random_int(2, 4);
    $suffix = '';
    for ($i = 0; $i < $suffixLen; $i++) {
        $suffix .= $charset[random_int(0, strlen($charset) - 1)];
    }
    return $prefix . $suffix;
}

// ── Send new vendor welcome email with auto-generated password ────────────
function sendVendorWelcomeEmail(string $toEmail, string $toName, string $companyName, string $plainPassword): bool {
    $currentYear = date('Y');
    $currentDate = date('d F Y');
    $escapedTo      = htmlspecialchars($toName);
    $escapedCompany = htmlspecialchars($companyName);
    $escapedEmail   = htmlspecialchars($toEmail);
    $escapedPw      = htmlspecialchars($plainPassword);

    $messageBody = '<p style="margin:0 0 12px;font-size:14px;color:#374151;line-height:1.75;">An account has been created for <strong>' . $escapedCompany . '</strong> to serve the <strong>IT Department</strong> on the UniKL RCMP Help Desk vendor portal. Your login credentials are below.</p>';

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
      <span style="font-size:12px;color:rgba(255,255,255,.65);letter-spacing:.06em;text-transform:uppercase;">&#128274;&nbsp; Vendor Account Created</span>
    </td></tr></table>
  </td></tr>
  <tr><td style="background:#f7f8fa;border-bottom:1px solid #e4e7ed;padding:14px 40px;">
    <table width="100%"><tr>
      <td style="font-size:12px;color:#6b7280;">Company</td>
      <td align="right" style="font-size:13px;font-weight:700;color:#00327a;">{$escapedCompany}</td>
    </tr></table>
  </td></tr>
  <tr><td style="padding:36px 40px 0;">
    <p style="margin:0 0 6px;font-size:12px;color:#9ca3af;">{$currentDate}</p>
    <p style="margin:0 0 20px;font-size:15px;font-weight:600;color:#111827;">Dear {$escapedTo},</p>
    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:20px;">
      <tr><td style="padding:0;">{$messageBody}</td></tr>
    </table>
    <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e4e7ed;border-radius:4px;overflow:hidden;margin-bottom:24px;">
      <tr><td colspan="2" style="background:#00327a;padding:10px 18px;"><span style="font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.85);">Login Credentials</span></td></tr>
      <tr>
        <td style="width:35%;padding:12px 18px;background:#f7f8fa;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;">Email</td>
        <td style="padding:12px 18px;font-size:13px;color:#111827;">{$escapedEmail}</td>
      </tr>
      <tr>
        <td style="width:35%;padding:12px 18px;background:#f7f8fa;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;">Password</td>
        <td style="padding:12px 18px;font-size:13px;font-weight:700;color:#00327a;font-family:monospace;letter-spacing:.04em;">{$escapedPw}</td>
      </tr>
    </table>
    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
      <tr><td style="border-left:3px solid #e8b200;background:#fffdf0;padding:16px 20px;border-radius:0 4px 4px 0;">
        <a href="https://rush.rcmp.edu.my/" style="display:inline-block;padding:10px 22px;background-color:#00327a;color:#ffffff;font-size:13px;font-weight:600;text-decoration:none;border-radius:4px;">Login to Vendor Portal</a>
        <p style="margin:14px 0 0;font-size:12px;color:#92400e;">For security, please change your password after logging in.</p>
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
        $mail->isSMTP();
        $mail->Host       = 'smtp.office365.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'rush.rcmp@unikl.edu.my';
        $mail->Password   = 'Rcmp@4321';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->SMTPDebug  = 0;
        $mail->Debugoutput = 'error_log';
        $mail->setFrom('rush.rcmp@unikl.edu.my', 'UniKL RCMP Help Desk');
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = "Your Vendor Account — {$companyName}";
        $mail->Body    = $htmlBody;
        $mail->AltBody = "Email: {$toEmail}\nPassword: {$plainPassword}";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("[UniKL Mail] Vendor welcome email failed for {$companyName}: " . $mail->ErrorInfo);
        return false;
    }
}

// ── Success messages via redirect ─────────────────────────────────────────
$successMessages = [
    'added'              => 'Vendor added successfully. Login credentials have been emailed to the vendor.',
    'added_email_failed' => 'Vendor added successfully, but the credentials email could not be sent. Please reset their password manually and share it with them directly.',
    'approved'           => 'Vendor approved successfully.',
    'rejected'           => 'Vendor application rejected.',
    'suspended'          => 'Vendor account suspended.',
    'activated'          => 'Vendor account reactivated.',
    'deleted'            => 'Vendor removed successfully.',
    'vendor_updated'     => 'Vendor details updated successfully.',
];
if (!empty($_GET['success']) && isset($successMessages[$_GET['success']])) {
    $msg = $successMessages[$_GET['success']];
}

// ── POST Actions ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = $_POST['action']    ?? '';
    $vendor_id = (int)($_POST['vendor_id'] ?? 0);

    // ADD NEW VENDOR
    if ($action === 'add') {
        $company  = trim($_POST['company_name'] ?? '');
        $address  = trim($_POST['address']      ?? '');
        $city     = trim($_POST['city']         ?? '');
        $state    = trim($_POST['state']        ?? '');
        $postcode = trim($_POST['postcode']     ?? '');
        $email    = trim($_POST['email']        ?? '');
        $phone    = trim($_POST['phone']        ?? '');
        $picName  = trim($_POST['pic_name']     ?? '');
        $picPos   = trim($_POST['pic_position'] ?? '');
        $picPhone = trim($_POST['pic_phone']    ?? '');

        if (!$company || !$email) {
            $error = 'Company name and email are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email address.';
        } elseif ($phone !== '' && !preg_match('/^\d{10,11}$/', $phone)) {
            $error = 'Phone number must be 10 or 11 digits (numbers only).';
        } else {
            $chk = $conn->prepare("SELECT vendor_id FROM vendors WHERE email = ?");
            $chk->bind_param("s", $email);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $error = 'A vendor with this email already exists.';
                $chk->close();
            } else {
                $chk->close();
                $plainPw = generateVendorPassword();
                $hash    = password_hash($plainPw, PASSWORD_BCRYPT);
                $conn->begin_transaction();
                try {
                    $ins = $conn->prepare("
                        INSERT INTO vendors (company_name, address, city, state, postcode, email, phone, password_hash, status, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())
                    ");
                    $ins->bind_param("ssssssss", $company, $address, $city, $state, $postcode, $email, $phone, $hash);
                    $ins->execute();
                    $newVendorId = $conn->insert_id;
                    $ins->close();

                    $lnk = $conn->prepare("
                        INSERT INTO vendor_departments (vendor_id, dept_id, status, reviewed_by, reviewed_at, created_at)
                        VALUES (?, 4, 'active', ?, NOW(), NOW())
                    ");
                    $lnk->bind_param("ii", $newVendorId, $_SESSION['staff_id']);
                    $lnk->execute();
                    $lnk->close();

                    // Save Person In Charge (PIC) if provided
                    if ($picName !== '') {
                        $picIns = $conn->prepare("
                            INSERT INTO vendor_staff (vendor_id, full_name, position, phone, is_primary, created_at)
                            VALUES (?, ?, ?, ?, 1, NOW())
                        ");
                        $picIns->bind_param("isss", $newVendorId, $picName, $picPos, $picPhone);
                        $picIns->execute();
                        $picIns->close();
                    }

                    $conn->commit();

                    $emailSent = sendVendorWelcomeEmail($email, $company, $company, $plainPw);

                    header('Location: vendors.php?success=' . ($emailSent ? 'added' : 'added_email_failed'));
                    exit;
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = 'Failed to add vendor: ' . $e->getMessage();
                }
            }
        }
    }

    // APPROVE: set vendor status → active AND set vendor_departments row for dept 4 → active
    if ($action === 'approve' && $vendor_id) {
        $conn->begin_transaction();
        try {
            $s1 = $conn->prepare("UPDATE vendors SET status = 'active' WHERE vendor_id = ?");
            $s1->bind_param("i", $vendor_id);
            $s1->execute();
            $s1->close();

            // Mark the IT dept link as active, record who reviewed it
            $s2 = $conn->prepare("
                UPDATE vendor_departments
                SET status = 'active', reviewed_by = ?, reviewed_at = NOW()
                WHERE vendor_id = ? AND dept_id = 4
            ");
            $s2->bind_param("ii", $_SESSION['staff_id'], $vendor_id);
            $s2->execute();
            $s2->close();

            $conn->commit();

            // Notify vendor by email (after commit, so DB state is safe either way)
            $vInfo = $conn->prepare("
    SELECT v.company_name, v.email,
           vs.full_name AS pic_name
    FROM vendors v
    LEFT JOIN vendor_staff vs ON vs.vendor_id = v.vendor_id AND vs.is_primary = 1
    WHERE v.vendor_id = ? LIMIT 1
");
$vInfo->bind_param("i", $vendor_id);
$vInfo->execute();
$vRow = $vInfo->get_result()->fetch_assoc();
$vInfo->close();
if ($vRow) {
    // Email always goes to the company email (vendor_staff no longer stores email)
    $toEmail = $vRow['email'];
    $toName  = !empty($vRow['pic_name']) ? $vRow['pic_name'] : $vRow['company_name'];
    sendVendorDecisionEmail($toEmail, $toName, $vRow['company_name'], true);
}

            header('Location: vendors.php?success=approved');
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            $error = 'Approval failed: ' . $e->getMessage();
        }
    }

    // REJECT (only valid while vendor is still pending — removes the application)
    if ($action === 'reject' && $vendor_id) {
        // Grab vendor contact info BEFORE any deletion happens
        $rInfo = $conn->prepare("
    SELECT v.company_name, v.email,
           vs.full_name AS pic_name
    FROM vendors v
    LEFT JOIN vendor_staff vs ON vs.vendor_id = v.vendor_id AND vs.is_primary = 1
    WHERE v.vendor_id = ? LIMIT 1
");
$rInfo->bind_param("i", $vendor_id);
$rInfo->execute();
$rVendorRow = $rInfo->get_result()->fetch_assoc();
$rInfo->close();

        $conn->begin_transaction();
        try {
            $d1 = $conn->prepare("DELETE FROM vendor_departments WHERE vendor_id = ? AND dept_id = 4");
            $d1->bind_param("i", $vendor_id);
            $d1->execute();
            $d1->close();

            // Only fully delete vendor if they serve no other depts
            $chk = $conn->prepare("SELECT COUNT(*) AS cnt FROM vendor_departments WHERE vendor_id = ?");
            $chk->bind_param("i", $vendor_id);
            $chk->execute();
            $remaining = (int)$chk->get_result()->fetch_assoc()['cnt'];
            $chk->close();

            if ($remaining === 0) {
                $d2 = $conn->prepare("DELETE FROM vendors WHERE vendor_id = ?");
                $d2->bind_param("i", $vendor_id);
                $d2->execute();
                $d2->close();
            }

            $conn->commit();

            // Notify vendor by email (vendor row was captured before deletion)
            // $remaining > 0 means the vendor still serves other departments, so only
            // the IT link was rejected — not a full account removal.
            if ($rVendorRow) {
    $toEmail = $rVendorRow['email'];
    $toName  = !empty($rVendorRow['pic_name']) ? $rVendorRow['pic_name'] : $rVendorRow['company_name'];
    sendVendorDecisionEmail($toEmail, $toName, $rVendorRow['company_name'], false, $remaining > 0);
}

            header('Location: vendors.php?success=rejected');
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            $error = 'Rejection failed: ' . $e->getMessage();
        }
    }

    // SUSPEND
    if ($action === 'suspend' && $vendor_id) {
    $s = $conn->prepare("UPDATE vendor_departments SET status = 'suspended' WHERE vendor_id = ? AND dept_id = 4");
    $s->bind_param("i", $vendor_id);
    if ($s->execute()) {
        header('Location: vendors.php?success=suspended');
        exit;
    }
    $error = 'Suspend failed.';
}

    // ACTIVATE (un-suspend)
   if ($action === 'activate' && $vendor_id) {
    $s = $conn->prepare("UPDATE vendor_departments SET status = 'active' WHERE vendor_id = ? AND dept_id = 4");
    $s->bind_param("i", $vendor_id);
    if ($s->execute()) {
        header('Location: vendors.php?success=activated');
        exit;
    }
    $error = 'Activation failed.';
}

    // EDIT VENDOR (company info + PIC + optional password change)
    if ($action === 'edit_vendor' && $vendor_id) {
        $company  = trim($_POST['company_name'] ?? '');
        $address  = trim($_POST['address']      ?? '');
        $city     = trim($_POST['city']         ?? '');
        $state    = trim($_POST['state']        ?? '');
        $postcode = trim($_POST['postcode']     ?? '');
        $email    = trim($_POST['email']        ?? '');
        $phone    = trim($_POST['phone']        ?? '');
        $picName  = trim($_POST['pic_name']     ?? '');
        $picPos   = trim($_POST['pic_position'] ?? '');
        $picPhone = trim($_POST['pic_phone']    ?? '');
        $newPw    = trim($_POST['new_password'] ?? '');

        if (!$company || !$email) {
            $error = 'Company name and email are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email address.';
        } elseif ($phone !== '' && !preg_match('/^\d{10,11}$/', $phone)) {
            $error = 'Company phone number must be 10 or 11 digits.';
        } elseif ($newPw !== '' && (strlen($newPw) < 8 || strlen($newPw) > 10)) {
            $error = 'Password must be between 8 and 10 characters.';
        } else {
            $conn->begin_transaction();
            try {
                if ($newPw !== '') {
                    $hash = password_hash($newPw, PASSWORD_BCRYPT);
                    $s = $conn->prepare("UPDATE vendors SET company_name=?, address=?, city=?, state=?, postcode=?, email=?, phone=?, password_hash=? WHERE vendor_id=?");
                    $s->bind_param("ssssssssi", $company, $address, $city, $state, $postcode, $email, $phone, $hash, $vendor_id);
                } else {
                    $s = $conn->prepare("UPDATE vendors SET company_name=?, address=?, city=?, state=?, postcode=?, email=?, phone=? WHERE vendor_id=?");
                    $s->bind_param("sssssssi", $company, $address, $city, $state, $postcode, $email, $phone, $vendor_id);
                }
                $s->execute();
                $s->close();

                $find = $conn->prepare("SELECT staff_id FROM vendor_staff WHERE vendor_id = ? LIMIT 1");
                $find->bind_param("i", $vendor_id);
                $find->execute();
                $existing = $find->get_result()->fetch_assoc();
                $find->close();

                if ($picName === '') {
                    if ($existing) {
                        $del = $conn->prepare("DELETE FROM vendor_staff WHERE staff_id = ?");
                        $del->bind_param("i", $existing['staff_id']);
                        $del->execute();
                        $del->close();
                    }
                } elseif ($existing) {
                    $upd = $conn->prepare("UPDATE vendor_staff SET full_name=?, position=?, phone=? WHERE staff_id=?");
                    $upd->bind_param("sssi", $picName, $picPos, $picPhone, $existing['staff_id']);
                    $upd->execute();
                    $upd->close();
                } else {
                    $ins = $conn->prepare("INSERT INTO vendor_staff (vendor_id, full_name, position, phone, is_primary, created_at) VALUES (?, ?, ?, ?, 1, NOW())");
                    $ins->bind_param("isss", $vendor_id, $picName, $picPos, $picPhone);
                    $ins->execute();
                    $ins->close();
                }

                $conn->commit();
                header('Location: vendors.php?success=vendor_updated');
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Update failed: ' . $e->getMessage();
            }
        }
    }

    

    // DELETE
    if ($action === 'delete' && $vendor_id) {
        $conn->begin_transaction();
        try {
            $d1 = $conn->prepare("DELETE FROM vendor_departments WHERE vendor_id = ? AND dept_id = 4");
            $d1->bind_param("i", $vendor_id);
            $d1->execute();
            $d1->close();

            // Only fully delete vendor if they serve no other depts
            $chk = $conn->prepare("SELECT COUNT(*) AS cnt FROM vendor_departments WHERE vendor_id = ?");
            $chk->bind_param("i", $vendor_id);
            $chk->execute();
            $remaining = (int)$chk->get_result()->fetch_assoc()['cnt'];
            $chk->close();

            if ($remaining === 0) {
                $d2 = $conn->prepare("DELETE FROM vendors WHERE vendor_id = ?");
                $d2->bind_param("i", $vendor_id);
                $d2->execute();
                $d2->close();
            }

            $conn->commit();
            header('Location: vendors.php?success=deleted');
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            $error = 'Delete failed: ' . $e->getMessage();
        }
    }
}

// ── Fetch vendors linked to IT dept (dept_id = 4) ─────────────────────────
$search  = trim($_GET['q']      ?? '');
$statusF = $_GET['status']      ?? '';

$where  = ["vd.dept_id = 4"];
$params = [];
$types  = '';

if ($search) {
    $where[] = "(v.company_name LIKE ? OR v.email LIKE ? OR EXISTS (
        SELECT 1 FROM vendor_staff vs2 WHERE vs2.vendor_id = v.vendor_id AND vs2.full_name LIKE ?
    ))";
    $l = "%$search%";
    $params = [$l, $l, $l];
    $types  = 'sss';
}
if ($statusF) {
    $where[] = "vd.status = ?";
    $params[] = $statusF;
    $types   .= 's';
}

$sql = "
    SELECT v.vendor_id, v.company_name, v.address, v.city, v.state, v.postcode,
           v.email, v.phone, v.status AS vendor_status, v.created_at,
           vd.status AS dept_status, vd.reviewed_at,
           s.full_name AS reviewed_by_name,
           vs.full_name AS pic_name, vs.position AS pic_position, vs.phone AS pic_phone
    FROM vendors v
    JOIN vendor_departments vd ON vd.vendor_id = v.vendor_id
    LEFT JOIN staff s ON s.staff_id = vd.reviewed_by
    LEFT JOIN vendor_staff vs ON vs.vendor_id = v.vendor_id AND vs.is_primary = 1
    WHERE " . implode(' AND ', $where) . "
    ORDER BY
        CASE vd.status WHEN 'pending' THEN 0 WHEN 'active' THEN 1 ELSE 2 END,
        v.created_at DESC
";

$stmt = $conn->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$vendors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── KPI STATUS COUNTS (always unfiltered, for the cards) ──
$kpiCounts = ['pending' => 0, 'active' => 0, 'suspended' => 0];
$kpiRes = $conn->query("
    SELECT vd.status, COUNT(*) AS n
    FROM vendors v
    JOIN vendor_departments vd ON vd.vendor_id = v.vendor_id
    WHERE vd.dept_id = 4
    GROUP BY vd.status
");
while ($kpiRow = $kpiRes->fetch_assoc()) {
    if (isset($kpiCounts[$kpiRow['status']])) {
        $kpiCounts[$kpiRow['status']] = (int)$kpiRow['n'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>IT Admin — Manage Vendors | UniKL Help Desk</title>
  <?php include '_head_assets.php'; ?>
  <style>
    /* Reuse icon-btn pattern from users.php */
    .icon-btn {
      display: inline-flex; align-items: center; justify-content: center;
      width: 32px; height: 32px; border: 1.5px solid #d1d5db; border-radius: 6px;
      background: #fff; color: #6b7280; cursor: pointer; transition: border-color .15s, color .15s, background .15s;
      padding: 0; flex-shrink: 0;
    }
    .icon-btn svg { width: 15px; height: 15px; stroke: currentColor; fill: none; stroke-width: 1.75; display: block; }
    .icon-btn:hover                { background: #f9fafb; border-color: #9ca3af; color: #374151; }
    .icon-btn.btn-approve:hover    { border-color: #10b981; color: #10b981; background: #ecfdf5; }
    .icon-btn.btn-suspend:hover    { border-color: #f59e0b; color: #d97706; background: #fffbeb; }
    .icon-btn.btn-activate:hover   { border-color: #3b82f6; color: #3b82f6; background: #eff6ff; }
    .icon-btn.btn-delete:hover     { border-color: #ef4444; color: #ef4444; background: #fef2f2; }
    .icon-btn.btn-view:hover       { border-color: #6366f1; color: #6366f1; background: #eef2ff; }
    .icon-btn.btn-reset:hover      { border-color: #f59e0b; color: #d97706; background: #fffbeb; }
    .actions-cell { display: flex; align-items: center; gap: 5px; flex-wrap: nowrap; }
    .actions-cell form { display: inline-flex; }

    /* Status badges for vendor */
    .vstatus-pending   { background: #FFF7ED; color: #C2410C; border: 1px solid rgba(194,65,12,.2); }
    .vstatus-active    { background: #F0FDF4; color: #15803D; border: 1px solid rgba(21,128,61,.2); }
    .vstatus-suspended { background: #FEF2F2; color: #DC2626; border: 1px solid rgba(220,38,38,.2); }

    .data-table tbody tr:hover td {
      background-color: #ede9fe !important;
      transition: background-color 0.15s ease;
    }

    /* ── MOBILE RESPONSIVE ── */
.card.no-pad {
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}

.data-table {
  min-width: 700px;
}

@media (max-width: 900px) {
  .main-content {
    padding: 20px 12px;
  }
  .filter-bar { flex-direction: column; align-items: stretch; }
  .filter-bar select,
  .filter-bar .search-wrap,
  .filter-bar .btn-primary-sm,
  .filter-bar .btn-ghost-sm { width: 100%; }

  .data-table th,
  .data-table td { padding: 8px 8px; font-size: 12px; }
}

@media (max-width: 480px) {
  .main-content {
    padding: 14px 8px;
    max-width: 100vw !important;
    box-sizing: border-box;
    width: 100%;
  }
  .data-table { min-width: 650px; }
  .data-table th,
  .data-table td { padding: 7px 6px; font-size: 11px; }
  .badge { font-size: 10px; padding: 3px 7px; }
}

    /* ── KPI STATUS CARDS (matches tickets.php) ── */
    .ticket-kpi-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 12px;
      margin-bottom: 20px;
    }
    @media (max-width: 900px) {
      .ticket-kpi-grid { grid-template-columns: repeat(2, 1fr); }
    }

    button.ticket-kpi-card {
      appearance: none; -webkit-appearance: none;
      font-family: inherit; text-align: left; cursor: pointer;
    }
    .ticket-kpi-card {
      background: var(--white);
      border: 2px solid var(--gray-200);
      border-radius: 14px;
      padding: 16px 18px;
      display: flex;
      flex-direction: row;
      align-items: center;
      gap: 14px;
      transition: box-shadow .2s, transform .15s, border-color .2s;
      color: inherit;
      position: relative;
      overflow: hidden;
    }
    .ticket-kpi-card::after {
      content: '';
      position: absolute;
      bottom: 0; left: 0; right: 0;
      height: 3px;
      opacity: 0;
      transition: opacity .2s;
    }
    .ticket-kpi-card:hover {
      box-shadow: 0 4px 16px rgba(107,90,158,.15);
      transform: translateY(-2px);
    }
    .ticket-kpi-card.active {
      box-shadow: 0 4px 16px rgba(107,90,158,.18);
      transform: translateY(-2px);
    }
    .ticket-kpi-card.active::after { opacity: 1; background: var(--blue); }
    .ticket-kpi-card.active:has(.tkpi-pending)::after   { background: #C2410C; }
    .ticket-kpi-card.active:has(.tkpi-active)::after    { background: #15803D; }
    .ticket-kpi-card.active:has(.tkpi-suspended)::after { background: #DC2626; }

    .ticket-kpi-icon {
      width: 44px; height: 44px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .ticket-kpi-icon svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 2; }

    .tkpi-all        { background: var(--blue-light); color: var(--blue); }
    .tkpi-pending    { background: #FFF7ED; color: #C2410C; }
    .tkpi-active     { background: #F0FDF4; color: #15803D; }
    .tkpi-suspended  { background: #FEF2F2; color: #DC2626; }

    .ticket-kpi-body { display: flex; flex-direction: column; gap: 2px; }
    .ticket-kpi-val {
      font-size: 26px; font-weight: 800;
      color: var(--gray-900); line-height: 1.1;
      font-variant-numeric: tabular-nums;
    }
    .ticket-kpi-label {
      font-size: 11px; color: var(--gray-500);
      font-weight: 600; letter-spacing: .02em;
    }
  </style>
</head>
<body>
<?php include '_sidebar.php'; ?>

<main class="main-content">

  <!-- Page Header -->
  <div class="page-header">
    <div>
      <div class="page-eyebrow">IT Department</div>
      <h1 class="page-title">
        Manage Vendors
        <span class="title-count"><?= count($vendors) ?></span>
      </h1>
    </div>
    <button class="btn-primary-sm" onclick="openModal('addVendorModal')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round" width="14" height="14">
        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
      </svg>
      Add Vendor
    </button>
  </div>

  <!-- Alerts -->
  <?php if ($msg): ?>
  <div class="alert alert-success" id="alertSuccess"><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
  <div class="alert alert-error" id="alertError"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <!-- ── KPI STATUS CARDS ── -->
  <div class="ticket-kpi-grid">

    <button type="button" class="ticket-kpi-card <?= $statusF===''?'active':'' ?>" onclick="filterByStatus('')">
      <div class="ticket-kpi-icon tkpi-all">
        <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      </div>
      <div class="ticket-kpi-body">
        <div class="ticket-kpi-val"><?= array_sum($kpiCounts) ?></div>
        <div class="ticket-kpi-label">All</div>
      </div>
    </button>

    <button type="button" class="ticket-kpi-card <?= $statusF==='pending'?'active':'' ?>" onclick="filterByStatus('pending')">
      <div class="ticket-kpi-icon tkpi-pending">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      </div>
      <div class="ticket-kpi-body">
        <div class="ticket-kpi-val"><?= $kpiCounts['pending'] ?></div>
        <div class="ticket-kpi-label">Pending Approval</div>
      </div>
    </button>

    <button type="button" class="ticket-kpi-card <?= $statusF==='active'?'active':'' ?>" onclick="filterByStatus('active')">
      <div class="ticket-kpi-icon tkpi-active">
        <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
      </div>
      <div class="ticket-kpi-body">
        <div class="ticket-kpi-val"><?= $kpiCounts['active'] ?></div>
        <div class="ticket-kpi-label">Active Vendors</div>
      </div>
    </button>

    <button type="button" class="ticket-kpi-card <?= $statusF==='suspended'?'active':'' ?>" onclick="filterByStatus('suspended')">
      <div class="ticket-kpi-icon tkpi-suspended">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
      </div>
      <div class="ticket-kpi-body">
        <div class="ticket-kpi-val"><?= $kpiCounts['suspended'] ?></div>
        <div class="ticket-kpi-label">Suspended</div>
      </div>
    </button>

  </div>

  <!-- Filter Bar -->
  <form method="GET" class="filter-bar">
    <div class="search-wrap">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
      </svg>
      <input type="text" name="q" placeholder="Search company, PIC, email…"
             value="<?= htmlspecialchars($search) ?>"/>
    </div>
    <select name="status">
      <option value="">All Status</option>
      <option value="pending"   <?= $statusF==='pending'   ?'selected':'' ?>>Pending</option>
      <option value="active"    <?= $statusF==='active'    ?'selected':'' ?>>Active</option>
      <option value="suspended" <?= $statusF==='suspended' ?'selected':'' ?>>Suspended</option>
    </select>
    <button type="submit" class="btn-primary-sm">Search</button>
    <?php if ($search || $statusF): ?>
    <a href="vendors.php" class="btn-ghost-sm">Clear</a>
    <?php endif; ?>
  </form>

  <!-- Table -->
  <div class="card no-pad" style="touch-action: pan-x pan-y; -webkit-overflow-scrolling: touch;">
    <table class="data-table full">
      <thead>
        <tr>
          <th>Company</th>
<th>Company Email</th>
<th>Phone</th>
          <th>Status</th>
          <th>Registered</th>
          <th>Reviewed By</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($vendors)): ?>
        <tr><td colspan="7" class="empty-row">No vendors found.</td></tr>
        <?php else: foreach ($vendors as $v): ?>
        <tr>
          <td>
            <div class="user-cell">
              <div class="user-avatar staff" style="background:#f3e8ff;color:#7c3aed;">
                <?= strtoupper(substr($v['company_name'], 0, 1)) ?>
              </div>
              <div class="user-name"><?= htmlspecialchars($v['company_name']) ?></div>
            </div>
          </td>
          
<td class="td-email"><?= htmlspecialchars($v['email']) ?></td>
<td><?= htmlspecialchars($v['phone'] ?? '—') ?></td>
          <td>
            <span class="badge <?= 'vstatus-' . $v['dept_status'] ?>">
  <?= ucfirst($v['dept_status']) ?>
</span>
          </td>
          <td class="td-date"><?= date('d M Y', strtotime($v['created_at'])) ?></td>
          <td class="td-date">
            <?php if ($v['reviewed_by_name']): ?>
              <?= htmlspecialchars($v['reviewed_by_name']) ?>
              <?php if ($v['reviewed_at']): ?>
              <div style="font-size:11px;color:var(--gray-500);">
                <?= date('d M Y', strtotime($v['reviewed_at'])) ?>
              </div>
              <?php endif; ?>
            <?php else: ?>
              <span style="color:var(--gray-500);">—</span>
            <?php endif; ?>
          </td>
          <td class="actions-cell">

            <?php if ($v['dept_status'] === 'pending'): ?>

              <!-- PENDING: only Approve + Reject -->
              <form method="POST">
                <input type="hidden" name="action"    value="approve"/>
                <input type="hidden" name="vendor_id" value="<?= $v['vendor_id'] ?>"/>
                <button type="submit" class="icon-btn btn-approve" title="Approve Vendor">
                  <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                </button>
              </form>

              <button type="button" class="icon-btn btn-delete" title="Reject Vendor"
                      onclick="confirmReject(<?= $v['vendor_id'] ?>, '<?= htmlspecialchars(addslashes($v['company_name'])) ?>')">
                <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
              </button>

            <?php else: ?>

              <!-- View Details -->
              <button type="button" class="icon-btn btn-view" title="View Details"
                      onclick="openViewModal(
  <?= $v['vendor_id'] ?>,
  '<?= htmlspecialchars(addslashes($v['company_name'])) ?>',
  '<?= htmlspecialchars(addslashes($v['email'])) ?>',
  '<?= htmlspecialchars(addslashes($v['phone'] ?? '')) ?>',
  '<?= htmlspecialchars(addslashes(implode(", ", array_filter([$v['address'] ?? '', $v['city'] ?? '', $v['state'] ?? '', $v['postcode'] ?? ''])))) ?>',
  '<?= $v['dept_status'] ?>',
  '<?= date('d M Y, g:i A', strtotime($v['created_at'])) ?>'
)">
                <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>

              <!-- Edit Vendor -->
              <button type="button" class="icon-btn btn-reset" title="Edit Vendor"
                      onclick="openEditModal(
  <?= $v['vendor_id'] ?>,
  '<?= htmlspecialchars(addslashes($v['company_name'])) ?>',
  '<?= htmlspecialchars(addslashes($v['email'])) ?>',
  '<?= htmlspecialchars(addslashes($v['phone'] ?? '')) ?>',
  '<?= htmlspecialchars(addslashes($v['address'] ?? '')) ?>',
  '<?= htmlspecialchars(addslashes($v['city'] ?? '')) ?>',
  '<?= htmlspecialchars(addslashes($v['state'] ?? '')) ?>',
  '<?= htmlspecialchars(addslashes($v['postcode'] ?? '')) ?>'
)">
                <svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              </button>

              <!-- Suspend (only if active) -->
              <?php if ($v['dept_status'] === 'active'): ?>
              <button type="button" class="icon-btn btn-suspend" title="Suspend Vendor"
                      onclick="confirmSuspend(<?= $v['vendor_id'] ?>, '<?= htmlspecialchars(addslashes($v['company_name'])) ?>')">
                <svg viewBox="0 0 24 24"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>
              </button>
              <?php endif; ?>

              <!-- Reactivate (only if suspended) -->
              <?php if ($v['dept_status'] === 'suspended'): ?>
              <form method="POST">
                <input type="hidden" name="action"    value="activate"/>
                <input type="hidden" name="vendor_id" value="<?= $v['vendor_id'] ?>"/>
                <button type="submit" class="icon-btn btn-activate" title="Reactivate Vendor">
                  <svg viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                </button>
              </form>
              <?php endif; ?>

              <!-- Delete -->
              <button type="button" class="icon-btn btn-delete" title="Remove Vendor"
                      onclick="confirmDelete(<?= $v['vendor_id'] ?>, '<?= htmlspecialchars(addslashes($v['company_name'])) ?>')">
                <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
              </button>

            <?php endif; ?>

          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

</main>

<!-- ── Add Vendor Modal ──────────────────────────────────────────────────── -->
<div class="modal-overlay" id="addVendorModal">
  <div class="modal" style="max-width:560px;">
    <div class="modal-header">
      <h3>Add New Vendor</h3>
      <button class="modal-close" onclick="closeModal('addVendorModal')">✕</button>
    </div>
    <form method="POST" class="modal-form" id="addVendorForm" onsubmit="return validateAddVendorForm()" autocomplete="off">
      <input type="hidden" name="action" value="add"/>

      <div class="field">
        <label>Company Name <span class="req">*</span></label>
        <input type="text" name="company_name" placeholder="e.g. TM One Sdn Bhd" required/>
      </div>

      <div class="field">
        <label>Address</label>
        <input type="text" name="address" placeholder="Street address"/>
      </div>

      <div class="form-grid-2">
        <div class="field">
          <label>City</label>
          <input type="text" name="city" placeholder="e.g. Ipoh"/>
        </div>
        <div class="field">
          <label>State</label>
          <input type="text" name="state" placeholder="e.g. Perak"/>
        </div>
      </div>

      <div class="form-grid-2">
        <div class="field">
          <label>Postcode</label>
          <input type="text" name="postcode" placeholder="e.g. 50480" inputmode="numeric" maxlength="5"/>
        </div>
        <div class="field">
          <label>Phone</label>
          <input type="text" name="phone" id="addVendorPhone"
                 placeholder="e.g. 0187001007" inputmode="numeric" maxlength="11"
                 pattern="\d{10,11}" title="10 or 11 digits only"/>
        </div>
      </div>

      <div class="field">
        <label>Email <span class="req">*</span></label>
        <input type="email" name="email" placeholder="vendor@company.com" required autocomplete="new-password"/>
      </div>

      <div class="form-grid-2">
        <div class="field">
          <label>Person In Charge (Name)</label>
          <input type="text" name="pic_name" placeholder="Full name"/>
        </div>
        <div class="field">
          <label>Position</label>
          <input type="text" name="pic_position" placeholder="e.g. Account Manager"/>
        </div>
      </div>

      <div class="field">
        <label>PIC Phone Number</label>
        <input type="text" name="pic_phone" id="addVendorPicPhone"
               placeholder="e.g. 0123456789" inputmode="numeric" maxlength="11"/>
      </div>


      <div class="field">
        <p style="margin:0;font-size:.8rem;color:#6b7280;background:#f7f8fa;border:1px solid #e4e7ed;border-radius:6px;padding:10px 12px;">
          A password will be generated automatically and emailed to the vendor's address above.
        </p>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn-ghost-sm" onclick="closeModal('addVendorModal')">Cancel</button>
        <button type="submit" class="btn-primary-sm">Add Vendor</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Edit Vendor Modal ─────────────────────────────────────────────────── -->
<div class="modal-overlay" id="editModal">
  <div class="modal" style="max-width:560px;">
    <div class="modal-header">
      <h3>Edit Vendor — <span id="editName"></span></h3>
      <button class="modal-close" onclick="closeModal('editModal')">✕</button>
    </div>
    <form method="POST" class="modal-form" id="editForm" autocomplete="off">
      <input type="hidden" name="action" value="edit_vendor"/>
      <input type="hidden" name="vendor_id" id="editVendorId"/>

      <div class="field">
        <label>Company Name <span class="req">*</span></label>
        <input type="text" name="company_name" id="editCompanyName" required/>
      </div>
      <div class="field">
        <label>Address</label>
        <input type="text" name="address" id="editAddress"/>
      </div>
      <div class="form-grid-2">
        <div class="field"><label>City</label><input type="text" name="city" id="editCity"/></div>
        <div class="field"><label>State</label><input type="text" name="state" id="editState"/></div>
      </div>
      <div class="form-grid-2">
        <div class="field"><label>Postcode</label><input type="text" name="postcode" id="editPostcode" inputmode="numeric" maxlength="5"/></div>
        <div class="field"><label>Company Phone</label><input type="text" name="phone" id="editPhone" inputmode="numeric" maxlength="11" pattern="\d{10,11}" title="10 or 11 digits only"/></div>
      </div>
      <div class="field">
        <label>Email <span class="req">*</span></label>
        <input type="email" name="email" id="editEmail" required/>
      </div>
      <div class="form-grid-2">
        <div class="field"><label>PIC Name</label><input type="text" name="pic_name" id="editPicName"/></div>
        <div class="field"><label>PIC Position</label><input type="text" name="pic_position" id="editPicPosition"/></div>
      </div>
      <div class="field">
        <label>PIC Phone</label>
        <input type="text" name="pic_phone" id="editPicPhone" inputmode="numeric" maxlength="11"/>
      </div>

      <div class="field">
        <label>New Password (optional)</label>
        <div style="position:relative;">
          <input type="password" name="new_password" id="editPasswordInput"
                 placeholder="Leave blank to keep current password" minlength="8" maxlength="10"
                 style="width:100%; padding-right:42px;"/>
          <button type="button" id="editPwToggle"
                  style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#9ca3af;">
            <svg id="editEyeIcon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
            </svg>
          </button>
        </div>
        <small style="color:#9ca3af;font-size:0.75rem;">If set, must be 8–10 characters.</small>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn-ghost-sm" onclick="closeModal('editModal')">Cancel</button>
        <button type="submit" class="btn-primary-sm">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ── View Vendor Modal ──────────────────────────────────────────────────── -->
<div class="modal-overlay" id="viewModal">
  <div class="modal" style="max-width:700px;">
    <div class="modal-header">
      <h3>Vendor Details</h3>
      <button class="modal-close" onclick="closeModal('viewModal')">✕</button>
    </div>
    <div class="modal-form" style="display:grid;gap:14px;">
  <!-- Company header -->
  <div style="display:flex;align-items:center;gap:14px;padding-bottom:10px;border-bottom:1px solid #f1f5f9;">
    <div id="vAvatar" style="width:48px;height:48px;border-radius:50%;display:flex;align-items:center;
         justify-content:center;font-weight:700;font-size:1.2rem;background:#f3e8ff;color:#7c3aed;flex-shrink:0;"></div>
    <div>
      <div id="vCompany" style="font-weight:600;font-size:1rem;"></div>
      <div id="vStatus" style="font-size:0.78rem;margin-top:3px;"></div>
    </div>
  </div>
  <!-- Company details -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
    <div style="grid-column:1/-1;">
      <div style="font-size:.72rem;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Address</div>
      <div id="vAddress" style="font-size:.9rem;margin-top:2px;"></div>
    </div>
    <div>
      <div style="font-size:.72rem;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Company Email</div>
      <div id="vEmail" style="font-size:.9rem;margin-top:2px;"></div>
    </div>
    <div>
      <div style="font-size:.72rem;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Company Phone</div>
      <div id="vPhone" style="font-size:.9rem;margin-top:2px;"></div>
    </div>
    <div>
      <div style="font-size:.72rem;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Registered</div>
      <div id="vCreated" style="font-size:.9rem;margin-top:2px;"></div>
    </div>
  </div>

  <!-- Person In Charge (PIC) — view only -->
  <div style="border-top:1px solid #f1f5f9;padding-top:14px;">
    <div style="font-size:.85rem;font-weight:600;color:#111827;margin-bottom:10px;">Person In Charge</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
      <div>
        <div style="font-size:.72rem;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Name</div>
        <div id="vPicName" style="font-size:.9rem;margin-top:2px;">—</div>
      </div>
      <div>
        <div style="font-size:.72rem;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Position</div>
        <div id="vPicPosition" style="font-size:.9rem;margin-top:2px;">—</div>
      </div>
      <div>
        <div style="font-size:.72rem;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Phone</div>
        <div id="vPicPhone" style="font-size:.9rem;margin-top:2px;">—</div>
      </div>
    </div>
  </div>

  <div class="modal-footer" style="padding-top:8px;">
    <button type="button" class="btn-ghost-sm" onclick="closeModal('viewModal')">Close</button>
  </div>
</div>
  </div>
</div>

<!-- ── Suspend Confirmation Modal ─────────────────────────────────────────── -->
<div class="modal-overlay" id="suspendModal">
  <div class="modal" style="max-width:420px;border:2px solid #fde68a;border-radius:14px;overflow:hidden;">
    <div class="modal-header" style="border-bottom:2px solid #fde68a;background:linear-gradient(135deg,#fffbeb,#fef9e7);">
      <h3 style="color:#92400e;display:flex;align-items:center;gap:8px;">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#d97706" stroke-width="2">
          <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
          <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
        </svg>
        Suspend Vendor
      </h3>
      <button class="modal-close" onclick="closeModal('suspendModal')">✕</button>
    </div>
    <div class="modal-form">
      <p style="margin:0 0 10px;font-size:.9rem;color:#374151;">
        Suspend <strong id="suspendName" style="color:#d97706;"></strong>?
        They will not be able to log in until reactivated.
      </p>
      <div style="background:#fef9ec;border:1px solid #fde68a;border-radius:8px;
                  padding:10px 14px;font-size:.8rem;color:#92400e;">
        You can reactivate this vendor at any time.
      </div>
    </div>
    <div class="modal-footer" style="padding:16px 24px 24px;">
      <button type="button" class="btn-ghost-sm" onclick="closeModal('suspendModal')">Cancel</button>
      <button type="button" id="suspendConfirmBtn"
              style="background:#d97706;color:#fff;border:2px solid #b45309;padding:7px 20px;border-radius:8px;
                     font-size:.82rem;font-weight:600;cursor:pointer;box-shadow:0 2px 6px rgba(217,119,6,.35);
                     transition:background .15s,transform .1s;"
              onmouseenter="this.style.background='#b45309';this.style.transform='translateY(-1px)'"
              onmouseleave="this.style.background='#d97706';this.style.transform='translateY(0)'">
        Yes, Suspend
      </button>
    </div>
  </div>
</div>

<!-- ── Delete Confirmation Modal ──────────────────────────────────────────── -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal" style="max-width:420px;border:2px solid #fecaca;border-radius:14px;overflow:visible;">
    <div class="modal-header" style="border-bottom:2px solid #fecaca;background:linear-gradient(135deg,#fef2f2,#fff5f5);border-radius:14px 14px 0 0;">
      <h3 style="color:#991b1b;display:flex;align-items:center;gap:8px;">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#ef4444" stroke-width="2">
          <polyline points="3 6 5 6 21 6"/>
          <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
          <path d="M10 11v6"/><path d="M14 11v6"/>
          <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
        </svg>
        Remove Vendor
      </h3>
      <button class="modal-close" onclick="closeModal('deleteModal')">✕</button>
    </div>
    <div class="modal-form">
      <p style="margin:0 0 10px;font-size:.9rem;color:#374151;">
        Remove <strong id="deleteModalName" style="color:#ef4444;"></strong> from the IT department?
      </p>
      <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;
                  padding:10px 14px;font-size:.8rem;color:#991b1b;">
        If this vendor serves only IT, their account will be fully deleted.
        If they serve other departments, only the IT link is removed.
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn-ghost-sm" onclick="closeModal('deleteModal')">Cancel</button>
      <button type="button" id="deleteConfirmBtn"
              style="background:#ef4444;color:#fff;border:2px solid #dc2626;padding:7px 20px;border-radius:8px;
                     font-size:.82rem;font-weight:600;cursor:pointer;box-shadow:0 2px 6px rgba(239,68,68,.35);
                     transition:background .15s,transform .1s;"
              onmouseenter="this.style.background='#dc2626';this.style.transform='translateY(-1px)'"
              onmouseleave="this.style.background='#ef4444';this.style.transform='translateY(0)'">
        Yes, Remove
      </button>
    </div>
  </div>
</div>

<!-- ── Reject Confirmation Modal ──────────────────────────────────────────── -->
<div class="modal-overlay" id="rejectModal">
  <div class="modal" style="max-width:420px;border:2px solid #fecaca;border-radius:14px;overflow:hidden;">
    <div class="modal-header" style="border-bottom:2px solid #fecaca;background:linear-gradient(135deg,#fef2f2,#fff5f5);">
      <h3 style="color:#991b1b;display:flex;align-items:center;gap:8px;">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#ef4444" stroke-width="2">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
        Reject Vendor Application
      </h3>
      <button class="modal-close" onclick="closeModal('rejectModal')">✕</button>
    </div>
    <div class="modal-form">
      <p style="margin:0 0 10px;font-size:.9rem;color:#374151;">
        Reject the application from <strong id="rejectModalName" style="color:#ef4444;"></strong>?
      </p>
      <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;
                  padding:10px 14px;font-size:.8rem;color:#991b1b;">
        This will remove the pending application. If this vendor serves only IT, their account will be fully deleted.
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn-ghost-sm" onclick="closeModal('rejectModal')">Cancel</button>
      <button type="button" id="rejectConfirmBtn"
              style="background:#ef4444;color:#fff;border:2px solid #dc2626;padding:7px 20px;border-radius:8px;
                     font-size:.82rem;font-weight:600;cursor:pointer;box-shadow:0 2px 6px rgba(239,68,68,.35);
                     transition:background .15s,transform .1s;"
              onmouseenter="this.style.background='#dc2626';this.style.transform='translateY(-1px)'"
              onmouseleave="this.style.background='#ef4444';this.style.transform='translateY(0)'">
        Yes, Reject
      </button>
    </div>
  </div>
</div>

<!-- Hidden forms for actions that need confirmation -->
<form method="POST" id="suspendForm" style="display:none;">
  <input type="hidden" name="action"    value="suspend"/>
  <input type="hidden" name="vendor_id" id="suspendVendorId"/>
</form>
<form method="POST" id="deleteForm" style="display:none;">
  <input type="hidden" name="action"    value="delete"/>
  <input type="hidden" name="vendor_id" id="deleteVendorId"/>
</form>
<form method="POST" id="rejectForm" style="display:none;">
  <input type="hidden" name="action"    value="reject"/>
  <input type="hidden" name="vendor_id" id="rejectVendorId"/>
</form>


<?php include '_foot_scripts.php'; ?>
<script>
// Eye icon SVG strings
const eyeOpen = `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>`;
const eyeShut = `<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19M1 1l22 22"/>`;

// Edit Vendor modal
function openEditModal(id, company, email, phone, address, city, state, postcode) {
  document.getElementById('editVendorId').value      = id;
  document.getElementById('editName').textContent    = company;
  document.getElementById('editCompanyName').value   = company;
  document.getElementById('editEmail').value         = email;
  document.getElementById('editPhone').value         = phone;
  document.getElementById('editAddress').value       = address;
  document.getElementById('editCity').value          = city;
  document.getElementById('editState').value         = state;
  document.getElementById('editPostcode').value      = postcode;
  document.getElementById('editPasswordInput').value = '';
  document.getElementById('editPasswordInput').type  = 'password';
  document.getElementById('editEyeIcon').innerHTML   = eyeOpen;
  document.getElementById('editPicName').value       = '';
  document.getElementById('editPicPosition').value   = '';
  document.getElementById('editPicPhone').value      = '';

  fetch('vendors_staff_ajax.php?vendor_id=' + encodeURIComponent(id))
    .then(r => r.json())
    .then(rows => {
      if (rows.length) {
        document.getElementById('editPicName').value     = rows[0].full_name || '';
        document.getElementById('editPicPosition').value = rows[0].position || '';
        document.getElementById('editPicPhone').value    = rows[0].phone || '';
      }
    })
    .catch(() => {});

  openModal('editModal');
}

document.getElementById('editPwToggle').addEventListener('click', function () {
  const input = document.getElementById('editPasswordInput');
  const isPw  = input.type === 'password';
  input.type  = isPw ? 'text' : 'password';
  document.getElementById('editEyeIcon').innerHTML = isPw ? eyeShut : eyeOpen;
});

document.getElementById('editForm').addEventListener('submit', function (e) {
  const pw = document.getElementById('editPasswordInput').value;
  if (pw !== '' && (pw.length < 8 || pw.length > 10)) {
    e.preventDefault();
    alert('Password must be between 8 and 10 characters.');
    document.getElementById('editPasswordInput').focus();
  }
});

/* ── KPI CARD FILTER — reload with status filter ── */
function filterByStatus(status) {
  const url = new URL(window.location.href);
  if (status === '') {
    url.searchParams.delete('status');
  } else {
    url.searchParams.set('status', status);
  }
  window.location.href = url.toString();
}

function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

// Close on backdrop click
document.querySelectorAll('.modal-overlay').forEach(el => {
  el.addEventListener('click', e => { if (e.target === el) el.classList.remove('open'); });
});

// View modal
let currentViewVendorId = null;

function openViewModal(vendorId, company, email, phone, address, status, created) {
  currentViewVendorId = vendorId;
  document.getElementById('vAvatar').textContent  = company.charAt(0).toUpperCase();
  document.getElementById('vCompany').textContent = company;
  document.getElementById('vEmail').textContent   = email || '—';
  document.getElementById('vPhone').textContent   = phone || '—';
  document.getElementById('vAddress').textContent = address || '—';
  document.getElementById('vCreated').textContent = created;

  const statusMap = {
    pending:   { label: 'Pending Approval', cls: 'vstatus-pending'   },
    active:    { label: 'Active',            cls: 'vstatus-active'    },
    suspended: { label: 'Suspended',         cls: 'vstatus-suspended' },
  };
  const s = statusMap[status] || { label: status, cls: '' };
  document.getElementById('vStatus').innerHTML =
    `<span class="badge ${s.cls}">${s.label}</span>`;

  loadPic(vendorId);

  openModal('viewModal');
}

function loadPic(vendorId) {
  document.getElementById('vPicName').textContent     = '—';
  document.getElementById('vPicPosition').textContent = '—';
  document.getElementById('vPicPhone').textContent     = '—';

  fetch('vendors_staff_ajax.php?vendor_id=' + encodeURIComponent(vendorId))
    .then(r => r.json())
    .then(rows => {
      if (rows.length) {
        document.getElementById('vPicName').textContent     = rows[0].full_name || '—';
        document.getElementById('vPicPosition').textContent = rows[0].position || '—';
        document.getElementById('vPicPhone').textContent    = rows[0].phone || '—';
      }
    })
    .catch(() => {});
}

// Suspend confirm
function confirmSuspend(id, name) {
  document.getElementById('suspendName').textContent    = name;
  document.getElementById('suspendVendorId').value      = id;
  openModal('suspendModal');
}
document.getElementById('suspendConfirmBtn').addEventListener('click', function () {
  closeModal('suspendModal');
  document.getElementById('suspendForm').submit();
});

// Delete confirm
function confirmDelete(id, name) {
  document.getElementById('deleteModalName').textContent = name;
  document.getElementById('deleteVendorId').value        = id;
  openModal('deleteModal');
}
document.getElementById('deleteConfirmBtn').addEventListener('click', function () {
  closeModal('deleteModal');
  document.getElementById('deleteForm').submit();
});

// Reject confirm
function confirmReject(id, name) {
  document.getElementById('rejectModalName').textContent = name;
  document.getElementById('rejectVendorId').value        = id;
  openModal('rejectModal');
}
document.getElementById('rejectConfirmBtn').addEventListener('click', function () {
  closeModal('rejectModal');
  document.getElementById('rejectForm').submit();
});

// Auto-dismiss alerts
['alertSuccess', 'alertError'].forEach(function (id) {
  const el = document.getElementById(id);
  if (!el) return;
  setTimeout(function () {
    el.style.transition = 'opacity 0.6s ease';
    el.style.opacity    = '0';
    setTimeout(function () { el.style.display = 'none'; }, 600);
  }, 4000);
});

// ── Add Vendor Modal ─────────────────────────────────────────────────────
document.getElementById('addVendorPhone').addEventListener('input', function () {
  this.value = this.value.replace(/\D/g, '').slice(0, 11);
});

// Clear form when modal opens
const _origOpenModal = window.openModal;
window.openModal = function(id) {
  if (id === 'addVendorModal') {
    document.getElementById('addVendorForm').reset();
  }
  document.getElementById(id).classList.add('open');
};

function validateAddVendorForm() {
  return true;
}

</script>
</body>
</html>