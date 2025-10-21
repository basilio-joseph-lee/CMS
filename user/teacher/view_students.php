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
 * Helper: build upload path for student files (relative to project root from this file)
 */
function build_student_upload_path($student_id, $prefix = 'face', $ext = 'jpg') {
    $dir = 'uploads/students';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $filename = $prefix . '_' . $student_id . '_' . time() . '.' . $ext;
    return $dir . '/' . $filename;
}

/**
 * Helper: robust file exists that tolerates leading slashes in DB values
 */
function resolve_relative_to_project($relPath) {
    if (!$relPath) return false;
    // normalize
    $rel = ltrim($relPath, '/');
    $absCandidate = __DIR__ . '/../' . $rel;
    if (file_exists($absCandidate)) return $absCandidate;
    // also check document root absolute path variant
    if (isset($_SERVER['DOCUMENT_ROOT'])) {
        $abs2 = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/' . $rel;
        if (file_exists($abs2)) return $abs2;
    }
    // finally check as given (maybe already relative to current dir)
    $abs3 = __DIR__ . '/' . $relPath;
    if (file_exists($abs3)) return $abs3;
    return false;
}

/**
 * AJAX endpoint: fetch advisories for a given subject (same school year)
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'fetch_advisories') {
    header('Content-Type: application/json; charset=utf-8');
    $src_subject = intval($_GET['subject_id'] ?? 0);
    $school_year_id = intval($_SESSION['school_year_id'] ?? 0);

    if ($src_subject <= 0 || $school_year_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid parameters.']);
        exit;
    }

    $qry = $conn->prepare("
        SELECT DISTINCT e.advisory_id, ac.class_name
        FROM student_enrollments e
        LEFT JOIN advisory_classes ac ON ac.advisory_id = e.advisory_id
        WHERE e.subject_id = ? AND e.school_year_id = ?
        ORDER BY ac.class_name ASC
    ");
    if (!$qry) {
        echo json_encode(['status' => 'error', 'message' => 'DB prepare failed.']);
        exit;
    }
    $qry->bind_param("ii", $src_subject, $school_year_id);
    if (!$qry->execute()) {
        echo json_encode(['status' => 'error', 'message' => 'DB execute failed.']);
        exit;
    }
    $res = $qry->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) {
        $name = $r['class_name'] ?? ('Advisory ' . $r['advisory_id']);
        $out[] = ['advisory_id' => intval($r['advisory_id']), 'class_name' => $name];
    }
    echo json_encode(['status' => 'ok', 'advisories' => $out]);
    exit;
}

/**
 * Handle basic edit
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_student'])) {
  $id = intval($_POST['student_id']);
  $name = $_POST['fullname'] ?? '';
  $gender = $_POST['gender'] ?? '';
  $stmt = $conn->prepare("UPDATE students SET fullname = ?, gender = ? WHERE student_id = ?");
  $stmt->bind_param("ssi", $name, $gender, $id);
  $stmt->execute();
  $_SESSION['toast'] = "Student updated successfully!";
  $_SESSION['toast_type'] = "success";
  header("Location: view_students.php");
  exit;
}

/**
 * Handle delete
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_student'])) {
  $id = intval($_POST['student_id']);
  $stmt = $conn->prepare("DELETE FROM students WHERE student_id = ?");
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $_SESSION['toast'] = "Student deleted successfully!";
  $_SESSION['toast_type'] = "error";
  header("Location: view_students.php");
  exit;
}

/**
 * Import handling (kept same as previous, requiring source_subject_id + source_advisory_id)
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_students'])) {
    $source_subject_id = intval($_POST['source_subject_id'] ?? 0);
    $source_advisory_id = intval($_POST['source_advisory_id'] ?? 0);
    $skip_existing = isset($_POST['skip_existing']) ? true : false;

    $target_subject_id = intval($_SESSION['subject_id']); // current subject
    $advisory_id = intval($_SESSION['advisory_id']);
    $school_year_id = intval($_SESSION['school_year_id']);
    $teacher_id = intval($_SESSION['teacher_id']);

    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    $logFile = $logDir . '/import_errors.log';

    if ($source_subject_id <= 0 || $source_advisory_id <= 0) {
        file_put_contents($logFile, date('c') . " - Import failed: invalid source subject/advisory (s:{$source_subject_id}, a:{$source_advisory_id})." . PHP_EOL, FILE_APPEND);
        $_SESSION['toast'] = "Import failed: invalid source selection.";
        $_SESSION['toast_type'] = "error";
        header("Location: view_students.php");
        exit;
    }

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

    $conn->begin_transaction();
    $fetchSrc = $checkEnroll = $insertEnroll = null;
    try {
        $fetchSrc = $conn->prepare("
            SELECT s.student_id
            FROM students s
            JOIN student_enrollments e ON s.student_id = e.student_id
            WHERE e.subject_id = ? AND e.advisory_id = ? AND e.school_year_id = ?
        ");
        if (!$fetchSrc) {
            throw new Exception("prepare(fetchSrc) failed: " . $conn->error);
        }
        $fetchSrc->bind_param("iii", $source_subject_id, $source_advisory_id, $school_year_id);
        if (!$fetchSrc->execute()) {
            throw new Exception("execute(fetchSrc) failed: " . $fetchSrc->error);
        }

        $fetchSrc->store_result();
        $fetchSrc->bind_result($f_student_id);

        $checkEnroll = $conn->prepare("SELECT 1 FROM student_enrollments WHERE student_id = ? AND subject_id = ? AND advisory_id = ? AND school_year_id = ? LIMIT 1");
        if (!$checkEnroll) throw new Exception("prepare(checkEnroll) failed: " . $conn->error);

        $insertEnroll = $conn->prepare("INSERT INTO student_enrollments (student_id, subject_id, advisory_id, school_year_id) VALUES (?, ?, ?, ?)");
        if (!$insertEnroll) throw new Exception("prepare(insertEnroll) failed: " . $conn->error);

        $imported = 0; $skipped = 0;

        while ($fetchSrc->fetch()) {
            $student_id = intval($f_student_id);

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

            $insertEnroll->bind_param("iiii", $student_id, $target_subject_id, $advisory_id, $school_year_id);
            if (!$insertEnroll->execute()) {
                throw new Exception("execute(insertEnroll) failed for student {$student_id}: " . $insertEnroll->error);
            }
            $imported++;
        }

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
        if ($fetchSrc !== null) {
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

        $conn->rollback();
        file_put_contents($logFile, date('c') . " - Import exception: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
        $_SESSION['toast'] = "Import failed: DB error (see server logs).";
        $_SESSION['toast_type'] = "error";
        header("Location: view_students.php");
        exit;
    }
}

/**
 * NEW: Retake via AJAX (camera capture + descriptor)
 * Accepts JSON POST (X-Requested-With + application/json) containing:
 * { retake_image:1, retake_student_id:123, image: "data:image/jpeg;base64,...", descriptor: [..] }
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // detect JSON payload (AJAX)
    $isJson = false;
    $raw = file_get_contents('php://input');
    $json = null;
    if ($raw) {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($decoded['retake_image'])) {
            $isJson = true;
            $json = $decoded;
        }
    }

    if ($isJson && intval($json['retake_image'] ?? 0) === 1) {
        $student_id = intval($json['retake_student_id'] ?? 0);
        $imgData = $json['image'] ?? '';
        $descriptor = $json['descriptor'] ?? null; // could be array or null

        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
        $logFile = $logDir . '/import_errors.log';

        if ($student_id <= 0 || !$imgData) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => 'Invalid payload.']);
            exit;
        }

        // parse base64
        if (preg_match('/^data:image\/(\w+);base64,/', $imgData, $m)) {
            $imgType = strtolower($m[1]) === 'png' ? 'png' : 'jpg';
            $imgBase = substr($imgData, strpos($imgData, ',') + 1);
            $imgDecoded = base64_decode($imgBase);
            if ($imgDecoded === false) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['status' => 'error', 'message' => 'Failed to decode image.']);
                exit;
            }
        } else {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => 'Invalid image format.']);
            exit;
        }

        // make upload dirs
        $destRel = build_student_upload_path($student_id, 'face', $imgType); // e.g. uploads/students/face_123_....jpg
        $destAbs = __DIR__ . '/' . $destRel;
        if (!is_dir(dirname($destAbs))) @mkdir(dirname($destAbs), 0755, true);

        // backup previous face file (optional)
        $stmtPrev = $conn->prepare("SELECT face_image_path FROM students WHERE student_id = ? LIMIT 1");
        if ($stmtPrev) {
            $stmtPrev->bind_param("i", $student_id);
            $stmtPrev->execute();
            $prevR = $stmtPrev->get_result();
            if ($rowPrev = $prevR->fetch_assoc()) {
                $prevPath = $rowPrev['face_image_path'];
                if (!empty($prevPath)) {
                    $prevAbs = resolve_relative_to_project($prevPath);
                    if ($prevAbs) {
                        $backupDir = __DIR__ . '/uploads/students/backup';
                        if (!is_dir($backupDir)) @mkdir($backupDir, 0755, true);
                        $backupName = $backupDir . '/face_' . $student_id . '_' . time() . '.' . pathinfo($prevAbs, PATHINFO_EXTENSION);
                        @copy($prevAbs, $backupName);
                    }
                }
            }
            $stmtPrev->close();
        }

        // save file
        if (file_put_contents($destAbs, $imgDecoded) === false) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => 'Failed to save image on server.']);
            exit;
        }

        // Attempt to save descriptor into student_face_descriptors table if present.
        $descriptorSavedToDB = false;
        $descriptor_json_string = '';
        if (is_array($descriptor) || is_string($descriptor)) {
            $descriptor_json_string = is_string($descriptor) ? $descriptor : json_encode($descriptor);
            // check if table student_face_descriptors exists
            $tblRes = $conn->query("SHOW TABLES LIKE 'student_face_descriptors'");
            if ($tblRes && $tblRes->num_rows > 0) {
                // Upsert: INSERT ... ON DUPLICATE KEY UPDATE (table has PRIMARY KEY student_id)
                // some MySQL versions may not support JSON check constraints; store string as-is
                $upsert = $conn->prepare("
                    INSERT INTO student_face_descriptors (student_id, descriptor_json, face_image_path, stale)
                    VALUES (?, ?, ?, 0)
                    ON DUPLICATE KEY UPDATE descriptor_json = VALUES(descriptor_json), face_image_path = VALUES(face_image_path), stale = 0, updated_at = CURRENT_TIMESTAMP()
                ");
                if ($upsert) {
                    // store path same as destRel
                    $upsert->bind_param("iss", $student_id, $descriptor_json_string, $destRel);
                    if (!$upsert->execute()) {
                        file_put_contents($logFile, date('c') . " - descriptor upsert failed: " . $upsert->error . PHP_EOL, FILE_APPEND);
                    } else {
                        $descriptorSavedToDB = true;
                    }
                    $upsert->close();
                } else {
                    file_put_contents($logFile, date('c') . " - prepare(upsert) failed: " . $conn->error . PHP_EOL, FILE_APPEND);
                }
            }
        }

        // If descriptor wasn't saved to descriptors table, write descriptor json to file for safekeeping
        if (!$descriptorSavedToDB && $descriptor_json_string) {
            $descDir = __DIR__ . '/uploads/students/descriptors';
            if (!is_dir($descDir)) @mkdir($descDir, 0755, true);
            $descPathRel = 'uploads/students/descriptors/student_' . $student_id . '_' . time() . '.json';
            file_put_contents(__DIR__ . '/' . $descPathRel, $descriptor_json_string);
            // still update students.face_image_path
            $upd2 = $conn->prepare("UPDATE students SET face_image_path = ? WHERE student_id = ?");
            if ($upd2) {
                $upd2->bind_param("si", $destRel, $student_id);
                if (!$upd2->execute()) {
                    file_put_contents($logFile, date('c') . " - face DB update failed: " . $upd2->error . PHP_EOL, FILE_APPEND);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['status' => 'error', 'message' => 'DB update failed.']);
                    exit;
                }
                $upd2->close();
            }
        } else {
            // descriptor was saved to DB already (or there was no descriptor). Update students.face_image_path if needed.
            $upd3 = $conn->prepare("UPDATE students SET face_image_path = ? WHERE student_id = ?");
            if ($upd3) {
                $upd3->bind_param("si", $destRel, $student_id);
                if (!$upd3->execute()) {
                    file_put_contents($logFile, date('c') . " - face DB update failed: " . $upd3->error . PHP_EOL, FILE_APPEND);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['status' => 'error', 'message' => 'DB update failed.']);
                    exit;
                }
                $upd3->close();
            }
        }

        // success
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'success', 'message' => 'Face updated', 'image' => $destRel]);
        exit;
    }
    // else continue to other POST handlers (import/edit/delete) above
}

/**
 * Fetch variables (existing)
 */
