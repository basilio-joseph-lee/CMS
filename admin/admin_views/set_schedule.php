<?php
// admin_schedules.php

include("../config/db.php");

// ---- Get ACTIVE school year ----
$syRes = $conn->query("SELECT school_year_id, year_label FROM school_years WHERE status='active' LIMIT 1");
if ($syRes->num_rows === 0) {
  die("No active school year found. Set one in school_years first.");
}
$activeSY = $syRes->fetch_assoc();
$ACTIVE_SY_ID   = (int)$activeSY['school_year_id'];
$ACTIVE_SY_LABEL= $activeSY['year_label'];

// ---- Fetch Sections (advisory_classes) for active year ----
$sections = [];
$secRes = $conn->prepare("SELECT advisory_id, class_name FROM advisory_classes WHERE school_year_id = ? ORDER BY class_name ASC");
$secRes->bind_param("i", $ACTIVE_SY_ID);
$secRes->execute();
$sec = $secRes->get_result();
while ($row = $sec->fetch_assoc()) {
  $sections[] = $row;
}
$secRes->close();

// ---- Fetch Subjects for active year ----
$subjectsByAdvisory = [];
$subRes = $conn->prepare("SELECT subject_id, advisory_id, subject_name FROM subjects WHERE school_year_id = ? ORDER BY subject_name ASC");
$subRes->bind_param("i", $ACTIVE_SY_ID);
$subRes->execute();
$sub = $subRes->get_result();
while ($row = $sub->fetch_assoc()) {
  $aid = (int)$row['advisory_id'];
  if (!isset($subjectsByAdvisory[$aid])) $subjectsByAdvisory[$aid] = [];
  $subjectsByAdvisory[$aid][] = $row;
}
$subRes->close();

// ---- Toast helper ----
function redirect_with_toast($type, $msg = '') {
  $url = "admin_schedules.php?success=$type";
  if ($msg !== '') $url .= "&msg=" . urlencode($msg);

  if (!headers_sent()) {
    header("Location: $url");
    exit;
  } else {
    echo "<script>location.href=".json_encode($url).";</script>";
    exit;
  }
}

// ---- Overlap checker ----
function has_time_conflict($conn, $sy_id, $advisory_id, $subject_id, $day, $start, $end, $ignore_id = null) {
  if (strtotime($start) >= strtotime($end)) return true;

  $sql = "
    SELECT timeblock_id
    FROM schedule_timeblocks
    WHERE school_year_id = ?
      AND day_of_week = ?
      AND active_flag = 1
      AND (
        advisory_id = ? OR subject_id = ?
      )
      AND NOT (? >= end_time OR ? <= start_time)
  ";
  if ($ignore_id !== null) $sql .= " AND timeblock_id <> ?";

  $stmt = $conn->prepare($sql);
  if ($ignore_id !== null) {
    $stmt->bind_param("iiiiissi", $sy_id, $day, $advisory_id, $subject_id, $start, $end, $ignore_id);
  } else {
    $stmt->bind_param("iiiiis", $sy_id, $day, $advisory_id, $subject_id, $start, $end);
  }
  $stmt->execute();
  $res = $stmt->get_result();
  $hasConflict = $res->num_rows > 0;
  $stmt->close();
  return $hasConflict;
}

