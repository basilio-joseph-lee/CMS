<?php
session_start();
if (!isset($_SESSION['teacher_id'])) {
  header("Location: teacher_login.php");
  exit;
}

$teacher_id = $_SESSION['teacher_id'];
$advisory_id = $_POST['advisory_id'];
$school_year_id = $_POST['school_year_id'];
$reason = trim($_POST['reason']);

// Connect to database
$conn = new mysqli("localhost", "root", "", "cms");
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

// Prevent duplicate pending request for the same advisory and school year
$check = $conn->prepare("SELECT * FROM section_access_requests WHERE requester_id = ? AND advisory_id = ? AND school_year_id = ? AND status = 'pending'");
$check->bind_param("iii", $teacher_id, $advisory_id, $school_year_id);
$check->execute();
$result = $check->get_result();

if ($result->num_rows > 0) {
  header("Location: ../user/teacher_dashboard.php?error=already_requested");
  exit;
}

// Insert new request
$stmt = $conn->prepare("INSERT INTO section_access_requests (requester_id, advisory_id, school_year_id, reason) VALUES (?, ?, ?, ?)");
$stmt->bind_param("iiis", $teacher_id, $advisory_id, $school_year_id, $reason);

if ($stmt->execute()) {
  header("Location: ../user/teacher_dashboard.php?success=request_sent");
} else {
  echo "Something went wrong. Please try again.";
}
?>
