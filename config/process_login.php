<?php
session_start();

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  $_SESSION['failed'] = 'Invalid request.';
  header('Location: ../index.php');
  exit;
}

// Get submitted credentials
$username = trim($_POST['username'] ?? '');
$password = trim($_POST['password'] ?? '');

// DB connection
include("db.php");
if ($conn->connect_error) {
  $_SESSION['failed'] = 'Database connection failed.';
  header('Location: ../index.php');
  exit;
}

// Query teacher credentials first
$stmt = $conn->prepare("SELECT * FROM teachers WHERE username = ? LIMIT 1");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $teacher = $result->fetch_assoc();

    if (password_verify($password, $teacher['password'])) {
        session_regenerate_id(true);
        $_SESSION['teacher_id'] = $teacher['teacher_id'];
        $_SESSION['teacher_fullname'] = $teacher['fullname'];
        $_SESSION['role'] = 'TEACHER';
        header("Location: ../user/teacher/select_subject.php");
        exit;
    } else {
        $_SESSION['failed'] = 'Incorrect password.';
    }
} else {
    // If not a teacher, try admin
    $stmt = $conn->prepare("SELECT * FROM admins WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 1) {
        $admin = $res->fetch_assoc();

        if (password_verify($password, $admin['password'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = $admin['admin_id'];
            $_SESSION['admin_fullname'] = $admin['fullname'];
            $_SESSION['role'] = 'ADMIN';
            header("Location: ../admin/admin.php");
            exit;
        } else {
            $_SESSION['failed'] = 'Incorrect password.';
        }
    } else {
        $_SESSION['failed'] = 'Account not found.';
    }
}

// Redirect back to login
header('Location: ../index.php');
exit;
