<?php
require '_layout.php';
header('Content-Type: application/json');

$staffId = (int)($_GET['staff_id'] ?? 0);
if (!$staffId) { echo json_encode(['categories' => []]); exit; }

$stmt = $conn->prepare("
    SELECT c.category_name
    FROM staff_categories sc
    JOIN categories c ON c.category_id = sc.category_id
    WHERE sc.staff_id = ? AND c.dept_id = 4
");
$stmt->bind_param("i", $staffId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$cats = [];
foreach ($rows as $row) {
    $parts = explode(' / ', $row['category_name'], 2);
    $cats[] = count($parts) === 2 ? trim($parts[1]) : $row['category_name'];
}

echo json_encode(['categories' => $cats]);