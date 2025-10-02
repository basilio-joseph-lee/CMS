<?php
// /CMS/user/teacher/quiz_dashboard.php
session_start();
if (!isset($_SESSION['teacher_id'])) { header("Location: ../teacher_login.php"); exit; }

$teacher_id     = (int)$_SESSION['teacher_id'];
$subject_id     = (int)$_SESSION['subject_id'];
$advisory_id    = (int)$_SESSION['advisory_id'];
$school_year_id = (int)$_SESSION['school_year_id'];

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli("localhost","root","","cms");
$conn->set_charset('utf8mb4');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ---------- Draft quizzes (questions still draft, grouped by quiz_id) ----------
$drafts = [];
$stmt = $conn->prepare("
  SELECT quiz_id,
         COALESCE(NULLIF(MIN(NULLIF(title,'')),''),'Quick Quiz') AS title,
         COUNT(*)   AS questions,
         MAX(created_at) AS updated_at
  FROM kiosk_quiz_questions
  WHERE teacher_id=? AND subject_id=? AND advisory_id=? AND school_year_id=? AND status='draft'
  GROUP BY quiz_id
  ORDER BY updated_at DESC
");
$stmt->bind_param('iiii', $teacher_id, $subject_id, $advisory_id, $school_year_id);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) { $drafts[] = $r; }



// ---------- Sessions (ACTIVE only) ----------
$open_sessions = [];
$stmt = $conn->prepare("
  SELECT session_id, title, session_code, status, created_at, started_at, ended_at
    FROM kiosk_quiz_sessions
   WHERE teacher_id=? AND subject_id=? AND advisory_id=? AND school_year_id=?
     AND status IN ('active','ongoing')
ORDER BY session_id DESC");
$stmt->bind_param('iiii', $teacher_id, $subject_id, $advisory_id, $school_year_id);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) { $open_sessions[] = $r; }

$hist_sessions = [];
$stmt = $conn->prepare("
  SELECT session_id, title, session_code, status, created_at, started_at, ended_at
  FROM kiosk_quiz_sessions
  WHERE teacher_id=? AND subject_id=? AND advisory_id=? AND school_year_id=? AND status='ended'
  ORDER BY ended_at DESC, session_id DESC
");
$stmt->bind_param('iiii', $teacher_id, $subject_id, $advisory_id, $school_year_id);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) { $hist_sessions[] = $r; }

