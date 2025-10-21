<?php
// --- Handle Toast Logic & Actions ---
include("../config/db.php");
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
  // Create Teacher
  if (isset($_POST['create_teacher'])) {
    $stmt = $conn->prepare("INSERT INTO teachers (fullname, username, password) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $_POST['fullname'], $_POST['username'], password_hash($_POST['password'], PASSWORD_DEFAULT));
    $stmt->execute(); $stmt->close();
    redirect_with_toast('add');
  }

  // Update Teacher
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

  // Delete Teacher
  if (isset($_POST['delete_teacher'])) {
    $stmt = $conn->prepare("DELETE FROM teachers WHERE teacher_id = ?");
    $stmt->bind_param("i", $_POST['teacher_id']);
    $stmt->execute(); $stmt->close();
    redirect_with_toast('delete');
  }

  // Assign Teacher
  if (isset($_POST['assign_teacher'])) {
    $teacherId = $_POST['teacher_id'];
    $subjectName = trim($_POST['subject_name']);
    $sectionName = trim($_POST['section_name']);
    $schoolYearId = $_POST['school_year_id'];
    $isAdvisory = $_POST['is_advisory'] ?? 'no';

    // Check for conflict
    $conflictCheck = $conn->prepare("SELECT * FROM subjects s JOIN advisory_classes ac ON s.advisory_id = ac.advisory_id WHERE s.subject_name = ? AND ac.class_name = ? AND s.school_year_id = ?");
    $conflictCheck->bind_param("ssi", $subjectName, $sectionName, $schoolYearId);
    $conflictCheck->execute();
    $conflictResult = $conflictCheck->get_result();
    if ($conflictResult->num_rows > 0) redirect_with_toast('conflict');
    $conflictCheck->close();

    // Normal assignment
    $stmt = $conn->prepare("SELECT advisory_id FROM advisory_classes WHERE teacher_id = ? AND school_year_id = ? AND class_name = ?");
    $stmt->bind_param("iis", $teacherId, $schoolYearId, $sectionName);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
      $advisoryId = $result->fetch_assoc()['advisory_id'];
    } else {
      $insertAdvisory = $conn->prepare("INSERT INTO advisory_classes (teacher_id, school_year_id, class_name, is_advisory) VALUES (?, ?, ?, ?)");
      $insertAdvisory->bind_param("iiss", $teacherId, $schoolYearId, $sectionName, $isAdvisory);
      $insertAdvisory->execute();
      $advisoryId = $insertAdvisory->insert_id;
      $insertAdvisory->close();
    }
    $stmt->close();

    $insertSubject = $conn->prepare("INSERT INTO subjects (teacher_id, subject_name, advisory_id, school_year_id) VALUES (?, ?, ?, ?)");
    $insertSubject->bind_param("isii", $teacherId, $subjectName, $advisoryId, $schoolYearId);
    $insertSubject->execute();
    $insertSubject->close();

    redirect_with_toast('assigned');
  }

  // ------------------- Import Teachers CSV -------------------
  if (isset($_POST['import_teachers']) && isset($_FILES['teachers_file'])) {
    $fileTmpPath = $_FILES['teachers_file']['tmp_name'];
    $fileName = $_FILES['teachers_file']['name'];
    $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    try {
      if ($fileExt === 'csv') {
        $file = fopen($fileTmpPath, 'r');
        $firstRow = true;
        while (($row = fgetcsv($file, 1000, ",")) !== FALSE) {
          if ($firstRow) { $firstRow = false; continue; } // skip header
          $fullname = $row[0];
          $username = $row[1];
          $password = password_hash($row[2], PASSWORD_DEFAULT);
          $stmt = $conn->prepare("INSERT INTO teachers (fullname, username, password) VALUES (?, ?, ?)");
          $stmt->bind_param("sss", $fullname, $username, $password);
          $stmt->execute(); $stmt->close();
        }
        fclose($file);
        redirect_with_toast('add');
      } else {
        throw new Exception("Only CSV files are supported.");
      }
    } catch (Exception $e) {
      echo "<script>alert('Error importing file: ".$e->getMessage()."');</script>";
    }
  }
}

