<?php
// /api/list_faces_all.php
header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Hide notices/warnings so JSON is never polluted.
error_reporting(E_ERROR | E_PARSE);

try {
    // --- DB ---
    // IMPORTANT: use a relative include (works on live hosting)
    include __DIR__ . '/../config/db.php';
    $conn->set_charset('utf8mb4');

    // Build absolute base URL like: https://myschoolness.site
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base   = $scheme . '://' . $host;

    // Helper: turn a stored path into an absolute URL under the same origin.
    // Accepts already-absolute http(s) URLs; otherwise makes "/...".
    function to_url(string $p, string $base): string {
        $p = trim($p);
        if ($p === '') return '';
        if (preg_match('#^https?://#i', $p)) return $p;         // already absolute
        if ($p[0] !== '/') $p = '/' . ltrim($p, './');          // make root-relative
        return $base . $p;
    }

    // Helper: check if the file is actually present on disk (best-effort).
    // Maps a web path "/uploads/..." -> $_SERVER['DOCUMENT_ROOT']."/uploads/..."
    function web_path_exists(string $webPath): bool {
        $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
        if ($docRoot === '') return true; // unknown; don't block
        $fsPath  = $docRoot . $webPath;
        return file_exists($fsPath);
    }

    // ---- Query students that have a face image path ----
    // Adjust table/column names if yours differ.
    $sql = "SELECT student_id, fullname, face_image_path
            FROM students
            WHERE face_image_path IS NOT NULL AND face_image_path <> ''";
    $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $raw   = (string)($r['face_image_path'] ?? '');
        $url   = to_url($raw, $base);

        // If the raw path wasn't absolute and doesn't exist under current root,
        // try a common alternate "/CMS/..." prefix (shared hosting quirk).
        if (!preg_match('#^https?://#i', $raw)) {
            $webRel = (strpos($url, $base) === 0) ? substr($url, strlen($base)) : $url;
            if (!web_path_exists($webRel)) {
                $alt = '/CMS/' . ltrim($webRel, '/');
                if (web_path_exists($alt)) {
                    $url = $base . $alt;
                }
            }
        }

        // Final safety: only include rows with some URL string
        if ($url !== '') {
            $out[] = [
                'student_id'      => (int)$r['student_id'],
                'fullname'        => (string)$r['fullname'],
                // Keep original for backwards-compat, but also expose a normalized absolute URL:
                'face_image_path' => $raw,
                'face_image_url'  => $url,
            ];
        }
    }

    echo json_encode($out, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    // Never leak HTML; return a JSON error object instead.
    http_response_code(500);
    echo json_encode(['error' => 'server_error', 'message' => $e->getMessage()]);
}
