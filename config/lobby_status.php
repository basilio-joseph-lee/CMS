<?php
// /CMS/user/config/lobby_status.php
// Upsert player into lobby and return session status + live players list.

header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$session_id = (int)($_GET['session_id'] ?? $_POST['session_id'] ?? 0);
$name = trim((string)($_GET['name'] ?? $_POST['name'] ?? ''));
$name = mb_substr($name, 0, 120, 'UTF-8');

if ($session_id <= 0 || $name === '') {
  echo json_encode(['success'=>false,'error'=>'Missing session_id or name']); exit;
}

try {
  $conn = new mysqli("localhost","root","","cms");
  $conn->set_charset('utf8mb4');

  // Ensure table exists (safe no-op if already created).
  $conn->query("
    CREATE TABLE IF NOT EXISTS kiosk_quiz_players (
      session_id INT NOT NULL,
      name       VARCHAR(120) NOT NULL,
      joined_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      last_seen  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (session_id, name),
      KEY idx_seen (session_id, last_seen)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  // Upsert: mark presence
  $up = $conn->prepare("
    INSERT INTO kiosk_quiz_players (session_id, name, joined_at, last_seen)
    VALUES (?, ?, NOW(), NOW())
    ON DUPLICATE KEY UPDATE last_seen=VALUES(last_seen)
  ");
  $up->bind_param('is', $session_id, $name);
  $up->execute();

  // Session status
  $qs = $conn->prepare("SELECT status, COALESCE(title,'Quick Quiz') AS title FROM kiosk_quiz_sessions WHERE session_id=? LIMIT 1");
  $qs->bind_param('i', $session_id);
  $qs->execute();
  $sess = $qs->get_result()->fetch_assoc();
  if (!$sess) { echo json_encode(['success'=>false,'error'=>'Session not found']); exit; }

  // Active players (seen in last 40s)
  $qp = $conn->prepare("
    SELECT name, joined_at, last_seen
    FROM kiosk_quiz_players
    WHERE session_id=? AND last_seen > (NOW() - INTERVAL 40 SECOND)
    ORDER BY joined_at ASC, name ASC
  ");
  $qp->bind_param('i', $session_id);
  $qp->execute();
  $players = [];
  $r = $qp->get_result();
  while ($row = $r->fetch_assoc()) {
    $players[] = [
      'name' => $row['name'],
      'joined_at' => $row['joined_at'],
      'last_seen' => $row['last_seen'],
    ];
  }

  echo json_encode([
    'success' => true,
    'session_status' => $sess['status'],
    'title' => $sess['title'],
    'players' => $players,
    'count' => count($players),
  ]);

} catch (Throwable $e) {
  echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
