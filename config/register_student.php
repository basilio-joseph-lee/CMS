<?php
session_start();
if (!isset($_SESSION['teacher_id'])) {
  header("Location: /CMS/user/teacher_login.php");
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  die("Invalid request method.");
}

/* ---------- DB connection ---------- */
include("db.php");
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

/* ---------- Paths: filesystem vs public URLs ---------- */
/* Filesystem root of the CMS project (…/CMS) */
$PROJECT_ROOT = realpath(__DIR__ . '/..');              // e.g., C:\xampp\htdocs\CMS
$PUBLIC_BASE  = '/CMS';                                 // public URL base

$facesDirFs   = $PROJECT_ROOT . '/student_faces';       // FS path for faces
$facesDirUrl  = $PUBLIC_BASE . '/student_faces';        // public URL to faces

$avatarDirFs  = $PROJECT_ROOT . '/avatar';              // FS path where your stock avatars live
$avatarDirUrl = $PUBLIC_BASE . '/avatar';               // public URL to avatars

/* ---------- Read form ---------- */
$fullname     = trim($_POST['fullname'] ?? '');
$gender       = $_POST['gender'] ?? '';
$capturedFace = $_POST['captured_face'] ?? '';          // data URL (optional)
$chosenAvatar = $_POST['avatar_path'] ?? '';            // e.g. /CMS/avatar/M-Blue-Polo.jpg (optional)

/* From session */
$school_year_id = intval($_SESSION['school_year_id'] ?? 0);
$advisory_id    = intval($_SESSION['advisory_id'] ?? 0);
$subject_id     = intval($_SESSION['subject_id'] ?? 0);

if (!$fullname || !$gender || !$school_year_id || !$advisory_id || !$subject_id) {
  die("Missing required fields.");
}

/* Require at least one: avatar OR captured face */
if ($capturedFace === '' && $chosenAvatar === '') {
  $_SESSION['toast'] = "Please choose a 2D avatar or capture a face.";
  $_SESSION['toast_type'] = "error";
  header("Location: /CMS/user/teacher/add_student.php");
  exit;
}

/* ---------- Normalize and validate avatar path (if provided) ---------- */
$avatar_path_db = null;
if ($chosenAvatar !== '') {
  // Expect something like /CMS/avatar/FILE
  // Keep only the filename to avoid traversal
  $avatarFile = basename($chosenAvatar);
  $candidateFs = $avatarDirFs . DIRECTORY_SEPARATOR . $avatarFile;

  if (!is_file($candidateFs)) {
    // Fallback: also try if user sent a relative like 'M-Blue-Polo.jpg'
    $candidateFs2 = $avatarDirFs . DIRECTORY_SEPARATOR . basename($chosenAvatar);
    if (!is_file($candidateFs2)) {
      $_SESSION['toast'] = "Selected avatar not found.";
      $_SESSION['toast_type'] = "error";
      header("Location: /CMS/user/teacher/add_student.php");
      exit;
    }
    $candidateFs = $candidateFs2;
    $avatarFile  = basename($candidateFs2);
  }
  // Public URL to store in DB
  $avatar_path_db = $avatarDirUrl . '/' . $avatarFile;
}

/* ---------- Save captured face (if provided) ---------- */
$face_image_path_db = null;
if ($capturedFace !== '') {
  // Expect "data:image/jpeg;base64,...."
  if (strpos($capturedFace, 'base64,') !== false) {
    $base64 = substr($capturedFace, strpos($capturedFace, 'base64,') + 7);
  } else {
    $base64 = $capturedFace; // be permissive
  }
  $binary = base64_decode($base64, true);
  if ($binary === false) {
    $_SESSION['toast'] = "Invalid captured image data.";
    $_SESSION['toast_type'] = "error";
    header("Location: /CMS/user/teacher/add_student.php");
    exit;
  }

  if (!is_dir($facesDirFs)) {
    @mkdir($facesDirFs, 0777, true);
  }

  $faceFileName = 'face_' . uniqid() . '.jpg';
  $faceFs = $facesDirFs . DIRECTORY_SEPARATOR . $faceFileName;
  if (file_put_contents($faceFs, $binary) === false) {
    $_SESSION['toast'] = "Failed to save captured face.";
    $_SESSION['toast_type'] = "error";
    header("Location: /CMS/user/teacher/add_student.php");
    exit;
  }
  // Public URL to store in DB
  $face_image_path_db = $facesDirUrl . '/' . $faceFileName;
}

/* ---------- Duplicate check: same fullname within same (SY, advisory, subject) ---------- */
$check_sql = "
  SELECT s.student_id
  FROM students s
  JOIN student_enrollments se ON s.student_id = se.student_id
  WHERE s.fullname = ?
    AND se.school_year_id = ?
    AND se.advisory_id = ?
    AND se.subject_id = ?
  LIMIT 1
";
$check_stmt = $conn->prepare($check_sql);
$check_stmt->bind_param("siii", $fullname, $school_year_id, $advisory_id, $subject_id);
$check_stmt->execute();
$check_stmt->store_result();

if ($check_stmt->num_rows > 0) {
  $check_stmt->close();
  $_SESSION['toast'] = "⚠️ Student already exists in this subject, section and school year.";
  $_SESSION['toast_type'] = "error";
  header("Location: /CMS/user/teacher/add_student.php");
  exit;
}
$check_stmt->close();

/* ---------- Insert student ---------- */
/* Ensure your table has columns: fullname, gender, face_image_path (NULL ok), avatar_path (NULL ok) */
$insert_sql = "INSERT INTO students (fullname, gender, face_image_path, avatar_path) VALUES (?, ?, ?, ?)";
$stmt = $conn->prepare($insert_sql);
$stmt->bind_param(
  "ssss",
  $fullname,
  $gender,
  $face_image_path_db,  // can be null
  $avatar_path_db       // can be null
);

if ($stmt->execute()) {
  $student_id = $conn->insert_id;

  // Enroll student
  $enroll_stmt = $conn->prepare("INSERT INTO student_enrollments (student_id, advisory_id, school_year_id, subject_id) VALUES (?, ?, ?, ?)");
  $enroll_stmt->bind_param("iiii", $student_id, $advisory_id, $school_year_id, $subject_id);
  $enroll_stmt->execute();
  $enroll_stmt->close();

  $_SESSION['toast'] = "✅ Student added successfully!";
  $_SESSION['toast_type'] = "success";
  header("Location: /CMS/user/teacher/add_student.php");
  exit;
} else {
  echo "❌ Failed to register student: " . $stmt->error;
}

$stmt->close();
$conn->close();
