<?php
session_start();
if (!isset($_SESSION['teacher_id'])) { header("Location: teacher_login.php"); exit; }

$teacherName = $_SESSION['teacher_fullname'] ?? 'Teacher';
$subject_id     = $_SESSION['subject_id'];
$advisory_id    = $_SESSION['advisory_id'];
$school_year_id = $_SESSION['school_year_id'];
$subject_name   = $_SESSION['subject_name'];
$class_name     = $_SESSION['class_name'];
$year_label     = $_SESSION['year_label'];

/* -------- Avatar choices split by gender (paths/labels unchanged) -------- */
$maleAvatars = [
  '/CMS/avatar/M-Blue-Polo.png'      => '',
  '/CMS/avatar/M-whitepolo-tie.png'  => '',
  '/CMS/avatar/2.png'  => '',
  '/CMS/avatar/3.png'  => '',
  '/CMS/avatar/4.png'  => '',
  '/CMS/avatar/5.png'  => '',
  '/CMS/avatar/6.png'  => '',
  '/CMS/avatar/7.png'  => '',
  '/CMS/avatar/8.png'  => '',
  '/CMS/avatar/9.png'  => '',
  '/CMS/avatar/10.png' => '',
  '/CMS/avatar/11.png' => '',
  '/CMS/avatar/12.png' => '',
  '/CMS/avatar/14.png' => '',
  '/CMS/avatar/15.png' => '',
  '/CMS/avatar/16.png' => '',
  '/CMS/avatar/17.png' => '',
  '/CMS/avatar/18.png' => '',
  '/CMS/avatar/19.png' => '',
  '/CMS/avatar/20.png' => '',
  '/CMS/avatar/21.png' => '',
  '/CMS/avatar/22.png' => '',
  '/CMS/avatar/23.png' => '',
  '/CMS/avatar/24.png' => '',
  '/CMS/avatar/25.png' => '',
];

