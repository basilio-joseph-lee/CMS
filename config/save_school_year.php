<?php
session_start();
if (!isset($_SESSION['teacher_id'])) {
  header("Location: ../user/teacher_login.php");
  exit;
}

$teacherId = $_SESSION['teacher_id'];

// Database connection
$conn = new mysqli("localhost", "root", "", "cms");
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

// Get data from POST
$schoolYearId = $_POST['school_year_id'] ?? '';
$className = trim($_POST['class_name'] ?? '');
$subjects = $_POST['subjects'] ?? [];

if ($schoolYearId === '' || empty($subjects)) {
  die("Missing school year or subjects.");
}

// If class name is provided, insert advisory class
$advisoryId = null;
if (!empty($className)) {
  $stmt = $conn->prepare("INSERT INTO advisory_classes (teacher_id, school_year_id, class_name) VALUES (?, ?, ?)");
  $stmt->bind_param("iis", $teacherId, $schoolYearId, $className);
  if ($stmt->execute()) {
    $advisoryId = $stmt->insert_id;
  }
  $stmt->close();
}

// Insert subjects
$stmt = $conn->prepare("INSERT INTO subjects (teacher_id, school_year_id, advisory_id, subject_name) VALUES (?, ?, ?, ?)");
foreach ($subjects as $subject) {
  $subject = trim($subject);
  if ($subject !== '') {
    $stmt->bind_param("iiis", $teacherId, $schoolYearId, $advisoryId, $subject);
    $stmt->execute();
  }
}
$stmt->close();

$conn->close();

header("Location: ../user/teacher_dashboard.php?success=1");
exit;
?>
