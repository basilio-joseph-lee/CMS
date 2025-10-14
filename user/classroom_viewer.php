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

// ---------- Inline AJAX API handler (XHR only) ----------
if (isset($_GET['action']) && (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
  header('Content-Type: application/json; charset=utf-8');

  // require student session
  if (!isset($_SESSION['student_id'])) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'message'=>'Not authenticated']);
    exit;
  }

  // derive class pairing (sy/ad/sj) from session or from student_enrollments
  $sy = intval($_SESSION['school_year_id'] ?? 0);
  $ad = intval($_SESSION['advisory_id'] ?? 0);
  $sj = intval($_SESSION['subject_id'] ?? 0);

  // normalize avatar path helper (same mapping as teacher)
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

  // If class info missing, try to find student's active enrollment
  if ((!$ad || !$sj) && isset($_SESSION['student_id'])) {
    $q = $conn->prepare("
      SELECT se.school_year_id, se.advisory_id, se.subject_id
      FROM student_enrollments se
      JOIN school_years y ON y.school_year_id = se.school_year_id AND y.status='active'
      WHERE se.student_id = ?
      LIMIT 1
    ");
    $q->bind_param("i", $_SESSION['student_id']);
    $q->execute();
    $q->bind_result($sy2,$ad2,$sj2);
    if ($q->fetch()) { $sy = intval($sy2); $ad = intval($ad2); $sj = intval($sj2); }
    $q->close();

    // persist for page convenience
    $_SESSION['school_year_id'] = $sy;
    $_SESSION['advisory_id']    = $ad;
    $_SESSION['subject_id']     = $sj;
  }

  // If still not set, return empty but 200 so frontend can show placeholder
  if (!$sy || !$ad || !$sj) {
    if ($action === 'get_students') echo json_encode(['students'=>[], 'meta'=>['sy'=>$sy,'ad'=>$ad,'sj'=>$sj]]);
    elseif ($action === 'get_seating') echo json_encode(['seating'=>[]]);
    elseif ($action === 'get_behavior') echo json_encode(['map'=>[]]);
    else echo json_encode(['ok'=>false,'message'=>'no class configured']);
    exit;
  }

  // GET STUDENTS (roster)
  if ($action === 'get_students') {
    $sql = "
      SELECT DISTINCT s.student_id, s.fullname, s.avatar_path
      FROM students s
      JOIN student_enrollments se ON se.student_id = s.student_id
      WHERE se.school_year_id=? AND se.advisory_id=? AND se.subject_id=?
      ORDER BY s.fullname ASC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $sy, $ad, $sj);
    if (!$stmt->execute()) { echo json_encode(['students'=>[]]); exit; }
    $res = $stmt->get_result();
    $rows=[];
    while ($r = $res->fetch_assoc()) {
      $rows[] = [
        'student_id' => (int)$r['student_id'],
        'fullname'   => $r['fullname'],
        'avatar_url' => normalize_path_ajax($r['avatar_path']) ?: '/CMS/img/empty_desk.png'
      ];
    }
    $stmt->close();
    echo json_encode(['students'=>$rows, 'meta'=>['sy'=>$sy,'ad'=>$ad,'sj'=>$sj]]);
    exit;
  }

  // GET SEATING (saved layout)
  if ($action === 'get_seating') {
    $sql = "
      SELECT seat_no, student_id, x, y
      FROM seating_plan
      WHERE school_year_id=? AND advisory_id=? AND subject_id=?
      ORDER BY seat_no ASC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $sy, $ad, $sj);
    if (!$stmt->execute()) { echo json_encode(['seating'=>[]]); exit; }
    $res = $stmt->get_result();
    $rows=[];
    while ($r = $res->fetch_assoc()) {
      $rows[] = [
        'seat_no'    => (int)$r['seat_no'],
        'student_id' => is_null($r['student_id']) ? null : (int)$r['student_id'],
        'x'          => is_null($r['x']) ? null : (int)$r['x'],
        'y'          => is_null($r['y']) ? null : (int)$r['y'],
      ];
    }
    $stmt->close();
    echo json_encode(['seating'=>$rows]);
    exit;
  }

  // GET BEHAVIOR / STATUS (latest status per student)
  if ($action === 'get_behavior') {
    function normalize_action($a) {
      $a = strtolower(trim((string)$a));
      $a = str_replace(['-',' '],'_',$a);
      if (str_ends_with($a, '_request')) $a = substr($a, 0, -8);
      return $a;
    }
    function label_for($a) {
      switch ($a) {
        case 'restroom':        return '🚻 Restroom';
        case 'snack':           return '🍎 Snack';
        case 'water_break':     return '💧 Water Break';
        case 'lunch_break':     return '🍱 Lunch Break';
        case 'not_well':        return '🤒 Not Feeling Well';
        case 'borrow_book':     return '📚 Borrowing Book';
        case 'return_material': return '📦 Returning Material';
        case 'help_request':    return '✋ Needs Help';
        case 'participated':    return '✅ Participated';
        case 'attendance':      return '✅ In';
        case 'log_out':         return '🚪 Logged out';
        case 'im_back':         return 'Im back';
        default:                return ucfirst(str_replace('_',' ',$a));
      }
    }
    $away_set = [
      'restroom','snack','water_break','lunch_break',
      'not_well','borrow_book','return_material','log_out'
    ];

    // Use teacher-style latest-per-student query (works even if class fields are missing)
    $sql = "
      SELECT bl.student_id, bl.action_type, bl.timestamp
      FROM behavior_logs bl
      INNER JOIN (
        SELECT student_id, MAX(timestamp) ts
        FROM behavior_logs
        GROUP BY student_id
      ) last
      ON last.student_id = bl.student_id AND last.ts = bl.timestamp
    ";
    $res = $conn->query($sql);

    $map = [];
    if ($res) {
      while ($row = $res->fetch_assoc()) {
        $act = normalize_action($row['action_type']);
        $map[(string)$row['student_id']] = [
          'action'    => $act,
          'label'     => label_for($act),
          'is_away'   => in_array($act, $away_set, true),
          'timestamp' => $row['timestamp']
        ];
      }
    }

    echo json_encode(['ok'=>true, 'map'=>$map]);
    exit;
  }


  // unknown action
  echo json_encode(['ok'=>false,'message'=>'unknown action']);
  exit;
}
// ---------- end inline handler ----------

