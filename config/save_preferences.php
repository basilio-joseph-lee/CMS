<?php
// ../../api/save_preferences.php
session_start();
require_once "../../config/db.php"; // $pdo or mysqli—update code accordingly

header('Content-Type: application/json');

if (!isset($_SESSION['teacher_id'])) { http_response_code(403); echo json_encode(['ok'=>false,'message'=>'Forbidden']); exit; }
if (($_SERVER['HTTP_X_CSRF'] ?? '') !== ($_SESSION['csrf'] ?? '')) { http_response_code(419); echo json_encode(['ok'=>false,'message'=>'CSRF']); exit; }

$data  = json_decode(file_get_contents('php://input'), true) ?: [];
$theme = substr($data['theme'] ?? 'classic', 0, 32);
$shape = substr($data['shape'] ?? 'classic', 0, 32);

$sy = intval($_SESSION['school_year_id']);
$ad = intval($_SESSION['advisory_id']);
$sj = intval($_SESSION['subject_id']);

/* Example for PDO */
try {
  // Make sure this table exists:
  // CREATE TABLE IF NOT EXISTS class_preferences(
  //   sy_id INT, advisory_id INT, subject_id INT,
  //   theme VARCHAR(32), shape VARCHAR(32),
  //   PRIMARY KEY (sy_id, advisory_id, subject_id)
  // );
  $stmt = $pdo->prepare("REPLACE INTO class_preferences (sy_id, advisory_id, subject_id, theme, shape) VALUES (?,?,?,?,?)");
  $stmt->execute([$sy,$ad,$sj,$theme,$shape]);
  echo json_encode(['ok'=>true]);
} catch(Exception $e){
  http_response_code(500);
  echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);
}
