<?php
// /public_html/config/db.php
// Robust mysqli connection for Hostinger

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Manila');

// ---- EDIT THESE FOUR VALUES ----
$DB_HOST = 'localhost';             // Hostinger MySQL host is usually 'localhost'
$DB_USER = 'u916312019_joseph';     // your MySQL username
$DB_PASS = 'REPLACE_WITH_NEW_PASS'; // your NEW password (rotate it in hPanel)
$DB_NAME = 'u916312019_cms';        // your database name
// --------------------------------

try {
    $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    $conn->set_charset('utf8mb4');
} catch (Throwable $e) {
    http_response_code(500);
    // TEMPORARY debug output — remove after fixing:
    echo "<pre>Database connection failed:\n" . htmlspecialchars($e->getMessage()) . "</pre>";
    exit;
}

/** HTML escape helper */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
