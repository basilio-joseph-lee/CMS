<?php
// /config/login_by_student.php
session_start(); // keep this, we will re-send cookie below

header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Manila'); // for correct timestamp

// ===== Helper functions =====
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

// ===== Validate POST =====
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  echo json_encode(['success'=>false,'error'=>'Invalid request']); exit;
}
$student_id = (int)($_POST['student_id'] ?? 0);
if ($student_id <= 0) {
  echo json_encode(['success'=>false,'error'=>'Missing student_id']); exit;
}

// ===== DB =====
require_once __DIR__ . '/db.php';
$conn->set_charset('utf8mb4');

// ===== Fetch student =====
$stmt = $conn->prepare("SELECT student_id, fullname, gender, avatar_path, face_image_path FROM students WHERE student_id=? LIMIT 1");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) { echo json_encode(['success'=>false,'error'=>'Student not found']); exit; }

// ===== Reset session =====
session_regenerate_id(true);
unset($_SESSION['teacher_id'], $_SESSION['teacher_fullname'], $_SESSION['teacher_name'],
      $_SESSION['admin_id'], $_SESSION['admin_fullname']);
$_SESSION['role'] = 'STUDENT';
$_SESSION['student_id']       = (int)$row['student_id'];
$_SESSION['student_fullname'] = (string)$row['fullname'];
$_SESSION['fullname']         = (string)$row['fullname'];
$_SESSION['avatar_path']      = (string)($row['avatar_path'] ?? '');
$_SESSION['face_image']       = (string)($row['face_image_path'] ?? '');

// ===== Sync cookie =====
$HTTPS  = is_https();
$DOMAIN = cookie_domain_for_host($_SERVER['HTTP_HOST'] ?? '');
$PATH   = base_path();
setcookie(session_name(), session_id(), [
  'expires'  => 0,
  'path'     => $PATH,
  'domain'   => $DOMAIN ?: '',
  'secure'   => $HTTPS,
  'httponly' => true,
  'samesite' => 'Lax',
]);

// ===== INSERT attendance =====
try {
  // If you want only one record per day, uncomment the 3 lines below
  /*
  $today = date('Y-m-d');
  $chk = $conn->prepare("SELECT attendance_id FROM attendance_records WHERE student_id=? AND DATE(`timestamp`)=? LIMIT 1");
  $chk->bind_param("is", $student_id, $today);
  $chk->execute();
  if ($chk->get_result()->num_rows === 0) {
  */
      $ins = $conn->prepare("
        INSERT INTO attendance_records (student_id, subject_id, advisory_id, school_year_id, status, `timestamp`)
        VALUES (?, 0, 0, 0, 'Present', NOW())
      ");
      $ins->bind_param("i", $student_id);
      $ins->execute();
      $ins->close();
  /*
  }
  $chk->close();
  */
} catch (Throwable $e) {
  echo json_encode(['success'=>false,'error'=>'Attendance insert failed: '.$e->getMessage()]);
  exit;
}

// ===== Return JSON =====
session_write_close();
echo json_encode([
  'success'     => true,
  'student_id'  => $_SESSION['student_id'],
  'studentName' => $_SESSION['student_fullname'],
  'message'     => 'Attendance recorded successfully'
]);
exit;
