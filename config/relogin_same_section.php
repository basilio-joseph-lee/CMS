<?php
session_start();

// Clear only student-specific login data
unset($_SESSION['student_id']);
unset($_SESSION['user']);

// Redirect back to face login
header("Location: ../user/face_login.php");
exit;
?>
