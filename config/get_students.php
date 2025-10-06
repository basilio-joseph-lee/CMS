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
  echo json_encode([]);
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

/**
 * Build an absolute URL for an avatar by:
 *  - respecting absolute URLs already stored,
 *  - trying several normalized web paths,
 *  - checking the filesystem (DOCUMENT_ROOT) to pick the one that actually exists.
 */
function resolve_avatar_url(?string $raw): ?string {
  if ($raw === null) return null;
  $p = trim($raw);
  if ($p === '') return null;

  // Already absolute?
  if (preg_match('~^https?://~i', $p)) return $p;

  // Base URL
  $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == '443');
  $scheme  = $isHttps ? 'https' : 'http';
  $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $base    = $scheme . '://' . $host;

  // Normalize into candidate web paths (leading slash)
  $p1 = '/' . ltrim($p, '/');               // as-is
  $candidates = [$p1];

  // Drop "/CMS" prefix if present (prod often serves without it)
  if (stripos($p1, '/CMS/') === 0) {
    $candidates[] = substr($p1, 4);         // '/CMS/...' -> '/...'
  }

  // Common alternate roots some installs use
  // e.g. '/uploads/avatar/6.png' or '/public/avatar/6.png'
  if (preg_match('#/avatar/([^/]+\.(?:png|jpe?g|gif|webp))$#i', $p1, $m)) {
    $file = $m[1];
    $candidates[] = '/avatar/' . $file;
    $candidates[] = '/uploads/avatar/' . $file;
    $candidates[] = '/public/avatar/' . $file;
  }

  // De-dupe
  $candidates = array_values(array_unique($candidates));

  // Try filesystem existence under document root
  $doc = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
  foreach ($candidates as $webPath) {
    if ($doc !== '' && file_exists($doc . $webPath)) {
      return $base . $webPath;
    }
  }

  // Fallback: prefer path without /CMS if it was there; else first candidate
  $fallback = (stripos($p1, '/CMS/') === 0) ? substr($p1, 4) : $p1;
  return $base . $fallback;
}

// ---- Query: real columns from `students` only ----
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
    'avatar_url'  => resolve_avatar_url($avatar_path), // guaranteed best-guess that exists
  ];
}
$stKids->close();

echo json_encode($out, JSON_UNESCAPED_UNICODE);
