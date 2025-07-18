<?php
$conn = new mysqli("localhost", "root", "", "cms");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$sql = "SELECT s.subject_id, s.subject_name, ac.class_name, sy.year_label
        FROM subjects s
        JOIN advisory_classes ac ON s.advisory_id = ac.advisory_id
        JOIN school_years sy ON s.school_year_id = sy.school_year_id
        ORDER BY sy.year_label DESC, ac.class_name ASC";

$result = $conn->query($sql);

$current_year = '';
$subjects = [];
while ($row = $result->fetch_assoc()) {
    $current_year = $row['year_label'];
    $subjects[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Classroom Subject Selector</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body {
      background-image: url('img/1.png'); /* Add your background image */
      background-size: cover;
      background-position: center;
      font-family: 'Comic Sans MS', cursive, sans-serif;
    }

    .card {
      background-image: url('img/role.png'); /* Optional paper texture */
      background-size: cover;
      background-blend-mode: lighten;
    }
  </style>
</head>
<body class="min-h-screen flex flex-col items-center px-4 py-8">

  <h1 class="text-4xl font-bold text-white drop-shadow mb-2">📚 Select Your Subject</h1>
  <p class="text-xl text-white mb-6">🗓️ School Year: <span class="font-semibold"><?= $current_year ?></span></p>

  <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6 w-full max-w-6xl">
    <?php foreach ($subjects as $row): ?>
     <div 
        onclick="location.href='choose_role.php?subject_id=<?= $row['subject_id'] ?>&subject_name=<?= urlencode($row['subject_name']) ?>&class_name=<?= urlencode($row['class_name']) ?>&year_label=<?= urlencode($current_year) ?>'"
        class="card cursor-pointer p-6 rounded-xl shadow-md border-4 border-yellow-300 hover:scale-105 transform transition-all duration-200 hover:border-blue-400"
      >

        <h2 class="text-2xl font-bold text-gray-800 mb-1"><?= htmlspecialchars($row['subject_name']) ?></h2>
        <p class="text-gray-700 text-lg">📘 Section: <span class="font-semibold"><?= htmlspecialchars($row['class_name']) ?></span></p>
      </div>
    <?php endforeach; ?>
  </div>

</body>
</html>
