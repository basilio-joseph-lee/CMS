<?php
session_start();

// Re-store subject data to ensure it's available even after future redirects
if (isset($_SESSION['subject_id'], $_SESSION['subject_name'], $_SESSION['class_name'], $_SESSION['year_label'])) {
    $_SESSION['active_subject_id'] = $_SESSION['subject_id'];
    $_SESSION['active_subject_name'] = $_SESSION['subject_name'];
    $_SESSION['active_class_name'] = $_SESSION['class_name'];
    $_SESSION['active_year_label'] = $_SESSION['year_label'];
} else {
    die("❗ Subject data missing. Please select subject again.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Teacher Login</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body {
      background-image: url('../img/role.png'); /* Corkboard/classroom background */
      background-size: cover;
      background-position: center;
      font-family: 'Comic Sans MS', cursive, sans-serif;
    }
    .input-style {
      @apply bg-blue-100 w-full p-3 pl-10 rounded-xl text-gray-800 shadow-inner focus:outline-none;
    }
    .icon {
      @apply absolute left-3 top-1/2 transform -translate-y-1/2 text-blue-600;
    }
  </style>
</head>
<body class="min-h-screen flex items-center justify-center px-4">



  <div class="bg-orange-100 rounded-[30px] shadow-2xl p-8 w-full max-w-md text-center">
    <h1 class="text-3xl font-bold text-gray-800 mb-6">Teacher Login</h1>

    <!-- Login Form -->
    <form method="POST" action="../config/process_login.php" class="bg-white rounded-2xl p-6 shadow-md space-y-4">
      
      <!-- Username -->
      <div class="relative">
        <span class="icon">👤</span>
        <input type="text" name="username" placeholder="Username" required class="input-style" autofocus>
      </div>

      <!-- Password -->
      <div class="relative">
        <span class="icon">🔒</span>
        <input type="password" name="password" placeholder="Password" required class="input-style">
      </div>

      <!-- Error message (optional) -->
    <?php if (!empty($_SESSION['failed'])): ?>
      <div class="bg-red-100 text-red-800 px-4 py-2 rounded mb-4">
        <?= $_SESSION['failed']; unset($_SESSION['failed']); ?>
      </div>
    <?php endif; ?>

      <?php if (!empty($_SESSION['error'])): ?>
      <div class="bg-red-100 text-red-800 px-4 py-2 rounded mb-4">
        <?= $_SESSION['error']; unset($_SESSION['error']); ?>
      </div>
    <?php endif; ?>




      <!-- Hidden subject info -->
      <input type="hidden" name="subject_id" value="<?= $_SESSION['active_subject_id'] ?>">
      <input type="hidden" name="subject_name" value="<?= htmlspecialchars($_SESSION['active_subject_name']) ?>">
      <input type="hidden" name="class_name" value="<?= htmlspecialchars($_SESSION['active_class_name']) ?>">
      <input type="hidden" name="year_label" value="<?= htmlspecialchars($_SESSION['active_year_label']) ?>">


      <!-- Login Button -->
      <button type="submit" class="bg-yellow-500 hover:bg-yellow-600 text-white font-bold py-3 rounded-xl w-full transition">
        Login
      </button>
    </form>

    <!-- Back Button -->
    <div class="mt-6 text-left">
      <a href="../config/logout.php" class="inline-flex items-center bg-white px-4 py-2 rounded-full shadow text-gray-700 hover:bg-gray-100 transition">
        ⬅️ Back
      </a>
    </div>
  </div>

</body>
</html>
