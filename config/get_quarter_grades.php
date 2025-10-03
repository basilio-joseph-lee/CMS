<?php
// cms/config/get_quarter_grades.php
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
  SELECT qg.subject_id, s.subject_name, qg.quarter, qg.grade, qg.remarks
  FROM quarter_grades qg
  LEFT JOIN subjects s ON s.subject_id = qg.subject_id
  WHERE qg.student_id = ?
    AND (? = 0 OR qg.school_year_id = ?)
  ORDER BY s.subject_name, FIELD(qg.quarter,'1st','2nd','3rd','4th')
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
    'quarter' => $row['quarter'],
    'grade' => is_null($row['grade']) ? null : (float)$row['grade'],
    'remarks' => $row['remarks'],
  ];
}

echo json_encode(['status'=>'success','grades'=>$out]);
