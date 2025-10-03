<?php
// cms/config/get_announcements.php
header('Content-Type: application/json');

include("db.php");
if ($conn->connect_error) {
  http_response_code(500);
  echo json_encode(['status'=>'error','message'=>'DB connection failed']);
  exit;
}

$parent_id = intval($_POST['parent_id'] ?? 0);
if ($parent_id <= 0) {
  echo json_encode(['status'=>'error','message'=>'parent_id required']);
  exit;
}

/*
  We map parent -> children -> enrollments to find advisory_id (class) and subject_id,
  then pull announcements that match those. visible_until honored if present.
  Tables referenced: students (parent_id), student_enrollments (advisory_id, subject_id),
  announcements (class_id, subject_id, visible_until). 
*/
$sql = "
  SELECT DISTINCT a.id, a.title, a.message, a.date_posted, a.visible_until,
         ac.class_name, s.subject_name
  FROM announcements a
  INNER JOIN student_enrollments se 
    ON se.advisory_id = a.class_id 
   AND (a.subject_id IS NULL OR a.subject_id = se.subject_id)
  INNER JOIN students st ON st.student_id = se.student_id
  LEFT JOIN advisory_classes ac ON ac.advisory_id = a.class_id
  LEFT JOIN subjects sj ON sj.subject_id = se.subject_id
  LEFT JOIN subjects s ON s.subject_id = a.subject_id
  WHERE st.parent_id = ?
    AND (a.visible_until IS NULL OR a.visible_until >= CURDATE())
  ORDER BY a.date_posted DESC, a.id DESC
";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $parent_id);
$stmt->execute();
$res = $stmt->get_result();

$out = [];
while ($row = $res->fetch_assoc()) {
  $out[] = [
    'id' => (int)$row['id'],
    'title' => $row['title'],
    'message' => $row['message'],
    'date_posted' => $row['date_posted'],
    'visible_until' => $row['visible_until'],
    'class_name' => $row['class_name'],
    'subject_name' => $row['subject_name'], // may be null if announcement not tied to a subject
  ];
}

echo json_encode(['status'=>'success','announcements'=>$out]);
