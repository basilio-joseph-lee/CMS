<?php
// --- Handle Toast Logic & Actions ---
$toast = '';
if (isset($_GET['success'])) {
  if ($_GET['success'] === 'add') $toast = 'Teacher added successfully!';
  elseif ($_GET['success'] === 'update') $toast = 'Teacher updated successfully!';
  elseif ($_GET['success'] === 'delete') $toast = 'Teacher deleted successfully!';
  elseif ($_GET['success'] === 'assigned') $toast = 'Teacher assigned successfully!';
  elseif ($_GET['success'] === 'conflict') $toast = '⚠️ That section and subject is already assigned to another teacher.';
}

function redirect_with_toast($type) {
  echo "<script>location.href='admin.php?page=teachers&success=$type';</script>";
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (isset($_POST['create_teacher'])) {
    $stmt = $conn->prepare("INSERT INTO teachers (fullname, username, password) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $_POST['fullname'], $_POST['username'], password_hash($_POST['password'], PASSWORD_DEFAULT));
    $stmt->execute(); $stmt->close();
    redirect_with_toast('add');
  }

  if (isset($_POST['update_teacher'])) {
    if (!empty($_POST['password'])) {
      $stmt = $conn->prepare("UPDATE teachers SET fullname = ?, username = ?, password = ? WHERE teacher_id = ?");
      $hashedPassword = password_hash($_POST['password'], PASSWORD_DEFAULT);
      $stmt->bind_param("sssi", $_POST['fullname'], $_POST['username'], $hashedPassword, $_POST['teacher_id']);
    } else {
      $stmt = $conn->prepare("UPDATE teachers SET fullname = ?, username = ? WHERE teacher_id = ?");
      $stmt->bind_param("ssi", $_POST['fullname'], $_POST['username'], $_POST['teacher_id']);
    }
    $stmt->execute(); $stmt->close();
    redirect_with_toast('update');
  }

  if (isset($_POST['delete_teacher'])) {
    $stmt = $conn->prepare("DELETE FROM teachers WHERE teacher_id = ?");
    $stmt->bind_param("i", $_POST['teacher_id']);
    $stmt->execute(); $stmt->close();
    redirect_with_toast('delete');
  }

  if (isset($_POST['assign_teacher'])) {
    $teacherId = $_POST['teacher_id'];
    $subjectName = trim($_POST['subject_name']);
    $sectionName = trim($_POST['section_name']);
    $schoolYearId = $_POST['school_year_id'];

    // Check if same subject and section is already assigned (regardless of teacher)
    $conflictCheck = $conn->prepare("SELECT * FROM subjects s JOIN advisory_classes ac ON s.advisory_id = ac.advisory_id WHERE s.subject_name = ? AND ac.class_name = ? AND s.school_year_id = ?");
    $conflictCheck->bind_param("ssi", $subjectName, $sectionName, $schoolYearId);
    $conflictCheck->execute();
    $conflictResult = $conflictCheck->get_result();

    if ($conflictResult->num_rows > 0) {
      redirect_with_toast('conflict');
    }
    $conflictCheck->close();

    // Proceed with normal assignment
    $stmt = $conn->prepare("SELECT advisory_id FROM advisory_classes WHERE teacher_id = ? AND school_year_id = ? AND class_name = ?");
    $stmt->bind_param("iis", $teacherId, $schoolYearId, $sectionName);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
      $advisoryId = $result->fetch_assoc()['advisory_id'];
    } else {
      $insertAdvisory = $conn->prepare("INSERT INTO advisory_classes (teacher_id, school_year_id, class_name) VALUES (?, ?, ?)");
      $insertAdvisory->bind_param("iis", $teacherId, $schoolYearId, $sectionName);
      $insertAdvisory->execute();
      $advisoryId = $insertAdvisory->insert_id;
      $insertAdvisory->close();
    }
    $stmt->close();

    // Final insert
    $insertSubject = $conn->prepare("INSERT INTO subjects (teacher_id, subject_name, advisory_id, school_year_id) VALUES (?, ?, ?, ?)");
    $insertSubject->bind_param("isii", $teacherId, $subjectName, $advisoryId, $schoolYearId);
    $insertSubject->execute();
    $insertSubject->close();

    redirect_with_toast('assigned');
  }
}

