<?php
session_start();
include '../../config/teacher_guard.php';
include "../../config/db.php";

$subject_id = $_SESSION['subject_id'] ?? null;
$advisory_id = $_SESSION['advisory_id'] ?? null;
$school_year_id = $_SESSION['school_year_id'] ?? null;

if (!$subject_id || !$advisory_id || !$school_year_id) {
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}


if ($conn->connect_error) {
    die(json_encode(['error' => 'DB connection failed']));
}

$today = date('Y-m-d');

$query = $conn->prepare("SELECT student_id, status FROM attendance_records WHERE subject_id = ? AND advisory_id = ? AND school_year_id = ? AND DATE(timestamp) = ?");
$query->bind_param("iiis", $subject_id, $advisory_id, $school_year_id, $today);
$query->execute();
$result = $query->get_result();

$marked = [];
while ($row = $result->fetch_assoc()) {
    $marked[$row['student_id']] = $row['status']; // Either "Present" or "Absent"
}

echo json_encode(['marked' => $marked]);
