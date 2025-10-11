<?php
include __DIR__ . '/../config/db.php';      // starts the session + $conn
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

ob_start();

function json_fail($msg, $code = 400){
  http_response_code($code);
  echo json_encode(["ok"=>false, "message"=>$msg], JSON_UNESCAPED_UNICODE);
  exit;
}

$raw = file_get_contents("php://input");
if ($raw === false) json_fail("No input");

$payload = json_decode($raw, true);
if (!is_array($payload)) json_fail("Invalid JSON body");

$action = $payload['action_type'] ?? ($payload['action'] ?? null);
$student_ids = $payload['student_ids'] ?? null;

/* Normalize / map synonyms */
if ($action === 'out') $action = 'out_time';

$ALLOWED = [
  'attendance','restroom','snack','lunch_break','water_break',
  'not_well','borrow_book','return_material',
  'participated','help_request','out_time'
];

if (!$action || !in_array($action, $ALLOWED, true)) {
  json_fail("Invalid action_type. Allowed: ".implode(", ", $ALLOWED));
}
if (!is_array($student_ids) || count($student_ids) === 0) {
  json_fail("student_ids must be a non-empty array");
}

/** Helper: normalize PH numbers to E.164 63XXXXXXXXXX; return null if invalid */
function normalizePH($msisdn){
  $s = trim((string)$msisdn);
  if ($s === '') return null;
  // keep + for detection then strip non-digits
  $hasPlus = str_starts_with($s, '+');
  $digits  = preg_replace('/\D+/', '', $s);

  // +63XXXXXXXXXX or 63XXXXXXXXXX (12 digits)
  if ((($hasPlus && str_starts_with($s, '+63')) || str_starts_with($digits,'63')) && strlen($digits) === 12) {
    return '63' . substr($digits, 2);
  }
  // 09XXXXXXXXX (11 digits) -> 63XXXXXXXXXX
  if (str_starts_with($digits, '0') && strlen($digits) === 11) {
    return '63' . substr($digits, 1);
  }
  // 9XXXXXXXXX (10 digits) -> 63XXXXXXXXXX
  if (strlen($digits) === 10 && str_starts_with($digits,'9')) {
    return '63' . $digits;
  }
  return null;
}

try {
  $conn->begin_transaction();

  // 1) Insert behavior logs (same as your version)
  $stmt = $conn->prepare("INSERT INTO behavior_logs (student_id, action_type) VALUES (?, ?)");
  if (!$stmt) {
    $conn->rollback();
    json_fail("DB prepare failed: ".$conn->error, 500);
  }

  $inserted = 0;
  foreach ($student_ids as $sid) {
    $sid = intval($sid);
    $stmt->bind_param("is", $sid, $action);
    if (!$stmt->execute()) {
      $stmt->close();
      $conn->rollback();
      json_fail("DB insert error: ".$conn->error, 500);
    }
    $inserted++;
  }
  $stmt->close();

  // 2) If OUT TIME: fetch parents + numbers (no SMS yet — just preview)
  $recipients = [];
  if ($action === 'out_time') {
    // Safe IN (...) with placeholders
    $ids = array_map('intval', $student_ids);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $sql = "
      SELECT s.student_id,
             s.fullname       AS student_name,
             p.parent_id,
             p.fullname       AS parent_name,
             p.mobile_number  AS parent_mobile
      FROM students s
      LEFT JOIN parents p ON p.parent_id = s.parent_id
      WHERE s.student_id IN ($placeholders)
    ";
    $tp = str_repeat('i', count($ids));
    $stmt2 = $conn->prepare($sql);
    if (!$stmt2) {
      $conn->rollback();
      json_fail("DB prepare (join) failed: ".$conn->error, 500);
    }
    // bind the dynamic IN list
    $stmt2->bind_param($tp, ...$ids);
    $stmt2->execute();
    $res = $stmt2->get_result();

    while ($row = $res->fetch_assoc()) {
      $raw = $row['parent_mobile'] ?? '';
      $norm = normalizePH($raw);
      $recipients[] = [
        "student_id"   => (int)$row['student_id'],
        "student_name" => $row['student_name'],
        "parent_id"    => $row['parent_id'] ? (int)$row['parent_id'] : null,
        "parent_name"  => $row['parent_name'] ?? null,
        "mobile_raw"   => $raw,
        "mobile_e164"  => $norm,            // null if invalid/unavailable
        "can_notify"   => $norm !== null
      ];
    }
    $stmt2->close();
  }

  $conn->commit();

  echo json_encode([
    "ok"        => true,
    "inserted"  => $inserted,
    "action_type" => $action,
    // only present for out_time previews:
    "recipients_preview" => $recipients
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  if ($conn && $conn->errno) { @ $conn->rollback(); }
  json_fail("DB error: ".$e->getMessage(), 500);
}