$subject_id = intval($_SESSION['subject_id'] ?? 0);
$advisory_id = intval($_SESSION['advisory_id'] ?? 0);
$school_year_id = intval($_SESSION['school_year_id'] ?? 0);
$subject_name = $_SESSION['subject_name'] ?? '';
$class_name = $_SESSION['class_name'] ?? '';
$year_label = $_SESSION['year_label'] ?? '';
$teacherName = $_SESSION['teacher_fullname'] ?? 'Teacher';

/**
 * Fetch students for current subject
 */
$stmt = $conn->prepare("SELECT s.student_id, s.fullname, s.gender, s.avatar_path, s.face_image_path 
                        FROM students s
                        JOIN student_enrollments e ON s.student_id = e.student_id
                        WHERE e.subject_id = ? AND e.advisory_id = ? AND e.school_year_id = ?");
$stmt->bind_param("iii", $subject_id, $advisory_id, $school_year_id);
$stmt->execute();
$result = $stmt->get_result();
$students = $result->fetch_all(MYSQLI_ASSOC);

/**
 * Fetch teacher's other subjects to populate import dropdown (exclude current subject)
 */
$teacher_id = intval($_SESSION['teacher_id'] ?? 0);
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
    <h1 class="text-xl font-bold">👨‍🏫 <?= htmlspecialchars($teacherName) ?></h1>
    <p class="text-sm">
      Subject: <?= htmlspecialchars($subject_name) ?> |
      Section: <?= htmlspecialchars($class_name) ?> |
      SY: <?= htmlspecialchars($year_label) ?>
    </p>
  </div>
  <div class="flex items-center gap-3">
    <!-- Import button -->
    <button onclick="openImportModal()" class="bg-blue-600 hover:bg-blue-700 px-4 py-2 rounded-lg font-semibold shadow text-white">⬇️ Import</button>
    <form action="teacher_dashboard.php" method="post">
      <input type="hidden" name="subject_id" value="<?= $subject_id ?>">
      <input type="hidden" name="advisory_id" value="<?= $advisory_id ?>">
      <input type="hidden" name="school_year_id" value="<?= $school_year_id ?>">
      <input type="hidden" name="subject_name" value="<?= htmlspecialchars($subject_name) ?>">
      <input type="hidden" name="class_name" value="<?= htmlspecialchars($class_name) ?>">
      <input type="hidden" name="year_label" value="<?= htmlspecialchars($year_label) ?>">
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
        <tr class="border-b" data-student-id="<?= intval($student['student_id']) ?>">
          <td class="px-4 py-3 border"><?= $i++ ?></td>
          <td class="px-4 py-3 border">
            <?php
              $facePath = $student['face_image_path'] ?? '';
              $avatarPath = $student['avatar_path'] ?? '';
              $faceAbs = resolve_relative_to_project($facePath);
              $avatarAbs = resolve_relative_to_project($avatarPath);
            ?>
            <?php if (!empty($facePath) && $faceAbs): ?>
              <?php
                // present path as URL relative to parent directory (attempt to guess public path)
                $urlFace = (strpos($facePath, '/') === 0) ? $facePath : ('../' . ltrim($facePath,'/'));
              ?>
              <img src="<?= htmlspecialchars($urlFace) ?>" alt="Face" class="w-10 h-10 rounded-full object-cover">
            <?php elseif (!empty($avatarPath) && $avatarAbs): ?>
              <?php $urlAvatar = (strpos($avatarPath, '/') === 0) ? $avatarPath : ('../' . ltrim($avatarPath,'/')); ?>
              <img src="<?= htmlspecialchars($urlAvatar) ?>" alt="Avatar" class="w-10 h-10 rounded-full object-cover">
            <?php else: ?>
              <div class="w-10 h-10 bg-yellow-300 rounded-full flex items-center justify-center text-lg">👤</div>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3 border name-cell"><?= htmlspecialchars($student['fullname']) ?></td>
          <td class="px-4 py-3 border"><?= htmlspecialchars($student['gender']) ?></td>
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

<!-- Import Modal (unchanged) -->
<div id="importModal" class="fixed inset-0 z-50 hidden bg-black bg-opacity-50 flex justify-center items-center">
  <form method="POST" id="importForm" class="bg-white p-6 rounded-lg shadow-lg w-96 space-y-4">
    <h2 class="text-lg font-bold">Import students from another subject</h2>
    <label class="block text-sm font-semibold">Source Subject</label>
    <select name="source_subject_id" id="source_subject_id" class="w-full border rounded px-3 py-2" required onchange="loadAdvisories(this.value)">
      <option value="">-- Select subject --</option>
      <?php foreach ($subjects_for_import as $s): ?>
        <option value="<?= intval($s['subject_id']) ?>"><?= htmlspecialchars($s['subject_name']) ?></option>
      <?php endforeach; ?>
    </select>

    <label class="block text-sm font-semibold mt-2">Source Advisory / Section</label>
    <select name="source_advisory_id" id="source_advisory_id" class="w-full border rounded px-3 py-2" required>
      <option value="">-- Select advisory --</option>
    </select>

    <label class="flex items-center gap-2 text-sm">
      <input type="checkbox" name="skip_existing" id="skip_existing" checked>
      Skip students already enrolled in this subject
    </label>
    <div class="flex justify-end gap-2 pt-4">
      <button type="button" onclick="closeModal('importModal')" class="text-gray-600">Cancel</button>
      <button type="submit" name="import_students" value="1" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">Import</button>
    </div>
  </form>
</div>

<!-- RETAKE Modal: Camera + Capture + Descriptor -->
<div id="retakeModal" class="fixed inset-0 z-50 hidden bg-black bg-opacity-50 flex justify-center items-center">
  <div class="bg-white p-6 rounded-lg shadow-lg w-96 space-y-4">
    <input type="hidden" id="retake_student_id_field">
    <h2 id="retakeTitle" class="text-lg font-bold">Retake Image</h2>
    <div class="w-full h-60 bg-blue-100 rounded-xl mb-2 flex items-center justify-center overflow-hidden">
      <video id="retakeVideo" width="320" height="240" autoplay class="rounded"></video>
      <canvas id="retakeCanvas" width="320" height="240" style="display:none;"></canvas>
    </div>
    <div class="flex gap-3 justify-center">
      <button type="button" onclick="retakeCapture()" class="bg-orange-400 hover:bg-orange-500 text-white px-4 py-2 rounded-lg font-bold">Capture</button>
      <button type="button" onclick="retakeClear()" class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-4 py-2 rounded-lg font-bold">Clear</button>
      <button type="button" onclick="submitRetakeAjax()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-bold">Save</button>
    </div>
    <p id="retakeHint" class="text-sm text-gray-600 mt-2">Capture student's face. This will update their face photo and descriptor for recognition.</p>
    <div class="flex justify-end">
      <button type="button" onclick="closeModal('retakeModal')" class="text-gray-600">Cancel</button>
    </div>
  </div>
</div>

<script>
  function openEditModal(id) { document.getElementById('editModal' + id).classList.remove('hidden'); }
  function openDeleteModal(id) { document.getElementById('deleteModal' + id).classList.remove('hidden'); }
  function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

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
  function openImportModal() { document.getElementById('importModal').classList.remove('hidden'); }
  function loadAdvisories(subjectId) {
    const advSelect = document.getElementById('source_advisory_id');
    advSelect.innerHTML = '<option value="">Loading...</option>';
    if (!subjectId) { advSelect.innerHTML = '<option value="">-- Select advisory --</option>'; return; }
    fetch('view_students.php?action=fetch_advisories&subject_id=' + encodeURIComponent(subjectId))
      .then(r => r.json())
      .then(json => {
        if (json.status === 'ok') {
          let html = '<option value="">-- Select advisory --</option>';
          if (json.advisories.length === 0) html = '<option value="">No advisories found for that subject</option>';
          json.advisories.forEach(a => { html += '<option value="' + a.advisory_id + '">' + escapeHtml(a.class_name) + '</option>'; });
          advSelect.innerHTML = html;
        } else { advSelect.innerHTML = '<option value="">Error loading advisories</option>'; console.error(json); }
      }).catch(err => { advSelect.innerHTML = '<option value="">Network error</option>'; console.error(err); });
  }
  function escapeHtml(unsafe) { return unsafe.replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#039;"); }

  document.getElementById('importForm').addEventListener('submit', function (e) {
    const subj = document.getElementById('source_subject_id').value;
    const adv = document.getElementById('source_advisory_id').value;
    if (!subj) { e.preventDefault(); alert('Please select a source subject.'); return; }
    if (!adv) { e.preventDefault(); alert('Please select a source advisory/section.'); return; }
  });

  // ------------------- RETAKE camera logic -------------------
  let retakeStream = null;
  let retakeCapturedDataUrl = '';

  // Start camera when opening modal
  function openRetakeModal(studentId, fullname) {
    document.getElementById('retake_student_id_field').value = studentId;
    document.getElementById('retakeTitle').textContent = 'Retake Image — ' + fullname;
    document.getElementById('retakeModal').classList.remove('hidden');
    startRetakeCamera();
  }

  function startRetakeCamera() {
    const video = document.getElementById('retakeVideo');
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      alert('Camera not supported in this browser.');
      return;
    }
    navigator.mediaDevices.getUserMedia({ video: true })
      .then(stream => {
        retakeStream = stream;
        video.srcObject = stream;
        video.play();
      }).catch(err => {
        console.error('Camera error', err);
        alert('Could not access camera.');
      });
  }

  function stopRetakeCamera() {
    if (retakeStream) {
      retakeStream.getTracks().forEach(t => t.stop());
      retakeStream = null;
    }
  }

  function retakeCapture() {
    const video = document.getElementById('retakeVideo');
    const canvas = document.getElementById('retakeCanvas');
    const ctx = canvas.getContext('2d');
    canvas.width = video.videoWidth || 320;
    canvas.height = video.videoHeight || 240;
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
    retakeCapturedDataUrl = canvas.toDataURL('image/jpeg');
    document.getElementById('retakeHint').textContent = 'Captured — press Save to upload.';
  }

  function retakeClear() {
    retakeCapturedDataUrl = '';
    document.getElementById('retakeHint').textContent = 'Capture cleared.';
  }

  window.addEventListener('beforeunload', () => stopRetakeCamera());

  // Compute descriptor using face-api (same approach as add-student)
  (function(){
    const APP_BASE = (location.pathname.startsWith('/CMS/')) ? '/CMS' : '';
    const MODELS = APP_BASE + '/models';
    let _modelsPromise = null;
    async function loadModelsOnce() {
      if (_modelsPromise) return _modelsPromise;
      _modelsPromise = (async () => {
        await faceapi.nets.tinyFaceDetector.loadFromUri(MODELS);
        await faceapi.nets.faceLandmark68Net.loadFromUri(MODELS);
        await faceapi.nets.faceRecognitionNet.loadFromUri(MODELS);
        return true;
      })();
      return _modelsPromise;
    }

    async function computeDescriptorFromCanvas(canvas) {
      await loadModelsOnce();
      const det = await faceapi.detectSingleFace(canvas, new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.4 })).withFaceLandmarks().withFaceDescriptor();
      if (!det || !det.descriptor) return null;
      return Array.from(det.descriptor);
    }

    // submit retake via AJAX: capture image + descriptor
    window.submitRetakeAjax = async function() {
      const studentId = document.getElementById('retake_student_id_field').value;
      if (!studentId) { alert('No student selected.'); return; }
      if (!retakeCapturedDataUrl) { alert('Please capture a face first.'); return; }

      // prepare canvas for descriptor computation
      const canvas = document.getElementById('retakeCanvas');
      if (!canvas) { alert('Internal canvas missing.'); return; }

      // compute descriptor (best effort; if fails, still upload image)
      let descriptorArr = null;
      try {
        descriptorArr = await computeDescriptorFromCanvas(canvas);
      } catch (err) {
        console.warn('Descriptor compute failed', err);
        descriptorArr = null;
      }

      // build payload
      const payload = {
        retake_image: 1,
        retake_student_id: parseInt(studentId, 10),
        image: retakeCapturedDataUrl,
        descriptor: descriptorArr // could be null
      };

      // send
      try {
        const resp = await fetch('view_students.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          body: JSON.stringify(payload)
        });
        const json = await resp.json();
        if (json.status === 'success') {
          // update row image preview in table if present
          const rows = document.querySelectorAll('#studentsTable tbody tr');
          rows.forEach(r => {
            if (r.getAttribute('data-student-id') == studentId) {
              const imgCell = r.querySelector('td:nth-child(2)');
              if (imgCell) {
                // use returned relative path; construct URL relative to parent
                const img = document.createElement('img');
                img.src = '../' + json.image;
                img.className = 'w-10 h-10 rounded-full object-cover';
                img.alt = 'Face';
                imgCell.innerHTML = '';
                imgCell.appendChild(img);
              }
            }
          });

          // show success small toast
          const t = document.createElement('div');
          t.className = 'fixed bottom-6 left-1/2 transform -translate-x-1/2 z-50 px-6 py-3 rounded-lg shadow-lg bg-green-500 text-white font-semibold text-center';
          t.textContent = 'Face updated';
          document.body.appendChild(t);
          setTimeout(()=>{ t.remove(); }, 1200);

          // close modal & stop camera
          closeModal('retakeModal');
          stopRetakeCamera();
          retakeCapturedDataUrl = '';
        } else {
          alert(json.message || 'Upload failed');
        }
      } catch (err) {
        console.error(err);
        alert('Network error while uploading retake.');
      }
    };
  })();
</script>

<!-- face-api for descriptor computation -->
<script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>

</body>
</html>
