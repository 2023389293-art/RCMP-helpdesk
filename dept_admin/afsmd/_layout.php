<?php
// dept_admin/afsmd/_layout.php 
// Shared layout helpers for AFSMD Admin pages

if (session_status() === PHP_SESSION_NONE) session_start();

// Auth guard
if (empty($_SESSION['staff_id']) || $_SESSION['dept_folder'] !== 'afsmd' || !in_array($_SESSION['staff_role'], ['admin', 'hod'])) {
    header("Location: ../../staff_login.php");
    exit;
}

// HOD can only access dashboard and reports
$hodRestrictedPages = ['tickets', 'users', 'categories', 'requisitions'];
if ($_SESSION['staff_role'] === 'hod' && in_array(basename($_SERVER['PHP_SELF'], '.php'), $hodRestrictedPages)) {
    header("Location: dashboard.php");
    exit;
}

require_once '../../db_connect.php';

$adminName   = $_SESSION['staff_name']  ?? 'Admin';
$adminEmail  = $_SESSION['staff_email'] ?? '';
$currentPage = basename($_SERVER['PHP_SELF'], '.php'); // dashboard | tickets | users | reports

function nav_item(string $href, string $icon, string $label, string $id, string $current): string {
    $active = ($id === $current);
    $cls    = $active ? 'nav-item active' : 'nav-item';
    
    // Calculate the "pip" HTML before putting it into the string
    $pip = $active ? '<span class="nav-pip"></span>' : '';

    return <<<HTML
    <a href="{$href}" class="{$cls}">
      <span class="nav-icon">{$icon}</span>
      <span class="nav-label">{$label}</span>
      {$pip}
    </a>
HTML;
}
?>