<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__.'/../config/db.php';

try {
  if (!isset($_SESSION['teacher_id'])) throw new Exception('Not logged in (teacher)');
  $teacher_id = (int)$_SESSION['teacher_id'];

  $raw = file_get_contents('php://input');
  $x = json_decode($raw, true) ?: $_POST;
  $id = (int)($x['id'] ?? 0);
  $decision = strtolower(trim((string)($x['decision'] ?? '')));
  if (!in_array($decision,['approve','deny'],true)) throw new Exception('decision must be approve|deny');

  // get request + student id
  $r = $conn->prepare("SELECT student_id FROM out_time_requests WHERE id=? AND status='pending' LIMIT 1");
  $r->bind_param('i', $id);
  $r->execute();
  $res = $r->get_result();
  $row = $res->fetch_assoc();
  if (!$row) throw new Exception('Request not found or already handled');
  $student_id = (int)$row['student_id'];
  $r->close();

  // mark decision
  $newStatus = ($decision==='approve') ? 'approved' : 'denied';
  $u = $conn->prepare("UPDATE out_time_requests SET status=?, decided_at=NOW(), decided_by_teacher_id=? WHERE id=?");
  $u->bind_param('sii', $newStatus, $teacher_id, $id);
  if (!$u->execute()) throw new Exception($u->error);
  $u->close();

  // If approved → log behavior out_time (single)
  if ($newStatus==='approved') {
    $ins = $conn->prepare("INSERT INTO behavior_logs (student_id, action_type, timestamp) VALUES (?, 'out_time', NOW())");
    $ins->bind_param('i', $student_id);
    $ins->execute();
    $ins->close();

    // OPTIONAL: trigger SMS for this one student via bulk API (re-using your logic)
    // file_get_contents can be replaced with curl if needed
    // Make sure the URL is correct for your host:
    // @file_get_contents("https://myschoolness.site/api/log_behavior_bulk.php", false, stream_context_create([
    //   'http' => ['method'=>'POST','header'=>"Content-Type: application/json\r\n",
    //     'content'=> json_encode(['action_type'=>'out_time','student_ids'=>[$student_id],'send_sms'=>true])]
    // ]));
  }

  echo json_encode(['ok'=>true,'id'=>$id,'status'=>$newStatus,'student_id'=>$student_id]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);
}
