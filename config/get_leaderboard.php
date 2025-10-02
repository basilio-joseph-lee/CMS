<?php
// /CMS/user/config/get_leaderboard.php
// Returns podium + ranked list (competition ranking with ties)

header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$session_id = (int)($_GET['session_id'] ?? 0);
if ($session_id <= 0) { echo json_encode(['success'=>false,'message'=>'Missing session_id']); exit; }

try {
  $db = new mysqli('localhost','root','','cms');
  $db->set_charset('utf8mb4');

  // total published
  $qt = $db->prepare("SELECT COUNT(*) AS total FROM kiosk_quiz_questions WHERE session_id=? AND status='published'");
  $qt->bind_param('i', $session_id);
  $qt->execute();
  $total = (int)$qt->get_result()->fetch_assoc()['total'];

  // aggregate per player
  $sql = "
    SELECT r.name,
           COALESCE(SUM(r.points),0)                 AS points,
           SUM(CASE WHEN r.is_correct=1 THEN 1 ELSE 0 END) AS correct,
           COUNT(*)                                  AS answered,
           MAX(r.answered_at)                        AS last_time
      FROM kiosk_quiz_responses r
      JOIN kiosk_quiz_questions  q ON q.question_id = r.question_id
     WHERE q.session_id = ?
     GROUP BY r.name
  ";
  $st = $db->prepare($sql);
  $st->bind_param('i', $session_id);
  $st->execute();
  $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);

  // sort + rank (points desc, correct desc, earliest last_time wins; then name)
  usort($rows, function($a,$b){
    if ((int)$a['points'] !== (int)$b['points']) return (int)$b['points'] - (int)$a['points'];
    if ((int)$a['correct']!== (int)$b['correct']) return (int)$b['correct'] - (int)$a['correct'];
    if ($a['last_time'] !== $b['last_time'])      return strcmp($a['last_time'],$b['last_time']); // earlier first
    return strnatcasecmp($a['name'],$b['name']);
  });

  // competition ranks (1,2,2,4…)
  $rank=0; $i=0; $prevKey=null;
  foreach ($rows as &$r) {
    $i++;
    $key = ((int)$r['points']).'|'.((int)$r['correct']).'|'.($r['last_time']??'');
    if ($key !== $prevKey) { $rank = $i; $prevKey = $key; }
    $r['rank']    = $rank;
    $r['points']  = (int)$r['points'];
    $r['correct'] = (int)$r['correct'];
    $r['answered']= (int)$r['answered'];
  }
  unset($r);

  echo json_encode([
    'success'=>true,
    'total_questions'=>$total,
    'players'=>$rows,          // sorted + ranked
    'top3'=>array_slice($rows,0,3)
  ]);
} catch (Throwable $e) {
  echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
