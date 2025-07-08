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
        <input type="text" name="username" placeholder="Username" required class="input-style">
      </div>

      <!-- Password -->
      <div class="relative">
        <span class="icon">🔒</span>
        <input type="password" name="password" placeholder="Password" required class="input-style">
      </div>

      <!-- Error message (optional) -->
      <?php if (isset($_GET['error'])): ?>
        <p class="text-red-600 text-sm font-semibold">Invalid credentials</p>
      <?php endif; ?>

      <!-- Login Button -->
      <button type="submit" class="bg-yellow-500 hover:bg-yellow-600 text-white font-bold py-3 rounded-xl w-full transition">
        Login
      </button>
    </form>

    <!-- Back Button -->
    <div class="mt-6 text-left">
      <a href="account_choice.php" class="inline-flex items-center bg-white px-4 py-2 rounded-full shadow text-gray-700 hover:bg-gray-100 transition">
        ⬅️ Back
      </a>
    </div>
  </div>

</body>
</html>
