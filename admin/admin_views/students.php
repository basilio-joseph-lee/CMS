<?php
// SESSION + DB
//session_start();
$conn = new mysqli("localhost", "root", "", "cms");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

// POST HANDLERS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $fullname = $_POST['fullname'];
  $gender = $_POST['gender'];
  $advisory_id = $_POST['advisory_id'];
  $subject_id = $_POST['subject_id'];
  $school_year_id = $_POST['school_year_id'];
  $action = $_POST['action'];
  $student_id = $_POST['student_id'] ?? null;

  if ($action === 'add') {
    $face_image = $_POST['avatar'] ?? '';
    $face_image = str_replace('data:image/jpeg;base64,', '', $face_image);
    $face_image = base64_decode($face_image);

    if (!file_exists('student_faces')) mkdir('student_faces', 0777, true);
    $face_filename = 'student_faces/' . uniqid('face_') . '.jpg';
    file_put_contents($face_filename, $face_image);

    if (!file_exists('student_avatars')) mkdir('student_avatars', 0777, true);
    $avatar_filename = 'student_avatars/' . uniqid('avatar_') . '.jpg';
    $output = shell_exec("python cartoonify.py $face_filename $avatar_filename");

    if (strpos($output, 'OK') === false) {
      echo "⚠️ Avatar cartoonify failed. Output: " . htmlspecialchars($output);
      exit;
    }

    $stmt = $conn->prepare("INSERT INTO students (fullname, gender, face_image_path, avatar_path) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $fullname, $gender, $face_filename, $avatar_filename);
    $stmt->execute();
    $newStudentId = $stmt->insert_id;

    $stmt2 = $conn->prepare("INSERT INTO student_enrollments (student_id, advisory_id, school_year_id, subject_id) VALUES (?, ?, ?, ?)");
    $stmt2->bind_param("iiii", $newStudentId, $advisory_id, $school_year_id, $subject_id);
    $stmt2->execute();
  }

  if ($action === 'edit') {
    $stmt = $conn->prepare("UPDATE students SET fullname=?, gender=? WHERE student_id=?");
    $stmt->bind_param("ssi", $fullname, $gender, $student_id);
    $stmt->execute();

    $stmt2 = $conn->prepare("UPDATE student_enrollments SET advisory_id=?, school_year_id=?, subject_id=? WHERE student_id=?");
    $stmt2->bind_param("iiii", $advisory_id, $school_year_id, $subject_id, $student_id);
    $stmt2->execute();
  }

  echo "<script>location.href='admin.php?page=students';

// Webcam setup
const video = document.getElementById('video');
  })
  .catch(err => {
    console.error('Camera access denied:', err);
  });

function captureFace() {
  const canvas = document.createElement('canvas');
  canvas.width = video.videoWidth;
  canvas.height = video.videoHeight;
  const ctx = canvas.getContext('2d');
  ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

  const base64Image = canvas.toDataURL('image/jpeg');
  document.getElementById('avatarData').value = base64Image;

  // Optional: Send to server.py backend
  fetch('http://127.0.0.1:5000/register', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ image: base64Image })
  })
  .then(res => res.json())
  .then(data => {
    console.log('Face registered:', data);
    alert('✅ Face registered successfully');
  })
  .catch(err => {
    console.error('Registration failed:', err);
    alert('❌ Face registration failed.');
  });
}
</script>";
  exit;
}

if (isset($_GET['delete_id'])) {
  $id = $_GET['delete_id'];
  $conn->query("DELETE FROM student_enrollments WHERE student_id = $id");
  $conn->query("DELETE FROM attendance_records WHERE student_id = $id");
  $conn->query("DELETE FROM students WHERE student_id = $id");
  echo "<script>location.href='admin.php?page=students';</script>";
  exit;
}

$schoolYears = $conn->query("SELECT * FROM school_years ORDER BY year_label DESC");
$sections = $conn->query("SELECT * FROM advisory_classes ORDER BY class_name ASC");
$subjects = $conn->query("SELECT * FROM subjects ORDER BY subject_name ASC");

