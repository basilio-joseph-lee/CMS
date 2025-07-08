<?php
session_start();
if (!isset($_SESSION['teacher_id'])) {
  header("Location: teacher_login.php");
  exit;
}

$teacherId = $_SESSION['teacher_id'];
$teacherName = $_SESSION['fullname'];

$conn = new mysqli("localhost", "root", "", "cms");
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

$schoolYears = [];
$advisorySections = [];
$subjects = [];

$syResult = $conn->query("SELECT school_year_id, year_label FROM school_years ORDER BY year_label DESC");
while ($row = $syResult->fetch_assoc()) {
  $schoolYears[] = $row;
}

$stmt = $conn->prepare("SELECT advisory_id, class_name FROM advisory_classes WHERE teacher_id = ?");
$stmt->bind_param("i", $teacherId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
  $advisorySections[] = $row;
}
$stmt->close();

$stmt = $conn->prepare("SELECT subject_id, subject_name FROM subjects WHERE teacher_id = ?");
$stmt->bind_param("i", $teacherId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
  $subjects[] = $row;
}
$stmt->close();
$conn->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $_SESSION['selected_school_year_id'] = $_POST['school_year_id'];
  $_SESSION['selected_advisory_id'] = $_POST['advisory_id'];
  $_SESSION['selected_subject_id'] = $_POST['subject_id'];
  header("Location: add_student.php");
  exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Select Class Context</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-[#fefae0] font-sans p-10">
  <div class="max-w-xl mx-auto bg-white p-8 rounded-xl shadow-xl">
    <h1 class="text-2xl font-bold mb-6 text-[#bc6c25]">📚 Select Class Context</h1>
    <form method="POST" class="space-y-4">
      <select name="school_year_id" required class="w-full p-3 border border-yellow-400 rounded-xl">
        <option value="">Select School Year</option>
        <?php foreach ($schoolYears as $year): ?>
          <option value="<?php echo $year['school_year_id']; ?>"><?php echo htmlspecialchars($year['year_label']); ?></option>
        <?php endforeach; ?>
      </select>
      <select name="advisory_id" required class="w-full p-3 border border-yellow-400 rounded-xl">
        <option value="">Select Section</option>
        <?php foreach ($advisorySections as $section): ?>
          <option value="<?php echo $section['advisory_id']; ?>"><?php echo htmlspecialchars($section['class_name']); ?></option>
        <?php endforeach; ?>
      </select>
      <select name="subject_id" required class="w-full p-3 border border-yellow-400 rounded-xl">
        <option value="">Select Subject</option>
        <?php foreach ($subjects as $subject): ?>
          <option value="<?php echo $subject['subject_id']; ?>"><?php echo htmlspecialchars($subject['subject_name']); ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="bg-green-500 hover:bg-green-600 text-white px-6 py-3 rounded-xl font-bold w-full">✅ Confirm and Proceed</button>
    </form>
  </div>
</body>
</html>