// Fetch data
$teachers = $conn->query("SELECT * FROM teachers ORDER BY fullname ASC");
$school_years = $conn->query("SELECT * FROM school_years WHERE status = 'active'");
$master_subjects = $conn->query("SELECT * FROM master_subjects");
$master_sections = $conn->query("SELECT * FROM master_sections");

$teacherAdvisories = [];
$res = $conn->query("SELECT ac.teacher_id, ac.class_name, sy.year_label 
                     FROM advisory_classes ac 
                     JOIN school_years sy ON ac.school_year_id = sy.school_year_id 
                     WHERE ac.is_advisory = 'yes'");
while ($row = $res->fetch_assoc()) {
  $teacherAdvisories[$row['teacher_id']] = $row['class_name'] . ' (' . $row['year_label'] . ')';
}
?>

<!-- Notification Bell HTML, Add/Edit/Delete/Assign Modals (keep all your original 392 lines intact) -->

<!-- Add Import Button -->
<div class="flex space-x-2 mb-4">
  <button onclick="document.getElementById('addModal').classList.remove('hidden')" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">➕ Add Teacher</button>
  <button onclick="document.getElementById('importModal').classList.remove('hidden')" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">📁 Import Teachers</button>
</div>

<!-- Import Modal -->
<div id="importModal" class="modal-backdrop fixed inset-0 bg-black bg-opacity-40 flex items-center justify-center hidden z-50">
  <div class="bg-white p-6 rounded shadow max-w-md w-full fade-in">
    <h3 class="text-xl font-bold mb-4">Import Teachers from CSV</h3>
    <form method="POST" enctype="multipart/form-data">
      <div class="mb-3">
        <label>Select CSV file</label>
        <input type="file" name="teachers_file" accept=".csv" class="w-full border p-2 rounded" required>
      </div>
      <button type="submit" name="import_teachers" class="bg-green-600 text-white px-4 py-2 rounded">Import</button>
      <button type="button" onclick="document.getElementById('importModal').classList.add('hidden')" class="ml-2 text-gray-600">Cancel</button>
    </form>
  </div>
</div>



<!-- Notification Bell HTML -->
<div class="relative float-right mb-4 z-50">
  <button onclick="toggleDropdown()" class="relative bg-white border rounded-full p-2 shadow hover:bg-blue-100">
    🔔
    <?php
    $notiCount = $conn->query("SELECT COUNT(*) as total FROM section_access_requests WHERE status = 'pending'")->fetch_assoc()['total'];
    if ($notiCount > 0): ?>
      <span class="absolute top-0 right-0 bg-red-500 text-white text-xs rounded-full px-1"><?= $notiCount ?></span>
    <?php endif; ?>
  </button>

  <div id="dropdownPanel" class="hidden absolute right-0 mt-2 w-96 bg-white border rounded shadow-lg z-50 max-h-96 overflow-y-auto">
    <div class="p-4 font-bold border-b">Pending Section Access Requests</div>

    <?php
    $pendingReq = $conn->query("
      SELECT r.request_id, t.fullname AS requester, ac.class_name, sy.year_label, r.reason
      FROM section_access_requests r
      JOIN teachers t ON r.requester_id = t.teacher_id
      JOIN advisory_classes ac ON r.advisory_id = ac.advisory_id
      JOIN school_years sy ON r.school_year_id = sy.school_year_id
      WHERE r.status = 'pending'
      ORDER BY r.requested_at DESC
    ");

    if ($pendingReq->num_rows === 0): ?>
      <div class="p-4 text-sm text-gray-500 italic">No pending requests.</div>
    <?php else:
      while ($row = $pendingReq->fetch_assoc()): ?>
        <div class="px-4 py-3 border-b text-sm">
          <div><strong><?= htmlspecialchars($row['requester']) ?></strong> → <?= $row['class_name'] ?> (<?= $row['year_label'] ?>)</div>
          <div class="text-gray-500 italic"><?= htmlspecialchars($row['reason']) ?></div>
          <div class="mt-1 space-x-2">
           <a href="approve_request.php?id=<?= $row['request_id'] ?>&action=approve" class="text-green-600 font-bold">✅ Approve</a>
            <a href="approve_request.php?id=<?= $row['request_id'] ?>&action=deny"    class="text-red-600 font-bold">❌ Deny</a>

          </div>
        </div>
      <?php endwhile;
    endif; ?>
  </div>
</div>



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

<div class="mb-4 flex items-center justify-between">
  <div>
    <button onclick="document.getElementById('addModal').classList.remove('hidden')" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
      ➕ Add Teacher
    </button>
  </div>

  <!-- Full Name search (search-only) -->
  <div class="w-1/3">
    <label class="block text-sm text-gray-600 mb-1">Search by Full Name</label>
    <input id="filter_q" type="search" placeholder="Type full name (realtime)..." class="w-full border p-2 rounded" oninput="filterRows()">
    <div class="mt-2 text-right">
      <button class="text-sm text-gray-600 underline" onclick="clearSearch()">Clear search</button>
    </div>
  </div>
</div>

<table class="min-w-full bg-white shadow rounded">
  <thead class="bg-blue-100 text-gray-700">
    <tr>
      <th class="py-2 px-4 text-left">Full Name</th>
      <th class="py-2 px-4 text-left">Username</th>
      <th class="py-2 px-4 text-left">Advisory Section</th>
      <th class="py-2 px-4 text-left">Actions</th>
    </tr>
  </thead>
  <tbody id="teachers_tbody">
    <?php while($row = $teachers->fetch_assoc()): ?>
      <tr class="border-t teacher-row"
          data-fullname="<?= htmlspecialchars($row['fullname'], ENT_QUOTES) ?>">
        <td class="py-2 px-4"><?= htmlspecialchars($row['fullname']) ?></td>
        <td class="py-2 px-4"><?= htmlspecialchars($row['username']) ?></td>
          <td class="py-2 px-4">
          <?= $teacherAdvisories[$row['teacher_id']] ?? '<span class="text-gray-400 italic">None</span>' ?>
        </td>
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
              <label>Is Adviser?</label>
              <select name="is_advisory" class="w-full border p-2 rounded" required>
                <option value="no">No</option>
                <option value="yes">Yes</option>
              </select>
            </div>


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
<script>
  function toggleDropdown() {
    const dropdown = document.getElementById('dropdownPanel');
    dropdown.classList.toggle('hidden');
  }

  // Hide dropdown if clicking outside
  document.addEventListener('click', function (e) {
    const isDropdown = e.target.closest('#dropdownPanel');
    const isBell = e.target.closest('button');
    if (!isDropdown && !isBell) {
      document.getElementById('dropdownPanel')?.classList.add('hidden');
    }
  });

  // Realtime Full Name search
  const teachersTbody = document.getElementById('teachers_tbody');
  const filterQ = document.getElementById('filter_q');

  function clearSearch() {
    filterQ.value = '';
    filterRows();
  }

  function filterRows() {
    const q = (filterQ.value || '').trim().toLowerCase();
    const rows = teachersTbody.querySelectorAll('.teacher-row');
    let anyVisible = false;
    rows.forEach(row => {
      const fullname = (row.getAttribute('data-fullname') || '').toLowerCase();
      const visible = q === '' || fullname.indexOf(q) !== -1;
      row.style.display = visible ? '' : 'none';
      if (visible) anyVisible = true;
    });

    // Optional: show a "no results" row when nothing matches
    let noRow = document.getElementById('no_results_row');
    if (!anyVisible) {
      if (!noRow) {
        noRow = document.createElement('tr');
        noRow.id = 'no_results_row';
        noRow.innerHTML = '<td class="py-6 px-3 text-center text-gray-500 italic" colspan="4">No teachers found.</td>';
        teachersTbody.appendChild(noRow);
      }
    } else {
      if (noRow) noRow.remove();
    }
  }

  // initial run
  filterRows();
</script>
