<?php
// Ensure this file is loaded via: admin.php?page=grades
$conn = new mysqli("localhost", "root", "", "cms");
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

// Handle final grade update logic
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_final_grade'])) {
  $student_id = $_POST['student_id'];
  $subject_id = $_POST['subject_id'];
  $advisory_id = $_POST['advisory_id'];
  $school_year_id = $_POST['school_year_id'];
  $q1 = $_POST['q1'];
  $q2 = $_POST['q2'];
  $q3 = $_POST['q3'];
  $q4 = $_POST['q4'];

  if ($q1 === '' || $q2 === '' || $q3 === '' || $q4 === '') {
    $remarks = 'INC';
    $average = null;
  } else {
    $average = round(($q1 + $q2 + $q3 + $q4) / 4, 2);
    $remarks = $average >= 75 ? 'Passed' : 'Failed';
  }

  $check = $conn->prepare("SELECT * FROM final_grades WHERE student_id = ? AND subject_id = ? AND advisory_id = ? AND school_year_id = ?");
  $check->bind_param("iiii", $student_id, $subject_id, $advisory_id, $school_year_id);
  $check->execute();
  $result = $check->get_result();

  if ($result->num_rows > 0) {
    $stmt = $conn->prepare("UPDATE final_grades SET q1=?, q2=?, q3=?, q4=?, final_average=?, remarks=? 
                            WHERE student_id=? AND subject_id=? AND advisory_id=? AND school_year_id=?");
    $stmt->bind_param("dddddsiiii", $q1, $q2, $q3, $q4, $average, $remarks, $student_id, $subject_id, $advisory_id, $school_year_id);
    $stmt->execute();
  } else {
    $stmt = $conn->prepare("INSERT INTO final_grades (student_id, subject_id, advisory_id, school_year_id, q1, q2, q3, q4, final_average, remarks)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iiiiddddss", $student_id, $subject_id, $advisory_id, $school_year_id, $q1, $q2, $q3, $q4, $average, $remarks);
    $stmt->execute();
  }

  $_SESSION['toast_edit_success'] = "Final grades updated successfully.";
  header("Location: admin.php?page=grades&school_year_id=$school_year_id&advisory_id=$advisory_id&subject_id=$subject_id");
  exit();
}


// Dropdown data
$school_years = $conn->query("SELECT * FROM school_years ORDER BY year_label DESC");
$advisories = $conn->query("SELECT * FROM advisory_classes ORDER BY class_name ASC");
$subjects = $conn->query("
  SELECT s.subject_id, s.subject_name, ac.class_name
  FROM subjects s
  JOIN advisory_classes ac ON s.advisory_id = ac.advisory_id
  ORDER BY s.subject_name ASC
");

// Selected filters
$selected_year = $_GET['school_year_id'] ?? '';
$selected_advisory = $_GET['advisory_id'] ?? '';
$selected_subject = $_GET['subject_id'] ?? '';

// Handle grading portal open/close
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (isset($_POST['open_portal'])) {
    $quarter = $_POST['open_portal'];
    $conn->query("UPDATE grading_portals SET status = 'closed' WHERE school_year_id = $selected_year");
    $conn->query("UPDATE grading_portals SET status = 'open' WHERE school_year_id = $selected_year AND quarter = '$quarter'");
  } elseif (isset($_POST['close_portal'])) {
    $quarter = $_POST['close_portal'];
    $conn->query("UPDATE grading_portals SET status = 'closed' WHERE school_year_id = $selected_year AND quarter = '$quarter'");
  }
  header("Location: admin.php?page=grades&school_year_id=$selected_year&advisory_id=$selected_advisory&subject_id=$selected_subject");
  exit();
}

// Build query
$query = "
  SELECT s.student_id, s.fullname,
         fg.q1, fg.q2, fg.q3, fg.q4,
         fg.final_average, fg.remarks
  FROM students s
  LEFT JOIN final_grades fg ON fg.student_id = s.student_id

";

$conditions = [];

if (!empty($selected_year) && is_numeric($selected_year)) {
  $conditions[] = "fg.school_year_id = " . intval($selected_year);
}
if (!empty($selected_advisory) && is_numeric($selected_advisory)) {
  $conditions[] = "fg.advisory_id = " . intval($selected_advisory);
}
if (!empty($selected_subject) && is_numeric($selected_subject)) {
  $conditions[] = "fg.subject_id = " . intval($selected_subject);
}

