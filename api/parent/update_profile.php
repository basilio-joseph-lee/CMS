<?php
// /api/parent/update_profile.php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

include("../../config/db.php");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["success" => false, "message" => "Invalid request method"]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);

if (!$input || !isset($input['parent_id'])) {
    echo json_encode(["success" => false, "message" => "Missing required data"]);
    exit;
}

$parent_id = (int)$input['parent_id'];
$fullname = trim($input['fullname'] ?? '');
$email = trim($input['email'] ?? '');
$mobile_number = trim($input['mobile_number'] ?? '');
$password = trim($input['password'] ?? '');

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db->set_charset("utf8mb4");

    // Prepare dynamic update query
    $fields = [];
    $params = [];
    $types = "";

    if (!empty($fullname)) {
        $fields[] = "fullname = ?";
        $params[] = $fullname;
        $types .= "s";
    }

    if (!empty($email)) {
        $fields[] = "email = ?";
        $params[] = $email;
        $types .= "s";
    }

    if (!empty($mobile_number)) {
        $fields[] = "mobile_number = ?";
        $params[] = $mobile_number;
        $types .= "s";
    }

    if (!empty($password)) {
        $fields[] = "password = ?";
        $params[] = password_hash($password, PASSWORD_DEFAULT);
        $types .= "s";
    }

    if (empty($fields)) {
        echo json_encode(["success" => false, "message" => "No fields to update"]);
        exit;
    }

    $query = "UPDATE parents SET " . implode(", ", $fields) . " WHERE parent_id = ?";
    $params[] = $parent_id;
    $types .= "i";

    $stmt = $db->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        echo json_encode(["success" => true, "message" => "Profile updated successfully"]);
    } else {
        echo json_encode(["success" => true, "message" => "No changes made"]);
    }

    $stmt->close();
    $db->close();

} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
}
?>
