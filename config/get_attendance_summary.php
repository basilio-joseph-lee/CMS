<?php
// cms/config/get_attendance_summary.php
header('Content-Type: application/json');

$mysqli = new mysqli("localhost","root","","cms");
if ($mysqli->connect_error) {
  http_response_code(500);
  echo json_encode(['status'=>'error','message'=>'DB error']);
  exit;
}

$student_id = intval($_POST['student_id'] ?? 0);
$school_year_id = intval($_POST['school_year_id'] ?? 0);

if ($student_id <= 0) {
  echo json_encode(['status'=>'error','message'=>'student_id required']);
  exit;
}

// Resolve active school year if not provided
if ($school_year_id <= 0) {
  $rs = $mysqli->query("SELECT school_year_id FROM school_years WHERE status='active' LIMIT 1");
  if ($rs && $rs->num_rows) {
    $school_year_id = intval($rs->fetch_assoc()['school_year_id']);
  }
}

$sql = "
  SELECT status, COUNT(*) AS c
  FROM attendance_records
  WHERE student_id = ?
    AND (? = 0 OR school_year_id = ?)
  GROUP BY status
";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("iii", $student_id, $school_year_id, $school_year_id);
$stmt->execute();
$res = $stmt->get_result();

$present = 0; $absent = 0; $late = 0;
while ($row = $res->fetch_assoc()) {
  switch ($row['status']) {
    case 'Present': $present = (int)$row['c']; break;
    case 'Absent':  $absent  = (int)$row['c']; break;
    case 'Late':    $late    = (int)$row['c']; break;
  }
}

echo json_encode(['status'=>'success','present'=>$present,'absent'=>$absent,'late'=>$late]);
