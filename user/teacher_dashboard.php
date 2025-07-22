<?php
session_start();
if (!isset($_SESSION['teacher_id'])) {
  header("Location: teacher_login.php");
  exit;
}
$teacherName = $_SESSION['fullname'];

// Get subject info from session
$subjectName = $_SESSION['subject_name'] ?? '';
$className = $_SESSION['class_name'] ?? '';
$yearLabel = $_SESSION['year_label'] ?? '';
?>


<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Teacher Dashboard</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body {
      background-image: url('../img/1.png'); /* corkboard background */
      background-size: cover;
      background-position: center;
      font-family: 'Comic Sans MS', cursive, sans-serif;
    }

    .pin::before {
      content: "📌";
      font-size: 1.75rem;
      position: absolute;
      top: -14px;
      left: 50%;
      transform: translateX(-50%);
    }

    .card {
      background-color: #fdf7e2;
      border-radius: 18px;
      padding: 20px 10px;
      box-shadow: 4px 6px 0 rgba(0, 0, 0, 0.15);
      text-align: center;
      transition: all 0.25s ease;
      height: 160px;
      display: flex;
      flex-direction: column;
      justify-content: center;
    }

    .card:hover {
      transform: scale(1.05);
      box-shadow: 6px 8px 0 rgba(0, 0, 0, 0.2);
    }

    .card-icon {
      font-size: 2.2rem;
      margin-bottom: 0.5rem;
    }
  </style>
</head>
<body class="min-h-screen flex flex-col items-center px-4 py-8">

 <!-- Header -->
<div class="w-full max-w-6xl bg-green-700 text-white flex justify-between items-center px-6 py-4 rounded-t-3xl rounded-b-xl shadow-lg mb-10">
  <div>
    <h1 class="text-2xl font-bold">Welcome, <?= htmlspecialchars($teacherName); ?></h1>
    <div class="text-sm">
      Subject: <span class="font-semibold"><?= htmlspecialchars($subjectName) ?></span> |
      Section: <span class="font-semibold"><?= htmlspecialchars($className) ?></span> |
      SY: <span class="font-semibold"><?= htmlspecialchars($yearLabel) ?></span>
    </div>
  </div>
  <a href="../config/logout.php" class="bg-orange-500 hover:bg-orange-600 text-white px-4 py-2 rounded-lg font-semibold shadow">Logout</a>
</div>


  <!-- Card Grid -->
  <div class="w-full max-w-6xl grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-8">

    <a href="add_student.php" class="relative pin">
      <div class="card bg-blue-100">
        <div class="card-icon">🎥</div>
        <div class="font-bold">Enroll Student</div>
      </div>
    </a>
    <a href="teacher/view_students.php" class="relative pin">
      <div class="card">
        <div class="card-icon">👦</div>
        <div class="font-bold">View Students</div>
      </div>
    </a>

    <a href="teacher/view_attendance_history.php" class="relative pin">
      <div class="card bg-blue-100">
        <div class="card-icon">📅</div>
        <div class="font-bold text-sm">View Attendance History</div>
      </div>
    </a>

    <a href="teacher/grading_sheet.php" class="relative pin">
      <div class="card">
        <div class="card-icon">📖</div>
        <div class="font-bold">Grades</div>
      </div>
    </a>

    <a href="teacher/mark_attendance.php" class="relative pin">
      <div class="card bg-blue-100">
        <div class="card-icon">🗓️</div>
        <div class="font-bold text-sm">Mark Attendance</div>
      </div>
    </a>

    <a href="teacher/announcement.php" class="relative pin">
      <div class="card">
        <div class="card-icon">📢</div>
        <div class="font-bold">Post Announcement</div>
      </div>
    </a>

    <!-- NEW CARD: Create School Year -->
    <a href="register_subject.php" class="relative pin">
      <div class="card bg-yellow-100">
        <div class="card-icon">📆</div>
        <div class="font-bold text-sm">Register Subject</div>
      </div>
    </a>

    <!-- NEW CARD: Seating Plan -->
    <a href="setup_seating_plan.php" class="relative pin">
      <div class="card bg-pink-100">
        <div class="card-icon">🪑</div>
        <div class="font-bold text-sm">Seating Plan</div>
      </div>
    </a>

  </div>

</body>
</html>
