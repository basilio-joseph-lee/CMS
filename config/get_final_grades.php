<?php
// cms/config/get_final_grades.php
header('Content-Type: application/json');

include("db.php");
if ($conn->connect_error) {
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

if ($school_year_id <= 0) {
  $rs = $mysqli->query("SELECT school_year_id FROM school_years WHERE status='active' LIMIT 1");
  if ($rs && $rs->num_rows) {
    $school_year_id = intval($rs->fetch_assoc()['school_year_id']);
  }
}

$sql = "
  SELECT fg.subject_id, s.subject_name, fg.q1, fg.q2, fg.q3, fg.q4, fg.final_average, fg.remarks
  FROM final_grades fg
  LEFT JOIN subjects s ON s.subject_id = fg.subject_id
  WHERE fg.student_id = ?
    AND (? = 0 OR fg.school_year_id = ?)
  ORDER BY s.subject_name
";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("iii", $student_id, $school_year_id, $school_year_id);
$stmt->execute();
$res = $stmt->get_result();

$out = [];
while ($row = $res->fetch_assoc()) {
  $out[] = [
    'subject_id' => (int)$row['subject_id'],
    'subject_name' => $row['subject_name'],
    'q1' => is_null($row['q1']) ? null : (float)$row['q1'],
    'q2' => is_null($row['q2']) ? null : (float)$row['q2'],
    'q3' => is_null($row['q3']) ? null : (float)$row['q3'],
    'q4' => is_null($row['q4']) ? null : (float)$row['q4'],
    'final_average' => is_null($row['final_average']) ? null : (float)$row['final_average'],
    'remarks' => $row['remarks'],
  ];
}

echo json_encode(['status'=>'success','final_grades'=>$out]);
