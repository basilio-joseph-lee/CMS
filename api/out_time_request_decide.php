<?php
// /api/out_time_request_decide.php
// Approve/Deny Out-Time and (on approve) notify parent via Mocean SMS, with DEBUG support.

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/db.php'; // must set $conn; (optional) $MOCEAN_TOKEN, $MOCEAN_SENDER

function fail($msg, $code=400, $debug=null){
  http_response_code($code);
  $out = ['ok'=>false, 'message'=>$msg];
  if ($debug !== null) $out['debug'] = $debug;
  echo json_encode($out, JSON_UNESCAPED_UNICODE);
  exit;
}

// ====== TEMP (you can move to config/env later) ======
$MOCEAN_TOKEN  = $MOCEAN_TOKEN  ?? 'apit-jv8BxWvCh8fTEsSi0iwZdlS6IUByRUP0-eLrBy';
$MOCEAN_SENDER = $MOCEAN_SENDER ?? 'MySchoolness';

// ---------------- Helpers ----------------
function normalizePH($msisdn){
  if ($msisdn === null) return null;
  $s = trim((string)$msisdn);
  if ($s === '') return null;
  $hasPlus = str_starts_with($s, '+');
  $digits  = preg_replace('/\D+/', '', $s);

  if ((($hasPlus && str_starts_with($s, '+63')) || str_starts_with($digits,'63')) && strlen($digits) === 12) {
    return '63' . substr($digits, 2);
  }
  if (str_starts_with($digits, '0') && strlen($digits) === 11) {
    return '63' . substr($digits, 1);
  }
  if (strlen($digits) === 10 && str_starts_with($digits,'9')) {
    return '63' . $digits;
  }
  return null;
}

function mocean_send_sms(string $token, string $from, string $to, string $text): array {
  $url  = "https://rest.moceanapi.com/rest/2/sms";
  $data = [
    'mocean-from'        => $from,
    'mocean-to'          => $to,
    'mocean-text'        => $text,
    'mocean-resp-format' => 'JSON'
  ];

  if (!function_exists('curl_init')) {
    return ['ok'=>false, 'http'=>0, 'error'=>'PHP cURL extension is not enabled', 'raw'=>null, 'parsed'=>null];
  }

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

  $parsed = null;
  if ($res !== false) $parsed = json_decode($res, true);

  if ($err) return ['ok'=>false, 'http'=>$http, 'error'=>$err, 'raw'=>$res, 'parsed'=>$parsed];

  // Mocean success if messages[0].status === "0"
  $status = $parsed['messages'][0]['status'] ?? null;
  $errorText = $parsed['messages'][0]['err_msg'] ?? $parsed['messages'][0]['error-text'] ?? null;

  return [
    'ok'     => ($status === '0'),
    'http'   => $http,
    'raw'    => $res,
    'parsed' => $parsed,
    'status' => $status,
    'error'  => $errorText
  ];
}

// --------------- Auth & Input ---------------
if (!isset($_SESSION['teacher_id'])) fail('Teacher not logged in', 401);
$teacher_id = (int)$_SESSION['teacher_id'];

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) $body = $_POST;

$DEBUG = (isset($_GET['debug']) && $_GET['debug'] !== '0') || (!empty($body['debug']));
$DBG = []; // debug bucket
$DBG['ts'] = date('c');

$id     = isset($body['id']) ? (int)$body['id'] : 0;
$action = strtolower(trim((string)($body['action'] ?? '')));
$note   = trim((string)($body['note'] ?? ''));

$DBG['input'] = ['id'=>$id,'action'=>$action,'note'=>$note];

if ($id <= 0) fail('Missing/invalid request id', 400, $DBG);
if (!in_array($action, ['approve','deny'], true)) fail('Action must be approve or deny', 400, $DBG);

$conn->set_charset('utf8mb4');

// --------------- Load + authorize request ---------------
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
if (!$stmt) fail('Prepare failed: '.$conn->error, 500, $DBG);
$stmt->bind_param('iii', $id, $teacher_id, $teacher_id);
$stmt->execute();
$res = $stmt->get_result();
$req = $res->fetch_assoc();
$stmt->close();

$DBG['request_row'] = $req;

if (!$req) fail('Request not found, already decided, or not under your sections/subjects', 404, $DBG);

$student_id = (int)$req['student_id'];

