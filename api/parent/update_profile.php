<?php
// /api/parent/update_profile.php
// CORS (simple)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Requested-With");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  echo json_encode(['success' => true, 'message' => 'OK']);
  exit;
}

require_once __DIR__ . '/../../config/db.php';

// Normalize mysqli handle: support $db or $conn from db.php
$mysqli = null;
if (isset($db) && $db instanceof mysqli)       { $mysqli = $db; }
elseif (isset($conn) && $conn instanceof mysqli){ $mysqli = $conn; }

if (!$mysqli) {
  http_response_code(500);
  echo json_encode(['success' => false, 'message' => 'Database connection not available']);
  exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli->set_charset('utf8mb4');

// Read input: JSON or form-encoded
$raw = file_get_contents('php://input');
$input = [];
if (!empty($raw)) {
  $decoded = json_decode($raw, true);
  if (is_array($decoded)) $input = $decoded;
}
if (empty($input)) {
  // fallback for application/x-www-form-urlencoded
  $input = $_POST;
}

// Validate
$parent_id = isset($input['parent_id']) ? (int)$input['parent_id'] : 0;
if ($parent_id <= 0) {
  echo json_encode(['success' => false, 'message' => 'Missing or invalid parent_id']);
  exit;
}

$fullname       = isset($input['fullname']) ? trim((string)$input['fullname']) : null;
$email          = isset($input['email']) ? trim((string)$input['email']) : null;
$mobile_number  = isset($input['mobile_number']) ? trim((string)$input['mobile_number']) : null;
$password_plain = isset($input['password']) ? (string)$input['password'] : null;

// Build dynamic SET
$fields = [];
$params = [];
$types  = '';

if ($fullname !== null && $fullname !== '') {
  $fields[] = 'fullname = ?';
  $params[] = $fullname;
  $types   .= 's';
}
if ($email !== null && $email !== '') {
  // check unique email (except self)
  $chk = $mysqli->prepare("SELECT parent_id FROM parents WHERE email = ? AND parent_id <> ? LIMIT 1");
  $chk->bind_param('si', $email, $parent_id);
  $chk->execute();
  $chk->store_result();
  if ($chk->num_rows > 0) {
    echo json_encode(['success' => false, 'message' => 'Email already in use by another account']);
    exit;
  }
  $chk->close();

  $fields[] = 'email = ?';
  $params[] = $email;
  $types   .= 's';
}
if ($mobile_number !== null && $mobile_number !== '') {
  $fields[] = 'mobile_number = ?';
  $params[] = $mobile_number;
  $types   .= 's';
}
if ($password_plain !== null && $password_plain !== '') {
  $hash = password_hash($password_plain, PASSWORD_DEFAULT);
  $fields[] = 'password = ?';
  $params[] = $hash;
  $types   .= 's';
}

if (empty($fields)) {
  echo json_encode(['success' => true, 'message' => 'No changes made']);
  exit;
}

try {
  $sql = "UPDATE parents SET " . implode(', ', $fields) . " WHERE parent_id = ?";
  $params[] = $parent_id;
  $types   .= 'i';

  $stmt = $mysqli->prepare($sql);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();

  $rows = $stmt->affected_rows;
  $stmt->close();

  // Return the latest row (optional but useful for client refresh)
  $get = $mysqli->prepare("SELECT parent_id, fullname, email, mobile_number, created_at FROM parents WHERE parent_id = ? LIMIT 1");
  $get->bind_param('i', $parent_id);
  $get->execute();
  $res = $get->get_result();
  $row = $res->fetch_assoc();
  $get->close();

  echo json_encode([
    'success' => true,
    'message' => $rows > 0 ? 'Profile updated successfully' : 'No changes made',
    'parent'  => $row ?: null
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['success' => false, 'message' => 'Server error: '.$e->getMessage()]);
}