// Page variables (for display only)
$subject_id     = intval($_SESSION['subject_id'] ?? 0);
$advisory_id    = intval($_SESSION['advisory_id'] ?? 0);
$school_year_id = intval($_SESSION['school_year_id'] ?? 0);
$subject_name   = $_SESSION['subject_name'] ?? 'Subject';
$class_name     = $_SESSION['class_name'] ?? 'Section';
$year_label     = $_SESSION['year_label'] ?? 'SY';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Classroom — View Only</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body { background:#fefae0; font-family:'Comic Sans MS', cursive, sans-serif; }
    #stage{ position:relative; min-height:540px; height:72vh; background: url('../../img/bg-8.png') center center / cover no-repeat; border-radius:12px; overflow:hidden; box-shadow: inset 0 0 20px rgba(0,0,0,0.15); }
    #seatLayer{ position:relative; width:100%; height:100%; }
    .seat{ width:100px; position:absolute; user-select:none; }
    .desk-rect{ width:90px; height:40px; border-radius:6px 6px 2px 2px; margin:0 auto; position:relative; z-index:1; background:linear-gradient(180deg,#e6cfa7,#d2a86a); border:2px solid #a16a2a; }
    .avatar-wrapper{ position:absolute; top:-20px; left:50%; transform:translateX(-50%); width:60px; height:60px; z-index:2; }
    .avatar-img{ width:100%; height:100%; object-fit:contain; display:block; border-radius:9999px; }
    .seat .name{ margin-top:4px; font-size:13px; text-align:center; font-weight:700; color:#1f2937; pointer-events:none; position:relative; z-index:3; }
    .status-bubble{ position:absolute; top:6px; left:calc(100% + 8px); background:#fff; border:2px solid #111; border-radius:9999px; padding:8px 12px; font-size:12px; font-weight:700; white-space:nowrap; box-shadow:0 2px 4px rgba(0,0,0,.2); pointer-events:none; }
    .seat.is-away .avatar-img { visibility: hidden; }
    .seat.is-away .name { opacity: 0.6; visibility: visible; color: black }
    /* Make everything read-only visually — hide controls */
    .ctl, .btn, .modal, #menu, .tpl, .tab-btn { display:none !important; }
    /* small responsiveness */
    @media (max-width:640px){ .seat{ transform:scale(.9); } }

    /* highlight current student seat */
    .seat.me .desk-rect { box-shadow: 0 6px 18px rgba(34,197,94,0.12); border-color: #10b981; }
    .status-bubble[title] { cursor: help; }

    /* modal (simple) */
    .cv-modal { position:fixed; inset:0; display:none; align-items:center; justify-content:center; background:rgba(0,0,0,0.45); z-index:9999; }
    .cv-modal.open { display:flex; }
    .cv-modal-card { background:#fff; padding:16px; border-radius:12px; width:min(520px,96%); box-shadow:0 12px 40px rgba(0,0,0,0.35); }
    .cv-row { display:flex; gap:12px; align-items:center; }
    .cv-avatar { width:72px; height:72px; border-radius:9999px; overflow:hidden; flex:0 0 72px; }
    .cv-avatar img { width:100%; height:100%; object-fit:cover; display:block; }
    .cv-name { font-weight:800; font-size:18px; color:#111827; }
    .cv-meta { font-size:13px; color:#4b5563; }
    .small-muted { font-size:12px; color:#6b7280; margin-top:8px; }

    /* helper: hide names toggle */
    .hide-names .name { display:none !important; }
    .hide-names .status-bubble { left:calc(100% + 6px); } /* keep bubble aligned */

    /* top-right controls */
    .top-controls { position: absolute; right: 12px; top: 12px; z-index: 60; display:flex; gap:8px; }
    .top-controls button { padding:6px 10px; border-radius:8px; background:#fff; border:1px solid #e5e7eb; font-weight:700; cursor:pointer; }
  </style>
</head>
<body>
  <div class="flex justify-end p-4">
    <a href="dashboard.php" class="bg-green-700 hover:bg-green-800 text-white text-sm px-4 py-2 rounded-lg shadow">
      ← Back
    </a>
  </div>
  <div class="p-4">
    <div class="mb-4">
      <div class="text-sm text-gray-700">Class: <b><?=htmlspecialchars($class_name)?></b> • <?=htmlspecialchars($subject_name)?> • <?=htmlspecialchars($year_label)?></div>
      <div class="text-xs text-gray-500">Viewing as: <b><?=htmlspecialchars($student_fullname)?></b></div>
    </div>

    <div id="stage" class="p-2">
      <div id="seatLayer"></div>

      <!-- top-right small controls (Back + Toggle names) -->
      <div class="top-controls" id="topControls">
        <a href="../student_dashboard.php" class="px-3 py-1 bg-green-600 text-white text-sm rounded hover:bg-green-700">← Back</a>
        <button id="toggleNamesBtn" title="Show / hide student names">Hide names</button>
      </div>
    </div>

    <div class="mt-3 text-xs text-gray-600" id="stats">Loading…</div>
  </div>

  <!-- Student Detail modal -->
  <div id="cvModal" class="cv-modal" role="dialog" aria-hidden="true">
    <div class="cv-modal-card" role="document">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <div class="text-lg font-bold">Student details</div>
        <button id="closeCvModal" style="background:transparent;border:none;font-weight:800;cursor:pointer">✕</button>
      </div>
      <div class="cv-row">
        <div class="cv-avatar"><img id="cvAvatar" src="/CMS/img/empty_desk.png" alt="avatar"></div>
        <div style="flex:1">
          <div class="cv-name" id="cvName">—</div>
          <div class="cv-meta" id="cvStatus">Status: —</div>
          <div class="small-muted" id="cvTimestamp"></div>
        </div>
      </div>
    </div>
  </div>

  <script>
    // Self API endpoint (calls this same file with ?action=)
    const SELF_API = window.location.pathname + '?action=';

    // avatar base detection (best-effort, matches teacher logic)
    const API = (function(){
      const loc = window.location;
      const path = loc.pathname || '/';
      const base = path.replace(/(\/user\/.*)$/i, '') || '';
      return loc.origin + base + '/api';
    })();
    const ROOT = API.replace(/\/api$/, '');
    const AVATAR_BASE = ROOT + '/avatar';
    const AVATAR_FALLBACK = AVATAR_BASE + '/default-student.png';

    function fixAvatar(u) {
      if (!u || typeof u !== 'string') return AVATAR_FALLBACK;
      if (u.startsWith('data:')) return u;
      let p = u.trim();
      try { if (/^https?:\/\//i.test(p)) p = new URL(p).pathname; } catch {}
      if (!p.startsWith('/')) p = '/' + p;
      p = p.replace(/^\/CMS\//i, '/').replace(/^\/img\/avatar\//i, '/avatar/').replace(/^\/img\/avatars\//i, '/avatar/').replace(/^\/avatars?\//i, '/avatar/');
      if (/^\/[^/]+\.(png|jpe?g|gif|webp)$/i.test(p)) return `${AVATAR_BASE}${p}`;
      if (/^\/avatar\/.+\.(png|jpe?g|gif|webp)$/i.test(p)) return `${ROOT}${p}`;
      return AVATAR_FALLBACK;
    }

    const FETCH_OPTS = { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } };
    let students = [];
    let seats = [];
    let behaviorMap = {};
    const MY_ID = <?= json_encode($student_id) ?>;

    function toastLog(msg){ console.debug('[classroom view]',msg); }

    // placement helpers (copied from teacher; non-editable here)
    function stageMetrics(){
      const pad=14, seatW=100, seatH=96, gapX=22, gapY=24;
      const rect=document.getElementById('stage').getBoundingClientRect();
      const usableW=Math.max(0,rect.width-pad*2), usableH=Math.max(0,rect.height-pad*2);
      const maxCols=Math.max(1,Math.floor((usableW+gapX)/(seatW+gapX)));
      const maxRows=Math.max(1,Math.floor((usableH+gapY)/(seatH+gapY)));
      return {pad,seatW,seatH,gapX,gapY,rectW:rect.width,rectH:rect.height,usableW,usableH,maxCols,maxRows};
    }
    function placeGridCentered(cols, rows, totalChairs, topOffset=0){
      const M=stageMetrics();
      cols=Math.min(Math.max(cols,1),M.maxCols);
      rows=Math.min(Math.max(rows,1),M.maxRows);
      const totalW=cols*M.seatW+(cols-1)*M.gapX, totalH=rows*M.seatH+(rows-1)*M.gapY;
      const startX=Math.max(M.pad,Math.round((M.rectW-totalW)/2));
      const startY=Math.max(M.pad,Math.round((M.rectH-totalH)/2)-20+topOffset);
      const pos=[];
      for(let r=0;r<rows;r++){
        for(let c=0;c<cols;c++){
          pos.push({x:startX+c*(M.seatW+M.gapX), y:startY+r*(M.seatH+M.gapY)});
          if(pos.length===totalChairs) return pos;
        }
      }
      return pos;
    }

    const placeGrid25=(n=25)=>placeGridCentered(5,5,n);

    // Render seats (view-only)
    function renderSeats(){
      const seatLayer = document.getElementById('seatLayer');
      seatLayer.innerHTML = '';
      const M = stageMetrics();
      seats = seats.map((s,i)=>{
        if (s.x==null || s.y==null){
          const c = i % Math.max(1,M.maxCols), r = Math.floor(i/Math.max(1,M.maxCols));
          s.x = M.pad + c*(M.seatW+M.gapX); s.y = M.pad + r*(M.seatH+M.gapY);
        }
        return s;
      });

      seats.forEach((seat,i) => {
        const node = document.createElement('div');
        node.className = 'seat';
        node.dataset.seatNo = seat.seat_no;
        node.dataset.studentId = seat.student_id ?? '';
        node.style.left = (seat.x ?? 14) + 'px';
        node.style.top  = (seat.y ?? 14) + 'px';

        const s = students.find(x => x.student_id == seat.student_id);
        const hasStudent = !!s;
        const img = fixAvatar(s?.avatar_url);
        const name = s?.fullname || '';

        // behavior lookup
        const st = hasStudent ? behaviorMap[String(s.student_id)] : null;
        const act = st ? String(st.action || '').toLowerCase() : '';
        const isAway = !!(st && st.is_away);

        // map action → emoji
        let overlayText = '';
        switch (act) {
          case 'restroom':        overlayText = '🚻'; break;
          case 'snack':           overlayText = '🍎'; break;
          case 'lunch_break':     overlayText = '🍱'; break;
          case 'out_time':        overlayText = '🚪'; break;
          case 'water_break':     overlayText = '💧'; break;
          case 'not_well':        overlayText = '🤒'; break;
          case 'borrow_book':     overlayText = '📚'; break;
          case 'return_material': overlayText = '📦'; break;
          case 'help_request':    overlayText = '✋'; break;
          case 'participated':    overlayText = '✅'; break;
          case 'im_back':         overlayText = '⬅️'; break;
          default:                overlayText = ''; break;
        }

        // bubble title shows label + timestamp where available
        const bubbleTitle = st && st.timestamp ? `${st.label || ''} — ${st.timestamp}` : (st && st.label ? st.label : '');

        node.innerHTML = `
          <div class="card ${hasStudent?'has-student':'empty'} ${isAway ? 'is-away' : ''} ${seat.student_id == MY_ID ? 'me' : ''}">
            ${ hasStudent ? `
              <div class="avatar-wrapper">
                <img src="${img}" class="avatar-img" onerror="this.onerror=null;this.src='${AVATAR_FALLBACK}';" />
                ${ overlayText ? `<div class="status-bubble" title="${bubbleTitle}">${overlayText}</div>` : '' }
              </div>
            ` : '' }
            <div class="desk-rect"></div>
          </div>
          <div class="name">${hasStudent ? name : ''}</div>
        `;

        if (isAway) node.classList.add('is-away'); else node.classList.remove('is-away');
        if (seat.student_id == MY_ID) node.classList.add('me'); // highlight current student

        // clicking a seat opens the modal with student details (for view-only)
        node.addEventListener('click', (ev) => {
          const sid = seat.student_id;
          if (!sid) return;
          const student = students.find(x => x.student_id == sid);
          const st = behaviorMap[String(sid)] || {};
          openStudentModal(student, st);
        });

        seatLayer.appendChild(node);
      });

      // update stats
      const total = students.length;
      const seatedCount = seats.reduce((a,s)=>a + (s.student_id!=null?1:0), 0);
      document.getElementById('stats').textContent = `Students: ${total} • Seated: ${seatedCount} • Chairs: ${seats.length}`;
    }

    // Normalize seating payload
    function normalizeSeating(raw){
      if(!raw) return [];
      if(Array.isArray(raw)){
        return raw.map((r,i)=>({
          seat_no: parseInt(r.seat_no||i+1,10),
          student_id: (r.student_id!==null && r.student_id!=='')?parseInt(r.student_id,10):null,
          x: (r.x!=null) ? parseFloat(r.x) : null,
          y: (r.y!=null) ? parseFloat(r.y) : null
        }));
      }
      const arr=[];
      Object.keys(raw).forEach((k,i)=> arr.push({seat_no:parseInt(k,10), student_id: raw[k]?parseInt(raw[k],10):null, x:null, y:null}));
      return arr.sort((a,b)=>a.seat_no-b.seat_no);
    }

    // Student modal helpers
    const cvModal = document.getElementById('cvModal');
    const cvAvatar = document.getElementById('cvAvatar');
    const cvName = document.getElementById('cvName');
    const cvStatus = document.getElementById('cvStatus');
    const cvTimestamp = document.getElementById('cvTimestamp');
    const closeCvModalBtn = document.getElementById('closeCvModal');

    function openStudentModal(student, st) {
      cvAvatar.src = student ? (student.avatar_url || '/CMS/img/empty_desk.png') : '/CMS/img/empty_desk.png';
      cvName.textContent = student ? student.fullname : '—';
      cvStatus.textContent = st && st.label ? `Status: ${st.label}` : 'Status: —';
      cvTimestamp.textContent = st && st.timestamp ? `Last updated: ${st.timestamp}` : '';
      cvModal.classList.add('open');
      cvModal.setAttribute('aria-hidden','false');
    }
    function closeStudentModal(){
      cvModal.classList.remove('open');
      cvModal.setAttribute('aria-hidden','true');
    }
    closeCvModalBtn.addEventListener('click', closeStudentModal);
    cvModal.addEventListener('click', (e)=>{ if (e.target===cvModal) closeStudentModal(); });

    // Load initial data
    async function loadData(){
      try {
        const [S,P,B] = await Promise.all([
          fetch(SELF_API + 'get_students', FETCH_OPTS).then(r=>r.json()).catch(()=>({students:[]}) ),
          fetch(SELF_API + 'get_seating',  FETCH_OPTS).then(r=>r.json()).catch(()=>({seating:[]}) ),
          fetch(SELF_API + 'get_behavior', FETCH_OPTS).then(r=>r.json()).catch(()=>({map:{}}) )
        ]);

        students = S.students || [];
        seats = normalizeSeating(P.seating || P || []);
        behaviorMap = B.map || {};

        // auto-assign unseated students (simple)
        const seatedIds = new Set(seats.map(s=>s.student_id).filter(v=>v!=null));
        students.forEach(stu=>{
          if(!seatedIds.has(stu.student_id)){
            const empty = seats.find(s=>s.student_id==null);
            if(empty) empty.student_id = stu.student_id;
            else {
              // add seat if none available
              const sCount = seats.length;
              const pos = placeGrid25(sCount+1)[sCount] || {x:14,y:14};
              seats.push({seat_no: sCount+1, student_id: stu.student_id, x: pos.x, y: pos.y});
            }
            seatedIds.add(stu.student_id);
          }
        });

        if (seats.length === 0) {
          const pos = placeGrid25(10);
          seats = pos.map((p,i)=>({seat_no:i+1, student_id:null, x:p.x, y:p.y}));
        }

        renderSeats();
        toastLog('Loaded initial data');
      } catch (e) {
        console.error(e);
      }
    }

    // Refresh behavior only (to keep view live)
    async function refreshBehavior(){
      try {
        const res = await fetch(SELF_API + 'get_behavior', FETCH_OPTS).then(r=>r.json()).catch(()=>({map:{}}));
        behaviorMap = res.map || {};
        renderSeats();
      } catch(e){ console.error('refreshBehavior',e); }
    }

    // Refresh students (roster changes)
    async function refreshStudents(){
      try {
        const res = await fetch(SELF_API + 'get_students', FETCH_OPTS).then(r=>r.json()).catch(()=>({students:[]}));
        students = res.students || students;
        // ensure seats still valid
        const newIds = new Set(students.map(s=>s.student_id));
        seats.forEach(s=>{ if(s.student_id!=null && !newIds.has(s.student_id)) s.student_id = null; });
        // auto assign unseated
        students.forEach(stu=>{
          if(!seats.some(s=>s.student_id==stu.student_id)){
            const empty = seats.find(s=>s.student_id==null);
            if(empty) empty.student_id = stu.student_id;
          }
        });
        renderSeats();
      } catch(e){ console.error('refreshStudents',e); }
    }

    // init
    loadData();
    // auto refresh behavior (every 3s), students (every 6s)
    setInterval(refreshBehavior, 3000);
    setInterval(refreshStudents, 6000);

    // Toggle names show/hide (top control)
    const toggleNamesBtn = document.getElementById('toggleNamesBtn');
    let namesHidden = false;
    toggleNamesBtn.addEventListener('click', ()=>{
      namesHidden = !namesHidden;
      if (namesHidden) {
        document.getElementById('stage').classList.add('hide-names');
        toggleNamesBtn.textContent = 'Show names';
      } else {
        document.getElementById('stage').classList.remove('hide-names');
        toggleNamesBtn.textContent = 'Hide names';
      }
    });

    // resize: recompute positions if viewport changed
    window.addEventListener('resize', ()=>{
      // reflow seats into grid maintaining assigned student ids
      const assigned = seats.filter(s=>s.student_id!=null).map(s=>s.student_id);
      const N = seats.length;
      const maxCols = Math.min(stageMetrics().maxCols, 8);
      const positions = placeGridCentered(maxCols, Math.ceil(N / Math.max(1, maxCols)), N);
      seats = positions.map((p,i)=>({seat_no:i+1, student_id: assigned[i]||null, x:p.x, y:p.y}));
      renderSeats();
    });
  </script>
</body>
</html>
