<?php
/**
 * STUDENT — READ-ONLY CLASSROOM LIVE VIEW
 * Mirrors the teacher’s 2D Classroom Simulator (same layout & bubbles), but no controls.
 * Path: /user/classroom_view.php
 */

session_start();

// Students only (must be logged in). We use their session context (advisory/subject/SY).
if (!isset($_SESSION['student_id'])) { header("Location: ../index.php"); exit; }

$student_id     = (int)$_SESSION['student_id'];
$fullname       = $_SESSION['fullname'] ?? 'Student';
$subject_id     = (int)($_SESSION['subject_id'] ?? 0);
$advisory_id    = (int)($_SESSION['advisory_id'] ?? 0);
$school_year_id = (int)($_SESSION['school_year_id'] ?? 0);
$subject_name   = $_SESSION['subject_name'] ?? 'Subject';
$class_name     = $_SESSION['class_name'] ?? 'Section';
$year_label     = $_SESSION['year_label'] ?? 'SY';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>👀 Classroom Live View</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body { background:#fefae0; font-family:'Comic Sans MS', cursive, sans-serif; }
    .btn { padding:.5rem .75rem; border-radius:.5rem; font-weight:700; }

    /* Stage — same visuals as teacher/classroom_simulator.php */
    #stage{
      position: relative;
      min-height: 540px;
      height: 72vh;
      background: url('../img/bg-8.png') center center / cover no-repeat;
      border-radius: 12px;
      overflow: hidden;
      box-shadow: inset 0 0 20px rgba(0,0,0,0.15);

      /* theme variables (default; teacher may change via CSS vars in future) */
      --desk-grad-1:#e6cfa7;
      --desk-grad-2:#d2a86a;
      --desk-border:#a16a2a;
      --leg:#8b5e34;
      --chair-seat:#d1d5db;
      --chair-back:#9ca3af;
      --chair-border:#6b7280;

      /* chair shape variables */
      --back-w:70px; --back-h:28px; --back-r:4px;
      --seat-w:70px; --seat-h:18px; --seat-r:4px; --seat-mt:-6px;
    }
    #seatLayer{ position:relative; width:100%; height:100%; }

    .seat{ width:100px; position:absolute; user-select:none; transition:opacity .2s ease; }
    .seat .card{ position:relative; background:transparent; border:none; box-shadow:none; text-align:center; }

    /* Desk */
    .desk-rect{ width:90px; height:40px; background:linear-gradient(180deg,var(--desk-grad-1) 0%,var(--desk-grad-2) 100%); border:2px solid var(--desk-border); border-radius:6px 6px 2px 2px; margin:0 auto; position:relative; z-index:1; }
    .desk-rect::before,.desk-rect::after{ content:""; position:absolute; width:6px; height:28px; background:var(--leg); bottom:-28px; }
    .desk-rect::before{ left:10px; } .desk-rect::after{ right:10px; }

    /* Chair (dimensioned by vars) */
    .chair-back{ width:var(--back-w); height:var(--back-h); background:var(--chair-back); border:2px solid var(--chair-border); border-radius:var(--back-r); margin:0 auto; position:relative; }
    .chair-seat{ width:var(--seat-w); height:var(--seat-h); background:var(--chair-seat); border:2px solid var(--chair-border); border-radius:var(--seat-r); margin:var(--seat-mt) auto 0; position:relative; z-index:0; }

    /* Avatar + light head tilt */
    .avatar-wrapper{ position:absolute; top:-20px; left:50%; transform:translateX(-50%); width:60px; height:60px; z-index:2; transform-style: preserve-3d; }
    .avatar-bias{ width:100%; height:100%; }
    .avatar-img{ width:100%; height:100%; object-fit:contain; display:block; animation: head-tilt var(--headDur, 2.8s) ease-in-out infinite; transform-origin: 50% 76%; backface-visibility: hidden; -webkit-backface-visibility: hidden; will-change: transform; transform: translateZ(0); }
    @keyframes head-tilt{ 0%,100% { transform: rotateZ(0deg); } 33% { transform: rotateZ(6deg); } 66% { transform: rotateZ(-6deg); } }
    body.respect-reduced-motion .avatar-img{ animation: none !important; }

    .seat .name{ margin-top:-18px; font-size:12px; text-align:center; font-weight:700; color:#1f2937; text-shadow: 0 1px 0 rgba(255,255,255,.9), 0 0 2px rgba(0,0,0,.08); pointer-events:none; }

    /* Right-side status bubble */
    .status-bubble{ position:absolute; top:6px; left:calc(100% + 8px); background:#fff; border:2px solid #111; border-radius:9999px; padding:8px 12px; font-size:12px; font-weight:700; white-space:nowrap; box-shadow:0 2px 4px rgba(0,0,0,.2); opacity:1; pointer-events:none; }
    .status-bubble::before,.status-bubble::after{ content:""; position:absolute; background:#fff; border:2px solid #111; border-radius:50%; }
    .status-bubble::before{ width:10px; height:10px; left:-14px; top:22px; }
    .status-bubble::after { width:6px; height:6px; left:-22px; top:28px; }

    /* When away: hide only avatar; keep furniture + name visible/dimmed */
    .seat.is-away .avatar-img { visibility: hidden; }
    .seat.is-away .name { opacity: 0.6; visibility: visible; color: #111; }

    /* Shell */
    .wrap{ max-width:1200px; margin-inline:auto; }
    .card{ background:#fff; border-radius:1rem; box-shadow:0 10px 30px rgba(0,0,0,.08); }
  </style>
</head>
<body class="min-h-screen p-4 md:p-6">
  <div class="wrap space-y-4">
    <div class="card p-4 flex items-center justify-between">
      <div>
        <div class="text-2xl md:text-3xl font-black text-[#bc6c25]">👀 Classroom Live View</div>
        <div class="text-sm text-gray-700 mt-1">
          <?= htmlspecialchars($class_name) ?> • <?= htmlspecialchars($subject_name) ?> • <?= htmlspecialchars($year_label) ?>
        </div>
        <div class="text-xs text-gray-600 mt-1">Welcome, <b><?= htmlspecialchars($fullname) ?></b></div>
      </div>
      <div class="flex items-center gap-2">
        <a href="dashboard.php" class="btn bg-gray-100 hover:bg-gray-200 text-gray-800">← Back</a>
      </div>
    </div>

    <div id="stage" class="p-2">
      <div id="seatLayer"></div>
    </div>

    <div class="card p-3">
      <div id="stats" class="text-sm text-gray-700">Loading…</div>
    </div>
  </div>

<script>
/* -------- API base that works for local (/CMS) and live (/user) -------- */
const API = <?php
  // detect scheme even behind proxies
  $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
         || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
  $scheme = $https ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $path   = $_SERVER['PHP_SELF'] ?? '/';
  // keep /CMS when local; strip /user/... to get root
  $base   = preg_replace('~(?i)(/CMS)?/user/.*$~', '$1', $path);
  $base   = rtrim($base, '/');
  echo json_encode("$scheme://$host$base/api");
?>;

const ROOT = API.replace(/\/api$/, '');
const AVATAR_BASE = ROOT + '/avatar';
const AVATAR_FALLBACK = AVATAR_BASE + '/default-student.png';

// Normalize stored avatar URLs (any old folder forms)
function fixAvatar(u){
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
  if (/^\/[^/]+\.(png|jpe?g|gif|webp)$/i.test(p)) return `${AVATAR_BASE}${p}`;
  if (/^\/avatar\/.+\.(png|jpe?g|gif|webp)$/i.test(p)) return `${ROOT}${p}`;
  return AVATAR_FALLBACK;
}

const ACTION_LABELS = {
  restroom:'Restroom', snack:'Snack', lunch_break:'Lunch Break', water_break:'Water Break',
  not_well:'Not Feeling Well', borrow_book:'Borrowing Book', return_material:'Returning Material',
  participated:'Participated', help_request:'Needs Help', attendance:'Attendance', im_back:'I’m Back', out_time:'Out Time'
};
const AWAY_ACTIONS = new Set(['restroom','snack','lunch_break','out_time','water_break','not_well','borrow_book','return_material','log_out']);

const SY = <?= $school_year_id ?>, AD = <?= $advisory_id ?>, SJ = <?= $subject_id ?>;

const stage     = document.getElementById('stage');
const seatLayer = document.getElementById('seatLayer');
const statsBox  = document.getElementById('stats');

let students = [];
let seats = [];
let behaviorMap = {};

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

async function loadData(){
  const [S,P] = await Promise.all([
    fetch(`${API}/get_students.php`, { credentials:'include' }).then(r=>r.json()),
    fetch(`${API}/get_seating.php`,  { credentials:'include' }).then(r=>r.json())
  ]);
  students = S.students || [];
  seats    = normalizeSeating(P.seating);

  // If no seat coords yet, produce a centered grid so chairs appear
  if(seats.length===0){
    const pos=placeGridCentered(5,5,10);
    seats=pos.map((p,i)=>({seat_no:i+1,student_id:null,x:p.x,y:p.y}));
  }
  renderSeats();
  renderStats();
  await refreshBehavior();
}

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

    const st = hasStudent ? behaviorMap[String(s.student_id)] : null;
    const actionKey = st ? String(st.action||'').toLowerCase() : '';
    const individuallyAway = !!(st && (st.is_away || AWAY_ACTIONS.has(actionKey)));
    const isAway = individuallyAway;

    node.innerHTML = `
      <div class="card ${hasStudent?'has-student':'empty'} ${isAway?'is-away':''}">
        ${hasStudent ? `
          <div class="avatar-wrapper">
            <div class="avatar-bias">
              <img src="${img}" class="avatar-img"
                   onerror="this.onerror=null;this.src='${AVATAR_FALLBACK}';"
                   style="--headDur:${(2.4+Math.random()*1.4).toFixed(2)}s;animation-delay:${(Math.random()*1.8).toFixed(2)}s;" />
            </div>
            ${(()=>{ 
              if (!st) return '';
              const label = AWAY_ACTIONS.has(actionKey) ? (ACTION_LABELS[actionKey]||'') : '';
              return label ? `<div class="status-bubble">${label}</div>` : '';
            })()}
          </div>
        ` : '' }
        <div class="desk-rect"></div>
        <div class="chair-back"></div>
        <div class="chair-seat"></div>
      </div>
      <div class="name ${hasStudent?'':'is-empty'}">${hasStudent?name:''}</div>
    `;

    if(isAway) node.classList.add('is-away'); else node.classList.remove('is-away');
    seatLayer.appendChild(node);
  });
}

function renderStats(){
  const total = students.length;
  const seatedCount = seats.reduce((a,s)=>a+(s.student_id!=null?1:0),0);
  statsBox.textContent = `Students: ${total} • Chairs: ${seats.length} • Seated (assigned): ${seatedCount}`;
}

async function refreshBehavior(){
  try{
    const res = await fetch(`${API}/get_behavior_status.php`, { credentials:'include', cache:'no-store' });
    const data = await res.json();
    behaviorMap = {};
    if (data?.map && typeof data.map === 'object') {
      behaviorMap = data.map;
    } else if (Array.isArray(data)) {
      data.forEach(row=>{
        const act = String(row.action_type||'').toLowerCase();
        behaviorMap[String(row.student_id)] = {
          action: act,
          label: ACTION_LABELS[act]||row.label||'',
          is_away: AWAY_ACTIONS.has(act),
          timestamp: row.timestamp
        };
      });
    }
    renderSeats();
  }catch(e){
    // keep last render silently
  }
}

/* Keep layout centered on resize */
window.addEventListener('resize', ()=>{
  const N=seats.length;
  const maxCols=Math.min(stageMetrics().maxCols,8);
  const positions=placeGridCentered(maxCols,Math.ceil(N/Math.max(1,maxCols)),N);
  seats=positions.map((p,i)=>({seat_no:i+1,student_id:seats[i]?.student_id??null,x:p.x,y:p.y}));
  renderSeats();
});

/* Init + light polling */
loadData();
setInterval(refreshBehavior, 3000);
setInterval(async ()=>{
  try{
    const S=await fetch(`${API}/get_students.php`, { credentials:'include' }).then(r=>r.json());
    if (Array.isArray(S.students)) { students = S.students; renderSeats(); }
  }catch(e){}
}, 8000);
</script>
</body>
</html>
