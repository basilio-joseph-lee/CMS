<?php
// /api/list_face_vectors.php
header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Hide notices/warnings so JSON is never polluted.
error_reporting(E_ERROR | E_PARSE);

try {
    // --- DB ---
    include __DIR__ . '/../config/db.php';
    $conn->set_charset('utf8mb4');

    // Join to get names; only take non-stale vectors
    $sql = "SELECT sfd.student_id,
                   s.fullname,
                   sfd.descriptor_json,
                   sfd.updated_at
            FROM student_face_descriptors sfd
            JOIN students s ON s.student_id = sfd.student_id
            WHERE COALESCE(sfd.stale, 0) = 0";
    $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

    // Roster version = newest updated_at (or 0)
    $verRow = $conn->query("
        SELECT UNIX_TIMESTAMP(MAX(updated_at)) AS v
        FROM student_face_descriptors
        WHERE COALESCE(stale,0)=0
    ")->fetch_assoc();
    $roster_version = (int)($verRow['v'] ?? 0);

    $out = [
        'roster_version' => $roster_version,
        'vectors' => array_map(function($r) {
            return [
                'student_id' => (int)$r['student_id'],
                'fullname'   => (string)$r['fullname'],
                // descriptor_json must be a JSON array of 128 numbers in DB
                'descriptor' => json_decode($r['descriptor_json'], true) ?: [],
                'updated_at' => (string)$r['updated_at'],
            ];
        }, $rows),
    ];

    echo json_encode($out, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server_error', 'message' => $e->getMessage()]);
}
