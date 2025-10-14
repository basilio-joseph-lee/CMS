<?php
// user/classroom_view.php
include "../config/db.php";
session_start();

// Student only (view-only)
if (!isset($_SESSION['student_id'])) {
  header("Location: ../student_login.php");
  exit;
}

$student_id = intval($_SESSION['student_id']);
$student_fullname = $_SESSION['student_fullname'] ?? 'Student';

// ---------- Inline AJAX API handler ----------
if (isset($_GET['action']) && (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
  header('Content-Type: application/json; charset=utf-8');
  include "../config/db.php";

  $sy = intval($_SESSION['school_year_id'] ?? 0);
  $ad = intval($_SESSION['advisory_id'] ?? 0);
  $sj = intval($_SESSION['subject_id'] ?? 0);

  function normalize_path_ajax($p) {
    if (!$p) return null;
    $p = trim(str_replace('\\','/',$p));
    $p = preg_replace('#^https?://[^/]+#','',$p);
    if ($p === '') return null;
    if ($p[0] !== '/') $p = '/'.$p;
    $p = preg_replace('#/+#','/',$p);
    if (strpos($p, '/CMS/') !== 0) $p = '/CMS'.$p;
    $p = str_replace('/CMS/CMS/','/CMS/',$p);
    return $p;
  }

  $action = $_GET['action'];

  // GET STUDENTS
  if ($action === 'get_students') {
    $sql = "SELECT DISTINCT s.student_id, s.fullname, s.avatar_path
            FROM students s
            JOIN student_enrollments se ON se.student_id = s.student_id
            WHERE se.school_year_id=? AND se.advisory_id=? AND se.subject_id=?
            ORDER BY s.fullname ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $sy, $ad, $sj);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
      $rows[] = [
        'student_id' => (int)$r['student_id'],
        'fullname'   => $r['fullname'],
        'avatar_url' => normalize_path_ajax($r['avatar_path']) ?: '/CMS/img/empty_desk.png'
      ];
    }
    echo json_encode(['students' => $rows]);
    exit;
  }

  // GET SEATING
  if ($action === 'get_seating') {
    $sql = "SELECT seat_no, student_id, x, y
            FROM seating_plan
            WHERE school_year_id=? AND advisory_id=? AND subject_id=?
            ORDER BY seat_no ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $sy, $ad, $sj);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
      $rows[] = [
        'seat_no'    => (int)$r['seat_no'],
        'student_id' => is_null($r['student_id']) ? null : (int)$r['student_id'],
        'x'          => is_null($r['x']) ? null : (int)$r['x'],
        'y'          => is_null($r['y']) ? null : (int)$r['y'],
      ];
    }
    echo json_encode(['seating' => $rows]);
    exit;
  }

  // GET BEHAVIOR
  if ($action === 'get_behavior') {
    function normalize_action($a) {
      $a = strtolower(trim((string)$a));
      $a = str_replace(['-',' '],'_',$a);
      if (str_ends_with($a, '_request')) $a = substr($a, 0, -8);
      return $a;
    }
    function label_for($a) {
      switch ($a) {
        case 'restroom': return '🚻 Restroom';
        case 'snack': return '🍎 Snack';
        case 'water_break': return '💧 Water Break';
        case 'lunch_break': return '🍱 Lunch Break';
        case 'not_well': return '🤒 Not Well';
        case 'help_request': return '✋ Needs Help';
        case 'participated': return '✅ Participated';
        case 'im_back': return '⬅️ Back';
        case 'log_out': return '🚪 Logged Out';
        default: return ucfirst(str_replace('_',' ',$a));
      }
    }
    $away = ['restroom','snack','water_break','lunch_break','not_well','log_out'];

    $sql = "SELECT bl.student_id, bl.action_type, bl.timestamp
            FROM behavior_logs bl
            INNER JOIN (
              SELECT student_id, MAX(timestamp) ts
              FROM behavior_logs
              GROUP BY student_id
            ) last ON last.student_id = bl.student_id AND last.ts = bl.timestamp";
    $res = $conn->query($sql);
    $map = [];
    while ($r = $res->fetch_assoc()) {
      $act = normalize_action($r['action_type']);
      $map[(string)$r['student_id']] = [
        'action'    => $act,
        'label'     => label_for($act),
        'is_away'   => in_array($act, $away, true),
        'timestamp' => $r['timestamp']
      ];
    }
    echo json_encode(['ok' => true, 'map' => $map]);
    exit;
  }

  echo json_encode(['ok' => false]);
  exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Classroom View</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
