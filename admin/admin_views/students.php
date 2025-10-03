<?php
include("../config/db.php");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

// Handle Update
if (isset($_POST['update_id'])) {
  $id = $_POST['update_id'];
  $fullname = $_POST['update_fullname'];
  $gender = $_POST['update_gender'];
  $stmt = $conn->prepare("UPDATE students SET fullname=?, gender=? WHERE student_id=?");
  $stmt->bind_param("ssi", $fullname, $gender, $id);
  $stmt->execute();
  $_SESSION['toast'] = "Student updated successfully!";
  $_SESSION['toast_type'] = "success";
  echo "<script>location.href='admin.php?page=students';</script>";
  exit;
}

// Handle Delete
if (isset($_GET['delete_id'])) {
  $id = $_GET['delete_id'];
  $conn->query("DELETE FROM students WHERE student_id=$id");
  $conn->query("DELETE FROM student_enrollments WHERE student_id=$id");
  $_SESSION['toast'] = "Student deleted!";
  $_SESSION['toast_type'] = "error";
  echo "<script>location.href='admin.php?page=students';</script>";
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $fullname = $_POST['fullname'];
  $gender = $_POST['gender'];

  $face_image = $_POST['captured_face'] ?? '';
  $face_image = str_replace('data:image/jpeg;base64,', '', $face_image);
  $face_image = base64_decode($face_image);

  if (!file_exists('student_faces')) mkdir('student_faces', 0777, true);
  $face_filename = 'student_faces/' . uniqid('face_') . '.jpg';
  file_put_contents($face_filename, $face_image);

  $avatar_filename = '../img/default.png';

$stmt = $conn->prepare("INSERT INTO students (fullname, gender, face_image_path, avatar_path) VALUES (?, ?, ?, ?)");
$stmt->bind_param("ssss", $fullname, $gender, $face_filename, $avatar_filename);
$stmt->execute();

  

  $_SESSION['toast'] = "Student added successfully!";
  $_SESSION['toast_type'] = "success";
  echo "<script>location.href='admin.php?page=students';</script>";
  exit;
}

$students = $conn->query("SELECT * FROM students ORDER BY fullname ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Manage Students</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body {
      background-color: #fefae0;
      font-family: 'Comic Sans MS', cursive, sans-serif;
    }
  </style>
