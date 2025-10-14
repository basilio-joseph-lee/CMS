<?php
// classroom_viewer.php — safe DB include + inline roster for student viewer

// try a few likely DB include paths (adjust if you know the exact path)
$db_included = false;
$possible_paths = [
    __DIR__ . '/../config/db.php',    // typical: /user/../config/db.php
    __DIR__ . '/../../config/db.php', // if called from /user/somefolder
    __DIR__ . '/../config/connection.php',
    __DIR__ . '/../../config/connection.php',
    __DIR__ . '/config/db.php'
];
foreach ($possible_paths as $p) {
    if (file_exists($p)) {
        try {
            require_once $p;
            $db_included = true;
            break;
        } catch (Throwable $e) {
            // include might emit warnings — we'll continue and try others
            error_log("DB include failed for $p: " . $e->getMessage());
        }
    }
}

// Start session after includes so session config from db.php (if any) is available
session_start();

// Session / context
$teacherName     = $_SESSION['teacher_fullname'] ?? ($_SESSION['teacher_name'] ?? 'Teacher');
$subject_id      = intval($_SESSION['subject_id'] ?? 0);
$advisory_id     = intval($_SESSION['advisory_id'] ?? 0);
$school_year_id  = intval($_SESSION['school_year_id'] ?? 0);
$subject_name    = $_SESSION['subject_name'] ?? 'Subject';
$class_name      = $_SESSION['class_name'] ?? 'Section';
$year_label      = $_SESSION['year_label'] ?? 'SY';

// Prepare fallback arrays
$students = [];
$behavior = [];

// Verify $db exists and looks like a PDO (or mysqli). If not, we skip DB queries.
$db_available = false;
if (isset($db) && is_object($db)) {
    // common pattern: $db is a PDO instance
    if ($db instanceof PDO) $db_available = true;
    // or the project may use mysqli with variable $conn or $mysqli
} elseif (isset($mysqli) && $mysqli instanceof mysqli) {
    $db = $mysqli;
    $db_available = true;
} elseif (isset($conn) && is_object($conn)) {
    $db = $conn;
    $db_available = true;
}

// If DB available, run queries (wrapped in try/catch)
if ($db_available) {
    try {
        // fetch roster for this student's section — adapt table/columns to your schema
        $sql = "SELECT student_id, fullname, avatar_url FROM students
                WHERE subject_id = ? AND advisory_id = ? AND school_year_id = ?
                ORDER BY fullname";
        // If PDO:
        if ($db instanceof PDO) {
            $stmt = $db->prepare($sql);
            $stmt->execute([$subject_id, $advisory_id, $school_year_id]);
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // If mysqli: use prepared statement
            if ($db->prepare) {
                $stmt = $db->prepare($sql);
                $stmt->bind_param('iii', $subject_id, $advisory_id, $school_year_id);
                $stmt->execute();
                $res = $stmt->get_result();
                $students = $res->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
            }
        }
    } catch (Throwable $e) {
        error_log("Error fetching students in classroom_viewer.php: " . $e->getMessage());
        $students = [];
    }

    try {
        // fetch current behavior statuses if you have a behavior table
        $sql = "SELECT student_id, action_type, timestamp, label FROM behavior
                WHERE subject_id = ? AND advisory_id = ? AND school_year_id = ?";
        if ($db instanceof PDO) {
            $stmt = $db->prepare($sql);
            $stmt->execute([$subject_id, $advisory_id, $school_year_id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $db->prepare($sql);
            $stmt->bind_param('iii', $subject_id, $advisory_id, $school_year_id);
            $stmt->execute();
            $res = $stmt->get_result();
            $rows = $res->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
        foreach ($rows as $r) {
            $behavior[intval($r['student_id'])] = [
                'action' => $r['action_type'] ?? '',
                'label' => $r['label'] ?? '',
                'timestamp' => $r['timestamp'] ?? null
            ];
        }
    } catch (Throwable $e) {
        error_log("Error fetching behavior in classroom_viewer.php: " . $e->getMessage());
        $behavior = [];
    }
} else {
    // DB was not available; log helpful debug info so you can fix the include path
    error_log("classroom_viewer.php: DB not available. Tried paths: " . implode(', ', $possible_paths));
    // Leave $students and $behavior as empty arrays so the page still renders.
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>2D Classroom Simulator — View</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    /* minimal CSS — keep as you had */
    body{ background:#fefae0; font-family:'Comic Sans MS',cursive,sans-serif; }
    .wrap{ max-width:1200px; margin:0 auto; padding:18px; }
    /* ... keep your stage styles or link to existing CSS ... */
  </style>
</head>
<body>
  <div class="wrap">
    <h1 style="font-size:22px;color:#bc6c25">🏫 2D Classroom Simulator — View</h1>
    <p><?= htmlspecialchars($class_name) ?> • <?= htmlspecialchars($subject_name) ?> • <?= htmlspecialchars($year_label) ?></p>
    <div id="stage" style="min-height:360px;border-radius:12px;background:url('../img/bg-8.png') center/cover no-repeat;padding:12px;">
      <div id="seatLayer"></div>
    </div>
    <div id="stats" style="margin-top:12px"></div>
  </div>

<script>
  // server-inlined arrays for client JS to use (safe-encoded)
  const SERVER_STUDENTS = <?= json_encode($students, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  const SERVER_BEHAVIOR = <?= json_encode($behavior, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;

  // small client-side code to render seats simply (you can replace with full viewer code)
  const stage = document.getElementById('stage');
  const layer = document.getElementById('seatLayer');
  const stats = document.getElementById('stats');

  function renderSimple(){
    layer.innerHTML = '';
    const students = SERVER_STUDENTS || [];
    const n = Math.max(6, students.length);
    for (let i=0;i<n;i++){
      const s = students[i] || null;
      const div = document.createElement('div');
      div.style.display='inline-block';
      div.style.width='110px';
      div.style.margin='10px';
      div.style.textAlign='center';
      div.innerHTML = s ? `<div style="height:60px"><img src="${s.avatar_url||'../avatar/default-student.png'}" alt="" style="max-height:60px"></div><div style="font-weight:700">${s.fullname}</div>` : `<div style="height:60px"></div><div style="opacity:.5">Empty</div>`;
      layer.appendChild(div);
    }
    stats.textContent = `Students: ${students.length} • Chairs: ${n}`;
  }
  renderSimple();
</script>
</body>
</html>
