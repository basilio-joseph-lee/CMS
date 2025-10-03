<?php
// config/save_quiz_response.php
session_start();
header('Content-Type: application/json');
include("db.php");

if (!isset($_SESSION['student_id'])) {
  echo json_encode(['ok'=>false,'message'=>'Unauthorized']); exit;
}

$student_id = (int)$_SESSION['student_id'];
$session_id = (int)($_POST['session_id'] ?? 0);
$qid        = (int)($_POST['question_id'] ?? 0);
$chosen     = strtoupper(trim($_POST['chosen_opt'] ?? ''));

if (!$student_id || !$session_id || !$qid || !in_array($chosen, ['A','B','C','D'], true)) {
  echo json_encode(['ok'=>false,'message'=>'Invalid payload']); exit;
}

try {
  $conn = new mysqli('localhost','root','','cms');
  $conn->set_charset('utf8mb4');

  // 1) Load the question
  $stmt = $conn->prepare("
    SELECT question_id, question_no, correct_opt, time_limit_sec, status, published_at
      FROM kiosk_quiz_questions
     WHERE question_id=? AND session_id=? AND status='published'
     LIMIT 1
  ");
  $stmt->bind_param("ii", $qid, $session_id);
  $stmt->execute();
  $q = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$q) { echo json_encode(['ok'=>false,'message'=>'Question not found']); exit; }

  // 2) Prevent double answer
  $stmt = $conn->prepare("SELECT response_id FROM kiosk_quiz_responses WHERE question_id=? AND student_id=? LIMIT 1");
  $stmt->bind_param("ii", $qid, $student_id);
  $stmt->execute();
  if ($stmt->get_result()->fetch_assoc()) {
    echo json_encode(['ok'=>false,'message'=>'Already answered']); exit;
  }
  $stmt->close();

  // 3) Scoring
  $published_at = strtotime($q['published_at'] ?? 'now');
  $now_ms = (int)round(microtime(true) * 1000);
  $elapsed_ms = max(0, ($now_ms - $published_at*1000));
  $limit_ms   = max(10, (int)$q['time_limit_sec']) * 1000;

  $is_correct = (int)($chosen === $q['correct_opt']);
  $base = $is_correct ? 100 : 10;
  $speed_bonus = 0;
  if ($is_correct) {
    $remain_ms = max(0, $limit_ms - $elapsed_ms);
    $speed_bonus = (int)round(50.0 * ($remain_ms / $limit_ms));
  }
  $points = $base + $speed_bonus;

  // 4) Save response
  $stmt = $conn->prepare("
    INSERT INTO kiosk_quiz_responses
      (question_id, student_id, chosen_opt, is_correct, answered_at, points, time_ms)
    VALUES (?,?,?,?,NOW(),?,?)
  ");
  $stmt->bind_param("iisiii", $qid, $student_id, $chosen, $is_correct, $points, $elapsed_ms);
  $stmt->execute();
  $stmt->close();

  // 5) Is there a next question?
  $nextQ = null;
  $stmt = $conn->prepare("
    SELECT question_id, question_no
      FROM kiosk_quiz_questions
     WHERE session_id=? AND question_no > ?
       AND status='published'
     ORDER BY question_no ASC
     LIMIT 1
  ");
  $stmt->bind_param("ii", $session_id, $q['question_no']);
  $stmt->execute();
  $res = $stmt->get_result()->fetch_assoc();
  if ($res) $nextQ = $res['question_no'];
  $stmt->close();

  echo json_encode([
    'ok'       => true,
    'correct'  => $is_correct === 1,
    'points'   => $points,
    'next_no'  => $nextQ
  ]);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'message'=>'Save failed','error'=>$e->getMessage()]);
}
