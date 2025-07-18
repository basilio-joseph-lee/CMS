<?php
session_start();
if (!isset($_SESSION['teacher_id'])) {
  header("Location: teacher_login.php");
  exit;
}

$host = "localhost";
$dbname = "cms";
$db_user = "root";
$db_pass = "";

$conn = new mysqli($host, $db_user, $db_pass, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Handle edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_student'])) {
  $id = $_POST['student_id'];
  $name = $_POST['fullname'];
  $gender = $_POST['gender'];
  $stmt = $conn->prepare("UPDATE students SET fullname = ?, gender = ? WHERE student_id = ?");
  $stmt->bind_param("ssi", $name, $gender, $id);
  $stmt->execute();
  $_SESSION['toast'] = "Student updated successfully!";
  $_SESSION['toast_type'] = "success";
  header("Location: view_students.php");
  exit;
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_student'])) {
  $id = $_POST['student_id'];
  $stmt = $conn->prepare("DELETE FROM students WHERE student_id = ?");
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $_SESSION['toast'] = "Student deleted successfully!";
  $_SESSION['toast_type'] = "error";
  header("Location: view_students.php");
  exit;
}

$subject_id = $_SESSION['subject_id'];
$advisory_id = $_SESSION['advisory_id'];
$school_year_id = $_SESSION['school_year_id'];
$subject_name = $_SESSION['subject_name'];
$class_name = $_SESSION['class_name'];
$year_label = $_SESSION['year_label'];
$teacherName = $_SESSION['fullname'];

