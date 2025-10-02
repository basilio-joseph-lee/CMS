<?php
// Strict, timezone-safe behavior log fetch
header('Content-Type: application/json');

// ---------------- DB ----------------
$mysqli = new mysqli("localhost","root","","cms");
if ($mysqli->connect_error) {
  http_response_code(500);
  echo json_encode(['status'=>'error','message'=>'DB connection failed']);
  exit;
}

// ---------------- INPUTS ----------------
$student_id = intval($_POST['student_id'] ?? 0);
$sinceIso   = $_POST['since'] ?? null;  // optional ISO string from app
$dateStr    = $_POST['date']  ?? null;  // optional 'YYYY-MM-DD' exact day

// ⚠️ CHANGE THIS if you want a different local time zone for "Today/Yesterday".
$APP_TZ = new DateTimeZone('Asia/Manila');
$UTC_TZ = new DateTimeZone('UTC');

if ($student_id <= 0) {
  echo json_encode(['status'=>'error','message'=>'student_id required']);
  exit;
}

// Helper: build UTC range from a local date (00:00:00..23:59:59 in local tz)
function localDayRangeToUtc(string $ymd, DateTimeZone $localTz, DateTimeZone $utcTz): array {
  $startLocal = DateTime::createFromFormat('Y-m-d H:i:s', $ymd.' 00:00:00', $localTz);
  $endLocal   = DateTime::createFromFormat('Y-m-d H:i:s', $ymd.' 23:59:59', $localTz);
  $startUtc = clone $startLocal; $startUtc->setTimezone($utcTz);
  $endUtc   = clone $endLocal;   $endUtc->setTimezone($utcTz);
  return [$startUtc->format('Y-m-d H:i:s'), $endUtc->format('Y-m-d H:i:s')];
}

// ---------------- QUERY BUILDING ----------------
// We’ll return DESC timestamp, de-duped by (action_type,timestamp).
$params = [$student_id];
$where  = " WHERE student_id = ? ";
$order  = " ORDER BY timestamp DESC ";
$limit  = " "; // add LIMIT if you want (e.g., 'LIMIT 200')

// 1) If a specific date is provided → STRICT day filter (local tz)
if ($dateStr) {
  // Validate y-m-d
  $dt = DateTime::createFromFormat('Y-m-d', $dateStr, $APP_TZ);
  if (!$dt) {
    echo json_encode(['status'=>'error','message'=>'invalid date']);
    exit;
  }
  [$utcStart, $utcEnd] = localDayRangeToUtc($dt->format('Y-m-d'), $APP_TZ, $UTC_TZ);
  $where .= " AND timestamp BETWEEN ? AND ? ";
  $params[] = $utcStart;
  $params[] = $utcEnd;

// 2) Else if incremental polling is requested → since (treat input as local time, convert to UTC)
} else if ($sinceIso) {
  // Parse since; accept ISO with/without timezone
  try {
    // Try parse with DateTime; if no TZ in string treat as local app TZ then convert to UTC
    $since = new DateTime($sinceIso);
    if ($since->getTimezone()->getName() === '+00:00' || $sinceIso[strlen($sinceIso)-1] === 'Z') {
      // already UTC
    } else {
      // assume local device time → convert to UTC
      $since->setTimezone($UTC_TZ);
    }
    $where .= " AND timestamp >= ? ";
    $params[] = $since->format('Y-m-d H:i:s');
  } catch (Exception $e) {
    // fallback: treat as today local range
    $todayLocal = new DateTime('now', $APP_TZ);
    [$utcStart, $utcEnd] = localDayRangeToUtc($todayLocal->format('Y-m-d'), $APP_TZ, $UTC_TZ);
    $where .= " AND timestamp BETWEEN ? AND ? ";
    $params[] = $utcStart;
    $params[] = $utcEnd;
  }

// 3) Else (first load with no filters) → default to "TODAY" local range
} else {
  $todayLocal = new DateTime('now', $APP_TZ);
  [$utcStart, $utcEnd] = localDayRangeToUtc($todayLocal->format('Y-m-d'), $APP_TZ, $UTC_TZ);
  $where .= " AND timestamp BETWEEN ? AND ? ";
  $params[] = $utcStart;
  $params[] = $utcEnd;
}

$sql = "SELECT action_type, timestamp FROM behavior_logs $where $order $limit";
$stmt = $mysqli->prepare($sql);

// dynamic bind
$types = '';
foreach ($params as $i => $_) $types .= ($i === 0 ? 'i' : 's');
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

// de-dupe and output
$out = [];
$seen = [];
while ($row = $res->fetch_assoc()) {
  $k = $row['action_type'].'|'.$row['timestamp'];
  if (isset($seen[$k])) continue;
  $seen[$k] = true;
  $out[] = [
    'action_type' => $row['action_type'],
    'timestamp'   => $row['timestamp'], // keep UTC; app formats to local
  ];
}

echo json_encode($out);
