<?php
// check_attendance.php — check if attendance already exists today
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';

$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$subject_id = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;
$advisory_id = isset($_GET['advisory_id']) ? (int)$_GET['advisory_id'] : 0;
$school_year_id = isset($_GET['school_year_id']) ? (int)$_GET['school_year_id'] : 0;

if($student_id && $subject_id && $advisory_id && $school_year_id){
    $stmt = $conn->prepare("SELECT 1 FROM attendance_records WHERE student_id=? AND subject_id=? AND advisory_id=? AND school_year_id=? AND DATE(`timestamp`) = CURDATE() LIMIT 1");
    $stmt->bind_param("iiii",$student_id,$subject_id,$advisory_id,$school_year_id);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    echo json_encode(['exists'=>$exists]);
}else{
    echo json_encode(['exists'=>false]);
}
