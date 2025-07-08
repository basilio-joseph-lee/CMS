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

    .animated-card {
      animation: popIn 0.6s ease-out forwards;
    }

    .bounce-photo {
      animation: bounceIn 1s ease-in-out;
    }

    .wobble-note {
      animation: wobble 0.5s ease-in-out;
    }

    .cutout-btn {
      font-weight: bold;
      padding: 12px 24px;
      border-radius: 12px;
      font-size: 16px;
      box-shadow: 3px 3px 0 #00000030;
      transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .cutout-btn:hover {
      transform: scale(1.05) translateY(-2px);
      box-shadow: 5px 5px 0 #00000020;
    }

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
    
    <!-- Title -->
    <h1 class="text-3xl mb-6 text-white drop-shadow-lg">📸 Face Recognition Login</h1>

    <!-- Webcam Frame -->
    <div class="bg-white rounded-xl p-2 border-4 border-gray-200 inline-block mb-4 bounce-photo shadow-lg">
      <video id="video" width="320" height="240" autoplay class="rounded-md"></video>
    </div>

    <canvas id="canvas" width="320" height="240" style="display:none;"></canvas>

    <!-- Buttons -->
    <div class="mt-6">
      <button onclick="captureAndLogin()" class="cutout-btn bg-green-400 text-white hover:bg-green-500">✅ Login with Face</button>
      <button id="registerBtn" onclick="registerFace()" style="display:none;" class="cutout-btn bg-red-400 text-white hover:bg-red-500 ml-4">➕ Register Face</button>
    </div>

    <!-- Status Note -->
    <p id="status" class="status-note"></p>
  </div>

  <script>
    navigator.mediaDevices.getUserMedia({ video: true }).then(stream => {
      document.getElementById('video').srcObject = stream;
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
      status.innerText = "⏳ Verifying face...";
      status.classList.add('wobble-note');

      fetch('../config/login.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ image: imageData })
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          const confidenceText = data.confidence ? ` (${data.confidence}% match)` : '';
          status.innerText = `✅ Welcome, ${data.name}!${confidenceText}`;
          document.getElementById('registerBtn').style.display = 'none';
          setTimeout(() => window.location.href = 'dashboard.php', 1000);
        } else {
          status.innerText = "❌ Face not recognized.";
          document.getElementById('registerBtn').style.display = 'inline-block';
        }
      })
      .catch(err => {
        status.innerText = "🚫 Server error.";
        console.error(err);
      });
    }

    function registerFace() {
      const imageData = captureImage();
      const name = prompt("Enter your name to register:");
      if (!name) return;

      fetch('../config/register_face.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name, image: imageData })
      })
      .then(response => response.json())
      .then(data => {
        alert(data.success ? "✅ Registered!" : "❌ Failed: " + data.error);
        if (data.success) document.getElementById('registerBtn').style.display = 'none';
      })
      .catch(err => {
        alert("🚫 Registration error.");
        console.error(err);
      });
    }
  </script>

</body>
</html>
