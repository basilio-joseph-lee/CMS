<?php
session_start();
if (!isset($_SESSION['teacher_id'])) {
  header("Location: teacher_login.php");
  exit;
}
$teacherId = $_SESSION['teacher_id'];
$teacherName = $_SESSION['fullname'];

// DB connect
$conn = new mysqli("localhost", "root", "", "cms");
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

// Get active school year
$schoolYearResult = $conn->query("SELECT * FROM school_years WHERE status = 'active' LIMIT 1");
$activeYear = $schoolYearResult->fetch_assoc();

// Get all admin-created sections
$sections = $conn->query("SELECT * FROM master_sections ORDER BY section_name ASC");

// Get all admin-created subjects
$subjects = $conn->query("SELECT * FROM master_subjects ORDER BY subject_name ASC");

$errors = [];
if (isset($_GET['error']) && $_GET['error'] === 'duplicate') {
  $errors[] = "⚠️ Section and subjects already registered for this school year.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Assign Advisory + Subjects</title>
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
    .toast {
      animation: fadeOut 3s forwards;
    }
    @keyframes fadeOut {
      0% { opacity: 1; }
      80% { opacity: 1; }
      100% { opacity: 0; display: none; }
    }
  </style>
</head>
<body class="flex items-center justify-center min-h-screen px-4 py-10">

  <div class="card w-full max-w-4xl relative shadow-pin">
    <a href="teacher_dashboard.php" class="inline-block mb-4 bg-orange-400 text-white font-bold px-6 py-2 rounded-md hover:bg-orange-500 transition">
      ← Back
    </a>

    <?php if (!empty($errors)): ?>
      <div id="toast" class="toast mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative transition-opacity duration-1000">
        <?php foreach ($errors as $error): ?>
          <p><?= $error ?></p>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <h2 class="text-2xl sm:text-3xl text-center font-bold text-yellow-900 mb-6">📂️ Assign School Year, Section, and Subjects</h2>

    <?php if (!$activeYear): ?>
      <p class="text-red-600 text-center font-bold">⚠️ No active school year. Contact Admin.</p>
    <?php else: ?>
      <form method="POST" action="../config/save_school_year.php" class="grid grid-cols-1 md:grid-cols-2 gap-6">

        <!-- School Year -->
        <div>
          <label class="section-title">Active School Year</label>
          <input type="text" class="input-style" value="<?= htmlspecialchars($activeYear['year_label']) ?>" readonly>
          <input type="hidden" name="school_year_id" value="<?= $activeYear['school_year_id'] ?>">
        </div>

        <!-- Section -->
        <div>
          <label class="section-title">Select Section</label>
          <select name="section_name" required class="input-style">
            <option value="">-- Select Section --</option>
            <?php while ($row = $sections->fetch_assoc()): ?>
              <option value="<?= htmlspecialchars($row['section_name']) ?>"><?= htmlspecialchars($row['section_name']) ?></option>
            <?php endwhile; ?>
          </select>
        </div>

        <!-- Subjects -->
        <div class="md:col-span-2">
          <label class="section-title">Select Subjects</label>
          <div id="subjectDropdowns" class="space-y-2">
            <select name="subject_names[]" required class="input-style">
              <option value="">-- Select Subject --</option>
              <?php $subjects->data_seek(0); while ($s = $subjects->fetch_assoc()): ?>
                <option value="<?= htmlspecialchars($s['subject_name']) ?>"><?= htmlspecialchars($s['subject_name']) ?></option>
              <?php endwhile; ?>
            </select>
          </div>
          <button type="button" onclick="addSubjectDropdown()" class="mt-2 text-sm text-blue-600 underline hover:text-blue-800">🔊 Add another subject</button>
        </div>

        <div class="col-span-1 md:col-span-2 text-center">
          <button type="submit" class="button-style mt-4">✅ Save</button>
        </div>
      </form>
    <?php endif; ?>
  </div>

  <script>
    function addSubjectDropdown() {
      const container = document.getElementById('subjectDropdowns');
      const select = document.createElement('select');
      select.name = 'subject_names[]';
      select.required = true;
      select.className = 'input-style';
      select.innerHTML = document.querySelector('select[name="subject_names[]"]').innerHTML;
      container.appendChild(select);
    }
  </script>
</body>
</html>
