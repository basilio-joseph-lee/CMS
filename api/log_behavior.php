<?php
header('Content-Type: application/json');
require_once "../config/db.php"; // adjust path if needed

function json_fail($msg, $code = 400){
  http_response_code($code);
  echo json_encode(["ok"=>false, "message"=>$msg], JSON_UNESCAPED_UNICODE);
  exit;
}

$raw = file_get_contents("php://input");
if ($raw === false) json_fail("No input");

$payload = json_decode($raw, true);
if (!is_array($payload)) json_fail("Invalid JSON body");

$student_id  = isset($payload['student_id']) ? intval($payload['student_id']) : 0;
$action_type = $payload['action_type'] ?? ($payload['action'] ?? null);

/* Normalize / map synonyms */
if ($action_type === 'out') $action_type = 'out_time';

$ALLOWED = [
  'attendance','restroom','snack','lunch_break','water_break',
  'not_well','borrow_book','return_material',
  'participated','help_request','out_time'
];

if ($student_id <= 0) json_fail("Invalid student_id");
if (!$action_type || !in_array($action_type, $ALLOWED, true)) {
  json_fail("Invalid action_type. Allowed: ".implode(", ", $ALLOWED));
}

try {
  $stmt = $conn->prepare("INSERT INTO behavior_logs (student_id, action_type) VALUES (?, ?)");
  if (!$stmt) json_fail("DB prepare failed: ".$conn->error, 500);

  $stmt->bind_param("is", $student_id, $action_type);
  if (!$stmt->execute()) json_fail("DB insert error: ".$conn->error, 500);
  $stmt->close();

  echo json_encode(["ok"=>true, "message"=>"Logged", "action_type"=>$action_type], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  json_fail("DB error: ".$e->getMessage(), 500);
}
