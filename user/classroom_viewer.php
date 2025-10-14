<?php
// simulator_student_view.php
// Read-only student view of the 2D classroom simulator.
// Requires a logged-in student (redirects to student_login.php if not present).

include __DIR__ . '/../config/db.php';
session_start();

// If your system uses another session key for students, change these accordingly.
if (!isset($_SESSION['student_id'])) {
  header("Location: student_login.php");
  exit;
}

$studentName    = $_SESSION['student_fullname'] ?? 'Student';
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
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Classroom — Student View</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    /* Keep most styles compatible with teacher simulator but hide controls */
    body { background:#fefae0; font-family:'Comic Sans MS', cursive, sans-serif; }
    #stage{ position: relative; min-height: 540px; height: 72vh; background: url('../../img/bg-8.png') center center / cover no-repeat; border-radius:12px; overflow:hidden; box-shadow: inset 0 0 20px rgba(0,0,0,0.15); }
    #seatLayer{ position:relative; width:100%; height:100%; }
    .seat{ width:100px; position:absolute; user-select:none; transition:opacity .2s ease; }
    .seat .card{ position:relative; background:transparent; border:none; box-shadow:none; text-align:center; }
    .desk-rect{ width:90px; height:40px; background:linear-gradient(180deg,var(--desk-grad-1) 0%,var(--desk-grad-2) 100%); border:2px solid var(--desk-border); border-radius:6px 6px 2px 2px; margin:0 auto; position:relative; z-index:1; }
    .chair-back{ width:var(--back-w); height:var(--back-h); background:var(--chair-back); border:2px solid var(--chair-border); border-radius:var(--back-r); margin:0 auto; position:relative; }
    .chair-seat{ width:var(--seat-w); height:var(--seat-h); background:var(--chair-seat); border:2px solid var(--chair-border); border-radius:var(--seat-r); margin:var(--seat-mt) auto 0; position:relative; z-index:0; }
    .avatar-wrapper{ position:absolute; top:-20px; left:50%; transform:translateX(-50%); width:60px; height:60px; z-index:2; transform-style: preserve-3d; }
    .avatar-img{ width:100%; height:100%; object-fit:contain; display:block; animation: head-tilt var(--headDur, 2.8s) ease-in-out infinite; transform-origin: 50% 76%; backface-visibility: hidden; will-change: transform; transform: translateZ(0); }
    @keyframes head-tilt{ 0%,100% { transform: rotateZ(0deg); } 33% { transform: rotateZ(6deg); } 66% { transform: rotateZ(-6deg); } }
    .seat .name{ margin-top:-18px; font-size:12px; text-align:center; font-weight:700; color:#1f2937; text-shadow: 0 1px 0 rgba(255,255,255,.9), 0 0 2px rgba(0,0,0,.08); pointer-events:none; }
    .status-bubble{ position:absolute; top:6px; left:calc(100% + 8px); background:#fff; border:2px solid #111; border-radius:9999px; padding:8px 12px; font-size:12px; font-weight:700; white-space:nowrap; box-shadow:0 2px 4px rgba(0,0,0,.2); opacity:1; pointer-events:none; }
    .seat.is-away .avatar-img { visibility: hidden; }
    .seat.is-away .name { opacity: 0.6; visibility: visible; color: black }
    .seat.is-away .desk-rect, .seat.is-away .chair-back, .seat.is-away .chair-seat{ opacity:.9; filter:none; }
    .topbar{ display:flex; justify-content:space-between; align-items:center; gap:12px; padding:12px; background:#fff; border-radius:12px; box-shadow:0 6px 18px rgba(0,0,0,.06); }
    .small-info{ font-size:13px; color:#374151; }
    /* remove pointer events for everything that would normally change state */
    .read-only * { pointer-events: none; }
    /* Except allow links/back button: enable pointer events on them specifically */
    .allow-click { pointer-events: auto !important; }
  </style>
</head>
<body>
  <div class="p-4 max-w-7xl mx-auto">
    <div class="topbar mb-4">
      <div>
        <h1 class="text-2xl font-bold text-[#bc6c25]">🏫 Classroom — Student View</h1>
        <div class="small-info mt-1"><?= htmlspecialchars($class_name) ?> • <?= htmlspecialchars($subject_name) ?> • <?= htmlspecialchars($year_label) ?></div>
      </div>
      <div class="text-right">
        <div class="text-sm">Welcome, <b><?= htmlspecialchars($studentName) ?></b></div>
        <a href="javascript:history.back()" class="mt-2 inline-block allow-click px-3 py-2 rounded bg-gray-100 text-gray-800">← Back</a>
      </div>
    </div>

    <div id="stage" class="p-2 read-only">
      <div id="seatLayer"></div>
    </div>

    <div class="mt-3 text-sm text-gray-600">This is a live, read-only view of the classroom. Avatars and status update automatically.</div>
  </div>

  <script>
    // --- API / ROOT detection (same logic as teacher file) ---
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

    function fixAvatar(u){
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
    const SY = <?= $school_year_id ?>, AD = <?= $advisory_id ?>, SJ = <?= $subject_id ?>;
    const stage = document.getElementById('stage');
    const seatLayer = document.getElementById('seatLayer');

    // theme variables default
    const defaultTheme = {
      d1:'#e6cfa7', d2:'#d2a86a', db:'#a16a2a', leg:'#8b5e34', seat:'#d1d5db', back:'#9ca3af', cb:'#6b7280'
    };
    stage.style.setProperty('--desk-grad-1', defaultTheme.d1);
    stage.style.setProperty('--desk-grad-2', defaultTheme.d2);
    stage.style.setProperty('--desk-border', defaultTheme.db);
    stage.style.setProperty('--leg', defaultTheme.leg);
    stage.style.setProperty('--chair-seat', defaultTheme.seat);
    stage.style.setProperty('--chair-back', defaultTheme.back);
    stage.style.setProperty('--chair-border', defaultTheme.cb);
    stage.style.setProperty('--back-w','70px'); stage.style.setProperty('--back-h','28px'); stage.style.setProperty('--back-r','4px');
    stage.style.setProperty('--seat-w','70px'); stage.style.setProperty('--seat-h','18px'); stage.style.setProperty('--seat-r','4px'); stage.style.setProperty('--seat-mt','-6px');

    let students = [];
    let seats = [];
    let behaviorMap = {};

    const AWAY_ACTIONS = new Set(['restroom','snack','lunch_break','water_break','not_well','borrow_book','return_material','log_out','out_time']);
    const ACTION_LABELS = { restroom:'Restroom', snack:'Snack', lunch_break:'Lunch Break', water_break:'Water Break', not_well:'Not Feeling Well', borrow_book:'Borrowing Book', return_material:'Returning Material', participated:'Participated', help_request:'Needs Help', attendance:'Attendance', im_back:'I’m Back', out_time:'Out Time' };

    function actionText(act){
      const icon = { restroom:'🚻', snack:'🍎', lunch_break:'🍱', water_break:'💧', not_well:'🤒', borrow_book:'📚', return_material:'📦', participated:'✅', help_request:'✋', attendance:'🟢', im_back:'🟢', out_time:'🚪' };
      return icon[act] || '•';
    }

    // --- placement helpers (simpler copy of teacher logic) ---
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

    // --- data load/render ---
    async function loadData(){
      try{
        const [S,P] = await Promise.all([
          fetch(`${API}/get_students.php`, { ...FETCH_OPTS }).then(r=>r.json()),
          fetch(`${API}/get_seating.php`,  { ...FETCH_OPTS }).then(r=>r.json())
        ]);
        students = S.students || [];
        seats = normalizeSeating(P.seating);
        // strip invalid
        const validIds=new Set(students.map(s=>s.student_id));
        seats.forEach(s=>{ if(s.student_id && !validIds.has(s.student_id)) s.student_id=null; });
        autoAssignUnseated();
        if(seats.length===0){
          const pos=placeGridCentered(5,5,10);
          seats=pos.map((p,i)=>({seat_no:i+1,student_id:null,x:p.x,y:p.y}));
        }
        renderSeats();
        await refreshBehavior();
      }catch(e){ console.error('loadData error', e); }
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

      seats.forEach((seat,i)=>{
        const node=document.createElement('div');
        node.className='seat';
        node.dataset.seatNo=seat.seat_no;
        node.style.left=(seat.x ?? 14)+'px';
        node.style.top=(seat.y ?? 14)+'px';

        const s=students.find(x=>x.student_id==seat.student_id);
        const hasStudent=!!s;
        const img = fixAvatar(s?.avatar_path || s?.avatar_url);
        const name = s?.fullname || '';

        // behavior info for this student
        const st = s ? behaviorMap[String(s.student_id)] : null;
        const actionKey = st ? String(st.action || '').toLowerCase() : '';
        const individuallyAway = !!(st && (st.is_away || AWAY_ACTIONS.has(actionKey)));

        const isAway = individuallyAway;

        node.innerHTML = `
          <div class="card ${hasStudent?'has-student':''} ${isAway?'is-away':''}">
            ${hasStudent ? `
              <div class="avatar-wrapper">
                <img src="${img}" class="avatar-img" onerror="this.onerror=null;this.src='${AVATAR_FALLBACK}';" style="--headDur:${(2.4+Math.random()*1.4).toFixed(2)}s;" />
                ${ (individuallyAway ? `<div class="status-bubble">${actionText(actionKey)}</div>` : '') }
              </div>
            ` : ''}
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

    async function refreshBehavior(){
      try{
        const res = await fetch(`${API}/get_behavior_status.php`, { ...FETCH_OPTS }).then(r=>r.json());
        if(!res) return;
        behaviorMap = {};
        if(res.map && typeof res.map === 'object'){
          behaviorMap = res.map;
        } else if(Array.isArray(res)){
          res.forEach(row=>{
            const act = String(row.action_type||'').toLowerCase();
            behaviorMap[String(row.student_id)] = {
              action: act,
              label: ACTION_LABELS[act] || row.label || '',
              is_away: AWAY_ACTIONS.has(act),
              timestamp: row.timestamp
            };
          });
        }
        renderSeats();
      }catch(e){ console.error('refreshBehavior error', e); }
    }

    // auto refresh loops (read-only)
    loadData();
    setInterval(refreshBehavior, 3000);
    setInterval(async ()=>{ // refresh student roster every 6s
      try{
        const S = await fetch(`${API}/get_students.php`, { ...FETCH_OPTS }).then(r=>r.json());
        students = S.students || students;
        // ensure seats keep valid student ids
        const newIds = new Set(students.map(s=>s.student_id));
        seats.forEach(s=>{ if(s.student_id!=null && !newIds.has(s.student_id)) s.student_id=null; });
        autoAssignUnseated();
        renderSeats();
      }catch(e){ /* ignore */ }
    }, 6000);

    // respond to resize to reflow seats
    window.addEventListener('resize', ()=>{
      const M=stageMetrics();
      const N=seats.length, maxCols=Math.min(M.maxCols,8);
      const positions=placeGridCentered(maxCols,Math.ceil(N/Math.max(1,maxCols)),N);
      seats=positions.map((p,i)=>({seat_no:i+1,student_id:seats[i]?.student_id||null,x:p.x,y:p.y}));
      renderSeats();
    });
  </script>
</body>
</html>
