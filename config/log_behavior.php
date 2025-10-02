<?php
session_start();
header('Content-Type: application/json');

$student_id     = $_SESSION['student_id']     ?? null;
$action_type    = isset($_POST['action_type']) ? trim($_POST['action_type']) : '';
$status         = $_POST['status']            ?? 'Present'; // used only for attendance
$subject_id     = $_SESSION['subject_id']     ?? null;
$advisory_id    = $_SESSION['advisory_id']    ?? null;
$school_year_id = $_SESSION['school_year_id'] ?? null;

if (!$student_id) {
  echo json_encode(['success' => false, 'message' => 'Missing student ID']);
  exit;
}

/** Allow all actions you support in UI + “participated” */
$valid_actions = [
  'attendance',
  'restroom',
  'snack',
  'daily_note',
  'participated',      // "I'm Back (IN)"
  'water_break',
  'borrow_book',
  'return_material',
  'lunch_break',
  'not_well',
];

$valid_status = ['Present', 'Absent', 'Late'];

if (!in_array($action_type, $valid_actions, true)) {
  echo json_encode(['success' => false, 'message' => 'Invalid action']);
  exit;
}

if ($action_type === 'attendance' && !in_array($status, $valid_status, true)) {
  echo json_encode(['success' => false, 'message' => 'Invalid attendance status']);
  exit;
}

$conn = new mysqli("localhost", "root", "", "cms");
if ($conn->connect_error) {
  echo json_encode(['success' => false, 'message' => 'DB connection failed']);
  exit;
}

/* Always insert into behavior_logs */
$stmt = $conn->prepare("INSERT INTO behavior_logs (student_id, action_type) VALUES (?, ?)");
if (!$stmt) {
  echo json_encode(['success' => false, 'message' => 'Prepare failed: behavior_logs']);
  $conn->close();
  exit;
}
$stmt->bind_param("is", $student_id, $action_type);
$success_behavior = $stmt->execute();
$stmt->close();

$already_marked = false;
$success_attendance = true;

/* For attendance, also write attendance_records (one per day/slot) */
if ($action_type === 'attendance') {
  if ($subject_id && $advisory_id && $school_year_id) {
    $check = $conn->prepare("
      SELECT attendance_id
      FROM attendance_records
      WHERE student_id = ? AND subject_id = ? AND advisory_id = ? AND school_year_id = ?
        AND DATE(`timestamp`) = CURDATE()
    ");
    if ($check) {
      $check->bind_param("iiii", $student_id, $subject_id, $advisory_id, $school_year_id);
      $check->execute();
      $check->store_result();

      if ($check->num_rows === 0) {
        $check->close();
        $insert = $conn->prepare("
          INSERT INTO attendance_records (student_id, subject_id, advisory_id, school_year_id, status)
          VALUES (?, ?, ?, ?, ?)
        ");
        if ($insert) {
          $insert->bind_param("iiiis", $student_id, $subject_id, $advisory_id, $school_year_id, $status);
          $success_attendance = $insert->execute();
          $insert->close();
        } else {
          $success_attendance = false;
        }
      } else {
        $already_marked = true;
        $check->close();
      }
    } else {
      $success_attendance = false;
    }
  } else {
    $success_attendance = false;
  }
}

$conn->close();

if (!$success_behavior) {
  echo json_encode(['success' => false, 'message' => 'Behavior insert failed']);
  exit;
}
if ($action_type === 'attendance' && !$success_attendance) {
  echo json_encode(['success' => false, 'message' => 'Attendance insert failed']);
  exit;
}

echo json_encode(['success' => true, 'already_marked' => $already_marked]);
