<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['teacher_id'])) {
    echo json_encode(['ok' => false, 'message' => 'Teacher not logged in']);
    exit;
}

$teacher_id = intval($_SESSION['teacher_id']);

$sql = "
    SELECT r.id, s.fullname AS student_name, r.requested_at
    FROM out_time_requests r
    JOIN students s ON s.student_id = r.student_id
    WHERE r.status='pending'
      AND (
        r.advisory_id IN (SELECT advisory_id FROM subjects WHERE teacher_id=?)
        OR r.subject_id IN (SELECT subject_id FROM subjects WHERE teacher_id=?)
      )
    ORDER BY r.requested_at DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $teacher_id, $teacher_id);
$stmt->execute();
$res = $stmt->get_result();

$items = [];
while ($row = $res->fetch_assoc()) {
    $items[] = $row;
}

echo json_encode([
    'ok' => true,
    'items' => $items
]);