// --------------- Decide + optional SMS ---------------
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
  $DBG['update_rows'] = $stmtU->affected_rows;
  if ($stmtU->affected_rows !== 1) {
    throw new Exception('Request already decided by someone else.');
  }
  $stmtU->close();

  $sms_info = ['skipped' => true, 'reason' => 'not approved'];

  // 2) If approved: behavior log + SMS
  if ($new_status === 'approved') {
    // a) behavior log
    $stmtB = $conn->prepare("INSERT INTO behavior_logs (student_id, action_type, timestamp) VALUES (?, 'out_time', NOW())");
    if ($stmtB) { $stmtB->bind_param('i', $student_id); $stmtB->execute(); $DBG['behavior_insert_id'] = $stmtB->insert_id; $stmtB->close(); }

    // b) parent lookup
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

    $DBG['parent_row'] = $rp;

    $parent_id   = $rp['parent_id'] ?? null;
    $studentName = $rp['student_name'] ?? 'your child';
    $rawMobile   = $rp['parent_mobile'] ?? '';
    $toE164      = normalizePH($rawMobile);
    $DBG['mobile'] = ['raw'=>$rawMobile, 'normalized'=>$toE164];

    // c) message template
    $template = "Hi, this is MySchoolness. Your child {student} has been approved for OUT TIME by the teacher.";
    $text     = str_replace('{student}', $studentName, $template);

    // d) token/sender
    $token  = $MOCEAN_TOKEN ?: null;
    $sender = $MOCEAN_SENDER ?: 'MySchoolness';
    $DBG['sms_cfg'] = ['have_token'=> !!$token, 'sender'=>$sender];

    if (!$parent_id || !$toE164) {
      $sms_info = ['skipped'=>true, 'reason'=> !$parent_id ? 'no parent record' : 'invalid mobile', 'mobile_raw'=>$rawMobile];
      $DBG['sms_skip'] = $sms_info;
    } elseif (!$token) {
      $sms_info = ['skipped'=>true, 'reason'=>'MOCEAN_TOKEN missing in config', 'to'=>$toE164];
      $DBG['sms_skip'] = $sms_info;
    } else {
      // e) send
      $resp = mocean_send_sms($token, $sender, $toE164, $text);

      // Parse Mocean canonical fields
      $parsed = is_array($resp['parsed'] ?? null) ? $resp['parsed'] : [];
      $msg    = $parsed['messages'][0] ?? [];
      $msgid  = $msg['message-id'] ?? null;
      $status = $msg['status'] ?? null;                 // '0' success
      $eText  = $msg['err_msg'] ?? ($msg['error-text'] ?? null);

      $DBG['sms_resp'] = [
        'ok'    => $resp['ok'] ?? false,
        'http'  => $resp['http'] ?? null,
        'status'=> $status,
        'error' => $eText,
        'raw_trunc' => isset($resp['raw']) ? substr($resp['raw'],0,400) : null
      ];

      // ensure sms_logs table exists
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
          provider_error VARCHAR(255) NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )");

      $stmtL = $conn->prepare("INSERT INTO sms_logs (student_id,parent_id,to_e164,message,provider,provider_msgid,status,http_code,provider_error)
                               VALUES (?,?,?,?,?,?,?,?,?)");
      if ($stmtL) {
        $prov = 'mocean';
        $http = $resp['http'] ?? null;
        $sid  = $student_id;
        $pid  = $parent_id ? (int)$parent_id : null;
        $stmtL->bind_param("iissssiss", $sid, $pid, $toE164, $text, $prov, $msgid, $status, $http, $eText);
        $stmtL->execute();
        $DBG['sms_log_insert_id'] = $stmtL->insert_id;
        $stmtL->close();
      }

      $sms_info = [
        'skipped' => false,
        'to'      => $toE164,
        'ok'      => ($status === '0'),
        'status'  => $status,
        'http'    => $resp['http'] ?? null,
        'msgid'   => $msgid,
        'error'   => $eText
      ];
    }
  }

  $conn->commit();

  $out = [
    'ok'         => true,
    'id'         => $id,
    'student_id' => $student_id,
    'status'     => $new_status,
    'sms'        => $sms_info
  ];
  if ($DEBUG) $out['debug'] = $DBG;

  echo json_encode($out, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  $conn->rollback();
  fail('DB error: '.$e->getMessage(), 500, $DBG);
}
