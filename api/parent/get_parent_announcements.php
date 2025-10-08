<?php
// /CMS/api/parent/get_parent_announcements.php  (local)
// https://myschoolness.site/api/parent/get_parent_announcements.php  (prod)
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';

if (!isset($_SESSION['parent_id'])) {
  echo json_encode(['success' => false, 'message' => 'Unauthorized']);
  exit;
}

$parent_id = (int)$_SESSION['parent_id'];

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

try {
  // 0) Resolve ACTIVE school year
  $syStmt = $conn->prepare("SELECT school_year_id FROM school_years WHERE status='active' LIMIT 1");
  $syStmt->execute();
  $syRes = $syStmt->get_result();
  $activeSy = $syRes->fetch_column();
  $syStmt->close();

  if (!$activeSy) {
    echo json_encode(['success' => true, 'announcements' => []]);
    exit;
  }

  // 1) Get all children (students) of this parent
  $kids = [];
  $kidStmt = $conn->prepare("SELECT student_id FROM students WHERE parent_id = ?");
  $kidStmt->bind_param('i', $parent_id);
  $kidStmt->execute();
  $kidRes = $kidStmt->get_result();
  while ($r = $kidRes->fetch_assoc()) $kids[] = (int)$r['student_id'];
  $kidStmt->close();

  if (empty($kids)) {
    echo json_encode(['success' => true, 'announcements' => []]);
    exit;
  }

  // 2) From student_enrollments, collect advisory_ids for ACTIVE school year
  //    (This is the reliable source of class membership.)
  $placeKids = implode(',', array_fill(0, count($kids), '?'));
  $typesKids = str_repeat('i', count($kids));

  $sqlAdvisories = "
    SELECT DISTINCT e.advisory_id
    FROM student_enrollments e
    WHERE e.school_year_id = ?
      AND e.student_id IN ($placeKids)
      AND e.advisory_id IS NOT NULL
  ";
  $stmt = $conn->prepare($sqlAdvisories);
  $bindTypes = 'i' . $typesKids;
  $stmt->bind_param($bindTypes, $activeSy, ...$kids);
  $stmt->execute();
  $rs = $stmt->get_result();
  $advisoryIds = [];
  while ($row = $rs->fetch_assoc()) {
    $advisoryIds[] = (int)$row['advisory_id'];
  }
  $stmt->close();

  if (empty($advisoryIds)) {
    // No advisory memberships found for active SY => no class-bound announcements
    echo json_encode(['success' => true, 'announcements' => []]);
    exit;
  }

  // 3) Fetch announcements for those classes.
  //    Note: Audience filter for PARENT/BOTH; respect visibility window.
  $in = implode(',', array_fill(0, count($advisoryIds), '?'));
  $types = str_repeat('i', count($advisoryIds));

  $sql = "
    SELECT a.id, a.title, a.message, a.audience, a.date_posted, a.visible_until,
           c.class_name, s.subject_name, t.teacher_fullname
    FROM announcements a
    LEFT JOIN advisory_classes c ON a.class_id = c.advisory_id
    LEFT JOIN subjects s ON a.subject_id = s.subject_id
    LEFT JOIN teachers t ON a.teacher_id = t.teacher_id
    WHERE a.class_id IN ($in)
      AND (a.audience IN ('PARENT','BOTH'))
      AND (a.visible_until IS NULL OR a.visible_until >= CURDATE())
    ORDER BY a.date_posted DESC
    LIMIT 200
  ";

  $a = $conn->prepare($sql);
  $a->bind_param($types, ...$advisoryIds);
  $a->execute();
  $res = $a->get_result();

  $rows = [];
  while ($r = $res->fetch_assoc()) {
    $rows[] = [
      'id'            => (int)$r['id'],
      'title'         => (string)$r['title'],
      'message'       => (string)$r['message'],
      'audience'      => (string)$r['audience'],
      'class_name'    => $r['class_name'],
      'subject_name'  => $r['subject_name'],
      'teacher_name'  => $r['teacher_fullname'],
      'date_posted'   => $r['date_posted'],
      'visible_until' => $r['visible_until'],
    ];
  }
  $a->close();

  echo json_encode(['success' => true, 'announcements' => $rows]);
} catch (Throwable $e) {
  echo json_encode(['success' => false, 'message' => 'Server error', 'error' => $e->getMessage()]);
}