$stmt = $conn->prepare("SELECT s.student_id, s.fullname, s.gender, s.avatar_path 
                        FROM students s
                        JOIN student_enrollments e ON s.student_id = e.student_id
                        WHERE e.subject_id = ? AND e.advisory_id = ? AND e.school_year_id = ?");
$stmt->bind_param("iii", $subject_id, $advisory_id, $school_year_id);
$stmt->execute();
$result = $stmt->get_result();
$students = $result->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>View Students</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body {
      background-image: url('../../img/role.png');
      background-size: cover;
      background-position: center;
      font-family: 'Comic Sans MS', cursive, sans-serif;
    }
  </style>
</head>
<body class="px-6 py-8">

<?php if (isset($_SESSION['toast'])): ?>
  <div id="toast" class="fixed bottom-6 left-1/2 transform -translate-x-1/2 z-50 px-6 py-3 rounded-lg shadow-lg transition-opacity duration-500
  <?= $_SESSION['toast_type'] === 'success' ? 'bg-green-500' : 'bg-red-500' ?> text-white font-semibold text-center">
  <?= $_SESSION['toast'] ?>
</div>

  <script>
    setTimeout(() => {
      const toast = document.getElementById('toast');
      toast.style.opacity = '0';
      setTimeout(() => toast.remove(), 500);
    }, 3000);
  </script>
  <?php unset($_SESSION['toast'], $_SESSION['toast_type']); ?>
<?php endif; ?>

<div class="flex justify-between items-center bg-green-800 text-white px-6 py-4 rounded-xl shadow-lg mb-6">
  <div>
    <h1 class="text-xl font-bold">👨‍🏫 <?= $teacherName ?></h1>
    <p class="text-sm">
      Subject: <?= $subject_name ?> |
      Section: <?= $class_name ?> |
      SY: <?= $year_label ?>
    </p>
  </div>
  <form action="../teacher_dashboard.php" method="post">
    <input type="hidden" name="subject_id" value="<?= $subject_id ?>">
    <input type="hidden" name="advisory_id" value="<?= $advisory_id ?>">
    <input type="hidden" name="school_year_id" value="<?= $school_year_id ?>">
    <input type="hidden" name="subject_name" value="<?= $subject_name ?>">
    <input type="hidden" name="class_name" value="<?= $class_name ?>">
    <input type="hidden" name="year_label" value="<?= $year_label ?>">
    <button type="submit" class="bg-orange-400 hover:bg-orange-500 px-4 py-2 rounded-lg font-semibold shadow text-white">← Back</button>
  </form>
</div>

<div class="mb-4">
  <input type="text" id="searchInput" placeholder="Search student..." class="px-4 py-2 border rounded-lg w-full">
</div>

<h2 class="text-2xl font-bold text-gray-800 mb-4">View Students</h2>
<div class="overflow-x-auto bg-white p-4 rounded-xl shadow-lg">
  <table id="studentsTable" class="min-w-full text-sm text-left border border-gray-300">
    <thead class="bg-yellow-300 text-gray-800 uppercase text-xs font-bold">
      <tr>
        <th class="px-4 py-3 border">#</th>
        <th class="px-4 py-3 border">Avatar</th>
        <th class="px-4 py-3 border">Full Name</th>
        <th class="px-4 py-3 border">Gender</th>
        <th class="px-4 py-3 border">Actions</th>
      </tr>
    </thead>
    <tbody class="text-gray-800 bg-white">
      <?php $i = 1; foreach ($students as $student): ?>
        <tr class="border-b">
          <td class="px-4 py-3 border"><?= $i++ ?></td>
          <td class="px-4 py-3 border">
            <?php if (!empty($student['avatar_path']) && file_exists('../' . $student['avatar_path'])): ?>
              <img src="../<?= $student['avatar_path'] ?>" alt="Avatar" class="w-10 h-10 rounded-full object-cover">
            <?php else: ?>
              <div class="w-10 h-10 bg-yellow-300 rounded-full flex items-center justify-center text-lg">👤</div>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3 border name-cell"><?= htmlspecialchars($student['fullname']) ?></td>
          <td class="px-4 py-3 border"><?= $student['gender'] ?></td>
          <td class="px-4 py-3 border">
            <button onclick="openEditModal(<?= $student['student_id'] ?>)" class="text-blue-600 text-sm hover:underline mr-2">✏️ Edit</button>
            <button onclick="openDeleteModal(<?= $student['student_id'] ?>)" class="text-red-600 text-sm hover:underline">🗑️ Delete</button>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php foreach ($students as $student): ?>
  <div id="editModal<?= $student['student_id'] ?>" class="fixed inset-0 z-50 hidden bg-black bg-opacity-50 flex justify-center items-center">
    <form method="POST" class="bg-white p-6 rounded-lg shadow-lg w-96 space-y-4">
      <input type="hidden" name="student_id" value="<?= $student['student_id'] ?>">
      <input type="hidden" name="edit_student" value="1">
      <h2 class="text-lg font-bold">Edit Student</h2>
      <label class="block text-sm font-semibold">Full Name</label>
      <input type="text" name="fullname" value="<?= htmlspecialchars($student['fullname']) ?>" class="w-full border rounded px-3 py-2">
      <label class="block text-sm font-semibold">Gender</label>
      <select name="gender" class="w-full border rounded px-3 py-2">
        <option value="Male" <?= $student['gender'] === 'Male' ? 'selected' : '' ?>>Male</option>
        <option value="Female" <?= $student['gender'] === 'Female' ? 'selected' : '' ?>>Female</option>
      </select>
      <div class="flex justify-end gap-2 pt-4">
        <button type="button" onclick="closeModal('editModal<?= $student['student_id'] ?>')" class="text-gray-600">Cancel</button>
        <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded">Update</button>
      </div>
    </form>
  </div>

  <div id="deleteModal<?= $student['student_id'] ?>" class="fixed inset-0 z-50 hidden bg-black bg-opacity-50 flex justify-center items-center">
    <form method="POST" class="bg-white p-6 rounded-lg shadow-lg w-96 space-y-4">
      <input type="hidden" name="student_id" value="<?= $student['student_id'] ?>">
      <input type="hidden" name="delete_student" value="1">
      <h2 class="text-lg font-bold text-gray-800">Delete Student</h2>
      <p>Are you sure you want to delete <strong><?= htmlspecialchars($student['fullname']) ?></strong>?</p>
      <div class="flex justify-end gap-2">
        <button type="button" onclick="closeModal('deleteModal<?= $student['student_id'] ?>')" class="text-gray-600">Cancel</button>
        <button type="submit" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded">Delete</button>
      </div>
    </form>
  </div>
<?php endforeach; ?>

<script>
  function openEditModal(id) {
    document.getElementById('editModal' + id).classList.remove('hidden');
  }
  function openDeleteModal(id) {
    document.getElementById('deleteModal' + id).classList.remove('hidden');
  }
  function closeModal(id) {
    document.getElementById(id).classList.add('hidden');
  }

  document.getElementById('searchInput').addEventListener('input', function () {
    const filter = this.value.toLowerCase();
    const rows = document.querySelectorAll('#studentsTable tbody tr');
    rows.forEach(row => {
      const nameCell = row.querySelector('.name-cell');
      const name = nameCell.textContent.toLowerCase();
      row.style.display = name.includes(filter) ? '' : 'none';
    });
  });
</script>

</body>
</html>
