<?php
session_start();
if (!isset($_SESSION['teacher_id'])) {
  header("Location: teacher_login.php");
  exit;
}
$teacherId = $_SESSION['teacher_id'];
$teacherName = $_SESSION['fullname'];

// DB connect
$conn = new mysqli("localhost", "root", "", "cms");
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

// Get active school year
$schoolYearResult = $conn->query("SELECT * FROM school_years WHERE status = 'active' LIMIT 1");
$activeYear = $schoolYearResult->fetch_assoc();

// Get all admin-created sections
$sections = $conn->query("SELECT * FROM master_sections ORDER BY section_name ASC");

// Get all admin-created subjects
$subjects = $conn->query("SELECT * FROM master_subjects ORDER BY subject_name ASC");

$errors = [];
$skippedSubjects = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $schoolYearId = $_POST['school_year_id'] ?? '';
  $sectionName = trim($_POST['section_name'] ?? '');
  $subjectNames = $_POST['subject_names'] ?? [];

  if ($schoolYearId === '' || $sectionName === '' || empty($subjectNames)) {
    header("Location: ../user/register_subject.php?error=missing");
    exit;
  }

  // Step 1: Get or Create advisory class
  $stmt = $conn->prepare("SELECT advisory_id FROM advisory_classes WHERE teacher_id = ? AND school_year_id = ? AND class_name = ?");
  $stmt->bind_param("iis", $teacherId, $schoolYearId, $sectionName);
  $stmt->execute();
  $result = $stmt->get_result();

  if ($result->num_rows > 0) {
    $advisoryId = $result->fetch_assoc()['advisory_id'];
  } else {
    $insert = $conn->prepare("INSERT INTO advisory_classes (teacher_id, school_year_id, class_name) VALUES (?, ?, ?)");
    $insert->bind_param("iis", $teacherId, $schoolYearId, $sectionName);
    $insert->execute();
    $advisoryId = $insert->insert_id;
    $insert->close();
  }
  $stmt->close();

  // Step 2: Insert each subject if not already assigned
  $stmt = $conn->prepare("SELECT * FROM subjects WHERE teacher_id = ? AND school_year_id = ? AND advisory_id = ? AND subject_name = ?");
  $insert = $conn->prepare("INSERT INTO subjects (teacher_id, school_year_id, advisory_id, subject_name) VALUES (?, ?, ?, ?)");

  foreach ($subjectNames as $subjectName) {
    $subjectName = trim($subjectName);
    if ($subjectName === '') continue;

    $stmt->bind_param("iiis", $teacherId, $schoolYearId, $advisoryId, $subjectName);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 0) {
      $insert->bind_param("iiis", $teacherId, $schoolYearId, $advisoryId, $subjectName);
      $insert->execute();
    } else {
      $skippedSubjects[] = $subjectName;
    }
  }


  // Redirect with success or skipped info
  if (!empty($skippedSubjects)) {
    $_SESSION['skipped_subjects'] = $skippedSubjects;
    $_SESSION['success_message'] = null;
    header("Location: ../user/register_subject.php");
  } else {
    $_SESSION['success_message'] = "✅ Subjects successfully assigned.";
    $_SESSION['skipped_subjects'] = null;
    header("Location: ../user/register_subject.php");
  }
  exit;
}

if (isset($_GET['error']) && $_GET['error'] === 'duplicate') {
  $errors[] = "⚠️ Section and subjects already registered for this school year.";
}
if (!empty($_SESSION['skipped_subjects'])) {
  $errors[] = "⚠️ Some subjects were already assigned and skipped: " . implode(', ', $_SESSION['skipped_subjects']);
}
if (!empty($_SESSION['success_message'])) {
  $errors[] = $_SESSION['success_message'];
}

  $stmt->close();
  $insert->close();

  $conn->close();
?>
