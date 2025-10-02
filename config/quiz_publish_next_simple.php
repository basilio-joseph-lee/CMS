<?php
// config/quiz_publish_next_simple.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['teacher_id'])) {
  echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit;
}

$subject_id     = (int)$_SESSION['subject_id'];
$advisory_id    = (int)$_SESSION['advisory_id'];
$school_year_id = (int)$_SESSION['school_year_id'];

$db = new mysqli('localhost','root','','cms');
$db->set_charset('utf8mb4');

// close any published
$db->query("UPDATE kiosk_quiz_questions 
              SET status='closed' 
            WHERE status='published' 
              AND subject_id=$subject_id 
              AND advisory_id=$advisory_id 
              AND school_year_id=$school_year_id");

// publish the oldest draft
$res = $db->query("SELECT question_id FROM kiosk_quiz_questions 
                    WHERE status='draft' 
                      AND subject_id=$subject_id 
                      AND advisory_id=$advisory_id 
                      AND school_year_id=$school_year_id
                    ORDER BY question_id ASC LIMIT 1");
if($row=$res->fetch_assoc()){
  $qid=(int)$row['question_id'];
  $db->query("UPDATE kiosk_quiz_questions SET status='published', published_at=NOW() WHERE question_id=$qid");
  echo json_encode(['success'=>true,'message'=>"Published question #$qid"]);
}else{
  echo json_encode(['success'=>false,'message'=>'No more drafts']);
}
