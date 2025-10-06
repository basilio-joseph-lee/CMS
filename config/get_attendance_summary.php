<?php
require __DIR__ . '/db.php';

$studentId = ip('student_id');
if (!$studentId) {
  echo json_encode(['status' => 'error', 'message' => 'Missing student_id']);
  exit;
}

$conditions = ['student_id = :sid'];
$params     = [':sid' => $studentId];

if ($sy = sp('school_year_id')) { $conditions[] = 'school_year_id = :sy'; $params[':sy'] = $sy; }
if ($sub = sp('subject_id'))     { $conditions[] = 'subject_id = :sub';     $params[':sub'] = $sub; }
if ($adv = sp('advisory_id'))    { $conditions[] = 'advisory_id = :adv';    $params[':adv'] = $adv; }

$sql = "
  SELECT
    SUM(status='Present') AS present,
    SUM(status='Absent')  AS absent,
    SUM(status='Late')    AS late
  FROM attendance_records
  WHERE " . implode(' AND ', $conditions);

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$row = $stmt->fetch() ?: ['present' => 0, 'absent' => 0, 'late' => 0];

echo json_encode([
  'status'  => 'success',
  'present' => (int)$row['present'],
  'absent'  => (int)$row['absent'],
  'late'    => (int)$row['late'],
]);
