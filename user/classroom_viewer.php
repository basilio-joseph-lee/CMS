<?php
/* dashboard.php — STUDENT KIOSK
 * Flow: face_login.php -> select_subject.php -> dashboard.php
 * - READ-ONLY Classroom Simulator (mirrors teacher)
 * - Removed "Recent"
 * - Grab-to-pan; floor tiles correctly (no double flooring)
 * - Quick actions + Quiz Game + View Results
 */


include "../config/db.php";

/* ---------- Guards: require STUDENT ---------- */
$student_id = $_SESSION['student_id'] ?? null;
if (!$student_id) { header("Location: ../index.php"); exit; }

/* ---------- CONTEXT (IDs) ---------- */
$subject_id     = $_SESSION['active_subject_id']     ?? $_SESSION['subject_id']     ?? null;
$advisory_id    = $_SESSION['active_advisory_id']    ?? $_SESSION['advisory_id']    ?? null;
$school_year_id = $_SESSION['active_school_year_id'] ?? $_SESSION['school_year_id'] ?? null;

/* ---------- Labels ---------- */
$subjectName = $_SESSION['subject_name'] ?? '';
$className   = $_SESSION['class_name']   ?? '';
$yearLabel   = $_SESSION['year_label']   ?? '';

/* ---------- Backfill labels from DB if missing ---------- */
if ((!$subjectName || !$className || !$yearLabel) && $subject_id && $advisory_id && $school_year_id) {

  if (!$conn->connect_error) {
    $conn->set_charset('utf8mb4');
    if (!$subjectName) {
      $stmt = $conn->prepare("SELECT subject_name FROM subjects WHERE subject_id=? LIMIT 1");
      $stmt->bind_param("i", $subject_id); $stmt->execute();
      $row = $stmt->get_result()->fetch_assoc(); if ($row) $subjectName = $row['subject_name']; $stmt->close();
    }
    if (!$className) {
      $stmt = $conn->prepare("SELECT class_name FROM advisory_classes WHERE advisory_id=? LIMIT 1");
      $stmt->bind_param("i", $advisory_id); $stmt->execute();
      $row = $stmt->get_result()->fetch_assoc(); if ($row) $className = $row['class_name']; $stmt->close();
    }
    if (!$yearLabel) {
      $stmt = $conn->prepare("SELECT year_label FROM school_years WHERE school_year_id=? LIMIT 1");
      $stmt->bind_param("i", $school_year_id); $stmt->execute();
      $row = $stmt->get_result()->fetch_assoc(); if ($row) $yearLabel = $row['year_label']; $stmt->close();
    }

  }
}
$_SESSION['subject_name'] = $subjectName;
$_SESSION['class_name']   = $className;
$_SESSION['year_label']   = $yearLabel;

/* ---------- Announcements + Quiz results ---------- */
$teacher_announcements = [];
$hasResultsToday = false;
$hasActiveQuiz   = false;
$roundRows       = [];

