<?php
/**
 * ONE-TIME SCRIPT: Backfill missing faculty_db.faculty records
 *
 * Run this ONCE to fix already-approved faculty_profiles that were
 * approved BEFORE pending-approvals.php auto-created the bridging
 * faculty_db.faculty row. Those profiles are stuck showing "NOT LINKED"
 * on the Peer Evaluation directory / Dean summary / Dept Head summary,
 * even though their account is active and approved.
 *
 * Safe to run more than once - it skips any profile that already has a
 * matching faculty row (by email or faculty_no), so it will never create
 * duplicates.
 *
 * HOW TO RUN:
 *   1. Drop this file anywhere temporarily reachable, e.g.
 *      modules/faculty/scripts/backfill-faculty-links.php
 *   2. Visit it once in the browser while logged in as an admin/dean
 *      (or run via `php backfill-faculty-links.php` on the CLI).
 *   3. Check the printed report.
 *   4. DELETE this file afterward - it's not meant to stay in the app.
 */
require_once __DIR__ . '/../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once __DIR__ . '/../config/database.php';

$pdo = function_exists('facultyDb') ? facultyDb() : null;
if (!$pdo) {
    die('facultyDb() is not available - check modules/faculty/config/database.php.');
}

echo "<pre style='background:#111;color:#0f0;padding:1rem;font-size:13px;'>";
echo "Faculty link backfill starting...\n\n";

// Only touch profiles that are actually approved/active - never invent
// faculty records for pending or rejected requests.
$stmt = $pdo->query("
    SELECT *
    FROM faculty_db.faculty_profiles
    WHERE request_status = 'approved'
       OR profile_status = 'Active'
");
$profiles = $stmt->fetchAll(PDO::FETCH_ASSOC);

$created = 0;
$skippedAlreadyLinked = 0;
$skippedNoEmailOrCode = 0;
$errors = 0;

foreach ($profiles as $fp) {
    $label = "[{$fp['id']}] {$fp['first_name']} {$fp['last_name']} ({$fp['email']})";

    try {
        $emailParam = !empty($fp['email']) ? $fp['email'] : null;

        $checkStmt = $pdo->prepare("
            SELECT faculty_id FROM faculty_db.faculty
            WHERE (:email_check IS NOT NULL AND email = :email_val)
               OR faculty_no = :faculty_no
            LIMIT 1
        ");
        $checkStmt->execute([
            ':email_check' => $emailParam,
            ':email_val'   => $emailParam,
            ':faculty_no'  => $fp['faculty_id'] ?? '',
        ]);

        if ($checkStmt->fetchColumn()) {
            $skippedAlreadyLinked++;
            echo "SKIP  (already linked)  $label\n";
            continue;
        }

        $departmentId = null;
        if (!empty($fp['designated_department'])) {
            $deptStmt = $pdo->prepare("SELECT department_id FROM faculty_db.departments WHERE code = :code LIMIT 1");
            $deptStmt->execute([':code' => $fp['designated_department']]);
            $departmentId = $deptStmt->fetchColumn() ?: null;
        }

        $insertStmt = $pdo->prepare("
            INSERT INTO faculty_db.faculty (
                faculty_no, external_user_id, first_name, middle_name, last_name, suffix,
                birthdate, sex, phone, email, department_id, position,
                academic_rank, employment_status, profile_status, overall_rating,
                hired_date, contractual_end_date, created_at, updated_at
            ) VALUES (
                :faculty_no, :external_user_id, :first_name, :middle_name, :last_name, :suffix,
                :birthdate, :sex, :phone, :email, :department_id, :position,
                'instructor', :employment_status, 'Active', 0.00,
                :hired_date, :contractual_end_date, NOW(), NOW()
            )
        ");
        $insertStmt->execute([
            ':faculty_no'           => $fp['faculty_id'] ?? null,
            ':external_user_id'     => (string) ($fp['user_id'] ?? ''),
            ':first_name'           => $fp['first_name'] ?? '',
            ':middle_name'          => $fp['middle_name'] ?? null,
            ':last_name'            => $fp['last_name'] ?? '',
            ':suffix'               => $fp['suffix'] ?? null,
            ':birthdate'            => !empty($fp['birthdate']) ? $fp['birthdate'] : null,
            ':sex'                  => !empty($fp['sex']) ? strtolower($fp['sex']) : null,
            ':phone'                => $fp['phone'] ?? null,
            ':email'                => $fp['email'] ?? null,
            ':department_id'        => $departmentId,
            ':position'             => $fp['position'] ?? null,
            ':employment_status'    => $fp['employment_status'] ?: 'Probationary',
            ':hired_date'           => !empty($fp['hired_date']) ? $fp['hired_date'] : null,
            ':contractual_end_date' => !empty($fp['contractual_end']) ? $fp['contractual_end'] : (!empty($fp['contractual_end_date']) ? $fp['contractual_end_date'] : null),
        ]);

        $created++;
        echo "CREATED faculty_id=" . $pdo->lastInsertId() . "  $label\n";
    } catch (Throwable $e) {
        $errors++;
        echo "ERROR   $label -> " . $e->getMessage() . "\n";
    }
}

echo "\n----------------------------------------\n";
echo "Profiles scanned:     " . count($profiles) . "\n";
echo "Faculty rows created: $created\n";
echo "Already linked:       $skippedAlreadyLinked\n";
echo "Errors:                $errors\n";
echo "----------------------------------------\n";
echo "\nDone. Delete this file now.\n";
echo "</pre>";