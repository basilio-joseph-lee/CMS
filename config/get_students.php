<?php
// cms/config/get_students.php
// Returns a plain JSON array of the parent's students:
//   [{ student_id, fullname, gender, avatar_path|null, avatar_url|null }, ...]

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
  echo json_encode([]); // consistent array for client
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

// ---- Helpers ----
function make_absolute_url(?string $raw): ?string {
  if ($raw === null) return null;
  $p = trim($raw);
  if ($p === '') return null;

  // Already absolute?
  if (preg_match('~^https?://~i', $p)) return $p;

  // Build base from request
  $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == '443');
  $scheme  = $isHttps ? 'https' : 'http';
  $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $base    = $scheme . '://' . $host;

  // Normalize CMS prefix: on production (myschoolness.site) drop leading "/CMS"
  if (stripos($host, 'myschoolness.site') !== false) {
    $p = preg_replace('#^/CMS/#i', '/', $p); // "/CMS/avatar/8.png" -> "/avatar/8.png"
  }

  // Ensure leading slash
  $p = '/' . ltrim($p, '/');

  return $base . $p;
}

// ---- Query: only real columns from `students` ----
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
  $avatar_path = isset($r['avatar_path']) ? (string)$r['avatar_path'] : null;
  $out[] = [
    'student_id'  => (int)$r['student_id'],
    'fullname'    => (string)$r['fullname'],
    'gender'      => (string)$r['gender'],
    'avatar_path' => $avatar_path,
    'avatar_url'  => make_absolute_url($avatar_path), // <- NEW absolute URL for app
  ];
}
$stKids->close();

echo json_encode($out, JSON_UNESCAPED_UNICODE);
