<?php
// config/admin_guard.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_id']) || ($_SESSION['role'] ?? '') !== 'ADMIN') {
    session_unset();
    session_destroy();
    header("Location: /CMS/index.php");
    exit;
}
