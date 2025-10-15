<?php
session_start();

// Include DB connection and config functions later
include("../config/db.php");

$sql = "SELECT school_year_id, year_label 
        FROM school_years 
        WHERE status = 'active' 
        LIMIT 1";

$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    $activeSY = $result->fetch_assoc();
    $ACTIVE_SY_ID = (int)$activeSY['school_year_id'];
    $ACTIVE_SY_LABEL = $activeSY['year_label'];
} else {
    
}

// Determine page
$page = $_GET['page'] ?? 'dashboard';

// Dashboard Statistics
$totalTeachers = $conn->query("SELECT COUNT(*) as count FROM teachers")->fetch_assoc()['count'];
$totalStudents = $conn->query("SELECT COUNT(*) as count FROM students")->fetch_assoc()['count'];
$totalSubjects = $conn->query("SELECT COUNT(*) as count FROM subjects")->fetch_assoc()['count'];
$totalSections = $conn->query("SELECT COUNT(*) as count FROM master_sections")->fetch_assoc()['count'];
$activeYear = $conn->query("SELECT year_label FROM school_years WHERE status = 'active' LIMIT 1")->fetch_assoc()['year_label'] ?? 'None';

$unassignedTeachers = $conn->query("SELECT COUNT(*) as count FROM teachers t LEFT JOIN advisory_classes a ON t.teacher_id = a.teacher_id WHERE a.teacher_id IS NULL")->fetch_assoc()['count'];
$unassignedSubjects = $conn->query("SELECT COUNT(*) as count FROM master_subjects m LEFT JOIN subjects s ON m.subject_name = s.subject_name WHERE s.subject_id IS NULL")->fetch_assoc()['count'];
$populatedSection = $conn->query("
  SELECT ac.class_name, COUNT(*) as total
  FROM student_enrollments se
  JOIN advisory_classes ac ON se.advisory_id = ac.advisory_id
  GROUP BY ac.class_name
  ORDER BY total DESC
  LIMIT 1
")->fetch_assoc();

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin Panel</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="flex bg-gray-100 min-h-screen">

  <!-- Sidebar -->
  <aside class="w-64 bg-blue-900 text-white p-5 space-y-4">
    <h1 class="text-xl font-bold mb-4">Admin Panel</h1>

        <nav class="space-y-1">
            <a href="admin.php?page=dashboard" class="block py-2 px-3 <?= $page === 'dashboard' ? 'bg-blue-700' : 'hover:bg-blue-800' ?> rounded">📊 Dashboard</a>
            <a href="admin.php?page=school_years" class="block py-2 px-3 <?= $page === 'school_years' ? 'bg-blue-700' : 'hover:bg-blue-800' ?> rounded">📘 School Years</a>
            <a href="admin.php?page=sections" class="block py-2 px-3 <?= $page === 'sections' ? 'bg-blue-700' : 'hover:bg-blue-800' ?> rounded">🏫 Sections</a>
            <a href="admin.php?page=subjects" class="block py-2 px-3 <?= $page === 'subjects' ? 'bg-blue-700' : 'hover:bg-blue-800' ?> rounded">📚 Subjects</a>
            <a href="admin.php?page=students" class="block py-2 px-3 <?= $page === 'students' ? 'bg-blue-700' : 'hover:bg-blue-800' ?> rounded">🧑‍🎓 Students</a>
            <a href="admin.php?page=set_schedule" class="block py-2 px-3 <?= $page === 'set_schedule' ? 'bg-blue-700' : 'hover:bg-blue-800' ?> rounded">⏰ Set Schedule</a>
            <a href="admin.php?page=teachers" class="block py-2 px-3 <?= $page === 'teachers' ? 'bg-blue-700' : 'hover:bg-blue-800' ?> rounded">👩‍🏫 Teachers</a>
            <a href="admin.php?page=parents" class="block py-2 px-3 <?= $page === 'parents' ? 'bg-blue-700' : 'hover:bg-blue-800' ?> rounded">👪 Parents</a>
            <a href="admin.php?page=enroll_student" class="block py-2 px-3 <?= $page === 'enroll_student' ? 'bg-blue-700' : 'hover:bg-blue-800' ?> rounded">📝 Assign Students</a>
            <a href="admin.php?page=grades" class="block py-2 px-3 <?= $page === 'grades' ? 'bg-blue-700' : 'hover:bg-blue-800' ?> rounded">📊 Grades</a>
            <a href="admin.php?page=attendance" class="block py-2 px-3 <?= $page === 'attendance' ? 'bg-blue-700' : 'hover:bg-blue-800' ?> rounded">🗓 Attendance</a>
            <a href="admin.php?page=announcement" class="block py-2 px-3 <?= $page === 'announcement' ? 'bg-blue-700' : 'hover:bg-blue-800' ?> rounded">📢 Announcement</a>
            <hr class="my-4 border-gray-600">

            <form method="POST" action="../config/logout.php">
                <button type="submit" class="w-full text-left py-2 px-3 hover:bg-red-700 rounded text-red-100 flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-red-200" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M3 4a1 1 0 011-1h6a1 1 0 010 2H5v10h5a1 1 0 110 2H4a1 1 0 01-1-1V4zm9.293 1.293a1 1 0 011.414 0L18 9.586a1 1 0 010 1.414l-4.293 4.293a1 1 0 01-1.414-1.414L14.586 11H9a1 1 0 110-2h5.586l-1.293-1.293a1 1 0 010-1.414z" clip-rule="evenodd" />
                    </svg>
                    Logout
                </button>
            </form>

        </nav>

        

  </aside>

  <!-- Main Content -->
  <main class="flex-1 p-8">
    <?php if ($page === 'dashboard'): ?>
      <h2 class="text-3xl font-bold mb-6">Welcome, Admin</h2>
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <div class="bg-white p-6 rounded shadow text-center">
          <h3 class="text-lg font-semibold text-gray-600">Total Teachers</h3>
          <p class="text-3xl font-bold text-blue-600 animate-count" data-count="<?= $totalTeachers ?>">0</p>
        </div>
        <div class="bg-white p-6 rounded shadow text-center">
          <h3 class="text-lg font-semibold text-gray-600">Total Students</h3>
          <p class="text-3xl font-bold text-green-600 animate-count" data-count="<?= $totalStudents ?>">0</p>
        </div>
        <div class="bg-white p-6 rounded shadow text-center">
          <h3 class="text-lg font-semibold text-gray-600">Total Subjects</h3>
          <p class="text-3xl font-bold text-indigo-600 animate-count" data-count="<?= $totalSubjects ?>">0</p>
        </div>
        <div class="bg-white p-6 rounded shadow text-center">
          <h3 class="text-lg font-semibold text-gray-600">Total Sections</h3>
          <p class="text-3xl font-bold text-yellow-600 animate-count" data-count="<?= $totalSections ?>">0</p>
        </div>
        <div class="bg-white p-6 rounded shadow text-center">
          <h3 class="text-lg font-semibold text-gray-600">Active School Year</h3>
          <p class="text-3xl font-bold text-pink-600"><?= $activeYear ?></p>
        </div>
        <div class="bg-white p-6 rounded shadow text-center">
          <h3 class="text-lg font-semibold text-gray-600">Unassigned Teachers</h3>
          <p class="text-3xl font-bold text-red-500 animate-count" data-count="<?= $unassignedTeachers ?>">0</p>
        </div>
        <div class="bg-white p-6 rounded shadow text-center">
          <h3 class="text-lg font-semibold text-gray-600">Subjects Without Teacher</h3>
          <p class="text-3xl font-bold text-red-400 animate-count" data-count="<?= $unassignedSubjects ?>">0</p>
        </div>
        <div class="bg-white p-6 rounded shadow text-center">
          <h3 class="text-lg font-semibold text-gray-600">Most Populated Section</h3>
          <p class="text-2xl font-bold text-gray-700">📘 <?= $populatedSection['class_name'] ?? 'N/A' ?> (<?= $populatedSection['total'] ?? 0 ?> students)</p>
        </div>
      </div>

      <script>
        document.querySelectorAll('.animate-count').forEach(el => {
          const count = +el.getAttribute('data-count');
          let i = 0;
          const interval = setInterval(() => {
            el.textContent = i;
            if (i >= count) clearInterval(interval);
            i++;
          }, 30);
        });
      </script>

    <?php elseif ($page === 'school_years'): ?>
      <h2 class="text-2xl font-bold mb-4">📘 Manage School Years</h2>
      <?php include 'admin_views/school_years.php'; ?>

    <?php elseif ($page === 'sections'): ?>
      <h2 class="text-2xl font-bold mb-4">🏫 Manage Sections</h2>
      <?php include 'admin_views/sections.php'; ?>

    <?php elseif ($page === 'subjects'): ?>
      <h2 class="text-2xl font-bold mb-4">📚 Manage Subjects</h2>
      <?php include 'admin_views/subjects.php'; ?>

    <?php elseif ($page === 'students'): ?>
      <h2 class="text-2xl font-bold mb-4">🧑‍🎓 Manage Students</h2>
      <?php include 'admin_views/students.php'; ?>

    <?php elseif ($page === 'teachers'): ?>
      <h2 class="text-2xl font-bold mb-4">👩‍🏫 Manage Teachers</h2>
      <?php include 'admin_views/teachers.php'; ?>

      <?php elseif ($page === 'parents'): ?>
        <h2 class="text-2xl font-bold mb-4">👪 Manage Parents</h2>
        <?php include 'admin_views/parents.php'; ?>

    <?php elseif ($page === 'enroll_student'): ?>
    <h2 class="text-2xl font-bold mb-4">📝 Register Student</h2>
    <?php include 'admin_views/enroll_students.php'; ?>

        <?php elseif ($page === 'grades'): ?>
    <h2 class="text-2xl font-bold mb-4">📊 Student Grades</h2>
    <?php include 'admin_views/grades.php'; ?>

    <?php elseif ($page === 'announcement'): ?>
    <h2 class="text-2xl font-bold mb-4">📢 Announcements</h2>
    
    <?php include 'admin_views/announcement.php'; ?>

<?php elseif ($page === 'attendance'): ?>
<h2 class="text-2xl font-bold mb-4">🗓 Attendance Management</h2>
<?php include 'admin_views/attendance.php'; ?>

<?php elseif ($page === 'add_student_admin'): ?>
<h2 class="text-2xl font-bold mb-4">🧑‍🎓 Add Student</h2>
<?php include 'admin_views/add_student_admin.php'; ?>

<?php elseif ($page === 'set_schedule'): ?>


  <h2 class="text-2xl font-bold mb-4">⏰ Set Schedule &nbsp;<span class="text-gray-500 text-base">(SY: <?= htmlspecialchars($ACTIVE_SY_LABEL) ?>)</span></h2>
  <?php include 'admin_views/set_schedule.php'; ?>

    



    <?php else: ?>
      <p class="text-red-600">Page not found.</p>
    <?php endif; ?>
  </main>

</body>
</html>
