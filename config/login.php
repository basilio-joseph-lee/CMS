<?php
session_start();

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    exit;
}

// Decode JSON input
$data = json_decode(file_get_contents("php://input"), true);
$image = $data['image'] ?? null;

if (!$image) {
    echo json_encode(['success' => false, 'error' => 'Missing image data.']);
    exit;
}

// Call the Python face verification API
$apiURL = 'http://127.0.0.1:5000/verify';
$options = [
    'http' => [
        'header'  => "Content-Type: application/json\r\n",
        'method'  => 'POST',
        'content' => json_encode(['image' => $image]),
        'ignore_errors' => true
    ]
];

$context = stream_context_create($options);
$response = @file_get_contents($apiURL, false, $context);
$result = json_decode($response, true);

if (!$result || !$result['match']) {
    echo json_encode(['success' => false, 'error' => $result['error'] ?? 'Face not recognized.']);
    exit;
}

$matchedName = $result['name'];

// Connect to database
include("db.php");
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed.']);
    exit;
}

// Look for student based on name match and get subject, advisory class, and school year
$stmt = $conn->prepare("
    SELECT s.student_id, s.fullname, se.subject_id, se.advisory_id, se.school_year_id,
           ac.class_name, sy.year_label, sub.subject_name
    FROM students s
    JOIN student_enrollments se ON s.student_id = se.student_id
    JOIN advisory_classes ac ON se.advisory_id = ac.advisory_id
    JOIN school_years sy ON se.school_year_id = sy.school_year_id
    JOIN subjects sub ON se.subject_id = sub.subject_id
    WHERE s.fullname = ?
    LIMIT 1
");
$stmt->bind_param("s", $matchedName);
$stmt->execute();
$resultSQL = $stmt->get_result();

if ($resultSQL->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Student enrollment not found.']);
    exit;
}

$row = $resultSQL->fetch_assoc();

// ✅ Set session variables
$_SESSION['user'] = $row['fullname'];
$_SESSION['student_id'] = $row['student_id'];
$_SESSION['subject_id'] = $row['subject_id'];
$_SESSION['subject_name'] = $row['subject_name'];
$_SESSION['advisory_id'] = $row['advisory_id'];
$_SESSION['class_name'] = $row['class_name'];
$_SESSION['school_year_id'] = $row['school_year_id'];
$_SESSION['year_label'] = $row['year_label'];

// Respond with success
echo json_encode([
    'success' => true,
    'name' => $row['fullname'],
    'confidence' => $result['confidence'] ?? null
]);

$conn->close();
?>
