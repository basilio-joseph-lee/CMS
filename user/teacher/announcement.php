<?php
session_start();
include '../../config/teacher_guard.php';
include '../../config/db.php';
require_once __DIR__ . '/../../config/sms.php'; // must expose send_sms($to, $text)

// config/db.php (o hiwalay na config.php)

/* ---- Session context ---- */
$teacher_id     = (int)($_SESSION['teacher_id'] ?? 0);
$subject_id     = (int)($_SESSION['subject_id'] ?? 0);
$advisory_id    = (int)($_SESSION['advisory_id'] ?? 0);
$school_year_id = (int)($_SESSION['school_year_id'] ?? 0);

$subject_name = $_SESSION['subject_name'] ?? '';
$class_name   = $_SESSION['class_name']   ?? '';
$year_label   = $_SESSION['year_label']   ?? '';
$teacherName  = $_SESSION['teacher_fullname'] ?? 'Teacher';

if ($conn->connect_error) { die("Connection failed: ".$conn->connect_error); }
$conn->set_charset('utf8mb4');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* ---- Helpers ---- */
function normalizePH(?string $msisdn): ?string {
  if ($msisdn === null) return null;
  $s = trim($msisdn);
  if ($s === '') return null;
  $hasPlus = str_starts_with($s, '+');
  $digits  = preg_replace('/\D+/', '', $s);

  // +63XXXXXXXXXX or 63XXXXXXXXXX (12 digits)
  if ((($hasPlus && str_starts_with($s,'+63')) || str_starts_with($digits,'63')) && strlen($digits) === 12) {
    return '63' . substr($digits, 2);
  }
  // 09XXXXXXXXX -> 63XXXXXXXXXX
  if (strlen($digits) === 11 && str_starts_with($digits,'0')) {
    return '63' . substr($digits, 1);
  }
  // 9XXXXXXXXX -> 63XXXXXXXXXX
  if (strlen($digits) === 10 && str_starts_with($digits,'9')) {
    return '63' . $digits;
  }
  return null;
}

/** Get unique, valid parent numbers for the chosen class/subject/SY. */
function fetch_parent_mobiles(mysqli $conn, int $advisoryId, int $subjectId, int $schoolYearId): array {
  $sql = "SELECT DISTINCT p.mobile_number
          FROM student_enrollments e
          JOIN students s ON s.student_id = e.student_id
          JOIN parents  p ON p.parent_id = s.parent_id
          WHERE e.advisory_id = ?
            AND e.subject_id = ?
            AND e.school_year_id = ?
            AND p.mobile_number IS NOT NULL
            AND p.mobile_number <> ''";
  $q = $conn->prepare($sql);
  $q->bind_param('iii', $advisoryId, $subjectId, $schoolYearId);
  $q->execute();
  $r = $q->get_result();

  $uniq = [];
  while ($row = $r->fetch_assoc()) {
    $norm = normalizePH($row['mobile_number']);
    if ($norm) { $uniq[$norm] = true; }
  }
  $q->close();
  return array_keys($uniq);
}

