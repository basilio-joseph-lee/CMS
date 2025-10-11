<?php


mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../../config/db.php';
$conn->set_charset('utf8mb4');

/* ---------- Read incoming filters from students.php ---------- */
$REQ_SECTION_ID = isset($_GET['section']) ? trim($_GET['section']) : '';
$REQ_SUBJECT    = isset($_GET['subject']) ? trim($_GET['subject']) : '';
$REQ_SY_ID      = isset($_GET['school_year']) ? (int)$_GET['school_year'] : 0;

/* ---------- Active School Year ---------- */
$sy = $conn->query("SELECT school_year_id, year_label FROM school_years WHERE status='active' LIMIT 1");
$ACTIVE_SY = $sy->fetch_assoc() ?? ['school_year_id'=>0,'year_label'=>'None'];
$ACTIVE_SY_ID    = (int)$ACTIVE_SY['school_year_id'];
$ACTIVE_SY_LABEL = $ACTIVE_SY['year_label'];

/* ---------- Decide which SY to load (requested or active) ---------- */
$LOAD_SY_ID    = $REQ_SY_ID ?: $ACTIVE_SY_ID;
$LOAD_SY_LABEL = '';
if ($LOAD_SY_ID) {
  $stmtTmp = $conn->prepare("SELECT year_label FROM school_years WHERE school_year_id=? LIMIT 1");
  $stmtTmp->bind_param("i", $LOAD_SY_ID);
  $stmtTmp->execute();
  $LOAD_SY_LABEL = $stmtTmp->get_result()->fetch_column() ?: '';
  $stmtTmp->close();
}

