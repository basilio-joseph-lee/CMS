<?php
// cms/config/get_behavior_students.php
// Returns a plain JSON array of behavior logs for ONE student.
//   Input (POST):
//     - student_id (required, int)
//     - date       (optional, 'YYYY-MM-DD' in Asia/Manila)  -> exact local day
//     - since      (optional, ISO-8601)                     -> incremental fetch from UTC time
//     - limit      (optional, int, default 200, max 1000)
//
// Notes:
// - DB stores timestamps in UTC.
// - We avoid MySQL CONVERT_TZ() (may be unavailable on shared hosts).
// - We compute local-day (Asia/Manila) boundaries in PHP, then compare in UTC.

header('Content-Type: application/json; charset=utf-8');
// Optional CORS for mobile/web clients
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  http_response_code(204);
  exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
// Hide notices/warnings to keep JSON clean
error_reporting(E_ERROR | E_PARSE);

// ---------- DB ----------
require_once __DIR__ . '/db.php'; // should define $conn (mysqli)
$db = null;
if (isset($conn) && $conn instanceof mysqli) $db = $conn;
if (!$db || $db->connect_error) {
  http_response_code(500);
  echo json_encode([]);
  exit;
}
$db->set_charset('utf8mb4');

// ---------- Inputs ----------
$student_id = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
$dateStr    = isset($_POST['date']) ? trim($_POST['date']) : null;   // 'YYYY-MM-DD' in Asia/Manila
$sinceIso   = isset($_POST['since']) ? trim($_POST['since']) : null; // ISO-8601 (UTC or local, we normalize)
$limit      = isset($_POST['limit']) ? (int)$_POST['limit'] : 200;
if ($limit <= 0) $limit = 200;
if ($limit > 1000) $limit = 1000;

if ($student_id <= 0) { echo json_encode([]); exit; }

// ---------- Time math helpers ----------
$APP_TZ = new DateTimeZone('Asia/Manila');
$UTC_TZ = new DateTimeZone('UTC');

$types  = 'i';           // first bind is student_id
$params = [$student_id];

$whereParts = ['`student_id` = ?'];

// If a local date is provided, compute its local 00:00:00 .. 23:59:59 then convert to UTC range.
if (!empty($dateStr)) {
  $startLocal = DateTime::createFromFormat('Y-m-d H:i:s', $dateStr . ' 00:00:00', $APP_TZ);
  $endLocal   = DateTime::createFromFormat('Y-m-d H:i:s', $dateStr . ' 23:59:59', $APP_TZ);

  if ($startLocal && $endLocal) {
    $startUtc = clone $startLocal; $startUtc->setTimezone($UTC_TZ);
    $endUtc   = clone $endLocal;   $endUtc->setTimezone($UTC_TZ);

    $whereParts[] = '`timestamp` BETWEEN ? AND ?';
    $types       .= 'ss';
    $params[]     = $startUtc->format('Y-m-d H:i:s');
    $params[]     = $endUtc->format('Y-m-d H:i:s');
  }
// Else if a "since" ISO is provided, use it as a lower UTC bound.
} elseif (!empty($sinceIso)) {
  // Normalize to UTC
  try {
    $since = new DateTime($sinceIso);
  } catch (Throwable $e) {
    $since = false;
  }
  if ($since instanceof DateTime) {
    $since->setTimezone($UTC_TZ);
    $whereParts[] = '`timestamp` >= ?';
    $types       .= 's';
    $params[]     = $since->format('Y-m-d H:i:s');
  } else {
    // Fallback to "today" in Asia/Manila:
    $startLocal = new DateTime('today', $APP_TZ);
    $endLocal   = new DateTime('today 23:59:59', $APP_TZ);
    $startUtc   = (clone $startLocal)->setTimezone($UTC_TZ);
    $endUtc     = (clone $endLocal)->setTimezone($UTC_TZ);

    $whereParts[] = '`timestamp` BETWEEN ? AND ?';
    $types       .= 'ss';
    $params[]     = $startUtc->format('Y-m-d H:i:s');
    $params[]     = $endUtc->format('Y-m-d H:i:s');
  }
// Default: "today" (local) → UTC range
} else {
  $startLocal = new DateTime('today', $APP_TZ);
  $endLocal   = new DateTime('today 23:59:59', $APP_TZ);
  $startUtc   = (clone $startLocal)->setTimezone($UTC_TZ);
  $endUtc     = (clone $endLocal)->setTimezone($UTC_TZ);

  $whereParts[] = '`timestamp` BETWEEN ? AND ?';
  $types       .= 'ss';
  $params[]     = $startUtc->format('Y-m-d H:i:s');
  $params[]     = $endUtc->format('Y-m-d H:i:s');
}

$whereSql = 'WHERE ' . implode(' AND ', $whereParts);

$sql = "SELECT `action_type`, `timestamp`
          FROM `behavior_logs`
          $whereSql
         ORDER BY `timestamp` DESC
         LIMIT ?";

$types .= 'i';
$params[] = $limit;

// ---------- Query ----------
try {
  $stmt = $db->prepare($sql);

  // bind_param needs references
  $bind = [];
  $bind[] = & $types;
  foreach ($params as $k => $v) {
    $bind[] = & $params[$k];
  }
  call_user_func_array([$stmt, 'bind_param'], $bind);

  $stmt->execute();
  $res = $stmt->get_result();

  $out  = [];
  $seen = [];

  while ($row = $res->fetch_assoc()) {
    // De-dup on (action_type|timestamp) just like your previous version
    $k = $row['action_type'] . '|' . $row['timestamp'];
    if (isset($seen[$k])) continue;
    $seen[$k] = true;

    $out[] = [
      'action_type' => $row['action_type'],
      'timestamp'   => $row['timestamp'], // keep UTC; app converts to local
    ];
  }

  echo json_encode($out, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  // Return empty array on error to avoid crashing the app
  echo json_encode([]);
}
