<?php
// /CMS/config/publish_quiz.php
session_start();
if (!isset($_SESSION['teacher_id'])) { die("Not logged in"); }

$teacher_id     = (int)$_SESSION['teacher_id'];
$subject_id     = (int)$_SESSION['subject_id'];
$advisory_id    = (int)$_SESSION['advisory_id'];
$school_year_id = (int)$_SESSION['school_year_id'];

$quiz_id = isset($_GET['quiz_id']) ? (int)$_GET['quiz_id'] : 0;

$conn = new mysqli("localhost","root","","cms");
$conn->set_charset('utf8mb4');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// fetch title
$stmt=$conn->prepare("SELECT MIN(title) as title FROM kiosk_quiz_questions 
    WHERE quiz_id=? AND teacher_id=? AND subject_id=? AND advisory_id=? AND school_year_id=?");
$stmt->bind_param("iiiii",$quiz_id,$teacher_id,$subject_id,$advisory_id,$school_year_id);
$stmt->execute();
$title=$stmt->get_result()->fetch_assoc()['title'] ?? 'Quick Quiz';

// generate code
$code=strtoupper(substr(md5(uniqid(mt_rand(),true)),0,6));

// create session
$stmt=$conn->prepare("INSERT INTO kiosk_quiz_sessions (teacher_id,subject_id,advisory_id,school_year_id,title,session_code,status,created_at) 
                      VALUES (?,?,?,?,?,?, 'active', NOW())");
$stmt->bind_param("iiiiss",$teacher_id,$subject_id,$advisory_id,$school_year_id,$title,$code);
$stmt->execute();
$session_id=$stmt->insert_id;

// update questions
$upd=$conn->prepare("UPDATE kiosk_quiz_questions SET status='published', published_at=NOW(), session_id=? 
    WHERE quiz_id=? AND teacher_id=? AND subject_id=? AND advisory_id=? AND school_year_id=?");
$upd->bind_param("iiiiii",$session_id,$quiz_id,$teacher_id,$subject_id,$advisory_id,$school_year_id);
$upd->execute();

// redirect back
header("Location: ../user/teacher/quiz_dashboard.php");
exit;
