<?php
// classroom_view.php (student/teacher view) — robust DB include, mysqli/PDO support, safe fallbacks

// Try common DB include locations (adjust if you know exact path)
$tried = [];
$included = false;
foreach ([
    __DIR__ . '/../config/db.php',
    __DIR__ . '/../../config/db.php',
    __DIR__ . '/../config/connection.php',
    __DIR__ . '/../../config/connection.php',
    __DIR__ . '/config/db.php'
] as $p) {
    $tried[] = $p;
    if (file_exists($p)) {
        // Use require_once so it's only included once if file is included elsewhere
        try { require_once $p; $included = true; break; } catch (Throwable $e) {
            error_log("classroom_view: include failed for $p — " . $e->getMessage());
        }
    }
}

// start session
if (session_status() === PHP_SESSION_NONE) session_start();

// session context (allow teacher or student sessions; default to teacher label)
$teacherName     = $_SESSION['teacher_fullname'] ?? $_SESSION['teacher_name'] ?? 'Teacher';
$subject_id      = intval($_SESSION['subject_id'] ?? 0);
$advisory_id     = intval($_SESSION['advisory_id'] ?? 0);
$school_year_id  = intval($_SESSION['school_year_id'] ?? 0);
$subject_name    = $_SESSION['subject_name'] ?? 'Subject';
$class_name      = $_SESSION['class_name'] ?? 'Section';
$year_label      = $_SESSION['year_label'] ?? 'SY';

// Prepare data containers
$students = [];
$behavior = [];
$seating  = []; // optionally inline seating if needed

// Determine DB handle — prefer mysqli $conn (as your dashboard uses), else $db (PDO)
$db_available = false;
$is_mysqli = false;
if (isset($conn) && $conn instanceof mysqli) {
    $db_available = true; $is_mysqli = true; $db = $conn;
} elseif (isset($mysqli) && $mysqli instanceof mysqli) {
    $db_available = true; $is_mysqli = true; $db = $mysqli;
} elseif (isset($db) && $db instanceof PDO) {
    $db_available = true; $is_mysqli = false;
}

