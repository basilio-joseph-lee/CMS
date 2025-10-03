<?php
// config/get_quiz_leaderboard.php
session_start();
header('Content-Type: application/json');
include("db.php");

if (!isset($_SESSION['teacher_id']) && !isset($_SESSION['student_id'])) {
  echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit;
}

$subject_id     = intval($_SESSION['subject_id']     ?? $_SESSION['active_subject_id']     ?? 0);
$advisory_id    = intval($_SESSION['advisory_id']    ?? $_SESSION['active_advisory_id']    ?? 0);
$school_year_id = intval($_SESSION['school_year_id'] ?? $_SESSION['active_school_year_id'] ?? 0);

$question_id = isset($_GET['question_id']) ? intval($_GET['question_id']) : 0;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {

  $conn->set_charset('utf8mb4');

  // If no question_id given, pull the most recently CLOSED one for this slot.
  if ($question_id <= 0) {
    $stmt = $conn->prepare("
      SELECT question_id
        FROM kiosk_quiz_questions
       WHERE subject_id = ? AND advisory_id = ? AND school_year_id = ?
         AND status = 'closed'
       ORDER BY closed_at DESC, question_id DESC
       LIMIT 1
    ");
    $stmt->bind_param("iii", $subject_id, $advisory_id, $school_year_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) { echo json_encode(['success'=>true,'data'=>[], 'question'=>null]); exit; }
    $question_id = intval($res->fetch_assoc()['question_id']);
    $stmt->close();
  }

  // Fetch question summary
  $stmt = $conn->prepare("
    SELECT q.question_id, q.title, q.question_text, q.published_at, q.closed_at
      FROM kiosk_quiz_questions q
     WHERE q.question_id = ?
       AND q.subject_id = ? AND q.advisory_id = ? AND q.school_year_id = ?
     LIMIT 1
  ");
  $stmt->bind_param("iiii", $question_id, $subject_id, $advisory_id, $school_year_id);
  $stmt->execute();
  $question = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$question) { echo json_encode(['success'=>false, 'message'=>'Invalid question']); exit; }

  // Leaderboard (Top 10)
  // If multiple responses per student accidentally exist, take the best (max points, min time).
  $sql = "
    SELECT r.student_id,
           s.fullname,
           COALESCE(s.avatar_url, s.photo, '') AS avatar_url,
           MAX(r.points) AS points,
           MIN(CASE WHEN r.points = (SELECT MAX(r2.points) FROM kiosk_quiz_responses r2 WHERE r2.student_id=r.student_id AND r2.question_id=r.question_id)
                    THEN r.time_ms ELSE 2147483647 END) AS best_time_ms
      FROM kiosk_quiz_responses r
      JOIN students s ON s.student_id = r.student_id
     WHERE r.question_id = ?
  GROUP BY r.student_id
  ORDER BY points DESC, best_time_ms ASC
  LIMIT 10";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("i", $question_id);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();

  echo json_encode([
    'success' => true,
    'question'=> [
      'question_id'  => $question['question_id'],
      'title'        => $question['title'],
      'question'     => $question['question_text'],
      'closed_at'    => $question['closed_at'],
      'published_at' => $question['published_at'],
    ],
    'data' => array_map(function($r){
      return [
        'student_id' => intval($r['student_id']),
        'name'       => $r['fullname'],
        'avatar'     => $r['avatar_url'] ?: '../img/avatar-default.png',
        'points'     => intval($r['points']),
        'time_ms'    => intval($r['best_time_ms'])
      ];
    }, $rows)
  ]);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['success'=>false,'message'=>'Server error','error'=>$e->getMessage()]);
}
