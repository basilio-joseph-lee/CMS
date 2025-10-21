<?php

include('../config/db.php');

if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

// Toast logic
$toast = '';
if (isset($_GET['success'])) {
  $map = [
    'add' => 'Student added successfully!',
    'update' => 'Student updated successfully!',
    'delete' => 'Student deleted successfully!',
  ];
  $toast = $map[$_GET['success']] ?? '';
}

function redirect_with_toast($type) {
  echo "<script>location.href='admin.php?page=enroll_student&success=$type';</script>";
  exit;
}

// CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (isset($_POST['create_student'])) {
    $stmt = $conn->prepare("INSERT INTO students (fullname, gender, parent_id) VALUES (?, ?, ?)");
    $stmt->bind_param("ssi", $_POST['fullname'], $_POST['gender'], $_POST['parent_id']);
    $stmt->execute(); $stmt->close();
    redirect_with_toast('add');
  }

  if (isset($_POST['update_student'])) {
    $stmt = $conn->prepare("UPDATE students SET fullname = ?, gender = ?, parent_id = ? WHERE student_id = ?");
    $stmt->bind_param("ssii", $_POST['fullname'], $_POST['gender'], $_POST['parent_id'], $_POST['student_id']);
    $stmt->execute(); $stmt->close();
    redirect_with_toast('update');
  }

  if (isset($_POST['delete_student'])) {
    $stmt = $conn->prepare("DELETE FROM students WHERE student_id = ?");
    $stmt->bind_param("i", $_POST['student_id']);
    $stmt->execute(); $stmt->close();
    redirect_with_toast('delete');
  }
}

// Fetch
$students = $conn->query("SELECT * FROM students ORDER BY fullname ASC");
$parents = $conn->query("SELECT * FROM parents ORDER BY fullname ASC");

$parentMap = [];
while ($p = $parents->fetch_assoc()) {
  $parentMap[$p['parent_id']] = $p['fullname'];
}
?>

<style>
  .modal-backdrop { backdrop-filter: blur(5px); }
  .fade-in { animation: fadeIn 0.3s ease-out; }
  @keyframes fadeIn {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
  }
  .toast {
    position: fixed; top: 1rem; right: 1rem; background-color: #38bdf8;
    color: white; padding: 1rem 1.5rem; border-radius: 0.5rem;
    box-shadow: 0 5px 15px rgba(0,0,0,0.2); z-index: 9999;
    animation: slideDown 0.5s ease, fadeOut 0.5s ease 3s forwards;
  }
  @keyframes slideDown {
    from { opacity: 0; transform: translateY(-20px); }
    to { opacity: 1; transform: translateY(0); }
  }
  @keyframes fadeOut {
    to { opacity: 0; transform: translateY(-20px); }
  }
</style>

<?php if ($toast): ?>
  <div class="toast"><?= $toast ?></div>
<?php endif; ?>

<div class="mb-4 flex items-center justify-between">
  <!-- Add button -->
  <button onclick="document.getElementById('addModal').classList.remove('hidden')" 
          class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
    ➕ Add Student
  </button>

  <!-- Search input -->
  <div class="w-1/3">
    <label class="block text-sm text-gray-600 mb-1">Search by Full Name</label>
    <input id="searchInput" type="search" placeholder="Type full name..." 
           class="w-full border p-2 rounded" oninput="filterStudents()">
    <div class="mt-2 text-right">
      <button class="text-sm text-gray-600 underline" onclick="clearSearch()">Clear search</button>
    </div>
  </div>
</div>

