<?php
// /api/parent/get_attendance_records.php
// Returns raw rows with timestamp, suitable for detailed lists.
// Shape: { status: "success", records: [ {attendance_id, date, timestamp, status, subject_id, advisory_id, school_year_id} ] }

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ERROR | E_PARSE);

try {
    include __DIR__ . '/../../config/db.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn->set_charset('utf8mb4');

    $student_id     = isset($_GET['student_id']) ? (int)$_GET['student_id'] : (int)($_POST['student_id'] ?? 0);
    $from           = $_GET['from']  ?? $_POST['from']  ?? null;
    $to             = $_GET['to']    ?? $_POST['to']    ?? null;
    $school_year_id = isset($_GET['school_year_id']) ? (int)$_GET['school_year_id'] : (int)($_POST['school_year_id'] ?? 0);
    $subject_id     = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : (int)($_POST['subject_id'] ?? 0);
    $advisory_id    = isset($_GET['advisory_id']) ? (int)$_GET['advisory_id'] : (int)($_POST['advisory_id'] ?? 0);
    $limit          = isset($_GET['limit']) ? (int)$_GET['limit'] : (int)($_POST['limit'] ?? 0);

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

    $where = " WHERE ar.student_id = ? AND ar.timestamp BETWEEN ? AND ? ";
    $types = "iss";
    $params = [$student_id, $from, $to];

    if ($school_year_id > 0) { $where .= " AND ar.school_year_id = ? "; $types .= "i"; $params[] = $school_year_id; }
    if ($subject_id > 0)     { $where .= " AND ar.subject_id     = ? "; $types .= "i"; $params[] = $subject_id; }
    if ($advisory_id > 0)    { $where .= " AND ar.advisory_id    = ? "; $types .= "i"; $params[] = $advisory_id; }

    $sql = "
        SELECT
            ar.attendance_id,
            ar.student_id,
            ar.subject_id,
            ar.advisory_id,
            ar.school_year_id,
            ar.status,
            ar.timestamp,
            DATE(ar.timestamp) AS date
        FROM attendance_records ar
        $where
        ORDER BY ar.timestamp DESC
    ";

    if ($limit > 0) {
        $sql .= " LIMIT ? ";
        $types .= "i";
        $params[] = $limit;
    }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = [
            'attendance_id'  => (int)$r['attendance_id'],
            'student_id'     => (int)$r['student_id'],
            'subject_id'     => (int)$r['subject_id'],
            'advisory_id'    => (int)$r['advisory_id'],
            'school_year_id' => (int)$r['school_year_id'],
            'status'         => (string)$r['status'],
            'timestamp'      => (string)$r['timestamp'],
            'date'           => (string)$r['date'],
        ];
    }

    echo json_encode(['status'=>'success','records'=>$rows], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
