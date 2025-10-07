<?php
/**
 * /config/register_student.php
 * - Lookup-or-create student (no duplicates in `students`)
 * - Save optional captured face (base64) and update students.face_image_path
 * - Enroll into student_enrollments with ON DUPLICATE KEY UPDATE
 * - Works both on localhost/CMS and production (no hard-coded domain in redirects)
 */

session_start();
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['teacher_id'])) {
  $_SESSION['toast'] = 'Unauthorized.';
  $_SESSION['toast_type'] = 'error';
  header('Location: ../user/teacher/teacher_login.php');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  $_SESSION['toast'] = 'Invalid request method.';
  $_SESSION['toast_type'] = 'error';
  header('Location: ../user/teacher/add_student.php');
  exit;
}

/* ---------- DB connection ---------- */
require_once __DIR__ . '/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

/* ---------- Utility: env-aware paths & redirects ---------- */
function is_https(){ return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'); }
function base_prefix() {
  // If serving under /CMS (local), keep it; on production (root) it's empty
  $script = $_SERVER['SCRIPT_NAME'] ?? '';
  return (str_starts_with($script, '/CMS/')) ? '/CMS' : '';
}
function root_path_exists($suffix) {
  $p = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . $suffix;
  return is_dir($p) || is_file($p);
}
function ensure_root_rel($p) {
  if (!$p) return '';
  if (preg_match('~^https?://~i', $p)) {
    // convert full URL to path so DB stays consistent
    $u = parse_url($p);
    return $u['path'] ?? $p;
  }
  return ($p[0] === '/') ? $p : ('/' . ltrim($p, '/'));
}
/** Save base64 jpeg to faces dir, returns root-relative web path or '' */
function save_face_b64($b64, $facesWeb) {
  if (!$b64) return '';
  if (strpos($b64, 'base64,') !== false) {
    $b64 = substr($b64, strpos($b64, 'base64,') + 7);
  }
  $data = base64_decode($b64, true);
  if ($data === false || strlen($data) < 800) return '';

  $fsDir = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . $facesWeb;
  if (!is_dir($fsDir)) @mkdir($fsDir, 0755, true);

  $fname = 'face_' . bin2hex(random_bytes(8)) . '.jpg';
  $full  = $fsDir . '/' . $fname;
  if (file_put_contents($full, $data) === false) return '';

  return $facesWeb . '/' . $fname;
}

/* ---------- Resolve directories for avatars & faces (local/prod) ---------- */
$prefix = base_prefix();                      // '' on prod, '/CMS' on local
$facesWeb  = root_path_exists('/CMS/student_faces') ? '/CMS/student_faces' : '/student_faces';
$avatarWeb = root_path_exists('/CMS/avatar')        ? '/CMS/avatar'        : '/avatar';

$facesFs  = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . $facesWeb;
$avatarFs = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . $avatarWeb;

/* ---------- Read form ---------- */
$fullname        = trim($_POST['fullname'] ?? '');
$gender          = trim($_POST['gender'] ?? '');
$capturedFace    = $_POST['captured_face'] ?? '';      // data URL (optional)
$chosenAvatar    = trim($_POST['avatar_path'] ?? '');  // optional, may be full URL or path

$school_year_id  = (int)($_SESSION['school_year_id'] ?? 0);
$advisory_id     = (int)($_SESSION['advisory_id'] ?? 0);
$subject_id      = (int)($_SESSION['subject_id'] ?? 0);

// Optional: teacher UI may send this later; safe to accept now
$student_code_in = trim($_POST['student_code'] ?? '');

if ($fullname === '' || $gender === '' || !$school_year_id || !$advisory_id || !$subject_id) {
  $_SESSION['toast'] = 'Missing required fields.';
  $_SESSION['toast_type'] = 'error';
  header('Location: ' . $prefix . '/user/teacher/add_student.php');
  exit;
}

// Need at least one source for identity visuals
if ($capturedFace === '' && $chosenAvatar === '') {
  $_SESSION['toast'] = 'Please choose a 2D avatar or capture a face.';
  $_SESSION['toast_type'] = 'error';
  header('Location: ' . $prefix . '/user/teacher/add_student.php');
  exit;
}

/* ---------- Normalize avatar path & verify file exists (if provided) ---------- */
$avatar_path_db = null;
if ($chosenAvatar !== '') {
  $p = ensure_root_rel($chosenAvatar);       // '/CMS/avatar/..' or '/avatar/..'
  $file = basename($p);

  // Try under detected avatar folder
  $candidate = $avatarFs . '/' . $file;
  if (!is_file($candidate)) {
    // If not found, leave as-is (CDN or absolute may be allowed), but prefer root-relative if we can
    // You can tighten this if you want to restrict to local list only.
  } else {
    $p = $avatarWeb . '/' . $file;
  }
  $avatar_path_db = $p;
}

/* ---------- Main flow: lookup-or-create → save face → enroll ---------- */
$conn->begin_transaction();
try {
  $student_id = null;

  // 1) Prefer lookup by student_code if provided
  if ($student_code_in !== '') {
    $q = $conn->prepare("SELECT student_id FROM students WHERE student_code=? LIMIT 1");
    $q->bind_param("s", $student_code_in);
    $q->execute();
    if ($row = $q->get_result()->fetch_assoc()) {
      $student_id = (int)$row['student_id'];
    }
    $q->close();
  }

  // 2) Fallback lookup by exact fullname
  if (!$student_id) {
    $q = $conn->prepare("SELECT student_id FROM students WHERE fullname=? LIMIT 1");
    $q->bind_param("s", $fullname);
    $q->execute();
    if ($row = $q->get_result()->fetch_assoc()) {
      $student_id = (int)$row['student_id'];
    }
    $q->close();
  }

  // 3) Create student if not found
  if (!$student_id) {
    $ins = $conn->prepare("
      INSERT INTO students (fullname, gender, face_image_path, avatar_path, student_code)
      VALUES (?, ?, '', ?, '')
    ");
    $ins->bind_param("sss", $fullname, $gender, $avatar_path_db);
    $ins->execute();
    $student_id = $ins->insert_id;
    $ins->close();

    // Generate deterministic student_code (keeps future merges easy)
    $code = 'STD-' . str_pad((string)$student_id, 8, '0', STR_PAD_LEFT);
    $u = $conn->prepare("UPDATE students SET student_code=? WHERE student_id=?");
    $u->bind_param("si", $code, $student_id);
    $u->execute();
    $u->close();
  } else {
    // Update avatar if student has none yet and we received one
    if ($avatar_path_db) {
      $u = $conn->prepare("UPDATE students SET avatar_path = COALESCE(NULLIF(avatar_path,''), ?) WHERE student_id=?");
      $u->bind_param("si", $avatar_path_db, $student_id);
      $u->execute();
      $u->close();
    }
  }

  // 4) Save captured face (if provided) and update primary face path
  if ($capturedFace !== '') {
    $newFacePath = save_face_b64($capturedFace, $facesWeb);
    if ($newFacePath !== '') {
      $u = $conn->prepare("UPDATE students SET face_image_path=? WHERE student_id=?");
      $u->bind_param("si", $newFacePath, $student_id);
      $u->execute();
      $u->close();

      // Optional gallery (if you created table student_faces)
      $chk = $conn->query("SHOW TABLES LIKE 'student_faces'");
      if ($chk && $chk->num_rows > 0) {
        $g = $conn->prepare("INSERT INTO student_faces (student_id, face_image_path) VALUES (?, ?)");
        $g->bind_param("is", $student_id, $newFacePath);
        $g->execute();
        $g->close();
      }
      if ($chk) $chk->free();
    }
  }

  // 5) Enroll (idempotent). Requires UNIQUE index on (student_id, advisory_id, school_year_id, subject_id)
  $sql = "
    INSERT INTO student_enrollments (student_id, advisory_id, school_year_id, subject_id)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE subject_id = VALUES(subject_id)
  ";
  $en = $conn->prepare($sql);
  $en->bind_param("iiii", $student_id, $advisory_id, $school_year_id, $subject_id);
  $en->execute();
  $en->close();

  $conn->commit();

  $_SESSION['toast'] = '✅ Student saved & enrolled successfully.';
  $_SESSION['toast_type'] = 'success';
  header('Location: ' . $prefix . '/user/teacher/add_student.php');
  exit;

} catch (Throwable $e) {
  $conn->rollback();
  $_SESSION['toast'] = '❌ Error: ' . $e->getMessage();
  $_SESSION['toast_type'] = 'error';
  header('Location: ' . $prefix . '/user/teacher/add_student.php');
  exit;
}
