<?php
// config/teacher_guard.php
// Protects teacher-only pages from bypass access

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verify logged in and role is TEACHER
if (!isset($_SESSION['teacher_id']) || ($_SESSION['role'] ?? '') !== 'TEACHER') {
    // Wipe out any invalid session
    session_unset();
    session_destroy();

    // Redirect to login
    header("Location: /CMS/index.php");
    exit;
}
