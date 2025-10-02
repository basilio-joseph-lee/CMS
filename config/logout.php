<?php
// config/logout.php
// Role-aware logout that won't kick out other active roles.
session_start();

// --- Identify requested role (explicit is best) ---
$role = $_GET['role'] ?? $_POST['role'] ?? 'auto';

// --- Key groups (current flat structure) ---
$teacherKeysOnly = [
  'teacher_id','teacher_fullname',
  'subject_id','advisory_id','school_year_id'
];

$studentKeysOnly = [
  'student_id','student_fullname',
  'avatar_path','face_image_path',
  'active_subject_id','active_advisory_id','active_school_year_id'
];
// Shared labels used by both UIs
$sharedLabelKeys = ['subject_name','class_name','year_label'];

// Capture state BEFORE clearing
$hadTeacher = !empty($_SESSION['teacher_id']);
$hadStudent = !empty($_SESSION['student_id']);

// Helper: unset keys safely
$clearKeys = static function(array $keys): void {
  foreach ($keys as $k) {
    if (array_key_exists($k, $_SESSION)) {
      unset($_SESSION[$k]);
    }
  }
};

// Decide redirect per role (customize if your routes differ)
$redirect = '../index.php';
if ($role === 'teacher') {
  $redirect = '../index.php';
} elseif ($role === 'student') {
  $redirect = '../index.php'; // or your student login page
}

// --- Perform role-scoped clearing ---
if ($role === 'teacher') {
  $clearKeys($teacherKeysOnly);
  // After clearing, if **no student remains**, drop shared labels
  if (empty($_SESSION['student_id'])) {
    $clearKeys($sharedLabelKeys);
  }

} elseif ($role === 'student') {
  $clearKeys($studentKeysOnly);
  // After clearing, if **no teacher remains**, drop shared labels
  if (empty($_SESSION['teacher_id'])) {
    $clearKeys($sharedLabelKeys);
  }

} else {
  // AUTO fallback: be conservative, don't kill both
  if ($hadTeacher && !$hadStudent) {
    $clearKeys($teacherKeysOnly);
    $clearKeys($sharedLabelKeys);
    $redirect = '../../index.php';
  } elseif ($hadStudent && !$hadTeacher) {
    $clearKeys($studentKeysOnly);
    $clearKeys($sharedLabelKeys);
    $redirect = '../index.php';
  } elseif ($hadTeacher && $hadStudent) {
    // If both were active and no role specified, default to teacher logout only
    $clearKeys($teacherKeysOnly);
    // keep shared labels because student still in
    $redirect = '../../index.php';
  } else {
    // Nothing to clear; go home
    $redirect = '../../index.php';
  }
}

// Optional flash cleanup
unset($_SESSION['toast'], $_SESSION['toast_type']);

// If no role data remains at all, tidy up the session id
if (empty($_SESSION['teacher_id']) && empty($_SESSION['student_id'])) {
  // If you want to fully wipe the bag, uncomment:
  // session_unset(); session_destroy();
  session_regenerate_id(true);
}

header('Location: ' . $redirect);
exit;
