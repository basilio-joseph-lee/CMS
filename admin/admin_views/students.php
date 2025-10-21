<?php
// admin_views/students.php

require_once __DIR__ . '/../../config/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

/* ---------- Active SY ---------- */
$active = $conn->query("SELECT school_year_id, year_label FROM school_years WHERE status='active' LIMIT 1")->fetch_assoc();
$ACTIVE_SY_ID    = $active ? (int)$active['school_year_id'] : 0;
$ACTIVE_SY_LABEL = $active['year_label'] ?? '';

/* ---------- Toast ---------- */
$toast = '';
$toast_type = 'success';
if (isset($_SESSION['toast'])) {
    $toast = $_SESSION['toast'];
    $toast_type = $_SESSION['toast_type'] ?? 'success';
    unset($_SESSION['toast'], $_SESSION['toast_type']);
} elseif (isset($_GET['success'])) {
    $map = [
        'add'    => 'Student enrolled successfully!',
        'update' => 'Student updated successfully!',
        'delete' => 'Student deleted successfully!',
        'import' => 'Students imported successfully!',
    ];
    $toast = $map[$_GET['success']] ?? '';
    $toast_type = 'success';
}

function redirect_with_toast($msg, $type='success') {
    $_SESSION['toast'] = $msg;
    $_SESSION['toast_type'] = $type;
    header("Location: admin.php?page=students");
    exit;
}

/* ---------- Options ---------- */
$sections = [];
if ($ACTIVE_SY_ID) {
  $stmt = $conn->prepare("SELECT advisory_id, class_name FROM advisory_classes WHERE school_year_id=? ORDER BY class_name ASC");
  $stmt->bind_param('i', $ACTIVE_SY_ID);
  $stmt->execute();
  $sections = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}

/* Subjects */
$subjectsNames = [];
if ($ACTIVE_SY_ID) {
  $stmt = $conn->prepare("SELECT DISTINCT subject_name FROM subjects WHERE school_year_id=? ORDER BY subject_name ASC");
  $stmt->bind_param('i', $ACTIVE_SY_ID);
  $stmt->execute();
  $subjectsNames = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'subject_name');
  $stmt->close();
}

/* School years list (all) */
$schoolYears = $conn->query("SELECT school_year_id, year_label, status FROM school_years ORDER BY school_year_id DESC")->fetch_all(MYSQLI_ASSOC);

/* ---------- Filters ---------- */
$flt_section_id   = isset($_GET['section']) ? trim($_GET['section']) : '';
$flt_subject_name = isset($_GET['subject']) ? trim($_GET['subject']) : '';
$flt_sy           = isset($_GET['school_year']) ? trim($_GET['school_year']) : '';
$q                = isset($_GET['q']) ? trim($_GET['q']) : '';

if ($flt_sy === '' && $ACTIVE_SY_ID) { $flt_sy = (string)$ACTIVE_SY_ID; }

