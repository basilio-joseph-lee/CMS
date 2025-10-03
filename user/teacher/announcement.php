<?php
session_start();
include '../../config/teacher_guard.php';
include '../../config/db.php';


$teacher_id = $_SESSION['teacher_id'];
$subject_id = $_SESSION['subject_id'];
$advisory_id = $_SESSION['advisory_id'];
$school_year_id = $_SESSION['school_year_id'];

$subject_name = $_SESSION['subject_name'];
$class_name = $_SESSION['class_name'];
$year_label = $_SESSION['year_label'];
$teacherName = $_SESSION['teacher_fullname'] ?? 'Teacher';


if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

// --- Create ---
if (isset($_POST['add'])) {
  $title = $_POST['title'];
  $message = $_POST['message'];
  $subject_id = $_POST['subject_id'];
  $class_id = $_POST['class_id'];
  $visible_until = $_POST['visible_until'] ?: NULL;

  $stmt = $conn->prepare("INSERT INTO announcements (teacher_id, subject_id, class_id, title, message, visible_until) VALUES (?, ?, ?, ?, ?, ?)");
  $stmt->bind_param("iiisss", $teacher_id, $subject_id, $class_id, $title, $message, $visible_until);
  $stmt->execute();
  $stmt->close();
  header("Location: announcement.php?success=added");
  exit;
}

// --- Delete ---
if (isset($_GET['delete'])) {
  $id = $_GET['delete'];
  $conn->query("DELETE FROM announcements WHERE id = $id");
  header("Location: announcement.php?success=deleted");
  exit;
}

// --- Edit Fetch ---
$edit_data = null;
if (isset($_GET['edit'])) {
  $id = $_GET['edit'];
  $result = $conn->query("SELECT * FROM announcements WHERE id = $id");
  $edit_data = $result->fetch_assoc();
}

// --- Update ---
if (isset($_POST['update'])) {
  $id = $_POST['id'];
  $title = $_POST['title'];
  $message = $_POST['message'];
  $subject_id = $_POST['subject_id'];
  $class_id = $_POST['class_id'];
  $visible_until = $_POST['visible_until'] ?: NULL;

  $stmt = $conn->prepare("UPDATE announcements SET title=?, message=?, subject_id=?, class_id=?, visible_until=? WHERE id=?");
  $stmt->bind_param("ssissi", $title, $message, $subject_id, $class_id, $visible_until, $id);
  $stmt->execute();
  $stmt->close();
  header("Location: announcement.php?success=updated");
  exit;
}

