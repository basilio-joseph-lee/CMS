<?php
// /api/list_face_descriptors.php
header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
error_reporting(E_ERROR | E_PARSE);

try {
    require_once __DIR__ . '/../config/db.php';
    $conn->set_charset('utf8mb4');

    // OPTIONAL filters to reduce payload (send via POST if you have these in your schema)
    // If you don't have these relationships, the code simply ignores filters.
    $payload = $_POST + (json_decode(file_get_contents('php://input'), true) ?: []);
    $advisory_id    = isset($payload['advisory_id'])    ? (int)$payload['advisory_id']    : null;
    $school_year_id = isset($payload['school_year_id']) ? (int)$payload['school_year_id'] : null;
    $subject_id     = isset($payload['subject_id'])     ? (int)$payload['subject_id']     : null;

    // Table exists (created in Step 1 or by the save endpoint)
    $conn->query("
        CREATE TABLE IF NOT EXISTS student_face_descriptors (
            student_id INT PRIMARY KEY,
            descriptor_json JSON NOT NULL,
            face_image_path VARCHAR(255) DEFAULT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Try adding 'stale' column if not present (safe, no error on re-run)
    try {
        $conn->query("ALTER TABLE student_face_descriptors ADD COLUMN IF NOT EXISTS stale TINYINT(1) NOT NULL DEFAULT 0");
    } catch (Throwable $e) { /* older MySQL lacks IF NOT EXISTS; ignore */ }

    // Base query: only non-stale descriptors
    $sql = "SELECT d.student_id, d.descriptor_json
            FROM student_face_descriptors d
            WHERE (d.stale IS NULL OR d.stale = 0)";
    $params = [];
    $types  = '';

    // Optional filters — only apply if you truly have these relations in your DB
    // Example JOINs commented out; keep base query if you’re unsure.
    /*
    if ($advisory_id) {
        $sql .= " AND d.student_id IN (SELECT student_id FROM advisory_students WHERE advisory_id = ?)";
        $types .= 'i'; $params[] = $advisory_id;
    }
    if ($school_year_id) {
        $sql .= " AND d.student_id IN (SELECT student_id FROM student_enrollments WHERE school_year_id = ?)";
        $types .= 'i'; $params[] = $school_year_id;
    }
    if ($subject_id) {
        $sql .= " AND d.student_id IN (SELECT student_id FROM subject_enrollments WHERE subject_id = ?)";
        $types .= 'i'; $params[] = $subject_id;
    }
    */

    if ($types) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
    } else {
        $res = $conn->query($sql);
    }

    $out = [];
    while ($row = $res->fetch_assoc()) {
        $desc = json_decode($row['descriptor_json'] ?? '[]', true);
        if (!is_array($desc) || count($desc) !== 128) continue;
        $out[] = [
            'student_id' => (int)$row['student_id'],
            'descriptor' => array_map('floatval', $desc),
        ];
    }

    echo json_encode($out, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server_error', 'message' => $e->getMessage()]);
}
