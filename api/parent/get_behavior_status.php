<?php
// api/parent/get_behavior_status.php
header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
error_reporting(E_ERROR | E_PARSE);

try {
    session_start();
    require_once __DIR__ . '/../../config/db.php';
    $conn->set_charset('utf8mb4');

    $parent_id  = (int)($_SESSION['parent_id'] ?? 0);
    $student_id = (int)($_GET['student_id'] ?? $_POST['student_id'] ?? 0);

    if ($parent_id <= 0) {
        http_response_code(401);
        echo json_encode(['ok'=>false,'message'=>'Unauthorized parent']);
        exit;
    }
    if ($student_id <= 0) {
        echo json_encode(['ok'=>false,'message'=>'student_id required']);
        exit;
    }

    // Ownership check
    $q = $conn->prepare("SELECT student_id FROM students WHERE student_id=? AND parent_id=? LIMIT 1");
    $q->bind_param('ii', $student_id, $parent_id);
    $q->execute();
    if (!$q->get_result()->fetch_row()) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'message'=>'Forbidden: not your child']);
        exit;
    }
    $q->close();

    // Latest behavior
    $r = $conn->prepare("SELECT action_type, timestamp FROM behavior_logs WHERE student_id=? ORDER BY timestamp DESC LIMIT 1");
    $r->bind_param('i', $student_id);
    $r->execute();
    $row = $r->get_result()->fetch_assoc();
    $r->close();

    $action = strtolower(trim($row['action_type'] ?? ''));
    $ts     = $row['timestamp'] ?? null;

    $AWAY = ['restroom','snack','lunch_break','out_time','water_break','not_well','borrow_book','return_material','log_out'];

    echo json_encode([
        'ok'  => true,
        'map' => [
            (string)$student_id => [
                'action'    => $action,
                'label'     => $action,
                'is_away'   => in_array($action, $AWAY, true),
                'timestamp' => $ts,
            ],
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'message'=>'Server error']);
}
