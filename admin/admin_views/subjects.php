<?php
// Handle Create

include("../config/db.php");
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subject_name'])) {
  $subjectName = trim($_POST['subject_name']);
  if (!empty($subjectName)) {
    // Insert only into master_subjects
    $stmt = $conn->prepare("INSERT IGNORE INTO master_subjects (subject_name) VALUES (?)");
    $stmt->bind_param("s", $subjectName);
    $stmt->execute();

    echo "<script>location.href='admin.php?page=subjects';</script>";
    exit;
  }
}

// Handle Delete
if (isset($_GET['delete_id'])) {
  $id = $_GET['delete_id'];
  $conn->query("DELETE FROM master_subjects WHERE master_subject_id = $id");
  echo "<script>location.href='admin.php?page=subjects';</script>";
  exit;
}

// Fetch all subjects
$subjects = $conn->query("SELECT * FROM master_subjects ORDER BY subject_name ASC");
?>

<!-- Add Subject Form -->
<form method="POST" class="mb-6 flex flex-wrap items-center gap-4">
  <div class="flex items-center gap-4">
    <input type="text" name="subject_name" required placeholder="e.g. Araling Panlipunan"
           class="p-2 border border-gray-300 rounded w-64" />
    <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">
      ➕ Add Subject
    </button>
  </div>

  <!-- Search box -->
  <div class="ml-auto w-1/3 min-w-[250px]">
    <label class="block text-sm text-gray-600 mb-1">Search by Subject Name</label>
    <div class="flex gap-2">
      <input id="subject_search" type="search" placeholder="Type subject name..."
             class="w-full p-2 border rounded" oninput="debouncedFilterSubjects()">
      <button type="button" onclick="clearSubjectSearch()" class="px-3 py-2 border rounded text-sm text-gray-600">
        Clear
      </button>
    </div>
  </div>
</form>

<!-- Table -->
<table class="w-full table-auto bg-white shadow rounded">
  <thead class="bg-gray-200 text-left">
    <tr>
      <th class="px-4 py-2">ID</th>
      <th class="px-4 py-2">Subject Name</th>
      <th class="px-4 py-2">Action</th>
    </tr>
  </thead>
  <tbody id="subjects_tbody">
    <?php while ($row = $subjects->fetch_assoc()): 
      $id = (int)$row['master_subject_id'];
      $name = htmlspecialchars($row['subject_name']);
    ?>
      <tr class="border-t subject-row" data-subject="<?= strtolower($name) ?>" data-id="<?= $id ?>">
        <td class="px-4 py-2"><?= $id ?></td>
        <td class="px-4 py-2"><?= $name ?></td>
        <td class="px-4 py-2">
          <a href="admin.php?page=subjects&delete_id=<?= $id ?>"
             onclick="return confirm('Delete this subject?')"
             class="text-red-600 hover:underline">🗑 Delete</a>
        </td>
      </tr>
    <?php endwhile; ?>
  </tbody>
</table>

<script>
  // Realtime subject search (client-side)
  const subjectSearch = document.getElementById('subject_search');
  const subjectsTbody = document.getElementById('subjects_tbody');

  function clearSubjectSearch() {
    subjectSearch.value = '';
    filterSubjects();
    subjectSearch.focus();
  }

  function filterSubjects() {
    const q = (subjectSearch.value || '').trim().toLowerCase();
    const rows = subjectsTbody.querySelectorAll('.subject-row');
    let anyVisible = false;

    rows.forEach(row => {
      const subject = (row.getAttribute('data-subject') || '').toLowerCase();
      const visible = q === '' || subject.indexOf(q) !== -1;
      row.style.display = visible ? '' : 'none';
      if (visible) anyVisible = true;
    });

    // Show "no results" row when no matches
    let noRow = document.getElementById('no_results_row_subjects');
    if (!anyVisible) {
      if (!noRow) {
        noRow = document.createElement('tr');
        noRow.id = 'no_results_row_subjects';
        noRow.innerHTML = '<td class="py-6 px-3 text-center text-gray-500 italic" colspan="3">No subjects found.</td>';
        subjectsTbody.appendChild(noRow);
      }
    } else {
      if (noRow) noRow.remove();
    }
  }

  // Debounce helper (prevents excessive filtering)
  let subjectDebounceTimer = null;
  function debouncedFilterSubjects(delay = 250) {
    if (subjectDebounceTimer) clearTimeout(subjectDebounceTimer);
    subjectDebounceTimer = setTimeout(() => {
      filterSubjects();
      subjectDebounceTimer = null;
    }, delay);
  }

  // Initial render
  filterSubjects();
</script>
