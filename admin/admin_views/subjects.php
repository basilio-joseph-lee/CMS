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
<form method="POST" class="mb-6 flex items-center gap-4">
  <input type="text" name="subject_name" required placeholder="e.g. Araling Panlipunan"
         class="p-2 border border-gray-300 rounded w-64" />
  <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">
    ➕ Add Subject
  </button>
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
  <tbody>
    <?php while ($row = $subjects->fetch_assoc()): ?>
      <tr class="border-t">
        <td class="px-4 py-2"><?= $row['master_subject_id'] ?></td>
        <td class="px-4 py-2"><?= htmlspecialchars($row['subject_name']) ?></td>
        <td class="px-4 py-2">
          <a href="admin.php?page=subjects&delete_id=<?= $row['master_subject_id'] ?>"

             onclick="return confirm('Delete this subject?')"
             class="text-red-600 hover:underline">🗑 Delete</a>
        </td>
      </tr>
    <?php endwhile; ?>
  </tbody>
</table>
