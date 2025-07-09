<?php
function generateAvatar($base64Image, $saveDir = 'student_avatars') {
    $apiKey = "6c5af982-5126-487e-a0e5-11aece8a4ca2";
    $endpoint = 'https://api.deepai.org/api/toonify';

    if (!file_exists($saveDir)) {
        mkdir($saveDir, 0777, true);
    }

    // Save temp image
    $tempFile = tempnam(sys_get_temp_dir(), 'face_') . '.jpg';
    file_put_contents($tempFile, base64_decode(str_replace('data:image/jpeg;base64,', '', $base64Image)));

    // Prepare CURL
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $endpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['image' => new CURLFile($tempFile)],
        CURLOPT_HTTPHEADER => ['Api-Key: ' . $apiKey],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    unlink($tempFile);

    // Decode response
    $result = json_decode($response, true);

    if ($httpCode !== 200 || !isset($result['output_url'])) {
        echo "<h3>❌ DeepAI Error</h3>";
        echo "<pre>Status Code: $httpCode\n\nResponse:\n" . htmlspecialchars($response) . "\n\nCURL Error:\n$error</pre>";
        return null;
    }

    $avatarUrl = $result['output_url'];
    $avatarPath = $saveDir . '/' . uniqid('avatar_') . '.jpg';
    file_put_contents($avatarPath, file_get_contents($avatarUrl));

    return $avatarPath;
}
