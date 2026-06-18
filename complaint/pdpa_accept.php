<?php
// complaint/pdpa_accept.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) && !isset($_SESSION['staff_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']); exit;
}

require '../db_connect.php';

$userId   = (int)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? 0);
$userRole = $_SESSION['user_role'] ?? '';
$now      = date('Y-m-d H:i:s');

if ($userId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid user']); exit;
}

if ($userRole === 'user') {
    $stmt = $conn->prepare("UPDATE users SET pdpa_accepted_at = ? WHERE user_id = ? AND pdpa_accepted_at IS NULL");
    $stmt->bind_param("si", $now, $userId);
} else {
    $stmt = $conn->prepare("UPDATE staff SET pdpa_accepted_at = ? WHERE staff_id = ? AND pdpa_accepted_at IS NULL");
    $stmt->bind_param("si", $now, $userId);
}

$stmt->execute();
$ok = $stmt->affected_rows > 0;
$stmt->close();

// Even if affected_rows = 0, verify it's actually saved
if (!$ok) {
    if ($userRole === 'user') {
        $chk = $conn->prepare("SELECT pdpa_accepted_at FROM users WHERE user_id = ? LIMIT 1");
    } else {
        $chk = $conn->prepare("SELECT pdpa_accepted_at FROM staff WHERE staff_id = ? LIMIT 1");
    }
    $chk->bind_param("i", $userId);
    $chk->execute();
    $row = $chk->get_result()->fetch_assoc();
    $chk->close();
    $ok = !empty($row['pdpa_accepted_at']);
}

echo json_encode(['success' => $ok]);