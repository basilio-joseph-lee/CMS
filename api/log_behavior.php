<?php
// ============================================================================
// /api/log_behavior.php — Handles student behavior logs (SMS only on attendance)
// ============================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

error_reporting(E_ERROR | E_PARSE);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
date_default_timezone_set('Asia/Manila');

ob_start();

require_once __DIR__ . '/../config/db.php';    // expects $conn (mysqli)
if (file_exists(__DIR__ . '/../config/sms.php')) {
    require_once __DIR__ . '/../config/sms.php'; // provides ph_e164() and send_sms()
}

$response = ['success' => false, 'message' => 'Unknown error'];

try {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new Exception('Database connection not available.');
    }
    $conn->set_charset('utf8mb4');

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = $_POST;

    $student_id = isset($_SESSION['student_id']) ? (int)$_SESSION['student_id']
                : (int)($data['student_id'] ?? 0);

    if ($student_id <= 0) {
        throw new Exception('Unauthorized: student_id missing.');
    }

    $rawType = trim((string)($data['action_type'] ?? ($data['action'] ?? '')));
    if ($rawType === '') throw new Exception('Missing action_type.');

    $t = strtolower($rawType);
    $t = preg_replace('/[^a-z0-9]+/i', '_', $t);
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

    $allowed = [
        'restroom','snack','lunch_break','water_break','not_well',
        'borrow_book','return_material','participated','help_request',
        'attendance','im_back','out_time','log_out'
    ];
    if (!in_array($t, $allowed, true)) {
        throw new Exception('Forbidden action type: '.$t);
    }

    $nowPH = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');

    // ---------------------------
    // Attendance insertion & SMS only for 'attendance'
    // ---------------------------
    if ($t === 'attendance') {
        $subject_id = isset($_SESSION['subject_id']) ? (int)$_SESSION['subject_id'] : (int)($data['subject_id'] ?? 0);
        $advisory_id = isset($_SESSION['advisory_id']) ? (int)$_SESSION['advisory_id'] : (int)($data['advisory_id'] ?? 0);
        $school_year_id = isset($_SESSION['school_year_id']) ? (int)$_SESSION['school_year_id'] : (int)($data['school_year_id'] ?? 0);

        $stmtA = $conn->prepare("INSERT INTO attendance_records (student_id, subject_id, advisory_id, school_year_id, status, timestamp) VALUES (?,?,?,?,?,?)");
        if ($stmtA) {
            $status = 'Present';
            $stmtA->bind_param("iiiiss", $student_id, $subject_id, $advisory_id, $school_year_id, $status, $nowPH);
            $stmtA->execute();
            $stmtA->close();
        } else {
            error_log('attendance_records prepare failed: '.$conn->error);
        }

        // --- SMS section ---
        $sms_meta = ['sent'=>false, 'reason'=>'not_attempted'];
        $sqlP = "
            SELECT s.fullname AS student_name,
                   p.parent_id AS parent_id,
                   p.fullname AS parent_name,
                   p.mobile_number AS parent_mobile
            FROM students s
            LEFT JOIN parents p ON p.parent_id = s.parent_id
            WHERE s.student_id = ?
            LIMIT 1
        ";
        $s2 = $conn->prepare($sqlP);
        if ($s2) {
            $s2->bind_param('i', $student_id);
            $s2->execute();
            $rowP = $s2->get_result()->fetch_assoc();
            $s2->close();
        } else {
            $rowP = null;
        }

        if (empty($rowP)) {
            $stmtCols = $conn->prepare("SELECT fullname, parent_mobile, parent_phone, guardian_mobile, guardian_phone, mobile, phone FROM students WHERE student_id = ? LIMIT 1");
            if ($stmtCols) {
                $stmtCols->bind_param('i', $student_id);
                $stmtCols->execute();
                $rowCols = $stmtCols->get_result()->fetch_assoc();
                $stmtCols->close();
            } else {
                $rowCols = null;
            }

            if (!empty($rowCols)) {
                $rowP = [
                    'student_name' => $rowCols['fullname'] ?? null,
                    'parent_id'    => null,
                    'parent_name'  => null,
                    'parent_mobile'=> $rowCols['parent_mobile'] ?? ($rowCols['parent_phone'] ?? ($rowCols['guardian_mobile'] ?? ($rowCols['guardian_phone'] ?? ($rowCols['mobile'] ?? $rowCols['phone'] ?? null))))
                ];
            }
        }

        $parent_mobile_raw = $rowP['parent_mobile'] ?? null;
        $studentName = $rowP['student_name'] ?? 'Your child';
        $parent_id = isset($rowP['parent_id']) && $rowP['parent_id'] !== '' ? (int)$rowP['parent_id'] : null;

        $to_e164 = null;
        if (function_exists('ph_e164')) {
            $to_e164 = ph_e164($parent_mobile_raw);
        } else {
            if ($parent_mobile_raw) {
                $digits = preg_replace('/\D+/', '', $parent_mobile_raw);
                if (strlen($digits) === 11 && str_starts_with($digits, '0')) $to_e164 = '63' . substr($digits, 1);
                elseif (strlen($digits) === 10 && str_starts_with($digits, '9')) $to_e164 = '63' . $digits;
                elseif (strlen($digits) === 12 && str_starts_with($digits, '63')) $to_e164 = $digits;
            }
        }

        $senderName = $MOCEAN_SENDER ?? ($MOCEAN_SENDER = 'MySchoolness');
        $msg = "{$studentName} is marked PRESENT on {$nowPH}. — {$senderName}";

        $conn->query("CREATE TABLE IF NOT EXISTS sms_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NULL,
            parent_id INT NULL,
            to_e164 VARCHAR(32) NOT NULL,
            message TEXT NOT NULL,
            provider VARCHAR(32) DEFAULT 'mocean',
            provider_msgid VARCHAR(128) NULL,
            status VARCHAR(64) NULL,
            http_code INT NULL,
            provider_error VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        if ($to_e164 && function_exists('send_sms')) {
            $smsRes = send_sms($parent_mobile_raw, $msg);
            $ok = $smsRes['ok'] ?? false;
            $http = $smsRes['http'] ?? null;
            $prov_status = $smsRes['provider_status'] ?? ($smsRes['status'] ?? null);
            $prov_msgid = $smsRes['msgid'] ?? ($smsRes['message-id'] ?? null);
            $prov_error = $smsRes['error'] ?? null;

            $stmtL = $conn->prepare("INSERT INTO sms_logs (student_id,parent_id,to_e164,message,provider,provider_msgid,status,http_code,provider_error) VALUES (?,?,?,?,?,?,?,?,?)");
            if ($stmtL) {
                $prov = 'mocean';
                $toLog = $smsRes['to'] ?? $to_e164;
                $stmtL->bind_param("iissssiss", $student_id, $parent_id, $toLog, $msg, $prov, $prov_msgid, $prov_status, $http, $prov_error);
                $stmtL->execute();
                $stmtL->close();
            }

            $sms_meta = [
                'sent' => $ok ? true : false,
                'to'   => $smsRes['to'] ?? $to_e164,
                'http' => $http,
                'provider_status' => $prov_status,
                'provider_msgid' => $prov_msgid,
                'error' => $prov_error,
            ];
            $response['sms'] = $sms_meta;
        }
    }

    // ---------------------------
    // Insert behavior log for ALL actions (including 'im_back'), no attendance or SMS
    // ---------------------------
    if ($t !== 'attendance') {
        $stmt = $conn->prepare("INSERT INTO behavior_logs (student_id, action_type, timestamp) VALUES (?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param('iss', $student_id, $t, $nowPH);
            $stmt->execute();
            $insertId = $stmt->insert_id;
            $stmt->close();
        }
    }

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

    $response['success'] = true;
    $response['message'] = $friendly[$t] ?? 'Behavior log saved.';
    $response['normalized'] = $t;
    $response['student_id'] = $student_id;
    $response['insert_id'] = isset($insertId) ? (int)$insertId : null;

} catch (Throwable $e) {
    $response = ['success' => false, 'message' => $e->getMessage()];
}

ob_end_clean();
http_response_code($response['success'] ? 200 : 200);
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
