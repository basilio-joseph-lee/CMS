<?php
// /CMS/user/teacher/quiz_game.php
session_start();
include '../../config/teacher_guard.php';
include "../../config/db.php";
if (!isset($_SESSION['teacher_id'])) { header("Location: ../teacher_login.php"); exit; }

$teacher_id     = (int)$_SESSION['teacher_id'];
$subject_id     = (int)$_SESSION['subject_id'];
$advisory_id    = (int)$_SESSION['advisory_id'];
$school_year_id = (int)$_SESSION['school_year_id'];

$teacherName = htmlspecialchars($_SESSION['fullname'] ?? 'Teacher');
$subjectName = htmlspecialchars($_SESSION['subject_name'] ?? '');
$className   = htmlspecialchars($_SESSION['class_name'] ?? '');
$yearLabel   = htmlspecialchars($_SESSION['year_label'] ?? '');

// Open as NEW (no quiz_id) or EDIT (with quiz_id)
$editing_quiz_id = isset($_GET['quiz_id']) ? (int)$_GET['quiz_id'] : 0;
$game_type       = $_GET['game_type']       ?? 'multiple_choice';
$total_questions = max(1, (int)($_GET['total_questions'] ?? 5));

// Preload (only when editing an existing quiz_id)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conn->set_charset('utf8mb4');

$boot_items = [];
$boot_title = '';

