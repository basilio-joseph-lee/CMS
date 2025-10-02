<?php
session_start();
if (!isset($_SESSION['teacher_id'])) { header("Location: ../teacher_login.php"); exit; }

$teacher_id     = intval($_SESSION['teacher_id']);
$subject_id     = intval($_SESSION['subject_id']);
$advisory_id    = intval($_SESSION['advisory_id']);
$school_year_id = intval($_SESSION['school_year_id']);

$teacherName = htmlspecialchars($_SESSION['fullname'] ?? 'Teacher');
$subjectName = htmlspecialchars($_SESSION['subject_name'] ?? '');
$className   = htmlspecialchars($_SESSION['class_name'] ?? '');
$yearLabel   = htmlspecialchars($_SESSION['year_label'] ?? '');

$conn = new mysqli("localhost","root","","cms");
if ($conn->connect_error) { die("DB failed: ".$conn->connect_error); }
$conn->set_charset('utf8mb4');

/* 1) Latest question for this section/subject/SY (published or closed) */
$qRow = null; $qid = null;
$stmt = $conn->prepare("
  SELECT question_id, title, question_text, correct_opt, time_limit_sec, status, published_at, closed_at
  FROM kiosk_quiz_questions
  WHERE subject_id=? AND advisory_id=? AND school_year_id=?
  ORDER BY question_id DESC
  LIMIT 1
");
$stmt->bind_param("iii", $subject_id, $advisory_id, $school_year_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res && $res->num_rows) { $qRow = $res->fetch_assoc(); $qid = intval($qRow['question_id']); }
$stmt->close();

/* 2) Round leaderboard rows (order by points desc, fastest time asc) */
$roundRows = [];
if ($qid) {
  $sql = "
    SELECT r.student_id, s.fullname, s.avatar_path,
           r.chosen_opt, r.is_correct, r.points, r.time_ms, r.answered_at
    FROM kiosk_quiz_responses r
    JOIN students s ON s.student_id = r.student_id
    WHERE r.question_id = ?
    ORDER BY r.points DESC, r.time_ms ASC, r.answered_at ASC
  ";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("i", $qid);
  $stmt->execute();
  $roundRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}

/* 3) Build Top 10 list (with avatars), and compute max points for bar scaling */
$top10 = array_slice($roundRows, 0, 10);
$maxPts = 0;
foreach ($top10 as $r) { $maxPts = max($maxPts, intval($r['points'])); }
if ($maxPts <= 0) $maxPts = 1; // avoid division by zero

/* 4) Cumulative Today (across questions published today for this section/subject/SY) */
$cumRows = [];
$sql = "
  SELECT r.student_id, s.fullname, s.avatar_path,
         SUM(r.points) AS total_points,
         SUM(r.is_correct) AS correct_count,
         COUNT(*) AS answered_count
  FROM kiosk_quiz_responses r
  JOIN kiosk_quiz_questions q ON q.question_id = r.question_id
  JOIN students s ON s.student_id = r.student_id
  WHERE q.subject_id=? AND q.advisory_id=? AND q.school_year_id=? AND DATE(q.published_at)=CURDATE()
  GROUP BY r.student_id
  ORDER BY total_points DESC, correct_count DESC, MIN(r.answered_at) ASC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iii", $subject_id, $advisory_id, $school_year_id);
$stmt->execute();
$cumRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$conn->close();

/* Helper for safe avatar */
function avatarSrc($path) {
  $path = trim((string)$path);
  if ($path !== '') return htmlspecialchars($path);
  // tiny inline placeholder circle (base64 SVG)
  $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128"><rect width="100%" height="100%" rx="64" fill="#e5e7eb"/><text x="50%" y="54%" text-anchor="middle" font-family="Arial" font-size="52" fill="#9ca3af">🙂</text></svg>';
  return 'data:image/svg+xml;base64,'.base64_encode($svg);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Quiz Leaderboard</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body { background:#fff7e6; }
    /* Cards enter animation */
    .stagger-enter { opacity:0; transform: translateY(16px) scale(.98); }
    .stagger-enter.show { opacity:1; transform: translateY(0) scale(1); transition: all .55s cubic-bezier(.2,.8,.2,1); }
    /* Podium bob */
    @keyframes bob { 0%,100%{ transform:translateY(0) } 50%{ transform:translateY(-6px) } }
    .bob { animation: bob 2s ease-in-out infinite; }
    /* Confetti (simple CSS) */
    .confetti { position:fixed; inset:0; pointer-events:none; overflow:hidden; z-index:60; }
    .confetti i {
      position:absolute; top:-12px; width:8px; height:12px; opacity:.9; transform:translateY(-100px) rotate(0deg);
      animation: fall linear forwards;
    }
    @keyframes fall {
      to { transform: translateY(110vh) rotate(720deg); }
    }
    /* Score bar animation */
    .bar { height:10px; border-radius:9999px; background:linear-gradient(90deg,#34d399,#22c55e); width:0%; }
    .bar.grow { transition: width 1.2s cubic-bezier(.2,.8,.2,1); }
    /* Medal rings */
    .ring-gold  { box-shadow:0 0 0 3px #f59e0b inset; }
    .ring-silver{ box-shadow:0 0 0 3px #9ca3af inset; }
    .ring-bronze{ box-shadow:0 0 0 3px #b45309 inset; }
  </style>
</head>
<body class="p-4 sm:p-6">
  <div class="max-w-6xl mx-auto bg-white rounded-2xl shadow p-5 sm:p-7 relative overflow-hidden">
    <div class="flex items-center justify-between gap-3">
      <h1 class="text-2xl font-extrabold">Quiz Leaderboard</h1>
      <a href="quiz_game.php" class="text-sm text-blue-600 underline">← Back to Quiz Builder</a>
    </div>
    <p class="text-sm text-gray-600 mb-6">
      <?= $teacherName ?> • <?= $subjectName ?> | <?= $className ?> | SY <?= $yearLabel ?>
    </p>

    <?php if (!$qid): ?>
      <div class="p-6 rounded-xl bg-yellow-50 border border-yellow-200">
        No quiz published yet for this class today.
      </div>
    <?php else: ?>

      <!-- Round meta -->
      <div class="mb-4">
        <div class="text-sm text-gray-700">
          <div><strong>Question:</strong> <?= htmlspecialchars($qRow['question_text']) ?></div>
          <div>
            <strong>Correct:</strong> <?= htmlspecialchars($qRow['correct_opt']) ?>
            • <strong>Status:</strong> <?= htmlspecialchars($qRow['status']) ?>
            <?php if (!empty($qRow['closed_at'])): ?> • Closed: <?= htmlspecialchars($qRow['closed_at']) ?><?php endif; ?>
          </div>
        </div>
      </div>

      <!-- PODIUM -->
      <div class="mb-6">
        <h2 class="text-lg font-bold mb-3">Top 3 Podium</h2>
        <?php
          $p1 = $top10[0] ?? null; $p2 = $top10[1] ?? null; $p3 = $top10[2] ?? null;
          // helper to print a podium block
          function podiumBlock($rank, $row, $class) {
            if (!$row) {
              echo '<div class="flex flex-col items-center justify-end '.$class.' opacity-50">
                      <div class="w-20 h-20 rounded-full bg-gray-100"></div>
                      <div class="mt-2 h-5 w-24 bg-gray-100 rounded"></div>
                      <div class="mt-2 h-4 w-14 bg-gray-100 rounded"></div>
                      <div class="mt-3 w-24 h-10 bg-gray-100 rounded-t"></div>
                    </div>';
              return;
            }
            $name = htmlspecialchars($row['fullname']);
            $pts  = intval($row['points']);
            $time = intval($row['time_ms']);
            $ring = $rank===1 ? 'ring-gold' : ($rank===2 ? 'ring-silver':'ring-bronze');
            echo '<div class="flex flex-col items-center justify-end '.$class.'">
                    <img src="'.avatarSrc($row['avatar_path']).'" alt="" class="w-24 h-24 rounded-full object-cover '.$ring.' bob">
                    <div class="mt-2 font-bold text-center">'. $name .'</div>
                    <div class="text-xs text-gray-600">+'. $pts .' pts • '. $time .' ms</div>
                    <div class="mt-3 w-28 h-14 rounded-t '.($rank===1?'bg-yellow-300':'bg-gray-200').'"></div>
                  </div>';
          }
        ?>
        <div class="grid grid-cols-3 gap-4 items-end">
          <?php
            podiumBlock(2, $p2, '');            // second (left)
            podiumBlock(1, $p1, '');            // first (center)
            podiumBlock(3, $p3, '');            // third (right)
          ?>
        </div>
      </div>

      <!-- TOP 10 (animated list) -->
      <div class="mb-8">
        <h2 class="text-lg font-bold mb-3">Top 10 – This Round</h2>
        <?php if (count($top10)===0): ?>
          <div class="p-4 border rounded-xl text-gray-600">No answers yet.</div>
        <?php else: ?>
          <ol id="top10" class="space-y-3">
            <?php foreach ($top10 as $i=>$r): 
              $rank = $i+1;
              $name = htmlspecialchars($r['fullname']);
              $pts  = intval($r['points']);
              $time = intval($r['time_ms']);
              $w    = round(($pts / $maxPts)*100);
              $badge = $rank===1?'bg-yellow-400 text-black':($rank===2?'bg-gray-300 text-black':($rank===3?'bg-amber-700 text-white':'bg-gray-200 text-gray-800'));
            ?>
              <li class="stagger-enter p-3 border rounded-xl flex items-center gap-3">
                <span class="inline-flex items-center justify-center w-8 h-8 rounded-full text-sm font-bold <?= $badge ?>">
                  <?= $rank ?>
                </span>
                <img src="<?= avatarSrc($r['avatar_path']) ?>" alt="" class="w-10 h-10 rounded-full object-cover">
                <div class="flex-1">
                  <div class="flex items-center justify-between">
                    <div class="font-semibold"><?= $name ?></div>
                    <div class="text-sm text-gray-600">+<?= $pts ?> pts • <?= $time ?> ms</div>
                  </div>
                  <div class="mt-2 bg-gray-100 rounded-full">
                    <div class="bar" data-w="<?= $w ?>"></div>
                  </div>
                </div>
              </li>
            <?php endforeach; ?>
          </ol>
        <?php endif; ?>
      </div>

      <!-- FULL ROUND TABLE -->
      <details class="mb-8">
        <summary class="cursor-pointer select-none font-semibold text-gray-800">Show full round table</summary>
        <div class="mt-3 overflow-x-auto">
          <table class="min-w-full border text-sm">
            <thead class="bg-gray-100">
              <tr>
                <th class="p-2 border">#</th>
                <th class="p-2 border text-left">Student</th>
                <th class="p-2 border">Answer</th>
                <th class="p-2 border">Correct?</th>
                <th class="p-2 border">Time (ms)</th>
                <th class="p-2 border">Points</th>
              </tr>
            </thead>
            <tbody>
            <?php if (count($roundRows) === 0): ?>
              <tr><td colspan="6" class="p-3 text-center text-gray-500">No answers yet.</td></tr>
            <?php else: $i=1; foreach ($roundRows as $r): ?>
              <tr>
                <td class="p-2 border text-center"><?= $i++ ?></td>
                <td class="p-2 border">
                  <div class="flex items-center gap-2">
                    <img src="<?= avatarSrc($r['avatar_path']) ?>" alt="" class="w-7 h-7 rounded-full object-cover">
                    <span><?= htmlspecialchars($r['fullname']) ?></span>
                  </div>
                </td>
                <td class="p-2 border text-center"><?= htmlspecialchars($r['chosen_opt']) ?></td>
                <td class="p-2 border text-center"><?= $r['is_correct'] ? '✅' : '❌' ?></td>
                <td class="p-2 border text-right"><?= intval($r['time_ms']) ?></td>
                <td class="p-2 border text-right font-semibold"><?= intval($r['points']) ?></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </details>

      <!-- CUMULATIVE TODAY -->
      <div>
        <h2 class="text-lg font-bold mb-3">Cumulative (Today)</h2>
        <div class="overflow-x-auto">
          <table class="min-w-full border text-sm">
            <thead class="bg-gray-100">
              <tr>
                <th class="p-2 border">#</th>
                <th class="p-2 border text-left">Student</th>
                <th class="p-2 border">Correct</th>
                <th class="p-2 border">Answered</th>
                <th class="p-2 border">Total Points</th>
              </tr>
            </thead>
            <tbody>
            <?php if (count($cumRows) === 0): ?>
              <tr><td colspan="5" class="p-3 text-center text-gray-500">No data yet today.</td></tr>
            <?php else: $i=1; foreach ($cumRows as $r): ?>
              <tr>
                <td class="p-2 border text-center"><?= $i++ ?></td>
                <td class="p-2 border">
                  <div class="flex items-center gap-2">
                    <img src="<?= avatarSrc($r['avatar_path']) ?>" alt="" class="w-7 h-7 rounded-full object-cover">
                    <span><?= htmlspecialchars($r['fullname']) ?></span>
                  </div>
                </td>
                <td class="p-2 border text-center"><?= intval($r['correct_count']) ?></td>
                <td class="p-2 border text-center"><?= intval($r['answered_count']) ?></td>
                <td class="p-2 border text-right font-semibold"><?= intval($r['total_points']) ?></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    <?php endif; ?>
  </div>

  <!-- Confetti layer -->
  <div id="confetti" class="confetti"></div>

  <script>
    // Stagger reveal + bar growth
    const cards = Array.from(document.querySelectorAll('#top10 > li'));
    cards.forEach((el, i) => setTimeout(() => el.classList.add('show'), 120 + i*80));
    // animate bars
    setTimeout(() => {
      document.querySelectorAll('.bar').forEach(b => {
        const w = parseInt(b.dataset.w || '0', 10);
        b.classList.add('grow');
        b.style.width = Math.max(6, w) + '%';
      });
    }, 300);

    // Tiny confetti burst on load
    (function confettiBurst(){
      const root = document.getElementById('confetti');
      if (!root) return;
      const colors = ['#f59e0b','#10b981','#3b82f6','#ef4444','#8b5cf6','#14b8a6','#eab308'];
      const N = 80;
      for (let i=0;i<N;i++){
        const p = document.createElement('i');
        const delay = Math.random()*0.6;
        const dur = 1.8 + Math.random()*1.2;
        p.style.left   = (Math.random()*100) + 'vw';
        p.style.background = colors[Math.floor(Math.random()*colors.length)];
        p.style.animationDuration = dur+'s';
        p.style.animationDelay = delay+'s';
        p.style.transform = `translateY(-100px) rotate(${Math.random()*180}deg)`;
        root.appendChild(p);
        setTimeout(()=> p.remove(), (delay+dur)*1000 + 500);
      }
      // one burst; if you want repeating, call again after a timeout.
    })();
  </script>
</body>
</html>
