<?php
session_start();

// DEBUG: show last lines of import log (temporary — remove when done)
$logFile = __DIR__ . '/logs/import_errors.log';
if (file_exists($logFile) && is_readable($logFile)) {
    $lines = array_slice(file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -40);
    if (!empty($lines)) {
        echo '<div style="max-width:1000px;margin:10px auto;padding:10px;background:#111;color:#fff;border-radius:6px;font-family:monospace;"><strong>DEBUG: import_errors.log (last lines)</strong><pre style="white-space:pre-wrap;">' . htmlspecialchars(implode("\n", $lines)) . '</pre></div>';
    }
}

include '../../config/teacher_guard.php';
include "../../config/db.php";


if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

/**
 * NEW: Helper function to build relative upload path
 */
function build_student_upload_path($student_id, $ext = 'jpg') {
    $dir = 'uploads/students';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $filename = $student_id . '_' . time() . '.' . $ext;
    return $dir . '/' . $filename;
}

/**
 * Handle edit (existing)
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_student'])) {
  $id = $_POST['student_id'];
  $name = $_POST['fullname'];
  $gender = $_POST['gender'];
  $stmt = $conn->prepare("UPDATE students SET fullname = ?, gender = ? WHERE student_id = ?");
  $stmt->bind_param("ssi", $name, $gender, $id);
  $stmt->execute();
  $_SESSION['toast'] = "Student updated successfully!";
  $_SESSION['toast_type'] = "success";
  header("Location: view_students.php");
  exit;
}

/**
 * Handle delete (existing)
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_student'])) {
  $id = $_POST['student_id'];
  $stmt = $conn->prepare("DELETE FROM students WHERE student_id = ?");
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $_SESSION['toast'] = "Student deleted successfully!";
  $_SESSION['toast_type'] = "error";
  header("Location: view_students.php");
  exit;
}

/**
 * NEW: Handle import from another subject (POST to same file)
 * Expects: import_subject_id (source subject), optional flag skip_existing
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_students'])) {
    $source_subject_id = intval($_POST['source_subject_id'] ?? 0);
    $target_subject_id = intval($_SESSION['subject_id']); // current subject
    $advisory_id = intval($_SESSION['advisory_id']);
    $school_year_id = intval($_SESSION['school_year_id']);
    $teacher_id = intval($_SESSION['teacher_id']);

    // prepare a small logger
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    $logFile = $logDir . '/import_errors.log';

    // Basic permissions check: ensure teacher owns source subject (adjust query to your schema if needed)
    $checkStmt = $conn->prepare("SELECT 1 FROM subjects WHERE subject_id = ? AND teacher_id = ? LIMIT 1"); // ADJUST IF NEEDED
    if (!$checkStmt) {
        file_put_contents($logFile, date('c') . " - prepare(checkStmt) failed: " . $conn->error . PHP_EOL, FILE_APPEND);
        $_SESSION['toast'] = "Import failed: internal error (see logs).";
        $_SESSION['toast_type'] = "error";
        header("Location: view_students.php");
        exit;
    }
    $checkStmt->bind_param("ii", $source_subject_id, $teacher_id);
    if (!$checkStmt->execute()) {
        file_put_contents($logFile, date('c') . " - execute(checkStmt) failed: " . $checkStmt->error . PHP_EOL, FILE_APPEND);
        $_SESSION['toast'] = "Import failed: internal error (see logs).";
        $_SESSION['toast_type'] = "error";
        header("Location: view_students.php");
        exit;
    }
    // buffer permission check result
    $checkStmt->store_result();
    if ($checkStmt->num_rows === 0) {
        $checkStmt->free_result();
        $checkStmt->close();
        $_SESSION['toast'] = "Import failed: you do not have permission to import from that subject.";
        $_SESSION['toast_type'] = "error";
        header("Location: view_students.php");
        exit;
    }
    $checkStmt->free_result();
    $checkStmt->close();

    // Begin transaction
    $conn->begin_transaction();
    // initialize statement vars for cleanup in catch
    $fetchSrc = $checkEnroll = $insertEnroll = null;
    try {
        // Fetch students in source subject using bind_result (avoid get_result)
        $insertEnroll = $conn->prepare("INSERT INTO student_enrollments (student_id, subject_id, advisory_id, school_year_id) VALUES (?, ?, ?, ?)");

        if (!$fetchSrc) {
            throw new Exception("prepare(fetchSrc) failed: " . $conn->error);
        }
        $fetchSrc->bind_param("iii", $source_subject_id, $advisory_id, $school_year_id);
        if (!$fetchSrc->execute()) {
            throw new Exception("execute(fetchSrc) failed: " . $fetchSrc->error);
        }

        // IMPORTANT: buffer the resultset so we can run other queries safely
        $fetchSrc->store_result();
        $fetchSrc->bind_result($f_student_id);

        $checkEnroll = $conn->prepare("SELECT 1 FROM student_enrollments WHERE student_id = ? AND subject_id = ? AND advisory_id = ? AND school_year_id = ? LIMIT 1");
        if (!$checkEnroll) throw new Exception("prepare(checkEnroll) failed: " . $conn->error);

        $insertEnroll = $conn->prepare("INSERT INTO student_enrollments (student_id, subject_id, advisory_id, school_year_id) VALUES (?, ?, ?, ?)");
        if (!$insertEnroll) throw new Exception("prepare(insertEnroll) failed: " . $conn->error);

        $imported = 0; $skipped = 0;

        while ($fetchSrc->fetch()) {
            $student_id = intval($f_student_id);

            // check existing using store_result() to get num_rows
            $checkEnroll->bind_param("iiii", $student_id, $target_subject_id, $advisory_id, $school_year_id);
            if (!$checkEnroll->execute()) {
                throw new Exception("execute(checkEnroll) failed for student {$student_id}: " . $checkEnroll->error);
            }
            $checkEnroll->store_result();
            if ($checkEnroll->num_rows > 0) {
                $skipped++;
                $checkEnroll->free_result();
                continue;
            }
            $checkEnroll->free_result();

            // insert enrollment
            $insertEnroll->bind_param("iiii", $student_id, $target_subject_id, $advisory_id, $school_year_id);
            if (!$insertEnroll->execute()) {
                throw new Exception("execute(insertEnroll) failed for student {$student_id}: " . $insertEnroll->error);
            }
            $imported++;
        }

        // free fetch results and close statements before commit
        $fetchSrc->free_result();
        $fetchSrc->close();
        $checkEnroll->close();
        $insertEnroll->close();

        $conn->commit();
        $_SESSION['toast'] = "Imported {$imported} students. Skipped {$skipped}.";
        $_SESSION['toast_type'] = "success";
        header("Location: view_students.php");
        exit;
    } catch (Exception $e) {
        // free/close any open statements before rollback to avoid "commands out of sync"
        if ($fetchSrc !== null) {
            // these calls may throw if statement already closed — suppress warnings
            @ $fetchSrc->free_result();
            @ $fetchSrc->close();
        }
        if ($checkEnroll !== null) {
            @ $checkEnroll->free_result();
            @ $checkEnroll->close();
        }
        if ($insertEnroll !== null) {
            @ $insertEnroll->close();
        }

        // rollback
        $conn->rollback();
        // write actual DB/exception details to log (safe: not shown to user)
        file_put_contents($logFile, date('c') . " - Import exception: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
        $_SESSION['toast'] = "Import failed: DB error (see server logs).";
        $_SESSION['toast_type'] = "error";
        header("Location: view_students.php");
        exit;
    }
}

/**
 * NEW: Handle retake image (POST with file upload)
 * Expects: retake_student_id and file input 'retake_image_file'
 * This supports both AJAX (fetch) and normal form submission.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['retake_image'])) {
    $student_id = intval($_POST['retake_student_id'] ?? 0);
    $teacher_id = intval($_SESSION['teacher_id']);
    // Permission check: ensure teacher can modify this student (simple check: does the teacher teach target subject?)
    // We check teacher ownership of current session subject (teacher should have access to this student's subject)
    $subject_id = intval($_SESSION['subject_id']);
    $checkPerm = $conn->prepare("SELECT 1 FROM subjects WHERE subject_id = ? AND teacher_id = ? LIMIT 1"); // ADJUST IF NEEDED
    $checkPerm->bind_param("ii", $subject_id, $teacher_id);
    $checkPerm->execute();
    $permRes = $checkPerm->get_result();
    if ($permRes->num_rows === 0) {
        // If AJAX expected JSON
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Permission denied.']);
            exit;
        } else {
            $_SESSION['toast'] = "Permission denied for retake.";
            $_SESSION['toast_type'] = "error";
            header("Location: view_students.php");
            exit;
        }
    }

    // Validate file
    if (!isset($_FILES['retake_image_file']) || $_FILES['retake_image_file']['error'] !== UPLOAD_ERR_OK) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'No image uploaded.']);
            exit;
        } else {
            $_SESSION['toast'] = "No image uploaded.";
            $_SESSION['toast_type'] = "error";
            header("Location: view_students.php");
            exit;
        }
    }

    $file = $_FILES['retake_image_file'];
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
    if (!array_key_exists($file['type'], $allowed)) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Invalid image type (jpeg/png only).']);
        exit;
    }
    $ext = $allowed[$file['type']];
    $destRel = build_student_upload_path($student_id, $ext);
    $destAbs = __DIR__ . '/' . $destRel;

    if (!move_uploaded_file($file['tmp_name'], $destAbs)) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Failed to save uploaded file.']);
            exit;
        } else {
            $_SESSION['toast'] = "Failed to save uploaded image.";
            $_SESSION['toast_type'] = "error";
            header("Location: view_students.php");
            exit;
        }
    }

    // Optionally create backup of previous image
    $stmtPrev = $conn->prepare("SELECT avatar_path FROM students WHERE student_id = ? LIMIT 1");
    $stmtPrev->bind_param("i", $student_id);
    $stmtPrev->execute();
    $prevR = $stmtPrev->get_result();
    if ($rowPrev = $prevR->fetch_assoc()) {
        $prevPath = $rowPrev['avatar_path'];
        if (!empty($prevPath) && file_exists(__DIR__ . '/' . $prevPath)) {
            $backupDir = 'uploads/students/backup';
            if (!is_dir($backupDir)) @mkdir($backupDir, 0755, true);
            $backupName = $backupDir . '/' . $student_id . '_' . time() . '.' . pathinfo($prevPath, PATHINFO_EXTENSION);
            @copy(__DIR__ . '/' . $prevPath, __DIR__ . '/' . $backupName);
        }
    }

    // Update DB
    $update = $conn->prepare("UPDATE students SET avatar_path = ? WHERE student_id = ?");
    $update->bind_param("si", $destRel, $student_id);
    if ($update->execute()) {
        // If AJAX -> return JSON with new image URL
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            // Build URL relative to site root. We'll return a relative path; frontend will use it.
            echo json_encode(['status' => 'success', 'message' => 'Image updated', 'image' => $destRel]);
            exit;
        } else {
            $_SESSION['toast'] = "Image updated successfully.";
            $_SESSION['toast_type'] = "success";
            header("Location: view_students.php");
            exit;
        }
    } else {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'DB update failed.']);
            exit;
        } else {
            $_SESSION['toast'] = "DB update failed when saving image.";
            $_SESSION['toast_type'] = "error";
            header("Location: view_students.php");
            exit;
        }
    }
}

/**
 * Fetch variables (existing)
 */