// ---- Handle POST actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Create
  if (isset($_POST['create_block'])) {
    $advisory_id = (int)$_POST['advisory_id'];
    $subject_id  = (int)$_POST['subject_id'];
    $day         = (int)$_POST['day_of_week'];
    $start_time  = $_POST['start_time'];
    $end_time    = $_POST['end_time'];
    $room        = trim($_POST['room'] ?? '');
    $remarks     = trim($_POST['remarks'] ?? '');

    if (has_time_conflict($conn, $ACTIVE_SY_ID, $advisory_id, $subject_id, $day, $start_time, $end_time)) {
      redirect_with_toast('conflict', "Overlapping schedule for this section/subject on the same day.");
    }

    $stmt = $conn->prepare("
      INSERT INTO schedule_timeblocks
      (school_year_id, advisory_id, subject_id, day_of_week, start_time, end_time, room, remarks, active_flag)
      VALUES (?,?,?,?,?,?,?,?,1)
    ");
    $stmt->bind_param("iiiissss", $ACTIVE_SY_ID, $advisory_id, $subject_id, $day, $start_time, $end_time, $room, $remarks);
    $stmt->execute();
    $stmt->close();
    redirect_with_toast('add');
  }

  // Update
  if (isset($_POST['update_block'])) {
    $timeblock_id = (int)$_POST['timeblock_id'];
    $advisory_id  = (int)$_POST['advisory_id'];
    $subject_id   = (int)$_POST['subject_id'];
    $day          = (int)$_POST['day_of_week'];
    $start_time   = $_POST['start_time'];
    $end_time     = $_POST['end_time'];
    $room         = trim($_POST['room'] ?? '');
    $remarks      = trim($_POST['remarks'] ?? '');
    $active_flag  = isset($_POST['active_flag']) ? 1 : 0;

    if (has_time_conflict($conn, $ACTIVE_SY_ID, $advisory_id, $subject_id, $day, $start_time, $end_time, $timeblock_id)) {
      redirect_with_toast('conflict', "Overlapping schedule for this section/subject on the same day.");
    }

    $stmt = $conn->prepare("
      UPDATE schedule_timeblocks
      SET advisory_id=?, subject_id=?, day_of_week=?, start_time=?, end_time=?, room=?, remarks=?, active_flag=?
      WHERE timeblock_id=? AND school_year_id=?
    ");
    $stmt->bind_param("iiiisssiii", $advisory_id, $subject_id, $day, $start_time, $end_time, $room, $remarks, $active_flag, $timeblock_id, $ACTIVE_SY_ID);
    $stmt->execute();
    $stmt->close();
    redirect_with_toast('update');
  }

  // Delete
  if (isset($_POST['delete_block'])) {
    $timeblock_id = (int)$_POST['timeblock_id'];
    $stmt = $conn->prepare("DELETE FROM schedule_timeblocks WHERE timeblock_id=? AND school_year_id=?");
    $stmt->bind_param("ii", $timeblock_id, $ACTIVE_SY_ID);
    $stmt->execute();
    $stmt->close();
    redirect_with_toast('delete');
  }
}

// ---- Toast message ----
$toast = '';
if (isset($_GET['success'])) {
  switch ($_GET['success']) {
    case 'add':      $toast = 'Time block added successfully!'; break;
    case 'update':   $toast = 'Time block updated successfully!'; break;
    case 'delete':   $toast = 'Time block deleted successfully!'; break;
    case 'conflict': $toast = '⚠️ Conflict: ' . ($_GET['msg'] ?? 'Overlap detected.'); break;
  }
}

// ---- Fetch all blocks
$listSql = "
  SELECT tb.*, ac.class_name, s.subject_name
  FROM schedule_timeblocks tb
  LEFT JOIN advisory_classes ac ON ac.advisory_id = tb.advisory_id
  LEFT JOIN subjects s ON s.subject_id = tb.subject_id
  WHERE tb.school_year_id = ?
  ORDER BY tb.day_of_week ASC, tb.start_time ASC, ac.class_name ASC
";
$stmt = $conn->prepare($listSql);
$stmt->bind_param("i", $ACTIVE_SY_ID);
$stmt->execute();
$list = $stmt->get_result();
$blocks = [];
while ($row = $list->fetch_assoc()) $blocks[] = $row;
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Schedules (<?= htmlspecialchars($ACTIVE_SY_LABEL) ?>)</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
  .modal-backdrop { backdrop-filter: blur(5px); }
  .fade-in{animation:fadeIn .2s ease-out}
  @keyframes fadeIn{from{opacity:0;transform:scale(.98)}to{opacity:1;transform:scale(1)}}
  .toast{position:fixed;top:1rem;right:1rem;background:#22c55e;color:#fff;padding:.75rem 1rem;border-radius:.5rem;z-index:9999}
</style>
</head>
<body class="bg-gray-50 min-h-screen p-6">
<div class="max-w-6xl mx-auto">
  <div class="flex items-center justify-between mb-4">
    <h1 class="text-2xl font-bold">Schedules - <?= htmlspecialchars($ACTIVE_SY_LABEL) ?></h1>
    <button class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700" onclick="openAdd()">➕ Add Time Block</button>
  </div>

  <?php if ($toast): ?><div class="toast"><?= $toast ?></div><?php endif; ?>

  <div class="bg-white shadow rounded overflow-hidden">
    <table class="min-w-full">
      <thead class="bg-blue-50 text-gray-700">
        <tr>
          <th class="py-2 px-3">Day</th>
          <th class="py-2 px-3">Section</th>
          <th class="py-2 px-3">Subject</th>
          <th class="py-2 px-3">Time</th>
          <th class="py-2 px-3">Room</th>
          <th class="py-2 px-3">Active</th>
          <th class="py-2 px-3">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$blocks): ?>
          <tr><td colspan="7" class="text-center py-6 text-gray-500">No schedule yet.</td></tr>
        <?php else: foreach ($blocks as $b): ?>
        <tr class="border-t">
          <td class="py-2 px-3"><?= ['','Mon','Tue','Wed','Thu','Fri','Sat','Sun'][$b['day_of_week']] ?></td>
          <td class="py-2 px-3"><?= htmlspecialchars($b['class_name']) ?></td>
          <td class="py-2 px-3"><?= htmlspecialchars($b['subject_name']) ?></td>
          <td class="py-2 px-3"><?= substr($b['start_time'],0,5) ?>–<?= substr($b['end_time'],0,5) ?></td>
          <td class="py-2 px-3"><?= htmlspecialchars($b['room']) ?></td>
          <td class="py-2 px-3"><?= $b['active_flag'] ? 'Yes' : 'No' ?></td>
          <td class="py-2 px-3 space-x-2">
            <button class="bg-yellow-500 text-white px-3 py-1 rounded" onclick='openEdit(<?= json_encode($b) ?>)'>Edit</button>
            <form method="POST" class="inline" onsubmit="return confirm('Delete this time block?')">
              <input type="hidden" name="delete_block" value="1">
              <input type="hidden" name="timeblock_id" value="<?= (int)$b['timeblock_id'] ?>">
              <button type="submit" class="bg-red-600 text-white px-3 py-1 rounded">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ADD MODAL -->
<div id="addModal" class="modal-backdrop fixed inset-0 bg-black/40 hidden items-center justify-center z-50">
  <div class="bg-white p-6 rounded shadow max-w-lg w-full fade-in">
    <h3 class="text-xl font-bold mb-4">Add Time Block</h3>
    <form method="POST" onsubmit="return validateTimes(this.start_time.value,this.end_time.value)">
      <input type="hidden" name="create_block" value="1">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
          <label class="block mb-1">Section</label>
          <select name="advisory_id" id="add_advisory" class="w-full border p-2 rounded" required onchange="populateSubjects('add_advisory','add_subject')">
            <option value="">-- choose --</option>
            <?php foreach ($sections as $s): ?>
              <option value="<?= $s['advisory_id'] ?>"><?= htmlspecialchars($s['class_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block mb-1">Subject</label>
          <select name="subject_id" id="add_subject" class="w-full border p-2 rounded" required>
            <option value="">-- choose section first --</option>
          </select>
        </div>
        <div>
          <label class="block mb-1">Day</label>
          <select name="day_of_week" class="w-full border p-2 rounded" required>
            <option value="1">Monday</option><option value="2">Tuesday</option>
            <option value="3">Wednesday</option><option value="4">Thursday</option>
            <option value="5">Friday</option><option value="6">Saturday</option>
            <option value="7">Sunday</option>
          </select>
        </div>
        <div>
          <label class="block mb-1">Start Time</label>
          <input type="time" name="start_time" class="w-full border p-2 rounded" required>
        </div>
        <div>
          <label class="block mb-1">End Time</label>
          <input type="time" name="end_time" class="w-full border p-2 rounded" required>
        </div>
        <div>
          <label class="block mb-1">Room (optional)</label>
          <input type="text" name="room" class="w-full border p-2 rounded">
        </div>
        <div class="md:col-span-2">
          <label class="block mb-1">Remarks (optional)</label>
          <input type="text" name="remarks" class="w-full border p-2 rounded">
        </div>
      </div>
      <div class="mt-4">
        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Save</button>
        <button type="button" class="ml-2 text-gray-600" onclick="closeAdd()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- EDIT MODAL -->
<div id="editModal" class="modal-backdrop fixed inset-0 bg-black/40 hidden items-center justify-center z-50">
  <div class="bg-white p-6 rounded shadow max-w-lg w-full fade-in">
    <h3 class="text-xl font-bold mb-4">Edit Time Block</h3>
    <form method="POST" onsubmit="return validateTimes(this.start_time.value,this.end_time.value)">
      <input type="hidden" name="update_block" value="1">
      <input type="hidden" name="timeblock_id" id="edit_timeblock_id">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
          <label class="block mb-1">Section</label>
          <select name="advisory_id" id="edit_advisory" class="w-full border p-2 rounded" required onchange="populateSubjects('edit_advisory','edit_subject')">
            <?php foreach ($sections as $s): ?>
              <option value="<?= $s['advisory_id'] ?>"><?= htmlspecialchars($s['class_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block mb-1">Subject</label>
          <select name="subject_id" id="edit_subject" class="w-full border p-2 rounded" required></select>
        </div>
        <div>
          <label class="block mb-1">Day</label>
          <select name="day_of_week" id="edit_day" class="w-full border p-2 rounded" required>
            <option value="1">Monday</option><option value="2">Tuesday</option>
            <option value="3">Wednesday</option><option value="4">Thursday</option>
            <option value="5">Friday</option><option value="6">Saturday</option>
            <option value="7">Sunday</option>
          </select>
        </div>
        <div>
          <label class="block mb-1">Start Time</label>
          <input type="time" name="start_time" id="edit_start" class="w-full border p-2 rounded" required>
        </div>
        <div>
          <label class="block mb-1">End Time</label>
          <input type="time" name="end_time" id="edit_end" class="w-full border p-2 rounded" required>
        </div>
        <div>
          <label class="block mb-1">Room</label>
          <input type="text" name="room" id="edit_room" class="w-full border p-2 rounded">
        </div>
        <div>
          <label class="block mb-1">Active?</label>
          <label class="inline-flex items-center space-x-2">
            <input type="checkbox" name="active_flag" id="edit_active" class="w-5 h-5"><span>Yes</span>
          </label>
        </div>
        <div class="md:col-span-2">
          <label class="block mb-1">Remarks</label>
          <input type="text" name="remarks" id="edit_remarks" class="w-full border p-2 rounded">
        </div>
      </div>
      <div class="mt-4">
        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Update</button>
        <button type="button" class="ml-2 text-gray-600" onclick="closeEdit()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
const subjectsByAdvisory = <?= json_encode($subjectsByAdvisory) ?>;

function populateSubjects(advisorySelectId, subjectSelectId, selectedSubjectId = null) {
  const advSel = document.getElementById(advisorySelectId);
  const subSel = document.getElementById(subjectSelectId);
  const aid = advSel.value;
  subSel.innerHTML = '';

  if (!aid || !subjectsByAdvisory[aid]) {
    subSel.innerHTML = '<option value="">-- choose section first --</option>';
    return;
  }

  subjectsByAdvisory[aid].forEach(s => {
    const opt = document.createElement('option');
    opt.value = s.subject_id;
    opt.textContent = s.subject_name;
    if (selectedSubjectId && String(selectedSubjectId) === String(s.subject_id)) {
      opt.selected = true;
    }
    subSel.appendChild(opt);
  });
}

function validateTimes(start, end) {
  if (!start || !end) return true;
  if (start >= end) {
    alert('End time must be later than start time.');
    return false;
  }
  return true;
}

function openAdd(){ document.getElementById('addModal').classList.replace('hidden','flex'); }
function closeAdd(){ document.getElementById('addModal').classList.replace('flex','hidden'); }

function openEdit(block){
  document.getElementById('edit_timeblock_id').value = block.timeblock_id;
  document.getElementById('edit_day').value = block.day_of_week;
  document.getElementById('edit_start').value = block.start_time.substring(0,5);
  document.getElementById('edit_end').value = block.end_time.substring(0,5);
  document.getElementById('edit_room').value = block.room || '';
  document.getElementById('edit_remarks').value = block.remarks || '';
  document.getElementById('edit_active').checked = block.active_flag == 1;
  document.getElementById('edit_advisory').value = block.advisory_id;
  populateSubjects('edit_advisory','edit_subject', block.subject_id);
  document.getElementById('editModal').classList.replace('hidden','flex');
}
function closeEdit(){ document.getElementById('editModal').classList.replace('flex','hidden'); }

document.addEventListener('click',(e)=>{
  if (e.target.id==='addModal') closeAdd();
  if (e.target.id==='editModal') closeEdit();
});
</script>
</body>
</html>
