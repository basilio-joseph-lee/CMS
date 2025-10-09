<?php
// /api/save_face_descriptor.php
header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
error_reporting(E_ERROR | E_PARSE);

try {
    require_once __DIR__ . '/../config/db.php';
    $conn->set_charset('utf8mb4');

    $payload = json_decode(file_get_contents('php://input'), true) ?: [];
    $student_id = (int)($payload['student_id'] ?? 0);
    $descriptor = $payload['descriptor'] ?? null;

    if ($student_id <= 0 || !is_array($descriptor) || count($descriptor) < 10) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'msg' => 'bad_request']); exit;
    }

    // Ensure student exists and has a face image registered
    $stmt = $conn->prepare("SELECT face_image_path FROM students WHERE student_id = ? LIMIT 1");
    $stmt->bind_param('i', $student_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || empty($row['face_image_path'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => 'no_registered_face']); exit;
    }

    // Table
    $conn->query("
        CREATE TABLE IF NOT EXISTS student_face_descriptors (
            student_id INT PRIMARY KEY,
            descriptor_json JSON NOT NULL,
            face_image_path VARCHAR(255) DEFAULT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $json = json_encode(array_map('floatval', $descriptor));
    $stmt = $conn->prepare("
        INSERT INTO student_face_descriptors (student_id, descriptor_json, face_image_path)
        VALUES (?, JSON_QUOTE(?), ?)
        ON DUPLICATE KEY UPDATE descriptor_json = VALUES(descriptor_json), face_image_path = VALUES(face_image_path)
    ");
    $stmt->bind_param('iss', $student_id, $json, $row['face_image_path']);
    $stmt->execute();

    echo json_encode(['ok' => true]); exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'server_error', 'error' => $e->getMessage()]);
}
