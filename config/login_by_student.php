<?php
// /config/login_by_student.php
// Purpose: create a student session after face verification
// Effects: sets $_SESSION['student_id'], $_SESSION['student_fullname'], $_SESSION['fullname'], $_SESSION['role']='STUDENT'

/**
 * IMPORTANT:
 * Do NOT change the session name here.
 * Using a custom session name caused a different cookie than the rest of the site,
 * so pages like /user/teacher/select_subject.php could not see the student session.
 */
session_start();

header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Only accept POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

$student_id = (int)($_POST['student_id'] ?? 0);
if ($student_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing student_id']);
    exit;
}

// DB connect (this file is in /config, so db.php is peer)
require_once __DIR__ . '/db.php';
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'DB connection failed']);
    exit;
}
$conn->set_charset('utf8mb4');

// Verify student exists
$stmt = $conn->prepare("
    SELECT student_id, fullname, gender, avatar_path, face_image_path
    FROM students
    WHERE student_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$res = $stmt->get_result();

if ($res && $res->num_rows === 1) {
    $row = $res->fetch_assoc();
    $stmt->close();

    // Regenerate session id for security
    session_regenerate_id(true);

    // 🔒 Purge any TEACHER / ADMIN leftovers so guards won't bounce us
    unset(
        $_SESSION['teacher_id'], $_SESSION['teacher_fullname'], $_SESSION['teacher_name'],
        $_SESSION['admin_id'],   $_SESSION['admin_fullname']
    );
    if (isset($_SESSION['role']) && $_SESSION['role'] !== 'STUDENT') {
        unset($_SESSION['role']);
    }

    // ✅ Set STUDENT session (keys used by your pages)
    $_SESSION['student_id']       = (int)$row['student_id'];
    $_SESSION['student_fullname'] = (string)$row['fullname'];
    $_SESSION['fullname']         = (string)$row['fullname'];  // some pages expect this
    $_SESSION['avatar_path']      = (string)($row['avatar_path'] ?? '');
    $_SESSION['face_image']       = (string)($row['face_image_path'] ?? '');
    $_SESSION['role']             = 'STUDENT';

    // Ensure the session cookie is valid for the whole site (path '/')
    // This re-sets the cookie with the current id so /user/* can read it.
    setcookie(session_name(), session_id(), [
        'expires'  => 0,
        'path'     => '/',                         // critical: site-wide
        'secure'   => !empty($_SERVER['HTTPS']),   // true on HTTPS
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    // Make sure session is written before the frontend redirects
    session_write_close();

    echo json_encode([
        'success'     => true,
        'student_id'  => $_SESSION['student_id'],
        'studentName' => $_SESSION['student_fullname'],
    ]);
    exit;
}

if ($stmt) { $stmt->close(); }
echo json_encode(['success' => false, 'error' => 'Student not found']);
exit;
