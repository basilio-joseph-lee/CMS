<!DOCTYPE html>
<html>
<head>
    <title>Face Recognition Login</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            text-align: center;
            margin-top: 30px;
        }
        video, canvas {
            border: 2px solid #333;
            margin: 10px;
        }
        button {
            padding: 10px 20px;
            font-size: 16px;
            background-color: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        #registerBtn {
            background-color: #f44336;
        }
        #status {
            margin-top: 20px;
            font-weight: bold;
            font-size: 18px;
        }
    </style>
</head>
<body>

    <h1>👤 Face Recognition Login</h1>

    <video id="video" width="320" height="240" autoplay></video>
    <canvas id="canvas" width="320" height="240" style="display:none;"></canvas>
    <br>
    <button onclick="captureAndLogin()">Login with Face</button>
    <button id="registerBtn" onclick="registerFace()" style="display:none;">Register Face</button>

    <p id="status"></p>

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
            document.getElementById('status').innerText = "⏳ Verifying face...";

            fetch('../config/login.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ image: imageData })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const confidenceText = data.confidence ? ` (${data.confidence}% match)` : '';
                    document.getElementById('status').innerText = `✅ Welcome, ${data.name}!${confidenceText}`;
                    document.getElementById('registerBtn').style.display = 'none';
                    setTimeout(() => window.location.href = 'dashboard.php', 1000);
                } else {
                    document.getElementById('status').innerText = "❌ Face not recognized.";
                    document.getElementById('registerBtn').style.display = 'inline-block';
                }
            })
            .catch(err => {
                document.getElementById('status').innerText = "🚫 Server error.";
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
