<?php
session_start();

$subjectName = $_SESSION['active_subject_name'] ?? '';
$className = $_SESSION['active_class_name'] ?? '';
$yearLabel = $_SESSION['active_year_label'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Student Dashboard</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body {
      background-image: url('../img/role.png');
      background-size: cover;
      background-position: center;
    }
  </style>
</head>
<body class="min-h-screen p-4 flex items-center justify-center">

<?php if (isset($_GET['attended']) && $_GET['attended'] == 1): ?>
  <div class="fixed top-4 right-4 bg-green-500 text-white px-6 py-3 rounded-xl shadow-xl z-50">
    ✅ Attendance recorded!
  </div>
<?php elseif (isset($_GET['attended']) && $_GET['attended'] == 'already'): ?>
  <div class="fixed top-4 right-4 bg-yellow-500 text-white px-6 py-3 rounded-xl shadow-xl z-50">
    ⚠️ Already marked attendance today!
  </div>
<?php endif; ?>


  <div class="bg-[#fef8e4] w-full max-w-screen-lg rounded-3xl shadow-lg p-6 md:p-10">

    <!-- Top Subject Info Banner -->
    <div class="mb-6 p-4 rounded-xl bg-yellow-100 border border-yellow-300 shadow text-center text-base sm:text-lg font-semibold">
      🎓 Logged into: 
      <span class="text-green-800"><?= htmlspecialchars($subjectName) ?></span> — 
      <span class="text-blue-800"><?= htmlspecialchars($className) ?></span> |
      SY: <span class="text-red-700"><?= htmlspecialchars($yearLabel) ?></span>
    </div>

    <!-- Header -->
    <h1 class="text-3xl md:text-4xl font-bold text-center text-gray-800 mb-10">Student Dashboard</h1>

    <!-- Grid Menu -->
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
      
      <!-- Attendance -->
      <a href="../config/submit_attendance.php" class="bg-green-500 hover:bg-green-600 text-white rounded-xl py-8 px-6 transition-all shadow-md text-center">
        <div class="text-4xl mb-2">✅</div>
        <div class="text-lg font-semibold">Attendance</div>
      </a>

      <!-- Restroom -->
      <a href="#" class="bg-yellow-400 hover:bg-yellow-500 text-black rounded-xl py-8 px-6 transition-all shadow-md text-center">
        <div class="text-4xl mb-2">🙋‍♂️</div>
        <div class="text-lg font-semibold">Restroom Request</div>
      </a>

      <!-- Snack -->
      <a href="#" class="bg-blue-500 hover:bg-blue-600 text-white rounded-xl py-8 px-6 transition-all shadow-md text-center">
        <div class="text-4xl mb-2">🍩</div>
        <div class="text-lg font-semibold">Snack Request</div>
      </a>

      <!-- Daily Notes -->
      <a href="#" class="bg-purple-500 hover:bg-purple-600 text-white rounded-xl py-8 px-6 transition-all shadow-md text-center">
        <div class="text-4xl mb-2">📝</div>
        <div class="text-lg font-semibold">Daily Notes</div>
      </a>

    </div>
  </div>

</body>
</html>
