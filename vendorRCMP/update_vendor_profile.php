<?php
// vendorRCMP/update_vendor_profile.php
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['vendor_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../db_connect.php';

$vendor_id = (int)$_SESSION['vendor_id'];
$company   = trim($_POST['company']  ?? '');
$address   = trim($_POST['address']  ?? '');
$city      = trim($_POST['city']     ?? '');
$state     = trim($_POST['state']    ?? '');
$postcode  = trim($_POST['postcode'] ?? '');
$phone     = trim($_POST['phone']    ?? '');
$email     = trim($_POST['email']    ?? '');
$pic_name     = trim($_POST['pic_name']     ?? '');
$pic_position = trim($_POST['pic_position'] ?? '');
$pic_phone    = trim($_POST['pic_phone']    ?? '');
$password     = $_POST['password']     ?? '';
$old_password = $_POST['old_password'] ?? '';

if (!$company || !$email) {
    echo json_encode(['success' => false, 'error' => 'Company name and email are required.']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'Please enter a valid email address.']);
    exit;
}

$emailChk = $conn->prepare("SELECT vendor_id FROM vendors WHERE email = ? AND vendor_id != ?");
$emailChk->bind_param('si', $email, $vendor_id);
$emailChk->execute();
if ($emailChk->get_result()->fetch_assoc()) {
    echo json_encode(['success' => false, 'error' => 'This email is already in use by another account.']);
    $emailChk->close();
    exit;
}
$emailChk->close();

if ($phone !== '' && (!ctype_digit($phone) || strlen($phone) < 9 || strlen($phone) > 11)) {
    echo json_encode(['success' => false, 'error' => 'Phone number must be 9–11 digits.']);
    exit;
}

if ($password !== '') {
    if ($old_password === '') {
        echo json_encode(['success' => false, 'error' => 'Please enter your current password to set a new one.']);
        exit;
    }
    $chk = $conn->prepare("SELECT password_hash FROM vendors WHERE vendor_id = ?");
    $chk->bind_param('i', $vendor_id);
    $chk->execute();
    $row = $chk->get_result()->fetch_assoc();
    $chk->close();
    if (!$row || !password_verify($old_password, $row['password_hash'])) {
        echo json_encode(['success' => false, 'error' => 'Current password is incorrect.']);
        exit;
    }
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $conn->prepare("UPDATE vendors SET company_name=?, address=?, city=?, state=?, postcode=?, phone=?, email=?, password_hash=?, first_login=0 WHERE vendor_id=?");
    $stmt->bind_param('ssssssssi', $company, $address, $city, $state, $postcode, $phone, $email, $hash, $vendor_id);
} else {
    $stmt = $conn->prepare("UPDATE vendors SET company_name=?, address=?, city=?, state=?, postcode=?, phone=?, email=?, first_login=0 WHERE vendor_id=?");
    $stmt->bind_param('sssssssi', $company, $address, $city, $state, $postcode, $phone, $email, $vendor_id);
}

if ($stmt->execute()) {
    $_SESSION['vendor_company']  = $company;
    $_SESSION['vendor_name']     = $company;
    $_SESSION['vendor_phone']    = $phone;
    $_SESSION['vendor_email']    = $email;
    $_SESSION['vendor_address']  = $address;
    $_SESSION['vendor_city']     = $city;
    $_SESSION['vendor_state']    = $state;
    $_SESSION['vendor_postcode'] = $postcode;
    $_SESSION['vendor_first_login'] = 0;

    // Upsert the primary Person In Charge (PIC) for this vendor
    $picFind = $conn->prepare("SELECT staff_id FROM vendor_staff WHERE vendor_id = ? AND is_primary = 1 LIMIT 1");
    $picFind->bind_param('i', $vendor_id);
    $picFind->execute();
    $picExisting = $picFind->get_result()->fetch_assoc();
    $picFind->close();

    if ($pic_name === '') {
        if ($picExisting) {
            $picDel = $conn->prepare("DELETE FROM vendor_staff WHERE staff_id = ?");
            $picDel->bind_param('i', $picExisting['staff_id']);
            $picDel->execute();
            $picDel->close();
        }
    } elseif ($picExisting) {
        $picUpd = $conn->prepare("UPDATE vendor_staff SET full_name=?, position=?, phone=? WHERE staff_id=?");
        $picUpd->bind_param('sssi', $pic_name, $pic_position, $pic_phone, $picExisting['staff_id']);
        $picUpd->execute();
        $picUpd->close();
    } else {
        $picIns = $conn->prepare("INSERT INTO vendor_staff (vendor_id, full_name, position, phone, is_primary, created_at) VALUES (?, ?, ?, ?, 1, NOW())");
        $picIns->bind_param('isss', $vendor_id, $pic_name, $pic_position, $pic_phone);
        $picIns->execute();
        $picIns->close();
    }

    echo json_encode(['success' => true, 'company' => $company]);
} else {
    echo json_encode(['success' => false, 'error' => 'Database error.']);
}
$stmt->close();