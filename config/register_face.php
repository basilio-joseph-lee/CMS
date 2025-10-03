<?php
$data = json_decode(file_get_contents("php://input"), true);
$name = preg_replace('/[^a-zA-Z0-9_\-]/', '', $data['name']);
$imageData = $data['image'] ?? null;

if (!$name || !$imageData) {
    echo json_encode(['success' => false, 'error' => 'Missing data']);
    exit;
}

// Decode base64 string
$imageData = str_replace('data:image/jpeg;base64,', '', $imageData);
$imageBlob = base64_decode($imageData);

// Connect to MySQL
include("db.php"); // Replace DB name
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'DB connection failed']);
    exit;
}

// Save image to DB
$stmt = $conn->prepare("INSERT INTO faces (name, image) VALUES (?, ?)");
$stmt->bind_param("sb", $name, $null);
$stmt->send_long_data(1, $imageBlob);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => $stmt->error]);
}

$stmt->close();
$conn->close();
