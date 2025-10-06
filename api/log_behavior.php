<?php
// ============================================================================
// log_behavior.php — Handles student behavior logs
// Called by: dashboard.php, classroom_simulator.php, mobile parent app, etc.
// ============================================================================

session_name('CMS_STUDENT');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
error_reporting(E_ERROR | E_PARSE);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
date_default_timezone_set('Asia/Manila');

// buffer any accidental output; we'll clear it before sending JSON
ob_start();

include __DIR__ . '/../config/db.php'; // DB only; do NOT (re)start session here


$response = ['success' => false, 'message' => 'Unknown error'];

try {
// Accept either JSON or form-urlencoded body
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST;

// Prefer session; fall back to JSON body
$student_id = isset($_SESSION['student_id']) ? (int)$_SESSION['student_id']
            : (int)($data['student_id'] ?? 0);

if ($student_id <= 0) {
    throw new Exception('Unauthorized access: student not logged in.');
}

$action_type = trim((string)($data['action_type'] ?? ''));

    if ($action_type === '') throw new Exception('Missing action_type.');

    // --- Allowlist ---
    $allowed = [
        'restroom','snack','lunch_break','water_break','not_well',
        'borrow_book','return_material','participated','help_request',
        'attendance','im_back','out_time','log_out'
    ];
    if (!in_array($action_type, $allowed, true)) {
        throw new Exception('Forbidden action type.');
    }

    // --- Insert behavior log ---
    $stmt = $conn->prepare("
        INSERT INTO behavior_logs (student_id, action_type, timestamp)
        VALUES (?, ?, NOW())
    ");
    $stmt->bind_param('is', $student_id, $action_type);
    $stmt->execute();
    $stmt->close();

    // --- Optional: friendly message ---
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
        'log_out'         => '👋 Logged out.'
    ];

    $msg = $friendly[$action_type] ?? 'Behavior log saved.';

    $response = ['success' => true, 'message' => $msg];

} catch (Exception $e) {
    $response = ['success' => false, 'message' => $e->getMessage()];
}

$payload = json_encode($response, JSON_UNESCAPED_UNICODE);

ob_end_clean(); // drop any accidental output
http_response_code($response['success'] ? 200 : 400);
echo $payload;
exit;
