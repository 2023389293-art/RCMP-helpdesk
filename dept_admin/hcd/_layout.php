<?php
// dept_admin/hcd/_layout.php

if (session_status() === PHP_SESSION_NONE) session_start();

// Auth guard — only HCD admins
if (empty($_SESSION['staff_id']) || $_SESSION['dept_folder'] !== 'hcd' || $_SESSION['staff_role'] !== 'admin') {
    header("Location: ../../staff_login.php");
    exit;
}

require_once '../../db_connect.php';

$adminName   = $_SESSION['staff_name']  ?? 'Admin';
$adminEmail  = $_SESSION['staff_email'] ?? '';
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

function nav_item(string $href, string $icon, string $label, string $id, string $current): string {
    $active = ($id === $current);
    $cls    = $active ? 'nav-item active' : 'nav-item';
    $pip    = $active ? '<span class="nav-pip"></span>' : '';

    return <<<HTML
    <a href="{$href}" class="{$cls}">
      <span class="nav-icon">{$icon}</span>
      <span class="nav-label">{$label}</span>
      {$pip}
    </a>
HTML;
}
?>