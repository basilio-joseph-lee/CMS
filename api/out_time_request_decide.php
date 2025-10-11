<?php
// /api/out_time_request_decide.php
// Approve/Deny a student's Out-Time request (teacher only)

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/db.php'; // provides $conn (mysqli)

function fail($msg, $code=400){
  http_response_code($code);
  echo json_encode(['ok'=>false, 'message'=>$msg], JSON_UNESCAPED_UNICODE);
  exit;
}

if (!isset($_SESSION['teacher_id'])) fail('Teacher not logged in', 401);
$teacher_id = (int)$_SESSION['teacher_id'];

// Read JSON body
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) $body = $_POST;

$id     = isset($body['id']) ? (int)$body['id'] : 0;
$action = strtolower(trim((string)($body['action'] ?? '')));
$note   = trim((string)($body['note'] ?? ''));

if ($id <= 0)               fail('Missing/invalid request id');
if (!in_array($action, ['approve','deny'], true)) fail('Action must be approve or deny');

$conn->set_charset('utf8mb4');

// 1) Load the request and verify the teacher has authority over it
$sql = "
  SELECT r.id, r.student_id, r.subject_id, r.advisory_id, r.status, r.requested_at
  FROM out_time_requests r
  WHERE r.id = ? 
    AND r.status = 'pending'
    AND (
      r.advisory_id IN (SELECT advisory_id FROM subjects WHERE teacher_id = ?)
      OR r.subject_id IN (SELECT subject_id FROM subjects WHERE teacher_id = ?)
    )
  LIMIT 1
";
$stmt = $conn->prepare($sql);
if (!$stmt) fail('Prepare failed: '.$conn->error, 500);
$stmt->bind_param('iii', $id, $teacher_id, $teacher_id);
$stmt->execute();
$res = $stmt->get_result();
$req = $res->fetch_assoc();
$stmt->close();

if (!$req) fail('Request not found, not pending, or not under your sections/subjects', 404);

$student_id = (int)$req['student_id'];

$conn->begin_transaction();

try {
  // 2) Update decision
  $new_status = ($action === 'approve') ? 'approved' : 'denied';
  $sqlU = "UPDATE out_time_requests
           SET status = ?, decided_at = NOW(), decided_by_teacher_id = ?, note = NULLIF(?, '')
           WHERE id = ? AND status = 'pending'";
  $stmtU = $conn->prepare($sqlU);
  if (!$stmtU) throw new Exception('Prepare update failed: '.$conn->error);
  $stmtU->bind_param('sisi', $new_status, $teacher_id, $note, $id);
  $stmtU->execute();
  if ($stmtU->affected_rows !== 1) {
    // Someone else may have decided already
    throw new Exception('Request already decided or does not exist.');
  }
  $stmtU->close();

  // 3) If approved, log to behavior_logs as out_time (optional but useful)
  if ($new_status === 'approved') {
    $stmtB = $conn->prepare("INSERT INTO behavior_logs (student_id, action_type, timestamp) VALUES (?, 'out_time', NOW())");
    if ($stmtB) {
      $stmtB->bind_param('i', $student_id);
      $stmtB->execute();
      $stmtB->close();
    }
    // If you want to trigger SMS here, you can call your bulk endpoint or reuse your sender,
    // but keeping this file as a pure decision endpoint is usually cleaner.
  }

  $conn->commit();

  echo json_encode([
    'ok'        => true,
    'id'        => $id,
    'student_id'=> $student_id,
    'status'    => $new_status
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  $conn->rollback();
  fail('DB error: '.$e->getMessage(), 500);
}
