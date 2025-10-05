<?php
include __DIR__ . '/../config/db.php';      // starts the session (once) + DB
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

ob_start();                                  // buffer any accidental output

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

try {
  $conn->begin_transaction();

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
  $conn->commit();

  echo json_encode(["ok"=>true, "inserted"=>$inserted, "action_type"=>$action], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  if ($conn && $conn->errno) { @ $conn->rollback(); }
  json_fail("DB error: ".$e->getMessage(), 500);
}
