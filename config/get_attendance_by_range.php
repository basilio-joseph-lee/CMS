<?php
require __DIR__ . '/db.php';

$studentId = ip('student_id');
$from      = sp('from'); // ISO
$to        = sp('to');   // ISO

if (!$studentId || !$from || !$to) {
  echo json_encode(['status' => 'error', 'message' => 'Missing student_id/from/to']);
  exit;
}

$conditions = ['student_id = :sid', 'DATE(`timestamp`) BETWEEN :f AND :t'];
$params     = [':sid' => $studentId, ':f' => substr($from,0,10), ':t' => substr($to,0,10)];

if ($sy = sp('school_year_id')) { $conditions[] = 'school_year_id = :sy'; $params[':sy'] = $sy; }
if ($sub = sp('subject_id'))     { $conditions[] = 'subject_id = :sub';     $params[':sub'] = $sub; }
if ($adv = sp('advisory_id'))    { $conditions[] = 'advisory_id = :adv';    $params[':adv'] = $adv; }

$sql = "
  SELECT DATE(`timestamp`) AS d,
         MAX(CASE status
             WHEN 'Present' THEN 3
             WHEN 'Late'    THEN 2
             WHEN 'Absent'  THEN 1
             ELSE 0 END) AS score
  FROM attendance_records
  WHERE " . implode(' AND ', $conditions) . "
  GROUP BY DATE(`timestamp`)
  ORDER BY d";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$map = [];
foreach ($rows as $r) { $map[$r['d']] = (int)$r['score']; }

$start = new DateTime(substr($from,0,10));
$end   = new DateTime(substr($to,0,10));
$end->setTime(0,0)->modify('+1 day');

$days = [];
$iter = new DatePeriod($start, new DateInterval('P1D'), $end);
foreach ($iter as $dt) {
  $key = $dt->format('Y-m-d');
  if (isset($map[$key])) {
    $s = $map[$key];
    $status = $s===3?'Present':($s===2?'Late':($s===1?'Absent':'No Record'));
  } else {
    $dow = (int)$dt->format('N');
    $status = ($dow >= 6) ? 'No Class' : 'No Record';
  }
  $days[] = ['date' => $key, 'status' => $status];
}

echo json_encode(['status' => 'success', 'days' => $days]);
