<?php
// uniKL/complaint/feedback_mark_shown.php
// Dismissal is tracked client-side via sessionStorage per ticket.
// This file intentionally does NOT set a server session block
// so new closed tickets can still trigger the popup later.

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

// No session flag — popup re-checks every 30s via JS poll
// and will show again if a new ticket gets closed
echo json_encode(['success' => true]);
exit;