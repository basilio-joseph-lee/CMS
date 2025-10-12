<?php
// /config/login_by_student.php
// Align session with face_login.php and insert per-subject attendance (PHP-only duplicate guard).

header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Manila'); // PH day boundary

/* -------- Helpers (match face_login.php semantics) -------- */
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

/* -------- Use SAME session name & cookie scope as face_login.php -------- */
$HTTPS  = is_https();
$DOMAIN = cookie_domain_for_host($_SERVER['HTTP_HOST'] ?? '');
$BASE   = app_base_path(); // "" on prod, "/CMS" on localhost

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

/* -------- Input guard -------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  echo json_encode(['success'=>false,'error'=>'Invalid request']); exit;
}
$student_id = (int)($_POST['student_id'] ?? 0);
if ($student_id <= 0) { echo json_encode(['success'=>false,'error'=>'Missing student_id']); exit; }

/* -------- DB -------- */
require_once __DIR__ . '/db.php';
$conn->set_charset('utf8mb4');

try {
  /* 1) Student */
  $stmt = $conn->prepare("SELECT student_id, fullname, gender, avatar_path, face_image_path FROM students WHERE student_id=? LIMIT 1");
  $stmt->bind_param("i", $student_id);
  $stmt->execute();
  $student = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$student) { echo json_encode(['success'=>false,'error'=>'Student not found']); exit; }

  /* 2) Enrollment context (prefer ACTIVE SY, else latest) */
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

  if (!$en) {
    // Still allow login, just report why attendance not written
    session_regenerate_id(true);
    unset($_SESSION['teacher_id'], $_SESSION['admin_id']);
    $_SESSION['role'] = 'STUDENT';
    $_SESSION['student_id'] = $student['student_id'];
    $_SESSION['student_fullname'] = $student['fullname'];
    $_SESSION['fullname'] = $student['fullname'];
    $_SESSION['avatar_path'] = $student['avatar_path'] ?? '';
    $_SESSION['face_image'] = $student['face_image_path'] ?? '';

    echo json_encode([
      'success'=>true,
      'student_id'=>$student_id,
      'studentName'=>$student['fullname'],
      'attendance'=>[
        'inserted'=>false,
        'alreadyMarked'=>false,
        'reason'=>'NO_ENROLLMENT_FOUND'
      ]
    ]);
    exit;
  }

  $advisory_id    = (int)$en['advisory_id'];
  $school_year_id = (int)$en['school_year_id'];

  /* 3) Subject resolution (live by schedule -> fallback within advisory/SY) */
  $subject_id = 0;
  $dow     = (int)date('N');      // 1..7 (Mon..Sun)
  $nowTime = date('H:i:s');

  // Try to pick the current subject by schedule
  $stmt = $conn->prepare("
    SELECT st.subject_id
    FROM schedule_timeblocks st
    JOIN subjects s ON s.subject_id = st.subject_id
    WHERE s.advisory_id = ?
      AND s.school_year_id = ?
      AND st.day_of_week = ?
      AND ? BETWEEN st.start_time AND st.end_time
    ORDER BY st.start_time ASC
    LIMIT 1
  ");
  $stmt->bind_param("iiis", $advisory_id, $school_year_id, $dow, $nowTime);
  $stmt->execute();
  $live = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if ($live) {
    $subject_id = (int)$live['subject_id'];
  } else {
    // Fallback: most recent subject for the advisory & SY
    $stmt = $conn->prepare("SELECT subject_id FROM subjects WHERE advisory_id=? AND school_year_id=? ORDER BY subject_id DESC LIMIT 1");
    $stmt->bind_param("ii", $advisory_id, $school_year_id);
    $stmt->execute();
    $sub = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $subject_id = $sub ? (int)$sub['subject_id'] : 0;
  }

  /* 4) Session (aligned with face_login.php) */
  session_regenerate_id(true);
  unset($_SESSION['teacher_id'], $_SESSION['admin_id']);
  $_SESSION['role'] = 'STUDENT';
  $_SESSION['student_id'] = $student['student_id'];
  $_SESSION['student_fullname'] = $student['fullname'];
  $_SESSION['fullname'] = $student['fullname'];
  $_SESSION['avatar_path'] = $student['avatar_path'] ?? '';
  $_SESSION['face_image'] = $student['face_image_path'] ?? '';

  // Re-send cookie in same scope
  setcookie(session_name(), session_id(), [
    'expires'  => 0,
    'path'     => ($BASE === '' ? '/' : $BASE),
    'domain'   => $DOMAIN ?: '',
    'secure'   => $HTTPS,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);

  /* 5) Attendance (pure PHP guard; no schema/index changes) */
  $today = date('Y-m-d');
  $attendance_inserted = false;
  $already = false;
  $reason = null;

  if ($subject_id > 0) {
    // Duplicate check: per student + subject + calendar day
    $stmt = $conn->prepare("
      SELECT attendance_id
      FROM attendance_records
      WHERE student_id=? AND subject_id=? AND DATE(`timestamp`)=?
      LIMIT 1
    ");
    $stmt->bind_param("iis", $student_id, $subject_id, $today);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($exists) {
      $already = true;
    } else {
      // Insert
      $stmt = $conn->prepare("
        INSERT INTO attendance_records
          (student_id, subject_id, advisory_id, school_year_id, status, `timestamp`)
        VALUES (?, ?, ?, ?, 'Present', NOW())
      ");
      $stmt->bind_param("iiii", $student_id, $subject_id, $advisory_id, $school_year_id);
      $stmt->execute();
      $stmt->close();
      $attendance_inserted = true;
    }
  } else {
    $reason = 'NO_SUBJECT_FOUND_FOR_ADVISORY_SY';
  }

  session_write_close();

  echo json_encode([
    'success'     => true,
    'student_id'  => $student_id,
    'studentName' => $student['fullname'],
    'attendance'  => [
      'inserted'        => $attendance_inserted,
      'alreadyMarked'   => $already,
      'date'            => $today,
      'school_year_id'  => $school_year_id,
      'advisory_id'     => $advisory_id,
      'subject_id'      => $subject_id,
      'status'          => $attendance_inserted || $already ? 'Present' : null,
      'reason'          => $reason
    ],
  ]);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['success'=>false,'error'=>'DB error: '.$e->getMessage()]);
  exit;
}