$filterYear = $_GET['school_year_id'] ?? '';
$filterSection = $_GET['advisory_id'] ?? '';
$where = "WHERE 1=1";
if ($filterYear) $where .= " AND se.school_year_id = " . intval($filterYear);
if ($filterSection) $where .= " AND se.advisory_id = " . intval($filterSection);

$query = "
  SELECT s.*, se.advisory_id, se.subject_id, se.school_year_id,
         ac.class_name, sy.year_label, subj.subject_name
  FROM students s
  JOIN student_enrollments se ON s.student_id = se.student_id
  LEFT JOIN advisory_classes ac ON se.advisory_id = ac.advisory_id
  LEFT JOIN school_years sy ON se.school_year_id = sy.school_year_id
  LEFT JOIN subjects subj ON se.subject_id = subj.subject_id
  $where
  ORDER BY s.fullname ASC
";
$students = $conn->query($query);
?>

<div class="flex justify-between items-center mb-4">
  <!-- Removed duplicate heading -->
  <button onclick="openAddModal()" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
    ➕ Add Student
  </button>
</div>

<form method="GET" action="admin.php" class="mb-6 flex flex-wrap gap-4">
  <input type="hidden" name="page" value="students">
  <select name="school_year_id" class="p-2 border rounded">
    <option value="">All Years</option>
    <?php $schoolYears->data_seek(0); while ($y = $schoolYears->fetch_assoc()): ?>
      <option value="<?= $y['school_year_id'] ?>" <?= $filterYear == $y['school_year_id'] ? 'selected' : '' ?>>
        <?= $y['year_label'] ?>
      </option>
    <?php endwhile; ?>
  </select>
  <select name="advisory_id" class="p-2 border rounded">
    <option value="">All Sections</option>
    <?php $sections->data_seek(0); while ($s = $sections->fetch_assoc()): ?>
      <option value="<?= $s['advisory_id'] ?>" <?= $filterSection == $s['advisory_id'] ? 'selected' : '' ?>>
        <?= $s['class_name'] ?>
      </option>
    <?php endwhile; ?>
  </select>
  <button type="submit" class="bg-gray-800 text-white px-4 py-2 rounded hover:bg-gray-700">Filter</button>
</form>

<table class="w-full table-auto bg-white shadow rounded text-sm">
  <thead class="bg-gray-200">
    <tr>
      <th class="px-4 py-2">Avatar</th>
      <th class="px-4 py-2">Name</th>
      <th class="px-4 py-2">Gender</th>
      <th class="px-4 py-2">Section</th>
      <th class="px-4 py-2">Subject</th>
      <th class="px-4 py-2">Year</th>
      <th class="px-4 py-2">Action</th>
    </tr>
  </thead>
  <tbody>
    <?php while ($row = $students->fetch_assoc()): ?>
      <tr class="border-t">
        <td class="px-4 py-2">
          <img src="<?= !empty($row['avatar_path']) ? $row['avatar_path'] : 'img/default.png' ?>" class="w-10 h-10 rounded-full object-cover">
        </td>
        <td class="px-4 py-2"><?= $row['fullname'] ?></td>
        <td class="px-4 py-2"><?= $row['gender'] ?></td>
        <td class="px-4 py-2"><?= $row['class_name'] ?></td>
        <td class="px-4 py-2"><?= $row['subject_name'] ?></td>
        <td class="px-4 py-2"><?= $row['year_label'] ?></td>
        <td class="px-4 py-2 space-x-2">
          <button onclick='openEditModal(<?= json_encode($row) ?>)' class="text-blue-600 hover:underline">✏️ Edit</button>
          <a href="admin.php?page=students&delete_id=<?= $row['student_id'] ?>" onclick="return confirm('Delete this student and all records?')" class="text-red-600 hover:underline">🗑 Delete</a>
        </td>
      </tr>
    <?php endwhile; ?>
  </tbody>
</table>

