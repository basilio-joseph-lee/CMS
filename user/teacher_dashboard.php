<?php
session_start();
if (!isset($_SESSION['teacher_id'])) {
  header("Location: teacher_login.php");
  exit;
}

$teacherName = $_SESSION['teacher_fullname'] ?? 'Teacher';
$subjectName = $_SESSION['subject_name'] ?? '';
$className   = $_SESSION['class_name'] ?? '';
$yearLabel   = $_SESSION['year_label'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Teacher Dashboard</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    /* ======== Your original CSS kept ======== */
    body {
      background-image: url('../img/1.png');
      background-size: cover;
      background-position: center;
      font-family: 'Comic Sans MS', cursive, sans-serif;
    }
    .pin::before {
      content: "📌";
      font-size: 1.75rem;
      position: absolute;
      top: -14px;
      left: 50%;
      transform: translateX(-50%);
      /* small enhancement so the pin sits above the card shadow */
      z-index: 10;
    }
    .card {
      background-color: #fdf7e2;
      border-radius: 18px;
      padding: 20px 10px;
      box-shadow: 4px 6px 0 rgba(0, 0, 0, 0.15);
      text-align: center;
      transition: all 0.25s ease;
      height: 160px;
      display: flex;
      flex-direction: column;
      justify-content: center;
    }
    .card:hover {
      transform: scale(1.05);
      box-shadow: 6px 8px 0 rgba(0, 0, 0, 0.2);
    }
    .card-icon {
      font-size: 2.2rem;
      margin-bottom: 0.5rem;
    }

    /* ======== Enhancements (responsive + subtle polish) ======== */
    /* Add a slight border for that pinned-note feel */
    .card {
      border: 2px solid rgba(0, 0, 0, 0.06);
    }
    /* Reduce motion for users who prefer it */
    @media (prefers-reduced-motion: reduce) {
      .card, .card:hover {
        transition: none !important;
        transform: none !important;
      }
    }
    /* Responsive sizing for cards and icons */
    @media (min-width: 480px) {
      .card { height: 170px; padding: 22px 12px; }
      .card-icon { font-size: 2.4rem; }
    }
    @media (min-width: 640px) {
      .card { height: 180px; padding: 24px 14px; }
      .card-icon { font-size: 2.6rem; }
    }
    @media (min-width: 768px) {
      .card { height: 184px; }
      .card-icon { font-size: 2.7rem; }
    }
    @media (min-width: 1024px) {
      .card { height: 188px; }
      .card-icon { font-size: 2.8rem; }
    }

    /* Tighter cards on very small screens so text never wraps awkwardly */
    @media (max-width: 360px) {
      .card { height: 150px; padding: 16px 8px; }
      .card-icon { font-size: 2rem; }
    }

    /* Focus styles for accessibility (when tabbing) */
    .focus-outline {
      outline: 3px solid rgba(34, 197, 94, 0.5); /* green-500/50 */
      outline-offset: 3px;
    }
  </style>
</head>
<body class="min-h-screen flex flex-col items-center px-3 sm:px-4 py-6 sm:py-8">

  <!-- Header (kept, with minor responsive polish) -->
  <div class="w-full max-w-7xl bg-green-700/95 text-white flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 sm:gap-6 rounded-3xl shadow-lg mb-8 sm:mb-10 px-5 sm:px-6 py-4">
    <div class="space-y-1">
      <h1 class="text-xl sm:text-2xl md:text-3xl font-extrabold leading-tight">
        Welcome, <?= htmlspecialchars($teacherName); ?>
      </h1>
      <div class="text-xs sm:text-sm md:text-base">
        Subject:
        <span class="font-semibold"><?= htmlspecialchars($subjectName) ?></span>
        <span class="opacity-80">|</span>
        Section:
        <span class="font-semibold"><?= htmlspecialchars($className) ?></span>
        <span class="opacity-80">|</span>
        SY:
        <span class="font-semibold"><?= htmlspecialchars($yearLabel) ?></span>
      </div>
    </div>
    <div class="flex sm:justify-end">

        <a
          href="../config/logout.php?role=teacher"
          class="inline-flex items-center justify-center rounded-xl bg-orange-500 px-4 py-2 text-sm sm:text-base font-semibold shadow-md hover:bg-orange-600 focus:outline-none focus:ring-2 focus:ring-orange-300 transition"
        >
          Logout
        </a>

    </div>
  </div>

  <!-- Card Grid (original layout, smoother breakpoints) -->
  <div class="w-full max-w-7xl grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-4 gap-4 sm:gap-6 md:gap-8">
    <a href="add_student.php" class="relative pin block rounded-2xl focus-visible:focus-outline">
      <div class="card bg-blue-100">
        <div class="card-icon">🎥</div>
        <div class="font-bold text-sm sm:text-base">Enroll Student</div>
      </div>
    </a>

    <a href="teacher/view_students.php" class="relative pin block rounded-2xl focus-visible:focus-outline">
      <div class="card">
        <div class="card-icon">👦</div>
        <div class="font-bold text-sm sm:text-base">View Students</div>
      </div>
    </a>

    <a href="teacher/view_attendance_history.php" class="relative pin block rounded-2xl focus-visible:focus-outline">
      <div class="card bg-blue-100">
        <div class="card-icon">📅</div>
        <div class="font-bold text-xs sm:text-sm md:text-base">View Attendance History</div>
      </div>
    </a>

    <a href="teacher/grading_sheet.php" class="relative pin block rounded-2xl focus-visible:focus-outline">
      <div class="card">
        <div class="card-icon">📖</div>
        <div class="font-bold text-sm sm:text-base">Grades</div>
      </div>
    </a>

    <a href="teacher/mark_attendance.php" class="relative pin block rounded-2xl focus-visible:focus-outline">
      <div class="card bg-blue-100">
        <div class="card-icon">🗓️</div>
        <div class="font-bold text-sm sm:text-base">Mark Attendance</div>
      </div>
    </a>

    <a href="teacher/announcement.php" class="relative pin block rounded-2xl focus-visible:focus-outline">
      <div class="card">
        <div class="card-icon">📢</div>
        <div class="font-bold text-sm sm:text-base">Post Announcement</div>
      </div>
    </a>

    <a href="teacher/classroom_simulator.php" class="relative pin block rounded-2xl focus-visible:focus-outline">
      <div class="card bg-pink-100">
        <div class="card-icon">🪑</div>
        <div class="font-bold text-sm sm:text-base">Seating Plan</div>
      </div>
    </a>

        <!-- NEW: Quiz Game (Create & Publish) -->
    <a href="teacher/quiz_dashboard.php" class="relative pin block rounded-2xl focus-visible:focus-outline">
      <div class="card bg-green-100">
        <div class="card-icon">❓</div>
        <div class="font-bold text-sm sm:text-base">Quiz Game</div>
      </div>
    </a>

  </div>

  <!-- Modal (kept; small UX tweaks only) -->
  <div id="accessRequestModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex justify-center items-center z-50 p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md relative p-5 sm:p-6">
      <button
        onclick="document.getElementById('accessRequestModal').classList.add('hidden')"
        class="absolute top-2 right-3 text-gray-500 hover:text-red-500 text-2xl leading-none"
        aria-label="Close"
      >×</button>

      <h2 class="text-lg sm:text-xl font-bold mb-4">Request Section Access</h2>

      <form method="POST" action="../config/submit_access_request.php" class="space-y-4">
        <label class="block text-sm font-semibold">Select Section:</label>
        <select name="advisory_id" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-green-300">
          <?php
          $conn = new mysqli("localhost", "root", "", "cms");
          if (!$conn->connect_error) {
            $res = $conn->query("SELECT advisory_id, class_name FROM advisory_classes");
            if ($res) {
              while ($row = $res->fetch_assoc()) {
                echo "<option value='{$row['advisory_id']}'>".htmlspecialchars($row['class_name'])."</option>";
              }
            }
          }
          ?>
        </select>

        <label class="block text-sm font-semibold">Reason:</label>
        <textarea name="reason" rows="3" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-green-300"></textarea>

        <input type="hidden" name="school_year_id" value="<?= $_SESSION['school_year_id'] ?? '' ?>">
        <button type="submit" class="w-full bg-green-600 text-white px-4 py-2.5 rounded-xl font-semibold shadow hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-300 transition">
          Submit Request
        </button>
      </form>
    </div>
  </div>

  <!-- Optional: open modal demo (keep your original trigger commented if you want) -->
  <!--
  <button
    onclick="document.getElementById
