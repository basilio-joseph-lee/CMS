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
  <title>Face Recognition Login</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body {
      background: url('../img/1.png') no-repeat center center fixed;
      background-size: cover;
      font-family: 'Comic Sans MS', cursive, sans-serif;
    }
    @keyframes popIn {
      0% { transform: scale(0.8) rotate(-2deg); opacity: 0; }
      100% { transform: scale(1) rotate(0); opacity: 1; }
    }
    @keyframes bounceIn {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(-10px); }
    }
    @keyframes wobble {
      0%, 100% { transform: rotate(0deg); }
      25% { transform: rotate(2deg); }
      50% { transform: rotate(-2deg); }
      75% { transform: rotate(1deg); }
    }
    .animated-card { animation: popIn 0.6s ease-out forwards; }
    .bounce-photo { animation: bounceIn 1s ease-in-out; }
    .wobble-note { animation: wobble 0.5s ease-in-out; }
    .status-note {
      background: #fff8a9;
      padding: 12px 16px;
      border: 2px solid #ccc65b;
      border-radius: 8px;
      margin-top: 20px;
      font-weight: bold;
      transform: rotate(-2deg);
      box-shadow: 4px 4px 0 #d6d173;
      display: inline-block;
    }
  </style>
</head>
<body class="flex items-center justify-center min-h-screen px-4 py-10">

  <div class="p-8 max-w-xl w-full text-center bg-[#2f4733] rounded-[30px] shadow-[8px_10px_0_rgba(0,0,0,0.25)] ring-4 ring-white animated-card">
    <h1 class="text-3xl mb-6 text-white drop-shadow-lg">📸 Face Recognition Login</h1>

    <div class="bg-white rounded-xl p-2 border-4 border-gray-200 inline-block mb-4 bounce-photo shadow-lg">
      <video id="video" width="320" height="240" autoplay class="rounded-md"></video>
    </div>
    <canvas id="canvas" width="320" height="240" style="display:none;"></canvas>

    <p id="status" class="status-note"></p>
  </div>

  <script>
    let autoLoginInterval;

    navigator.mediaDevices.getUserMedia({ video: true }).then(stream => {
      document.getElementById('video').srcObject = stream;

      // Start scanning every 2 seconds
      autoLoginInterval = setInterval(captureAndLogin, 2000);
    });

    function captureImage() {
      const video = document.getElementById('video');
      const canvas = document.getElementById('canvas');
      const ctx = canvas.getContext('2d');
      ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
      return canvas.toDataURL('image/jpeg');
    }

    function captureAndLogin() {
      const imageData = captureImage();
      const status = document.getElementById('status');
      status.innerText = "⏳ Scanning face...";
      status.classList.add('wobble-note');

      fetch('../config/login.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          image: imageData,
          subject_id: <?= json_encode($_SESSION['subject_id']) ?>,
          advisory_id: <?= json_encode($_SESSION['advisory_id']) ?>,
          school_year_id: <?= json_encode($_SESSION['school_year_id']) ?>
        })
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          clearInterval(autoLoginInterval);
          status.innerText = `✅ Welcome, ${data.name}!`;
          setTimeout(() => window.location.href = 'dashboard.php', 1000);
        } else {
          status.innerText = "❌ Face not recognized.";
        }
      })
      .catch(err => {
        status.innerText = "🚫 Server error.";
        console.error(err);
      });
    }
  </script>
</body>
</html>
