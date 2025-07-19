<?php
session_start();
if (!isset($_SESSION['teacher_id'])) {
  header("Location: teacher_login.php");
  exit;
}

$teacherName = $_SESSION['fullname'];
$subject_id = $_SESSION['subject_id'];
$advisory_id = $_SESSION['advisory_id'];
$school_year_id = $_SESSION['school_year_id'];
$subject_name = $_SESSION['subject_name'];
$class_name = $_SESSION['class_name'];
$year_label = $_SESSION['year_label'];

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
  <?php if (isset($_SESSION['toast'])): ?>
  <div id="toast" class="fixed top-5 right-5 bg-<?= $_SESSION['toast_type'] === 'error' ? 'red' : 'green' ?>-500 text-white px-6 py-3 rounded shadow-lg z-50">
    <?= $_SESSION['toast'] ?>
  </div>
  <script>
    setTimeout(() => {
      const toast = document.getElementById('toast');
      if (toast) toast.style.display = 'none';
    }, 4000);
  </script>
  <?php unset($_SESSION['toast'], $_SESSION['toast_type']); ?>
<?php endif; ?>

<!-- Toast -->
<div id="toast" class="fixed top-5 right-5 bg-red-600 text-white px-4 py-2 rounded shadow-lg hidden z-50 transition-opacity duration-300">
  ⚠️ Please capture the student's face before submitting.
</div>


  
  <!-- Sidebar -->
  <div class="sidebar w-1/5 p-6">
    <h2 class="text-2xl font-bold mb-6">SMARTCLASS KIOSK</h2>
    <a href="teacher_dashboard.php">Home</a>
    <a href="#" class="bg-yellow-300 text-black">Add Student</a>
    <a href="teacher/view_students.php">View Students</a>
  </div>

  <!-- Main Content -->
  <div class="flex-1 p-10">
    <div class="bg-white shadow-xl rounded-2xl p-6 max-w-5xl mx-auto">
      <div class="flex flex-col md:flex-row items-center justify-between mb-6">
        <h1 class="text-3xl font-bold text-[#bc6c25]">📌 Add Student</h1>
        <p class="text-lg text-gray-700 font-semibold">Welcome, <?= htmlspecialchars($teacherName); ?></p>
      </div>

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
            <input type="hidden" name="school_year_id" value="<?= htmlspecialchars($school_year_id) ?>">
            <input type="hidden" name="advisory_id" value="<?= htmlspecialchars($advisory_id) ?>">
            <input type="hidden" name="subject_id" value="<?= htmlspecialchars($subject_id) ?>">
            <input type="hidden" id="captured_face" name="captured_face">

            <input type="text" name="fullname" placeholder="Full Name" required class="w-full p-3 border border-yellow-400 rounded-xl">
            <select name="gender" required class="w-full p-3 border border-yellow-400 rounded-xl">
              <option value="">Select gender</option>
              <option value="Male">Male</option>
              <option value="Female">Female</option>
            </select>
            <button type="submit" class="bg-green-500 hover:bg-green-600 text-white px-6 py-3 rounded-xl font-bold w-full">✅ Confirm</button>
           
              <script>
              document.querySelector("form").addEventListener("submit", function(e) {
                const captured = document.getElementById("captured_face").value;
                if (!captured) {
                  e.preventDefault();
                  const toast = document.getElementById("toast");
                  toast.classList.remove("hidden");
                  toast.classList.add("opacity-100");

                  setTimeout(() => {
                    toast.classList.add("hidden");
                  }, 2000); // 1 second
                }
              });
              </script> 


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
    </div>
  </div>
  
</div>


</body>
</html>
