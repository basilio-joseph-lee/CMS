<?php
header('Content-Type: application/json');

// Allow only POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'error' => 'Invalid request method. POST required.'
    ]);
    exit;
}

// Decode the incoming JSON
$data = json_decode(file_get_contents("php://input"), true);
$image = $data['image'] ?? null;

if (!$image) {
    echo json_encode([
        'success' => false,
        'error' => 'No image provided.'
    ]);
    exit;
}

// Python API endpoint
$apiURL = 'http://127.0.0.1:5000/verify';

// Prepare the POST request to Python Flask
$options = [
    'http' => [
        'header'  => "Content-Type: application/json\r\n",
        'method'  => 'POST',
        'content' => json_encode(['image' => $image]),
        'ignore_errors' => true // Capture even non-200 responses
    ]
];

$context = stream_context_create($options);
$response = @file_get_contents($apiURL, false, $context);
$http_response_header = $http_response_header ?? [];

if ($response === false) {
    echo json_encode([
        'success' => false,
        'error' => 'Python server unreachable or returned error.',
        'http_response' => $http_response_header
    ]);
    exit;
}

// Decode the response from the Python API
$result = json_decode($response, true);

if ($result && $result['match'] === true) {
    session_start();
    $_SESSION['user'] = $result['name'] ?? 'Guest';

    echo json_encode([
        'success' => true,
        'name' => $result['name'] ?? 'Unknown',
        'confidence' => $result['confidence'] ?? null
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => $result['error'] ?? 'No face match.'
    ]);
}