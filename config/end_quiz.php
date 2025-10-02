<?php
// /CMS/user/config/end_quiz.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['teacher_id'])) { echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit; }

$teacher_id     = (int)$_SESSION['teacher_id'];
$subject_id     = (int)($_SESSION['active_subject_id']     ?? $_SESSION['subject_id']     ?? 0);
$advisory_id    = (int)($_SESSION['active_advisory_id']    ?? $_SESSION['advisory_id']    ?? 0);
$school_year_id = (int)($_SESSION['active_school_year_id'] ?? $_SESSION['school_year_id'] ?? 0);
$session_id     = (int)($_GET['session_id'] ?? $_POST['session_id'] ?? 0);

if ($session_id <= 0) { echo json_encode(['success'=>false,'error'=>'Missing session_id']); exit; }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
  $db = new mysqli('localhost','root','','cms'); $db->set_charset('utf8mb4');

  $q = $db->prepare("UPDATE kiosk_quiz_sessions SET status='ended', ended_at=NOW() WHERE session_id=?");
  $q->bind_param('i', $session_id); $q->execute();

  echo json_encode(['success'=>true]);

} catch (Throwable $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
