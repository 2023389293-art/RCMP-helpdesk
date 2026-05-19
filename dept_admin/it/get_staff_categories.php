<?php
require '_layout.php';
header('Content-Type: application/json');

$staffId = (int)($_GET['staff_id'] ?? 0);
if (!$staffId) { echo json_encode(['categories' => []]); exit; }

// Get staff's dept_id
$deptStmt = $conn->prepare("SELECT dept_id FROM staff WHERE staff_id = ?");
$deptStmt->bind_param("i", $staffId);
$deptStmt->execute();
$deptRow = $deptStmt->get_result()->fetch_assoc();
$deptStmt->close();
$deptId = $deptRow['dept_id'] ?? 4;

// Try staff_categories junction table first
$stmt = $conn->prepare("
    SELECT c.category_name
    FROM staff_categories sc
    JOIN categories c ON c.category_id = sc.category_id
    WHERE sc.staff_id = ? AND c.dept_id = ?
");
$stmt->bind_param("ii", $staffId, $deptId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fallback: if junction table empty, read from staff.category column
if (empty($rows)) {
    $fbStmt = $conn->prepare("SELECT category FROM staff WHERE staff_id = ?");
    $fbStmt->bind_param("i", $staffId);
    $fbStmt->execute();
    $fbRow = $fbStmt->get_result()->fetch_assoc();
    $fbStmt->close();

    $cats = [];
    if (!empty($fbRow['category'])) {
        $parts = explode(' / ', $fbRow['category'], 2);
        $cats[] = count($parts) === 2 ? trim($parts[1]) : trim($fbRow['category']);
    }
    echo json_encode(['categories' => $cats]);
    exit;
}

$cats = [];
foreach ($rows as $row) {
    $parts = explode(' / ', $row['category_name'], 2);
    $cats[] = count($parts) === 2 ? trim($parts[1]) : $row['category_name'];
}

echo json_encode(['categories' => $cats]);