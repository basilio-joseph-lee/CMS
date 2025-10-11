<?php
// /api/out_time_request_decide.php
// Teacher approves/denies "Out Time" request and (on approve) notifies parent via SMS (Mocean)

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/db.php'; // must provide $conn; (optional) $MOCEAN_TOKEN, $MOCEAN_SENDER

function fail($msg, $code=400){
  http_response_code($code);
  echo json_encode(['ok'=>false, 'message'=>$msg], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ---------------- Helpers ---------------- */

// Normalize PH numbers to E.164 sans plus: 63XXXXXXXXXX
function normalizePH($msisdn){
  if ($msisdn === null) return null;
  $s = trim((string)$msisdn);
  if ($s === '') return null;
  $hasPlus = str_starts_with($s, '+');
  $digits  = preg_replace('/\D+/', '', $s);

  // +63XXXXXXXXXX or 63XXXXXXXXXX
  if ((($hasPlus && str_starts_with($s, '+63')) || str_starts_with($digits, '63')) && strlen($digits) === 12) {
    return '63' . substr($digits, 2);
  }
  // 09XXXXXXXXX -> 63XXXXXXXXXX
  if (str_starts_with($digits, '0') && strlen($digits) === 11) {
    return '63' . substr($digits, 1);
  }
  // 9XXXXXXXXX -> 63XXXXXXXXXX
  if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
    return '63' . $digits;
  }
  return null;
}

// Send SMS via Mocean (Bearer token)
function mocean_send_sms(string $token, string $from, string $to, string $text): array {
  $url  = "https://rest.moceanapi.com/rest/2/sms";
  $data = [
    'mocean-from'        => $from,     // alphanumeric or numeric sender (numeric is more reliable in PH)
    'mocean-to'          => $to,       // e.g., 63917xxxxxxx
    'mocean-text'        => $text,
    'mocean-resp-format' => 'JSON'
  ];
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($data),
    CURLOPT_HTTPHEADER     => [
      "Authorization: Bearer {$token}",
      "Content-Type: application/x-www-form-urlencoded",
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
  ]);
  $res  = curl_exec($ch);
  $err  = curl_error($ch);
  $http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);

  if ($err) return ['ok'=>false, 'http'=>$http, 'error'=>$err];
  $json = json_decode($res, true);
  return ['ok'=>true, 'http'=>$http, 'body'=>$json ?? $res];
}

/* --------------- Auth & Input --------------- */

if (!isset($_SESSION['teacher_id'])) fail('Teacher not logged in', 401);
$teacher_id = (int)$_SESSION['teacher_id'];

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) $body = $_POST;

$id     = isset($body['id']) ? (int)$body['id'] : 0;
$action = strtolower(trim((string)($body['action'] ?? '')));
$note   = trim((string)($body['note'] ?? ''));

if ($id <= 0) fail('Missing/invalid request id');
if (!in_array($action, ['approve','deny'], true)) fail('Action must be approve or deny');

$conn->set_charset('utf8mb4');

/* --------------- Load + authorize request --------------- */

$sql = "
  SELECT r.id, r.student_id, r.subject_id, r.advisory_id, r.status, r.requested_at
  FROM out_time_requests r
  WHERE r.id = ?
    AND r.status = 'pending'
    AND (
      r.advisory_id IN (SELECT advisory_id FROM subjects WHERE teacher_id = ?)
      OR r.subject_id  IN (SELECT subject_id  FROM subjects WHERE teacher_id = ?)
    )
  LIMIT 1
";
$stmt = $conn->prepare($sql);
if (!$stmt) fail('Prepare failed: '.$conn->error, 500);
$stmt->bind_param('iii', $id, $teacher_id, $teacher_id);
$stmt->execute();
$res = $stmt->get_result();
$req = $res->fetch_assoc();
$stmt->close();

if (!$req) fail('Request not found, already decided, or not under your sections/subjects', 404);

$student_id = (int)$req['student_id'];

/* --------------- Decide + optional SMS --------------- */

$conn->begin_transaction();

