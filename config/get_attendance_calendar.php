<?php
header('Content-Type: application/json');

include("db.php");
if ($conn->connect_error) {
  http_response_code(500);
  echo json_encode(['status'=>'error','message'=>'DB connection failed']);
  exit;
}

$student_id = intval($_POST['student_id'] ?? 0);
$month = intval($_POST['month'] ?? 0);
$year  = intval($_POST['year'] ?? 0);
if ($student_id <= 0 || $month < 1 || $month > 12 || $year < 1970) {
  echo json_encode(['status'=>'error','message'=>'student_id, month (1-12), year required']);
  exit;
}

// ---- Timezones ----
$APP_TZ = new DateTimeZone('Asia/Manila');
$UTC_TZ = new DateTimeZone('UTC');

$firstDay = new DateTime(sprintf('%04d-%02d-01', $year, $month), $APP_TZ);
$lastDay  = (clone $firstDay)->modify('last day of this month');

// ---- Helper: read table columns ----
function table_cols(mysqli $db, string $table) {
  $cols = [];
  if ($res = $db->query("SHOW COLUMNS FROM `$table`")) {
    while ($r = $res->fetch_assoc()) $cols[] = $r['Field'];
  }
  return $cols;
}

// ---- Try to find a linking column that exists in BOTH tables ----
$studentsCols = table_cols($mysqli, 'students');
$stbCols      = table_cols($mysqli, 'schedule_timeblocks');

// Candidate names in order of likelihood
$candidates = [
  'class_id','section_id','class_section_id','section','grade_level_id','strand_id',
  'program_id','homeroom_id','room_id'
];

$linkCol = null;
foreach ($candidates as $c) {
  if (in_array($c, $studentsCols, true) && in_array($c, $stbCols, true)) {
    $linkCol = $c;
    break;
  }
}

// ---- Get the student's link value, if linkCol was found ----
$linkVal = null;
if ($linkCol) {
  $q = "SELECT `$linkCol` FROM `students` WHERE `student_id`=? LIMIT 1";
  if ($stmt = $mysqli->prepare($q)) {
    $stmt->bind_param("i", $student_id);
    $stmt->execute(); $stmt->bind_result($tmp);
    if ($stmt->fetch()) $linkVal = $tmp;
    $stmt->close();
  }
}

// ---- Determine which weekdays have class ----
// Prefer: schedule_timeblocks filtered by linkCol=value
// Else:   distinct weekdays from schedule_timeblocks (whole school)
// Else:   default Mon–Fri
$scheduledWeekdays = []; // ISO 1..7 (Mon..Sun)
if ($linkCol && $linkVal !== null) {
  $q = "SELECT DISTINCT `weekday` FROM `schedule_timeblocks` WHERE `$linkCol` = ?";
  if ($stmt = $mysqli->prepare($q)) {
    $stmt->bind_param("s", $linkVal);
    $stmt->execute(); $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
      $w = intval($row['weekday']);            // adjust here if your DB uses 0..6 with 0=Sun
      // if ($w === 0) $w = 7;  // uncomment if needed
      $scheduledWeekdays[$w] = true;
    }
    $stmt->close();
  }
}

if (empty($scheduledWeekdays)) {
  // Try global school schedule
  if ($res = $mysqli->query("SELECT DISTINCT `weekday` FROM `schedule_timeblocks`")) {
    while ($row = $res->fetch_assoc()) {
      $w = intval($row['weekday']);
      // if ($w === 0) $w = 7;
      $scheduledWeekdays[$w] = true;
    }
  }
}

if (empty($scheduledWeekdays)) {
  // Final fallback: Mon–Fri
  $scheduledWeekdays = [1=>true,2=>true,3=>true,4=>true,5=>true];
}

// ---- Pull attendance rows for the month (UTC timestamps in DB) ----
$startUtc = (clone $firstDay)->setTime(0,0,0)->setTimezone($UTC_TZ)->format('Y-m-d H:i:s');
$endUtc   = (clone $lastDay)->setTime(23,59,59)->setTimezone($UTC_TZ)->format('Y-m-d H:i:s');

$q = "SELECT `timestamp`, `status` FROM `attendance_records`
      WHERE `student_id`=? AND `timestamp` BETWEEN ? AND ?
      ORDER BY `timestamp` ASC";
$stmt = $mysqli->prepare($q);
$stmt->bind_param("iss", $student_id, $startUtc, $endUtc);
$stmt->execute();
$res = $stmt->get_result();

// Reduce to one status per LOCAL day with precedence
$prio = ['Present'=>1,'Late'=>2,'Absent'=>3];
$perDay = []; // 'Y-m-d' local => status
while ($row = $res->fetch_assoc()) {
  $tsUtc   = new DateTime($row['timestamp'], $UTC_TZ);
  $tsLocal = (clone $tsUtc)->setTimezone($APP_TZ);
  $key = $tsLocal->format('Y-m-d');
  $st  = $row['status'] ?? 'Present';
  if (!isset($perDay[$key]) || ($prio[$st] ?? 0) > ($prio[$perDay[$key]] ?? 0)) {
    $perDay[$key] = $st;
  }
}
$stmt->close();

// ---- Build days ----
$daysOut = [];
$counts = ['Present'=>0,'Absent'=>0,'Late'=>0,'No Class'=>0,'No Record'=>0];

$iter = clone $firstDay;
while ($iter <= $lastDay) {
  $dStr = $iter->format('Y-m-d');
  $isoW = intval($iter->format('N')); // 1..7 Mon..Sun
  $hasClass = isset($scheduledWeekdays[$isoW]);

  $status = $perDay[$dStr] ?? ($hasClass ? 'No Record' : 'No Class');
  if (isset($counts[$status])) $counts[$status]++;

  $daysOut[] = [
    'date'      => $dStr,
    'day'       => intval($iter->format('j')),
    'weekday'   => $iter->format('D'),
    'has_class' => $hasClass ? 1 : 0,
    'status'    => $status,
  ];

  $iter->modify('+1 day');
}

echo json_encode([
  'status'=>'success',
  'month'=>$month,
  'year'=>$year,
  'days'=>$daysOut,
  'counts'=>$counts,
  'link_key_used' => $linkCol,   // debug hint
  'link_value'    => $linkVal    // debug hint
]);
