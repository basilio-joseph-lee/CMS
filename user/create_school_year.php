<?php
session_start();
if (!isset($_SESSION['teacher_id'])) {
  header("Location: teacher_login.php");
  exit;
}
$teacherId = $_SESSION['teacher_id'];
$teacherName = $_SESSION['fullname'];

// Database connection to fetch school years and advisory classes
$host = "localhost";
$user = "root";
$pass = "";
$db = "cms";
$conn = new mysqli($host, $user, $pass, $db);

$schoolYears = [];
$advisoryClasses = [];

$result = $conn->query("SELECT * FROM school_years ORDER BY year_label DESC");
while ($row = $result->fetch_assoc()) {
  $schoolYears[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Create School Year</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body {
      background-image: url('../img/1.png');
      background-size: cover;
      background-position: center;
      font-family: 'Comic Sans MS', cursive, sans-serif;
    }
    .card {
      background-color: #fffbe6;
      border-radius: 20px;
      box-shadow: 6px 8px rgba(0,0,0,0.15);
      padding: 2rem;
    }
    .section-title {
      font-size: 1.1rem;
      font-weight: bold;
      color: #4b3a2f;
      margin-bottom: 0.5rem;
    }
    .input-style {
      background-color: #fff7d6;
      border: 2px solid #ecc94b;
      padding: 0.6rem 1rem;
      border-radius: 12px;
      width: 100%;
      font-family: inherit;
      font-size: 0.9rem;
      box-shadow: inset 0 2px 4px rgba(0,0,0,0.05);
    }
    .input-style:focus {
      outline: none;
      border-color: #d69e2e;
    }
    .button-style {
      background-color: #f6ad55;
      color: white;
      font-weight: bold;
      padding: 0.6rem 1.5rem;
      border-radius: 12px;
      transition: all 0.2s;
    }
    .button-style:hover {
      background-color: #dd6b20;
    }
    .shadow-pin::before {
      content: "📌";
      position: absolute;
      top: -1.25rem;
      left: 50%;
      transform: translateX(-50%);
      font-size: 1.75rem;
    }
  </style>
</head>
<body class="flex items-center justify-center min-h-screen px-4 py-10">

  <div class="card w-full max-w-4xl relative shadow-pin">
    <h2 class="text-2xl sm:text-3xl text-center font-bold text-yellow-900 mb-6">🗓️ Create School Year</h2>

    <form method="POST" action="../config/save_school_year.php" class="grid grid-cols-1 md:grid-cols-2 gap-6">

      <!-- School Year Dropdown -->
      <div>
        <label class="section-title">Select School Year</label>
        <select name="school_year_id" required class="input-style">
          <option value="">-- Select School Year --</option>
          <?php foreach ($schoolYears as $year): ?>
            <option value="<?= $year['school_year_id'] ?>"><?= htmlspecialchars($year['year_label']) ?></option>
          <?php endforeach; ?>
        </select>

        <!-- Advisory Class Name -->
        <label class="section-title mt-6 block">Advisory Class Name</label>
        <input type="text" name="class_name" placeholder="e.g., 8-Courage" class="input-style">
      </div>

      <!-- Subjects -->
      <div>
        <label class="section-title">Subjects to Handle</label>
        <div id="subjectFields" class="space-y-2">
          <input type="text" name="subjects[]" placeholder="e.g., Math" class="input-style">
        </div>
        <button type="button" onclick="addSubjectField()" class="mt-2 text-sm text-blue-600 underline hover:text-blue-800">➕ Add another subject</button>
      </div>

      <div class="col-span-1 md:col-span-2 text-center">
        <button type="submit" class="button-style mt-4">✅ Save</button>
      </div>
    </form>
  </div>

  <script>
    function addSubjectField() {
      const container = document.getElementById('subjectFields');
      const input = document.createElement('input');
      input.type = 'text';
      input.name = 'subjects[]';
      input.placeholder = 'e.g., Science';
      input.className = 'input-style';
      container.appendChild(input);
    }
  </script>

</body>
</html>
