<?php
// config/logout.php
// Role-aware logout that will not kick out the other active role.

session_start();

/*
  HOW IT WORKS
  ------------
  - Call with ?role=teacher OR ?role=student (recommended).
  - Clears ONLY that role’s session keys.
  - Shared display labels are cleared only if no other role remains.
  - If called without role (auto), it behaves conservatively.
*/

$role = $_GET['role'] ?? $_POST['role'] ?? 'auto';

/* ----- Role-specific key groups ----- */
$teacherKeysOnly = [
  'teacher_id', 'teacher_fullname',
  'subject_id', 'advisory_id', 'school_year_id',
];

$studentKeysOnly = [
  'student_id', 'student_fullname',
  'avatar_path', 'face_image_path',
  'active_subject_id', 'active_advisory_id', 'active_school_year_id',
];

/* Labels used by BOTH UIs (only clear if the other role is gone) */
$sharedLabelKeys = ['subject_name', 'class_name', 'year_label'];

/* Capture current state BEFORE clearing */
$hadTeacher = !empty($_SESSION['teacher_id']);
$hadStudent = !empty($_SESSION['student_id']);

/* Helper to unset keys safely */
$clearKeys = static function(array $keys): void {
  foreach ($keys as $k) {
    if (array_key_exists($k, $_SESSION)) {
      unset($_SESSION[$k]);
    }
  }
};

/* Decide default redirect per role (adjust if your paths differ) */
$redirect = '../index.php';
if ($role === 'teacher') {
  $redirect = '../index.php';
} elseif ($role === 'student') {
  $redirect = '../index.php'; // replace if you have a dedicated student login
}

/* ----- Perform role-scoped clearing ----- */
if ($role === 'teacher') {

  $clearKeys($teacherKeysOnly);

  // If no student remains, drop shared labels too
  if (empty($_SESSION['student_id'])) {
    $clearKeys($sharedLabelKeys);
  }

} elseif ($role === 'student') {

  $clearKeys($studentKeysOnly);

  // If no teacher remains, drop shared labels too
  if (empty($_SESSION['teacher_id'])) {
    $clearKeys($sharedLabelKeys);
  }

} else {
  // AUTO fallback (be conservative, never kick both)
  if ($hadTeacher && !$hadStudent) {
    $clearKeys($teacherKeysOnly);
    $clearKeys($sharedLabelKeys);
    $redirect = '../index.php';

  } elseif ($hadStudent && !$hadTeacher) {
    $clearKeys($studentKeysOnly);
    $clearKeys($sharedLabelKeys);
    $redirect = '../index.php';

  } elseif ($hadTeacher && $hadStudent) {
    // If both were active and no role specified, default to teacher logout only
    $clearKeys($teacherKeysOnly);
    // keep labels because student still in
    $redirect = '../index.php';

  } else {
    // Nothing to clear; go home
    $redirect = '../index.php';
  }
}

/* Optional flash cleanup */
unset($_SESSION['toast'], $_SESSION['toast_type']);

/* Tidy up session id; do NOT destroy whole session if the other role still uses it */
if (empty($_SESSION['teacher_id']) && empty($_SESSION['student_id'])) {
  // If you truly want to wipe everything when both roles are gone, you could:
  // session_unset();
  // session_destroy();
  // But for safety we just rotate the ID.
  session_regenerate_id(true);
}

/* Go back */
header('Location: ' . $redirect);
exit;