// If DB available, fetch students, seating, behavior (wrapped in try/catch)
if ($db_available && $subject_id && $advisory_id && $school_year_id) {
    try {
        if ($is_mysqli) {
            // roster from students table (adjust columns/table to match your schema)
            $sql = "SELECT student_id, fullname, avatar_url FROM students WHERE subject_id=? AND advisory_id=? AND school_year_id=? ORDER BY fullname";
            $stmt = $db->prepare($sql);
            $stmt->bind_param('iii', $subject_id, $advisory_id, $school_year_id);
            $stmt->execute();
            $res = $stmt->get_result();
            $students = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
            $stmt->close();

            // seating (if you store it)
            $sql = "SELECT seat_no, student_id, x, y FROM seating WHERE subject_id=? AND advisory_id=? AND school_year_id=? ORDER BY seat_no";
            $stmt = $db->prepare($sql);
            $stmt->bind_param('iii', $subject_id, $advisory_id, $school_year_id);
            $stmt->execute();
            $res = $stmt->get_result();
            $seating = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
            $stmt->close();

            // behavior map
            $sql = "SELECT student_id, action_type, timestamp, label FROM behavior WHERE subject_id=? AND advisory_id=? AND school_year_id=?";
            $stmt = $db->prepare($sql);
            $stmt->bind_param('iii', $subject_id, $advisory_id, $school_year_id);
            $stmt->execute();
            $res = $stmt->get_result();
            $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
            $stmt->close();
            foreach ($rows as $r) {
                $behavior[intval($r['student_id'])] = [
                    'action' => $r['action_type'] ?? '',
                    'label' => $r['label'] ?? '',
                    'timestamp' => $r['timestamp'] ?? null,
                    'is_away' => in_array(strtolower($r['action_type'] ?? ''), ['restroom','snack','lunch_break','out_time','water_break','not_well','borrow_book','return_material','log_out'])
                ];
            }
        } else {
            // PDO path
            $pdo = $db; // $db is PDO
            $stmt = $pdo->prepare("SELECT student_id, fullname, avatar_url FROM students WHERE subject_id=? AND advisory_id=? AND school_year_id=? ORDER BY fullname");
            $stmt->execute([$subject_id, $advisory_id, $school_year_id]);
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("SELECT seat_no, student_id, x, y FROM seating WHERE subject_id=? AND advisory_id=? AND school_year_id=? ORDER BY seat_no");
            $stmt->execute([$subject_id, $advisory_id, $school_year_id]);
            $seating = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("SELECT student_id, action_type, timestamp, label FROM behavior WHERE subject_id=? AND advisory_id=? AND school_year_id=?");
            $stmt->execute([$subject_id, $advisory_id, $school_year_id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $behavior[intval($r['student_id'])] = [
                    'action' => $r['action_type'] ?? '',
                    'label' => $r['label'] ?? '',
                    'timestamp' => $r['timestamp'] ?? null,
                    'is_away' => in_array(strtolower($r['action_type'] ?? ''), ['restroom','snack','lunch_break','out_time','water_break','not_well','borrow_book','return_material','log_out'])
                ];
            }
        }
    } catch (Throwable $e) {
        error_log("classroom_view fetch error: " . $e->getMessage());
        // keep arrays empty so the UI will still render
    }
} else {
    // log attempted include paths if DB not available (helps debugging)
    if (!$db_available) error_log("classroom_view: DB not available. Tried includes: " . implode('; ', $tried));
}

// If there are no students from DB, keep students as [] — client code will handle it.
// Inline necessary server-side arrays for client
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>2D Classroom Simulator — View</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
  /* minimal styling, compatible with your existing viewer */
  body{ background:#fefae0; font-family:'Comic Sans MS',cursive,sans-serif; }
  .wrap{ max-width:1200px; margin:12px auto; }
  .card{ background:#fff; border-radius:12px; padding:12px; }
  #stage{ position:relative; min-height:520px; height:72vh; background:url('../img/bg-8.png') center/cover no-repeat; border-radius:12px; overflow:hidden; box-shadow:inset 0 0 20px rgba(0,0,0,.15); --desk-grad-1:#e6cfa7; --desk-grad-2:#d2a86a; --desk-border:#a16a2a; --leg:#8b5e34; --chair-seat:#d1d5db; --chair-back:#9ca3af; --chair-border:#6b7280; --back-w:70px; --back-h:28px; --back-r:4px; --seat-w:70px; --seat-h:18px; --seat-r:4px; --seat-mt:-6px;}
  #seatLayer{ position:relative; width:100%; height:100%; }
  .seat{ width:100px; position:absolute; user-select:none; }
  .desk-rect{ width:90px; height:40px; background:linear-gradient(180deg,var(--desk-grad-1),var(--desk-grad-2)); border:2px solid var(--desk-border); border-radius:6px 6px 2px 2px; margin:0 auto; position:relative; z-index:1; }
  .chair-back{ width:var(--back-w); height:var(--back-h); background:var(--chair-back); border:2px solid var(--chair-border); border-radius:var(--back-r); margin:0 auto; position:relative; }
  .chair-seat{ width:var(--seat-w); height:var(--seat-h); background:var(--chair-seat); border:2px solid var(--chair-border); border-radius:var(--seat-r); margin:var(--seat-mt) auto 0; position:relative; z-index:0; }
  .avatar-wrapper{ position:absolute; top:-20px; left:50%; transform:translateX(-50%); width:60px; height:60px; z-index:2; }
  .avatar-img{ width:100%; height:100%; object-fit:contain; display:block; }
  .name{ margin-top:6px; font-size:12px; text-align:center; font-weight:700; color:#1f2937; }
  .status-bubble{ position:absolute; top:6px; left:calc(100% + 8px); background:#fff; border:2px solid #111; border-radius:9999px; padding:6px 10px; font-size:12px; font-weight:700; white-space:nowrap; box-shadow:0 2px 4px rgba(0,0,0,.2); color:#111;}
  .seat.is-away .avatar-img{ display:none; }
</style>
</head>
<body>
  <div class="wrap">
    <div class="card mb-4 flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-[#bc6c25]">🏫 2D Classroom Simulator — View</h1>
        <div class="text-sm text-gray-700"><?= htmlspecialchars($class_name) ?> • <?= htmlspecialchars($subject_name) ?> • <?= htmlspecialchars($year_label) ?></div>
        <div class="text-xs text-gray-600">Viewing as: <b><?= htmlspecialchars($teacherName) ?></b></div>
      </div>
      <div><button onclick="history.back()" class="px-3 py-2 rounded bg-gray-100">← Back</button></div>
    </div>

    <div id="stage" class="card p-2">
      <div id="seatLayer"></div>
    </div>

    <div id="stats" class="mt-3 text-sm text-gray-700"></div>
  </div>

<script>
/* Server-inlined values (safe JSON encoding) */
const SERVER_STUDENTS = <?= json_encode($students, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const SERVER_BEHAVIOR = <?= json_encode($behavior, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const SERVER_SEATING  = <?= json_encode($seating, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;

/* Minimal viewer logic (based on your earlier viewer) */
const stage = document.getElementById('stage');
const seatLayer = document.getElementById('seatLayer');
const stats = document.getElementById('stats');

let students = SERVER_STUDENTS || [];
let behavior = SERVER_BEHAVIOR || {};
let seats = (SERVER_SEATING && SERVER_SEATING.length>0) ? SERVER_SEATING : [];

function metrics(){
  const pad=14, seatW=100, seatH=96, gapX=22, gapY=24;
  const rect = stage.getBoundingClientRect();
  const usableW = Math.max(0, rect.width - pad*2);
  const usableH = Math.max(0, rect.height - pad*2);
  const maxCols = Math.max(1, Math.floor((usableW + gapX) / (seatW + gapX)));
  const maxRows = Math.max(1, Math.floor((usableH + gapY) / (seatH + gapY)));
  return { pad, seatW, seatH, gapX, gapY, rectW:rect.width, rectH:rect.height, usableW, usableH, maxCols, maxRows };
}

function placeGridCentered(cols, rows, totalChairs){
  const M=metrics();
  cols=Math.min(Math.max(cols,1),M.maxCols); rows=Math.min(Math.max(rows,1),M.maxRows);
  const totalW=cols*M.seatW+(cols-1)*M.gapX, totalH=rows*M.seatH+(rows-1)*M.gapY;
  const startX=Math.max(M.pad, Math.round((M.rectW-totalW)/2));
  const startY=Math.max(M.pad, Math.round((M.rectH-totalH)/2)-10);
  const pos=[];
  for(let r=0;r<rows;r++){
    for(let c=0;c<cols;c++){
      pos.push({x:startX+c*(M.seatW+M.gapX), y:startY+r*(M.seatH+M.gapY)});
      if(pos.length===totalChairs) return pos;
    }
  }
  return pos;
}

function normalizeSeating(raw){
  if(!raw) return [];
  if(Array.isArray(raw)){
    return raw.map((r,i)=>({
      seat_no: parseInt(r.seat_no || i+1,10),
      student_id: (r.student_id !== null && r.student_id !== '') ? parseInt(r.student_id,10) : null,
      x: (r.x != null) ? parseFloat(r.x) : null,
      y: (r.y != null) ? parseFloat(r.y) : null
    }));
  }
  return [];
}

function autoAssignUnseated(){
  const seatedIds = new Set(seats.map(s=>s.student_id).filter(v=>v!=null));
  const empties = ()=> seats.filter(s=>s.student_id==null);
  students.forEach(stu=>{
    if(!seatedIds.has(stu.student_id)){
      let slot = empties()[0];
      if(!slot){
        const M = metrics();
        const idx=seats.length;
        const c = idx % Math.max(1, M.maxCols);
        const r = Math.floor(idx / Math.max(1, M.maxCols));
        seats.push({seat_no:idx+1, student_id:null, x:M.pad + c*(M.seatW+M.gapX), y:M.pad + r*(M.seatH+M.gapY)});
        slot = seats[seats.length-1];
      }
      slot.student_id = stu.student_id;
      seatedIds.add(stu.student_id);
    }
  });
}

function renderSeats(){
  seatLayer.innerHTML = '';
  const M = metrics();
  seats = seats.map((s,i)=>{
    if(s.x==null || s.y==null){
      const c = i % Math.max(1, M.maxCols), r = Math.floor(i / Math.max(1, M.maxCols));
      s.x = M.pad + c*(M.seatW + M.gapX); s.y = M.pad + r*(M.seatH + M.gapY);
    }
    return s;
  });

  seats.forEach((seat,i)=>{
    const node = document.createElement('div');
    node.className = 'seat';
    node.style.left = (seat.x ?? 14) + 'px';
    node.style.top  = (seat.y ?? 14) + 'px';

    const s = students.find(x=>x.student_id==seat.student_id);
    const hasStudent = !!s;
    const img = s?.avatar_url || s?.avatar_path || '';
    const name = s?.fullname || '';

    const st = hasStudent ? behavior[String(s.student_id)] : null;
    const actionKey = st ? String(st.action||'') : '';
    const isAway = !!(st && (st.is_away || ['restroom','snack','lunch_break','out_time','water_break','not_well','borrow_book','return_material','log_out'].includes(actionKey)));

    node.innerHTML = `
      <div class="card ${hasStudent?'has-student':''} ${isAway?'is-away':''}">
        ${hasStudent ? `
          <div class="avatar-wrapper">
            <img src="${img ? img : '../avatar/default-student.png'}" class="avatar-img" onerror="this.onerror=null;this.src='../avatar/default-student.png'">
            ${isAway ? `<div class="status-bubble">${actionKey}</div>` : ''}
          </div>
        ` : ''}
        <div class="desk-rect"></div>
        <div class="chair-back"></div>
        <div class="chair-seat"></div>
      </div>
      ${name ? `<div class="name">${name}</div>` : ''}
    `;
    seatLayer.appendChild(node);
  });

  renderStats();
}

function renderStats(){
  const total = students.length;
  const seatedCount = seats.reduce((a,s)=>a + (s.student_id != null ? 1 : 0), 0);
  stats.textContent = `Students: ${total} • Seated: ${seatedCount} • Chairs: ${seats.length}`;
}

/* Poll behavior via API, but if API is forbidden the inlined SERVER_BEHAVIOR will still show states */
async function refreshBehavior(){
  try{
    const res = await fetch('../api/get_behavior_status.php', { cache:'no-store' });
    if (!res.ok) throw new Error('no-behavior');
    const data = await res.json();
    if (data && data.map && typeof data.map==='object') { behavior = data.map; }
    else if (Array.isArray(data)) {
      const map = {};
      data.forEach(row=> { map[String(row.student_id)] = { action: String(row.action_type||''), is_away: ['restroom','snack','lunch_break','out_time','water_break','not_well','borrow_book','return_material','log_out'].includes(String(row.action_type||'')) }; });
      behavior = map;
    }
    renderSeats();
  } catch(e){
    // fallback: keep inlined SERVER_BEHAVIOR if any
    if (Object.keys(behavior).length === 0 && Object.keys(SERVER_BEHAVIOR || {}).length) behavior = SERVER_BEHAVIOR;
    // don't spam errors; the page still renders
  }
}

/* Boot */
seats = normalizeSeating(seats.length?seats:SERVER_SEATING);
if(seats.length === 0){
  // create a default grid sized to students
  const pos = placeGridCentered(5,5, Math.max(10, (students||[]).length));
  seats = pos.map((p,i)=> ({ seat_no:i+1, student_id: (students[i] ? students[i].student_id : null), x:p.x, y:p.y }));
}
autoAssignUnseated();
renderSeats();
refreshBehavior();
setInterval(refreshBehavior, 3000);

// keep students updated (in case teacher updates roster)
setInterval(async ()=>{
  try {
    const s = await fetch('../api/get_students.php', { cache:'no-store' }).then(r=>r.json());
    if (Array.isArray(s?.students)) { students = s.students; autoAssignUnseated(); renderSeats(); }
  } catch(e){}
}, 6000);

window.addEventListener('resize', ()=> {
  const M=metrics();
  const N=seats.length;
  const cols = Math.min(M.maxCols, 8);
  const pos = placeGridCentered(cols, Math.ceil(N/Math.max(1,cols)), N);
  seats = pos.map((p,i)=> ({ seat_no:i+1, student_id: seats[i]?.student_id ?? null, x:p.x, y:p.y }));
  renderSeats();
});
</script>
</body>
</html>
