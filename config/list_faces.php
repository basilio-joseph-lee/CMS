<?php
// returns [{student_id, fullname, face_image_path}]
header('Content-Type: application/json');
session_start();

$subject_id     = (int)($_POST['subject_id']     ?? $_SESSION['active_subject_id']     ?? $_SESSION['subject_id']     ?? 0);
$advisory_id    = (int)($_POST['advisory_id']    ?? $_SESSION['active_advisory_id']    ?? $_SESSION['advisory_id']    ?? 0);
$school_year_id = (int)($_POST['school_year_id'] ?? $_SESSION['active_school_year_id'] ?? $_SESSION['school_year_id'] ?? 0);

$conn = @new mysqli('localhost','root','','cms');
if ($conn->connect_error) { echo json_encode([]); exit; }
$conn->set_charset('utf8mb4');

$sql = "
  SELECT s.student_id, s.fullname, s.face_image_path
  FROM students s
  JOIN student_enrollments e ON s.student_id=e.student_id
  WHERE e.subject_id=? AND e.advisory_id=? AND e.school_year_id=?
    AND s.face_image_path IS NOT NULL AND s.face_image_path <> ''
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('iii',$subject_id,$advisory_id,$school_year_id);
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = $r;
$stmt->close(); $conn->close();
echo json_encode($rows, JSON_UNESCAPED_SLASHES);
