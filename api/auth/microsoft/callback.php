<?php
session_start();
$flow = $_SESSION['sso_flow'] ?? 'student';
if ($flow === 'staff') {
    require __DIR__ . '/../../../auth/staff_callback.php';
} else {
    require __DIR__ . '/../../../auth/callback.php';
}