/* ---------- CRUD DELETE ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_enrollment'])) {
  $id = (int)$_POST['enrollment_id'];
  $stmt = $conn->prepare("DELETE FROM student_enrollments WHERE enrollment_id=?");
  $stmt->bind_param("i", $id);
  $stmt->execute(); $stmt->close();
  redirect_with_toast('delete');
}

/* ---------- Import (Admin) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_students'])) {
    $source_subject_name = trim($_POST['source_subject_name'] ?? '');
    $source_section_id   = intval($_POST['source_section_id'] ?? 0);
    $source_sy           = intval($_POST['source_school_year'] ?? 0);
    $skip_existing       = isset($_POST['skip_existing']) ? true : false;

    $target_subject_name = trim($_POST['target_subject_name'] ?? '');
    $target_section_id   = intval($_POST['target_section_id'] ?? 0);
    $target_sy           = intval($_POST['target_school_year'] ?? 0);

    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    $logFile = $logDir . '/import_errors.log';

    if (!$source_subject_name || !$source_section_id || !$source_sy || !$target_subject_name || !$target_section_id || !$target_sy) {
        file_put_contents($logFile, date('c')." - Import failed: invalid parameters.".PHP_EOL, FILE_APPEND);
        redirect_with_toast('error');
    }

    // Fetch source subject_id(s) from subjects table
    $stmtSub = $conn->prepare("SELECT subject_id FROM subjects WHERE subject_name=? AND school_year_id=? LIMIT 1");
    $stmtSub->bind_param("si", $source_subject_name, $source_sy);
    $stmtSub->execute();
    $resSub = $stmtSub->get_result()->fetch_assoc();
    $source_subject_id = $resSub['subject_id'] ?? 0;
    $stmtSub->close();

    $stmtSub = $conn->prepare("SELECT subject_id FROM subjects WHERE subject_name=? AND school_year_id=? LIMIT 1");
    $stmtSub->bind_param("si", $target_subject_name, $target_sy);
    $stmtSub->execute();
    $resSub = $stmtSub->get_result()->fetch_assoc();
    $target_subject_id = $resSub['subject_id'] ?? 0;
    $stmtSub->close();

    if (!$source_subject_id || !$target_subject_id) {
        file_put_contents($logFile, date('c')." - Import failed: source or target subject not found.".PHP_EOL, FILE_APPEND);
        redirect_with_toast('error');
    }

    $conn->begin_transaction();
    try {
        // fetch source students
        $stmtFetch = $conn->prepare("
            SELECT s.student_id
            FROM students s
            JOIN student_enrollments e ON s.student_id = e.student_id
            JOIN subjects sub ON e.subject_id = sub.subject_id
            WHERE e.advisory_id=? AND e.subject_id=? AND e.school_year_id=?
        ");
        $stmtFetch->bind_param("iii", $source_section_id, $source_subject_id, $source_sy);
        $stmtFetch->execute();
        $stmtFetch->bind_result($f_student_id);

        $checkEnroll = $conn->prepare("SELECT 1 FROM student_enrollments WHERE student_id=? AND subject_id=? AND advisory_id=? AND school_year_id=? LIMIT 1");
        $insertEnroll = $conn->prepare("INSERT INTO student_enrollments (student_id, subject_id, advisory_id, school_year_id) VALUES (?,?,?,?)");

        $imported = 0; $skipped = 0;
        while ($stmtFetch->fetch()) {
            $student_id = intval($f_student_id);

            if ($skip_existing) {
                $checkEnroll->bind_param("iiii", $student_id, $target_subject_id, $target_section_id, $target_sy);
                $checkEnroll->execute();
                $checkEnroll->store_result();
                if ($checkEnroll->num_rows > 0) { $skipped++; continue; }
            }

            $insertEnroll->bind_param("iiii", $student_id, $target_subject_id, $target_section_id, $target_sy);
            $insertEnroll->execute();
            $imported++;
        }

        $stmtFetch->close();
        $checkEnroll->close();
        $insertEnroll->close();
        $conn->commit();

        redirect_with_toast("Imported {$imported} students. Skipped {$skipped}.", $imported > 0 ? 'success' : 'error');
    } catch (Exception $e) {
        $conn->rollback();
        file_put_contents($logFile, date('c')." - Import exception: ".$e->getMessage().PHP_EOL, FILE_APPEND);
        redirect_with_toast('error');
    }
}

/* ---------- Fetch enrollments ---------- */
function fetch_enrollments($conn, $flt_section_id, $flt_subject_name, $flt_sy, $q) {
  $sql = "
    SELECT 
      e.enrollment_id,
      s.student_id,
      s.fullname,
      s.gender,
      s.avatar_path,
      ac.class_name,
      sub.subject_name,
      sy.year_label
    FROM student_enrollments e
    JOIN students s        ON e.student_id = s.student_id
    LEFT JOIN advisory_classes ac ON ac.advisory_id    = e.advisory_id
    LEFT JOIN subjects sub        ON sub.subject_id    = e.subject_id
    LEFT JOIN school_years sy     ON sy.school_year_id = e.school_year_id
    WHERE 1=1
  ";
  $params = []; $types  = '';
  if ($flt_section_id !== '')   { $sql .= " AND e.advisory_id = ? ";    $types .= 'i'; $params[] = (int)$flt_section_id; }
  if ($flt_subject_name !== '') { $sql .= " AND sub.subject_name = ? "; $types .= 's'; $params[] = $flt_subject_name; }
  if ($flt_sy !== '')           { $sql .= " AND e.school_year_id = ? "; $types .= 'i'; $params[] = (int)$flt_sy; }
  $sql .= " AND s.fullname LIKE ? "; $types .= 's'; $params[] = ($q !== '' ? "%{$q}%" : '%');
  $sql .= " ORDER BY s.fullname ASC";

  $stmt = $conn->prepare($sql);
  if (!empty($params)) { $stmt->bind_param($types, ...$params); }
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
  return $rows;
}

