<?php
// api/save_seating.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['teacher_id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Forbidden']);
    exit;
}

$sy = intval($_SESSION['school_year_id'] ?? 0);
$ad = intval($_SESSION['advisory_id'] ?? 0);
$sj = intval($_SESSION['subject_id'] ?? 0);

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
$items = $payload['seating'] ?? [];
$color = $payload['chair_color'] ?? 'classic';
$shape = $payload['chair_shape'] ?? 'classic';

include __DIR__ . '/../config/db.php';
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'DB error']);
    exit;
}

$conn->begin_transaction();

try {
    // 1️⃣ Clear existing seating for this class
    $del = $conn->prepare("
        DELETE FROM seating_plan
        WHERE school_year_id=? AND advisory_id=? AND subject_id=?
    ");
    $del->bind_param("iii", $sy, $ad, $sj);
    $del->execute();
    $del->close();

    // 2️⃣ Insert all seats (even empty), with x,y
    $ins = $conn->prepare("
        INSERT INTO seating_plan
            (school_year_id, advisory_id, subject_id, seat_no, student_id, x, y)
        VALUES (?,?,?,?,?,?,?)
    ");

    foreach ($items as $row) {
        $seat = intval($row['seat_no']);
        $sid  = isset($row['student_id']) ? ($row['student_id'] === null ? null : intval($row['student_id'])) : null;
        $x    = isset($row['x']) ? floatval($row['x']) : null;
        $y    = isset($row['y']) ? floatval($row['y']) : null;

        $ins->bind_param("iiiiiii", $sy, $ad, $sj, $seat, $sid, $x, $y);
        $ins->execute();
    }
    $ins->close();

    // 3️⃣ Save seating theme (chair color and shape)
    $upd = $conn->prepare("
        INSERT INTO seating_style (school_year_id, advisory_id, subject_id, chair_color, chair_shape)
        VALUES (?,?,?,?,?)
        ON DUPLICATE KEY UPDATE chair_color=VALUES(chair_color), chair_shape=VALUES(chair_shape)
    ");
    $upd->bind_param("iiiss", $sy, $ad, $sj, $color, $shape);
    $upd->execute();
    $upd->close();

    $conn->commit();
    echo json_encode(['ok' => true]);

} catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Save failed', 'error' => $e->getMessage()]);
}

$conn->close();
