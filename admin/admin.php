<?php
session_start();

// Include DB connection and config functions later
$conn = new mysqli("localhost", "root", "", "cms");
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

// Determine page
$page = $_GET['page'] ?? 'dashboard';
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
    <nav class="space-y-2">
      <a href="admin.php?page=dashboard" class="block py-2 px-3 <?= $page === 'dashboard' ? 'bg-blue-700' : 'hover:bg-blue-800' ?> rounded">📊 Dashboard</a>
      <a href="admin.php?page=school_years" class="block py-2 px-3 <?= $page === 'school_years' ? 'bg-blue-700' : 'hover:bg-blue-800' ?> rounded">📘 School Years</a>
      <a href="admin.php?page=sections" class="block py-2 px-3 <?= $page === 'sections' ? 'bg-blue-700' : 'hover:bg-blue-800' ?> rounded">🏫 Sections</a>
      <a href="admin.php?page=subjects" class="block py-2 px-3 <?= $page === 'subjects' ? 'bg-blue-700' : 'hover:bg-blue-800' ?> rounded">📚 Subjects</a>
      <a href="admin.php?page=students" class="block py-2 px-3 <?= $page === 'students' ? 'bg-blue-700' : 'hover:bg-blue-800' ?> rounded">🧑‍🎓 Students</a>
    </nav>
  </aside>

  <!-- Main Content -->
  <main class="flex-1 p-8">
    <?php if ($page === 'dashboard'): ?>
      <h2 class="text-3xl font-bold mb-4">Welcome, Admin</h2>
      <p class="text-gray-600">Use the sidebar to manage the system.</p>

    <?php elseif ($page === 'school_years'): ?>
      <h2 class="text-2xl font-bold mb-4">📘 Manage School Years</h2>
      <!-- You can include admin_school_years.php here later -->
      <?php include 'admin_views/school_years.php'; ?>

    <?php elseif ($page === 'sections'): ?>
      <h2 class="text-2xl font-bold mb-4">🏫 Manage Sections</h2>
      <!-- Placeholder or include -->
      <?php include 'admin_views/sections.php'; ?>

    <?php elseif ($page === 'subjects'): ?>
      <h2 class="text-2xl font-bold mb-4">📚 Manage Subjects</h2>
      <!-- Placeholder or include -->
      <?php include 'admin_views/subjects.php'; ?>

    <?php elseif ($page === 'students'): ?>
      <h2 class="text-2xl font-bold mb-4">🧑‍🎓 Manage Students</h2>
      <!-- Placeholder or include -->
      <?php include 'admin_views/students.php'; ?>

    <?php else: ?>
      <p class="text-red-600">Page not found.</p>
    <?php endif; ?>
  </main>

</body>
</html>