$base = "http://".$_SERVER['HTTP_HOST'].dirname($_SERVER['PHP_SELF']);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Quiz Builder & Sessions</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root{
      --bg:#f8fafc; --card:#ffffff; --muted:#475569; --line:#e2e8f0;
      --primary:#2563eb; --primaryDark:#1e40af; --ok:#16a34a; --warn:#ea580c; --bad:#b91c1c;
    }
    *{box-sizing:border-box}
    body{font-family:system-ui,Arial,sans-serif;background:var(--bg);margin:0;padding:24px;color:#0f172a}
    .wrap{max-width:1100px;margin:auto}
    h1{margin:0 0 16px}
    .toolbar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
    .btn{display:inline-block;padding:10px 14px;border-radius:10px;border:1px solid var(--line);background:var(--card);text-decoration:none;color:#0f172a;cursor:pointer}
    .btn.primary{background:var(--primary);border-color:var(--primary);color:#fff}
    .btn.dark{background:#111827;color:#fff;border-color:#111827}
    .btn.red{border-color:#ef4444;color:#ef4444;background:#fff}
    .btn:disabled{opacity:.5;cursor:not-allowed}
    .btn:hover{filter:brightness(0.98)}
    .grid{display:grid;grid-template-columns:1fr;gap:16px}
    @media(min-width:900px){ .grid{grid-template-columns:1fr 1fr} }
    .card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px}
    .card h3{margin:0 0 10px}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;border-top:1px solid var(--line)}
    thead th{background:#f9fafb;border-top:none;color:#111827;text-align:left}
    .badge{display:inline-block;padding:3px 8px;border-radius:999px;font-size:12px}
    .badge.ok{background:#dcfce7;color:#166534}
    .badge.live{background:#dbeafe;color:#1e40af}
    .badge.draft{background:#fef9c3;color:#854d0e}
    .badge.ended{background:#fee2e2;color:#991b1b}
    .muted{color:var(--muted);font-size:12px}
    .code{font-weight:800;letter-spacing:4px;font-size:28px}
    /* modal */
    .modal{position:fixed;inset:0;background:rgba(15,23,42,.45);display:none;align-items:center;justify-content:center;padding:16px}
    .modal .box{background:#fff;max-width:760px;width:100%;border-radius:16px;overflow:hidden;border:1px solid var(--line)}
    .modal .head{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid var(--line)}
    .modal .body{padding:16px}
  </style>
</head>
<body>
<div class="wrap">
  <h1>Quiz Builder & Sessions</h1>

  <div class="toolbar">
    <a class="btn primary" href="quiz_setup.php">➕ New Quiz</a>
    <button id="btnPublishAll" class="btn">🚀 Publish All Drafts</button>
    <a class="btn dark" href="../teacher_dashboard.php">Back to Dashboard</a>
  </div>

  <div class="grid">
    <!-- Draft Quizzes -->
    <div class="card">
      <h3>Draft Quizzes</h3>
      <?php if(empty($drafts)): ?>
        <div class="muted">No drafts yet. Click <b>New Quiz</b> to start building.</div>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Title</th>
              <th style="text-align:center">Questions</th>
              <th style="text-align:center">Updated</th>
              <th style="text-align:right">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($drafts as $d): ?>
            <tr>
              <td><?=h($d['title'])?></td>
              <td style="text-align:center"><?= (int)$d['questions'] ?></td>
              <td style="text-align:center"><?= h($d['updated_at']) ?></td>
              <td style="text-align:right; white-space:nowrap;">
                <a class="btn"
                   href="quiz_game.php?quiz_id=<?= (int)$d['quiz_id'] ?>&title=<?= urlencode($d['title']) ?>">
                   Edit
                </a>
                <!-- Publish turns question rows to 'published' and creates/keeps a DRAFT session (does NOT start it) -->
               <button
  class="btn primary publish-btn"
  data-quiz-id="<?= (int)$d['quiz_id'] ?>"
  data-title="<?= h($d['title']) ?>">
  Publish
</button>

                <a class="btn red"
                   href="../../config/delete_quiz.php?quiz_id=<?= (int)$d['quiz_id'] ?>"
                   onclick="return confirm('Delete this entire draft (all its questions)?')">
                   Delete
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <!-- Open Sessions (draft or active) -->
    <div class="card">
      <h3>Open Sessions</h3>
      <?php if(empty($open_sessions)): ?>
        <div class="muted">No open sessions yet. Publish a draft to create one.</div>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Title</th>
              <th>Code</th>
              <th>Status</th>
              <th style="text-align:right">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($open_sessions as $s): ?>
            <tr>
              <td><?=h($s['title'])?></td>
              <td><span class="code"><?=h($s['session_code'] ?: '—')?></span></td>
               <td>
  <?php if ($s['status'] === 'ongoing'): ?>
    <span class="badge ok">ONGOING</span>
  <?php elseif ($s['status'] === 'active'): ?>
    <span class="badge live">ACTIVE</span>
  <?php else: ?>
    <span class="badge draft"><?=h(strtoupper($s['status']))?></span>
  <?php endif; ?>
</td>

              <td style="text-align:right; white-space:nowrap;">
                <a class="btn"
                   target="_blank"
                   href="qr_view.php?session_id=<?= (int)$s['session_id'] ?>">
                   QR
                </a>
              <a class="btn primary"
                href="../../config/start_quiz.php?session_id=<?= (int)$s['session_id'] ?>">
                Start
              </a>

               <button
  class="btn red end-btn"
  data-session-id="<?= (int)$s['session_id'] ?>"
  data-title="<?= h($s['title']) ?>">
  End
</button>

                <a class="btn"
                   href="results_quiz.php?session_id=<?= (int)$s['session_id'] ?>">
                   Results
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <!-- Ended Sessions -->
    <div class="card" style="grid-column:1/-1">
      <h3>Ended Sessions</h3>
      <?php if(empty($hist_sessions)): ?>
        <div class="muted">No ended sessions yet.</div>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Title</th>
              <th>Code</th>
              <th>Started</th>
              <th>Ended</th>
              <th style="text-align:right">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($hist_sessions as $s): ?>
            <tr>
              <td><?=h($s['title'])?></td>
              <td><span class="badge ended"><?=h($s['session_code'] ?: '—')?></span></td>
              <td><?=h($s['started_at'] ?: '-')?></td>
              <td><?=h($s['ended_at'] ?: '-')?></td>
              <td style="text-align:right; white-space:nowrap;">
                <a class="btn" href="results_quiz.php?session_id=<?= (int)$s['session_id'] ?>">View Results</a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Publish All modal (keeps sessions in DRAFT) -->
<div id="mPub" class="modal" role="dialog" aria-modal="true">
  <div class="box">
    <div class="head">
      <div><b>Publish Summary</b></div>
      <button class="btn" onclick="closePub()">✖</button>
    </div>
    <div class="body" id="pubBody">
      <div class="muted">Publishing…</div>
    </div>
  </div>
</div>

<!-- End session modal -->
<div id="mEnd" class="modal" role="dialog" aria-modal="true" style="display:none">
  <div class="box">
    <div class="head">
      <div><b>End Session</b></div>
      <button class="btn" onclick="closeEnd()">✖</button>
    </div>
    <div class="body">
      <p id="endText" style="margin:0 0 16px">End this session?</p>
      <div style="display:flex; gap:8px; justify-content:flex-end">
        <button class="btn" onclick="closeEnd()">Cancel</button>
        <button class="btn red" id="btnConfirmEnd">End</button>
      </div>
    </div>
  </div>
</div>


<!-- Confirm single publish modal -->
<div id="mConfirm" class="modal" role="dialog" aria-modal="true" style="display:none">
  <div class="box">
    <div class="head">
      <div><b>Confirm Publish</b></div>
      <button class="btn" onclick="closeConfirm()">✖</button>
    </div>
    <div class="body">
      <p id="confirmText" style="margin:0 0 16px">Publish this draft?</p>
      <div style="display:flex; gap:8px; justify-content:flex-end">
        <button class="btn" onclick="closeConfirm()">Cancel</button>
        <button class="btn primary" id="btnConfirmPublish">Publish</button>
      </div>
    </div>
  </div>
</div>


<script>

  
const base = <?= json_encode($base) ?>;

function openPub(){ document.getElementById('mPub').style.display='flex'; }
function closePub(){ document.getElementById('mPub').style.display='none'; }

document.getElementById('btnPublishAll').addEventListener('click', async () => {
  openPub();
  const bodyEl = document.getElementById('pubBody');
  bodyEl.innerHTML = '<div class="muted">Publishing drafts… please wait…</div>';

  try {
    const res = await fetch('../../config/publish_all_quiz.php', {
      method: 'POST',
      headers:{'X-Requested-With':'fetch'}
    });
    const json = await res.json();
    if (!json.success) {
      bodyEl.innerHTML = '<div style="color:#b91c1c"><b>Failed:</b> '+ (json.message||'Unknown error') +'</div>';
      return;
    }

    let html = `<div style="margin-bottom:10px"><b>${json.message || 'Published.'}</b></div>`;
    if (Array.isArray(json.sessions) && json.sessions.length) {
      html += `<div style="margin:8px 0 12px">Sessions created/updated (still <b>DRAFT</b>):</div>`;
      html += `<div style="display:grid;grid-template-columns:1fr;gap:14px">`;
      json.sessions.forEach(s => {
        html += `
          <div class="card">
            <div style="font-weight:600;margin-bottom:8px">${escapeHtml(s.title||'Quick Quiz')}</div>
            <div class="muted">Status: <b>${escapeHtml(s.status||'draft')}</b></div>
            <div style="margin-top:10px">
              <a class="btn primary" href="../../config/start_quiz.php?session_id=${s.session_id}">Start Now</a>
            </div>
          </div>`;
      });
      html += `</div>`;
    } else {
      html += `<div class="muted">No drafts found to publish.</div>`;
    }

    html += `<div style="margin-top:16px">
      <a class="btn" onclick="location.reload()">Refresh Page</a>
      <button class="btn dark" onclick="closePub()">Close</button>
    </div>`;

    bodyEl.innerHTML = html;
  } catch (e) {
    bodyEl.innerHTML = '<div style="color:#b91c1c">Network error: '+ e +'</div>';
  }
});

function escapeHtml(s){
  return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
}

async function publishDraft(quizId){
  if (!confirm('Publish this draft?')) return;
  try {
    const res = await fetch('../../config/publish_all_quiz.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({quiz_id:quizId})
    });
    const data = await res.json();
    if(data.success){
      location.reload();
    }else{
      alert(data.message || 'Failed to publish draft');
    }
  } catch(e){
    alert('Network error: '+e);
  }
}

// ===== Single publish modal wiring (replaces confirm()) =====
let CONFIRM_QUIZ_ID = null;

function openConfirmPublish(quizId, title){
  CONFIRM_QUIZ_ID = quizId;
  const t = (title && title.trim()) ? title.trim() : 'Quick Quiz';
  document.getElementById('confirmText').textContent = `Publish “${t}”?`;
  document.getElementById('mConfirm').style.display = 'flex';
}
function closeConfirm(){
  document.getElementById('mConfirm').style.display = 'none';
  CONFIRM_QUIZ_ID = null;
}

// turn every draft-row Publish button into a modal trigger
document.querySelectorAll('.publish-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    openConfirmPublish(btn.dataset.quizId, btn.dataset.title);
  });
});

// perform publish after user confirms in modal
document.getElementById('btnConfirmPublish').addEventListener('click', async () => {
  if (!CONFIRM_QUIZ_ID) return;
  try {
    const res = await fetch('../../config/publish_all_quiz.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({ quiz_id: String(CONFIRM_QUIZ_ID) })
    });
    const data = await res.json();
    if (data.success) {
      location.reload();
    } else {
      alert(data.message || 'Failed to publish draft');
    }
  } catch(e){
    alert('Network error: ' + e);
  } finally {
    closeConfirm();
  }
});

// ===== End session modal wiring =====
let SESSION_TO_END = null;

function openEnd(sessionId, title){
  SESSION_TO_END = sessionId;
  const t = (title && title.trim()) ? title.trim() : 'Quiz';
  document.getElementById('endText').textContent = `End session for “${t}”?`;
  document.getElementById('mEnd').style.display = 'flex';
}
function closeEnd(){
  document.getElementById('mEnd').style.display = 'none';
  SESSION_TO_END = null;
}

// hook all "End" buttons
document.querySelectorAll('.end-btn').forEach(btn => {
  btn.addEventListener('click', () => openEnd(btn.dataset.sessionId, btn.dataset.title));
});

// confirm end via AJAX; reload dashboard on success
document.getElementById('btnConfirmEnd').addEventListener('click', async () => {
  if (!SESSION_TO_END) return;
  try{
    const res = await fetch('../../config/close_quiz.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({ session_id: String(SESSION_TO_END), ajax: '1' })
    });
    const data = await res.json();
    if (data.success) {
      location.reload();
    } else {
      alert(data.message || 'Failed to end session');
    }
  }catch(e){
    alert('Network error: '+ e);
  }finally{
    closeEnd();
  }

});

</script>
</body>
</html>
