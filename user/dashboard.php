<?php
/**
 * STUDENT DASHBOARD — primary student UI
 * Flow: face_login.php → select_subject.php → dashboard.php
 */

session_start();

if (!isset($_SESSION['student_id'])) { header("Location: ../index.php"); exit; }

$student_id     = (int)$_SESSION['student_id'];
$fullname       = $_SESSION['fullname'] ?? 'Student';
$subject_id     = (int)($_SESSION['subject_id'] ?? 0);
$advisory_id    = (int)($_SESSION['advisory_id'] ?? 0);
$school_year_id = (int)($_SESSION['school_year_id'] ?? 0);
$subject_name   = $_SESSION['subject_name'] ?? 'Subject';
$class_name     = $_SESSION['class_name'] ?? 'Section';
$year_label     = $_SESSION['year_label'] ?? 'SY';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Student Dashboard</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
  :root{
    /* keep palette in sync with simulator */
    --brand:#bc6c25;       /* title orange */
    --panel:#ffffff;
    --bg:#fefae0;
    --accent:#386641;      /* green from your sidebar */
  }
  body{ background:var(--bg); font-family:'Comic Sans MS', cursive, sans-serif; }

  /* Shell */
  .wrap{ max-width:900px; margin-inline:auto; }

  /* Glassy header */
  .hero{
    background:linear-gradient(180deg,#fff8 0,#fff0 100%), url('../img/bg-8.png') center/cover no-repeat;
    border-radius:1.25rem;
    box-shadow: inset 0 0 16px rgba(0,0,0,.08);
  }

  /* Cards */
  .card{
    background:var(--panel);
    border-radius:1.25rem;
    box-shadow:0 10px 30px rgba(0,0,0,.08);
  }

  /* Action tiles */
  .tile{
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    gap:.35rem; text-align:center; padding:1.1rem .9rem; border-radius:1rem;
    font-weight:800; box-shadow:0 6px 18px rgba(0,0,0,.06);
    transform:translateY(0); transition:transform .12s ease, box-shadow .12s ease, opacity .12s ease;
  }
  .tile:active{ transform:translateY(2px); box-shadow:0 4px 12px rgba(0,0,0,.08); }
  .tile[disabled]{ opacity:.6; pointer-events:none; }

  /* Colors per action */
  .t-yellow{ background:#fef08a; }
  .t-pink  { background:#fbcfe8; }
  .t-green { background:#86efac; }
  .t-blue  { background:#93c5fd; }
  .t-cyan  { background:#a5f3fc; }
  .t-rose  { background:#fecdd3; }

  .tile span.emoji{ font-size:1.9rem; line-height:1; }
  .tile .label{ font-size:.95rem; color:#1f2937; }

  /* Status chip */
  .chip{
    display:inline-flex; align-items:center; gap:.4rem;
    background:#ecfccb; color:#14532d; border:2px solid #16a34a;
    border-radius:9999px; padding:.35rem .7rem; font-weight:800;
  }
  .chip.bad{ background:#fee2e2; color:#7f1d1d; border-color:#ef4444; }

  /* Footer nav */
  .bar{
    position:sticky; bottom:0; left:0; right:0; z-index:10;
    background:#ffffffcc; backdrop-filter:blur(6px);
    border-top:1px solid #0000000f;
  }

  /* Toast */
  #toast{ display:none; }
</style>
</head>

<body class="min-h-screen p-4 md:p-6">
  <!-- Header -->
  <div class="wrap hero p-5 md:p-7 mb-5">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
      <div>
        <h1 class="text-3xl md:text-4xl font-black" style="color:var(--brand)">🏫 Student Dashboard</h1>
        <p class="text-sm text-gray-700 mt-1">
          <?= htmlspecialchars($class_name) ?> • <?= htmlspecialchars($subject_name) ?> • <?= htmlspecialchars($year_label) ?>
        </p>
        <!-- Logout Button -->
<div class="mt-3">
  <a
    href="../config/logout.php?role=student"
    class="inline-flex items-center justify-center rounded-xl bg-orange-500 px-4 py-2 text-sm sm:text-base font-semibold shadow-md hover:bg-orange-600 focus:outline-none focus:ring-2 focus:ring-orange-300 transition"
  >
    🚪 Logout
  </a>
  <!-- <button id="btnRelog"
    class="inline-flex items-center justify-center rounded-xl bg-green-600 px-4 py-2 text-sm sm:text-base font-semibold shadow-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-300 transition">
    👤 Re-log (Face)
  </button> -->
  <!-- NEW: read-only viewer -->
  <a
    href="classroom_viewer.php"
    class="inline-flex items-center justify-center rounded-xl bg-blue-500 px-4 py-2 text-sm sm:text-base font-semibold shadow-md hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-300 transition"
    style="margin-left:.25rem"
  >
    👀 View Classroom
  </a>
</div>
      </div>
      <div class="card px-4 py-3">
        <div class="text-xs text-gray-500 font-bold uppercase">Current status</div>
        <div class="mt-1">
          <span id="statusChip" class="chip" aria-live="polite">Loading…</span>
        </div>
        <div id="lastTime" class="text-xs text-gray-500 mt-1">—</div>
      </div>
    </div>
  </div>

  <!-- Actions -->
  <div class="wrap grid grid-cols-2 sm:grid-cols-3 md:grid-cols-7 gap-3 md:gap-4 mb-6">
    <!-- Attendance tile -->
    <button class="tile t-green" data-action="attendance" aria-label="Mark Attendance">
      <span class="emoji">✅</span><div class="label">Attendance</div>
    </button>

    <button class="tile t-yellow" data-action="restroom"  aria-label="Restroom">
      <span class="emoji">🚻</span><div class="label">Restroom</div>
    </button>
    <button class="tile t-pink"   data-action="snack"     aria-label="Snack">
      <span class="emoji">🍎</span><div class="label">Snack</div>
    </button>
    <button class="tile t-rose" id="btnAskOut" aria-label="Ask Out Time">
      <span class="emoji">🚪</span><div class="label">Out Time</div>
    </button>

    <button class="tile t-cyan"   data-action="water_break" aria-label="Water Break">
      <span class="emoji">💧</span><div class="label">Water</div>
    </button>
    <!-- <button class="tile t-rose"   data-action="not_well"  aria-label="Not Feeling Well">
      <span class="emoji">🤒</span><div class="label">Not Well</div>
    </button> -->
    <button class="tile t-green"  data-action="lunch_break" aria-label="Lunch Break">
      <span class="emoji">🍱</span><div class="label">Lunch</div>
    </button>
    <button class="tile t-blue"   data-action="im_back"   aria-label="I’m Back">
      <span class="emoji">🟢</span><div class="label">I’m Back</div>
    </button>
  </div>

  <!-- Status / tips -->
  <div class="wrap grid md:grid-cols-2 gap-4">
    <div class="card p-5">
      <div class="text-xl font-black mb-2">🧾 Details</div>
      <div id="statusText" class="text-gray-700">Loading…</div>
    </div>

    <div class="card p-5">
      <div class="text-xl font-black mb-2">💡 Tips</div>
      <ul class="list-disc pl-5 text-gray-700 space-y-1">
        <li>Tap an action when you leave (restroom, water, lunch).</li>
        <li>Tap <b>I’m Back</b> as soon as you return.</li>
        <li>Your teacher sees your status instantly.</li>
      </ul>
      <div class="mt-4">
        <button onclick="location.href='select_subject.php'" class="underline text-gray-600 text-sm">← Back to Subjects</button>
      </div>
    </div>
  </div>

  <!-- Bottom bar: quick “I’m Back” -->
  <div class="bar mt-6">
    <div class="wrap flex items-center justify-between px-4 py-3">
      <div class="text-sm text-gray-700">Need to clear your status?</div>
      <button id="quickBack" class="tile t-blue !py-2 !px-3 !rounded-full !flex-row !gap-2">
        <span class="emoji">🟢</span><span class="label font-black">I’m Back</span>
      </button>
    </div>
  </div>

  <!-- Toast -->
  <div id="toast" class="fixed top-5 right-5 px-4 py-3 rounded-xl shadow text-white font-bold"></div>

<script>
const student_id = <?= $student_id ?>;
const statusChip = document.getElementById('statusChip');
const statusText = document.getElementById('statusText');
const lastTime   = document.getElementById('lastTime');
const toastBox   = document.getElementById('toast');
const ACTION_LABELS = {
  restroom:'Restroom', snack:'Snack', lunch_break:'Lunch Break',
  water_break:'Water Break', not_well:'Not Feeling Well',
  im_back:'I’m Back', attendance:'Attendance', out_time:'Out Time'
};

function toast(msg,type='ok'){
  toastBox.textContent = msg;
  toastBox.style.background = type==='err' ? '#ef4444' : '#16a34a';
  toastBox.style.display = 'block';
  setTimeout(()=>toastBox.style.display='none', 1800);
}

function setBusy(b){
  document.querySelectorAll('[data-action]').forEach(btn=>{
    if(b){ btn.setAttribute('disabled',''); btn.ariaBusy='true'; }
    else { btn.removeAttribute('disabled'); btn.ariaBusy='false'; }
  });
  document.getElementById('quickBack').toggleAttribute('disabled', b);
}

function fmtTime(ts){
  if(!ts) return '—';
  const d = new Date(ts.replace(' ', 'T')); // handle "YYYY-MM-DD HH:MM:SS"
  if(Number.isNaN(+d)) return '—';
  return d.toLocaleString();
}

function paintStatus(action, ts){
  if(!action){
    statusChip.classList.remove('bad');
    statusChip.textContent = '✅ Present';
    statusText.textContent = 'You are marked as present with no ongoing action.';
  }else{
    const label = ACTION_LABELS[action] || action.replace('_',' ');
    const isBack = action==='im_back' || action==='attendance';
    statusChip.classList.toggle('bad', !isBack);
    statusChip.textContent = (isBack ? '✅ Present' : `📍 ${label}`);
    statusText.textContent = isBack
      ? 'Status cleared. Welcome back!'
      : `You are currently on: ${label}. Tap “I’m Back” once you return.`;
  }
  lastTime.textContent = ts ? `Last update: ${fmtTime(ts)}` : '—';
}

const btnAskOut = document.getElementById('btnAskOut');
let myReqPoll = null;

async function askOutTime(){
  try{
    setBusy(true);
    const r = await fetch('../api/out_time_request.php', {method:'POST',credentials:'include'});
    const j = await r.json();
    if(!j.ok){ toast(j.message || 'Request failed','err'); return; }
    toast(j.dup ? 'Already pending approval' : 'Request sent to teacher');

    statusChip.classList.add('bad');
    statusChip.textContent = '⏳ Waiting for teacher approval';
    statusText.textContent  = 'Your Out Time request is pending.';

    if(myReqPoll) clearInterval(myReqPoll);
    myReqPoll = setInterval(loadStatus, 3000);
  }catch(e){
    toast('Network error','err');
  }finally{
    setBusy(false);
  }
}

btnAskOut.addEventListener('click', askOutTime);

async function loadStatus(){
  try{
    const res = await fetch('../api/get_behavior_status.php', { cache:'no-store', credentials:'include' });
    if (!res.ok) throw new Error('HTTP '+res.status);
    const data = await res.json();

    let my = null;
    if(Array.isArray(data)){
      my = data.find(r=>String(r.student_id)===String(student_id));
    }else if(data && data.map){
      my = data.map[String(student_id)];
    }
    paintStatus(my?.action || '', my?.timestamp || '');
  }catch(e){
    statusChip.classList.add('bad');
    statusChip.textContent = 'Offline';
    statusText.textContent = '⚠️ Cannot reach server. Your actions will try again.';
  }
}

async function logAction(action_type){
  setBusy(true);
  paintStatus(action_type, new Date().toISOString());
  try{
    const resp = await fetch('../api/log_behavior.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      credentials:'include',
      body: JSON.stringify({ student_id, action_type })
    });
    if (!resp.ok) {
      const txt = await resp.text();
      throw new Error('HTTP '+resp.status+' — '+txt);
    }
    const r = await resp.json();
    toast(r?.message || 'Saved');
    await loadStatus();
  }catch(e){
    toast('Network error','err');
  }finally{
    setBusy(false);
  }
}

document.querySelectorAll('[data-action]').forEach(btn=>{
  btn.addEventListener('click', ()=> logAction(btn.dataset.action));
});
document.getElementById('quickBack').addEventListener('click', ()=> logAction('im_back'));

loadStatus();
setInterval(loadStatus, 4000);

// --- Auto mark attendance on dashboard load ---
async function markAttendanceOnLoad(){
  try {
    // 1️⃣ Log behavior
    await fetch('../api/log_behavior.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      credentials: 'include',
      body: JSON.stringify({ student_id, action_type:'attendance' })
    });

    // 2️⃣ Mark official attendance record
    await fetch('../api/mark_attendance.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      credentials: 'include',
      body: JSON.stringify({
        student_id: student_id,
        status: 'Present'
      })
    });
  } catch(e){
    console.warn('Could not mark attendance:', e);
  }
}

window.addEventListener('DOMContentLoaded', markAttendanceOnLoad);
</script>
</body>
</html>
