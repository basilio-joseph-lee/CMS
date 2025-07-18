<?php
session_start();

// Store selected subject info from URL into session
if (isset($_GET['subject_id']) && isset($_GET['subject_name']) && isset($_GET['class_name']) && isset($_GET['year_label'])) {
    $_SESSION['subject_id'] = $_GET['subject_id'];
    $_SESSION['subject_name'] = $_GET['subject_name'];
    $_SESSION['class_name'] = $_GET['class_name'];
    $_SESSION['year_label'] = $_GET['year_label'];
} else {
    die("Missing subject information.");
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Choose Role</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-cover bg-center min-h-screen flex items-center justify-center" style="background-image: url('img/1.png');">
  <div class="bg-white/80 backdrop-blur-sm shadow-xl rounded-xl p-10 w-full max-w-3xl text-center border border-gray-200">
    <h1 class="text-3xl font-bold text-gray-800 mb-8">Choose Your Role</h1>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
      <!-- Student -->
      <a href="user/face_login.php" class="bg-blue-500 hover:bg-blue-600 text-white p-6 rounded-lg transition-all shadow-md">
        <div class="text-4xl mb-2">🎓</div>
        <h2 class="text-xl font-semibold">Student</h2>
        <p class="text-sm">Login as Student</p>
      </a>

      <!-- Teacher -->
      <a href="user/teacher_login.php" class="bg-green-500 hover:bg-green-600 text-white p-6 rounded-lg transition-all shadow-md">
        <div class="text-4xl mb-2">👩‍🏫</div>
        <h2 class="text-xl font-semibold">Teacher</h2>
        <p class="text-sm">Login as Teacher</p>
      </a>

      <!-- Parent -->
      <a href="parent_login.php" class="bg-purple-500 hover:bg-purple-600 text-white p-6 rounded-lg transition-all shadow-md">
        <div class="text-4xl mb-2">👨‍👩‍👧</div>
        <h2 class="text-xl font-semibold">Parent</h2>
        <p class="text-sm">Login as Parent</p>
      </a>
    </div>
  </div>
</body>
</html>
