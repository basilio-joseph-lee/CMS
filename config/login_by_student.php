<?php
// /config/login_by_student.php
session_start(); // keep this, we will re-send cookie below

header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Manila'); // ensure “today” checks match PH time

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

try {
  /* ------------------------------------------------------------------------
     1) Validate student
  ------------------------------------------------------------------------ */
  $stmt = $conn->prepare("SELECT student_id, fullname, gender, avatar_path, face_image_path FROM students WHERE student_id=? LIMIT 1");
  $stmt->bind_param("i", $student_id);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$row) {
    echo json_encode(['success'=>false,'error'=>'Student not found']); exit;
  }

  /* ------------------------------------------------------------------------
     2) Resolve context (REQUIRED): school_year_id, advisory_id, subject_id
        - Prefer ACTIVE school year; if none, fallback to most recent
        - Pick any subject for that advisory & school year
  ------------------------------------------------------------------------ */
  $school_year_id = 0;
  $advisory_id    = 0;
  $subject_id     = 0;

  // Active or latest enrollment
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
    // No enrollment → we cannot safely insert attendance tied to FKs
    echo json_encode([
      'success'=>false,
      'error'=>'No enrollment found for this student (cannot mark attendance).'
    ]);
    exit;
  }

  $advisory_id    = (int)$en['advisory_id'];
  $school_year_id = (int)$en['school_year_id'];

  // Any subject under that advisory & school year
  $qs = "
    SELECT subject_id
    FROM subjects
    WHERE advisory_id = ? AND school_year_id = ?
    ORDER BY subject_id DESC
    LIMIT 1
  ";
  $stmt = $conn->prepare($qs);
  $stmt->bind_param("ii", $advisory_id, $school_year_id);
  $stmt->execute();
  $sub = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$sub) {
    echo json_encode([
      'success'=>false,
      'error'=>'No subject found for the student’s advisory & school year (cannot mark attendance).'
    ]);
    exit;
  }

  $subject_id = (int)$sub['subject_id'];

  /* ------------------------------------------------------------------------
     3) Login session
  ------------------------------------------------------------------------ */
  session_regenerate_id(true);

  // Clear other roles
  unset($_SESSION['teacher_id'], $_SESSION['teacher_fullname'], $_SESSION['teacher_name'],
        $_SESSION['admin_id'], $_SESSION['admin_fullname']);
  $_SESSION['role'] = 'STUDENT';

  // Set student identity (do NOT set subject/advisory here for UI flow)
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

  /* ------------------------------------------------------------------------
     4) Attendance write-on-login (one mark per day per student)
        - Duplicate guard: student_id + DATE(timestamp)
  ------------------------------------------------------------------------ */
  $today = date('Y-m-d');

  $chk = $conn->prepare("
    SELECT COUNT(*) AS cnt
    FROM attendance_records
    WHERE student_id = ?
      AND DATE(`timestamp`) = ?
    LIMIT 1
  ");
  $chk->bind_param("is", $student_id, $today);
  $chk->execute();
  $already = (int)$chk->get_result()->fetch_assoc()['cnt'];
  $chk->close();

  $attendance_inserted = false;
  if ($already === 0) {
    $ins = $conn->prepare("
      INSERT INTO attendance_records
        (student_id, subject_id, advisory_id, school_year_id, status, `timestamp`)
      VALUES (?, ?, ?, ?, 'Present', NOW())
    ");
    $ins->bind_param("iiii", $student_id, $subject_id, $advisory_id, $school_year_id);
    $ins->execute();
    $ins->close();
    $attendance_inserted = true;
  }

  session_write_close();
  echo json_encode([
    'success'              => true,
    'student_id'           => $_SESSION['student_id'],
    'studentName'          => $_SESSION['student_fullname'],
    'attendance'           => [
        'inserted'         => $attendance_inserted,
        'alreadyMarked'    => !$attendance_inserted,
        'date'             => $today,
        'school_year_id'   => $school_year_id,
        'advisory_id'      => $advisory_id,
        'subject_id'       => $subject_id,
        'status'           => 'Present'
    ],
  ]);
  exit;

} catch (Throwable $e) {
  // Helpful error for debugging on prod too
  http_response_code(500);
  echo json_encode([
    'success'=>false,
    'error'=>'DB error: '.$e->getMessage()
  ]);
  exit;
}
