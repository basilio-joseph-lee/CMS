<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Student Dashboard</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body {
      background-image: url('../img/role.png'); /* Replace with your corkboard image path */
      background-size: cover;
      background-position: center;
    }
  </style>
</head>
<body class="min-h-screen flex items-center justify-center p-6">
  <div class="bg-[#fef8e4] p-10 rounded-3xl shadow-lg w-full max-w-4xl text-center">
    <h1 class="text-4xl font-bold text-gray-800 mb-10">Student Dashboard</h1>

    <div class="grid grid-cols-2 gap-6">
      <!-- Attendance -->
      <a href="#" class="bg-green-500 hover:bg-green-600 text-white rounded-xl py-8 px-4 transition-all shadow-md">
        <div class="text-5xl mb-4">✅</div>
        <div class="text-xl font-semibold">Attendance</div>
      </a>

      <!-- Restroom Request -->
      <a href="#" class="bg-yellow-400 hover:bg-yellow-500 text-black rounded-xl py-8 px-4 transition-all shadow-md">
        <div class="text-5xl mb-4">🙋‍♂️</div>
        <div class="text-xl font-semibold">Restroom Request</div>
      </a>

      <!-- Snack Request -->
      <a href="#" class="bg-blue-500 hover:bg-blue-600 text-white rounded-xl py-8 px-4 transition-all shadow-md">
        <div class="text-5xl mb-4">🍩</div>
        <div class="text-xl font-semibold">Snack Request</div>
      </a>

      <!-- Daily Notes -->
      <a href="#" class="bg-purple-500 hover:bg-purple-600 text-white rounded-xl py-8 px-4 transition-all shadow-md">
        <div class="text-5xl mb-4">📝</div>
        <div class="text-xl font-semibold">Daily Notes</div>
      </a>
    </div>
  </div>
</body>
</html>
