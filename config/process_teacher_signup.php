<?php
// cms/config/process_teacher_signup.php
session_start();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/db.php'; // loads /public_html/config/db.php reliably


// Require POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $_SESSION['error'] = 'Invalid request.';
    header('Location: ../index.php');
    exit;
}

// Collect inputs
$fullname = trim($_POST['fullname']        ?? '');
$username = trim($_POST['username']        ?? '');
$mobile   = trim($_POST['mobile_number']   ?? ''); // required (per your request)
$password =        $_POST['password']      ?? '';
$confirm  =        $_POST['confirm_password'] ?? '';

// Server-side validation
if ($fullname === '' || $username === '' || $mobile === '' || $password === '' || $confirm === '') {
    $_SESSION['error'] = 'All fields are required.';
    header('Location: ../index.php');
    exit;
}
if (!preg_match('/^\+?\d{10,13}$/', $mobile)) {
    $_SESSION['error'] = 'Invalid mobile number (use 10–13 digits).';
    header('Location: ../index.php');
    exit;
}
if ($password !== $confirm) {
    $_SESSION['error'] = 'Passwords do not match.';
    header('Location: ../index.php');
    exit;
}
if (strlen($password) < 6) {
    $_SESSION['error'] = 'Password must be at least 6 characters.';
    header('Location: ../index.php');
    exit;
}

// DB ops
try {
    $conn->set_charset('utf8mb4');

    // Unique username check
    $chk = $conn->prepare('SELECT teacher_id FROM teachers WHERE username = ? LIMIT 1');
    $chk->bind_param('s', $username);
    $chk->execute();
    if ($chk->get_result()->num_rows > 0) {
        $_SESSION['error'] = 'Username is already taken.';
        header('Location: ../index.php');
        exit;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    // Detect if teachers.mobile_number exists
    $colQ = $conn->prepare("
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name   = 'teachers'
          AND column_name  = 'mobile_number'
        LIMIT 1
    ");
    $colQ->execute();
    $hasMobileColumn = (bool)$colQ->get_result()->fetch_row();

    if ($hasMobileColumn) {
        // Insert with mobile_number column
        $ins = $conn->prepare('
            INSERT INTO teachers (username, password, fullname, mobile_number)
            VALUES (?, ?, ?, ?)
        ');
        $ins->bind_param('ssss', $username, $hash, $fullname, $mobile);
    } else {
        // Fallback: insert without mobile_number (works with your current schema)
        // NOTE: Mobile is validated but not stored unless you add the column.
        $ins = $conn->prepare('
            INSERT INTO teachers (username, password, fullname)
            VALUES (?, ?, ?)
        ');
        $ins->bind_param('sss', $username, $hash, $fullname);
    }

    $ins->execute();

    $_SESSION['success'] = 'Teacher account created. You can now log in.';
    header('Location: ../index.php');
    exit;

} catch (Throwable $e) {
    // Optionally log: error_log($e->getMessage());
    $_SESSION['error'] = 'Signup failed. Please try again.';
    header('Location: ../index.php');
    exit;
}
