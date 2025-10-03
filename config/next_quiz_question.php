<?php
// /CMS/config/next_quiz_question.php
session_start();
header('Content-Type: application/json');
include("db.php");

if (!isset($_SESSION['teacher_id'])) { echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit; }

$teacher_id     = (int)$_SESSION['teacher_id'];
$subject_id     = (int)($_SESSION['active_subject_id']     ?? $_SESSION['subject_id']     ?? 0);
$advisory_id    = (int)($_SESSION['active_advisory_id']    ?? $_SESSION['advisory_id']    ?? 0);
$school_year_id = (int)($_SESSION['active_school_year_id'] ?? $_SESSION['school_year_id'] ?? 0);
$session_id     = (int)($_GET['session_id'] ?? $_POST['session_id'] ?? 0);

if ($session_id <= 0) { echo json_encode(['success'=>false,'error'=>'Missing session_id']); exit; }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
  $conn = new mysqli('localhost','root','','cms'); $conn->set_charset('utf8mb4');

  // get current pointer
  $q = $conn->prepare("
    SELECT question_id FROM kiosk_quiz_active
    WHERE teacher_id=? AND subject_id=? AND advisory_id=? AND school_year_id=? AND session_id=?
    LIMIT 1
  ");
  $q->bind_param('iiiii', $teacher_id,$subject_id,$advisory_id,$school_year_id,$session_id);
  $q->execute();
  $row = $q->get_result()->fetch_assoc();
  $curr_qid = $row ? (int)$row['question_id'] : 0;

  // compute order of current
  $ord = 0;
  if ($curr_qid > 0) {
    $q = $conn->prepare("
      SELECT COALESCE(order_no, question_no, question_id) AS ord
      FROM kiosk_quiz_questions
      WHERE question_id=? AND session_id=? AND status='published' LIMIT 1
    ");
    $q->bind_param('ii', $curr_qid, $session_id); $q->execute();
    $r = $q->get_result()->fetch_assoc();
    $ord = $r ? (int)$r['ord'] : 0;
  }

  // next published by order
  $q = $conn->prepare("
    SELECT question_id
    FROM kiosk_quiz_questions
    WHERE session_id=? AND status='published'
      AND COALESCE(order_no, question_no, question_id) > ?
    ORDER BY COALESCE(order_no, question_no, question_id) ASC, question_id ASC
    LIMIT 1
  ");
  $q->bind_param('ii', $session_id, $ord); $q->execute();
  $n = $q->get_result()->fetch_assoc();

  if (!$n) { echo json_encode(['success'=>false,'error'=>'No next question']); exit; }
  $next_qid = (int)$n['question_id'];

  // update pointer
  $q = $conn->prepare("
    INSERT INTO kiosk_quiz_active
      (teacher_id, subject_id, advisory_id, school_year_id, session_id, question_id, updated_at)
    VALUES (?,?,?,?,?,?,NOW())
    ON DUPLICATE KEY UPDATE question_id=VALUES(question_id), updated_at=NOW()
  ");
  $q->bind_param('iiiiii', $teacher_id,$subject_id,$advisory_id,$school_year_id,$session_id,$next_qid);
  $q->execute();

  echo json_encode(['success'=>true,'question_id'=>$next_qid]);
} catch (Throwable $e) {
  echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
