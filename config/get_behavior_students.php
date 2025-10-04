<?php
// cms/config/get_behavior_students.php
header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ---- DB ----
require_once __DIR__ . '/db.php'; // defines $conn or $mysqli
$db = null;
if (isset($conn) && $conn instanceof mysqli) $db = $conn;
if (!$db && isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli;
if (!$db || $db->connect_error) {
  http_response_code(500);
  echo json_encode(['status'=>'error','message'=>'DB connection failed']);
  exit;
}

// ---- Inputs ----
$student_id = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
$sinceIso   = $_POST['since'] ?? null;      // optional ISO8601
$dateStr    = $_POST['date']  ?? null;      // optional YYYY-MM-DD
if ($student_id <= 0) { echo json_encode([]); exit; }

// ---- Build WHERE (use MySQL timezone conversion) ----
// We store timestamps in UTC. To compare "today" in Asia/Manila:
//   DATE(CONVERT_TZ(`timestamp`, 'UTC', 'Asia/Manila')) = CURDATE()
$where = " WHERE `student_id` = ? ";
$params = [$student_id];
$types  = "i";

if (!empty($dateStr)) {
  // strict given date (local)
  $where .= " AND DATE(CONVERT_TZ(`timestamp`, 'UTC', 'Asia/Manila')) = ? ";
  $params[] = $dateStr; // 'YYYY-MM-DD'
  $types   .= "s";

} elseif (!empty($sinceIso)) {
  // incremental from given UTC time (assume incoming is ISO; normalize with strtotime)
  $t = strtotime($sinceIso);
  if ($t === false) {
    // fallback: today local
    $where .= " AND DATE(CONVERT_TZ(`timestamp`, 'UTC', 'Asia/Manila')) = CURDATE() ";
  } else {
    $sinceUtc = gmdate('Y-m-d H:i:s', $t);
    $where   .= " AND `timestamp` >= ? ";
    $params[] = $sinceUtc;
    $types   .= "s";
  }

} else {
  // default: today local
  $where .= " AND DATE(CONVERT_TZ(`timestamp`, 'UTC', 'Asia/Manila')) = CURDATE() ";
}

$sql = "SELECT `action_type`, `timestamp`
        FROM `behavior_logs`
        $where
        ORDER BY `timestamp` DESC";

// ---- Query ----
try {
  $stmt = $db->prepare($sql);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $res = $stmt->get_result();

  // de-dupe on (action_type, timestamp)
  $out = [];
  $seen = [];
  while ($row = $res->fetch_assoc()) {
    $k = $row['action_type'].'|'.$row['timestamp'];
    if (isset($seen[$k])) continue;
    $seen[$k] = true;
    $out[] = [
      'action_type' => $row['action_type'],
      'timestamp'   => $row['timestamp'], // keep UTC; app converts to local
    ];
  }

  // IMPORTANT: return a PLAIN ARRAY for your current ApiService
  echo json_encode($out, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  // On error, return empty array so app doesn't crash
  echo json_encode([]);
}
  