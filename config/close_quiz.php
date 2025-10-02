<?php
// /CMS/config/close_quiz.php
session_start();

$ajax = isset($_POST['ajax']) || isset($_GET['ajax']);
if ($ajax) header('Content-Type: application/json');

if (!isset($_SESSION['teacher_id'])) {
  if ($ajax) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
  header("Location: ../user/teacher_login.php"); exit;
}

$session_id = (int)($_POST['session_id'] ?? $_GET['session_id'] ?? 0);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
  $db = new mysqli("localhost","root","","cms");
  $db->set_charset('utf8mb4');

  if ($session_id > 0) {
    $db->begin_transaction();

    // 1) Mark session Ended
    $q = $db->prepare("UPDATE kiosk_quiz_sessions SET status='ended', ended_at=NOW() WHERE session_id=?");
    $q->bind_param('i', $session_id);
    $q->execute();

    // 2) Clear any active flags (use NULL — avoids unique collisions on (subject/advisory/sy/active_flag))
    $q = $db->prepare("UPDATE kiosk_quiz_questions SET active_flag=NULL WHERE session_id=?");
    $q->bind_param('i', $session_id);
    $q->execute();

    // 3) Remove the active pointer for this session
    $q = $db->prepare("DELETE FROM kiosk_quiz_active WHERE session_id=?");
    $q->bind_param('i', $session_id);
    $q->execute();

    $db->commit();
  }

  if ($ajax) echo json_encode(['success'=>true,'session_id'=>$session_id,'status'=>'ended']);
  else       header("Location: ../user/teacher/quiz_dashboard.php");
} catch (Throwable $e) {
  if (isset($db)) { try { $db->rollback(); } catch (\Throwable $ignored) {} }
  if ($ajax) echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
  else       header("Location: ../user/teacher/quiz_dashboard.php");
}
