<?php
// config/get_round_results.php
session_start();
header('Content-Type: application/json');
include("db.php");


$subject_id = (int)($_SESSION['active_subject_id']  ?? $_SESSION['subject_id']     ?? 0);
$advisory_id= (int)($_SESSION['active_advisory_id'] ?? $_SESSION['advisory_id']    ?? 0);
$sy_id      = (int)($_SESSION['active_school_year_id'] ?? $_SESSION['school_year_id'] ?? 0);

$qid = (int)($_GET['question_id'] ?? 0);

if (!$subject_id || !$advisory_id || !$sy_id) {
  echo json_encode(['success'=>false,'message'=>'Missing class context']); exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
  $conn = new mysqli('localhost','root','','cms');
  $conn->set_charset('utf8mb4');

  // If no question_id provided, get latest CLOSED today for this slot
  if (!$qid) {
    $stmt = $conn->prepare("
      SELECT question_id
        FROM kiosk_quiz_questions
       WHERE subject_id=? AND advisory_id=? AND school_year_id=?
         AND status='closed' AND DATE(published_at)=CURDATE()
       ORDER BY question_id DESC
       LIMIT 1
    ");
    $stmt->bind_param("iii", $subject_id, $advisory_id, $sy_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) { echo json_encode(['success'=>true,'results'=>[]]); exit; }
    $qid = (int)$row['question_id'];
  }

  // Pull top 10
  $stmt = $conn->prepare("
    SELECT r.student_id, s.fullname, s.avatar_path,
           r.chosen_opt, r.is_correct, r.points, r.time_ms, r.answered_at
      FROM kiosk_quiz_responses r
      JOIN students s ON s.student_id = r.student_id
     WHERE r.question_id = ?
     ORDER BY r.points DESC, r.time_ms ASC
     LIMIT 10
  ");
  $stmt->bind_param("i", $qid);
  $stmt->execute();
  $rs = $stmt->get_result();
  $rows = [];
  while ($r = $rs->fetch_assoc()) {
    $p = trim((string)$r['avatar_path']);
    if ($p !== '') { $p = preg_replace('#^\./#','',$p); }
    if ($p === '') { $p = 'img/default-avatar.png'; }
    $rows[] = [
      'student_id'  => (int)$r['student_id'],
      'fullname'    => $r['fullname'],
      'avatar_path' => $p,
      'chosen_opt'  => $r['chosen_opt'],
      'is_correct'  => (int)$r['is_correct'],
      'points'      => (int)$r['points'],
      'time_ms'     => (int)$r['time_ms'],
      'answered_at' => $r['answered_at'],
    ];
  }
  $stmt->close();

  echo json_encode(['success'=>true,'results'=>$rows,'question_id'=>$qid]);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['success'=>false,'message'=>'Query failed','error'=>$e->getMessage()]);
}
