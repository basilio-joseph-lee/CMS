<?php
/**
 * face_login.php — production-safe
 * - Consistent student session (works on localhost/CMS and on your domain)
 * - Absolute redirects so the browser never treats "user" as a domain
 */

function is_https() {
  if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
  if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') return true;
  return false;
}
function cookie_domain_for_host($host) {
  $h = preg_replace('/:\d+$/', '', (string)$host);
  if ($h === 'localhost' || filter_var($h, FILTER_VALIDATE_IP)) return '';
  return $h;
}
function app_base_path() {
  // If the app lives under /CMS (e.g., http://localhost/CMS), return "/CMS". Else return "" (root).
  $script = $_SERVER['SCRIPT_NAME'] ?? '/';
  return (strpos($script, '/CMS/') !== false || preg_match('#/CMS$#', $script)) ? '/CMS' : '';
}

$HTTPS  = is_https();
$DOMAIN = cookie_domain_for_host($_SERVER['HTTP_HOST'] ?? '');
$BASE   = app_base_path();                 // "" on prod root, "/CMS" on localhost/CMS

// Use one cookie for all student pages (+ set proper path)
session_name('CMS_STUDENT');
session_set_cookie_params([
  'lifetime' => 0,
  'path'     => ($BASE === '' ? '/' : $BASE), // "/" or "/CMS"
  'domain'   => $DOMAIN ?: '',
  'secure'   => $HTTPS,
  'httponly' => true,
  'samesite' => 'Lax',
]);
if (session_status() === PHP_SESSION_NONE) session_start();

// Clear any leftover class context so selector will show all classes
unset($_SESSION['subject_id'], $_SESSION['subject_name'], $_SESSION['advisory_id'],
      $_SESSION['school_year_id'], $_SESSION['class_name'], $_SESSION['year_label']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Face Recognition Login</title>
<script src="https://cdn.tailwindcss.com"></script>
<!-- Enable GPU: WebGL backend gives 2–5× speedup -->
<script src="https://cdn.jsdelivr.net/npm/@tensorflow/tfjs@4.20.0/dist/tf.min.js"></script>
<script>
  if (window.tf && tf.setBackend) {
    tf.setBackend('webgl').then(() => tf.ready());
  }
</script>
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
/* ---------------- CONFIG ---------------- */
// PHP → JS: base path ("" for root domain, "/CMS" on localhost/CMS)
const APP_BASE = <?= json_encode($BASE) ?>;
// Build absolute URLs (prevents "user" being treated as a domain)
const ORIGIN = window.location.origin;
const LOGIN_URL  = ORIGIN + APP_BASE + '/config/login_by_student.php';
const SELECT_URL = ORIGIN + APP_BASE + '/user/teacher/select_subject.php';

const MODELS_URL_PRIMARY = '../models';
// Safer + snappier
const DISTANCE_THRESHOLD = 0.45;    // stricter accept
const DISTANCE_MARGIN     = 0.10;   // best must beat 2nd-best by this gap
const MIN_DETECTION_SCORE = 0.35;
const PING_MS = 300;

const DET_PROFILES = [
  { inputSize: 416, scoreThreshold: 0.35 },
  { inputSize: 320, scoreThreshold: 0.30 },
];

let stream, matcher = null, roster = [], indexByLabel = new Map();
let scanTimer = null, ready = false;

function setStatus(txt){ document.getElementById('status').textContent = txt; }
function normalizePath(p){
  if(!p) return '';
  p = String(p).trim().replace(/^\.?\//,'');
  if (p.startsWith('http://') || p.startsWith('https://') || p.startsWith('data:')) return p;
  if (p.startsWith('CMS/') || p.startsWith('/CMS/')) return p.startsWith('/') ? p : '/' + p;
  return '../' + p;
}

async function waitForFaceApi(){ for (let i=0;i<200;i++){ if (window.faceapi) return; await new Promise(r=>setTimeout(r,50)); } throw new Error('face-api.js failed to load'); }
async function resolveModelsUrl() {
  const probe = (url) => fetch(url + '/face_recognition_model-weights_manifest.json', { cache: 'no-store' }).then(r => r.ok).catch(() => false);
  // Try relative models folder first
  const rel = MODELS_URL_PRIMARY;
  if (await probe(rel)) return rel;
  // Then absolute to app base
  const abs = ORIGIN + APP_BASE + '/models';
  if (await probe(abs)) return abs;
  return rel; // fallback (error will show later if truly missing)
}
async function loadModels(){
  const base = await resolveModelsUrl();
  await faceapi.nets.tinyFaceDetector.loadFromUri(base);
  await faceapi.nets.faceLandmark68Net.loadFromUri(base);
  await faceapi.nets.faceRecognitionNet.loadFromUri(base);
}
async function startCam(){
  const video = document.getElementById('video');
  stream = await navigator.mediaDevices.getUserMedia({ video: { width: 320, height: 240 }, audio: false });
  video.srcObject = stream;
}
async function fetchRoster(){
  const res = await fetch(ORIGIN + APP_BASE + '/api/list_faces_all.php', { method:'POST' });
  roster = await res.json();
  if (!Array.isArray(roster)) roster = [];
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
      const blob = await fetch(url, { cache: 'no-store' }).then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.blob(); });
      const img  = await new Promise(res => { const i = new Image(); i.onload = () => res(i); i.src = URL.createObjectURL(blob); });
      const det  = await detectOneWithProfiles(img);
      if (det){
        const label = String(row.student_id);
        labeled.push(new faceapi.LabeledFaceDescriptors(label, [det.descriptor]));
        indexByLabel.set(label, { id: row.student_id, name: row.fullname });
      }
    }catch(err){
      console.warn('Reference image error:', err);
    }finally{
      done++; setStatus(`Preparing faces… ${done}/${total}`);
    }
  }
  if (!labeled.length) throw new Error('No reference faces loaded.');
  matcher = new faceapi.FaceMatcher(labeled, DISTANCE_THRESHOLD);
}

