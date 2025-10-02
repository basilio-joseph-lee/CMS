<?php
// /CMS/config/publish_all_quiz.php
session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['teacher_id'])) { echo json_encode(['success'=>false,'message'=>'Not logged in']); exit; }

$teacher_id     = (int)$_SESSION['teacher_id'];
$subject_id     = (int)$_SESSION['subject_id'];
$advisory_id    = (int)$_SESSION['advisory_id'];
$school_year_id = (int)$_SESSION['school_year_id'];

$conn = new mysqli("localhost","root","","cms");
$conn->set_charset('utf8mb4');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// 1. Find all distinct quiz_ids in draft
$q = $conn->prepare("SELECT DISTINCT quiz_id, MIN(title) as title 
    FROM kiosk_quiz_questions
    WHERE teacher_id=? AND subject_id=? AND advisory_id=? AND school_year_id=? AND status='draft'
    GROUP BY quiz_id");
$q->bind_param("iiii",$teacher_id,$subject_id,$advisory_id,$school_year_id);
$q->execute();
$res=$q->get_result();

$sessions=[];
while($row=$res->fetch_assoc()){
    $quiz_id=(int)$row['quiz_id'];
    $title=$row['title'] ?: 'Quick Quiz';

    // Generate a short join code
    $code = strtoupper(substr(md5(uniqid(mt_rand(),true)),0,6));

    // Create session
    $stmt=$conn->prepare("INSERT INTO kiosk_quiz_sessions (teacher_id,subject_id,advisory_id,school_year_id,title,session_code,status,created_at) 
                          VALUES (?,?,?,?,?,?, 'active', NOW())");
    $stmt->bind_param("iiiiss",$teacher_id,$subject_id,$advisory_id,$school_year_id,$title,$code);
    $stmt->execute();
    $session_id=$stmt->insert_id;

    // Update questions from draft->published, assign session_id
    $upd=$conn->prepare("UPDATE kiosk_quiz_questions 
        SET status='published', published_at=NOW(), session_id=? 
        WHERE teacher_id=? AND subject_id=? AND advisory_id=? AND school_year_id=? AND quiz_id=? AND status='draft'");
    $upd->bind_param("iiiiii",$session_id,$teacher_id,$subject_id,$advisory_id,$school_year_id,$quiz_id);
    $upd->execute();

    $sessions[]=[
        'session_id'=>$session_id,
        'title'=>$title,
        'session_code'=>$code,
        'status'=>'active'
    ];
}

echo json_encode(['success'=>true,'message'=>'Published '.count($sessions).' draft(s)','sessions'=>$sessions,'join_base'=>'../student/join_quiz.php?code=']);
