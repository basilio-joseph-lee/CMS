<?php
// /CMS/user/teacher/qr_view.php
session_start();
include '../../config/teacher_guard.php';
include "../../config/db.php";
if (!isset($_SESSION['teacher_id'])) { header("Location: ../teacher_login.php"); exit; }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conn->set_charset("utf8mb4");

$session_id = (int)($_GET['session_id'] ?? 0);
$res = $conn->query("SELECT session_id, session_code, title FROM kiosk_quiz_sessions WHERE session_id={$session_id} LIMIT 1");
if (!$res || !$res->num_rows) { http_response_code(404); echo "Session not found"; exit; }

$row   = $res->fetch_assoc();
$code  = $row['session_code'] ?: (string)$row['session_id']; // fallback to numeric id
$title = $row['title'] ?: 'Quick Quiz';

/* ---- Build join link (use LAN IP if accessed via localhost) ---- */
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
if ($host === 'localhost' || $host === '127.0.0.1') {
    $host = '10.108.126.30'; // your LAN IP for mobile testing
}
/* If your Apache docroot serves CMS at the root (no /CMS in the URL),
   change the line below to: $base = $scheme.'://'.$host.'/user/'; */
$base     = $scheme . '://' . $host . '/CMS/user/';
$joinLink = $base . 'join_quiz.php?code=' . urlencode($code);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Quiz QR</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script src="https://cdn.tailwindcss.com"></script>
  <!-- qrcodejs (very reliable) -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
  <style> body{background:#f3f4f6} </style>
</head>
<body class="min-h-screen flex items-center justify-center p-6">
  <div class="bg-white rounded-2xl shadow p-8 w-full max-w-md text-center">
    <h1 class="text-2xl font-bold mb-6">Quiz Ready: <?= htmlspecialchars($title) ?></h1>

    <!-- QR target -->
    <div id="qrBox" class="mx-auto mb-4" style="width:220px;height:220px;"></div>
    <!-- Image fallback (hidden by default) -->
    <img id="qrImg" class="mx-auto mb-4 hidden" width="220" height="220" alt="QR Code (fallback)">

    <p class="mt-2 text-sm text-gray-600">
      Session Code:
      <span class="font-semibold text-blue-600"><?= htmlspecialchars($code) ?></span>
    </p>

    <p class="mt-4 text-gray-700">Students can scan or open this link:</p>
    <p class="mt-1 break-words">
      <a class="text-blue-700 underline" href="<?= $joinLink ?>" target="_blank"><?= $joinLink ?></a>
    </p>

    <div class="mt-5 flex gap-2 justify-center">
      <button id="btnCopy" class="px-4 py-2 rounded-lg bg-blue-600 text-white hover:bg-blue-700">
        Copy Link
      </button>
      <a href="quiz_dashboard.php" class="px-4 py-2 rounded-lg border hover:bg-gray-50">Back</a>
    </div>
  </div>

  <script>
    (function () {
      const url     = <?= json_encode($joinLink) ?>;
      const qrBox   = document.getElementById('qrBox');
      const qrImg   = document.getElementById('qrImg');
      const copyBtn = document.getElementById('btnCopy');

      try {
        new QRCode(qrBox, { text: url, width: 220, height: 220, correctLevel: QRCode.CorrectLevel.M });
      } catch (e) {
        // Fallback to server PNG if JS drawing fails
        qrImg.src = "/CMS/config/generate_qr.php?code=" + encodeURIComponent(<?= json_encode($code) ?>);
        qrImg.classList.remove('hidden');
        qrBox.classList.add('hidden');
      }

      copyBtn?.addEventListener('click', async (ev) => {
        try {
          await navigator.clipboard.writeText(url);
          const old = ev.target.textContent;
          ev.target.textContent = 'Copied!';
          setTimeout(()=> ev.target.textContent = old, 1200);
        } catch { alert('Copy failed'); }
      });
    })();
  </script>
</body>
</html>
