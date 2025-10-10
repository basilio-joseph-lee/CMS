<?php
// api/parent/get_profile.php
header('Content-Type: application/json; charset=utf-8');
session_start();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
error_reporting(E_ERROR | E_PARSE);

try {
    include __DIR__ . '/../../config/db.php';
    $conn->set_charset('utf8mb4');

    // Default: require a logged-in parent session
    $pid = isset($_SESSION['parent_id']) ? (int)$_SESSION['parent_id'] : 0;

    // Optional debug backdoor for testing without session:
    // /api/parent/get_profile.php?parent_id=1&debug=1
    if ($pid <= 0 && isset($_GET['debug']) && (int)$_GET['debug'] === 1 && isset($_GET['parent_id'])) {
        $pid = (int)$_GET['parent_id'];
    }

    if ($pid <= 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
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
            'success' => true,
            'profile' => [
                'parent_id'     => (int)$row['parent_id'],
                'fullname'      => (string)$row['fullname'],
                'email'         => (string)$row['email'],
                'mobile_number' => (string)($row['mobile_number'] ?? ''),
            ]
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Not found']);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
