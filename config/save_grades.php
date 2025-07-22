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

    // Save weight percentages
    $stmt = $conn->prepare("REPLACE INTO grade_weights 
        (subject_id, advisory_id, quarter, school_year_id, quiz_weight, activity_weight, performance_weight, exam_weight) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iisiiiii", $subject_id, $advisory_id, $quarter, $school_year_id,
        $weights['quiz'], $weights['activity'], $weights['performance'], $weights['exam']);
    $stmt->execute();

    // Save student scores and max_scores
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

    // Recalculate and save averages
    $stmt = $conn->prepare("SELECT student_id FROM student_enrollments 
                            WHERE subject_id = ? AND advisory_id = ? AND school_year_id = ?");
    $stmt->bind_param("iii", $subject_id, $advisory_id, $school_year_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $student_id = $row['student_id'];
        $component_totals = ["quiz" => [], "activity" => [], "performance" => [], "exam" => []];

        $stmt2 = $conn->prepare("SELECT component_type, score, max_score FROM grade_components 
                                 WHERE student_id = ? AND subject_id = ? AND advisory_id = ? AND school_year_id = ? AND quarter = ?");
        $stmt2->bind_param("iiiis", $student_id, $subject_id, $advisory_id, $school_year_id, $quarter);
        $stmt2->execute();
        $grades = $stmt2->get_result();

        while ($g = $grades->fetch_assoc()) {
            if ($g['max_score'] > 0) {
                $percentage = ($g['score'] / $g['max_score']) * 100;
                $component_totals[$g['component_type']][] = $percentage;
            }
        }

        $total = 0;
        $totalWeight = 0;

        foreach ($component_totals as $type => $percentages) {
            if (count($percentages) > 0 && $weights[$type] > 0) {
                $avg = array_sum($percentages) / count($percentages);
                $total += $avg * ($weights[$type] / 100);
                $totalWeight += $weights[$type];
            }
        }

        if ($totalWeight > 0) {
            $final_avg = round($total * (100 / $totalWeight), 2);
            $remarks = $final_avg >= 75 ? 'Passed' : 'Failed';
        } else {
            $final_avg = 0;
            $remarks = 'INC';
        }

        $stmt3 = $conn->prepare("REPLACE INTO quarter_grades 
            (student_id, subject_id, advisory_id, school_year_id, quarter, average, remarks) 
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt3->bind_param("iiiisds", $student_id, $subject_id, $advisory_id, $school_year_id,
            $quarter, $final_avg, $remarks);
        $stmt3->execute();
    }

    header("Location: ../user/teacher/grading_sheet.php?quarter=" . urlencode($quarter));
    exit();
}
?>
