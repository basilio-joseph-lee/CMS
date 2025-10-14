<?php
session_start();

// Determine base URL dynamically
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];

// If local, include /CMS; if production, assume root
$basePath = ($host === 'localhost' || $host === '127.0.0.1') ? '/CMS' : '';

// Check if user session exists, else redirect
if (!isset($_SESSION['user_id'])) {
    header("Location: " . $protocol . $host . $basePath . "/index.php");
    exit;
}
