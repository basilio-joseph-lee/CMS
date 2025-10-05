<?php
session_start();

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Face Recognition Login</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <!-- Load face-api once (no duplicates, no defer). We'll wait for it in JS. -->
  <script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
  <style>
    body {
      background: url('../img/1.png') no-repeat center center fixed;
      background-size: cover;
      font-family: 'Comic Sans MS', cursive, sans-serif;
    }
    .status-note {
      background:#fff8a9; padding:12px 16px; border:2px solid #ccc65b; border-radius:8px;
      margin-top:20px; font-weight:bold; transform:rotate(-2deg); box-shadow:4px 4px 0 #d6d173;
      display:inline-block;
    }
  </style>
</head>
<body class="flex items-center justify-center min-h-screen px-4 py-10">

  <div class="p-8 max-w-xl w-full text-center bg-[#2f4733] rounded-[30px] shadow-lg ring-4 ring-white">
    <h1 class="text-3xl mb-6 text-white drop-shadow-lg">📸 Face Recognition Login</h1>

    <div class="bg-white rounded-xl p-2 border-4 border-gray-200 inline-block mb-4 relative">
      <video id="video" width="320" height="240" autoplay muted playsinline class="rounded-md bg-black"></video>
      <canvas id="overlay" width="320" height="240" class="absolute left-[10px] top-[10px] pointer-events-none"></canvas>
    </div>

    <p id="status" class="status-note">Loading models…</p>
  </div>

<script>
/* ---------- Config ---------- */
const MODELS_URL = '../models';          // your CMS/models folder (with *.json + *.bin)
const DISTANCE_THRESHOLD = 0.6;          // typical for face-api FaceMatcher
const PING_MS = 900;                     // scan interval

// Detector profiles (we’ll try a few so reference JPGs don’t fail easily)
const DET_PROFILES = [
  { inputSize: 512, scoreThreshold: 0.30 },
  { inputSize: 416, scoreThreshold: 0.30 },
  { inputSize: 320, scoreThreshold: 0.25 },
];

let stream, matcher = null, roster = [], indexByLabel = new Map();
let scanTimer = null, ready = false;

