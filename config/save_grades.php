<?php
session_start();
include 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subject_id = $_POST['subject_id'];
    $advisory_id = $_POST['advisory_id'];
    $quarter = $_POST['quarter'];
    $school_year_id = $_SESSION['school_year_id'];
    $teacher_id = $_SESSION['teacher_id'];
    $weights = $_POST['weights'];

    // 🛡️ Validate portal status if publishing
    if (isset($_POST['publish'])) {
        $check = $conn->prepare("SELECT status FROM grading_portals WHERE school_year_id = ? AND quarter = ?");
        $check->bind_param("is", $school_year_id, $quarter);
        $check->execute();
        $res = $check->get_result()->fetch_assoc();

        if (!$res || $res['status'] !== 'open') {
            $_SESSION['toast'] = "❌ Cannot publish. Portal for $quarter quarter is closed.";
            $_SESSION['toast_type'] = "error";
            header("Location: ../user/teacher/grading_sheet.php?quarter=" . urlencode($quarter));
            exit();
        }
    }

    // ✅ Save weights
    $stmt = $conn->prepare("REPLACE INTO grade_weights 
        (subject_id, advisory_id, quarter, school_year_id, quiz_weight, activity_weight, performance_weight, exam_weight) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iisiiiii", $subject_id, $advisory_id, $quarter, $school_year_id,
        $weights['quiz'], $weights['activity'], $weights['performance'], $weights['exam']);
    $stmt->execute();

    // ✅ Save component scores
    foreach ($_POST['scores'] as $student_id => $components) {
        foreach ($components as $type => $items) {
            foreach ($items as $item_no => $score) {
                $score = ($score !== '') ? (int)$score : null;
                $max_score = $_POST['max_scores'][$type][$item_no] ?? null;
                $max_score = ($max_score !== '') ? (int)$max_score : null;

                if ($score !== null && $max_score !== null) {
                    $stmt = $conn->prepare("REPLACE INTO grade_components 
                        (student_id, subject_id, advisory_id, school_year_id, quarter, component_type, item_no, score, max_score) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("iiiissiii", $student_id, $subject_id, $advisory_id, $school_year_id,
                        $quarter, $type, $item_no, $score, $max_score);
                    $stmt->execute();
                }
            }
        }
    }

    // ✅ Compute quarter grades
    $students = $conn->query("SELECT student_id FROM student_enrollments 
                              WHERE subject_id = $subject_id AND advisory_id = $advisory_id AND school_year_id = $school_year_id");

    while ($row = $students->fetch_assoc()) {
        $student_id = $row['student_id'];
        $totals = ["quiz" => [], "activity" => [], "performance" => [], "exam" => []];

        $grades = $conn->query("SELECT component_type, score, max_score FROM grade_components 
                                WHERE student_id = $student_id AND subject_id = $subject_id AND advisory_id = $advisory_id AND school_year_id = $school_year_id AND quarter = '$quarter'");

        while ($g = $grades->fetch_assoc()) {
            if ($g['max_score'] > 0) {
                $percent = ($g['score'] / $g['max_score']) * 100;
                $totals[$g['component_type']][] = $percent;
            }
        }

        $total = 0; $weightSum = 0;
        foreach ($totals as $type => $arr) {
            if (count($arr) && $weights[$type] > 0) {
                $avg = array_sum($arr) / count($arr);
                $total += $avg * ($weights[$type] / 100);
                $weightSum += $weights[$type];
            }
        }

        $final_avg = ($weightSum > 0) ? round($total, 2) : 0;
        $remarks = ($weightSum > 0) ? ($final_avg >= 75 ? 'Passed' : 'Failed') : 'INC';

        // ✅ Upsert quarter grade
        $stmt = $conn->prepare("INSERT INTO quarter_grades 
            (student_id, subject_id, advisory_id, school_year_id, quarter, average, grade, remarks)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE average = VALUES(average), grade = VALUES(grade), remarks = VALUES(remarks)");
        $stmt->bind_param("iiiissds", $student_id, $subject_id, $advisory_id, $school_year_id, $quarter, $final_avg, $final_avg, $remarks);
        $stmt->execute();

        // ✅ If publishing, insert/update final_grades
        if (isset($_POST['publish'])) {
            $qMap = [];
            $hasAllQuarters = true;
            $final_avg_total = 0;

            foreach (['1st','2nd','3rd','4th'] as $q) {
                $qStmt = $conn->prepare("SELECT average FROM quarter_grades 
                    WHERE student_id = ? AND subject_id = ? AND advisory_id = ? AND school_year_id = ? AND quarter = ?");
                $qStmt->bind_param("iiiis", $student_id, $subject_id, $advisory_id, $school_year_id, $q);
                $qStmt->execute();
                $res = $qStmt->get_result()->fetch_assoc();
                $qMap[$q] = isset($res['average']) ? (float)$res['average'] : null;

                if (!isset($res['average'])) {
                    $hasAllQuarters = false;
                } else {
                    $final_avg_total += $res['average'];
                }
            }

            $q1 = $qMap['1st'] ?? null;
            $q2 = $qMap['2nd'] ?? null;
            $q3 = $qMap['3rd'] ?? null;
            $q4 = $qMap['4th'] ?? null;

            if ($hasAllQuarters) {
                $final_avg_clean = round($final_avg_total / 4, 2);
                $final_remarks = ($final_avg_clean >= 75 ? 'Passed' : 'Failed');
            } else {
                $final_avg_clean = null;
                $final_remarks = 'INC';
            }

            $stmt = $conn->prepare("INSERT INTO final_grades 
                (student_id, subject_id, advisory_id, school_year_id, q1, q2, q3, q4, final_average, remarks)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    q1 = VALUES(q1), q2 = VALUES(q2), q3 = VALUES(q3), q4 = VALUES(q4),
                    final_average = VALUES(final_average), remarks = VALUES(remarks)");
            $stmt->bind_param("iiiiiiidds", $student_id, $subject_id, $advisory_id, $school_year_id, $q1, $q2, $q3, $q4, $final_avg_clean, $final_remarks);
            $stmt->execute();
        }
    }

    $param = isset($_POST['publish']) ? "published=1" : "success=1";
    header("Location: ../user/teacher/grading_sheet.php?quarter=" . urlencode($quarter) . "&$param");
    exit();
}
?>
