<?php
session_start();

$subjectName = $_SESSION['active_subject_name'] ?? '';
$className = $_SESSION['active_class_name'] ?? '';
$yearLabel = $_SESSION['active_year_label'] ?? '';

$subject_id = $_SESSION['active_subject_id'] ?? null;
$advisory_id = $_SESSION['active_advisory_id'] ?? null;
$school_year_id = $_SESSION['active_school_year_id'] ?? null;

$teacher_announcements = [];

if ($subject_id && $advisory_id && $school_year_id) {
  $conn = new mysqli("localhost", "root", "", "cms");
  if (!$conn->connect_error) {
    $stmt = $conn->prepare("SELECT title, message, date_posted FROM announcements 
                            WHERE subject_id = ? AND class_id = ? 
                            AND (visible_until IS NULL OR visible_until >= CURDATE())
                            ORDER BY date_posted DESC LIMIT 5");
    $stmt->bind_param("ii", $subject_id, $advisory_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
      $teacher_announcements[] = $row;
    }
    $stmt->close();
    $conn->close();
  }
}

$announcement_count = count($teacher_announcements);
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

    @keyframes pulse-bell {
      0%, 100% { transform: scale(1); }
      50% { transform: scale(1.2); }
    }

    .animate-bell {
      animation: pulse-bell 1s infinite;
    }

    .fade-out {
      animation: fadeOut 1s forwards;
    }

    @keyframes fadeOut {
      to {
        opacity: 0;
        transform: translateY(-20px);
      }
    }
  </style>
</head>
<body class="min-h-screen p-4 flex items-center justify-center relative">

<!-- ✅ Popup Toast (Only on Login) -->
<?php if (!empty($teacher_announcements) && isset($_GET['new_login'])): ?>
  <div id="announcement-toast" class="fixed top-6 left-1/2 transform -translate-x-1/2 bg-yellow-100 border border-yellow-400 text-gray-900 px-6 py-4 rounded-xl shadow-xl z-50">
    <strong class="block mb-1">📢 Announcement</strong>
    <p class="text-sm"><?= htmlspecialchars($teacher_announcements[0]['title']) ?>: <?= htmlspecialchars($teacher_announcements[0]['message']) ?></p>
  </div>
  <script>
    setTimeout(() => {
      const toast = document.getElementById('announcement-toast');
      if (toast) {
        toast.classList.add('fade-out');
        setTimeout(() => toast.remove(), 1000);
      }
    }, 5000);
  </script>
<?php endif; ?>

<!-- ✅ Main Dashboard Container -->
<div class="bg-[#fef8e4] w-full max-w-screen-lg rounded-3xl shadow-lg p-6 md:p-10 relative">

  <!-- ✅ Top Info with Notification Bell -->
  <div class="mb-6 relative p-4 rounded-xl bg-yellow-100 border border-yellow-300 shadow text-center text-base sm:text-lg font-semibold flex items-center justify-center">
    🎓 Logged into: 
    <span class="text-green-800 mx-1"><?= htmlspecialchars($subjectName) ?></span> — 
    <span class="text-blue-800"><?= htmlspecialchars($className) ?></span> |
    SY: <span class="text-red-700"><?= htmlspecialchars($yearLabel) ?></span>

    <?php if ($announcement_count > 0): ?>
      <div class="absolute right-6 top-1/2 -translate-y-1/2">
        <button onclick="document.getElementById('notifPanel').classList.toggle('hidden')" class="relative focus:outline-none">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7 text-yellow-700 hover:text-yellow-900 transition duration-200 animate-bell" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 10-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
          </svg>
          <span class="absolute -top-2 -right-2 bg-red-600 text-white text-xs font-bold rounded-full px-2">
            <?= $announcement_count ?>
          </span>
        </button>
      </div>
    <?php endif; ?>
  </div>

  <!-- ✅ Dropdown Notification Panel -->
  <div id="notifPanel" class="hidden absolute top-28 right-10 w-80 bg-white border border-yellow-300 rounded-xl shadow-lg z-40">
    <div class="p-4">
      <h3 class="text-lg font-bold text-[#bc6c25] mb-2">📢 Announcements</h3>
      <ul class="space-y-2 max-h-64 overflow-y-auto">
        <?php foreach ($teacher_announcements as $a): ?>
          <li class="border border-yellow-200 rounded p-3 bg-yellow-50">
            <p class="font-semibold text-gray-800"><?= htmlspecialchars($a['title']) ?></p>
            <p class="text-gray-600 text-sm"><?= htmlspecialchars($a['message']) ?></p>
            <p class="text-xs text-gray-500 mt-1 italic"><?= date('M d, Y h:i A', strtotime($a['date_posted'])) ?></p>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>

  <!-- ✅ Dashboard Header -->
  <h1 class="text-3xl md:text-4xl font-bold text-center text-gray-800 mb-10">Student Dashboard</h1>

  <!-- ✅ Grid Menu -->
  <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
    <a href="../config/submit_attendance.php" class="bg-green-500 hover:bg-green-600 text-white rounded-xl py-8 px-6 shadow-md text-center">
      <div class="text-4xl mb-2">✅</div>
      <div class="text-lg font-semibold">Attendance</div>
    </a>
    <a href="#" class="bg-yellow-400 hover:bg-yellow-500 text-black rounded-xl py-8 px-6 shadow-md text-center">
      <div class="text-4xl mb-2">🙋‍♂️</div>
      <div class="text-lg font-semibold">Restroom Request</div>
    </a>
    <a href="#" class="bg-blue-500 hover:bg-blue-600 text-white rounded-xl py-8 px-6 shadow-md text-center">
      <div class="text-4xl mb-2">🍩</div>
      <div class="text-lg font-semibold">Snack Request</div>
    </a>
    <a href="#" class="bg-purple-500 hover:bg-purple-600 text-white rounded-xl py-8 px-6 shadow-md text-center">
      <div class="text-4xl mb-2">📝</div>
      <div class="text-lg font-semibold">Daily Notes</div>
    </a>
  </div>

</div>
</body>
</html>
