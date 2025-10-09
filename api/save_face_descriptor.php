<?php
/**
 * /api/save_face_descriptor.php
 * Receives { student_id, descriptor:[...128 floats...] }
 * Saves or updates a record in student_face_descriptors.
 */

header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
error_reporting(E_ERROR | E_PARSE);

try {
    require_once __DIR__ . '/../config/db.php';
    $conn->set_charset('utf8mb4');

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data) || empty($data['student_id']) || empty($data['descriptor'])) {
        echo json_encode(['success' => false, 'message' => 'invalid_input']);
        exit;
    }

    $student_id = (int)$data['student_id'];
    $descArr = $data['descriptor'];

    if (!is_array($descArr) || count($descArr) < 10) {
        echo json_encode(['success' => false, 'message' => 'bad_descriptor']);
        exit;
    }

    $descJson = json_encode(array_map('floatval', $descArr));

    // Ensure table exists
    $conn->query("
        CREATE TABLE IF NOT EXISTS student_face_descriptors (
            student_id INT PRIMARY KEY,
            descriptor_json JSON NOT NULL,
            face_image_path VARCHAR(255) DEFAULT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            stale TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Get current student's face_image_path
    $facePath = '';
    $q = $conn->prepare("SELECT face_image_path FROM students WHERE student_id=? LIMIT 1");
    $q->bind_param('i', $student_id);
    $q->execute();
    $r = $q->get_result()->fetch_assoc();
    if ($r) $facePath = (string)$r['face_image_path'];
    $q->close();

    // Insert or update descriptor
    $stmt = $conn->prepare("
        INSERT INTO student_face_descriptors (student_id, descriptor_json, face_image_path, stale)
        VALUES (?, ?, ?, 0)
        ON DUPLICATE KEY UPDATE
            descriptor_json=VALUES(descriptor_json),
            face_image_path=VALUES(face_image_path),
            stale=0,
            updated_at=CURRENT_TIMESTAMP
    ");
    $stmt->bind_param('iss', $student_id, $descJson, $facePath);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
