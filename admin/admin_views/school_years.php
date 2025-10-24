<?php
// You may eventually move these queries to config/logic files
include("../config/db.php");
// Handle Add School Year
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['year_label'])) {
  $yearLabel = trim($_POST['year_label']);
  if (!empty($yearLabel)) {
    $stmt = $conn->prepare("INSERT INTO school_years (year_label, status) VALUES (?, 'inactive')");
    $stmt->bind_param("s", $yearLabel);
    $stmt->execute();
    echo "<script>location.href='admin.php?page=school_years';</script>";
    exit;
  }
}

// Handle Toggle Activation
if (isset($_GET['activate_id'])) {
  $activateId = $_GET['activate_id'];

  // Set all to inactive first
  $conn->query("UPDATE school_years SET status = 'inactive'");

  // Activate selected year
  $stmt = $conn->prepare("UPDATE school_years SET status = 'active' WHERE school_year_id = ?");
  $stmt->bind_param("i", $activateId);
  $stmt->execute();

  echo "<script>location.href='admin.php?page=school_years';</script>";
  exit;
}

// Fetch all years
$result = $conn->query("SELECT * FROM school_years ORDER BY school_year_id DESC");
?>

<!-- Add Year Form -->
<form method="POST" class="mb-6 flex items-center gap-4">
  <input type="text" name="year_label" required placeholder="e.g. 2026-2027"
         class="p-2 border border-gray-300 rounded w-64" />
  <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">
    Add School Year
  </button>

  <!-- Search box added (keeps styling consistent) -->
  <div class="ml-auto w-1/3">
    <label class="block text-sm text-gray-600 mb-1">Search (ID, Year, Status)</label>
    <div class="flex gap-2">
      <input id="sy_search" type="search" placeholder="Search by ID, year label, or status..." class="w-full p-2 border rounded" oninput="debouncedFilter()">
      <button type="button" onclick="clearSearch()" class="px-3 py-2 border rounded text-sm text-gray-600">Clear</button>
    </div>
  </div>
</form>

<!-- Table -->
<table class="w-full table-auto bg-white shadow rounded">
  <thead class="bg-gray-200 text-left">
    <tr>
      <th class="px-4 py-2">ID</th>
      <th class="px-4 py-2">Year Label</th>
      <th class="px-4 py-2">Status</th>
      <th class="px-4 py-2">Action</th>
    </tr>
  </thead>
  <tbody id="school_years_tbody">
    <?php while ($row = $result->fetch_assoc()) { 
        $id = (int)$row['school_year_id'];
        $label = htmlspecialchars($row['year_label']);
        $status = htmlspecialchars($row['status']);
    ?>
      <tr class="border-t sy-row"
          data-id="<?= $id ?>"
          data-label="<?= strtolower($label) ?>"
          data-status="<?= strtolower($status) ?>">
        <td class="px-4 py-2"><?= $id ?></td>
        <td class="px-4 py-2"><?= $label ?></td>
        <td class="px-4 py-2">
          <span class="<?= $row['status'] === 'active' ? 'text-green-600 font-semibold' : 'text-gray-500' ?>">
            <?= ucfirst($status) ?>
          </span>
        </td>
        <td class="px-4 py-2">
          <?php if ($row['status'] !== 'active') { ?>
            <a href="admin.php?page=school_years&activate_id=<?= $id ?>"
               class="text-blue-600 hover:underline">Activate</a>
          <?php } else { ?>
            <span class="text-gray-400">Active</span>
          <?php } ?>
        </td>
      </tr>
    <?php } ?>
  </tbody>
</table>

<script>
  // Realtime search for School Years (searches ID, year label, and status)
  const sySearch = document.getElementById('sy_search');
  const syTbody = document.getElementById('school_years_tbody');

  function clearSearch() {
    sySearch.value = '';
    filterSYRows();
    sySearch.focus();
  }

  function filterSYRows() {
    const q = (sySearch.value || '').trim().toLowerCase();
    const rows = syTbody.querySelectorAll('.sy-row');
    let anyVisible = false;

    rows.forEach(row => {
      const id = String(row.getAttribute('data-id') || '').toLowerCase();
      const label = String(row.getAttribute('data-label') || '').toLowerCase();
      const status = String(row.getAttribute('data-status') || '').toLowerCase();

      const visible = q === '' ||
                      id.indexOf(q) !== -1 ||
                      label.indexOf(q) !== -1 ||
                      status.indexOf(q) !== -1;

      row.style.display = visible ? '' : 'none';
      if (visible) anyVisible = true;
    });

    // show a "no results" row when nothing matches
    let noRow = document.getElementById('no_results_row_sy');
    if (!anyVisible) {
      if (!noRow) {
        noRow = document.createElement('tr');
        noRow.id = 'no_results_row_sy';
        noRow.innerHTML = '<td class="py-6 px-3 text-center text-gray-500 italic" colspan="4">No school years found.</td>';
        syTbody.appendChild(noRow);
      }
    } else {
      if (noRow) noRow.remove();
    }
  }

  // Debounce helper to avoid running filter on every keystroke
  let syDebounceTimer = null;
  function debouncedFilter(delay = 250) {
    if (syDebounceTimer) clearTimeout(syDebounceTimer);
    syDebounceTimer = setTimeout(() => {
      filterSYRows();
      syDebounceTimer = null;
    }, delay);
  }

  // initial run (in case there's pre-filled search from older logic)
  filterSYRows();
</script>