if ($editing_quiz_id > 0) {
  $stmt = $conn->prepare("
    SELECT title, question_text, opt_a, opt_b, opt_c, opt_d, correct_opt, time_limit_sec,
           COALESCE(question_no, COALESCE(order_no, COALESCE(order_hint, 0))) AS sort_no
    FROM kiosk_quiz_questions
    WHERE quiz_id=? AND teacher_id=? AND subject_id=? AND advisory_id=? AND school_year_id=?
    ORDER BY sort_no ASC, question_no ASC
  ");
  $stmt->bind_param('iiiii', $editing_quiz_id, $teacher_id, $subject_id, $advisory_id, $school_year_id);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) {
    if ($boot_title === '' && !empty($r['title'])) $boot_title = $r['title'];
    $boot_items[] = [
      'title'         => (string)($r['title'] ?? 'Quick Quiz'),
      'question_text' => (string)($r['question_text'] ?? ''),
      'opt_a'         => (string)($r['opt_a'] ?? ''),
      'opt_b'         => (string)($r['opt_b'] ?? ''),
      'opt_c'         => (string)($r['opt_c'] ?? ''),
      'opt_d'         => (string)($r['opt_d'] ?? ''),
      'correct_opt'   => (string)($r['correct_opt'] ?? 'A'),
      'time_limit_sec'=> (int)($r['time_limit_sec'] ?? 30),
    ];
  }
  if (!empty($boot_items)) $total_questions = count($boot_items);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Quiz Game Builder</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body {
      background:
        radial-gradient(800px 400px at 10% 10%, #fff7ed 0%, transparent 45%),
        radial-gradient(800px 400px at 90% 20%, #f0f9ff 0%, transparent 45%),
        linear-gradient(120deg, #fde68a, #e0f2fe 45%, #fae8ff 85%) fixed;
      min-height: 100dvh;
      font-family: ui-sans-serif, system-ui, -apple-system, "Comic Sans MS", cursive;
    }
    .glass { background: rgba(255,255,255,.88); backdrop-filter: blur(6px); }
    .chip  { background: #f3f4f6; border: 1px solid #e5e7eb; }
    .shadow-soft { box-shadow: 0 8px 30px rgba(0,0,0,.08); }
    .ring-anim { animation: pulse 1.6s infinite; }
    @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(16,185,129,.45); }
      70%{ box-shadow: 0 0 0 16px rgba(16,185,129,0); } 100%{ box-shadow: 0 0 0 0 rgba(16,185,129,0); } }
    @media (max-width: 640px){ .stack-sm { display: grid; gap: .75rem; } }
  </style>
</head>
<body class="px-3 sm:px-4 py-5">

  <header class="max-w-5xl mx-auto mb-4">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
      <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold">Quiz Game Builder</h1>
        <p class="text-gray-600 text-sm">
          <?= $teacherName ?> • <?= $subjectName ?> | <?= $className ?> | SY <?= $yearLabel ?>
        </p>
      </div>
      <div class="flex gap-2">
        <a href="quiz_dashboard.php" class="chip px-3 py-2 rounded-lg hover:bg-white shadow-sm">← Back</a>
        <a href="quiz_leaderboard.php" class="px-3 py-2 rounded-lg bg-blue-600 text-white shadow-soft hover:bg-blue-700">📊 Leaderboard</a>
      </div>
    </div>
  </header>

  <main class="max-w-5xl mx-auto glass rounded-2xl shadow-soft p-4 sm:p-6">
    <div class="flex flex-wrap items-center gap-2 sm:gap-3 mb-4">
      <span class="chip px-3 py-1.5 rounded-full text-sm">🎮 Game: <b class="ml-1"><?= htmlspecialchars($game_type) ?></b></span>
      <span class="chip px-3 py-1.5 rounded-full text-sm">📝 Total: <b class="ml-1" id="totalLabel"><?= (int)$total_questions ?></b> questions</span>
      <span class="chip px-3 py-1.5 rounded-full text-sm ring-anim">Question <b id="stepNo">1</b> of <b id="stepOf"><?= (int)$total_questions ?></b></span>
      <?php if ($editing_quiz_id): ?>
        <span class="chip px-3 py-1.5 rounded-full text-sm">✏️ Editing quiz_id: <b class="ml-1"><?= (int)$editing_quiz_id ?></b></span>
      <?php endif; ?>
    </div>

    <div class="w-full h-2 bg-gray-200 rounded-full overflow-hidden mb-6">
      <div id="progressBar" class="h-2 bg-emerald-500 w-[0%] transition-all"></div>
    </div>

    <form id="quizForm" class="space-y-5" onsubmit="return false;">
      <input type="hidden" id="game_type" value="<?= htmlspecialchars($game_type) ?>">
      <input type="hidden" id="total_questions" value="<?= (int)$total_questions ?>">
      <input type="hidden" id="quiz_id" value="<?= (int)$editing_quiz_id ?>">
      <input type="hidden" id="order_hint" value="1">

      <div>
        <label class="block text-sm font-semibold">Title (optional)</label>
        <input name="title" id="title" class="w-full border rounded-xl px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-400"
               placeholder="Quick Quiz" value="<?= htmlspecialchars($boot_title ?: '') ?>">
      </div>

      <div>
        <label class="block text-sm font-semibold">Question</label>
        <textarea name="question_text" id="question_text" rows="3"
          class="w-full border rounded-xl px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-400"
          placeholder="Type your question here..." required></textarea>
      </div>

      <?php if ($game_type === 'multiple_choice'): ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div><label class="block text-sm font-semibold">Option A</label><input id="opt_a" class="w-full border rounded-xl px-3 py-2" required></div>
          <div><label class="block text-sm font-semibold">Option B</label><input id="opt_b" class="w-full border rounded-xl px-3 py-2" required></div>
          <div><label class="block text-sm font-semibold">Option C</label><input id="opt_c" class="w-full border rounded-xl px-3 py-2" required></div>
          <div><label class="block text-sm font-semibold">Option D</label><input id="opt_d" class="w-full border rounded-xl px-3 py-2" required></div>
        </div>
        <div class="stack-sm">
          <div>
            <label class="block text-sm font-semibold">Correct Answer</label>
            <select id="correct_opt" class="w-full border rounded-xl px-3 py-2" required>
              <option value="A">A</option><option value="B">B</option><option value="C">C</option><option value="D">D</option>
            </select>
          </div>
        </div>
      <?php elseif ($game_type === 'true_false'): ?>
        <div>
          <label class="block text-sm font-semibold">Correct Answer</label>
          <select id="correct_opt" class="w-full border rounded-xl px-3 py-2" required>
            <option value="True">True</option><option value="False">False</option>
          </select>
        </div>
      <?php elseif ($game_type === 'identification'): ?>
        <div>
          <label class="block text-sm font-semibold">Correct Answer</label>
          <input id="correct_opt" class="w-full border rounded-xl px-3 py-2" placeholder="Type the exact correct answer">
        </div>
      <?php endif; ?>

      <div class="stack-sm">
        <div>
          <label class="block text-sm font-semibold">Time Limit (seconds)</label>
          <input type="number" min="10" max="300" id="time_limit_sec"
                 class="w-full border rounded-xl px-3 py-2" value="30">
        </div>
      </div>

      <!-- Only 1 primary action -->
      <div class="flex items-center gap-3 pt-2">
        <div class="flex gap-2">
          <button type="button" id="btnPrev"  class="px-4 py-2 rounded-lg border hover:bg-gray-50">◀ Prev</button>
          <button type="button" id="btnNext"  class="px-4 py-2 rounded-lg border hover:bg-gray-50">Next ▶</button>
        </div>
        <div class="grow"></div>
        <button type="button" id="btnSavePublish" class="px-4 py-2 rounded-lg bg-green-700 text-white hover:bg-green-800">
          💾 Save All Draft
        </button>
      </div>
    </form>

    <div id="status" class="mt-5 text-sm"></div>
  </main>

  <script>
    const BOOT_ITEMS = <?= json_encode($boot_items ?: []) ?>;
    const BOOT_TITLE = <?= json_encode($boot_title ?: '') ?>;
  </script>

  <script>
  // ---------- State ----------
  let TOTAL  = parseInt(document.getElementById('total_questions').value, 10);
  const GAME = document.getElementById('game_type').value;
  let QUIZ_ID = parseInt(document.getElementById('quiz_id').value || '0', 10);
  let idx = 0;

  // Pre-allocate questions
  let Q = Array.from({length: TOTAL}, () => ({
    title: "Quick Quiz", question_text: "",
    opt_a: "", opt_b: "", opt_c: "", opt_d: "",
    correct_opt: (GAME === "multiple_choice" ? "A" : (GAME === "true_false" ? "True" : "")),
    time_limit_sec: 30
  }));

  if (Array.isArray(BOOT_ITEMS) && BOOT_ITEMS.length) {
    Q = BOOT_ITEMS.map(x => ({
      title: (BOOT_TITLE || x.title || "Quick Quiz"),
      question_text: x.question_text || "",
      opt_a: x.opt_a || "", opt_b: x.opt_b || "", opt_c: x.opt_c || "", opt_d: x.opt_d || "",
      correct_opt: x.correct_opt || (GAME === "true_false" ? "True" : "A"),
      time_limit_sec: parseInt(x.time_limit_sec || 30, 10)
    }));
    TOTAL = Q.length;
    document.getElementById('total_questions').value = String(TOTAL);
    document.getElementById('totalLabel').textContent = String(TOTAL);
    document.getElementById('stepOf').textContent = String(TOTAL);
    if (BOOT_TITLE) document.getElementById('title').value = BOOT_TITLE;
  }

  // ---------- Helpers ----------
  const $ = id => document.getElementById(id);
  function toast(msg, ok=true){
    const el = $("status");
    el.textContent = msg;
    el.className = "mt-5 text-sm " + (ok ? "text-emerald-700" : "text-red-700");
  }
  function updateProgress(){
    $("stepNo").textContent = (idx+1);
    const pct = Math.round(((idx+1)/TOTAL)*100);
    $("progressBar").style.width = pct + "%";
    $("btnPrev").disabled = (idx === 0);
    $("btnNext").disabled = (idx === TOTAL-1);
  }
  function pushFormToState(){
    const cur = Q[idx];
    cur.title = $("title").value || "Quick Quiz";
    cur.question_text = $("question_text").value || "";
    cur.time_limit_sec = parseInt($("time_limit_sec").value || "30", 10);
    if (GAME === "multiple_choice"){
      cur.opt_a = $("opt_a").value || "";
      cur.opt_b = $("opt_b").value || "";
      cur.opt_c = $("opt_c").value || "";
      cur.opt_d = $("opt_d").value || "";
      cur.correct_opt = $("correct_opt").value || "A";
    } else {
      cur.opt_a = cur.opt_b = cur.opt_c = cur.opt_d = "";
      cur.correct_opt = $("correct_opt").value || (GAME === "true_false" ? "True" : "");
    }
  }
  function pullStateToForm(){
    const cur = Q[idx];
    $("title").value = cur.title || "Quick Quiz";
    $("question_text").value = cur.question_text || "";
    $("time_limit_sec").value = cur.time_limit_sec || 30;
    if (GAME === "multiple_choice"){
      $("opt_a").value = cur.opt_a || "";
      $("opt_b").value = cur.opt_b || "";
      $("opt_c").value = cur.opt_c || "";
      $("opt_d").value = cur.opt_d || "";
      $("correct_opt").value = cur.correct_opt || "A";
    } else {
      $("correct_opt").value = cur.correct_opt || (GAME === "true_false" ? "True" : "");
    }
    updateProgress();
  }

  // ---------- Navigation ----------
  $("btnPrev").onclick = () => { pushFormToState(); idx = Math.max(0, idx-1); pullStateToForm(); };
  $("btnNext").onclick = () => { pushFormToState(); idx = Math.min(TOTAL-1, idx+1); pullStateToForm(); };

  // ---------- Bulk save & publish ----------
  function collectAllQuestions() {
    pushFormToState();
    return {
      title: $("title").value || "Quick Quiz",
      game_type: GAME,
      quiz_id: QUIZ_ID || 0,        // 0 = new quiz; >0 = edit existing
      questions: Q.map((q, i) => ({
        title: q.title || "Quick Quiz",
        question_text: q.question_text || "",
        opt_a: q.opt_a || "", opt_b: q.opt_b || "", opt_c: q.opt_c || "", opt_d: q.opt_d || "",
        correct_opt: q.correct_opt || "",
        time_limit_sec: parseInt(q.time_limit_sec || 30, 10),
        question_no: i + 1
      }))
    };
  }

async function saveAllDraft(){
  const payload = collectAllQuestions();
  try{
    const res = await fetch('../../config/quiz_save_whole.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ mode: 'draft', ...payload })
    });
    const data = await res.json();
    if (data.success){
      // redirect after successful save
      window.location.href = 'quiz_dashboard.php';
    } else {
      toast(data.message || 'Save failed', false);
    }
  }catch(e){
    toast('Network error', false);
  }
}



$("btnSavePublish").onclick = saveAllDraft;


  // Init
  pullStateToForm();
  </script>
</body>
</html>
