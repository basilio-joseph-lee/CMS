<?php
// /api/out_time_requests_list.php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['teacher_id'])) {
    echo json_encode(['ok' => false, 'message' => 'Teacher not logged in']);
    exit;
}

$teacher_id     = (int)$_SESSION['teacher_id'];
$advisory_id    = (int)($_SESSION['advisory_id'] ?? 0);
$subject_id     = (int)($_SESSION['subject_id'] ?? 0);

// Fallback: if neither is in session, keep previous (teacher_id-based) logic
if ($advisory_id > 0 || $subject_id > 0) {
    $sql = "
        SELECT 
            r.id,
            r.student_id,
            s.fullname AS student_name,
            r.subject_id,
            r.advisory_id,
            r.status,
            r.requested_at
        FROM out_time_requests r
        JOIN students s ON s.student_id = r.student_id
        WHERE r.status='pending'
          AND ( (? > 0 AND r.advisory_id = ?)
             OR (? > 0 AND r.subject_id  = ?) )
        ORDER BY r.requested_at DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iiii', $advisory_id, $advisory_id, $subject_id, $subject_id);
} else {
    // Original fallback if sessions aren’t present
    $sql = "
        SELECT 
            r.id,
            r.student_id,
            s.fullname AS student_name,
            r.subject_id,
            r.advisory_id,
            r.status,
            r.requested_at
        FROM out_time_requests r
        JOIN students s ON s.student_id = r.student_id
        WHERE r.status='pending'
          AND (
            r.advisory_id IN (SELECT advisory_id FROM subjects WHERE teacher_id=?)
            OR r.subject_id  IN (SELECT subject_id  FROM subjects WHERE teacher_id=?)
          )
        ORDER BY r.requested_at DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $teacher_id, $teacher_id);
}

$stmt->execute();
$res = $stmt->get_result();

$items = [];
while ($row = $res->fetch_assoc()) {
    $items[] = $row;
}

echo json_encode(['ok' => true, 'items' => $items]);
