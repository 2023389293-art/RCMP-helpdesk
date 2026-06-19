<?php
// run_once_encrypt_existing.php — run ONCE from CLI, then delete
require __DIR__ . '/db_connect.php'; // also loads encryption_helper.php

function isAlreadyEncrypted(?string $value): bool {
    if ($value === null || $value === '') return false;
    $decoded = base64_decode($value, true);
    return $decoded !== false && strpos($decoded, '::') !== false;
}

// ── PASS 1: name + email + phone, for submitter_type = 'user' ──
$rows = $conn->query("SELECT ticket_id, submitter_name, submitter_email, phone 
                       FROM complaints 
                       WHERE submitter_type = 'user' 
                       AND (submitter_name IS NOT NULL OR submitter_email IS NOT NULL OR phone IS NOT NULL)");

$stmt = $conn->prepare("UPDATE complaints SET submitter_name=?, submitter_email=?, phone=? WHERE ticket_id=?");

while ($row = $rows->fetch_assoc()) {
    $name  = isAlreadyEncrypted($row['submitter_name'])  ? $row['submitter_name']  : encryptField($row['submitter_name']);
    $email = isAlreadyEncrypted($row['submitter_email']) ? $row['submitter_email'] : encryptField($row['submitter_email']);
    $phone = isAlreadyEncrypted($row['phone'])           ? $row['phone']           : encryptField($row['phone']);
    $stmt->bind_param("ssss", $name, $email, $phone, $row['ticket_id']);
    $stmt->execute();
    echo "Encrypted (user) {$row['ticket_id']}\n";
}
$stmt->close();

// ── PASS 2: phone only, for submitter_type = 'staff' (name/email don't apply here) ──
$rows2 = $conn->query("SELECT ticket_id, phone FROM complaints WHERE submitter_type = 'staff' AND phone IS NOT NULL");
$stmt2 = $conn->prepare("UPDATE complaints SET phone=? WHERE ticket_id=?");

while ($row = $rows2->fetch_assoc()) {
    if (isAlreadyEncrypted($row['phone'])) continue; // already done, skip
    $phone = encryptField($row['phone']);
    $stmt2->bind_param("ss", $phone, $row['ticket_id']);
    $stmt2->execute();
    echo "Encrypted (staff phone) {$row['ticket_id']}\n";
}
$stmt2->close();

echo "Done.\n";