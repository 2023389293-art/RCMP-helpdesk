<?php
// complaint/batch_submit.php
session_start();
header('Content-Type: application/json');

$allowedRoles = ['user', 'lecturer', 'dept_handler', 'admin', 'super_admin', 'report_viewer', 'staff'];
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], $allowedRoles)) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit;
}

require '../db_connect.php';
require '../mail_helper.php';
require '../assign_helper.php';

$myt  = new DateTimeZone('Asia/Kuala_Lumpur');
$now  = new DateTime('now', $myt);


$userRole      = $_SESSION['user_role'];
$userId        = (int)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? 0);
$userName      = $_SESSION['user_name'] ?? 'User';
$userEmail     = $_SESSION['user_email'] ?? '';
$submitterType = ($userRole === 'user') ? 'user' : 'staff';

function calcSlaStart(DateTime $from): DateTime {
    $sla = clone $from;
    $hour= (int)$sla->format('H');
    $dow = (int)$sla->format('N');
    $isWorkday = ($dow >= 1 && $dow <= 5);
    $inHours   = ($hour >= 8 && $hour < 17);
    if ($isWorkday && $inHours) return $sla;
    if ($isWorkday && $hour >= 17) { $daysToAdd = ($dow == 5) ? 3 : 1; }
    elseif ($dow == 6)             { $daysToAdd = 2; }
    elseif ($dow == 7)             { $daysToAdd = 1; }
    else                           { $daysToAdd = 0; }
    if ($daysToAdd > 0) $sla->modify("+{$daysToAdd} days");
    $sla->setTime(8, 0, 0);
    return $sla;
}

$tickets      = [];
$requisitions = [];
$errors       = [];

// Pre-calculate base sequences once before the loop
$dayRes      = $conn->query("SELECT COUNT(*) AS cnt FROM complaints WHERE DATE(created_at)=CURDATE()");
$baseSeq     = ($dayRes->fetch_assoc()['cnt'] ?? 0) + 1;
$seqOffset   = 0;

$reqDayRes    = $conn->query("SELECT COUNT(*) AS cnt FROM requisitions WHERE DATE(created_at)=CURDATE()");
$reqBaseSeq   = ($reqDayRes->fetch_assoc()['cnt'] ?? 0) + 1;
$reqSeqOffset = 0;

// Collect all items: queued ones + 'current'
$allItems = [];
$count    = (int)($_POST['batch_count'] ?? 1);

// queued items (index 0, 1, 2...)
for ($i = 0; $i < $count - 1; $i++) {
    if (isset($_POST["items"][$i])) {
        $item         = $_POST["items"][$i];
        $item['file'] = isset($_FILES['attachments']['name'][$i]) ? [
    'name'     => $_FILES['attachments']['name'][$i],
    'tmp_name' => $_FILES['attachments']['tmp_name'][$i],
    'size'     => $_FILES['attachments']['size'][$i],
    'error'    => $_FILES['attachments']['error'][$i],
] : null;
        $allItems[]   = $item;
    }
}
// current (the one being previewed)
if (isset($_POST['items']['current'])) {
    $item         = $_POST['items']['current'];
    $item['file'] = isset($_FILES['attachments']['name']['current']) ? [
    'name'     => $_FILES['attachments']['name']['current'],
    'tmp_name' => $_FILES['attachments']['tmp_name']['current'],
    'size'     => $_FILES['attachments']['size']['current'],
    'error'    => $_FILES['attachments']['error']['current'],
] : null;
    $allItems[]   = $item;
}

