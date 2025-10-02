<?php
session_start();
$conn = new mysqli("localhost", "root", "", "cms");


$request_id = $_GET['id'] ?? null;
$action = $_GET['action'] ?? null;
$reviewer_id = $_SESSION['admin_id'];

if (!$request_id || !in_array($action, ['approve', 'deny'])) {
  die("Invalid request.");
}

$status = $action === 'approve' ? 'approved' : 'denied';

$stmt = $conn->prepare("UPDATE section_access_requests SET status = ?, reviewed_by = ? WHERE request_id = ?");
$stmt->bind_param("sii", $status, $reviewer_id, $request_id);
$stmt->execute();

header("Location: admin.php?page=teachers&success=$status");
?>
