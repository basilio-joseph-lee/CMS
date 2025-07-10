<?php
session_start();
if (!isset($_SESSION['teacher_id'])) {
  header("Location: teacher_login.php");
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  die("Invalid request method.");
}

// DB connection
$conn = new mysqli("localhost", "root", "", "cms");
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

// Get form data
$fullname = trim($_POST['fullname'] ?? '');
$gender = $_POST['gender'] ?? '';
$school_year_id = $_POST['school_year_id'] ?? '';
$advisory_id = $_POST['advisory_id'] ?? '';
$subject_id = $_POST['subject_id'] ?? '';
$face_image = $_POST['captured_face'] ?? '';

if (!$fullname || !$gender || !$school_year_id || !$advisory_id || !$subject_id || !$face_image) {
  die("Missing required fields.");
}

// Save face image
$face_image = str_replace('data:image/jpeg;base64,', '', $face_image);
$face_image = base64_decode($face_image);
$face_filename = 'student_faces/' . uniqid('face_') . '.jpg';

if (!file_exists('student_faces')) {
  mkdir('student_faces', 0777, true);
}
file_put_contents($face_filename, $face_image);

// === Generate cartoon avatar === //
$avatar_filename = 'student_avatars/' . uniqid('avatar_') . '.jpg';

if (!file_exists('student_avatars')) {
  mkdir('student_avatars', 0777, true);
}

// Run cartoonify.py
$command = escapeshellcmd("python cartoonify.py $face_filename $avatar_filename");
$output = shell_exec($command);

// Check if success
if (strpos($output, 'OK') === false) {
  echo "⚠️ Avatar cartoonify failed. Output: " . htmlspecialchars($output);
}

// Insert into students table
$stmt = $conn->prepare("INSERT INTO students (fullname, gender, face_image_path, avatar_path) VALUES (?, ?, ?, ?)");
$stmt->bind_param("ssss", $fullname, $gender, $face_filename, $avatar_filename);

if ($stmt->execute()) {
  $student_id = $conn->insert_id;

  // Enroll student
  $enroll_stmt = $conn->prepare("INSERT INTO student_enrollments (student_id, advisory_id, school_year_id, subject_id) VALUES (?, ?, ?, ?)");
  $enroll_stmt->bind_param("iiii", $student_id, $advisory_id, $school_year_id, $subject_id);
  $enroll_stmt->execute();
  $enroll_stmt->close();

  header("Location: ../user/add_student.php");
} else {
  echo "Failed to register student: " . $stmt->error;
}

$stmt->close();
$conn->close();
?>
