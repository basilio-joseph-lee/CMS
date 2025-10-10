<?php
/**
 * face_login.php — fast scan version
 * - Consistent sessions (localhost/CMS and production)
 * - Absolute redirects
 * - Uses precomputed vectors first (with local cache); optional image fallback
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
  <!-- Perf: use TFJS so face-api can run on GPU (WebGL) -->
  <script src="https://cdn.jsdelivr.net/npm/@tensorflow/tfjs@3.21.0/dist/tf.min.js"></script>
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
const SELECT_URL = ORIGIN + APP_BASE + '/user/teacher/select_subject.php'; // keep as your current target

// Models & matching
const MODELS_URL_PRIMARY = '../models';
const DISTANCE_THRESHOLD = 0.6;

// Perf loop settings
const SCAN_MIN_MS = 120; // throttle detection so it's not every paint

// Vector cache keys
const VEC_CACHE_KEY = 'face_vectors_cache';
const VEC_CACHE_VER = 'face_vectors_version';

// Detector profiles (used for image-fallback only)
const DET_PROFILES = [
  { inputSize: 512, scoreThreshold: 0.30 },
  { inputSize: 416, scoreThreshold: 0.30 },
  { inputSize: 320, scoreThreshold: 0.25 },
];

let stream, matcher = null, roster = [], indexByLabel = new Map();
let rafId = 0, lastScan = 0, ready = false;
let currentInputSize = 320; // adaptive input size for live video
let lastSeenTs = 0;

let _lastStatus = '';
let _lastStatusTs = 0;

function setStatus(txt){
  const now = performance.now();
  if (txt === _lastStatus && now - _lastStatusTs < 250) return; // debounce UI churn
  _lastStatus = txt; _lastStatusTs = now;
  document.getElementById('status').textContent = txt;
}

function normalizePath(p){
  if(!p) return '';
  p = String(p).trim().replace(/^\.?\//,'');
  if (p.startsWith('http://') || p.startsWith('https://') || p.startsWith('data:')) return p;
  if (p.startsWith('CMS/') || p.startsWith('/CMS/')) return p.startsWith('/') ? p : '/' + p;
  return '../' + p;
}

async function waitForFaceApi(){ for (let i=0;i<200;i++){ if (window.faceapi) return; await new Promise(r=>setTimeout(r,50)); } throw new Error('face-api.js failed to load'); }
async function selectBackend(){
  try { await tf.setBackend('webgl'); } catch {}
  await tf.ready();
}
async function resolveModelsUrl() {
  const probe = (url) => fetch(url + '/face_recognition_model-weights_manifest.json', { cache: 'no-store' }).then(r => r.ok).catch(() => false);
  const rel = MODELS_URL_PRIMARY;
  if (await probe(rel)) return rel;
  const abs = ORIGIN + APP_BASE + '/models';
  if (await probe(abs)) return abs;
  return rel;
}
async function loadModels(){
  const base = await resolveModelsUrl();
  await faceapi.nets.tinyFaceDetector.loadFromUri(base);
  await faceapi.nets.faceLandmark68Net.loadFromUri(base);
  await faceapi.nets.faceRecognitionNet.loadFromUri(base);
}
async function startCam(){
  const video = document.getElementById('video');
  // Lower resolution = less compute per frame, still enough for TinyFaceDetector
  stream = await navigator.mediaDevices.getUserMedia({
    video: { width: { ideal: 320 }, height: { ideal: 240 }, facingMode: 'user' },
    audio: false
  });
  video.srcObject = stream;
}

function drawBox(det, label){
  const canvas = document.getElementById('overlay');
  const ctx = canvas.getContext('2d');
  ctx.clearRect(0,0,canvas.width,canvas.height);
  if (!det) return;
  const { box } = det.detection;
  ctx.strokeStyle = 'lime'; ctx.lineWidth = 2; ctx.strokeRect(box.x, box.y, box.width, box.height);
  ctx.fillStyle='rgba(0,0,0,.6)'; const tag = String(label||'');
  ctx.font='12px sans-serif';
  const tw = Math.min(200, ctx.measureText(tag).width + 10);
  ctx.fillRect(box.x, Math.max(0, box.y-18), tw, 18);
  ctx.fillStyle='white'; ctx.fillText(tag, box.x+4, Math.max(12, box.y-4));
}

// --------- Vectors cache helpers ----------
function loadVectorsFromCache() {
  try {
    const v = localStorage.getItem(VEC_CACHE_KEY);
    if (!v) return null;
    const arr = JSON.parse(v);
    if (!Array.isArray(arr) || !arr.length) return null;
    return arr;
  } catch { return null; }
}
function saveVectorsToCache(vectors, version) {
  try {
    localStorage.setItem(VEC_CACHE_KEY, JSON.stringify(vectors));
    if (version) localStorage.setItem(VEC_CACHE_VER, String(version));
  } catch {}
}
function getCachedVersion() {
  const s = localStorage.getItem(VEC_CACHE_VER);
  return s ? parseInt(s, 10) || 0 : 0;
}

// --------- Fetch roster (vectors-first + cache; optional image fallback) ----------
async function fetchRoster(){
  // 0) Try cache first for instant matcher build
  const cached = loadVectorsFromCache();
  if (cached) {
    roster = cached.map(v => ({ student_id: v.student_id, fullname: v.fullname, descriptor: v.descriptor }));
  }

  // 1) Fetch server vectors (fast path)
  try {
    const since = getCachedVersion();
    const url = ORIGIN + APP_BASE + '/api/list_face_vectors.php' + (since ? ('?since=' + encodeURIComponent(since)) : '');
    const r = await fetch(url, { method:'GET', cache:'no-store' });
    if (r.ok) {
      const data = await r.json();
      if (data && Array.isArray(data.vectors)) {
        // Refresh cache (simple strategy)
        saveVectorsToCache(data.vectors, data.roster_version || Date.now());
        roster = data.vectors.map(v => ({
          student_id: v.student_id,
          fullname: v.fullname,
          descriptor: Array.isArray(v.descriptor) ? v.descriptor : []
        }));
        return; // Success using vectors
      }
    }
  } catch (e) {
    console.warn('Vector API failed, using cache if any.', e);
  }

  // 2) VECTORS-ONLY MODE (uncomment to forbid slow image fallback)
  // roster = []; return;

  // 3) Otherwise, fallback to old images (will be slow if no vectors)
  try {
    const res = await fetch(ORIGIN + APP_BASE + '/api/list_faces_all.php', { method:'POST' });
    roster = await res.json();
    if (!Array.isArray(roster)) roster = [];
  } catch {
    roster = [];
  }
}

function toFloat32(arr){
  if (!Array.isArray(arr) || arr.length !== 128) return null;
  try { return new Float32Array(arr); } catch { return null; }
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
  // Fast path: use precomputed 128-float vectors if present
  const haveVectors = roster.length && roster.every(r => Array.isArray(r.descriptor));
  if (haveVectors) {
    const labeled = [];
    let done = 0, total = roster.length;
    for (const r of roster){
      const vec = toFloat32(r.descriptor);
      if (vec) {
        const label = String(r.student_id);
        labeled.push(new faceapi.LabeledFaceDescriptors(label, [vec]));
        indexByLabel.set(label, { id: r.student_id, name: r.fullname });
      }
      done++; setStatus(`Preparing faces… ${done}/${total}`);
    }
    if (!labeled.length) throw new Error('No vectors loaded.');
    matcher = new faceapi.FaceMatcher(labeled, DISTANCE_THRESHOLD);
    return;
  }

  // Image fallback (only if enabled by fetchRoster)
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

  const det = await faceapi
    .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions({ inputSize: currentInputSize, scoreThreshold: 0.3 }))
    .withFaceLandmarks()
    .withFaceDescriptor();

  if (!det){
    // Adaptive bump if no face for ~1.5s
    if (performance.now() - lastSeenTs > 1500 && currentInputSize === 320) {
      currentInputSize = 416;
      setTimeout(() => { currentInputSize = 320; }, 1500);
    }
    setStatus('🔍 Scanning for a face…');
    drawBox(null,'');
    return;
  }

  lastSeenTs = performance.now();

  const best = matcher.findBestMatch(det.descriptor);
  if (best && best.label !== 'unknown'){
    const meta = indexByLabel.get(best.label) || { name: 'Student', id: best.label };
    const confPct = Math.max(0, Math.min(99, Math.round((1 - best.distance) * 100)));
    drawBox(det, `${meta.name} ${confPct}%`);
    setStatus(`✅ Recognized: ${meta.name} (${confPct}%) — logging in…`);
    if (rafId) { cancelAnimationFrame(rafId); rafId = 0; }

    try{
      const body = new URLSearchParams({ student_id: String(meta.id) });
      const res  = await fetch(LOGIN_URL, {
        method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body
      }).then(r=>r.json());

      if (res && res.success){
        window.location.replace(SELECT_URL);
      } else {
        setStatus('⚠️ Login failed on server. Retrying…');
        if (!rafId) rafId = requestAnimationFrame(loop);
      }
    } catch(e) {
      console.error(e);
      setStatus('❌ Network/login error.');
      if (!rafId) rafId = requestAnimationFrame(loop);
    }
  } else {
    drawBox(det, 'Unknown'); setStatus('❌ Face not recognized.');
  }
}

function loop(){
  rafId = requestAnimationFrame(loop);
  const now = performance.now();
  if (now - lastScan < SCAN_MIN_MS) return;
  lastScan = now;
  scanLoop();
}

async function init(){
  try{
    setStatus('Loading models…'); await waitForFaceApi(); await selectBackend(); await loadModels();
    setStatus('Starting camera…'); await startCam();

    // Warm-up once to compile kernels
    try {
      await faceapi.detectSingleFace(document.getElementById('video'),
        new faceapi.TinyFaceDetectorOptions({ inputSize: 160, scoreThreshold: 0.4 })
      );
    } catch {}

    setStatus('Loading face roster…'); await fetchRoster();
    if (!roster.length) throw new Error('No reference faces from API.');
    setStatus('Preparing faces…'); await buildMatcher();

    ready = true; setStatus('🔍 Scanning for a face…');
    if (!rafId) rafId = requestAnimationFrame(loop);
  }catch(e){ console.error(e); setStatus('🚫 Setup error: ' + e.message); }
}
window.addEventListener('load', init);
</script>
</body>
</html>
