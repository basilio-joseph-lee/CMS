<?php
// /config/login_by_student.php
session_start(); // keep this, we will re-send cookie below

header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Manila');  // ensure local time comparisons

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

/* ---------- Load student ---------- */
$stmt = $conn->prepare("SELECT student_id, fullname, gender, avatar_path, face_image_path FROM students WHERE student_id=? LIMIT 1");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) { echo json_encode(['success'=>false,'error'=>'Student not found']); exit; }

/* ---------- Session setup ---------- */
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

/* =========================================================================
   Auto-attendance marking (today’s schedule)
   - Looks up active school year
   - Finds the student’s enrollments
   - Finds today’s timeblock(s) for those enrollments
   - Determines Present/Late/Absent based on now vs start/end (+15m rule)
   - Inserts once per (student, subject, advisory, day)
   - If a record already exists, only upgrades status (Absent→Late→Present)
   ======================================================================= */

function active_school_year_id(mysqli $conn): int {
  // Prefer is_active=1; fallback to the latest row
  $q = $conn->query("SELECT school_year_id FROM school_years WHERE is_active=1 ORDER BY school_year_id DESC LIMIT 1");
  if ($q && ($r = $q->fetch_assoc())) return (int)$r['school_year_id'];
  $q = $conn->query("SELECT school_year_id FROM school_years ORDER BY school_year_id DESC LIMIT 1");
  if ($q && ($r = $q->fetch_assoc())) return (int)$r['school_year_id'];
  return 0;
}

function status_rank(string $s): int {
  // Order of "goodness" for upgrade logic
  // lower rank means worse; higher rank overwrites lower
  switch (strtolower($s)) {
    case 'present': return 3;
    case 'late':    return 2;
    case 'absent':  return 1;
    default:        return 0;
  }
}

$ay_id = active_school_year_id($conn);
$nowTs = time();
$today = date('Y-m-d', $nowTs);
$timeNow = date('H:i:s', $nowTs);
$dowN  = (int)date('N', $nowTs); // 1=Mon..7=Sun
$dowW  = (int)date('w', $nowTs); // 0=Sun..6=Sat

$attendance_actions = []; // collect what we did for transparency

if ($ay_id) {
  // Enrollments for this student in the active year
  $en = $conn->prepare("
    SELECT advisory_id, subject_id
    FROM student_enrollments
    WHERE student_id=? AND school_year_id=?");
  $en->bind_param("ii", $student_id, $ay_id);
  $en->execute();
  $en_res = $en->get_result();
  $en->close();

  while ($e = $en_res->fetch_assoc()) {
    $advisory_id = (int)$e['advisory_id'];
    $subject_id  = (int)$e['subject_id'];

    // Find an active timeblock for today
    // We support either convention for day_of_week: N(1-7) or w(0-6)
    $tb = $conn->prepare("
      SELECT timeblock_id, start_time, end_time, day_of_week, room
      FROM schedule_timeblocks
      WHERE school_year_id=? AND advisory_id=? AND subject_id=? AND active_flag=1
        AND (day_of_week IN (?, ?) )
      ORDER BY timeblock_id ASC
      LIMIT 1
    ");
    $tb->bind_param("iiiiii", $ay_id, $advisory_id, $subject_id, $dowN, $dowW);
    $tb->execute();
    $tb_res = $tb->get_result();
    $tb->close();

    if (!$tb_res || $tb_res->num_rows === 0) {
      $attendance_actions[] = [
        'advisory_id' => $advisory_id,
        'subject_id'  => $subject_id,
        'action'      => 'no_timeblock_today'
      ];
      continue;
    }

    $block = $tb_res->fetch_assoc();
    $start = strtotime($today . ' ' . $block['start_time']);
    $end   = strtotime($today . ' ' . $block['end_time']);
    if (!$start || !$end) {
      $attendance_actions[] = [
        'advisory_id' => $advisory_id,
        'subject_id'  => $subject_id,
        'action'      => 'invalid_timeblock_time'
      ];
      continue;
    }

    // Decide status
    $statusWanted = null;
    if ($nowTs < $start) {
      // Not started yet → don’t mark attendance; just note
      $attendance_actions[] = [
        'advisory_id' => $advisory_id,
        'subject_id'  => $subject_id,
        'action'      => 'class_not_started_yet',
        'window'      => [$block['start_time'], $block['end_time']]
      ];
      continue;
    } elseif ($nowTs >= $start && $nowTs <= $end) {
      // Class ongoing
      $lateThreshold = $start + (15 * 60); // +15 minutes
      $statusWanted = ($nowTs <= $lateThreshold) ? 'Present' : 'Late';
    } else { // $nowTs > $end
      $statusWanted = 'Absent';
    }

    // Check if we already have an attendance row for this (today)
    $chk = $conn->prepare("
      SELECT attendance_id, status
      FROM attendance_records
      WHERE student_id=? AND subject_id=? AND advisory_id=? AND school_year_id=?
        AND DATE(`timestamp`) = ?
      LIMIT 1
    ");
    $chk->bind_param("iiiis", $student_id, $subject_id, $advisory_id, $ay_id, $today);
    $chk->execute();
    $existing = $chk->get_result()->fetch_assoc();
    $chk->close();

    if ($existing) {
      // Only upgrade if new status is better (Present>Late>Absent)
      $curRank = status_rank((string)$existing['status']);
      $newRank = status_rank($statusWanted);
      if ($newRank > $curRank) {
        $up = $conn->prepare("UPDATE attendance_records SET status=?, `timestamp`=NOW() WHERE attendance_id=?");
        $up->bind_param("si", $statusWanted, $existing['attendance_id']);
        $up->execute();
        $up->close();
        $attendance_actions[] = [
          'advisory_id' => $advisory_id,
          'subject_id'  => $subject_id,
          'action'      => 'updated',
          'from'        => $existing['status'],
          'to'          => $statusWanted
        ];
      } else {
        $attendance_actions[] = [
          'advisory_id' => $advisory_id,
          'subject_id'  => $subject_id,
          'action'      => 'kept',
          'status'      => $existing['status']
        ];
      }
    } else {
      // Insert new row
      $ins = $conn->prepare("
        INSERT INTO attendance_records (student_id, subject_id, advisory_id, school_year_id, status, `timestamp`)
        VALUES (?, ?, ?, ?, ?, NOW())
      ");
      $ins->bind_param("iiiis", $student_id, $subject_id, $advisory_id, $ay_id, $statusWanted);
      $ins->execute();
      $ins->close();

      $attendance_actions[] = [
        'advisory_id' => $advisory_id,
        'subject_id'  => $subject_id,
        'action'      => 'inserted',
        'status'      => $statusWanted
      ];
    }
  }
}

/* ---------- Done ---------- */
session_write_close();
echo json_encode([
  'success'     => true,
  'student_id'  => $_SESSION['student_id'],
  'studentName' => $_SESSION['student_fullname'],
  // Extra info for debugging/UX; safe to ignore on client
  'attendance'  => [
    'school_year_id' => $ay_id,
    'now_time'       => $timeNow,
    'actions'        => $attendance_actions
  ]
]);
exit;