async function scanLoop(){
  if (!ready || !matcher) return;

  const video = document.getElementById('video');
// detect ALL faces and require exactly one, then require a frontal pose,
// then enforce best-vs-second-best margin, then send LIVE descriptor to server
const detections = await faceapi
  .detectAllFaces(video, new faceapi.TinyFaceDetectorOptions({ inputSize: 416, scoreThreshold: MIN_DETECTION_SCORE }))
  .withFaceLandmarks()
  .withFaceDescriptors();

if (!detections || detections.length === 0) {
  setStatus('🔍 Scanning for a face…'); drawBox(null,'');
  return;
}
if (detections.length > 1) {
  setStatus('⚠️ Multiple faces detected — one person only.'); drawBox(null, 'Multiple faces');
  return;
}

const det = detections[0];
if (!det?.descriptor) { setStatus('🔍 Scanning for a face…'); drawBox(null,''); return; }

// ---- lightweight "frontalness" heuristic via landmarks
const lm = det.landmarks;
const leftEye  = lm.getLeftEye();
const rightEye = lm.getRightEye();
const nose     = lm.getNose();
const avgDist = pts => { let d=0; for(let i=1;i<pts.length;i++){ const dx=pts[i].x-pts[i-1].x, dy=pts[i].y-pts[i-1].y; d+=Math.hypot(dx,dy);} return d/(pts.length-1); };
const leftEyeSize  = avgDist(leftEye);
const rightEyeSize = avgDist(rightEye);
const eyeSizeRatio = leftEyeSize / (rightEyeSize || 1);
const eyeYDiff = Math.abs((leftEye[0].y + leftEye[leftEye.length-1].y)/2 - (rightEye[0].y + rightEye[rightEye.length-1].y)/2);
const noseTip = nose[Math.floor(nose.length/2)];
const eyeCenterX = ((leftEye[0].x + leftEye[leftEye.length-1].x)/2 + (rightEye[0].x + rightEye[rightEye.length-1].x)/2)/2;
const noseOffsetX = Math.abs(noseTip.x - eyeCenterX);

const MAX_EYE_SIZE_RATIO = 1.25, MAX_EYE_Y_DIFF = 12, MAX_NOSE_X_OFFSET = 18;
if (eyeSizeRatio > MAX_EYE_SIZE_RATIO || eyeSizeRatio < (1/MAX_EYE_SIZE_RATIO) || eyeYDiff > MAX_EYE_Y_DIFF || noseOffsetX > MAX_NOSE_X_OFFSET) {
  setStatus('⚠️ Please face the camera directly (front view).');
  drawBox(det, 'Turn face forward');
  return;
}

// ---- compute best & second-best distances to enforce margin
const distances = matcher.labeledDescriptors.map(ld => {
  const d1 = det.descriptor, d2 = ld.descriptors[0];
  let s = 0; for (let i=0;i<d1.length;i++){ const q=d1[i]-d2[i]; s+=q*q; } return { label: ld.label, dist: Math.sqrt(s) };
}).sort((a,b)=>a.dist-b.dist);

if (!distances.length) { setStatus('❌ No reference faces loaded.'); return; }
const best = distances[0], second = distances[1] || { dist: 999 };

if (best.dist > DISTANCE_THRESHOLD) {
  drawBox(det, 'Unknown'); setStatus('❌ Face not recognized.'); return;
}
if ((second.dist - best.dist) < DISTANCE_MARGIN) {
  drawBox(det, 'Ambiguous'); setStatus('⚠️ Match ambiguous — step closer & face camera.'); return;
}

const meta = indexByLabel.get(best.label) || { name: 'Student', id: best.label };
drawBox(det, `${meta.name} (${Math.round((1-best.dist)*100)}%)`);
setStatus(`✅ Candidate: ${meta.name} — verifying…`);

try {
  const payload = { student_id: String(meta.id), descriptor: Array.from(det.descriptor) };
  const res = await fetch(LOGIN_URL, {
    method: 'POST',
    headers: { 'Content-Type':'application/json' },
    body: JSON.stringify(payload),
  }).then(r => r.json());

  if (res?.success) {
    window.location.replace(SELECT_URL);
  } else if (res?.message === 'no_server_descriptor') {
    // Seed once (Phase 1 bootstrap) then retry
    await fetch(ORIGIN + APP_BASE + '/api/save_face_descriptor.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const retry = await fetch(LOGIN_URL, {
      method: 'POST',
      headers: { 'Content-Type':'application/json' },
      body: JSON.stringify(payload),
    }).then(r => r.json());
    if (retry?.success) {
      window.location.replace(SELECT_URL);
    } else {
      setStatus('⚠️ Server verification failed: ' + (retry?.message || 'unknown'));
    }
  } else {
    setStatus('⚠️ Server verification failed: ' + (res?.message || 'unknown'));
  }
} catch (e) {
  console.error(e); setStatus('❌ Network/server error during verification.');
}


async function init(){
  try{
    setStatus('Loading models…'); await waitForFaceApi(); await loadModels();
    setStatus('Starting camera…'); await startCam();
    setStatus('Loading face roster…'); await fetchRoster(); if (!roster.length) throw new Error('No reference faces from API.');
    setStatus('Preparing faces…'); await buildMatcher();
    ready = true; setStatus('🔍 Scanning for a face…');
    function tick(){ scanLoop().finally(()=>setTimeout(()=>requestAnimationFrame(tick), PING_MS)); }
requestAnimationFrame(tick);
  }catch(e){ console.error(e); setStatus('🚫 Setup error: ' + e.message); }
}
window.addEventListener('load', init);
</script>
</body>
</html>