if ($subject_id && $advisory_id && $school_year_id) {

  if (!$conn->connect_error) {
    $conn->set_charset('utf8mb4');

    // Announcements
    $stmt = $conn->prepare("
      SELECT title, message, date_posted
      FROM announcements
      WHERE subject_id=? AND class_id=?
        AND (visible_until IS NULL OR visible_until >= CURDATE())
      ORDER BY date_posted DESC
      LIMIT 5
    ");
    $stmt->bind_param("ii", $subject_id, $advisory_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $teacher_announcements[] = $row;
    $stmt->close();

    // Active quiz?
    $stmt = $conn->prepare("
      SELECT COUNT(*) AS c
      FROM kiosk_quiz_questions
      WHERE status='published' AND subject_id=? AND advisory_id=? AND school_year_id=?
    ");
    $stmt->bind_param("iii", $subject_id, $advisory_id, $school_year_id);
    $stmt->execute();
    $hasActiveQuiz = intval($stmt->get_result()->fetch_assoc()['c'] ?? 0) > 0;
    $stmt->close();

    // Latest closed quiz today (for results)
    $stmt = $conn->prepare("
      SELECT question_id
      FROM kiosk_quiz_questions
      WHERE subject_id=? AND advisory_id=? AND school_year_id=?
        AND status='closed' AND DATE(published_at)=CURDATE()
      ORDER BY question_id DESC
      LIMIT 1
    ");
    $stmt->bind_param("iii", $subject_id, $advisory_id, $school_year_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows) {
      $qid = intval($res->fetch_assoc()['question_id']);
      $hasResultsToday = true;

      $stmt2 = $conn->prepare("
        SELECT r.student_id, s.fullname, s.avatar_path,
               r.chosen_opt, r.is_correct, r.points, r.time_ms, r.answered_at
        FROM kiosk_quiz_responses r
        JOIN students s ON s.student_id=r.student_id
        WHERE r.question_id=?
        ORDER BY r.points DESC, r.time_ms ASC
        LIMIT 10
      ");
      $stmt2->bind_param("i", $qid);
      $stmt2->execute();
      $roundRows = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
      $stmt2->close();
    }
    $stmt->close();


  }
}

function cleanRow($r){
  $p = trim((string)($r['avatar_path'] ?? ''));
  if ($p !== '') $p = preg_replace('#^\./#','',$p);
  if ($p === '') $p = 'img/default-avatar.png';
  return [
    'student_id'  => intval($r['student_id']),
    'fullname'    => $r['fullname'],
    'avatar_path' => $p,
    'chosen_opt'  => $r['chosen_opt'],
    'is_correct'  => intval($r['is_correct']),
    'points'      => intval($r['points']),
    'time_ms'     => intval($r['time_ms']),
    'answered_at' => $r['answered_at'],
  ];
}
$roundPayload = array_map('cleanRow', $roundRows);
$announcement_count = count($teacher_announcements);
$fullname = $_SESSION['fullname'] ?? 'Student';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Student Kiosk</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<script src="https://cdn.tailwindcss.com"></script>
<style>
  body{ background:#0b1222; color:#e6edf5; }
  .btn{ border-radius:.75rem; padding:.5rem 1rem; color:#fff; }
  .tile{ background:#1c2635; border-radius:1rem; padding:1.25rem; box-shadow:0 2px 8px rgba(0,0,0,.25); }
  .tile:hover{ background:#223046; }
  @keyframes fadeOut{ to{opacity:0; transform:translateY(20px);} }
  .fade-out{ animation: fadeOut 1s forwards; }
  @keyframes glow { 0%{box-shadow:0 0 0 0 rgba(250,204,21,.8);} 70%{box-shadow:0 0 0 16px rgba(250,204,21,0);} 100%{box-shadow:0 0 0 0 rgba(250,204,21,0);} }
  .quiz-glow{ animation: glow 1.6s infinite; }

  /* ====== READ-ONLY SIMULATOR ====== */
  .sim #stage{
    position: relative;
    min-height: 420px;
    height: 50vh;
    background:#111;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: inset 0 0 20px rgba(0,0,0,0.15);
    cursor: grab;
    --desk-grad-1:#e6cfa7; --desk-grad-2:#d2a86a; --desk-border:#a16a2a; --leg:#8b5e34;
    --chair-seat:#d1d5db; --chair-back:#9ca3af; --chair-border:#6b7280;
    --back-w:70px; --back-h:28px; --back-r:4px;
    --seat-w:70px; --seat-h:18px; --seat-r:4px; --seat-mt:-6px;
  }
  .sim #stage.panning{ cursor:grabbing; }

  /* Floor (single source of truth) */
  #simInner{
    position:absolute; inset:0;
    background:url('../img/bg-8.png') repeat;
    background-size:512px auto;                 /* match your tile size */
    background-position: var(--bgX,0px) var(--bgY,0px);
    z-index:0;
  }

  /* Seats/avatars (NO background here) */
  #seatLayer{
    position:absolute; inset:0;
    transform: translate3d(var(--panX,0px), var(--panY,0px), 0);
    will-change: transform;
    z-index:1;
  }

  .no-select{ user-select:none; -webkit-user-select:none; -ms-user-select:none; -moz-user-select:none; }

  .sim .seat{ width:100px; position:absolute; user-select:none; transition:opacity .2s ease; }
  .sim .seat .card{ position:relative; background:transparent; border:none; box-shadow:none; text-align:center; }
  .sim .desk-rect{ width:90px; height:40px; background:linear-gradient(180deg,var(--desk-grad-1),var(--desk-grad-2)); border:2px solid var(--desk-border); border-radius:6px 6px 2px 2px; margin:0 auto; position:relative; z-index:1; }
  .sim .desk-rect::before,.sim .desk-rect::after{ content:""; position:absolute; width:6px; height:28px; background:var(--leg); bottom:-28px; }
  .sim .desk-rect::before{ left:10px; } .sim .desk-rect::after{ right:10px; }
  .sim .chair-back{ width:var(--back-w); height:var(--back-h); background:var(--chair-back); border:2px solid var(--chair-border); border-radius:var(--back-r); margin:0 auto; position:relative; }
  .sim .chair-seat{ width:var(--seat-w); height:var(--seat-h); background:var(--chair-seat); border:2px solid var(--chair-border); border-radius:var(--seat-r); margin:var(--seat-mt) auto 0; position:relative; z-index:0; }
  .sim .avatar-wrapper{ position:absolute; top:-20px; left:50%; transform:translateX(-50%); width:60px; height:60px; z-index:2; }
  .sim .avatar-img{ width:100%; height:100%; object-fit:contain; display:block; animation: head-tilt var(--headDur, 2.8s) ease-in-out infinite alternate; transform-origin: 50% 76%; }
  @keyframes head-tilt{ 0%{ transform: rotateZ(-6deg);} 100%{ transform: rotateZ(6deg);} }
  .sim .name{ margin-top:6px; font-size:12px; text-align:center; font-weight:700; color:#e6edf5; opacity:.9; text-shadow:0 1px 0 rgba(0,0,0,.5); pointer-events:none; }
  .sim .status-bubble{
    position:absolute; top:6px; left:calc(100% + 8px);
    background:#fff; border:2px solid #111; border-radius:9999px; padding:6px 10px; font-size:12px; font-weight:700; white-space:nowrap; box-shadow:0 2px 4px rgba(0,0,0,.2); color:#111;
  }
  .sim .status-bubble::before,.sim .status-bubble::after{ content:""; position:absolute; background:#fff; border:2px solid #111; border-radius:50%; }
  .sim .status-bubble::before{ width:10px; height:10px; left:-14px; top:22px; }
  .sim .status-bubble::after { width:6px;  height:6px;  left:-22px; top:28px; }
  .sim .seat.is-away .avatar-img{ display:none; }
</style>
</head>
<body class="min-h-screen">
<div class="max-w-6xl mx-auto p-4">
  <!-- Header -->
  <header class="flex items-center justify-between mb-4">
    <div>
      <h1 class="text-2xl font-bold">
        Student Kiosk • <?= htmlspecialchars($className ?: 'Section') ?> • <?= htmlspecialchars($subjectName ?: 'Subject') ?> (<?= htmlspecialchars($yearLabel ?: 'SY') ?>)
      </h1>
      <p class="text-slate-400 text-sm">Student: <?= htmlspecialchars($fullname) ?></p>
    </div>
    <a href="../config/logout.php?role=student" class="btn bg-red-600 hover:bg-red-700">Logout</a>
  </header>

  <!-- Announcement toast -->
  <?php if (!empty($teacher_announcements) && isset($_GET['new_login'])): ?>
    <div id="announcement-toast" class="fixed top-6 left-1/2 -translate-x-1/2 bg-yellow-100 border border-yellow-400 text-gray-900 px-6 py-4 rounded-xl shadow-xl z-50">
      <strong class="block mb-1">📢 Announcement</strong>
      <p class="text-sm"><?= htmlspecialchars($teacher_announcements[0]['title']) ?>: <?= htmlspecialchars($teacher_announcements[0]['message']) ?></p>
    </div>
    <script>
      setTimeout(()=>{ const t=document.getElementById('announcement-toast'); if(t){ t.classList.add('fade-out'); setTimeout(()=>t.remove(),1000);} }, 5000);
    </script>
  <?php endif; ?>

  <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-3">
    <!-- Left: SIM -->
    <section class="md:col-span-2 bg-slate-900 rounded-2xl p-4 sim">
      <div class="mb-3">
        <h3 class="text-lg font-semibold">Live Classroom (View Only)</h3>
        <p class="text-slate-400 text-xs">This mirrors your teacher’s simulator. It auto-updates every few seconds. Hold & drag to pan.</p>
      </div>

      <div id="stage" class="mb-1 overflow-hidden rounded-xl">
        <div id="simInner">
          <div id="seatLayer"></div>
        </div>
      </div>
    </section>

    <!-- Right: Quick actions + Quiz -->
    <aside class="space-y-3">
      <div class="grid grid-cols-1 gap-3">
        <button class="tile" onclick="logBehavior('participated')">🟢 <div class="font-semibold mt-2">I’m Back (IN)</div></button>
      </div>

      <div class="grid grid-cols-2 gap-3">
        <button class="tile" onclick="logBehavior('restroom')">🚻 <div class="font-semibold mt-2">Restroom</div></button>
        <button class="tile" onclick="logBehavior('clinic')">🏥 <div class="font-semibold mt-2">Clinic</div></button>
        <button class="tile" onclick="logBehavior('snack')">🍔 <div class="font-semibold mt-2">Snack</div></button>
        <button class="tile" onclick="logBehavior('water_break')">💧 <div class="font-semibold mt-2">Water Break</div></button>
        <button class="tile" onclick="logBehavior('borrow_book')">📚 <div class="font-semibold mt-2">Borrow Book</div></button>
        <!-- <button class="tile" onclick="logBehavior('return_material')">🔄 <div class="font-semibold mt-2">Return Material</div></button>
        <button class="tile" onclick="logBehavior('lunch_break')">🍱 <div class="font-semibold mt-2">Lunch Break</div></button> -->
        <button class="tile" onclick="logBehavior('not_well')">😷 <div class="font-semibold mt-2">Not Feeling Well</div></button>
      </div>

      <div class="grid grid-cols-2 gap-3">
        <button id="quizBtn" class="tile" onclick="openQuiz()">
          ❓ <div class="font-semibold mt-2">Quiz Game</div>
        </button>
        <button class="tile" onclick="openResults()">
          🏆 <div class="font-semibold mt-2">View Results</div>
        </button>
      </div>

      <?php if ($announcement_count > 0): ?>
        <div class="text-xs text-slate-400">You have <?= $announcement_count ?> announcement(s).</div>
      <?php endif; ?>
    </aside>
  </div>
</div>

<!-- Results Modal -->
<div id="resultsModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-[70] items-center justify-center">
  <div class="bg-white w-[96%] max-w-4xl rounded-2xl shadow-xl p-6 relative overflow-hidden text-gray-900">
    <button onclick="closeResults()" class="absolute top-2 right-3 text-2xl leading-none text-gray-500 hover:text-red-600">×</button>
    <h3 class="text-2xl font-extrabold text-center mb-6">Top 3 Podium</h3>
    <div class="grid grid-cols-3 gap-6 items-end mb-8">
      <div class="text-center opacity-70" id="podium2"></div>
      <div class="text-center" id="podium1"></div>
      <div class="text-center opacity-70" id="podium3"></div>
    </div>
    <h4 class="text-xl font-bold mb-3">Top 10 — This Round</h4>
    <div id="top10List" class="space-y-3"></div>
  </div>
</div>

<!-- Quiz Modal -->
<div id="quizModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-[60] items-center justify-center">
  <div class="bg-white w-[95%] max-w-xl rounded-2xl shadow-xl p-6 relative text-gray-900">
    <button onclick="closeQuiz()" class="absolute top-2 right-3 text-2xl leading-none text-gray-500 hover:text-red-600">×</button>
    <h3 class="text-xl font-bold mb-2" id="quizTitle">Quick Quiz</h3>
    <p class="mb-4" id="quizQuestion">Loading question…</p>
    <div id="quizOptions" class="space-y-2 mb-4"></div>
    <div class="flex items-center justify-between">
      <div class="text-sm text-gray-600">⏳ Time left: <span id="quizTimer">—</span>s</div>
      <button id="quizSubmitBtn" class="bg-blue-600 text-white px-4 py-2 rounded-lg disabled:opacity-50" disabled>Submit</button>
    </div>
  </div>
</div>

<script>
/* ===== Server flags ===== */
const HAS_RESULTS_TODAY = <?= $hasResultsToday ? 'true' : 'false' ?>;
const HAS_ACTIVE_QUIZ   = <?= $hasActiveQuiz ? 'true' : 'false' ?>;
const ROUND_RESULTS     = <?= json_encode($roundPayload, JSON_UNESCAPED_UNICODE) ?>;
if (HAS_ACTIVE_QUIZ) { const qb=document.getElementById('quizBtn'); qb && qb.classList.add('quiz-glow'); }

/* ===== Quick actions ===== */
function showToast(message, isError=false){
  const t=document.createElement('div');
  t.className=`fixed bottom-6 left-1/2 transform -translate-x-1/2 px-6 py-3 rounded-xl shadow-lg text-white z-[80] text-sm ${isError?'bg-red-600':'bg-green-600'}`;
  t.innerText=message; document.body.appendChild(t);
  setTimeout(()=>{t.classList.add('fade-out'); setTimeout(()=>t.remove(),1000);},3000);
}
function logBehavior(actionType){
  fetch('../config/log_behavior.php', {
    method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body: 'action_type='+encodeURIComponent(actionType)
  })
  .then(r=>r.json())
  .then(d=>{ if (d.success) showToast('✅ '+ actionType.replace('_',' ').toUpperCase() +' logged!'); else showToast('❌ '+(d.message||'Error'), true); })
  .catch(()=> showToast('❌ Network error', true));
}

/* =========================
   READ-ONLY SIMULATOR LOGIC
   ========================= */
const SIM_stage     = document.getElementById('stage');
const SIM_inner     = document.getElementById('simInner');
const SIM_seatLayer = document.getElementById('seatLayer');

let   SIM_students  = [];
let   SIM_seats     = [];
let   SIM_behavior  = {};

const SIM_AWAY = new Set(['restroom','snack','lunch_break','out_time','water_break','not_well','borrow_book','return_material','log_out']);

/* Responsive metrics */
let SIM_LAST_W = null, SIM_LAST_H = null;
function SIM_stageMetrics(){
  const pad = 14;
  const rect = SIM_stage.getBoundingClientRect();
  const seatW = Math.max(72, Math.min(110, Math.floor(rect.width / 10)));
  const seatH = Math.round(seatW * 0.95);
  const gapX  = Math.round(seatW * 0.22);
  const gapY  = Math.round(seatH * 0.25);
  const usableW=Math.max(0,rect.width-pad*2), usableH=Math.max(0,rect.height-pad*2);
  const maxCols = Math.max(1, Math.floor((usableW + gapX) / (seatW + gapX)));
  const maxRows = Math.max(1, Math.floor((usableH + gapY) / (seatH + gapY)));
  return { pad, seatW, seatH, gapX, gapY, rectW: rect.width, rectH: rect.height, usableW, usableH, maxCols, maxRows };
}
function SIM_placeGridCentered(cols, rows, total){
  const M=SIM_stageMetrics();
  cols=Math.min(Math.max(cols,1),M.maxCols); rows=Math.min(Math.max(rows,1),M.maxRows);
  const totalW=cols*M.seatW+(cols-1)*M.gapX, totalH=rows*M.seatH+(rows-1)*M.gapY;
  const startX=Math.max(M.pad,Math.round((M.rectW-totalW)/2));
  const startY=Math.max(M.pad,Math.round((M.rectH-totalH)/2)-10);
  const pos=[]; for(let r=0;r<rows;r++){ for(let c=0;c<cols;c++){ pos.push({x:startX+c*(M.seatW+M.gapX), y:startY+r*(M.seatH+M.gapY)}); if(pos.length===total) return pos; } }
  return pos;
}
function SIM_avatarUrl(row){
  const raw=(row && (row.avatar_url||row.avatar_path) ? String(row.avatar_url||row.avatar_path) : '').trim();
  if (!raw) return 'data:image/svg+xml;utf8,'+encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128"><rect width="100%" height="100%" rx="64" fill="#e5e7eb"/><text x="50%" y="54%" text-anchor="middle" font-family="Arial" font-size="52" fill="#9ca3af">?</text></svg>');
  if (raw.startsWith('http://')||raw.startsWith('https://')||raw.startsWith('data:')) return raw;
  if (raw.startsWith('/')) return raw;
  if (raw.startsWith('../')) return raw;
  return '../' + raw.replace(/^\.?\//,'');
}
function SIM_actionText(act){
  const icon = {
    restroom:'🚻', snack:'🍎', lunch_break:'🍱', water_break:'💧', not_well:'🤒',
    borrow_book:'📚', return_material:'📦', participated:'✅', help_request:'✋',
    attendance:'🟢', out_time:'🚪'
  };
  return icon[act] || '•';
}
function SIM_renderSeats(){
  SIM_seatLayer.innerHTML='';
  const M=SIM_stageMetrics();
  // Fill missing coordinates
  SIM_seats = SIM_seats.map((s,i)=>{
    if(s.x==null || s.y==null){
      const c=i%Math.max(1,M.maxCols), r=Math.floor(i/Math.max(1,M.maxCols));
      s.x=M.pad+c*(M.seatW+M.gapX); s.y=M.pad+r*(M.seatH+M.gapY);
    }
    return s;
  });
  const colsForBias=Math.max(1,M.maxCols);

  SIM_seats.forEach((seat,i)=>{
    const node=document.createElement('div'); node.className='seat';
    node.style.left=(seat.x ?? 14)+'px'; node.style.top=(seat.y ?? 14)+'px';

    const s=SIM_students.find(x=>x.student_id==seat.student_id);
    const hasStudent=!!s; const img=SIM_avatarUrl(s||{}); const name=s?.fullname || '';
    const colIndex=i%colsForBias; const biasClass=(colIndex%2===0)?'tilt-left':'tilt-right';

    const st = hasStudent ? SIM_behavior[String(s.student_id)] : null;
    const actionKey = st ? String(st.action||'').toLowerCase() : '';
    const isAway = !!(st && (st.is_away || SIM_AWAY.has(actionKey)));

    node.innerHTML = `
      <div class="card ${hasStudent?'has-student':'empty'} ${isAway?'is-away':''}">
        ${hasStudent?`
          <div class="avatar-wrapper">
            <img src="${img}" class="avatar-img ${biasClass}" style="--headDur:${(2.4+Math.random()*1.4).toFixed(2)}s;animation-delay:${(Math.random()*1.4).toFixed(2)}s;">
            <div class="status-bubble" ${isAway?'':'style="display:none"'}>${isAway ? SIM_actionText(actionKey) : ''}</div>
          </div>`:``}
        <div class="desk-rect"></div>
        <div class="chair-back"></div>
        <div class="chair-seat"></div>
      </div>
      ${name ? `<div class="name">${name}</div>` : ``}
    `;
    SIM_seatLayer.appendChild(node);
  });
}
function SIM_updateSeatStates(){
  SIM_seatLayer.querySelectorAll('.seat').forEach((node, idx)=>{
    const seat = SIM_seats[idx]; if(!seat) return;
    const stu  = SIM_students.find(x=>x.student_id==seat.student_id);
    const st   = stu ? SIM_behavior[String(stu.student_id)] : null;
    const actionKey = st ? String(st.action||'').toLowerCase() : '';
    const isAway = !!(st && (st.is_away || SIM_AWAY.has(actionKey)));
    node.classList.toggle('is-away', isAway);
    const bubble = node.querySelector('.status-bubble');
    if (bubble){
      bubble.textContent = isAway ? SIM_actionText(actionKey) : '';
      bubble.style.display = isAway ? '' : 'none';
    }
  });
}
async function SIM_loadData(){
  try{
    const [S,P]=await Promise.all([
      fetch('../api/get_students.php').then(r=>r.json()),
      fetch('../api/get_seating.php').then(r=>r.json())
    ]);
    SIM_students = S.students || [];
    SIM_seats    = (P.seating || []).map((r,i)=>({
      seat_no: parseInt(r.seat_no||i+1,10),
      student_id: (r.student_id!==null && r.student_id!=='')?parseInt(r.student_id,10):null,
      x: (r.x!=null)?parseFloat(r.x):null,
      y: (r.y!=null)?parseFloat(r.y):null
    }));

    const validIds = new Set(SIM_students.map(s=>s.student_id));
    SIM_seats.forEach(s=>{ if(s.student_id && !validIds.has(s.student_id)) s.student_id=null; });

    if (SIM_seats.length===0){
      const pos = SIM_placeGridCentered(5,5, Math.max(10, SIM_students.length));
      SIM_seats = pos.map((p,i)=>({seat_no:i+1, student_id:SIM_students[i]?.student_id ?? null, x:p.x, y:p.y}));
    }
    SIM_renderSeats();
    await SIM_refreshBehavior();
  }catch(e){ /* silent */ }
}
async function SIM_refreshBehavior(){
  try{
    const res=await fetch('../api/get_behavior_status.php').then(r=>r.json());
    let map={};
    if(res && res.map && typeof res.map==='object'){ map=res.map; }
    else if(Array.isArray(res)){
      res.forEach(row=>{
        const act=String(row.action_type||'').toLowerCase();
        map[String(row.student_id)]={ action:act, is_away:SIM_AWAY.has(act), timestamp:row.timestamp };
      });
    }
    SIM_behavior = map;
    SIM_updateSeatStates();
  }catch(e){ /* silent */ }
}
async function SIM_refreshStudents(){
  try{
    const S=await fetch('../api/get_students.php').then(r=>r.json());
    const list=S.students||[];
    const byId=new Map(list.map(s=>[s.student_id,s]));
    SIM_students=list;
    SIM_seats.forEach(s=>{ if(s.student_id!=null && !byId.has(s.student_id)) s.student_id=null; });
    SIM_renderSeats(); SIM_updateSeatStates();
  }catch(e){ /* silent */ }
}

/* ---- Grab-to-pan ---- */
let PAN_X = 0, PAN_Y = 0;
let _panning=false, _panStartX=0, _panStartY=0, _panBaseX=0, _panBaseY=0;

/* match CSS background-size tile (px) */
const TILE_W = 512, TILE_H = 512;

function setPan(x, y){
  PAN_X = x; PAN_Y = y;
  // move seats
  SIM_seatLayer.style.setProperty('--panX', `${Math.round(PAN_X)}px`);
  SIM_seatLayer.style.setProperty('--panY', `${Math.round(PAN_Y)}px`);
  // shift floor bg (no extra layer/translate)
  const bgX = ((PAN_X % TILE_W) + TILE_W) % TILE_W;
  const bgY = ((PAN_Y % TILE_H) + TILE_H) % TILE_H;
  SIM_inner.style.setProperty('--bgX', `${Math.round(bgX)}px`);
  SIM_inner.style.setProperty('--bgY', `${Math.round(bgY)}px`);
}
function beginPan(clientX, clientY){
  _panning = true;
  _panStartX = clientX; _panStartY = clientY;
  _panBaseX = PAN_X; _panBaseY = PAN_Y;
  SIM_stage.classList.add('panning');
  document.body.classList.add('no-select');
}
function movePan(clientX, clientY){
  if(!_panning) return;
  setPan(_panBaseX + (clientX - _panStartX), _panBaseY + (clientY - _panStartY));
}
function endPan(){
  if(!_panning) return;
  _panning = false;
  SIM_stage.classList.remove('panning');
  document.body.classList.remove('no-select');
}

SIM_stage.addEventListener('mousedown', e=>{ beginPan(e.clientX, e.clientY); e.preventDefault(); });
window.addEventListener('mousemove', e=>{ if(_panning){ movePan(e.clientX, e.clientY); e.preventDefault(); }});
window.addEventListener('mouseup',   endPan);

SIM_stage.addEventListener('touchstart', e=>{ const t=e.touches[0]; beginPan(t.clientX, t.clientY); e.preventDefault(); }, {passive:false});
window.addEventListener('touchmove',  e=>{ if(!_panning) return; const t=e.touches[0]; movePan(t.clientX, t.clientY); e.preventDefault(); }, {passive:false});
window.addEventListener('touchend',   endPan);

/* ---- Resize: scale absolute positions ---- */
function SIM_onResize(){
  const rect = SIM_stage.getBoundingClientRect();
  if (SIM_LAST_W && SIM_LAST_H){
    const sx = rect.width  / SIM_LAST_W;
    const sy = rect.height / SIM_LAST_H;
    SIM_seats.forEach(s=>{
      if (typeof s.x==='number') s.x = Math.round(s.x * sx);
      if (typeof s.y==='number') s.y = Math.round(s.y * sy);
    });
  }
  SIM_LAST_W = rect.width; SIM_LAST_H = rect.height;
  SIM_renderSeats(); SIM_updateSeatStates();
}
window.addEventListener('resize', SIM_onResize);

/* ---- Boot ---- */
const _firstRect = SIM_stage.getBoundingClientRect();
SIM_LAST_W = _firstRect.width; SIM_LAST_H = _firstRect.height;
SIM_loadData();
setInterval(SIM_refreshBehavior, 3000);
setInterval(SIM_refreshStudents, 6000);

/* ===== Results Modal ===== */
const resultsModal=document.getElementById('resultsModal');
function openResults(){
  if (!HAS_RESULTS_TODAY || !Array.isArray(ROUND_RESULTS) || ROUND_RESULTS.length===0){
    showToast('No results to show yet.', true); return;
  }
  buildPodiumAndList(ROUND_RESULTS);
  resultsModal.classList.remove('hidden'); resultsModal.classList.add('flex');
}
function closeResults(){ resultsModal.classList.add('hidden'); resultsModal.classList.remove('flex'); }

const DEFAULT_AVATAR='data:image/svg+xml;utf8,'+encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128"><rect width="100%" height="100%" rx="64" fill="#e5e7eb"/><text x="50%" y="54%" text-anchor="middle" font-family="Arial" font-size="52" fill="#9ca3af">?</text></svg>`);
function avatarUrl(row){
  const raw=(row && row.avatar_path ? String(row.avatar_path) : '').trim();
  if (!raw) return DEFAULT_AVATAR;
  if (raw.startsWith('http://')||raw.startsWith('https://')||raw.startsWith('data:')) return raw;
  if (raw.startsWith('/')) return raw;
  if (raw.startsWith('../')) return raw;
  return '../' + raw.replace(/^\.?\//,'');
}
function podiumCard(row, place){
  const ring = place===1 ? 'box-shadow:0 0 0 3px #facc15 inset' : place===2 ? 'box-shadow:0 0 0 3px #cbd5e1 inset' : 'box-shadow:0 0 0 3px #f59e0b inset';
  const pedestal = place===1 ? 'h-20 bg-yellow-300' : place===2 ? 'h-16 bg-gray-200' : 'h-14 bg-amber-200';
  if (!row || !row.fullname){
    return `<div style="opacity:.7"><div class="w-28 h-28 mx-auto rounded-full bg-gray-100"></div><div class="mt-2 mx-auto h-5 w-24 bg-gray-100 rounded"></div><div class="mt-2 mx-auto h-4 w-14 bg-gray-100 rounded"></div><div class="mx-auto mt-3 w-32 ${pedestal} rounded"></div></div>`;
  }
  return `<div><div class="w-28 h-28 mx-auto rounded-full overflow-hidden" style="${ring}"><img src="${avatarUrl(row)}" class="w-full h-full object-cover" alt=""/></div><div class="mt-2 font-bold text-gray-900">${row.fullname}</div><div class="text-sm text-gray-600">+${row.points} pts • ${row.time_ms} ms</div><div class="mx-auto mt-3 w-32 ${pedestal} rounded"></div></div>`;
}
function buildPodiumAndList(rows){
  const [first,second,third]=[rows[0]||{},rows[1]||{},rows[2]||{}];
  document.getElementById('podium1').innerHTML=podiumCard(first,1);
  document.getElementById('podium2').innerHTML=podiumCard(second,2);
  document.getElementById('podium3').innerHTML=podiumCard(third,3);
  const listBox=document.getElementById('top10List'); listBox.innerHTML='';
  rows.forEach((r,i)=>{
    const barPct = rows[0]?.points ? Math.max(5, Math.min(100, Math.round((r.points/rows[0].points)*100))) : 100;
    const item=document.createElement('div'); item.className='relative border rounded-xl p-3 shadow-sm bg-white';
    item.innerHTML=`
      <div class="absolute left-3 top-1/2 -translate-y-1/2 flex items-center gap-3">
        <div class="w-8 h-8 rounded-full bg-yellow-400 text-white font-extrabold flex items-center justify-center">${i+1}</div>
        <img src="${avatarUrl(r)}" class="w-8 h-8 rounded-full object-cover" alt=""/>
      </div>
      <div class="pl-[96px] pr-2">
        <div class="flex items-center justify-between">
          <div class="font-medium text-gray-900">${r.fullname}</div>
          <div class="text-sm text-gray-600">+${r.points} pts • ${r.time_ms} ms</div>
        </div>
        <div class="mt-2 h-2 bg-gray-100 rounded"><div class="h-2 rounded bg-emerald-500" style="width:${barPct}%"></div></div>
      </div>`;
    listBox.appendChild(item);
  });
}

/* ===== Quiz ===== */
const quizModal=document.getElementById('quizModal');
let QUIZ=null, QUIZ_SELECTED=null, QUIZ_TIMER=null, QUIZ_TIMELEFT=0, QUIZ_SUBMITTING=false;

function openQuiz(){
  if(!HAS_ACTIVE_QUIZ){ showToast('No active quiz right now.'); return; }
  fetch('../config/get_active_quiz.php')
    .then(r=>r.json())
    .then(d=>{
      if(!d.success){ showToast('❌ '+(d.message||'Error'), true); return; }
      if(!d.quiz){ showToast('No active quiz right now.'); return; }
      if(d.quiz.already_answered){ showToast('You already answered this quiz.'); return; }
      QUIZ=d.quiz; renderQuiz(QUIZ);
      quizModal.classList.remove('hidden'); quizModal.classList.add('flex');
      startQuizTimer(d.quiz.time_limit);
    })
    .catch(()=> showToast('❌ Network error', true));
}
function closeQuiz(){ stopQuizTimer(); quizModal.classList.add('hidden'); quizModal.classList.remove('flex'); QUIZ_SUBMITTING=false; const b=document.getElementById('quizSubmitBtn'); if(b) b.disabled=false; }
function renderQuiz(q){
  document.getElementById('quizTitle').textContent=q.title||'Quick Quiz';
  document.getElementById('quizQuestion').textContent=q.question;
  const box=document.getElementById('quizOptions'); box.innerHTML=''; QUIZ_SELECTED=null;
  const entries=Object.entries(q.options);
  for(let i=entries.length-1;i>0;i--){ const j=Math.floor(Math.random()*(i+1)); [entries[i],entries[j]]=[entries[j],entries[i]]; }
  entries.forEach(([k,v])=>{
    const btn=document.createElement('button');
    btn.className='w-full text-left border rounded-lg px-3 py-2 hover:bg-orange-50';
    btn.innerHTML=`<strong>${k}.</strong> ${v}`;
    btn.onclick=()=>{ QUIZ_SELECTED=k; Array.from(box.children).forEach(ch=>ch.classList.remove('ring-2','ring-orange-400','bg-orange-50')); btn.classList.add('ring-2','ring-orange-400','bg-orange-50'); document.getElementById('quizSubmitBtn').disabled=false; };
    box.appendChild(btn);
  });
  const submit=document.getElementById('quizSubmitBtn'); submit.disabled=true; submit.onclick=submitQuizAnswer;
}
function startQuizTimer(secs){
  QUIZ_TIMELEFT=secs; const el=document.getElementById('quizTimer'); el.textContent=QUIZ_TIMELEFT;
  stopQuizTimer();
  QUIZ_TIMER=setInterval(()=>{ QUIZ_TIMELEFT--; el.textContent=Math.max(0,QUIZ_TIMELEFT); if(QUIZ_TIMELEFT<=0){ stopQuizTimer(); document.getElementById('quizSubmitBtn').disabled=true; showToast('⏰ Time is up!', true); setTimeout(closeQuiz,800);} },1000);
}
function stopQuizTimer(){ if(QUIZ_TIMER){ clearInterval(QUIZ_TIMER); QUIZ_TIMER=null; } }
function submitQuizAnswer(){
  if(!QUIZ || !QUIZ_SELECTED || QUIZ_SUBMITTING) return;
  if(QUIZ_TIMELEFT<=0){ showToast('⏰ Time is up!', true); return; }
  QUIZ_SUBMITTING=true; document.getElementById('quizSubmitBtn').disabled=true;
  const body=new URLSearchParams({ question_id:String(QUIZ.question_id), chosen_opt:QUIZ_SELECTED });
  fetch('../config/submit_quiz_answer.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body })
    .then(r=>r.json())
    .then(d=>{
      if(!d.success){ showToast('❌ '+(d.message||'Error'), true); QUIZ_SUBMITTING=false; document.getElementById('quizSubmitBtn').disabled=false; return; }
      if(typeof d.points!=='undefined') showToast((d.correct?'🎉 Correct! +':'❌ Incorrect, +')+d.points+' pts', !d.correct);
      else showToast(d.correct?'🎉 Correct!':'❌ Incorrect', !d.correct);
      QUIZ_SUBMITTING=false; closeQuiz();
    })
    .catch(()=>{ showToast('❌ Network error', true); QUIZ_SUBMITTING=false; document.getElementById('quizSubmitBtn').disabled=false; });
}
</script>
</body>
</html>