try {
  // 1) Update decision
  $new_status = ($action === 'approve') ? 'approved' : 'denied';
  $sqlU = "UPDATE out_time_requests
           SET status = ?, decided_at = NOW(), decided_by_teacher_id = ?, note = NULLIF(?, '')
           WHERE id = ? AND status = 'pending'";
  $stmtU = $conn->prepare($sqlU);
  if (!$stmtU) throw new Exception('Prepare update failed: '.$conn->error);
  $stmtU->bind_param('sisi', $new_status, $teacher_id, $note, $id);
  $stmtU->execute();
  if ($stmtU->affected_rows !== 1) {
    throw new Exception('Request already decided or does not exist.');
  }
  $stmtU->close();

  $sms_info = ['skipped' => true, 'reason' => 'not approved'];

  // 2) If approved: log to behavior_logs + send SMS to parent
  if ($new_status === 'approved') {
    // a) behavior log
    $stmtB = $conn->prepare("INSERT INTO behavior_logs (student_id, action_type, timestamp) VALUES (?, 'out_time', NOW())");
    if ($stmtB) { $stmtB->bind_param('i', $student_id); $stmtB->execute(); $stmtB->close(); }

    // b) fetch parent + number
    $sqlP = "
      SELECT s.fullname AS student_name,
             p.parent_id, p.fullname AS parent_name, p.mobile_number AS parent_mobile
      FROM students s
      LEFT JOIN parents p ON p.parent_id = s.parent_id
      WHERE s.student_id = ?
      LIMIT 1
    ";
    $stmtP = $conn->prepare($sqlP);
    if (!$stmtP) throw new Exception('Prepare parent lookup failed: '.$conn->error);
    $stmtP->bind_param('i', $student_id);
    $stmtP->execute();
    $rp = $stmtP->get_result()->fetch_assoc();
    $stmtP->close();

    $parent_id   = $rp['parent_id'] ?? null;
    $studentName = $rp['student_name'] ?? 'your child';
    $rawMobile   = $rp['parent_mobile'] ?? '';
    $toE164      = normalizePH($rawMobile);

    // c) SMS template
    $template = "Hi, this is MySchoolness. Your child {student} has been approved for OUT TIME by the teacher.";
    $text     = str_replace('{student}', $studentName, $template);

    // d) Prepare SMS config
    $token  = isset($MOCEAN_TOKEN)  && $MOCEAN_TOKEN  ? $MOCEAN_TOKEN  : null;
    $sender = isset($MOCEAN_SENDER) && $MOCEAN_SENDER ? $MOCEAN_SENDER : 'MySchoolness'; // set numeric here if alphanumeric fails

    if (!$parent_id || !$toE164) {
      $sms_info = ['skipped'=>true, 'reason'=> !$parent_id ? 'no parent record' : 'invalid mobile', 'mobile_raw'=>$rawMobile];
    } elseif (!$token) {
      $sms_info = ['skipped'=>true, 'reason'=>'MOCEAN_TOKEN missing in config', 'to'=>$toE164];
    } else {
      // e) Send
      $resp = mocean_send_sms($token, $sender, $toE164, $text);

      // parse result
      $msgid  = $resp['body']['messages'][0]['message-id'] ?? null;
      $status = $resp['body']['messages'][0]['status'] ?? null; // '0' = success

      // ensure sms_logs table exists (lightweight)
      $conn->query("CREATE TABLE IF NOT EXISTS sms_logs (
          id INT AUTO_INCREMENT PRIMARY KEY,
          student_id INT NULL,
          parent_id INT NULL,
          to_e164 VARCHAR(20) NOT NULL,
          message TEXT NOT NULL,
          provider VARCHAR(20) DEFAULT 'mocean',
          provider_msgid VARCHAR(64) NULL,
          status VARCHAR(32) NULL,
          http_code INT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )");

      // insert log
      $stmtL = $conn->prepare("INSERT INTO sms_logs (student_id,parent_id,to_e164,message,provider,provider_msgid,status,http_code)
                               VALUES (?,?,?,?,?,?,?,?)");
      if ($stmtL) {
        $prov = 'mocean';
        $http = $resp['http'] ?? null;
        $sid  = $student_id;
        $pid  = $parent_id ? (int)$parent_id : null;
        $stmtL->bind_param("iissssii", $sid, $pid, $toE164, $text, $prov, $msgid, $status, $http);
        $stmtL->execute();
        $stmtL->close();
      }

      $sms_info = [
        'skipped' => false,
        'to'      => $toE164,
        'status'  => $status,
        'ok'      => ($resp['ok'] ?? false) && ($status === '0'),
        'http'    => $resp['http'] ?? null,
        'msgid'   => $msgid
      ];
    }
  }

  $conn->commit();

  echo json_encode([
    'ok'         => true,
    'id'         => $id,
    'student_id' => $student_id,
    'status'     => $new_status,
    'sms'        => $sms_info
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  $conn->rollback();
  fail('DB error: '.$e->getMessage(), 500);
}
