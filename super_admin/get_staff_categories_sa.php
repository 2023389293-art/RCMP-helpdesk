<?php
// super_admin/get_staff_categories_sa.php
session_start();
if (empty($_SESSION['staff_role']) || $_SESSION['staff_role'] !== 'super_admin') {
    http_response_code(401);
    echo json_encode(['category_ids' => []]);
    exit;
}

require_once '../db_connect.php';
header('Content-Type: application/json');

$staffId = (int)($_GET['staff_id'] ?? 0);
if (!$staffId) { echo json_encode(['category_ids' => []]); exit; }

$stmt = $conn->prepare("SELECT category_id FROM staff_categories WHERE staff_id = ?");
$stmt->bind_param("i", $staffId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode(['category_ids' => array_column($rows, 'category_id')]);