<table class="min-w-full bg-white shadow rounded">
  <thead class="bg-blue-100 text-gray-700">
    <tr>
      <th class="py-2 px-4 text-left">Full Name</th>
      <th class="py-2 px-4 text-left">Gender</th>
      <th class="py-2 px-4 text-left">Parent</th>
      <th class="py-2 px-4 text-left">Actions</th>
    </tr>
  </thead>
  <tbody id="studentsBody">
    <?php while ($s = $students->fetch_assoc()): ?>
    <tr class="border-t student-row" data-fullname="<?= htmlspecialchars($s['fullname'], ENT_QUOTES) ?>">
      <td class="py-2 px-4"><?= htmlspecialchars($s['fullname']) ?></td>
      <td class="py-2 px-4"><?= htmlspecialchars($s['gender']) ?></td>
      <td class="py-2 px-4 text-sm italic text-gray-500"><?= $parentMap[$s['parent_id']] ?? 'None' ?></td>
      <td class="py-2 px-4 space-x-2">
        <button onclick="document.getElementById('editModal<?= $s['student_id'] ?>').classList.remove('hidden')" class="bg-yellow-500 text-white px-3 py-1 rounded">Edit</button>
        <button onclick="document.getElementById('deleteModal<?= $s['student_id'] ?>').classList.remove('hidden')" class="bg-red-600 text-white px-3 py-1 rounded">Delete</button>
      </td>
    </tr>

    <!-- Edit Modal -->
    <div id="editModal<?= $s['student_id'] ?>" class="modal-backdrop fixed inset-0 bg-black bg-opacity-40 flex items-center justify-center hidden z-50">
      <div class="bg-white p-6 rounded shadow max-w-md w-full fade-in">
        <h3 class="text-xl font-bold mb-4">Edit Student</h3>
        <form method="POST">
          <input type="hidden" name="update_student" value="1">
          <input type="hidden" name="student_id" value="<?= $s['student_id'] ?>">
          <div class="mb-3">
            <label>Full Name</label>
            <input type="text" name="fullname" value="<?= htmlspecialchars($s['fullname']) ?>" class="w-full border p-2 rounded" required>
          </div>
          <div class="mb-3">
            <label>Gender</label>
            <select name="gender" class="w-full border p-2 rounded" required>
              <option value="Male" <?= $s['gender'] === 'Male' ? 'selected' : '' ?>>Male</option>
              <option value="Female" <?= $s['gender'] === 'Female' ? 'selected' : '' ?>>Female</option>
            </select>
          </div>
          <div class="mb-3">
            <label>Parent</label>
            <select name="parent_id" class="w-full border p-2 rounded">
              <option value="">None</option>
              <?php foreach ($parentMap as $id => $name): ?>
              <option value="<?= $id ?>" <?= $s['parent_id'] == $id ? 'selected' : '' ?>><?= $name ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="bg-yellow-500 text-white px-4 py-2 rounded">Update</button>
          <button type="button" onclick="document.getElementById('editModal<?= $s['student_id'] ?>').classList.add('hidden')" class="ml-2 text-gray-600">Cancel</button>
        </form>
      </div>
    </div>

    <!-- Delete Modal -->
    <div id="deleteModal<?= $s['student_id'] ?>" class="modal-backdrop fixed inset-0 bg-black bg-opacity-40 flex items-center justify-center hidden z-50">
      <div class="bg-white p-6 rounded shadow max-w-sm w-full fade-in">
        <h3 class="text-lg font-bold mb-4">Confirm Deletion</h3>
        <p>Are you sure you want to delete <strong><?= htmlspecialchars($s['fullname']) ?></strong>?</p>
        <form method="POST" class="mt-4">
          <input type="hidden" name="delete_student" value="1">
          <input type="hidden" name="student_id" value="<?= $s['student_id'] ?>">
          <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded">Yes, Delete</button>
          <button type="button" onclick="document.getElementById('deleteModal<?= $s['student_id'] ?>').classList.add('hidden')" class="ml-2 text-gray-600">Cancel</button>
        </form>
      </div>
    </div>
    <?php endwhile; ?>
  </tbody>
</table>

<!-- Add Modal -->
<div id="addModal" class="modal-backdrop fixed inset-0 bg-black bg-opacity-40 flex items-center justify-center hidden z-50">
  <div class="bg-white p-6 rounded shadow max-w-md w-full fade-in">
    <h3 class="text-xl font-bold mb-4">Add New Student</h3>
    <form method="POST">
      <input type="hidden" name="create_student" value="1">
      <div class="mb-3">
        <label>Full Name</label>
        <input type="text" name="fullname" class="w-full border p-2 rounded" required>
      </div>
      <div class="mb-3">
        <label>Gender</label>
        <select name="gender" class="w-full border p-2 rounded" required>
          <option value="Male">Male</option>
          <option value="Female">Female</option>
        </select>
      </div>
      <div class="mb-3">
        <label>Parent</label>
        <select name="parent_id" class="w-full border p-2 rounded">
          <option value="">None</option>
          <?php foreach ($parentMap as $id => $name): ?>
          <option value="<?= $id ?>"><?= $name ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Save</button>
      <button type="button" onclick="document.getElementById('addModal').classList.add('hidden')" class="ml-2 text-gray-600">Cancel</button>
    </form>
  </div>
</div>

<script>
  // Realtime Full Name search
  const searchInput = document.getElementById('searchInput');
  const studentsBody = document.getElementById('studentsBody');

  function clearSearch() {
    searchInput.value = '';
    filterStudents();
  }

  function filterStudents() {
    const q = searchInput.value.trim().toLowerCase();
    const rows = studentsBody.querySelectorAll('.student-row');
    let anyVisible = false;

    rows.forEach(row => {
      const fullname = (row.getAttribute('data-fullname') || '').toLowerCase();
      const visible = q === '' || fullname.includes(q);
      row.style.display = visible ? '' : 'none';
      if (visible) anyVisible = true;
    });

    // "No results" row
    let noRow = document.getElementById('no_results_row_students');
    if (!anyVisible) {
      if (!noRow) {
        noRow = document.createElement('tr');
        noRow.id = 'no_results_row_students';
        noRow.innerHTML = '<td colspan="4" class="py-6 px-3 text-center text-gray-500 italic">No students found.</td>';
        studentsBody.appendChild(noRow);
      }
    } else {
      if (noRow) noRow.remove();
    }
  }

  filterStudents();
</script>
