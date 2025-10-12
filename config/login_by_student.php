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

// -------------------------------
// 1) Load student basic profile
// -------------------------------
$stmt = $conn->prepare("SELECT student_id, fullname, gender, avatar_path, face_image_path, COALESCE(section,'') AS section FROM students WHERE student_id=? LIMIT 1");
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
$_SESSION['student_section']  = (string)$row['section'];

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

// ---------------------------------------------------------
// 2) AUTO ATTENDANCE (best-effort with safe fallbacks)
// ---------------------------------------------------------
date_default_timezone_set('Asia/Manila'); // ensure local time

$today       = date('Y-m-d');
$nowTime     = date('H:i:s');
$dayOfWeek   = (int)date('w'); // 0=Sun..6=Sat
$mysqlDOW    = $dayOfWeek + 1; // MySQL DAYOFWEEK(): 1=Sun..7=Sat

$school_year_id = 0;
$advisory_id    = 0;
$subject_id     = 0; // safe fallback when not resolvable
$status         = 'Present'; // default

// 2.a) Active School Year
$q = $conn->query("SELECT school_year_id FROM school_years WHERE status='active' ORDER BY school_year_id DESC LIMIT 1");
if ($r = $q->fetch_assoc()) {
  $school_year_id = (int)$r['school_year_id'];
}
$q->close();

// 2.b) Advisory by student's section (if available) within active SY
if ($school_year_id > 0 && $_SESSION['student_section'] !== '') {
  $stmt = $conn->prepare("
    SELECT advisory_id
    FROM advisory_classes
    WHERE school_year_id=? AND section=?
    ORDER BY advisory_id DESC
    LIMIT 1
  ");
  $stmt->bind_param("is", $school_year_id, $_SESSION['student_section']);
  $stmt->execute();
  $res = $stmt->get_result()->fetch_assoc();
  if ($res) { $advisory_id = (int)$res['advisory_id']; }
  $stmt->close();
}

// 2.c) Try to get current subject from schedule_timeblocks (optional)
if ($school_year_id > 0 && $advisory_id > 0) {
  // Find a block matching current DOW and time
  $stmt = $conn->prepare("
    SELECT subject_id, start_time, end_time
    FROM schedule_timeblocks
    WHERE school_year_id=? AND advisory_id=? AND day_of_week=? 
      AND TIME(?) BETWEEN start_time AND end_time
    ORDER BY start_time ASC
    LIMIT 1
  ");
  $stmt->bind_param("iiis", $school_year_id, $advisory_id, $mysqlDOW, $nowTime);
  $stmt->execute();
  $blk = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if ($blk) {
    $subject_id = (int)$blk['subject_id'];
    // Determine LATE if > start_time + 10 minutes (grace)
    $start = strtotime($blk['start_time']);
    if ((time() - $start) > 10*60) {
      $status = 'Late';
    }
  }
}

// 2.d) Prevent duplicates (one row per: student + date + subject)
// If subject_id=0 (no block matched), we still keep it unique per date with subject_id=0.
$alreadyExists = false;
$stmt = $conn->prepare("
  SELECT attendance_id 
  FROM attendance_records
  WHERE student_id=? 
    AND DATE(`timestamp`) = ?
    AND subject_id = ?
  LIMIT 1
");
$stmt->bind_param("isi", $student_id, $today, $subject_id);
$stmt->execute();
$exists = $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($exists) { $alreadyExists = true; }

// 2.e) Insert when not existing
$attendance_id = null;
if (!$alreadyExists) {
  $stmt = $conn->prepare("
    INSERT INTO attendance_records
      (student_id, subject_id, advisory_id, school_year_id, status, `timestamp`)
    VALUES
      (?, ?, ?, ?, ?, NOW())
  ");
  $stmt->bind_param("iiiss",
    $student_id,
    $subject_id,
    $advisory_id,
    $school_year_id,
    $status
  );
  $stmt->execute();
  $attendance_id = $stmt->insert_id;
  $stmt->close();
}

// ---------------------------------------------------------
// 3) Respond
// ---------------------------------------------------------
session_write_close();

echo json_encode([
  'success'        => true,
  'student_id'     => $_SESSION['student_id'],
  'studentName'    => $_SESSION['student_fullname'],
  // attendance info (for debugging/visibility)
  'attendance' => [
    'inserted'        => !$alreadyExists,
    'attendance_id'   => $attendance_id,
    'status'          => $status,
    'school_year_id'  => $school_year_id,
    'advisory_id'     => $advisory_id,
    'subject_id'      => $subject_id,
    'date'            => $today,
    'time'            => $nowTime
  ],
]);
exit;
