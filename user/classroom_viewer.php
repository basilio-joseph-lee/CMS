<?php
include __DIR__ . "/../config/db.php";

session_start();

// viewing doesn't require teacher login; still use session values if present
$teacherName = $_SESSION['teacher_fullname'] ?? 'Teacher';
$subject_id     = intval($_SESSION['subject_id'] ?? 0);
$advisory_id    = intval($_SESSION['advisory_id'] ?? 0);
$school_year_id = intval($_SESSION['school_year_id'] ?? 0);
$subject_name   = $_SESSION['subject_name'] ?? 'Subject';
$class_name     = $_SESSION['class_name'] ?? 'Section';
$year_label     = $_SESSION['year_label'] ?? 'SY';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>2D Classroom Simulator — View</title>
  <script src="https://cdn.tailwindcss.com"></script>

  <style>
    body { background:#fefae0; font-family:'Comic Sans MS', cursive, sans-serif; }
    .ctl h3 { font-weight:800; letter-spacing:.3px; }
    .btn { padding:.5rem .75rem; border-radius:.5rem; font-weight:700; }
    .card.empty .avatar-wrapper { display:none; }
    /* hide only when truly empty */
  .name.is-empty { display: none; }

  /* show the name whenever the seat has a student */
  .seat.has-student .name { display: block; }

  /* dim but keep visible when away */
  .seat.is-away .name {
    opacity: 0.6;
    visibility: visible; /* <-- remove the stray comma */
    color: #111;
  }
  /* 1) Avatar under the name */
  .avatar-wrapper { z-index: 1; }

  /* 2) Name always on top (present or away) */
  .seat .name {
    position: relative;   /* create stacking context */
    z-index: 2;           /* above avatar-wrapper (1) */
    display: block;
  }

  /* 3) Hide only if no student talaga */
  .name.is-empty { display: none; }

  /* 4) Optional: dim when away, but keep visible */
  .seat.is-away .name { opacity: 0.6; }

    /* --- Stage (board) --- */
    #stage{
      position: relative;
      min-height: 540px;
      height: 72vh;
      background: url('../../img/bg-8.png') center center / cover no-repeat;
      border-radius: 12px;
      overflow: hidden;
      box-shadow: inset 0 0 20px rgba(0,0,0,0.15);

      /* theme variables (changed by JS) */
      --desk-grad-1:#e6cfa7;
      --desk-grad-2:#d2a86a;
      --desk-border:#a16a2a;
      --leg:#8b5e34;
      --chair-seat:#d1d5db;
      --chair-back:#9ca3af;
      --chair-border:#6b7280;

      /* chair shape variables (changed by JS) */
      --back-w:70px; --back-h:28px; --back-r:4px;
      --seat-w:70px; --seat-h:18px; --seat-r:4px; --seat-mt:-6px;
    }
    #seatLayer{ position:relative; width:100%; height:100%; }
    .seat{ width:100px; position:absolute; user-select:none; transition:opacity .2s ease; }
    .seat .card{ position:relative; background:transparent; border:none; box-shadow:none; text-align:center; }

    /* DESK */
    .desk-rect{ width:90px; height:40px; background:linear-gradient(180deg,var(--desk-grad-1) 0%,var(--desk-grad-2) 100%); border:2px solid var(--desk-border); border-radius:6px 6px 2px 2px; margin:0 auto; position:relative; z-index:1; }
    .desk-rect::before,.desk-rect::after{ content:""; position:absolute; width:6px; height:28px; background:var(--leg); bottom:-28px; }
    .desk-rect::before{ left:10px; } .desk-rect::after{ right:10px; }

    /* BASE CHAIR (dimensioned by variables) */
    .chair-back{ width:var(--back-w); height:var(--back-h); background:var(--chair-back); border:2px solid var(--chair-border); border-radius:var(--back-r); margin:0 auto; position:relative; }
    .chair-seat{ width:var(--seat-w); height:var(--seat-h); background:var(--chair-seat); border:2px solid var(--chair-border); border-radius:var(--seat-r); margin:var(--seat-mt) auto 0; position:relative; z-index:0; }

    /* SHAPE EXTRAS (composable) */
    #stage.no-back .chair-back{ display:none; }
    #stage.extra-tablet .chair-seat::after{ content:""; position:absolute; right:-18px; top:-10px; width:28px; height:16px; background:var(--desk-grad-1); border:2px solid var(--desk-border); border-radius:5px; }
    #stage.extra-post .chair-seat::after{ content:""; position:absolute; left:50%; transform:translateX(-50%); bottom:-22px; width:6px; height:24px; background:var(--chair-border); border-radius:3px; }
    #stage.extra-tripod .chair-seat::before, #stage.extra-tripod .chair-seat::after{ content:""; position:absolute; bottom:-16px; width:6px; height:16px; background:var(--chair-border); border-radius:3px; }
    #stage.extra-tripod .chair-seat::before{ left:26%; transform:rotate(6deg); }
    #stage.extra-tripod .chair-seat::after{ right:26%; transform:rotate(-6deg); }
    #stage.extra-wings .chair-back::before, #stage.extra-wings .chair-back::after{ content:""; position:absolute; top:4px; width:10px; height:18px; background:var(--chair-back); border:2px solid var(--chair-border); border-radius:8px; }
    #stage.extra-wings .chair-back::before{ left:-12px; } #stage.extra-wings .chair-back::after{ right:-12px; }
    #stage.extra-stripes .chair-back{ background: repeating-linear-gradient(90deg, rgba(0,0,0,.10) 0 6px, rgba(255,255,255,.12) 6px 12px), var(--chair-back); }
    #stage.extra-notch .chair-seat::after{ content:""; position:absolute; left:50%; transform:translateX(-50%); top:-6px; width:14px; height:8px; background:var(--chair-seat); border:2px solid var(--chair-border); border-bottom:none; border-radius:10px 10px 0 0; }
    #stage.extra-splitback .chair-back{ background:transparent; border-color:transparent; height:0; }
    #stage.extra-splitback .chair-back::before, #stage.extra-splitback .chair-back::after{ content:""; position:absolute; top:-22px; width:30px; height:20px; background:var(--chair-back); border:2px solid var(--chair-border); border-radius:4px; }
    #stage.extra-splitback .chair-back::before{ left:0; } #stage.extra-splitback .chair-back::after { right:0; }

    /* AVATAR: head tilt */
    .avatar-wrapper{ position:absolute; top:-20px; left:50%; transform:translateX(-50%); width:60px; height:60px; z-index:2; transform-style: preserve-3d; }
    .avatar-bias{ width:100%; height:100%; transform:none; }
    .avatar-img{ width:100%; height:100%; object-fit:contain; display:block; animation: head-tilt var(--headDur, 2.8s) ease-in-out infinite; transform-origin: 50% 76%; backface-visibility: hidden; -webkit-backface-visibility: hidden; will-change: transform; transform: translateZ(0); }
    @keyframes head-tilt{ 0%,100% { transform: rotateZ(0deg); } 33% { transform: rotateZ(6deg); } 66% { transform: rotateZ(-6deg); } }
    body.respect-reduced-motion .avatar-img{ animation: none !important; }

    .seat .name{ margin-top:-18px; font-size:12px; text-align:center; font-weight:700; color:#1f2937; text-shadow: 0 1px 0 rgba(255,255,255,.9), 0 0 2px rgba(0,0,0,.08); pointer-events:none; }
    .draggable{ cursor:grab; } .dragging{ opacity:.9; cursor:grabbing; }

    /* Right-side status bubble */
    .status-bubble{ position:absolute; top:6px; left:calc(100% + 8px); background:#fff; border:2px solid #111; border-radius:9999px; padding:8px 12px; font-size:12px; font-weight:700; white-space:nowrap; box-shadow:0 2px 4px rgba(0,0,0,.2); opacity:1; pointer-events:none; }
    .status-bubble::before,.status-bubble::after{ content:""; position:absolute; background:#fff; border:2px solid #111; border-radius:50%; }
    .status-bubble::before{ width:10px; height:10px; left:-14px; top:22px; }
    .status-bubble::after { width:6px; height:6px; left:-22px; top:28px; }

    /* When a student is away, hide the avatar & bubble entirely */
/* Hide only the avatar image, but keep the bubble visible */
.seat.is-away .avatar-img { visibility: hidden; }
.seat.is-away .name { opacity: 0.6; visibility: visible; color: black }

    /* (optional) keep, remove if you don’t want any dimming */
.seat.is-away .desk-rect,
.seat.is-away .chair-back,
.seat.is-away .chair-seat{
  opacity:.9;     /* tweak or remove */
  filter:none;    /* keep normal furniture color; feels like an empty seat */
}

    /* Modal */
    .modal{ position:fixed; inset:0; background:rgba(0,0,0,.45); display:none; align-items:center; justify-content:center; z-index:100; }
    .modal.open{ display:flex; }
    .modal-card{ background:#fff; width:min(1100px,96vw); border-radius:16px; padding:18px; box-shadow:0 20px 60px rgba(0,0,0,.35); }

    .tpl{ border:2px solid #e5e7eb; border-radius:12px; padding:12px; transition:.15s; cursor:pointer; background:#fafafa; }
    .tpl:hover{ border-color:#94a3b8; background:#fff; }
    .tpl img{ width:100%; height:auto; display:block; }

    .tab-btn{ padding:.45rem .75rem; border-radius:.6rem; border:1px solid #e5e7eb; background:#f8fafc; font-weight:700; }
    .tab-btn.active{ background:#fde68a; border-color:#f59e0b; }

    .style-btn{ display:flex; align-items:center; gap:.6rem; padding:.45rem .6rem; border-radius:.6rem; background:#f9fafb; border:1px solid #e5e7eb; font-weight:600; }
    .style-btn.active{ outline:2px solid #facc15; background:#fff; color:#111827; }

    .swatch{ width:22px; height:22px; border-radius:.35rem; border:1px solid #0f172a22; }
    .grid-auto{ display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:.6rem; }
  </style>
</head>
<body>
  <div class="grid grid-cols-12 gap-4 p-4">
    <!-- Sidebar (view-only: info only) -->
    <aside class="ctl col-span-12 md:col-span-3 lg:col-span-2 bg-[#386641] text-white rounded-2xl p-4 space-y-5">
      <div>
        <h3 class="text-xl">SMARTCLASS — View</h3>
        <div class="text-xs opacity-80 mt-1">
          <?= htmlspecialchars($class_name) ?> • <?= htmlspecialchars($subject_name) ?> • <?= htmlspecialchars($year_label) ?>
        </div>
      </div>

      <div class="space-y-2">
        <h3 class="text-sm uppercase opacity-90">Mode</h3>
        <div class="text-sm opacity-90">Viewing mode — no editing allowed</div>
      </div>

      <div class="space-y-2">
        <h3 class="text-sm uppercase opacity-90">Info</h3>
        <div class="text-xs opacity-90">
          Viewing as: <b><?= htmlspecialchars($teacherName) ?></b><br/>
          This view refreshes automatically to show current student statuses.
        </div>
      </div>
    </aside>

    <!-- Main -->
    <main class="col-span-12 md:col-span-9 lg:col-span-10">
      <div class="bg-white rounded-2xl shadow p-5 mb-4">
        <div class="flex items-center justify-between">
          <h1 class="text-2xl md:text-3xl font-bold text-[#bc6c25]">🏫 2D Classroom Simulator — View</h1>
          <button id="backBtn" class="btn bg-gray-100 hover:bg-gray-200 text-gray-800">← Back</button>
        </div>
        <div class="text-sm text-gray-700 mt-1" id="stats">Loading…</div>
      </div>

      <div id="stage" class="p-2">
        <div id="seatLayer"></div>
      </div>
    </main>
  </div>

  <!-- Toast (kept for simple messages) -->
  <div id="toast" class="fixed top-5 right-5 hidden px-4 py-3 rounded shadow text-white"></div>

  <script>
    // Build an absolute API base that works in local and deployment
const API = <?php
  $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
         || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
  $scheme = $https ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $path = $_SERVER['PHP_SELF'] ?? '/';
  $base = preg_replace('~(?i)(/CMS)?/user/.*$~', '$1', $path);
  $base = rtrim($base, '/');
  $apiBase = "$scheme://$host$base/api";
  echo json_encode($apiBase);
?>;

const ROOT = API.replace(/\/api$/, '');
const AVATAR_BASE = ROOT + '/avatar';
const AVATAR_FALLBACK = AVATAR_BASE + '/default-student.png';

function fixAvatar(u) {
  if (!u || typeof u !== 'string') return AVATAR_FALLBACK;
  if (u.startsWith('data:')) return u;
  let p = u.trim();
  try { if (/^https?:\/\//i.test(p)) p = new URL(p).pathname; } catch {}
  if (!p.startsWith('/')) p = '/' + p;
  p = p
    .replace(/^\/CMS\//i, '/')
    .replace(/^\/img\/avatar\//i, '/avatar/')
    .replace(/^\/img\/avatars\//i, '/avatar/')
    .replace(/^\/avatars?\//i, '/avatar/');
  if (/^\/[^/]+\.(png|jpe?g|gif|webp)$/i.test(p)) {
    return `${AVATAR_BASE}${p}`;
  }
  if (/^\/avatar\/.+\.(png|jpe?g|gif|webp)$/i.test(p)) {
    return `${ROOT}${p}`;
  }
  return AVATAR_FALLBACK;
}

const FETCH_OPTS = {
  credentials: 'same-origin',
  headers: { 'X-Requested-With': 'XMLHttpRequest' }
};

// small helpers
function modeText(){ return mode==='quiz' ? '✏️' : (mode==='discussion' ? '📖' : ''); }
function actionOverlayText(){ return globalAction==='break' ? '🍱' : (globalAction==='out' ? '🚪' : ''); }
function actionText(act){
  const icon = {
    restroom:'🚻', snack:'🍎', lunch_break:'🍱', water_break:'💧', not_well:'🤒',
    borrow_book:'📚', return_material:'📦', participated:'✅', help_request:'✋',
    attendance:'🟢', im_back:'🟢', out_time:'🚪'
  };
  return icon[act] || '•';
}

const SY = <?= $school_year_id ?>, AD = <?= $advisory_id ?>, SJ = <?= $subject_id ?>;
const stage     = document.getElementById('stage');
const seatLayer = document.getElementById('seatLayer');
const seatCountEl= document.getElementById('seatCount') || { textContent: '' };
const toastBox  = document.getElementById('toast');
const backBtn   = document.getElementById('backBtn');

const COLOR_KEY = `sim:chairColor:${SY}:${AD}:${SJ}`;
const SHAPE_KEY = `sim:chairShape:${SY}:${AD}:${SJ}`;
const BACK_KEY  = `sim:backSet:${SY}:${AD}:${SJ}`;

let mode = null;
let globalAction = null;
let students = [];
let seats = [];
let backSet = new Set(JSON.parse(localStorage.getItem(BACK_KEY) || '[]'));
let behaviorMap = {};

const AWAY_ACTIONS = new Set(['restroom','snack','lunch_break','out_time','water_break','not_well','borrow_book','return_material','log_out']);
const ACTION_LABELS = {
  restroom:'Restroom', snack:'Snack', lunch_break:'Lunch Break', water_break:'Water Break',
  not_well:'Not Feeling Well', borrow_book:'Borrowing Book', return_material:'Returning Material',
  participated:'Participated', help_request:'Needs Help', attendance:'Attendance', im_back:'I’m Back', out_time:'Out Time'
};

function toast(msg, type='success'){
  toastBox.textContent = msg;
  toastBox.className = 'fixed top-5 right-5 px-4 py-3 rounded shadow text-white ' + (type==='error'?'bg-red-500':'bg-green-600');
  toastBox.style.display = 'block';
  setTimeout(()=>toastBox.style.display='none', 2200);
}
const clamp=(v,min,max)=>Math.max(min,Math.min(max,v));
const saveBackSet = ()=> localStorage.setItem(BACK_KEY, JSON.stringify([...backSet]));
const markStudentBack = id => { backSet.add(String(id)); saveBackSet(); renderSeats(); };
const markStudentAway = id => { backSet.delete(String(id)); saveBackSet(); renderSeats(); };
const clearBackSet = ()=>{ backSet.clear(); saveBackSet(); };

// Placement helpers (copied as-is)
function stageMetrics(){
  const pad=14, seatW=100, seatH=96, gapX=22, gapY=24;
  const rect=stage.getBoundingClientRect();
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

const placeRows=(n=30)=>placeGridCentered(6,5,n);
const placePairs=(n=12)=>placeGridCentered(6,2,n);
const placeGrid25=(n=25)=>placeGridCentered(5,5,n);

function placeGroupsOfFour(n=24){
  const M=stageMetrics(), groupsX=3, groupsY=2, groupSeatW=2*M.seatW+M.gapX, groupSeatH=2*M.seatH+M.gapY, interGapX=M.gapX*2, interGapY=M.gapY*2;
  const totalW=groupsX*groupSeatW+(groupsX-1)*interGapX, totalH=groupsY*groupSeatH+(groupsY-1)*interGapY;
  const startX=Math.max(M.pad,Math.round((M.rectW-totalW)/2)), startY=Math.max(M.pad,Math.round((M.rectH-totalH)/2)-10);
  const pos=[];
  for(let gy=0;gy<groupsY;gy++){
    for(let gx=0;gx<groupsX;gx++){
      const baseX=startX+gx*(groupSeatW+interGapX), baseY=startY+gy*(groupSeatH+interGapY);
      const cells=[
        {x:baseX,y:baseY},
        {x:baseX+(M.seatW+M.gapX),y:baseY},
        {x:baseX,y:baseY+(M.seatH+M.gapY)},
        {x:baseX+(M.seatW+M.gapX),y:baseY+(M.seatH+M.gapY)}
      ];
      for(const c of cells){ pos.push(c); if(pos.length===n) return pos; }
    }
  }
  return pos;
}

function placeUShape(bottomWanted=10, sideRowsWanted=3, n=16){
  const M=stageMetrics(), bottom=Math.max(3,Math.min(bottomWanted,M.maxCols));
  const totalBottomW=bottom*M.seatW+(bottom-1)*M.gapX;
  const startX=Math.max(M.pad,Math.round((M.rectW-totalBottomW)/2));
  const bottomY=Math.max(M.pad,M.rectH-M.pad-M.seatH);
  const pos=[];
  for(let c=0;c<bottom && pos.length<n;c++){
    pos.push({x:startX+c*(M.seatW+M.gapX), y:bottomY});
  }
  const sideRows=Math.max(1,Math.min(sideRowsWanted,M.maxRows-1));
  const leftX=startX, rightX=startX+(bottom-1)*(M.seatW+M.gapX);
  const topMostY=Math.max(M.pad,bottomY-sideRows*(M.seatH+M.gapY));
  for(let r=0;r<sideRows && pos.length<n;r++){
    const y=bottomY-(r+1)*(M.seatH+M.gapY);
    pos.push({x:leftX,y:Math.max(topMostY,y)});
    if(pos.length<n) pos.push({x:rightX,y:Math.max(topMostY,y)});
  }
  return pos;
}

// Data loading & roster sync (slimmed)
async function loadData(){
  try {
    const [S,P] = await Promise.all([
      fetch(`${API}/get_students.php`, { ...FETCH_OPTS }).then(r=>r.json()),
      fetch(`${API}/get_seating.php`,  { ...FETCH_OPTS }).then(r=>r.json())
    ]);
    students = S.students || [];
    seats = normalizeSeating(P.seating);
    const validIds=new Set(students.map(s=>s.student_id));
    seats.forEach(s=>{ if(s.student_id && !validIds.has(s.student_id)) s.student_id=null; });
    autoAssignUnseated();
    if(seats.length===0){
      const pos=placeGrid25(10);
      seats=pos.map((p,i)=>({seat_no:i+1,student_id:null,x:p.x,y:p.y}));
    }
    renderSeats();
    renderStats();
    await refreshBehavior();
  } catch(e){
    console.error('loadData error', e);
  }
}

function normalizeSeating(raw){
  if(!raw) return [];
  if(Array.isArray(raw)){
    return raw.map((r,i)=>({
      seat_no:parseInt(r.seat_no||i+1,10),
      student_id:(r.student_id!==null && r.student_id!=='')?parseInt(r.student_id,10):null,
      x:(r.x!=null)?parseFloat(r.x):null,
      y:(r.y!=null)?parseFloat(r.y):null
    }));
  }
  const arr=[];
  Object.keys(raw).forEach((k,i)=>arr.push({seat_no:parseInt(k,10),student_id:raw[k]?parseInt(raw[k],10):null,x:null,y:null}));
  return arr.sort((a,b)=>a.seat_no-b.seat_no);
}

function autoAssignUnseated(){
  const seatedIds=new Set(seats.map(s=>s.student_id).filter(v=>v!=null));
  const empties=()=>seats.filter(s=>s.student_id==null);
  students.forEach(stu=>{
    if(!seatedIds.has(stu.student_id)){
      let slot=empties()[0];
      if(!slot){
        const M=stageMetrics();
        const idx=seats.length;
        const c=idx%Math.max(1,M.maxCols);
        const r=Math.floor(idx/Math.max(1,M.maxCols));
        seats.push({seat_no:idx+1,student_id:null,x:M.pad+c*(M.seatW+M.gapX),y:M.pad+r*(M.seatH+M.gapY)});
        slot=seats[seats.length-1];
      }
      slot.student_id=stu.student_id;
      seatedIds.add(stu.student_id);
    }
  });
}

// Layout apply (viewer doesn't expose customizer, but keep function for possible external triggers)
function applyLayout(kind){
  const assigned=seats.filter(s=>s.student_id!=null).map(s=>s.student_id);
  let positions;
  switch(kind){
    case 'pairs': positions=placePairs(12); break;
    case 'grid': positions=placeGrid25(25); break;
    case 'rows': positions=placeRows(30); break;
    case 'g4': positions=placeGroupsOfFour(24); break;
    case 'ushape': positions=placeUShape(10,3,16); break;
    default: positions=placeGrid25(25);
  }
  if(assigned.length>positions.length){
    toast(`Layout has ${positions.length} chairs but you have ${assigned.length} seated students.`, 'error');
    return;
  }
  seats=positions.map((p,i)=>({seat_no:i+1,student_id:assigned[i]||null,x:p.x,y:p.y}));
  renderSeats(); renderStats();
}

// Rendering (no drag, no menu)
function renderSeats(){
  seatLayer.innerHTML='';
  const M=stageMetrics();
  seats=seats.map((s,i)=>{
    if(s.x==null || s.y==null){
      const c=i%Math.max(1,M.maxCols), r=Math.floor(i/Math.max(1,M.maxCols));
      s.x=M.pad+c*(M.seatW+M.gapX); s.y=M.pad+r*(M.seatH+M.gapY);
    }
    return s;
  });

  const colsForBias=Math.max(1,M.maxCols);
  seats.forEach((seat,i)=>{
    const node=document.createElement('div');
    node.className='seat';
    node.dataset.seatNo=seat.seat_no;
    node.dataset.studentId=seat.student_id ?? '';
    node.style.left=(seat.x ?? 14)+'px';
    node.style.top=(seat.y ?? 14)+'px';

    const s=students.find(x=>x.student_id==seat.student_id);
    const hasStudent=!!s;
    const img = fixAvatar(s?.avatar_url);
    const name = s?.fullname || 'Empty';

    const colIndex=i%colsForBias; const biasClass=(colIndex%2===0)?'tilt-left':'tilt-right';

    const st=hasStudent ? behaviorMap[String(s.student_id)] : null;
    const actionKey=st ? String(st.action||'').toLowerCase() : '';
    const individuallyAway = !!(st && (st.is_away || AWAY_ACTIONS.has(actionKey)));
    const isBackOverride   = backSet.has(String(seat.student_id));
    const overlayApplies   = !!globalAction && !isBackOverride && !(st && !individuallyAway);
    const isAway           = isBackOverride ? false : (overlayApplies || individuallyAway);

    node.innerHTML = `
      <div class="card ${hasStudent?'has-student':'empty'} ${isAway?'is-away':''}">
        ${hasStudent ? `
          <div class="avatar-wrapper">
            <div class="avatar-bias ${biasClass}">
              <img src="${img}" class="avatar-img"
 onerror="this.onerror=null;this.src='${AVATAR_FALLBACK}';"
 style="--headDur:${(2.4+Math.random()*1.4).toFixed(2)}s;animation-delay:${(Math.random()*1.8).toFixed(2)}s;" />
            </div>
            ${(()=>{ const txt = overlayApplies ? actionOverlayText() : (individuallyAway ? actionText(actionKey) : modeText()); return txt ? `<div class="status-bubble">${txt}</div>` : '' })()}
          </div>
        ` : ''}
        <div class="desk-rect"></div>
        <div class="chair-back"></div>
        <div class="chair-seat"></div>
      </div>
      <div class="name ${hasStudent?'':'is-empty'}">${hasStudent?name:''}</div>
    `;

    if(hasStudent) node.classList.add('has-student'); else node.classList.remove('has-student');
    if(isAway) node.classList.add('is-away'); else node.classList.remove('is-away');

    seatLayer.appendChild(node);
  });
}

function renderStats(){
  const total=students.length;
  const seatedCount=seats.reduce((a,s)=>a+(s.student_id!=null?1:0),0);
  document.getElementById('stats').textContent=`Students: ${total} • Seated: ${seatedCount} • Chairs: ${seats.length}`;
}

// Behavior refresh
async function refreshBehavior(){
  try{
    const res=await fetch(`${API}/get_behavior_status.php`, { ...FETCH_OPTS }).then(r=>r.json());
    if(!res || res.ok===false) return;
    behaviorMap={};
    if(res.map && typeof res.map==='object'){
      behaviorMap=res.map;
    } else if(Array.isArray(res)){
      res.forEach(row=>{
        const act=String(row.action_type||'').toLowerCase();
        behaviorMap[String(row.student_id)]={
          action:act,
          label:ACTION_LABELS[act]||row.label||'',
          is_away:AWAY_ACTIONS.has(act),
          timestamp:row.timestamp
        };
      });
    }
    renderSeats();
  }catch(e){ console.error('refreshBehavior error:',e); }
}

// Periodic student refresh (only updates roster/assignment)
async function refreshStudents(){
  try{
    const S=await fetch(`${API}/get_students.php`, { ...FETCH_OPTS }).then(r=>r.json());
    const newList=S.students||[];
    const newIds=new Set(newList.map(s=>s.student_id));
    seats.forEach(s=>{ if(s.student_id!=null && !newIds.has(s.student_id)) s.student_id=null; });
    students=newList; autoAssignUnseated(); renderSeats(); renderStats();
  }catch(e){ /* ignore */ }
}

backBtn.onclick=()=>history.back();

// initialize viewer themes from localStorage (visual only)
function applyColorTheme(id){
  const t = (window.THEMES && window.THEMES.find(x=>x.id===id)) || null;
  if(!t) return;
  stage.style.setProperty('--desk-grad-1',t.d1);
  stage.style.setProperty('--desk-grad-2',t.d2);
  stage.style.setProperty('--desk-border',t.db);
  stage.style.setProperty('--leg',t.leg);
  stage.style.setProperty('--chair-seat',t.seat);
  stage.style.setProperty('--chair-back',t.back);
  stage.style.setProperty('--chair-border',t.cb);
}
function applyChairShape(id){
  // shapes not embedded here; viewer will rely on existing stage CSS vars if present
  // no-op if not stored
}
window.addEventListener('resize', ()=>{
  const seatedIDs=seats.filter(s=>s.student_id!=null).map(s=>s.student_id);
  const N=seats.length, maxCols=Math.min(stageMetrics().maxCols,8);
  const positions=placeGridCentered(maxCols,Math.ceil(N/Math.max(1,maxCols)),N);
  seats=positions.map((p,i)=>({seat_no:i+1,student_id:seatedIDs[i]||null,x:p.x,y:p.y}));
  renderSeats(); refreshBehavior();
});

loadData();
setInterval(refreshBehavior, 3000);
setInterval(refreshStudents, 6000);
  </script>
</body>
</html>
