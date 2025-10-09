<?php
/**
 * /api/list_face_descriptors.php
 * Returns pre-rendered 128-D descriptors for students with a registered face image.
 * - Works on localhost/CMS and https://myschoolness.site (no hard-coded host paths)
 * - Only returns NON-stale descriptors (stale=0 or column absent)
 * - Output: [{ student_id, fullname, descriptor:[...128 floats...] }, ...]
 */

header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
error_reporting(E_ERROR | E_PARSE);

try {
    require_once __DIR__ . '/../config/db.php';
    $conn->set_charset('utf8mb4');

    // Ensure table exists (safe if already exists)
    $conn->query("
        CREATE TABLE IF NOT EXISTS student_face_descriptors (
            student_id INT PRIMARY KEY,
            descriptor_json JSON NOT NULL,
            face_image_path VARCHAR(255) DEFAULT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Try to add 'stale' flag (ignore if already there or IF NOT EXISTS unsupported)
    try {
        $conn->query("ALTER TABLE student_face_descriptors ADD COLUMN IF NOT EXISTS stale TINYINT(1) NOT NULL DEFAULT 0");
    } catch (Throwable $e) {
        // Older MySQL may not support IF NOT EXISTS; try plain add and ignore duplicate-column error
        try { $conn->query("ALTER TABLE student_face_descriptors ADD COLUMN stale TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e2) {}
    }

    // We only need descriptors for students who actually have a face image registered
    // and whose descriptor is not marked stale (or stale column missing -> treat as fresh).
    $sql = "
        SELECT s.student_id, s.fullname, d.descriptor_json
        FROM students s
        INNER JOIN student_face_descriptors d ON d.student_id = s.student_id
        WHERE s.face_image_path IS NOT NULL
          AND s.face_image_path <> ''
          AND (d.stale IS NULL OR d.stale = 0)
    ";

    $res = $conn->query($sql);

    $out = [];
    while ($row = $res->fetch_assoc()) {
        $desc = json_decode($row['descriptor_json'] ?? '[]', true);
        if (!is_array($desc) || count($desc) !== 128) continue;

        // cast to float to ensure JS gets numbers, not strings
        $out[] = [
            'student_id' => (int)$row['student_id'],
            'fullname'   => (string)$row['fullname'],
            'descriptor' => array_map('floatval', $desc),
        ];
    }

    echo json_encode($out, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server_error', 'message' => $e->getMessage()]);
}
