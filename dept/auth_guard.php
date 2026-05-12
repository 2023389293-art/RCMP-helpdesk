<?php
// dept/auth_guard.php
// ─────────────────────────────────────────────────────────────────────────────
// Include this at the very top of EVERY department dashboard.php
// Usage:  require_once __DIR__ . '/../auth_guard.php';
// ─────────────────────────────────────────────────────────────────────────────

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Map dept_id → folder (must match staff_login.php)
$deptFolders = [
    1 => 'afsmd',
    2 => 'maintenance',
    3 => 'ccu',
    4 => 'it',
    5 => 'hcd',
];

// Not logged in at all → back to login
// auth_guard.php is in /dept/ → one level up reaches project root
if (empty($_SESSION['staff_id']) || empty($_SESSION['dept_id'])) {
    header('Location: ../staff_login.php');
    exit;
}

$sessionDeptId     = (int) $_SESSION['dept_id'];
$sessionDeptFolder = $deptFolders[$sessionDeptId] ?? null;

if (!$sessionDeptFolder) {
    // Unknown dept — clear session and send back
    session_destroy();
    header('Location: ../staff_login.php?err=invalid_dept');
    exit;
}

// Figure out which dept folder the current dashboard lives in
// e.g. dashboard.php is in dept/it/ → basename gives 'it'
$currentFolder = strtolower(basename(dirname($_SERVER['SCRIPT_FILENAME'])));

if ($currentFolder !== $sessionDeptFolder) {
    // Wrong department — redirect to their correct dashboard
    header("Location: ../{$sessionDeptFolder}/dashboard.php");
    exit;
}

// ── Helper: call this from any dashboard to log the user out ──────────────
function staffLogout(): void {
    session_unset();
    session_destroy();
    header('Location: ../staff_login.php');
    exit;
}

// ── Convenience variables available in every dashboard ───────────────────
$staffId    = $_SESSION['staff_id'];
$staffCode  = $_SESSION['staff_code'];
$staffName  = $_SESSION['staff_name'];
$staffEmail = $_SESSION['staff_email'];
$staffRole  = $_SESSION['staff_role'];
$deptId     = (int)$_SESSION['dept_id'];
$deptName   = $_SESSION['dept_name'];