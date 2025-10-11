<?php
// admin_views/attendance.php
// Attendance Management (Admin)

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../../config/db.php';
$conn->set_charset('utf8mb4');

/* ---------- Active School Year ---------- */
$sy = $conn->query("SELECT school_year_id, year_label FROM school_years WHERE status='active' LIMIT 1");
if ($sy->num_rows === 0) { die('No active school year set.'); }
$ACTIVE_SY = $sy->fetch_assoc();
$ACTIVE_SY_ID = (int)$ACTIVE_SY['school_year_id'];
$ACTIVE_SY_LABEL = $ACTIVE_SY['year_label'];

/* ---------- Filters from query ---------- */
$selected_section = isset($_GET['section']) ? (string)$_GET['section'] : '';
$selected_subject = isset($_GET['subject']) ? (string)$_GET['subject'] : '';

/* ---------- Options: Sections & Subjects (active SY) ---------- */
$sections = [];
$stmtSec = $conn->prepare("SELECT advisory_id, class_name FROM advisory_classes WHERE school_year_id=? ORDER BY class_name ASC");
$stmtSec->bind_param("i", $ACTIVE_SY_ID);
$stmtSec->execute();
$resSec = $stmtSec->get_result();
while ($row = $resSec->fetch_assoc()) { $sections[] = $row; }
$stmtSec->close();

$subjects = [];
$stmtSub = $conn->prepare("SELECT subject_id, subject_name FROM subjects WHERE school_year_id=? ORDER BY subject_name ASC");
$stmtSub->bind_param("i", $ACTIVE_SY_ID);
$stmtSub->execute();
$resSub = $stmtSub->get_result();
while ($row = $resSub->fetch_assoc()) { $subjects[] = $row; }
$stmtSub->close();

/* ---------- Attendance list (matches your schema) ---------- */
$sql = "
  SELECT
    a.attendance_id,
    s.fullname,
    ac.class_name,
    sub.subject_name,
    a.status,
    a.timestamp
  FROM attendance_records a
  LEFT JOIN students s       ON s.student_id   = a.student_id
  LEFT JOIN advisory_classes ac ON ac.advisory_id = a.advisory_id
  LEFT JOIN subjects sub     ON sub.subject_id = a.subject_id
  WHERE a.school_year_id = ?
";
$params = [$ACTIVE_SY_ID];
$types  = "i";

if ($selected_section !== '') {           // advisory filter
  $sql .= " AND a.advisory_id = ? ";
  $params[] = (int)$selected_section;
  $types   .= "i";
}
if ($selected_subject !== '') {           // subject filter
  $sql .= " AND a.subject_id = ? ";
  $params[] = (int)$selected_subject;
  $types   .= "i";
}

$sql .= " ORDER BY a.timestamp DESC";

$stmt = $conn->prepare($sql);
if (count($params)) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$list = $stmt->get_result();
?>
<div class="bg-white rounded-xl shadow p-6">
  <!-- Header -->
  <div class="flex items-center justify-between mb-4">
    <h3 class="text-lg font-semibold text-gray-800">Attendance Records <span class="text-gray-500 text-sm">(SY: <?= htmlspecialchars($ACTIVE_SY_LABEL) ?>)</span></h3>
  </div>

  <!-- Filters -->
  <form method="GET" class="mb-4">
    <input type="hidden" name="page" value="attendance">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div>
        <label class="block text-sm text-gray-600 mb-1">Section</label>
        <select name="section" class="w-full border rounded-lg px-3 py-2">
          <option value="">All Sections</option>
          <?php foreach ($sections as $s): ?>
            <option value="<?= (int)$s['advisory_id'] ?>" <?= ($selected_section!=='' && (int)$selected_section===(int)$s['advisory_id'])?'selected':''; ?>>
              <?= htmlspecialchars($s['class_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-sm text-gray-600 mb-1">Subject</label>
        <select name="subject" class="w-full border rounded-lg px-3 py-2">
          <option value="">All Subjects</option>
          <?php foreach ($subjects as $s): ?>
            <option value="<?= (int)$s['subject_id'] ?>" <?= ($selected_subject!=='' && (int)$selected_subject===(int)$s['subject_id'])?'selected':''; ?>>
              <?= htmlspecialchars($s['subject_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="flex items-end">
        <button class="bg-blue-700 text-white px-4 py-2 rounded-lg hover:bg-blue-800">Apply</button>
        <?php if ($selected_section!=='' || $selected_subject!==''): ?>
          <a href="admin.php?page=attendance" class="ml-3 text-sm text-blue-600 hover:underline">Reset</a>
        <?php endif; ?>
      </div>
    </div>
  </form>

  <!-- Table with vertical scroll (adjust max-height as you prefer) -->
  <div class="overflow-y-auto rounded-lg" style="max-height: 450px;">
    <table class="min-w-full text-sm">
      <thead class="bg-gray-100 text-gray-700">
        <tr>
          <th class="text-left px-4 py-3">Student</th>
          <th class="text-left px-4 py-3">Section</th>
          <th class="text-left px-4 py-3">Subject</th>
          <th class="text-left px-4 py-3">Status</th>
          <th class="text-left px-4 py-3">Timestamp</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100">
        <?php if ($list->num_rows === 0): ?>
          <tr><td colspan="5" class="px-4 py-8 text-center text-gray-500">No attendance records found.</td></tr>
        <?php else: while($row = $list->fetch_assoc()): ?>
          <tr class="hover:bg-gray-50">
            <td class="px-4 py-3 font-medium text-gray-900"><?= htmlspecialchars($row['fullname']) ?></td>
            <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars($row['class_name'] ?? '-') ?></td>
            <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars($row['subject_name'] ?? '-') ?></td>
            <td class="px-4 py-3">
              <?php
                $status = (string)$row['status'];
                if (strcasecmp($status, 'Present') === 0) {
                  echo '<span class="px-2 py-1 rounded bg-green-100 text-green-700 text-xs">Present</span>';
                } elseif (strcasecmp($status, 'Absent') === 0) {
                  echo '<span class="px-2 py-1 rounded bg-red-100 text-red-700 text-xs">Absent</span>';
                } elseif (strcasecmp($status, 'Late') === 0) {
                  echo '<span class="px-2 py-1 rounded bg-yellow-100 text-yellow-700 text-xs">Late</span>';
                } else {
                  echo '<span class="px-2 py-1 rounded bg-gray-100 text-gray-700 text-xs">'.htmlspecialchars($status ?: '—').'</span>';
                }
              ?>
            </td>
            <td class="px-4 py-3 text-gray-600 whitespace-nowrap">
              <?= htmlspecialchars(date('M d, Y h:i A', strtotime($row['timestamp']))) ?>
            </td>
          </tr>
        <?php endwhile; endif; ?>
      </tbody>
    </table>
  </div>
</div>
