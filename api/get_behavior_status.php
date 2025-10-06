<?php
// api/get_behavior_status.php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (!isset($_SESSION['teacher_id'])) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'message'=>'Forbidden']);
  exit;
}

include __DIR__ . '/../config/db.php';
if ($conn->connect_error) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'message'=>'DB error']);
  exit;
}

/* Map any “*_request” to the base name so the UI can treat them the same */
function normalize_action($a) {
  $a = strtolower(trim($a));
  $a = str_replace(['-',' '],'_',$a);
  if (str_ends_with($a, '_request')) $a = substr($a, 0, -8);
  return $a;
}
function label_for($a) {
  switch ($a) {
    case 'restroom':        return '🚻 Restroom';
    case 'snack':           return '🍎 Snack';
    case 'water_break':     return '💧 Water Break';
    case 'lunch_break':     return '🍱 Lunch Break';
    case 'not_well':        return '🤒 Not Feeling Well';
    case 'borrow_book':     return '📚 Borrowing Book';
    case 'return_material': return '📦 Returning Material';
    case 'help_request':    return '✋ Needs Help';
    case 'participated':    return '✅ Participated';
    case 'attendance':      return '✅ In';
    case 'log_out':         return '🚪 Logged out';
    default:                return ucfirst(str_replace('_',' ',$a));
  }
}
$away_set = [
  'restroom','snack','water_break','lunch_break',
  'not_well','borrow_book','return_material','log_out'
];

/* Latest behavior row per student */
$sql = "
  SELECT bl.student_id, bl.action_type, bl.timestamp
  FROM behavior_logs bl
  INNER JOIN (
    SELECT student_id, MAX(timestamp) ts
    FROM behavior_logs
    GROUP BY student_id
  ) last
  ON last.student_id = bl.student_id AND last.ts = bl.timestamp
";
$res = $conn->query($sql);

$map = [];
$rows = 0;
while ($row = $res->fetch_assoc()) {
  $rows++;
  $act = normalize_action($row['action_type']);
  $map[(string)$row['student_id']] = [
    'action'    => $act,
    'label'     => label_for($act),
    'is_away'   => in_array($act, $away_set, true),
    'timestamp' => $row['timestamp']
  ];
}

echo json_encode(['ok'=>true, 'map'=>$map, 'count'=>$rows]);
