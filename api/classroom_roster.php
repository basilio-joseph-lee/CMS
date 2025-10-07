<?php
// /user/api/classroom_roster.php
// Input: GET student_id
// Output: JSON {
//   "class": {"advisory_id":1,"school_year_id":3,"subject_id":2,"class_name":"9 - Ruby","subject_name":"AP"},
//   "students":[{"id":12,"name":"Juan Dela Cruz"}, ...],
//   "child":{"id":12,"name":"Juan Dela Cruz"}
// }

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
include __DIR__ . '/../config/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Normalize mysqli variable name
if (!isset($conn) && isset($db)) $conn = $db;
if (!isset($conn)) { echo json_encode(['error'=>'DB connection not available']); exit; }
$conn->set_charset('utf8mb4');

$student_id = (int)($_GET['student_id'] ?? 0);
if ($student_id <= 0) { http_response_code(400); echo json_encode(['error'=>'Invalid student_id']); exit; }

// ------------ 1) Resolve child's info ------------
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

// ------------ 2) Resolve class context ------------
$ctx = [
  'advisory_id'    => null,
  'school_year_id' => null,
  'subject_id'     => null,
  'class_name'     => null,
  'subject_name'   => null,
];

// Prefer student_enrollments (latest)
try {
  $en = $conn->prepare("
    SELECT se.advisory_id, se.school_year_id, se.subject_id,
           ac.class_name, sj.subject_name
    FROM student_enrollments se
    LEFT JOIN advisory_classes ac ON ac.advisory_id = se.advisory_id
    LEFT JOIN subjects sj ON sj.subject_id = se.subject_id
    WHERE se.student_id = ?
    ORDER BY se.updated_at DESC, se.created_at DESC
    LIMIT 1
  ");
  $en->bind_param('i', $student_id);
  $en->execute();
  $res = $en->get_result();
  if ($row = $res->fetch_assoc()) {
    $ctx['advisory_id']    = (int)($row['advisory_id'] ?? 0) ?: null;
    $ctx['school_year_id'] = (int)($row['school_year_id'] ?? 0) ?: null;
    $ctx['subject_id']     = isset($row['subject_id']) ? (int)$row['subject_id'] : null;
    $ctx['class_name']     = $row['class_name'] ?? null;
    $ctx['subject_name']   = $row['subject_name'] ?? null;
  }
} catch (Throwable $e) { /* fallback below */ }

// Fallback: infer by students.section
if (!$ctx['advisory_id']) {
  try {
    $q2 = $conn->prepare("
      SELECT s.section AS class_name, ac.advisory_id
      FROM students s
      LEFT JOIN advisory_classes ac ON ac.class_name = s.section
      WHERE s.student_id = ?
      LIMIT 1
    ");
    $q2->bind_param('i', $student_id);
    $q2->execute();
    $r2 = $q2->get_result();
    if ($row = $r2->fetch_assoc()) {
      $ctx['class_name']   = $row['class_name'] ?? $ctx['class_name'];
      $ctx['advisory_id']  = $row['advisory_id'] ? (int)$row['advisory_id'] : $ctx['advisory_id'];
    }
  } catch (Throwable $e) {}
}

if (!$ctx['advisory_id']) {
  http_response_code(404);
  echo json_encode(['error'=>'Class context not found for this student']); exit;
}

// ------------ 3) Classmates list ------------
$students = [];
try {
  if ($ctx['school_year_id']) {
    $st = $conn->prepare("
      SELECT s.student_id AS id, s.fullname AS name
      FROM student_enrollments se
      INNER JOIN students s ON s.student_id = se.student_id
      WHERE se.advisory_id = ? AND se.school_year_id = ?
      GROUP BY s.student_id, s.fullname
      ORDER BY s.fullname ASC
    ");
    $st->bind_param('ii', $ctx['advisory_id'], $ctx['school_year_id']);
  } else {
    $st = $conn->prepare("
      SELECT s.student_id AS id, s.fullname AS name
      FROM student_enrollments se
      INNER JOIN students s ON s.student_id = se.student_id
      WHERE se.advisory_id = ?
      GROUP BY s.student_id, s.fullname
      ORDER BY s.fullname ASC
    ");
    $st->bind_param('i', $ctx['advisory_id']);
  }
  $st->execute();
  $rs = $st->get_result();
  while ($row = $rs->fetch_assoc()) {
    $students[] = ['id'=>(int)$row['id'], 'name'=>$row['name']];
  }
} catch (Throwable $e) {
  // Final fallback: section/class_name (if no enrollments)
  if ($ctx['class_name']) {
    $safe = $conn->real_escape_string($ctx['class_name']);
    $rs = $conn->query("
      SELECT s.student_id AS id, s.fullname AS name
      FROM students s
      WHERE s.section = '{$safe}'
      ORDER BY s.fullname ASC
    ");
    while ($row = $rs->fetch_assoc()) {
      $students[] = ['id'=>(int)$row['id'], 'name'=>$row['name']];
    }
  }
}

echo json_encode([
  'class'    => $ctx,
  'students' => $students,
  'child'    => $child,
], JSON_UNESCAPED_UNICODE);
