login by student 
<?php
// /config/login_by_student.php
session_start(); // keep this, we will re-send cookie below

header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Helpers to mirror face_login.php
function is_https() {
  if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
  if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') return true;
  return false;
}
function cookie_domain_for_host($host) {
  $h = preg_replace('/:\d+$/', '', (string)$host);
  if ($h === 'localhost' || filter_var($h, FILTER_VALIDATE_IP)) return '';
  return $h;
}
function base_path() {
  $script = $_SERVER['SCRIPT_NAME'] ?? '/';
  if (strpos($script, '/CMS/') !== false || substr($script, -4) === '/CMS') return '/CMS';
  return '/';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  echo json_encode(['success' => false, 'error' => 'Invalid request']); exit;
}

$student_id = (int)($_POST['student_id'] ?? 0);
if ($student_id <= 0) {
  echo json_encode(['success' => false, 'error' => 'Missing student_id']); exit;
}

require_once __DIR__ . '/db.php';
$conn->set_charset('utf8mb4');

$stmt = $conn->prepare("SELECT student_id, fullname, gender, avatar_path, face_image_path FROM students WHERE student_id=? LIMIT 1");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) { echo json_encode(['success'=>false,'error'=>'Student not found']); exit; }

// Reset session to a fresh id
session_regenerate_id(true);

// Clear other roles
unset($_SESSION['teacher_id'], $_SESSION['teacher_fullname'], $_SESSION['teacher_name'],
      $_SESSION['admin_id'], $_SESSION['admin_fullname']);
$_SESSION['role'] = 'STUDENT';

// Set student identity (do NOT set subject/advisory here)
$_SESSION['student_id']       = (int)$row['student_id'];
$_SESSION['student_fullname'] = (string)$row['fullname'];
$_SESSION['fullname']         = (string)$row['fullname'];
$_SESSION['avatar_path']      = (string)($row['avatar_path'] ?? '');
$_SESSION['face_image']       = (string)($row['face_image_path'] ?? '');

// Re-send cookie with same scope as face_login.php
$HTTPS  = is_https();
$DOMAIN = cookie_domain_for_host($_SERVER['HTTP_HOST'] ?? '');
$PATH   = base_path();
setcookie(session_name(), session_id(), [
  'expires'  => 0,
  'path'     => $PATH,        // 👈 must match face_login.php
  'domain'   => $DOMAIN ?: '',
  'secure'   => $HTTPS,
  'httponly' => true,
  'samesite' => 'Lax',
]);

session_write_close();
echo json_encode([
  'success'     => true,
  'student_id'  => $_SESSION['student_id'],
  'studentName' => $_SESSION['student_fullname'],
]);
exit;
