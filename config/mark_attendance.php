<?php
// Mark attendance (Present/Late/Absent) then also log to behavior_logs as "attendance"
session_start();
header('Content-Type: application/json');

$status = $_POST['status'] ?? 'Present'; // default
$valid  = ['Present','Late','Absent'];
if (!in_array($status, $valid, true)) {
  echo json_encode(['success'=>false,'message'=>'Invalid status']); exit;
}

/* Determine who the student is:
 * - Teacher kiosk sends student_id via POST
 * - Student dashboard uses session
 */
$student_id = isset($_POST['student_id']) && ctype_digit($_POST['student_id'])
  ? (int)$_POST['student_id']
  : ($_SESSION['student_id'] ?? null);

// Context comes from teacher active_* or student regular session
$subject_id     = $_SESSION['active_subject_id']     ?? $_SESSION['subject_id']     ?? null;
$advisory_id    = $_SESSION['active_advisory_id']    ?? $_SESSION['advisory_id']    ?? null;
$school_year_id = $_SESSION['active_school_year_id'] ?? $_SESSION['school_year_id'] ?? null;

if (!$student_id || !$subject_id || !$advisory_id || !$school_year_id) {
  echo json_encode(['success'=>false,'message'=>'Missing context/student']); exit;
}

$conn = @new mysqli("localhost","root","","cms");
if ($conn->connect_error) {
  echo json_encode(['success'=>false,'message'=>'DB connection failed']); exit;
}
$conn->set_charset('utf8mb4');

// 1) INSERT to attendance_records only once per day per slot
$already = false;
$ok_att  = true;

$check = $conn->prepare("
  SELECT attendance_id
  FROM attendance_records
  WHERE student_id=? AND subject_id=? AND advisory_id=? AND school_year_id=?
    AND DATE(`timestamp`)=CURDATE()
  LIMIT 1
");
$check->bind_param("iiii",$student_id,$subject_id,$advisory_id,$school_year_id);
$check->execute();
$check->store_result();

if ($check->num_rows === 0) {
  $check->close();
  $ins = $conn->prepare("
    INSERT INTO attendance_records (student_id,subject_id,advisory_id,school_year_id,status)
    VALUES (?,?,?,?,?)
  ");
  $ins->bind_param("iiiis",$student_id,$subject_id,$advisory_id,$school_year_id,$status);
  $ok_att = $ins->execute();
  $ins->close();
} else {
  $already = true;
  $check->close();
}

// 2) Always log behavior as "attendance"
$ok_beh = true;
$beh = $conn->prepare("INSERT INTO behavior_logs (student_id, action_type) VALUES (?, 'attendance')");
if ($beh) {
  $beh->bind_param("i",$student_id);
  $ok_beh = $beh->execute();
  $beh->close();
} else {
  $ok_beh = false;
}

$conn->close();

if (!$ok_att || !$ok_beh) {
  echo json_encode(['success'=>false,'message'=>'Insert failed','already_marked'=>$already]); exit;
}
echo json_encode(['success'=>true,'already_marked'=>$already]);