/* ---------- Options: Sections & Subjects for the chosen SY ---------- */
$sections = [];
$stmtSec = $conn->prepare("
  SELECT advisory_id, class_name 
  FROM advisory_classes 
  WHERE school_year_id=? 
  ORDER BY class_name ASC
");
$stmtSec->bind_param("i", $LOAD_SY_ID);
$stmtSec->execute();
$resSec = $stmtSec->get_result();
while ($row = $resSec->fetch_assoc()) { $sections[] = $row; }
$stmtSec->close();

$subjects = [];
$stmtSub = $conn->prepare("
  SELECT subject_id, subject_name 
  FROM subjects 
  WHERE school_year_id=? 
  ORDER BY subject_name ASC
");
$stmtSub->bind_param("i", $LOAD_SY_ID);
$stmtSub->execute();
$resSub = $stmtSub->get_result();
while ($row = $resSub->fetch_assoc()) { $subjects[] = $row; }
$stmtSub->close();

/* ---------- Preselect values ---------- */
$PRE_SECTION_ID = '';
if ($REQ_SECTION_ID !== '') {
  // ensure the requested section exists in this SY
  foreach ($sections as $s) {
    if ((int)$s['advisory_id'] === (int)$REQ_SECTION_ID) { $PRE_SECTION_ID = (int)$REQ_SECTION_ID; break; }
  }
}

// Subject can arrive as an id or as a name (students.php sends name). We try both.
$PRE_SUBJECT_ID = '';
if ($REQ_SUBJECT !== '') {
  // numeric? treat as id first
  if (ctype_digit($REQ_SUBJECT)) {
    foreach ($subjects as $sub) {
      if ((int)$sub['subject_id'] === (int)$REQ_SUBJECT) { $PRE_SUBJECT_ID = (int)$REQ_SUBJECT; break; }
    }
  }
  // if not set yet, try by name (exact match within this SY)
  if ($PRE_SUBJECT_ID === '') {
    foreach ($subjects as $sub) {
      if (strcasecmp($sub['subject_name'], $REQ_SUBJECT) === 0) { $PRE_SUBJECT_ID = (int)$sub['subject_id']; break; }
    }
  }
}

/* ---------- Handle Submit ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_student_admin'])) {
  $fullname    = trim($_POST['fullname'] ?? '');
  $gender      = $_POST['gender'] ?? '';
  $advisory_id = (int)($_POST['advisory_id'] ?? 0);
  $subject_id  = (int)($_POST['subject_id'] ?? 0);
  $avatar_path = trim($_POST['avatar_path'] ?? '');
  $captured    = $_POST['captured_face'] ?? '';

  // We enroll into the SY we loaded for this page
  $ENROLL_SY_ID = $LOAD_SY_ID;

  if ($fullname === '' || $gender === '' || !$advisory_id || !$subject_id || !$ENROLL_SY_ID) {
    $_SESSION['toast'] = '❌ Please complete all required fields.';
    $_SESSION['toast_type'] = 'error';
    echo "<script>location.href='admin.php?page=add_student_admin"
       . ($REQ_SECTION_ID || $REQ_SUBJECT || $REQ_SY_ID ? "&" . http_build_query([
            'section'=>$REQ_SECTION_ID, 'subject'=>$REQ_SUBJECT, 'school_year'=>$REQ_SY_ID
          ]) : "")
       . "';</script>";
    exit;
  }

  // Save captured face to file (optional)
  $face_image_path = '';
  if ($captured) {
    $raw = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $captured));
    if ($raw !== false) {
      $fname = 'face_' . uniqid('', true) . '.jpg';
      $abs   = __DIR__ . '/../../student_faces/' . $fname;
      file_put_contents($abs, $raw);
      $base = (strpos($_SERVER['REQUEST_URI'], '/CMS/') === 0) ? '/CMS' : '';
      $face_image_path = $base . '/student_faces/' . $fname;
    }
  }

  if (empty($student_code)) {
    $student_code = uniqid('STU-');
}


  // Insert student
  $ins = $conn->prepare("INSERT INTO students (fullname, gender, avatar_path, face_image_path) VALUES (?, ?, ?, ?)");
  $ins->bind_param("ssss", $fullname, $gender, $avatar_path, $face_image_path);
  $ins->execute();
  $student_id = $conn->insert_id;
  $ins->close();

  

  // Enroll to class + subject for selected/loaded SY
  $en = $conn->prepare("INSERT INTO student_enrollments (student_id, advisory_id, school_year_id, subject_id) VALUES (?, ?, ?, ?)");
  $en->bind_param("iiii", $student_id, $advisory_id, $ENROLL_SY_ID, $subject_id);
  $en->execute();
  $en->close();

  $_SESSION['toast'] = '✅ Student successfully added!';
  $_SESSION['toast_type'] = 'success';
  echo "<script>location.href='admin.php?page=students&success=add';</script>";
  exit;
}

/* ---------- Avatars (unchanged) ---------- */
$maleAvatars = [
  'https://myschoolness.site//avatar/M-Blue-Polo.png'      => '',
  'https://myschoolness.site//avatar/M-whitepolo-tie.png'  => '',
  'https://myschoolness.site//avatar/2.png'  => '',
  'https://myschoolness.site//avatar/3.png'  => '',
  'https://myschoolness.site//avatar/4.png'  => '',
  'https://myschoolness.site//avatar/5.png'  => '',
  'https://myschoolness.site//avatar/6.png'  => '',
  'https://myschoolness.site//avatar/7.png'  => '',
  'https://myschoolness.site//avatar/8.png'  => '',
  'https://myschoolness.site//avatar/9.png'  => '',
  'https://myschoolness.site//avatar/10.png' => '',
  'https://myschoolness.site//avatar/11.png' => '',
  'https://myschoolness.site//avatar/12.png' => '',
  'https://myschoolness.site//avatar/14.png' => '',
  'https://myschoolness.site//avatar/15.png' => '',
  'https://myschoolness.site//avatar/16.png' => '',
  'https://myschoolness.site//avatar/17.png' => '',
  'https://myschoolness.site//avatar/18.png' => '',
  'https://myschoolness.site//avatar/19.png' => '',
  'https://myschoolness.site//avatar/20.png' => '',
  'https://myschoolness.site//avatar/21.png' => '',
  'https://myschoolness.site//avatar/22.png' => '',
  'https://myschoolness.site//avatar/23.png' => '',
  'https://myschoolness.site//avatar/24.png' => '',
  'https://myschoolness.site//avatar/25.png' => '',
];
$femaleAvatars = [
  'https://myschoolness.site//avatar/F-Yellow-blowse.png' => '',
  'https://myschoolness.site//avatar/F-Green-sweater.png' => '',
  'https://myschoolness.site//avatar/f1.png' => '',
  'https://myschoolness.site//avatar/f2.png' => '',
  'https://myschoolness.site//avatar/f3.png' => '',
  'https://myschoolness.site//avatar/f4.png' => '',
  'https://myschoolness.site//avatar/f5.png' => '',
  'https://myschoolness.site//avatar/f6.png' => '',
  'https://myschoolness.site//avatar/f7.png' => '',
  'https://myschoolness.site//avatar/f8.png' => '',
  'https://myschoolness.site//avatar/f9.png' => '',
  'https://myschoolness.site//avatar/f14.png' => '',
  'https://myschoolness.site//avatar/f15.png' => '',
  'https://myschoolness.site//avatar/f16.png' => '',
  'https://myschoolness.site//avatar/f17.png' => '',
  'https://myschoolness.site//avatar/f18.png' => '',
  'https://myschoolness.site//avatar/f19.png' => '',
  'https://myschoolness.site//avatar/f20.png' => '',
  'https://myschoolness.site//avatar/f21.png' => '',
  'https://myschoolness.site//avatar/f22.png' => '',
  'https://myschoolness.site//avatar/f23.png' => '',
  'https://myschoolness.site//avatar/f24.png' => '',
  'https://myschoolness.site//avatar/f25.png' => '',
  'https://myschoolness.site//avatar/f26.png' => '',
  'https://myschoolness.site//avatar/f27.png' => '',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Add Student (Admin)</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body { background-color:#fefae0; font-family:'Comic Sans MS', cursive, sans-serif; }
    .modal-hidden { display:none; }
    .avatar-card { transition: box-shadow .2s, transform .06s; }
    .avatar-card:hover { transform: translateY(-1px); }
    .avatar-card.active { box-shadow: 0 0 0 3px rgb(79 70 229 / .5) inset; border-color: rgb(79 70 229); }
    .avatar-card.active .sel-badge { display:inline-block; color:rgb(79 70 229); }
  </style>
</head>
<body>
<div class="max-w-6xl mx-auto p-6">
  <?php if (isset($_SESSION['toast'])): ?>
    <div class="fixed top-5 right-5 bg-<?= ($_SESSION['toast_type']??'success')==='error'?'red':'green' ?>-500 text-white px-6 py-3 rounded shadow z-50">
      <?= $_SESSION['toast']; unset($_SESSION['toast'], $_SESSION['toast_type']); ?>
    </div>
  <?php endif; ?>

  <div class="bg-white shadow-xl rounded-2xl p-6">
    <div class="flex items-center justify-between mb-6">
      <h1 class="text-2xl md:text-3xl font-bold text-[#bc6c25] flex items-center gap-3">
        📌 Add Student
        <?php if ($LOAD_SY_LABEL): ?>
          <span class="text-xs bg-indigo-100 text-indigo-700 px-2 py-1 rounded">SY: <?= htmlspecialchars($LOAD_SY_LABEL) ?></span>
        <?php endif; ?>
      </h1>
      <a href="admin.php?page=students" class="text-blue-600 hover:underline">← Back to Enrollments</a>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
      <!-- Camera / capture -->
      <div class="text-center">
        <div class="w-full h-60 bg-blue-100 rounded-xl mb-4 flex items-center justify-center overflow-hidden">
          <video id="video" width="320" height="240" autoplay class="rounded"></video>
          <canvas id="canvas" width="320" height="240" style="display:none;"></canvas>
        </div>
        <div class="flex gap-3 justify-center flex-wrap">
          <button type="button" onclick="captureImage()" class="bg-orange-400 hover:bg-orange-500 text-white px-6 py-2 rounded-lg font-bold">Capture Face (optional)</button>
          <button type="button" onclick="clearCapture()" class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-6 py-2 rounded-lg font-bold">Clear</button>
        </div>
        <div class="bg-blue-50 rounded-xl p-4 text-center border border-blue-100 mt-3">
          <img id="facePreview" class="w-24 h-24 mx-auto mb-2 rounded hidden" alt="Captured face">
          <p id="facePlaceholder" class="text-gray-600 text-sm">No face captured yet</p>
        </div>
      </div>

      <!-- Form -->
      <div>
        <div class="bg-yellow-100 rounded-xl p-4 text-center">
          <img id="avatarPreview" src="../../img/default.png" alt="Avatar preview" class="w-24 h-24 mx-auto mb-3 rounded">
          <button type="button" class="ml-2 px-3 py-2 rounded-md bg-indigo-600 text-white text-sm font-semibold" onclick="openAvatarModal()">Choose 2D Avatar</button>
        </div>

        <form method="POST" class="space-y-4 mt-4" id="addStudentForm">
          <input type="hidden" name="create_student_admin" value="1">
          <input type="hidden" id="captured_face" name="captured_face">
          <input type="hidden" id="avatar_path"  name="avatar_path" value="">

          <input type="text" name="fullname" placeholder="Full Name" required class="w-full p-3 border border-yellow-400 rounded-xl">
          <select name="gender" required class="w-full p-3 border border-yellow-400 rounded-xl">
            <option value="">Select gender</option>
            <option value="Male">Male</option>
            <option value="Female">Female</option>
          </select>

          <select name="advisory_id" required class="w-full p-3 border border-yellow-400 rounded-xl">
            <option value="">Select Section (SY: <?= htmlspecialchars($LOAD_SY_LABEL ?: $ACTIVE_SY_LABEL) ?>)</option>
            <?php foreach ($sections as $sec): ?>
              <option value="<?= (int)$sec['advisory_id'] ?>" <?= ($PRE_SECTION_ID && (int)$sec['advisory_id']===$PRE_SECTION_ID)?'selected':''; ?>>
                <?= htmlspecialchars($sec['class_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>

          <select name="subject_id" required class="w-full p-3 border border-yellow-400 rounded-xl">
            <option value="">Select Subject</option>
            <?php foreach ($subjects as $sub): ?>
              <option value="<?= (int)$sub['subject_id'] ?>" <?= ($PRE_SUBJECT_ID && (int)$sub['subject_id']===$PRE_SUBJECT_ID)?'selected':''; ?>>
                <?= htmlspecialchars($sub['subject_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>

          <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded-xl font-bold w-full">
            ✅ Confirm
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Avatar Modal -->
<div id="avatarModal" class="modal-hidden fixed inset-0 z-50">
  <div class="absolute inset-0 bg-black/50" onclick="closeAvatarModal()"></div>
  <div class="absolute inset-x-0 top-6 mx-auto w-[96%] max-w-5xl bg-white rounded-2xl shadow-xl h-[88vh] flex flex-col overflow-hidden">
    <div class="flex items-center justify-between px-5 py-3 border-b">
      <h3 class="text-lg font-bold">Assign 2D Character</h3>
      <button class="px-2 py-1 rounded hover:bg-gray-100" onclick="closeAvatarModal()">✕</button>
    </div>
    <div class="px-5 pt-3 flex gap-3 border-b">
      <button id="tabMaleBtn"   class="px-3 py-2 text-sm font-semibold border-b-2 border-indigo-600 text-indigo-700">Male</button>
      <button id="tabFemaleBtn" class="px-3 py-2 text-sm font-semibold border-b-2 border-transparent hover:bg-gray-50 rounded-t">Female</button>
    </div>
    <div class="px-5 py-4 grow overflow-y-auto">
      <div id="maleTab" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
        <?php foreach ($maleAvatars as $path => $label): ?>
          <button type="button" class="avatar-card relative border rounded-xl p-3 text-center bg-white hover:shadow"
                  onclick="chooseAvatar('<?= htmlspecialchars($path) ?>', this)">
            <span class="sel-badge hidden absolute right-2 top-2 rounded-full border border-indigo-600 bg-white">✓</span>
            <img src="<?= htmlspecialchars($path) ?>" class="w-20 h-20 md:w-24 md:h-24 object-contain mx-auto mb-2">
            <div class="text-xs md:text-sm font-semibold"><?= htmlspecialchars($label) ?></div>
          </button>
        <?php endforeach; ?>
      </div>
      <div id="femaleTab" class="hidden grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
        <?php foreach ($femaleAvatars as $path => $label): ?>
          <button type="button" class="avatar-card relative border rounded-xl p-3 text-center bg-white hover:shadow"
                  onclick="chooseAvatar('<?= htmlspecialchars($path) ?>', this)">
            <span class="sel-badge hidden absolute right-2 top-2 rounded-full border border-indigo-600 bg-white">✓</span>
            <img src="<?= htmlspecialchars($path) ?>" class="w-20 h-20 md:w-24 md:h-24 object-contain mx-auto mb-2">
            <div class="text-xs md:text-sm font-semibold"><?= htmlspecialchars($label) ?></div>
          </button>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="px-5 py-3 border-t bg-white text-right">
      <button class="px-3 py-2 rounded-md bg-gray-100" onclick="closeAvatarModal()">Done</button>
    </div>
  </div>
</div>

<script>
  // Camera
  navigator.mediaDevices.getUserMedia({ video: true })
    .then(stream => { const v=document.getElementById('video'); if (v) v.srcObject=stream; })
    .catch(()=>{});

  function captureImage(){
    const v=document.getElementById('video'), c=document.getElementById('canvas'), ctx=c.getContext('2d');
    ctx.drawImage(v,0,0,c.width,c.height);
    const data=c.toDataURL('image/jpeg');
    document.getElementById('captured_face').value=data;
    const img=document.getElementById('facePreview'), ph=document.getElementById('facePlaceholder');
    img.src=data; img.classList.remove('hidden'); ph.classList.add('hidden');
  }
  function clearCapture(){
    document.getElementById('captured_face').value='';
    const img=document.getElementById('facePreview'), ph=document.getElementById('facePlaceholder');
    img.src=''; img.classList.add('hidden'); ph.classList.remove('hidden');
  }

  // Modal + tabs
  function openAvatarModal(){document.getElementById('avatarModal').classList.remove('modal-hidden');}
  function closeAvatarModal(){document.getElementById('avatarModal').classList.add('modal-hidden');}
  document.getElementById('tabMaleBtn').addEventListener('click',()=>{document.getElementById('maleTab').classList.remove('hidden');document.getElementById('femaleTab').classList.add('hidden');});
  document.getElementById('tabFemaleBtn').addEventListener('click',()=>{document.getElementById('femaleTab').classList.remove('hidden');document.getElementById('maleTab').classList.add('hidden');});

  // Avatar choose
  let last=null;
  function chooseAvatar(path,btn){
    document.getElementById('avatar_path').value=path;
    document.getElementById('avatarPreview').src=path;
    if(last) last.classList.remove('active');
    btn.classList.add('active'); last=btn;
  }

  // Guard: must pick avatar or face
  document.getElementById('addStudentForm').addEventListener('submit', function(e){
    const a=document.getElementById('avatar_path').value;
    const f=document.getElementById('captured_face').value;
    if(!a && !f){ e.preventDefault(); alert('Please choose a 2D avatar or capture a face.'); }
  });
</script>
</body>
</html>
