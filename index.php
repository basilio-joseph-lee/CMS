<?php session_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Login Portal</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body {
      background-image: url('img/role.png');
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
    <h1 class="text-3xl font-bold text-gray-800 mb-6">Welcome to CMS</h1>

    <!-- Login Form -->
    <form method="POST" action="config/process_login.php" class="bg-white rounded-2xl p-6 shadow-md space-y-4">
      <h2 class="text-xl font-semibold text-gray-700 mb-2">👩‍🏫 Teacher Login</h2>
      
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

      <!-- Error Messages -->
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

      <!-- Login Button -->
      <button type="submit" class="bg-yellow-500 hover:bg-yellow-600 text-white font-bold py-3 rounded-xl w-full transition">
        Login as Teacher
      </button>
    </form>

    <!-- OR Divider -->
    <div class="my-4 text-gray-600 font-semibold">— or —</div>

    <!-- Student Face Login -->
    <a href="user/face_login.php" class="inline-block bg-green-500 hover:bg-green-600 text-white font-bold py-3 px-6 rounded-xl transition shadow-md">
      🎓 Face Login (Student)
    </a>
  </div>

</body>
</html>
