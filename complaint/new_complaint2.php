<?php
// complaint/new_complaint.php
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isWorkingHours) {
    $title         = trim($_POST['title']         ?? '');
    $category_id   = (int)($_POST['category_id'] ?? 0);
    $description   = trim($_POST['description']  ?? '');
    $phone         = trim($_POST['phone']         ?? '');
    $my_department = trim($_POST['my_department'] ?? '');

    if (empty($title) || empty($description) || $category_id === 0 || empty($phone) || empty($my_department)) {
        $error = 'Please fill in all required fields.';
    } else {
        $stmtCat = $conn->prepare("SELECT dept_id FROM categories WHERE category_id = ? LIMIT 1");
        $stmtCat->bind_param("i", $category_id);
        $stmtCat->execute();
        $catRow = $stmtCat->get_result()->fetch_assoc();
        $stmtCat->close();

        if (!$catRow) {
            $error = 'Invalid category selected.';
        } else {
            $dept_id = (int)$catRow['dept_id'];

            // Generate ticket ID
            $dateStr = date('dmY');
            $dayRes  = $conn->query("SELECT MAX(CAST(SUBSTRING_INDEX(ticket_id, '-', -1) AS UNSIGNED)) AS last_seq FROM complaints WHERE DATE(created_at) = CURDATE()");
            $dayRow  = $dayRes->fetch_assoc();
            $seq     = ($dayRow['last_seq'] ?? 0) + 1;
            $ticketId = 'RCMP-' . $dateStr . '-' . str_pad($seq, 5, '0', STR_PAD_LEFT);

            // Handle file upload
            $attachmentPath = null;
            if (!empty($_FILES['attachment']['name'])) {
                $uploadDir = '../uploads/complaints/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $ext     = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg','jpeg','png','gif','pdf','doc','docx','txt'];
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
  .steps-row{display:flex;align-items:stretch;width:100%;max-width:820px;margin-bottom:28px;background:white;border:1px solid var(--g300);border-radius:14px;overflow:hidden;}
  .step{flex:1;display:flex;align-items:center;gap:12px;padding:18px 24px;position:relative;transition:background 0.25s;}
  .step:not(:last-child)::after{content:'';position:absolute;right:0;top:50%;transform:translateY(-50%);width:1px;height:40%;background:var(--g300);}
  .step.active{background:var(--blue-light);}
  .step.done{background:#f0f8eb;}
  .step-num{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:600;flex-shrink:0;background:var(--g100);color:var(--g500);border:1.5px solid var(--g300);transition:all 0.25s;}
  .step.active .step-num{background:var(--blue);color:white;border-color:var(--blue);}
  .step.done .step-num{background:#4CAF50;color:white;border-color:#4CAF50;}
  .step-label{font-size:13px;font-weight:500;color:var(--g500);}
  .step.active .step-label{color:var(--blue);}
  .step.done .step-label{color:#3B6D11;}
  .step-sub{font-size:11px;color:var(--g500);margin-top:1px;}
  .wh-banner{display:flex;align-items:flex-start;gap:14px;width:100%;max-width:820px;padding:16px 20px;border-radius:12px;margin-bottom:20px;font-size:13px;line-height:1.6;animation:fadeSlideIn 0.35s ease;}
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
  #complaintForm{width:100%;max-width:820px;}
  .form-card{background:white;border-radius:20px;border:1px solid var(--g300);overflow:hidden;box-shadow:0 4px 6px -1px rgba(0,0,0,0.06),0 10px 40px -8px rgba(0,59,142,0.10);}
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
  .form-locked-wrap{position:relative;width:100%;max-width:820px;}
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

  #previewPanel{display:none;width:100%;max-width:820px;}
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
    .page-header,.steps-row,.alert,.wh-banner,#complaintForm,#previewPanel,.form-locked-wrap{max-width:100%;}
    .form-body,.preview-body{padding:20px 18px;}
    .form-card-header,.preview-card-header{padding:20px 18px 16px;}
    .form-actions,.preview-actions{padding:16px 18px;flex-wrap:wrap;}
    .steps-row{overflow-x:auto;}
    .step-sub{display:none;}
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
  </a><br>
  <h1>Submit a Complaint</h1>
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
<!-- ── OUTSIDE HOURS: wrapped form with locked overlay ── -->
<div class="form-locked-wrap" id="complaintForm">
  <div class="form-locked-overlay">
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
  </div>

  <!-- Blurred/disabled form (visual only, no interaction) -->
  <div class="form-card" aria-hidden="true" tabindex="-1">
    <div class="form-card-header">
      <div class="fch-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></div>
      <div class="fch-text"><h3>Complaint Details</h3><p>Your complaint will be routed to the correct department automatically</p></div>
    </div>
    <div class="form-body">
      <div class="form-section-label">Your Information</div>
      <div class="field-grid">
        <div class="field">
          <label>Phone Number <span class="req">*</span></label>
          <div class="phone-wrap"><span class="phone-prefix">+60</span><input type="tel" disabled placeholder="11-1234 5678"/></div>
        </div>
        <div class="field">
          <label>My Department / Faculty <span class="req">*</span></label>
          <div class="select-wrap"><select disabled><option>— Select Department / Faculty —</option></select></div>
        </div>
      </div>
      <div class="form-section-label">Complaint Details</div>
      <div class="field"><label>Complaint Title <span class="req">*</span></label><input type="text" disabled placeholder="e.g. Air conditioning not working in Block A"/></div>
      <div class="field"><label>Description <span class="req">*</span></label><textarea disabled placeholder="Please describe your issue in detail..."></textarea></div>
    </div>
    <div class="form-actions">
      <a href="homepage.php" class="btn-cancel">Cancel</a>
      <button type="button" class="btn-submit-locked" disabled>
        <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        Submissions Closed
      </button>
    </div>
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

      <div class="form-section-label">Complaint Details</div>
      <div class="field">
        <label for="title">Complaint Title <span class="req">*</span></label>
        <input type="text" id="title" name="title" placeholder="e.g. Air conditioning not working in Block A"
          maxlength="150" value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>" required/>
        <div class="field-footer"><span class="char-counter" id="titleCounter">0 / 150</span></div>
      </div>

      <div class="field">
        <label>Category <span class="req">*</span></label>
        <div class="category-two-step">
          <div class="cat-step-block">
            <div class="cat-step-label">1. Department</div>
            <div class="select-wrap">
              <select id="dept_group_select">
                <option value="">— Select Department —</option>
                <?php foreach (array_keys($dbCategories) as $dname): ?>
                <option value="<?php echo htmlspecialchars($dname); ?>"
                  <?php echo ($prevDeptGroup === $dname) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($dname); ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="cat-step-block subcategory-reveal" id="subcategoryReveal">
            <div class="cat-step-label">2. Sub-Category</div>
            <div class="select-wrap">
              <select id="subcategory_select" disabled>
                <option value="">— Select Sub-Category —</option>
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
  accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt"/>
          <div class="file-icon"><svg viewBox="0 0 24 24"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg></div>
          <div class="file-drop-title">Drop your file here, or <strong>browse</strong></div>
          <div class="file-drop-sub">JPG, PNG, PDF, DOC, DOCX, TXT — max 5 MB</div>
        </div>
        <div class="file-selected" id="fileSelected">
          <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
          <span id="fileName">—</span>
          <button type="button" class="file-remove" id="fileRemove">×</button>
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
          <div class="preview-field full"><div class="preview-label">Title</div><div class="preview-value" id="pv-title">—</div></div>
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
      <button type="button" class="btn-confirm" id="confirmBtn" onclick="submitForm()">
        Confirm & Submit
        <svg viewBox="0 0 24 24"><path d="M22 2L11 13"/><path d="M22 2L15 22 11 13 2 9l20-7z"/></svg>
      </button>
    </div>
  </div>
</div>
<?php endif; ?>

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
// Only run interactive JS if working hours are active
<?php if ($isWorkingHours): ?>
const allCategories  = <?php echo json_encode($dbCategories, JSON_UNESCAPED_UNICODE); ?>;
const deptLabels     = <?php echo json_encode($deptLabelsMap, JSON_UNESCAPED_UNICODE); ?>;
const prevDeptGroup  = <?php echo json_encode($prevDeptGroup); ?>;
const prevCategoryId = <?php echo (int)$prevCategoryId; ?>;

const progressFill = document.getElementById('progressFill');
const hiddenCatId  = document.getElementById('category_id');
const deptGroupSel = document.getElementById('dept_group_select');
const subcatSel    = document.getElementById('subcategory_select');
const subcatReveal = document.getElementById('subcategoryReveal');
let selectedHandler = '';

function updateSelectColour(sel){ /* no-op: removed grey placeholder */ }
document.querySelectorAll('select').forEach(sel=>{updateSelectColour(sel);sel.addEventListener('change',()=>updateSelectColour(sel));});

deptGroupSel.addEventListener('change',function(){
  const chosen=this.value;
  subcatSel.innerHTML='<option value="">— Select Sub-Category —</option>';
  hiddenCatId.value='';selectedHandler='';
  document.getElementById('deptHint').classList.remove('show');
  if(!chosen||!allCategories[chosen]){subcatSel.disabled=true;subcatReveal.classList.remove('active');updateProgress();return;}
  allCategories[chosen].forEach(cat=>{
    const opt=document.createElement('option');
    opt.value=cat.id;
    const si=cat.name.indexOf(' / ');
    opt.textContent=si!==-1?cat.name.substring(si+3):cat.name;
    subcatSel.appendChild(opt);
  });
  subcatSel.disabled=false;subcatReveal.classList.add('active');subcatSel.focus();updateSelectColour(subcatSel);updateProgress();
});

subcatSel.addEventListener('change',function(){
  const id=parseInt(this.value);
  hiddenCatId.value=id||'';
  if(id&&deptLabels[id]){
    selectedHandler=deptLabels[id];
    document.getElementById('deptHintText').textContent='Will be handled by: '+deptLabels[id];
    document.getElementById('deptHint').classList.add('show');
  }else{selectedHandler='';document.getElementById('deptHint').classList.remove('show');}
  updateProgress();
});

(function restore(){
  if(!prevDeptGroup)return;
  deptGroupSel.value=prevDeptGroup;
  deptGroupSel.dispatchEvent(new Event('change'));
  if(prevCategoryId)setTimeout(()=>{subcatSel.value=prevCategoryId;subcatSel.dispatchEvent(new Event('change'));},0);
})();

function updateProgress(){
  const f=[document.getElementById('title').value.trim(),hiddenCatId.value,document.getElementById('description').value.trim(),document.getElementById('phone').value.trim(),document.getElementById('my_department').value];
  progressFill.style.width=Math.round((f.filter(Boolean).length/5)*100)+'%';
}
function updateCounter(id,len,max){
  const el=document.getElementById(id);
  el.textContent=len+' / '+max;
  el.classList.remove('warn','danger');
  if(len>=max*0.9)el.classList.add('danger');
  else if(len>=max*0.75)el.classList.add('warn');
}
document.getElementById('title').addEventListener('input',function(){updateCounter('titleCounter',this.value.length,150);updateProgress();});
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
  const title   = document.getElementById('title').value.trim();
  const deptGrp = document.getElementById('dept_group_select').value;
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

  // 3. Complaint Title
  if(!title){
    markFieldError('title', 'Complaint title is required.');
    firstError = firstError || document.getElementById('title');
  }

  // 4. Category — must pick department group first, then sub-category
  if(!deptGrp){
    markSelectError('dept_group_select', 'Please select a department for the category.');
    firstError = firstError || document.getElementById('dept_group_select');
  } else if(!catId){
    markSelectError('subcategory_select', 'Please select a sub-category.');
    firstError = firstError || document.getElementById('subcategory_select');
  }

  // 5. Description
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
  document.getElementById('pv-title').textContent = title;
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
  document.getElementById('step1').classList.remove('active');
  document.getElementById('step1').classList.add('done');
  document.getElementById('step2').classList.add('active');
  progressFill.style.width = '66%';
  window.scrollTo({top:0, behavior:'smooth'});
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
  ['dept_group_select','subcategory_select'].forEach(id => {
    const el = document.getElementById(id);
    if(el){ el.style.borderColor = ''; el.style.boxShadow = ''; }
  });
  document.querySelectorAll('.inline-err').forEach(el => el.remove());
}

function backToForm(){
  document.getElementById('complaintForm').style.display='';
  document.getElementById('previewPanel').classList.remove('show');
  document.getElementById('step1').classList.add('active');document.getElementById('step1').classList.remove('done');
  document.getElementById('step2').classList.remove('active');updateProgress();window.scrollTo({top:0,behavior:'smooth'});
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
(function init(){updateProgress();const t=document.getElementById('title'),de=document.getElementById('description');if(t.value)updateCounter('titleCounter',t.value.length,150);if(de.value)updateCounter('descCounter',de.value.length,2000);})();

// Clear inline errors when user corrects a field
['title','description'].forEach(id => {
  const el = document.getElementById(id);
  if(!el) return;
  el.addEventListener('input', function(){
    this.classList.remove('input-error');
    const hint = this.closest('.field')?.querySelector('.inline-err');
    if(hint) hint.remove();
  });
});
document.getElementById('my_department').addEventListener('change', function(){
  this.classList.remove('input-error');
  const hint = this.closest('.field')?.querySelector('.inline-err');
  if(hint) hint.remove();
});
document.getElementById('dept_group_select').addEventListener('change', function(){
  this.style.borderColor = '';
  this.style.boxShadow   = '';
  const hint = this.closest('.cat-step-block')?.querySelector('.inline-err');
  if(hint) hint.remove();
}, true);
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