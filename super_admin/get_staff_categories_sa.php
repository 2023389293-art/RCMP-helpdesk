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

// Try staff_categories junction table first
$stmt = $conn->prepare("SELECT category_id FROM staff_categories WHERE staff_id = ?");
$stmt->bind_param("i", $staffId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (!empty($rows)) {
    echo json_encode(['category_ids' => array_column($rows, 'category_id')]);
    exit;
}

// Fallback: derive category_id from staff.category column (handles legacy data)
$fbStmt = $conn->prepare("
    SELECT c.category_id
    FROM staff s
    JOIN categories c
      ON c.dept_id = s.dept_id
      AND (
        c.category_name = s.category
        OR SUBSTRING_INDEX(c.category_name, ' / ', -1) = s.category
      )
    WHERE s.staff_id = ?
      AND s.category IS NOT NULL
      AND s.category != ''
");
$fbStmt->bind_param("i", $staffId);
$fbStmt->execute();
$fbRows = $fbStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$fbStmt->close();

echo json_encode(['category_ids' => array_column($fbRows, 'category_id')]);