/* ===================== CREATE ===================== */
if (isset($_POST['add'])) {
  $title         = trim($_POST['title'] ?? '');
  $message       = trim($_POST['message'] ?? '');
  $subject_id    = (int)($_POST['subject_id'] ?? 0);
  $class_id      = (int)($_POST['class_id'] ?? 0);
  $audience      = $_POST['audience'] ?? 'STUDENT';  // STUDENT | PARENT | BOTH
  $visible_until = ($_POST['visible_until'] ?? '') ?: null;

  // 1) Save announcement
  $stmt = $conn->prepare("INSERT INTO announcements (teacher_id, subject_id, class_id, title, message, audience, visible_until)
                          VALUES (?, ?, ?, ?, ?, ?, ?)");
  $stmt->bind_param("iiissss", $teacher_id, $subject_id, $class_id, $title, $message, $audience, $visible_until);
  $stmt->execute();
  $stmt->close();

  // 2) Determine recipients (parents only for PARENT or BOTH)
  $mobiles = [];
  if ($audience === 'PARENT' || $audience === 'BOTH') {
    $mobiles = fetch_parent_mobiles($conn, $class_id, $subject_id, $school_year_id);
  }

  // 3) Optional preview
  if (($audience === 'PARENT' || $audience === 'BOTH') && (isset($_GET['sms_preview']) || isset($_POST['sms_preview']))) {
    $_SESSION['sms_preview_list']  = $mobiles;
    $_SESSION['sms_preview_title'] = $title;
    header("Location: announcement.php?success=added&sms_preview=1&count=" . count($mobiles));
    exit;
  }

  // 4) Send SMS (best-effort)
if (($audience === 'PARENT' || $audience === 'BOTH') && !empty($mobiles)) {
    $msg = "From {$teacherName}: " . mb_substr($title, 0, 60) . " — " . mb_substr($message, 0, 100);

    $okCount = 0; 
    $failCount = 0; 
    $debug = [];

    foreach ($mobiles as $msisdn) {
        $r = send_sms($msisdn, $msg);
        $debug[] = $r;
        if ($r['ok']) $okCount++; else $failCount++;
    }

    // Optional: inspect details after redirect
    $_SESSION['sms_debug'] = $debug;

    header("Location: announcement.php?success=added&sms_sent={$okCount}&sms_failed={$failCount}");
    exit;
}

  // 5) No SMS case
  header("Location: announcement.php?success=added");
  exit;
}

/* ===================== DELETE ===================== */
if (isset($_GET['delete'])) {
  $id = (int)$_GET['delete'];
  $conn->query("DELETE FROM announcements WHERE id = $id");
  header("Location: announcement.php?success=deleted");
  exit;
}

/* ===================== EDIT FETCH ===================== */
$edit_data = null;
if (isset($_GET['edit'])) {
  $id = (int)$_GET['edit'];
  $result = $conn->query("SELECT * FROM announcements WHERE id = $id");
  $edit_data = $result->fetch_assoc();
}

/* ===================== UPDATE ===================== */
if (isset($_POST['update'])) {
  $id            = (int)$_POST['id'];
  $title         = trim($_POST['title'] ?? '');
  $message       = trim($_POST['message'] ?? '');
  $subject_id    = (int)($_POST['subject_id'] ?? 0);
  $class_id      = (int)($_POST['class_id'] ?? 0);
  $audience      = $_POST['audience'] ?? 'STUDENT';
  $visible_until = ($_POST['visible_until'] ?? '') ?: null;

  $stmt = $conn->prepare("UPDATE announcements
                          SET title=?, message=?, subject_id=?, class_id=?, audience=?, visible_until=?
                          WHERE id=?");
  $stmt->bind_param("ssisssi", $title, $message, $subject_id, $class_id, $audience, $visible_until, $id);
  $stmt->execute();
  $stmt->close();
  header("Location: announcement.php?success=updated");
  exit;
}

/* ---- Dropdown sources ---- */
$subjects = $conn->query("SELECT subject_id, subject_name FROM subjects WHERE teacher_id = $teacher_id");
$classes  = $conn->query("SELECT advisory_id, class_name FROM advisory_classes WHERE teacher_id = $teacher_id");

/* ---- List ---- */
$res = $conn->query("
  SELECT a.*, s.subject_name, c.class_name
  FROM announcements a
  LEFT JOIN subjects s ON a.subject_id = s.subject_id
  LEFT JOIN advisory_classes c ON a.class_id = c.advisory_id
  WHERE a.teacher_id = $teacher_id
  ORDER BY a.date_posted DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>📣 Announcements</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body{background-image:url('../../img/role.png');background-size:cover;font-family:'Comic Sans MS',cursive,sans-serif}
  </style>
</head>
<body class="p-6">
<?php if (isset($_GET['success'])): ?>
  <div class="fixed top-4 right-4 bg-green-500 text-white px-6 py-3 rounded-xl shadow-xl z-50">
    ✅ Announcement <?= htmlspecialchars($_GET['success']) ?> successfully!
  </div>
  <script>setTimeout(()=>document.querySelector('.fixed.top-4.right-4')?.remove(),3000)</script>
<?php endif; ?>

<?php if (isset($_GET['sms_preview']) && !empty($_SESSION['sms_preview_list'])): ?>
  <div class="fixed top-20 right-4 bg-blue-500 text-white px-6 py-3 rounded-xl shadow-xl z-50">
    🔍 SMS PREVIEW: <?= (int)($_GET['count'] ?? count($_SESSION['sms_preview_list'])) ?> parent number(s) fetched. Check console for the list.
  </div>
  <script>
    console.group('SMS Preview — Parent Mobiles');
    <?php foreach ($_SESSION['sms_preview_list'] as $mobile): ?>
      console.log('<?= addslashes($mobile) ?>');
    <?php endforeach; unset($_SESSION['sms_preview_list']); ?>
    console.groupEnd();
  </script>
<?php endif; ?>



<div class="max-w-6xl mx-auto bg-[#fffbea] p-10 rounded-3xl shadow-2xl ring-4 ring-yellow-300">
  <div class="flex justify-between items-center mb-6">
    <div>
      <h1 class="text-4xl font-bold text-[#bc6c25]">📣 Post Announcements</h1>
      <p class="text-lg font-semibold text-gray-800 mt-2">
        Subject: <span class="text-green-700"><?= htmlspecialchars($subject_name) ?></span> —
        <span class="text-blue-700"><?= htmlspecialchars($class_name) ?></span> |
        SY: <span class="text-red-700"><?= htmlspecialchars($year_label) ?></span>
      </p>
    </div>
    <div class="space-x-4">
      <button onclick="openAddModal()" class="bg-green-500 hover:bg-green-600 text-white px-6 py-2 rounded-xl shadow">+ Add</button>
      <a href="teacher_dashboard.php" class="bg-orange-400 hover:bg-orange-500 text-white text-lg font-bold px-6 py-2 rounded-lg shadow-md">← Back</a>
    </div>
  </div>

  <!-- Announcement Table -->
  <div class="overflow-x-auto">
    <table class="w-full text-left border-4 border-yellow-300 rounded-xl overflow-hidden bg-white">
      <thead class="bg-yellow-200">
        <tr>
          <th class="p-3 text-lg">📌 Title</th>
          <th class="p-3 text-lg">Message</th>
          <th class="p-3 text-lg">Subject</th>
          <th class="p-3 text-lg">Section</th>
          <th class="p-3 text-lg">Audience</th>
          <th class="p-3 text-lg">Date</th>
          <th class="p-3 text-lg">Visible Until</th>
          <th class="p-3 text-center text-lg">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($res->num_rows > 0): while ($row = $res->fetch_assoc()): ?>
        <tr class="border-b hover:bg-yellow-50">
          <td class="p-3 font-semibold"><?= htmlspecialchars($row['title']) ?></td>
          <td class="p-3"><?= htmlspecialchars($row['message']) ?></td>
          <td class="p-3"><?= htmlspecialchars($row['subject_name'] ?? 'N/A') ?></td>
          <td class="p-3"><?= htmlspecialchars($row['class_name'] ?? 'N/A') ?></td>
          <td class="p-3"><?= htmlspecialchars($row['audience'] ?? 'STUDENT') ?></td>
          <td class="p-3"><?= date('M d, Y h:i A', strtotime($row['date_posted'])) ?></td>
          <td class="p-3"><?= $row['visible_until'] ? date('M d, Y', strtotime($row['visible_until'])) : '—' ?></td>
          <td class="p-3 text-center">
            <button onclick='openEditModal(<?= json_encode($row, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)' class="text-yellow-600 hover:underline">Edit</button> |
            <button onclick="confirmDelete(<?= (int)$row['id'] ?>)" class="text-red-600 hover:underline">Delete</button>
          </td>
        </tr>
        <?php endwhile; else: ?>
        <tr><td colspan="8" class="text-center text-gray-500 py-6">No announcements found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal Form -->
<div id="modal" class="fixed inset-0 bg-black bg-opacity-40 flex items-center justify-center z-50 hidden">
  <form method="POST" class="bg-white rounded-2xl p-8 w-full max-w-xl shadow-2xl space-y-4">
    <h2 id="modalTitle" class="text-2xl font-bold mb-4 text-yellow-800">📢 New Announcement</h2>
    <input type="hidden" name="id" id="announcementId">
    <div>
      <label class="block font-semibold">Title</label>
      <input type="text" name="title" id="title" required class="w-full border-2 border-yellow-300 rounded px-3 py-2">
    </div>
    <div>
      <label class="block font-semibold">Message</label>
      <textarea name="message" id="message" required class="w-full border-2 border-yellow-300 rounded px-3 py-2"></textarea>
    </div>
    <div class="grid grid-cols-2 gap-4">
      <div>
        <label class="block font-semibold">Subject</label>
        <select name="subject_id" id="subject_id" required class="w-full border-2 border-yellow-300 rounded px-3 py-2">
          <?php
          $subjects2 = $conn->query("SELECT subject_id, subject_name FROM subjects WHERE teacher_id = $teacher_id");
          while ($s = $subjects2->fetch_assoc()) echo "<option value='{$s['subject_id']}'>".htmlspecialchars($s['subject_name'])."</option>";
          ?>
        </select>
      </div>
      <div>
        <label class="block font-semibold">Section</label>
        <select name="class_id" id="class_id" required class="w-full border-2 border-yellow-300 rounded px-3 py-2">
          <?php
          $classes2 = $conn->query("SELECT advisory_id, class_name FROM advisory_classes WHERE teacher_id = $teacher_id");
          while ($c = $classes2->fetch_assoc()) echo "<option value='{$c['advisory_id']}'>".htmlspecialchars($c['class_name'])."</option>";
          ?>
        </select>
      </div>
    </div>

    <div>
      <label class="block font-semibold">Audience</label>
      <select name="audience" id="audience" class="w-full border-2 border-yellow-300 rounded px-3 py-2">
        <option value="STUDENT">Students</option>
        <option value="PARENT">Parents</option>
        <option value="BOTH">Both (Students & Parents)</option>
      </select>
      <p class="text-xs text-gray-600 mt-1">
        When set to <strong>Parents</strong> or <strong>Both</strong>, an SMS will be sent to all parents with valid numbers in this section.
      </p>
    </div>

    <div>
      <label class="block font-semibold">Visible Until</label>
      <input type="date" name="visible_until" id="visible_until" class="w-full border-2 border-yellow-300 rounded px-3 py-2">
    </div>
    <div class="flex justify-end gap-4 mt-4">
      <button type="button" onclick="closeModal()" class="px-4 py-2 rounded bg-gray-400 text-white hover:bg-gray-500">Cancel</button>
      <button type="submit" name="add" id="submitBtn" class="px-6 py-2 rounded bg-green-500 text-white hover:bg-green-600 shadow">Post</button>
    </div>
  </form>
</div>

<script>
function openAddModal(){
  document.getElementById('modalTitle').innerText='📢 New Announcement';
  document.getElementById('submitBtn').name='add';
  document.getElementById('announcementId').value='';
  document.getElementById('title').value='';
  document.getElementById('message').value='';
  document.getElementById('subject_id').selectedIndex=0;
  document.getElementById('class_id').selectedIndex=0;
  document.getElementById('audience').value='STUDENT';
  document.getElementById('visible_until').value='';
  document.getElementById('modal').classList.remove('hidden');
}
function openEditModal(data){
  document.getElementById('modalTitle').innerText='✏️ Edit Announcement';
  document.getElementById('submitBtn').name='update';
  document.getElementById('announcementId').value=data.id;
  document.getElementById('title').value=data.title;
  document.getElementById('message').value=data.message;
  document.getElementById('subject_id').value=data.subject_id;
  document.getElementById('class_id').value=data.class_id;
  document.getElementById('audience').value=(data.audience||'STUDENT');
  document.getElementById('visible_until').value=(data.visible_until||'');
  document.getElementById('modal').classList.remove('hidden');
}
function closeModal(){ document.getElementById('modal').classList.add('hidden'); }
function confirmDelete(id){ if(confirm('Delete this announcement?')) location.href='?delete='+id; }
</script>
</body>
</html>
