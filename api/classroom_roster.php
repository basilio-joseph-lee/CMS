<?php
// /api/classroom_roster.php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../config/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!isset($conn) && isset($db)) $conn = $db;
if (!isset($conn)) { echo json_encode(['error'=>'DB connection not available']); exit; }
$conn->set_charset('utf8mb4');

$student_id = (int)($_GET['student_id'] ?? 0);
if ($student_id <= 0) { http_response_code(400); echo json_encode(['error'=>'Invalid student_id']); exit; }

// 1. Get student's name
$child = ['id' => $student_id, 'name' => null];
try {
  $q = $conn->prepare("SELECT fullname FROM students WHERE student_id = ? LIMIT 1");
  $q->bind_param('i', $student_id);
  $q->execute();
  $r = $q->get_result();
  if ($row = $r->fetch_assoc()) {
    $child['name'] = $row['fullname'] ?? 'Student';
  }
} catch (Throwable $e) {}

// 2. Get latest enrollment directly (no dependence on joins)
$ctx = [
  'advisory_id' => null,
  'school_year_id' => null,
  'subject_id' => null,
  'class_name' => null,
  'subject_name' => null
];

try {
  $q = $conn->prepare("
    SELECT advisory_id, school_year_id, subject_id
    FROM student_enrollments
    WHERE student_id = ?
    ORDER BY enrollment_id DESC
    LIMIT 1
  ");
  $q->bind_param('i', $student_id);
  $q->execute();
  $res = $q->get_result();
  if ($row = $res->fetch_assoc()) {
    $ctx['advisory_id'] = (int)$row['advisory_id'];
    $ctx['school_year_id'] = (int)$row['school_year_id'];
    $ctx['subject_id'] = (int)$row['subject_id'];
  }
} catch (Throwable $e) {}

// Try to get advisory class and subject names if they exist
try {
  if ($ctx['advisory_id']) {
    $r = $conn->query("SELECT class_name FROM advisory_classes WHERE advisory_id={$ctx['advisory_id']} LIMIT 1");
    if ($row = $r->fetch_assoc()) $ctx['class_name'] = $row['class_name'];
  }
  if ($ctx['subject_id']) {
    $r = $conn->query("SELECT subject_name FROM subjects WHERE subject_id={$ctx['subject_id']} LIMIT 1");
    if ($row = $r->fetch_assoc()) $ctx['subject_name'] = $row['subject_name'];
  }
} catch (Throwable $e) {}

// If still no context, fallback to student's section
if (!$ctx['advisory_id'] && !$ctx['class_name']) {
  $r = $conn->query("SELECT section FROM students WHERE student_id=$student_id LIMIT 1");
  if ($row = $r->fetch_assoc()) $ctx['class_name'] = $row['section'];
}

// 3. Always attempt to build roster (no 404 anymore)
$students = [];
try {
  if ($ctx['advisory_id'] && $ctx['school_year_id']) {
    $st = $conn->prepare("
      SELECT s.student_id AS id, s.fullname AS name
      FROM student_enrollments se
      INNER JOIN students s ON s.student_id = se.student_id
      WHERE se.advisory_id = ? AND se.school_year_id = ?
      GROUP BY s.student_id
      ORDER BY s.fullname ASC
    ");
    $st->bind_param('ii', $ctx['advisory_id'], $ctx['school_year_id']);
    $st->execute();
    $rs = $st->get_result();
    while ($row = $rs->fetch_assoc()) $students[] = ['id'=>(int)$row['id'],'name'=>$row['name']];
  }
  // Fallback by advisory only
  if (empty($students) && $ctx['advisory_id']) {
    $st = $conn->prepare("
      SELECT s.student_id AS id, s.fullname AS name
      FROM student_enrollments se
      INNER JOIN students s ON s.student_id = se.student_id
      WHERE se.advisory_id = ?
      GROUP BY s.student_id
      ORDER BY s.fullname ASC
    ");
    $st->bind_param('i', $ctx['advisory_id']);
    $st->execute();
    $rs = $st->get_result();
    while ($row = $rs->fetch_assoc()) $students[] = ['id'=>(int)$row['id'],'name'=>$row['name']];
  }
  // Fallback by section
  if (empty($students) && $ctx['class_name']) {
    $safe = $conn->real_escape_string($ctx['class_name']);
    $rs = $conn->query("SELECT student_id AS id, fullname AS name FROM students WHERE section='{$safe}' ORDER BY fullname ASC");
    while ($row = $rs->fetch_assoc()) $students[] = ['id'=>(int)$row['id'],'name'=>$row['name']];
  }
} catch (Throwable $e) {}

echo json_encode([
  'class' => $ctx,
  'students' => $students,
  'child' => $child,
], JSON_UNESCAPED_UNICODE);
