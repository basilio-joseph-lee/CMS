<?php
// admin_announcements.php
// NOTE: per request, no session/login guard here.

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/config/sms.php'; // <-- Mocean helper (send_sms, ph_e164)

$conn->set_charset('utf8mb4');

// Helpers
if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('postv')) { function postv($k, $d=null){ return $_POST[$k] ?? $d; } }
if (!function_exists('getv')) { function getv($k, $d=null){ return $_GET[$k] ?? $d; } }

/* -------------------- Active School Year -------------------- */
$sy = $conn->query("SELECT school_year_id, year_label FROM school_years WHERE status='active' LIMIT 1");
if ($sy->num_rows === 0) { die('No active school year set.'); }
$ACTIVE_SY = $sy->fetch_assoc();
$ACTIVE_SY_ID = (int)$ACTIVE_SY['school_year_id'];
$ACTIVE_SY_LABEL = $ACTIVE_SY['year_label'];

/* -------------------- Sections (active SY) -------------------- */
$sections = [];
$sec = $conn->prepare("SELECT advisory_id, class_name FROM advisory_classes WHERE school_year_id=? ORDER BY class_name ASC");
$sec->bind_param("i", $ACTIVE_SY_ID);
$sec->execute();
$r = $sec->get_result();
while($row = $r->fetch_assoc()){ $sections[] = $row; }
$sec->close();

/* -------------------- Subjects + group by name (dedupe) -------------------- */
$subjectsByAdvisory = []; // advisory_id => [{subject_id, subject_name}]
$subjectsGroup = [];      // subject_name => [subject_id,...]
$sub = $conn->prepare("SELECT subject_id, advisory_id, subject_name FROM subjects WHERE school_year_id=? ORDER BY subject_name ASC");
$sub->bind_param("i", $ACTIVE_SY_ID);
$sub->execute();
$rs = $sub->get_result();
while($row = $rs->fetch_assoc()){
  $aid = (int)$row['advisory_id'];
  if (!isset($subjectsByAdvisory[$aid])) $subjectsByAdvisory[$aid] = [];
  $subjectsByAdvisory[$aid][] = [
    'subject_id'   => (int)$row['subject_id'],
    'subject_name' => $row['subject_name']
  ];
  $n = $row['subject_name'];
  if (!isset($subjectsGroup[$n])) $subjectsGroup[$n] = [];
  $subjectsGroup[$n][] = (int)$row['subject_id'];
}
$sub->close();

/* -------------------- SMS target fetcher -------------------- */
/**
 * Returns DISTINCT parent mobile numbers (raw as saved) for a section,
 * optionally filtered by subject, in the given school year.
 * - If $subjectId is null, sends to ALL students in the section for that SY.
 */
function fetch_parent_mobiles_for_admin(mysqli $conn, int $advisoryId, ?int $subjectId, int $schoolYearId): array {
  if ($subjectId) {
    $sql = "SELECT DISTINCT p.mobile_number AS msisdn
            FROM student_enrollments e
            JOIN students s ON s.student_id = e.student_id
            JOIN parents  p ON p.parent_id = s.parent_id
            WHERE e.advisory_id = ?
              AND e.subject_id = ?
              AND e.school_year_id = ?
              AND p.mobile_number IS NOT NULL
              AND p.mobile_number <> ''";
    $st = $conn->prepare($sql);
    $st->bind_param("iii", $advisoryId, $subjectId, $schoolYearId);
  } else {
    $sql = "SELECT DISTINCT p.mobile_number AS msisdn
            FROM student_enrollments e
            JOIN students s ON s.student_id = e.student_id
            JOIN parents  p ON p.parent_id = s.parent_id
            WHERE e.advisory_id = ?
              AND e.school_year_id = ?
              AND p.mobile_number IS NOT NULL
              AND p.mobile_number <> ''";
    $st = $conn->prepare($sql);
    $st->bind_param("ii", $advisoryId, $schoolYearId);
  }
  $st->execute();
  $res = $st->get_result();
  $out = [];
  while ($row = $res->fetch_assoc()) $out[] = $row['msisdn'];
  $st->close();
  return $out;
}