/* ---------- AJAX fetch ---------- */
if (isset($_GET['ajax']) && $_GET['ajax']==='1') {
  $rows = fetch_enrollments($conn, $flt_section_id, $flt_subject_name, $flt_sy, $q);
  if (empty($rows)) { echo '<tr><td colspan="7" class="py-6 text-center text-gray-500">No enrolled students found.</td></tr>'; exit; }
  foreach ($rows as $row) {
    $avatar = !empty($row['avatar_path']) ? '<img src="'.htmlspecialchars($row['avatar_path']).'" alt="avatar" class="w-10 h-10 rounded-full object-cover">' : '<span class="text-gray-400">—</span>';
    echo '<tr class="hover:bg-gray-50">';
    echo '<td class="px-4 py-3">'.$avatar.'</td>';
    echo '<td class="px-4 py-3 font-semibold text-gray-800">'.htmlspecialchars($row['fullname']).'</td>';
    echo '<td class="px-4 py-3 text-gray-600">'.htmlspecialchars($row['gender']).'</td>';
    echo '<td class="px-4 py-3 text-gray-600">'.htmlspecialchars($row['class_name'] ?? '-').'</td>';
    echo '<td class="px-4 py-3 text-gray-600">'.htmlspecialchars($row['subject_name'] ?? '-').'</td>';
    echo '<td class="px-4 py-3 text-gray-600">'.htmlspecialchars($row['year_label'] ?? '-').'</td>';
    echo '<td class="px-4 py-3 text-center">
            <form method="POST" class="inline" onsubmit="return confirm(\'Are you sure you want to remove this student from enrollment?\')">
              <input type="hidden" name="delete_enrollment" value="1">
              <input type="hidden" name="enrollment_id" value="'.(int)$row['enrollment_id'].'">
              <button type="submit" class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded">Delete</button>
            </form>
          </td>';
    echo '</tr>';
  }
  exit;
}

