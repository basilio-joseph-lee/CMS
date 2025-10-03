<?php
// /CMS/config/quiz_save_whole.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['teacher_id'])) {
  echo json_encode(['success'=>false,'message'=>'Not logged in']); 
  exit;
}

$teacher_id     = (int)$_SESSION['teacher_id'];
$subject_id     = (int)$_SESSION['subject_id'];
$advisory_id    = (int)$_SESSION['advisory_id'];
$school_year_id = (int)$_SESSION['school_year_id'];

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
include("db.php");
$conn->set_charset('utf8mb4');

// --- helpers ---
function rand_code($len=8){
  $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  $s=''; 
  for($i=0;$i<$len;$i++) $s .= $chars[random_int(0,strlen($chars)-1)];
  return $s;
}
function unique_session_code(mysqli $c, $tries=10){
  for($i=0;$i<$tries;$i++){
    $code = rand_code(8);
    $q = $c->prepare("SELECT 1 FROM kiosk_quiz_sessions WHERE session_code=? LIMIT 1");
    $q->bind_param('s',$code); 
    $q->execute();
    if ($q->get_result()->num_rows===0) return $code;
  }
  return rand_code(10);
}

// --- input ---
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$mode      = $body['mode'] ?? 'draft';   // 'draft' or 'publish'
$title     = trim($body['title'] ?? 'Quick Quiz');
$game_type = trim($body['game_type'] ?? 'multiple_choice');
$quiz_id   = (int)($body['quiz_id'] ?? 0);
$questions = $body['questions'] ?? [];

if (!$questions || !is_array($questions)){
  echo json_encode(['success'=>false,'message'=>'No questions provided']); 
  exit;
}

try {
  $conn->begin_transaction();

  // 1) create new session if quiz_id == 0
  if ($quiz_id <= 0) {
    $code = unique_session_code($conn);
    $insS = $conn->prepare("
      INSERT INTO kiosk_quiz_sessions
        (teacher_id, subject_id, advisory_id, school_year_id, title, session_code, status, started_at)
      VALUES (?,?,?,?,?,?,'draft', NULL)
    ");
    $insS->bind_param('iiiiss', $teacher_id,$subject_id,$advisory_id,$school_year_id,$title,$code);
    $insS->execute();
    $quiz_id = (int)$conn->insert_id;
  } else {
    // editing existing: clear old questions
    $del = $conn->prepare("DELETE FROM kiosk_quiz_questions WHERE quiz_id=?");
    $del->bind_param('i',$quiz_id);
    $del->execute();
  }

  // 2) insert all questions
  $insQ = $conn->prepare("
    INSERT INTO kiosk_quiz_questions
      (teacher_id, subject_id, advisory_id, school_year_id,
       quiz_id, title, question_text,
       opt_a,opt_b,opt_c,opt_d,
       correct_opt, time_limit_sec, status, game_type, question_no)
    VALUES
      (?,?,?,?,?,
       ?,?, ?,?,?,?,
       ?,?, 'draft', ?, ?)
  ");

  foreach ($questions as $q) {
    $q_title = trim((string)($q['title'] ?? $title));
    $q_text  = trim((string)($q['question_text'] ?? ''));
    $a = (string)($q['opt_a'] ?? '');
    $b = (string)($q['opt_b'] ?? '');
    $c = (string)($q['opt_c'] ?? '');
    $d = (string)($q['opt_d'] ?? '');
    $ans = (string)($q['correct_opt'] ?? 'A');
    $sec = (int)($q['time_limit_sec'] ?? 30);
    $qno = (int)($q['question_no'] ?? 1);

    $insQ->bind_param(
      'iiiiisssssssisi',
      $teacher_id,$subject_id,$advisory_id,$school_year_id,
      $quiz_id,
      $q_title,$q_text,
      $a,$b,$c,$d,
      $ans,$sec,$game_type,$qno
    );
    $insQ->execute();
  }

  // 3) publish if explicitly requested
  if ($mode === 'publish') {
    $uS = $conn->prepare("UPDATE kiosk_quiz_sessions SET status='active', started_at=NOW() WHERE session_id=?");
    $uS->bind_param('i',$quiz_id); 
    $uS->execute();

    $uQ = $conn->prepare("UPDATE kiosk_quiz_questions SET status='published' WHERE quiz_id=?");
    $uQ->bind_param('i',$quiz_id); 
    $uQ->execute();
  }

  $conn->commit();
  echo json_encode([
    'success'=>true,
    'quiz_id'=>$quiz_id,
    'message'=>($mode==='publish'?'Published all':'Saved all as draft')
  ]);
} catch (Throwable $e) {
  $conn->rollback();
  echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
