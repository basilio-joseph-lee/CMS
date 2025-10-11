<?php
session_start();
include '../../config/teacher_guard.php';
include '../../config/db.php';

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
    body{background-image:url('../../img/1.png');background-size:cover;background-position:center;font-family:'Comic Sans MS',cursive,sans-serif}
    .pin::before{content:"📌";font-size:1.75rem;position:absolute;top:-14px;left:50%;transform:translateX(-50%);z-index:10}
    .card{background:#fdf7e2;border-radius:18px;padding:20px 10px;box-shadow:4px 6px 0 rgba(0,0,0,.15);text-align:center;transition:.25s;height:160px;display:flex;flex-direction:column;justify-content:center;border:2px solid rgba(0,0,0,.06)}
    .card:hover{transform:scale(1.05);box-shadow:6px 8px 0 rgba(0,0,0,.2)}
    .card-icon{font-size:2.2rem;margin-bottom:.5rem}
    @media (prefers-reduced-motion:reduce){.card,.card:hover{transition:none!important;transform:none!important}}
    @media (min-width:480px){.card{height:170px;padding:22px 12px}.card-icon{font-size:2.4rem}}
    @media (min-width:640px){.card{height:180px;padding:24px 14px}.card-icon{font-size:2.6rem}}
    @media (min-width:768px){.card{height:184px}.card-icon{font-size:2.7rem}}
    @media (min-width:1024px){.card{height:188px}.card-icon{font-size:2.8rem}}
    @media (max-width:360px){.card{height:150px;padding:16px 8px}.card-icon{font-size:2rem}}
    .focus-outline{outline:3px solid rgba(34,197,94,.5);outline-offset:3px}
    /* bell dropdown */
    .menu-shadow{box-shadow:0 10px 30px rgba(0,0,0,.20)}
    .scroll-smooth::-webkit-scrollbar{width:8px}
    .scroll-smooth::-webkit-scrollbar-thumb{background:#e5e7eb;border-radius:999px}
  </style>
</head>
<body class="min-h-screen flex flex-col items-center px-3 sm:px-4 py-6 sm:py-8">

  <!-- Header -->
  <div class="w-full max-w-7xl bg-green-700/95 text-white flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 sm:gap-6 rounded-3xl shadow-lg mb-8 sm:mb-10 px-5 sm:px-6 py-4 relative">
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

    <!-- Right controls: Bell + Logout -->
    <div class="flex items-center gap-3 sm:gap-4">
      <!-- Notification bell -->
      <div class="relative">
        <button id="btnBell"
          class="relative inline-flex items-center justify-center w-11 h-11 rounded-full bg-white text-green-700 hover:bg-amber-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/60"
          aria-haspopup="true" aria-expanded="false" title="Out Time requests">
          <!-- bell -->
          <span style="font-size:1.35rem">🔔</span>
          <!-- badge -->
          <span id="bellBadge"
            class="absolute -top-1 -right-1 min-w-[20px] h-[20px] px-1 rounded-full bg-red-600 text-white text-xs font-bold flex items-center justify-center hidden">0</span>
        </button>

        <!-- Dropdown menu -->
        <div id="bellMenu"
             class="hidden absolute right-0 mt-2 w-[22rem] sm:w-[26rem] bg-white text-gray-800 rounded-2xl p-3 menu-shadow z-30">
          <div class="flex items-center justify-between px-1">
            <div class="font-extrabold text-lg">Pending Out Time Requests</div>
            <button id="btnRefresh"
              class="text-xs px-2 py-1 rounded-lg bg-green-600 text-white hover:bg-green-700">Refresh</button>
          </div>
          <div id="menuEmpty" class="text-sm text-gray-500 px-1 py-3">No pending requests.</div>
          <div id="menuList" class="max-h-80 overflow-auto scroll-smooth space-y-2"></div>
        </div>
      </div>

      <!-- Logout -->
      <a href="../../config/logout.php?role=teacher"
         class="inline-flex items-center justify-center rounded-xl bg-orange-500 px-4 py-2 text-sm sm:text-base font-semibold shadow-md hover:bg-orange-600 focus:outline-none focus:ring-2 focus:ring-orange-300 transition">
         Logout
      </a>
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

  <!-- Toast -->
  <div id="toast" class="fixed top-5 right-5 px-4 py-3 rounded-xl shadow text-white font-bold hidden"></div>

<script>
  /* ===== Toast ===== */
  const toastBox = document.getElementById('toast');
  function toast(msg, type='ok'){
    toastBox.textContent = msg;
    toastBox.style.background = (type==='err') ? '#ef4444' : '#16a34a';
    toastBox.classList.remove('hidden');
    setTimeout(()=>toastBox.classList.add('hidden'), 1600);
  }

  /* ===== Elements ===== */
  const btnBell    = document.getElementById('btnBell');
  const bellBadge  = document.getElementById('bellBadge');
  const bellMenu   = document.getElementById('bellMenu');
  const btnRefresh = document.getElementById('btnRefresh');
  const menuList   = document.getElementById('menuList');
  const menuEmpty  = document.getElementById('menuEmpty');

  /* ===== Helpers ===== */
  function toggleMenu(force){
    const show = (typeof force==='boolean') ? force : bellMenu.classList.contains('hidden');
    bellMenu.classList.toggle('hidden', !show);
    btnBell.setAttribute('aria-expanded', show ? 'true' : 'false');
  }
  function closeOnOutside(e){
    if(!bellMenu.classList.contains('hidden')){
      if(!bellMenu.contains(e.target) && !btnBell.contains(e.target)) toggleMenu(false);
    }
  }
  document.addEventListener('click', closeOnOutside);
  btnBell.addEventListener('click', ()=> toggleMenu());

  function renderRequests(items){
    const count = Array.isArray(items) ? items.length : 0;
    // badge
    if(count>0){ bellBadge.textContent = String(count); bellBadge.classList.remove('hidden'); }
    else{ bellBadge.classList.add('hidden'); }

    // dropdown list
    if(count===0){
      menuEmpty.classList.remove('hidden');
      menuList.innerHTML = '';
      return;
    }
    menuEmpty.classList.add('hidden');
    menuList.innerHTML = items.map(it => `
      <div class="border rounded-xl p-3 bg-white/90">
        <div class="font-bold">${it.student_name || 'Student'}</div>
        <div class="text-xs text-gray-500 mt-0.5">Requested: ${
          it.requested_at ? new Date(it.requested_at.replace(' ','T')).toLocaleString() : '—'
        }</div>
        <div class="mt-3 flex gap-2">
          <button class="px-3 py-1.5 rounded-lg bg-green-600 text-white hover:bg-green-700"
                  data-approve="${it.id}">Approve</button>
          <button class="px-3 py-1.5 rounded-lg bg-gray-300 hover:bg-gray-400"
                  data-deny="${it.id}">Deny</button>
        </div>
      </div>
    `).join('');

    // bind buttons
    menuList.querySelectorAll('[data-approve]').forEach(b=>{
      b.onclick = ()=> decideReq(b.dataset.approve, 'approve');
    });
    menuList.querySelectorAll('[data-deny]').forEach(b=>{
      b.onclick = ()=> decideReq(b.dataset.deny, 'deny');
    });
  }

  async function loadRequests(){
    try{
      const r = await fetch('/api/out_time_requests_list.php', {credentials:'include', cache:'no-store'});
      const j = await r.json();
      renderRequests(j.ok ? j.items : []);
    }catch(e){
      console.error(e);
      renderRequests([]);
    }
  }

  async function decideReq(id, action){
    try{
      const r = await fetch('/api/out_time_request_decide.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        credentials:'include',
        body: JSON.stringify({id, action}) // 'approve' | 'deny'
      });
      const j = await r.json();
      if(j.ok){
        toast(action==='approve' ? 'Approved' : 'Denied');
        await loadRequests();
      }else{
        toast(j.message || 'Failed', 'err');
      }
    }catch(e){
      toast('Network error', 'err');
    }
  }

  // wire and poll
  btnRefresh.addEventListener('click', loadRequests);
  loadRequests();
  setInterval(loadRequests, 5000);
</script>
</body>
</html>
