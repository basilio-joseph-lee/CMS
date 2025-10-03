<?php
// /CMS/user/config/get_leaderboard.php
// Returns podium + ranked list (competition ranking with ties), using shared db.php

header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$session_id = (int)($_GET['session_id'] ?? 0);
if ($session_id <= 0) {
  echo json_encode(['success' => false, 'message' => 'Missing session_id']);
  exit;
}

try {
  // Use the shared connection
  require_once __DIR__ . '/../../config/db.php'; // adjusts path: /user/config -> /config/db.php
  if (!isset($conn) || !($conn instanceof mysqli)) {
    throw new Exception('DB connection not initialized.');
  }

  // Ensure charset
  $conn->set_charset('utf8mb4');

  // total published questions in this session
  $qt = $conn->prepare("
    SELECT COUNT(*) AS total
      FROM kiosk_quiz_questions
     WHERE session_id = ? AND status = 'published'
  ");
  $qt->bind_param('i', $session_id);
  $qt->execute();
  $resT = $qt->get_result();
  $total = (int)($resT->fetch_assoc()['total'] ?? 0);
  $qt->close();

  // aggregate per player
  $sql = "
    SELECT r.name,
           COALESCE(SUM(r.points), 0)                         AS points,
           SUM(CASE WHEN r.is_correct = 1 THEN 1 ELSE 0 END)  AS correct,
           COUNT(*)                                           AS answered,
           MAX(r.answered_at)                                 AS last_time
      FROM kiosk_quiz_responses r
      JOIN kiosk_quiz_questions  q ON q.question_id = r.question_id
     WHERE q.session_id = ?
     GROUP BY r.name
  ";
  $st = $conn->prepare($sql);
  $st->bind_param('i', $session_id);
  $st->execute();
  $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
  $st->close();

  // sort + rank (points desc, correct desc, earliest last_time wins; then name)
  usort($rows, function($a, $b) {
    $pa = (int)$a['points'];  $pb = (int)$b['points'];
    if ($pa !== $pb) return $pb - $pa;

    $ca = (int)$a['correct']; $cb = (int)$b['correct'];
    if ($ca !== $cb) return $cb - $ca;

    $la = $a['last_time'] ?? ''; $lb = $b['last_time'] ?? '';
    if ($la !== $lb) return strcmp($la, $lb); // earlier first

    return strnatcasecmp($a['name'] ?? '', $b['name'] ?? '');
  });

  // competition ranks (1,2,2,4…)
  $rank = 0; $i = 0; $prevKey = null;
  foreach ($rows as &$r) {
    $i++;
    $key = ((int)$r['points']) . '|' . ((int)$r['correct']) . '|' . ($r['last_time'] ?? '');
    if ($key !== $prevKey) { $rank = $i; $prevKey = $key; }
    $r['rank']     = $rank;
    $r['points']   = (int)$r['points'];
    $r['correct']  = (int)$r['correct'];
    $r['answered'] = (int)$r['answered'];
  }
  unset($r);

  echo json_encode([
    'success'          => true,
    'total_questions'  => $total,
    'players'          => $rows,                // sorted + ranked
    'top3'             => array_slice($rows, 0, 3)
  ]);
} catch (Throwable $e) {
  echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
