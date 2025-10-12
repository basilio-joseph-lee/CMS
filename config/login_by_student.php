<?php
// /config/login_by_student.php
// Aligns session with face_login.php and inserts exactly ONE "Present" per student per day.

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ERROR | E_PARSE);
ob_start(); // swallow accidental output so JSON stays clean
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Manila');

/* -------- Helpers -------- */
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
function app_base_path() {
  $script = $_SERVER['SCRIPT_NAME'] ?? '/';
  return (strpos($script, '/CMS/') !== false || preg_match('#/CMS$#', $script)) ? '/CMS' : '/';
}

/* -------- Match face_login.php session (IMPORTANT) -------- */
$HTTPS  = is_https();
$DOMAIN = cookie_domain_for_host($_SERVER['HTTP_HOST'] ?? '');
$PATH   = app_base_path();

// Use same session name & scope as face_login.php
session_name('CMS_STUDENT');
session_set_cookie_params([
  'lifetime' => 0,
  'path'     => $PATH,
  'domain'   => $DOMAIN ?: '',
  'secure'   => $HTTPS,
  'httponly' => true,
  'samesite' => 'Lax',
]);
if (session_status() === PHP_SESSION_NONE) session_start();

/* -------- Validate input -------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  echo json_encode(['success'=>false,'error'=>'Invalid request']); exit;
}
$student_id = (int)($_POST['student_id'] ?? 0);
if ($student_id <= 0) {
  echo json_encode(['success'=>false,'error'=>'Missing student_id']); exit;
}

/* -------- DB -------- */
require_once __DIR__ . '/db.php'; // defines $conn
if (!isset($conn) || !($conn instanceof mysqli)) {
  echo json_encode(['success'=>false,'error'=>'Database connection not available']); exit;
}
$conn->set_charset('utf8mb4');

try {
  /* 1) Fetch student */
  $stmt = $conn->prepare("SELECT student_id, fullname, gender, avatar_path, face_image_path FROM students WHERE student_id=? LIMIT 1");
  $stmt->bind_param("i", $student_id);
  $stmt->execute();
  $student = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$student) { echo json_encode(['success'=>false,'error'=>'Student not found']); exit; }

  /* 2) Refresh session identity */
  session_regenerate_id(true);
  unset($_SESSION['teacher_id'], $_SESSION['teacher_fullname'], $_SESSION['teacher_name'],
        $_SESSION['admin_id'], $_SESSION['admin_fullname']);
  $_SESSION['role'] = 'STUDENT';
  $_SESSION['student_id']       = (int)$student['student_id'];
  $_SESSION['student_fullname'] = (string)$student['fullname'];
  $_SESSION['fullname']         = (string)$student['fullname'];
  $_SESSION['avatar_path']      = (string)($student['avatar_path'] ?? '');
  $_SESSION['face_image']       = (string)($student['face_image_path'] ?? '');

  // Re-send cookie with same scope as face_login.php
  setcookie(session_name(), session_id(), [
    'expires'  => 0,
    'path'     => $PATH,
    'domain'   => $DOMAIN ?: '',
    'secure'   => $HTTPS,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);

  /* 3) Resolve enrollment context (optional; for reporting) */
  $advisory_id = 0;
  $school_year_id = 0;

  $q = "
    SELECT e.advisory_id, e.school_year_id
    FROM student_enrollments e
    JOIN school_years y ON y.school_year_id = e.school_year_id
    WHERE e.student_id = ?
    ORDER BY (y.status='active') DESC, e.school_year_id DESC
    LIMIT 1
  ";
  $stmt = $conn->prepare($q);
  $stmt->bind_param("i", $student_id);
  $stmt->execute();
  $en = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if ($en) {
    $advisory_id    = (int)$en['advisory_id'];
    $school_year_id = (int)$en['school_year_id'];
  }

  /* 4) Attendance: insert exactly ONE "Present" per calendar day per student */
  $today = date('Y-m-d');

  // Duplicate guard: per-student per-day
  $chk = $conn->prepare("SELECT attendance_id FROM attendance_records WHERE student_id=? AND DATE(`timestamp`)=? LIMIT 1");
  $chk->bind_param("is", $student_id, $today);
  $chk->execute();
  $exists = $chk->get_result()->fetch_assoc();
  $chk->close();

  $inserted = false;
  if (!$exists) {
    // subject_id set to 0 to avoid subject dependency; table has NOT NULL so 0 is safe
    $ins = $conn->prepare("
      INSERT INTO attendance_records
        (student_id, subject_id, advisory_id, school_year_id, status, `timestamp`)
      VALUES (?, 0, ?, ?, 'Present', NOW())
    ");
    $ins->bind_param("iii", $student_id, $advisory_id, $school_year_id);
    $ins->execute();
    $ins->close();
    $inserted = true;
  }

  session_write_close();

  /* 5) Clean JSON */
  ob_end_clean();
  echo json_encode([
    'success'     => true,
    'student_id'  => $_SESSION['student_id'],
    'studentName' => $_SESSION['student_fullname'],
    'attendance'  => [
      'inserted'        => $inserted,
      'alreadyMarked'   => (bool)$exists,
      'date'            => $today,
      'status'          => 'Present',
      'school_year_id'  => $school_year_id,
      'advisory_id'     => $advisory_id
    ]
  ]);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  ob_end_clean();
  echo json_encode(['success'=>false,'error'=>'DB error: '.$e->getMessage()]);
  exit;
}
