<?php
include "../../config/db.php";
session_start();

if (!isset($_SESSION['teacher_id'])) {
  header("Location: teacher_login.php");
  exit;
}

$teacherName = $_SESSION['teacher_fullname'] ?? 'Teacher';
$subject_id     = intval($_SESSION['subject_id']);
$advisory_id    = intval($_SESSION['advisory_id']);
$school_year_id = intval($_SESSION['school_year_id']);
$subject_name   = $_SESSION['subject_name'] ?? 'Subject';
$class_name     = $_SESSION['class_name'] ?? 'Section';
$year_label     = $_SESSION['year_label'] ?? 'SY';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>2D Classroom Simulator</title>
  <script src="https://cdn.tailwindcss.com"></script>

  <style>
    body { background:#fefae0; font-family:'Comic Sans MS', cursive, sans-serif; }
    .ctl h3 { font-weight:800; letter-spacing:.3px; }
    .btn { padding:.5rem .75rem; border-radius:.5rem; font-weight:700; }
    .card.empty .avatar-wrapper { display:none; }
    /* hide only when truly empty */
  .name.is-empty { display: none; }

  /* show the name whenever the seat has a student */
  .seat.has-student .name { display: block; }

  /* dim but keep visible when away */
  .seat.is-away .name {
    opacity: 0.6;
    visibility: visible; /* <-- remove the stray comma */
    color: #111;
  }
  /* 1) Avatar under the name */
  .avatar-wrapper { z-index: 1; }

  /* 2) Name always on top (present or away) */
  .seat .name {
    position: relative;   /* create stacking context */
    z-index: 2;           /* above avatar-wrapper (1) */
    display: block;
  }

  /* 3) Hide only if no student talaga */
  .name.is-empty { display: none; }

  /* 4) Optional: dim when away, but keep visible */
  .seat.is-away .name { opacity: 0.6; }

    /* --- Stage (board) --- */
    #stage{
      position: relative;
      min-height: 540px;
      height: 72vh;
      background: url('../../img/bg-8.png') center center / cover no-repeat;
      border-radius: 12px;
      overflow: hidden;
      box-shadow: inset 0 0 20px rgba(0,0,0,0.15);

      /* theme variables (changed by JS) */
      --desk-grad-1:#e6cfa7;
      --desk-grad-2:#d2a86a;
      --desk-border:#a16a2a;
      --leg:#8b5e34;
      --chair-seat:#d1d5db;
      --chair-back:#9ca3af;
      --chair-border:#6b7280;

      /* chair shape variables (changed by JS) */
      --back-w:70px; --back-h:28px; --back-r:4px;
      --seat-w:70px; --seat-h:18px; --seat-r:4px; --seat-mt:-6px;
    }
    #seatLayer{ position:relative; width:100%; height:100%; }
    .seat{ width:100px; position:absolute; user-select:none; transition:opacity .2s ease; }
    .seat .card{ position:relative; background:transparent; border:none; box-shadow:none; text-align:center; }

    /* DESK */
    .desk-rect{ width:90px; height:40px; background:linear-gradient(180deg,var(--desk-grad-1) 0%,var(--desk-grad-2) 100%); border:2px solid var(--desk-border); border-radius:6px 6px 2px 2px; margin:0 auto; position:relative; z-index:1; }
    .desk-rect::before,.desk-rect::after{ content:""; position:absolute; width:6px; height:28px; background:var(--leg); bottom:-28px; }
    .desk-rect::before{ left:10px; } .desk-rect::after{ right:10px; }

    /* BASE CHAIR (dimensioned by variables) */
    .chair-back{ width:var(--back-w); height:var(--back-h); background:var(--chair-back); border:2px solid var(--chair-border); border-radius:var(--back-r); margin:0 auto; position:relative; }
    .chair-seat{ width:var(--seat-w); height:var(--seat-h); background:var(--chair-seat); border:2px solid var(--chair-border); border-radius:var(--seat-r); margin:var(--seat-mt) auto 0; position:relative; z-index:0; }

    /* SHAPE EXTRAS (composable) */
    #stage.no-back .chair-back{ display:none; }
    #stage.extra-tablet .chair-seat::after{ content:""; position:absolute; right:-18px; top:-10px; width:28px; height:16px; background:var(--desk-grad-1); border:2px solid var(--desk-border); border-radius:5px; }
    #stage.extra-post .chair-seat::after{ content:""; position:absolute; left:50%; transform:translateX(-50%); bottom:-22px; width:6px; height:24px; background:var(--chair-border); border-radius:3px; }
    #stage.extra-tripod .chair-seat::before, #stage.extra-tripod .chair-seat::after{ content:""; position:absolute; bottom:-16px; width:6px; height:16px; background:var(--chair-border); border-radius:3px; }
    #stage.extra-tripod .chair-seat::before{ left:26%; transform:rotate(6deg); }
    #stage.extra-tripod .chair-seat::after{ right:26%; transform:rotate(-6deg); }
    #stage.extra-wings .chair-back::before, #stage.extra-wings .chair-back::after{ content:""; position:absolute; top:4px; width:10px; height:18px; background:var(--chair-back); border:2px solid var(--chair-border); border-radius:8px; }
    #stage.extra-wings .chair-back::before{ left:-12px; } #stage.extra-wings .chair-back::after{ right:-12px; }
    #stage.extra-stripes .chair-back{ background: repeating-linear-gradient(90deg, rgba(0,0,0,.10) 0 6px, rgba(255,255,255,.12) 6px 12px), var(--chair-back); }
    #stage.extra-notch .chair-seat::after{ content:""; position:absolute; left:50%; transform:translateX(-50%); top:-6px; width:14px; height:8px; background:var(--chair-seat); border:2px solid var(--chair-border); border-bottom:none; border-radius:10px 10px 0 0; }
    #stage.extra-splitback .chair-back{ background:transparent; border-color:transparent; height:0; }
    #stage.extra-splitback .chair-back::before, #stage.extra-splitback .chair-back::after{ content:""; position:absolute; top:-22px; width:30px; height:20px; background:var(--chair-back); border:2px solid var(--chair-border); border-radius:4px; }
    #stage.extra-splitback .chair-back::before{ left:0; } #stage.extra-splitback .chair-back::after { right:0; }

    /* AVATAR: head tilt */
    .avatar-wrapper{ position:absolute; top:-20px; left:50%; transform:translateX(-50%); width:60px; height:60px; z-index:2; transform-style: preserve-3d; }
    .avatar-bias{ width:100%; height:100%; transform:none; }
    .avatar-img{ width:100%; height:100%; object-fit:contain; display:block; animation: head-tilt var(--headDur, 2.8s) ease-in-out infinite; transform-origin: 50% 76%; backface-visibility: hidden; -webkit-backface-visibility: hidden; will-change: transform; transform: translateZ(0); }
    @keyframes head-tilt{ 0%,100% { transform: rotateZ(0deg); } 33% { transform: rotateZ(6deg); } 66% { transform: rotateZ(-6deg); } }
    body.respect-reduced-motion .avatar-img{ animation: none !important; }

    .seat .name{ margin-top:-18px; font-size:12px; text-align:center; font-weight:700; color:#1f2937; text-shadow: 0 1px 0 rgba(255,255,255,.9), 0 0 2px rgba(0,0,0,.08); pointer-events:none; }
    .draggable{ cursor:grab; } .dragging{ opacity:.9; cursor:grabbing; }

    /* Right-side status bubble */
    .status-bubble{ position:absolute; top:6px; left:calc(100% + 8px); background:#fff; border:2px solid #111; border-radius:9999px; padding:8px 12px; font-size:12px; font-weight:700; white-space:nowrap; box-shadow:0 2px 4px rgba(0,0,0,.2); opacity:1; pointer-events:none; }
    .status-bubble::before,.status-bubble::after{ content:""; position:absolute; background:#fff; border:2px solid #111; border-radius:50%; }
    .status-bubble::before{ width:10px; height:10px; left:-14px; top:22px; }
    .status-bubble::after { width:6px; height:6px; left:-22px; top:28px; }

    /* When a student is away, hide the avatar & bubble entirely */
/* Hide only the avatar image, but keep the bubble visible */
.seat.is-away .avatar-img { visibility: hidden; }
.seat.is-away .name { opacity: 0.6; visibility: visible; color: black }

/* (optional) keep, remove if you don’t want any dimming */
.seat.is-away .desk-rect,
.seat.is-away .chair-back,
.seat.is-away .chair-seat{
  opacity:.9;     /* tweak or remove */
  filter:none;    /* keep normal furniture color; feels like an empty seat */
}

    /* Modal */
    .modal{ position:fixed; inset:0; background:rgba(0,0,0,.45); display:none; align-items:center; justify-content:center; z-index:100; }
    .modal.open{ display:flex; }
    .modal-card{ background:#fff; width:min(1100px,96vw); border-radius:16px; padding:18px; box-shadow:0 20px 60px rgba(0,0,0,.35); }

    .tpl{ border:2px solid #e5e7eb; border-radius:12px; padding:12px; transition:.15s; cursor:pointer; background:#fafafa; }
    .tpl:hover{ border-color:#94a3b8; background:#fff; }
    .tpl img{ width:100%; height:auto; display:block; }

    .tab-btn{ padding:.45rem .75rem; border-radius:.6rem; border:1px solid #e5e7eb; background:#f8fafc; font-weight:700; }
    .tab-btn.active{ background:#fde68a; border-color:#f59e0b; }

    .style-btn{ display:flex; align-items:center; gap:.6rem; padding:.45rem .6rem; border-radius:.6rem; background:#f9fafb; border:1px solid #e5e7eb; font-weight:600; }
    .style-btn.active{ outline:2px solid #facc15; background:#fff; color:#111827; }

    .swatch{ width:22px; height:22px; border-radius:.35rem; border:1px solid #0f172a22; }
    .grid-auto{ display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:.6rem; }
  </style>
</head>
<body>
  <div class="grid grid-cols-12 gap-4 p-4">
    <!-- Sidebar -->
    <aside class="ctl col-span-12 md:col-span-3 lg:col-span-2 bg-[#386641] text-white rounded-2xl p-4 space-y-5">
      <div>
        <h3 class="text-xl">SMARTCLASS</h3>
        <div class="text-xs opacity-80 mt-1">
          <?= htmlspecialchars($class_name) ?> • <?= htmlspecialchars($subject_name) ?> • <?= htmlspecialchars($year_label) ?>
        </div>
      </div>

      <div class="space-y-2">
        <h3 class="text-sm uppercase opacity-90">MODES</h3>
        <div class="flex flex-wrap gap-2">
          <button data-mode="quiz" class="btn bg-yellow-500 text-white">Quiz</button>
          <button data-mode="discussion" class="btn bg-blue-500 text-white">Discussion</button>
        </div>
      </div>

      <div class="space-y-2">
        <h3 class="text-sm uppercase opacity-90">ACTIONS</h3>
        <div class="flex flex-wrap gap-2">
          <button data-action="break" class="btn bg-green-600 text-white">Break Time</button>
          <button data-action="out" class="btn bg-gray-700 text-white">Out Time</button>
          <button id="openTpl" class="btn bg-white/10 text-white w-full mt-4">Customize / Layout</button>
        </div>
        <div class="text-xs opacity-90">Modes are visual only; Actions also write to behavior logs.</div>
      </div>

      <div class="space-y-2">
        <h3 class="text-sm uppercase opacity-90">CHAIRS</h3>
        <div class="flex items-center gap-2">
          <button id="minusSeat" class="px-3 py-1 rounded bg-white/10">−</button>
          <span id="seatCount" class="min-w-[2ch] text-center font-semibold">0</span>
          <button id="plusSeat" class="px-3 py-1 rounded bg-white/10">＋</button>
        </div>
      </div>

      <div class="text-xs opacity-90">
        Welcome, <b><?= htmlspecialchars($teacherName) ?></b><br/>
        Tip: Drag chairs anywhere you like.
      </div>
    </aside>

    <!-- Main -->
    <main class="col-span-12 md:col-span-9 lg:col-span-10">
      <div class="bg-white rounded-2xl shadow p-5 mb-4">
        <div class="flex items-center justify-between">
          <h1 class="text-2xl md:text-3xl font-bold text-[#bc6c25]">🏫 2D Classroom Simulator</h1>
          <button id="backBtn" class="btn bg-gray-100 hover:bg-gray-200 text-gray-800">← Back</button>
        </div>
        <div class="text-sm text-gray-700 mt-1" id="stats">Loading…</div>
      </div>

      <div id="stage" class="p-2">
        <div id="seatLayer"></div>
      </div>
    </main>
  </div>

  <!-- Customizer modal (Layouts • Colors • Styles) -->
  <div id="tplModal" class="modal">
    <div class="modal-card">
      <div class="flex items-center justify-between mb-4">
        <div class="text-lg font-bold">Classroom Customizer</div>
        <button id="closeTpl" class="px-3 py-2 rounded bg-gray-100">Close</button>
      </div>

      <div class="flex flex-wrap gap-2 mb-4">
        <button class="tab-btn active" data-tab="layouts">Layouts</button>
        <button class="tab-btn" data-tab="colors">Chair Colors</button>
        <button class="tab-btn" data-tab="styles">Chair Styles</button>
      </div>

      <!-- LAYOUTS TAB -->
      <div class="tab-body" data-tab-panel="layouts">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          <!-- Pairs -->
          <div class="tpl" data-layout="pairs">
            <img src="data:image/svg+xml;utf8,<?php echo rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="360" height="220"><rect width="100%" height="100%" fill="#f8fafc"/><rect x="24" y="18" width="110" height="32" rx="6" fill="#60a5fa"/><text x="79" y="38" text-anchor="middle" font-size="16" fill="#fff" font-weight="700">Teacher</text><g fill="#06b6d4"><rect x="60" y="80" width="24" height="18" rx="3"/><rect x="90" y="80" width="24" height="18" rx="3"/><rect x="60" y="112" width="24" height="18" rx="3"/><rect x="90" y="112" width="24" height="18" rx="3"/><rect x="200" y="80" width="24" height="18" rx="3"/><rect x="230" y="80" width="24" height="18" rx="3"/><rect x="200" y="112" width="24" height="18" rx="3"/><rect x="230" y="112" width="24" height="18" rx="3"/></g></svg>'); ?>" alt="Pairs" />
            <div class="mt-2 text-sm font-semibold">Pairs</div>
          </div>

          <!-- Grid -->
          <div class="tpl" data-layout="grid">
            <img src="data:image/svg+xml;utf8,<?php $rows=[80,112,144,176]; $gridRects=''; foreach($rows as $y){ foreach([60,100,140,180,220] as $x){ $gridRects .="<rect x='$x' y='$y' width='24' height='18' rx='3'/>"; } } echo rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="360" height="220"><rect width="100%" height="100%" fill="#f8fafc"/><rect x="24" y="18" width="110" height="32" rx="6" fill="#60a5fa"/><text x="79" y="38" text-anchor="middle" font-size="16" fill="#fff" font-weight="700">Teacher</text><g fill="#06b6d4">'.$gridRects.'</g></svg>'); ?>" alt="Grid" />
            <div class="mt-2 text-sm font-semibold">Grid</div>
          </div>

          <!-- Rows -->
          <div class="tpl" data-layout="rows">
            <img src="data:image/svg+xml;utf8,<?php $rows=[80,112,144,176]; $rowRects=''; foreach($rows as $y){ foreach([60,100,140,180,220,260,300,340] as $x){ $rowRects .="<rect x='$x' y='$y' width='24' height='18' rx='3'/>"; } } echo rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="360" height="220"><rect width="100%" height="100%" fill="#f8fafc"/><rect x="24" y="18" width="110" height="32" rx="6" fill="#60a5fa"/><text x="79" y="38" text-anchor="middle" font-size="16" fill="#fff" font-weight="700">Teacher</text><g fill="#06b6d4">'.$rowRects.'</g></svg>'); ?>" alt="Rows" />
            <div class="mt-2 text-sm font-semibold">Rows</div>
          </div>

          <!-- Groups of Four -->
          <div class="tpl" data-layout="g4">
            <img src="data:image/svg+xml;utf8,<?php $blocks=''; $centers=[[100,100],[200,100],[300,100],[100,160],[200,160],[300,160]]; foreach($centers as [$cx,$cy]){ foreach([[ -22,-18],[0,-18],[-22,4],[0,4]] as $d){ $blocks.="<rect x='".($cx+$d[0])."' y='".($cy+$d[1])."' width='20' height='16' rx='3'/>"; } } echo rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="360" height="220"><rect width="100%" height="100%" fill="#f8fafc"/><rect x="24" y="18" width="110" height="32" rx="6" fill="#60a5fa"/><text x="79" y="38" text-anchor="middle" font-size="16" fill="#fff" font-weight="700">Teacher</text><g fill="#06b6d4">'.$blocks.'</g></svg>'); ?>" alt="Groups of four" />
            <div class="mt-2 text-sm font-semibold">Groups of Four</div>
          </div>

          <!-- U-Shape -->
          <div class="tpl" data-layout="ushape">
            <img src="data:image/svg+xml;utf8,<?php $bottom=''; foreach([60,100,140,180,220,260,300] as $x){ $bottom.="<rect x='$x' y='168' width='24' height='18' rx='3'/>"; } $sides = "<rect x='48' y='116' width='24' height='18' rx='3'/><rect x='320' y='116' width='24' height='18' rx='3'/>". "<rect x='48' y='88' width='24' height='18' rx='3'/><rect x='320' y='88' width='24' height='18' rx='3'/>". "<rect x='48' y='60' width='24' height='18' rx='3'/><rect x='320' y='60' width='24' height='18' rx='3'/>"; echo rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="360" height="220"><rect width="100%" height="100%" fill="#f8fafc"/><rect x="24" y="18" width="110" height="32" rx="6" fill="#60a5fa"/><text x="79" y="38" text-anchor="middle" font-size="16" fill="#fff" font-weight="700">Teacher</text><g fill="#06b6d4">'.$bottom.$sides.'</g></svg>'); ?>" alt="U Shape" />
            <div class="mt-2 text-sm font-semibold">U-Shape</div>
          </div>
        </div>
      </div>

      <!-- COLORS TAB -->
      <div class="tab-body hidden" data-tab-panel="colors">
        <div id="colorList" class="grid-auto"></div>
      </div>

      <!-- STYLES TAB -->
      <div class="tab-body hidden" data-tab-panel="styles">
        <div id="shapeList" class="grid-auto"></div>
      </div>
    </div>
  </div>

  <!-- Toast -->
  <div id="toast" class="fixed top-5 right-5 hidden px-4 py-3 rounded shadow text-white"></div>

  <!-- Context menu -->
  <div id="menu" class="hidden fixed z-50 bg-white border rounded-xl shadow-lg p-2 w-56">
    <div class="text-xs text-gray-500 px-2 py-1">Quick Actions</div>
    <div id="menuHeader" class="px-2 pb-1 text-sm font-semibold text-gray-800"></div>
    <button data-action-student="Present" class="block w-full text-left px-3 py-2 hover:bg-gray-100">Mark Present</button>
    <button data-action-student="Late" class="block w-full text-left px-3 py-2 hover:bg-gray-100">Mark Late</button>
    <button data-action-student="Absent" class="block w-full text-left px-3 py-2 hover:bg-gray-100">Mark Absent</button>
    <hr class="my-2"/>
    <button data-log="restroom" class="block w-full text-left px-3 py-2 hover:bg-gray-100">🚻 Restroom</button>
    <button data-log="snack" class="block w-full text-left px-3 py-2 hover:bg-gray-100">🍎 Snack</button>
    <button data-log="help_request" class="block w-full text-left px-3 py-2 hover:bg-gray-100">✋ Raise Hand</button>
    <button data-log="participated" class="block w-full text-left px-3 py-2 hover:bg-gray-100">✅ Participated</button>
    <button data-log="im_back" class="block w-full text-left px-3 py-2 hover:bg-gray-100">🟢 I’m Back</button>
  </div>

  <script>

        // Build an absolute API base that works in subfolders and on production
// Build an absolute API base that works in subfolders and on production
const API = <?php
  // --- Flexible, works local and deployed ---
  $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
         || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
  $scheme = $https ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

  $path = $_SERVER['PHP_SELF'] ?? '/';
  // Normalize case-insensitive variants of “/user/...” path
  $base = preg_replace('~(?i)/user/.*$~', '', $path);   // strip anything after /user/
  $base = rtrim($base, '/');                            // remove trailing slash

  // Compose final API URL (e.g. http://localhost/CMS/api or https://mysite/api)
  $apiBase = $base === '' ? "$scheme://$host/api" : "$scheme://$host$base/api";
  echo json_encode($apiBase);
?>;



    // Always send cookies and mark as XHR (some hosts/mod_security like this)
    const FETCH_OPTS = {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    };

    // ---------- Small helpers ----------
    function modeText(){ return mode==='quiz' ? '✏️' : (mode==='discussion' ? '📖' : ''); }
    function actionOverlayText(){ return globalAction==='break' ? '🍱' : (globalAction==='out' ? '🚪' : ''); }

    function actionText(act){
      const icon = {
        restroom:'🚻', snack:'🍎', lunch_break:'🍱', water_break:'💧', not_well:'🤒',
        borrow_book:'📚', return_material:'📦', participated:'✅', help_request:'✋',
        attendance:'🟢', im_back:'🟢', out_time:'🚪'
      };
      return icon[act] || '•';
    }


    const SY = <?= $school_year_id ?>, AD = <?= $advisory_id ?>, SJ = <?= $subject_id ?>;
    const stage     = document.getElementById('stage');
    const seatLayer = document.getElementById('seatLayer');
    const seatCountEl= document.getElementById('seatCount');
    const menu      = document.getElementById('menu');
    const menuHeader= document.getElementById('menuHeader');
    const toastBox  = document.getElementById('toast');
    const backBtn   = document.getElementById('backBtn');

    const COLOR_KEY = `sim:chairColor:${SY}:${AD}:${SJ}`;
    const SHAPE_KEY = `sim:chairShape:${SY}:${AD}:${SJ}`;
    const BACK_KEY  = `sim:backSet:${SY}:${AD}:${SJ}`;

    let mode = null;            // visual only
    let globalAction = null;    // 'break'|'out' overlay + logs
    let students = [];          // roster
    let seats = [];             // seats with coords
    let backSet = new Set(JSON.parse(localStorage.getItem(BACK_KEY) || '[]'));
    let behaviorMap = {};

    const AWAY_ACTIONS = new Set(['restroom','snack','lunch_break','out_time','water_break','not_well','borrow_book','return_material','log_out']);
    const ACTION_LABELS = {
      restroom:'Restroom', snack:'Snack', lunch_break:'Lunch Break', water_break:'Water Break',
      not_well:'Not Feeling Well', borrow_book:'Borrowing Book', return_material:'Returning Material',
      participated:'Participated', help_request:'Needs Help', attendance:'Attendance', im_back:'I’m Back', out_time:'Out Time'
    };


    function toast(msg, type='success'){
      toastBox.textContent = msg;
      toastBox.className = 'fixed top-5 right-5 px-4 py-3 rounded shadow text-white ' + (type==='error'?'bg-red-500':'bg-green-600');
      toastBox.style.display = 'block';
      setTimeout(()=>toastBox.style.display='none', 2200);
    }
    const clamp=(v,min,max)=>Math.max(min,Math.min(max,v));
    const saveBackSet = ()=> localStorage.setItem(BACK_KEY, JSON.stringify([...backSet]));
    const markStudentBack = id => { backSet.add(String(id)); saveBackSet(); renderSeats(); };
    const markStudentAway = id => { backSet.delete(String(id)); saveBackSet(); renderSeats(); };
    const clearBackSet = ()=>{ backSet.clear(); saveBackSet(); };

    // Modes (visual only)
    document.querySelectorAll('[data-mode]').forEach(btn=>{
      btn.onclick = ()=>{ const m=btn.dataset.mode; mode=(mode===m)?null:m; renderSeats(); refreshBehavior(); };
    });

    // Actions (overlay + bulk logs)
    document.querySelectorAll('[data-action]').forEach(btn=>{
      btn.onclick = async ()=>{
        const kind = btn.dataset.action; // 'break'|'out'
        if (globalAction === kind){ globalAction=null; clearBackSet(); renderSeats(); refreshBehavior(); return; }

        const student_ids = students.map(s=>s.student_id);
        const action_type = (kind==='break') ? 'lunch_break' : 'out_time';
        try{
          btn.disabled = true;
const resp = await fetch(`${API}/log_behavior_bulk.php`,{
  method:'POST',
  headers:{ 'Content-Type':'application/json', ...FETCH_OPTS.headers },
  credentials: FETCH_OPTS.credentials,
  body: JSON.stringify({action_type, student_ids})
});

          const raw = await resp.text();
          let r; try{ r=JSON.parse(raw);}catch{ throw new Error('Bulk API returned non-JSON: '+raw.slice(0,120)); }
          if (!r?.ok) throw new Error(r?.message || 'Bulk log failed');
          toast(`${kind==='break'?'Break Time':'Out Time'} logged for ${r.inserted ?? student_ids.length} students`);
          globalAction = kind; clearBackSet(); renderSeats(); refreshBehavior();
        }catch(e){ toast(String(e.message||e),'error'); }
        finally{ btn.disabled=false; }
      };
    });

    // ---------- Placement helpers ----------
    function stageMetrics(){
      const pad=14, seatW=100, seatH=96, gapX=22, gapY=24;
      const rect=stage.getBoundingClientRect();
      const usableW=Math.max(0,rect.width-pad*2), usableH=Math.max(0,rect.height-pad*2);
      const maxCols=Math.max(1,Math.floor((usableW+gapX)/(seatW+gapX)));
      const maxRows=Math.max(1,Math.floor((usableH+gapY)/(seatH+gapY)));
      return {pad,seatW,seatH,gapX,gapY,rectW:rect.width,rectH:rect.height,usableW,usableH,maxCols,maxRows};
    }

    function placeGridCentered(cols, rows, totalChairs, topOffset=0){
      const M=stageMetrics();
      cols=Math.min(Math.max(cols,1),M.maxCols);
      rows=Math.min(Math.max(rows,1),M.maxRows);
      const totalW=cols*M.seatW+(cols-1)*M.gapX, totalH=rows*M.seatH+(rows-1)*M.gapY;
      const startX=Math.max(M.pad,Math.round((M.rectW-totalW)/2));
      const startY=Math.max(M.pad,Math.round((M.rectH-totalH)/2)-20+topOffset);
      const pos=[];
      for(let r=0;r<rows;r++){
        for(let c=0;c<cols;c++){
          pos.push({x:startX+c*(M.seatW+M.gapX), y:startY+r*(M.seatH+M.gapY)});
          if(pos.length===totalChairs) return pos;
        }
      }
      return pos;
    }

    const placeRows=(n=30)=>placeGridCentered(6,5,n);
    const placePairs=(n=12)=>placeGridCentered(6,2,n);
    const placeGrid25=(n=25)=>placeGridCentered(5,5,n);

    function placeGroupsOfFour(n=24){
      const M=stageMetrics(), groupsX=3, groupsY=2, groupSeatW=2*M.seatW+M.gapX, groupSeatH=2*M.seatH+M.gapY, interGapX=M.gapX*2, interGapY=M.gapY*2;
      const totalW=groupsX*groupSeatW+(groupsX-1)*interGapX, totalH=groupsY*groupSeatH+(groupsY-1)*interGapY;
      const startX=Math.max(M.pad,Math.round((M.rectW-totalW)/2)), startY=Math.max(M.pad,Math.round((M.rectH-totalH)/2)-10);
      const pos=[];
      for(let gy=0;gy<groupsY;gy++){
        for(let gx=0;gx<groupsX;gx++){
          const baseX=startX+gx*(groupSeatW+interGapX), baseY=startY+gy*(groupSeatH+interGapY);
          const cells=[
            {x:baseX,y:baseY},
            {x:baseX+(M.seatW+M.gapX),y:baseY},
            {x:baseX,y:baseY+(M.seatH+M.gapY)},
            {x:baseX+(M.seatW+M.gapX),y:baseY+(M.seatH+M.gapY)}
          ];
          for(const c of cells){ pos.push(c); if(pos.length===n) return pos; }
        }
      }
      return pos;
    }

    function placeUShape(bottomWanted=10, sideRowsWanted=3, n=16){
      const M=stageMetrics(), bottom=Math.max(3,Math.min(bottomWanted,M.maxCols));
      const totalBottomW=bottom*M.seatW+(bottom-1)*M.gapX;
      const startX=Math.max(M.pad,Math.round((M.rectW-totalBottomW)/2));
      const bottomY=Math.max(M.pad,M.rectH-M.pad-M.seatH);
      const pos=[];
      for(let c=0;c<bottom && pos.length<n;c++){
        pos.push({x:startX+c*(M.seatW+M.gapX), y:bottomY});
      }
      const sideRows=Math.max(1,Math.min(sideRowsWanted,M.maxRows-1));
      const leftX=startX, rightX=startX+(bottom-1)*(M.seatW+M.gapX);
      const topMostY=Math.max(M.pad,bottomY-sideRows*(M.seatH+M.gapY));
      for(let r=0;r<sideRows && pos.length<n;r++){
        const y=bottomY-(r+1)*(M.seatH+M.gapY);
        pos.push({x:leftX,y:Math.max(topMostY,y)});
        if(pos.length<n) pos.push({x:rightX,y:Math.max(topMostY,y)});
      }
      return pos;
    }

    // ---------- Data loading & roster sync ----------
    async function loadData(){
const [S,P] = await Promise.all([
  fetch(`${API}/get_students.php`, { ...FETCH_OPTS }).then(r=>r.json()),
  fetch(`${API}/get_seating.php`,  { ...FETCH_OPTS }).then(r=>r.json())
]);

      students = S.students || [];
      seats = normalizeSeating(P.seating);

      // strip invalid assignments
      const validIds=new Set(students.map(s=>s.student_id));
      seats.forEach(s=>{ if(s.student_id && !validIds.has(s.student_id)) s.student_id=null; });

      // auto-assign any unseated students
      autoAssignUnseated();

      if(seats.length===0){
        const pos=placeGrid25(10);
        seats=pos.map((p,i)=>({seat_no:i+1,student_id:null,x:p.x,y:p.y}));
      }
      renderSeats();
      renderStats();
      saveSeating();
      await refreshBehavior();
    }

    function normalizeSeating(raw){
      if(!raw) return [];
      if(Array.isArray(raw)){
        return raw.map((r,i)=>({
          seat_no:parseInt(r.seat_no||i+1,10),
          student_id:(r.student_id!==null && r.student_id!=='')?parseInt(r.student_id,10):null,
          x:(r.x!=null)?parseFloat(r.x):null,
          y:(r.y!=null)?parseFloat(r.y):null
        }));
      }
      const arr=[];
      Object.keys(raw).forEach((k,i)=>arr.push({seat_no:parseInt(k,10),student_id:raw[k]?parseInt(raw[k],10):null,x:null,y:null}));
      return arr.sort((a,b)=>a.seat_no-b.seat_no);
    }

    function autoAssignUnseated(){
      const seatedIds=new Set(seats.map(s=>s.student_id).filter(v=>v!=null));
      const empties=()=>seats.filter(s=>s.student_id==null);
      students.forEach(stu=>{
        if(!seatedIds.has(stu.student_id)){
          let slot=empties()[0];
          if(!slot){
            const M=stageMetrics();
            const idx=seats.length;
            const c=idx%Math.max(1,M.maxCols);
            const r=Math.floor(idx/Math.max(1,M.maxCols));
            seats.push({seat_no:idx+1,student_id:null,x:M.pad+c*(M.seatW+M.gapX),y:M.pad+r*(M.seatH+M.gapY)});
            slot=seats[seats.length-1];
          }
          slot.student_id=stu.student_id;
          seatedIds.add(stu.student_id);
        }
      });
    }

    async function refreshStudents(){
      try{
const S=await fetch(`${API}/get_students.php`, { ...FETCH_OPTS }).then(r=>r.json());

        const newList=S.students||[];
        const newIds=new Set(newList.map(s=>s.student_id));
        seats.forEach(s=>{ if(s.student_id!=null && !newIds.has(s.student_id)) s.student_id=null; });
        students=newList; autoAssignUnseated(); renderSeats(); renderStats(); saveSeating();
      }catch(e){ /* ignore */ }
    }

    // ---------- Layout apply ----------
    function applyLayout(kind){
      const assigned=seats.filter(s=>s.student_id!=null).map(s=>s.student_id);
      let positions;
      switch(kind){
        case 'pairs': positions=placePairs(12); break;
        case 'grid': positions=placeGrid25(25); break;
        case 'rows': positions=placeRows(30); break;
        case 'g4': positions=placeGroupsOfFour(24); break;
        case 'ushape': positions=placeUShape(10,3,16); break;
        default: positions=placeGrid25(25);
      }
      if(assigned.length>positions.length){
        toast(`Layout has ${positions.length} chairs but you have ${assigned.length} seated students. Remove/unassign some first.`, 'error');
        return;
      }
      seats=positions.map((p,i)=>({seat_no:i+1,student_id:assigned[i]||null,x:p.x,y:p.y}));
      renderSeats(); renderStats(); saveSeating();
    }

    // ---------- Rendering ----------
    function renderSeats(){
      seatLayer.innerHTML='';
      const M=stageMetrics();
      seats=seats.map((s,i)=>{
        if(s.x==null || s.y==null){
          const c=i%Math.max(1,M.maxCols), r=Math.floor(i/Math.max(1,M.maxCols));
          s.x=M.pad+c*(M.seatW+M.gapX); s.y=M.pad+r*(M.seatH+M.gapY);
        }
        return s;
      });

      const colsForBias=Math.max(1,M.maxCols);
      seats.forEach((seat,i)=>{
        const node=document.createElement('div');
        node.className='seat draggable';
        node.dataset.seatNo=seat.seat_no;
        node.dataset.studentId=seat.student_id ?? '';
        node.style.left=(seat.x ?? 14)+'px';
        node.style.top=(seat.y ?? 14)+'px';

        const s=students.find(x=>x.student_id==seat.student_id);
        const hasStudent=!!s; const img=s?.avatar_url; const name=s?.fullname || 'Empty';
        const colIndex=i%colsForBias; const biasClass=(colIndex%2===0)?'tilt-left':'tilt-right';

        const st=hasStudent ? behaviorMap[String(s.student_id)] : null;
        const actionKey=st ? String(st.action||'').toLowerCase() : '';
const individuallyAway = !!(st && (st.is_away || AWAY_ACTIONS.has(actionKey)));
const isBackOverride   = backSet.has(String(seat.student_id));
const overlayApplies   = !!globalAction && !isBackOverride && !(st && !individuallyAway);
const isAway           = isBackOverride ? false : (overlayApplies || individuallyAway);


        node.innerHTML = `
          <div class="card ${hasStudent?'has-student':'empty'} ${isAway?'is-away':''}">
            ${hasStudent ? `
              <div class="avatar-wrapper">
                <div class="avatar-bias ${biasClass}">
                  <img src="${img}" class="avatar-img" style="--headDur:${(2.4+Math.random()*1.4).toFixed(2)}s;animation-delay:${(Math.random()*1.8).toFixed(2)}s;" />
                </div>
                ${(()=>{ const txt = overlayApplies ? actionOverlayText() : (individuallyAway ? actionText(actionKey) : modeText()); return txt ? `<div class="status-bubble">${txt}</div>` : '' })()}
              </div>
            ` : ''}
            <div class="desk-rect"></div>
            <div class="chair-back"></div>
            <div class="chair-seat"></div>
          </div>
          <div class="name ${hasStudent?'':'is-empty'}">${hasStudent?name:''}</div>
        `;

        // after node.innerHTML = `...`
if (hasStudent) {
  node.classList.add('has-student');
} else {
  node.classList.remove('has-student');
}

// keep away state on seat (you already do this)
// if (isAway) node.classList.add('is-away');
// else node.classList.remove('is-away');


        if(isAway) node.classList.add('is-away'); else node.classList.remove('is-away');
        seatLayer.appendChild(node);
      });
      bindMenus();
      enableDragging();
    }

    function renderStats(){
      const total=students.length;
      const seatedCount=seats.reduce((a,s)=>a+(s.student_id!=null?1:0),0);
      document.getElementById('stats').textContent=`Students: ${total} • Seated: ${seatedCount} • Chairs: ${seats.length}`;
      seatCountEl.textContent = seats.length;
    }

    // ---------- Behavior refresh ----------
    async function refreshBehavior(){
      try{
        const res=await fetch(`${API}/get_behavior_status.php`, { ...FETCH_OPTS }).then(r=>r.json());
        if(!res || res.ok===false) return;
        behaviorMap={};
        if(res.map && typeof res.map==='object'){
          behaviorMap=res.map;
        } else if(Array.isArray(res)){
          res.forEach(row=>{
            const act=String(row.action_type||'').toLowerCase();
            behaviorMap[String(row.student_id)]={
              action:act,
              label:ACTION_LABELS[act]||row.label||'',
              is_away:AWAY_ACTIONS.has(act),
              timestamp:row.timestamp
            };
          });
        }
        renderSeats();
      }catch(e){ console.error('refreshBehavior error:',e); }
    }

    // ---------- Context menu ----------
    function bindMenus(){
      seatLayer.querySelectorAll('.seat').forEach(card=>{
        card.addEventListener('contextmenu', e=>{
          e.preventDefault();
          const no=parseInt(card.dataset.seatNo,10);
          const seat=seats.find(s=>s.seat_no===no);
          if(!seat?.student_id) return;
          menu.dataset.studentId=seat.student_id;
          const stu=students.find(x=>x.student_id==seat.student_id);
          menuHeader.textContent = stu ? `For: ${stu.fullname}` : '';
          menu.style.left=(e.pageX+2)+'px';
          menu.style.top=(e.pageY+2)+'px';
          menu.classList.remove('hidden');
        });
      });

      document.addEventListener('click', e=>{ if(!menu.contains(e.target)) menu.classList.add('hidden'); });

      // Attendance
      menu.querySelectorAll('[data-action-student]').forEach(btn=>{
        btn.onclick = async ()=>{
          const sid=menu.dataset.studentId, status=btn.dataset.actionStudent;
const r=await fetch(`${API}/mark_attendance.php`,{
  method:'POST',
  headers:{ 'Content-Type':'application/json', ...FETCH_OPTS.headers },
  credentials: FETCH_OPTS.credentials,
  body: JSON.stringify({student_id:sid,status})
}).then(r=>r.json());

          toast(r.message||'Saved');
          menu.classList.add('hidden');
          if(status==='Present'||status==='Late') markStudentBack(sid); else markStudentAway(sid);
          await refreshBehavior();
        };
      });

      // Behavior logs
      menu.querySelectorAll('[data-log]').forEach(btn=>{
        btn.onclick = async ()=>{
          const sid=menu.dataset.studentId, action_type=btn.dataset.log;
const r=await fetch(`${API}/log_behavior.php`,{
  method:'POST',
  headers:{ 'Content-Type':'application/json', ...FETCH_OPTS.headers },
  credentials: FETCH_OPTS.credentials,
  body: JSON.stringify({student_id:sid,action_type})
}).then(r=>r.json()).catch(()=>({message:'Network error'}));

          toast(r.message||'Logged');
          menu.classList.add('hidden');
          if(AWAY_ACTIONS.has(action_type)) markStudentAway(sid); else markStudentBack(sid);
          await refreshBehavior();
        };
      });
    }

    // ---------- Dragging ----------
    function enableDragging(){
      function attachDrag(el,onDrop){
        let dragging=false,dX=0,dY=0;
        function down(e){
          dragging=true; el.classList.add('dragging');
          const p=(e.touches?e.touches[0]:e);
          const rect=el.getBoundingClientRect();
          dX=p.clientX-rect.left; dY=p.clientY-rect.top; e.preventDefault();
        }
        function move(e){
          if(!dragging) return; const p=(e.touches?e.touches[0]:e);
          const sRect=stage.getBoundingClientRect(); const r=el.getBoundingClientRect();
          let left=p.clientX-sRect.left-dX, top=p.clientY-sRect.top-dY;
          left=clamp(left,0,sRect.width-r.width); top=clamp(top,0,sRect.height-r.height);
          el.style.left=left+'px'; el.style.top=top+'px';
        }
        function up(){ if(!dragging) return; dragging=false; el.classList.remove('dragging'); if(typeof onDrop==='function') onDrop(); }
        el.addEventListener('mousedown',down); document.addEventListener('mousemove',move); document.addEventListener('mouseup',up);
        el.addEventListener('touchstart',down,{passive:false}); document.addEventListener('touchmove',move,{passive:false}); document.addEventListener('touchend',up);
      }
      seatLayer.querySelectorAll('.seat').forEach(el=>{
        attachDrag(el,()=>{
          const no=parseInt(el.dataset.seatNo,10);
          const seat=seats.find(s=>s.seat_no===no);
          seat.x=parseInt(el.style.left,10) || seat.x; seat.y=parseInt(el.style.top,10) || seat.y; saveSeating();
        });
      });
    }

    // ---------- Save seating ----------
    async function saveSeating(){
      seats.forEach((s,i)=>{ s.seat_no=i+1; });
      const payload=seats.map(s=>({seat_no:s.seat_no,student_id:s.student_id??null,x:Math.round(s.x??0),y:Math.round(s.y??0)}));
const r=await fetch(`${API}/save_seating.php`,{
  method:'POST',
  headers:{ 'Content-Type':'application/json', ...FETCH_OPTS.headers },
  credentials: FETCH_OPTS.credentials,
  body: JSON.stringify({seating:payload})
}).then(r=>r.json()).catch(()=>({ok:false}));

      if(r?.ok) toast('Layout saved'); else toast(r?.message||'Save failed','error');
    }

    // ---------- Chair + / - ----------
    document.getElementById('plusSeat').onclick=()=>{
      const M=stageMetrics();
      const idx=seats.length; const c=idx%Math.max(1,M.maxCols); const r=Math.floor(idx/Math.max(1,M.maxCols));
      seats.push({ seat_no:idx+1, student_id:null, x:M.pad+c*(M.seatW+M.gapX), y:M.pad+r*(M.seatH+M.gapY) });
      renderSeats(); renderStats(); saveSeating();
    };
    document.getElementById('minusSeat').onclick=()=>{
      if(seats.length<=1) return;
      const emptyIndexes = seats.map((s,i)=>({i,s})).filter(o=>o.s.student_id==null);
      if(emptyIndexes.length===0){ toast('Cannot remove: all chairs have students','error'); return; }
      const idx = emptyIndexes.sort((a,b)=>b.s.seat_no-a.s.seat_no)[0].i;
      seats.splice(idx,1);
      seats.sort((a,b)=>a.seat_no-b.seat_no).forEach((s,i)=> s.seat_no=i+1);
      renderSeats(); renderStats(); saveSeating();
    };

    // ---------- THEMES & SHAPES ----------
    const THEMES=[
      {id:'classic',name:'Classic Wood',sw:'#d2a86a',d1:'#e6cfa7',d2:'#d2a86a',db:'#a16a2a',leg:'#8b5e34',seat:'#d1d5db',back:'#9ca3af',cb:'#6b7280'},
      {id:'ocean',name:'Ocean Blue',sw:'#4fc3f7',d1:'#b3e5fc',d2:'#4fc3f7',db:'#0288d1',leg:'#01579b',seat:'#b2f5ea',back:'#80deea',cb:'#0ea5e9'},
      {id:'spring',name:'Spring Green',sw:'#86efac',d1:'#d9f99d',d2:'#86efac',db:'#22c55e',leg:'#166534',seat:'#bbf7d0',back:'#86efac',cb:'#16a34a'},
      {id:'berry',name:'Berry Pop',sw:'#e879f9',d1:'#f5d0fe',d2:'#e879f9',db:'#a21caf',leg:'#6b21a8',seat:'#fde68a',back:'#fbcfe8',cb:'#be185d'},
      {id:'modern',name:'Modern Gray',sw:'#cbd5e1',d1:'#e5e7eb',d2:'#cbd5e1',db:'#475569',leg:'#334155',seat:'#e2e8f0',back:'#cbd5e1',cb:'#334155'},
      {id:'sunset',name:'Sunset',sw:'#fb923c',d1:'#fed7aa',d2:'#fb923c',db:'#f59e0b',leg:'#92400e',seat:'#fee2e2',back:'#fecaca',cb:'#ea580c'},
      {id:'jungle',name:'Jungle',sw:'#16a34a',d1:'#86efac',d2:'#22c55e',db:'#15803d',leg:'#065f46',seat:'#bbf7d0',back:'#4ade80',cb:'#166534'},
      {id:'lavender',name:'Lavender',sw:'#a78bfa',d1:'#ede9fe',d2:'#a78bfa',db:'#7c3aed',leg:'#5b21b6',seat:'#f5f3ff',back:'#ddd6fe',cb:'#6d28d9'},
      {id:'cocoa',name:'Cocoa',sw:'#a16207',d1:'#fde68a',d2:'#f59e0b',db:'#a16207',leg:'#7c2d12',seat:'#fef3c7',back:'#fcd34d',cb:'#92400e'},
      {id:'slate',name:'Slate',sw:'#64748b',d1:'#cbd5e1',d2:'#94a3b8',db:'#475569',leg:'#334155',seat:'#e2e8f0',back:'#94a3b8',cb:'#1f2937'},
      {id:'mint',name:'Mint',sw:'#34d399',d1:'#d1fae5',d2:'#34d399',db:'#10b981',leg:'#065f46',seat:'#ecfeff',back:'#a7f3d0',cb:'#0f766e'},
      {id:'coral',name:'Coral',sw:'#fb7185',d1:'#fecdd3',d2:'#fb7185',db:'#f43f5e',leg:'#9f1239',seat:'#ffe4e6',back:'#fecdd3',cb:'#be123c'},
      {id:'sky',name:'Sky',sw:'#38bdf8',d1:'#e0f2fe',d2:'#38bdf8',db:'#0284c7',leg:'#075985',seat:'#e0f2fe',back:'#bae6fd',cb:'#0369a1'},
      {id:'lemon',name:'Lemon',sw:'#fde047',d1:'#fef9c3',d2:'#fde047',db:'#ca8a04',leg:'#854d0e',seat:'#fefce8',back:'#fde68a',cb:'#a16207'},
      {id:'rose',name:'Rose',sw:'#f472b6',d1:'#fce7f3',d2:'#f472b6',db:'#db2777',leg:'#9d174d',seat:'#ffe4e6',back:'#f9a8d4',cb:'#be185d'},
      {id:'night',name:'Night',sw:'#0ea5e9',d1:'#1f2937',d2:'#0ea5e9',db:'#0c4a6e',leg:'#082f49',seat:'#cbd5e1',back:'#94a3b8',cb:'#0f172a'},
      {id:'sand',name:'Sand',sw:'#facc15',d1:'#fde68a',d2:'#facc15',db:'#ca8a04',leg:'#92400e',seat:'#fef3c7',back:'#fde68a',cb:'#a16207'},
      {id:'copper',name:'Copper',sw:'#ea580c',d1:'#fed7aa',d2:'#fdba74',db:'#c2410c',leg:'#7c2d12',seat:'#fff7ed',back:'#fed7aa',cb:'#7c2d12'},
      {id:'denim',name:'Denim',sw:'#2563eb',d1:'#bfdbfe',d2:'#60a5fa',db:'#1d4ed8',leg:'#1e3a8a',seat:'#e0e7ff',back:'#bfdbfe',cb:'#1e40af'},
      {id:'lime',name:'Lime',sw:'#84cc16',d1:'#ecfccb',d2:'#a3e635',db:'#65a30d',leg:'#3f6212',seat:'#f7fee7',back:'#d9f99d',cb:'#4d7c0f'}
    ];

    const SHAPES=[
      {id:'classic',name:'Classic',v:{bw:'70px',bh:'28px',br:'4px',sw:'70px',sh:'18px',sr:'4px',sm:'-6px'},ex:[]},
      {id:'rounded',name:'Rounded Plastic',v:{bw:'72px',bh:'34px',br:'18px',sw:'76px',sh:'22px',sr:'9999px',sm:'-4px'},ex:[]},
      {id:'stool',name:'Stool',v:{bw:'0px',bh:'0px',br:'0',sw:'42px',sh:'42px',sr:'9999px',sm:'6px'},ex:['no-back','extra-post']},
      {id:'tablet',name:'Tablet Arm',v:{bw:'64px',bh:'24px',br:'6px',sw:'68px',sh:'18px',sr:'10px',sm:'-4px'},ex:['extra-tablet']},
      {id:'bench',name:'Bench',v:{bw:'92px',bh:'22px',br:'6px',sw:'92px',sh:'16px',sr:'6px',sm:'-6px'},ex:[]},
      {id:'highback',name:'High Back',v:{bw:'70px',bh:'44px',br:'10px',sw:'70px',sh:'18px',sr:'6px',sm:'-6px'},ex:[]},
      {id:'split',name:'Split Back',v:{bw:'72px',bh:'22px',br:'6px',sw:'70px',sh:'18px',sr:'6px',sm:'-6px'},ex:['extra-splitback']},
      {id:'winged',name:'Winged',v:{bw:'70px',bh:'30px',br:'10px',sw:'70px',sh:'18px',sr:'8px',sm:'-6px'},ex:['extra-wings']},
      {id:'saddle',name:'Saddle Seat',v:{bw:'68px',bh:'26px',br:'8px',sw:'72px',sh:'20px',sr:'16px',sm:'-6px'},ex:['extra-notch']},
      {id:'bubble',name:'Bubble',v:{bw:'60px',bh:'38px',br:'9999px',sw:'58px',sh:'18px',sr:'9999px',sm:'-8px'},ex:[]},
      {id:'square',name:'Square',v:{bw:'70px',bh:'28px',br:'2px',sw:'70px',sh:'18px',sr:'2px',sm:'-6px'},ex:[]},
      {id:'trape',name:'Trapezoid Seat',v:{bw:'68px',bh:'26px',br:'8px',sw:'78px',sh:'18px',sr:'12px',sm:'-6px'},ex:[]},
      {id:'arc',name:'Arc Back',v:{bw:'76px',bh:'30px',br:'9999px 9999px 6px 6px',sw:'70px',sh:'18px',sr:'8px',sm:'-6px'},ex:[]},
      {id:'lattice',name:'Lattice Back',v:{bw:'70px',bh:'30px',br:'8px',sw:'70px',sh:'18px',sr:'8px',sm:'-6px'},ex:['extra-stripes']},
      {id:'oval',name:'Oval Back',v:{bw:'76px',bh:'28px',br:'9999px / 60% 60% 30% 30%',sw:'70px',sh:'18px',sr:'10px',sm:'-6px'},ex:[]},
      {id:'bucket',name:'Bucket',v:{bw:'70px',bh:'28px',br:'12px',sw:'70px',sh:'20px',sr:'14px 14px 10px 10px',sm:'-6px'},ex:[]},
      {id:'slim',name:'Slim Back',v:{bw:'56px',bh:'36px',br:'12px',sw:'68px',sh:'18px',sr:'10px',sm:'-6px'},ex:[]},
      {id:'wide',name:'Wide Back',v:{bw:'100px',bh:'20px',br:'8px',sw:'90px',sh:'16px',sr:'8px',sm:'-6px'},ex:[]},
      {id:'flip',name:'Low Seat',v:{bw:'70px',bh:'28px',br:'8px',sw:'68px',sh:'14px',sr:'10px',sm:'-10px'},ex:[]},
      {id:'tripod',name:'Tripod Stool',v:{bw:'0px',bh:'0px',br:'0',sw:'44px',sh:'44px',sr:'9999px',sm:'4px'},ex:['no-back','extra-tripod']}
    ];

    function applyColorTheme(id){
      const t=THEMES.find(x=>x.id===id)||THEMES[0];
      stage.style.setProperty('--desk-grad-1',t.d1);
      stage.style.setProperty('--desk-grad-2',t.d2);
      stage.style.setProperty('--desk-border',t.db);
      stage.style.setProperty('--leg',t.leg);
      stage.style.setProperty('--chair-seat',t.seat);
      stage.style.setProperty('--chair-back',t.back);
      stage.style.setProperty('--chair-border',t.cb);
      localStorage.setItem(COLOR_KEY,id);
      document.querySelectorAll('#colorList .style-btn').forEach(b=>b.classList.toggle('active',b.dataset.theme===id));
    }

    function clearShapeExtras(){ stage.classList.remove('no-back','extra-tablet','extra-post','extra-tripod','extra-wings','extra-stripes','extra-notch','extra-splitback'); }

    function applyChairShape(id){
      const s=SHAPES.find(x=>x.id===id)||SHAPES[0], v=s.v;
      stage.style.setProperty('--back-w',v.bw); stage.style.setProperty('--back-h',v.bh); stage.style.setProperty('--back-r',v.br);
      stage.style.setProperty('--seat-w',v.sw); stage.style.setProperty('--seat-h',v.sh); stage.style.setProperty('--seat-r',v.sr); stage.style.setProperty('--seat-mt',v.sm);
      clearShapeExtras(); s.ex.forEach(cls=>stage.classList.add(cls));
      localStorage.setItem(SHAPE_KEY,id);
      document.querySelectorAll('#shapeList .style-btn').forEach(b=>b.classList.toggle('active',b.dataset.shape===id));
    }

    function buildCustomizerLists(){
      const colorList=document.getElementById('colorList');
      colorList.innerHTML='';
      THEMES.forEach(t=>{
        const btn=document.createElement('button');
        btn.className='style-btn'; btn.dataset.theme=t.id;
        btn.innerHTML=`<span class="swatch" style="background:linear-gradient(180deg,${t.d1},${t.d2});"></span><span>${t.name}</span>`;
        btn.onclick=()=>applyColorTheme(t.id);
        colorList.appendChild(btn);
      });

      const shapeList=document.getElementById('shapeList');
      shapeList.innerHTML='';
      SHAPES.forEach(s=>{
        const btn=document.createElement('button');
        btn.className='style-btn'; btn.dataset.shape=s.id; btn.textContent=s.name; btn.onclick=()=>applyChairShape(s.id);
        shapeList.appendChild(btn);
      });

      applyColorTheme(localStorage.getItem(COLOR_KEY)||'classic');
      applyChairShape(localStorage.getItem(SHAPE_KEY)||'classic');
    }

    // ---------- Resize & init ----------
    window.addEventListener('resize', ()=>{
      const seatedIDs=seats.filter(s=>s.student_id!=null).map(s=>s.student_id);
      const N=seats.length, maxCols=Math.min(stageMetrics().maxCols,8);
      const positions=placeGridCentered(maxCols,Math.ceil(N/Math.max(1,maxCols)),N);
      seats=positions.map((p,i)=>({seat_no:i+1,student_id:seatedIDs[i]||null,x:p.x,y:p.y}));
      renderSeats(); refreshBehavior();
    });

    const tplModal=document.getElementById('tplModal');
    document.getElementById('openTpl').onclick=()=>tplModal.classList.add('open');
    document.getElementById('closeTpl').onclick=()=>tplModal.classList.remove('open');

    document.querySelectorAll('.tab-btn').forEach(btn=>{
      btn.addEventListener('click',()=>{
        document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
        btn.classList.add('active');
        const tab=btn.dataset.tab;
        document.querySelectorAll('.tab-body').forEach(p=>p.classList.toggle('hidden',p.dataset.tabPanel!==tab));
      });
    });

    document.querySelectorAll('.tpl').forEach(t=>{
      t.onclick=()=>{ applyLayout(t.dataset.layout); tplModal.classList.remove('open'); };
    });

    backBtn.onclick=()=>history.back();

    buildCustomizerLists();
    loadData();
    setInterval(refreshBehavior, 3000);
    setInterval(refreshStudents, 6000);
  </script>
</body>
</html>
