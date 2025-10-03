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
<form method="POST" class="mb-6 flex items-center gap-4">
  <input type="text" name="class_name" required placeholder="e.g. 9-Matapat"
         class="p-2 border border-gray-300 rounded w-64" />
  <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">
    ➕ Add Section
  </button>
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
  <tbody>
    <?php while ($row = $sections->fetch_assoc()): ?>
      <tr class="border-t">
        <td class="px-4 py-2"><?= $row['master_section_id'] ?></td>
        <td class="px-4 py-2"><?= htmlspecialchars($row['section_name']) ?></td>
        <td class="px-4 py-2">
          <a href="admin.php?page=sections&delete_id=<?= $row['master_section_id'] ?>"

             onclick="return confirm('Delete this section?')"
             class="text-red-600 hover:underline">🗑 Delete</a>
        </td>
      </tr>
    <?php endwhile; ?>
  </tbody>
</table>
