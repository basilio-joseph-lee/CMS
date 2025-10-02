<?php
session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['teacher_id'])) { echo json_encode(['success'=>false]); exit; }

$subject_id     = (int)$_SESSION['subject_id'];
$advisory_id    = (int)$_SESSION['advisory_id'];
$school_year_id = (int)$_SESSION['school_year_id'];

$db = new mysqli('localhost','root','','cms');
$db->set_charset('utf8mb4');

// close all published
$db->query("UPDATE kiosk_quiz_questions 
              SET status='closed' 
            WHERE status='published' 
              AND subject_id=$subject_id 
              AND advisory_id=$advisory_id 
              AND school_year_id=$school_year_id");

echo json_encode(['success'=>true,'message'=>'Quiz closed']);
