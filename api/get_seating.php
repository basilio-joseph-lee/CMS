<?php
// api/get_seating.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['teacher_id'])) {
  http_response_code(403);
  echo json_encode(['seating'=>[]]);
  exit;
}

$sy = intval($_SESSION['school_year_id'] ?? 0);
$ad = intval($_SESSION['advisory_id'] ?? 0);
$sj = intval($_SESSION['subject_id'] ?? 0);

require_once __DIR__ . '/../config/db.php';
if ($conn->connect_error) {
  http_response_code(500);
  echo json_encode(['seating'=>[]]);
  exit;
}

$sql = "
  SELECT seat_no, student_id, x, y
  FROM seating_plan
  WHERE school_year_id=? AND advisory_id=? AND subject_id=?
  ORDER BY seat_no ASC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iii", $sy, $ad, $sj);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
while ($r = $res->fetch_assoc()) {
  $rows[] = [
    'seat_no'    => (int)$r['seat_no'],
    'student_id' => is_null($r['student_id']) ? null : (int)$r['student_id'],
    'x'          => is_null($r['x']) ? null : (int)$r['x'],
    'y'          => is_null($r['y']) ? null : (int)$r['y'],
  ];
}
$stmt->close();
$conn->close();

echo json_encode(['seating'=>$rows]);
