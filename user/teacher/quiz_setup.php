<?php
session_start();
include '../../config/teacher_guard.php';
if (!isset($_SESSION['teacher_id'])) { header("Location: ../teacher_login.php"); exit; }

$teacherName = htmlspecialchars($_SESSION['fullname'] ?? 'Teacher');
$subjectName = htmlspecialchars($_SESSION['subject_name'] ?? '');
$className   = htmlspecialchars($_SESSION['class_name'] ?? '');
$yearLabel   = htmlspecialchars($_SESSION['year_label'] ?? '');
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Quiz Setup</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-white flex items-center justify-center p-6">
  <div class="bg-white shadow-lg rounded-2xl p-8 w-full max-w-md">
    <h1 class="text-2xl font-bold mb-4">🎮 New Quiz Game</h1>
    <p class="text-sm text-gray-600 mb-6">
      <?= $teacherName ?> • <?= $subjectName ?> | <?= $className ?> | SY <?= $yearLabel ?>
    </p>

    <form action="quiz_game.php" method="GET" class="space-y-4">
      <div>
        <label class="block font-semibold mb-1">Select Game Type</label>
        <select name="game_type" class="w-full border rounded-lg px-3 py-2" required>
          <option value="multiple_choice">Multiple Choice</option>
          <!-- <option value="true_false">True / False</option>
          <option value="identification">Identification</option> -->
          <!-- add more later -->
        </select>
      </div>

      <div>
        <label class="block font-semibold mb-1">How many questions?</label>
        <input type="number" name="total_questions" min="1" max="50"
               class="w-full border rounded-lg px-3 py-2" placeholder="e.g. 10" required>
      </div>

      <button type="submit" class="bg-blue-600 text-white w-full py-2 rounded-lg">Proceed ➡️</button>
    </form>
  </div>
</body>
</html>
