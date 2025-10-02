<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

$conn = new mysqli("localhost", "root", "", "cms");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(["error" => "Invalid request method"]);
  exit;
}

$parent_id = $_POST['parent_id'] ?? '';
if (empty($parent_id)) {
  echo json_encode(["error" => "Missing parent_id"]);
  exit;
}

$query = "SELECT student_id, fullname, gender FROM students WHERE parent_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $parent_id);
$stmt->execute();
$result = $stmt->get_result();

// Return id, fullname, gender, avatar_path
$out = [];
while ($r = $result->fetch_assoc()) {
  $out[] = [
    'student_id'  => (int)$r['student_id'],
    'fullname'    => $r['fullname'],
    'gender'      => $r['gender'],
    'avatar_path' => $r['avatar_path'] ?? null,
  ];
}
echo json_encode($out);
