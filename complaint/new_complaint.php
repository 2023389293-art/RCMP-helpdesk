<?php
// complaint/new_complaint.php (new) 
session_start();

$allowedRoles = ['student', 'lecturer', 'dept_handler', 'admin', 'super_admin', 'report_viewer', 'staff'];
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], $allowedRoles)) {
    header('Location: ../login.php');
    exit;
}

require '../db_connect.php';
require '../mail_helper.php';
require '../assign_helper.php';

// ── SESSION DATA ──────────────────────────────────────────────────────────────
$userName      = htmlspecialchars($_SESSION['user_name']  ?? 'User');
$userEmail     = htmlspecialchars($_SESSION['user_email'] ?? '');
$userRole      = $_SESSION['user_role'];
$userId        = (int)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? 0);
$submitterType = ($userRole === 'student') ? 'student' : 'staff';

// ── WORKING HOURS CHECK ───────────────────────────────────────────────────────
$myt          = new DateTimeZone('Asia/Kuala_Lumpur');
$now          = new DateTime('now', $myt);
$currentHour  = (int)$now->format('H');
$currentDow   = (int)$now->format('N');
$isWeekday    = ($currentDow >= 1 && $currentDow <= 5);
$isWithinTime = ($currentHour >= 8 && $currentHour < 17);
$isWorkingHours = ($isWeekday && $isWithinTime);

function calcSlaStart(DateTime $from): DateTime {
    $sla  = clone $from;
    $hour = (int)$sla->format('H');
    $dow  = (int)$sla->format('N');
    $isWorkday = ($dow >= 1 && $dow <= 5);
    $inHours   = ($hour >= 8 && $hour < 17);
    if ($isWorkday && $inHours) return $sla;
    if ($isWorkday && $hour >= 17) {
        $daysToAdd = ($dow == 5) ? 3 : 1;
    } elseif ($dow == 6) {
        $daysToAdd = 2;
    } elseif ($dow == 7) {
        $daysToAdd = 1;
    } else {
        $daysToAdd = 0;
    }
    if ($daysToAdd > 0) $sla->modify("+{$daysToAdd} days");
    $sla->setTime(8, 0, 0);
    return $sla;
}

$slaStartDt = calcSlaStart($now);
$slaDisplay = $slaStartDt->format('l, d M Y \a\t g:ia');

// ── BLOCK SUBMISSION OUTSIDE WORKING HOURS ────────────────────────────────────
// If a POST is attempted outside working hours, reject it immediately
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isWorkingHours) {
    // Do NOT process — just fall through with an error
    $error = 'Complaints can only be submitted during working hours (Monday – Friday, 8:00 AM – 5:00 PM MYT). Your submission has not been recorded.';
}

