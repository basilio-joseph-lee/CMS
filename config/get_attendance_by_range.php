<?php
// cms/config/get_attendance_by_range.php
header('Content-Type: application/json');
include("db.php");
if ($conn->connect_error) {
  http_response_code(500);
  echo json_encode(['status'=>'error','message'=>'DB connection failed']);
  exit;
}

$student_id = intval($_POST['student_id'] ?? 0);
$from = $_POST['from'] ?? '';
$to   = $_POST['to'] ?? '';

if ($student_id <= 0 || $from === '' || $to === '') {
  echo json_encode(['status'=>'error','message'=>'student_id, from, to required']);
  exit;
}

$from_dt = date_create($from);
$to_dt   = date_create($to);
if (!$from_dt || !$to_dt) {
  echo json_encode(['status'=>'error','message'=>'invalid date range']);
  exit;
}
$from_str = $from_dt->format('Y-m-d');
$to_str   = $to_dt->format('Y-m-d');

$sql = "
  SELECT DATE(attendance_date) AS date, status
  FROM attendance_records
  WHERE student_id = ?
    AND DATE(attendance_date) BETWEEN ? AND ?
  ORDER BY date ASC
";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("iss", $student_id, $from_str, $to_str);
$stmt->execute();
$res = $stmt->get_result();

$out = [];
while ($row = $res->fetch_assoc()) {
  $out[] = ['date' => $row['date'], 'status' => $row['status']];
}

echo json_encode(['status'=>'success','days'=>$out]);
