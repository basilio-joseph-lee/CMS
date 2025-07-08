<?php
session_start();

// Database config
$host = "localhost";
$dbname = "cms";
$db_user = "root";
$db_pass = "";

// Connect to DB
$conn = new mysqli($host, $db_user, $db_pass, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get form data
$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';

// Sanitize and prepare
$stmt = $conn->prepare("SELECT * FROM teachers WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $teacher = $result->fetch_assoc();

    // For testing: plain text password check (no hash)
    if ($password === $teacher['password']) {
        $_SESSION['teacher_id'] = $teacher['teacher_id'];
        $_SESSION['fullname'] = $teacher['fullname'];
        header("Location: ../user/teacher_dashboard.php");
        exit;
    }
}

// Invalid login
header("Location: ../user/teacher_login.php?error=1");
exit;
