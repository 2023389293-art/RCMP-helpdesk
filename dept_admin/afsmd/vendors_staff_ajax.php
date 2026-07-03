<?php
// dept_admin/afsmd/vendors_staff_ajax.php
require '_layout.php'; // gives us $conn and session check

header('Content-Type: application/json');

$vendor_id = (int)($_GET['vendor_id'] ?? 0);
if (!$vendor_id) { echo json_encode([]); exit; }

$stmt = $conn->prepare("
    SELECT staff_id, full_name, position, phone, is_primary
    FROM vendor_staff
    WHERE vendor_id = ?
    ORDER BY is_primary DESC, full_name ASC
");
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode($rows);