</head>
<body>
  <div class="min-h-screen py-12 px-6">
    <div class="bg-white shadow-xl rounded-2xl p-6 w-full max-w-5xl mx-auto">
      <div class="flex justify-between mb-6">
        <h1 class="text-3xl font-bold text-[#bc6c25]">📌 Student List</h1>
        <button onclick="openAddStudentModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">➕ Add Student</button>
      </div>

      <table class="w-full text-sm text-left">
        <thead class="bg-gray-200 text-gray-700">
          <tr>
            <th class="py-2 px-4">ID</th>
            <th class="py-2 px-4">Full Name</th>
            <th class="py-2 px-4">Gender</th>
            <th class="py-2 px-4">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php while ($row = $students->fetch_assoc()): ?>
          <tr class="border-t">
            <td class="py-2 px-4"><?= $row['student_id'] ?></td>
            <td class="py-2 px-4"><?= htmlspecialchars($row['fullname']) ?></td>
            <td class="py-2 px-4"><?= htmlspecialchars($row['gender']) ?></td>
            <td class="py-2 px-4">
              <button onclick="openEditModal(<?= $row['student_id'] ?>, '<?= htmlspecialchars($row['fullname']) ?>', '<?= $row['gender'] ?>')" class="text-blue-600 hover:underline">✏️ Edit</button>
              <button onclick="confirmDelete(<?= $row['student_id'] ?>)" class="text-red-600 hover:underline ml-2">🗑️ Delete</button>
            </td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>

    <!-- Add Student Modal -->
    <div id="addModal" class="fixed inset-0 bg-black/50 flex items-center justify-center hidden z-50">
      <div class="bg-white p-6 rounded-2xl shadow-lg w-full max-w-6xl mx-4 overflow-y-auto max-h-[95vh]">
        <div class="flex justify-between items-center mb-4">
          <h2 class="text-xl font-bold text-[#bc6c25]">➕ Add Student</h2>
          <button onclick="closeAddStudentModal()" class="text-gray-500 text-xl">✖</button>
        </div>

        <form method="POST">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Capture Section -->
            <div class="text-center">
              <div class="w-full h-60 bg-blue-100 rounded-xl flex items-center justify-center">
                <video id="video" width="320" height="240" autoplay class="rounded"></video>
                <canvas id="canvas" width="320" height="240" style="display:none;"></canvas>
              </div>
              <button type="button" onclick="captureImage()" class="mt-2 bg-orange-400 hover:bg-orange-500 text-white px-6 py-2 rounded-lg font-bold">Capture Face</button>
            </div>

            <!-- Input Section -->
            <div>
              <div class="bg-yellow-100 rounded-xl mb-4 p-4 text-center">
                <img id="avatarPreview" src="img/default.png" class="w-24 h-24 mx-auto rounded-full">
                <p class="text-sm text-gray-700 mt-2">Preview of student avatar</p>
              </div>
              <input type="hidden" name="captured_face" id="captured_face">

              <input type="text" name="fullname" placeholder="Full Name" required class="w-full p-3 mb-3 border border-yellow-400 rounded-xl">
              <select name="gender" required class="w-full p-3 mb-3 border border-yellow-400 rounded-xl">
                <option value="">Select Gender</option>
                <option value="Male">Male</option>
                <option value="Female">Female</option>
              </select>
              
              <button type="submit" class="bg-green-500 hover:bg-green-600 text-white px-6 py-3 rounded-xl font-bold w-full">✅ Confirm</button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="fixed inset-0 bg-black/50 hidden z-50 flex items-center justify-center">
      <form method="POST" class="bg-white rounded-xl p-6 shadow-md w-full max-w-md">
        <h2 class="text-xl font-bold mb-4 text-[#bc6c25]">✏️ Edit Student</h2>
        <input type="hidden" name="update_id" id="edit_id">
        <input type="text" name="update_fullname" id="edit_fullname" required placeholder="Full Name" class="w-full p-3 mb-3 border border-yellow-400 rounded-xl">
        <select name="update_gender" id="edit_gender" required class="w-full p-3 mb-4 border border-yellow-400 rounded-xl">
          <option value="">Select Gender</option>
          <option value="Male">Male</option>
          <option value="Female">Female</option>
        </select>
        <div class="flex justify-end gap-2">
          <button type="button" onclick="closeEditModal()" class="px-4 py-2 bg-gray-300 rounded-xl">Cancel</button>
          <button type="submit" class="bg-green-500 hover:bg-green-600 text-white px-6 py-2 rounded-xl font-bold">Save</button>
        </div>
      </form>
    </div>
  </div>

  <?php if (!empty($_SESSION['toast'])): ?>
  <div class="fixed bottom-6 right-6 bg-<?= $_SESSION['toast_type'] === 'error' ? 'red' : 'green' ?>-500 text-white px-4 py-3 rounded-xl shadow z-50 animate-bounce">
    <?= $_SESSION['toast'] ?>
  </div>
  <script>setTimeout(() => { document.querySelector('.fixed.bottom-6').remove(); }, 3000);</script>
  <?php unset($_SESSION['toast'], $_SESSION['toast_type']); endif; ?>

  <script>
    function openEditModal(id, name, gender) {
      document.getElementById('edit_id').value = id;
      document.getElementById('edit_fullname').value = name;
      document.getElementById('edit_gender').value = gender;
      document.getElementById('editModal').classList.remove('hidden');
    }

    function closeEditModal() {
      document.getElementById('editModal').classList.add('hidden');
    }

    function confirmDelete(id) {
      if (confirm("Are you sure you want to delete this student?")) {
        window.location.href = `admin.php?page=students&delete_id=${id}`;
      }
    }

    let stream = null;
    function openAddStudentModal() {
      document.getElementById('addModal').classList.remove('hidden');
      if (!stream) {
        navigator.mediaDevices.getUserMedia({ video: true }).then(mediaStream => {
          stream = mediaStream;
          document.getElementById('video').srcObject = stream;
        }).catch(err => alert("Camera error: " + err));
      }
    }
    function closeAddStudentModal() {
      document.getElementById('addModal').classList.add('hidden');
      if (stream) {
        stream.getTracks().forEach(track => track.stop());
        stream = null;
        document.getElementById('video').srcObject = null;
      }
    }
    function captureImage() {
      const canvas = document.getElementById('canvas');
      const ctx = canvas.getContext('2d');
      ctx.drawImage(document.getElementById('video'), 0, 0, canvas.width, canvas.height);
      const data = canvas.toDataURL('image/jpeg');
      document.getElementById('captured_face').value = data;
      document.getElementById('avatarPreview').src = data;
    }
  </script>
</body>
</html>
