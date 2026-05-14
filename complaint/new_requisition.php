<?php
// complaint/new_requisition.php
session_start();

$allowedRoles = ['student', 'lecturer', 'dept_handler', 'admin', 'super_admin', 'report_viewer', 'staff'];
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], $allowedRoles)) {
    header('Location: ../login.php');
    exit;
}

require '../db_connect.php';
require '../mail_helper.php';

// ── SESSION DATA ──────────────────────────────────────────────────────────────
$userName      = htmlspecialchars($_SESSION['user_name']  ?? 'User');
$userEmail     = htmlspecialchars($_SESSION['user_email'] ?? '');
$userRole      = $_SESSION['user_role'];
$userId        = (int)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? 0);
$submitterType = ($userRole === 'student') ? 'student' : 'staff';

// ── WORKING HOURS CHECK ───────────────────────────────────────────────────────
$myt            = new DateTimeZone('Asia/Kuala_Lumpur');
$now            = new DateTime('now', $myt);
$currentHour    = (int)$now->format('H');
$currentDow     = (int)$now->format('N');
$isWeekday      = ($currentDow >= 1 && $currentDow <= 5);
$isWithinTime   = ($currentHour >= 8 && $currentHour < 17);
$isWorkingHours = ($isWeekday && $isWithinTime);

// Next available time calculation
function calcNextOpen(DateTime $from): DateTime {
    $dt   = clone $from;
    $hour = (int)$dt->format('H');
    $dow  = (int)$dt->format('N');
    $isWeekday = ($dow >= 1 && $dow <= 5);
    $inHours   = ($hour >= 8 && $hour < 17);
    if ($isWeekday && $inHours) return $dt;
    if ($isWeekday && $hour >= 17) {
        $add = ($dow == 5) ? 3 : 1;
    } elseif ($dow == 6) { $add = 2; }
    elseif ($dow == 7)   { $add = 1; }
    else                 { $add = 0; }
    if ($add > 0) $dt->modify("+{$add} days");
    $dt->setTime(8, 0, 0);
    return $dt;
}
$nextOpen    = calcNextOpen($now);
$nextDisplay = $nextOpen->format('l, d M Y \a\t g:ia');

// ── EQUIPMENT CATEGORIES ──────────────────────────────────────────────────────
$equipmentCategories = [
    'Office Furniture'  => [
        'description' => 'Chairs, desks, cabinets, shelving units, tables',
        'icon'        => '<path d="M20 7H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/><line x1="8" y1="17" x2="8" y2="21"/><line x1="16" y1="17" x2="16" y2="21"/>',
    ],
    'Water Dispenser'   => [
        'description' => 'Hot & cold dispensers, replacement units, servicing',
        'icon'        => '<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/>',
    ],
    'Signage'           => [
        'description' => 'Room signs, directional boards, notice boards, banners',
        'icon'        => '<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/>',
    ],
    'Vending Machine'   => [
        'description' => 'Snack, beverage or combo vending machine placement',
        'icon'        => '<rect x="5" y="2" width="14" height="20" rx="2"/><line x1="5" y1="10" x2="19" y2="10"/><line x1="5" y1="14" x2="19" y2="14"/><circle cx="12" cy="17" r="1"/>',
    ],
    'Office Keys'       => [
        'description' => 'Room keys, master keys, key duplicates, access cards',
        'icon'        => '<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>',
    ],
    'Office Equipment'  => [
        'description' => 'Printers, scanners, projectors, whiteboards, phones',
        'icon'        => '<rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>',
    ],
];

// ── MY DEPARTMENTS ────────────────────────────────────────────────────────────
$myDepartments = [];
$deptRes = $conn->query("SELECT name FROM my_departments ORDER BY sort_order ASC");
if ($deptRes) {
    while ($r = $deptRes->fetch_assoc()) $myDepartments[] = $r['name'];
}
if (empty($myDepartments)) {
    $myDepartments = [
        'Faculty of Pharmacy & Health Sciences',
        'Faculty of Medicine',
        'Student Development & Campus Lifestyle',
        'International Industrial & Institutional Partnership (IIIP)',
        'Corporate Services Division',
    ];
}

// ── HANDLE FORM SUBMISSION ────────────────────────────────────────────────────
$success   = false;
$error     = '';
$refNumber = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isWorkingHours) {
        $error = 'Requests can only be submitted during working hours (Monday – Friday, 8:00 AM – 5:00 PM MYT).';
    } else {
        $category      = trim($_POST['category']      ?? '');
        $item_name     = trim($_POST['item_name']     ?? '');
        $quantity      = (int)($_POST['quantity']     ?? 0);
        $my_department = trim($_POST['my_department'] ?? '');
        $location      = trim($_POST['location']      ?? '');
        $reason        = trim($_POST['reason']        ?? '');
        $phone         = trim($_POST['phone']         ?? '');
        $urgency       = trim($_POST['urgency']       ?? 'normal');

        $allowed_urgency    = ['normal', 'urgent', 'critical'];
        $allowed_categories = array_keys($equipmentCategories);

        if (empty($category) || !in_array($category, $allowed_categories)) {
            $error = 'Please select a valid equipment category.';
        } elseif (empty($item_name)) {
            $error = 'Please describe the item you are requesting.';
        } elseif ($quantity < 1 || $quantity > 999) {
            $error = 'Quantity must be between 1 and 999.';
        } elseif (empty($my_department)) {
            $error = 'Please select your department or faculty.';
        } elseif (empty($location)) {
            $error = 'Please specify the delivery / installation location.';
        } elseif (empty($reason)) {
            $error = 'Please provide a justification for this request.';
        } elseif (empty($phone)) {
            $error = 'Please enter your phone number.';
        } elseif (!in_array($urgency, $allowed_urgency)) {
            $error = 'Invalid urgency level.';
        } else {
            // Generate reference number: REQ-AFSMD-DDMMYYYY-NNNNN
            $dateStr = date('dmY');
            $seqRes  = $conn->query("SELECT COUNT(*) AS cnt FROM requisitions WHERE DATE(created_at) = CURDATE()");
            $seqRow  = $seqRes ? $seqRes->fetch_assoc() : ['cnt' => 0];
            $seq     = ($seqRow['cnt'] ?? 0) + 1;
            $refNumber = 'REQ-AFSMD-' . $dateStr . '-' . str_pad($seq, 5, '0', STR_PAD_LEFT);

            // Handle file attachment
            $attachmentPath = null;
            if (!empty($_FILES['attachment']['name'])) {
                $uploadDir = '../uploads/requisitions/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $ext     = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg','jpeg','png','gif','pdf','doc','docx','txt'];
                $maxSize = 5 * 1024 * 1024;
                if (!in_array($ext, $allowed)) {
                    $error = 'Invalid file type. Allowed: JPG, PNG, PDF, DOC, DOCX, TXT.';
                } elseif ($_FILES['attachment']['size'] > $maxSize) {
                    $error = 'File too large. Maximum size is 5 MB.';
                } else {
                    $filename       = $refNumber . '_' . time() . '.' . $ext;
                    $attachmentPath = 'uploads/requisitions/' . $filename;
                    move_uploaded_file($_FILES['attachment']['tmp_name'], $uploadDir . $filename);
                }
            }

            if (empty($error)) {
                $stmt = $conn->prepare("
                    INSERT INTO requisitions
                        (ref_number, submitter_id, submitter_type, phone,
                         my_department, category, item_name, quantity,
                         location, reason, urgency, attachment_path,
                         status, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())
                ");
                $stmt->bind_param(
                    "sisssssissss",
                    $refNumber, $userId, $submitterType, $phone,
                    $my_department, $category, $item_name, $quantity,
                    $location, $reason, $urgency, $attachmentPath
                );

                if ($stmt->execute()) {
                    $success = true;

                    // ── Send emails ──────────────────────────────────────────
                    // 1) Confirmation to submitter
                    $submitterEmail = $userEmail;
                    $subjectUser    = "[UniKL RCMP] Equipment Request Received – {$refNumber}";
                    $bodyUser       = "
Dear {$userName},

Your equipment request has been successfully submitted to the Administration & Facilities Management Department (AFSMD).

━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  Reference Number : {$refNumber}
  Category         : {$category}
  Item Requested   : {$item_name}
  Quantity         : {$quantity}
  Department       : {$my_department}
  Location         : {$location}
  Urgency          : " . ucfirst($urgency) . "
  Submitted On     : " . $now->format('d M Y, g:ia') . " MYT
━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Our team will review your request and reach out if additional information is needed.

You may track your request status by logging into the UniKL RCMP Complaint & Request Portal.

Thank you,
UniKL Royal College of Medicine Perak
Help Desk
";
                    sendRawEmail($submitterEmail, $subjectUser, $bodyUser);

// 2) Notification to ALL active AFSMD staff (dept_id = 1)
$subjectAdmin = "[UniKL RCMP] New Equipment Request – {$refNumber}";
$bodyAdmin    = "
New Equipment Request Received

━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  Reference Number : {$refNumber}
  Submitted By     : {$userName} ({$submitterType})
  Contact          : +60{$phone}
  Email            : {$submitterEmail}
  Department       : {$my_department}
  Category         : {$category}
  Item Requested   : {$item_name}
  Quantity         : {$quantity}
  Location         : {$location}
  Urgency          : " . ucfirst($urgency) . "
  Submitted On     : " . $now->format('d M Y, g:ia') . " MYT
━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Justification:
{$reason}

Please log in to the admin portal to review and process this request.

UniKL RCMP Portal System
";

$afsmdStaff = $conn->prepare(
    "SELECT full_name, email FROM staff WHERE dept_id = 1 AND status = 'active'"
);
$afsmdStaff->execute();
$afsmdList = $afsmdStaff->get_result()->fetch_all(MYSQLI_ASSOC);
$afsmdStaff->close();

foreach ($afsmdList as $afsmdMember) {
    if (!empty($afsmdMember['email'])) {
        sendRawEmail($afsmdMember['email'], $subjectAdmin, $bodyAdmin);
    }
}
                } else {
                    $error = 'Failed to submit request. Please try again. (' . $stmt->error . ')';
                }
                $stmt->close();
            }
        }
    }
}

