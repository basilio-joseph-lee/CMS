<?php
// config/parent_register.php
// Parent registration endpoint (works for local http://CMS and prod https://myschoolness.site)

// Always return JSON
header('Content-Type: application/json; charset=utf-8');
// Optional CORS for mobile/web clients (safe here)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
// Hide notices/warnings so JSON isn't corrupted
error_reporting(E_ERROR | E_PARSE);

function json_out(array $arr, int $http = 200) {
    http_response_code($http);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

// Enforce POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['status' => 'error', 'message' => 'Method not allowed.'], 405);
}

try {
    // ---------- DB (use environment/db.php — not hard-coded localhost) ----------
    require_once __DIR__ . '/db.php';     // must define $conn = new mysqli(...);
    $conn->set_charset('utf8mb4');

    // ---------- Inputs ----------
    $fullname = trim($_POST['fullname']       ?? '');
    $email    = trim($_POST['email']          ?? '');
    $mobile   = trim($_POST['mobile_number']  ?? '');  // matches column in `parents`
    $password =        $_POST['password']     ?? '';

    // ---------- Validation ----------
    if ($fullname === '' || $email === '' || $mobile === '' || $password === '') {
        json_out(['status' => 'error', 'message' => 'Missing required fields.'], 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_out(['status' => 'error', 'message' => 'Invalid email format.'], 400);
    }
    // 10–13 digits, optional leading +
    if (!preg_match('/^\+?\d{10,13}$/', $mobile)) {
        json_out(['status' => 'error', 'message' => 'Invalid mobile number (use 10–13 digits).'], 400);
    }
    if (strlen($password) < 6) {
        json_out(['status' => 'error', 'message' => 'Password must be at least 6 characters.'], 400);
    }

    // Optional: pre-check for duplicate email (also covered by UNIQUE constraint)
    $chk = $conn->prepare("SELECT parent_id FROM parents WHERE email = ? LIMIT 1");
    $chk->bind_param('s', $email);
    $chk->execute();
    if ($chk->get_result()->fetch_assoc()) {
        json_out(['status' => 'error', 'message' => 'Email is already registered.'], 409);
    }
    $chk->close();

    // ---------- Insert ----------
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $ins = $conn->prepare("
        INSERT INTO parents (fullname, email, password, mobile_number, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $ins->bind_param('ssss', $fullname, $email, $hash, $mobile);
    $ins->execute();

    $newId = (int)$conn->insert_id;

    json_out([
        'status'  => 'success',
        'message' => 'Registration successful.',
        'data'    => [
            'parent_id'     => $newId,
            'fullname'      => $fullname,
            'email'         => $email,
            'mobile_number' => $mobile,
        ],
    ], 200);

} catch (mysqli_sql_exception $e) {
    // Handle UNIQUE email race (errno 1062)
    if ((int)$e->getCode() === 1062) {
        json_out(['status' => 'error', 'message' => 'Email is already registered.'], 409);
    }
    // error_log($e->getMessage());
    json_out(['status' => 'error', 'message' => 'Server error (DB).'], 500);
} catch (Throwable $e) {
    // error_log($e->getMessage());
    json_out(['status' => 'error', 'message' => 'Server error. Please try again.'], 500);
}
