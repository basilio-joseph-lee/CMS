<?php
// /admin/backfill_face_vectors.php
// Single-file tool: GET shows the UI; POST upserts one student's descriptor.
// Auth check: allow only logged-in teacher/admin. Adjust as needed.
session_start();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!isset($_SESSION['teacher_id']) && !isset($_SESSION['admin_id'])) {
  http_response_code(403);
  echo "Forbidden"; exit;
}

require_once __DIR__ . '/../config/db.php';
$conn->set_charset('utf8mb4');

// Helper: convert DB file path to absolute URL on this host (same logic as your list_faces_all.php)
function build_base_url() {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  return $scheme . '://' . $host;
}
function map_web_path(string $p): string {
  $p = trim($p);
  if ($p === '') return '';
  if (preg_match('#^https?://#i', $p)) return $p; // already absolute
  $p = '/' . ltrim($p, '/');      // root-relative
  if (strpos($p, '/CMS/') === 0) { // live fix: strip /CMS if deployed at root
    $p = substr($p, 4);
  }
  return $p;
}

// Handle save (AJAX POST)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  header('Content-Type: application/json; charset=utf-8');
  try {
    $student_id = (int)($_POST['student_id'] ?? 0);
    $desc_json  = $_POST['descriptor_json'] ?? '';
    $face_path  = $_POST['face_image_path'] ?? '';

    if ($student_id <= 0) throw new Exception('Missing student_id');
    $arr = json_decode($desc_json, true);
    if (!is_array($arr) || count($arr) !== 128) throw new Exception('Invalid descriptor length');
    foreach ($arr as $v) { if (!is_numeric($v)) throw new Exception('Descriptor must be numeric'); }

    $desc_json_norm = json_encode(array_map('floatval', $arr), JSON_UNESCAPED_UNICODE);
    $face_path_norm = $face_path ? map_web_path($face_path) : '';

    $sql = "
      INSERT INTO student_face_descriptors (student_id, descriptor_json, face_image_path, updated_at, stale)
      VALUES (?, ?, ?, NOW(), 0)
      ON DUPLICATE KEY UPDATE
        descriptor_json = VALUES(descriptor_json),
        face_image_path = VALUES(face_image_path),
        updated_at = VALUES(updated_at),
        stale = 0
    ";
    $up = $conn->prepare($sql);
    $up->bind_param('iss', $student_id, $desc_json_norm, $face_path_norm);
    $up->execute();
    $up->close();

    echo json_encode(['ok' => true]);
  } catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  }
  exit;
}

// GET: fetch roster that NEEDS vectors (no row OR stale=1 OR invalid json)
$base = build_base_url();

// Students with a face image but no fresh descriptor
$q = $conn->query("
  SELECT s.student_id, s.fullname, s.face_image_path,
         COALESCE(sfd.stale, 1) AS stale,
         sfd.descriptor_json
  FROM students s
  LEFT JOIN student_face_descriptors sfd ON sfd.student_id = s.student_id
  WHERE s.face_image_path IS NOT NULL AND s.face_image_path <> ''
");
$need = [];
while ($r = $q->fetch_assoc()) {
  $ok = false;
  if ($r['descriptor_json']) {
    $arr = json_decode($r['descriptor_json'], true);
    $ok = is_array($arr) && count($arr) === 128;
  }
  if (!$ok || (int)$r['stale'] === 1) {
    $web = map_web_path((string)$r['face_image_path']);
    $need[] = [
      'student_id' => (int)$r['student_id'],
      'fullname'   => (string)$r['fullname'],
      'face_url'   => $base . $web
    ];
  }
}
$q->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Backfill Face Vectors</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <!-- No CSS frameworks to honor your style rule -->
</head>
<body style="font-family: system-ui, Arial; padding:20px;">
  <h1>Backfill Face Vectors</h1>
  <p>Total needing vectors: <b id="total"><?php echo count($need) ?></b></p>
  <div id="log" style="white-space:pre-line; background:#f7f7f7; border:1px solid #ddd; padding:10px; height:240px; overflow:auto;"></div>

  <!-- Hidden image used for loading each face -->
  <img id="probe" alt="" style="max-width:240px; display:none;" crossorigin="anonymous"/>

  <!-- face-api.js -->
  <script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
  <script>
  // Model path (works on prod root and local /CMS)
  const APP_BASE = (location.pathname.startsWith('/CMS/')) ? '/CMS' : '';
  const MODELS   = APP_BASE + '/models';

  const roster = <?php echo json_encode($need, JSON_UNESCAPED_UNICODE); ?>;
  const logEl  = document.getElementById('log');
  const imgEl  = document.getElementById('probe');

  function logln(s){ logEl.textContent += s + "\n"; logEl.scrollTop = logEl.scrollHeight; }

  async function loadModels() {
    await faceapi.nets.tinyFaceDetector.loadFromUri(MODELS);
    await faceapi.nets.faceLandmark68Net.loadFromUri(MODELS);
    await faceapi.nets.faceRecognitionNet.loadFromUri(MODELS);
  }

  async function computeDescriptorFromURL(url) {
    return new Promise((resolve, reject) => {
      imgEl.onload = async () => {
        try {
          const det = await faceapi
            .detectSingleFace(imgEl, new faceapi.TinyFaceDetectorOptions({ inputSize: 416, scoreThreshold: 0.3 }))
            .withFaceLandmarks()
            .withFaceDescriptor();
          if (!det || !det.descriptor) return reject(new Error('No face detected'));
          resolve(Array.from(det.descriptor));
        } catch (e) {
          reject(e);
        }
      };
      imgEl.onerror = () => reject(new Error('Image load error'));
      imgEl.src = url + ((url.includes('?') ? '&' : '?') + 't=' + Date.now()); // bypass cache
    });
  }

  async function saveVector(student_id, descriptor, face_image_path) {
    const body = new URLSearchParams();
    body.set('student_id', String(student_id));
    body.set('descriptor_json', JSON.stringify(descriptor));
    body.set('face_image_path', face_image_path);

    const res = await fetch(location.href, { method: 'POST', body });
    if (!res.ok) {
      const t = await res.text().catch(()=> '');
      throw new Error('Save failed: ' + t);
    }
    return res.json();
  }

  (async function main(){
    logln('Loading models…');
    await loadModels();
    logln('Models ready. Processing ' + roster.length + ' students…');

    let ok = 0, fail = 0, i = 0;
    for (const row of roster) {
      i++;
      logln(`[${i}/${roster.length}] ${row.fullname} (${row.student_id})`);
      try {
        const vec = await computeDescriptorFromURL(row.face_url);
        await saveVector(row.student_id, vec, row.face_url);
        ok++;
        logln('  ✔ saved');
      } catch (e) {
        fail++;
        logln('  ✖ ' + e.message);
      }
    }
    logln(`Done. OK=${ok}, FAIL=${fail}`);
  })();
  </script>
</body>
</html>
