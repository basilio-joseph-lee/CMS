<?php
// Unified endpoint used by Student “I’m Back (IN)” and other quick tiles in student view.
// Mirrors your working log_behavior.php, but safe if called without student session.
session_start();
header('Content-Type: application/json');

$action_type = isset($_POST['action_type']) ? trim($_POST['action_type']) : '';
if ($action_type === '') {
  echo json_encode(['success'=>false,'message'=>'No action_type']); exit;
}

$valid_actions = [
  'attendance','restroom','snack','daily_note','participated',
  'water_break','borrow_book','return_material','lunch_break','not_well'
];
if (!in_array($action_type,$valid_actions,true)) {
  echo json_encode(['success'=>false,'message'=>'Invalid action_type']); exit;
}

// Student self-logging only for this endpoint
$student_id = $_SESSION['student_id'] ?? null;
if (!$student_id) {
  echo json_encode(['success'=>false,'message'=>'No student session']); exit;
}

// Optional status when action_type=attendance (student self mark)
$status = $_POST['status'] ?? 'Present';
$valid_status = ['Present','Late','Absent'];
if ($action_type === 'attendance' && !in_array($status,$valid_status,true)) {
  echo json_encode(['success'=>false,'message'=>'Invalid status']); exit;
}

include("db.php");
if ($conn->connect_error) {
  echo json_encode(['success'=>false,'message'=>'DB connection failed']); exit;
}
$conn->set_charset('utf8mb4');

// Always insert to behavior_logs
$ok_beh = true;
$stmt = $conn->prepare("INSERT INTO behavior_logs (student_id, action_type) VALUES (?, ?)");
$stmt->bind_param("is",$student_id,$action_type);
$ok_beh = $stmt->execute();
$stmt->close();

// If attendance, also ensure one-per-day row in attendance_records using student context
if ($action_type === 'attendance') {
  $subject_id     = $_SESSION['active_subject_id']     ?? $_SESSION['subject_id']     ?? null;
  $advisory_id    = $_SESSION['active_advisory_id']    ?? $_SESSION['advisory_id']    ?? null;
  $school_year_id = $_SESSION['active_school_year_id'] ?? $_SESSION['school_year_id'] ?? null;

  if ($subject_id && $advisory_id && $school_year_id) {
    $check = $conn->prepare("
      SELECT attendance_id
      FROM attendance_records
      WHERE student_id=? AND subject_id=? AND advisory_id=? AND school_year_id=?
        AND DATE(`timestamp`)=CURDATE()
    ");
    $check->bind_param("iiii",$student_id,$subject_id,$advisory_id,$school_year_id);
    $check->execute(); $check->store_result();

    if ($check->num_rows === 0) {
      $check->close();
      $ins = $conn->prepare("
        INSERT INTO attendance_records (student_id,subject_id,advisory_id,school_year_id,status)
        VALUES (?,?,?,?,?)
      ");
      $ins->bind_param("iiiis",$student_id,$subject_id,$advisory_id,$school_year_id,$status);
      $ins->execute();
      $ins->close();
    } else {
      $check->close();
    }
  }
}

$conn->close();
echo json_encode(['success'=>true]);
