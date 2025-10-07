<?php
// /api/parent/get_enrollments.php
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ERROR | E_PARSE);

try {
    include __DIR__ . '/../../config/db.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn->set_charset('utf8mb4');

    $student_id     = isset($_GET['student_id']) ? (int)$_GET['student_id'] : (int)($_POST['student_id'] ?? 0);
    $school_year_id = isset($_GET['school_year_id']) ? (int)$_GET['school_year_id'] : (int)($_POST['school_year_id'] ?? 0);

    if ($student_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Missing student_id']); exit;
    }

    if ($school_year_id <= 0) {
        $sy = $conn->query("SELECT school_year_id FROM school_years WHERE status='active' LIMIT 1");
        if ($sy && $sy->num_rows > 0) {
            $school_year_id = (int)$sy->fetch_assoc()['school_year_id'];
        }
    }

    $sql = "
        SELECT 
            se.enrollment_id,
            se.student_id,
            se.subject_id,
            se.advisory_id,
            se.school_year_id,
            s.subject_name,
            ac.class_name
        FROM student_enrollments se
        LEFT JOIN subjects s ON s.subject_id = se.subject_id
        LEFT JOIN advisory_classes ac ON ac.advisory_id = se.advisory_id
        WHERE se.student_id = ?
        " . ($school_year_id > 0 ? " AND se.school_year_id = ? " : "") . "
        ORDER BY s.subject_name ASC
    ";

    if ($school_year_id > 0) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ii', $student_id, $school_year_id);
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $student_id);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    $enrollments = [];
    while ($row = $res->fetch_assoc()) {
        $enrollments[] = [
            'enrollment_id'  => (int)$row['enrollment_id'],
            'student_id'     => (int)$row['student_id'],
            'subject_id'     => (int)$row['subject_id'],
            'advisory_id'    => (int)$row['advisory_id'],
            'school_year_id' => (int)$row['school_year_id'],
            'subject_name'   => $row['subject_name'] ?? 'Subject',
            'class_name'     => $row['class_name'] ?? null,
        ];
    }

    echo json_encode(['status' => 'success', 'enrollments' => $enrollments]);

} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
