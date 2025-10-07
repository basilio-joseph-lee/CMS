<?php
// /user/api/classroom_snapshot.php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../../config/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!isset($conn) && isset($db)) $conn = $db;
if (!isset($conn)) { echo json_encode(['error'=>'DB connection not available']); exit; }
$conn->set_charset('utf8mb4');

$student_id = (int)($_GET['student_id'] ?? 0);
if ($student_id <= 0) { http_response_code(400); echo json_encode(['error'=>'Invalid student_id']); exit; }

$ctx = [
  'advisory_id'    => null,
  'school_year_id' => null,
  'subject_id'     => null,
  'class_name'     => null,
  'subject_name'   => null,
];

// Try enrollment first
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

// Fallback: infer from students.section
if (!$ctx['advisory_id']) {
  try {
    $q = $conn->prepare("
      SELECT s.section AS class_name, ac.advisory_id
      FROM students s
      LEFT JOIN advisory_classes ac ON ac.class_name = s.section
      WHERE s.student_id = ?
      LIMIT 1
    ");
    $q->bind_param('i', $student_id);
    $q->execute();
    $r = $q->get_result();
    if ($row = $r->fetch_assoc()) {
      $ctx['class_name']   = $row['class_name'] ?? $ctx['class_name'];
      $ctx['advisory_id']  = $row['advisory_id'] ? (int)$row['advisory_id'] : $ctx['advisory_id'];
    }
  } catch (Throwable $e) {}
}

if (!$ctx['advisory_id']) { http_response_code(404); echo json_encode(['error'=>'Class context not found']); exit; }

// Pull classmates
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

$actions = [];
if (!empty($students)) {
  $ids = array_map(fn($s)=>(int)$s['id'], $students);
  $idList = implode(',', array_map('intval', $ids));
  if ($idList !== '') {
    $sql = "
      SELECT bl.student_id, bl.action_type, bl.timestamp
      FROM behavior_logs bl
      INNER JOIN (
        SELECT student_id, MAX(timestamp) AS latest_ts
        FROM behavior_logs
        WHERE student_id IN ($idList)
        GROUP BY student_id
      ) x ON x.student_id = bl.student_id AND x.latest_ts = bl.timestamp
    ";
    $rs = $conn->query($sql);
    while ($row = $rs->fetch_assoc()) {
      $sid = (int)$row['student_id'];
      $actions[(string)$sid] = [
        'action'    => $row['action_type'],
        'timestamp' => $row['timestamp'],
      ];
    }
  }
}

echo json_encode([
  'class'    => $ctx,
  'students' => $students,
  'actions'  => (object)$actions,
], JSON_UNESCAPED_UNICODE);
