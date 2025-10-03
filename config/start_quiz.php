<?php
// /CMS/config/start_quiz.php
session_start();

$ajax = isset($_POST['ajax']) || isset($_GET['ajax']);
if ($ajax) header('Content-Type: application/json');

if (!isset($_SESSION['teacher_id'])) {
  if ($ajax) { echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit; }
  header("Location: ../user/teacher_login.php"); exit;
}

$teacher_id     = (int)$_SESSION['teacher_id'];
$subject_id     = (int)($_SESSION['active_subject_id']     ?? $_SESSION['subject_id']     ?? 0);
$advisory_id    = (int)($_SESSION['active_advisory_id']    ?? $_SESSION['advisory_id']    ?? 0);
$school_year_id = (int)($_SESSION['active_school_year_id'] ?? $_SESSION['school_year_id'] ?? 0);
$session_id     = (int)($_GET['session_id'] ?? $_POST['session_id'] ?? 0);

if ($session_id <= 0) {
  if ($ajax) { echo json_encode(['success'=>false,'error'=>'Missing session_id']); exit; }
  header("Location: ../user/teacher/quiz_dashboard.php"); exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
include("db.php");

try {

  $conn->set_charset('utf8mb4');

  $conn->begin_transaction();

  // 1) Mark the session ONGOING (stamp started_at once)
  $q = $conn->prepare("
    UPDATE kiosk_quiz_sessions
       SET status='ongoing',
           started_at = IFNULL(started_at, NOW())
     WHERE session_id=?");
  $q->bind_param('i', $session_id);
  $q->execute();

  // 2) First PUBLISHED question for this session
  $q = $conn->prepare("
    SELECT question_id
      FROM kiosk_quiz_questions
     WHERE session_id=? AND status='published'
  ORDER BY COALESCE(order_no, question_no, question_id) ASC, question_id ASC
     LIMIT 1");
  $q->bind_param('i', $session_id);
  $q->execute();
  $row = $q->get_result()->fetch_assoc();
  if (!$row) {
    $conn->rollback();
    if ($ajax) { echo json_encode(['success'=>false,'error'=>'No published questions']); exit; }
    header("Location: ../user/teacher/quiz_dashboard.php"); exit;
  }
  $qid = (int)$row['question_id'];

  // 3) Reset all questions for this class to NOT ACTIVE (NULL), then mark this one as ACTIVE (1)
  $q = $conn->prepare("
    UPDATE kiosk_quiz_questions
       SET active_flag = NULL
     WHERE teacher_id=? AND subject_id=? AND advisory_id=? AND school_year_id=?");
  $q->bind_param('iiii', $teacher_id,$subject_id,$advisory_id,$school_year_id);
  $q->execute();

  $q = $conn->prepare("UPDATE kiosk_quiz_questions SET active_flag=1 WHERE question_id=?");
  $q->bind_param('i', $qid);
  $q->execute();

  // 4) Upsert the “active pointer” for this class+session
  // NOTE: kiosk_quiz_active PRIMARY KEY must include (teacher_id, subject_id, advisory_id, school_year_id, session_id)
  $q = $conn->prepare("
    INSERT INTO kiosk_quiz_active
      (teacher_id, subject_id, advisory_id, school_year_id, session_id, question_id, updated_at)
    VALUES (?,?,?,?,?,?,NOW())
    ON DUPLICATE KEY UPDATE
      question_id = VALUES(question_id),
      updated_at = NOW()
  ");
  $q->bind_param('iiiiii',
    $teacher_id, $subject_id, $advisory_id, $school_year_id, $session_id, $qid
  );
  $q->execute();

  $conn->commit();

  if ($ajax) {
    echo json_encode(['success'=>true,'session_id'=>$session_id,'question_id'=>$qid,'status'=>'ongoing']);
  } else {
    header("Location: ../user/teacher/quiz_dashboard.php");
  }
} catch (Throwable $e) {
  if (isset($conn)) { try { $conn->rollback(); } catch (\Throwable $ignored) {} }
  if ($ajax) echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
  else       header("Location: ../user/teacher/quiz_dashboard.php");
}
