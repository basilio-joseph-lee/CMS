<?php
// cms/config/get_students.php
// Returns a plain JSON array of the parent's students with quick "today" stats:
//
// Input (POST/OPTIONS):
//   - parent_id (required, int)
//   - date (optional, 'YYYY-MM-DD' Asia/Manila). Default = today.
// Output (array):
//   [
//     {
//       student_id: int,
//       fullname: string,
//       gender: string,
//       section: string|null,
//       avatar_path: string|null,
//       today: {
//         date: 'YYYY-MM-DD',
//         present: bool,                   // true if any 'attendance' log today
//         attendance_time: 'YYYY-MM-DD HH:MM:SS'|null, // first attendance (local Asia/Manila)
//         logs_count: int                  // total behavior logs today
//       }
//     },
//     ...
//   ]

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  http_response_code(204);
  exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
error_reporting(E_ERROR | E_PARSE);

// ---- DB ----
require_once __DIR__ . '/db.php'; // defines $conn
if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
  http_response_code(500);
  echo json_encode([]);
  exit;
}
$conn->set_charset('utf8mb4');

// ---- Inputs ----
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  echo json_encode([]);
  exit;
}

$parent_id = isset($_POST['parent_id']) ? (int)$_POST['parent_id'] : 0;
if ($parent_id <= 0) {
  echo json_encode([]);
  exit;
}
$dateStr = isset($_POST['date']) ? trim($_POST['date']) : null; // optional local day

// ---- Time math (Asia/Manila → UTC window) ----
$APP_TZ = new DateTimeZone('Asia/Manila');
$UTC_TZ = new DateTimeZone('UTC');
if ($dateStr && DateTime::createFromFormat('Y-m-d', $dateStr) !== false) {
  $startLocal = DateTime::createFromFormat('Y-m-d H:i:s', $dateStr . ' 00:00:00', $APP_TZ);
  $endLocal   = DateTime::createFromFormat('Y-m-d H:i:s', $dateStr . ' 23:59:59', $APP_TZ);
} else {
  $dateStr    = (new DateTime('now', $APP_TZ))->format('Y-m-d');
  $startLocal = new DateTime('today', $APP_TZ);
  $endLocal   = new DateTime('today 23:59:59', $APP_TZ);
}
$startUtc = (clone $startLocal)->setTimezone($UTC_TZ)->format('Y-m-d H:i:s');
$endUtc   = (clone $endLocal)->setTimezone($UTC_TZ)->format('Y-m-d H:i:s');

// ---- Fetch children (include section + avatar_path) ----
$sqlKids = "SELECT student_id, fullname, gender, section, avatar_path
              FROM students
             WHERE parent_id = ?";
$stKids = $conn->prepare($sqlKids);
$stKids->bind_param('i', $parent_id);
$stKids->execute();
$resKids = $stKids->get_result();

$students = [];
$ids = [];
while ($r = $resKids->fetch_assoc()) {
  $sid = (int)$r['student_id'];
  $ids[] = $sid;
  $students[$sid] = [
    'student_id'  => $sid,
    'fullname'    => (string)$r['fullname'],
    'gender'      => (string)$r['gender'],
    'section'     => isset($r['section']) ? (string)$r['section'] : null,
    'avatar_path' => isset($r['avatar_path']) ? (string)$r['avatar_path'] : null,
    'today'       => [
      'date'             => $dateStr,
      'present'          => false,
      'attendance_time'  => null,
      'logs_count'       => 0,
    ],
  ];
}
$stKids->close();

if (!$ids) {
  echo json_encode([], JSON_UNESCAPED_UNICODE);
  exit;
}

// ---- Batch compute today's stats from behavior_logs ----
// One query for all kids: counts + first attendance per child
$in = implode(',', array_fill(0, count($ids), '?'));
$sqlStats = "
  SELECT bl.student_id,
         COUNT(*) AS logs_count,
         MIN(CASE WHEN bl.action_type = 'attendance' THEN bl.`timestamp` END) AS first_attendance
    FROM behavior_logs bl
   WHERE bl.student_id IN ($in)
     AND bl.`timestamp` BETWEEN ? AND ?
   GROUP BY bl.student_id
";
$st = $conn->prepare($sqlStats);

// Bind dynamic: ids (i...), startUtc (s), endUtc (s)
$types = str_repeat('i', count($ids)) . 'ss';
$params = array_merge($ids, [ $startUtc, $endUtc ]);
$bind = [];
$bind[] = & $types;
foreach ($params as $k => $v) { $bind[] = & $params[$k]; }
call_user_func_array([$st, 'bind_param'], $bind);

$st->execute();
$res = $st->get_result();

while ($row = $res->fetch_assoc()) {
  $sid = (int)$row['student_id'];
  if (!isset($students[$sid])) continue;

  $logsCount = (int)$row['logs_count'];
  $firstAttendUtc = $row['first_attendance']; // may be null

  $attendanceLocal = null;
  $present = false;
  if ($firstAttendUtc !== null) {
    $dt = new DateTime($firstAttendUtc, $UTC_TZ);
    $dt->setTimezone($APP_TZ);
    $attendanceLocal = $dt->format('Y-m-d H:i:s');
    $present = true;
  }

  $students[$sid]['today'] = [
    'date'             => $dateStr,
    'present'          => $present,
    'attendance_time'  => $attendanceLocal, // local Asia/Manila if present
    'logs_count'       => $logsCount,
  ];
}

$st->close();

// ---- Output list (preserve original order by id list) ----
$out = array_values($students);
echo json_encode($out, JSON_UNESCAPED_UNICODE);
