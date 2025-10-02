<?php
// /CMS/user/config/get_active_quiz.php
// Name-based: serve FIRST published question that THIS name hasn't answered yet.
// IMPORTANT: Only deliver a question when the session is **ongoing**.
// Otherwise (draft/active/ended) return quiz=null so clients stay in the lobby.

header('Content-Type: application/json; charset=utf-8');

$session_id = (int)($_GET['session_id'] ?? 0);
$player     = trim((string)($_GET['player'] ?? ''));
$player     = mb_substr($player, 0, 120, 'UTF-8');

if ($session_id <= 0) { echo json_encode(['success'=>false,'error'=>'Missing session_id']); exit; }
if ($player === '')   { echo json_encode(['success'=>false,'error'=>'Missing player name']); exit; }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
  $conn = new mysqli("localhost","root","","cms");
  $conn->set_charset('utf8mb4');

  // 1) Load session FIRST (bug fix: don't touch $sess before this)
  $q = $conn->prepare("
    SELECT session_id, status, COALESCE(title,'Quick Quiz') AS session_title,
           teacher_id, subject_id, advisory_id, school_year_id
    FROM kiosk_quiz_sessions
    WHERE session_id=? LIMIT 1
  ");
  $q->bind_param('i', $session_id);
  $q->execute();
  $sess = $q->get_result()->fetch_assoc();
  if (!$sess) { echo json_encode(['success'=>false,'error'=>'Session not found']); exit; }

  $session_status = strtolower((string)$sess['status']);

  // 2) Only move players out of the lobby when the teacher has started (status=ongoing)
  if ($session_status !== 'ongoing') {
    echo json_encode(['success'=>true,'session_status'=>$sess['status'],'quiz'=>null]);
    exit;
  }

  $session_title = $sess['session_title'];

  // 3) Totals
  $qTot = $conn->prepare("SELECT COUNT(*) AS total FROM kiosk_quiz_questions WHERE session_id=? AND status='published'");
  $qTot->bind_param('i', $session_id);
  $qTot->execute();
  $total = (int)$qTot->get_result()->fetch_assoc()['total'];
  if ($total === 0) { echo json_encode(['success'=>true,'session_status'=>'ongoing','quiz'=>null]); exit; }

  // 4) How many already answered by this name
  $qAns = $conn->prepare("
    SELECT COUNT(*) AS answered
    FROM kiosk_quiz_responses r
    JOIN kiosk_quiz_questions q ON q.question_id=r.question_id
    WHERE r.name=? AND q.session_id=? AND q.status='published'
  ");
  $qAns->bind_param('si', $player, $session_id);
  $qAns->execute();
  $answered = (int)$qAns->get_result()->fetch_assoc()['answered'];

  // 5) Try current pointer first (if still unanswered by this name)
  $active_row = null;
  $qa = $conn->prepare("
    SELECT question_id
    FROM kiosk_quiz_active
    WHERE teacher_id=? AND subject_id=? AND advisory_id=? AND school_year_id=? AND session_id=?
    LIMIT 1
  ");
  $qa->bind_param('iiiii',
    $sess['teacher_id'], $sess['subject_id'], $sess['advisory_id'], $sess['school_year_id'], $session_id
  );
  $qa->execute();
  if ($r = $qa->get_result()->fetch_assoc()) {
    $ptr_qid = (int)$r['question_id'];
    $qp = $conn->prepare("
      SELECT q.question_id
      FROM kiosk_quiz_questions q
      LEFT JOIN kiosk_quiz_responses r ON r.question_id=q.question_id AND r.name=?
      WHERE q.question_id=? AND q.session_id=? AND q.status='published' AND r.response_id IS NULL
      LIMIT 1
    ");
    $qp->bind_param('sii', $player, $ptr_qid, $session_id);
    $qp->execute();
    $active_row = $qp->get_result()->fetch_assoc();
  }

  // 6) If none, first unanswered by name
  if (!$active_row) {
    $qNext = $conn->prepare("
      SELECT q.question_id
      FROM kiosk_quiz_questions q
      LEFT JOIN kiosk_quiz_responses r ON r.question_id=q.question_id AND r.name=?
      WHERE q.session_id=? AND q.status='published' AND r.response_id IS NULL
      ORDER BY COALESCE(q.order_no, q.question_no, q.question_id) ASC, q.question_id ASC
      LIMIT 1
    ");
    $qNext->bind_param('si', $player, $session_id);
    $qNext->execute();
    $active_row = $qNext->get_result()->fetch_assoc();
  }

  if (!$active_row) {
    echo json_encode([
      'success'=>true,
      'session_status'=>'ongoing',
      'quiz'=>null,
      'finished'=>true,
      'session_index'=>$total,
      'session_total'=>$total
    ]);
    exit;
  }

  $active_qid = (int)$active_row['question_id'];

  // 7) Load the question payload
  $q1 = $conn->prepare("
    SELECT question_id, COALESCE(title, ?) AS title, question_text,
           opt_a, opt_b, opt_c, opt_d,
           COALESCE(time_limit_sec,30) AS time_limit_sec
    FROM kiosk_quiz_questions
    WHERE question_id=? AND session_id=? AND status='published'
    LIMIT 1
  ");
  $q1->bind_param('sii', $session_title, $active_qid, $session_id);
  $q1->execute();
  $active = $q1->get_result()->fetch_assoc();
  if (!$active) { echo json_encode(['success'=>true,'session_status'=>'ongoing','quiz'=>null]); exit; }

  $payload = [
    'question_id'   => (int)$active['question_id'],
    'title'         => $active['title'],
    'question'      => $active['question_text'],
    'options'       => ['A'=>$active['opt_a'],'B'=>$active['opt_b'],'C'=>$active['opt_c'],'D'=>$active['opt_d']],
    'time_limit'    => (int)$active['time_limit_sec'],
    'session_index' => max(1, $answered + 1),
    'session_total' => $total
  ];

  echo json_encode(['success'=>true,'session_status'=>'ongoing','quiz'=>$payload]);

} catch (Throwable $e) {
  echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
