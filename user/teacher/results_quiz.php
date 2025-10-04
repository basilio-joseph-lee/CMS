<?php
// /CMS/user/teacher/results_quiz.php
// Teacher-facing results page (Kahoot-style). Pure HTML output (no JSON headers).

session_start();
include '../../config/teacher_guard.php';
include "../../config/db.php";

if (!isset($_SESSION['teacher_id'])) { header("Location: ../teacher_login.php"); exit; }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$session_id = (int)($_GET['session_id'] ?? 0);
if ($session_id <= 0) { http_response_code(400); echo "Invalid session."; exit; }

// ---- Session info ----
$qs = $conn->prepare("SELECT title, session_code, status, started_at, ended_at FROM kiosk_quiz_sessions WHERE session_id=? LIMIT 1");
$qs->bind_param('i', $session_id);
$qs->execute();
$session = $qs->get_result()->fetch_assoc();
$qs->close();
if (!$session) { http_response_code(404); echo "Session not found."; exit; }

$title   = $session['title'] ?: 'Quick Quiz';
$code    = $session['session_code'] ?: '—';
$status  = strtoupper($session['status'] ?: '');
$started = $session['started_at'] ?: '-';
$ended   = $session['ended_at'] ?: '-';

// ---- Total published questions ----
$qt = $conn->prepare("
  SELECT COUNT(*) AS total
  FROM kiosk_quiz_questions
  WHERE session_id = ? AND status = 'published'
");
$qt->bind_param('i', $session_id);
$qt->execute();
$total_questions = (int)($qt->get_result()->fetch_assoc()['total'] ?? 0);
$qt->close();

// ---- Leaderboard aggregation (same logic used elsewhere) ----
$sql = "
  SELECT
    t.norm_name,
    t.display_name AS name,
    COALESCE(SUM(t.points), 0)                                        AS points,
    SUM(CASE WHEN t.is_correct = 1 THEN 1 ELSE 0 END)                 AS correct,
    COUNT(*)                                                           AS answered,
    MAX(t.last_time)                                                  AS last_time
  FROM (
    SELECT
      LOWER(TRIM(r.name))                    AS norm_name,
      MIN(TRIM(r.name))                      AS display_name,
      r.question_id,
      MAX(r.points)                          AS points,
      MAX(r.is_correct)                      AS is_correct,
      MAX(r.answered_at)                     AS last_time
    FROM kiosk_quiz_responses r
    JOIN kiosk_quiz_questions  q ON q.question_id = r.question_id
    WHERE q.session_id = ?
    GROUP BY LOWER(TRIM(r.name)), r.question_id
  ) t
  GROUP BY t.norm_name, t.display_name
";
$st = $conn->prepare($sql);
$st->bind_param('i', $session_id);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// ---- Sort + rank ----
usort($rows, function($a, $b){
  $pa = (int)$a['points']; $pb = (int)$b['points'];
  if ($pa !== $pb) return $pb - $pa;
  $ca = (int)$a['correct']; $cb = (int)$b['correct'];
  if ($ca !== $cb) return $cb - $ca;
  $la = (string)($a['last_time'] ?? ''); $lb = (string)($b['last_time'] ?? '');
  if ($la !== $lb) return strcmp($la, $lb); // earlier first
  return strnatcasecmp((string)$a['name'], (string)$b['name']);
});

// competition ranking 1,2,2,4…
$rank = 0; $i = 0; $prevKey = null;
foreach ($rows as &$r){
  $i++;
  $key = ((int)$r['points']).'|'.((int)$r['correct']).'|'.((string)($r['last_time'] ?? ''));
  if ($key !== $prevKey) { $rank = $i; $prevKey = $key; }
  $r['rank']     = $rank;
  $r['points']   = (int)$r['points'];
  $r['correct']  = (int)$r['correct'];
  $r['answered'] = (int)$r['answered'];
}
unset($r);

// for podium visual order [2nd, 1st, 3rd]
$top = $rows;
$podium = [$top[1] ?? null, $top[0] ?? null, $top[2] ?? null];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Results • <?= h($title) ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body{
      background:
        radial-gradient(1200px 600px at 10% 10%, #fff7ed 0%, transparent 40%),
        radial-gradient(1200px 600px at 90% 20%, #f0f9ff 0%, transparent 45%),
        linear-gradient(120deg, #fef3c7, #e0f2fe 40%, #fae8ff 80%);
      min-height:100vh;
    }
    .glass{background:rgba(255,255,255,.9);backdrop-filter:blur(6px)}
    .shine{position:relative;overflow:hidden}
    .shine::after{content:"";position:absolute;inset:-200%;background:conic-gradient(from 180deg at 50% 50%,rgba(255,255,255,.3),transparent 30% 70%,rgba(255,255,255,.3));animation:spin 8s linear infinite;mix-blend:overlay}
    @keyframes spin{to{transform:rotate(360deg)}}
  </style>
</head>
<body class="font-[system-ui] text-slate-900">
  <div class="max-w-5xl mx-auto px-4 py-6">


    <!-- Banner -->
    <section class="glass rounded-3xl p-6 md:p-8 shadow-xl">
      <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
        <div>
          <h1 class="text-3xl md:text-4xl font-extrabold"><?= h($title) ?></h1>
          <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
            <span class="px-3 py-1 rounded-full bg-slate-100 border">Code: <b><?= h($code) ?></b></span>
            <span class="px-3 py-1 rounded-full <?= $status==='ENDED'?'bg-rose-100 text-rose-800 border':'bg-emerald-100 text-emerald-800 border' ?>">
              Status: <?= h($status) ?>
            </span>
            <?php if ($total_questions): ?>
              <span class="px-3 py-1 rounded-full bg-slate-100 border">Questions: <?= (int)$total_questions ?></span>
            <?php endif; ?>
          </div>
          <div class="mt-2 text-xs text-slate-600">
            Started: <?= h($started) ?> • Ended: <?= h($ended) ?>
          </div>
        </div>
        <div class="hidden md:block">
          <div class="text-5xl">🏁</div>
        </div>
      </div>
    </section>

    <!-- Podium -->
    <section class="glass rounded-3xl p-6 md:p-8 shadow-xl mt-6">
      <div class="flex items-center gap-3 text-lg font-bold">
        <span>🏆</span><span>Top 3 Podium</span>
      </div>

      <div class="grid grid-cols-3 gap-4 mt-6 items-end">
        <?php
          // visual heights: 2nd, 1st, 3rd
          $heights = ['h-36','h-48','h-32'];
          $labels  = ['2','1','3'];
          $medals  = ['bg-slate-300','bg-yellow-400','bg-amber-600'];
          for ($pos=0;$pos<3;$pos++):
            $p = $podium[$pos] ?? null;
        ?>
          <div class="text-center">
            <div class="shine rounded-3xl bg-gradient-to-b from-indigo-400 to-indigo-600 text-white <?= $heights[$pos] ?> flex items-end justify-center shadow-lg">
              <div class="pb-3">
                <div class="w-12 h-12 mx-auto rounded-full <?= $medals[$pos] ?> text-black grid place-items-center font-extrabold shadow"><?= $labels[$pos] ?></div>
              </div>
            </div>
            <div class="mt-2 font-bold"><?= h($p['name'] ?? '—') ?></div>
            <div class="text-sm text-slate-600"><?= (int)($p['points'] ?? 0) ?> pts</div>
          </div>
        <?php endfor; ?>
      </div>
    </section>

    <!-- Leaderboard -->
    <section class="glass rounded-3xl p-6 md:p-8 shadow-xl mt-6">
      <div class="flex items-center gap-3 text-lg font-bold">
        <span>📊</span><span>Full Leaderboard</span>
      </div>

      <?php if (!empty($rows)): ?>
        <div class="mt-4 divide-y divide-slate-200">
          <?php foreach ($rows as $idx => $r): ?>
            <?php
              $rk  = (int)$r['rank'];
              $pt  = (int)$r['points'];
              $cr  = (int)$r['correct'];
              $name= $r['name'];
              // medal colors for rank chip
              $chip = 'bg-indigo-600 text-white';
              if ($rk === 1) $chip = 'bg-yellow-400 text-black';
              elseif ($rk === 2) $chip = 'bg-slate-300 text-black';
              elseif ($rk === 3) $chip = 'bg-amber-600 text-white';
            ?>
            <div class="py-3">
              <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-3 min-w-0">
                  <div class="w-9 h-9 rounded-full grid place-items-center font-bold <?= $chip ?> shadow"><?= $rk ?></div>
                  <div class="font-semibold truncate"><?= h($name) ?></div>
                </div>
                <div class="hidden md:block text-sm text-slate-600"><?= $cr ?> correct</div>
                <div class="font-extrabold tabular-nums"><?= $pt ?> pts</div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="mt-4 text-slate-600">No responses yet.</div>
      <?php endif; ?>
    </section>

    <!-- Footer actions -->
    <div class="mt-6 flex flex-wrap gap-3">
      <a href="quiz_dashboard.php" class="inline-flex items-center gap-2 bg-white hover:bg-slate-50 text-slate-700 border rounded-xl px-4 py-2 shadow">
        ← Back to Dashboard
      </a>
      <!-- Optional: Export CSV (disabled by default) -->
      <!-- <a href="results_quiz_export.php?session_id=<?= (int)$session_id ?>" class="inline-flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl px-4 py-2 shadow">⬇ Export CSV</a> -->
    </div>

  </div>
</body>
</html>
