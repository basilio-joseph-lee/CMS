<?php
// --- Handle Toast Logic & Actions ---

include("../config/db.php");
$toast = '';
if (isset($_GET['success'])) {
  if ($_GET['success'] === 'add') $toast = 'Parent added successfully!';
  elseif ($_GET['success'] === 'update') $toast = 'Parent updated successfully!';
  elseif ($_GET['success'] === 'delete') $toast = 'Parent deleted successfully!';
}

function redirect_with_toast($type) {
  echo "<script>location.href='admin.php?page=parents&success=$type';</script>";
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (isset($_POST['create_parent'])) {
    $stmt = $conn->prepare("INSERT INTO parents (fullname, email, password, mobile_number) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $_POST['fullname'], $_POST['email'], password_hash($_POST['password'], PASSWORD_DEFAULT), $_POST['mobile_number']);
    $stmt->execute(); $stmt->close();
    redirect_with_toast('add');
  }

  if (isset($_POST['update_parent'])) {
    if (!empty($_POST['password'])) {
      $stmt = $conn->prepare("UPDATE parents SET fullname = ?, email = ?, password = ?, mobile_number = ? WHERE parent_id = ?");
      $hashedPassword = password_hash($_POST['password'], PASSWORD_DEFAULT);
      $stmt->bind_param("ssssi", $_POST['fullname'], $_POST['email'], $hashedPassword, $_POST['mobile_number'], $_POST['parent_id']);
    } else {
      $stmt = $conn->prepare("UPDATE parents SET fullname = ?, email = ?, mobile_number = ? WHERE parent_id = ?");
      $stmt->bind_param("sssi", $_POST['fullname'], $_POST['email'], $_POST['mobile_number'], $_POST['parent_id']);
    }
    $stmt->execute(); $stmt->close();
    redirect_with_toast('update');
  }

  if (isset($_POST['delete_parent'])) {
    $stmt = $conn->prepare("DELETE FROM parents WHERE parent_id = ?");
    $stmt->bind_param("i", $_POST['parent_id']);
    $stmt->execute(); $stmt->close();
    redirect_with_toast('delete');
  }
}

$parents = $conn->query("SELECT * FROM parents ORDER BY fullname ASC");
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

<div class="mb-4 flex items-center justify-between">
  <div>
    <button onclick="document.getElementById('addModal').classList.remove('hidden')" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
      ➕ Add Parent
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
      <th class="py-2 px-4 text-left">Email</th>
      <th class="py-2 px-4 text-left">Mobile</th>
      <th class="py-2 px-4 text-left">Actions</th>
    </tr>
  </thead>
  <tbody id="parents_tbody">
    <?php while($row = $parents->fetch_assoc()): ?>
      <tr class="border-t parent-row"
          data-fullname="<?= htmlspecialchars($row['fullname'], ENT_QUOTES) ?>">
        <td class="py-2 px-4"><?= htmlspecialchars($row['fullname']) ?></td>
        <td class="py-2 px-4"><?= htmlspecialchars($row['email']) ?></td>
        <td class="py-2 px-4"><?= htmlspecialchars($row['mobile_number']) ?></td>
        <td class="py-2 px-4 space-x-2">
          <button onclick="document.getElementById('editModal<?= $row['parent_id'] ?>').classList.remove('hidden')" class="bg-yellow-500 text-white px-3 py-1 rounded">Edit</button>
          <button onclick="document.getElementById('deleteModal<?= $row['parent_id'] ?>').classList.remove('hidden')" class="bg-red-600 text-white px-3 py-1 rounded">Delete</button>
        </td>
      </tr>

      <!-- Edit Modal -->
      <div id="editModal<?= $row['parent_id'] ?>" class="modal-backdrop fixed inset-0 bg-black bg-opacity-40 flex items-center justify-center hidden z-50">
        <div class="bg-white p-6 rounded shadow max-w-md w-full fade-in">
          <h3 class="text-xl font-bold mb-4">Edit Parent</h3>
          <form method="POST">
            <input type="hidden" name="update_parent" value="1">
            <input type="hidden" name="parent_id" value="<?= $row['parent_id'] ?>">
            <div class="mb-3">
              <label>Full Name</label>
              <input type="text" name="fullname" value="<?= htmlspecialchars($row['fullname']) ?>" class="w-full border p-2 rounded" required>
            </div>
            <div class="mb-3">
              <label>Email</label>
              <input type="email" name="email" value="<?= htmlspecialchars($row['email']) ?>" class="w-full border p-2 rounded" required>
            </div>
            <div class="mb-3">
              <label>Mobile Number</label>
              <input type="text" name="mobile_number" value="<?= htmlspecialchars($row['mobile_number']) ?>" class="w-full border p-2 rounded" required>
            </div>
            <div class="mb-3">
              <label>New Password <span class="text-sm text-gray-500">(leave blank to keep current)</span></label>
              <input type="password" name="password" class="w-full border p-2 rounded">
            </div>
            <button type="submit" class="bg-yellow-500 text-white px-4 py-2 rounded">Update</button>
            <button type="button" onclick="document.getElementById('editModal<?= $row['parent_id'] ?>').classList.add('hidden')" class="ml-2 text-gray-600">Cancel</button>
          </form>
        </div>
      </div>

      <!-- Delete Modal -->
      <div id="deleteModal<?= $row['parent_id'] ?>" class="modal-backdrop fixed inset-0 bg-black bg-opacity-40 flex items-center justify-center hidden z-50">
        <div class="bg-white p-6 rounded shadow max-w-sm w-full fade-in">
          <h3 class="text-lg font-bold mb-4">Confirm Deletion</h3>
          <p>Are you sure you want to delete <strong><?= htmlspecialchars($row['fullname']) ?></strong>?</p>
          <form method="POST" class="mt-4">
            <input type="hidden" name="delete_parent" value="1">
            <input type="hidden" name="parent_id" value="<?= $row['parent_id'] ?>">
            <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded">Yes, Delete</button>
            <button type="button" onclick="document.getElementById('deleteModal<?= $row['parent_id'] ?>').classList.add('hidden')" class="ml-2 text-gray-600">Cancel</button>
          </form>
        </div>
      </div>

    <?php endwhile; ?>
  </tbody>
</table>

<!-- Add Modal -->
<div id="addModal" class="modal-backdrop fixed inset-0 bg-black bg-opacity-40 flex items-center justify-center hidden z-50">
  <div class="bg-white p-6 rounded shadow max-w-md w-full fade-in">
    <h3 class="text-xl font-bold mb-4">Add New Parent</h3>
    <form method="POST">
      <input type="hidden" name="create_parent" value="1">
      <div class="mb-3">
        <label>Full Name</label>
        <input type="text" name="fullname" class="w-full border p-2 rounded" required>
      </div>
      <div class="mb-3">
        <label>Email</label>
        <input type="email" name="email" class="w-full border p-2 rounded" required>
      </div>
      <div class="mb-3">
        <label>Mobile Number</label>
        <input type="text" name="mobile_number" class="w-full border p-2 rounded" required>
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
  // Realtime Full Name search for Parents
  const parentsTbody = document.getElementById('parents_tbody');
  const filterQ = document.getElementById('filter_q');

  function clearSearch() {
    filterQ.value = '';
    filterRows();
  }

  function filterRows() {
    const q = (filterQ.value || '').trim().toLowerCase();
    const rows = parentsTbody.querySelectorAll('.parent-row');
    let anyVisible = false;
    rows.forEach(row => {
      const fullname = (row.getAttribute('data-fullname') || '').toLowerCase();
      const visible = q === '' || fullname.indexOf(q) !== -1;
      row.style.display = visible ? '' : 'none';
      if (visible) anyVisible = true;
    });

    // Optional: show a "no results" row when nothing matches
    let noRow = document.getElementById('no_results_row_parents');
    if (!anyVisible) {
      if (!noRow) {
        noRow = document.createElement('tr');
        noRow.id = 'no_results_row_parents';
        noRow.innerHTML = '<td class="py-6 px-3 text-center text-gray-500 italic" colspan="4">No parents found.</td>';
        parentsTbody.appendChild(noRow);
      }
    } else {
      if (noRow) noRow.remove();
    }
  }

  // initial run
  filterRows();
</script>
