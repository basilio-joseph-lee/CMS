<?php
header('Content-Type: application/json');
session_start();

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    exit;
}

// Get and decode input
$data = json_decode(file_get_contents("php://input"), true);
$image = $data['image'] ?? null;
$subject_id = $data['subject_id'] ?? null;
$advisory_id = $data['advisory_id'] ?? null;
$school_year_id = $data['school_year_id'] ?? null;

if (!$image || !$subject_id || !$advisory_id || !$school_year_id) {
    echo json_encode(['success' => false, 'error' => 'Missing required data.']);
    exit;
}

// Call Python API for face verification
$apiURL = 'http://127.0.0.1:5000/verify';
$options = [
    'http' => [
        'header'  => "Content-Type: application/json\r\n",
        'method'  => 'POST',
        'content' => json_encode([
            'image' => $image,
            'subject_id' => $subject_id,
            'advisory_id' => $advisory_id,
            'school_year_id' => $school_year_id
        ]),
        'ignore_errors' => true
    ]
];

$context = stream_context_create($options);
$response = @file_get_contents($apiURL, false, $context);
$result = json_decode($response, true);

// If no match or error
if (!$result || !$result['match']) {
    echo json_encode(['success' => false, 'error' => $result['error'] ?? 'Face not recognized.']);
    exit;
}

// Match found — fetch student info from database
$name = $result['name'];
$conn = new mysqli("localhost", "root", "", "cms");
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'DB connection failed.']);
    exit;
}

$stmt = $conn->prepare("
    SELECT s.student_id, s.fullname, c.class_name, sy.year_label, sub.subject_name
    FROM students s
    JOIN student_enrollments e ON s.student_id = e.student_id
    JOIN advisory_classes c ON e.advisory_id = c.advisory_id
    JOIN school_years sy ON e.school_year_id = sy.school_year_id
    JOIN subjects sub ON e.subject_id = sub.subject_id
    WHERE s.fullname = ? AND e.subject_id = ? AND e.advisory_id = ? AND e.school_year_id = ?
    LIMIT 1
");
$stmt->bind_param("siii", $name, $subject_id, $advisory_id, $school_year_id);
$stmt->execute();
$resultSQL = $stmt->get_result();

if ($resultSQL->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Student not found in enrollment.']);
    exit;
}

$row = $resultSQL->fetch_assoc();

// ✅ Set session variables
$_SESSION['user'] = $row['fullname'];
$_SESSION['student_id'] = $row['student_id'];

$_SESSION['active_subject_id'] = $subject_id;
$_SESSION['active_subject_name'] = $row['subject_name'];
$_SESSION['active_advisory_id'] = $advisory_id;
$_SESSION['active_class_name'] = $row['class_name'];
$_SESSION['active_school_year_id'] = $school_year_id;
$_SESSION['active_year_label'] = $row['year_label'];

echo json_encode([
    'success' => true,
    'name' => $row['fullname'],
    'confidence' => $result['confidence'] ?? null
]);

$conn->close();
?>
