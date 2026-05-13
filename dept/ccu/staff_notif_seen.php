<?php
// dept/ccu/staff_notif_seen.php 
// GET  → returns { last_seen: "2026-04-09 12:00:00" }
// POST → updates last_seen to NOW(), returns { last_seen: "..." }
 
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
    // Mark all read — stamp NOW()
    $stmt = $conn->prepare("UPDATE staff SET last_notif_seen = NOW() WHERE staff_id = ?");
    $stmt->bind_param("i", $staffId);
    $stmt->execute();
    $stmt->close();

    // Return the timestamp we just wrote
    $stmt = $conn->prepare("SELECT last_notif_seen FROM staff WHERE staff_id = ?");
    $stmt->bind_param("i", $staffId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    echo json_encode(['last_seen' => $row['last_notif_seen'] ?? null]);

} else {
    // GET — return current last_seen
    $stmt = $conn->prepare("SELECT last_notif_seen FROM staff WHERE staff_id = ?");
    $stmt->bind_param("i", $staffId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    echo json_encode(['last_seen' => $row['last_notif_seen'] ?? null]);
}