<?php

include("../config/db.php");

// Handle Create
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['class_name'])) {
  $className = trim($_POST['class_name']);
  if (!empty($className)) {
    // Insert only into master_sections
    $stmt = $conn->prepare("INSERT IGNORE INTO master_sections (section_name) VALUES (?)");
    $stmt->bind_param("s", $className);
    $stmt->execute();

    echo "<script>location.href='admin.php?page=sections';</script>";
    exit;
  }
}

// Handle Delete
if (isset($_GET['delete_id'])) {
  $id = $_GET['delete_id'];
  $conn->query("DELETE FROM master_sections WHERE master_section_id = $id");
  echo "<script>location.href='admin.php?page=sections';</script>";
  exit;
}

// Fetch all sections
$sections = $conn->query("SELECT * FROM master_sections ORDER BY section_name ASC");
?>

<!-- Add Section Form -->
<form method="POST" class="mb-6 flex flex-wrap items-center gap-4">
  <div class="flex items-center gap-4">
    <input type="text" name="class_name" required placeholder="e.g. 9-Matapat"
           class="p-2 border border-gray-300 rounded w-64" />
    <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">
      ➕ Add Section
    </button>
  </div>

  <!-- Search box -->
  <div class="ml-auto w-1/3 min-w-[250px]">
    <label class="block text-sm text-gray-600 mb-1">Search by Section Name</label>
    <div class="flex gap-2">
      <input id="section_search" type="search" placeholder="Type section name..."
             class="w-full p-2 border rounded" oninput="debouncedFilterSections()">
      <button type="button" onclick="clearSectionSearch()" class="px-3 py-2 border rounded text-sm text-gray-600">
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
      <th class="px-4 py-2">Section Name</th>
      <th class="px-4 py-2">Action</th>
    </tr>
  </thead>
  <tbody id="sections_tbody">
    <?php while ($row = $sections->fetch_assoc()): 
      $id = (int)$row['master_section_id'];
      $name = htmlspecialchars($row['section_name']);
    ?>
      <tr class="border-t section-row" data-section="<?= strtolower($name) ?>" data-id="<?= $id ?>">
        <td class="px-4 py-2"><?= $id ?></td>
        <td class="px-4 py-2"><?= $name ?></td>
        <td class="px-4 py-2">
          <a href="admin.php?page=sections&delete_id=<?= $id ?>"
             onclick="return confirm('Delete this section?')"
             class="text-red-600 hover:underline">🗑 Delete</a>
        </td>
      </tr>
    <?php endwhile; ?>
  </tbody>
</table>

<script>
  // Realtime section search (client-side)
  const sectionSearch = document.getElementById('section_search');
  const sectionsTbody = document.getElementById('sections_tbody');

  function clearSectionSearch() {
    sectionSearch.value = '';
    filterSections();
    sectionSearch.focus();
  }

  function filterSections() {
    const q = (sectionSearch.value || '').trim().toLowerCase();
    const rows = sectionsTbody.querySelectorAll('.section-row');
    let anyVisible = false;

    rows.forEach(row => {
      const section = (row.getAttribute('data-section') || '').toLowerCase();
      const visible = q === '' || section.indexOf(q) !== -1;
      row.style.display = visible ? '' : 'none';
      if (visible) anyVisible = true;
    });

    // Show "no results" row when no matches
    let noRow = document.getElementById('no_results_row_sections');
    if (!anyVisible) {
      if (!noRow) {
        noRow = document.createElement('tr');
        noRow.id = 'no_results_row_sections';
        noRow.innerHTML = '<td class="py-6 px-3 text-center text-gray-500 italic" colspan="3">No sections found.</td>';
        sectionsTbody.appendChild(noRow);
      }
    } else {
      if (noRow) noRow.remove();
    }
  }

  // Debounce helper (prevents excessive filtering)
  let debounceTimer = null;
  function debouncedFilterSections(delay = 250) {
    if (debounceTimer) clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
      filterSections();
      debounceTimer = null;
    }, delay);
  }

  // Initial render
  filterSections();
</script>
