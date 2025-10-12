<?php
// /config/login_by_student.php
// Same behavior as your original + ALWAYS insert a Present row into attendance_records on login.

header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Manila'); // keep timestamps consistent (PH)

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

/* ==== Match face_login.php session name & scope ==== */
$HTTPS  = is_https();
$DOMAIN = cookie_domain_for_host($_SERVER['HTTP_HOST'] ?? '');
$PATH   = base_path();

session_name('CMS_STUDENT');
session_set_cookie_params([
  'lifetime' => 0,
  'path'     => $PATH,          // "/" on prod root, "/CMS" on localhost
  'domain'   => $DOMAIN ?: '',
  'secure'   => $HTTPS,
  'httponly' => true,
  'samesite' => 'Lax',
]);
if (session_status() === PHP_SESSION_NONE) session_start();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  echo json_encode(['success' => false, 'error' => 'Invalid request']); exit;
}

$student_id = (int)($_POST['student_id'] ?? 0);
if ($student_id <= 0) {
  echo json_encode(['success' => false, 'error' => 'Missing student_id']); exit;
}

require_once __DIR__ . '/db.php';
$conn->set_charset('utf8mb4');

try {
  /* 1) Student */
  $stmt = $conn->prepare("SELECT student_id, fullname, gender, avatar_path, face_image_path FROM students WHERE student_id=? LIMIT 1");
  $stmt->bind_param("i", $student_id);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$row) { echo json_encode(['success'=>false,'error'=>'Student not found']); exit; }

  /* 2) Refresh session */
  session_regenerate_id(true);

  unset($_SESSION['teacher_id'], $_SESSION['teacher_fullname'], $_SESSION['teacher_name'],
        $_SESSION['admin_id'], $_SESSION['admin_fullname']);
  $_SESSION['role'] = 'STUDENT';
  $_SESSION['student_id']       = (int)$row['student_id'];
  $_SESSION['student_fullname'] = (string)$row['fullname'];
  $_SESSION['fullname']         = (string)$row['fullname'];
  $_SESSION['avatar_path']      = (string)($row['avatar_path'] ?? '');
  $_SESSION['face_image']       = (string)($row['face_image_path'] ?? '');

  // ensure cookie is sent with same scope as face_login
  setcookie(session_name(), session_id(), [
    'expires'  => 0,
    'path'     => $PATH,
    'domain'   => $DOMAIN ?: '',
    'secure'   => $HTTPS,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);

  /* 3) Derive enrollment context (optional; fallback to 0s if not found) */
  $advisory_id = 0;
  $school_year_id = 0;
  $subject_id = 0; // we won’t block on this—table allows any int; 0 is fine if you have no FK

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

    // optional: grab any subject for advisory+SY (not required for insert)
    $stmt = $conn->prepare("SELECT subject_id FROM subjects WHERE advisory_id=? AND school_year_id=? ORDER BY subject_id ASC LIMIT 1");
    $stmt->bind_param("ii", $advisory_id, $school_year_id);
    $stmt->execute();
    $s = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($s) $subject_id = (int)$s['subject_id'];
  }

  /* 4) ALWAYS insert attendance (no per-day/per-subject filters as requested) */
  $attendance_inserted = false;
  $insert_id = null;

  $ins = $conn->prepare("
    INSERT INTO attendance_records
      (student_id, subject_id, advisory_id, school_year_id, status, `timestamp`)
    VALUES (?, ?, ?, ?, 'Present', NOW())
  ");
  $ins->bind_param("iiii", $student_id, $subject_id, $advisory_id, $school_year_id);
  if (!$ins->execute()) {
    // if Hostinger blocks error, we still return message
    $err = $conn->error ?: 'Unknown insert error';
    echo json_encode(['success'=>false, 'error'=>"Attendance insert failed: $err"]);
    exit;
  }
  $insert_id = $conn->insert_id;
  $ins->close();
  $attendance_inserted = true;

  session_write_close();

  /* 5) Response */
  echo json_encode([
    'success'     => true,
    'student_id'  => $_SESSION['student_id'],
    'studentName' => $_SESSION['student_fullname'],
    'attendance'  => [
      'inserted'       => $attendance_inserted,
      'attendance_id'  => $insert_id,
      'school_year_id' => $school_year_id,
      'advisory_id'    => $advisory_id,
      'subject_id'     => $subject_id,
      'status'         => 'Present'
    ]
  ]);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['success'=>false,'error'=>'DB error: '.$e->getMessage()]);
  exit;
}
