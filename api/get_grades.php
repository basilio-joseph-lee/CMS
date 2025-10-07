<?php
// /CMS/api/get_grades.php
header('Content-Type: application/json; charset=utf-8');

// Never echo notices/warnings into JSON
error_reporting(E_ERROR | E_PARSE);

try {
    include __DIR__ . '/../config/db.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn->set_charset('utf8mb4');

    // Accept both GET or POST
    $parent_id = isset($_GET['parent_id']) ? (int)$_GET['parent_id'] : (int)($_POST['parent_id'] ?? 0);
    if ($parent_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid parent_id']);
        exit;
    }

    // Get ACTIVE School Year
    $syStmt = $conn->prepare("SELECT school_year_id, year_label FROM school_years WHERE status='active' LIMIT 1");
    $syStmt->execute();
    $syRes = $syStmt->get_result();
    if ($syRes->num_rows === 0) {
        echo json_encode(['success' => true, 'data' => [], 'note' => 'No active school year']);
        exit;
    }
    $syRow = $syRes->fetch_assoc();
    $active_sy_id = (int)$syRow['school_year_id'];
    $active_sy_label = $syRow['year_label'];

    // Get children of this parent
    $childStmt = $conn->prepare("SELECT student_id, fullname FROM students WHERE parent_id = ?");
    $childStmt->bind_param('i', $parent_id);
    $childStmt->execute();
    $children = $childStmt->get_result();

    $result = [
        'school_year_id' => $active_sy_id,
        'year_label'     => $active_sy_label,
        'students'       => []
    ];

    while ($c = $children->fetch_assoc()) {
        $student_id = (int)$c['student_id'];

        // One row per enrolled subject (active SY), with Q1–Q4 + Final + Remarks
        $gradesSql = "
            SELECT
                s.subject_id,
                s.subject_name,
                se.advisory_id,
                se.school_year_id,

                MAX(CASE WHEN q.quarter='1st' THEN q.grade END) AS q1,
                MAX(CASE WHEN q.quarter='2nd' THEN q.grade END) AS q2,
                MAX(CASE WHEN q.quarter='3rd' THEN q.grade END) AS q3,
                MAX(CASE WHEN q.quarter='4th' THEN q.grade END) AS q4,

                MAX(f.final_average) AS final_average,
                MAX(f.remarks)       AS remarks
            FROM student_enrollments se
            JOIN subjects s
              ON s.subject_id = se.subject_id
             AND s.advisory_id = se.advisory_id
             AND s.school_year_id = se.school_year_id
            LEFT JOIN quarter_grades q
              ON q.student_id     = se.student_id
             AND q.subject_id     = se.subject_id
             AND q.advisory_id    = se.advisory_id
             AND q.school_year_id = se.school_year_id
            LEFT JOIN final_grades f
              ON f.student_id     = se.student_id
             AND f.subject_id     = se.subject_id
             AND f.advisory_id    = se.advisory_id
             AND f.school_year_id = se.school_year_id
            WHERE se.student_id = ?
              AND se.school_year_id = ?
            GROUP BY s.subject_id, s.subject_name, se.advisory_id, se.school_year_id
            ORDER BY s.subject_name ASC
        ";
        $gStmt = $conn->prepare($gradesSql);
        $gStmt->bind_param('ii', $student_id, $active_sy_id);
        $gStmt->execute();
        $gRes = $gStmt->get_result();

        $subjects = [];
        while ($row = $gRes->fetch_assoc()) {
            // Normalize nulls -> null (keep numeric types clean on client)
            $subjects[] = [
                'subject_id'    => (int)$row['subject_id'],
                'subject_name'  => $row['subject_name'],
                'advisory_id'   => (int)$row['advisory_id'],
                'school_year_id'=> (int)$row['school_year_id'],
                'q1'            => isset($row['q1']) ? (float)$row['q1'] : null,
                'q2'            => isset($row['q2']) ? (float)$row['q2'] : null,
                'q3'            => isset($row['q3']) ? (float)$row['q3'] : null,
                'q4'            => isset($row['q4']) ? (float)$row['q4'] : null,
                'final_average' => isset($row['final_average']) ? (float)$row['final_average'] : null,
                'remarks'       => $row['remarks'] ?? null,
            ];
        }

        $result['students'][] = [
            'student_id' => $student_id,
            'fullname'   => $c['fullname'],
            'subjects'   => $subjects
        ];
    }

    echo json_encode(['success' => true, 'data' => $result]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Server Error', 'error' => $e->getMessage()]);
}
