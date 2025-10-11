<?php
// Central place for SMS config + helper.
// Prefer env vars on Hostinger → Advanced → Environment, else fallback.

$MOCEAN_TOKEN  = getenv('MOCEAN_TOKEN') ?: 'apit-a31jaJaUCd9wE7lfnuzzjjPT3QU5FjED-avNb5';
$MOCEAN_SENDER = getenv('MOCEAN_SENDER') ?: 'MySchoolness'; // or a numeric long number like 639xx...

/**
 * Send an SMS (Mocean). Return true on success, false otherwise.
 */
function send_sms(string $toMsisdn, string $text): bool {
  global $MOCEAN_TOKEN, $MOCEAN_SENDER;

  // Normalize PH numbers (63xxxxxxxxxx)
  $digits = preg_replace('/\D+/', '', $toMsisdn);
  if (str_starts_with($digits,'0') && strlen($digits)===11) $digits = '63'.substr($digits,1);
  elseif (strlen($digits)===10 && str_starts_with($digits,'9')) $digits = '63'.$digits;
  elseif (str_starts_with($digits,'63') && strlen($digits)===12) { /* ok */ }
  else return false;

  $url  = "https://rest.moceanapi.com/rest/2/sms";
  $data = [
    'mocean-from'        => $MOCEAN_SENDER,
    'mocean-to'          => $digits,
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
    CURLOPT_TIMEOUT        => 20,
  ]);
  $res  = curl_exec($ch);
  $err  = curl_error($ch);
  curl_close($ch);

  if ($err) return false;
  $j = json_decode($res, true);
  // Mocean success: messages[0].status === '0'
  return isset($j['messages'][0]['status']) && $j['messages'][0]['status'] === '0';
}
