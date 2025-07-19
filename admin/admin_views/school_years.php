<?php
// You may eventually move these queries to config/logic files

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
  <tbody>
    <?php while ($row = $result->fetch_assoc()) { ?>
      <tr class="border-t">
        <td class="px-4 py-2"><?= $row['school_year_id'] ?></td>
        <td class="px-4 py-2"><?= htmlspecialchars($row['year_label']) ?></td>
        <td class="px-4 py-2">
          <span class="<?= $row['status'] === 'active' ? 'text-green-600 font-semibold' : 'text-gray-500' ?>">
            <?= ucfirst($row['status']) ?>
          </span>
        </td>
        <td class="px-4 py-2">
          <?php if ($row['status'] !== 'active') { ?>
            <a href="admin.php?page=school_years&activate_id=<?= $row['school_year_id'] ?>"
               class="text-blue-600 hover:underline">Activate</a>
          <?php } else { ?>
            <span class="text-gray-400">Active</span>
          <?php } ?>
        </td>
      </tr>
    <?php } ?>
  </tbody>
</table>
