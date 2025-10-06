<?php
// cms/config/get_students.php
// Returns a plain JSON array of the parent's students:
//   [{ student_id, fullname, gender, avatar_path|null }, ...]

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  http_response_code(204);
  exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
error_reporting(E_ERROR | E_PARSE);

// ---- DB ----
require_once __DIR__ . '/db.php'; // defines $conn
if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
  echo json_encode([]); // keep client happy with consistent shape
  exit;
}
$conn->set_charset('utf8mb4');

// ---- Inputs ----
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  echo json_encode([]);
  exit;
}
$parent_id = isset($_POST['parent_id']) ? (int)$_POST['parent_id'] : 0;
if ($parent_id <= 0) {
  echo json_encode([]);
  exit;
}

// ---- Query: use only real columns in `students` ----
$sqlKids = "SELECT student_id, fullname, gender, avatar_path
              FROM students
             WHERE parent_id = ?";
$stKids = $conn->prepare($sqlKids);
$stKids->bind_param('i', $parent_id);
$stKids->execute();
$resKids = $stKids->get_result();

// ---- Output ----
$out = [];
while ($r = $resKids->fetch_assoc()) {
  $out[] = [
    'student_id'  => (int)$r['student_id'],
    'fullname'    => (string)$r['fullname'],
    'gender'      => (string)$r['gender'],
    'avatar_path' => isset($r['avatar_path']) ? (string)$r['avatar_path'] : null,
  ];
}
$stKids->close();

echo json_encode($out, JSON_UNESCAPED_UNICODE);
