<?php
// /config/login_by_student.php
session_start();

header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Manila'); // PH time

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
  // ===== 1. Get student =====
  $stmt = $conn->prepare("SELECT student_id, fullname, gender, avatar_path, face_image_path FROM students WHERE student_id=? LIMIT 1");
  $stmt->bind_param("i", $student_id);
  $stmt->execute();
  $student = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$student) {
    echo json_encode(['success'=>false,'error'=>'Student not found']); exit;
  }

  // ===== 2. Enrollment context =====
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

  if (!$en) {
    echo json_encode(['success'=>false,'error'=>'No enrollment found for this student']); exit;
  }

  $advisory_id    = (int)$en['advisory_id'];
  $school_year_id = (int)$en['school_year_id'];

  // ===== 3. Resolve subject =====
  $subject_id = 0;
  $dow = (int)date('N');
  $nowTime = date('H:i:s');

  // Try current active subject by schedule_timeblocks
  $stmt = $conn->prepare("
    SELECT st.subject_id
    FROM schedule_timeblocks st
    JOIN subjects s ON s.subject_id = st.subject_id
    WHERE s.advisory_id = ?
      AND s.school_year_id = ?
      AND st.day_of_week = ?
      AND ? BETWEEN st.start_time AND st.end_time
    ORDER BY st.subject_id DESC
    LIMIT 1
  ");
  $stmt->bind_param("iiis", $advisory_id, $school_year_id, $dow, $nowTime);
  $stmt->execute();
  $live = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if ($live) {
    $subject_id = (int)$live['subject_id'];
  } else {
    // fallback: first subject of that advisory/year
    $stmt = $conn->prepare("SELECT subject_id FROM subjects WHERE advisory_id=? AND school_year_id=? ORDER BY subject_id ASC LIMIT 1");
    $stmt->bind_param("ii", $advisory_id, $school_year_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $subject_id = $row ? (int)$row['subject_id'] : 0;
  }

  if ($subject_id === 0) {
    echo json_encode(['success'=>false,'error'=>'No subject found for this student']); exit;
  }

  // ===== 4. Start session =====
  session_regenerate_id(true);
  unset($_SESSION['teacher_id'], $_SESSION['admin_id']);
  $_SESSION['role'] = 'STUDENT';
  $_SESSION['student_id'] = $student['student_id'];
  $_SESSION['student_fullname'] = $student['fullname'];
  $_SESSION['fullname'] = $student['fullname'];
  $_SESSION['avatar_path'] = $student['avatar_path'] ?? '';
  $_SESSION['face_image'] = $student['face_image_path'] ?? '';

  // cookie
  $HTTPS  = is_https();
  $DOMAIN = cookie_domain_for_host($_SERVER['HTTP_HOST'] ?? '');
  $PATH   = base_path();
  setcookie(session_name(), session_id(), [
    'expires'=>0,
    'path'=>$PATH,
    'domain'=>$DOMAIN ?: '',
    'secure'=>$HTTPS,
    'httponly'=>true,
    'samesite'=>'Lax'
  ]);

  // ===== 5. Check if already has attendance today for same subject =====
  $today = date('Y-m-d');
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

  $attendance_inserted = false;
  if (!$exists) {
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

  session_write_close();

  echo json_encode([
    'success'=>true,
    'student_id'=>$student_id,
    'studentName'=>$student['fullname'],
    'attendance'=>[
      'inserted'=>$attendance_inserted,
      'alreadyMarked'=>!$attendance_inserted,
      'subject_id'=>$subject_id,
      'advisory_id'=>$advisory_id,
      'school_year_id'=>$school_year_id,
      'date'=>$today,
      'status'=>'Present'
    ]
  ]);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
  exit;
}
