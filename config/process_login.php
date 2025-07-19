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
$subject_id = $_POST['subject_id'];
$subject_name = $_POST['subject_name'];
$class_name = $_POST['class_name'];
$year_label = $_POST['year_label'];

// Sanitize and prepare
$stmt = $conn->prepare("SELECT * FROM teachers WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $teacher = $result->fetch_assoc();

    // For testing: plain text password check (replace with password_verify for hashed pw)
    if ($password === $teacher['password']) {

        // Check if the teacher is assigned to the selected subject
        $check = $conn->prepare("SELECT * FROM subjects WHERE subject_id = ? AND teacher_id = ?");
        $check->bind_param("ii", $subject_id, $teacher['teacher_id']);
        $check->execute();
        $subjectResult = $check->get_result();

        if ($subjectResult->num_rows > 0) {
            $_SESSION['teacher_id'] = $teacher['teacher_id'];
            $_SESSION['fullname'] = $teacher['fullname'];
            $_SESSION['subject_id'] = $subject_id;
            $_SESSION['subject_name'] = $subject_name;
            $_SESSION['class_name'] = $class_name;
            $_SESSION['year_label'] = $year_label;

            // Get advisory_id and school_year_id from the subject
            $subjQuery = $conn->prepare("SELECT advisory_id, school_year_id FROM subjects WHERE subject_id = ?");
            $subjQuery->bind_param("i", $subject_id);
            $subjQuery->execute();
            $subjResult = $subjQuery->get_result();
            if ($subj = $subjResult->fetch_assoc()) {
                $_SESSION['advisory_id'] = $subj['advisory_id'];
                $_SESSION['school_year_id'] = $subj['school_year_id'];
            }

            header("Location: ../user/teacher_dashboard.php");
            exit;
        } else {
            $_SESSION['error'] = "⚠️ You aren't registered in the selected subject.";
            header("Location: ../user/teacher_login.php");
            exit;
        }
    } else {
        $_SESSION['failed'] = "❌ Incorrect password.";
        header("Location: ../user/teacher_login.php");
        exit;
    }
}

// Invalid login
$_SESSION['failed'] = "❌ Invalid login credentials.";
header("Location: ../user/teacher_login.php");
exit;
