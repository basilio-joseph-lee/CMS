<?php
// quiz_play.php — Student Kiosk (Kahoot-like, auto-advance)
session_start();
if (!isset($_SESSION['student_id'])) { header("Location: ../student_login.php"); exit; }

$student_id     = intval($_SESSION['student_id']);
$subject_id     = intval($_SESSION['active_subject_id']     ?? $_SESSION['subject_id']     ?? 0);
$advisory_id    = intval($_SESSION['active_advisory_id']    ?? $_SESSION['advisory_id']    ?? 0);
$school_year_id = intval($_SESSION['active_school_year_id'] ?? $_SESSION['school_year_id'] ?? 0);

$subjectName = htmlspecialchars($_SESSION['subject_name'] ?? '');
$className   = htmlspecialchars($_SESSION['class_name'] ?? '');
$yearLabel   = htmlspecialchars($_SESSION['year_label'] ?? '');

if (!$subject_id || !$advisory_id || !$school_year_id) { die("Missing class context."); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Quiz Game • <?= $subjectName ?> — <?= $className ?></title>
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
  .ring-gold{box-shadow:0 0 0 4px #facc15 inset;} .ring-silver{box-shadow:0 0 0 4px #cbd5e1 inset;} .ring-bronze{box-shadow:0 0 0 4px #f59e0b inset;}
</style>
</head>
<body class="relative">

<div class="blob b1"></div><div class="blob b2"></div><div class="blob b3"></div>

<header class="max-w-5xl mx-auto px-4 pt-6">
  <div class="flex items-center justify-between">
    <div class="text-sm md:text-base text-gray-700">
      <div class="font-semibold">🎓 <?= $subjectName ?> — <?= $className ?></div>
      <div class="text-gray-500">SY <?= $yearLabel ?></div>
    </div>
    <a href="dashboard.php" class="inline-flex items-center gap-2 bg-white/80 hover:bg-white text-gray-700 border rounded-xl px-4 py-2 shadow">← Back to Dashboard</a>
  </div>
</header>

<main class="max-w-5xl mx-auto px-4 py-6">
  <!-- LOBBY -->
  <section id="lobby" class="rounded-3xl bg-white/80 backdrop-blur shadow-xl p-8 text-center hidden">
    <h1 class="text-3xl md:text-4xl font-extrabold">Waiting for the next quiz…</h1>
    <p class="mt-2 text-gray-600">Your teacher will start a round shortly.</p>
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
      <h3 class="text-xl md:text-2xl font-bold" id="qTitle">Quick Quiz <span id="roundLabelQ" class="text-gray-500 text-base ml-2"></span></h3>
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
  </section>

  <!-- RESULTS (not used in simple "publish-all" flow, but kept for compatibility) -->
  <section id="results" class="rounded-3xl bg-white/90 backdrop-blur shadow-xl p-8 hidden">
    <div class="flex items-center justify-between">
      <h3 class="text-2xl font-extrabold">Round Results</h3>
      <button class="text-sm underline text-gray-600" onclick="goLobby()">Back to Lobby</button>
    </div>
    <div class="grid grid-cols-3 gap-6 items-end mt-6">
      <div id="pod2" class="text-center opacity-80"></div>
      <div id="pod1" class="text-center"></div>
      <div id="pod3" class="text-center opacity-80"></div>
    </div>
    <h4 class="mt-8 text-lg font-semibold">Top 10</h4>
    <div id="top10" class="mt-3 space-y-3"></div>
  </section>
</main>

<audio id="sfxTick"><source src="https://assets.mixkit.co/active_storage/sfx/995/995-preview.mp3" type="audio/mpeg"></audio>
<audio id="sfxCorrect"><source src="https://assets.mixkit.co/active_storage/sfx/2018/2018-preview.mp3" type="audio/mpeg"></audio>
<audio id="sfxWrong"><source src="https://assets.mixkit.co/active_storage/sfx/103/103-preview.mp3" type="audio/mpeg"></audio>

<script>
/* ---------- State ---------- */
let STATE="LOBBY", ACTIVE=null, PICK=null, timerInt=null, timeLeft=0, polling=null, lastSeenQid=null;
const sTick = document.getElementById('sfxTick');
const sOk   = document.getElementById('sfxCorrect');
const sNg   = document.getElementById('sfxWrong');

const el   = (id)=>document.getElementById(id);
const show = (id)=>el(id).classList.remove('hidden');
const hide = (id)=>el(id).classList.add('hidden');

/* ---------- Screens ---------- */
function toLobby(){ STATE="LOBBY"; ['countdown','question','locked','results'].forEach(hide); show('lobby'); }
function toCountdown(n=3){
  STATE="COUNTDOWN"; ['lobby','question','locked','results'].forEach(hide); show('countdown');
  el('cdNum').textContent = n;
  const t = setInterval(()=>{
    n--; el('cdNum').textContent = n; sTick.currentTime=0; sTick.play().catch(()=>{});
    if(n<=0){ clearInterval(t); toQuestion(); }
  }, 900);
}
function toQuestion(){
  STATE="QUESTION"; ['lobby','countdown','locked','results'].forEach(hide); show('question');
  renderQuestion(ACTIVE);
  startTimer(ACTIVE.time_limit);
}
function toLocked(msg="Waiting for next question…"){
  STATE="LOCKED"; ['lobby','countdown','question','results'].forEach(hide); show('locked');
  el('lockedMsg').textContent = msg;
}
function toResults(payload){
  STATE="RESULTS"; ['lobby','countdown','question','locked'].forEach(hide); show('results');
  renderResults(payload||[]);
}

/* Lobby dot pulse */
setInterval(()=>{ const d=el('lobbyDot'); if(d){ d.textContent = (d.textContent==='•' ? '◦' : '•'); }}, 700);

/* ---------- Polling ---------- */
function startPolling(){ if(!polling) polling = setInterval(refreshState, 2000); }
function stopPolling(){ if(polling) { clearInterval(polling); polling=null; } }

async function refreshState(){
  try{
    const res = await fetch('../config/get_active_quiz.php', {cache:'no-store'});
    const d   = await res.json();
    if(!d.success) return;

    // No more questions → lobby
    if(!d.quiz){
      if(STATE!=='LOBBY') toLobby();
      return;
    }

    const q = d.quiz;

    // New question ID detected → move to it
    if(lastSeenQid !== q.question_id){
      ACTIVE = q; PICK=null; lastSeenQid = q.question_id;
      const tag = (q.session_index && q.session_total) ? `Round ${q.session_index} of ${q.session_total}` : '';
      const rl  = el('roundLabel'); const rlq = el('roundLabelQ');
      if(rl)  rl.textContent  = tag;
      if(rlq) rlq.textContent = tag;
      toCountdown(3);
      return;
    }
  }catch(e){ /* ignore */ }
}

/* ---------- Render Question ---------- */
function renderQuestion(q){
  el('qTitle').textContent = q.title || 'Quick Quiz';
  el('qText').textContent  = q.question || '';
  const box = el('qOptions'); box.innerHTML = '';
  Object.entries(q.options||{}).sort(()=>Math.random()-.5).forEach(([k,v])=>{
    const b = document.createElement('button');
    b.className = 'pop border rounded-2xl px-4 py-4 text-left bg-white hover:bg-amber-50 shadow';
    b.innerHTML = `<div class="text-sm text-gray-500 font-semibold">${k}</div><div class="text-lg font-bold">${v}</div>`;
    b.onclick = ()=>{
      PICK = k;
      [...box.children].forEach(ch=>ch.classList.remove('ring','ring-amber-400','bg-amber-50'));
      b.classList.add('ring','ring-amber-400','bg-amber-50');
      el('qSubmit').disabled = false;
    };
    box.appendChild(b);
  });
  el('qHint').textContent = 'Pick an option to lock in.';
  const submit = el('qSubmit'); submit.disabled = true; submit.onclick = submitAnswer;
}

/* ---------- Timer ---------- */
function startTimer(secs){
  timeLeft = secs|0; el('qTimer').textContent = timeLeft;
  if(timerInt) clearInterval(timerInt);
  timerInt = setInterval(()=>{
    timeLeft--; el('qTimer').textContent = Math.max(0, timeLeft);
    if(timeLeft<=0){ clearInterval(timerInt); el('qSubmit').disabled = true; toLocked('⏰ Time is up!'); }
  }, 1000);
}

/* ---------- Submit ---------- */
async function submitAnswer(){
  if(!ACTIVE || !PICK || timeLeft<=0) return;
  el('qSubmit').disabled = true;

  try{
    const body = new URLSearchParams({ question_id: String(ACTIVE.question_id), chosen_opt: PICK });
    const r = await fetch('../config/submit_quiz_answer.php', {
      method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body
    });
    const d = await r.json();
    if(!d.success){
      toLocked('Submission failed.'); 
      setTimeout(refreshState, 600);
      return;
    }

    if(d.correct){ sOk.currentTime=0; sOk.play().catch(()=>{}); burst(); }
    else { sNg.currentTime=0; sNg.play().catch(()=>{}); }

    const msg = d.correct ? `🎉 Correct! +${d.points||0} pts` : `❌ Incorrect, +${d.points||0} pts`;
    toLocked(msg);

    // Force a quick refresh so we jump to the next question right away
    setTimeout(refreshState, 700);
  }catch(e){
    toLocked('Network error.');
    setTimeout(refreshState, 1000);
  }
}

/* ---------- Confetti ---------- */
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

/* ---------- Results (kept for compatibility) ---------- */
function renderResults(rows){
  [rows[0],rows[1],rows[2]].forEach((r,i)=>el(['pod1','pod2','pod3'][i]).innerHTML=podiumCard(r,i+1));
  const list=el('top10'); list.innerHTML='';
  rows.forEach((r,i)=>{
    const pct = rows[0]?.points ? Math.max(5, Math.min(100, Math.round((r.points/rows[0].points)*100))) : 100;
    list.innerHTML += `
      <div class="relative border rounded-xl p-3 shadow-sm bg-white">
        <div class="absolute left-3 top-1/2 -translate-y-1/2 flex items-center gap-3">
          <div class="w-8 h-8 rounded-full bg-yellow-400 text-white font-extrabold flex items-center justify-center">${i+1}</div>
          <img src="${avatarUrl(r)}" class="w-8 h-8 rounded-full object-cover">
        </div>
        <div class="pl-[96px] pr-2">
          <div class="flex items-center justify-between">
            <div class="font-medium">${r.fullname||'—'}</div>
            <div class="text-sm text-gray-600">+${r.points||0} pts • ${r.time_ms||0} ms</div>
          </div>
          <div class="mt-2 h-2 bg-gray-100 rounded"><div class="h-2 rounded bg-emerald-500" style="width:${pct}%"></div></div>
        </div>
      </div>`;
  });
}
function podiumCard(row,place){
  const ring = place===1?'ring-gold':(place===2?'ring-silver':'ring-bronze');
  if(!row) return `<div><div class="w-28 h-28 mx-auto rounded-full bg-gray-100"></div></div>`;
  return `
    <div>
      <div class="w-28 h-28 mx-auto rounded-full overflow-hidden ${ring}">
        <img src="${avatarUrl(row)}" class="w-full h-full object-cover">
      </div>
      <div class="mt-2 font-bold">${row.fullname}</div>
      <div class="text-sm text-gray-600">+${row.points} pts • ${row.time_ms} ms</div>
    </div>`;
}
const DEFAULT_AVATAR='data:image/svg+xml;utf8,'+encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128"><rect width="100%" height="100%" rx="64" fill="#e5e7eb"/><text x="50%" y="54%" text-anchor="middle" font-family="Arial" font-size="52" fill="#9ca3af">?</text></svg>`);
function avatarUrl(r){
  const raw=(r?.avatar_path||'').trim();
  if(!raw) return DEFAULT_AVATAR;
  if(raw.startsWith('http')||raw.startsWith('data:')) return raw;
  if(raw.startsWith('/')) return raw;
  return '../'+raw.replace(/^\.?\//,'');
}

function goLobby(){ toLobby(); }

/* ---------- Init ---------- */
toLobby();
startPolling();
refreshState(); // kick an initial load
</script>
</body>
</html>