foreach ($allItems as $idx => $item) {
    $type = $item['type'] ?? '';

    if ($type === 'complaint') {
        $category_id  = (int)($item['category_id'] ?? 0);
        $description  = trim($item['description']   ?? '');
        $phone        = trim($item['phone']          ?? '');
        $my_department= trim($item['my_department']  ?? '');

        if (!$category_id || !$description || !$phone || !$my_department) {
            $errors[] = "Item " . ($idx+1) . ": missing complaint fields"; continue;
        }

        $stmtCat = $conn->prepare("SELECT dept_id, category_name FROM categories WHERE category_id=? LIMIT 1");
        $stmtCat->bind_param("i", $category_id);
        $stmtCat->execute();
        $catRow = $stmtCat->get_result()->fetch_assoc();
        $stmtCat->close();
        if (!$catRow) { $errors[] = "Item ".($idx+1).": invalid category"; continue; }

        $dept_id = (int)$catRow['dept_id'];
        $title   = $catRow['category_name'];

        // Generate ticket ID
$dateStr  = date('dmY');
$ticketId = 'RCMP-' . $dateStr . '-' . ($baseSeq + $seqOffset);
$seqOffset++;

        // File upload
        $attachmentPath = null;
        $fileData = $item['file'] ?? null;
        if ($fileData && !empty($fileData['name'])) {
            $ext     = strtolower(pathinfo($fileData['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','heic','heif','webp','pdf','doc','docx','txt'];
            if (in_array($ext,$allowed) && $fileData['size'] <= 5*1024*1024) {
                $uploadDir = '../uploads/complaints/';
                if (!is_dir($uploadDir)) mkdir($uploadDir,0755,true);
                $filename       = $ticketId.'_'.time().'.'.$ext;
                $attachmentPath = 'uploads/complaints/'.$filename;
                move_uploaded_file($fileData['tmp_name'], $uploadDir.$filename);
            }
        }

        $slaStart   = calcSlaStart($now);
        $slaInsert  = $slaStart->format('Y-m-d H:i:s');
        $defaultPri = 'medium';

$batchEmail = ($submitterType === 'user') ? encryptField($_SESSION['user_email'] ?? '') : null;
$batchName  = ($submitterType === 'user') ? encryptField($_SESSION['user_name']  ?? '') : null;
$stmt = $conn->prepare("INSERT INTO complaints (ticket_id,submitter_id,submitter_type,submitter_email,submitter_name,phone,my_department,category_id,dept_id,title,description,attachment_path,status,priority,assigned_to,sla_start_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'open',?,NULL,?,NOW(),NOW())");
$encryptedPhone = encryptField($phone);
$stmt->bind_param("sisssssiisssss", $ticketId,$userId,$submitterType,$batchEmail,$batchName,$encryptedPhone,$my_department,$category_id,$dept_id,$title,$description,$attachmentPath,$defaultPri,$slaInsert);
        if ($stmt->execute()) {
            autoAssignTicket($conn, $dept_id, $ticketId);
            try {
                sendComplaintEmail($conn, $dept_id, $ticketId);
            } catch (\Throwable $mailErr) {
                error_log("[Batch Submit] Email failed for {$ticketId}: " . $mailErr->getMessage());
            }
            $tickets[] = $ticketId;
        } else {
            $errors[] = "Item ".($idx+1).": DB error ".$stmt->error;
        }
        $stmt->close();

    } elseif ($type === 'requisition') {
        $req_category      = trim($item['category']       ?? '');
        $req_quantity      = (int)($item['quantity']      ?? 0);
        $req_my_department = trim($item['my_department']  ?? '');
        $req_location      = trim($item['location']       ?? '');
        $req_reason        = trim($item['reason']         ?? '');
        $req_phone         = trim($item['phone']          ?? '');
        $req_urgency       = trim($item['urgency']        ?? 'normal');
        $req_item_name = trim($item['item_name'] ?? $item['category'] ?? '');

        $allowedCats = ['Office Furniture','Water Dispenser','Signage','Vending Machine','Office Keys','Office Equipment','Others'];
        $allowedUrg  = ['normal','urgent','critical'];
        if (!in_array($req_category,$allowedCats)||!$req_quantity||!$req_my_department||!$req_location||!$req_reason||!$req_phone||!in_array($req_urgency,$allowedUrg)) {
            $errors[] = "Item ".($idx+1).": missing/invalid requisition fields"; continue;
        }

$dateStr   = date('dmY');
$refNumber = 'REQ-' . $dateStr . '-' . ($reqBaseSeq + $reqSeqOffset);
$reqSeqOffset++;

        $attachmentPath = null;
        $fileData = $item['file'] ?? null;
        if ($fileData && !empty($fileData['name'])) {
            $ext     = strtolower(pathinfo($fileData['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','heic','heif','webp','pdf','doc','docx','txt'];
            if (in_array($ext,$allowed) && $fileData['size'] <= 5*1024*1024) {
                $uploadDir = '../uploads/requisitions/';
                if (!is_dir($uploadDir)) mkdir($uploadDir,0755,true);
                $filename       = $refNumber.'_'.time().'.'.$ext;
                $attachmentPath = 'uploads/requisitions/'.$filename;
                move_uploaded_file($fileData['tmp_name'], $uploadDir.$filename);
            }
        }

        $stmt = $conn->prepare("INSERT INTO requisitions (ref_number,submitter_id,submitter_type,phone,my_department,category,item_name,quantity,location,reason,urgency,attachment_path,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'pending',NOW(),NOW())");
        $stmt->bind_param("sisssssissss",$refNumber,$userId,$submitterType,$req_phone,$req_my_department,$req_category,$req_item_name,$req_quantity,$req_location,$req_reason,$req_urgency,$attachmentPath);
        if ($stmt->execute()) {
            try {
                sendRequisitionConfirmationEmail($userEmail,$userName,$refNumber,$req_category,$req_category,$req_quantity,$req_my_department,$req_location,$req_urgency,$now->format('d M Y, g:ia'));
                sendRequisitionEmail($conn,$refNumber,$userName,$submitterType,$userEmail,$req_phone,$req_my_department,$req_category,$req_category,$req_quantity,$req_location,$req_reason,$req_urgency,$now->format('d M Y, g:ia'));
            } catch (\Throwable $mailErr) {
                error_log("[Batch Submit] Requisition email failed for {$refNumber}: " . $mailErr->getMessage());
            }
            $requisitions[] = $refNumber;
        } else {
            $errors[] = "Item ".($idx+1).": DB error ".$stmt->error;
        }
        $stmt->close();
    }
}

echo json_encode([
    'success'      => count($errors) === 0,
    'tickets'      => $tickets,
    'requisitions' => $requisitions,
    'errors'       => $errors,
]);