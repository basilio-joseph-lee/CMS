<?php
include __DIR__ . '/../config/db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

ob_start();

function json_fail($msg, $code = 400){
  http_response_code($code);
  echo json_encode(["ok"=>false, "message"=>$msg], JSON_UNESCAPED_UNICODE);
  exit;
}

/* === INSERT YOUR MOCEAN TOKEN HERE === */
$MOCEAN_TOKEN  = 'apit-jv8BxWvCh8fTEsSi0iwZdlS6IUByRUP0-eLrBy'; // <-- replace with your actual token (full string)
$MOCEAN_SENDER = 'MySchoolness';
 // numeric sender (sa PH, mas sure pumasok kaysa alphanumeric)

/* === Read and parse request === */
$raw = file_get_contents("php://input");
if ($raw === false) json_fail("No input");
$payload = json_decode($raw, true);
if (!is_array($payload)) json_fail("Invalid JSON body");

$action      = $payload['action_type'] ?? ($payload['action'] ?? null);
$student_ids = $payload['student_ids'] ?? null;
$send_sms    = !empty($payload['send_sms']); // true => talagang magpadala; else preview lang

/* === Normalize / map === */
if ($action === 'out') $action = 'out_time';

$ALLOWED = [
  'attendance','restroom','snack','lunch_break','water_break',
  'not_well','borrow_book','return_material','participated','help_request','out_time'
];
if (!$action || !in_array($action, $ALLOWED, true)) {
  json_fail("Invalid action_type. Allowed: ".implode(", ", $ALLOWED));
}
if (!is_array($student_ids) || count($student_ids) === 0) {
  json_fail("student_ids must be a non-empty array");
}

/* === Helpers === */
// normalize PH numbers into E.164 (63XXXXXXXXXX)
function normalizePH($msisdn){
  if ($msisdn === null) return null;
  $s = trim((string)$msisdn);
  if ($s === '') return null;
  $hasPlus = str_starts_with($s, '+');
  $digits  = preg_replace('/\D+/', '', $s);

  // +63XXXXXXXXXX or 63XXXXXXXXXX
  if ((($hasPlus && str_starts_with($s, '+63')) || str_starts_with($digits,'63')) && strlen($digits) === 12) {
    return '63' . substr($digits, 2);
  }
  // 09XXXXXXXXX -> 63XXXXXXXXXX
  if (str_starts_with($digits, '0') && strlen($digits) === 11) {
    return '63' . substr($digits, 1);
  }
  // 9XXXXXXXXX -> 63XXXXXXXXXX
  if (strlen($digits) === 10 && str_starts_with($digits,'9')) {
    return '63' . $digits;
  }
  return null;
}

// send SMS via Mocean API
function mocean_send_sms(string $token, string $from, string $to, string $text): array {
  $url  = "https://rest.moceanapi.com/rest/2/sms";
  $data = [
    'mocean-from'        => $from,
    'mocean-to'          => $to,
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
    CURLOPT_TIMEOUT        => 15,
  ]);
  $res  = curl_exec($ch);
  $err  = curl_error($ch);
  $http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);

  if ($err) return ['ok'=>false,'error'=>$err,'http'=>$http];
  $json = json_decode($res, true);
  return ['ok'=>true,'http'=>$http,'body'=>$json ?? $res];
}