$subject_id = $_SESSION['subject_id'];
$advisory_id = $_SESSION['advisory_id'];
$school_year_id = $_SESSION['school_year_id'];
$subject_name = $_SESSION['subject_name'];
$class_name = $_SESSION['class_name'];
$year_label = $_SESSION['year_label'];
$teacherName = $_SESSION['teacher_fullname'] ?? 'Teacher';

/**
 * Fetch students for current subject (existing)
 */
$stmt = $conn->prepare("SELECT s.student_id, s.fullname, s.gender, s.avatar_path 
                        FROM students s
                        JOIN student_enrollments e ON s.student_id = e.student_id
                        WHERE e.subject_id = ? AND e.advisory_id = ? AND e.school_year_id = ?");
$stmt->bind_param("iii", $subject_id, $advisory_id, $school_year_id);
$stmt->execute();
$result = $stmt->get_result();
$students = $result->fetch_all(MYSQLI_ASSOC);

/**
 * NEW: Fetch teacher's other subjects to populate import dropdown (ADJUST IF NEEDED)
 * We exclude current subject.
 */
$teacher_id = intval($_SESSION['teacher_id']);
$subjects_for_import = [];
$subStmt = $conn->prepare("SELECT subject_id, subject_name FROM subjects WHERE teacher_id = ? AND subject_id != ?");
$subStmt->bind_param("ii", $teacher_id, $subject_id);
$subStmt->execute();
$sr = $subStmt->get_result();
while ($r = $sr->fetch_assoc()) {
    $subjects_for_import[] = $r;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>View Students</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body {
      background-image: url('../../img/role.png');
      background-size: cover;
      background-position: center;
      font-family: 'Comic Sans MS', cursive, sans-serif;
      
    }
    
  </style>
</head>
<body class="px-6 py-8">

<?php if (isset($_SESSION['toast'])): ?>
  <div id="toast" class="fixed bottom-6 left-1/2 transform -translate-x-1/2 z-50 px-6 py-3 rounded-lg shadow-lg transition-opacity duration-500
  <?= $_SESSION['toast_type'] === 'success' ? 'bg-green-500' : 'bg-red-500' ?> text-white font-semibold text-center">
  <?= $_SESSION['toast'] ?>
</div>

  <script>
    setTimeout(() => {
      const toast = document.getElementById('toast');
      toast.style.opacity = '0';
      setTimeout(() => toast.remove(), 500);
    }, 3000);
  </script>
  <?php unset($_SESSION['toast'], $_SESSION['toast_type']); ?>
<?php endif; ?>

<div class="flex justify-between items-center bg-green-800 text-white px-6 py-4 rounded-xl shadow-lg mb-6">
  <div>
    <h1 class="text-xl font-bold">👨‍🏫 <?= $teacherName ?></h1>
    <p class="text-sm">
      Subject: <?= $subject_name ?> |
      Section: <?= $class_name ?> |
      SY: <?= $year_label ?>
    </p>
  </div>
  <div class="flex items-center gap-3">
    <!-- Import button -->
    <button onclick="openImportModal()" class="bg-blue-600 hover:bg-blue-700 px-4 py-2 rounded-lg font-semibold shadow text-white">⬇️ Import</button>
    <form action="teacher_dashboard.php" method="post">
      <input type="hidden" name="subject_id" value="<?= $subject_id ?>">
      <input type="hidden" name="advisory_id" value="<?= $advisory_id ?>">
      <input type="hidden" name="school_year_id" value="<?= $school_year_id ?>">
      <input type="hidden" name="subject_name" value="<?= $subject_name ?>">
      <input type="hidden" name="class_name" value="<?= $class_name ?>">
      <input type="hidden" name="year_label" value="<?= $year_label ?>">
      <button type="submit" class="bg-orange-400 hover:bg-orange-500 px-4 py-2 rounded-lg font-semibold shadow text-white">← Back</button>
    </form>
  </div>
</div>

<div class="mb-4">
  <input type="text" id="searchInput" placeholder="Search student..." class="px-4 py-2 border rounded-lg w-full">
</div>

<h2 class="text-2xl font-bold text-gray-800 mb-4">View Students</h2>
<div class="overflow-x-auto bg-white p-4 rounded-xl shadow-lg">
  <table id="studentsTable" class="min-w-full text-sm text-left border border-gray-300">
    <thead class="bg-yellow-300 text-gray-800 uppercase text-xs font-bold">
      <tr>
        <th class="px-4 py-3 border">#</th>
        <th class="px-4 py-3 border">Avatar</th>
        <th class="px-4 py-3 border">Full Name</th>
        <th class="px-4 py-3 border">Gender</th>
        <th class="px-4 py-3 border">Actions</th>
      </tr>
    </thead>
    <tbody class="text-gray-800 bg-white">
      <?php $i = 1; foreach ($students as $student): ?>
        <tr class="border-b">
          <td class="px-4 py-3 border"><?= $i++ ?></td>
          <td class="px-4 py-3 border">
            <?php if (!empty($student['avatar_path']) && file_exists(__DIR__ . '/../' . $student['avatar_path'])): ?>
              <img src="../<?= $student['avatar_path'] ?>" alt="Avatar" class="w-10 h-10 rounded-full object-cover">
            <?php else: ?>
              <div class="w-10 h-10 bg-yellow-300 rounded-full flex items-center justify-center text-lg">👤</div>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3 border name-cell"><?= htmlspecialchars($student['fullname']) ?></td>
          <td class="px-4 py-3 border"><?= $student['gender'] ?></td>
          <td class="px-4 py-3 border">
            <button onclick="openEditModal(<?= $student['student_id'] ?>)" class="text-blue-600 text-sm hover:underline mr-2">✏️ Edit</button>
            <button onclick="openDeleteModal(<?= $student['student_id'] ?>)" class="text-red-600 text-sm hover:underline mr-2">🗑️ Delete</button>
            <button onclick="openRetakeModal(<?= $student['student_id'] ?>, '<?= addslashes(htmlspecialchars($student['fullname'])) ?>')" class="text-green-700 text-sm hover:underline">📸 Retake</button>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Edit & Delete modals (existing) -->
<?php foreach ($students as $student): ?>
  <div id="editModal<?= $student['student_id'] ?>" class="fixed inset-0 z-50 hidden bg-black bg-opacity-50 flex justify-center items-center">
    <form method="POST" class="bg-white p-6 rounded-lg shadow-lg w-96 space-y-4">
      <input type="hidden" name="student_id" value="<?= $student['student_id'] ?>">
      <input type="hidden" name="edit_student" value="1">
      <h2 class="text-lg font-bold">Edit Student</h2>
      <label class="block text-sm font-semibold">Full Name</label>
      <input type="text" name="fullname" value="<?= htmlspecialchars($student['fullname']) ?>" class="w-full border rounded px-3 py-2">
      <label class="block text-sm font-semibold">Gender</label>
      <select name="gender" class="w-full border rounded px-3 py-2">
        <option value="Male" <?= $student['gender'] === 'Male' ? 'selected' : '' ?>>Male</option>
        <option value="Female" <?= $student['gender'] === 'Female' ? 'selected' : '' ?>>Female</option>
      </select>
      <div class="flex justify-end gap-2 pt-4">
        <button type="button" onclick="closeModal('editModal<?= $student['student_id'] ?>')" class="text-gray-600">Cancel</button>
        <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded">Update</button>
      </div>
    </form>
  </div>

  <div id="deleteModal<?= $student['student_id'] ?>" class="fixed inset-0 z-50 hidden bg-black bg-opacity-50 flex justify-center items-center">
    <form method="POST" class="bg-white p-6 rounded-lg shadow-lg w-96 space-y-4">
      <input type="hidden" name="student_id" value="<?= $student['student_id'] ?>">
      <input type="hidden" name="delete_student" value="1">
      <h2 class="text-lg font-bold text-gray-800">Delete Student</h2>
      <p>Are you sure you want to delete <strong><?= htmlspecialchars($student['fullname']) ?></strong>?</p>
      <div class="flex justify-end gap-2">
        <button type="button" onclick="closeModal('deleteModal<?= $student['student_id'] ?>')" class="text-gray-600">Cancel</button>
        <button type="submit" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded">Delete</button>
      </div>
    </form>
  </div>
<?php endforeach; ?>

<!-- NEW: Import Modal -->
<div id="importModal" class="fixed inset-0 z-50 hidden bg-black bg-opacity-50 flex justify-center items-center">
  <form method="POST" class="bg-white p-6 rounded-lg shadow-lg w-96 space-y-4">
    <h2 class="text-lg font-bold">Import students from another subject</h2>
    <label class="block text-sm font-semibold">Source Subject</label>
    <select name="source_subject_id" class="w-full border rounded px-3 py-2" required>
      <option value="">-- Select subject --</option>
      <?php foreach ($subjects_for_import as $s): ?>
        <option value="<?= intval($s['subject_id']) ?>"><?= htmlspecialchars($s['subject_name']) ?></option>
      <?php endforeach; ?>
    </select>
    <label class="flex items-center gap-2 text-sm">
      <input type="checkbox" name="skip_existing" checked>
      Skip students already enrolled in this subject
    </label>
    <div class="flex justify-end gap-2 pt-4">
      <button type="button" onclick="closeModal('importModal')" class="text-gray-600">Cancel</button>
      <button type="submit" name="import_students" value="1" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">Import</button>
    </div>
  </form>
</div>

<!-- NEW: Retake Modal -->
<div id="retakeModal" class="fixed inset-0 z-50 hidden bg-black bg-opacity-50 flex justify-center items-center">
  <form id="retakeForm" method="POST" enctype="multipart/form-data" class="bg-white p-6 rounded-lg shadow-lg w-96 space-y-4">
    <input type="hidden" name="retake_image" value="1">
    <input type="hidden" id="retake_student_id" name="retake_student_id" value="">
    <h2 id="retakeTitle" class="text-lg font-bold">Retake Image</h2>
    <p class="text-sm text-gray-600">Use your camera or choose a file to retake the student's image.</p>
    <input type="file" name="retake_image_file" id="retake_image_file" accept="image/*" capture="environment" class="w-full">
    <div class="flex justify-end gap-2 pt-4">
      <button type="button" onclick="closeModal('retakeModal')" class="text-gray-600">Cancel</button>
      <button type="button" onclick="submitRetake()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded">Upload</button>
    </div>
  </form>
</div>

<script>
  function openEditModal(id) {
    document.getElementById('editModal' + id).classList.remove('hidden');
  }
  function openDeleteModal(id) {
    document.getElementById('deleteModal' + id).classList.remove('hidden');
  }
  function closeModal(id) {
    document.getElementById(id).classList.add('hidden');
  }

  document.getElementById('searchInput').addEventListener('input', function () {
    const filter = this.value.toLowerCase();
    const rows = document.querySelectorAll('#studentsTable tbody tr');
    rows.forEach(row => {
      const nameCell = row.querySelector('.name-cell');
      const name = nameCell.textContent.toLowerCase();
      row.style.display = name.includes(filter) ? '' : 'none';
    });
  });

  // Import modal
  function openImportModal() {
    document.getElementById('importModal').classList.remove('hidden');
  }

  // Retake modal: set student id and show
  function openRetakeModal(studentId, fullname) {
    document.getElementById('retake_student_id').value = studentId;
    document.getElementById('retakeTitle').textContent = 'Retake Image — ' + fullname;
    document.getElementById('retake_image_file').value = null;
    document.getElementById('retakeModal').classList.remove('hidden');
  }

  // Submit retake via AJAX to same page (so no redirect) to update preview live
  function submitRetake() {
    const form = document.getElementById('retakeForm');
    const fd = new FormData(form);
    const studentId = document.getElementById('retake_student_id').value;
    const fileInput = document.getElementById('retake_image_file');
    if (fileInput.files.length === 0) {
      alert('Please choose or capture an image first.');
      return;
    }

    // Show simple loading state
    const btn = event ? event.target : null;

    fetch('view_students.php', {
      method: 'POST',
      body: fd,
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      }
    }).then(r => r.json())
    .then(data => {
      if (data.status === 'success') {
        // update avatar img in table if present (relative path returned)
        const rel = data.image;
        // Find the table row with studentId and replace img or fallback element
        // We used the student id in onclick only. Let's scan table rows by button onclick attribute.
        // Simple approach: reload the page to reflect change (keeps logic simple and reliable)
        closeModal('retakeModal');
        // small toast before reload
        const t = document.createElement('div');
        t.className = 'fixed bottom-6 left-1/2 transform -translate-x-1/2 z-50 px-6 py-3 rounded-lg shadow-lg bg-green-500 text-white font-semibold text-center';
        t.textContent = 'Image updated';
        document.body.appendChild(t);
        setTimeout(() => {
          location.reload();
        }, 900);
      } else {
        alert(data.message || 'Failed to upload image.');
      }
    }).catch(err => {
      console.error(err);
      alert('Network error.');
    });
  }
</script>

</body>
</html>