<!-- MODAL -->
<!-- Modal -->
<div id="studentModal" class="fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center hidden z-50 opacity-0 transition-opacity duration-1000 ease-in-out">
  <div class="bg-white p-6 rounded-lg shadow-lg w-full max-w-4xl relative">
    <h2 id="modalTitle" class="text-2xl font-bold mb-6 text-blue-800">Add/Edit Student</h2>

    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" id="formAction">
      <input type="hidden" name="student_id" id="studentId">

      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
          <label class="block mb-1 font-semibold">Full Name</label>
          <input type="text" name="fullname" id="fullname" required class="w-full p-2 border rounded">
        </div>
        <div>
          <label class="block mb-1 font-semibold">Gender</label>
          <select name="gender" id="gender" required class="w-full p-2 border rounded">
            <option value="">Select Gender</option>
            <option value="Male">Male</option>
            <option value="Female">Female</option>
          </select>
        </div>
        <div>
          <label class="block mb-1 font-semibold">Section</label>
          <select name="advisory_id" id="advisoryId" required class="w-full p-2 border rounded">
            <option value="">Select Section</option>
            <?php $sections->data_seek(0); while ($sec = $sections->fetch_assoc()): ?>
              <option value="<?= $sec['advisory_id'] ?>"><?= $sec['class_name'] ?></option>
            <?php endwhile; ?>
          </select>
        </div>
        <div>
          <label class="block mb-1 font-semibold">Subject</label>
          <select name="subject_id" id="subjectId" required class="w-full p-2 border rounded">
            <option value="">Select Subject</option>
            <?php $subjects->data_seek(0); while ($sub = $subjects->fetch_assoc()): ?>
              <option value="<?= $sub['subject_id'] ?>"><?= $sub['subject_name'] ?></option>
            <?php endwhile; ?>
          </select>
        </div>
        <div>
          <label class="block mb-1 font-semibold">School Year</label>
          <select name="school_year_id" id="schoolYearId" required class="w-full p-2 border rounded">
            <option value="">Select Year</option>
            <?php $schoolYears->data_seek(0); while ($sy = $schoolYears->fetch_assoc()): ?>
              <option value="<?= $sy['school_year_id'] ?>"><?= $sy['year_label'] ?></option>
            <?php endwhile; ?>
          </select>
        </div>
        <div>
  <label class="block mb-1 font-semibold">Live Capture</label>
  <video id="video" autoplay class="w-full h-48 bg-black rounded"></video>
  <button type="button" onclick="captureFace()" class="mt-2 w-full bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">📸 Capture Face</button>
  <input type="hidden" name="avatar" id="avatarData">
</div>
      </div>

      <div class="text-right mt-6">
        <button type="button" onclick="closeModal()" class="mr-2 px-4 py-2 border rounded">Cancel</button>
        <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">Save</button>
      </div>
    </form>
  </div>
</div>

<script>
function openAddModal() {
  const modal = document.getElementById('studentModal');
  document.getElementById('modalTitle').innerText = "➕ Add Student";
  document.getElementById('formAction').value = 'add';
  document.getElementById('studentId').value = '';
  document.getElementById('fullname').value = '';
  document.getElementById('gender').value = '';
  document.getElementById('advisoryId').value = '';
  document.getElementById('subjectId').value = '';
  document.getElementById('schoolYearId').value = '';
  modal.classList.remove('hidden');
  modal.classList.add('opacity-0');
  setTimeout(() => modal.classList.remove('opacity-0'), 50);

  // Start webcam
  if (!video.srcObject) {
    navigator.mediaDevices.getUserMedia({ video: true }).then(stream => {
      video.srcObject = stream;
    }).catch(err => {
      console.error('Webcam error:', err);
    });
  }
}

function openEditModal(data) {
  document.getElementById('modalTitle').innerText = "✏️ Edit Student";
  document.getElementById('formAction').value = 'edit';
  document.getElementById('studentId').value = data.student_id;
  document.getElementById('fullname').value = data.fullname;
  document.getElementById('gender').value = data.gender;
  document.getElementById('advisoryId').value = data.advisory_id;
  document.getElementById('subjectId').value = data.subject_id;
  document.getElementById('schoolYearId').value = data.school_year_id;
  
  const modal = document.getElementById('studentModal');
  modal.classList.remove('hidden');
  modal.classList.add('opacity-0');
  setTimeout(() => modal.classList.remove('opacity-0'), 50);
}

function closeModal() {
  const modal = document.getElementById('studentModal');
  modal.classList.add('opacity-0');
  setTimeout(() => modal.classList.add('hidden'), 1000);
}
</script>
