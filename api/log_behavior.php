<?php
// ============================================================================
// /api/log_behavior.php — Handles student behavior logs (Hostinger-friendly)
// ============================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start(); // do NOT change session_name here; we also accept student_id in body
}

error_reporting(E_ERROR | E_PARSE);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
date_default_timezone_set('Asia/Manila');

// swallow accidental output until we emit JSON
ob_start();

require_once __DIR__ . '/../config/db.php'; // defines $conn

$response = ['success' => false, 'message' => 'Unknown error'];

try {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new Exception('Database connection not available.');
    }
    $conn->set_charset('utf8mb4');

    // Read JSON or form data
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = $_POST;

    // Prefer session; fall back to body
    $student_id = isset($_SESSION['student_id']) ? (int)$_SESSION['student_id']
                : (int)($data['student_id'] ?? 0);

    if ($student_id <= 0) {
        throw new Exception('Unauthorized: student_id missing.');
    }

    $rawType = trim((string)($data['action_type'] ?? ''));
    if ($rawType === '') {
        throw new Exception('Missing action_type.');
    }

    // --- normalize action_type ---
    $t = strtolower($rawType);
    $t = preg_replace('/[^a-z0-9]+/i', '_', $t); // collapse to underscores
    $t = trim($t, '_');

    // alias map
    $aliases = [
        'i_m_back' => 'im_back', 'imback' => 'im_back', 'i_am_back' => 'im_back',
        'back_to_class' => 'im_back', 'returned' => 'im_back', 'came_back' => 'im_back',
        'back' => 'im_back', 'log_back' => 'im_back',
        'toilet' => 'restroom', 'bathroom' => 'restroom',
        'check_in' => 'attendance', 'marked_attendance' => 'attendance',
        'snack_requested' => 'snack',
        'notwell' => 'not_well',
    ];
    if (isset($aliases[$t])) $t = $aliases[$t];

    // --- allowlist ---
    $allowed = [
        'restroom','snack','lunch_break','water_break','not_well',
        'borrow_book','return_material','participated','help_request',
        'attendance','im_back','out_time','log_out'
    ];
    if (!in_array($t, $allowed, true)) {
        throw new Exception('Forbidden action type: '.$t);
    }
    $nowPH = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');

    // --- insert ---
  $stmt = $conn->prepare("INSERT INTO behavior_logs (student_id, action_type, timestamp) VALUES (?, ?, ?)");
    if (!$stmt) {
        throw new Exception('Prepare failed: '.$conn->error);
    }
   $stmt->bind_param('iss', $student_id, $t, $nowPH);

    if (!$stmt->execute()) {
        throw new Exception('Execute failed: '.$stmt->error);
    }
    $insertId = $stmt->insert_id;
    $stmt->close();

    // friendly messages
    $friendly = [
        'restroom'        => '🚻 Restroom log saved.',
        'snack'           => '🍎 Snack break recorded.',
        'lunch_break'     => '🍱 Lunch break logged.',
        'water_break'     => '💧 Water break recorded.',
        'not_well'        => '🤒 Health log saved.',
        'borrow_book'     => '📚 Borrowing material noted.',
        'return_material' => '📦 Return recorded.',
        'participated'    => '✅ Participation logged.',
        'help_request'    => '✋ Help request sent.',
        'attendance'      => '✅ Attendance recorded.',
        'im_back'         => '🟢 Welcome back!',
        'out_time'        => '🚪 Out time recorded.',
        'log_out'         => '👋 Logged out.',
    ];

    $response = [
        'success'     => true,
        'message'     => $friendly[$t] ?? 'Behavior log saved.',
        // diagnostics (kept — helps us verify what server saw & saved)
        'normalized'  => $t,
        'student_id'  => $student_id,
        'insert_id'   => (int)$insertId,
    ];
} catch (Throwable $e) {
    $response = [
        'success' => false,
        'message' => $e->getMessage(),
    ];
}

// emit JSON
ob_end_clean();
http_response_code($response['success'] ? 200 : 400);
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
