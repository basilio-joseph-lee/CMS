<?php
// cms/config/parent_register.php
header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function json_out(array $arr, int $http_code = 200) {
    http_response_code($http_code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

// Reject non-POST (mobile + web AJAX should both be POST)
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['status' => 'error', 'message' => 'Method not allowed.'], 405);
}

try {
    // Collect input (works for mobile & web forms)
    $fullname = trim($_POST['fullname']       ?? '');
    $email    = trim($_POST['email']          ?? '');
    $mobile   = trim($_POST['mobile_number']  ?? ''); // align with your DB column
    $password =        $_POST['password']     ?? '';

    // Basic validation
    if ($fullname === '' || $email === '' || $mobile === '' || $password === '') {
        json_out(['status' => 'error', 'message' => 'Missing required fields.'], 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_out(['status' => 'error', 'message' => 'Invalid email format.'], 400);
    }
    // Match your UI rule: 10–13 digits, optional leading +
    if (!preg_match('/^\+?\d{10,13}$/', $mobile)) {
        json_out(['status' => 'error', 'message' => 'Invalid mobile number (use 10–13 digits).'], 400);
    }
    if (strlen($password) < 6) {
        json_out(['status' => 'error', 'message' => 'Password must be at least 6 characters.'], 400);
    }

    // DB connection
    $db = new mysqli('localhost', 'root', '', 'cms');
    $db->set_charset('utf8mb4');

    // Duplicate email check
    $q = $db->prepare("SELECT parent_id FROM parents WHERE email = ? LIMIT 1");
    $q->bind_param('s', $email);
    $q->execute();
    if ($q->get_result()->fetch_assoc()) {
        json_out(['status' => 'error', 'message' => 'Email is already registered.'], 409);
    }

    // Hash & insert
    $hash = password_hash($password, PASSWORD_DEFAULT);

    // created_at exists with default, but explicit NOW() is fine
    $ins = $db->prepare("
        INSERT INTO parents (fullname, email, password, mobile_number, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $ins->bind_param('ssss', $fullname, $email, $hash, $mobile);
    $ins->execute();

    $newId = (int)$db->insert_id;

    json_out([
        'status'  => 'success',
        'message' => 'Registration successful.',
        'data'    => [
            'parent_id'      => $newId,
            'fullname'       => $fullname,
            'email'          => $email,
            'mobile_number'  => $mobile,
        ],
    ], 200);

} catch (Throwable $e) {
    // Optionally log: error_log($e->getMessage());
    json_out(['status' => 'error', 'message' => 'Server error. Please try again.'], 500);
}
