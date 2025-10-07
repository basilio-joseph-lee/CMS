<?php
// /config/get_final_grades.php
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ERROR | E_PARSE);

try {
    // Use your existing DB config
    include __DIR__ . '/db.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn->set_charset('utf8mb4');

    // Accept both GET/POST
    $student_id     = isset($_GET['student_id']) ? (int)$_GET['student_id'] : (int)($_POST['student_id'] ?? 0);
    $school_year_id = isset($_GET['school_year_id']) ? (int)$_GET['school_year_id'] : (int)($_POST['school_year_id'] ?? 0);

    if ($student_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Missing student_id']);
        exit;
    }

    // If no school_year_id provided, use ACTIVE SY
    if ($school_year_id <= 0) {
        $sy = $conn->query("SELECT school_year_id FROM school_years WHERE status='active' LIMIT 1");
        if ($sy && $sy->num_rows > 0) {
            $school_year_id = (int)$sy->fetch_assoc()['school_year_id'];
        }
    }

    // Pull final grades + subject name; optionally filter to active SY
    $sql = "
        SELECT 
            f.final_grade_id,
            f.student_id,
            f.subject_id,
            s.subject_name,
            f.advisory_id,
            f.school_year_id,
            f.q1, f.q2, f3.q3, f4.q4, -- placeholders, will be replaced next line
            f.q3, f.q4,
            f.final_average,
            f.remarks
        FROM final_grades f
        JOIN subjects s ON s.subject_id = f.subject_id
        WHERE f.student_id = ?
        " . ($school_year_id > 0 ? " AND f.school_year_id = ? " : "") . "
        ORDER BY s.subject_name ASC
    ";
    // Fix a typo introduced by comment-insertion above
    $sql = str_replace("f3.q3, f4.q4, -- placeholders, will be replaced next line\n            f.q3, f.q4,", "f.q3, f.q4,", $sql);

    if ($school_year_id > 0) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ii', $student_id, $school_year_id);
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $student_id);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $out = [];
    while ($row = $res->fetch_assoc()) {
        // Cast numerics
        foreach (['q1','q2','q3','q4','final_average'] as $k) {
            if ($row[$k] !== null) $row[$k] = (float)$row[$k];
        }
        $row['subject_id']     = (int)$row['subject_id'];
        $row['advisory_id']    = (int)$row['advisory_id'];
        $row['school_year_id'] = (int)$row['school_year_id'];
        $out[] = $row;
    }

    echo json_encode([
        'status'       => 'success',
        'final_grades' => $out,
    ]);

} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
