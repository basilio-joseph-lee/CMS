<?php
// CMS/user/student/join_quiz.php  (name-based; waits for ONGOING → shows leaderboard after ENDED)

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$code = strtoupper(trim($_GET['code'] ?? ''));

$conn = new mysqli("localhost","root","","cms");
$conn->set_charset('utf8mb4');

// Look up session by code
$session_id = 0;
$status     = '';
$title      = 'Quick Quiz';

if ($code !== '') {
  $stmt = $conn->prepare("
    SELECT session_id, title, status
    FROM kiosk_quiz_sessions
    WHERE session_code=?
    LIMIT 1
  ");
  $stmt->bind_param('s', $code);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($r = $res->fetch_assoc()) {
    $session_id = (int)$r['session_id'];
    $title      = $r['title'] ?: $title;
    $status     = $r['status'];
  }
}

if (!$session_id) {
  http_response_code(404);
  echo "Invalid or expired session code.";
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Join Quiz • <?= htmlspecialchars($title) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
  body{
    background: radial-gradient(1200px 600px at 10% 10%, #fff7ed 0%, transparent 40%),
                radial-gradient(1200px 600px at 90% 20%, #f0f9ff 0%, transparent 45%),
                linear-gradient(120deg, #fef3c7, #e0f2fe 40%, #fae8ff 80%);
    min-height:100vh; overflow-x:hidden;
  }
  .blob{position:absolute;border-radius:50%;filter:blur(30px);opacity:.6;pointer-events:none;animation:floaty 12s ease-in-out infinite;}
  .blob.b1{width:260px;height:260px;top:8%;left:6%;background:#fde68a;animation-delay:.2s;}
  .blob.b2{width:280px;height:280px;top:12%;right:8%;background:#bfdbfe;animation-delay:.8s;}
  .blob.b3{width:240px;height:240px;bottom:6%;left:10%;background:#fbcfe8;animation-delay:1.2s;}
  @keyframes floaty{0%,100%{transform:translateY(0);}50%{transform:translateY(-16px);} }
  @keyframes pop{0%{transform:scale(.98)}40%{transform:scale(1.02)}100%{transform:scale(1)}}
  .pop{animation:pop .35s ease;}
  .ring{width:100px;height:100px;border-radius:9999px;display:grid;place-items:center;
    box-shadow:0 0 0 6px rgba(234,179,8,.35) inset,0 8px 20px rgba(0,0,0,.15);background:white;}
  .burst{position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);font-size:24px;pointer-events:none;z-index:80;animation:burstUp .9s ease-out forwards;}
  @keyframes burstUp{0%{opacity:0;transform:translate(-50%,-50%) scale(.6);}30%{opacity:1;transform:translate(-50%,-50%) scale(1.1);}100%{opacity:0;transform:translate(-50%,-110%) scale(.9);} }
  .modal{position:fixed;inset:0;background:rgba(15,23,42,.5);display:none;align-items:center;justify-content:center;padding:16px;z-index:90}
  .modal .box{background:#fff;max-width:420px;width:100%;border-radius:16px;overflow:hidden;border:1px solid #e5e7eb}
  .modal .head{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid #e5e7eb}
  .modal .body{padding:16px}
</style>
</head>
<body class="relative">
<div class="blob b1"></div><div class="blob b2"></div><div class="blob b3"></div>

<header class="max-w-5xl mx-auto px-4 pt-6">
  <div class="flex items-center justify-between">
    <div class="text-sm md:text-base text-gray-700">
      <div class="font-semibold">🎓 <?= htmlspecialchars($title) ?></div>
      <div class="text-gray-500">Code: <?= htmlspecialchars($code) ?></div>
    </div>
    <a href="javascript:history.back()" class="inline-flex items-center gap-2 bg-white/80 hover:bg-white text-gray-700 border rounded-xl px-4 py-2 shadow">← Back</a>
  </div>
</header>

<main class="max-w-5xl mx-auto px-4 py-6">
  <!-- LOBBY -->
  <section id="lobby" class="rounded-3xl bg-white/80 backdrop-blur shadow-xl p-0 pb-6 text-center">
    <div class="p-8">
      <h1 class="text-3xl md:text-4xl font-extrabold">Waiting for the teacher to start…</h1>
      <p class="mt-2 text-gray-600">Session Code: <b><?= htmlspecialchars($code) ?></b></p>

      <div class="mt-6 flex items-center justify-center gap-6">
        <div class="ring"><div class="text-xl font-bold" id="lobbyDot">•</div></div>
        <div class="text-left">
          <div class="font-semibold">Tips</div>
          <ul class="text-sm text-gray-600 list-disc ml-5">
            <li>Keep this page open.</li>
            <li>Answer fast for more points.</li>
          </ul>
        </div>
      </div>
    </div>

    <!-- Live Players -->
    <div class="border-t border-slate-200 px-6 md:px-10">
      <div class="flex items-center justify-between py-3">
        <div class="font-semibold text-left">Players in lobby</div>
        <div class="text-sm text-gray-500"><span id="pCount">0</span> joined</div>
      </div>
      <div id="pList" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3 text-left pb-4"></div>
    </div>
  </section>

  <!-- COUNTDOWN -->
  <section id="countdown" class="rounded-3xl bg-amber-50 border border-amber-200 shadow-xl p-8 text-center hidden">
    <h2 class="text-2xl md:text-3xl font-bold text-amber-900">
      Get ready! <span id="roundLabel" class="text-amber-700 text-base ml-2"></span>
    </h2>
    <div class="mt-4 flex items-center justify-center gap-6">
      <div class="ring"><div class="text-3xl font-extrabold" id="cdNum">3</div></div>
      <div class="text-left text-amber-800"><div class="font-semibold">Round starting…</div></div>
    </div>
  </section>

  <!-- QUESTION -->
  <section id="question" class="rounded-3xl bg-white/90 backdrop-blur shadow-xl p-8 hidden">
    <div class="flex items-center justify-between gap-4">
      <h3 class="text-xl md:text-2xl font-bold" id="qTitle"><?= htmlspecialchars($title) ?> <span id="roundLabelQ" class="text-gray-500 text-base ml-2"></span></h3>
      <div class="ring"><div id="qTimer" class="text-xl font-extrabold">—</div></div>
    </div>
    <p class="mt-4 text-gray-800 text-lg" id="qText">Loading…</p>
    <div id="qOptions" class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-4"></div>
    <div class="mt-6 flex items-center justify-between">
      <div id="qHint" class="text-sm text-gray-600">Pick an option.</div>
      <button id="qSubmit" class="bg-blue-600 text-white font-semibold px-5 py-2 rounded-xl shadow disabled:opacity-50" disabled>Submit</button>
    </div>
  </section>

  <!-- LOCKED -->
  <section id="locked" class="rounded-3xl bg-green-50 border border-green-200 shadow-xl p-8 text-center hidden">
    <h3 class="text-2xl font-bold" id="lockedTitle">Answer submitted!</h3>
    <p class="text-gray-700 mt-2" id="lockedMsg">Waiting for next question…</p>

    <!-- NEW: Live standings after each submission -->
    <div class="mt-6 text-left">
      <div class="font-semibold">Live standings</div>
      <div id="miniLb" class="mt-2 space-y-2"></div>
    </div>
  </section>

  <!-- COMPLETED (no button now) -->
  <section id="completed" class="rounded-3xl bg-white/90 backdrop-blur shadow-xl p-8 hidden">
    <div class="text-center">
      <h2 class="text-3xl md:text-4xl font-extrabold">✅ Successfully completed the quiz</h2>
      <p class="text-gray-600 mt-1">Please keep this page open while waiting for your teacher to end the session.</p>
    </div>
  </section>

  <!-- ENDED + LEADERBOARD -->
  <section id="ended" class="rounded-3xl bg-white/90 backdrop-blur shadow-xl p-8 hidden">
    <div class="text-center">
      <h2 class="text-3xl md:text-4xl font-extrabold">Session closed</h2>
      <p class="text-gray-600 mt-1">Your teacher ended the quiz.</p>
    </div>

    <!-- Podium -->
    <div id="podium" class="mt-8 grid grid-cols-3 gap-4 items-end"></div>

    <!-- Top list -->
    <div class="mt-8">
      <h3 class="text-xl font-bold">Leaderboard</h3>
      <div id="lbList" class="mt-3 space-y-2"></div>
    </div>
  </section>

  <!-- RESULTS (optional list mode reused) -->
  <section id="results" class="rounded-3xl bg-white/90 backdrop-blur shadow-xl p-8 hidden">
    <div class="flex items-center justify-between">
      <h3 class="text-2xl font-extrabold">Quiz Questions</h3>
      <button class="text-sm underline text-gray-600" onclick="toLobby()">Back to Lobby</button>
    </div>
    <div id="top10" class="mt-3 space-y-3"></div>
  </section>
</main>

<!-- NAME MODAL -->
<div id="nameModal" class="modal" role="dialog" aria-modal="true">
  <div class="box">
    <div class="head">
      <div><b>Enter your Name</b></div>
    </div>
    <div class="body">
      <label class="block text-sm font-semibold mb-1">Name</label>
      <input id="nameInput" class="w-full border rounded-xl px-3 py-2" placeholder="e.g., Juan Dela Cruz">
      <div class="text-xs text-gray-500 mt-2">This name will appear in results.</div>
      <div class="mt-4 flex justify-end gap-2">
        <button id="saveName" class="px-4 py-2 rounded-lg bg-blue-600 text-white">Continue</button>
      </div>
    </div>
  </div>
</div>

<audio id="sfxTick"><source src="https://assets.mixkit.co/active_storage/sfx/995/995-preview.mp3" type="audio/mpeg"></audio>
<audio id="sfxCorrect"><source src="https://assets.mixkit.co/active_storage/sfx/2018/2018-preview.mp3" type="audio/mpeg"></audio>
<audio id="sfxWrong"><source src="https://assets.mixkit.co/active_storage/sfx/103/103-preview.mp3" type="audio/mpeg"></audio>

<script>
const SESSION_ID = <?= (int)$session_id ?>;
let STATE="LOBBY", ACTIVE=null, PICK=null, timerInt=null, timeLeft=0, polling=null, lobbyPoll=null, lastSeenQid=null, FREEZE=false;

const sTick = document.getElementById('sfxTick');
const sOk   = document.getElementById('sfxCorrect');
const sNg   = document.getElementById('sfxWrong');

const el   = (id)=>document.getElementById(id);
const show = (id)=>el(id).classList.remove('hidden');
const hide = (id)=>el(id).classList.add('hidden');

function playerName(){
  const k = 'kiosk_player_name';
  let n = (localStorage.getItem(k)||'').trim();
  if (!n){
    el('nameModal').style.display='flex';
    el('nameInput').value = '';
    setTimeout(()=>el('nameInput').focus(), 50);
    el('saveName').onclick = ()=>{
      let v = (el('nameInput').value||'').trim();
      if(!v){ alert('Please enter your name'); return; }
      localStorage.setItem(k, v);
      el('nameModal').style.display='none';
      startLobby();
    };
  }
  return n;
}
function getName(){ return (localStorage.getItem('kiosk_player_name')||'').trim(); }

function toLobby(){ STATE="LOBBY"; ['countdown','question','locked','results','ended','completed'].forEach(hide); show('lobby'); }
function toCountdown(n=3){
  STATE="COUNTDOWN"; ['lobby','question','locked','results','ended','completed'].forEach(hide); show('countdown');
  el('cdNum').textContent = n;
  const t = setInterval(()=>{ n--; el('cdNum').textContent = n; sTick.currentTime=0; sTick.play().catch(()=>{}); if(n<=0){ clearInterval(t); toQuestion(); } }, 900);
}
function toQuestion(){
  STATE="QUESTION"; ['lobby','countdown','locked','results','ended','completed'].forEach(hide); show('question');
  renderQuestion(ACTIVE); startTimer(ACTIVE.time_limit || 30);
}
function toLocked(msg="Waiting for next question…"){
  STATE="LOCKED"; ['lobby','countdown','question','results','ended','completed'].forEach(hide); show('locked'); el('lockedMsg').textContent = msg;
}
function toCompleted(){
  STATE="COMPLETED"; ['lobby','countdown','question','locked','results','ended'].forEach(hide); show('completed');
}
function toEnded(){
  STATE="ENDED"; ['lobby','countdown','question','locked','results','completed'].forEach(hide); show('ended');
}

setInterval(()=>{ const d=el('lobbyDot'); if(d){ d.textContent = (d.textContent==='•' ? '◦' : '•'); }}, 700);

// ---------- LOBBY ----------
function startLobby(){
  toLobby();
  if (lobbyPoll) clearInterval(lobbyPoll);
  lobbyPing(); // immediate
  lobbyPoll = setInterval(lobbyPing, 2000);
}
async function lobbyPing(){
  const name = getName(); if(!name){ playerName(); return; }
  try{
    const r = await fetch(`../config/lobby_status.php?session_id=${encodeURIComponent(SESSION_ID)}&name=${encodeURIComponent(name)}`, {cache:'no-store'});
    const d = await r.json();
    if(!d.success) return;
    const ST = String(d.session_status || '').toLowerCase();

    // list
    const list = Array.isArray(d.players) ? d.players : [];
    el('pCount').textContent = d.count || list.length || 0;
    const wrap = el('pList'); wrap.innerHTML = '';
    list.forEach(p=>{
      const div = document.createElement('div');
      div.className = 'border rounded-xl px-3 py-2 bg-white shadow-sm';
      div.textContent = p.name;
      wrap.appendChild(div);
    });

    if (ST === 'ongoing') {
      clearInterval(lobbyPoll); lobbyPoll = null;
      refreshState(); startPolling();
    }
    if (ST === 'ended' || ST === 'closed' || ST === 'finished') {
      clearInterval(lobbyPoll); lobbyPoll = null;
      await loadLeaderboard(); toEnded();
      return;
    }
  }catch(e){}
}

// ---------- QUIZ FLOW ----------
function startPolling(){ if(!polling) polling = setInterval(refreshState, 1500); }
function stopPolling(){ if(polling) { clearInterval(polling); polling=null; } }

async function refreshState(){
  if (FREEZE) return;
  const name = getName(); if(!name){ playerName(); return; }
  try{
    const res = await fetch(`../config/get_active_quiz.php?session_id=${encodeURIComponent(SESSION_ID)}&player=${encodeURIComponent(name)}`, {cache:'no-store'});
    const d   = await res.json();
    if(!d.success) return;
    const ST = String(d.session_status || '').toLowerCase();

    if (ST === 'ended' || ST === 'closed') {
      stopPolling();
      await loadLeaderboard();
      toEnded();
      return;
    }

    if (ST !== 'ongoing') { // draft/active (not started)
      stopPolling(); startLobby(); return;
    }

    // If session is ongoing but there is no active quiz for this player,
    // show Completed (not Lobby).
    if(!d.quiz){
      toCompleted();
      return;
    }

    const q = d.quiz;
    if(lastSeenQid !== q.question_id){
      ACTIVE = q; PICK=null; lastSeenQid = q.question_id;
      const tag = (q.session_index && q.session_total) ? `Round ${q.session_index} of ${q.session_total}` : '';
      const rl  = el('roundLabel'); const rlq = el('roundLabelQ');
      if(rl)  rl.textContent  = tag;
      if(rlq) rlq.textContent = tag;
      toCountdown(3);
    }
  }catch(e){}
}

function renderQuestion(q){
  el('qTitle').textContent = q.title || 'Quick Quiz';
  el('qText').textContent  = q.question || '';
  const box = el('qOptions'); box.innerHTML = '';
  Object.entries(q.options||{}).forEach(([k,v])=>{
    if(v==null || v==='') return;
    const b = document.createElement('button');
    b.className = 'pop border rounded-2xl px-4 py-4 text-left bg-white hover:bg-amber-50 shadow';
    b.innerHTML = `<div class="text-sm text-gray-500 font-semibold">${k}</div><div class="text-lg font-bold">${v}</div>`;
    b.onclick = ()=>{ PICK = k; [...box.children].forEach(ch=>ch.classList.remove('ring','ring-amber-400','bg-amber-50')); b.classList.add('ring','ring-amber-400','bg-amber-50'); el('qSubmit').disabled = false; };
    box.appendChild(b);
  });
  el('qHint').textContent = 'Pick an option to lock in.';
  const submit = el('qSubmit'); submit.disabled = true; submit.onclick = submitAnswer;
}

function startTimer(secs){
  timeLeft = secs|0; el('qTimer').textContent = timeLeft;
  if(timerInt) clearInterval(timerInt);
  timerInt = setInterval(()=>{ timeLeft--; el('qTimer').textContent = Math.max(0, timeLeft); if(timeLeft<=0){ clearInterval(timerInt); el('qSubmit').disabled = true; toLocked('⏰ Time is up!'); } }, 1000);
}

async function submitAnswer(){
  const name = getName(); if(!name){ playerName(); return; }
  if(!ACTIVE || !PICK || timeLeft<=0) return;

  try{
    el('qSubmit').disabled = true;
    const body = new URLSearchParams({ question_id: String(ACTIVE.question_id), chosen_opt: PICK, name });
    const r = await fetch('../config/submit_quiz_answer.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body });
    const d = await r.json();

    if(!d.success){
      if ((d.message||'').toLowerCase().includes('closed')) {
        await loadLeaderboard(); toEnded(); return;
      }
      toLocked('Submission failed.'); setTimeout(refreshState, 700); return;
    }

    if(d.correct){ sOk.currentTime=0; sOk.play().catch(()=>{}); burst(); }
    else { sNg.currentTime=0; sNg.play().catch(()=>{}); }

    const msg = d.correct ? `🎉 Correct! +${d.points||0} pts` : `❌ Incorrect, +${d.points||0} pts`;
    toLocked(msg);

    // NEW: load mini leaderboard right after submitting
    loadMiniLeaderboard();

    FREEZE = true; stopPolling();
    setTimeout(async () => {
      await refreshState();
      FREEZE = false; startPolling();
    }, 900);

  }catch(e){
    toLocked('Network error.'); setTimeout(refreshState, 1000);
  }
}

// ---------- Leaderboards ----------
async function loadLeaderboard(){
  try{
    const r = await fetch(`../config/get_leaderboard.php?session_id=${encodeURIComponent(SESSION_ID)}`, {cache:'no-store'});
    const d = await r.json();
    if(!d.success) return;

    renderPodium(d.players||[], d.total_questions||0);
    renderList(d.players||[], d.total_questions||0);
  }catch(e){}
}

async function loadMiniLeaderboard(){
  try{
    const r = await fetch(`../config/get_leaderboard.php?session_id=${encodeURIComponent(SESSION_ID)}`, {cache:'no-store'});
    const d = await r.json();
    if(!d.success) return;
    renderMiniList(d.players||[]);
  }catch(e){}
}

// Podium: columns [2nd][1st][3rd] with #1 tallest in the center
function renderPodium(players, total){
  const top = players.slice(0,3);
  const order  = [1,0,2]; // pick from players: index of 2nd, 1st, 3rd
  const labels = [2,1,3]; // text on each column
  const wrap = el('podium'); wrap.innerHTML = '';
  order.forEach((srcIdx, pos)=>{
    const p = top[srcIdx] || null;
    const height = (pos===1 ? 'h-40' : (pos===0 ? 'h-32' : 'h-28'));
    wrap.insertAdjacentHTML('beforeend', `
      <div class="text-center">
        <div class="rounded-2xl bg-gradient-to-b from-indigo-400 to-indigo-600 text-white ${height} flex items-end justify-center shadow-lg">
          <div class="pb-3"><div class="text-3xl font-extrabold">${labels[pos]}</div></div>
        </div>
        <div class="mt-2 font-bold">${p ? escapeHtml(p.name) : '—'}</div>
        <div class="text-sm text-gray-600">${p ? (p.points||0) : 0} pts</div>
      </div>
    `);
  });
}

// Top list
function renderList(players, total){
  const list = players.slice(0,10);
  const box = el('lbList'); box.innerHTML = '';
  list.forEach(p=>{
    const rank = p.rank || 0;
    box.insertAdjacentHTML('beforeend', `
      <div class="flex items-center justify-between border rounded-xl bg-white px-3 py-2 shadow-sm">
        <div class="flex items-center gap-3">
          <div class="w-7 h-7 rounded-full bg-indigo-600 text-white grid place-items-center font-bold">${rank}</div>
          <div class="font-semibold">${escapeHtml(p.name)}</div>
        </div>
        <div class="text-sm text-gray-600">${p.points||0} pts</div>
      </div>
    `);
  });
}

// Mini list under LOCKED (compact)
function renderMiniList(players){
  const list = players.slice(0,10);
  const box = el('miniLb'); if(!box) return;
  box.innerHTML = '';
  list.forEach(p=>{
    const rank = p.rank || 0;
    box.insertAdjacentHTML('beforeend', `
      <div class="flex items-center justify-between border rounded-xl bg-white px-3 py-2 shadow-sm">
        <div class="flex items-center gap-3">
          <div class="w-7 h-7 rounded-full bg-indigo-600 text-white grid place-items-center font-bold">${rank}</div>
          <div class="font-semibold">${escapeHtml(p.name)}</div>
        </div>
        <div class="font-bold">${p.points||0} pts</div>
      </div>
    `);
  });
}

function escapeHtml(s){ return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

// ---------- FX ----------
function burst(){
  for(let i=0;i<16;i++){
    const x = document.createElement('div');
    x.className = 'burst';
    x.style.left = (50 + (Math.random()*40-20)) + '%';
    x.style.top  = (50 + (Math.random()*30-15)) + '%';
    x.style.fontSize = (18 + Math.random()*14) + 'px';
    x.textContent = (Math.random()<.5) ? '🎉' : '✨';
    document.body.appendChild(x);
    setTimeout(()=>x.remove(), 900);
  }
}

// boot
if (!playerName()) { /* modal shows, lobby begins after save */ } else { startLobby(); }
</script>
</body>
</html>
