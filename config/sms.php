<?php
// config/sms.php — Mocean helper (works for PH numbers)
// Prefer environment variables on your host; fall back to literals.

$MOCEAN_TOKEN  = getenv('MOCEAN_TOKEN')  ?: 'apit-jv8BxWvCh8fTEsSi0iwZdlS6IUByRUP0-eLrBy';
$MOCEAN_SENDER = getenv('MOCEAN_SENDER') ?: 'MySchoolness'; // or numeric long number (recommended in PH)

/**
 * Normalize PH mobile to E.164 (no +): 63XXXXXXXXXX
 */
function ph_e164(?string $msisdn): ?string {
  if ($msisdn === null) return null;
  $d = preg_replace('/\D+/', '', $msisdn);
  if ($d === '') return null;
  if (strlen($d) === 11 && str_starts_with($d, '0')) return '63'.substr($d,1);
  if (strlen($d) === 10 && str_starts_with($d, '9')) return '63'.$d;
  if (strlen($d) === 12 && str_starts_with($d, '63')) return $d;
  return null;
}

/**
 * Send SMS via Mocean. Returns a rich result:
 * ['ok'=>bool, 'to'=>string, 'http'=>int|null, 'provider_status'=>string|null, 'msgid'=>string|null, 'error'=>string|null, 'raw'=>mixed]
 */
function send_sms(string $toMsisdn, string $text): array {
  global $MOCEAN_TOKEN, $MOCEAN_SENDER;

  $to = ph_e164($toMsisdn);
  if (!$to) return ['ok'=>false, 'to'=>$toMsisdn, 'error'=>'invalid_msisdn', 'http'=>null, 'provider_status'=>null, 'msgid'=>null];

  $url  = 'https://rest.moceanapi.com/rest/2/sms';
  $data = [
    'mocean-from'        => $MOCEAN_SENDER,
    'mocean-to'          => $to,
    'mocean-text'        => $text,
    'mocean-resp-format' => 'JSON',
  ];

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($data),
    CURLOPT_HTTPHEADER     => [
      "Authorization: Bearer {$MOCEAN_TOKEN}",
      "Content-Type: application/x-www-form-urlencoded",
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 25,
  ]);

  $res  = curl_exec($ch);
  $err  = curl_error($ch);
  $http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);

  if ($err) return ['ok'=>false, 'to'=>$to, 'http'=>$http, 'error'=>$err, 'provider_status'=>null, 'msgid'=>null];

  $j = json_decode($res, true);
  // Mocean success = messages[0].status === '0'
  $msg = $j['messages'][0] ?? null;
  $status = is_array($msg) ? ($msg['status'] ?? null) : null;
  $ok = ($status === '0');

  return [
    'ok'              => $ok,
    'to'              => $to,
    'http'            => $http,
    'provider_status' => $status,
    'msgid'           => $msg['message-id'] ?? null,
    'error'           => $ok ? null : ($j['status'] ?? $res),
    'raw'             => $j ?: $res,
  ];
}