function setStatus(txt){ document.getElementById('status').textContent = txt; }
function normalizePath(p){
  if(!p) return '';
  p = String(p).trim().replace(/^\.?\//,'');    // strip leading ./ or /
  // If absolute http(s) or data URL -> return as is
  if (p.startsWith('http://') || p.startsWith('https://') || p.startsWith('data:')) return p;
  // If it was absolute like /CMS/..., keep it absolute
  if (p.startsWith('CMS/') || p.startsWith('/CMS/')) return p.startsWith('/') ? p : '/' + p;
  // Otherwise treat as path under the web root and climb from /user/
  return '../' + p;
}

async function waitForFaceApi(){
  for (let i=0;i<200;i++){  // ~10s
    if (window.faceapi) return;
    await new Promise(r=>setTimeout(r,50));
  }
  throw new Error('face-api.js failed to load');
}

async function loadModels(){
  await faceapi.nets.tinyFaceDetector.loadFromUri(MODELS_URL);
  await faceapi.nets.faceLandmark68Net.loadFromUri(MODELS_URL);
  await faceapi.nets.faceRecognitionNet.loadFromUri(MODELS_URL);
}

async function startCam(){
  const video = document.getElementById('video');
  stream = await navigator.mediaDevices.getUserMedia({ video: { width: 640, height: 480 }, audio: false });
  video.srcObject = stream;
}

async function fetchRoster(){
  const res = await fetch('../api/list_faces_all.php', { method:'POST' });
  roster = await res.json();
  if (!Array.isArray(roster)) roster = [];
  console.log('Roster:', roster);
}

function drawBox(det, label){
  const canvas = document.getElementById('overlay');
  const ctx = canvas.getContext('2d');
  ctx.clearRect(0,0,canvas.width,canvas.height);
  if (!det) return;
  const { box } = det.detection;
  ctx.strokeStyle = 'lime'; ctx.lineWidth = 2; ctx.strokeRect(box.x, box.y, box.width, box.height);
  ctx.fillStyle='rgba(0,0,0,.6)'; const tag = String(label||'');
  const tw = Math.min(160, ctx.measureText(tag).width + 10);
  ctx.fillRect(box.x, Math.max(0, box.y-18), tw, 18);
  ctx.fillStyle='white'; ctx.font='12px sans-serif'; ctx.fillText(tag, box.x+4, Math.max(12, box.y-4));
}

// Try multiple detector profiles on one image until we get a face + descriptor
async function detectOneWithProfiles(img){
  for (const prof of DET_PROFILES){
    const det = await faceapi
      .detectSingleFace(img, new faceapi.TinyFaceDetectorOptions(prof))
      .withFaceLandmarks()
      .withFaceDescriptor();
    if (det && det.descriptor) return det;
  }
  return null;
}

async function buildMatcher(){
  const labeled = [];
  let done = 0, total = roster.length;

  for (const row of roster){
const url = row.face_image_url || normalizePath(row.face_image_path);

    if (!url) { setStatus(`Preparing faces… ${done}/${total}`); continue; }

    try{
      // Load via fetch -> blob -> objectURL to avoid CORS/Hotlink issues on shared hosting
      const blob = await fetch(url, { cache: 'no-store' }).then(r => {
        if (!r.ok) throw new Error('Image HTTP ' + r.status + ': ' + url);
        return r.blob();
      });
      const img = await new Promise((resolve) => {
        const i = new Image();
        i.onload  = () => resolve(i);
        i.src     = URL.createObjectURL(blob);
      });



      const det = await detectOneWithProfiles(img);
      if (det){
        const label = String(row.student_id);
        labeled.push(new faceapi.LabeledFaceDescriptors(label, [det.descriptor]));
        indexByLabel.set(label, { id: row.student_id, name: row.fullname });
      } else {
        console.warn('No face found in reference image:', url);
      }
    }catch(err){
      console.warn('Reference image error:', err);
    }finally{
      done++;
      setStatus(`Preparing faces… ${done}/${total}`);
    }
  }

  if (!labeled.length) throw new Error('No reference faces loaded.');
  matcher = new faceapi.FaceMatcher(labeled, DISTANCE_THRESHOLD);
}

async function scanLoop(){
  if (!ready || !matcher) return;

  const video = document.getElementById('video');
  // Use our most robust profile when scanning live
  const det = await faceapi
    .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions({ inputSize: 512, scoreThreshold: 0.3 }))
    .withFaceLandmarks()
    .withFaceDescriptor();

  if (!det){
    setStatus('🔍 Scanning for a face…'); drawBox(null,''); return;
  }

  const best = matcher.findBestMatch(det.descriptor);
  if (best && best.label !== 'unknown'){
    const meta = indexByLabel.get(best.label) || { name: 'Student', id: best.label };
    const confPct = Math.max(0, Math.min(99, Math.round((1 - best.distance) * 100)));
    drawBox(det, `${meta.name} ${confPct}%`);
    setStatus(`✅ Recognized: ${meta.name} (${confPct}%) — signing in…`);
    clearInterval(scanTimer);

    const body = new URLSearchParams({ student_id: String(meta.id) });
    const res  = await fetch('../config/login_by_student.php', {
      method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body
    }).then(r=>r.json()).catch(()=>null);

    if (res && res.success){
      const hasContext = <?= (isset($_SESSION['subject_id']) && isset($_SESSION['advisory_id'])) ? 'true' : 'false' ?>;
      window.location.href = hasContext ? 'dashboard.php?new_login=1' : 'teacher/select_subject.php';
    } else {
      setStatus('⚠️ Login failed on server. Scanning again…');
      scanTimer = setInterval(scanLoop, PING_MS);
    }
  } else {
    drawBox(det, 'Unknown'); setStatus('❌ Face not recognized.');
  }
}

async function init(){
  try{
    setStatus('Loading models…');
    await waitForFaceApi();
    await loadModels();

    setStatus('Starting camera…');
    await startCam();

    setStatus('Loading face roster…');
    await fetchRoster();
    if (!roster.length){
      throw new Error('No reference faces from API. (Check /api/list_faces_all.php)');
    }

    setStatus('Preparing faces…');
    await buildMatcher();

    ready = true;
    setStatus('🔍 Scanning for a face…');
    scanTimer = setInterval(scanLoop, PING_MS);
  }catch(e){
    console.error(e);
    setStatus('🚫 Setup error: ' + e.message);
  }
}

window.addEventListener('load', init);
</script>
</body>
</html>