/* -------------------- Redirect helper -------------------- */
function redirect_with_toast($type, $msg = '', $extra = []) {
  $qs = $_GET;
  $qs['page'] = 'announcement';
  $qs['success'] = $type;
  if ($msg !== '') { $qs['msg'] = $msg; }
  foreach ($extra as $k=>$v) { $qs[$k] = $v; }
  $url = 'admin.php?' . http_build_query($qs);
  echo "<script>location.href=" . json_encode($url) . ";</script>";
  exit;
}
function toast_redirect($type, $msg = '', $extra = []) { redirect_with_toast($type, $msg, $extra); }

/* -------------------- CREATE (multi-section; admin post = teacher_id NULL) -------------------- */
if (isset($_POST['create_announcement'])) {
  $title = trim(postv('title',''));
  $message = trim(postv('message',''));
  $visible_until = postv('visible_until') ?: null;
  $subject_name_for_all = postv('subject_name_all', ''); // optional
  $class_ids = $_POST['class_ids'] ?? [];

  if ($title === '' || $message === '' || !is_array($class_ids) || count($class_ids)===0) {
    toast_redirect('error','Missing title/message/section(s).');
  }

  // Insert announcements per section (with or without subject mapping)
  $stmtWith = $conn->prepare("INSERT INTO announcements (teacher_id, subject_id, class_id, title, message, visible_until) VALUES (NULL, ?, ?, ?, ?, ?)");
  $stmtWithout = $conn->prepare("INSERT INTO announcements (teacher_id, subject_id, class_id, title, message, visible_until) VALUES (NULL, NULL, ?, ?, ?, ?)");

  // Build SMS list across all selected sections (dedup later)
  $allMobiles = [];

  foreach ($class_ids as $cid) {
    $cid = (int)$cid;

    // map subject name -> subject_id for this section (if provided)
    $subject_id = null;
    if ($subject_name_for_all !== '' && isset($subjectsByAdvisory[$cid])) {
      foreach ($subjectsByAdvisory[$cid] as $s) {
        if ($s['subject_name'] === $subject_name_for_all) { $subject_id = (int)$s['subject_id']; break; }
      }
    }

    // INSERT announcement row
    if ($subject_id === null) {
      $stmtWithout->bind_param("isss", $cid, $title, $message, $visible_until);
      $stmtWithout->execute();
    } else {
      $stmtWith->bind_param("iisss", $subject_id, $cid, $title, $message, $visible_until);
      $stmtWith->execute();
    }

    // Gather parent numbers for SMS
    $mobiles = fetch_parent_mobiles_for_admin($conn, $cid, $subject_id, $ACTIVE_SY_ID);
    if (!empty($mobiles)) $allMobiles = array_merge($allMobiles, $mobiles);
  }
  $stmtWith->close();
  $stmtWithout->close();

  // Dedup mobile numbers
  $allMobiles = array_values(array_unique($allMobiles));

  // Compose SMS (from “Admin”)
  $smsText = "From Admin: " . mb_substr($title, 0, 60) . " — " . mb_substr($message, 0, 100);

  // Send and count
  $okCount = 0; $failCount = 0;
  $debugPack = [];
  foreach ($allMobiles as $msisdn) {
    $r = send_sms($msisdn, $smsText);
    $debugPack[] = $r;
    if ($r['ok']) $okCount++; else $failCount++;
  }
  $_SESSION['sms_debug_admin'] = $debugPack;

  toast_redirect('created', 'Posted to '.count($class_ids).' section(s).', [
    'sms_sent' => $okCount,
    'sms_failed' => $failCount
  ]);
}

/* -------------------- DELETE -------------------- */
if (isset($_POST['delete_announcement'], $_POST['id'])) {
  $id = (int)$_POST['id'];
  $d = $conn->prepare("DELETE FROM announcements WHERE id=?");
  $d->bind_param("i",$id);
  $d->execute(); $d->close();
  toast_redirect('deleted');
}

