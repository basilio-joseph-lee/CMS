<?php
// admin/approve_request.php
session_start();

require_once __DIR__ . '/../config/admin_guard.php'; // must set $_SESSION['admin_id']
require_once __DIR__ . '/../config/db.php';

function back($flag = '') {
  $qs = $flag ? "&success=$flag" : '';
  echo "<script>location.href='admin.php?page=teachers$qs';</script>";
  exit;
}

$admin_id = (int)($_SESSION['admin_id'] ?? 0);
$request_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$action = isset($_GET['action']) ? strtolower(trim($_GET['action'])) : '';

if (!$admin_id || !$request_id || !in_array($action, ['approve','deny'], true)) {
  back();
}

// fetch request (must be pending)
$stmt = $conn->prepare("
  SELECT r.request_id, r.requester_id, r.advisory_id, r.school_year_id, r.status
  FROM section_access_requests r
  WHERE r.request_id = ? AND r.status = 'pending'
  LIMIT 1
");
$stmt->bind_param("i", $request_id);
$stmt->execute();
$req = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$req) back(); // invalid or already handled

if ($action === 'approve') {
  // Create the view-only table if it doesn't exist yet
  $conn->query("
    CREATE TABLE IF NOT EXISTS teacher_section_access (
      tsa_id INT AUTO_INCREMENT PRIMARY KEY,
      teacher_id INT NOT NULL,
      advisory_id INT NOT NULL,
      school_year_id INT NOT NULL,
      granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      CONSTRAINT uq_tsa UNIQUE (teacher_id, advisory_id, school_year_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

  // Grant view-only access (ignore if already present)
  $ins = $conn->prepare("
    INSERT IGNORE INTO teacher_section_access (teacher_id, advisory_id, school_year_id)
    VALUES (?, ?, ?)
  ");
  $ins->bind_param("iii", $req['requester_id'], $req['advisory_id'], $req['school_year_id']);
  $ins->execute();
  $ins->close();

  // Mark request approved
  $up = $conn->prepare("
    UPDATE section_access_requests
       SET status='approved', reviewed_by=?, reviewed_at=NOW()
     WHERE request_id=?
  ");
  $up->bind_param("ii", $admin_id, $request_id);
  $up->execute();
  $up->close();

  back('approved');

} else { // deny
  $up = $conn->prepare("
    UPDATE section_access_requests
       SET status='denied', reviewed_by=?, reviewed_at=NOW()
     WHERE request_id=?
  ");
  $up->bind_param("ii", $admin_id, $request_id);
  $up->execute();
  $up->close();

  back('denied');
}
