<?php
// CMS/api/list_faces_all.php
// Returns ALL students that have a stored face photo (no class filter).
header('Content-Type: application/json');

include("../config/db.php");

if ($conn->connect_error) { echo '[]'; exit; }
$conn->set_charset('utf8mb4');

$sql = "
  SELECT student_id, fullname, face_image_path
  FROM students
  WHERE face_image_path IS NOT NULL
    AND face_image_path <> ''
";
$res = $conn->query($sql);
$out = [];
if ($res) { while ($r = $res->fetch_assoc()) { $out[] = $r; } }

echo json_encode($out, JSON_UNESCAPED_SLASHES);
