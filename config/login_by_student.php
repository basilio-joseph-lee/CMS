<?php
// /config/login_by_student.php
// Secure server-side verification for face login.
// Accepts JSON { student_id, descriptor: [128 floats] }
// Returns { success: true } on success, else { success: false, message: '...' }

header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
error_reporting(E_ERROR | E_PARSE);

try {
    // Read JSON payload
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'invalid_payload']); exit;
    }

    $student_id = (int)($body['student_id'] ?? 0);
    $descriptor = $body['descriptor'] ?? null;
    if ($student_id <= 0 || !is_array($descriptor) || count($descriptor) < 10) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'bad_request']); exit;
    }

    require_once __DIR__ . '/db.php';
    $conn->set_charset('utf8mb4');

    // Ensure descriptor table exists (safe if already exists)
    $conn->query("
        CREATE TABLE IF NOT EXISTS student_face_descriptors (
            student_id INT PRIMARY KEY,
            descriptor_json JSON NOT NULL,
            face_image_path VARCHAR(255) DEFAULT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Try to load stored descriptor
    $stmt = $conn->prepare("SELECT descriptor_json FROM student_face_descriptors WHERE student_id = ? LIMIT 1");
    $stmt->bind_param('i', $student_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $storedArr = null;
    if ($row = $res->fetch_assoc()) {
        $json = $row['descriptor_json'] ?? null;
        if ($json) {
            $arr = json_decode($json, true);
            if (is_array($arr)) $storedArr = array_map('floatval', $arr);
        }
    }
    $stmt->close();

    if (!$storedArr) {
        // No server descriptor yet; signal client to seed (Phase 1 bootstrap)
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'no_server_descriptor']); exit;
    }

    // Compare live vs stored (euclidean)
    $live = array_map('floatval', $descriptor);
    $n = min(count($live), count($storedArr));
    if ($n === 0) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'descriptor_mismatch']); exit;
    }
    $sum = 0.0;
    for ($i=0; $i<$n; $i++) { $d = $live[$i] - $storedArr[$i]; $sum += $d*$d; }
    $dist = sqrt($sum);

    // Server threshold (close to client; adjust after field tests)
    $SERVER_DISTANCE_THRESHOLD = 0.50;
    if ($dist > $SERVER_DISTANCE_THRESHOLD) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'not_matching', 'distance' => $dist]); exit;
    }

    // Load student minimal info
    $stmt = $conn->prepare("SELECT student_id, fullname, avatar_path, face_image_path FROM students WHERE student_id = ? LIMIT 1");
    $stmt->bind_param('i', $student_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'student_not_found']); exit;
    }

    // Session naming consistent with face_login.php
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
    function base_path() {
      $script = $_SERVER['SCRIPT_NAME'] ?? '/';
      return (strpos($script, '/CMS/') !== false || substr($script, -4) === '/CMS') ? '/CMS' : '/';
    }

    session_name('CMS_STUDENT');
    if (session_status() === PHP_SESSION_NONE) session_start();
    session_regenerate_id(true);

    // Clear other roles
    unset($_SESSION['teacher_id'], $_SESSION['teacher_fullname'], $_SESSION['teacher_name'],
          $_SESSION['admin_id'], $_SESSION['admin_fullname']);
    $_SESSION['role']             = 'STUDENT';
    $_SESSION['student_id']       = (int)$user['student_id'];
    $_SESSION['student_fullname'] = (string)$user['fullname'];
    $_SESSION['fullname']         = (string)$user['fullname'];
    $_SESSION['avatar_path']      = (string)($user['avatar_path'] ?? '');
    $_SESSION['face_image']       = (string)($user['face_image_path'] ?? '');

    // Re-send cookie aligned with face_login.php
    $HTTPS  = is_https();
    $DOMAIN = cookie_domain_for_host($_SERVER['HTTP_HOST'] ?? '');
    $PATH   = base_path();
    setcookie(session_name(), session_id(), [
      'expires'  => 0,
      'path'     => $PATH,
      'domain'   => $DOMAIN ?: '',
      'secure'   => $HTTPS,
      'httponly' => true,
      'samesite' => 'Lax',
    ]);

    session_write_close();
    echo json_encode(['success' => true]); exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'server_error', 'error' => $e->getMessage()]);
}