/* -------------------- UPDATE (single row) -------------------- */
if (isset($_POST['update_announcement'], $_POST['id'])) {
  $id = (int)$_POST['id'];
  $title = trim(postv('title',''));
  $message = trim(postv('message',''));
  $visible_until = postv('visible_until') ?: null;
  $class_id = (int)postv('class_id');
  $subject_name = postv('subject_name', '');

  if ($title === '' || $message === '' || !$class_id) {
    toast_redirect('error','Missing title/message/section.');
  }

  // map subject_name -> subject_id under this class (if provided)
  $subject_id = null;
  if ($subject_name !== '' && isset($subjectsByAdvisory[$class_id])) {
    foreach ($subjectsByAdvisory[$class_id] as $s) {
      if ($s['subject_name'] === $subject_name) { $subject_id = (int)$s['subject_id']; break; }
    }
  }

  if ($subject_id === null) {
    $u = $conn->prepare("UPDATE announcements SET title=?, message=?, subject_id=NULL, class_id=?, visible_until=? WHERE id=?");
    $u->bind_param("ssisi", $title, $message, $class_id, $visible_until, $id);
  } else {
    $u = $conn->prepare("UPDATE announcements SET title=?, message=?, subject_id=?, class_id=?, visible_until=? WHERE id=?");
    $u->bind_param("ssiisi", $title, $message, $subject_id, $class_id, $visible_until, $id);
  }
  $u->execute(); $u->close();
  toast_redirect('updated');
}

/* -------------------- Filters (deduped subject by NAME) -------------------- */
$flt_section = getv('section','');            // advisory_id
$flt_subject_name = getv('subject_name','');  // subject_name (not id)

$where = " WHERE ac.school_year_id = ".$ACTIVE_SY_ID." ";
$params = [];
if ($flt_section !== '') { $where .= " AND a.class_id = ? "; $params[] = (int)$flt_section; }
if ($flt_subject_name !== '' && isset($subjectsGroup[$flt_subject_name])) {
  $ids = $subjectsGroup[$flt_subject_name]; // all subject_id that share the same name
  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $where .= " AND a.subject_id IN ($placeholders) ";
  $params = array_merge($params, $ids);
}

/* -------------------- List (prepared) -------------------- */
$sql = "
  SELECT a.*, ac.class_name, s.subject_name, t.fullname AS teacher_name
  FROM announcements a
  LEFT JOIN advisory_classes ac ON ac.advisory_id = a.class_id
  LEFT JOIN subjects s ON s.subject_id = a.subject_id
  LEFT JOIN teachers t ON t.teacher_id = a.teacher_id
  $where
  ORDER BY a.date_posted DESC, a.id DESC
