<?php
// /CMS/user/config/get_quiz_questions.php
header('Content-Type: application/json; charset=utf-8');
include("db.php");

$session_id = (int)($_GET['session_id'] ?? 0);
if ($session_id <= 0) { echo json_encode(['success'=>false,'error'=>'Missing session_id']); exit; }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {

  $conn->set_charset("utf8mb4");

  $stmt = $conn->prepare("
    SELECT session_id, status, COALESCE(title,'Quick Quiz') AS session_title
    FROM kiosk_quiz_sessions WHERE session_id=? LIMIT 1
  ");
  $stmt->bind_param('i', $session_id);
  $stmt->execute();
  $sess = $stmt->get_result()->fetch_assoc();
  if (!$sess) { echo json_encode(['success'=>false,'error'=>'Session not found']); exit; }

  $stmt = $conn->prepare("
    SELECT question_id, COALESCE(title, ?) AS title, question_text,
           opt_a, opt_b, opt_c, opt_d,
           COALESCE(time_limit_sec,30) AS time_limit_sec,
           COALESCE(order_no, question_no, question_id) AS ord,
           COALESCE(active_flag,0) AS active_flag
    FROM kiosk_quiz_questions
    WHERE session_id=? AND status='published'
    ORDER BY COALESCE(order_no, question_no, question_id) ASC, question_id ASC
  ");
  $stmt->bind_param('si', $sess['session_title'], $session_id);
  $stmt->execute();
  $rs = $stmt->get_result();

  $rows = [];
  while ($r = $rs->fetch_assoc()) {
    $rows[] = [
      'question_id' => (int)$r['question_id'],
      'title'       => $r['title'],
      'question'    => $r['question_text'],
      'options'     => ['A'=>$r['opt_a'],'B'=>$r['opt_b'],'C'=>$r['opt_c'],'D'=>$r['opt_d']],
      'time_limit'  => (int)$r['time_limit_sec'],
      'order_no'    => (int)$r['ord'],
      'is_active'   => ((int)$r['active_flag'] === 1)
    ];
  }

  echo json_encode([
    'success' => true,
    'session_status' => $sess['status'],
    'questions' => $rows,
    'total' => count($rows)
  ]);

} catch (Throwable $e) {
  echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
