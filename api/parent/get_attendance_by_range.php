<?php
// /api/parent/get_attendance_by_range.php
// Returns one row per calendar day with a single status for that day.
// Shape: { status: "success", days: [ {date:"YYYY-MM-DD", status:"Present|Absent|Late"} ] }

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ERROR | E_PARSE);

try {
    include __DIR__ . '/../../config/db.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn->set_charset('utf8mb4');

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

    // default range: last 90 days
    if (!$from || !$to) {
        $toDT = new DateTime('now');
        $fromDT = (clone $toDT)->modify('-90 days');
        $from = $fromDT->format('Y-m-d') . ' 00:00:00';
        $to   = $toDT->format('Y-m-d')   . ' 23:59:59';
    }

    // Build WHERE dynamically
    $where = " WHERE ar.student_id = ? AND ar.timestamp BETWEEN ? AND ? ";
    $types = "iss";
    $params = [$student_id, $from, $to];

    if ($school_year_id > 0) { $where .= " AND ar.school_year_id = ? "; $types .= "i"; $params[] = $school_year_id; }
    if ($subject_id > 0)     { $where .= " AND ar.subject_id     = ? "; $types .= "i"; $params[] = $subject_id; }
    if ($advisory_id > 0)    { $where .= " AND ar.advisory_id    = ? "; $types .= "i"; $params[] = $advisory_id; }

    // Collapse multiple entries per day using a precedence: Absent > Late > Present
    // score: Present=1, Late=2, Absent=3; choose MAX(score), then map back to label.
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
