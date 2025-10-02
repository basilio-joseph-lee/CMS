<?php
// api/mark_attendance.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['teacher_id'])) { http_response_code(403); echo json_encode(['message'=>'Forbidden']); exit; }

$sy = intval($_SESSION['school_year_id'] ?? 0);
$ad = intval($_SESSION['advisory_id'] ?? 0);
$sj = intval($_SESSION['subject_id'] ?? 0);

$raw = file_get_contents('php://input');
$in = json_decode($raw, true);
$student_id = intval($in['student_id'] ?? 0);
$status = $in['status'] ?? '';

$valid = ['Present','Absent','Late'];
if (!$student_id || !in_array($status,$valid,true)) { http_response_code(400); echo json_encode(['message'=>'Bad request']); exit; }

$conn = new mysqli("localhost","root","","cms");
if ($conn->connect_error) { http_response_code(500); echo json_encode(['message'=>'DB error']); exit; }

$sql = "INSERT INTO attendance_records (student_id, subject_id, advisory_id, school_year_id, status) VALUES (?,?,?,?,?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iiiis", $student_id, $sj, $ad, $sy, $status);
$stmt->execute();
$stmt->close();
$conn->close();

echo json_encode(['message'=>'Attendance saved']);