if (!empty($conditions)) {
  $query .= " WHERE " . implode(" AND ", $conditions);
}
$query .= " ORDER BY s.fullname ASC";
$results = $conn->query($query);
?>

<div class="p-6">
  <form method="GET" action="admin.php" class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    <input type="hidden" name="page" value="grades">
    <div>
      <label class="block font-medium mb-1 text-gray-700">School Year</label>
      <select name="school_year_id" class="w-full border border-gray-300 rounded-lg p-2">
        <option value="">All</option>
        <?php while ($row = $school_years->fetch_assoc()): ?>
          <option value="<?= $row['school_year_id'] ?>" <?= ($selected_year == $row['school_year_id']) ? 'selected' : '' ?>>
            <?= $row['year_label'] ?> <?= $row['status'] == 'active' ? '(Active)' : '' ?>
          </option>
        <?php endwhile; ?>
      </select>
    </div>
    <div>
      <label class="block font-medium mb-1 text-gray-700">Advisory Class</label>
      <select name="advisory_id" class="w-full border border-gray-300 rounded-lg p-2">
        <option value="">All</option>
        <?php mysqli_data_seek($advisories, 0); while ($row = $advisories->fetch_assoc()): ?>
          <option value="<?= $row['advisory_id'] ?>" <?= ($selected_advisory == $row['advisory_id']) ? 'selected' : '' ?>>
            <?= $row['class_name'] ?>
          </option>
        <?php endwhile; ?>
      </select>
    </div>
    <div>
      <label class="block font-medium mb-1 text-gray-700">Subject</label>
      <select name="subject_id" class="w-full border border-gray-300 rounded-lg p-2">
        <option value="">All</option>
        <?php mysqli_data_seek($subjects, 0); while ($row = $subjects->fetch_assoc()): ?>
          <option value="<?= $row['subject_id'] ?>" <?= ($selected_subject == $row['subject_id']) ? 'selected' : '' ?>>
            <?= $row['subject_name'] ?> – <?= $row['class_name'] ?>
          </option>
        <?php endwhile; ?>
      </select>
    </div>
    <div class="md:col-span-3">
      <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-6 py-2 mt-2 rounded shadow">
        Filter
      </button>
    </div>
  </form>

  <?php if ($selected_year): ?>
  <div class="mt-8 mb-6">
    <h2 class="text-xl font-semibold mb-3 text-gray-800">📂 Grading Portal Control (S.Y. <?= $selected_year ?>)</h2>
    <form method="POST" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      <?php
        $portalResult = $conn->query("SELECT quarter, status FROM grading_portals WHERE school_year_id = $selected_year");
        $portalStatuses = [];
        while ($row = $portalResult->fetch_assoc()) {
          $portalStatuses[$row['quarter']] = $row['status'];
        }
      ?>
      <?php foreach (['1st','2nd','3rd','4th'] as $q): ?>
        <div class="flex items-center justify-between gap-3 bg-gray-100 p-3 rounded shadow">
          <span class="font-semibold"><?= ucfirst($q) ?> Quarter</span>
          <?php if (($portalStatuses[$q] ?? 'closed') === 'open'): ?>
            <button name="close_portal" value="<?= $q ?>" class="bg-red-500 text-white text-sm px-3 py-1 rounded shadow">Close</button>
          <?php else: ?>
            <button name="open_portal" value="<?= $q ?>" class="bg-green-600 text-white text-sm px-3 py-1 rounded shadow">Open</button>
          <?php endif; ?>
          <span class="text-xs text-gray-600">Status: <?= strtoupper($portalStatuses[$q] ?? 'closed') ?></span>
        </div>
      <?php endforeach; ?>
    </form>
  </div>
  <?php endif; ?>

  <div class="overflow-x-auto">
    <table class="min-w-full border border-gray-300 rounded-lg overflow-hidden">
      <thead class="bg-blue-600 text-white text-sm">
        <tr>
          <th class="px-4 py-2 text-left">Student Name</th>
          <th class="px-4 py-2 text-center">1st Quarter</th>
          <th class="px-4 py-2 text-center">2nd Quarter</th>
          <th class="px-4 py-2 text-center">3rd Quarter</th>
          <th class="px-4 py-2 text-center">4th Quarter</th>
          <th class="px-4 py-2 text-center">Final Grade</th>
          <th class="px-4 py-2 text-center">Remarks</th>
          <th class="px-4 py-2 text-center">Action</th>
        </tr>
      </thead>
      <tbody class="text-center text-sm bg-white">
        <?php if ($results && $results->num_rows > 0): ?>
          <?php while ($row = $results->fetch_assoc()): ?>
            <?php
              $remarks = $row['remarks'] ?? '-';
              $remarkClass = match($remarks) {
                'Passed' => 'text-green-600 font-semibold',
                'Failed' => 'text-red-600 font-semibold',
                'INC'    => 'text-gray-500 font-semibold',
                default  => '',
              };
            ?>
            <tr class="border-t border-gray-200">
              <td class="px-4 py-2 text-left"><?= $row['fullname'] ?></td>
              <td><?= $row['q1'] ?? '-' ?></td>
              <td><?= $row['q2'] ?? '-' ?></td>
              <td><?= $row['q3'] ?? '-' ?></td>
              <td><?= $row['q4'] ?? '-' ?></td>
              <td class="font-bold"><?= $row['final_average'] ?? '-' ?></td>
              <td class="<?= $remarkClass ?>"><?= $remarks ?></td>
              <td>
                <button onclick="openEditModal(
                  <?= $row['student_id'] ?>,
                  <?= $row['q1'] ?? 'null' ?>,
                  <?= $row['q2'] ?? 'null' ?>,
                  <?= $row['q3'] ?? 'null' ?>,
                  <?= $row['q4'] ?? 'null' ?>,
                  '<?= $remarks ?>'
                )"
                class="bg-yellow-500 hover:bg-yellow-600 text-white text-xs px-3 py-1 rounded shadow">
                  Edit
                </button>
              </td>

            </tr>
          <?php endwhile; ?>
        <?php else: ?>
          <tr>
            <td colspan="8" class="px-4 py-4 text-gray-600">No results found.</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if (isset($_SESSION['toast_edit_success'])): ?>
  <div id="toast-success" class="fixed top-6 right-6 bg-green-500 text-white px-6 py-3 rounded shadow-lg z-50 animate-fade-in">
    <?= $_SESSION['toast_edit_success'] ?>
  </div>

  <script>
    setTimeout(() => {
      const toast = document.getElementById("toast-success");
      if (toast) toast.style.display = "none";
    }, 3000); // hide after 3 seconds
  </script>

  <style>
    @keyframes fade-in {
      from { opacity: 0; transform: translateY(-10px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .animate-fade-in {
      animation: fade-in 0.4s ease-out;
    }
  </style>

  <?php unset($_SESSION['toast_edit_success']); ?>
<?php endif; ?>

<!-- Edit Modal -->
<div id="editModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
  <div class="bg-white p-6 rounded-lg w-full max-w-md shadow-xl relative">
    <h2 class="text-xl font-semibold mb-4 text-gray-800">✏️ Edit Final Grade</h2>
    <form method="POST">
      <input type="hidden" name="edit_final_grade" value="1">
      <input type="hidden" id="edit_student_id" name="student_id">
      <input type="hidden" name="school_year_id" value="<?= $selected_year ?>">
      <input type="hidden" name="advisory_id" value="<?= $selected_advisory ?>">
      <input type="hidden" name="subject_id" value="<?= $selected_subject ?>">

      <div class="grid grid-cols-2 gap-4">
        <?php foreach (['q1', 'q2', 'q3', 'q4'] as $q): ?>
          <div>
            <label class="block text-sm font-medium mb-1"><?= strtoupper($q) ?>:</label>
            <input type="number" step="0.01" name="<?= $q ?>" id="edit_<?= $q ?>" class="w-full border rounded px-3 py-1">
          </div>
        <?php endforeach; ?>
      </div>

      <div class="mt-4 flex justify-end gap-3">
        <button type="button" onclick="closeModal()" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded">Cancel</button>
        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">Save</button>
      </div>
    </form>
  </div>
</div>

<script>
  function openEditModal(studentId, q1, q2, q3, q4, remarks) {
    document.getElementById('edit_student_id').value = studentId;
    document.getElementById('edit_q1').value = q1 ?? '';
    document.getElementById('edit_q2').value = q2 ?? '';
    document.getElementById('edit_q3').value = q3 ?? '';
    document.getElementById('edit_q4').value = q4 ?? '';
    document.getElementById('editModal').classList.remove('hidden');
    document.getElementById('editModal').classList.add('flex');
  }

  function closeModal() {
    document.getElementById('editModal').classList.remove('flex');
    document.getElementById('editModal').classList.add('hidden');
  }
</script>

