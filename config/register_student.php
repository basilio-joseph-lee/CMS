<?php
session_start();
if (!isset($_SESSION['teacher_id'])) {
  header("Location: teacher_login.php");
  exit;
}

$teacherId = $_SESSION['teacher_id'];
$teacherName = $_SESSION['fullname'];

// Connect to DB
$conn = new mysqli("localhost", "root", "", "cms");
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $fullname = $_POST['fullname'] ?? '';
  $gender = $_POST['gender'] ?? '';
  $school_year_id = $_POST['school_year_id'] ?? '';
  $advisory_id = $_POST['advisory_id'] ?? '';
  $subject_id = $_POST['subject_id'] ?? '';
  $avatarPath = '../img/avatar_placeholder.png'; // Placeholder path for now

  if ($fullname && $gender && $school_year_id && $advisory_id && $subject_id) {
    // Insert student
    $stmt = $conn->prepare("INSERT INTO students (fullname, gender, avatar, school_year_id) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("sssi", $fullname, $gender, $avatarPath, $school_year_id);
    if ($stmt->execute()) {
      $student_id = $stmt->insert_id;
      $stmt->close();

      // Enroll student to advisory class and subject
      $stmt = $conn->prepare("INSERT INTO student_enrollments (student_id, advisory_id, subject_id) VALUES (?, ?, ?)");
      $stmt->bind_param("iii", $student_id, $advisory_id, $subject_id);
      if ($stmt->execute()) {
        header("Location: ../user/view_students.php?success=1");
        exit;
      } else {
        echo "Error: " . $stmt->error;
      }
      $stmt->close();
    } else {
      echo "Error: " . $stmt->error;
    }
  } else {
    echo "Missing required fields.";
  }
}

$conn->close();
?>
