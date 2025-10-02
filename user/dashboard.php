<?php
/* dashboard.php — STUDENT KIOSK
 * Flow: face_login.php -> select_subject.php -> dashboard.php
 * - Removed: Camera auto-attendance (no scanning, no image capture)
 * - Quick actions (restroom/snack/etc.) post to ../config/log_behavior.php
 * - Quiz Game + View Results included
 */
session_start();

/* ---------- Guards: require STUDENT ---------- */
$student_id = $_SESSION['student_id'] ?? null;
if (!$student_id) {
  header("Location: ../index.php"); exit;
}

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
  $conn = @new mysqli("localhost", "root", "", "cms");
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
    $conn->close();
  }
}
$_SESSION['subject_name'] = $subjectName;
$_SESSION['class_name']   = $className;
$_SESSION['year_label']   = $yearLabel;

/* ---------- Announcements + Quiz results (for View Results) ---------- */
$teacher_announcements = [];
$hasResultsToday = false;
$hasActiveQuiz   = false;
$roundRows       = [];

if ($subject_id && $advisory_id && $school_year_id) {
  $conn = @new mysqli("localhost", "root", "", "cms");
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

    $conn->close();
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
  .banner{ width:100%; text-align:center; padding:.75rem; font-weight:600; border-radius:.75rem; }
  @keyframes fadeOut{ to{opacity:0; transform:translateY(20px);} }
  .fade-out{ animation: fadeOut 1s forwards; }
  @keyframes glow { 0%{box-shadow:0 0 0 0 rgba(250,204,21,.8);} 70%{box-shadow:0 0 0 16px rgba(250,204,21,0);} 100%{box-shadow:0 0 0 0 rgba(250,204,21,0);} }
  .quiz-glow{ animation: glow 1.6s infinite; }
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

  <!-- Announcement toast if new_login -->
  <?php if (!empty($teacher_announcements) && isset($_GET['new_login'])): ?>
    <div id="announcement-toast" class="fixed top-6 left-1/2 -translate-x-1/2 bg-yellow-100 border border-yellow-400 text-gray-900 px-6 py-4 rounded-xl shadow-xl z-50">
      <strong class="block mb-1">📢 Announcement</strong>
      <p class="text-sm"><?= htmlspecialchars($teacher_announcements[0]['title']) ?>: <?= htmlspecialchars($teacher_announcements[0]['message']) ?></p>
    </div>
    <script>
      setTimeout(()=>{ const t=document.getElementById('announcement-toast'); if(t){ t.classList.add('fade-out'); setTimeout(()=>t.remove(),1000);} }, 5000);
    </script>
  <?php endif; ?>

  <!-- Removed: Attendance Mode banner and scan status -->

  <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-3">
    <!-- Left area (Previously camera & feed) -->
    <section class="md:col-span-2 bg-slate-900 rounded-2xl p-4">
      <!-- Removed camera & overlay -->
      <div>
        <h3 class="mt-1 mb-2 text-sm text-slate-400">Recent</h3>
        <ul id="feed" class="space-y-1 text-sm"></ul>
        <p class="text-xs text-slate-500 mt-2">
          Tips: Use the quick actions on the right to log activities. Your face recognition is already handled during login.
        </p>
      </div>
    </section>

    <!-- Quick actions + Quiz -->
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
        <button class="tile" onclick="logBehavior('return_material')">🔄 <div class="font-semibold mt-2">Return Material</div></button>
        <button class="tile" onclick="logBehavior('lunch_break')">🍱 <div class="font-semibold mt-2">Lunch Break</div></button>
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

/* ===== UI helpers ===== */
function pushFeed(text){
  const ul=document.getElementById('feed'); const li=document.createElement('li'); li.textContent=text; ul.prepend(li);
}
function showToast(message, isError=false){
  const t=document.createElement('div');
  t.className=`fixed bottom-6 left-1/2 transform -translate-x-1/2 px-6 py-3 rounded-xl shadow-lg text-white z-[80] text-sm ${isError?'bg-red-600':'bg-green-600'}`;
  t.innerText=message; document.body.appendChild(t);
  setTimeout(()=>{t.classList.add('fade-out'); setTimeout(()=>t.remove(),1000);},3000);
}

/* ===== Quick actions ===== */
function logBehavior(actionType){
  fetch('../config/log_behavior.php', {
    method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body: 'action_type='+encodeURIComponent(actionType)
  })
  .then(r=>r.json())
  .then(d=>{
    if (d.success) showToast('✅ '+ actionType.replace('_',' ').toUpperCase() +' logged!');
    else showToast('❌ '+(d.message||'Error'), true);
  })
  .catch(()=> showToast('❌ Network error', true));
}

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
