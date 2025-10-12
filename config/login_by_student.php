<?php
// /config/login_by_student.php
session_start(); // keep this, we will re-send cookie below

header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Manila'); // PH time for per-day checks

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

// 1) Fetch student
$stmt = $conn->prepare("SELECT student_id, fullname, gender, avatar_path, face_image_path FROM students WHERE student_id=? LIMIT 1");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) { echo json_encode(['success'=>false,'error'=>'Student not found']); exit; }

// 2) Reset session to a fresh id
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

// 3) Re-send cookie with same scope as face_login.php
$HTTPS  = is_https();
$DOMAIN = cookie_domain_for_host($_SERVER['HTTP_HOST'] ?? '');
$PATH   = base_path();
setcookie(session_name(), session_id(), [
  'expires'  => 0,
  'path'     => $PATH,        // must match face_login.php
  'domain'   => $DOMAIN ?: '',
  'secure'   => $HTTPS,
  'httponly' => true,
  'samesite' => 'Lax',
]);

/* 4) Resolve class context:
      - advisory_id & school_year_id from latest/active enrollment
      - subject_id from LIVE schedule_timeblocks first, then fallback to any subject in that advisory/year
*/
$advisory_id    = 0;
$school_year_id = 0;
$subject_id     = 0;

// enrollment (prefer active SY)
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

  // Try LIVE timeblock (day_of_week + time within start/end + active_flag=1)
  $dow = (int)date('N');      // 1..7 (Mon..Sun)
  $now = date('H:i:s');

  $stmt = $conn->prepare("
    SELECT st.subject_id
    FROM schedule_timeblocks st
    JOIN subjects s ON s.subject_id = st.subject_id
    WHERE s.advisory_id = ?
      AND s.school_year_id = ?
      AND st.active_flag = 1
      AND st.day_of_week = ?
      AND ? BETWEEN st.start_time AND st.end_time
    ORDER BY st.start_time ASC
    LIMIT 1
  ");
  $stmt->bind_param("iiis", $advisory_id, $school_year_id, $dow, $now);
  $stmt->execute();
  $live = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if ($live) {
    $subject_id = (int)$live['subject_id'];
  } else {
    // Fallback: any subject for this advisory & school year (keeps attendance working off-hours)
    $stmt = $conn->prepare("
      SELECT subject_id
      FROM subjects
      WHERE advisory_id = ? AND school_year_id = ?
      ORDER BY subject_id ASC
      LIMIT 1
    ");
    $stmt->bind_param("ii", $advisory_id, $school_year_id);
    $stmt->execute();
    $sub = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($sub) $subject_id = (int)$sub['subject_id'];
  }
}

// 5) Attendance: insert exactly ONE "Present" per student per calendar day (atomic)
$today = date('Y-m-d');
$ins = $conn->prepare("
  INSERT INTO attendance_records (student_id, subject_id, advisory_id, school_year_id, status, `timestamp`)
  SELECT ?, ?, ?, ?, 'Present', NOW()
  FROM DUAL
  WHERE NOT EXISTS (
    SELECT 1 FROM attendance_records
    WHERE student_id = ? AND DATE(`timestamp`) = ?
  )
");
$ins->bind_param("iiiiis",
  $student_id, $subject_id, $advisory_id, $school_year_id,
  $student_id, $today
);
$ins->execute();
$inserted = ($conn->affected_rows === 1);
$ins->close();

session_write_close();

// 6) Response
echo json_encode([
  'success'     => true,
  'student_id'  => $_SESSION['student_id'],
  'studentName' => $_SESSION['student_fullname'],
  'attendance'  => [
    'inserted'        => $inserted,
    'alreadyMarked'   => !$inserted,
    'date'            => $today,
    'status'          => 'Present',
    'advisory_id'     => $advisory_id,
    'school_year_id'  => $school_year_id,
    'subject_id'      => $subject_id
  ]
]);
exit;