/* ---------- Initial list ---------- */
$initialRows = fetch_enrollments($conn, $flt_section_id, $flt_subject_name, $flt_sy, $q);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Manage Students</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
.toast { position: fixed; top:1rem; right:1rem; background-color: #22c55e; color:white; padding:1rem 1.5rem; border-radius:0.5rem; box-shadow:0 5px 15px rgba(0,0,0,0.2); z-index:9999; animation: slideDown .5s ease, fadeOut .5s ease 3s forwards; }
@keyframes slideDown { from { opacity:0; transform:translateY(-20px);} to {opacity:1; transform:translateY(0);} }
@keyframes fadeOut { to { opacity:0; transform:translateY(-20px); } }
</style>
</head>
<body class="bg-gray-50">
<div class="max-w-7xl mx-auto px-6 py-2">

<div class="flex items-center justify-between mb-6">
  <div></div><!-- spacer -->
  <div class="flex gap-2">
    <button onclick="openImportModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg shadow font-medium">⬇️ Import</button>
    <a id="btnAddStudent"
       href="admin.php?page=add_student_admin"
       class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium shadow">
      ➕ Add Student
    </a>
  </div>
</div>

<?php if ($toast): ?>
  <div class="toast"><?= htmlspecialchars($toast) ?></div>
  <script>setTimeout(()=>document.querySelector('.toast')?.remove(),3000);</script>
<?php endif; ?>

<!-- Filters -->
<div class="bg-white rounded-xl shadow p-4 mb-4">
  <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
    <div>
      <label class="block text-sm text-gray-600 mb-1">Section</label>
      <select id="flt_section" class="w-full border rounded-lg px-3 py-2">
        <option value="">All Sections</option>
        <?php foreach ($sections as $sec): ?>
          <option value="<?= (int)$sec['advisory_id'] ?>" <?= ($flt_section_id!=='' && (int)$flt_section_id===(int)$sec['advisory_id'])?'selected':''; ?>><?= htmlspecialchars($sec['class_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label class="block text-sm text-gray-600 mb-1">Subject</label>
      <select id="flt_subject" class="w-full border rounded-lg px-3 py-2">
        <option value="">All Subjects</option>
        <?php foreach ($subjectsNames as $name): ?>
          <option value="<?= htmlspecialchars($name) ?>" <?= ($flt_subject_name!=='' && $flt_subject_name===$name)?'selected':''; ?>><?= htmlspecialchars($name) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label class="block text-sm text-gray-600 mb-1">School Year</label>
      <select id="flt_sy" class="w-full border rounded-lg px-3 py-2">
        <option value="">All Years</option>
        <?php foreach ($schoolYears as $sy): ?>
          <option value="<?= (int)$sy['school_year_id'] ?>" <?= ($flt_sy!=='' && (int)$flt_sy===(int)$sy['school_year_id'])?'selected':''; ?>><?= htmlspecialchars($sy['year_label']) ?><?= ($sy['status']==='active' ? ' • active' : '') ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="flex items-end justify-end">
      <button id="btnClear" class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg shadow">Clear</button>
    </div>
  </div>
</div>

<!-- Table -->
<div class="bg-white rounded-xl shadow overflow-y-auto" style="max-height:420px;">
  <table class="min-w-full text-sm">
    <thead class="bg-gray-100 text-gray-700">
      <tr>
        <th class="text-left px-4 py-3">Avatar</th>
        <th class="text-left px-4 py-3">Full Name</th>
        <th class="text-left px-4 py-3">Gender</th>
        <th class="text-left px-4 py-3">Section</th>
        <th class="text-left px-4 py-3">Subject</th>
        <th class="text-left px-4 py-3">School Year</th>
        <th class="text-center px-4 py-3">Actions</th>
      </tr>
      <tr>
        <td colspan="7" class="bg-white px-4 py-3">
          <input id="search_q" type="text" value="<?= htmlspecialchars($q) ?>" class="w-full md:w-1/2 border rounded-lg px-3 py-2" placeholder="Search student name… ">
        </td>
      </tr>
    </thead>
    <tbody id="enrollTbody" class="divide-y divide-gray-100">
      <?php if (empty($initialRows)): ?>
        <tr><td colspan="7" class="py-6 text-center text-gray-500">No enrolled students found.</td></tr>
      <?php else: foreach ($initialRows as $row): ?>
        <tr class="hover:bg-gray-50">
          <td class="px-4 py-3"><?php if(!empty($row['avatar_path'])): ?><img src="<?= htmlspecialchars($row['avatar_path']) ?>" class="w-10 h-10 rounded-full object-cover"><?php else: ?><span class="text-gray-400">—</span><?php endif; ?></td>
          <td class="px-4 py-3 font-semibold text-gray-800"><?= htmlspecialchars($row['fullname']) ?></td>
          <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($row['gender']) ?></td>
          <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($row['class_name'] ?? '-') ?></td>
          <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($row['subject_name'] ?? '-') ?></td>
          <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($row['year_label'] ?? '-') ?></td>
          <td class="px-4 py-3 text-center">
            <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to remove this student from enrollment?')">
              <input type="hidden" name="delete_enrollment" value="1">
              <input type="hidden" name="enrollment_id" value="<?= (int)$row['enrollment_id'] ?>">
              <button type="submit" class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
</div>

<!-- Import Modal -->
<div id="importModal" class="fixed inset-0 z-50 hidden bg-black bg-opacity-50 flex justify-center items-center">
  <form method="POST" class="bg-white p-6 rounded-lg shadow-lg w-96 space-y-4">
    <h2 class="text-lg font-bold">Import Students from Another Subject</h2>

    <label class="block text-sm font-semibold mt-2">Source Subject</label>
    <select name="source_subject_name" class="w-full border rounded px-3 py-2" required>
      <option value="">-- Select subject --</option>
      <?php foreach ($subjectsNames as $name): ?>
        <option value="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars($name) ?></option>
      <?php endforeach; ?>
    </select>

    <label class="block text-sm font-semibold mt-2">Source Section</label>
    <select name="source_section_id" class="w-full border rounded px-3 py-2" required>
      <option value="">-- Select section --</option>
      <?php foreach ($sections as $sec): ?>
        <option value="<?= (int)$sec['advisory_id'] ?>"><?= htmlspecialchars($sec['class_name']) ?></option>
      <?php endforeach; ?>
    </select>

    <label class="block text-sm font-semibold mt-2">Source School Year</label>
    <select name="source_school_year" class="w-full border rounded px-3 py-2" required>
      <?php foreach ($schoolYears as $sy): ?>
        <option value="<?= (int)$sy['school_year_id'] ?>"><?= htmlspecialchars($sy['year_label']) ?></option>
      <?php endforeach; ?>
    </select>

    <label class="block text-sm font-semibold mt-2">Target Subject</label>
    <select name="target_subject_name" class="w-full border rounded px-3 py-2" required>
      <option value="">-- Select subject --</option>
      <?php foreach ($subjectsNames as $name): ?>
        <option value="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars($name) ?></option>
      <?php endforeach; ?>
    </select>

    <label class="block text-sm font-semibold mt-2">Target Section</label>
    <select name="target_section_id" class="w-full border rounded px-3 py-2" required>
      <option value="">-- Select section --</option>
      <?php foreach ($sections as $sec): ?>
        <option value="<?= (int)$sec['advisory_id'] ?>"><?= htmlspecialchars($sec['class_name']) ?></option>
      <?php endforeach; ?>
    </select>

    <label class="block text-sm font-semibold mt-2">Target School Year</label>
    <select name="target_school_year" class="w-full border rounded px-3 py-2" required>
      <?php foreach ($schoolYears as $sy): ?>
        <option value="<?= (int)$sy['school_year_id'] ?>"><?= htmlspecialchars($sy['year_label']) ?></option>
      <?php endforeach; ?>
    </select>

    <label class="flex items-center gap-2 text-sm mt-2">
      <input type="checkbox" name="skip_existing" checked>
      Skip students already enrolled in target
    </label>

    <div class="flex justify-end gap-2 pt-4">
      <button type="button" onclick="closeImportModal()" class="text-gray-600">Cancel</button>
      <button type="submit" name="import_students" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">Import</button>
    </div>
  </form>
</div>

<script>
function buildQS(params){ const esc=encodeURIComponent; const parts=[]; for(const k in params){ if(params[k]!==undefined && params[k]!==null) parts.push(esc(k)+'='+esc(params[k])); } return parts.join('&'); }
function debounce(fn,delay=250){ let t; return (...args)=>{ clearTimeout(t); t=setTimeout(()=>fn(...args),delay); }; }

const $tbody = document.getElementById('enrollTbody');
const $q     = document.getElementById('search_q');
const $sec   = document.getElementById('flt_section');
const $sub   = document.getElementById('flt_subject');
const $sy    = document.getElementById('flt_sy');
const $clear = document.getElementById('btnClear');
const $add   = document.getElementById('btnAddStudent');

async function fetchRows(){
  const params={ajax:'1',q:$q.value.trim(),section:$sec.value,subject:$sub.value,school_year:$sy.value};
  const isLocal = location.hostname==='localhost';
  const basePath = isLocal?'/CMS/admin/admin_views/students.php':'/admin/admin_views/students.php';
  const url = basePath+'?'+buildQS(params);
  try{ const res=await fetch(url,{ headers:{'X-Requested-With':'fetch'} }); $tbody.innerHTML=await res.text(); }catch(e){console.error(e);}
}

function updateAddStudentLink(){
  const params={section:$sec.value||'',subject:$sub.value||'',school_year:$sy.value||''};
  const qs=buildQS(params);
  const base='admin.php?page=add_student_admin';
  $add.href = qs?`${base}&${qs}`:base;
}

const run = debounce(()=>{ fetchRows(); updateAddStudentLink(); },250);
$q.addEventListener('input', run);
$sec.addEventListener('change',()=>{ fetchRows(); updateAddStudentLink(); });
$sub.addEventListener('change',()=>{ fetchRows(); updateAddStudentLink(); });
$sy.addEventListener('change',()=>{ fetchRows(); updateAddStudentLink(); });
$clear.addEventListener('click',()=>{ $q.value='';$sec.value='';$sub.value='';$sy.value=''; fetchRows(); updateAddStudentLink(); });
updateAddStudentLink();

function openImportModal(){ document.getElementById('importModal').classList.remove('hidden'); }
function closeImportModal(){ document.getElementById('importModal').classList.add('hidden'); }
</script>
</body>
</html>
