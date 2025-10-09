<?php
/**
 * /api/save_face_descriptor.php
 * Accepts a face descriptor for a student and stores/updates it.
 * Supports:
 *   - JSON:  { "student_id": 123, "descriptor": [ ...128 floats... ] }
 *   - FORM:  student_id=123&descriptor=[...json array...]   OR   descriptor=0.1,0.2,....
 */

header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
error_reporting(E_ERROR | E_PARSE);

try {
    require_once __DIR__ . '/../config/db.php';
    $conn->set_charset('utf8mb4');

    // --- Read payload (JSON first, then form fallback) ---
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        // Fallback to form vars
        $data = $_POST;
        // If descriptor is a string, try to parse JSON or CSV
        if (isset($data['descriptor']) && is_string($data['descriptor'])) {
            $descStr = trim($data['descriptor']);
            $descArr = json_decode($descStr, true);
            if (!is_array($descArr)) {
                // try CSV
                $parts = array_map('trim', explode(',', $descStr));
                $descArr = [];
                foreach ($parts as $p) {
                    if ($p === '') continue;
                    $descArr[] = (float)$p;
                }
            }
            $data['descriptor'] = $descArr;
        }
    }

    $student_id = isset($data['student_id']) ? (int)$data['student_id'] : 0;
    $descriptor = isset($data['descriptor']) ? $data['descriptor'] : null;

    if ($student_id <= 0 || !is_array($descriptor) || count($descriptor) < 10) {
        echo json_encode(['success' => false, 'message' => 'invalid_input']); exit;
    }

    // Normalize to floats, trim to 128 if longer
    $floats = array_map('floatval', $descriptor);
    if (count($floats) > 128) $floats = array_slice($floats, 0, 128);

    // --- Ensure table exists ---
    $conn->query("
        CREATE TABLE IF NOT EXISTS student_face_descriptors (
            student_id INT PRIMARY KEY,
            descriptor_json JSON NOT NULL,
            face_image_path VARCHAR(255) DEFAULT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            stale TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // --- Verify student exists & get the current face path (optional) ---
    $facePath = '';
    $stmt = $conn->prepare("SELECT face_image_path FROM students WHERE student_id = ? LIMIT 1");
    $stmt->bind_param('i', $student_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $facePath = (string)($row['face_image_path'] ?? '');
    } else {
        echo json_encode(['success' => false, 'message' => 'student_not_found']); exit;
    }
    $stmt->close();

    // --- Upsert descriptor ---
    $json = json_encode($floats);
    $stmt = $conn->prepare("
        INSERT INTO student_face_descriptors (student_id, descriptor_json, face_image_path, stale)
        VALUES (?, JSON_QUOTE(?), ?, 0)
        ON DUPLICATE KEY UPDATE
            descriptor_json = VALUES(descriptor_json),
            face_image_path = VALUES(face_image_path),
            stale = 0,
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->bind_param('iss', $student_id, $json, $facePath);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
