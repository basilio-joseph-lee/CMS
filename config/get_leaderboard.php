<?php
// /CMS/user/config/get_leaderboard.php
// Returns podium + ranked list (competition ranking with ties)
// Uses same-folder db.php (consistent with submit_quiz_answer.php)

header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$session_id = (int)($_GET['session_id'] ?? 0);
if ($session_id <= 0) {
  echo json_encode(['success' => false, 'message' => 'Missing session_id']);
  exit;
}

try {
  // Use the same DB include style as submit_quiz_answer.php
  include __DIR__ . '/db.php';
  if (!isset($conn) || !($conn instanceof mysqli)) {
    throw new Exception('DB connection not initialized.');
  }
  $conn->set_charset('utf8mb4');

  // Read session metadata (status/title)
  $sessStatus = '';
  $sessTitle  = '';
  $qs = $conn->prepare("SELECT status, title FROM kiosk_quiz_sessions WHERE session_id = ? LIMIT 1");
  $qs->bind_param('i', $session_id);
  $qs->execute();
  if ($sr = $qs->get_result()->fetch_assoc()) {
    $sessStatus = (string)($sr['status'] ?? '');
    $sessTitle  = (string)($sr['title'] ?? '');
  }
  $qs->close();

  // Count published questions for display
  $qt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM kiosk_quiz_questions
    WHERE session_id = ? AND status = 'published'
  ");
  $qt->bind_param('i', $session_id);
  $qt->execute();
  $resT  = $qt->get_result();
  $total = (int)($resT->fetch_assoc()['total'] ?? 0);
  $qt->close();

  // Aggregate scores:
  // - Join by question_id limited to this session
  // - Collapse to one attempt per (normalized name, question)
  // - Keep best points and whether any attempt was correct
  // - Track latest answered_at per question for timing tiebreak
  $sql = "
    SELECT
      t.norm_name,
      t.display_name AS name,
      COALESCE(SUM(t.points), 0)                                        AS points,
      SUM(CASE WHEN t.is_correct = 1 THEN 1 ELSE 0 END)                 AS correct,
      COUNT(*)                                                           AS answered,
      MAX(t.last_time)                                                  AS last_time
    FROM (
      SELECT
        LOWER(TRIM(r.name))                         AS norm_name,
        MIN(TRIM(r.name))                           AS display_name,
        r.question_id,
        MAX(r.points)                               AS points,
        MAX(r.is_correct)                           AS is_correct,
        MAX(r.answered_at)                          AS last_time
      FROM kiosk_quiz_responses r
      JOIN kiosk_quiz_questions  q ON q.question_id = r.question_id
      WHERE q.session_id = ?
      GROUP BY LOWER(TRIM(r.name)), r.question_id
    ) t
    GROUP BY t.norm_name, t.display_name
  ";

  $st = $conn->prepare($sql);
  $st->bind_param('i', $session_id);
  $st->execute();
  $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
  $st->close();

  // Sort: points desc, correct desc, earliest last_time wins, then name asc
  usort($rows, function($a, $b) {
    $pa = (int)$a['points'];  $pb = (int)$b['points'];
    if ($pa !== $pb) return $pb - $pa;

    $ca = (int)$a['correct']; $cb = (int)$b['correct'];
    if ($ca !== $cb) return $cb - $ca;

    $la = (string)($a['last_time'] ?? '');
    $lb = (string)($b['last_time'] ?? '');
    if ($la !== $lb) return strcmp($la, $lb); // earlier first

    $na = (string)($a['name'] ?? '');
    $nb = (string)($b['name'] ?? '');
    return strnatcasecmp($na, $nb);
  });

  // Competition ranking (1,2,2,4…)
  $rank = 0; $i = 0; $prevKey = null;
  foreach ($rows as &$r) {
    $i++;
    $key = ((int)$r['points']) . '|' . ((int)$r['correct']) . '|' . ((string)($r['last_time'] ?? ''));
    if ($key !== $prevKey) { $rank = $i; $prevKey = $key; }
    $r['rank']     = $rank;
    $r['points']   = (int)$r['points'];
    $r['correct']  = (int)$r['correct'];
    $r['answered'] = (int)$r['answered'];
    unset($r['norm_name']); // internal only
  }
  unset($r);

  echo json_encode([
    'success'          => true,
    'session_status'   => $sessStatus,
    'session_title'    => $sessTitle,
    'total_questions'  => $total,
    'players'          => $rows,
    'top3'             => array_slice($rows, 0, 3),
  ]);
} catch (Throwable $e) {
  echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
