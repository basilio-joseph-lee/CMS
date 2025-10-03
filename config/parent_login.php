
<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");


include("db.php");
if ($conn->connect_error) {
  echo json_encode(["status" => "error", "message" => "Connection failed"]);
  exit;
}

// ✅ Get data from $_POST instead of json_decode
$email = $_POST['email'] ?? '';
$password = $_POST['password'] ?? '';

$stmt = $conn->prepare("SELECT parent_id, fullname, password FROM parents WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
  if (password_verify($password, $row['password'])) {
    echo json_encode([
      "status" => "success",
      "parent_id" => $row['parent_id'],
      "fullname" => $row['fullname']
    ]);
  } else {
    echo json_encode(["status" => "error", "message" => "Invalid password"]);
  }
} else {
  echo json_encode(["status" => "error", "message" => "Parent not found"]);
}



$stmt->close();
$conn->close();
