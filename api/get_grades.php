<?php
// /CMS/api/get_final_grades.php
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ERROR | E_PARSE);

try {
    include __DIR__ . '/../config/db.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn->set_charset('utf8mb4');

    $student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : (int)($_POST['student_id'] ?? 0);
    if ($student_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Missing student_id']);
        exit;
    }

    // Join with subject name for display
    $sql = "
        SELECT 
            f.final_grade_id,
            f.student_id,
            f.subject_id,
            s.subject_name,
            f.advisory_id,
            f.school_year_id,
            f.q1, f.q2, f.q3, f.q4,
            f.final_average,
            f.remarks
        FROM final_grades f
        JOIN subjects s ON s.subject_id = f.subject_id
        WHERE f.student_id = ?
        ORDER BY s.subject_name ASC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $student_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $grades = [];
    while ($row = $res->fetch_assoc()) {
        foreach (['q1','q2','q3','q4','final_average'] as $n) {
            if ($row[$n] !== null) $row[$n] = (float)$row[$n];
        }
        $grades[] = $row;
    }

    echo json_encode(['success' => true, 'data' => $grades]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
