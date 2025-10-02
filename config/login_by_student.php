<?php
// POST: student_id
header('Content-Type: application/json');
session_start();

$student_id = intval($_POST['student_id'] ?? 0);
if ($student_id <= 0) { echo json_encode(['success'=>false,'message'=>'missing id']); exit; }

$conn = @new mysqli('localhost','root','','cms');
if ($conn->connect_error) { echo json_encode(['success'=>false,'message'=>'db']); exit; }
$conn->set_charset('utf8mb4');

$stmt = $conn->prepare("SELECT student_id, fullname FROM students WHERE student_id=? LIMIT 1");
$stmt->bind_param('i', $student_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close(); $conn->close();

if (!$row) { echo json_encode(['success'=>false,'message'=>'not found']); exit; }

// set session like your old login did
$_SESSION['role']       = 'student';
$_SESSION['student_id'] = intval($row['student_id']);
$_SESSION['fullname']   = $row['fullname'];

echo json_encode(['success'=>true, 'student_id'=>$_SESSION['student_id'], 'name'=>$_SESSION['fullname']]);
