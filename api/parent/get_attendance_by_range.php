<?php
// /api/parent/get_attendance_by_range.php
// Returns one row per calendar day with a single status for that day.
// Shape: { status: "success", days: [ {date:"YYYY-MM-DD", status:"Present|Absent|Late|No Record"} ] }

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ERROR | E_PARSE);

try {
    include __DIR__ . '/../../config/db.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn->set_charset('utf8mb4');

    // --- helpers ---
    function norm_dt(?string $s): ?string {
        if (!$s) return null;
        $s = trim($s);
        // Replace 'T' with space
        $s = preg_replace('/[Tt]/', ' ', $s);
        // Drop milliseconds + timezone suffix like ".123Z" or "+08:00"
        $s = preg_replace('/(\.\d+)?(Z|[+\-]\d{2}:\d{2})$/', '', $s);
        // Keep first 19 chars (YYYY-MM-DD HH:MM:SS)
        if (strlen($s) >= 19) $s = substr($s, 0, 19);
        // Fallback parseable by strtotime
        $ts = strtotime($s);
        if ($ts === false) return null;
        return date('Y-m-d H:i:s', $ts);
    }

    // GET/POST params
    $student_id     = isset($_GET['student_id']) ? (int)$_GET['student_id'] : (int)($_POST['student_id'] ?? 0);
    $from           = $_GET['from']  ?? $_POST['from']  ?? null;
    $to             = $_GET['to']    ?? $_POST['to']    ?? null;
    $school_year_id = isset($_GET['school_year_id']) ? (int)$_GET['school_year_id'] : (int)($_POST['school_year_id'] ?? 0);
    $subject_id     = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : (int)($_POST['subject_id'] ?? 0);
    $advisory_id    = isset($_GET['advisory_id']) ? (int)$_GET['advisory_id'] : (int)($_POST['advisory_id'] ?? 0);

    if ($student_id <= 0) {
        echo json_encode(['status'=>'error','message'=>'Missing student_id']); exit;
    }

    // default range: last 90 days (server local time)
    if (!$from || !$to) {
        $toDT   = new DateTime('now');
        $fromDT = (clone $toDT)->modify('-90 days');
        $from = $fromDT->format('Y-m-d H:i:s');
        $to   = $toDT->format('Y-m-d H:i:s');
    }

    $from = norm_dt($from);
    $to   = norm_dt($to);

    if (!$from || !$to) {
        echo json_encode(['status'=>'error','message'=>'Invalid date range']); exit;
    }

    // Swap if caller sent reversed
    if (strtotime($from) > strtotime($to)) {
        $tmp = $from; $from = $to; $to = $tmp;
    }

    // Build WHERE dynamically
    $where = " WHERE ar.student_id = ? AND ar.timestamp BETWEEN ? AND ? ";
    $types = "iss";
    $params = [$student_id, $from, $to];

    if ($school_year_id > 0) { $where .= " AND ar.school_year_id = ? "; $types .= "i"; $params[] = $school_year_id; }
    if ($subject_id > 0)     { $where .= " AND ar.subject_id     = ? "; $types .= "i"; $params[] = $subject_id; }
    if ($advisory_id > 0)    { $where .= " AND ar.advisory_id    = ? "; $types .= "i"; $params[] = $advisory_id; }

    // Day-level collapse using precedence: Absent > Late > Present
    $sql = "
        SELECT
            DATE(ar.timestamp) AS date,
            MAX(
                CASE LOWER(ar.status)
                    WHEN 'present' THEN 1
                    WHEN 'late'    THEN 2
                    WHEN 'absent'  THEN 3
                    ELSE 0
                END
            ) AS score
        FROM attendance_records ar
        $where
        GROUP BY DATE(ar.timestamp)
        ORDER BY DATE(ar.timestamp) ASC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $days = [];
    while ($row = $res->fetch_assoc()) {
        $status = 'No Record';
        $score = (int)$row['score'];
        if ($score === 1) $status = 'Present';
        elseif ($score === 2) $status = 'Late';
        elseif ($score === 3) $status = 'Absent';

        $days[] = [
            'date'   => $row['date'],
            'status' => $status,
        ];
    }

    echo json_encode(['status'=>'success','days'=>$days], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
