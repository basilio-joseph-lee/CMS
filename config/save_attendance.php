<?php
session_start();

if (!isset($_SESSION['teacher_id'])) {
  http_response_code(403);
  echo json_encode(['error' => 'Unauthorized']);
  exit;
}

include("db.php");

// ✅ Use regular form POST
$data = $_POST;

if (!isset($data['attendance']) || !is_array($data['attendance'])) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid data received']);
  exit;
}

$subject_id = $_POST['subject_id'] ?? null;
$advisory_id = $_POST['advisory_id'] ?? null;
$school_year_id = $_POST['school_year_id'] ?? null;

if (!$subject_id || !$advisory_id || !$school_year_id) {
  http_response_code(400);
  echo json_encode(['error' => 'Missing required fields']);
  exit;
}

$inserted = 0;
foreach ($data['attendance'] as $student_id => $status) {
  if (!in_array($status, ['Present', 'Absent', 'Late'])) continue;

  $stmt = $conn->prepare("INSERT INTO attendance_records (student_id, subject_id, advisory_id, school_year_id, status) VALUES (?, ?, ?, ?, ?)");
  $stmt->bind_param("iiiis", $student_id, $subject_id, $advisory_id, $school_year_id, $status);
  if ($stmt->execute()) {
    $inserted++;
  }
  $stmt->close();
}

$conn->close();

// ✅ Redirect back or show success
header("Location: ../user/teacher/mark_attendance.php?success=1");
exit;
