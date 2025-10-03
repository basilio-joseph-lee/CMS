<?php
session_start();
include '../../config/teacher_guard.php';
include "../../config/db.php";

$teacher_id = $_SESSION['teacher_id'];
$subject_id = $_SESSION['subject_id'];
$advisory_id = $_SESSION['advisory_id'];
$school_year_id = $_SESSION['school_year_id'];

$subject_name = $_SESSION['subject_name'];
$class_name = $_SESSION['class_name'];
$year_label = $_SESSION['year_label'];

if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

$stmt = $conn->prepare("SELECT s.student_id, s.fullname, s.avatar_path FROM students s 
  JOIN student_enrollments e ON s.student_id = e.student_id 
  WHERE e.subject_id = ? AND e.advisory_id = ? AND e.school_year_id = ?");
$stmt->bind_param("iii", $subject_id, $advisory_id, $school_year_id);
$stmt->execute();
$result = $stmt->get_result();
$students = $result->fetch_all(MYSQLI_ASSOC);

$today = date('Y-m-d');
$marked_today = [];

$checkStmt = $conn->prepare("SELECT student_id FROM attendance_records 
  WHERE subject_id = ? AND advisory_id = ? AND school_year_id = ? AND DATE(timestamp) = ?");
$checkStmt->bind_param("iiis", $subject_id, $advisory_id, $school_year_id, $today);
$checkStmt->execute();
$resultMarked = $checkStmt->get_result();

while ($row = $resultMarked->fetch_assoc()) {
  $marked_today[] = $row['student_id'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Mark Attendance</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body {
      background-image: url('../../img/role.png');
      background-size: cover;
      font-family: 'Comic Sans MS', cursive, sans-serif;
    }
    tr.active-row { background-color: #fff9c4; }
    .fade-out { animation: fadeOut 1s forwards; }
    @keyframes fadeOut { to { opacity: 0; transform: translateY(-20px); } }
  </style>
</head>
<body class="p-6">

<audio id="tickSound" src="../../audio/tick.mp3" preload="auto"></audio>

<div id="toast-warning" class="hidden fixed inset-0 flex items-center justify-center z-50">
  <div class="bg-yellow-400 text-black px-8 py-5 rounded-xl shadow-2xl text-xl font-semibold animate-bounce">
    ⚠️ Please mark attendance for all students.
  </div>
</div>

<?php if (isset($_GET['success'])): ?>
  <div class="fixed top-4 right-4 bg-green-500 text-white px-6 py-3 rounded-xl shadow-xl z-50">
    ✅ Attendance saved successfully!
  </div>
  <script>
    setTimeout(() => document.querySelector('.fixed.top-4.right-4')?.remove(), 3000);
  </script>
<?php endif; ?>

<div class="max-w-6xl mx-auto bg-[#fffbea] p-10 rounded-3xl shadow-2xl ring-4 ring-yellow-300">
  <div class="flex justify-between items-center mb-6">
    <div>
      <h1 class="text-4xl font-bold text-[#bc6c25]">📌 Mark Attendance</h1>
      <p class="text-lg font-semibold text-gray-800 mt-2">Subject: 
        <span class="text-green-700"><?= $subject_name ?></span> — 
        <span class="text-blue-700"><?= $class_name ?></span> | SY: 
        <span class="text-red-700"><?= $year_label ?></span>
      </p>
    </div>
    <a href="teacher_dashboard.php" class="bg-orange-400 hover:bg-orange-500 text-white text-lg font-bold px-6 py-2 rounded-lg shadow-md">← Back</a>
  </div>

  <form action="../../config/save_attendance.php" method="POST">
    <input type="hidden" name="subject_id" value="<?= $subject_id ?>">
    <input type="hidden" name="advisory_id" value="<?= $advisory_id ?>">
    <input type="hidden" name="school_year_id" value="<?= $school_year_id ?>">

    <div class="overflow-x-auto">
      <table class="w-full text-center border-4 border-yellow-400 rounded-2xl overflow-hidden">
        <thead class="bg-yellow-300 text-gray-900">
          <tr>
            <th class="py-3 px-4 text-lg">#</th>
            <th class="py-3 px-4 text-lg text-left">Student Name</th>
            <th class="py-3 px-4 text-lg">✅ Present</th>
            <th class="py-3 px-4 text-lg">❌ Absent</th>
            <th class="py-3 px-4 text-lg text-yellow-600">⏰ Late</th>
          </tr>
        </thead>
        <tbody>
          <?php 
          $i = 1; 
          $firstActiveIndex = -1;
          foreach ($students as $index => $student): 
            $isMarked = in_array($student['student_id'], $marked_today);
            if (!$isMarked && $firstActiveIndex === -1) $firstActiveIndex = $index;
          ?>
            <tr class="border-b border-yellow-300 student-row 
              <?= !$isMarked && $firstActiveIndex === $index ? 'active-row' : '' ?> 
              <?= $isMarked ? 'bg-green-100' : '' ?>">
              <td class="py-3 text-lg font-semibold text-gray-800 px-4"><?= $i++ ?></td>
              <td class="py-3 px-4 text-lg text-gray-700 text-left">
                <?= htmlspecialchars($student['fullname']) ?>
                <?php if ($isMarked): ?>
                  <span class="text-sm text-green-700 ml-2">✅ Already marked</span>
                <?php endif; ?>
              </td>
              <td class="py-3">
                <input type="radio" name="attendance[<?= $student['student_id'] ?>]" value="Present" class="accent-green-500 present-radio w-5 h-5" <?= $isMarked ? 'disabled' : '' ?>>
              </td>
              <td class="py-3">
                <input type="radio" name="attendance[<?= $student['student_id'] ?>]" value="Absent" class="accent-red-500 w-5 h-5" <?= $isMarked ? 'disabled' : '' ?>>
              </td>
              <td class="py-3">
                <input type="radio" name="attendance[<?= $student['student_id'] ?>]" value="Late" class="accent-yellow-500 w-5 h-5" <?= $isMarked ? 'disabled' : '' ?>>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div id="attendance-summary" class="text-center mt-4 text-lg font-semibold text-gray-700"></div>

    <div class="text-center mt-8">
      <button type="submit" class="bg-green-500 hover:bg-green-600 text-white font-bold px-10 py-4 text-lg rounded-xl shadow-lg">📥 Submit Attendance</button>
    </div>
  </form>
</div>

<script>
const rows = document.querySelectorAll(".student-row");
let current = Array.from(rows).findIndex(r => r.classList.contains("active-row"));
if (current === -1) current = 0;

function nextRow() {
  rows[current]?.classList.remove("active-row");
  do { current++; }
  while (current < rows.length && rows[current].classList.contains("bg-green-100"));
  if (current < rows.length) {
    rows[current].classList.add("active-row");
    rows[current].scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
}

document.addEventListener("keydown", (e) => {
  const row = rows[current];
  if (!row) return;

  const presentInput = row.querySelector("input[value='Present']");
  const absentInput = row.querySelector("input[value='Absent']");
  const lateInput = row.querySelector("input[value='Late']");
  const sound = document.getElementById("tickSound");

  if (e.key === "Enter" && presentInput && !presentInput.disabled) {
    presentInput.checked = true;
    sound?.play();
    nextRow();
    checkAutoSubmit();
  } else if (e.key === "ArrowRight" && absentInput && !absentInput.disabled) {
    absentInput.checked = true;
    sound?.play();
    nextRow();
    checkAutoSubmit();
  } else if ((e.key === "l" || e.key === "L") && lateInput && !lateInput.disabled) {
    lateInput.checked = true;
    sound?.play();
    nextRow();
    checkAutoSubmit();
  }
});

function updateSummary() {
  const total = document.querySelectorAll(".student-row:not(.bg-green-100)").length;
  const marked = document.querySelectorAll("input[type=radio]:checked").length;
  document.getElementById("attendance-summary").textContent = `Marked: ${marked} / ${total}`;
}
setInterval(updateSummary, 1000);

function checkAutoSubmit() {
  const unmarked = Array.from(document.querySelectorAll(".student-row:not(.bg-green-100)"))
    .filter(row => !row.querySelector("input[type=radio]:checked"));
  if (unmarked.length === 0 && unmarked.length !== -1) {
    document.querySelector("form").submit();
  }
}

document.querySelectorAll("input[type=radio]").forEach(input => {
  input.addEventListener("change", () => {
    checkAutoSubmit();
    updateSummary();
  });
});

document.querySelector("form").addEventListener("submit", function(e) {
  const radioInputs = document.querySelectorAll("input[type=radio]:not(:disabled)");
  const attendanceStatus = {};
  radioInputs.forEach(input => {
    const name = input.name;
    if (!(name in attendanceStatus)) attendanceStatus[name] = false;
    if (input.checked) attendanceStatus[name] = true;
  });
  const unmarked = Object.entries(attendanceStatus).filter(([_, marked]) => !marked);
  if (unmarked.length > 0) {
    e.preventDefault();
    const toast = document.getElementById("toast-warning");
    toast.classList.remove("hidden");
    setTimeout(() => { toast.classList.add("hidden"); }, 3000);
  }
});

function fetchMarkedStudents() {
  fetch("../../config/fetch_attendance.php")
    .then(res => res.json())
    .then(data => {
      const markedIds = data.marked;
      document.querySelectorAll(".student-row").forEach(row => {
        const studentId = row.querySelector("input[type=radio]")?.name?.match(/\d+/)?.[0];
        if (!studentId || !(studentId in markedIds)) return;
        const status = markedIds[studentId];
        if (!row.classList.contains("bg-green-100") && !row.classList.contains("bg-red-100")) {
          if (status === "Present") row.classList.add("bg-green-100");
          else row.classList.add("bg-red-100");

          row.classList.remove("active-row");
          const radios = row.querySelectorAll("input[type=radio]");
          radios.forEach(radio => {
            radio.disabled = true;
            if (radio.value === status) radio.checked = true;
          });

          const nameCell = row.querySelector("td:nth-child(2)");
          const statusNote = document.createElement("span");
          statusNote.className = "text-sm text-green-700 ml-2";
          statusNote.textContent = "✅ Already marked";
          if (!nameCell.querySelector("span")) nameCell.appendChild(statusNote);
        }
      });
    });
}
setInterval(fetchMarkedStudents, 5000);

window.addEventListener('offline', () => {
  alert('⚠️ You are offline. Attendance may not save.');
});

setTimeout(() => {
  alert("⏰ Time's up! Submitting attendance now.");
  document.querySelector("form").submit();
}, 10 * 60 * 1000); // 10 minutes
</script>
</body>
</html>
