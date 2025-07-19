<?php
session_start();
$conn = new mysqli("localhost", "root", "", "cms");
if ($conn->connect_error) die("DB connection failed.");

$student_id = $_SESSION['student_id'] ?? null;
$subject_id = $_SESSION['active_subject_id'] ?? null;
$advisory_id = $_SESSION['active_advisory_id'] ?? null;
$school_year_id = $_SESSION['active_school_year_id'] ?? null;

if (!$student_id || !$subject_id || !$advisory_id || !$school_year_id) {
    header("Location: ../../user/dashboard.php?attended=0");
    exit;
}

$today = date('Y-m-d');
$checkStmt = $conn->prepare("
    SELECT * FROM attendance_records 
    WHERE student_id = ? AND subject_id = ? AND advisory_id = ? AND school_year_id = ? 
    AND DATE(timestamp) = ?
");
$checkStmt->bind_param("iiiis", $student_id, $subject_id, $advisory_id, $school_year_id, $today);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();

if ($checkResult->num_rows === 0) {
    $status = 'Present';
    $insertStmt = $conn->prepare("INSERT INTO attendance_records (student_id, subject_id, advisory_id, school_year_id, status) VALUES (?, ?, ?, ?, ?)");
    $insertStmt->bind_param("iiiis", $student_id, $subject_id, $advisory_id, $school_year_id, $status);
    $insertStmt->execute();
    header("Location: ../user/dashboard.php?attended=1");
} else {
    header("Location: ../user/dashboard.php?attended=already");
}
exit;
