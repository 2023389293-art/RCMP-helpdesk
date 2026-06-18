<?php
// uniKL/complaint/feedback_mark_shown.php
// Marks the feedback popup as dismissed for the rest of THIS login session.
// Stored in $_SESSION so it disappears automatically on logout, and a
// fresh session on next login means the popup can show again.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

$submitterId = (int)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? 0);
if ($submitterId <= 0) {
    echo json_encode(['error' => 'unauthenticated']);
    exit;
}

// Suppress the popup for the rest of this login session.
$_SESSION['fb_popup_dismissed'] = true;

echo json_encode(['success' => true]);
exit;