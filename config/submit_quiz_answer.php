<?php
// /CMS/user/config/submit_quiz_answer.php
// Insert answer keyed by NAME (no login). Prevents duplicate answers per (question_id, name).

header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
include("db.php");

$question_id = (int)($_POST['question_id'] ?? 0);
$chosen_opt  = strtoupper(trim($_POST['chosen_opt'] ?? ''));
$name        = trim((string)($_POST['name'] ?? ''));
$name        = mb_substr($name, 0, 120, 'UTF-8');

if ($question_id<=0 || !in_array($chosen_opt, ['A','B','C','D'], true) || $name==='') {
  echo json_encode(['success'=>false,'message'=>'Invalid payload']); exit;
}

try{

  $conn->set_charset('utf8mb4');

  // Fetch correct answer and simple points rule
  $q = $conn->prepare("SELECT correct_opt, COALESCE(time_limit_sec,30) AS tl FROM kiosk_quiz_questions WHERE question_id=? LIMIT 1");
  $q->bind_param('i', $question_id);
  $q->execute();
  $row = $q->get_result()->fetch_assoc();
  if(!$row){ echo json_encode(['success'=>false,'message'=>'Question not found']); exit; }

  $correct = strtoupper($row['correct_opt'] ?? 'A');
  $is_correct = (int)($correct === $chosen_opt);
  $points = $is_correct ? 150 : 10; // adjust if you like

  // Prevent duplicate for same name
  $chk = $conn->prepare("SELECT response_id FROM kiosk_quiz_responses WHERE question_id=? AND name=? LIMIT 1");
  $chk->bind_param('is', $question_id, $name);
  $chk->execute();
  if ($chk->get_result()->fetch_assoc()) {
    echo json_encode(['success'=>true,'duplicate'=>true,'correct'=>$is_correct===1,'points'=>$points]); exit;
  }

  $ins = $conn->prepare("
    INSERT INTO kiosk_quiz_responses (question_id, name, chosen_opt, is_correct, answered_at, points, time_ms)
    VALUES (?, ?, ?, ?, NOW(), ?, 0)
  ");
  $ins->bind_param('issii', $question_id, $name, $chosen_opt, $is_correct, $points);
  $ins->execute();

  echo json_encode(['success'=>true,'correct'=>$is_correct===1,'points'=>$points]);

} catch (Throwable $e){
  echo json_encode(['success'=>false,'message'=>'Save failed','error'=>$e->getMessage()]);
}
