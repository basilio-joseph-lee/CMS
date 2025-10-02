<?php
// /CMS/api/session_end.php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/../config/db.php';

$session_id = (int)($_POST['session_id'] ?? 0);
if(!$session_id){
  http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Missing']); exit;
}

$stmt = $conn->prepare("UPDATE behavior_sessions SET ended_at=NOW(), status='completed' WHERE id=? AND status='ongoing'");
$stmt->bind_param('i', $session_id);
$stmt->execute();

echo json_encode(['ok'=>true]);
