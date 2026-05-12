<?php
// dept/afsmd/staff_notif_seen.php
require_once __DIR__ . '/../auth_guard.php';
require_once __DIR__ . '/../../db_connect.php';

header('Content-Type: application/json');

$staffId = (int)($staffId ?? 0);
if (!$staffId) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorised']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $conn->prepare("UPDATE staff SET last_notif_seen = NOW() WHERE staff_id = ?");
    $stmt->bind_param("i", $staffId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("SELECT last_notif_seen FROM staff WHERE staff_id = ?");
    $stmt->bind_param("i", $staffId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    echo json_encode(['last_seen' => $row['last_notif_seen'] ?? null]);
} else {
    $stmt = $conn->prepare("SELECT last_notif_seen FROM staff WHERE staff_id = ?");
    $stmt->bind_param("i", $staffId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    echo json_encode(['last_seen' => $row['last_notif_seen'] ?? null]);
}