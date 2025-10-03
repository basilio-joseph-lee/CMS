<?php
session_start();
include '../../config/teacher_guard.php';
include "../../config/db.php";
date_default_timezone_set('Asia/Manila');
if (!isset($_SESSION['teacher_id'])) {
  header("Location: teacher_login.php");
  exit;
}

$teacher_id = $_SESSION['teacher_id'];
$subject_id = $_SESSION['subject_id'];
$advisory_id = $_SESSION['advisory_id'];
$school_year_id = $_SESSION['school_year_id'];
$subject_name = $_SESSION['subject_name'];
$class_name = $_SESSION['class_name'];
$year_label = $_SESSION['year_label'];


if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

$selected_date = $_GET['date'] ?? date('Y-m-d');

$stmt = $conn->prepare("SELECT s.fullname, a.status, a.timestamp 
  FROM attendance_records a 
  JOIN students s ON a.student_id = s.student_id 
  WHERE a.subject_id = ? AND a.advisory_id = ? AND a.school_year_id = ? AND DATE(a.timestamp) = ? 
  ORDER BY s.fullname ASC");
$stmt->bind_param("iiis", $subject_id, $advisory_id, $school_year_id, $selected_date);
$stmt->execute();
$result = $stmt->get_result();
$records = $result->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>View Attendance History</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body {
      background-image: url('../../img/role.png');
      background-size: cover;
      font-family: 'Comic Sans MS', cursive, sans-serif;
    }
  </style>
</head>
<body class="p-6">
  <div class="max-w-6xl mx-auto bg-[#fffbea] p-10 rounded-3xl shadow-2xl ring-4 ring-yellow-300">
    <div class="flex justify-between items-center mb-6">
      <div>
        <h1 class="text-4xl font-bold text-[#bc6c25]">📖 Attendance History</h1>
        <p class="text-lg font-semibold text-gray-800 mt-2">
          Subject: <span class="text-green-700"><?= $subject_name ?></span> — 
          <span class="text-blue-700"><?= $class_name ?></span> | SY: 
          <span class="text-red-700"><?= $year_label ?></span>
        </p>
        <p id="clock" class="text-sm text-gray-600 mt-2">🕒 Loading time...</p>
        <form method="GET" class="mt-4">
          <label for="date" class="font-bold mr-2">Select Date:</label>
          <input type="date" id="date" name="date" value="<?= $selected_date ?>" class="border p-2 rounded">
          <button type="submit" class="ml-2 bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded">View</button>
        </form>
      </div>
      <a href="teacher_dashboard.php" class="bg-orange-400 hover:bg-orange-500 text-white text-lg font-bold px-6 py-2 rounded-lg shadow-md">← Back</a>
    </div>

    <?php if (empty($records)): ?>
      <p class="text-center text-gray-600">No attendance records found for <?= htmlspecialchars($selected_date) ?>.</p>
    <?php else: ?>
      <table class="w-full text-center border-4 border-yellow-400 rounded-2xl overflow-hidden">
        <thead class="bg-yellow-300 text-gray-900">
          <tr>
            <th class="py-3 px-4 text-lg">#</th>
            <th class="py-3 px-4 text-lg text-left">Student Name</th>
            <th class="py-3 px-4 text-lg">Status</th>
            <th class="py-3 px-4 text-lg">Timestamp</th>
          </tr>
        </thead>
        <tbody>
          <?php $i = 1; foreach ($records as $rec): ?>
            <tr class="border-b border-yellow-300">
              <td class="py-3 px-4 text-lg font-semibold text-gray-800"><?= $i++ ?></td>
              <td class="py-3 px-4 text-lg text-gray-700 text-left"><?= htmlspecialchars($rec['fullname']) ?></td>
              <td class="py-3 px-4 text-lg font-semibold 
                <?= $rec['status'] === 'Present' ? 'text-green-600' : ($rec['status'] === 'Absent' ? 'text-red-600' : 'text-yellow-600') ?>">
                <?= $rec['status'] === 'Present' ? '✅ Present' : ($rec['status'] === 'Absent' ? '❌ Absent' : '⏰ Late') ?>
              </td>
              <td class="py-3 px-4 text-lg text-gray-600"><?= date("M d, Y — h:i A", strtotime($rec['timestamp'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <script>
    function updateClock() {
      const now = new Date();
      const options = {
        weekday: 'short', year: 'numeric', month: 'short', day: 'numeric',
        hour: '2-digit', minute: '2-digit', second: '2-digit'
      };
      document.getElementById("clock").textContent = "🕒 Browser Time: " + now.toLocaleString('en-US', options);
    }
    setInterval(updateClock, 1000);
    updateClock();
  </script>
</body>
</html>