/* === Main logic === */
try {
  $conn->begin_transaction();

  // 1) Log behavior (unchanged)
  $stmt = $conn->prepare("INSERT INTO behavior_logs (student_id, action_type) VALUES (?, ?)");
  if (!$stmt) { $conn->rollback(); json_fail("DB prepare failed: ".$conn->error, 500); }
  $inserted = 0;
  foreach ($student_ids as $sid) {
    $sid = intval($sid);
    $stmt->bind_param("is", $sid, $action);
    if (!$stmt->execute()) { $stmt->close(); $conn->rollback(); json_fail("DB insert error: ".$conn->error, 500); }
    $inserted++;
  }
  $stmt->close();

  // 2) Preview recipients kung OUT TIME
  $recipients = [];
  if ($action === 'out_time') {
    $ids = array_map('intval', $student_ids);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $sql = "
      SELECT s.student_id, s.fullname AS student_name,
             p.parent_id, p.fullname AS parent_name, p.mobile_number AS parent_mobile
      FROM students s
      LEFT JOIN parents p ON p.parent_id = s.parent_id
      WHERE s.student_id IN ($ph)
    ";
    $stmt2 = $conn->prepare($sql);
    if (!$stmt2) { $conn->rollback(); json_fail("DB prepare (join) failed: ".$conn->error, 500); }
    $types = str_repeat('i', count($ids));
    $stmt2->bind_param($types, ...$ids);
    $stmt2->execute();
    $res = $stmt2->get_result();

    while ($row = $res->fetch_assoc()) {
      $raw = $row['parent_mobile'] ?? '';
      $norm = normalizePH($raw);
      $recipients[] = [
        "student_id"   => (int)$row['student_id'],
        "student_name" => $row['student_name'],
        "parent_id"    => $row['parent_id'] ? (int)$row['parent_id'] : null,
        "parent_name"  => $row['parent_name'] ?? null,
        "mobile_raw"   => $raw,
        "mobile_e164"  => $norm,
        "can_notify"   => ($row['parent_id'] !== null) && ($norm !== null)
      ];
    }
    $stmt2->close();
  }

  $result = [
    "ok" => true,
    "inserted" => $inserted,
    "action_type" => $action,
    "recipients_preview" => $recipients
  ];

  // 3) Kung send_sms=true: magpadala lang sa may parent + valid #
  if ($action === 'out_time' && $send_sms) {
    $template = "Hi, this is MySchoolness. Your child {student} has checked OUT from class.";
    $sent = []; $failed = [];

    foreach ($recipients as $r) {
      if (!$r['can_notify']) continue;
      $text = str_replace('{student}', $r['student_name'], $template);
      $resp = mocean_send_sms($GLOBALS['MOCEAN_TOKEN'], $GLOBALS['MOCEAN_SENDER'], $r['mobile_e164'], $text);

      $msgid  = $resp['body']['messages'][0]['message-id'] ?? null;
      $status = $resp['body']['messages'][0]['status'] ?? null;

      // optional: log to table
      if (isset($conn)) {
        $stmt4 = $conn->prepare("CREATE TABLE IF NOT EXISTS sms_logs (
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
        if ($stmt4) { $stmt4->execute(); $stmt4->close(); }

        $stmt5 = $conn->prepare("INSERT INTO sms_logs (student_id,parent_id,to_e164,message,provider,provider_msgid,status,http_code)
                                 VALUES (?,?,?,?,?,?,?,?)");
        if ($stmt5) {
          $prov = 'mocean';
          $http = $resp['http'] ?? null;
          $sid = $r['student_id']; $pid = $r['parent_id'];
          $stmt5->bind_param("iissssii", $sid, $pid, $r['mobile_e164'], $text, $prov, $msgid, $status, $http);
          $stmt5->execute(); $stmt5->close();
        }
      }

      if ($resp['ok'] && $status === '0') {
        $sent[] = $r['mobile_e164'];
      } else {
        $failed[] = [
          'to' => $r['mobile_e164'],
          'error' => $resp['error'] ?? ($resp['body'] ?? 'unknown')
        ];
      }
    }

    $result["sent_count"]   = count($sent);
    $result["failed_count"] = count($failed);
    $result["sent_to"]      = $sent;
    $result["failed"]       = $failed;
  }

  $conn->commit();
  echo json_encode($result, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  if ($conn && $conn->errno) { @ $conn->rollback(); }
  json_fail("DB error: ".$e->getMessage(), 500);
}
?>
