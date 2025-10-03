<?php
// config/student_guard.php
// Protects student-only pages from bypass access

ob_start();           // avoid "headers already sent"
session_start();

// Must be logged in as STUDENT and have a valid student_id
$role = $_SESSION['role'] ?? null;
if ($role !== 'STUDENT' || !isset($_SESSION['student_id'])) {
    // If a TEACHER accidentally hits a student page, send them to teacher area
    if ($role === 'TEACHER' && isset($_SESSION['teacher_id'])) {
        header('Location: /CMS/user/teacher/select_subject.php');
        exit;
    }
    // Otherwise, go to login
    header('Location: /CMS/index.php');
    exit;
}

/*
 * (Optional) Require subject context before entering certain pages.
 * Enable per-page by defining STUDENT_REQUIRE_CONTEXT = true *before* including this file:
 *
 *   <?php define('STUDENT_REQUIRE_CONTEXT', true); include __DIR__.'/student_guard.php';
 */
if (defined('STUDENT_REQUIRE_CONTEXT') && STUDENT_REQUIRE_CONTEXT === true) {
    $need = ['subject_id','advisory_id','school_year_id'];
    foreach ($need as $k) {
        if (!isset($_SESSION[$k])) {
            header('Location: /CMS/select_subject.php');
            exit;
        }
    }
}
