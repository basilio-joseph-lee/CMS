<?php
// Teacher kiosk ACTIONS (restroom, snack, etc.) OR student self-logging.
// Always inserts into behavior_logs.
session_start();
header('Content-Type: application/json');


$action_type = isset($_POST['action_type']) ? trim($_POST['action_type']) : '';
$valid = [
  'restroom','snack','daily_note','participated',
  'water_break','borrow_book','return_material',
  'lunch_break','not_well'
];

if (!in_array($action_type, $valid, true)) {
  echo json_encode(['success'=>false,'message'=>'Invalid action_type']); exit;
}

// If teacher kiosk: student_id is posted. If student self log: pull from session.
$student_id = null;
if (isset($_POST['student_id']) && ctype_digit($_POST['student_id'])) {
  $student_id = (int)$_POST['student_id'];
} else {
  $student_id = $_SESSION['student_id'] ?? null;
}

if (!$student_id) {
  echo json_encode(['success'=>false,'message'=>'Missing student_id']); exit;
}

include("db.php");
if ($conn->connect_error) {
  echo json_encode(['success'=>false,'message'=>'DB connection failed']); exit;
}
$conn->set_charset('utf8mb4');

$stmt = $conn->prepare("INSERT INTO behavior_logs (student_id, action_type) VALUES (?, ?)");
if (!$stmt) { echo json_encode(['success'=>false,'message'=>'Prepare failed']); $conn->close(); exit; }
$stmt->bind_param("is",$student_id,$action_type);
$ok = $stmt->execute();
$stmt->close();
$conn->close();

echo json_encode(['success'=>$ok]);
