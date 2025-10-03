<?php
// /CMS/config/save_all_draft.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['teacher_id'])) {
  echo json_encode(['success'=>false,'message'=>'Not logged in']); exit;
}

$teacher_id     = (int)$_SESSION['teacher_id'];
$subject_id     = (int)$_SESSION['subject_id'];
$advisory_id    = (int)$_SESSION['advisory_id'];
$school_year_id = (int)$_SESSION['school_year_id'];

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
include("db.php");
$conn->set_charset('utf8mb4');

// Read JSON body
$payload = json_decode(file_get_contents('php://input'), true);
if (!$payload || empty($payload['questions']) || !is_array($payload['questions'])) {
  echo json_encode(['success'=>false,'message'=>'No questions received']); exit;
}

$title     = trim($payload['title'] ?? 'Quick Quiz');
$game_type = trim($payload['game_type'] ?? 'multiple_choice');

// 1) Create a new draft row in kiosk_quiz_sessions
$insS = $conn->prepare("
  INSERT INTO kiosk_quiz_sessions
    (teacher_id, subject_id, advisory_id, school_year_id, title, status)
  VALUES (?,?,?,?,?, 'draft')
");
$insS->bind_param('iiiis', $teacher_id, $subject_id, $advisory_id, $school_year_id, $title);
$insS->execute();
$quiz_id = (int)$conn->insert_id;

// 2) Insert all questions linked via quiz_id
$insQ = $conn->prepare("
  INSERT INTO kiosk_quiz_questions
    (teacher_id, subject_id, advisory_id, school_year_id,
     quiz_id, title, question_text,
     opt_a, opt_b, opt_c, opt_d,
     correct_opt, time_limit_sec, status, game_type, question_no)
  VALUES
    (?,?,?,?,?,
     ?,?, ?,?,?,?,
     ?,?, 'draft', ?, ?)
");

$qno = 0;
foreach ($payload['questions'] as $q) {
  $qno++;
  $q_title   = trim($q['title'] ?? $title);
  $q_text    = trim($q['question_text'] ?? '');
  $opt_a     = (string)($q['opt_a'] ?? '');
  $opt_b     = (string)($q['opt_b'] ?? '');
  $opt_c     = (string)($q['opt_c'] ?? '');
  $opt_d     = (string)($q['opt_d'] ?? '');
  $correct   = (string)($q['correct_opt'] ?? 'A');
  $seconds   = (int)($q['time_limit_sec'] ?? 30);

$insQ->bind_param(
  'iiiiissssssssisi',
  $teacher_id, $subject_id, $advisory_id, $school_year_id, // i i i i
  $quiz_id,                                                // i
  $q_title, $q_text,                                       // s s
  $opt_a, $opt_b, $opt_c, $opt_d,                          // s s s s
  $correct,                                                // s
  $seconds,                                                // i  (time_limit_sec is INT)
  $game_type,                                              // s
  $qno                                                     // i
);

  $insQ->execute();
}

echo json_encode([
  'success'  => true,
  'message'  => 'Draft saved',
  'quiz_id'  => $quiz_id,
  'count'    => $qno,
]);
