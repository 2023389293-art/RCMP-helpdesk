<?php
// uniKL/complaint/feedback_mark_shown.php 
// Called via POST after the student submits OR skips the feedback popup.
// Sets a server-side session flag as a secondary guard (primary is sessionStorage in JS).

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// Only accept POST from authenticated students
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

$_SESSION['fb_popup_dismissed_' . $submitterId] = true;

echo json_encode(['success' => true]);
exit;