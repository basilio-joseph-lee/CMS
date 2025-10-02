<?php
// CMS/config/face_cache_reload.php
// Helper to tell Flask to rebuild its in-memory face cache.

if (!function_exists('reload_face_cache')) {
    function reload_face_cache(): bool {
        $url   = 'http://127.0.0.1:5000/reload_faces';
        // MUST match server.py's RELOAD_TOKEN
        $token = 'kiosk-reload-123';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'X-Reload-Token: ' . $token,
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT        => 4,
        ]);
        $res = curl_exec($ch);
        if ($res === false) { curl_close($ch); return false; }
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http !== 200) return false;
        $json = json_decode($res, true);
        return !empty($json['ok']);
    }
}
