<?php
// /api/parent/get_quarter_grades.php
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ERROR | E_PARSE);

try {
    include __DIR__ . '/../../config/db.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn->set_charset('utf8mb4');

    $student_id     = isset($_GET['student_id']) ? (int)$_GET['student_id'] : (int)($_POST['student_id'] ?? 0);
    $school_year_id = isset($_GET['school_year_id']) ? (int)$_GET['school_year_id'] : (int)($_POST['school_year_id'] ?? 0);
    $subject_id     = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : (int)($_POST['subject_id'] ?? 0);
    $advisory_id    = isset($_GET['advisory_id']) ? (int)$_GET['advisory_id'] : (int)($_POST['advisory_id'] ?? 0);

    if ($student_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Missing student_id']); exit;
    }

    if ($school_year_id <= 0) {
        $sy = $conn->query("SELECT school_year_id FROM school_years WHERE status='active' LIMIT 1");
        if ($sy && $sy->num_rows > 0) {
            $school_year_id = (int)$sy->fetch_assoc()['school_year_id'];
        }
    }

    $where  = " WHERE q.student_id = ? ";
    $types  = "i";
    $params = [$student_id];

    if ($school_year_id > 0) { $where .= " AND q.school_year_id = ? "; $types .= "i"; $params[] = $school_year_id; }
    if ($subject_id     > 0) { $where .= " AND q.subject_id     = ? "; $types .= "i"; $params[] = $subject_id; }
    if ($advisory_id    > 0) { $where .= " AND q.advisory_id    = ? "; $types .= "i"; $params[] = $advisory_id; }

    $sql = "
        SELECT 
            q.student_id,
            q.subject_id,
            s.subject_name,
            q.advisory_id,
            q.school_year_id,
            q.quarter,
            q.grade,
            q.remarks
        FROM quarter_grades q
        JOIN subjects s ON s.subject_id = q.subject_id
        $where
        ORDER BY s.subject_name ASC, FIELD(q.quarter,'1st','2nd','3rd','4th')
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $grades = [];
    while ($row = $res->fetch_assoc()) {
        $row['subject_id']     = (int)$row['subject_id'];
        $row['advisory_id']    = (int)$row['advisory_id'];
        $row['school_year_id'] = (int)$row['school_year_id'];
        $row['grade']          = $row['grade'] === null ? null : (float)$row['grade'];
        $grades[] = $row;
    }

    echo json_encode(['status' => 'success', 'grades' => $grades]);

} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
