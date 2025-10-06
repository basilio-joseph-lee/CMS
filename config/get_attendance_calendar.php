<?php
// config/get_attendance_calendar.php
require_once __DIR__ . '/db_connect.php'; // <- change to your actual DB include
header('Content-Type: application/json');

$student_id     = intval($_POST['student_id'] ?? 0);
$month          = intval($_POST['month'] ?? 0);
$year           = intval($_POST['year'] ?? 0);
$subject_id     = isset($_POST['subject_id']) ? intval($_POST['subject_id']) : null;
$advisory_id    = isset($_POST['advisory_id']) ? intval($_POST['advisory_id']) : null;
$school_year_id = isset($_POST['school_year_id']) ? intval($_POST['school_year_id']) : null;

if (!$student_id || !$month || !$year) {
  echo json_encode(['status' => 'error', 'message' => 'Missing parameters.']); exit;
}

$start = sprintf('%04d-%02d-01 00:00:00', $year, $month);
$end   = date('Y-m-d H:i:s', strtotime("$start +1 month"));

$sql = "SELECT DATE(`timestamp`) as d, status
        FROM attendance_records
        WHERE student_id = ? AND `timestamp` >= ? AND `timestamp` < ?";
$params = [$student_id, $start, $end];

if ($subject_id)     { $sql .= " AND subject_id = ?";     $params[] = $subject_id; }
if ($advisory_id)    { $sql .= " AND advisory_id = ?";    $params[] = $advisory_id; }
if ($school_year_id) { $sql .= " AND school_year_id = ?"; $params[] = $school_year_id; }

$sql .= " ORDER BY `timestamp` DESC";

try {
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);

  // Use the latest record per day
  $byDay = [];
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $day = intval(date('j', strtotime($row['d'])));
    if (!isset($byDay[$day])) {
      $byDay[$day] = $row['status']; // 'Present'|'Absent'|'Late'
    }
  }

  $days = [];
  foreach ($byDay as $d => $st) $days[] = ['day' => $d, 'status' => $st];

  echo json_encode(['status' => 'success', 'days' => $days]);
} catch (Throwable $e) {
  echo json_encode(['status' => 'error', 'message' => 'DB error']);
}
