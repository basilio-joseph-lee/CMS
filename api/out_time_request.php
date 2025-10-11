<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__.'/../config/db.php';

try {
  if (!isset($_SESSION['student_id'])) throw new Exception('Not logged in (student)');
  $student_id  = (int)$_SESSION['student_id'];
  $subject_id  = (int)($_SESSION['subject_id'] ?? 0);
  $advisory_id = (int)($_SESSION['advisory_id'] ?? 0);

  // if there’s still a pending one, don’t duplicate
  $q = $conn->prepare("SELECT id FROM out_time_requests WHERE student_id=? AND status='pending' ORDER BY id DESC LIMIT 1");
  $q->bind_param('i', $student_id);
  $q->execute();
  $q->store_result();
  if ($q->num_rows > 0) { echo json_encode(['ok'=>true,'dup'=>true,'message'=>'Request already pending']); exit; }
  $q->close();

  $stmt = $conn->prepare("INSERT INTO out_time_requests (student_id, subject_id, advisory_id, status) VALUES (?,?,?, 'pending')");
  $stmt->bind_param('iii', $student_id, $subject_id, $advisory_id);
  if (!$stmt->execute()) throw new Exception($stmt->error);
  echo json_encode(['ok'=>true,'request_id'=>$stmt->insert_id]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);
}
