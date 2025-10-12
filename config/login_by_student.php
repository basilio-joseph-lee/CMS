<?php
// /config/login_by_student.php
// Marks one "Present" attendance per day per student (no subject linking required).

header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Manila'); // PH timezone

// ===== Match session with face_login.php =====
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
  return (strpos($script, '/CMS/') !== false || preg_match('#/CMS$#', $script)) ? '/CMS' : '';
}

$HTTPS  = is_https();
$DOMAIN = cookie_domain_for_host($_SERVER['HTTP_HOST'] ?? '');
$BASE   = app_base_path();

session_name('CMS_STUDENT');
session_set_cookie_params([
  'lifetime' => 0,
  'path'     => ($BASE === '' ? '/' : $BASE),
  'domain'   => $DOMAIN ?: '',
  'secure'   => $HTTPS,
  'httponly' => true,
  'samesite' => 'Lax',
]);
if (session_status() === PHP_SESSION_NONE) session_start();

// ===== Validate Request =====
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  echo json_encode(['success'=>false,'error'=>'Invalid request']); exit;
}

$student_id = (int)($_POST['student_id'] ?? 0);
if ($student_id <= 0) {
  echo json_encode(['success'=>false,'error'=>'Missing student_id']); exit;
}

require_once __DIR__ . '/db.php';
$conn->set_charset('utf8mb4');

try {
  // ===== 1. Get Student =====
  $stmt = $conn->prepare("SELECT student_id, fullname, avatar_path, face_image_path FROM students WHERE student_id=? LIMIT 1");
  $stmt->bind_param("i", $student_id);
  $stmt->execute();
  $student = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$student) {
    echo json_encode(['success'=>false,'error'=>'Student not found']); exit;
  }

  // ===== 2. Get School Year + Advisory (optional) =====
  $school_year_id = 0;
  $advisory_id    = 0;

  $stmt = $conn->prepare("
    SELECT e.advisory_id, e.school_year_id
    FROM student_enrollments e
    JOIN school_years y ON y.school_year_id = e.school_year_id
    WHERE e.student_id = ?
    ORDER BY (y.status='active') DESC, e.school_year_id DESC
    LIMIT 1
  ");
  $stmt->bind_param("i", $student_id);
  $stmt->execute();
  $en = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if ($en) {
    $advisory_id    = (int)$en['advisory_id'];
    $school_year_id = (int)$en['school_year_id'];
  }

  // ===== 3. Start/refresh session =====
  session_regenerate_id(true);
  unset($_SESSION['teacher_id'], $_SESSION['admin_id']);
  $_SESSION['role'] = 'STUDENT';
  $_SESSION['student_id'] = $student['student_id'];
  $_SESSION['student_fullname'] = $student['fullname'];
  $_SESSION['fullname'] = $student['fullname'];
  $_SESSION['avatar_path'] = $student['avatar_path'] ?? '';
  $_SESSION['face_image'] = $student['face_image_path'] ?? '';

  // Ensure cookie sync
  setcookie(session_name(), session_id(), [
    'expires'  => 0,
    'path'     => ($BASE === '' ? '/' : $BASE),
    'domain'   => $DOMAIN ?: '',
    'secure'   => $HTTPS,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);

  // ===== 4. Mark Attendance (one per day) =====
  $today = date('Y-m-d');
  $attendance_inserted = false;
  $already = false;

  $stmt = $conn->prepare("
    SELECT attendance_id FROM attendance_records
    WHERE student_id=? AND DATE(`timestamp`)=?
    LIMIT 1
  ");
  $stmt->bind_param("is", $student_id, $today);
  $stmt->execute();
  $exists = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if ($exists) {
    $already = true;
  } else {
    $stmt = $conn->prepare("
      INSERT INTO attendance_records (student_id, advisory_id, school_year_id, status, `timestamp`)
      VALUES (?, ?, ?, 'Present', NOW())
    ");
    $stmt->bind_param("iii", $student_id, $advisory_id, $school_year_id);
    $stmt->execute();
    $stmt->close();
    $attendance_inserted = true;
  }

  session_write_close();

  // ===== 5. Return JSON Response =====
  echo json_encode([
    'success' => true,
    'student_id' => $student_id,
    'studentName' => $student['fullname'],
    'attendance' => [
      'inserted' => $attendance_inserted,
      'alreadyMarked' => $already,
      'date' => $today,
      'status' => 'Present'
    ]
  ]);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
  exit;
}
