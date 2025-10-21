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
// include SMS helper (uses environment fallback in config/sms.php)
if (file_exists(__DIR__ . '/../config/sms.php')) {
    require_once __DIR__ . '/../config/sms.php';
}

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

    $rawType = trim((string)($data['action_type'] ?? ($data['action'] ?? '')));
    if ($rawType === '') {
        throw new Exception('Missing action_type.');
    }

    // --- normalize action_type ---
    $t = strtolower($rawType);
    $t = preg_replace('/[^a-z0-9]+/i', '_', $t); // collapse to underscores
    $t = trim($t, '_');

    // If client sent 'attendance' as specific boolean flag in older code, support it
    if (isset($data['attendance']) && $data['attendance']) $t = 'attendance';

    // NOTE: original code inserted attendance record earlier in file. We'll keep that behavior
    if ($t === 'attendance') {
        // Defensive: ensure session values exist (fall back to data)
        $subject_id = isset($_SESSION['subject_id']) ? (int)$_SESSION['subject_id'] : (int)($data['subject_id'] ?? 0);
        $advisory_id = isset($_SESSION['advisory_id']) ? (int)$_SESSION['advisory_id'] : (int)($data['advisory_id'] ?? 0);
        $school_year_id = isset($_SESSION['school_year_id']) ? (int)$_SESSION['school_year_id'] : (int)($data['school_year_id'] ?? 0);

        $stmt2 = $conn->prepare("INSERT INTO attendance_records (student_id, subject_id, advisory_id, school_year_id, status) VALUES (?,?,?,?,?)");
        if ($stmt2) {
            $status = 'Present';
            $stmt2->bind_param("iiiis", $student_id, $subject_id, $advisory_id, $school_year_id, $status);
            $stmt2->execute();
            $stmt2->close();
        } else {
            // don't abort — continue to behavior_logs insertion and still attempt to notify
            error_log('attendance_records prepare failed: ' . $conn->error);
        }
    }

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

    // --- insert behavior log ---
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

    // ---------------------------
    // SEND SMS TO PARENT ON ATTENDANCE
    // ---------------------------
    if ($t === 'attendance' && function_exists('send_sms')) {
        try {
            // 1) discover which parent phone column exists (defensive)
            $possibleCols = ['parent_mobile','parent_phone','guardian_mobile','guardian_phone','parent_contact','guardian_contact','mobile','phone'];
            $colsRes = $conn->query("SHOW COLUMNS FROM students");
            $presentCols = [];
            if ($colsRes) {
                while ($c = $colsRes->fetch_assoc()) {
                    $presentCols[] = $c['Field'];
                }
                $colsRes->free();
            }

            $foundCol = null;
            foreach ($possibleCols as $pc) {
                if (in_array($pc, $presentCols, true)) {
                    $foundCol = $pc;
                    break;
                }
            }

            // 2) fetch student's fullname + parent phone if any
            $parentPhone = null;
            $studentFullname = null;
            if ($foundCol) {
                // safe because $foundCol came from DB
                $sql = "SELECT fullname, `{$foundCol}` AS parent_phone FROM students WHERE student_id = ? LIMIT 1";
                $s2 = $conn->prepare($sql);
                if ($s2) {
                    $s2->bind_param('i', $student_id);
                    $s2->execute();
                    $r = $s2->get_result();
                    if ($row = $r->fetch_assoc()) {
                        $parentPhone = $row['parent_phone'] ?? null;
                        $studentFullname = $row['fullname'] ?? null;
                    }
                    $s2->close();
                }
            } else {
                // If no known column present, try to get fullname only (no phone)
                $s3 = $conn->prepare("SELECT fullname FROM students WHERE student_id = ? LIMIT 1");
                if ($s3) {
                    $s3->bind_param('i', $student_id);
                    $s3->execute();
                    $r = $s3->get_result();
                    if ($row = $r->fetch_assoc()) $studentFullname = $row['fullname'] ?? null;
                    $s3->close();
                }
            }

            // 3) send SMS if phone found and non-empty
            if (!empty($parentPhone)) {
                // build message (short, PH friendly). Avoid exceeding SMS length.
                $nameText = $studentFullname ? trim($studentFullname) : 'Your child';
                $msg = "{$nameText} is marked PRESENT for today ({$nowPH}). — {$MOCEAN_SENDER}";

                // send and capture result
                $smsResult = send_sms($parentPhone, $msg);

                // attach SMS result to response for debugging (non-sensitive)
                $response['sms_sent'] = $smsResult['ok'] ? true : false;
                $response['sms_http'] = $smsResult['http'] ?? null;
                $response['sms_provider_status'] = $smsResult['provider_status'] ?? null;
                // log when not ok
                if (!$smsResult['ok']) {
                    // keep server log for failures
                    error_log('SMS send failed for student_id=' . $student_id . ' phone=' . $parentPhone . ' res=' . json_encode($smsResult));
                }
            } else {
                // nothing to send
                $response['sms_sent'] = false;
                $response['sms_reason'] = 'no_parent_phone';
            }
        } catch (Throwable $se) {
            // do not break the main flow — just record diagnostic
            error_log('SMS notify error: ' . $se->getMessage());
            $response['sms_sent'] = false;
            $response['sms_error'] = $se->getMessage();
        }
    }

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
