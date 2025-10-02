<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['teacher_id'])) { http_response_code(403); echo json_encode(['students'=>[]]); exit; }

$teacher_id = intval($_SESSION['teacher_id']);
$sy = intval($_SESSION['school_year_id'] ?? 0);
$ad = intval($_SESSION['advisory_id']    ?? 0);
$sj = intval($_SESSION['subject_id']     ?? 0);

$conn = new mysqli("localhost","root","","cms");
if ($conn->connect_error) { http_response_code(500); echo json_encode(['students'=>[]]); exit; }

/* If advisory/subject not set (or wrong), derive the pair for this teacher from active SY */
if (!$ad || !$sj) {
  $q = $conn->prepare("
    SELECT s.advisory_id, s.subject_id, s.school_year_id
    FROM subjects s
    JOIN school_years y ON y.school_year_id = s.school_year_id AND y.status='active'
    WHERE s.teacher_id = ?
    ORDER BY s.subject_id DESC
    LIMIT 1
  ");
  $q->bind_param("i",$teacher_id);
  $q->execute();
  $q->bind_result($ad2,$sj2,$sy2);
  if ($q->fetch()) { $ad = $ad2; $sj = $sj2; $sy = $sy2; }
  $q->close();
}

/* Save back to session so the rest of the app sees the correct class */
$_SESSION['school_year_id'] = $sy;
$_SESSION['advisory_id']    = $ad;
$_SESSION['subject_id']     = $sj;

$sql = "
SELECT DISTINCT s.student_id, s.fullname, s.avatar_path
FROM students s
JOIN student_enrollments se ON se.student_id = s.student_id
WHERE se.school_year_id=? AND se.advisory_id=? AND se.subject_id=?
ORDER BY s.fullname ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iii", $sy, $ad, $sj);
$stmt->execute();
$res = $stmt->get_result();

/* Normalize avatar paths to web paths */
function normalize_path($p) {
  if (!$p) return null;
  $p = trim(str_replace('\\','/',$p));
  $p = preg_replace('#^https?://[^/]+#','',$p);
  if ($p === '') return null;
  if ($p[0] !== '/') $p = '/'.$p;
  $p = preg_replace('#/+#','/',$p);
  if (strpos($p, '/CMS/') !== 0) $p = '/CMS'.$p;
  $p = str_replace('/CMS/CMS/','/CMS/',$p);
  return $p;
}

$rows = [];
while ($r = $res->fetch_assoc()) {
  $rows[] = [
    'student_id' => (int)$r['student_id'],
    'fullname'   => $r['fullname'],
    'avatar_url' => normalize_path($r['avatar_path']) ?: '/CMS/img/empty_desk.png',
  ];
}
$stmt->close();
$conn->close();

echo json_encode(['students'=>$rows, 'meta'=>['sy'=>$sy,'ad'=>$ad,'sj'=>$sj]]);