// ── PAGE CONFIG ───────────────────────────────────────────────────────────────
$pageTitle    = 'Request Equipment';
$pageSubtitle = date('l, d F Y');
$activeNav    = 'new_requisition';

ob_start();
?>
<style>
/* ══════════════════════════════════════════════════
   NEW REQUISITION — inline styles
══════════════════════════════════════════════════ */

/* Progress bar */
.req-progress-bar  { position:fixed;top:0;left:var(--sidebar-w);right:0;height:3px;background:var(--g100);z-index:500; }
.req-progress-fill { height:100%;width:0%;background:linear-gradient(90deg,#854f0b,#D4A017);border-radius:0 2px 2px 0;transition:width 0.4s cubic-bezier(.4,0,.2,1); }

/* Page layout */
.content { flex:1;width:100%;max-width:100%;padding:36px 48px;display:flex;flex-direction:column;align-items:center;box-sizing:border-box; }

/* Breadcrumb */
.breadcrumb { display:flex;align-items:center;gap:6px;font-size:12px;color:var(--g500);margin-bottom:8px; }
.breadcrumb a { color:var(--g500);text-decoration:none; }
.breadcrumb a:hover { color:var(--blue); }
.breadcrumb-sep { color:var(--g300); }

/* Back button */
.tp-back-btn { display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border-radius:9px;border:0.5px solid var(--g300);background:white;color:var(--g700);font-size:13px;font-weight:500;text-decoration:none;transition:all .2s;font-family:'DM Sans',sans-serif; }
.tp-back-btn:hover { border-color:#854f0b;background:#854f0b;color:#fff; }
.tp-back-btn svg { width:14px;height:14px;fill:none;stroke:currentColor;stroke-width:2; }

/* Page header */
.page-header { width:100%;max-width:860px;margin-bottom:28px;display:flex;flex-direction:column;align-items:flex-start;align-self:flex-start; }
.page-header h1 { font-family:'DM Serif Display',serif;font-size:25px;color:var(--g900);line-height:1.15; }
.page-header p  { font-size:14px;color:var(--g500);margin-top:6px;font-weight:300; }

/* Steps */
.steps-row { display:flex;align-items:stretch;width:100%;max-width:860px;margin-bottom:28px;background:white;border:1px solid var(--g300);border-radius:14px;overflow:hidden; }
.step { flex:1;display:flex;align-items:center;gap:12px;padding:18px 24px;position:relative;transition:background 0.25s; }
.step:not(:last-child)::after { content:'';position:absolute;right:0;top:50%;transform:translateY(-50%);width:1px;height:40%;background:var(--g300); }
.step.active { background:#faeeda; }
.step.done   { background:#f0f8eb; }
.step-num { width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:600;flex-shrink:0;background:var(--g100);color:var(--g500);border:1.5px solid var(--g300);transition:all 0.25s; }
.step.active .step-num { background:#854f0b;color:white;border-color:#854f0b; }
.step.done   .step-num { background:#4CAF50;color:white;border-color:#4CAF50; }
.step-label { font-size:13px;font-weight:500;color:var(--g500); }
.step.active .step-label { color:#854f0b; }
.step.done   .step-label { color:#3B6D11; }
.step-sub { font-size:11px;color:var(--g500);margin-top:1px; }

/* Working hours banner */
.wh-banner { display:flex;align-items:flex-start;gap:14px;width:100%;max-width:860px;padding:16px 20px;border-radius:12px;margin-bottom:20px;font-size:13px;line-height:1.6; }
.wh-banner.wh-open   { background:#e8f5e9;border:1px solid #a5d6a7;color:#1b5e20; }
.wh-banner.wh-closed { background:#fff8e1;border:1px solid #ffe082;color:#5d4037; }
.wh-banner-icon { width:38px;height:38px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center; }
.wh-open .wh-banner-icon   { background:#c8e6c9; }
.wh-closed .wh-banner-icon { background:#ffe0b2; }
.wh-banner-icon svg { width:20px;height:20px;fill:none;stroke-width:2; }
.wh-open   .wh-banner-icon svg { stroke:#2e7d32; }
.wh-closed .wh-banner-icon svg { stroke:#e65100; }
.wh-banner-body strong { display:block;font-size:14px;font-weight:600;margin-bottom:3px; }
.wh-open   .wh-banner-body strong { color:#1b5e20; }
.wh-closed .wh-banner-body strong { color:#bf360c; }
.wh-hours-chips { display:flex;flex-wrap:wrap;gap:6px;margin-top:8px; }
.wh-chip { display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;padding:3px 10px;border-radius:100px;letter-spacing:0.02em; }
.wh-open   .wh-chip { background:#a5d6a7;color:#1b5e20; }
.wh-closed .wh-chip { background:#ffcc80;color:#6d3200; }

/* Alert */
.alert { display:flex;align-items:flex-start;gap:12px;padding:14px 18px;border-radius:10px;font-size:13px;margin-bottom:24px;line-height:1.5;width:100%;max-width:860px; }
.alert svg { width:18px;height:18px;flex-shrink:0;margin-top:1px; }
.alert-error { background:#FDECEA;color:#B71C1C;border:1px solid rgba(183,28,28,0.15); }
.alert-error svg { fill:none;stroke:#B71C1C;stroke-width:2; }

/* Category picker */
.cat-picker { display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:24px; }
@media(max-width:680px){ .cat-picker { grid-template-columns:repeat(2,1fr); } }
.cat-option { display:none; }
.cat-label {
    display:flex;flex-direction:column;align-items:flex-start;gap:8px;
    padding:16px 16px;border-radius:14px;
    border:1.5px solid var(--g300);background:white;
    cursor:pointer;transition:all .2s;position:relative;
}
.cat-label:hover { border-color:#D4A017;box-shadow:0 4px 16px rgba(212,160,23,.12); }
.cat-option:checked + .cat-label {
    border-color:#854f0b;background:#faeeda;
    box-shadow:0 4px 20px rgba(133,79,11,.15);
}
.cat-option:checked + .cat-label .cat-check { opacity:1; }
.cat-icon { width:40px;height:40px;border-radius:10px;background:#f4f6fb;display:flex;align-items:center;justify-content:center;flex-shrink:0; }
.cat-icon svg { width:19px;height:19px;fill:none;stroke:#854f0b;stroke-width:1.8; }
.cat-name { font-size:13px;font-weight:600;color:var(--g900);line-height:1.2; }
.cat-desc { font-size:11px;color:var(--g500);line-height:1.4;margin-top:2px; }
.cat-check { position:absolute;top:10px;right:10px;width:18px;height:18px;border-radius:50%;background:#854f0b;display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .2s; }
.cat-check svg { width:10px;height:10px;fill:none;stroke:white;stroke-width:3; }

/* Urgency pills */
.urgency-row { display:flex;gap:10px;flex-wrap:wrap; }
.urg-option { display:none; }
.urg-label { display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border-radius:100px;border:1.5px solid var(--g300);background:white;font-size:13px;font-weight:500;color:var(--g700);cursor:pointer;transition:all .18s; }
.urg-dot   { width:8px;height:8px;border-radius:50%;flex-shrink:0; }
.urg-label:hover { border-color:var(--g500); }
.urg-option[value="normal"]:checked   + .urg-label { background:#EFF6FF;color:#1D4ED8;border-color:#93C5FD; }
.urg-option[value="urgent"]:checked   + .urg-label { background:#FEF3E2;color:#92520C;border-color:#FCD34D; }
.urg-option[value="critical"]:checked + .urg-label { background:#FDECEA;color:#B71C1C;border-color:#FCA5A5; }
.urg-option[value="normal"]   + .urg-label .urg-dot { background:#3B82F6; }
.urg-option[value="urgent"]   + .urg-label .urg-dot { background:#F59E0B; }
.urg-option[value="critical"] + .urg-label .urg-dot { background:#EF4444; }

/* Form card */
#reqForm { width:100%;max-width:860px; }
.form-card { background:white;border-radius:20px;border:1px solid var(--g300);overflow:hidden;box-shadow:0 4px 6px -1px rgba(0,0,0,.06),0 10px 40px -8px rgba(133,79,11,.10); }
.form-card-header { padding:26px 32px 22px;border-bottom:1px solid var(--g100);display:flex;align-items:center;gap:14px;background:linear-gradient(to right,#fffbf2,#ffffff); }
.fch-icon { width:44px;height:44px;border-radius:12px;background:#faeeda;display:flex;align-items:center;justify-content:center;flex-shrink:0; }
.fch-icon svg { width:22px;height:22px;fill:none;stroke:#854f0b;stroke-width:1.8; }
.fch-text h3 { font-family:'DM Serif Display',serif;font-size:19px;color:var(--g900); }
.fch-text p  { font-size:13px;color:var(--g500);margin-top:2px; }
.form-body { padding:32px 32px 24px; }
.form-section-label { font-size:11px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:var(--g500);margin-bottom:16px;margin-top:4px;padding-bottom:8px;border-bottom:1px solid var(--g100); }
.field-grid { display:grid;grid-template-columns:1fr 1fr;gap:0 20px; }
@media(max-width:600px){ .field-grid { grid-template-columns:1fr; } }
.field { margin-bottom:22px; }
.field label { display:flex;align-items:center;gap:6px;font-size:13px;font-weight:500;color:var(--g700);margin-bottom:8px; }
.field label .req { color:#E53935; }
.field label .opt { font-size:11px;color:var(--g500);font-weight:400;background:var(--g100);padding:2px 7px;border-radius:100px; }
.field input[type="text"],.field input[type="number"],.field input[type="tel"],.field select,.field textarea {
    width:100%;padding:12px 16px;border:1.5px solid var(--g300);border-radius:10px;
    font-family:'DM Sans',sans-serif;font-size:14px;color:var(--g900);background:white;
    transition:border-color 0.2s,box-shadow 0.2s;outline:none;appearance:none;box-sizing:border-box;
}
.field input:focus,.field select:focus,.field textarea:focus { border-color:#854f0b;box-shadow:0 0 0 3px rgba(133,79,11,.08); }
.field input::placeholder,.field textarea::placeholder { color:var(--g300); }
.field textarea { resize:vertical;min-height:120px;line-height:1.65; }
.select-wrap { position:relative; }
.select-wrap::after { content:'';position:absolute;right:14px;top:50%;width:10px;height:10px;pointer-events:none;border-right:2px solid var(--g500);border-bottom:2px solid var(--g500);transform:translateY(-65%) rotate(45deg); }
.select-wrap select { padding-right:36px;cursor:pointer; }
.qty-wrap { display:flex;align-items:center;gap:0; }
.qty-btn  { width:42px;height:46px;border:1.5px solid var(--g300);background:var(--g100);color:var(--g700);font-size:20px;font-weight:400;cursor:pointer;transition:background .15s,color .15s;display:flex;align-items:center;justify-content:center;flex-shrink:0;line-height:1; }
.qty-btn:first-child { border-radius:10px 0 0 10px;border-right:none; }
.qty-btn:last-child  { border-radius:0 10px 10px 0;border-left:none; }
.qty-btn:hover { background:#faeeda;color:#854f0b; }
.qty-wrap input[type="number"] { border-radius:0;text-align:center;border-left:none;border-right:none;font-weight:600; -moz-appearance:textfield; }
.qty-wrap input[type="number"]::-webkit-outer-spin-button,
.qty-wrap input[type="number"]::-webkit-inner-spin-button { -webkit-appearance:none;margin:0; }
.phone-wrap { display:flex; }
.phone-prefix { padding:12px 14px;background:var(--g100);border:1.5px solid var(--g300);border-right:none;border-radius:10px 0 0 10px;font-size:14px;color:var(--g700);font-weight:500;white-space:nowrap;display:flex;align-items:center;flex-shrink:0; }
.phone-wrap input[type="tel"] { border-radius:0 10px 10px 0 !important; }
.phone-hint { font-size:11px;color:var(--g500);margin-top:5px; }
.hint-error { color:#E53935; }
.hint-ok    { color:#43A047; }
.input-error { border-color:#E53935 !important;box-shadow:0 0 0 3px rgba(229,57,53,.10) !important; }
.input-ok    { border-color:#43A047 !important;box-shadow:0 0 0 3px rgba(67,160,71,.10) !important; }
.char-counter { font-size:11px;color:var(--g500); }
.char-counter.warn   { color:#FF9800; }
.char-counter.danger { color:#E53935; }
.field-footer { display:flex;align-items:center;justify-content:flex-end;margin-top:6px; }

/* File drop */
.file-drop { border:2px dashed var(--g300);border-radius:10px;padding:28px 24px;text-align:center;cursor:pointer;transition:all .2s;position:relative;overflow:hidden; }
.file-drop:hover,.file-drop.dragover { border-color:#D4A017;background:#fffbf2; }
.file-drop input[type="file"] { position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%; }
.file-icon { width:44px;height:44px;border-radius:12px;background:var(--g100);margin:0 auto 12px;display:flex;align-items:center;justify-content:center; }
.file-icon svg { width:20px;height:20px;fill:none;stroke:var(--g500);stroke-width:1.8; }
.file-drop-title { font-size:14px;font-weight:500;color:var(--g700);margin-bottom:4px; }
.file-drop-sub   { font-size:12px;color:var(--g500); }
.file-drop-sub strong { color:#854f0b; }
.file-selected { display:none;align-items:center;gap:10px;padding:10px 14px;background:#faeeda;border:1px solid rgba(133,79,11,.2);border-radius:8px;margin-top:10px;font-size:13px;color:#854f0b; }
.file-selected svg { width:14px;height:14px;fill:none;stroke:#854f0b;stroke-width:2; }
.file-remove { margin-left:auto;cursor:pointer;color:var(--g500);background:none;border:none;font-size:18px;line-height:1;padding:0; }
.file-remove:hover { color:#E53935; }

/* Form actions */
.form-actions { padding:20px 32px;border-top:1px solid var(--g100);display:flex;align-items:center;justify-content:space-between;gap:12px;background:var(--off); }
.btn-cancel { font-family:'DM Sans',sans-serif;font-size:14px;font-weight:500;padding:11px 22px;border-radius:9px;border:1.5px solid var(--g300);background:white;color:var(--g700);cursor:pointer;text-decoration:none;transition:all .2s; }
.btn-cancel:hover { border-color:var(--g500);color:var(--g900); }
.btn-submit-req { font-family:'DM Sans',sans-serif;font-size:14px;font-weight:600;padding:12px 32px;border-radius:10px;background:#854f0b;color:white;border:none;cursor:pointer;display:flex;align-items:center;gap:9px;letter-spacing:.01em;box-shadow:0 4px 14px rgba(133,79,11,.28);transition:all .2s; }
.btn-submit-req:hover { background:#6d3f08;transform:translateY(-1px);box-shadow:0 6px 20px rgba(133,79,11,.38); }
.btn-submit-req svg { width:16px;height:16px;fill:none;stroke:white;stroke-width:2; }

/* Locked form overlay */
.form-locked-wrap { position:relative;width:100%;max-width:860px; }
.form-locked-overlay { position:absolute;inset:0;z-index:20;background:rgba(255,255,255,.82);backdrop-filter:blur(3px);border-radius:20px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:40px 32px; }
.flo-icon { width:64px;height:64px;border-radius:50%;background:#fff3e0;border:2px solid #ffcc80;display:flex;align-items:center;justify-content:center;margin-bottom:18px; }
.flo-icon svg { width:28px;height:28px;fill:none;stroke:#e65100;stroke-width:2; }
.flo-title { font-family:'DM Serif Display',serif;font-size:20px;color:#bf360c;margin-bottom:8px; }
.flo-body  { font-size:13px;color:#5d4037;line-height:1.65;max-width:420px;margin-bottom:16px; }
.flo-hours { display:inline-flex;align-items:center;gap:8px;background:#fff8e1;border:1px solid #ffe082;border-radius:10px;padding:10px 18px;font-size:13px;font-weight:600;color:#6d3200;margin-bottom:16px; }
.flo-hours svg { width:15px;height:15px;fill:none;stroke:#e65100;stroke-width:2; }
.flo-next  { font-size:12px;color:#795548;background:#ffe0b2;border-radius:8px;padding:8px 14px;display:inline-flex;align-items:center;gap:7px; }
.flo-next svg { width:13px;height:13px;fill:none;stroke:#bf360c;stroke-width:2;flex-shrink:0; }
.form-locked-wrap .form-card { pointer-events:none;user-select:none; }
.btn-submit-locked { font-family:'DM Sans',sans-serif;font-size:14px;font-weight:600;padding:12px 32px;border-radius:10px;background:#bdbdbd;color:white;border:none;cursor:not-allowed;display:flex;align-items:center;gap:9px; }

/* Preview panel */
#previewPanel { display:none;width:100%;max-width:860px; }
#previewPanel.show { display:block;animation:panelIn 0.3s ease; }
.preview-card { background:white;border-radius:20px;border:1px solid var(--g300);overflow:hidden;box-shadow:0 4px 6px -1px rgba(0,0,0,.06),0 10px 40px -8px rgba(133,79,11,.10); }
.preview-card-header { padding:26px 32px 22px;border-bottom:1px solid var(--g100);display:flex;align-items:center;gap:14px;background:linear-gradient(to right,#fffbf2,#ffffff); }
.pch-icon { width:44px;height:44px;border-radius:12px;background:#e8f5e9;display:flex;align-items:center;justify-content:center;flex-shrink:0; }
.pch-icon svg { width:22px;height:22px;fill:none;stroke:#2e7d32;stroke-width:1.8; }
.pch-text h3 { font-family:'DM Serif Display',serif;font-size:19px;color:var(--g900); }
.pch-text p  { font-size:13px;color:var(--g500);margin-top:2px; }
.preview-body { padding:28px 32px; }
.preview-section { margin-bottom:28px; }
.preview-section-title { font-size:11px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--g500);margin-bottom:14px;padding-bottom:8px;border-bottom:1px solid var(--g100); }
.preview-grid { display:grid;grid-template-columns:1fr 1fr;gap:14px 24px; }
@media(max-width:600px){ .preview-grid { grid-template-columns:1fr; } }
.preview-field { display:flex;flex-direction:column;gap:4px; }
.preview-field.full { grid-column:1/-1; }
.preview-label { font-size:11px;font-weight:600;color:var(--g500);letter-spacing:.04em;text-transform:uppercase; }
.preview-value { font-size:14px;color:var(--g900);background:var(--g100);border-radius:8px;padding:10px 14px;line-height:1.5;word-break:break-word; }
.preview-value.pre-wrap { white-space:pre-wrap;min-height:72px; }
.preview-badge     { display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:100px;background:#faeeda;color:#854f0b;font-size:12px;font-weight:600;border:1px solid rgba(133,79,11,.2); }
.preview-urg-badge { display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:100px;font-size:12px;font-weight:600;border:1px solid; }
.urg-normal   { background:#EFF6FF;color:#1D4ED8;border-color:#93C5FD; }
.urg-urgent   { background:#FEF3E2;color:#92520C;border-color:#FCD34D; }
.urg-critical { background:#FDECEA;color:#B71C1C;border-color:#FCA5A5; }
.preview-notice { display:flex;align-items:flex-start;gap:10px;padding:12px 16px;border-radius:10px;background:#fff8e1;border:1px solid #ffe082;font-size:13px;color:#795548;line-height:1.5;margin-top:20px; }
.preview-notice svg { width:16px;height:16px;fill:none;stroke:#F9A825;stroke-width:2;flex-shrink:0;margin-top:1px; }
.preview-actions { padding:20px 32px;border-top:1px solid var(--g100);display:flex;align-items:center;justify-content:space-between;gap:12px;background:var(--off); }
.btn-back { font-family:'DM Sans',sans-serif;font-size:14px;font-weight:500;padding:11px 22px;border-radius:9px;border:1.5px solid var(--g300);background:white;color:var(--g700);cursor:pointer;display:flex;align-items:center;gap:8px;text-decoration:none;transition:all .2s; }
.btn-back:hover { border-color:var(--g500);color:var(--g900); }
.btn-back svg { width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:2; }
.btn-confirm { font-family:'DM Sans',sans-serif;font-size:14px;font-weight:600;padding:12px 32px;border-radius:10px;background:#2e7d32;color:white;border:none;cursor:pointer;display:flex;align-items:center;gap:9px;box-shadow:0 4px 14px rgba(46,125,50,.25);transition:all .2s; }
.btn-confirm:hover { background:#1b5e20;transform:translateY(-1px); }
.btn-confirm svg { width:16px;height:16px;fill:none;stroke:white;stroke-width:2; }

/* Success overlay */
.success-overlay { display:none;position:fixed;inset:0;z-index:999;background:rgba(10,20,50,.65);backdrop-filter:blur(6px);align-items:center;justify-content:center; }
.success-overlay.show { display:flex; }
.success-card { background:white;border-radius:20px;padding:48px 44px;max-width:480px;width:90%;text-align:center;animation:scaleIn 0.4s cubic-bezier(.34,1.56,.64,1);box-shadow:0 24px 80px rgba(0,0,0,.18); }
.success-icon-wrap { width:72px;height:72px;border-radius:50%;background:linear-gradient(135deg,#854f0b,#D4A017);margin:0 auto 20px;display:flex;align-items:center;justify-content:center;box-shadow:0 8px 24px rgba(133,79,11,.3); }
.success-icon-wrap svg { width:32px;height:32px;fill:none;stroke:white;stroke-width:2.5; }
.success-card h2 { font-family:'DM Serif Display',serif;font-size:26px;color:var(--g900);margin-bottom:10px; }
.success-card p  { font-size:14px;color:var(--g500);line-height:1.65;font-weight:300;margin-bottom:20px; }
.success-ref { background:#faeeda;border:1px solid rgba(133,79,11,.2);border-radius:10px;padding:14px 20px;margin-bottom:20px; }
.success-ref .ref-label { font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:#854f0b;font-weight:600;margin-bottom:4px; }
.success-ref .ref-id { font-family:monospace;font-size:18px;font-weight:700;color:var(--g900);letter-spacing:.04em; }
.success-info { display:flex;align-items:flex-start;gap:10px;padding:10px 14px;border-radius:10px;margin-bottom:20px;font-size:12px;line-height:1.55;text-align:left;background:#e8f5e9;border:1px solid #a5d6a7;color:#1b5e20; }
.success-info svg { width:14px;height:14px;fill:none;stroke:currentColor;stroke-width:2;flex-shrink:0;margin-top:1px; }
.success-actions { display:flex;gap:10px;justify-content:center; }
.btn-ghost { font-family:'DM Sans',sans-serif;font-size:13px;font-weight:500;padding:10px 20px;border-radius:8px;border:1.5px solid var(--g300);background:white;color:var(--g700);cursor:pointer;text-decoration:none;transition:all .2s; }
.btn-ghost:hover { border-color:var(--g500); }
.btn-primary-sm { font-family:'DM Sans',sans-serif;font-size:13px;font-weight:500;padding:10px 20px;border-radius:8px;background:#854f0b;color:white;border:none;cursor:pointer;text-decoration:none;transition:background .2s; }
.btn-primary-sm:hover { background:#6d3f08; }

/* Animations */
@keyframes panelIn { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:translateY(0)} }
@keyframes scaleIn { from{opacity:0;transform:scale(.85)} to{opacity:1;transform:scale(1)} }
@keyframes spin    { to{transform:rotate(360deg)} }

/* Responsive */
@media(max-width:1100px){ .content { padding:28px 32px; } }
@media(max-width:768px){
    .content { padding:20px 16px;align-items:stretch; }
    .page-header,.steps-row,.wh-banner,.alert,#reqForm,#previewPanel,.form-locked-wrap { max-width:100%; }
    .form-body,.preview-body { padding:20px 18px; }
    .form-card-header,.preview-card-header { padding:20px 18px 16px; }
    .form-actions,.preview-actions { padding:16px 18px;flex-wrap:wrap; }
}
</style>
<?php
$extraHead = ob_get_clean();
require 'layout.php';
?>

<div class="req-progress-bar"><div class="req-progress-fill" id="progressFill"></div></div>

<!-- ── Page Header ── -->
<div class="page-header">
  <div class="breadcrumb" style="margin-top:12px;">
    <a href="homepage.php">Dashboard</a>
    <span class="breadcrumb-sep">›</span>
    <span>Request Equipment</span>
  </div>
  <a href="homepage.php" class="tp-back-btn" style="margin-top:10px;">
    <svg viewBox="0 0 24 24"><polyline points="15,18 9,12 15,6"/></svg>
    Back to Dashboard
  </a><br>
  <h1>Request Equipment</h1>
  <p>Submit a request to AFSMD for office equipment, furniture, or facility items.</p>
</div>

<!-- ── Steps ── -->
<div class="steps-row">
  <div class="step active" id="step1">
    <div class="step-num">1</div>
    <div><div class="step-label">Fill Details</div><div class="step-sub">What do you need?</div></div>
  </div>
  <div class="step" id="step2">
    <div class="step-num">2</div>
    <div><div class="step-label">Preview</div><div class="step-sub">Review before sending</div></div>
  </div>
  <div class="step" id="step3">
    <div class="step-num">3</div>
    <div><div class="step-label">Submitted</div><div class="step-sub">Reference generated</div></div>
  </div>
</div>

<!-- ── Working Hours Banner ── -->
<?php if ($isWorkingHours): ?>
<div class="wh-banner wh-open">
  <div class="wh-banner-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
  <div class="wh-banner-body">
    <strong>AFSMD is available — your request will be processed today!</strong>
    UniKL RCMP Help Desk is currently open.
    <div class="wh-hours-chips">
      <span class="wh-chip">Mon – Fri</span>
      <span class="wh-chip">8:00 AM – 5:00 PM</span>
      <span class="wh-chip">Now: <?php echo $now->format('g:i A, l'); ?></span>
    </div>
  </div>
</div>
<?php else: ?>
<div class="wh-banner wh-closed">
  <div class="wh-banner-icon"><svg viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg></div>
  <div class="wh-banner-body">
    <strong>Outside Working Hours — Requests are currently disabled</strong>
    Submissions are accepted Monday–Friday, 8:00 AM – 5:00 PM (MYT).
    <div class="wh-hours-chips">
      <span class="wh-chip">Now: <?php echo $now->format('g:i A, l'); ?></span>
      <span class="wh-chip">Next available: <?php echo $nextDisplay; ?></span>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Error Alert ── -->
<?php if (!empty($error)): ?>
<div class="alert alert-error">
  <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
  <div><?php echo htmlspecialchars($error); ?></div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════
     LOCKED FORM (outside working hours)
══════════════════════════════════════════ -->
<?php if (!$isWorkingHours): ?>
<div class="form-locked-wrap">
  <div class="form-locked-overlay">
    <div class="flo-icon"><svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></div>
    <div class="flo-title">Submissions Closed</div>
    <div class="flo-body">The request form is only available during official working hours. Please come back when AFSMD is open.</div>
    <div class="flo-hours"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>Monday – Friday &nbsp;|&nbsp; 8:00 AM – 5:00 PM MYT</div>
    <div class="flo-next"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>Next available: <?php echo $nextDisplay; ?></div>
  </div>
  <div class="form-card" aria-hidden="true" tabindex="-1">
    <div class="form-card-header">
      <div class="fch-icon"><svg viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/><path d="M16 3H8a1 1 0 0 0-1 1v3h10V4a1 1 0 0 0-1-1z"/></svg></div>
      <div class="fch-text"><h3>Equipment Request</h3><p>Your request will be sent directly to Administration & Facilities Management Department for processing</p></div>
    </div>
    <div class="form-body">
      <div class="form-section-label">Equipment Category</div>
      <div class="cat-picker">
        <?php foreach ($equipmentCategories as $name => $meta): ?>
        <div><input type="radio" class="cat-option" disabled><label class="cat-label"><div class="cat-icon"><svg viewBox="0 0 24 24"><?php echo $meta['icon']; ?></svg></div><div class="cat-name"><?php echo $name; ?></div><div class="cat-desc"><?php echo $meta['description']; ?></div></label></div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="form-actions">
      <a href="homepage.php" class="btn-cancel">Cancel</a>
      <button type="button" class="btn-submit-locked" disabled><svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Submissions Closed</button>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ══════════════════════════════════════════
     ACTIVE FORM (working hours)
══════════════════════════════════════════ -->
<form method="POST" action="new_requisition.php" id="reqForm" enctype="multipart/form-data" novalidate>

  <div class="form-card">
    <div class="form-card-header">
      <div class="fch-icon">
        <svg viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/><path d="M16 3H8a1 1 0 0 0-1 1v3h10V4a1 1 0 0 0-1-1z"/><line x1="12" y1="12" x2="12" y2="16"/><line x1="10" y1="14" x2="14" y2="14"/></svg>
      </div>
      <div class="fch-text">
        <h3>Equipment Request</h3>
        <p>Your request will be sent directly to AFSMD for processing</p>
      </div>
    </div>

    <div class="form-body">

      <!-- ── STEP 1: Category ── -->
      <div class="form-section-label">Equipment Category <span style="color:#E53935">*</span></div>
      <div class="cat-picker" id="catPicker">
        <?php foreach ($equipmentCategories as $name => $meta):
          $slug = strtolower(str_replace([' ', '&'], ['-', ''], $name));
          $checked = (($_POST['category'] ?? '') === $name) ? 'checked' : '';
        ?>
        <div>
          <input type="radio" name="category" id="cat-<?php echo $slug; ?>"
                 class="cat-option" value="<?php echo htmlspecialchars($name); ?>" <?php echo $checked; ?>>
          <label class="cat-label" for="cat-<?php echo $slug; ?>">
            <div class="cat-check"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg></div>
            <div class="cat-icon"><svg viewBox="0 0 24 24"><?php echo $meta['icon']; ?></svg></div>
            <div class="cat-name"><?php echo htmlspecialchars($name); ?></div>
            <div class="cat-desc"><?php echo htmlspecialchars($meta['description']); ?></div>
          </label>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- ── STEP 2: Item Details ── -->
      <div class="form-section-label" style="margin-top:4px;">Item Details</div>

      <div class="field">
        <label for="item_name">Item Description <span class="req">*</span></label>
        <input type="text" id="item_name" name="item_name"
               placeholder="e.g. Ergonomic office chair, model Flexispot EC1"
               maxlength="200"
               value="<?php echo htmlspecialchars($_POST['item_name'] ?? ''); ?>" required/>
        <div class="field-footer"><span class="char-counter" id="itemCounter">0 / 200</span></div>
      </div>

      <div class="field-grid">
        <div class="field">
          <label for="quantity">Quantity <span class="req">*</span></label>
          <div class="qty-wrap">
            <button type="button" class="qty-btn" id="qtyMinus">−</button>
            <input type="number" id="quantity" name="quantity" min="1" max="999"
                   value="<?php echo htmlspecialchars($_POST['quantity'] ?? '1'); ?>" required/>
            <button type="button" class="qty-btn" id="qtyPlus">+</button>
          </div>
        </div>

        <div class="field">
          <label for="urgency">Urgency Level <span class="req">*</span></label>
          <div class="urgency-row">
            <?php
            $curUrg = $_POST['urgency'] ?? 'normal';
            foreach (['normal'=>'Normal','urgent'=>'Urgent','critical'=>'Critical'] as $val=>$lbl):
            ?>
            <input type="radio" name="urgency" id="urg-<?php echo $val; ?>"
                   class="urg-option" value="<?php echo $val; ?>"
                   <?php echo ($curUrg === $val) ? 'checked' : ''; ?>>
            <label class="urg-label" for="urg-<?php echo $val; ?>">
              <span class="urg-dot"></span><?php echo $lbl; ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- ── STEP 3: Your Info ── -->
      <div class="form-section-label">Your Information</div>

      <div class="field-grid">
        <div class="field">
          <label for="my_department">Your Department / Faculty <span class="req">*</span></label>
          <div class="select-wrap">
            <select id="my_department" name="my_department" required>
              <option value="" disabled selected>— Select Department / Faculty —</option>
              <?php foreach ($myDepartments as $d): ?>
              <option value="<?php echo htmlspecialchars($d); ?>"
                <?php echo (($_POST['my_department'] ?? '') === $d) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($d); ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="field">
          <label for="phone">Contact Number <span class="req">*</span></label>
          <div class="phone-wrap">
            <span class="phone-prefix">+60</span>
            <input type="tel" id="phone" name="phone"
                   placeholder="11-12345678" maxlength="12"
                   value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" required autocomplete="off"/>
          </div>
          <div class="phone-hint" id="phoneHint">Enter 9–10 digits (e.g. 11-12345678)</div>
        </div>
      </div>

      <div class="field">
        <label for="location">Delivery / Installation Location <span class="req">*</span></label>
        <input type="text" id="location" name="location"
               placeholder="e.g. Block A, Room 203, Level 2"
               maxlength="200"
               value="<?php echo htmlspecialchars($_POST['location'] ?? ''); ?>" required/>
      </div>

      <!-- ── STEP 4: Justification ── -->
      <div class="form-section-label">Justification</div>

      <div class="field">
        <label for="reason">Reason / Justification <span class="req">*</span></label>
        <textarea id="reason" name="reason"
                  placeholder="Please explain why this equipment is needed and how it will be used..."
                  maxlength="1500" required><?php echo htmlspecialchars($_POST['reason'] ?? ''); ?></textarea>
        <div class="field-footer"><span class="char-counter" id="reasonCounter">0 / 1500</span></div>
      </div>

      <!-- ── Attachment ── -->
      <div class="field">
        <label>Supporting Document <span class="opt">Optional</span></label>
        <div class="file-drop" id="fileDrop">
          <input type="file" name="attachment" id="attachment"
                 accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt"/>
          <div class="file-icon"><svg viewBox="0 0 24 24"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg></div>
          <div class="file-drop-title">Drop a file here, or <strong>browse</strong></div>
          <div class="file-drop-sub">JPG, PNG, PDF, DOC, DOCX, TXT — max 5 MB</div>
        </div>
        <div class="file-selected" id="fileSelected">
          <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
          <span id="fileName">—</span>
          <button type="button" class="file-remove" id="fileRemove">×</button>
        </div>
      </div>

    </div><!-- /form-body -->

    <div class="form-actions">
      <a href="homepage.php" class="btn-cancel">Cancel</a>
      <button type="button" class="btn-submit-req" onclick="showPreview()">
        Preview & Review
        <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
      </button>
    </div>
  </div><!-- /form-card -->

</form>

<!-- ══════════════════════════════════════════
     PREVIEW PANEL
══════════════════════════════════════════ -->
<div id="previewPanel">
  <div class="preview-card">
    <div class="preview-card-header">
      <div class="pch-icon"><svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></div>
      <div class="pch-text"><h3>Review Your Request</h3><p>Please check all details carefully before submitting</p></div>
    </div>
    <div class="preview-body">

      <div class="preview-section">
        <div class="preview-section-title">Equipment</div>
        <div class="preview-grid">
          <div class="preview-field">
            <div class="preview-label">Category</div>
            <div class="preview-value" id="pv-category">—</div>
          </div>
          <div class="preview-field">
            <div class="preview-label">Urgency</div>
            <div class="preview-value" id="pv-urgency">—</div>
          </div>
          <div class="preview-field full">
            <div class="preview-label">Item Description</div>
            <div class="preview-value" id="pv-item">—</div>
          </div>
          <div class="preview-field">
            <div class="preview-label">Quantity</div>
            <div class="preview-value" id="pv-quantity">—</div>
          </div>
          <div class="preview-field">
            <div class="preview-label">Location</div>
            <div class="preview-value" id="pv-location">—</div>
          </div>
        </div>
      </div>

      <div class="preview-section">
        <div class="preview-section-title">Your Information</div>
        <div class="preview-grid">
          <div class="preview-field">
            <div class="preview-label">Department / Faculty</div>
            <div class="preview-value" id="pv-dept">—</div>
          </div>
          <div class="preview-field">
            <div class="preview-label">Contact Number</div>
            <div class="preview-value" id="pv-phone">—</div>
          </div>
        </div>
      </div>

      <div class="preview-section">
        <div class="preview-section-title">Justification</div>
        <div class="preview-grid">
          <div class="preview-field full">
            <div class="preview-label">Reason</div>
            <div class="preview-value pre-wrap" id="pv-reason">—</div>
          </div>
          <div class="preview-field">
            <div class="preview-label">Attachment</div>
            <div class="preview-value" id="pv-attachment"><span style="color:#bbb;font-style:italic">No attachment</span></div>
          </div>
        </div>
      </div>

      <div class="preview-notice">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        Once submitted, this request will be forwarded to AFSMD and you will receive a confirmation email.
      </div>
    </div>
    <div class="preview-actions">
      <button type="button" class="btn-back" onclick="backToForm()">
        <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>Back to Edit
      </button>
      <button type="button" class="btn-confirm" id="confirmBtn" onclick="submitForm()">
        Confirm & Submit
        <svg viewBox="0 0 24 24"><path d="M22 2L11 13"/><path d="M22 2L15 22 11 13 2 9l20-7z"/></svg>
      </button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════
     SUCCESS OVERLAY
══════════════════════════════════════════ -->
<div class="success-overlay <?php echo $success ? 'show' : ''; ?>" id="successOverlay">
  <div class="success-card">
    <div class="success-icon-wrap"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg></div>
    <h2>Request Submitted!</h2>
    <p>Your equipment request has been forwarded to AFSMD. A confirmation email has been sent to you.</p>
    <div class="success-ref">
      <div class="ref-label">Your Reference Number</div>
      <div class="ref-id"><?php echo htmlspecialchars($refNumber); ?></div>
    </div>
    <div class="success-info">
      <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      <div>AFSMD will review your request and contact you if additional information is needed.</div>
    </div>
    <div class="success-actions">
      <a href="new_requisition.php" class="btn-ghost">Submit Another</a>
      <a href="homepage.php" class="btn-primary-sm">← Back to Dashboard</a>
    </div>
  </div>
</div>

<?php ob_start(); ?>
<script>
<?php if ($isWorkingHours): ?>

const progressFill = document.getElementById('progressFill');

// ── Progress calculation ──────────────────────────────────────
function updateProgress() {
    const fields = [
        document.querySelector('input[name="category"]:checked')?.value,
        document.getElementById('item_name').value.trim(),
        document.getElementById('quantity').value,
        document.getElementById('my_department').value,
        document.getElementById('phone').value.trim(),
        document.getElementById('location').value.trim(),
        document.getElementById('reason').value.trim(),
    ];
    const filled = fields.filter(Boolean).length;
    progressFill.style.width = Math.round((filled / fields.length) * 100) + '%';
}

// ── Category picker ───────────────────────────────────────────
document.querySelectorAll('.cat-option').forEach(r => r.addEventListener('change', updateProgress));

// ── Quantity stepper ──────────────────────────────────────────
const qtyInput = document.getElementById('quantity');
document.getElementById('qtyMinus').addEventListener('click', () => {
    const v = parseInt(qtyInput.value) || 1;
    if (v > 1) { qtyInput.value = v - 1; updateProgress(); }
});
document.getElementById('qtyPlus').addEventListener('click', () => {
    const v = parseInt(qtyInput.value) || 1;
    if (v < 999) { qtyInput.value = v + 1; updateProgress(); }
});
qtyInput.addEventListener('input', updateProgress);

// ── Character counters ────────────────────────────────────────
function updateCounter(id, len, max) {
    const el = document.getElementById(id);
    el.textContent = len + ' / ' + max;
    el.classList.remove('warn', 'danger');
    if (len >= max * 0.9) el.classList.add('danger');
    else if (len >= max * 0.75) el.classList.add('warn');
}
document.getElementById('item_name').addEventListener('input', function () {
    updateCounter('itemCounter', this.value.length, 200); updateProgress();
});
document.getElementById('reason').addEventListener('input', function () {
    updateCounter('reasonCounter', this.value.length, 1500); updateProgress();
});

// ── Phone formatting ──────────────────────────────────────────
(function initPhone() {
    const phoneInput = document.getElementById('phone');
    const phoneHint  = document.getElementById('phoneHint');
    function formatPhone(raw) {
        const d = raw.replace(/\D/g, '');
        if (d.length <= 2) return d;
        return d.slice(0, 2) + '-' + d.slice(2, 11);
    }
    function validatePhone(val) {
        const d = val.replace(/\D/g, '');
        if (!d.length)    return 'empty';
        if (d.length < 9) return 'short';
        if (d.length > 10) return 'long';
        return 'ok';
    }
    phoneInput.addEventListener('input', function () {
        const cursor = this.selectionStart;
        const oldLen = this.value.length;
        this.value   = formatPhone(this.value);
        const diff   = this.value.length - oldLen;
        this.setSelectionRange(cursor + diff, cursor + diff);
        const state  = validatePhone(this.value);
        this.classList.remove('input-error', 'input-ok');
        phoneHint.classList.remove('hint-error', 'hint-ok');
        if (state === 'empty') {
            phoneHint.textContent = 'Enter 9–10 digits (e.g. 11-12345678)';
        } else if (state === 'short') {
            this.classList.add('input-error'); phoneHint.classList.add('hint-error');
            phoneHint.textContent = 'Too short — minimum 9 digits required.';
        } else if (state === 'long') {
            this.classList.add('input-error'); phoneHint.classList.add('hint-error');
            phoneHint.textContent = 'Too long — maximum 10 digits allowed.';
        } else {
            this.classList.add('input-ok'); phoneHint.classList.add('hint-ok');
            phoneHint.textContent = '✓ Looks good!';
        }
        updateProgress();
    });
    phoneInput.addEventListener('keydown', function (e) {
        const allowed = ['Backspace','Delete','ArrowLeft','ArrowRight','Tab','Home','End'];
        if (allowed.includes(e.key)) return;
        if (!/^\d$/.test(e.key)) e.preventDefault();
    });
    if (phoneInput.value) phoneInput.dispatchEvent(new Event('input'));
})();

document.getElementById('my_department').addEventListener('change', updateProgress);
document.getElementById('location').addEventListener('input', updateProgress);
document.querySelectorAll('.urg-option').forEach(r => r.addEventListener('change', updateProgress));

// ── File drop ─────────────────────────────────────────────────
const fileInput = document.getElementById('attachment');
const fileDrop  = document.getElementById('fileDrop');
const fileSel   = document.getElementById('fileSelected');
const fileNameEl= document.getElementById('fileName');
document.getElementById('fileRemove').addEventListener('click', () => {
    fileInput.value = ''; fileSel.style.display = 'none';
});
fileInput.addEventListener('change', function () {
    if (this.files.length > 0) { fileNameEl.textContent = this.files[0].name; fileSel.style.display = 'flex'; }
});
['dragenter','dragover'].forEach(e => fileDrop.addEventListener(e, ev => { ev.preventDefault(); fileDrop.classList.add('dragover'); }));
['dragleave','drop'].forEach(e => fileDrop.addEventListener(e, ev => { ev.preventDefault(); fileDrop.classList.remove('dragover'); }));
fileDrop.addEventListener('drop', ev => {
    const f = ev.dataTransfer.files[0];
    if (f) { const dt = new DataTransfer(); dt.items.add(f); fileInput.files = dt.files; fileNameEl.textContent = f.name; fileSel.style.display = 'flex'; }
});

// ── Validation helpers ────────────────────────────────────────
function markError(el, msg) {
    el.classList.add('input-error');
    const wrap = el.closest('.field');
    if (!wrap) return;
    let hint = wrap.querySelector('.inline-err');
    if (!hint) { hint = document.createElement('div'); hint.className = 'inline-err phone-hint hint-error'; wrap.appendChild(hint); }
    hint.textContent = msg;
}
function clearErrors() {
    document.querySelectorAll('.input-error').forEach(el => el.classList.remove('input-error'));
    document.querySelectorAll('.inline-err').forEach(el => el.remove());
}

// ── Show Preview ──────────────────────────────────────────────
function showPreview() {
    clearErrors();
    const category  = document.querySelector('input[name="category"]:checked')?.value || '';
    const itemName  = document.getElementById('item_name').value.trim();
    const qty       = parseInt(document.getElementById('quantity').value) || 0;
    const dept      = document.getElementById('my_department').value;
    const phone     = document.getElementById('phone').value.trim();
    const location  = document.getElementById('location').value.trim();
    const reason    = document.getElementById('reason').value.trim();
    const urgency   = document.querySelector('input[name="urgency"]:checked')?.value || '';

    let firstError = null;

    if (!category) {
        alert('Please select an equipment category.');
        document.getElementById('catPicker').scrollIntoView({behavior:'smooth',block:'center'});
        return;
    }
    if (!itemName) { markError(document.getElementById('item_name'), 'Please describe the item.'); firstError = firstError || document.getElementById('item_name'); }
    if (!qty || qty < 1) { markError(document.getElementById('quantity'), 'Quantity must be at least 1.'); firstError = firstError || document.getElementById('quantity'); }
    if (!dept) { markError(document.getElementById('my_department'), 'Please select your department.'); firstError = firstError || document.getElementById('my_department'); }
    const phoneDigits = phone.replace(/\D/g,'');
    if (!phone || phoneDigits.length < 9 || phoneDigits.length > 10) { markError(document.getElementById('phone'), !phone ? 'Phone number is required.' : 'Phone must be 9–10 digits.'); firstError = firstError || document.getElementById('phone'); }
    if (!location) { markError(document.getElementById('location'), 'Please specify the delivery location.'); firstError = firstError || document.getElementById('location'); }
    if (!reason) { markError(document.getElementById('reason'), 'Please provide a justification.'); firstError = firstError || document.getElementById('reason'); }

    if (firstError) { firstError.scrollIntoView({behavior:'smooth',block:'center'}); firstError.focus(); return; }

    // Populate preview
    document.getElementById('pv-category').innerHTML = `<span class="preview-badge">${esc(category)}</span>`;

    const urgLabels = {normal:'Normal',urgent:'Urgent',critical:'Critical'};
    const urgClasses = {normal:'urg-normal',urgent:'urg-urgent',critical:'urg-critical'};
    document.getElementById('pv-urgency').innerHTML = `<span class="preview-urg-badge ${urgClasses[urgency]||''}">${esc(urgLabels[urgency]||urgency)}</span>`;

    document.getElementById('pv-item').textContent     = itemName;
    document.getElementById('pv-quantity').textContent = qty + (qty === 1 ? ' unit' : ' units');
    document.getElementById('pv-location').textContent = location;
    document.getElementById('pv-dept').textContent     = dept;
    document.getElementById('pv-phone').textContent    = '+60 ' + phone;
    document.getElementById('pv-reason').textContent   = reason;

    const attEl = document.getElementById('pv-attachment');
    attEl.innerHTML = (fileInput.files.length > 0 && fileSel.style.display !== 'none')
        ? `<span style="color:#854f0b;font-weight:500">${esc(fileNameEl.textContent)}</span>`
        : '<span style="color:#bbb;font-style:italic">No attachment</span>';

    // Switch panels
    document.getElementById('reqForm').style.display = 'none';
    document.getElementById('previewPanel').classList.add('show');
    document.getElementById('step1').classList.remove('active'); document.getElementById('step1').classList.add('done');
    document.getElementById('step2').classList.add('active');
    progressFill.style.width = '66%';
    window.scrollTo({top:0, behavior:'smooth'});
}

function backToForm() {
    document.getElementById('reqForm').style.display = '';
    document.getElementById('previewPanel').classList.remove('show');
    document.getElementById('step1').classList.add('active'); document.getElementById('step1').classList.remove('done');
    document.getElementById('step2').classList.remove('active');
    updateProgress();
    window.scrollTo({top:0, behavior:'smooth'});
}

function submitForm() {
    const btn = document.getElementById('confirmBtn');
    btn.disabled = true;
    btn.innerHTML = 'Submitting… <svg viewBox="0 0 24 24" width="16" height="16" style="animation:spin 0.8s linear infinite;fill:none;stroke:white;stroke-width:2"><circle cx="12" cy="12" r="10" stroke-opacity="0.25"/><path d="M12 2a10 10 0 0 1 10 10"/></svg>';
    document.getElementById('step2').classList.remove('active'); document.getElementById('step2').classList.add('done');
    document.getElementById('step3').classList.add('active');
    progressFill.style.width = '100%';
    document.getElementById('reqForm').submit();
}

function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// Init counters
(function init() {
    const n = document.getElementById('item_name'), r = document.getElementById('reason');
    if (n.value) updateCounter('itemCounter', n.value.length, 200);
    if (r.value) updateCounter('reasonCounter', r.value.length, 1500);
    updateProgress();
})();

// Clear errors on input
['item_name','location','reason'].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input', function () {
        this.classList.remove('input-error');
        this.closest('.field')?.querySelector('.inline-err')?.remove();
    });
});
document.getElementById('my_department').addEventListener('change', function () {
    this.classList.remove('input-error');
    this.closest('.field')?.querySelector('.inline-err')?.remove();
});

<?php endif; ?>
</script>
<?php
$extraFoot = ob_get_clean();
require 'layout_end.php';
?>