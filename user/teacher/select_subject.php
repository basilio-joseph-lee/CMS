<?php
/**
 * teacher/select_subject.php
 * - Shows subjects for active school year
 * - When a subject is clicked (via GET), saves context to session
 *   and (for students) auto-logs attendance based on schedule_timeblocks.
 */

session_start();
date_default_timezone_set('Asia/Manila');

// --- Require a logged-in user (teacher or student) ---
if (!isset($_SESSION['teacher_id']) && !isset($_SESSION['student_id'])) {
    header("Location: index.php");
    exit;
}

// --- DB ---
include '../../config/db.php';
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset('utf8mb4');

// ======================================================================
// 1) If user clicked a subject card, capture via GET, set session,
//    then (if student) auto-log attendance and redirect.
// ======================================================================
if (
    isset(
        $_GET['subject_id'],
        $_GET['subject_name'],
        $_GET['class_name'],
        $_GET['year_label'],
        $_GET['advisory_id'],
        $_GET['school_year_id']
    )
) {
    // Sanitize / normalize
    $_SESSION['subject_id']     = (int)$_GET['subject_id'];
    $_SESSION['subject_name']   = $_GET['subject_name'];
    $_SESSION['class_name']     = $_GET['class_name'];
    $_SESSION['year_label']     = $_GET['year_label'];
    $_SESSION['advisory_id']    = (int)$_GET['advisory_id'];
    $_SESSION['school_year_id'] = (int)$_GET['school_year_id'];

    // ---------------- Attendance Auto-Check (student only) ----------------
    if (isset($_SESSION['student_id'])) {
        $studentId = (int)$_SESSION['student_id'];
        $advisoryId = (int)$_SESSION['advisory_id'];
        $subjectId  = (int)$_SESSION['subject_id'];
        $syId       = (int)$_SESSION['school_year_id'];

        $now     = new DateTime('now');
        $today   = $now->format('Y-m-d');
        $nowTime = $now->format('H:i:s');
        $dow     = (int)$now->format('N'); // 1=Mon..7=Sun

        // Prevent duplicate attendance for the same date
        $dupe = $conn->prepare("
            SELECT 1
            FROM attendance_records
            WHERE student_id=? AND subject_id=? AND advisory_id=? AND school_year_id=?
              AND DATE(`timestamp`) = CURDATE()
            LIMIT 1
        ");
        $dupe->bind_param("iiii", $studentId, $subjectId, $advisoryId, $syId);
        $dupe->execute();
        $alreadyLoggedToday = $dupe->get_result()->num_rows > 0;
        $dupe->close();

        if (!$alreadyLoggedToday) {
            // Find today's active schedule block for this subject/section
            $stmt = $conn->prepare("
                SELECT start_time, end_time
                FROM schedule_timeblocks
                WHERE school_year_id=? AND advisory_id=? AND subject_id=?
                  AND day_of_week=? AND active_flag=1
                ORDER BY start_time ASC
                LIMIT 1
            ");
            $stmt->bind_param("iiii", $syId, $advisoryId, $subjectId, $dow);
            $stmt->execute();
            $blk = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($blk) {
                $startTs = DateTime::createFromFormat('Y-m-d H:i:s', $today.' '.$blk['start_time']);
                $endTs   = DateTime::createFromFormat('Y-m-d H:i:s', $today.' '.$blk['end_time']);

                // Rules
                $LATE_GRACE_MIN  = 5;     // <=5 mins late => still "Present"
                $ABSENT_IF_AFTER = true;  // If login is after class end -> "Absent"

                $status = null;

                if ($now >= $startTs && $now <= $endTs) {
                    $diffMin = (int)floor(($now->getTimestamp() - $startTs->getTimestamp()) / 60);
                    $status  = ($diffMin <= $LATE_GRACE_MIN) ? 'Present' : 'Late';
                } elseif ($ABSENT_IF_AFTER && $now > $endTs) {
                    $status = 'Absent';
                } else {
                    // Before class start or no clear state => don't log anything yet
                    $status = null;
                }

                if ($status) {
                    // HARD GUARD: Insert only if no record exists for today
                    $ins = $conn->prepare("
                        INSERT INTO attendance_records
                            (student_id, subject_id, advisory_id, school_year_id, status, `timestamp`)
                        SELECT ?, ?, ?, ?, ?, NOW()
                        FROM DUAL
                        WHERE NOT EXISTS (
                            SELECT 1
                            FROM attendance_records
                            WHERE student_id    = ?
                              AND subject_id     = ?
                              AND advisory_id    = ?
                              AND school_year_id = ?
                              AND DATE(`timestamp`) = CURDATE()
                        )
                    ");
                    $ins->bind_param(
                        'iiiisiiii',
                        $studentId, $subjectId, $advisoryId, $syId, $status,
                        $studentId, $subjectId, $advisoryId, $syId
                    );
                    $ins->execute();
                    $ins->close();
                }
            }
        }
    }

    // ---------------- Redirect by role ----------------
    if (isset($_SESSION['teacher_id'])) {
        header("Location: ../teacher/teacher_dashboard.php");
        exit;
    } elseif (isset($_SESSION['student_id'])) {
        header("Location: ../dashboard.php?new_login=1");
        exit;
    } else {
        $_SESSION['error'] = "No user role detected.";
        header("Location: index.php");
        exit;
    }
}

// ======================================================================
// 2) Load active school year and list of subjects for chooser
// ======================================================================

$active_sy_id = null;
$current_year = '';
$subjects = [];

$sy_sql = "SELECT school_year_id, year_label FROM school_years WHERE status='active' LIMIT 1";
if ($res = $conn->query($sy_sql)) {
    if ($row = $res->fetch_assoc()) {
        $active_sy_id = (int)$row['school_year_id'];
        $current_year = $row['year_label'];
    }
    $res->free();
}

if ($active_sy_id) {
    if (isset($_SESSION['teacher_id'])) {
        $teacherId = (int)$_SESSION['teacher_id'];

        $sql = "SELECT 
                    s.subject_id,
                    s.subject_name,
                    s.advisory_id,
                    s.school_year_id,
                    ac.class_name,
                    sy.year_label
                FROM subjects s
                JOIN advisory_classes ac 
                  ON ac.advisory_id = s.advisory_id
                 AND ac.school_year_id = s.school_year_id
                JOIN school_years sy 
                  ON sy.school_year_id = s.school_year_id
                WHERE s.teacher_id = ?
                  AND s.school_year_id = ?
                ORDER BY ac.class_name ASC, s.subject_name ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $teacherId, $active_sy_id);
    } else {
        // student
        $studentId = (int)$_SESSION['student_id'];
        $sql = "SELECT 
                    s.subject_id,
                    s.subject_name,
                    s.advisory_id,
                    s.school_year_id,
                    ac.class_name,
                    sy.year_label
                FROM student_enrollments se
                JOIN subjects s 
                  ON s.subject_id = se.subject_id
                 AND s.school_year_id = se.school_year_id
                JOIN advisory_classes ac 
                  ON ac.advisory_id = se.advisory_id
                 AND ac.school_year_id = se.school_year_id
                JOIN school_years sy 
                  ON sy.school_year_id = se.school_year_id
                WHERE se.student_id = ?
                  AND se.school_year_id = ?
                ORDER BY ac.class_name ASC, s.subject_name ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $studentId, $active_sy_id);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $subjects[] = $row; }
    $stmt->close();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Classroom Subject Selector</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body {
      background-image: url('../../img/1.png');
      background-size: cover;
      background-position: center;
      font-family: 'Comic Sans MS', cursive, sans-serif;
    }
    .card {
      background: rgba(255, 255, 255, 0.8);
      border-radius: 1.5rem;
      box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
      transition: all 0.3s ease-in-out;
    }
    .card:hover {
      transform: scale(1.05);
      box-shadow: 0 12px 25px rgba(0, 0, 0, 0.25);
      border-color: #60a5fa;
    }
  </style>
</head>
<body class="min-h-screen flex flex-col items-center px-4 py-8">

  <h1 class="text-4xl font-bold text-white drop-shadow mb-2">📚 Select Your Subject</h1>
  <p class="text-xl text-white mb-6">
    🗓️ School Year:
    <span class="font-semibold">
      <?= htmlspecialchars($current_year ?: 'No active school year') ?>
    </span>
  </p>

  <?php if ($active_sy_id && count($subjects) > 0): ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6 w-full max-w-6xl">
      <?php foreach ($subjects as $row): ?>
        <div
          onclick="location.href='?subject_id=<?= (int)$row['subject_id'] ?>&subject_name=<?= urlencode($row['subject_name']) ?>&class_name=<?= urlencode($row['class_name']) ?>&year_label=<?= urlencode($row['year_label']) ?>&advisory_id=<?= (int)$row['advisory_id'] ?>&school_year_id=<?= (int)$row['school_year_id'] ?>'"
          class="card cursor-pointer p-6 border-4 border-yellow-300 hover:border-blue-400"
        >
          <h2 class="text-2xl font-bold text-gray-800 mb-2">
            <?= htmlspecialchars($row['subject_name']) ?>
          </h2>
          <p class="text-gray-700 text-lg">
            📘 Section: <span class="font-semibold text-blue-600">
              <?= htmlspecialchars($row['class_name']) ?>
            </span>
          </p>
          <p class="text-sm text-gray-600 italic mt-1">Tap to select</p>
        </div>
      <?php endforeach; ?>
    </div>
  <?php elseif ($active_sy_id && count($subjects) === 0): ?>
    <div class="bg-white/90 rounded-xl p-6 shadow max-w-xl text-center">
      <p class="text-lg">No subjects/sections are listed for the active school year <strong><?= htmlspecialchars($current_year) ?></strong>.</p>
      <p class="text-sm text-gray-600 mt-2">Make sure subjects are assigned to the teacher/student for this school year.</p>
    </div>
  <?php else: ?>
    <div class="bg-white/90 rounded-xl p-6 shadow max-w-xl text-center">
      <p class="text-lg">Walang <strong>active</strong> na school year. I‑set muna ang status sa <code>school_years.status = 'active'</code>.</p>
    </div>
  <?php endif; ?>

</body>
</html>
