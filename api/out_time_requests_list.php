<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__.'/../config/db.php';

try {
  if (!isset($_SESSION['teacher_id'])) throw new Exception('Not logged in (teacher)');

  $sql = "SELECT r.id, r.student_id, s.fullname AS student_name, r.requested_at
          FROM out_time_requests r
          JOIN students s ON s.student_id = r.student_id
          WHERE r.status='pending'
          ORDER BY r.requested_at ASC";
  $res = $conn->query($sql);
  $rows = [];
  while ($row = $res->fetch_assoc()) $rows[] = $row;

  echo json_encode(['ok'=>true,'items'=>$rows]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);
}
