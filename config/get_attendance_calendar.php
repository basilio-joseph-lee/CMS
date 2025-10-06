<?php
require __DIR__ . '/db.php';

$studentId = ip('student_id');
$month     = ip('month');
$year      = ip('year');

if (!$studentId || !$month || !$year) {
  echo json_encode(['status' => 'error', 'message' => 'Missing student_id/month/year']);
  exit;
}

$conditions = ['student_id = :sid', 'MONTH(`timestamp`) = :m', 'YEAR(`timestamp`) = :y'];
$params     = [':sid' => $studentId, ':m' => $month, ':y' => $year];

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
  GROUP BY DATE(`timestamp`)";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$byDay = [];
foreach ($rows as $r) {
  $day   = (int)date('j', strtotime($r['d']));
  $score = (int)$r['score'];
  $byDay[$day] = $score === 3 ? 'Present' : ($score === 2 ? 'Late' : ($score === 1 ? 'Absent' : 'No Record'));
}

$days = [];
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
for ($d = 1; $d <= $daysInMonth; $d++) {
  if (isset($byDay[$d])) {
    $status = $byDay[$d];
  } else {
    // 6 = Sat, 7 = Sun
    $dow = (int)date('N', strtotime(sprintf('%04d-%02d-%02d', $year, $month, $d)));
    $status = ($dow >= 6) ? 'No Class' : 'No Record';
  }
  $days[] = ['day' => $d, 'status' => $status];
}

echo json_encode(['status' => 'success', 'days' => $days]);
