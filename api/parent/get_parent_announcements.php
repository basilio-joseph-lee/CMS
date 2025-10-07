<?php
// /CMS/api/parent/get_parent_announcements.php  (local)
// https://myschoolness.site/api/parent/get_parent_announcements.php  (prod)

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php'; // up to /config/db.php

if (!isset($_SESSION['parent_id'])) {
  echo json_encode(['success' => false, 'message' => 'Unauthorized']);
  exit;
}

$parent_id = (int)$_SESSION['parent_id'];

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

try {
  // Collect distinct advisory classes (sections) for this parent's children
  $q = $conn->prepare("
    SELECT DISTINCT s.advisory_id
    FROM students s
    WHERE s.parent_id = ?
      AND s.advisory_id IS NOT NULL
  ");
  $q->bind_param('i', $parent_id);
  $q->execute();
  $rs = $q->get_result();
  $advisoryIds = [];
  while ($r = $rs->fetch_assoc()) {
    $advisoryIds[] = (int)$r['advisory_id'];
  }
  $q->close();

  if (!$advisoryIds) {
    echo json_encode(['success' => true, 'announcements' => []]);
    exit;
  }

  // Build IN clause
  $placeholders = implode(',', array_fill(0, count($advisoryIds), '?'));
  $types = str_repeat('i', count($advisoryIds));

  $sql = "
    SELECT a.id, a.title, a.message, a.audience, a.date_posted, a.visible_until,
           c.class_name, s.subject_name, t.teacher_fullname
    FROM announcements a
    LEFT JOIN advisory_classes c ON a.class_id = c.advisory_id
    LEFT JOIN subjects s ON a.subject_id = s.subject_id
    LEFT JOIN teachers t ON a.teacher_id = t.teacher_id
    WHERE a.class_id IN ($placeholders)
      AND a.audience IN ('PARENT','BOTH')
      AND (a.visible_until IS NULL OR a.visible_until >= CURDATE())
    ORDER BY a.date_posted DESC
    LIMIT 200
  ";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param($types, ...$advisoryIds);
  $stmt->execute();
  $res = $stmt->get_result();

  $rows = [];
  while ($r = $res->fetch_assoc()) {
    $rows[] = [
      'id'            => (int)$r['id'],
      'title'         => $r['title'],
      'message'       => $r['message'],
      'audience'      => $r['audience'],
      'class_name'    => $r['class_name'],
      'subject_name'  => $r['subject_name'],
      'teacher_name'  => $r['teacher_fullname'],
      'date_posted'   => $r['date_posted'],
      'visible_until' => $r['visible_until']
    ];
  }
  $stmt->close();

  echo json_encode(['success' => true, 'announcements' => $rows]);
} catch (Throwable $e) {
  echo json_encode(['success' => false, 'message' => 'Server error', 'error' => $e->getMessage()]);
}
