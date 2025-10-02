<?php
/**
 * config/save_quiz_question.php
 * 
 * Supports multi-question quizzes:
 * - Each question gets its own row keyed by order_hint (1..N).
 * - Teacher can save drafts per question.
 * - Publish mode closes existing active quiz then inserts this one as published.
 */
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['teacher_id'])) {
  echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit;
}

$teacher_id     = intval($_SESSION['teacher_id']);
$subject_id     = intval($_SESSION['subject_id']);
$advisory_id    = intval($_SESSION['advisory_id']);
$school_year_id = intval($_SESSION['school_year_id']);

$mode           = $_POST['mode'] ?? 'draft';
$order_hint     = intval($_POST['order_hint'] ?? 1);
$title          = trim($_POST['title'] ?? 'Quick Quiz');
$question_text  = trim($_POST['question_text'] ?? '');
$opt_a          = trim($_POST['opt_a'] ?? '');
$opt_b          = trim($_POST['opt_b'] ?? '');
$opt_c          = trim($_POST['opt_c'] ?? '');
$opt_d          = trim($_POST['opt_d'] ?? '');
$correct_opt    = $_POST['correct_opt'] ?? 'A';
$time_limit_sec = max(10, min(300, intval($_POST['time_limit_sec'] ?? 30)));

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
  $conn = new mysqli("localhost", "root", "", "cms");
  $conn->set_charset('utf8mb4');

  // add order_hint column if missing
  $res = $conn->query("SHOW COLUMNS FROM kiosk_quiz_questions LIKE 'order_hint'");
  if (!$res || $res->num_rows === 0) {
    $conn->query("ALTER TABLE kiosk_quiz_questions ADD COLUMN order_hint INT NULL AFTER school_year_id");
  }
  if ($res) $res->close();

  $conn->begin_transaction();

  if ($mode === 'publish') {
    // close any currently active quiz
    $stmt = $conn->prepare("
      UPDATE kiosk_quiz_questions
         SET status='closed', active_flag=NULL, closed_at=NOW()
       WHERE subject_id=? AND advisory_id=? AND school_year_id=? AND active_flag=1
    ");
    $stmt->bind_param("iii", $subject_id, $advisory_id, $school_year_id);
    $stmt->execute();
    $stmt->close();

    // insert this question as published
    $status = 'published';
    $stmt = $conn->prepare("
      INSERT INTO kiosk_quiz_questions
        (teacher_id, subject_id, advisory_id, school_year_id, order_hint,
         title, question_text, opt_a, opt_b, opt_c, opt_d,
         correct_opt, time_limit_sec, status, active_flag, published_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,NOW())
    ");
    $stmt->bind_param(
      "iiiissssssssis",
      $teacher_id, $subject_id, $advisory_id, $school_year_id, $order_hint,
      $title, $question_text, $opt_a, $opt_b, $opt_c, $opt_d,
      $correct_opt, $time_limit_sec, $status
    );
    $stmt->execute();
    $stmt->close();

    $conn->commit();
    echo json_encode(['success'=>true,'message'=>"Question $order_hint published!"]);
    exit;
  }

  // ---------- DRAFT MODE ----------
  $stmt = $conn->prepare("
    SELECT question_id FROM kiosk_quiz_questions
     WHERE status='draft' AND teacher_id=? AND subject_id=? AND advisory_id=? 
       AND school_year_id=? AND order_hint=?
     LIMIT 1
  ");
  $stmt->bind_param("iiiii", $teacher_id, $subject_id, $advisory_id, $school_year_id, $order_hint);
  $stmt->execute();
  $res = $stmt->get_result();
  $existing = $res && $res->num_rows ? intval($res->fetch_assoc()['question_id']) : 0;
  $stmt->close();

  if ($existing) {
    $stmt = $conn->prepare("
      UPDATE kiosk_quiz_questions
         SET title=?, question_text=?, opt_a=?, opt_b=?, opt_c=?, opt_d=?,
             correct_opt=?, time_limit_sec=?
       WHERE question_id=?
    ");
    $stmt->bind_param(
      "sssssssii",
      $title, $question_text, $opt_a, $opt_b, $opt_c, $opt_d,
      $correct_opt, $time_limit_sec, $existing
    );
    $stmt->execute();
    $stmt->close();
  } else {
    $status = 'draft';
    $stmt = $conn->prepare("
      INSERT INTO kiosk_quiz_questions
        (teacher_id, subject_id, advisory_id, school_year_id, order_hint,
         title, question_text, opt_a, opt_b, opt_c, opt_d,
         correct_opt, time_limit_sec, status)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    $stmt->bind_param(
      "iiiissssssssis",
      $teacher_id, $subject_id, $advisory_id, $school_year_id, $order_hint,
      $title, $question_text, $opt_a, $opt_b, $opt_c, $opt_d,
      $correct_opt, $time_limit_sec, $status
    );
    $stmt->execute();
    $stmt->close();
  }

  $conn->commit();
  echo json_encode(['success'=>true,'message'=>"Draft for question $order_hint saved."]);

} catch (Throwable $e) {
  if (isset($conn)) { try { $conn->rollback(); } catch (Throwable $ignored) {} }
  http_response_code(500);
  echo json_encode(['success'=>false,'message'=>'Save failed','error'=>$e->getMessage()]);
}