// ── FETCH MY DEPARTMENTS ──────────────────────────────────────────────────────
$myDepartments = [];
$deptRes = $conn->query("SELECT name FROM my_departments ORDER BY sort_order ASC");
if ($deptRes) {
    while ($row = $deptRes->fetch_assoc()) $myDepartments[] = $row['name'];
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

// ── FETCH CATEGORIES ─────────────────────────────────────────────────────────
$dbCategories  = [];
$deptLabelsMap = [];
$catRes = $conn->query("
    SELECT c.category_id, c.category_name, d.dept_id, d.dept_name
    FROM categories c
    JOIN departments d ON d.dept_id = c.dept_id
    ORDER BY d.dept_id ASC, c.category_id ASC
");
if ($catRes) {
    while ($row = $catRes->fetch_assoc()) {
        $dname = $row['dept_name'];
        if (!isset($dbCategories[$dname])) $dbCategories[$dname] = [];
        $dbCategories[$dname][] = ['id' => (int)$row['category_id'], 'name' => $row['category_name']];
        $deptLabelsMap[(int)$row['category_id']] = $dname;
    }
}

// ── HANDLE FORM SUBMISSION ────────────────────────────────────────────────────
$success  = false;
$error    = $error ?? '';
$ticketId = '';

// ── HANDLE REQUISITION SUBMISSION ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_type']) && $_POST['form_type'] === 'requisition' && $isWorkingHours) {
    $req_category      = trim($_POST['req_category']      ?? '');
    $req_item_name     = trim($_POST['req_item_name'] ?? '');
    $req_quantity      = (int)($_POST['req_quantity']     ?? 0);
    $req_my_department = trim($_POST['req_my_department'] ?? '');
    $req_location      = trim($_POST['req_location']      ?? '');
    $req_reason        = trim($_POST['req_reason']        ?? '');
    $req_phone         = trim($_POST['req_phone']         ?? '');
    $req_urgency       = trim($_POST['req_urgency']       ?? 'normal');

    $allowedUrgency = ['normal','urgent','critical'];
    $allowedReqCats = ['Office Furniture','Water Dispenser','Signage','Vending Machine','Office Keys','Office Equipment','Others'];

    if (empty($req_category) || !in_array($req_category, $allowedReqCats)) {
        $error = 'Please select a valid equipment category.';
    } elseif ($req_quantity < 1 || $req_quantity > 999) {
        $error = 'Quantity must be between 1 and 999.';
    } elseif (empty($req_my_department)) {
        $error = 'Please select your department.';
    } elseif (empty($req_item_name)) {
    $error = 'Please specify the item name or specification.';
} elseif (empty($req_location)) {
    $error = 'Please specify a location.';
    } elseif (empty($req_reason)) {
        $error = 'Please provide a justification.';
    } elseif (empty($req_phone)) {
        $error = 'Please enter your phone number.';
    } elseif (!in_array($req_urgency, $allowedUrgency)) {
        $error = 'Invalid urgency level.';
    } else {
$dateStr   = date('dmY');
$seqRes    = $conn->query("SELECT COUNT(*) AS cnt FROM requisitions WHERE DATE(created_at) = CURDATE()");
$seqRow    = $seqRes ? $seqRes->fetch_assoc() : ['cnt' => 0];
$seq       = ($seqRow['cnt'] ?? 0) + 1;
$refNumber = 'REQ-' . $dateStr . '-' . $seq;

        $attachmentPath = null;
        if (!empty($_FILES['req_attachment']['name'])) {
            $uploadDir = '../uploads/requisitions/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $ext     = strtolower(pathinfo($_FILES['req_attachment']['name'], PATHINFO_EXTENSION));
$allowed = ['jpg','jpeg','png','heic','heif','webp','pdf','doc','docx','txt'];
if (!in_array($ext, $allowed)) {
    $error = 'Invalid file type.';
} elseif ($_FILES['req_attachment']['size'] > 5 * 1024 * 1024) {
                $error = 'File too large. Max 5 MB.';
            } else {
                $filename       = $refNumber . '_' . time() . '.' . $ext;
                $attachmentPath = 'uploads/requisitions/' . $filename;
                move_uploaded_file($_FILES['req_attachment']['tmp_name'], $uploadDir . $filename);
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
            $stmt->bind_param("sisssssissss",
                $refNumber, $userId, $submitterType, $req_phone,
                $req_my_department, $req_category, $req_item_name, $req_quantity,
                $req_location, $req_reason, $req_urgency, $attachmentPath
            );
            if ($stmt->execute()) {
    $reqSuccess   = true;
    $reqRefNumber = $refNumber;

    // Email 1: HTML confirmation to the person who submitted
    sendRequisitionConfirmationEmail($userEmail, $userName, $refNumber, $req_category, $req_category, $req_quantity, $req_my_department, $req_location, $req_urgency, $now->format('d M Y, g:ia'));

    // Email 2: Notification to AFSMD staff
    sendRequisitionEmail($conn, $refNumber, $userName, $submitterType, $userEmail, $req_phone, $req_my_department, $req_category, $req_category, $req_quantity, $req_location, $req_reason, $req_urgency, $now->format('d M Y, g:ia'));
} else {
    $error = 'Failed to submit request: ' . $stmt->error;
}
            $stmt->close();
        }
    }
}
$reqSuccess   = $reqSuccess   ?? false;
$reqRefNumber = $reqRefNumber ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isWorkingHours && !isset($_POST['form_type'])) {
$category_id   = (int)($_POST['category_id'] ?? 0);
$description   = trim($_POST['description']  ?? '');
$phone         = trim($_POST['phone']         ?? '');
$my_department = trim($_POST['my_department'] ?? '');

if (empty($description) || $category_id === 0 || empty($phone) || empty($my_department)) {
    $error = 'Please fill in all required fields.';
    } else {
        $stmtCat = $conn->prepare("SELECT dept_id, category_name FROM categories WHERE category_id = ? LIMIT 1");
$stmtCat->bind_param("i", $category_id);
$stmtCat->execute();
$catRow = $stmtCat->get_result()->fetch_assoc();
$stmtCat->close();

if (!$catRow) {
    $error = 'Invalid category selected.';
} else {
    $dept_id = (int)$catRow['dept_id'];
    $title   = $catRow['category_name'] ?? 'Complaint #' . $category_id;

// Generate ticket ID
$dateStr  = date('dmY');
$dayRes   = $conn->query("SELECT COUNT(*) AS cnt FROM complaints WHERE DATE(created_at) = CURDATE()");
$dayRow   = $dayRes->fetch_assoc();
$seq      = ($dayRow['cnt'] ?? 0) + 1;
$ticketId = 'RCMP-' . $dateStr . '-' . $seq;

            // Handle file upload
            $attachmentPath = null;
            if (!empty($_FILES['attachment']['name'])) {
                $uploadDir = '../uploads/complaints/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $ext     = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg','jpeg','png','heic','heif','webp','pdf','doc','docx','txt'];
$maxSize = 5 * 1024 * 1024;
                if (!in_array($ext, $allowed)) {
                    $error = 'Invalid file type.';
                } elseif ($_FILES['attachment']['size'] > $maxSize) {
                    $error = 'File too large. Max 5 MB.';
                } else {
                    $filename       = $ticketId . '_' . time() . '.' . $ext;
                    $attachmentPath = 'uploads/complaints/' . $filename;
                    move_uploaded_file($_FILES['attachment']['tmp_name'], $uploadDir . $filename);
                }
            }

            if (empty($error)) {
                $submitNow    = new DateTime('now', $myt);
                $slaAtSubmit  = calcSlaStart($submitNow);
                $slaInsertStr = $slaAtSubmit->format('Y-m-d H:i:s');
                $defaultPriority = 'medium';

                $stmt = $conn->prepare("
                    INSERT INTO complaints
                        (ticket_id, submitter_id, submitter_type, phone, my_department,
                         category_id, dept_id, title, description,
                         attachment_path, status, priority, assigned_to, sla_start_at, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', ?, NULL, ?, NOW(), NOW())
                ");
                $stmt->bind_param(
                    "sisssiisssss",
                    $ticketId,
                    $userId,
                    $submitterType,
                    $phone,
                    $my_department,
                    $category_id,
                    $dept_id,
                    $title,
                    $description,
                    $attachmentPath,
                    $defaultPriority,
                    $slaInsertStr
                );

                if ($stmt->execute()) {
                    autoAssignTicket($conn, $dept_id, $ticketId);
                    $success = true;
                    sendComplaintEmail($conn, $dept_id, $ticketId);
                } else {
                    $error = 'Failed to submit complaint: ' . $stmt->error;
                }
                $stmt->close();
            }
        }
    }
}

$prevCategoryId = (int)($_POST['category_id'] ?? 0);
$prevDeptGroup  = '';
if ($prevCategoryId && isset($deptLabelsMap[$prevCategoryId])) {
    $prevDeptGroup = $deptLabelsMap[$prevCategoryId];
}

$pageTitle    = 'New Complaint';
$pageSubtitle = date('l, d F Y');
$activeNav    = 'new_complaint';

ob_start();
?>
<style>
  /* ── EXISTING STYLES (unchanged) ─────────────────────────── */
  .form-progress-bar{position:fixed;top:0;left:var(--sidebar-w);right:0;height:3px;background:var(--g100);z-index:500;}
  .form-progress-fill{height:100%;width:0%;background:linear-gradient(90deg,var(--blue),#4A90D9);border-radius:0 2px 2px 0;transition:width 0.4s cubic-bezier(0.4,0,0.2,1);}
  .content{flex:1;width:100%;max-width:100%;padding:36px 48px;display:flex;flex-direction:column;align-items:center;box-sizing:border-box;}
  .page-header{width:100%;max-width:820px;margin-bottom:28px;display:flex;flex-direction:column;align-items:flex-start;align-self:flex-start;}
  .breadcrumb{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--g500);margin-bottom:8px;}
  .breadcrumb a{color:var(--g500);text-decoration:none;}
  .breadcrumb a:hover{color:var(--blue);}
  .breadcrumb-sep{color:var(--g300);}
  .page-header h1{font-family:'DM Serif Display',serif;font-size:25px;color:var(--g900);line-height:1.15;}
  .page-header p{font-size:14px;color:var(--g500);margin-top:6px;font-weight:300;}
  .tp-back-btn{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border-radius:9px;border:0.5px solid var(--g200);background:white;color:var(--g700);font-size:13px;font-weight:500;text-decoration:none;transition:border-color .2s,background .2s,color .2s;font-family:'DM Sans',sans-serif;}
  .tp-back-btn:hover{border-color:#2d5986;background:#2d5986;color:#fff;}
  .tp-back-btn svg{width:14px;height:14px;fill:none;stroke:currentColor;stroke-width:2;}
  .steps-row{display:flex;align-items:stretch;width:100%;max-width:960px;margin-bottom:28px;background:white;border:1px solid var(--g300);border-radius:14px;overflow:hidden;}
  .step{flex:1;display:flex;align-items:center;gap:12px;padding:18px 24px;position:relative;transition:background 0.25s;}
  .step:not(:last-child)::after{content:'';position:absolute;right:0;top:50%;transform:translateY(-50%);width:1px;height:40%;background:var(--g300);}
  .step.active{background:color-mix(in srgb, var(--active-tab-color, var(--blue)) 10%, white);}
  .step.done{background:#f0f8eb;}
  .step-num{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:600;flex-shrink:0;background:var(--g100);color:var(--g500);border:1.5px solid var(--g300);transition:all 0.25s;}
  .step.active .step-num{background:var(--active-tab-color, var(--blue));color:white;border-color:var(--active-tab-color, var(--blue));}
  .step.done .step-num{background:#4CAF50;color:white;border-color:#4CAF50;}
  .step-label{font-size:13px;font-weight:500;color:var(--g500);}
  .step.active .step-label{color:var(--active-tab-color, var(--blue));}
  .step.done .step-label{color:#3B6D11;}
  .step-sub{font-size:11px;color:var(--g500);margin-top:1px;}
  .wh-banner{display:flex;align-items:flex-start;gap:14px;width:100%;max-width:960px;padding:16px 20px;border-radius:12px;margin-bottom:20px;font-size:13px;line-height:1.6;animation:fadeSlideIn 0.35s ease;}
  .wh-banner.wh-open{background:#e8f5e9;border:1px solid #a5d6a7;color:#1b5e20;}
  .wh-banner.wh-closed{background:#fff8e1;border:1px solid #ffe082;color:#5d4037;}
  .wh-banner-icon{width:38px;height:38px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;}
  .wh-open .wh-banner-icon{background:#c8e6c9;}
  .wh-closed .wh-banner-icon{background:#ffe0b2;}
  .wh-banner-icon svg{width:20px;height:20px;fill:none;stroke-width:2;}
  .wh-open .wh-banner-icon svg{stroke:#2e7d32;}
  .wh-closed .wh-banner-icon svg{stroke:#e65100;}
  .wh-banner-body strong{display:block;font-size:14px;font-weight:600;margin-bottom:3px;}
  .wh-open .wh-banner-body strong{color:#1b5e20;}
  .wh-closed .wh-banner-body strong{color:#bf360c;}
  .wh-hours-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;}
  .wh-chip{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;padding:3px 10px;border-radius:100px;letter-spacing:0.02em;}
  .wh-open .wh-chip{background:#a5d6a7;color:#1b5e20;}
  .wh-closed .wh-chip{background:#ffcc80;color:#6d3200;}
  .wh-sla-row{margin-top:10px;padding:8px 12px;border-radius:8px;font-size:12px;font-weight:500;display:flex;align-items:center;gap:7px;}
  .wh-open .wh-sla-row{background:#c8e6c9;color:#1b5e20;}
  .wh-closed .wh-sla-row{background:#ffe0b2;color:#6d3200;}
  .wh-sla-row svg{width:13px;height:13px;fill:none;stroke:currentColor;stroke-width:2;flex-shrink:0;}
  .alert{display:flex;align-items:flex-start;gap:12px;padding:14px 18px;border-radius:10px;font-size:13px;margin-bottom:24px;line-height:1.5;width:100%;max-width:820px;animation:fadeSlideIn 0.3s ease;}
  .alert svg{width:18px;height:18px;flex-shrink:0;margin-top:1px;}
  .alert-error{background:#FDECEA;color:#B71C1C;border:1px solid rgba(183,28,28,0.15);}
  .alert-error svg{fill:none;stroke:#B71C1C;stroke-width:2;}
  #complaintForm{width:100%;max-width:960px;background:transparent;padding:0;box-sizing:border-box;}
  .complaint-section-wrapper{width:100%;max-width:960px;border:2px solid #e2e8f0;border-radius:20px;padding:24px 24px 24px 24px;box-sizing:border-box;background:white;transition:border-color 0.3s ease;}
.form-card{background:white;border-radius:18px;border:1px solid #e2e8f0;overflow:hidden;box-shadow:none;}
  .form-card-header{padding:26px 32px 22px;border-bottom:1px solid var(--g100);display:flex;align-items:center;gap:14px;background:linear-gradient(to right,#f7f9ff,#ffffff);}
  .fch-icon{width:44px;height:44px;border-radius:12px;background:var(--blue-light);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
  .fch-icon svg{width:22px;height:22px;fill:none;stroke:var(--blue);stroke-width:1.8;}
  .fch-text h3{font-family:'DM Serif Display',serif;font-size:19px;color:var(--g900);}
  .fch-text p{font-size:13px;color:var(--g500);margin-top:2px;}
  .form-body{padding:32px 32px 24px;}
  .form-section-label{font-size:11px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:var(--g500);margin-bottom:16px;margin-top:4px;padding-bottom:8px;border-bottom:1px solid var(--g100);}
  .field-grid{display:grid;grid-template-columns:1fr 1fr;gap:0 20px;}
  @media(max-width:600px){.field-grid{grid-template-columns:1fr;}}
  .field{margin-bottom:22px;}
  .field label{display:flex;align-items:center;gap:6px;font-size:13px;font-weight:500;color:var(--g700);margin-bottom:8px;}
  .field label .req{color:#E53935;}
  .field label .opt{font-size:11px;color:var(--g500);font-weight:400;background:var(--g100);padding:2px 7px;border-radius:100px;}
  .field input[type="text"],.field input[type="tel"],.field select,.field textarea{width:100%;padding:12px 16px;border:1.5px solid var(--g300);border-radius:10px;font-family:'DM Sans',sans-serif;font-size:14px;color:var(--g900);background:white;transition:border-color 0.2s,box-shadow 0.2s;outline:none;appearance:none;box-sizing:border-box;}
  .field input:focus,.field select:focus,.field textarea:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(0,59,142,0.08);}
  .field input::placeholder,.field textarea::placeholder{color:var(--g300);}
  .field textarea{resize:vertical;min-height:140px;line-height:1.65;}
  .field select.placeholder-active{color:var(--g700);}
  .phone-wrap{display:flex;}
  .phone-prefix{padding:12px 14px;background:var(--g100);border:1.5px solid var(--g300);border-right:none;border-radius:10px 0 0 10px;font-size:14px;color:var(--g700);font-weight:500;white-space:nowrap;display:flex;align-items:center;flex-shrink:0;}
  .phone-wrap input[type="tel"]{border-radius:0 10px 10px 0 !important;}
.phone-wrap input[type="tel"].input-error{border-color:#E53935 !important;box-shadow:0 0 0 3px rgba(229,57,53,0.10);}
.phone-wrap input[type="tel"].input-ok{border-color:#43A047 !important;box-shadow:0 0 0 3px rgba(67,160,71,0.10);}
.phone-hint{font-size:11px;color:var(--g500);margin-top:5px;}
.phone-hint.hint-error{color:#E53935;}
.phone-hint.hint-ok{color:#43A047;}
  .select-wrap{position:relative;}
  .select-wrap::after{content:'';position:absolute;right:14px;top:50%;width:10px;height:10px;pointer-events:none;border-right:2px solid var(--g500);border-bottom:2px solid var(--g500);transform:translateY(-65%) rotate(45deg);}
  .select-wrap select{padding-right:36px;cursor:pointer;}
  .select-wrap:has(select:disabled)::after{display:none;}
  .select-wrap select:disabled{padding-right:16px;}
  .category-two-step{display:grid;grid-template-columns:1fr 1fr;gap:12px;align-items:start;}
  @media(max-width:600px){.category-two-step{grid-template-columns:1fr;}}
  .cat-step-block{display:flex;flex-direction:column;gap:6px;}
  .cat-step-label{font-size:11px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;color:var(--g500);}
  #subcategory_select:disabled{background:var(--g100);color:var(--g500);cursor:not-allowed;opacity:1;}
  .subcategory-reveal{transition:opacity 0.25s ease,transform 0.25s ease;opacity:0.45;transform:translateX(4px);}
  .subcategory-reveal.active{opacity:1;transform:translateX(0);}
  .dept-hint{display:none;align-items:center;gap:8px;margin-top:8px;padding:8px 12px;background:var(--blue-light);border:1px solid rgba(0,59,142,0.15);border-radius:8px;font-size:12px;color:var(--blue);font-weight:500;grid-column:1/-1;}
  .dept-hint svg{width:14px;height:14px;fill:none;stroke:var(--blue);stroke-width:2;}
  .dept-hint.show{display:flex;}
  .file-drop{border:2px dashed var(--g300);border-radius:10px;padding:32px 24px;text-align:center;cursor:pointer;transition:border-color 0.2s,background 0.2s;position:relative;overflow:hidden;}
  .file-drop:hover,.file-drop.dragover{border-color:var(--blue-mid);background:var(--blue-light);}
  .file-drop input[type="file"]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;}
  .file-icon{width:44px;height:44px;border-radius:12px;background:var(--g100);margin:0 auto 12px;display:flex;align-items:center;justify-content:center;}
  .file-icon svg{width:20px;height:20px;fill:none;stroke:var(--g500);stroke-width:1.8;}
  .file-drop-title{font-size:14px;font-weight:500;color:var(--g700);margin-bottom:4px;}
  .file-drop-sub{font-size:12px;color:var(--g500);}
  .file-drop-sub strong{color:var(--blue);}
  .file-selected{display:none;align-items:center;gap:10px;padding:10px 14px;background:var(--blue-light);border:1px solid rgba(0,59,142,0.15);border-radius:8px;margin-top:10px;font-size:13px;color:var(--blue);}
  .file-selected svg{width:14px;height:14px;fill:none;stroke:var(--blue);stroke-width:2;}
  .file-remove{margin-left:auto;cursor:pointer;color:var(--g500);background:none;border:none;font-size:18px;line-height:1;padding:0;}
  .file-remove:hover{color:#E53935;}
  .field-footer{display:flex;align-items:center;justify-content:flex-end;margin-top:6px;}
  .char-counter{font-size:11px;color:var(--g500);}
  .char-counter.warn{color:#FF9800;}
  .char-counter.danger{color:#E53935;}
  .form-actions{padding:20px 32px;border-top:1px solid var(--g100);display:flex;align-items:center;justify-content:space-between;gap:12px;background:var(--off);}
  .btn-cancel{font-family:'DM Sans',sans-serif;font-size:14px;font-weight:500;padding:11px 22px;border-radius:9px;border:1.5px solid var(--g300);background:white;color:var(--g700);cursor:pointer;text-decoration:none;transition:border-color 0.2s,color 0.2s;}
  .btn-cancel:hover{border-color:var(--g500);color:var(--g900);}
  .btn-submit{font-family:'DM Sans',sans-serif;font-size:14px;font-weight:600;padding:12px 32px;border-radius:10px;background:var(--blue);color:white;border:none;cursor:pointer;display:flex;align-items:center;gap:9px;letter-spacing:0.01em;box-shadow:0 4px 14px rgba(0,59,142,0.25);transition:background 0.2s,transform 0.15s,box-shadow 0.2s;}
  .btn-submit:hover{background:var(--blue-dark);transform:translateY(-1px);box-shadow:0 6px 20px rgba(0,59,142,0.35);}
  .btn-submit svg{width:16px;height:16px;fill:none;stroke:white;stroke-width:2;}

  /* ── OUTSIDE HOURS: locked form overlay ──────────────────── */
  .form-locked-overlay{
    position:absolute;inset:0;z-index:20;
    background:rgba(255,255,255,0.82);
    backdrop-filter:blur(3px);
    -webkit-backdrop-filter:blur(3px);
    border-radius:20px;
    display:flex;flex-direction:column;align-items:center;justify-content:center;
    text-align:center;padding:40px 32px;
    animation:fadeSlideIn 0.35s ease;
  }
  .flo-icon{width:64px;height:64px;border-radius:50%;background:#fff3e0;border:2px solid #ffcc80;display:flex;align-items:center;justify-content:center;margin-bottom:18px;flex-shrink:0;}
  .flo-icon svg{width:28px;height:28px;fill:none;stroke:#e65100;stroke-width:2;}
  .flo-title{font-family:'DM Serif Display',serif;font-size:20px;color:#bf360c;margin-bottom:8px;}
  .flo-body{font-size:13px;color:#5d4037;line-height:1.65;max-width:420px;margin-bottom:16px;}
  .flo-hours{display:inline-flex;align-items:center;gap:8px;background:#fff8e1;border:1px solid #ffe082;border-radius:10px;padding:10px 18px;font-size:13px;font-weight:600;color:#6d3200;margin-bottom:16px;}
  .flo-hours svg{width:15px;height:15px;fill:none;stroke:#e65100;stroke-width:2;}
  .flo-next{font-size:12px;color:#795548;background:#ffe0b2;border-radius:8px;padding:8px 14px;display:inline-flex;align-items:center;gap:7px;}
  .flo-next svg{width:13px;height:13px;fill:none;stroke:#bf360c;stroke-width:2;flex-shrink:0;}
  /* Disable all inputs when outside hours */
  .form-locked-wrap .form-card{pointer-events:none;user-select:none;}
  /* Locked submit button style */
  .btn-submit-locked{font-family:'DM Sans',sans-serif;font-size:14px;font-weight:600;padding:12px 32px;border-radius:10px;background:#bdbdbd;color:white;border:none;cursor:not-allowed;display:flex;align-items:center;gap:9px;letter-spacing:0.01em;}
  .btn-submit-locked svg{width:16px;height:16px;fill:none;stroke:white;stroke-width:2;}

  #previewPanel{display:none;width:100%;max-width:960px;}
  #previewPanel.show{display:block;}
  .preview-card{background:white;border-radius:20px;border:1px solid var(--g300);overflow:hidden;box-shadow:0 4px 6px -1px rgba(0,0,0,0.06),0 10px 40px -8px rgba(0,59,142,0.10);}
  .preview-card-header{padding:26px 32px 22px;border-bottom:1px solid var(--g100);display:flex;align-items:center;gap:14px;background:linear-gradient(to right,#f7fff7,#ffffff);}
  .pch-icon{width:44px;height:44px;border-radius:12px;background:#e8f5e9;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
  .pch-icon svg{width:22px;height:22px;fill:none;stroke:#2e7d32;stroke-width:1.8;}
  .pch-text h3{font-family:'DM Serif Display',serif;font-size:19px;color:var(--g900);}
  .pch-text p{font-size:13px;color:var(--g500);margin-top:2px;}
  .preview-body{padding:28px 32px;}
  .preview-section{margin-bottom:28px;}
  .preview-section-title{font-size:11px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:var(--g500);margin-bottom:14px;padding-bottom:8px;border-bottom:1px solid var(--g100);}
  .preview-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px 24px;}
  @media(max-width:600px){.preview-grid{grid-template-columns:1fr;}}
  .preview-field{display:flex;flex-direction:column;gap:4px;}
  .preview-field.full{grid-column:1/-1;}
  .preview-label{font-size:11px;font-weight:600;color:var(--g500);letter-spacing:0.04em;text-transform:uppercase;}
  .preview-value{font-size:14px;color:var(--g900);font-weight:400;background:var(--g100);border-radius:8px;padding:10px 14px;line-height:1.5;word-break:break-word;}
  .preview-value.description-val{white-space:pre-wrap;min-height:80px;}
  .preview-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:100px;background:var(--blue-light);color:var(--blue);font-size:12px;font-weight:600;border:1px solid rgba(0,59,142,0.15);}
  .preview-dept-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:100px;background:#e8f5e9;color:#2e7d32;font-size:12px;font-weight:600;border:1px solid #c8e6c9;}
  .preview-notice{display:flex;align-items:flex-start;gap:10px;padding:12px 16px;border-radius:10px;background:#fff8e1;border:1px solid #ffe082;font-size:13px;color:#795548;line-height:1.5;margin-top:20px;}
  .preview-notice svg{width:16px;height:16px;fill:none;stroke:#F9A825;stroke-width:2;flex-shrink:0;margin-top:1px;}
  .preview-sla-row{display:flex;align-items:flex-start;gap:10px;padding:12px 16px;border-radius:10px;font-size:13px;line-height:1.5;margin-top:10px;}
  .preview-sla-row.sla-open{background:#e8f5e9;border:1px solid #a5d6a7;color:#1b5e20;}
  .preview-sla-row.sla-closed{background:#fff8e1;border:1px solid #ffe082;color:#795548;}
  .preview-sla-row svg{width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:2;flex-shrink:0;margin-top:1px;}
  .preview-actions{padding:20px 32px;border-top:1px solid var(--g100);display:flex;align-items:center;justify-content:space-between;gap:12px;background:var(--off);}
  .btn-back{font-family:'DM Sans',sans-serif;font-size:14px;font-weight:500;padding:11px 22px;border-radius:9px;border:1.5px solid var(--g300);background:white;color:var(--g700);cursor:pointer;display:flex;align-items:center;gap:8px;text-decoration:none;transition:border-color 0.2s,color 0.2s;}
  .btn-back:hover{border-color:var(--g500);color:var(--g900);}
  .btn-back svg{width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:2;}
  .btn-confirm{font-family:'DM Sans',sans-serif;font-size:14px;font-weight:600;padding:12px 32px;border-radius:10px;background:#2e7d32;color:white;border:none;cursor:pointer;display:flex;align-items:center;gap:9px;box-shadow:0 4px 14px rgba(46,125,50,0.25);transition:background 0.2s,transform 0.15s;}
  .btn-confirm:hover{background:#1b5e20;transform:translateY(-1px);}
  .btn-confirm svg{width:16px;height:16px;fill:none;stroke:white;stroke-width:2;}
  .file-preview-val{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--blue);font-weight:500;}
  .no-file{color:#bbb;font-style:italic;}
  .success-overlay{display:none;position:fixed;inset:0;z-index:999;background:rgba(10,20,50,0.65);backdrop-filter:blur(6px);align-items:center;justify-content:center;}
  .success-overlay.show{display:flex;}
  .success-card{background:white;border-radius:20px;padding:48px 44px;max-width:460px;width:90%;text-align:center;animation:scaleIn 0.4s cubic-bezier(0.34,1.56,0.64,1);box-shadow:0 24px 80px rgba(0,0,0,0.18);}
  .success-icon-wrap{width:72px;height:72px;border-radius:50%;background:linear-gradient(135deg,#43A047,#66BB6A);margin:0 auto 20px;display:flex;align-items:center;justify-content:center;box-shadow:0 8px 24px rgba(67,160,71,0.3);}
  .success-icon-wrap svg{width:32px;height:32px;fill:none;stroke:white;stroke-width:2.5;}
  .success-card h2{font-family:'DM Serif Display',serif;font-size:26px;color:var(--g900);margin-bottom:10px;}
  .success-card p{font-size:14px;color:var(--g500);line-height:1.65;font-weight:300;margin-bottom:20px;}
  .success-ticket{background:var(--blue-light);border:1px solid rgba(0,59,142,0.15);border-radius:10px;padding:12px 20px;margin-bottom:16px;}
  .success-ticket .label{font-size:11px;text-transform:uppercase;letter-spacing:0.07em;color:var(--blue);font-weight:600;margin-bottom:4px;}
  .success-ticket .tid{font-family:monospace;font-size:20px;font-weight:700;color:var(--g900);letter-spacing:0.05em;}
  .success-sla{display:flex;align-items:flex-start;gap:10px;padding:10px 14px;border-radius:10px;margin-bottom:20px;font-size:12px;line-height:1.55;text-align:left;}
  .success-sla.sla-now{background:#e8f5e9;border:1px solid #a5d6a7;color:#1b5e20;}
  .success-sla.sla-queued{background:#fff8e1;border:1px solid #ffe082;color:#795548;}
  .success-sla svg{width:14px;height:14px;fill:none;stroke:currentColor;stroke-width:2;flex-shrink:0;margin-top:1px;}
  .success-actions{display:flex;gap:10px;justify-content:center;}
  .btn-ghost{font-family:'DM Sans',sans-serif;font-size:13px;font-weight:500;padding:10px 20px;border-radius:8px;border:1.5px solid var(--g300);background:white;color:var(--g700);cursor:pointer;text-decoration:none;transition:border-color 0.2s;}
  .btn-ghost:hover{border-color:var(--g500);}
  .btn-primary-sm{font-family:'DM Sans',sans-serif;font-size:13px;font-weight:500;padding:10px 20px;border-radius:8px;background:var(--blue);color:white;border:none;cursor:pointer;text-decoration:none;transition:background 0.2s;}
  .btn-primary-sm:hover{background:var(--blue-dark);}
  @keyframes fadeSlideIn{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
  @keyframes fadeIn{from{opacity:0}to{opacity:1}}
  @keyframes scaleIn{from{opacity:0;transform:scale(0.85)}to{opacity:1;transform:scale(1)}}
  @keyframes spin{to{transform:rotate(360deg)}}
  @keyframes panelIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
  #previewPanel.show{animation:panelIn 0.3s ease;}
  @media(max-width:1100px){.content{padding:28px 32px;}}
  @media(max-width:768px){
    .content{padding:20px 16px;align-items:stretch;}
    .page-header,.dept-tabbar,.dept-page-title,.steps-row,.alert,.wh-banner,#complaintForm,#previewPanel,.form-locked-wrap{max-width:100%;}
    .form-body,.preview-body{padding:20px 18px;}
    .form-card-header,.preview-card-header{padding:20px 18px 16px;}
    .form-actions,.preview-actions{padding:16px 18px;flex-wrap:wrap;}
    .steps-row{overflow-x:auto;}
    .step-sub{display:none;}
  }
  .preview-urg-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:100px;font-size:12px;font-weight:600;border:1px solid;}
.urg-normal{background:#EFF6FF;color:#1D4ED8;border-color:#93C5FD;}
.urg-urgent{background:#FEF3E2;color:#92520C;border-color:#FCD34D;}
.urg-critical{background:#FDECEA;color:#B71C1C;border-color:#FCA5A5;}
.urg-option{display:none;}
.urg-label{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border-radius:100px;border:1.5px solid var(--g300);background:white;font-size:13px;font-weight:500;color:var(--g700);cursor:pointer;transition:all .18s;}
.urg-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;}
.urg-option[value="normal"]:checked+.urg-label{background:#EFF6FF;color:#1D4ED8;border-color:#93C5FD;}
.urg-option[value="urgent"]:checked+.urg-label{background:#FEF3E2;color:#92520C;border-color:#FCD34D;}
.urg-option[value="critical"]:checked+.urg-label{background:#FDECEA;color:#B71C1C;border-color:#FCA5A5;}
.urg-option[value="normal"]+.urg-label .urg-dot{background:#3B82F6;}
.urg-option[value="urgent"]+.urg-label .urg-dot{background:#F59E0B;}
.urg-option[value="critical"]+.urg-label .urg-dot{background:#EF4444;}
.cat-option{display:none;}
.cat-label{display:flex;flex-direction:column;align-items:flex-start;gap:8px;padding:16px;border-radius:14px;border:1.5px solid var(--g300);background:white;cursor:pointer;transition:all .2s;position:relative;}
.cat-label:hover{border-color:#0D9488;box-shadow:0 4px 16px rgba(13,148,136,.12);}
.cat-option:checked+.cat-label{border-color:#0D9488;background:#f0fdfa;box-shadow:0 4px 20px rgba(13,148,136,.15);}
.cat-option:checked+.cat-label .cat-check{opacity:1;}
.cat-icon{width:40px;height:40px;border-radius:10px;background:#f4f6fb;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.cat-name{font-size:13px;font-weight:600;color:var(--g900);line-height:1.2;}
.cat-desc{font-size:11px;color:var(--g500);line-height:1.4;margin-top:2px;}
.cat-check{position:absolute;top:10px;right:10px;width:18px;height:18px;border-radius:50%;background:#0D9488;display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .2s;}
.cat-check svg{width:10px;height:10px;fill:none;stroke:white;stroke-width:3;}

/* ── DEPARTMENT TAB BAR ─────────────────────────────────────── */
.dept-tabbar{width:100%;max-width:960px;margin-bottom:16px;}
.dept-tabbar-header{display:none;}
.dept-tabbar-title{display:none;}
.dept-tabs{display:flex;overflow-x:auto;scrollbar-width:none;padding:4px 0;gap:8px;}
.dept-tabs::-webkit-scrollbar{display:none;}
.dept-tab{flex:1;min-width:90px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:7px;padding:12px 8px 12px;font-family:'DM Sans',sans-serif;font-size:10px;font-weight:700;letter-spacing:0.05em;text-transform:uppercase;color:var(--g500);cursor:pointer;border-radius:12px;border:1.5px solid var(--g200);background:white;text-align:center;line-height:1.3;transition:all 0.2s;box-shadow:0 1px 4px rgba(0,0,0,0.06);}
.dept-tab:hover{color:#1a3a5c;border-color:var(--g200);background:#f8f9fb;box-shadow:none;border:2.5px solid var(--g200);}
.dept-tab.active{color:#1a3a5c;border-color:var(--g200);background:#eef2f9;box-shadow:none;border:2.5px solid var(--g200);}
.dept-tab-icon{display:flex;align-items:center;justify-content:center;width:42px;height:42px;border-radius:10px;background:transparent;transition:background 0.2s;}
.dept-tab:hover .dept-tab-icon{background:transparent;}
.dept-tab.active .dept-tab-icon{background:transparent;}

/* ── Per-department hover & active colors ── */
/* Information Technology — blue */
.dept-tab:nth-child(1):hover,
.dept-tab:nth-child(1).active{border:2.5px solid #2563EB !important;color:#1e40af;background:#f0f5ff;}
.dept-tab:nth-child(1):hover .dept-tab-icon,
.dept-tab:nth-child(1).active .dept-tab-icon{background:transparent;}
.dept-tab:nth-child(1):hover svg,
.dept-tab:nth-child(1).active svg{stroke:#2563EB;}

/* Maintenance — green */
.dept-tab:nth-child(2):hover,
.dept-tab:nth-child(2).active{border:2.5px solid #16A34A !important;color:#15803d;background:#f0fdf4;}
.dept-tab:nth-child(2):hover .dept-tab-icon,
.dept-tab:nth-child(2).active .dept-tab-icon{background:transparent;}
.dept-tab:nth-child(2):hover svg,
.dept-tab:nth-child(2).active svg{stroke:#16A34A;}

/* Admin & Facilities — orange */
.dept-tab:nth-child(3):hover,
.dept-tab:nth-child(3).active{border:2.5px solid #EA580C !important;color:#c2410c;background:#fff7ed;}
.dept-tab:nth-child(3):hover .dept-tab-icon,
.dept-tab:nth-child(3).active .dept-tab-icon{background:transparent;}
.dept-tab:nth-child(3):hover svg,
.dept-tab:nth-child(3).active svg{stroke:#EA580C;}

/* Corporate Communication — purple */
.dept-tab:nth-child(4):hover,
.dept-tab:nth-child(4).active{border:2.5px solid #7C3AED !important;color:#6d28d9;background:#faf5ff;}
.dept-tab:nth-child(4):hover .dept-tab-icon,
.dept-tab:nth-child(4).active .dept-tab-icon{background:transparent;}
.dept-tab:nth-child(4):hover svg,
.dept-tab:nth-child(4).active svg{stroke:#7C3AED;}

/* Human Capital — rose/pink */
.dept-tab:nth-child(5):hover,
.dept-tab:nth-child(5).active{border:2.5px solid #E11D48 !important;color:#be123c;background:#fff1f2;}
.dept-tab:nth-child(5):hover .dept-tab-icon,
.dept-tab:nth-child(5).active .dept-tab-icon{background:transparent;}
.dept-tab:nth-child(5):hover svg,
.dept-tab:nth-child(5).active svg{stroke:#E11D48;}
.dept-tab-label{max-width:120px;word-break:break-word;}
.dept-form-header{display:none;}
.dept-page-title{width:100%;max-width:960px;margin-bottom:20px;padding-top:4px;}
.dept-page-title h2{font-family:'DM Serif Display',serif;font-size:22px;color:var(--g900);margin:0 0 4px 0;font-weight:400;}
.dept-page-title p{font-size:13px;color:var(--g500);margin:0;font-weight:300;}
@media(max-width:768px){
  .dept-tabs{gap:6px;padding:4px 0;}
  .dept-tab{min-width:72px;font-size:9px;padding:10px 6px 10px;}
  .dept-tab-icon{width:36px;height:36px;}
  .dept-tab-icon svg{width:18px;height:18px;}
  .dept-tabbar{max-width:100%;}
  .dept-page-title{max-width:100%;}
}

.dept-tab-disabled {
  opacity: 0.4 !important;
  cursor: not-allowed !important;
  pointer-events: none !important;
}

</style>
<?php
$extraHead = ob_get_clean();
require 'layout.php';
?>

<div class="form-progress-bar"><div class="form-progress-fill" id="progressFill"></div></div>

<div class="page-header">
  <div class="breadcrumb" style="margin-top:12px;">
    <a href="homepage.php">Dashboard</a>
    <span class="breadcrumb-sep">›</span>
    <span>New Complaint</span>
  </div>
  <a href="homepage.php" class="tp-back-btn" style="margin-top:10px;">
    <svg viewBox="0 0 24 24"><polyline points="15,18 9,12 15,6"/></svg>
    Back to Dashboard
  </a>
</div>

<!-- ── DEPARTMENT TAB BAR ── -->
<div class="dept-tabbar">
  <div class="dept-tabbar-header">
    <div class="dept-tabbar-title" id="deptTabbarTitle">Choose Department</div>
  </div>
  <div class="dept-tabs" id="deptTabs">

    <button class="dept-tab active" data-dept="Information Technology Department" type="button">
      <span class="dept-tab-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="22" height="22">
          <rect x="2" y="3" width="20" height="14" rx="2"/>
          <line x1="8" y1="21" x2="16" y2="21"/>
          <line x1="12" y1="17" x2="12" y2="21"/>
        </svg>
      </span>
      <span class="dept-tab-label">Information Technology</span>
    </button>

    <button class="dept-tab" data-dept="Maintenance Department" type="button">
      <span class="dept-tab-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="22" height="22">
          <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>
        </svg>
      </span>
      <span class="dept-tab-label">Maintenance</span>
    </button>

    <button class="dept-tab" data-dept="Administration &amp; Facilities Management Department" type="button">
      <span class="dept-tab-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="22" height="22">
          <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
          <polyline points="9 22 9 12 15 12 15 22"/>
        </svg>
      </span>
      <span class="dept-tab-label">Admin &amp; Facilities</span>
    </button>

    <button class="dept-tab dept-tab-disabled" data-dept="Corporate Communication Unit" type="button" disabled onclick="return false;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="22" height="22">
          <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
        </svg>
      </span>
      <span class="dept-tab-label">Corporate Communication</span>
    </button>

    <button class="dept-tab dept-tab-disabled" data-dept="Human Capital Department" type="button" disabled onclick="return false;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="22" height="22">
          <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
          <circle cx="9" cy="7" r="4"/>
          <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
          <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
        </svg>
      </span>
      <span class="dept-tab-label">Human Capital</span>
    </button>

  </div>
</div>

<!-- ── AFSMD SUB-TABS ── -->
<div id="afsmdSubTabs" style="display:none;width:100%;max-width:960px;margin-bottom:16px;margin-top:12px;">
  <div style="display:flex;justify-content:center;">
    <div style="display:inline-flex;background:#f1f5f9;border-radius:14px;padding:4px;gap:2px;border:2px solid #cbd5e1;">

      <button type="button" id="subTabComplaint" onclick="switchAfsmdTab('complaint')"
        style="display:inline-flex;align-items:center;gap:7px;
               padding:8px 18px;border-radius:10px;border:none;
               cursor:pointer;font-family:'DM Sans',sans-serif;
               background:white;color:#1a3a5c;
               box-shadow:0 1px 4px rgba(0,0,0,0.10);font-size:13px;font-weight:600;
               transition:all .2s;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
          <polyline points="14 2 14 8 20 8"/>
          <line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>
        </svg>
        Submit Complaint
      </button>

      <button type="button" id="subTabRequisition" disabled
        style="display:inline-flex;align-items:center;gap:7px;
               padding:8px 18px;border-radius:10px;border:none;
               cursor:not-allowed;font-family:'DM Sans',sans-serif;
               background:transparent;color:#cbd5e1;
               box-shadow:none;font-size:13px;font-weight:600;
               transition:all .2s;opacity:0.5;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M20 7H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/>
          <path d="M16 3H8a1 1 0 0 0-1 1v3h10V4a1 1 0 0 0-1-1z"/>
          <line x1="12" y1="12" x2="12" y2="16"/><line x1="10" y1="14" x2="14" y2="14"/>
        </svg>
        Request Equipment
      </button>

    </div>
  </div>
</div>

<div id="afsmdComplaintSection">
<div class="complaint-section-wrapper" id="complaintSectionWrapper">
<div class="dept-page-title"> <br>
  <h2>Submit a Complaint</h2>
  <p>Fill in the details below and the relevant department will attend to your request.</p>
</div>

<div class="steps-row">
  <div class="step active" id="step1">
    <div class="step-num">1</div>
    <div><div class="step-label">Fill Details</div><div class="step-sub">Describe your issue</div></div>
  </div>
  <div class="step" id="step2">
    <div class="step-num">2</div>
    <div><div class="step-label">Preview</div><div class="step-sub">Review before sending</div></div>
  </div>
  <div class="step" id="step3">
    <div class="step-num">3</div>
    <div><div class="step-label">Confirm All</div><div class="step-sub">Final check</div></div>
  </div>
  <div class="step" id="step4">
    <div class="step-num">4</div>
    <div><div class="step-label">Submitted</div><div class="step-sub">Ticket generated</div></div>
  </div>
</div>

<?php if ($isWorkingHours): ?>
<div class="wh-banner wh-open">
  <div class="wh-banner-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
  <div class="wh-banner-body">
    <strong>We are currently open — your complaint will be processed today!</strong>
    UniKL RCMP Help Desk is operating right now.
    <div class="wh-hours-chips">
      <span class="wh-chip">Mon – Fri</span>
      <span class="wh-chip">8:00 AM – 5:00 PM</span>
      <span class="wh-chip">Now: <?php echo $now->format('g:i A, l'); ?></span>
    </div>
    <div class="wh-sla-row"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>SLA starts immediately upon submission.</div>
  </div>
</div>
<?php else: ?>
<div class="wh-banner wh-closed">
  <div class="wh-banner-icon"><svg viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg></div>
  <div class="wh-banner-body">
    <strong>Outside Working Hours — Complaints are currently disabled</strong>
    Submissions are only accepted Monday–Friday, 8:00 AM – 5:00 PM (MYT).
    <div class="wh-hours-chips">
      <span class="wh-chip">Now: <?php echo $now->format('g:i A, l'); ?></span>
    </div>
    <div class="wh-sla-row"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>Form will be available from: <strong>&nbsp;<?php echo $slaDisplay; ?></strong></div>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($error)): ?>
<div class="alert alert-error">
  <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
  <div><?php echo htmlspecialchars($error); ?></div>
</div>
<?php endif; ?>

<?php if (!$isWorkingHours): ?>
<!-- ── OUTSIDE HOURS: clean locked state, no scrollable form ── -->
<div id="complaintForm" style="width:100%;max-width:960px;">
  <div style="
    background:white;
    border-radius:20px;
    border:1px solid #ffe082;
    padding:48px 32px;
    text-align:center;
    display:flex;
    flex-direction:column;
    align-items:center;
    gap:16px;
  ">
    <div class="flo-icon">
      <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
    </div>
    <div class="flo-title">Submissions Closed</div>
    <div class="flo-body">
      The complaint form is only available during official working hours.<br>
      Please come back when the Help Desk is open.
    </div>
    <div class="flo-hours">
      <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      Monday – Friday &nbsp;|&nbsp; 8:00 AM – 5:00 PM MYT
    </div>
    <div class="flo-next">
      <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      Next available: <?php echo $slaDisplay; ?>
    </div>
    <a href="homepage.php" class="tp-back-btn" style="margin-top:8px;">
      <svg viewBox="0 0 24 24"><polyline points="15,18 9,12 15,6"/></svg>
      Back to Dashboard
    </a>
  </div>
</div>

<?php else: ?>
<!-- ── WORKING HOURS: full interactive form ── -->
<form method="POST" action="new_complaint.php" id="complaintForm" enctype="multipart/form-data" novalidate>
  <input type="hidden" name="category_id" id="category_id" value="<?php echo $prevCategoryId; ?>"/>
  <div class="form-card">
    <div class="form-card-header">
      <div class="fch-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></div>
      <div class="fch-text"><h3>Complaint Details</h3><p>Your complaint will be routed to the correct department automatically</p></div>
    </div>
    <div class="form-body">
      <div class="form-section-label">Complaint Details</div>
      

      <div class="field">
        <label>Category <span class="req">*</span></label>
        <div class="category-two-step" style="grid-template-columns:1fr;">
  <div class="cat-step-block subcategory-reveal active" id="subcategoryReveal">
    <div class="select-wrap">
      <select id="subcategory_select" disabled>
        <option value="">— Select Category —</option>
      </select>
    </div>
  </div>
  <div class="dept-hint" id="deptHint">
    <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
    <span id="deptHintText"></span>
  </div>
</div>
      </div>

      <div class="field">
        <label for="description">Description <span class="req">*</span></label>
        <textarea id="description" name="description"
          placeholder="Please describe your issue in detail..." maxlength="2000" required><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
        <div class="field-footer"><span class="char-counter" id="descCounter">0 / 2000</span></div>
      </div>

      <div class="field">
        <label>Attachment <span class="opt">Optional</span></label>
        <div class="file-drop" id="fileDrop">
<input type="file" name="attachment" id="attachment" 
  accept=".jpg,.jpeg,.png,.heic,.heif,.webp,.pdf,.doc,.docx,.txt"/>
          <div class="file-icon"><svg viewBox="0 0 24 24"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg></div>
          <div class="file-drop-title">Drop your file here, or <strong>browse</strong></div>
          <div class="file-drop-sub">JPG, PNG, HEIC, WEBP, PDF, DOC, DOCX, TXT — max 5 MB</div>
        </div>
        <div class="file-selected" id="fileSelected">
          <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
          <span id="fileName">—</span>
          <button type="button" class="file-remove" id="fileRemove">×</button>
        </div>
      </div>

      <br>

      <div class="form-section-label">Your Information</div>
      <div class="field-grid">
        <div class="field">
          <label for="phone">Phone Number <span class="req">*</span></label>
          <div class="phone-wrap">
            <span class="phone-prefix">+60</span>
            <input type="tel" id="phone" name="phone" placeholder="11-12345678" maxlength="12"
              value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" required autocomplete="off"/>
          </div>
          <div class="phone-hint" id="phoneHint">Enter 9–10 digits — dash will be added automatically (e.g. 11-12345678)</div>
        </div>
        <div class="field">
          <label for="my_department">My Department / Faculty <span class="req">*</span></label>
          <div class="select-wrap">
            <select id="my_department" name="my_department" required>
              <option value="" disabled selected>— Select Department / Faculty —</option>
              <?php foreach ($myDepartments as $dept): ?>
              <option value="<?php echo htmlspecialchars($dept); ?>"
                <?php echo (($_POST['my_department'] ?? '') === $dept) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($dept); ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
    </div>
    <div class="form-actions">
      <a href="homepage.php" class="btn-cancel">Cancel</a>
      <button type="button" class="btn-submit" onclick="showPreview()">
        Preview & Review
        <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
      </button>
    </div>
  </div>
</form>

<div id="previewPanel">
  <div class="preview-card">
    <div class="preview-card-header">
      <div class="pch-icon"><svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></div>
      <div class="pch-text"><h3>Review Your Complaint</h3><p>Please check all details carefully before submitting</p></div>
    </div>
    <div class="preview-body">
      <div class="preview-section">
        <div class="preview-section-title">Your Information</div>
        <div class="preview-grid">
          <div class="preview-field"><div class="preview-label">Phone Number</div><div class="preview-value" id="pv-phone">—</div></div>
          <div class="preview-field"><div class="preview-label">My Department / Faculty</div><div class="preview-value" id="pv-dept">—</div></div>
        </div>
      </div>
      <div class="preview-section">
        <div class="preview-section-title">Complaint Details</div>
        <div class="preview-grid">
          <div class="preview-field"><div class="preview-label">Category</div><div class="preview-value" id="pv-category">—</div></div>
          <div class="preview-field"><div class="preview-label">Will Be Handled By</div><div class="preview-value" id="pv-handler">—</div></div>
          <div class="preview-field full"><div class="preview-label">Description</div><div class="preview-value description-val" id="pv-description">—</div></div>
          <div class="preview-field"><div class="preview-label">Attachment</div><div class="preview-value" id="pv-attachment"><span class="no-file">No attachment</span></div></div>
        </div>
      </div>
      <div class="preview-notice">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span id="previewNoticeText">Once submitted, your complaint cannot be edited.</span>
      </div>
      <div class="preview-sla-row sla-open" id="previewSlaRow" style="display:none;">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        <span id="previewSlaText"></span>
      </div>
    </div>
    

    <div class="preview-actions">
      <button type="button" class="btn-back" onclick="backToForm()">
        <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>Back to Edit
      </button>
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        
        <button type="button" class="btn-confirm" id="confirmBtn" onclick="showConfirmationPanel()">
          Next: Review All
          <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
</div><!-- /complaint-section-wrapper -->
</div><!-- /afsmdComplaintSection -->

<!-- ── AFSMD REQUISITION SECTION (embedded, hidden by default) ── -->
<div id="afsmdRequisitionSection" style="display:none; width:100%; max-width:960px;">
<div class="complaint-section-wrapper" id="requisitionSectionWrapper" style="border-color:#0D9488;">

  <div class="dept-page-title"><br>
    <h2>Request Equipment</h2>
    <p>Submit a request to Administration & Facilities Management for office equipment, furniture, or facility items.</p>
  </div>

  <!-- Steps for requisition -->
  <div class="steps-row" id="req-steps-row">
    <div class="step active" id="req-step1">
      <div class="step-num" style="background:#0D9488;color:white;border-color:#0D9488;">1</div>
      <div><div class="step-label" style="color:#0D9488;">Fill Details</div><div class="step-sub">What do you need?</div></div>
    </div>
    <div class="step" id="req-step2">
      <div class="step-num">2</div>
      <div><div class="step-label">Preview</div><div class="step-sub">Review before sending</div></div>
    </div>
    <div class="step" id="req-step3">
      <div class="step-num">3</div>
      <div><div class="step-label">Confirm All</div><div class="step-sub">Final check</div></div>
    </div>
    <div class="step" id="req-step4">
      <div class="step-num">4</div>
      <div><div class="step-label">Submitted</div><div class="step-sub">Reference generated</div></div>
    </div>
  </div>

  <?php if ($isWorkingHours): ?>
  <!-- ── REQ FORM ── -->
  <form method="POST" action="new_complaint.php" id="reqForm" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="form_type" value="requisition"/>
    <div class="form-card" style="box-shadow:0 4px 6px -1px rgba(0,0,0,.06),0 10px 40px -8px rgba(13,148,136,.10);">
      <div class="form-card-header" style="background:linear-gradient(to right,#f0fdfa,#ffffff);">
        <div class="fch-icon" style="background:#ccfbf1;">
          <svg viewBox="0 0 24 24" style="stroke:#0D9488">
            <path d="M20 7H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/>
            <path d="M16 3H8a1 1 0 0 0-1 1v3h10V4a1 1 0 0 0-1-1z"/>
            <line x1="12" y1="12" x2="12" y2="16"/><line x1="10" y1="14" x2="14" y2="14"/>
          </svg>
        </div>
        <div class="fch-text"><h3>Equipment Request</h3><p>Your request will be sent to Administration & Facilities Management Department</p></div>
      </div>

      <div class="form-body">

        <!-- Category -->
        <div class="form-section-label">Equipment Category <span style="color:#E53935">*</span></div>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:24px;" id="reqCatPicker">
          <?php
          $equipCats = [
  'Office Furniture'  => ['desc'=>'Chairs, desks, cabinets, shelving','icon'=>'<path d="M20 7H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0-2-2z"/><line x1="8" y1="17" x2="8" y2="21"/><line x1="16" y1="17" x2="16" y2="21"/>'],
  'Water Dispenser'   => ['desc'=>'Hot & cold dispensers, servicing','icon'=>'<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/>'],
  'Signage'           => ['desc'=>'Room signs, notice boards, banners','icon'=>'<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/>'],
  'Vending Machine'   => ['desc'=>'Snack, beverage or combo machines','icon'=>'<rect x="5" y="2" width="14" height="20" rx="2"/><line x1="5" y1="10" x2="19" y2="10"/><line x1="5" y1="14" x2="19" y2="14"/><circle cx="12" cy="17" r="1"/>'],
  'Office Keys'       => ['desc'=>'Room keys, key duplicates, access cards','icon'=>'<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>'],
  'Office Equipment'  => ['desc'=>'Printers, projectors, whiteboards','icon'=>'<rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>'],
  'Others'            => ['desc'=>'Any other equipment not listed above','icon'=>'<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>'],
];
          foreach ($equipCats as $catName => $catMeta):
            $slug = 'req-' . strtolower(preg_replace('/[^a-z0-9]/i','-',$catName));
          ?>
          <div>
            <input type="radio" name="req_category" id="<?php echo $slug; ?>" class="cat-option req-cat-option" value="<?php echo htmlspecialchars($catName); ?>">
            <label class="cat-label" for="<?php echo $slug; ?>">
              <div class="cat-check" style="background:#0D9488;"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg></div>
              <div class="cat-icon" style="background:#ccfbf1;"><svg viewBox="0 0 24 24" style="fill:none;stroke:#0D9488;stroke-width:1.8;width:19px;height:19px;"><?php echo $catMeta['icon']; ?></svg></div>
              <div class="cat-name"><?php echo htmlspecialchars($catName); ?></div>
              <div class="cat-desc"><?php echo htmlspecialchars($catMeta['desc']); ?></div>
            </label>
          </div>
          <?php endforeach; ?>
        </div>

        

        <!-- Request Details -->
        <div class="form-section-label">Request Details</div>

        <div class="field">
          <label for="req_item_name">Item Name / Specification <span class="req">*</span></label>
          <input type="text" id="req_item_name" name="req_item_name" maxlength="200"
                 placeholder="e.g. High-back ergonomic chair, Samsung 55-inch projector screen"/>
        </div>

        <div class="field-grid">
          <div class="field">
            <label for="req_quantity">Quantity <span class="req">*</span></label>
            <div class="qty-wrap" style="display:flex;">
              <button type="button" onclick="reqQtyChange(-1)"
                style="width:42px;height:46px;border:1.5px solid var(--g300);background:var(--g100);
                       color:var(--g700);font-size:20px;border-radius:10px 0 0 10px;border-right:none;
                       cursor:pointer;display:flex;align-items:center;justify-content:center;">−</button>
              <input type="number" id="req_quantity" name="req_quantity" min="1" max="999" value="1"
                     style="border-radius:0;text-align:center;border-left:none;border-right:none;font-weight:600;
                            width:100%;padding:12px 16px;border:1.5px solid var(--g300);font-family:'DM Sans',sans-serif;
                            font-size:14px;color:var(--g900);background:white;outline:none;-moz-appearance:textfield;"/>
              <button type="button" onclick="reqQtyChange(1)"
                style="width:42px;height:46px;border:1.5px solid var(--g300);background:var(--g100);
                       color:var(--g700);font-size:20px;border-radius:0 10px 10px 0;border-left:none;
                       cursor:pointer;display:flex;align-items:center;justify-content:center;">+</button>
            </div>
          </div>
          <div class="field">
            <label>Urgency Level <span class="req">*</span></label>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:2px;">
              <?php foreach(['normal'=>'Normal','urgent'=>'Urgent','critical'=>'Critical'] as $v=>$l): ?>
              <input type="radio" name="req_urgency" id="req-urg-<?php echo $v; ?>" class="urg-option" value="<?php echo $v; ?>" <?php echo $v==='normal'?'checked':''; ?>>
              <label class="urg-label" for="req-urg-<?php echo $v; ?>"><span class="urg-dot"></span><?php echo $l; ?></label>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <div class="field">
          <label for="req_location">Delivery / Installation Location <span class="req">*</span></label>
          <input type="text" id="req_location" name="req_location" maxlength="200"
                 placeholder="e.g. Block A, Room 203, Level 2"/>
        </div>

        <!-- Contact Information -->
        <div class="form-section-label">Contact Information</div>
        <div class="field-grid">
          <div class="field">
            <label for="req_my_department">Your Department / Faculty <span class="req">*</span></label>
            <div class="select-wrap">
              <select id="req_my_department" name="req_my_department">
                <option value="" disabled selected>— Select Department / Faculty —</option>
                <?php foreach ($myDepartments as $d): ?>
                <option value="<?php echo htmlspecialchars($d); ?>"><?php echo htmlspecialchars($d); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="field">
            <label for="req_phone">Contact Number <span class="req">*</span></label>
            <div class="phone-wrap">
              <span class="phone-prefix">+60</span>
              <input type="tel" id="req_phone" name="req_phone" placeholder="11-12345678" maxlength="12" autocomplete="off"/>
            </div>
            <div class="phone-hint" id="reqPhoneHint">Enter 9–10 digits (e.g. 11-12345678)</div>
          </div>
        </div>

        <!-- Justification -->
        <div class="form-section-label">Justification</div>
        <div class="field">
          <label for="req_reason">Reason / Justification <span class="req">*</span></label>
          <textarea id="req_reason" name="req_reason" maxlength="1500"
                    placeholder="Please explain why this equipment is needed..."></textarea>
          <div class="field-footer"><span class="char-counter" id="reqReasonCounter">0 / 1500</span></div>
        </div>

        <!-- Attachment -->
        <div class="field">
          <label>Supporting Document <span class="opt">Optional</span></label>
          <div class="file-drop" id="reqFileDrop"
               style="border-color:var(--g300);"
               ondragenter="this.classList.add('dragover')"
               ondragleave="this.classList.remove('dragover')"
               ondragover="event.preventDefault();this.classList.add('dragover')"
               ondrop="reqHandleDrop(event)">
<input type="file" name="req_attachment" id="req_attachment"
       accept=".jpg,.jpeg,.png,.heic,.heif,.webp,.pdf,.doc,.docx,.txt"
                   onchange="reqHandleFile(this)"/>
            <div class="file-icon"><svg viewBox="0 0 24 24"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg></div>
            <div class="file-drop-title">Drop a file here, or <strong style="color:#0D9488;">browse</strong></div>
            <div class="file-drop-sub">JPG, PNG, HEIC, WEBP, PDF, DOC, DOCX, TXT — max 5 MB</div>
          </div>
          <div class="file-selected" id="reqFileSelected"
               style="display:none;align-items:center;gap:10px;padding:10px 14px;
                      background:#f0fdfa;border:1px solid rgba(13,148,136,.2);border-radius:8px;
                      margin-top:10px;font-size:13px;color:#0D9488;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#0D9488" stroke-width="2">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
              <polyline points="14 2 14 8 20 8"/>
            </svg>
            <span id="reqFileName">—</span>
            <button type="button" onclick="reqRemoveFile()"
                    style="margin-left:auto;cursor:pointer;color:var(--g500);background:none;border:none;font-size:18px;">×</button>
          </div>
        </div>

      </div><!-- /form-body -->

      <div class="form-actions" style="background:var(--off);">
        <button type="button" class="btn-cancel" onclick="switchAfsmdTab('complaint')">Cancel</button>
        <button type="button" onclick="showReqPreview()"
                style="font-family:'DM Sans',sans-serif;font-size:14px;font-weight:600;padding:12px 32px;
                       border-radius:10px;background:#0D9488;color:white;border:none;cursor:pointer;
                       display:flex;align-items:center;gap:9px;box-shadow:0 4px 14px rgba(13,148,136,.28);
                       transition:all .2s;">
          Preview & Review
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
      </div>
    </div>
  </form>

  <!-- REQ PREVIEW PANEL -->
  <div id="reqPreviewPanel" style="display:none;width:100%;">
    <div class="preview-card" style="box-shadow:0 4px 6px -1px rgba(0,0,0,.06),0 10px 40px -8px rgba(13,148,136,.10);">
      <div class="preview-card-header" style="background:linear-gradient(to right,#f0fdfa,#ffffff);">
        <div class="pch-icon"><svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></div>
        <div class="pch-text"><h3>Review Your Request</h3><p>Please check all details carefully before submitting</p></div>
      </div>
      <div class="preview-body">
        <div class="preview-section">
          <div class="preview-section-title">Equipment</div>
          <div class="preview-grid">
            <div class="preview-field"><div class="preview-label">Category</div><div class="preview-value" id="rpv-category">—</div></div>
            <div class="preview-field"><div class="preview-label">Urgency</div><div class="preview-value" id="rpv-urgency">—</div></div>
            <div class="preview-field full"><div class="preview-label">Item Name / Specification</div><div class="preview-value" id="rpv-item-name">—</div></div>
            <div class="preview-field"><div class="preview-label">Quantity</div><div class="preview-value" id="rpv-quantity">—</div></div>
            <div class="preview-field"><div class="preview-label">Location</div><div class="preview-value" id="rpv-location">—</div></div>
          </div>
        </div>
        <div class="preview-section">
          <div class="preview-section-title">Your Information</div>
          <div class="preview-grid">
            <div class="preview-field"><div class="preview-label">Department / Faculty</div><div class="preview-value" id="rpv-dept">—</div></div>
            <div class="preview-field"><div class="preview-label">Contact Number</div><div class="preview-value" id="rpv-phone">—</div></div>
          </div>
        </div>
        <div class="preview-section">
          <div class="preview-section-title">Justification</div>
          <div class="preview-grid">
            <div class="preview-field full"><div class="preview-label">Reason</div><div class="preview-value" style="white-space:pre-wrap;min-height:72px;" id="rpv-reason">—</div></div>
            <div class="preview-field"><div class="preview-label">Attachment</div><div class="preview-value" id="rpv-attachment"><span style="color:#bbb;font-style:italic">No attachment</span></div></div>
          </div>
        </div>
        <div class="preview-notice">
          <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          Once submitted, this request will be forwarded to Administration & Facilities Management Department.
        </div>
      </div>
      

      <div class="preview-actions" style="background:var(--off);">
        <button type="button" class="btn-back" onclick="backToReqForm()">
          <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>Back to Edit
        </button>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
          
          <button type="button" id="reqConfirmBtn" onclick="showConfirmationPanel()"
                style="font-family:'DM Sans',sans-serif;font-size:14px;font-weight:600;padding:12px 32px;
                       border-radius:10px;background:#2e7d32;color:white;border:none;cursor:pointer;
                       display:flex;align-items:center;gap:9px;box-shadow:0 4px 14px rgba(46,125,50,.25);
                       transition:all .2s;">
            Next: Review All
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="white" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
          </button>
        </div>
      </div>
    </div>
  </div>

  <?php else: ?>
  <!-- Outside hours locked state -->
  <div style="position:relative;width:100%;">
    <div style="position:absolute;inset:0;z-index:20;background:rgba(255,255,255,.85);backdrop-filter:blur(3px);
                border-radius:20px;display:flex;flex-direction:column;align-items:center;justify-content:center;
                text-align:center;padding:40px 32px;">
      <div class="flo-icon"><svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></div>
      <div class="flo-title">Submissions Closed</div>
      <div class="flo-body">Equipment requests are only available Monday–Friday, 8:00 AM – 5:00 PM MYT.</div>
      <div class="flo-hours"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>Next available: <?php echo $slaDisplay; ?></div>
    </div>
    <div class="form-card" aria-hidden="true" style="pointer-events:none;opacity:0.4;min-height:300px;"></div>
  </div>
  <?php endif; ?>

</div><!-- /requisitionSectionWrapper -->
</div><!-- /afsmdRequisitionSection -->

<!-- ── CONFIRMATION PANEL ── -->
<div id="confirmationPanel" style="display:none;width:100%;max-width:960px;">
  <div class="preview-card">
    <div class="preview-card-header">
      <div class="pch-icon" style="background:#e8f5e9;">
        <svg viewBox="0 0 24 24" style="stroke:#2e7d32;">
          <path d="M9 11l3 3L22 4"/>
          <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
        </svg>
      </div>
      <div class="pch-text">
        <h3>Confirm All Submissions</h3>
        <p>Review everything before sending — once submitted, these cannot be edited</p>
      </div>
    </div>
    <div class="preview-body">
      <div class="batch-summary-slot" id="confirmationSummarySlot"></div>
      <div class="preview-notice" style="margin-top:8px;">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        Once you click <strong>Submit All</strong>, all items above will be submitted and cannot be changed.
      </div>
    </div>
    <div class="preview-actions">
      <button type="button" onclick="openAddAnotherModal()"
        style="font-family:'DM Sans',sans-serif;font-size:13px;font-weight:600;
               padding:11px 20px;border-radius:10px;background:white;
               color:var(--blue);border:1.5px solid var(--blue);cursor:pointer;
               display:flex;align-items:center;gap:7px;transition:all .2s;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
        </svg>
        Add Another
      </button>
      <button type="button" class="btn-confirm" id="finalSubmitBtn" onclick="submitAllBatch()">
        Submit All
        <span id="finalSubmitCount" style="background:rgba(255,255,255,.25);border-radius:100px;
              padding:1px 8px;font-size:11px;font-weight:700;">1</span>
        <svg viewBox="0 0 24 24"><path d="M22 2L11 13"/><path d="M22 2L15 22 11 13 2 9l20-7z"/></svg>
      </button>
    </div>
  </div>
</div>

<!-- ── ADD ANOTHER PICKER MODAL ── -->
<div id="addAnotherModal" style="display:none;position:fixed;inset:0;z-index:998;
     background:rgba(10,20,50,.6);backdrop-filter:blur(5px);
     align-items:center;justify-content:center;">
  <div style="background:white;border-radius:20px;padding:36px 32px;max-width:480px;
              width:90%;animation:scaleIn .35s cubic-bezier(.34,1.56,.64,1);
              box-shadow:0 24px 80px rgba(0,0,0,.18);">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
      <div style="font-family:'DM Serif Display',serif;font-size:20px;color:var(--g900);">
        Add Another Item
      </div>
      <button onclick="closeAddAnotherModal()" type="button"
        style="background:none;border:none;cursor:pointer;font-size:22px;color:var(--g400);
               line-height:1;padding:0 4px;">&times;</button>
    </div>
    <p style="font-size:13px;color:var(--g500);margin-bottom:24px;line-height:1.6;">
      Choose what you'd like to add next. All items will be submitted together.
    </p>

    <!-- Department picker -->
    <div style="font-size:11px;font-weight:600;letter-spacing:.07em;text-transform:uppercase;
                color:var(--g500);margin-bottom:10px;">Choose Department</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px;">
      <?php
      $modalDepts = [
        ['key'=>'Information Technology Department',                  'label'=>'Information Technology',     'color'=>'#2563EB','bg'=>'#EFF6FF','icon'=>'<rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>'],
        ['key'=>'Maintenance Department',                            'label'=>'Maintenance',                 'color'=>'#16A34A','bg'=>'#F0FDF4','icon'=>'<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>'],
        ['key'=>'Administration &amp; Facilities Management Department','label'=>'Admin &amp; Facilities Mgmt','color'=>'#EA580C','bg'=>'#FFF7ED','icon'=>'<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>'],
        ['key'=>'Corporate Communication Unit',                      'label'=>'Corporate Communication',     'color'=>'#7C3AED','bg'=>'#FAF5FF','icon'=>'<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>'],
        ['key'=>'Human Capital Department',                          'label'=>'Human Capital',               'color'=>'#E11D48','bg'=>'#FFF1F2','icon'=>'<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'],
      ];
      foreach ($modalDepts as $md):
      ?>
      <button type="button"
        onclick="pickAddAnotherDept('<?php echo addslashes($md['key']); ?>', 'complaint')"
        style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;
               padding:14px 10px;border-radius:12px;
               border:1.5px solid <?php echo $md['color']; ?>22;
               background:<?php echo $md['bg']; ?>;
               cursor:pointer;font-family:'DM Sans',sans-serif;
               text-align:center;transition:all .18s;width:100%;"
        onmouseover="this.style.borderColor='<?php echo $md['color']; ?>';this.style.boxShadow='0 4px 12px <?php echo $md['color']; ?>22';"
        onmouseout="this.style.borderColor='<?php echo $md['color']; ?>22';this.style.boxShadow='none';">
        <div style="width:32px;height:32px;border-radius:8px;
                    background:<?php echo $md['color']; ?>18;
                    display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
               stroke="<?php echo $md['color']; ?>" stroke-width="2">
            <?php echo $md['icon']; ?>
          </svg>
        </div>
        <span style="font-size:11px;font-weight:700;color:<?php echo $md['color']; ?>;
                     line-height:1.3;letter-spacing:0.01em;">
          <?php echo $md['label']; ?>
        </span>
      </button>
      <?php endforeach; ?>
    </div>

    <!-- Request equipment shortcut (AFSMD only) -->
    <div style="font-size:11px;font-weight:600;letter-spacing:.07em;text-transform:uppercase;
                color:var(--g500);margin-bottom:10px;">Or add a Request</div>
    <button type="button"
      onclick="pickAddAnotherDept('Administration &amp; Facilities Management Department', 'requisition')"
      style="width:100%;display:flex;align-items:center;gap:10px;padding:10px 14px;
             border-radius:12px;border:1.5px solid rgba(13,148,136,.25);background:#F0FDFA;
             cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .18s;"
      onmouseover="this.style.borderColor='#0D9488';this.style.boxShadow='0 4px 12px rgba(13,148,136,.15)';"
      onmouseout="this.style.borderColor='rgba(13,148,136,.25)';this.style.boxShadow='none';">
      <div style="width:30px;height:30px;border-radius:8px;background:rgba(13,148,136,.12);
                  display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#0D9488" stroke-width="2">
          <path d="M20 7H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/>
          <path d="M16 3H8a1 1 0 0 0-1 1v3h10V4a1 1 0 0 0-1-1z"/>
          <line x1="12" y1="12" x2="12" y2="16"/><line x1="10" y1="14" x2="14" y2="14"/>
        </svg>
      </div>
      <div>
        <div style="font-size:12px;font-weight:700;color:#0D9488;line-height:1.3;">Request Equipment</div>
        <div style="font-size:10px;color:#5eaaa3;font-weight:500;margin-top:1px;">Admin &amp; Facilities</div>
      </div>
    </button>
  </div>
</div>

<!-- ── CANCEL CONFIRMATION MODAL ── -->
<div id="cancelConfirmModal" style="display:none;position:fixed;inset:0;z-index:999;
     background:rgba(10,20,50,.6);backdrop-filter:blur(5px);
     align-items:center;justify-content:center;">
  <div style="background:white;border-radius:20px;padding:36px 32px;max-width:400px;
              width:90%;animation:scaleIn .3s cubic-bezier(.34,1.56,.64,1);
              box-shadow:0 24px 80px rgba(0,0,0,.18);text-align:center;">
    <div style="width:56px;height:56px;border-radius:50%;background:#fff0f0;border:2px solid #fca5a5;
                display:flex;align-items:center;justify-content:center;margin:0 auto 18px;">
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2">
        <circle cx="12" cy="12" r="10"/>
        <line x1="15" y1="9" x2="9" y2="15"/>
        <line x1="9" y1="9" x2="15" y2="15"/>
      </svg>
    </div>
    <div style="font-family:'DM Serif Display',serif;font-size:20px;color:var(--g900);margin-bottom:8px;">
      Remove this item?
    </div>
    <p style="font-size:13px;color:var(--g500);line-height:1.6;margin-bottom:24px;">
      This item will be removed from your submission. This action cannot be undone.
    </p>
    <div style="display:flex;gap:10px;justify-content:center;">
      <button type="button" id="cancelModalNo"
        style="font-family:'DM Sans',sans-serif;font-size:14px;font-weight:500;
               padding:11px 28px;border-radius:10px;border:1.5px solid var(--g300);
               background:white;color:var(--g700);cursor:pointer;transition:all .2s;"
        onmouseover="this.style.borderColor='var(--g500)';this.style.color='var(--g900)'"
        onmouseout="this.style.borderColor='var(--g300)';this.style.color='var(--g700)'">
        Keep Item
      </button>
      <button type="button" id="cancelModalYes"
        style="font-family:'DM Sans',sans-serif;font-size:14px;font-weight:600;
               padding:11px 28px;border-radius:10px;background:#dc2626;color:white;
               border:none;cursor:pointer;box-shadow:0 4px 14px rgba(220,38,38,.25);
               transition:all .2s;"
        onmouseover="this.style.background='#b91c1c';this.style.transform='translateY(-1px)'"
        onmouseout="this.style.background='#dc2626';this.style.transform='translateY(0)'">
        Yes, Remove
      </button>
    </div>
  </div>
</div>

<!-- ── REQUISITION SUCCESS OVERLAY ── -->
<div class="success-overlay <?php echo $reqSuccess ? 'show' : ''; ?>" id="reqSuccessOverlay">
  <div class="success-card">
    <div class="success-icon-wrap" style="background:linear-gradient(135deg,#0D9488,#2DD4BF);box-shadow:0 8px 24px rgba(13,148,136,.3);">
      <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
    </div>
    <h2>Request Submitted!</h2>
    <p>Your equipment request has been forwarded to Administration & Facilities Management Department.</p>
    <div style="background:#f0fdfa;border:1px solid rgba(13,148,136,.2);border-radius:10px;padding:14px 20px;margin-bottom:20px;">
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:#0D9488;font-weight:600;margin-bottom:4px;">Reference Number</div>
      <div style="font-family:monospace;font-size:18px;font-weight:700;color:var(--g900);"><?php echo htmlspecialchars($reqRefNumber); ?></div>
    </div>
    <div class="success-actions">
      <a href="new_complaint.php" class="btn-ghost">Submit Another</a>
      <a href="homepage.php" style="font-family:'DM Sans',sans-serif;font-size:13px;font-weight:500;padding:10px 20px;border-radius:8px;background:#0D9488;color:white;border:none;cursor:pointer;text-decoration:none;">← Back to Dashboard</a>
    </div>
  </div>
</div>

<!-- ── BATCH SUCCESS OVERLAY ── -->
<div class="success-overlay" id="batchSuccessOverlay">
  <div class="success-card">
    <div class="success-icon-wrap"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg></div>
    <h2>All Submitted!</h2>
    <p>Your items have been received. Here are your reference numbers:</p>
    <div id="batchSuccessList" style="text-align:left;width:100%;margin-bottom:18px;max-height:200px;overflow-y:auto;"></div>
    <div class="success-actions">
      <a href="new_complaint.php" class="btn-ghost">Submit More</a>
      <a href="homepage.php" class="btn-primary-sm">← Back to Dashboard</a>
    </div>
  </div>
</div>

<div class="success-overlay <?php echo $success ? 'show' : ''; ?>" id="successOverlay">
  <div class="success-card">
    <div class="success-icon-wrap"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg></div>
    <h2>Complaint Submitted!</h2>
    <p>Your complaint has been received. A staff member has been automatically assigned and will respond shortly.</p>
    <div class="success-ticket">
      <div class="label">Your Ticket ID</div>
      <div class="tid"><?php echo htmlspecialchars($ticketId); ?></div>
    </div>
    <?php if ($success): ?>
      <div class="success-sla sla-now">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        <div>Your complaint is being processed <strong>right now</strong>.</div>
      </div>
    <?php endif; ?>
    <div class="success-actions">
      <a href="new_complaint.php" class="btn-ghost">Submit Another</a>
      <a href="homepage.php" class="btn-primary-sm">← Back to Dashboard</a>
    </div>
  </div>
</div>

<?php ob_start(); ?>
<script>
  // ── BATCH SUBMISSION STATE ────────────────────────────────────
const batchItems = []; // Array of {type:'complaint'|'requisition', data:{...}, displayHtml:''}

function getBatchSummaryHtml(currentItem) {
  const allItems = [...batchItems];
  const total = allItems.length + (currentItem ? 1 : 0);
  if (!currentItem && batchItems.length === 0) return '';

  let html = `<div style="width:100%;margin-bottom:20px;border-top:1px solid #e2e8f0;padding-top:24px;">
    <div style="font-size:11px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;
                color:var(--g500);margin-bottom:16px;">
      All Items in This Submission (${total})
    </div>
    <div style="display:flex;flex-direction:column;gap:16px;">`;

  // ── Render queued items with full detail ──
  allItems.forEach((item, idx) => {
    const isReq  = item.type === 'requisition';
    const color  = isReq ? '#0D9488' : 'var(--blue)';
    const bg     = isReq ? '#f0fdfa' : '#f0f5ff';
    const border = isReq ? 'rgba(13,148,136,.2)' : 'rgba(0,59,142,.15)';
    const label  = isReq ? '📦 Request' : '📋 Complaint';
    const num    = idx + 1;

    let detailRows = '';
    if (isReq) {
      detailRows = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px 20px;margin-top:12px;">
          <div><div style="font-size:10px;font-weight:600;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">Category</div>
               <div style="font-size:13px;color:var(--g900);font-weight:500;">${esc(item.data.category || item.data.title)}</div></div>
          <div><div style="font-size:10px;font-weight:600;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">Urgency</div>
               <div style="font-size:13px;color:var(--g900);font-weight:500;">${esc(item.data.urgency || 'normal')}</div></div>
          <div><div style="font-size:10px;font-weight:600;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">Quantity</div>
               <div style="font-size:13px;color:var(--g900);font-weight:500;">${esc(String(item.data.quantity || 1))} unit(s)</div></div>
          <div><div style="font-size:10px;font-weight:600;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">Item Name</div>
               <div style="font-size:13px;color:var(--g900);font-weight:500;">${esc(item.data.item_name || '—')}</div></div>
          <div><div style="font-size:10px;font-weight:600;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">Location</div>
               <div style="font-size:13px;color:var(--g900);font-weight:500;">${esc(item.data.location || '—')}</div></div>
          <div><div style="font-size:10px;font-weight:600;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">Department</div>
               <div style="font-size:13px;color:var(--g900);font-weight:500;">${esc(item.data.my_department || '—')}</div></div>
          <div><div style="font-size:10px;font-weight:600;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">Phone</div>
               <div style="font-size:13px;color:var(--g900);font-weight:500;">+60 ${esc(item.data.phone || '—')}</div></div>
          <div style="grid-column:1/-1;"><div style="font-size:10px;font-weight:600;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">Reason</div>
               <div style="font-size:13px;color:var(--g900);font-weight:500;white-space:pre-wrap;">${esc(item.data.reason || '—')}</div></div>
        </div>`;
    } else {
      detailRows = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px 20px;margin-top:12px;">
          <div><div style="font-size:10px;font-weight:600;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">Category</div>
               <div style="font-size:13px;color:var(--g900);font-weight:500;">${esc(item.data.title)}</div></div>
          <div><div style="font-size:10px;font-weight:600;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">Handled By</div>
               <div style="font-size:13px;color:var(--g900);font-weight:500;">${esc(item.data.dept)}</div></div>
          <div><div style="font-size:10px;font-weight:600;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">Department</div>
               <div style="font-size:13px;color:var(--g900);font-weight:500;">${esc(item.data.my_department || '—')}</div></div>
          <div><div style="font-size:10px;font-weight:600;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">Phone</div>
               <div style="font-size:13px;color:var(--g900);font-weight:500;">+60 ${esc(item.data.phone || '—')}</div></div>
          <div style="grid-column:1/-1;"><div style="font-size:10px;font-weight:600;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">Description</div>
               <div style="font-size:13px;color:var(--g900);font-weight:500;white-space:pre-wrap;">${esc(item.data.description || '—')}</div></div>
        </div>`;
    }

    html += `
    <div style="border:1px solid ${border};border-radius:14px;overflow:hidden;background:${bg};">
      <div style="display:flex;align-items:center;gap:10px;padding:12px 16px;
                  border-bottom:1px solid ${border};background:white;">
        <span style="font-size:10px;font-weight:700;padding:2px 9px;border-radius:100px;
                     background:${color};color:white;">${label} #${num}</span>
        <div style="flex:1;font-size:13px;font-weight:600;color:var(--g900);">${esc(item.data.title)}</div>
        <div style="display:flex;gap:6px;">
          <button type="button" onclick="editBatchItem(${idx})"
            style="background:#eff6ff;border:1px solid #93c5fd;cursor:pointer;color:#1d4ed8;
                   font-size:11px;font-weight:600;padding:4px 10px;border-radius:6px;"
            onmouseover="this.style.background='#dbeafe'"
            onmouseout="this.style.background='#eff6ff'">Edit</button>
          <button type="button" onclick="removeBatchItem(${idx})"
            style="background:#fff0f0;border:1px solid #fca5a5;cursor:pointer;color:#dc2626;
                   font-size:11px;font-weight:600;padding:4px 10px;border-radius:6px;"
            onmouseover="this.style.background='#fee2e2'"
            onmouseout="this.style.background='#fff0f0'">Cancel</button>
        </div>
      </div>
      <div style="padding:14px 16px;">${detailRows}</div>
    </div>`;
  });

  // ── Render current item — now has Edit + Cancel like queued items ──
  if (currentItem) {
    const isReq  = currentItem.type === 'requisition';
    const color  = isReq ? '#0D9488' : 'var(--blue)';
    const bg     = isReq ? '#f0fdfa' : '#f0f5ff';
    const border = isReq ? 'rgba(13,148,136,.2)' : 'rgba(0,59,142,.15)';
    const label  = isReq ? '📦 Request' : '📋 Complaint';
    const num    = allItems.length + 1;

    let currentDetailRows = '';
    if (currentItem.type === 'requisition') {
      currentDetailRows = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px 20px;margin-top:12px;">
          <div><div style="font-size:10px;font-weight:600;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">Category</div>
               <div style="font-size:13px;color:var(--g900);font-weight:500;">${esc(currentItem.data.category || currentItem.data.title)}</div></div>
          <div><div style="font-size:10px;font-weight:600;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">Urgency</div>
               <div style="font-size:13px;color:var(--g900);font-weight:500;">${esc(currentItem.data.urgency || 'normal')}</div></div>
          <div><div style="font-size:10px;font-weight:600;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">Quantity</div>
               <div style="font-size:13px;color:var(--g900);font-weight:500;">${esc(String(currentItem.data.quantity || 1))} unit(s)</div></div>
          <div><div style="font-size:10px;font-weight:600;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">Item Name</div>
               <div style="font-size:13px;color:var(--g900);font-weight:500;">${esc(currentItem.data.item_name || '—')}</div></div>
          <div><div style="font-size:10px;font-weight:600;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">Location</div>
               <div style="font-size:13px;color:var(--g900);font-weight:500;">${esc(currentItem.data.location || '—')}</div></div>
          <div><div style="font-size:10px;font-weight:600;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">Department</div>
               <div style="font-size:13px;color:var(--g900);font-weight:500;">${esc(currentItem.data.my_department || '—')}</div></div>
          <div><div style="font-size:10px;font-weight:600;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">Phone</div>
               <div style="font-size:13px;color:var(--g900);font-weight:500;">+60 ${esc(currentItem.data.phone || '—')}</div></div>
          <div style="grid-column:1/-1;"><div style="font-size:10px;font-weight:600;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">Reason</div>
               <div style="font-size:13px;color:var(--g900);font-weight:500;white-space:pre-wrap;">${esc(currentItem.data.reason || '—')}</div></div>
        </div>`;
    } else {
      currentDetailRows = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px 20px;margin-top:12px;">
          <div><div style="font-size:10px;font-weight:600;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">Category</div>
               <div style="font-size:13px;color:var(--g900);font-weight:500;">${esc(currentItem.data.title)}</div></div>
          <div><div style="font-size:10px;font-weight:600;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">Handled By</div>
               <div style="font-size:13px;color:var(--g900);font-weight:500;">${esc(currentItem.data.dept)}</div></div>
          <div><div style="font-size:10px;font-weight:600;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">Department</div>
               <div style="font-size:13px;color:var(--g900);font-weight:500;">${esc(currentItem.data.my_department || '—')}</div></div>
          <div><div style="font-size:10px;font-weight:600;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">Phone</div>
               <div style="font-size:13px;color:var(--g900);font-weight:500;">+60 ${esc(currentItem.data.phone || '—')}</div></div>
          <div style="grid-column:1/-1;"><div style="font-size:10px;font-weight:600;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">Description</div>
               <div style="font-size:13px;color:var(--g900);font-weight:500;white-space:pre-wrap;">${esc(currentItem.data.description || '—')}</div></div>
        </div>`;
    }

    html += `
    <div style="border:1px solid ${border};border-radius:14px;overflow:hidden;background:${bg};">
      <div style="display:flex;align-items:center;gap:10px;padding:12px 16px;
                  border-bottom:1px solid ${border};background:white;">
        <span style="font-size:10px;font-weight:700;padding:2px 9px;border-radius:100px;
                     background:${color};color:white;">${label} #${num}</span>
        <div style="flex:1;font-size:13px;font-weight:600;color:var(--g900);">${esc(currentItem.data.title)}</div>
        <div style="display:flex;gap:6px;">
          <button type="button" onclick="editCurrentItem()"
            style="background:#eff6ff;border:1px solid #93c5fd;cursor:pointer;color:#1d4ed8;
                   font-size:11px;font-weight:600;padding:4px 10px;border-radius:6px;"
            onmouseover="this.style.background='#dbeafe'"
            onmouseout="this.style.background='#eff6ff'">Edit</button>
          <button type="button" onclick="cancelCurrentItem()"
            style="background:#fff0f0;border:1px solid #fca5a5;cursor:pointer;color:#dc2626;
                   font-size:11px;font-weight:600;padding:4px 10px;border-radius:6px;"
            onmouseover="this.style.background='#fee2e2'"
            onmouseout="this.style.background='#fff0f0'">Cancel</button>
        </div>
      </div>
      <div style="padding:14px 16px;">${currentDetailRows}</div>
    </div>`;
  }

  html += `</div></div>`;
  return html;
}

function removeBatchItem(idx) {
  showCancelModal(function() {
    batchItems.splice(idx, 1);
  const complaintPreviewVisible = document.getElementById('previewPanel').classList.contains('show');
  const reqPreviewVisible = document.getElementById('reqPreviewPanel')?.style.display !== 'none' &&
                            document.getElementById('reqPreviewPanel')?.style.display !== '';
  let currentItem = null;
  if (complaintPreviewVisible) {
    const _phone  = document.getElementById('phone')?.value.trim() || '—';
    const _dept   = document.getElementById('my_department')?.value || '—';
    const _desc   = document.getElementById('description')?.value.trim() || '—';
    const _catSel = document.getElementById('subcategory_select');
    currentItem = {
      type: 'complaint',
      data: {
        title:         _catSel?.options[_catSel?.selectedIndex]?.text || '—',
        dept:          selectedHandler || '—',
        category_id:   document.getElementById('category_id')?.value || '',
        description:   _desc,
        phone:         _phone,
        my_department: _dept,
      }
    };
  } else if (reqPreviewVisible) {
    const cat      = document.querySelector('input[name="req_category"]:checked')?.value || '—';
    const qty      = parseInt(document.getElementById('req_quantity')?.value) || 1;
    const _dept    = document.getElementById('req_my_department')?.value || '—';
    const _phone   = document.getElementById('req_phone')?.value.trim() || '—';
    const _loc     = document.getElementById('req_location')?.value.trim() || '—';
    const _reason  = document.getElementById('req_reason')?.value.trim() || '—';
    const _urgency = document.querySelector('input[name="req_urgency"]:checked')?.value || 'normal';
    currentItem = {
      type: 'requisition',
      data: {
        title:         cat + ' ×' + qty,
        dept:          'Admin & Facilities Management',
        category:      cat,
        quantity:      qty,
        my_department: _dept,
        phone:         _phone,
        location:      _loc,
        reason:        _reason,
        urgency:       _urgency,
      }
    };
  }
  // Only call renderBatchSummary (which updates _confirmCurrentItem) if
  // the confirmation panel is NOT open — otherwise preserve _confirmCurrentItem
  const confirmPanel = document.getElementById('confirmationPanel');
  if (confirmPanel && confirmPanel.style.display !== 'none') {
    // Don't overwrite _confirmCurrentItem — just re-render the confirmation panel
    renderConfirmationSummary();
  } else {
    renderBatchSummary(currentItem);
  }
  updateConfirmCount();
  }); // end showCancelModal
}

function editBatchItem(idx) {
  const item = batchItems[idx];
  batchItems.splice(idx, 1);

  // If there was a current item being reviewed, push it back into the batch
  // so it isn't lost when we go back to edit the selected item
  if (window._confirmCurrentItem) {
    batchItems.push(window._confirmCurrentItem);
    window._confirmCurrentItem = null;
  }

  document.getElementById('confirmationPanel').style.display = 'none';
  renderBatchSummary(null);

  // Restore tabs and banner before showing preview
  const tabbar = document.getElementById('deptTabs')?.closest('.dept-tabbar');
  if (tabbar) tabbar.style.display = '';
  const banner = document.querySelector('.wh-banner');
  if (banner) banner.style.display = '';

  if (item.type === 'complaint') {
    restoreComplaintToPreview(item.data);
  } else if (item.type === 'requisition') {
    restoreReqToPreview(item.data);
  }
}

function restoreComplaintToPreview(data) {
  // Make sure complaint section is visible
  document.getElementById('afsmdComplaintSection').style.display = '';
  document.getElementById('afsmdRequisitionSection').style.display = 'none';
  // Hide the form, ensure preview panel is ready
  document.getElementById('complaintForm').style.display = 'none';
  document.getElementById('previewPanel').style.display = '';

  // Re-populate the hidden category_id
  document.getElementById('category_id').value = data.category_id || '';

  // Try to activate the right dept tab based on handler
  // (We stored selectedHandler as dept in data.dept)
  // Re-populate subcategory select
  // Find which dept tab matches
  const deptTabs = document.querySelectorAll('.dept-tab');
  deptTabs.forEach(tab => {
    if (allCategories[tab.dataset.dept]) {
      allCategories[tab.dataset.dept].forEach(cat => {
        if (String(cat.id) === String(data.category_id)) {
          activateTab(tab);
        }
      });
    }
  });

  // Set subcategory select value
  setTimeout(() => {
    const subcatSel = document.getElementById('subcategory_select');
    subcatSel.value = data.category_id;
    subcatSel.dispatchEvent(new Event('change'));

    // Now populate form fields
    document.getElementById('description').value  = data.description || '';
    document.getElementById('phone').value         = data.phone || '';
    document.getElementById('my_department').value = data.my_department || '';
    updateProgress();
    updateCounter('descCounter', (data.description||'').length, 2000);

    // Go back to the editable form (not the preview)
    window._confirmCurrentItem = null;
    document.getElementById('complaintForm').style.display = '';
    document.getElementById('previewPanel').classList.remove('show');
    document.getElementById('previewPanel').style.display = '';
    document.getElementById('step1').classList.add('active');
    document.getElementById('step1').classList.remove('done');
    document.getElementById('step2').classList.remove('active');
    updateProgress();
    window.scrollTo({top:0, behavior:'smooth'});
  }, 50);
}

function restoreReqToPreview(data) {
  // Switch to AFSMD requisition tab
  const afsmdTab = document.querySelector('.dept-tab[data-dept="Administration & Facilities Management Department"]');
  if (afsmdTab) activateTab(afsmdTab);
  switchAfsmdTab('requisition');

  // Populate form fields first
  document.querySelectorAll('input[name="req_category"]').forEach(r => {
    r.checked = (r.value === data.category);
  });
  document.getElementById('req_quantity').value      = data.quantity || 1;
  document.getElementById('req_my_department').value = data.my_department || '';
  document.getElementById('req_phone').value         = data.phone || '';
  document.getElementById('req_item_name').value     = data.item_name || '';
  document.getElementById('req_location').value      = data.location || '';
  document.getElementById('req_reason').value        = data.reason || '';
  const urgRadio = document.getElementById('req-urg-' + (data.urgency || 'normal'));
  if (urgRadio) urgRadio.checked = true;

  // Populate the preview panel values directly (skip re-validation)
  const urgLabels  = {normal:'Normal',urgent:'Urgent',critical:'Critical'};
  const urgClasses = {normal:'urg-normal',urgent:'urg-urgent',critical:'urg-critical'};
  const urgency = data.urgency || 'normal';
  document.getElementById('rpv-category').innerHTML = `<span class="preview-badge" style="background:#f0fdfa;color:#0D9488;border-color:rgba(13,148,136,.2)">${esc(data.category)}</span>`;
  document.getElementById('rpv-urgency').innerHTML  = `<span class="preview-urg-badge ${urgClasses[urgency]}">${esc(urgLabels[urgency] || urgency)}</span>`;
  document.getElementById('rpv-quantity').textContent = (data.quantity || 1) + ((data.quantity === 1) ? ' unit' : ' units');
  document.getElementById('rpv-item-name').textContent = data.item_name || '—';
          document.getElementById('rpv-location').textContent = data.location || '—';
  document.getElementById('rpv-dept').textContent     = data.my_department || '—';
  document.getElementById('rpv-phone').textContent    = '+60 ' + (data.phone || '—');
  document.getElementById('rpv-reason').textContent   = data.reason || '—';
  document.getElementById('rpv-attachment').innerHTML = '<span style="color:#bbb;font-style:italic">No attachment</span>';

  // Go back to the editable form (not the preview)
  document.getElementById('reqForm').style.display = '';
  document.getElementById('reqPreviewPanel').style.display = 'none';

  // Reset steps back to step 1
  document.getElementById('req-step1').classList.add('active');
  document.getElementById('req-step1').classList.remove('done');
  document.getElementById('req-step1').querySelector('.step-num').style.cssText = 'background:#0D9488;color:white;border-color:#0D9488;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:600;flex-shrink:0;';
  document.getElementById('req-step2').classList.remove('active');
  document.getElementById('req-step2').querySelector('.step-num').style.cssText = '';

  window._confirmCurrentItem = null;
  window.scrollTo({top:0, behavior:'smooth'});
}

function renderBatchSummary(currentItem) {
  // Update Add Another badges on preview panels
  document.querySelectorAll('.batch-count-badge').forEach(el => {
    el.textContent = batchItems.length;
    el.style.display = batchItems.length > 0 ? 'inline-flex' : 'none';
  });
  // Update the badge on the confirmation panel Add Another button
  const confirmBadge = document.getElementById('confirmAddBadge');
  if (confirmBadge) {
    confirmBadge.textContent = batchItems.length;
    confirmBadge.style.display = batchItems.length > 0 ? 'inline-flex' : 'none';
  }
  // Store currentItem for use when confirmation panel opens
  window._confirmCurrentItem = currentItem;
}

function renderConfirmationSummary() {
  const slot = document.getElementById('confirmationSummarySlot');
  if (slot) slot.innerHTML = getBatchSummaryHtml(window._confirmCurrentItem || null);
  const total = batchItems.length + (window._confirmCurrentItem ? 1 : 0);
  const countEl = document.getElementById('finalSubmitCount');
  if (countEl) countEl.textContent = total;
}

// Only run interactive JS if working hours are active
<?php if ($isWorkingHours): ?>
    // ── AFSMD SUB-TAB SWITCHER ────────────────────────────────────
function switchAfsmdTab(tab) {
  const complaint    = document.getElementById('afsmdComplaintSection');
  const requisition  = document.getElementById('afsmdRequisitionSection');
  const btnComplaint = document.getElementById('subTabComplaint');
  const btnReq       = document.getElementById('subTabRequisition');

  if (tab === 'complaint') {
    complaint.style.display   = '';
    requisition.style.display = 'none';
    // Active pill style
    btnComplaint.style.background  = 'white';
    btnComplaint.style.color       = '#1a3a5c';
    btnComplaint.style.boxShadow   = '0 1px 4px rgba(0,0,0,0.10)';
    // Inactive pill style
    btnReq.style.background  = 'transparent';
    btnReq.style.color       = '#64748b';
    btnReq.style.boxShadow   = 'none';
  } else {
    complaint.style.display   = 'none';
    requisition.style.display = '';
    // Active pill style
    btnReq.style.background  = 'white';
    btnReq.style.color       = '#0D9488';
    btnReq.style.boxShadow   = '0 1px 4px rgba(13,148,136,0.15)';
    // Inactive pill style
    btnComplaint.style.background  = 'transparent';
    btnComplaint.style.color       = '#64748b';
    btnComplaint.style.boxShadow   = 'none';
  }
}
const allCategories  = <?php echo json_encode($dbCategories, JSON_UNESCAPED_UNICODE); ?>;
const deptLabels     = <?php echo json_encode($deptLabelsMap, JSON_UNESCAPED_UNICODE); ?>;
const prevCategoryId = <?php echo (int)$prevCategoryId; ?>;

const progressFill = document.getElementById('progressFill');
const hiddenCatId  = document.getElementById('category_id');
const subcatSel    = document.getElementById('subcategory_select');
const subcatReveal = document.getElementById('subcategoryReveal');
let selectedHandler = '';

// ── TAB BAR LOGIC ──────────────────────────────────────────────
const deptBorderColors = {
  'Information Technology Department':                   '#2563EB',
  'Maintenance Department':                              '#16A34A',
  'Administration & Facilities Management Department':   '#EA580C',
  'Corporate Communication Unit':                        '#7C3AED',
  'Human Capital Department':                            '#E11D48',
};

function applyDeptBorderColor(deptName) {
  const color = deptBorderColors[deptName] || '#b6c6e0';

  // Wrapper border (the outer card — keep this)
  const wrapper = document.getElementById('complaintSectionWrapper');
  if (wrapper) wrapper.style.borderColor = color;

  // Step 1 circle color
  const step1Num = document.querySelector('#step1 .step-num');
  if (step1Num && document.getElementById('step1').classList.contains('active')) {
    step1Num.style.background   = color;
    step1Num.style.borderColor  = color;
    step1Num.style.color        = 'white';
  }

  // Step 1 label color
  const step1Label = document.querySelector('#step1 .step-label');
  if (step1Label) step1Label.style.color = color;

  // Step highlight background
  const step1El = document.getElementById('step1');
  if (step1El) {
    // Use a very light tint of the color for the active step background
    step1El.style.background = color + '18'; // 18 = ~10% opacity hex
  }

  // Form card header icon background tint
  document.querySelectorAll('.fch-icon').forEach(icon => {
    icon.style.background = color + '18';
  });
  document.querySelectorAll('.fch-icon svg').forEach(svg => {
    svg.style.stroke = color;
  });

  // Store as CSS variable for use elsewhere
  document.documentElement.style.setProperty('--active-tab-color', color);
}

function activateTab(tabEl) {
  document.querySelectorAll('.dept-tab').forEach(t => t.classList.remove('active'));
  tabEl.classList.add('active');
  const titleEl = document.getElementById('deptTabbarTitle');
  if (titleEl) titleEl.textContent = tabEl.dataset.dept;
  loadDeptCategories(tabEl.dataset.dept);
  applyDeptBorderColor(tabEl.dataset.dept);

  // Show/hide the AFSMD sub-tabs
  const subTabs = document.getElementById('afsmdSubTabs');
  if (tabEl.dataset.dept === 'Administration & Facilities Management Department') {
    subTabs.style.display = '';
  } else {
    subTabs.style.display = 'none';
    switchAfsmdTab('complaint');
  }
}

// ── REQUISITION FORM JS ───────────────────────────────────────
function reqQtyChange(delta) {
  const el = document.getElementById('req_quantity');
  const v  = (parseInt(el.value) || 1) + delta;
  if (v >= 1 && v <= 999) el.value = v;
}

function reqHandleFile(input) {
  if (input.files.length > 0) {
    document.getElementById('reqFileName').textContent = input.files[0].name;
    document.getElementById('reqFileSelected').style.display = 'flex';
  }
}
function reqRemoveFile() {
  document.getElementById('req_attachment').value = '';
  document.getElementById('reqFileSelected').style.display = 'none';
}
function reqHandleDrop(ev) {
  ev.preventDefault();
  document.getElementById('reqFileDrop').classList.remove('dragover');
  const f = ev.dataTransfer.files[0];
  if (f) {
    const dt = new DataTransfer(); dt.items.add(f);
    const inp = document.getElementById('req_attachment');
    inp.files = dt.files;
    reqHandleFile(inp);
  }
}

// Phone formatting for requisition
(function initReqPhone() {
  const inp  = document.getElementById('req_phone');
  const hint = document.getElementById('reqPhoneHint');
  if (!inp) return;
  inp.addEventListener('input', function() {
    const d = this.value.replace(/\D/g,'');
    this.value = d.length <= 2 ? d : d.slice(0,2) + '-' + d.slice(2,11);
    const len = d.length;
    inp.classList.remove('input-error','input-ok');
    hint.classList.remove('hint-error','hint-ok');
    if (!len) { hint.textContent = 'Enter 9–10 digits (e.g. 11-12345678)'; }
    else if (len < 9) { inp.classList.add('input-error'); hint.classList.add('hint-error'); hint.textContent = 'Too short — minimum 9 digits.'; }
    else if (len > 10) { inp.classList.add('input-error'); hint.classList.add('hint-error'); hint.textContent = 'Too long — maximum 10 digits.'; }
    else { inp.classList.add('input-ok'); hint.classList.add('hint-ok'); hint.textContent = '✓ Looks good!'; }
  });
  inp.addEventListener('keydown', function(e) {
    const ok = ['Backspace','Delete','ArrowLeft','ArrowRight','Tab','Home','End'];
    if (ok.includes(e.key)) return;
    if (!/^\d$/.test(e.key)) e.preventDefault();
  });
})();

// Character counters

document.getElementById('req_reason')?.addEventListener('input', function() {
  const el = document.getElementById('reqReasonCounter');
  el.textContent = this.value.length + ' / 1500';
});

function showReqPreview() {
  const category = document.querySelector('input[name="req_category"]:checked')?.value || '';
  const qty      = parseInt(document.getElementById('req_quantity').value) || 0;
  const dept     = document.getElementById('req_my_department').value;
  const phone    = document.getElementById('req_phone').value.trim();
  const location = document.getElementById('req_location').value.trim();
  const reason   = document.getElementById('req_reason').value.trim();
  const urgency  = document.querySelector('input[name="req_urgency"]:checked')?.value || '';
  const itemName = document.getElementById('req_item_name').value.trim();

  // Clear old errors
  document.querySelectorAll('#afsmdRequisitionSection .input-error').forEach(el => el.classList.remove('input-error'));
  document.querySelectorAll('#afsmdRequisitionSection .inline-err').forEach(el => el.remove());

  let firstErr = null;
  function markReqErr(id, msg) {
    const el = document.getElementById(id); if (!el) return;
    el.classList.add('input-error');
    const w = el.closest('.field'); if (!w) return;
    let h = w.querySelector('.inline-err');
    if (!h) { h = document.createElement('div'); h.className = 'inline-err phone-hint hint-error'; w.appendChild(h); }
    h.textContent = msg;
    firstErr = firstErr || el;
  }

  if (!category) { alert('Please select an equipment category.'); document.getElementById('reqCatPicker').scrollIntoView({behavior:'smooth',block:'center'}); return; }
  if (!qty)      markReqErr('req_quantity','Quantity must be at least 1.');
  if (!dept)     markReqErr('req_my_department','Please select your department.');
  const pd = phone.replace(/\D/g,'');
  if (!phone || pd.length < 9 || pd.length > 10) markReqErr('req_phone', !phone ? 'Phone is required.' : 'Phone must be 9–10 digits.');
  if (!itemName) markReqErr('req_item_name','Please specify the item name or specification.');
  if (!location) markReqErr('req_location','Please specify the delivery location.');
  if (!reason)   markReqErr('req_reason','Please provide a justification.');

  if (firstErr) { firstErr.scrollIntoView({behavior:'smooth',block:'center'}); firstErr.focus(); return; }

  // Populate preview
  const urgLabels  = {normal:'Normal',urgent:'Urgent',critical:'Critical'};
  const urgClasses = {normal:'urg-normal',urgent:'urg-urgent',critical:'urg-critical'};
  document.getElementById('rpv-category').innerHTML = `<span class="preview-badge" style="background:#f0fdfa;color:#0D9488;border-color:rgba(13,148,136,.2)">${esc(category)}</span>`;
  document.getElementById('rpv-urgency').innerHTML  = `<span class="preview-urg-badge ${urgClasses[urgency]||''}">${esc(urgLabels[urgency]||urgency)}</span>`;
  document.getElementById('rpv-quantity').textContent = qty + (qty===1?' unit':' units');
  document.getElementById('rpv-item-name').textContent = itemName;
  document.getElementById('rpv-location').textContent = location;
  document.getElementById('rpv-dept').textContent     = dept;
  document.getElementById('rpv-phone').textContent    = '+60 ' + phone;
  document.getElementById('rpv-reason').textContent   = reason;

  const attEl = document.getElementById('rpv-attachment');
  const fileInp = document.getElementById('req_attachment');
  const fileSel = document.getElementById('reqFileSelected');
  attEl.innerHTML = (fileInp.files.length > 0 && fileSel.style.display !== 'none')
    ? `<span style="color:#0D9488;font-weight:500">${esc(document.getElementById('reqFileName').textContent)}</span>`
    : '<span style="color:#bbb;font-style:italic">No attachment</span>';

  document.getElementById('reqForm').style.display = 'none';
  document.getElementById('reqPreviewPanel').style.display = '';

// Build current item descriptor for the summary
const currentItem = {
  type: 'requisition',
  data: {
    title:         category + ' ×' + qty,
    dept:          'Admin & Facilities Management',
    category:      category,
    quantity:      qty,
    my_department: dept,
    phone:         phone,
    item_name:     itemName,
    location:      location,
    reason:        reason,
    urgency:       urgency,
  }
};
renderBatchSummary(currentItem);

  // Update steps
  document.getElementById('req-step1').classList.remove('active'); document.getElementById('req-step1').classList.add('done');
  document.getElementById('req-step1').querySelector('.step-num').style.cssText = 'background:#4CAF50;color:white;border-color:#4CAF50;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:600;flex-shrink:0;';
  document.getElementById('req-step2').classList.add('active');
  document.getElementById('req-step2').querySelector('.step-num').style.cssText = 'background:#0D9488;color:white;border-color:#0D9488;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:600;flex-shrink:0;';
  window.scrollTo({top:0,behavior:'smooth'});
}

function backToReqForm() {
  document.getElementById('reqForm').style.display = '';
  document.getElementById('reqPreviewPanel').style.display = 'none';
  document.getElementById('req-step1').classList.add('active'); document.getElementById('req-step1').classList.remove('done');
  document.getElementById('req-step1').querySelector('.step-num').style.cssText = 'background:#4CAF50;color:white;border-color:#4CAF50;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:600;flex-shrink:0;';
  document.getElementById('req-step2').classList.remove('active');
  document.getElementById('req-step2').querySelector('.step-num').style.cssText = '';
  window.scrollTo({top:0,behavior:'smooth'});
}

function submitReqForm() {
  const btn = document.getElementById('reqConfirmBtn');
  btn.disabled = true;
  btn.innerHTML = 'Submitting… <svg viewBox="0 0 24 24" width="16" height="16" style="animation:spin 0.8s linear infinite;fill:none;stroke:white;stroke-width:2"><circle cx="12" cy="12" r="10" stroke-opacity="0.25"/><path d="M12 2a10 10 0 0 1 10 10"/></svg>';
  document.getElementById('req-step2').classList.remove('active'); document.getElementById('req-step2').classList.add('done');
  document.getElementById('req-step3').classList.add('active');
  document.getElementById('req-step3').querySelector('.step-num').style.cssText = 'background:#0D9488;color:white;border-color:#0D9488;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:600;flex-shrink:0;';
  document.getElementById('reqForm').submit();
}

function loadDeptCategories(deptName) {
  hiddenCatId.value = '';
  selectedHandler = '';
  document.getElementById('deptHint').classList.remove('show');
  subcatSel.innerHTML = '<option value="">— Select Category —</option>';

  if (!deptName || !allCategories[deptName]) {
    subcatSel.disabled = true;
    updateProgress();
    return;
  }
  allCategories[deptName].forEach(cat => {
    const o = document.createElement('option');
    o.value = cat.id;
    const si = cat.name.indexOf(' / ');
    o.textContent = si !== -1 ? cat.name.substring(si + 3) : cat.name;
    subcatSel.appendChild(o);
  });
  subcatSel.disabled = false;
  subcatReveal.classList.add('active');
  updateProgress();
}

document.querySelectorAll('.dept-tab').forEach(tab => {
  tab.addEventListener('click', function() {
    subcatSel.value = '';
    hiddenCatId.value = '';
    selectedHandler = '';
    document.getElementById('deptHint').classList.remove('show');
    activateTab(this);
  });
});

(function initTabs() {
  const firstTab = document.querySelector('.dept-tab.active');
  if (firstTab) {
    const titleEl = document.getElementById('deptTabbarTitle');
    if (titleEl) titleEl.textContent = firstTab.dataset.dept;
    loadDeptCategories(firstTab.dataset.dept);
    applyDeptBorderColor(firstTab.dataset.dept);
    const subTabs = document.getElementById('afsmdSubTabs');
    if (firstTab.dataset.dept === 'Administration & Facilities Management Department') {
      subTabs.style.display = '';
    }
  }

  // ── Auto-open tab from ?dept_tab= or ?tab=requisition ──
  const urlParams = new URLSearchParams(window.location.search);
  const deptTabParam = urlParams.get('dept_tab');
  if (deptTabParam) {
    const targetTab = document.querySelector(`.dept-tab[data-dept="${deptTabParam}"]`);
    if (targetTab) {
      targetTab.click();
    }
  }
  if (urlParams.get('tab') === 'requisition') {
    const afsmdTab = document.querySelector('.dept-tab[data-dept="Administration & Facilities Management Department"]');
    if (afsmdTab) {
      afsmdTab.click();
    }
    switchAfsmdTab('requisition');
  }
})();

subcatSel.addEventListener('change', function() {
  const id = parseInt(this.value);
  hiddenCatId.value = id || '';
  if (id && deptLabels[id]) {
    selectedHandler = deptLabels[id];
    document.getElementById('deptHintText').textContent = 'Will be handled by: ' + deptLabels[id];
    document.getElementById('deptHint').classList.add('show');
  } else {
    selectedHandler = '';
    document.getElementById('deptHint').classList.remove('show');
  }
  updateProgress();
});

function updateProgress(){
  const f=[hiddenCatId.value,document.getElementById('description').value.trim(),document.getElementById('phone').value.trim(),document.getElementById('my_department').value];
  progressFill.style.width=Math.round((f.filter(Boolean).length/4)*100)+'%';
}
function updateCounter(id,len,max){
  const el=document.getElementById(id);
  el.textContent=len+' / '+max;
  el.classList.remove('warn','danger');
  if(len>=max*0.9)el.classList.add('danger');
  else if(len>=max*0.75)el.classList.add('warn');
}

document.getElementById('description').addEventListener('input',function(){updateCounter('descCounter',this.value.length,2000);updateProgress();});
(function initPhone(){
  const phoneInput = document.getElementById('phone');
  const phoneHint  = document.getElementById('phoneHint');

  function formatPhone(raw) {
    // Strip everything except digits
    const digits = raw.replace(/\D/g, '');
    if (digits.length === 0) return '';
    // Insert dash after 2nd digit
    if (digits.length <= 2) return digits;
    return digits.slice(0, 2) + '-' + digits.slice(2, 11);
  }

  function validatePhone(val) {
    const digits = val.replace(/\D/g, '');
    if (digits.length === 0)  return 'empty';
    if (digits.length < 9)    return 'short';
    if (digits.length > 10)   return 'long';
    return 'ok';
  }

  phoneInput.addEventListener('input', function() {
    const cursor   = this.selectionStart;
    const oldLen   = this.value.length;
    const formatted = formatPhone(this.value);
    this.value     = formatted;
    // Restore cursor position roughly
    const diff = formatted.length - oldLen;
    this.setSelectionRange(cursor + diff, cursor + diff);

    const state = validatePhone(formatted);
    this.classList.remove('input-error','input-ok');
    phoneHint.classList.remove('hint-error','hint-ok');

    if (state === 'empty') {
      phoneHint.textContent = 'Enter 9–10 digits — dash will be added automatically (e.g. 11-12345678)';
    } else if (state === 'short') {
      this.classList.add('input-error');
      phoneHint.classList.add('hint-error');
      phoneHint.textContent = 'Too short — minimum 9 digits required.';
    } else if (state === 'long') {
      this.classList.add('input-error');
      phoneHint.classList.add('hint-error');
      phoneHint.textContent = 'Too long — maximum 10 digits allowed.';
    } else {
      this.classList.add('input-ok');
      phoneHint.classList.add('hint-ok');
      phoneHint.textContent = '✓ Looks good!';
    }
    updateProgress();
  });

  // Block non-digit keys (allow control keys)
  phoneInput.addEventListener('keydown', function(e) {
    const allowed = ['Backspace','Delete','ArrowLeft','ArrowRight','Tab','Home','End'];
    if (allowed.includes(e.key)) return;
    if (!/^\d$/.test(e.key)) e.preventDefault();
  });

  // Validate existing value on page load (if repopulated after POST error)
  if (phoneInput.value) phoneInput.dispatchEvent(new Event('input'));
})();
document.getElementById('my_department').addEventListener('change',updateProgress);

const fileInput=document.getElementById('attachment');
const fileDrop=document.getElementById('fileDrop');
const fileSel=document.getElementById('fileSelected');
const fileNameEl=document.getElementById('fileName');
document.getElementById('fileRemove').addEventListener('click',()=>{fileInput.value='';fileSel.style.display='none';});
fileInput.addEventListener('change',function(){if(this.files.length>0){fileNameEl.textContent=this.files[0].name;fileSel.style.display='flex';}});
['dragenter','dragover'].forEach(e=>fileDrop.addEventListener(e,ev=>{ev.preventDefault();fileDrop.classList.add('dragover');}));
['dragleave','drop'].forEach(e=>fileDrop.addEventListener(e,ev=>{ev.preventDefault();fileDrop.classList.remove('dragover');}));
fileDrop.addEventListener('drop',ev=>{const f=ev.dataTransfer.files[0];if(f){const dt=new DataTransfer();dt.items.add(f);fileInput.files=dt.files;fileNameEl.textContent=f.name;fileSel.style.display='flex';}});

function showPreview(){
const phone   = document.getElementById('phone').value.trim();
const dept    = document.getElementById('my_department').value;
const catId   = hiddenCatId.value;
const desc    = document.getElementById('description').value.trim();

  clearInlineErrors();

  let firstError = null;

  // 1. Phone
  const phoneDigits = phone.replace(/\D/g,'');
  if(!phone || phoneDigits.length < 9 || phoneDigits.length > 10){
    markFieldError('phone', !phone ? 'Phone number is required.' : 'Phone must be 9–10 digits (e.g. 11-12345678).');
    firstError = firstError || document.getElementById('phone');
  }

  // 2. My Department / Faculty
  if(!dept){
    markFieldError('my_department', 'Please select your department or faculty.');
    firstError = firstError || document.getElementById('my_department');
  }

  // 3. Sub-category only (dept is set by tab)
  if(!catId){
    markSelectError('subcategory_select', 'Please select a sub-category.');
    firstError = firstError || document.getElementById('subcategory_select');
  }

  // 4. Description
  if(!desc){
    markFieldError('description', 'Description is required.');
    firstError = firstError || document.getElementById('description');
  }

  // If any error found, scroll to the first one and stop
  if(firstError){
    firstError.scrollIntoView({behavior:'smooth', block:'center'});
    firstError.focus();
    return;
  }

  // All valid — build the preview
document.getElementById('pv-phone').textContent = '+60 ' + phone;
document.getElementById('pv-dept').textContent  = dept;
document.getElementById('pv-description').textContent = desc;

  const subcatText = subcatSel.options[subcatSel.selectedIndex]?.text || '—';
  document.getElementById('pv-category').innerHTML =
    `<span class="preview-badge">${esc(subcatText)}</span>`;
  document.getElementById('pv-handler').innerHTML = selectedHandler
    ? `<span class="preview-dept-badge">${esc(selectedHandler)}</span>`
    : '—';

  const attEl = document.getElementById('pv-attachment');
  attEl.innerHTML = (fileInput.files.length > 0 && fileSel.style.display !== 'none')
    ? `<span class="file-preview-val">${esc(fileNameEl.textContent)}</span>`
    : '<span class="no-file">No attachment</span>';

  const slaRow = document.getElementById('previewSlaRow');
  const slaTxt = document.getElementById('previewSlaText');
  slaRow.style.display = 'flex';
  slaRow.className = 'preview-sla-row sla-open';
  slaTxt.textContent = 'Working hours active — complaint will be attended to today.';

document.getElementById('complaintForm').style.display = 'none';
document.getElementById('previewPanel').classList.add('show');

// Build current item descriptor for the summary
const currentItem = {
  type: 'complaint',
  data: {
    title:         subcatSel.options[subcatSel.selectedIndex]?.text || '—',
    dept:          selectedHandler || '—',
    category_id:   hiddenCatId.value,
    description:   desc,
    phone:         phone,
    my_department: dept,
  }
};
renderBatchSummary(currentItem);

document.getElementById('step1').classList.remove('active');
document.getElementById('step1').classList.add('done');
document.getElementById('step2').classList.add('active');
progressFill.style.width = '66%';

// Hide everything except the preview
document.getElementById('deptTabs').closest('.dept-tabbar').style.display = 'none';
document.getElementById('afsmdSubTabs').style.display = 'none';
document.querySelector('.wh-banner').style.display = 'none';

window.scrollTo({top:0, behavior:'smooth'});
}

function editCurrentItem() {
  const item = window._confirmCurrentItem;
  if (!item) return;
  window._confirmCurrentItem = null;
  document.getElementById('confirmationPanel').style.display = 'none';
  renderBatchSummary(null);

  if (item.type === 'complaint') {
    restoreComplaintToPreview(item.data);
  } else {
    restoreReqToPreview(item.data);
  }
}

function cancelCurrentItem() {
  showCancelModal(function() {
    window._confirmCurrentItem = null;

  // If no queued items left either, go back to the blank form
  if (batchItems.length === 0) {
    document.getElementById('confirmationPanel').style.display = 'none';
    document.getElementById('afsmdComplaintSection').style.display = '';
    document.getElementById('afsmdRequisitionSection').style.display = 'none';
    // Restore tabs and banner
    const tabbar = document.getElementById('deptTabs')?.closest('.dept-tabbar');
    if (tabbar) tabbar.style.display = '';
    const activeDept = document.querySelector('.dept-tab.active')?.dataset?.dept;
    if (activeDept === 'Administration & Facilities Management Department') {
      document.getElementById('afsmdSubTabs').style.display = '';
    }
    const banner = document.querySelector('.wh-banner');
    if (banner) banner.style.display = '';
    // Reset steps
    ['step1','step2','step3'].forEach(id => {
      const el = document.getElementById(id); if (!el) return;
      el.classList.remove('active','done');
      el.querySelector('.step-num').style.cssText = '';
    });
    document.getElementById('step1').classList.add('active');
    // Make sure the form is visible and preview is hidden
    const complaintForm = document.getElementById('complaintForm');
    if (complaintForm) complaintForm.style.display = '';
    const previewPanel = document.getElementById('previewPanel');
    if (previewPanel) { previewPanel.classList.remove('show'); previewPanel.style.display = ''; }
    window.scrollTo({top:0, behavior:'smooth'});
    return;
  }

  // Still have queued items — promote the last queued item to "current"
  const promoted = batchItems.pop();
  window._confirmCurrentItem = promoted;
  renderConfirmationSummary();
  updateConfirmCount();
  }); // end showCancelModal
}

function showCancelModal(onConfirm) {
  const modal = document.getElementById('cancelConfirmModal');
  modal.style.display = 'flex';
  const yesBtn = document.getElementById('cancelModalYes');
  const noBtn  = document.getElementById('cancelModalNo');
  // Clone to remove old listeners
  const newYes = yesBtn.cloneNode(true);
  const newNo  = noBtn.cloneNode(true);
  yesBtn.parentNode.replaceChild(newYes, yesBtn);
  noBtn.parentNode.replaceChild(newNo, noBtn);
  newYes.addEventListener('click', function() {
    modal.style.display = 'none';
    onConfirm();
  });
  newNo.addEventListener('click', function() {
    modal.style.display = 'none';
  });
  // Close on backdrop click
  modal.addEventListener('click', function handler(e) {
    if (e.target === modal) {
      modal.style.display = 'none';
      modal.removeEventListener('click', handler);
    }
  });
}

function markFieldError(fieldId, msg){
  const el = document.getElementById(fieldId);
  if(!el) return;
  el.classList.add('input-error');
  const wrap = el.closest('.field');
  if(!wrap) return;
  let hint = wrap.querySelector('.inline-err');
  if(!hint){
    hint = document.createElement('div');
    hint.className = 'inline-err phone-hint hint-error';
    wrap.appendChild(hint);
  }
  hint.textContent = msg;
}

function markSelectError(fieldId, msg){
  const el = document.getElementById(fieldId);
  if(!el) return;
  el.style.borderColor = '#E53935';
  el.style.boxShadow   = '0 0 0 3px rgba(229,57,53,0.10)';
  const block = el.closest('.cat-step-block');
  if(!block) return;
  let hint = block.querySelector('.inline-err');
  if(!hint){
    hint = document.createElement('div');
    hint.className = 'inline-err phone-hint hint-error';
    block.appendChild(hint);
  }
  hint.textContent = msg;
}

function clearInlineErrors(){
  document.querySelectorAll('.input-error').forEach(el => el.classList.remove('input-error'));
  const subcatEl = document.getElementById('subcategory_select');
  if(subcatEl){ subcatEl.style.borderColor = ''; subcatEl.style.boxShadow = ''; }
  document.querySelectorAll('.inline-err').forEach(el => el.remove());
}

function backToForm(){
  document.getElementById('complaintForm').style.display='';
  document.getElementById('previewPanel').classList.remove('show');
  document.getElementById('step1').classList.add('active');
  document.getElementById('step1').classList.remove('done');
  document.getElementById('step2').classList.remove('active');

  // Restore hidden elements
  document.getElementById('deptTabs').closest('.dept-tabbar').style.display = '';
  const activeDept = document.querySelector('.dept-tab.active')?.dataset?.dept;
  if (activeDept === 'Administration & Facilities Management Department') {
    document.getElementById('afsmdSubTabs').style.display = '';
  }
  document.querySelector('.wh-banner').style.display = '';

  updateProgress();
  window.scrollTo({top:0, behavior:'smooth'});
}
function submitForm(){
  const btn=document.getElementById('confirmBtn');btn.disabled=true;
  btn.innerHTML='Submitting… <svg viewBox="0 0 24 24" width="16" height="16" style="animation:spin 0.8s linear infinite;fill:none;stroke:white;stroke-width:2"><circle cx="12" cy="12" r="10" stroke-opacity="0.25"/><path d="M12 2a10 10 0 0 1 10 10"/></svg>';
  document.getElementById('step2').classList.remove('active');document.getElementById('step2').classList.add('done');
  document.getElementById('step3').classList.add('active');progressFill.style.width='100%';
  document.getElementById('complaintForm').submit();
}
function showAlert(msg){
  let ex=document.getElementById('jsAlert');if(ex)ex.remove();
  const d=document.createElement('div');d.id='jsAlert';d.className='alert alert-error';d.style.marginBottom='16px';
  d.innerHTML=`<svg viewBox="0 0 24 24" style="fill:none;stroke:#B71C1C;stroke-width:2;width:18px;height:18px;flex-shrink:0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><div>${msg}</div>`;
  const form=document.getElementById('complaintForm');form.parentNode.insertBefore(d,form);
  d.scrollIntoView({behavior:'smooth',block:'center'});setTimeout(()=>d.remove(),4000);
}
function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

// ── BATCH LOGIC ───────────────────────────────────────────────

function addComplaintToBatch() {
  // Capture current complaint form data into batchItems
  const catId   = document.getElementById('category_id').value;
  const catText = document.getElementById('subcategory_select').options[document.getElementById('subcategory_select').selectedIndex]?.text || '—';
  const dept    = selectedHandler || '—';
  const desc    = document.getElementById('description').value.trim();
  const phone   = document.getElementById('phone').value.trim();
  const myDept  = document.getElementById('my_department').value;
  const fileInp = document.getElementById('attachment');
  const fileName= (fileInp.files.length > 0 && document.getElementById('fileSelected').style.display !== 'none')
                  ? document.getElementById('fileName').textContent : '';

  batchItems.push({
    type: 'complaint',
    data: {
      title:       catText,
      dept:        dept,
      category_id: catId,
      description: desc,
      phone:       phone,
      my_department: myDept,
      fileName:    fileName,
      // Store file object for later FormData submission
      fileObj:     (fileInp.files.length > 0 ? fileInp.files[0] : null),
    }
  });

  // Reset form for next entry
  resetComplaintForm();
  renderBatchSummary(null);
  updateConfirmCount();
  openAddAnotherModal();
}

function addReqToBatch() {
  const category = document.querySelector('input[name="req_category"]:checked')?.value || '';
  const qty      = parseInt(document.getElementById('req_quantity').value) || 0;
  const dept     = document.getElementById('req_my_department').value;
  const phone    = document.getElementById('req_phone').value.trim();
  const itemName = document.getElementById('req_item_name').value.trim();
  const location = document.getElementById('req_location').value.trim();
  const reason   = document.getElementById('req_reason').value.trim();
  const urgency  = document.querySelector('input[name="req_urgency"]:checked')?.value || 'normal';
  const fileInp  = document.getElementById('req_attachment');
  const fileName = (fileInp.files.length > 0 && document.getElementById('reqFileSelected').style.display !== 'none')
                   ? document.getElementById('reqFileName').textContent : '';

  batchItems.push({
    type: 'requisition',
    data: {
      title:         category + ' ×' + qty,
      dept:          'Admin & Facilities Management',
      category:      category,
      quantity:      qty,
      my_department: dept,
      phone:         phone,
      item_name:     itemName,
      location:      location,
      reason:        reason,
      urgency:       urgency,
      fileName:      fileName,
      fileObj:       (fileInp.files.length > 0 ? fileInp.files[0] : null),
    }
  });

  resetReqForm();
  renderBatchSummary(null);
  updateConfirmCount();
  openAddAnotherModal();
}

function resetComplaintForm() {
  document.getElementById('category_id').value = '';
  document.getElementById('subcategory_select').value = '';
  document.getElementById('subcategory_select').disabled = true;
  document.getElementById('description').value = '';
  document.getElementById('attachment').value  = '';
  document.getElementById('fileSelected').style.display = 'none';
  document.getElementById('deptHint').classList.remove('show');
  selectedHandler = '';
  updateProgress();
  updateCounter('descCounter', 0, 2000);
  // Go back to form view
  document.getElementById('complaintForm').style.display = '';
  document.getElementById('previewPanel').classList.remove('show');
  document.getElementById('step1').classList.add('active');
  document.getElementById('step1').classList.remove('done');
  document.getElementById('step2').classList.remove('active');
  document.getElementById('deptTabs').closest('.dept-tabbar').style.display = '';
  const activeDept = document.querySelector('.dept-tab.active')?.dataset?.dept;
  if (activeDept === 'Administration & Facilities Management Department') {
    document.getElementById('afsmdSubTabs').style.display = '';
  }
  document.querySelector('.wh-banner').style.display = '';
  window.scrollTo({top:0, behavior:'smooth'});
}

function resetReqForm() {
  document.querySelectorAll('input[name="req_category"]').forEach(r => r.checked = false);
  document.getElementById('req_quantity').value = 1;
  document.getElementById('req_my_department').value = '';
  document.getElementById('req_phone').value = '';
  document.getElementById('req_item_name').value = '';
  document.getElementById('req_location').value = '';
  document.getElementById('req_reason').value = '';
  document.getElementById('req_attachment').value = '';
  document.getElementById('reqFileSelected').style.display = 'none';
  const urgNormal = document.getElementById('req-urg-normal');
  if (urgNormal) urgNormal.checked = true;
  // Go back to req form view
  document.getElementById('reqForm').style.display = '';
  document.getElementById('reqPreviewPanel').style.display = 'none';
  document.getElementById('req-step1').classList.add('active');
  document.getElementById('req-step1').classList.remove('done');
  document.getElementById('req-step2').classList.remove('active');
  window.scrollTo({top:0, behavior:'smooth'});
}

function updateConfirmCount() {
  const total = batchItems.length + (window._confirmCurrentItem ? 1 : 0);
  const countEl = document.getElementById('finalSubmitCount');
  if (countEl) countEl.textContent = total;
  // Update the Add Another badge in confirmation panel
  const confirmBadge = document.getElementById('confirmAddBadge');
  if (confirmBadge) {
    confirmBadge.textContent = batchItems.length;
    confirmBadge.style.display = batchItems.length > 0 ? 'inline-flex' : 'none';
  }
}

function openAddAnotherModal() {
  const modal = document.getElementById('addAnotherModal');
  modal.style.display = 'flex';
}

function closeAddAnotherModal() {
  document.getElementById('addAnotherModal').style.display = 'none';
}

function pickAddAnotherDept(deptName, mode) {
  closeAddAnotherModal();

  // Save the current item into the batch queue before navigating away
  if (window._confirmCurrentItem) {
    batchItems.push(window._confirmCurrentItem);
    window._confirmCurrentItem = null;
  }

  // Hide the confirmation panel
  document.getElementById('confirmationPanel').style.display = 'none';

  // Restore tabs and banners
  const tabbar = document.getElementById('deptTabs')?.closest('.dept-tabbar');
  if (tabbar) tabbar.style.display = '';
  const banner = document.querySelector('.wh-banner');
  if (banner) banner.style.display = '';

  // ── Always reset the complaint form to a clean Step 1 state ──
  const complaintForm = document.getElementById('complaintForm');
  const previewPanel  = document.getElementById('previewPanel');
  if (complaintForm) complaintForm.style.display = '';
  if (previewPanel)  { previewPanel.classList.remove('show'); previewPanel.style.display = ''; }

  // Reset step indicators to step 1 active
  ['step1','step2','step3'].forEach(id => {
    const el = document.getElementById(id); if (!el) return;
    el.classList.remove('active','done');
    el.querySelector('.step-num').style.cssText = '';
    el.style.background = '';
  });
  document.getElementById('step1').classList.add('active');

  // Clear all complaint form fields
  document.getElementById('category_id').value = '';
  document.getElementById('subcategory_select').value = '';
  document.getElementById('subcategory_select').disabled = true;
  document.getElementById('description').value = '';
  document.getElementById('attachment').value  = '';
  document.getElementById('fileSelected').style.display = 'none';
  document.getElementById('deptHint').classList.remove('show');
  selectedHandler = '';
  updateProgress();
  updateCounter('descCounter', 0, 2000);

  if (deptName === 'Administration & Facilities Management Department') {
    const afsmdTab = document.querySelector(`.dept-tab[data-dept="Administration & Facilities Management Department"]`);
    if (afsmdTab) activateTab(afsmdTab);

    document.getElementById('afsmdComplaintSection').style.display    = (mode === 'complaint')    ? '' : 'none';
    document.getElementById('afsmdRequisitionSection').style.display  = (mode === 'requisition')  ? '' : 'none';

    if (mode === 'requisition') {
      switchAfsmdTab('requisition');
      // Reset req form fields to blank
      document.querySelectorAll('input[name="req_category"]').forEach(r => r.checked = false);
      document.getElementById('req_quantity').value = 1;
      document.getElementById('req_my_department').value = '';
      document.getElementById('req_phone').value = '';
      document.getElementById('req_item_name').value = '';
      document.getElementById('req_location').value = '';
      document.getElementById('req_reason').value = '';
      document.getElementById('req_attachment').value = '';
      document.getElementById('reqFileSelected').style.display = 'none';
      const urgNormal = document.getElementById('req-urg-normal');
      if (urgNormal) urgNormal.checked = true;
      // Clear any inline errors
      document.querySelectorAll('#afsmdRequisitionSection .input-error').forEach(el => el.classList.remove('input-error'));
      document.querySelectorAll('#afsmdRequisitionSection .inline-err').forEach(el => el.remove());
      // Reset req form to step 1
      document.getElementById('reqForm').style.display = '';
      document.getElementById('reqPreviewPanel').style.display = 'none';
      ['req-step1','req-step2','req-step3'].forEach(id => {
        const el = document.getElementById(id); if (!el) return;
        el.classList.remove('active','done');
        el.querySelector('.step-num').style.cssText = '';
      });
      document.getElementById('req-step1').classList.add('active');
      document.getElementById('req-step1').querySelector('.step-num').style.cssText =
        'background:#0D9488;color:white;border-color:#0D9488;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:600;flex-shrink:0;';
    } else {
      switchAfsmdTab('complaint');
    }

  } else {
    document.getElementById('afsmdRequisitionSection').style.display = 'none';
    document.getElementById('afsmdComplaintSection').style.display   = '';
    const targetTab = document.querySelector(`.dept-tab[data-dept="${deptName}"]`);
    if (targetTab) activateTab(targetTab);
    switchAfsmdTab('complaint');
  }

  window.scrollTo({top:0, behavior:'smooth'});
}
function showConfirmationPanel() {
  // Determine which preview is currently visible
  const complaintVisible = document.getElementById('previewPanel').classList.contains('show');
  const reqVisible = document.getElementById('reqPreviewPanel')?.style.display !== 'none' &&
                     document.getElementById('reqPreviewPanel')?.style.display !== '';

  // Build current item descriptor if not already set
  if (complaintVisible && !window._confirmCurrentItem) {
    const catSel = document.getElementById('subcategory_select');
    window._confirmCurrentItem = {
      type: 'complaint',
      data: {
        title:         catSel?.options[catSel?.selectedIndex]?.text || '—',
        dept:          selectedHandler || '—',
        category_id:   document.getElementById('category_id').value,
        description:   document.getElementById('description').value.trim(),
        phone:         document.getElementById('phone').value.trim(),
        my_department: document.getElementById('my_department').value,
      }
    };
  } else if (reqVisible && !window._confirmCurrentItem) {
    const cat = document.querySelector('input[name="req_category"]:checked')?.value || '—';
    const qty = parseInt(document.getElementById('req_quantity')?.value) || 1;
    window._confirmCurrentItem = {
      type: 'requisition',
      data: {
        title:         cat + ' ×' + qty,
        dept:          'Admin & Facilities Management',
        category:      cat,
        quantity:      qty,
        my_department: document.getElementById('req_my_department')?.value || '—',
        phone:         document.getElementById('req_phone')?.value.trim() || '—',
        item_name:     document.getElementById('req_item_name')?.value.trim() || '—',
        location:      document.getElementById('req_location')?.value.trim() || '—',
        reason:        document.getElementById('req_reason')?.value.trim() || '—',
        urgency:       document.querySelector('input[name="req_urgency"]:checked')?.value || 'normal',
      }
    };
  }

  // Hide preview panels, show complaint/req sections hidden
  document.getElementById('previewPanel').classList.remove('show');
  document.getElementById('previewPanel').style.display = 'none';
  if (document.getElementById('reqPreviewPanel')) {
    document.getElementById('reqPreviewPanel').style.display = 'none';
  }
  document.getElementById('afsmdComplaintSection').style.display = 'none';
  document.getElementById('afsmdRequisitionSection').style.display = 'none';

  // Show confirmation panel
  const panel = document.getElementById('confirmationPanel');
  panel.style.display = '';
  renderConfirmationSummary();

  // Update steps for both complaint and req
  ['step1','step2'].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.remove('active'); el.classList.add('done');
    el.querySelector('.step-num').style.cssText = 'background:#4CAF50;color:white;border-color:#4CAF50;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:600;flex-shrink:0;';
  });
  const s3 = document.getElementById('step3');
  if (s3) { s3.classList.add('active'); }

  ['req-step1','req-step2'].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.remove('active'); el.classList.add('done');
    el.querySelector('.step-num').style.cssText = 'background:#4CAF50;color:white;border-color:#4CAF50;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:600;flex-shrink:0;';
  });
  const rs3 = document.getElementById('req-step3');
  if (rs3) { rs3.classList.add('active'); }

  // Hide dept tabs and banners
  const tabbar = document.getElementById('deptTabs')?.closest('.dept-tabbar');
  if (tabbar) tabbar.style.display = 'none';
  document.getElementById('afsmdSubTabs').style.display = 'none';
  const banner = document.querySelector('.wh-banner');
  if (banner) banner.style.display = 'none';

  window.scrollTo({top:0, behavior:'smooth'});
}

function backToPreviewFromConfirmation() {
  const panel = document.getElementById('confirmationPanel');
  panel.style.display = 'none';

  // Determine which preview to go back to
  const currentItem = window._confirmCurrentItem;
  const isReq = currentItem?.type === 'requisition';

  if (isReq) {
    document.getElementById('afsmdRequisitionSection').style.display = '';
    document.getElementById('reqPreviewPanel').style.display = '';
    document.getElementById('reqForm').style.display = 'none';
    // Restore step states
    const rs3 = document.getElementById('req-step3'); if (rs3) rs3.classList.remove('active');
    const rs2 = document.getElementById('req-step2');
    if (rs2) { rs2.classList.add('active'); rs2.classList.remove('done'); }
  } else {
    document.getElementById('afsmdComplaintSection').style.display = '';
    document.getElementById('previewPanel').classList.add('show');
    document.getElementById('previewPanel').style.display = '';
    document.getElementById('complaintForm').style.display = 'none';
    // Restore step states
    const s3 = document.getElementById('step3'); if (s3) s3.classList.remove('active');
    const s2 = document.getElementById('step2');
    if (s2) { s2.classList.add('active'); s2.classList.remove('done'); }
  }

  // Restore dept tabs and banners
  const tabbar = document.getElementById('deptTabs')?.closest('.dept-tabbar');
  if (tabbar) tabbar.style.display = '';
  const activeDept = document.querySelector('.dept-tab.active')?.dataset?.dept;
  if (activeDept === 'Administration & Facilities Management Department') {
    document.getElementById('afsmdSubTabs').style.display = '';
  }
  const banner = document.querySelector('.wh-banner');
  if (banner) banner.style.display = '';

  window.scrollTo({top:0, behavior:'smooth'});
}

async function submitAllBatch() {
  // Disable the final submit button
  ['confirmBtn','reqConfirmBtn','finalSubmitBtn'].forEach(id => {
    const b = document.getElementById(id); if (!b) return;
    b.disabled = true;
    b.innerHTML = 'Submitting… <svg viewBox="0 0 24 24" width="16" height="16" style="animation:spin 0.8s linear infinite;fill:none;stroke:white;stroke-width:2"><circle cx="12" cy="12" r="10" stroke-opacity="0.25"/><path d="M12 2a10 10 0 0 1 10 10"/></svg>';
  });

  // Build one FormData for the whole batch
  const fd = new FormData();
  fd.append('batch_submit', '1');
  fd.append('batch_count', String(batchItems.length + 1));

  // Use stored current item from confirmation step
  const currentItem = window._confirmCurrentItem;
  const complaintPreviewVisible = currentItem?.type === 'complaint';
  const reqPreviewVisible       = currentItem?.type === 'requisition';

  if (complaintPreviewVisible) {
    fd.append('items[current][type]',           'complaint');
    fd.append('items[current][category_id]',    currentItem.data.category_id);
    fd.append('items[current][description]',    currentItem.data.description);
    fd.append('items[current][phone]',          currentItem.data.phone);
    fd.append('items[current][my_department]',  currentItem.data.my_department);
    const curFile = document.getElementById('attachment').files[0];
    if (curFile) fd.append('attachments[current]', curFile);
  } else if (reqPreviewVisible) {
    fd.append('items[current][type]',           'requisition');
    fd.append('items[current][category]',       currentItem.data.category);
    fd.append('items[current][quantity]',       String(currentItem.data.quantity));
    fd.append('items[current][my_department]',  currentItem.data.my_department);
    fd.append('items[current][phone]',          currentItem.data.phone);
    fd.append('items[current][item_name]',      currentItem.data.item_name || '');
    fd.append('items[current][location]',       currentItem.data.location);
    fd.append('items[current][reason]',         currentItem.data.reason);
    fd.append('items[current][urgency]',        currentItem.data.urgency || 'normal');
    const curFile = document.getElementById('req_attachment').files[0];
    if (curFile) fd.append('attachments[current]', curFile);
  }

  // Append queued batch items
  batchItems.forEach((item, idx) => {
    fd.append(`items[${idx}][type]`, item.type);
    Object.entries(item.data).forEach(([k, v]) => {
      if (k !== 'fileObj' && v !== null && v !== undefined) {
        fd.append(`items[${idx}][${k}]`, String(v));
      }
    });
    if (item.data.fileObj) {
      fd.append(`attachments[${idx}]`, item.data.fileObj);
    }
  });

  try {
    const resp = await fetch('batch_submit.php', { method: 'POST', body: fd });
    const json = await resp.json();
    if (json.success) {
      showBatchSuccessOverlay(json.tickets, json.requisitions);
    } else {
      alert('Submission failed: ' + (json.error || 'Unknown error'));
      ['confirmBtn','reqConfirmBtn'].forEach(id => {
        const b = document.getElementById(id); if(b) { b.disabled = false; b.textContent = 'Submit All'; }
      });
    }
  } catch(e) {
    alert('Network error. Please try again.');
  }
}

function showBatchSuccessOverlay(tickets, requisitions) {
  const overlay = document.getElementById('batchSuccessOverlay');
  const list    = document.getElementById('batchSuccessList');
  let html = '';
  (tickets || []).forEach(t => {
    html += `<div style="display:flex;align-items:center;gap:10px;padding:10px 14px;
                          background:#f0f5ff;border:1px solid rgba(0,59,142,.15);border-radius:8px;margin-bottom:6px;">
      <span style="font-size:10px;font-weight:700;background:var(--blue);color:white;
                   padding:2px 8px;border-radius:100px;">TICKET</span>
      <span style="font-family:monospace;font-size:14px;font-weight:700;color:var(--g900);">${esc(t)}</span>
    </div>`;
  });
  (requisitions || []).forEach(r => {
    html += `<div style="display:flex;align-items:center;gap:10px;padding:10px 14px;
                          background:#f0fdfa;border:1px solid rgba(13,148,136,.2);border-radius:8px;margin-bottom:6px;">
      <span style="font-size:10px;font-weight:700;background:#0D9488;color:white;
                   padding:2px 8px;border-radius:100px;">REQUEST</span>
      <span style="font-family:monospace;font-size:14px;font-weight:700;color:var(--g900);">${esc(r)}</span>
    </div>`;
  });
  list.innerHTML = html;
  overlay.classList.add('show');
}

(function init(){updateProgress();const de=document.getElementById('description');if(de.value)updateCounter('descCounter',de.value.length,2000);})();

// Clear inline errors when user corrects a field
document.getElementById('description').addEventListener('input', function(){
  this.classList.remove('input-error');
  const hint = this.closest('.field')?.querySelector('.inline-err');
  if(hint) hint.remove();
});
document.getElementById('my_department').addEventListener('change', function(){
  this.classList.remove('input-error');
  const hint = this.closest('.field')?.querySelector('.inline-err');
  if(hint) hint.remove();
});

document.getElementById('subcategory_select').addEventListener('change', function(){
  this.style.borderColor = '';
  this.style.boxShadow   = '';
  const hint = this.closest('.cat-step-block')?.querySelector('.inline-err');
  if(hint) hint.remove();
});

<?php endif; ?>
</script>
<?php
$extraFoot = ob_get_clean();
require 'layout_end.php';
?>