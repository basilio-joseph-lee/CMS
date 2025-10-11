<?php
// test_sms.php — Mocean token-based SMS test

$token   = "apit-a31jaJaUCd9wE7lfnuzzjjPT3QU5FjED-avNb5";            // paste the full token you generated
$from    = "MySchoolness";               // use approved sender ID or a numeric long number
$to      = "639630499218";               // your TNT number in E.164 (63 + number)
$message = "Hello! ✅ Test SMS from MySchoolness via Mocean (token auth).";

$url = "https://rest.moceanapi.com/rest/2/sms";

// Build POST body (same params as key/secret flow, but we auth via Bearer token)
$data = [
    'mocean-from' => $from,
    'mocean-to'   => $to,
    'mocean-text' => $message,
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

$response = curl_exec($ch);
$err      = curl_error($ch);
$code     = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

header("Content-Type: text/plain; charset=utf-8");
if ($err) {
    echo "❌ cURL Error: $err\n";
} else {
    echo "HTTP {$code}\n\n";
    echo $response;
}
