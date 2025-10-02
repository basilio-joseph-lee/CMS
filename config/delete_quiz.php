<?php
// /CMS/config/delete_quiz.php
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

$stmt=$conn->prepare("DELETE FROM kiosk_quiz_questions WHERE quiz_id=? AND teacher_id=? AND subject_id=? AND advisory_id=? AND school_year_id=? AND status='draft'");
$stmt->bind_param("iiiii",$quiz_id,$teacher_id,$subject_id,$advisory_id,$school_year_id);
$stmt->execute();

header("Location: ../user/teacher/quiz_dashboard.php");
exit;
