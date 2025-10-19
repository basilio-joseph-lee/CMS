<?php
session_start();
include '../../config/db.php';
include '../../config/teacher_guard.php';

$teacher_id = $_SESSION['teacher_id'] ?? 0;
$subject_id = $_SESSION['subject_id'] ?? null;
$advisory_id = $_SESSION['advisory_id'] ?? null;
$school_year_id = $_SESSION['school_year_id'] ?? null;




if ($subject_id && $advisory_id && $school_year_id):
    $quarter = $_GET['quarter'] ?? '1st';

    $stmt = $conn->prepare("SELECT s.student_id, s.fullname FROM students s 
                            JOIN student_enrollments e ON s.student_id = e.student_id 
                            WHERE e.subject_id = ? AND e.advisory_id = ? AND e.school_year_id = ?");
    $stmt->bind_param("iii", $subject_id, $advisory_id, $school_year_id);
    $stmt->execute();
    $students = $stmt->get_result();

    $weights = ['quiz'=>25, 'activity'=>25, 'performance'=>30, 'exam'=>20];
    $stmt = $conn->prepare("SELECT quiz_weight, activity_weight, performance_weight, exam_weight FROM grade_weights 
                            WHERE subject_id = ? AND advisory_id = ? AND quarter = ? AND school_year_id = ?");
    $stmt->bind_param("iisi", $subject_id, $advisory_id, $quarter, $school_year_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $weights['quiz'] = $row['quiz_weight'];
        $weights['activity'] = $row['activity_weight'];
        $weights['performance'] = $row['performance_weight'];
        $weights['exam'] = $row['exam_weight'];
    }

    $existing_scores = [];
    $max_scores = [];
    $stmt = $conn->prepare("SELECT * FROM grade_components WHERE subject_id = ? AND advisory_id = ? AND quarter = ? AND school_year_id = ?");
    $stmt->bind_param("iisi", $subject_id, $advisory_id, $quarter, $school_year_id);
    $stmt->execute();
    $components_result = $stmt->get_result();
    while ($row = $components_result->fetch_assoc()) {
        $existing_scores[$row['student_id']][$row['component_type']][$row['item_no']] = $row['score'];
        $max_scores[$row['component_type']][$row['item_no']] = $row['max_score'] ?? '';
    }

    $quarter_grades = [];
    $stmt = $conn->prepare("SELECT * FROM quarter_grades WHERE subject_id = ? AND advisory_id = ? AND quarter = ? AND school_year_id = ?");
    $stmt->bind_param("iisi", $subject_id, $advisory_id, $quarter, $school_year_id);
    $stmt->execute();
    $grade_res = $stmt->get_result();
    while ($row = $grade_res->fetch_assoc()) {
        $quarter_grades[$row['student_id']] = $row;
    }

    // Check if portal is open for this quarter
$portal_open = false;
$check = $conn->prepare("SELECT status FROM grading_portals WHERE school_year_id = ? AND quarter = ?");
$check->bind_param("is", $school_year_id, $quarter);
$check->execute();
$portal_res = $check->get_result();
if ($row = $portal_res->fetch_assoc()) {
    $portal_open = ($row['status'] === 'open');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Grading Sheet</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body {
      background-image: url('../../img/role.png');
      background-size: cover;
      font-family: 'Comic Sans MS', cursive, sans-serif;
    }
    @keyframes bounce-right {
      0%, 100% { transform: translateX(0); }
      50% { transform: translateX(6px); }
    }
    .animate-bounce-right {
      animation: bounce-right 1.5s infinite;
    }
  </style>
</head>
<body class="p-6">
  <!-- <?php if (!$portal_open): ?>
  <div class="fixed top-4 right-4 bg-red-500 text-white px-6 py-3 rounded-xl shadow-xl z-50">
    🚫 This grading portal is currently closed. Publishing is disabled.
  </div>
  <script>
    setTimeout(() => document.querySelector('.fixed.bg-red-500')?.remove(), 4000);
  </script>
<?php endif; ?> -->

<?php if (isset($_GET['success']) || isset($_GET['published'])): ?>
  <div class="fixed top-4 right-4 px-6 py-3 rounded-xl shadow-xl z-50
              <?= isset($_GET['success']) ? 'bg-green-500' : 'bg-green-600' ?> text-white">
    <?= isset($_GET['success']) ? '✅ Grades saved successfully!' : '🚀 Grades published to portal!' ?>
  </div>
  <script>
    setTimeout(() => document.querySelector('.fixed.top-4.right-4')?.remove(), 3000);
  </script>
<?php endif; ?>


<div class="max-w-7xl mx-auto bg-[#fffbea] p-10 rounded-3xl shadow-2xl ring-4 ring-yellow-300">
  <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
    <div>
      <h1 class="text-4xl font-bold text-[#bc6c25]">📘 Grading Sheet</h1>
      <form method="GET" class="mt-2">
        <label class="text-gray-800 font-semibold text-sm mr-2">Quarter:</label>
        <select name="quarter" onchange="this.form.submit()" class="px-3 py-1 rounded bg-yellow-100 border border-yellow-400 text-gray-800 shadow text-sm">
          <?php foreach (['1st','2nd','3rd','4th'] as $q): ?>
            <option value="<?= $q ?>" <?= $quarter === $q ? 'selected' : '' ?>><?= ucfirst($q) ?> Quarter</option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
    <div class="flex gap-2 items-center">
      <button type="button" onclick="document.getElementById('weightModal').classList.remove('hidden')" class="bg-yellow-300 hover:bg-yellow-400 text-gray-800 font-bold px-4 py-2 rounded-lg shadow">
        ⚖️ Edit Weight %
      </button>
      <a href="teacher_dashboard.php" class="bg-orange-400 hover:bg-orange-500 text-white text-lg font-bold px-6 py-2 rounded-lg shadow-md">← Back</a>
    </div>
  </div>

  <form method="POST" action="../../config/save_grades.php">
    <input type="hidden" name="subject_id" value="<?= $subject_id ?>">
    <input type="hidden" name="advisory_id" value="<?= $advisory_id ?>">
    <input type="hidden" name="quarter" value="<?= $quarter ?>">

    <!-- Modal for Weights -->
    <div id="weightModal" class="fixed inset-0 bg-black bg-opacity-40 backdrop-blur-sm flex justify-center items-center z-50 hidden">
      <div class="bg-white p-6 rounded-xl shadow-xl w-11/12 max-w-md">
        <h2 class="text-xl font-bold text-yellow-700 mb-4">Edit Grade Weights</h2>
        <div class="grid grid-cols-2 gap-4">
          <?php foreach (['quiz', 'activity', 'performance', 'exam'] as $type): ?>
            <div>
              <label class="block text-sm font-semibold text-gray-700"><?= ucfirst($type) ?> (%)</label>
              <input type="number" name="weights[<?= $type ?>]" value="<?= $weights[$type] ?>" class="w-full px-3 py-2 border border-yellow-400 rounded-md shadow-sm focus:ring-2 focus:ring-yellow-300 text-sm" required>
            </div>
          <?php endforeach; ?>
        </div>
<div class="mt-4 text-right text-sm font-semibold">
  <span id="weightTotal" class="text-gray-700">Total: 100%</span>
</div>

<div class="flex justify-end mt-4">
  <button type="button" onclick="document.getElementById('weightModal').classList.add('hidden')" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-semibold px-4 py-2 rounded mr-2">Cancel</button>
  <button type="submit" id="saveWeightBtn" class="bg-green-500 hover:bg-green-600 text-white font-bold px-6 py-2 rounded shadow">✅ Save</button>
</div>

      </div>
    </div>

    <!-- Grading Table Section (Original) -->
    <div class="relative overflow-x-auto border-4 border-yellow-400 rounded-2xl shadow mb-6 bg-white">
      <table class="w-full text-sm text-center">
        <thead class="bg-yellow-300 text-gray-900">
          <tr>
            <th rowspan="3" class="border px-2 py-2">Student</th>
            <?php foreach (["quiz", "activity", "performance", "exam"] as $cat): ?>
              <th colspan="6" class="border px-2 py-2"><?= ucfirst($cat) ?></th>
            <?php endforeach; ?>
            <th rowspan="3" class="border px-2 py-2">Average</th>
            <th rowspan="3" class="border px-2 py-2">Remarks</th>
          </tr>
          <tr>
            <?php foreach (["quiz", "activity", "performance", "exam"] as $cat): ?>
              <?php for ($j = 1; $j <= 5; $j++): ?>
                <th class="border px-2 py-1">#<?= $j ?></th>
              <?php endfor; ?>
              <th class="border px-2 py-1">%</th>
            <?php endforeach; ?>
          </tr>
          <tr>
            <?php foreach (["quiz", "activity", "performance", "exam"] as $cat): ?>
              <?php for ($j = 1; $j <= 5; $j++): ?>
                <th class="border">
                  <input type="number" step="1" min="1" name="max_scores[<?= $cat ?>][<?= $j ?>]"
                         value="<?= $max_scores[$cat][$j] ?? '' ?>" class="w-12 border rounded text-xs px-1 text-center">
                </th>
              <?php endfor; ?>
              <th class="border"></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php while ($stu = $students->fetch_assoc()): ?>
            <?php
              $computed = ['quiz'=>0, 'activity'=>0, 'performance'=>0, 'exam'=>0];
              $final_average = 0;
              $final_weight = 0;
              foreach (["quiz", "activity", "performance", "exam"] as $type) {
                $sum = 0; $total = 0;
                for ($i = 1; $i <= 5; $i++) {
                  $score = $existing_scores[$stu['student_id']][$type][$i] ?? null;
                  $max = $max_scores[$type][$i] ?? null;
                  if ($score !== null && $max > 0) {
                    $sum += ($score / $max) * 100;
                    $total++;
                  }
                }
                if ($total > 0) {
                  $average = ($sum / $total);
                  $weighted = round(($average * $weights[$type] / 100), 2);
                  $computed[$type] = $weighted;
                  $final_average += $weighted;
                  $final_weight += $weights[$type];
                }
              }
              $final_grade = ($final_weight > 0) ? round($final_average, 2) : 'N/A';
              $remarks = ($final_weight > 0) ? ($final_grade >= 75 ? 'PASSED' : 'FAILED') : 'INC';
            ?>
            <tr class="hover:bg-yellow-100">
              <td class="border text-left px-2 py-1 font-semibold"><?= htmlspecialchars($stu['fullname']) ?></td>
              <?php foreach (["quiz", "activity", "performance", "exam"] as $type): ?>
                <?php for ($i = 1; $i <= 5; $i++): ?>
                  <?php $score = $existing_scores[$stu['student_id']][$type][$i] ?? ''; ?>
                  <td class="border p-1">
                    <input type="number" step="1" min="0" name="scores[<?= $stu['student_id'] ?>][<?= $type ?>][<?= $i ?>]"
                           value="<?= $score !== '' ? (int)$score : '' ?>" class="w-12 px-1 py-0.5 border rounded text-center text-xs">
                  </td>
                <?php endfor; ?>
                <td class="border font-bold text-green-700"><?= $computed[$type] ?></td>
              <?php endforeach; ?>
              <td class="border bg-yellow-100 font-semibold text-green-800"><?= $final_grade ?></td>
              <td class="border bg-yellow-100 font-semibold"><?= $remarks ?></td>
            </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>

<!-- Inside the form footer -->
<div class="text-center flex gap-4 justify-center mt-6">
  <button type="submit" name="save_draft" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-3 rounded-lg shadow-lg">
    💾 Save Draft
  </button>

  <?php if ($portal_open): ?>
    <button type="submit" name="publish" class="bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded-lg shadow-lg">
      🚀 Publish to Portal
    </button>
  <?php else: ?>
    <button type="button" disabled class="bg-gray-400 text-white px-6 py-3 rounded-lg shadow-lg cursor-not-allowed">
      🚫 Portal Closed
    </button>
  <?php endif; ?>
</div>


  </form>
</div>

<script>
document.querySelectorAll('input[name^="weights["]').forEach(input => {
  input.addEventListener('input', updateWeightTotal);
});

function updateWeightTotal() {
  let total = 0;
  document.querySelectorAll('input[name^="weights["]').forEach(input => {
    total += parseFloat(input.value) || 0;
  });

  const totalDisplay = document.getElementById('weightTotal');
  const saveBtn = document.getElementById('saveWeightBtn');

  totalDisplay.textContent = `Total: ${total}%`;

  if (total === 100) {
    totalDisplay.classList.remove('text-red-600');
    totalDisplay.classList.add('text-green-600');
    saveBtn.disabled = false;
    saveBtn.classList.remove('opacity-50', 'cursor-not-allowed');
  } else {
    totalDisplay.classList.remove('text-green-600');
    totalDisplay.classList.add('text-red-600');
    saveBtn.disabled = true;
    saveBtn.classList.add('opacity-50', 'cursor-not-allowed');
  }
}
updateWeightTotal(); // run once when modal opens
</script>


</body>
</html>
<?php endif; ?>
