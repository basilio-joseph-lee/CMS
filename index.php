<?php session_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Login Portal</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body {
      background-image: url('img/role.png');
      background-size: cover;
      background-position: center;
      font-family: 'Comic Sans MS', cursive, sans-serif;
    }
    .input-style {
      @apply bg-blue-100 w-full p-3 pl-10 rounded-xl text-gray-800 shadow-inner focus:outline-none;
    }
    .icon {
      @apply absolute left-3 top-1/2 transform -translate-y-1/2 text-blue-600;
    }

    /* --- Flip Card (auto-sizing via JS) --- */
    .perspective { perspective: 1000px; }
    .flip-card {
      position: relative;
      width: 100%;
      /* height is set dynamically by JS to match visible face */
      transform-style: preserve-3d;
      transition: transform 0.7s;
    }
    .flip-card.flipped { transform: rotateY(180deg); }
    .flip-face {
      position: absolute;
      inset: 0;          /* top:0; right:0; bottom:0; left:0 */
      backface-visibility: hidden;
    }
    .flip-back { transform: rotateY(180deg); }
  </style>
</head>
<body class="min-h-screen flex items-center justify-center px-4">

  <div class="bg-orange-100 rounded-[30px] shadow-2xl p-8 w-full max-w-md text-center">
    <h1 class="text-3xl font-bold text-gray-800 mb-6">Welcome to CMS</h1>

    <!-- Flip Container -->
    <div class="perspective">
      <div id="flipCard" class="flip-card">

        <!-- FRONT: Login (wrap EVERYTHING that belongs to the front) -->
        <div id="frontFace" class="flip-face">
          <div class="bg-white rounded-2xl p-6 shadow-md space-y-4">
            <h2 class="text-xl font-semibold text-gray-700 mb-2">👩‍🏫 Teacher Login</h2>

            <div class="relative">
              <span class="icon">👤</span>
              <input type="text" name="username" form="loginForm" placeholder="Username" required class="input-style" autofocus>
            </div>

            <div class="relative">
              <span class="icon">🔒</span>
              <input type="password" name="password" form="loginForm" placeholder="Password" required class="input-style">
            </div>

            <!-- Error / Success Messages -->
            <?php if (!empty($_SESSION['failed'])): ?>
              <div class="bg-red-100 text-red-800 px-4 py-2 rounded">
                <?= $_SESSION['failed']; unset($_SESSION['failed']); ?>
              </div>
            <?php endif; ?>
            <?php if (!empty($_SESSION['error'])): ?>
              <div class="bg-red-100 text-red-800 px-4 py-2 rounded">
                <?= $_SESSION['error']; unset($_SESSION['error']); ?>
              </div>
            <?php endif; ?>
            <?php if (!empty($_SESSION['success'])): ?>
              <div class="bg-green-100 text-green-800 px-4 py-2 rounded">
                <?= $_SESSION['success']; unset($_SESSION['success']); ?>
              </div>
            <?php endif; ?>

            <form id="loginForm" method="POST" action="config/process_login.php">
              <button type="submit" class="bg-yellow-500 hover:bg-yellow-600 text-white font-bold py-3 rounded-xl w-full transition">
                Login as Teacher
              </button>
            </form>
              <div class="mb-[35vh]">

              </div>
            <button type="button" 
                    class="w-full text-blue-700 underline font-semibold "
                    onclick="flipToSignup()">
              ✍️ Sign up (Teacher/Parent)
            </button>
          </div>

          <!-- OR Divider + Student Face Login are PART of the front face -->
          <div class="my-4 text-gray-600 font-semibold">— or —</div>
          <a href="user/face_login.php"
             class="inline-block bg-green-500 hover:bg-green-600 text-white font-bold py-3 px-6 rounded-xl transition shadow-md">
            🎓 Face Login (Student)
          </a>
        </div>

        <!-- BACK: Signup -->
        <div id="backFace" class="flip-face flip-back">
          <div class="bg-white rounded-2xl p-6 shadow-md space-y-4">
            <h2 class="text-xl font-semibold text-gray-700 mb-1">Create Account</h2>

            <!-- Role Switch -->
            <div class="grid grid-cols-2 gap-2">
              <button id="tabTeacher" type="button"
                      class="bg-yellow-500 hover:bg-yellow-600 text-white font-bold py-2 rounded-xl transition"
                      onclick="showRole('teacher')">
                👩‍🏫 Teacher
              </button>
              <button id="tabParent" type="button"
                      class="bg-gray-200 hover:bg-gray-300 text-gray-800 font-bold py-2 rounded-xl transition"
                      onclick="showRole('parent')">
                🧩 Parent
              </button>
            </div>

            <!-- Teacher Signup -->
            <form id="formTeacher" method="POST" action="config/process_teacher_signup.php" class="space-y-3">
              <div class="relative">
                <span class="icon">🧑‍🏫</span>
                <input type="text" name="fullname" placeholder="Full name" required class="input-style">
              </div>

              <!-- REQUIRED mobile number with validation -->
              <div class="relative">
                <span class="icon">📱</span>
                <input type="text" id="teacherMobile" name="mobile_number" placeholder="Mobile number" required class="input-style">
              </div>
              <div id="teacherMobileNote" class="text-xs text-red-600 text-left hidden">Enter a valid 10–13 digit mobile number.</div>

              <div class="relative">
                <span class="icon">👤</span>
                <input type="text" name="username" placeholder="Username" required class="input-style">
              </div>
              <div class="relative">
                <span class="icon">🔒</span>
                <input type="password" id="teacherPass" name="password" placeholder="Password (min 6)" required minlength="6" class="input-style">
              </div>
              <div class="relative">
                <span class="icon">✅</span>
                <input type="password" id="teacherConfirm" name="confirm_password" placeholder="Confirm password" required minlength="6" class="input-style">
              </div>
              <div id="teacherMatch" class="text-xs text-left"></div>

              <button type="submit" class="bg-yellow-500 hover:bg-yellow-600 text-white font-bold py-3 rounded-xl w-full transition">
                Create Teacher Account
              </button>
            </form>

            <!-- Parent Signup -->
            <form id="formParent" method="POST" action="config/process_parent_signup.php" class="space-y-3 hidden">
              <div class="relative">
                <span class="icon">🧑‍👧‍👦</span>
                <input type="text" name="fullname" placeholder="Full name" required class="input-style">
              </div>
              <div class="relative">
                <span class="icon">📧</span>
                <input type="email" name="email" placeholder="Email" required class="input-style">
              </div>
              <div class="relative">
                <span class="icon">📱</span>
                <input type="text" name="mobile_number" placeholder="Mobile number (required)" class="input-style" required >
              </div>
              <div class="relative">
                <span class="icon">🔒</span>
                <input type="password" id="parentPass" name="password" placeholder="Password (min 6)" required minlength="6" class="input-style">
              </div>
              <div class="relative">
                <span class="icon">✅</span>
                <input type="password" id="parentConfirm" name="confirm_password" placeholder="Confirm password" required minlength="6" class="input-style">
              </div>
              <div id="parentMatch" class="text-xs text-left"></div>

              <button type="submit" class="bg-yellow-500 hover:bg-yellow-600 text-white font-bold py-3 rounded-xl w-full transition">
                Create Parent Account
              </button>
            </form>

            <button type="button"
                    class="w-full text-blue-700 underline font-semibold"
                    onclick="flipToLogin()">
              🔙 Back to Login
            </button>
          </div>
        </div>

      </div>
    </div>
  </div>

  <script>
    // Dynamically set the flip-card height to match the visible face
    function setCardHeight(which) {
      const card = document.getElementById('flipCard');
      const face = document.getElementById(which === 'back' ? 'backFace' : 'frontFace');
      const h = face.scrollHeight;
      card.style.height = h + 'px';
    }

    function flipToSignup() {
      const card = document.getElementById('flipCard');
      card.classList.add('flipped');
      setTimeout(() => setCardHeight('back'), 0);
    }

    function flipToLogin() {
      const card = document.getElementById('flipCard');
      card.classList.remove('flipped');
      setTimeout(() => setCardHeight('front'), 0);
    }

    function showRole(role) {
      const tBtn = document.getElementById('tabTeacher');
      const pBtn = document.getElementById('tabParent');
      const tForm = document.getElementById('formTeacher');
      const pForm = document.getElementById('formParent');

      if (role === 'teacher') {
        tBtn.classList.add('bg-yellow-500','text-white');
        tBtn.classList.remove('bg-gray-200','text-gray-800');
        pBtn.classList.add('bg-gray-200','text-gray-800');
        pBtn.classList.remove('bg-yellow-500','text-white');
        tForm.classList.remove('hidden');
        pForm.classList.add('hidden');
      } else {
        pBtn.classList.add('bg-yellow-500','text-white');
        pBtn.classList.remove('bg-gray-200','text-gray-800');
        tBtn.classList.add('bg-gray-200','text-gray-800');
        tBtn.classList.remove('bg-yellow-500','text-white');
        pForm.classList.remove('hidden');
        tForm.classList.add('hidden');
      }
      setTimeout(() => setCardHeight('back'), 0);
    }

    // ✅ Real-time password match (both forms)
    function setupPasswordCheck(passId, confirmId, msgId){
      const pass = document.getElementById(passId);
      const confirm = document.getElementById(confirmId);
      const msg = document.getElementById(msgId);
      function check(){
        if (!confirm.value) { msg.textContent=''; msg.className='text-xs text-left'; setCardHeight('back'); return; }
        if (pass.value === confirm.value) {
          msg.textContent = '✅ Passwords match';
          msg.className = 'text-xs text-green-600 text-left';
        } else {
          msg.textContent = '❌ Passwords do not match';
          msg.className = 'text-xs text-red-600 text-left';
        }
        setCardHeight('back');
      }
      pass.addEventListener('input', check);
      confirm.addEventListener('input', check);
    }
    setupPasswordCheck('teacherPass','teacherConfirm','teacherMatch');
    setupPasswordCheck('parentPass','parentConfirm','parentMatch');

    // ✅ Teacher mobile validation (10–13 digits)
    const mobileInput = document.getElementById('teacherMobile');
    const mobileNote  = document.getElementById('teacherMobileNote');
    function validateMobile() {
      const valid = /^[0-9]{10,13}$/.test(mobileInput.value.trim());
      if (!valid) { mobileNote.classList.remove('hidden'); }
      else { mobileNote.classList.add('hidden'); }
      setCardHeight('back');
      return valid;
    }
    mobileInput.addEventListener('input', validateMobile);

    // Prevent submit for invalid teacher mobile or password mismatch
    document.getElementById('formTeacher').addEventListener('submit', function(e){
      const pass = document.getElementById('teacherPass').value;
      const cfm  = document.getElementById('teacherConfirm').value;
      if (!validateMobile() || pass !== cfm) {
        e.preventDefault();
        if (pass !== cfm) {
          const msg = document.getElementById('teacherMatch');
          msg.textContent = '❌ Passwords do not match';
          msg.className   = 'text-xs text-red-600 text-left';
        }
      }
    });
    document.getElementById('formParent').addEventListener('submit', function(e){
      const pass = document.getElementById('parentPass').value;
      const cfm  = document.getElementById('parentConfirm').value;
      if (pass !== cfm) {
        e.preventDefault();
        const msg = document.getElementById('parentMatch');
        msg.textContent = '❌ Passwords do not match';
        msg.className   = 'text-xs text-red-600 text-left';
        setCardHeight('back');
      }
    });

    // Initial state: Teacher tab, front height
    showRole('teacher');
    window.addEventListener('load', () => setCardHeight('front'));
    document.fonts && document.fonts.ready && document.fonts.ready.then(() => setCardHeight('front'));
  </script>
</body>
</html>
