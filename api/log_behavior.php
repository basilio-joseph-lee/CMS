<?php
// ============================================================================
// log_behavior.php — Handles student behavior logs
// Called by: dashboard.php, classroom_simulator.php, mobile parent app, etc.
// ============================================================================

header('Content-Type: application/json');
date_default_timezone_set('Asia/Manila');

include __DIR__ . '/../config/db.php';      // starts the session (once) + DB
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

ob_start();                                  // buffer any accidental output

session_name('CMS_STUDENT');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$response = ['success' => false, 'message' => 'Unknown error'];

try {
    // --- Ensure logged in ---
    $student_id = $_SESSION['student_id'] ?? ($_POST['student_id'] ?? null);
    if (!$student_id) {
        throw new Exception('Unauthorized access: student not logged in.');
    }

    // --- Get POST data ---
    // Accept either JSON or form-urlencoded body
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = $_POST;

    $action_type = trim($data['action_type'] ?? '');
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

echo json_encode($response, JSON_UNESCAPED_UNICODE);
