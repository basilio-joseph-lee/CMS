<?php
// /maintenance/mark_stale_descriptors.php
header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
error_reporting(E_ERROR | E_PARSE);

try {
    require_once __DIR__ . '/../config/db.php';
    $conn->set_charset('utf8mb4');

    // Ensure table/columns
    $conn->query("
        CREATE TABLE IF NOT EXISTS student_face_descriptors (
            student_id INT PRIMARY KEY,
            descriptor_json JSON NOT NULL,
            face_image_path VARCHAR(255) DEFAULT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    try { $conn->query("ALTER TABLE student_face_descriptors ADD COLUMN IF NOT EXISTS stale TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}

    // Mark >24h old as stale (client will reseed on next login; still fast if cache exists)
    $conn->query("UPDATE student_face_descriptors SET stale = 1 WHERE updated_at < (NOW() - INTERVAL 24 HOUR)");

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
