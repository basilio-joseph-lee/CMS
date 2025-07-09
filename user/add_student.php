<?php
session_start();
if (!isset($_SESSION['teacher_id'])) {
  header("Location: teacher_login.php");
  exit;
}
$teacherId = $_SESSION['teacher_id'];
$teacherName = $_SESSION['fullname'];

$conn = new mysqli("localhost", "root", "", "cms");
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

// Fetch school years
$schoolYears = [];
$result = $conn->query("SELECT school_year_id, year_label FROM school_years ORDER BY year_label DESC");
while ($row = $result->fetch_assoc()) {
  $schoolYears[] = $row;
}

$selectedYear = $_POST['school_year_id'] ?? '';
$selectedSection = $_POST['advisory_id'] ?? '';
$selectedSubject = $_POST['subject_id'] ?? '';

// Fetch advisory classes for selected year
$advisorySections = [];
if ($selectedYear) {
  $stmt = $conn->prepare("SELECT advisory_id, class_name FROM advisory_classes WHERE teacher_id = ? AND school_year_id = ?");
  $stmt->bind_param("ii", $teacherId, $selectedYear);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) {
    $advisorySections[] = $row;
  }
  $stmt->close();
}

// Fetch subjects for selected year and section
$subjects = [];
if ($selectedYear && $selectedSection) {
  $stmt = $conn->prepare("SELECT subject_id, subject_name FROM subjects WHERE teacher_id = ? AND school_year_id = ? AND advisory_id = ?");
  $stmt->bind_param("iii", $teacherId, $selectedYear, $selectedSection);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) {
    $subjects[] = $row;
  }
  $stmt->close();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Add Student</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body {
      background-color: #fefae0;
      font-family: 'Comic Sans MS', cursive, sans-serif;
    }
    .sidebar {
      background-color: #386641;
      color: white;
    }
    .sidebar a {
      display: block;
      padding: 12px;
      margin: 8px 0;
      border-radius: 10px;
      text-decoration: none;
      font-weight: bold;
    }
    .sidebar a:hover {
      background-color: #6a994e;
    }
  </style>
</head>
<body>
<div class="flex min-h-screen">
  <!-- Sidebar -->
  <div class="sidebar w-1/5 p-6">
    <h2 class="text-2xl font-bold mb-6">SMARTCLASS KIOSK</h2>
    <a href="teacher_dashboard.php">Home</a>
    <a href="#" class="bg-yellow-300 text-black">Add Student</a>
    <a href="view_students.php">View Students</a>
  </div>

  <!-- Main Content -->
  <div class="flex-1 p-10">
    <div class="bg-white shadow-xl rounded-2xl p-6 max-w-5xl mx-auto">
      <div class="flex flex-col md:flex-row items-center justify-between mb-6">
        <h1 class="text-3xl font-bold text-[#bc6c25]">📌 Add Student</h1>
        <p class="text-lg text-gray-700 font-semibold">Welcome, <?php echo htmlspecialchars($teacherName); ?></p>
      </div>

      <!-- Step 1: School Year Selection -->
      <form method="POST" class="mb-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <select name="school_year_id" onchange="this.form.submit()" class="p-3 border border-yellow-400 rounded-xl">
            <option value="">Select School Year</option>
            <?php foreach ($schoolYears as $year): ?>
              <option value="<?= $year['school_year_id'] ?>" <?= $selectedYear == $year['school_year_id'] ? 'selected' : '' ?>><?= htmlspecialchars($year['year_label']) ?></option>
            <?php endforeach; ?>
          </select>

          <?php if ($selectedYear): ?>
          <select name="advisory_id" onchange="this.form.submit()" class="p-3 border border-yellow-400 rounded-xl">
            <option value="">Select Section</option>
            <?php foreach ($advisorySections as $section): ?>
              <option value="<?= $section['advisory_id'] ?>" <?= $selectedSection == $section['advisory_id'] ? 'selected' : '' ?>><?= htmlspecialchars($section['class_name']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php endif; ?>

          <?php if ($selectedSection): ?>
          <select name="subject_id" onchange="this.form.submit()" class="p-3 border border-yellow-400 rounded-xl">
            <option value="">Select Subject</option>
            <?php foreach ($subjects as $sub): ?>
              <option value="<?= $sub['subject_id'] ?>" <?= $selectedSubject == $sub['subject_id'] ? 'selected' : '' ?>><?= htmlspecialchars($sub['subject_name']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php endif; ?>
        </div>
      </form>

      <?php if ($selectedYear && $selectedSection && $selectedSubject): ?>
      <!-- Student Registration Form -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
        <!-- Capture Section -->
        <div class="text-center">
          <div class="w-full h-60 bg-blue-100 rounded-xl mb-4 flex items-center justify-center">
            <video id="video" width="320" height="240" autoplay class="rounded"></video>
            <canvas id="canvas" width="320" height="240" style="display:none;"></canvas>
          </div>
          <button type="button" onclick="captureImage()" class="bg-orange-400 hover:bg-orange-500 text-white px-6 py-2 rounded-lg font-bold">Capture Face</button>
        </div>

        <!-- Avatar + Form -->
        <div>
          <div class="bg-yellow-100 rounded-xl mb-4 p-4 text-center">
            <img id="avatarPreview" src="../img/avatar_placeholder.png" alt="Avatar" class="w-24 h-24 mx-auto mb-2">
            <p class="text-gray-700 font-semibold">Preview of student avatar</p>
          </div>

          <form action="../config/register_student.php" method="POST" enctype="multipart/form-data" class="space-y-4">
            <input type="hidden" name="school_year_id" value="<?= htmlspecialchars($selectedYear) ?>">
            <input type="hidden" name="advisory_id" value="<?= htmlspecialchars($selectedSection) ?>">
            <input type="hidden" name="subject_id" value="<?= htmlspecialchars($selectedSubject) ?>">
            <input type="hidden" id="captured_face" name="captured_face">

            <input type="text" name="fullname" placeholder="Full Name" required class="w-full p-3 border border-yellow-400 rounded-xl">
            <select name="gender" required class="w-full p-3 border border-yellow-400 rounded-xl">
              <option value="">Select gender</option>
              <option value="Male">Male</option>
              <option value="Female">Female</option>
            </select>
            <button type="submit" class="bg-green-500 hover:bg-green-600 text-white px-6 py-3 rounded-xl font-bold w-full">✅ Confirm</button>
          </form>
        </div>
      </div>
      <script>
        navigator.mediaDevices.getUserMedia({ video: true }).then(stream => {
          document.getElementById('video').srcObject = stream;
        });

        function captureImage() {
          const video = document.getElementById('video');
          const canvas = document.getElementById('canvas');
          const ctx = canvas.getContext('2d');
          ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

          const imageData = canvas.toDataURL('image/jpeg');
          document.getElementById('captured_face').value = imageData;
          document.getElementById('avatarPreview').src = imageData;
        }
      </script>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