$teachers = $conn->query("SELECT * FROM teachers ORDER BY fullname ASC");
$school_years = $conn->query("SELECT * FROM school_years WHERE status = 'active'");
$master_subjects = $conn->query("SELECT * FROM master_subjects");
$master_sections = $conn->query("SELECT * FROM master_sections");
?>




<style>
  .modal-backdrop { backdrop-filter: blur(5px); }
  .fade-in { animation: fadeIn 1s ease-out; }
  @keyframes fadeIn {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
  }
  .toast {
    position: fixed; top: 1.5rem; right: 1.5rem; background-color: #38bdf8;
    color: white; padding: 1rem 1.5rem; border-radius: 0.5rem;
    box-shadow: 0 5px 15px rgba(0,0,0,0.2); z-index: 9999;
    animation: slideDown 0.5s ease, fadeOut 0.5s ease 2.5s forwards;
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

<div class="mb-4">
  <button onclick="document.getElementById('addModal').classList.remove('hidden')" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
    ➕ Add Teacher
  </button>
</div>

<table class="min-w-full bg-white shadow rounded">
  <thead class="bg-blue-100 text-gray-700">
    <tr>
      <th class="py-2 px-4 text-left">Full Name</th>
      <th class="py-2 px-4 text-left">Username</th>
      <th class="py-2 px-4 text-left">Actions</th>
    </tr>
  </thead>
  <tbody>
    <?php while($row = $teachers->fetch_assoc()): ?>
      <tr class="border-t">
        <td class="py-2 px-4"><?= htmlspecialchars($row['fullname']) ?></td>
        <td class="py-2 px-4"><?= htmlspecialchars($row['username']) ?></td>
        <td class="py-2 px-4 space-x-2">
          <button onclick="document.getElementById('editModal<?= $row['teacher_id'] ?>').classList.remove('hidden')" class="bg-yellow-500 text-white px-3 py-1 rounded">Edit</button>
          <button onclick="document.getElementById('deleteModal<?= $row['teacher_id'] ?>').classList.remove('hidden')" class="bg-red-600 text-white px-3 py-1 rounded">Delete</button>
          <button onclick="document.getElementById('assignModal<?= $row['teacher_id'] ?>').classList.remove('hidden')" class="bg-purple-600 text-white px-3 py-1 rounded">Assign</button>
        </td>
      </tr>

      <!-- Edit Modal -->
      <div id="editModal<?= $row['teacher_id'] ?>" class="modal-backdrop fixed inset-0 bg-black bg-opacity-40 flex items-center justify-center hidden z-50">
        <div class="bg-white p-6 rounded shadow max-w-md w-full fade-in">
          <h3 class="text-xl font-bold mb-4">Edit Teacher</h3>
          <form method="POST">
            <input type="hidden" name="update_teacher" value="1">
            <input type="hidden" name="teacher_id" value="<?= $row['teacher_id'] ?>">
            <div class="mb-3">
              <label>Full Name</label>
              <input type="text" name="fullname" value="<?= htmlspecialchars($row['fullname']) ?>" class="w-full border p-2 rounded" required>
            </div>
            <div class="mb-3">
              <label>Username</label>
              <input type="text" name="username" value="<?= htmlspecialchars($row['username']) ?>" class="w-full border p-2 rounded" required>
            </div>
            <div class="mb-3">
              <label>New Password <span class="text-sm text-gray-500">(leave blank to keep current)</span></label>
              <input type="password" name="password" class="w-full border p-2 rounded">
            </div>
            <button type="submit" class="bg-yellow-500 text-white px-4 py-2 rounded">Update</button>
            <button type="button" onclick="document.getElementById('editModal<?= $row['teacher_id'] ?>').classList.add('hidden')" class="ml-2 text-gray-600">Cancel</button>
          </form>
        </div>
      </div>

      <!-- Delete Modal -->
      <div id="deleteModal<?= $row['teacher_id'] ?>" class="modal-backdrop fixed inset-0 bg-black bg-opacity-40 flex items-center justify-center hidden z-50">
        <div class="bg-white p-6 rounded shadow max-w-sm w-full fade-in">
          <h3 class="text-lg font-bold mb-4">Confirm Deletion</h3>
          <p>Are you sure you want to delete <strong><?= htmlspecialchars($row['fullname']) ?></strong>?</p>
          <form method="POST" class="mt-4">
            <input type="hidden" name="delete_teacher" value="1">
            <input type="hidden" name="teacher_id" value="<?= $row['teacher_id'] ?>">
            <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded">Yes, Delete</button>
            <button type="button" onclick="document.getElementById('deleteModal<?= $row['teacher_id'] ?>').classList.add('hidden')" class="ml-2 text-gray-600">Cancel</button>
          </form>
        </div>
      </div>

      <!-- Assign Modal -->
      <div id="assignModal<?= $row['teacher_id'] ?>" class="modal-backdrop fixed inset-0 bg-black bg-opacity-40 flex items-center justify-center hidden z-50">
        <div class="bg-white p-6 rounded shadow max-w-md w-full fade-in">
          <h3 class="text-xl font-bold mb-4">Assign Teacher</h3>
          <form method="POST">
            <input type="hidden" name="assign_teacher" value="1">
            <input type="hidden" name="teacher_id" value="<?= $row['teacher_id'] ?>">

            <div class="mb-3">
              <label>Subject Name</label>
              <select name="subject_name" class="w-full border p-2 rounded" required>
                <?php foreach ($master_subjects as $subject): ?>
                  <option value="<?= $subject['subject_name'] ?>"><?= $subject['subject_name'] ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="mb-3">
              <label>Section</label>
              <select name="section_name" class="w-full border p-2 rounded" required>
                <?php foreach ($master_sections as $adv): ?>
                  <option value="<?= $adv['section_name'] ?>"><?= $adv['section_name'] ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="mb-3">
              <label>School Year</label>
              <select name="school_year_id" class="w-full border p-2 rounded" required>
                <?php foreach ($school_years as $year): ?>
                  <option value="<?= $year['school_year_id'] ?>"><?= $year['year_label'] ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <button type="submit" class="bg-purple-600 text-white px-4 py-2 rounded">Assign</button>
            <button type="button" onclick="document.getElementById('assignModal<?= $row['teacher_id'] ?>').classList.add('hidden')" class="ml-2 text-gray-600">Cancel</button>
          </form>
        </div>
      </div>

    <?php endwhile; ?>
  </tbody>
</table>

<!-- Add Modal -->
<div id="addModal" class="modal-backdrop fixed inset-0 bg-black bg-opacity-40 flex items-center justify-center hidden z-50">
  <div class="bg-white p-6 rounded shadow max-w-md w-full fade-in">
    <h3 class="text-xl font-bold mb-4">Add New Teacher</h3>
    <form method="POST">
      <input type="hidden" name="create_teacher" value="1">
      <div class="mb-3">
        <label>Full Name</label>
        <input type="text" name="fullname" class="w-full border p-2 rounded" required>
      </div>
      <div class="mb-3">
        <label>Username</label>
        <input type="text" name="username" class="w-full border p-2 rounded" required>
      </div>
      <div class="mb-3">
        <label>Password</label>
        <input type="password" name="password" class="w-full border p-2 rounded" required>
      </div>
      <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Save</button>
      <button type="button" onclick="document.getElementById('addModal').classList.add('hidden')" class="ml-2 text-gray-600">Cancel</button>
    </form>
  </div>
</div>
