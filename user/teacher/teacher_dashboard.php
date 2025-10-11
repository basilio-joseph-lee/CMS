<?php
session_start();
include '../../config/teacher_guard.php';
include "../../config/db.php";

$teacherName = $_SESSION['teacher_fullname'] ?? 'Teacher';
$subjectName = $_SESSION['subject_name'] ?? '';
$className   = $_SESSION['class_name'] ?? '';
$yearLabel   = $_SESSION['year_label'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Teacher Dashboard</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body {
      background-image: url('../../img/1.png');
      background-size: cover;
      background-position: center;
      font-family: 'Comic Sans MS', cursive, sans-serif;
    }
    .pin::before {
      content: "📌";
      font-size: 1.75rem;
      position: absolute;
      top: -14px;
      left: 50%;
      transform: translateX(-50%);
      z-index: 10;
    }
    .card {
      background-color: #fdf7e2;
      border-radius: 18px;
      padding: 20px 10px;
      box-shadow: 4px 6px 0 rgba(0, 0, 0, 0.15);
      text-align: center;
      transition: all 0.25s ease;
      height: 160px;
      display: flex;
      flex-direction: column;
      justify-content: center;
      border: 2px solid rgba(0, 0, 0, 0.06);
    }
    .card:hover {
      transform: scale(1.05);
      box-shadow: 6px 8px 0 rgba(0, 0, 0, 0.2);
    }
    .card-icon { font-size: 2.2rem; margin-bottom: 0.5rem; }

    @media (prefers-reduced-motion: reduce) {
      .card, .card:hover { transition: none !important; transform: none !important; }
    }
    @media (min-width: 480px) { .card { height: 170px; padding: 22px 12px; } .card-icon { font-size: 2.4rem; } }
    @media (min-width: 640px) { .card { height: 180px; padding: 24px 14px; } .card-icon { font-size: 2.6rem; } }
    @media (min-width: 768px) { .card { height: 184px; } .card-icon { font-size: 2.7rem; } }
    @media (min-width: 1024px){ .card { height: 188px; } .card-icon { font-size: 2.8rem; } }
    @media (max-width: 360px) { .card { height: 150px; padding: 16px 8px; } .card-icon { font-size: 2rem; } }
    .focus-outline { outline: 3px solid rgba(34,197,94,.5); outline-offset: 3px; }
  </style>
</head>
<body class="min-h-screen flex flex-col items-center px-3 sm:px-4 py-6 sm:py-8">

  <!-- Header -->
  <div class="w-full max-w-7xl bg-green-700/95 text-white flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 sm:gap-6 rounded-3xl shadow-lg mb-8 sm:mb-10 px-5 sm:px-6 py-4">
    <div class="space-y-1">
      <h1 class="text-xl sm:text-2xl md:text-3xl font-extrabold leading-tight">
        Welcome, <?= htmlspecialchars($teacherName); ?>
      </h1>
      <div class="text-xs sm:text-sm md:text-base">
        Subject: <span class="font-semibold"><?= htmlspecialchars($subjectName) ?></span>
        <span class="opacity-80">|</span>
        Section: <span class="font-semibold"><?= htmlspecialchars($className) ?></span>
        <span class="opacity-80">|</span>
        SY: <span class="font-semibold"><?= htmlspecialchars($yearLabel) ?></span>
      </div>
    </div>
    <div class="flex sm:justify-end">
      <a href="../../config/logout.php?role=teacher"
         class="inline-flex items-center justify-center rounded-xl bg-orange-500 px-4 py-2 text-sm sm:text-base font-semibold shadow-md hover:bg-orange-600 focus:outline-none focus:ring-2 focus:ring-orange-300 transition">Logout</a>
    </div>
  </div>

  <!-- Card Grid -->
  <div class="w-full max-w-7xl grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-4 gap-4 sm:gap-6 md:gap-8">
    <a href="add_student.php" class="relative pin block rounded-2xl focus-visible:focus-outline">
      <div class="card bg-blue-100">
        <div class="card-icon">🎥</div>
        <div class="font-bold text-sm sm:text-base">Add Student</div>
      </div>
    </a>

    <a href="view_students.php" class="relative pin block rounded-2xl focus-visible:focus-outline">
      <div class="card">
        <div class="card-icon">👦</div>
        <div class="font-bold text-sm sm:text-base">View Students</div>
      </div>
    </a>

    <a href="view_attendance_history.php" class="relative pin block rounded-2xl focus-visible:focus-outline">
      <div class="card bg-blue-100">
        <div class="card-icon">📅</div>
        <div class="font-bold text-xs sm:text-sm md:text-base">View Attendance History</div>
      </div>
    </a>

    <a href="grading_sheet.php" class="relative pin block rounded-2xl focus-visible:focus-outline">
      <div class="card">
        <div class="card-icon">📖</div>
        <div class="font-bold text-sm sm:text-base">Grades</div>
      </div>
    </a>

    <a href="mark_attendance.php" class="relative pin block rounded-2xl focus-visible:focus-outline">
      <div class="card bg-blue-100">
        <div class="card-icon">🗓️</div>
        <div class="font-bold text-sm sm:text-base">Mark Attendance</div>
      </div>
    </a>

    <a href="announcement.php" class="relative pin block rounded-2xl focus-visible:focus-outline">
      <div class="card">
        <div class="card-icon">📢</div>
        <div class="font-bold text-sm sm:text-base">Post Announcement</div>
      </div>
    </a>

    <a href="classroom_simulator.php" class="relative pin block rounded-2xl focus-visible:focus-outline">
      <div class="card bg-pink-100">
        <div class="card-icon">🪑</div>
        <div class="font-bold text-sm sm:text-base">Seating Plan</div>
      </div>
    </a>

    <a href="quiz_dashboard.php" class="relative pin block rounded-2xl focus-visible:focus-outline">
      <div class="card bg-green-100">
        <div class="card-icon">❓</div>
        <div class="font-bold text-sm sm:text-base">Quiz Game</div>
      </div>
    </a>
  </div>

  <!-- Pending Out-Time Requests panel -->
  <div id="pendingBox" class="w-full max-w-7xl mt-8 hidden">
    <div class="bg-white/95 rounded-3xl shadow-lg border p-4 sm:p-5">
      <div class="flex items-center justify-between mb-3">
        <h2 class="text-lg sm:text-xl font-extrabold">⏳ Pending Out Time Requests</h2>
        <button id="btnRefreshReq"
          class="text-sm px-3 py-1.5 rounded-lg bg-green-600 text-white hover:bg-green-700">
          Refresh
        </button>
      </div>

      <div id="pendingList" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"></div>
      <div id="emptyState" class="text-sm text-gray-600">No pending requests.</div>
    </div>
  </div>

  <!-- Modal kept from your file (if you still use it) -->
  <div id="accessRequestModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex justify-center items-center z-50 p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md relative p-5 sm:p-6">
      <button onclick="document.getElementById('accessRequestModal').classList.add('hidden')"
        class="absolute top-2 right-3 text-gray-500 hover:text-red-500 text-2xl leading-none" aria-label="Close">×</button>
      <h2 class="text-lg sm:text-xl font-bold mb-4">Request Section Access</h2>

      <form method="POST" action="../config/submit_access_request.php" class="space-y-4">
        <label class="block text-sm font-semibold">Select Section:</label>
        <select name="advisory_id" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-green-300">
          <?php
          $conn2 = @new mysqli("localhost", "root", "", "cms");
          if (!$conn2->connect_error) {
            $res = $conn2->query("SELECT advisory_id, class_name FROM advisory_classes");
            if ($res) { while ($row = $res->fetch_assoc()) {
              echo "<option value='{$row['advisory_id']}'>".htmlspecialchars($row['class_name'])."</option>";
            } }
          }
          ?>
        </select>

        <label class="block text-sm font-semibold">Reason:</label>
        <textarea name="reason" rows="3" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-green-300"></textarea>

        <input type="hidden" name="school_year_id" value="<?= $_SESSION['school_year_id'] ?? '' ?>">
        <button type="submit" class="w-full bg-green-600 text-white px-4 py-2.5 rounded-xl font-semibold shadow hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-300 transition">
          Submit Request
        </button>
      </form>
    </div>
  </div>

  <!-- Toast -->
  <div id="toast" class="fixed top-5 right-5 px-4 py-3 rounded-xl shadow text-white font-bold hidden"></div>

  <script>
<script>
  // Toast helper
  const toastBox = document.createElement('div');
  toastBox.id = 'toast';
  toastBox.className = 'fixed top-5 right-5 px-4 py-3 rounded-xl shadow text-white font-bold hidden';
  document.body.appendChild(toastBox);
  function toast(msg, type='ok'){
    toastBox.textContent = msg;
    toastBox.style.background = (type==='err') ? '#ef4444' : '#16a34a';
    toastBox.classList.remove('hidden');
    setTimeout(()=>toastBox.classList.add('hidden'), 1800);
  }

  const pendingBox   = document.createElement('div');
  pendingBox.id = 'pendingBox';
  pendingBox.className = 'w-full max-w-7xl mt-8 hidden';
  pendingBox.innerHTML = `
    <div class="bg-white/95 rounded-3xl shadow-lg border p-4 sm:p-5">
      <div class="flex items-center justify-between mb-3">
        <h2 class="text-lg sm:text-xl font-extrabold">⏳ Pending Out Time Requests</h2>
        <button id="btnRefreshReq" class="text-sm px-3 py-1.5 rounded-lg bg-green-600 text-white hover:bg-green-700">Refresh</button>
      </div>
      <div id="pendingList" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"></div>
      <div id="emptyState" class="text-sm text-gray-600">No pending requests.</div>
    </div>
  `;
  document.body.appendChild(pendingBox);

  const pendingList  = document.getElementById('pendingList');
  const emptyState   = document.getElementById('emptyState');
  const btnRefreshReq= document.getElementById('btnRefreshReq');

  function renderPending(items){
    if (!Array.isArray(items) || items.length === 0){
      pendingBox.classList.add('hidden');
      emptyState.classList.remove('hidden');
      pendingList.innerHTML = '';
      return;
    }
    pendingBox.classList.remove('hidden');
    emptyState.classList.add('hidden');
    pendingList.innerHTML = items.map(it => `
      <div class="border rounded-2xl p-3 bg-white/80 shadow-sm">
        <div class="font-bold text-base">${it.student_name}</div>
        <div class="text-xs text-gray-500 mt-0.5">
          Requested: ${new Date(it.requested_at.replace(' ','T')).toLocaleString()}
        </div>
        <div class="mt-3 flex gap-2">
          <button class="px-3 py-1.5 rounded-lg bg-green-600 text-white hover:bg-green-700"
                  data-approve="${it.id}">Approve</button>
          <button class="px-3 py-1.5 rounded-lg bg-gray-300 hover:bg-gray-400"
                  data-deny="${it.id}">Deny</button>
        </div>
      </div>
    `).join('');

    pendingList.querySelectorAll('[data-approve]').forEach(b=>{
      b.onclick = ()=> decideReq(b.dataset.approve, 'approve', b);
    });
    pendingList.querySelectorAll('[data-deny]').forEach(b=>{
      b.onclick = ()=> decideReq(b.dataset.deny, 'deny', b);
    });
  }

  async function fetchPending(){
    try{
      const res = await fetch('../../api/out_time_requests_list.php', {
        credentials:'include', cache:'no-store'
      });
      const j = await res.json();
      if (!j.ok) { toast(j.message || 'Failed to load','err'); return; }
      renderPending(j.items || []);
    }catch(e){ toast('Network error','err'); }
  }

  async function decideReq(id, decision, btnEl){
    try{
      if (btnEl) btnEl.disabled = true;
      const res = await fetch('../../api/out_time_request_decide.php', {
        method:'POST',
        headers:{ 'Content-Type':'application/json' },
        credentials:'include',
        body: JSON.stringify({ id: Number(id), decision })
      });
      const j = await res.json();
      if (!j.ok){ toast(j.message || 'Action failed','err'); return; }
      toast(decision==='approve' ? 'Out Time approved' : 'Request denied');
      fetchPending();
    }catch(e){
      toast('Network error','err');
    }finally{
      if (btnEl) btnEl.disabled = false;
    }
  }

  btnRefreshReq.addEventListener('click', fetchPending);
  fetchPending();
  setInterval(fetchPending, 5000);
</script>

  </script>
</body>
</html>