";
$stmt = $conn->prepare($sql);
if (count($params)) {
  $types = str_repeat('i', count($params));
  $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$list = $stmt->get_result();
$rows = [];
while($row = $list->fetch_assoc()){ $rows[] = $row; }
$stmt->close();

/* -------------------- Toast map -------------------- */
$toast=''; $smsBanner='';
if (isset($_GET['success'])) {
  $map = [
    'created' => 'Announcement posted successfully!',
    'updated' => 'Announcement updated successfully!',
    'deleted' => 'Announcement deleted successfully!',
    'error'   => 'Error: '.($_GET['msg'] ?? 'Please check inputs.')
  ];
  $toast = $map[$_GET['success']] ?? '';
}
$sent  = isset($_GET['sms_sent'])   ? (int)$_GET['sms_sent']   : null;
$fail  = isset($_GET['sms_failed']) ? (int)$_GET['sms_failed'] : null;
if ($sent !== null || $fail !== null) {
  $smsBanner = "SMS sent to ".(int)$sent." parent(s), failed: ".(int)$fail;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin • Announcements (<?= h($ACTIVE_SY_LABEL) ?>)</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<script src="https://cdn.tailwindcss.com"></script>
<style>
  .modal { backdrop-filter: blur(4px); }
  .fade-in { animation: fadeIn .18s ease-out; }
  @keyframes fadeIn{from{opacity:.0;transform:scale(.98)}to{opacity:1;transform:scale(1)}}
  .truncate-2 { display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
  .icon-btn { display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; border-radius:8px; }

  @media (max-width: 1024px) {
    .table-auto { display:block; overflow-x:auto; white-space:nowrap; }
  }
  @media (max-width: 640px) {
    table thead { display:none; }
    table, table tbody, table tr, table td { display:block; width:100%; }
    table tr { margin-bottom:1rem; background:#fff; border-radius:.5rem; box-shadow:0 2px 5px rgba(0,0,0,.05); }
    table td { text-align:right; padding:.75rem 1rem; position:relative; border:none; }
    table td::before { content:attr(data-label); position:absolute; left:1rem; font-weight:600; color:#4b5563; }
  }
</style>
</head>
<body class="bg-gray-50">
<div class="max-w-6xl mx-auto px-6 py-6">
  <!-- Header -->
  <div class="flex items-center justify-between mb-4">
    <div></div>
    <button class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700" onclick="openCreate()">＋ New Announcement</button>
  </div>

  <!-- Filters -->
  <form method="GET" class="bg-white rounded-xl shadow p-4 mb-4">
    <input type="hidden" name="page" value="announcement">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
      <div>
        <label class="block text-sm text-gray-600 mb-1">Section</label>
        <select name="section" class="w-full border rounded-lg px-3 py-2">
          <option value="">All Sections</option>
          <?php foreach($sections as $s): ?>
            <option value="<?= (int)$s['advisory_id'] ?>" <?= ($flt_section!=='' && (int)$flt_section===(int)$s['advisory_id'])?'selected':''; ?>>
              <?= h($s['class_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-sm text-gray-600 mb-1">Subject</label>
        <select name="subject_name" class="w-full border rounded-lg px-3 py-2">
          <option value="">All Subjects</option>
          <?php foreach(array_keys($subjectsGroup) as $name): ?>
            <option value="<?= h($name) ?>" <?= ($flt_subject_name===$name)?'selected':''; ?>><?= h($name) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="flex items-end">
        <button class="bg-gray-900 text-white px-4 py-2 rounded-lg">Apply</button>
        <?php if ($flt_section!=='' || $flt_subject_name!==''): ?>
          <a href="admin.php?page=announcement" class="ml-3 text-sm text-blue-600 hover:underline">Reset</a>
        <?php endif; ?>
      </div>
    </div>
  </form>

  <?php if ($toast): ?>
    <div class="fixed top-4 right-4 bg-emerald-500 text-white px-4 py-2 rounded-lg shadow"><?= h($toast) ?></div>
    <script>setTimeout(()=>document.querySelector('.fixed.top-4')?.remove(), 2300);</script>
  <?php endif; ?>

  <?php if ($smsBanner): ?>
    <div class="fixed top-16 right-4 bg-green-600 text-white px-4 py-2 rounded-lg shadow"><?= h($smsBanner) ?></div>
    <script>setTimeout(()=>document.querySelector('.fixed.top-16')?.remove(), 3000);</script>
  <?php endif; ?>

  <!-- Optional console dump for last send -->
  <?php if (!empty($_SESSION['sms_debug_admin'])): ?>
    <script>
      console.group('Admin SMS debug (last send)');
      <?php foreach ($_SESSION['sms_debug_admin'] as $r): ?>
        console.log(<?= json_encode($r, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>);
      <?php endforeach; unset($_SESSION['sms_debug_admin']); ?>
      console.groupEnd();
    </script>
  <?php endif; ?>

  <!-- Table -->
  <div class="bg-white rounded-xl shadow overflow-x-auto">
    <div class="overflow-y-auto" style="max-height: 400px;">
      <table class="min-w-full text-sm table-auto">
        <thead class="bg-gray-100 text-gray-700">
          <tr>
            <th class="text-left px-4 py-3">Title</th>
            <th class="text-left px-4 py-3">Message</th>
            <th class="text-left px-4 py-3">Section</th>
            <th class="text-left px-4 py-3">Subject</th>
            <th class="text-left px-4 py-3">Posted By</th>
            <th class="text-left px-4 py-3">Date</th>
            <th class="text-left px-4 py-3">Visible Until</th>
            <th class="text-center px-3 py-3">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php if (empty($rows)): ?>
            <tr><td colspan="8" class="px-4 py-8 text-center text-gray-500">No announcements found.</td></tr>
          <?php else: foreach($rows as $row): ?>
            <tr class="hover:bg-gray-50">
              <td data-label="Title" class="px-4 py-3 font-medium text-gray-900"><?= h($row['title']) ?></td>
              <td data-label="Message" class="px-4 py-3 text-gray-700 break-words max-w-[200px]">
                <div class="truncate-2"><?= nl2br(h($row['message'])) ?></div>
              </td>
              <td data-label="Section" class="px-4 py-3">
                <span class="inline-block bg-gray-100 text-gray-700 text-xs px-2 py-1 rounded">
                  <?= h($row['class_name'] ?? ('#'.$row['class_id'])) ?>
                </span>
              </td>
              <td data-label="Subject" class="px-4 py-3">
                <span class="inline-block bg-indigo-50 text-indigo-700 text-xs px-2 py-1 rounded">
                  <?= h($row['subject_name'] ?? '—') ?>
                </span>
              </td>
              <td data-label="Posted By" class="px-4 py-3">
                <?php if (empty($row['teacher_name'])): ?>
                  <span class="inline-block bg-emerald-50 text-emerald-700 text-xs px-2 py-1 rounded">Admin</span>
                <?php else: ?>
                  <span class="inline-block bg-amber-50 text-amber-700 text-xs px-2 py-1 rounded"><?= h($row['teacher_name']) ?></span>
                <?php endif; ?>
              </td>
              <td data-label="Date" class="px-4 py-3 text-gray-600 whitespace-nowrap">
                <?= $row['date_posted'] ? date('M d, Y h:i A', strtotime($row['date_posted'])) : '—' ?>
              </td>
              <td data-label="Visible Until" class="px-4 py-3 text-gray-600 whitespace-nowrap">
                <?= $row['visible_until'] ? date('M d, Y', strtotime($row['visible_until'])) : '—' ?>
              </td>
              <td data-label="Actions" class="px-3 py-2 text-center">
                <!-- Edit -->
                <button class="icon-btn mr-2 hover:bg-yellow-100" title="Edit"
                  onclick='openEdit(<?= json_encode([
                    "id"=>$row["id"],
                    "title"=>$row["title"],
                    "message"=>$row["message"],
                    "class_id"=>$row["class_id"],
                    "subject_name"=>$row["subject_name"],
                    "visible_until"=>$row["visible_until"]
                  ], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>)'>
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" class="text-yellow-600">
                    <path d="M12 20h9" stroke-width="2" stroke-linecap="round"/>
                    <path d="M16.5 3.5a2.121 2.121 0 1 1 3 3L7 19l-4 1 1-4 12.5-12.5z" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                  </svg>
                </button>
                <!-- Delete -->
                <form method="POST" class="inline" onsubmit="return confirm('Delete this announcement?')">
                  <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                  <button name="delete_announcement" class="icon-btn hover:bg-red-100" title="Delete">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" class="text-red-600">
                      <polyline points="3 6 5 6 21 6" stroke-width="2" stroke-linecap="round"/>
                      <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" stroke-width="2" stroke-linecap="round"/>
                      <line x1="10" y1="11" x2="10" y2="17" stroke-width="2" stroke-linecap="round"/>
                      <line x1="14" y1="11" x2="14" y2="17" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Create Modal -->
<div id="createModal" class="modal fixed inset-0 bg-black/40 hidden items-center justify-center p-4 z-50">
  <div class="bg-white rounded-xl shadow max-w-2xl w-full p-6 fade-in">
    <div class="flex items-center justify-between mb-3">
      <h2 class="text-lg font-semibold text-gray-900">New Announcement</h2>
      <button onclick="closeCreate()" class="text-gray-500 hover:text-gray-700">✕</button>
    </div>
    <form method="POST" class="space-y-4">
      <input type="hidden" name="create_announcement" value="1">
      <div>
        <label class="block text-sm text-gray-700 mb-1">Title</label>
        <input type="text" name="title" class="w-full border rounded-lg px-3 py-2" required>
      </div>
      <div>
        <label class="block text-sm text-gray-700 mb-1">Message</label>
        <textarea name="message" rows="4" class="w-full border rounded-lg px-3 py-2" required></textarea>
      </div>
      <div class="grid md:grid-cols-2 gap-3">
        <div>
          <label class="block text-sm text-gray-700 mb-1">Subject (optional, by name)</label>
          <select name="subject_name_all" class="w-full border rounded-lg px-3 py-2">
            <option value="">No subject</option>
            <?php foreach(array_keys($subjectsGroup) as $name): ?>
              <option value="<?= h($name) ?>"><?= h($name) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="text-xs text-gray-500 mt-1">We’ll match this subject per section if available.</p>
        </div>
        <div>
          <label class="block text-sm text-gray-700 mb-1">Visible Until (optional)</label>
          <input type="date" name="visible_until" class="w-full border rounded-lg px-3 py-2">
        </div>
      </div>
      <div>
        <label class="block text-sm text-gray-700 mb-1">Post to Section(s)</label>
        <select name="class_ids[]" multiple size="6" class="w-full border rounded-lg px-3 py-2" required>
          <?php foreach($sections as $s): ?>
            <option value="<?= (int)$s['advisory_id'] ?>"><?= h($s['class_name']) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="text-xs text-gray-500 mt-1">Hold Ctrl/Cmd to select multiple sections.</p>
      </div>
      <div class="flex justify-end gap-2">
        <button type="button" onclick="closeCreate()" class="px-4 py-2 rounded-lg bg-gray-200 text-gray-700">Cancel</button>
        <button class="px-4 py-2 rounded-lg bg-blue-600 text-white">Post</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="modal fixed inset-0 bg-black/40 hidden items-center justify-center p-4 z-50">
  <div class="bg-white rounded-xl shadow max-w-2xl w-full p-6 fade-in">
    <div class="flex items-center justify-between mb-3">
      <h2 class="text-lg font-semibold text-gray-900">Edit Announcement</h2>
      <button onclick="closeEdit()" class="text-gray-500 hover:text-gray-700">✕</button>
    </div>
    <form method="POST" class="space-y-4">
      <input type="hidden" name="update_announcement" value="1">
      <input type="hidden" name="id" id="e_id">
      <div>
        <label class="block text-sm text-gray-700 mb-1">Title</label>
        <input type="text" name="title" id="e_title" class="w-full border rounded-lg px-3 py-2" required>
      </div>
      <div>
        <label class="block text-sm text-gray-700 mb-1">Message</label>
        <textarea name="message" id="e_message" rows="4" class="w-full border rounded-lg px-3 py-2" required></textarea>
      </div>
      <div class="grid md:grid-cols-2 gap-3">
        <div>
          <label class="block text-sm text-gray-700 mb-1">Section</label>
          <select name="class_id" id="e_class" class="w-full border rounded-lg px-3 py-2" required>
            <?php foreach($sections as $s): ?>
              <option value="<?= (int)$s['advisory_id'] ?>"><?= h($s['class_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm text-gray-700 mb-1">Subject (by name)</label>
          <select name="subject_name" id="e_subject_name" class="w-full border rounded-lg px-3 py-2">
            <option value="">No subject</option>
            <?php foreach(array_keys($subjectsGroup) as $name): ?>
              <option value="<?= h($name) ?>"><?= h($name) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div>
        <label class="block text-sm text-gray-700 mb-1">Visible Until (optional)</label>
        <input type="date" name="visible_until" id="e_visible_until" class="w-full border rounded-lg px-3 py-2">
      </div>
      <div class="flex justify-end gap-2">
        <button type="button" onclick="closeEdit()" class="px-4 py-2 rounded-lg bg-gray-200 text-gray-700">Cancel</button>
        <button class="px-4 py-2 rounded-lg bg-blue-600 text-white">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
function openCreate(){ const m=document.getElementById('createModal'); m.classList.remove('hidden'); m.classList.add('flex'); }
function closeCreate(){ const m=document.getElementById('createModal'); m.classList.add('hidden'); m.classList.remove('flex'); }
function openEdit(data){
  document.getElementById('e_id').value = data.id || '';
  document.getElementById('e_title').value = data.title || '';
  document.getElementById('e_message').value = data.message || '';
  document.getElementById('e_class').value = data.class_id || '';
  document.getElementById('e_subject_name').value = data.subject_name || '';
  document.getElementById('e_visible_until').value = (data.visible_until || '').substring(0,10);
  const m=document.getElementById('editModal'); m.classList.remove('hidden'); m.classList.add('flex');
}
function closeEdit(){ const m=document.getElementById('editModal'); m.classList.add('hidden'); m.classList.remove('flex'); }
document.addEventListener('click', (e) => {
  if (e.target.id === 'createModal') closeCreate();
  if (e.target.id === 'editModal') closeEdit();
});
</script>
</body>
</html>