$femaleAvatars = [
  '/CMS/avatar/F-Yellow-blowse.png' => '',
  '/CMS/avatar/F-Green-sweater.png' => '',
  '/CMS/avatar/f1.png' => '',
  '/CMS/avatar/f2.png' => '',
  '/CMS/avatar/f3.png' => '',
  '/CMS/avatar/f4.png' => '',
  '/CMS/avatar/f5.png' => '',
  '/CMS/avatar/f6.png' => '',
  '/CMS/avatar/f7.png' => '',
  '/CMS/avatar/f8.png' => '',
  '/CMS/avatar/f9.png' => '',
  '/CMS/avatar/f14.png' => '',
  '/CMS/avatar/f15.png' => '',
  '/CMS/avatar/f16.png' => '',
  '/CMS/avatar/f17.png' => '',
  '/CMS/avatar/f18.png' => '',
  '/CMS/avatar/f19.png' => '',
  '/CMS/avatar/f20.png' => '',
  '/CMS/avatar/f21.png' => '',
  '/CMS/avatar/f22.png' => '',
  '/CMS/avatar/f23.png' => '',
  '/CMS/avatar/f24.png' => '',
  '/CMS/avatar/f25.png' => '',
  '/CMS/avatar/f26.png' => '',
  '/CMS/avatar/f27.png' => '',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Add Student</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body { background-color:#fefae0; font-family:'Comic Sans MS', cursive, sans-serif; }
    .sidebar { background-color:#386641; color:#fff; }
    .sidebar a { display:block; padding:12px; margin:8px 0; border-radius:10px; text-decoration:none; font-weight:bold; }
    .sidebar a:hover { background-color:#6a994e; }
    .modal-hidden { display:none; }

    /* Avatar selection styles */
    .avatar-card { transition: box-shadow .2s, transform .06s; }
    .avatar-card:hover { transform: translateY(-1px); }
    .avatar-card.active { box-shadow: 0 0 0 3px rgb(79 70 229 / .5) inset; border-color: rgb(79 70 229); }
    .avatar-card.active .sel-badge { display: inline-block; color: rgb(79 70 229); }
  </style>
</head>
<body>
<div class="flex min-h-screen">
  <?php if (isset($_SESSION['toast'])): ?>
    <div id="toast-live" class="fixed top-5 right-5 bg-<?= $_SESSION['toast_type']==='error'?'red':'green' ?>-500 text-white px-6 py-3 rounded shadow-lg z-50">
      <?= $_SESSION['toast'] ?>
    </div>
    <script> setTimeout(()=>{ const t=document.getElementById('toast-live'); if(t) t.remove(); }, 4000); </script>
    <?php unset($_SESSION['toast'], $_SESSION['toast_type']); ?>
  <?php endif; ?>

  <!-- Sidebar -->
  <div class="sidebar w-1/5 p-6 hidden md:block">
    <h2 class="text-2xl font-bold mb-6">SMARTCLASS KIOSK</h2>
    <a href="teacher_dashboard.php">Home</a>
    <a href="#" class="bg-yellow-300 text-black">Add Student</a>
    <a href="view_students.php">View Students</a>
  </div>

  <!-- Main Content -->
  <div class="flex-1 p-4 md:p-10">
    <div class="bg-white shadow-xl rounded-2xl p-6 max-w-5xl mx-auto">
      <div class="flex flex-col md:flex-row items-center justify-between mb-6 gap-2">
        <h1 class="text-2xl md:text-3xl font-bold text-[#bc6c25]">📌 Add Student</h1>
        <p class="text-base md:text-lg text-gray-700 font-semibold">Welcome, <?= htmlspecialchars($teacherName); ?></p>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
        <!-- Capture Section (optional) -->
        <div class="text-center">
          <div class="w-full h-60 bg-blue-100 rounded-xl mb-4 flex items-center justify-center overflow-hidden">
            <video id="video" width="320" height="240" autoplay class="rounded"></video>
            <canvas id="canvas" width="320" height="240" style="display:none;"></canvas>
          </div>
          <div class="flex gap-3 justify-center flex-wrap">
            <button type="button" onclick="captureImage()" class="bg-orange-400 hover:bg-orange-500 text-white px-6 py-2 rounded-lg font-bold">
              Capture Face (optional)
            </button>
            <button type="button" onclick="clearCapture()" class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-6 py-2 rounded-lg font-bold">
              Clear
            </button>
          </div>
          <p class="text-xs text-gray-500 mt-2">If no face is captured, the chosen 2D avatar will be used.</p>
        </div>

        <!-- Avatar + Form -->
        <div>
          <!-- Separate Previews + choose button -->
          <div class="space-y-4">
            <!-- Avatar Preview (always shows chosen 2D character) -->
            <div class="bg-yellow-100 rounded-xl p-4 text-center">
              <img id="avatarPreview" src="../../img/default.png" alt="Avatar preview" class="w-24 h-24 mx-auto mb-3 rounded">
              <div class="flex items-center justify-center gap-3">
                <p class="text-gray-700 font-semibold">Avatar Preview</p>
                <button type="button" class="ml-2 px-3 py-2 rounded-md bg-indigo-600 text-white text-sm font-semibold"
                        onclick="openAvatarModal()">
                  Choose 2D Avatar
                </button>
              </div>
            </div>

            <!-- Captured Face Preview -->
            <div class="bg-blue-50 rounded-xl p-4 text-center border border-blue-100">
              <img id="facePreview" alt="Captured face preview" class="w-24 h-24 mx-auto mb-2 rounded hidden">
              <p id="facePlaceholder" class="text-gray-600 text-sm">No face captured yet</p>
            </div>
          </div>

          <form action="../../config/register_student.php" method="POST" enctype="multipart/form-data" class="space-y-4 mt-4" id="addStudentForm">
            <input type="hidden" name="school_year_id" value="<?= htmlspecialchars($school_year_id) ?>">
            <input type="hidden" name="advisory_id"    value="<?= htmlspecialchars($advisory_id) ?>">
            <input type="hidden" name="subject_id"     value="<?= htmlspecialchars($subject_id) ?>">
            <input type="hidden" id="captured_face"    name="captured_face">
            <input type="hidden" id="avatar_path"      name="avatar_path" value="">

            <input type="text" name="fullname" placeholder="Full Name" required class="w-full p-3 border border-yellow-400 rounded-xl">
            <select name="gender" required class="w-full p-3 border border-yellow-400 rounded-xl">
              <option value="">Select gender</option>
              <option value="Male">Male</option>
              <option value="Female">Female</option>
            </select>

            <button type="submit" class="bg-green-500 hover:bg-green-600 text-white px-6 py-3 rounded-xl font-bold w-full">
              ✅ Confirm
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Avatar Modal with Tabs (responsive + sticky footer) -->
<div id="avatarModal" class="modal-hidden fixed inset-0 z-50">
  <!-- Backdrop -->
  <div class="absolute inset-0 bg-black/50" onclick="closeAvatarModal()"></div>

  <!-- Sheet -->
  <div
    class="absolute inset-x-0 top-4 md:top-10 mx-auto w-[96%] max-w-5xl bg-white rounded-2xl shadow-xl
           h-[92vh] md:h-[85vh] flex flex-col overflow-hidden">

    <!-- Header -->
    <div class="flex items-center justify-between px-4 md:px-6 py-3 border-b">
      <div>
        <h3 class="text-lg md:text-xl font-bold text-gray-800">Assign 2D Character</h3>
        <p class="text-xs text-gray-500">Unique per class (we’ll validate on save). Tap an avatar to select.</p>
      </div>
      <button class="px-2 py-1 rounded hover:bg-gray-100" onclick="closeAvatarModal()">✕</button>
    </div>

    <!-- Tabs -->
    <div class="px-4 md:px-6 pt-3">
      <div class="flex gap-3 border-b">
        <button id="tabMaleBtn"
                class="tab-btn px-2 md:px-4 py-2 text-sm font-semibold border-b-2 border-transparent hover:bg-gray-50 rounded-t
                       data-[active=true]:border-indigo-600 data-[active=true]:text-indigo-700"
                data-target="#maleTab" data-active="true">Male</button>
        <button id="tabFemaleBtn"
                class="tab-btn px-2 md:px-4 py-2 text-sm font-semibold border-b-2 border-transparent hover:bg-gray-50 rounded-t
                       data-[active=true]:border-indigo-600 data-[active=true]:text-indigo-700"
                data-target="#femaleTab">Female</button>
      </div>
    </div>

    <!-- Scrollable body -->
    <div class="px-4 md:px-6 py-4 grow overflow-y-auto">
      <!-- Male Panel -->
      <div id="maleTab" class="tab-panel block">
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
          <?php foreach ($maleAvatars as $path => $label): ?>
            <button type="button"
                    class="avatar-card relative border rounded-xl p-3 text-center bg-white hover:shadow transition
                           focus:outline-none"
                    data-avatar="<?= htmlspecialchars($path) ?>"
                    onclick="chooseAvatar('<?= htmlspecialchars($path) ?>', this)">
              <!-- selection badge -->
              <span class="sel-badge hidden absolute right-2 top-2 rounded-full border border-indigo-600 bg-white">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 m-0.5" viewBox="0 0 20 20" fill="currentColor">
                  <path d="M16.707 5.293a1 1 0 010 1.414l-7.364 7.364a1 1 0 01-1.414 0L3.293 9.536a1 1 0 111.414-1.414l3.01 3.01 6.657-6.657a1 1 0 011.333-.182z" />
                </svg>
              </span>
              <img src="<?= htmlspecialchars($path) ?>" alt="<?= htmlspecialchars($label) ?>"
                   class="w-20 h-20 md:w-24 md:h-24 object-contain mx-auto mb-2 pointer-events-none">
              <div class="text-xs md:text-sm font-semibold pointer-events-none"><?= htmlspecialchars($label) ?></div>
            </button>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Female Panel -->
      <div id="femaleTab" class="tab-panel hidden">
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
          <?php foreach ($femaleAvatars as $path => $label): ?>
            <button type="button"
                    class="avatar-card relative border rounded-xl p-3 text-center bg-white hover:shadow transition
                           focus:outline-none"
                    data-avatar="<?= htmlspecialchars($path) ?>"
                    onclick="chooseAvatar('<?= htmlspecialchars($path) ?>', this)">
              <span class="sel-badge hidden absolute right-2 top-2 rounded-full border border-indigo-600 bg-white">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 m-0.5" viewBox="0 0 20 20" fill="currentColor">
                  <path d="M16.707 5.293a1 1 0 010 1.414l-7.364 7.364a1 1 0 01-1.414 0L3.293 9.536a1 1 0 111.414-1.414l3.01 3.01 6.657-6.657a1 1 0 011.333-.182z" />
                </svg>
              </span>
              <img src="<?= htmlspecialchars($path) ?>" alt="<?= htmlspecialchars($label) ?>"
                   class="w-20 h-20 md:w-24 md:h-24 object-contain mx-auto mb-2 pointer-events-none">
              <div class="text-xs md:text-sm font-semibold pointer-events-none"><?= htmlspecialchars($label) ?></div>
            </button>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Sticky Footer -->
    <div class="px-4 md:px-6 py-3 border-t bg-white">
      <div class="flex flex-col sm:flex-row justify-end gap-2">
        <button class="px-3 py-2 rounded-md bg-gray-100" onclick="closeAvatarModal()">Close</button>
        <button class="px-3 py-2 rounded-md bg-indigo-600 text-white" onclick="closeAvatarModal()">Done</button>
      </div>
    </div>
  </div>
</div>

<script>
  // Camera (optional)
  navigator.mediaDevices.getUserMedia({ video: true })
    .then(stream => { const v=document.getElementById('video'); if (v) v.srcObject = stream; })
    .catch(()=>{});

  function captureImage() {
    const video  = document.getElementById('video');
    const canvas = document.getElementById('canvas');
    const ctx    = canvas.getContext('2d');
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
    const imageData = canvas.toDataURL('image/jpeg');

    // Save captured face data
    document.getElementById('captured_face').value = imageData;

    // Update dedicated face preview
    const faceImg = document.getElementById('facePreview');
    const facePh  = document.getElementById('facePlaceholder');
    faceImg.src = imageData;
    faceImg.classList.remove('hidden');
    if (facePh) facePh.classList.add('hidden');
  }

  function clearCapture() {
    document.getElementById('captured_face').value = '';
    const faceImg = document.getElementById('facePreview');
    const facePh  = document.getElementById('facePlaceholder');
    if (faceImg) {
      faceImg.src = '';
      faceImg.classList.add('hidden');
    }
    if (facePh) facePh.classList.remove('hidden');
  }

  // Modal open/close
  function openAvatarModal(){
    const m = document.getElementById('avatarModal');
    m.classList.remove('modal-hidden');
    document.addEventListener('keydown', escClose);
  }
  function closeAvatarModal(){
    const m = document.getElementById('avatarModal');
    m.classList.add('modal-hidden');
    document.removeEventListener('keydown', escClose);
  }
  function escClose(e){ if(e.key === 'Escape') closeAvatarModal(); }

  // Selection highlight
  let lastSelectedCard = null;
  function chooseAvatar(path, btnEl){
    document.getElementById('avatar_path').value = path;
    document.getElementById('avatarPreview').src = path;

    if (lastSelectedCard) lastSelectedCard.classList.remove('active');
    if (btnEl) {
      btnEl.classList.add('active');
      lastSelectedCard = btnEl;
    }
  }

  // Form guard
  document.getElementById('addStudentForm').addEventListener('submit', function(e){
    const avatar = document.getElementById('avatar_path').value;
    const face   = document.getElementById('captured_face').value;
    if (!avatar && !face) {
      e.preventDefault();
      alert('Please choose a 2D avatar or capture a face before confirming.');
    }
  });

  // Tabs logic
  const tabButtons = document.querySelectorAll('.tab-btn');
  const panels     = document.querySelectorAll('.tab-panel');
  tabButtons.forEach(btn => {
    btn.addEventListener('click', () => {
      tabButtons.forEach(b => b.dataset.active = 'false');
      btn.dataset.active = 'true';
      const target = btn.getAttribute('data-target');
      panels.forEach(p => {
        if ('#' + p.id === target) { p.classList.remove('hidden'); p.classList.add('block'); }
        else { p.classList.add('hidden'); p.classList.remove('block'); }
      });
    });
  });
  document.getElementById('tabMaleBtn').dataset.active = 'true';
</script>
</body>
</html>
