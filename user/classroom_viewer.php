<?php
include __DIR__ . "/../config/db.php";

session_start();

// viewing doesn't require teacher login; still use session values if present
$teacherName = $_SESSION['teacher_fullname'] ?? 'Teacher';
$subject_id     = intval($_SESSION['subject_id'] ?? 0);
$advisory_id    = intval($_SESSION['advisory_id'] ?? 0);
$school_year_id = intval($_SESSION['school_year_id'] ?? 0);
$subject_name   = $_SESSION['subject_name'] ?? 'Subject';
$class_name     = $_SESSION['class_name'] ?? 'Section';
$year_label     = $_SESSION['year_label'] ?? 'SY';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>2D Classroom Simulator — View</title>
  <script src="https://cdn.tailwindcss.com"></script>

  <style>
    /* (⚠️ Style kept 100% as your original) */
    body { background:#fefae0; font-family:'Comic Sans MS', cursive, sans-serif; }
    .ctl h3 { font-weight:800; letter-spacing:.3px; }
    .btn { padding:.5rem .75rem; border-radius:.5rem; font-weight:700; }
    .card.empty .avatar-wrapper { display:none; }
    .name.is-empty { display:none; }
    .seat.has-student .name { display:block; }
    .seat.is-away .name { opacity:.6; visibility:visible; color:#111; }
    .avatar-wrapper { z-index:1; }
    .seat .name { position:relative; z-index:2; display:block; }
    #stage{ position:relative; min-height:540px; height:72vh; background:url('../../img/bg-8.png') center center / cover no-repeat; border-radius:12px; overflow:hidden; box-shadow:inset 0 0 20px rgba(0,0,0,0.15);
      --desk-grad-1:#e6cfa7; --desk-grad-2:#d2a86a; --desk-border:#a16a2a; --leg:#8b5e34; --chair-seat:#d1d5db; --chair-back:#9ca3af; --chair-border:#6b7280;
      --back-w:70px; --back-h:28px; --back-r:4px; --seat-w:70px; --seat-h:18px; --seat-r:4px; --seat-mt:-6px; }
    #seatLayer{ position:relative; width:100%; height:100%; }
    .seat{ width:100px; position:absolute; user-select:none; transition:opacity .2s ease; }
    .seat .card{ background:transparent; border:none; box-shadow:none; text-align:center; }
    .desk-rect{ width:90px; height:40px; background:linear-gradient(180deg,var(--desk-grad-1) 0%,var(--desk-grad-2) 100%); border:2px solid var(--desk-border); border-radius:6px 6px 2px 2px; margin:0 auto; }
    .desk-rect::before,.desk-rect::after{ content:""; position:absolute; width:6px; height:28px; background:var(--leg); bottom:-28px; }
    .desk-rect::before{ left:10px; } .desk-rect::after{ right:10px; }
    .chair-back{ width:var(--back-w); height:var(--back-h); background:var(--chair-back); border:2px solid var(--chair-border); border-radius:var(--back-r); margin:0 auto; position:relative; }
    .chair-seat{ width:var(--seat-w); height:var(--seat-h); background:var(--chair-seat); border:2px solid var(--chair-border); border-radius:var(--seat-r); margin:var(--seat-mt) auto 0; position:relative; }
    .avatar-wrapper{ position:absolute; top:-20px; left:50%; transform:translateX(-50%); width:60px; height:60px; z-index:2; }
    .avatar-img{ width:100%; height:100%; object-fit:contain; display:block; animation: head-tilt var(--headDur,2.8s) ease-in-out infinite; transform-origin:50% 76%; }
    @keyframes head-tilt{0%,100%{transform:rotateZ(0deg);}33%{transform:rotateZ(6deg);}66%{transform:rotateZ(-6deg);} }
    .seat .name{ margin-top:-18px; font-size:12px; text-align:center; font-weight:700; color:#1f2937; text-shadow:0 1px 0 rgba(255,255,255,.9),0 0 2px rgba(0,0,0,.08); }
    .status-bubble{ position:absolute; top:6px; left:calc(100% + 8px); background:#fff; border:2px solid #111; border-radius:9999px; padding:8px 12px; font-size:12px; font-weight:700; white-space:nowrap; box-shadow:0 2px 4px rgba(0,0,0,.2); }
    .seat.is-away .avatar-img{ visibility:hidden; }
    .seat.is-away .name{ opacity:.6; visibility:visible; color:black; }
  </style>
</head>
<body>
  <div class="grid grid-cols-12 gap-4 p-4">
    <!-- Sidebar -->
    <aside class="ctl col-span-12 md:col-span-3 lg:col-span-2 bg-[#386641] text-white rounded-2xl p-4 space-y-5">
      <div>
        <h3 class="text-xl">SMARTCLASS — View</h3>
        <div class="text-xs opacity-80 mt-1"><?= htmlspecialchars($class_name) ?> • <?= htmlspecialchars($subject_name) ?> • <?= htmlspecialchars($year_label) ?></div>
      </div>
      <div>
        <h3 class="text-sm uppercase opacity-90">Mode</h3>
        <div class="text-sm opacity-90">Viewing mode — no editing allowed</div>
      </div>
      <div>
        <h3 class="text-sm uppercase opacity-90">Info</h3>
        <div class="text-xs opacity-90">Viewing as: <b><?= htmlspecialchars($teacherName) ?></b><br/>This view refreshes automatically to show current student statuses.</div>
      </div>
    </aside>

    <!-- Main -->
    <main class="col-span-12 md:col-span-9 lg:col-span-10">
      <div class="bg-white rounded-2xl shadow p-5 mb-4">
        <div class="flex items-center justify-between">
          <h1 class="text-2xl md:text-3xl font-bold text-[#bc6c25]">🏫 2D Classroom Simulator — View</h1>
          <button id="backBtn" class="btn bg-gray-100 hover:bg-gray-200 text-gray-800">← Back</button>
        </div>
        <div class="text-sm text-gray-700 mt-1" id="stats">Loading…</div>
      </div>

      <div id="stage" class="p-2">
        <div id="seatLayer"></div>
      </div>
    </main>
  </div>

  <div id="toast" class="fixed top-5 right-5 hidden px-4 py-3 rounded shadow text-white"></div>

<script>
/* 🌍 Flexible API base — local or deployed */
const API = <?php
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
         || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
  $scheme = $https ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $path   = $_SERVER['PHP_SELF'] ?? '/';
  $base   = preg_replace('~(?i)(/CMS)?/user/.*$~', '$1', $path);
  $base   = rtrim($base, '/');
  echo json_encode("$scheme://$host$base/api");
?>;

/* Constants */
const SY = <?= $school_year_id ?>, AD = <?= $advisory_id ?>, SJ = <?= $subject_id ?>;
const stage = document.getElementById('stage');
const seatLayer = document.getElementById('seatLayer');
const stats = document.getElementById('stats');
const toastBox = document.getElementById('toast');

/* 🎨 Theme sync from teacher’s simulator */
const COLOR_KEY = `sim:chairColor:${SY}:${AD}:${SJ}`;
function applyColorTheme(id){
  const themes = JSON.parse(localStorage.getItem('themes') || '[]');
  const t = themes.find(x=>x.id===id);
  if (!t) return;
  stage.style.setProperty('--desk-grad-1',t.d1);
  stage.style.setProperty('--desk-grad-2',t.d2);
  stage.style.setProperty('--desk-border',t.db);
  stage.style.setProperty('--leg',t.leg);
  stage.style.setProperty('--chair-seat',t.seat);
  stage.style.setProperty('--chair-back',t.back);
  stage.style.setProperty('--chair-border',t.cb);
}
const storedColor = localStorage.getItem(COLOR_KEY);
if (storedColor) applyColorTheme(storedColor);

/* 🔁 Fetch and render */
async function loadData(){
  try{
    const [sRes, cRes] = await Promise.all([
      fetch(`${API}/get_students.php`, {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        credentials:'include',
        body: JSON.stringify({ subject_id:SJ, advisory_id:AD, school_year_id:SY })
      }),
      fetch(`${API}/get_seating.php`, {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        credentials:'include',
        body: JSON.stringify({ subject_id:SJ, advisory_id:AD, school_year_id:SY })
      })
    ]);
    const students = await sRes.json();
    const chairs = await cRes.json();
    renderSeats(chairs, students);
  }catch(e){
    stats.textContent = '⚠️ Failed to load seating or students.';
  }
}

/* 🪑 Draw seating */
function renderSeats(list, students){
  seatLayer.innerHTML='';
  const seated = new Set();
  list.forEach(ch=>{
    const s = students.find(x=>x.student_id==ch.student_id);
    const el = document.createElement('div');
    el.className='seat';
    el.style.left = ch.x+'px';
    el.style.top  = ch.y+'px';
    el.dataset.empty = s ? 0 : 1;
    el.innerHTML = `
      <div class="card ${s?'has-student':''}">
        ${s?`
        <div class="avatar-wrapper">
          <img src="${s.avatar_url||'../avatar/default-student.png'}" class="avatar-img"/>
        </div>`:''}
        <div class="desk-rect"></div>
        <div class="chair-back"></div>
        <div class="chair-seat"></div>
      </div>
      <div class="name ${s?'':'is-empty'}">${s?s.firstname:''}</div>
    `;
    seatLayer.appendChild(el);
    if(s) seated.add(s.student_id);
  });
  stats.textContent=`Students: ${students.length} • Seated: ${seated.size} • Chairs: ${list.length}`;
}

/* 🔁 Auto refresh */
loadData();
setInterval(loadData, 4000);

/* Back button */
document.getElementById('backBtn').onclick=()=>history.back();
</script>
</body>
</html>
