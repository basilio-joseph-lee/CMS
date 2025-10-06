<?php
// config/get_attendance_summary.php
require_once __DIR__ . '/db_connect.php'; // <- change to your actual DB include
header('Content-Type: application/json');

$student_id     = intval($_POST['student_id'] ?? 0);
$subject_id     = isset($_POST['subject_id']) ? intval($_POST['subject_id']) : null;
$advisory_id    = isset($_POST['advisory_id']) ? intval($_POST['advisory_id']) : null;
$school_year_id = isset($_POST['school_year_id']) ? intval($_POST['school_year_id']) : null;

if (!$student_id) { echo json_encode(['status'=>'error','message'=>'Missing student_id']); exit; }

$sql = "SELECT status, COUNT(*) c FROM attendance_records WHERE student_id = ?";
$params = [$student_id];
if ($subject_id)     { $sql .= " AND subject_id = ?";     $params[] = $subject_id; }
if ($advisory_id)    { $sql .= " AND advisory_id = ?";    $params[] = $advisory_id; }
if ($school_year_id) { $sql .= " AND school_year_id = ?"; $params[] = $school_year_id; }
$sql .= " GROUP BY status";

$present = 0; $absent = 0; $late = 0;

try {
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $st = strtolower($row['status']);
    $cnt = intval($row['c']);
    if ($st === 'present') $present = $cnt;
    elseif ($st === 'absent') $absent = $cnt;
    elseif ($st === 'late') $late = $cnt;
  }
  echo json_encode(['status'=>'success', 'present'=>$present, 'absent'=>$absent, 'late'=>$late]);
} catch (Throwable $e) {
  echo json_encode(['status' => 'error', 'message' => 'DB error']);
}
