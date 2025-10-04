<?php
// /public_html/config/db.php

// Only run this block once per request
if (!defined('__DB_BOOTSTRAPPED__')) {
    define('__DB_BOOTSTRAPPED__', true);

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    date_default_timezone_set('Asia/Manila');

    // ==== EDIT YOUR CREDENTIALS HERE ====
    $DB_HOST = 'localhost';
    $DB_USER = 'u916312019_joseph';
    $DB_PASS = 'Twice_jihyo12345';
    $DB_NAME = 'u916312019_cms';
    // =====================================

    try {
        $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
        $conn->set_charset('utf8mb4');
    } catch (Throwable $e) {
        http_response_code(500);
        // (Optional) temporary debug:
        echo "<pre>DB connection failed:\n" . htmlspecialchars($e->getMessage()) . "</pre>";
        exit;
    }
}

// Define helper only if not already defined
if (!function_exists('h')) {
    function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}