$subjects = $conn->query("SELECT subject_id, subject_name FROM subjects WHERE teacher_id = $teacher_id");
$classes = $conn->query("SELECT advisory_id, class_name FROM advisory_classes WHERE teacher_id = $teacher_id");
$res = $conn->query("SELECT a.*, s.subject_name, c.class_name FROM announcements a 
                     LEFT JOIN subjects s ON a.subject_id = s.subject_id 
                     LEFT JOIN advisory_classes c ON a.class_id = c.advisory_id
                     WHERE a.teacher_id = $teacher_id ORDER BY a.date_posted DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>📣 Announcements</title>
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
<?php if (isset($_GET['success'])): ?>
  <div class="fixed top-4 right-4 bg-green-500 text-white px-6 py-3 rounded-xl shadow-xl z-50">
    ✅ Announcement <?= htmlspecialchars($_GET['success']) ?> successfully!
  </div>
  <script>
    setTimeout(() => document.querySelector('.fixed.top-4.right-4')?.remove(), 3000);
  </script>
<?php endif; ?>

<div class="max-w-6xl mx-auto bg-[#fffbea] p-10 rounded-3xl shadow-2xl ring-4 ring-yellow-300">
  <div class="flex justify-between items-center mb-6">
    <div>
      <h1 class="text-4xl font-bold text-[#bc6c25]">📣 Post Announcements</h1>
      <p class="text-lg font-semibold text-gray-800 mt-2">Subject: 
        <span class="text-green-700"><?= $subject_name ?></span> — 
        <span class="text-blue-700"><?= $class_name ?></span> | SY: 
        <span class="text-red-700"><?= $year_label ?></span></p>
    </div>
    <div class="space-x-4">
      <button onclick="openAddModal()" class="bg-green-500 hover:bg-green-600 text-white px-6 py-2 rounded-xl shadow">+ Add</button>
      <a href="teacher_dashboard.php" class="bg-orange-400 hover:bg-orange-500 text-white text-lg font-bold px-6 py-2 rounded-lg shadow-md">← Back</a>
    </div>
  </div>

  <!-- Announcement Table -->
  <div class="overflow-x-auto">
    <table class="w-full text-left border-4 border-yellow-300 rounded-xl overflow-hidden bg-white">
      <thead class="bg-yellow-200">
        <tr>
          <th class="p-3 text-lg">📌 Title</th>
          <th class="p-3 text-lg">Message</th>
          <th class="p-3 text-lg">Subject</th>
          <th class="p-3 text-lg">Section</th>
          <th class="p-3 text-lg">Date</th>
          <th class="p-3 text-lg">Visible Until</th>
          <th class="p-3 text-center text-lg">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($res->num_rows > 0): while ($row = $res->fetch_assoc()): ?>
        <tr class="border-b hover:bg-yellow-50">
          <td class="p-3 font-semibold"><?= htmlspecialchars($row['title']) ?></td>
          <td class="p-3"><?= htmlspecialchars($row['message']) ?></td>
          <td class="p-3"><?= $row['subject_name'] ?? 'N/A' ?></td>
          <td class="p-3"><?= $row['class_name'] ?? 'N/A' ?></td>
          <td class="p-3"><?= date('M d, Y h:i A', strtotime($row['date_posted'])) ?></td>
          <td class="p-3"><?= $row['visible_until'] ? date('M d, Y', strtotime($row['visible_until'])) : '—' ?></td>
          <td class="p-3 text-center">
            <button onclick='openEditModal(<?= json_encode($row) ?>)' class="text-yellow-600 hover:underline">Edit</button> |
            <button onclick="confirmDelete(<?= $row['id'] ?>)" class="text-red-600 hover:underline">Delete</button>
          </td>
        </tr>
        <?php endwhile; else: ?>
        <tr><td colspan="7" class="text-center text-gray-500 py-6">No announcements found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal Form -->
<div id="modal" class="fixed inset-0 bg-black bg-opacity-40 flex items-center justify-center z-50 hidden">
  <form method="POST" class="bg-white rounded-2xl p-8 w-full max-w-xl shadow-2xl space-y-4">
    <h2 id="modalTitle" class="text-2xl font-bold mb-4 text-yellow-800">📢 New Announcement</h2>
    <input type="hidden" name="id" id="announcementId">
    <div>
      <label class="block font-semibold">Title</label>
      <input type="text" name="title" id="title" required class="w-full border-2 border-yellow-300 rounded px-3 py-2">
    </div>
    <div>
      <label class="block font-semibold">Message</label>
      <textarea name="message" id="message" required class="w-full border-2 border-yellow-300 rounded px-3 py-2"></textarea>
    </div>
    <div class="grid grid-cols-2 gap-4">
      <div>
        <label class="block font-semibold">Subject</label>
        <select name="subject_id" id="subject_id" required class="w-full border-2 border-yellow-300 rounded px-3 py-2">
          <?php
          $subjects2 = $conn->query("SELECT subject_id, subject_name FROM subjects WHERE teacher_id = $teacher_id");
          while ($s = $subjects2->fetch_assoc()) echo "<option value='{$s['subject_id']}'>{$s['subject_name']}</option>";
          ?>
        </select>
      </div>
      <div>
        <label class="block font-semibold">Section</label>
        <select name="class_id" id="class_id" required class="w-full border-2 border-yellow-300 rounded px-3 py-2">
          <?php
          $classes2 = $conn->query("SELECT advisory_id, class_name FROM advisory_classes WHERE teacher_id = $teacher_id");
          while ($c = $classes2->fetch_assoc()) echo "<option value='{$c['advisory_id']}'>{$c['class_name']}</option>";
          ?>
        </select>
      </div>
    </div>
    <div>
      <label class="block font-semibold">Visible Until</label>
      <input type="date" name="visible_until" id="visible_until" class="w-full border-2 border-yellow-300 rounded px-3 py-2">
    </div>
    <div class="flex justify-end gap-4 mt-4">
      <button type="button" onclick="closeModal()" class="px-4 py-2 rounded bg-gray-400 text-white hover:bg-gray-500">Cancel</button>
      <button type="submit" name="add" id="submitBtn" class="px-6 py-2 rounded bg-green-500 text-white hover:bg-green-600 shadow">Post</button>
    </div>
  </form>
</div>

<script>
function openAddModal() {
  document.getElementById('modalTitle').innerText = '📢 New Announcement';
  document.getElementById('submitBtn').name = 'add';
  document.getElementById('announcementId').value = '';
  document.getElementById('title').value = '';
  document.getElementById('message').value = '';
  document.getElementById('visible_until').value = '';
  document.getElementById('modal').classList.remove('hidden');
}

function openEditModal(data) {
  document.getElementById('modalTitle').innerText = '✏️ Edit Announcement';
  document.getElementById('submitBtn').name = 'update';
  document.getElementById('announcementId').value = data.id;
  document.getElementById('title').value = data.title;
  document.getElementById('message').value = data.message;
  document.getElementById('subject_id').value = data.subject_id;
  document.getElementById('class_id').value = data.class_id;
  document.getElementById('visible_until').value = data.visible_until;
  document.getElementById('modal').classList.remove('hidden');
}

function closeModal() {
  document.getElementById('modal').classList.add('hidden');
}

function confirmDelete(id) {
  if (confirm('Are you sure you want to delete this announcement?')) {
    window.location.href = '?delete=' + id;
  }
}
</script>
</body>
</html>
