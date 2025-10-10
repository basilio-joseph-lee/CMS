<?php
// config/get_parent_profile.php
header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
error_reporting(E_ERROR | E_PARSE);

try {
    // include DB (relative path works on local/prod)
    include __DIR__ . '/db.php';
    $conn->set_charset('utf8mb4');

    // Accept parent_id from POST (preferred) or GET
    $pid = 0;
    if (isset($_POST['parent_id'])) $pid = (int)$_POST['parent_id'];
    if ($pid <= 0 && isset($_GET['parent_id'])) $pid = (int)$_GET['parent_id'];

    if ($pid <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Missing parent_id']);
        exit;
    }

    $stmt = $conn->prepare(
        "SELECT parent_id, fullname, email, mobile_number
           FROM parents
          WHERE parent_id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $pid);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        echo json_encode([
            'status'  => 'success',
            'profile' => [
                'parent_id'     => (int)$row['parent_id'],
                'fullname'      => (string)$row['fullname'],
                'email'         => (string)$row['email'],
                'mobile_number' => (string)($row['mobile_number'] ?? ''),
            ]
        ]);
        exit;
    }

    echo json_encode(['status' => 'not_found']);
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
}