body{background:#fefae0;font-family:'Comic Sans MS',cursive;}
#stage{position:relative;min-height:540px;height:72vh;background:url('../../img/bg-8.png')center/cover;border-radius:12px;overflow:hidden;box-shadow:inset 0 0 20px rgba(0,0,0,.15);}
.seat{width:100px;position:absolute;user-select:none;}
.desk-rect{width:90px;height:40px;border-radius:6px;margin:auto;background:linear-gradient(180deg,#e6cfa7,#d2a86a);border:2px solid #a16a2a;}
.avatar-wrapper{position:absolute;top:-25px;left:50%;transform:translateX(-50%);width:60px;height:60px;z-index:2;}
.avatar-img{width:100%;height:100%;border-radius:50%;object-fit:cover;}
.name{text-align:center;margin-top:45px;font-size:12px;font-weight:700;color:#1f2937;}
.status-bubble{position:absolute;top:5px;left:calc(100% + 6px);background:#fff;border:1px solid #000;border-radius:9999px;padding:5px 10px;font-size:11px;white-space:nowrap;}
.is-away .avatar-img{opacity:0.2;}
.is-away .name{opacity:0.5;}
.me .desk-rect{border-color:#10b981;box-shadow:0 0 10px #10b98180;}
</style>
</head>
<body class="p-4">

<!-- Header with Back Button -->
<div class="flex justify-between items-center mb-3">
  <div>
    <h2 class="text-lg font-bold text-gray-800">Classroom View</h2>
    <div class="text-sm text-gray-600">Viewing as <?= htmlspecialchars($student_fullname) ?></div>
  </div>
  <a href="../student_dashboard.php" class="px-3 py-1 bg-green-600 text-white text-sm rounded hover:bg-green-700">← Back</a>
</div>

<div id="stage">
  <div id="seatLayer"></div>
</div>

<div class="text-xs mt-2 text-gray-600" id="stats">Loading…</div>

<script>
const SELF_API = window.location.pathname + '?action=';
const FETCH_OPTS = { credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'} };
const MY_ID = <?= json_encode($student_id) ?>;
let students=[], seats=[], behavior={};

async function loadAll(){
  const [S,P,B] = await Promise.all([
    fetch(SELF_API+'get_students',FETCH_OPTS).then(r=>r.json()),
    fetch(SELF_API+'get_seating',FETCH_OPTS).then(r=>r.json()),
    fetch(SELF_API+'get_behavior',FETCH_OPTS).then(r=>r.json())
  ]);
  students=S.students||[];
  seats=P.seating||[];
  behavior=B.map||{};
  renderSeats();
}

function renderSeats(){
  const layer=document.getElementById('seatLayer');
  layer.innerHTML='';
  seats.forEach(seat=>{
    const s=students.find(x=>x.student_id==seat.student_id);
    const st=behavior[String(seat.student_id)]||{};
    const away=!!st.is_away;
    const emoji={
      restroom:'🚻',snack:'🍎',lunch_break:'🍱',water_break:'💧',help_request:'✋',participated:'✅',im_back:'⬅️',log_out:'🚪'
    }[st.action]||'';
    const div=document.createElement('div');
    div.className=`seat ${away?'is-away':''} ${seat.student_id==MY_ID?'me':''}`;
    div.style.left=(seat.x||20)+'px';
    div.style.top=(seat.y||20)+'px';
    div.innerHTML=`
      <div class="avatar-wrapper">
        <img src="${s?s.avatar_url:'/CMS/img/empty_desk.png'}" class="avatar-img">
        ${emoji?`<div class="status-bubble" title="${st.label||''}">${emoji}</div>`:''}
      </div>
      <div class="desk-rect"></div>
      <div class="name">${s?s.fullname:''}</div>
    `;
    layer.appendChild(div);
  });
  document.getElementById('stats').textContent=`Students: ${students.length}`;
}

async function refreshBehavior(){
  const B=await fetch(SELF_API+'get_behavior',FETCH_OPTS).then(r=>r.json());
  behavior=B.map||{};
  renderSeats();
}

loadAll();
setInterval(refreshBehavior,3000);
</script>
</body>
</html>
