<?php
session_start();
include("db.php");

if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

// Check POST data
if (!isset($_POST['attendance'], $_POST['subject_id'], $_POST['advisory_id'], $_POST['school_year_id'])) {
  $_SESSION['toast'] = "Missing form data.";
  $_SESSION['toast_type'] = "error";
  header("Location: ../teacher/mark_attendance.php");
  exit;
}

$attendance = $_POST['attendance'];
$subject_id = $_POST['subject_id'];
$advisory_id = $_POST['advisory_id'];
$school_year_id = $_POST['school_year_id'];
$today = date('Y-m-d');

// Update or insert attendance per student
foreach ($attendance as $student_id => $status) {
  // Skip if status is invalid
  if (!in_array($status, ['Present', 'Absent', 'Late'])) continue;

  // Check if already marked today
  $checkStmt = $conn->prepare("SELECT attendance_id FROM attendance_records 
    WHERE student_id = ? AND subject_id = ? AND advisory_id = ? AND school_year_id = ? AND DATE(timestamp) = ?");
  $checkStmt->bind_param("iiiis", $student_id, $subject_id, $advisory_id, $school_year_id, $today);
  $checkStmt->execute();
  $checkResult = $checkStmt->get_result();

  if ($checkResult->num_rows > 0) {
    // Update existing record
    $updateStmt = $conn->prepare("UPDATE attendance_records 
      SET status = ? WHERE student_id = ? AND subject_id = ? AND advisory_id = ? AND school_year_id = ? AND DATE(timestamp) = ?");
    $updateStmt->bind_param("siiiis", $status, $student_id, $subject_id, $advisory_id, $school_year_id, $today);
    $updateStmt->execute();
  } else {
    // Insert new record
    $insertStmt = $conn->prepare("INSERT INTO attendance_records 
      (student_id, subject_id, advisory_id, school_year_id, status) VALUES (?, ?, ?, ?, ?)");
    $insertStmt->bind_param("iiiis", $student_id, $subject_id, $advisory_id, $school_year_id, $status);
    $insertStmt->execute();
  }
}

$_SESSION['toast'] = "✅ Attendance saved successfully!";
$_SESSION['toast_type'] = "success";
header("Location: ../teacher/mark_attendance.php?success=1");
exit;
