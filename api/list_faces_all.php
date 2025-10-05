<?php
// /api/list_faces_all.php
header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Hide notices/warnings so JSON is never polluted.
error_reporting(E_ERROR | E_PARSE);

try {
    // --- DB ---
    include __DIR__ . '/../config/db.php';
    $conn->set_charset('utf8mb4');

    // Build absolute base URL like: https://myschoolness.site
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base   = $scheme . '://' . $host;

    /**
     * Map a DB path to a web path that exists on this deployment.
     * - Accepts absolute http(s) -> return as-is.
     * - Normalizes to root-relative "/..."
     * - IMPORTANT: If it starts with "/CMS/...", strip the "/CMS" because your live files
     *   are deployed at "/student_faces/..." (no CMS folder in public_html).
     */
    function map_web_path(string $p): string {
        $p = trim($p);
        if ($p === '') return '';
        if (preg_match('#^https?://#i', $p)) return $p; // already absolute URL

        // normalize to root-relative
        $p = '/' . ltrim($p, '/');

        // LIVE FIX: drop leading "/CMS" if present (since you didn't deploy the CMS folder)
        if (strpos($p, '/CMS/') === 0) {
            $p = substr($p, 4); // remove '/CMS'
        }
        return $p;
    }

    // Quick existence check (best-effort) for root-relative paths.
    function web_path_exists(string $webPath): bool {
        if (preg_match('#^https?://#i', $webPath)) return true;
        $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
        if ($docRoot === '') return true; // unknown; don't block
        return file_exists($docRoot . $webPath);
    }

    // ---- Query students that have a face image path ----
    $sql = "SELECT student_id, fullname, face_image_path
            FROM students
            WHERE face_image_path IS NOT NULL AND face_image_path <> ''";
    $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $raw     = (string)($r['face_image_path'] ?? '');
        $webPath = map_web_path($raw);
        $url     = preg_match('#^https?://#i', $webPath) ? $webPath : ($base . $webPath);

        // Only emit rows that we can plausibly serve
        if ($webPath !== '' && web_path_exists($webPath)) {
            $out[] = [
                'student_id'      => (int)$r['student_id'],
                'fullname'        => (string)$r['fullname'],
                'face_image_path' => $raw,     // original (for reference/back-compat)
                'face_image_url'  => $url,     // normalized absolute URL used by JS
            ];
        }
    }

    echo json_encode($out, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server_error', 'message' => $e->getMessage()]);
}
