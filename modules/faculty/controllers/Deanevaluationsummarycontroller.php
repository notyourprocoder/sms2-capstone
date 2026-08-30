<?php
/**
 * DeanEvaluationSummaryController
 *
 * Builds all the data needed by
 * modules/faculty/views/dean/evaluation-summary.php.
 *
 * This is the Dean's counterpart to the Department Head "Evaluation
 * Summary" page (currently inline in
 * modules/faculty/views/department-head/evaluation-summary.php). Same
 * 50% Student / 30% Peer / 20% Department-Head weighted composite and
 * the same department-wide overview stats - the only real difference is
 * WHOSE department drives the filter: here it's the department currently
 * assigned to the logged-in Dean, not a Department Head.
 *
 * IMPORTANT: faculty_profiles.id is NOT the same identifier as
 * faculty.faculty_id. The evaluations table's faculty_id column is a
 * foreign key into faculty.faculty_id, so every evaluation lookup below
 * must use the bridged "real_faculty_id", never fp.id directly - otherwise
 * every average comes back as zero. Same bridging logic used in
 * modules/faculty/views/department-head/evaluation-summary.php and
 * modules/faculty/views/faculty/peer-evaluation.php: prefer an email
 * match (unambiguous), fall back to faculty_no.
 */

if (!function_exists('getRatingLabel')) {
    function getRatingLabel($score)
    {
        $num = (float) $score;
        if ($num >= 4.50) return '5 - Outstanding';
        if ($num >= 3.50) return '4 - Very Satisfactory';
        if ($num >= 2.50) return '3 - Satisfactory';
        if ($num >= 1.50) return '2 - Average';
        if ($num > 0)     return '1 - Needs Improvement';
        return 'No Rating';
    }
}

/**
 * Fetch and compute everything the Dean's Evaluation Summary view needs.
 *
 * @param  PDO   $pdo
 * @return array{
 *   deanDept: string,
 *   isDean: bool,
 *   deanName: string,
 *   facultyMembers: array,
 *   performanceDB: array,
 *   totalFacultyCount: int,
 *   evaluatedFacultyCount: int,
 *   fullyEvaluatedCount: int,
 *   deptOverallAverage: float,
 *   deptStudentAverage: float,
 *   deptPeerAverage: float,
 *   deptHeadAverage: float,
 *   deptStudentScores: array,
 *   deptPeerScores: array,
 *   deptHeadScores: array,
 *   deptPeerRatedCount: int,
 *   topPerformer: ?array,
 *   topPerformerScore: float,
 *   needsAttentionList: array,
 *   needsAttentionCount: int,
 * }
 */
function getDeanEvaluationSummaryData(PDO $pdo): array
{
    // 1. Identify the logged-in Dean and the department currently
    //    assigned to them.
    //
    //    Matches the session-resolution pattern already proven in
    //    Processdeptheadevaluationcontroller.php: try the numeric user id
    //    first (user_id, or the profile's own id if that's what the login
    //    flow stored), then fall back to email. A single "user_id ?? id"
    //    guess isn't enough if the actual login session stores the
    //    identifier under a different key or only stores the email -
    //    in that case the lookup below silently finds nothing, $deanDept
    //    stays empty, and the page shows no faculty even though the DB
    //    row is perfectly correct.
    $sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
    $sessionEmail  = $_SESSION['user_email'] ?? $_SESSION['email'] ?? null;

    $currentUserId = $sessionUserId;
    $deanDept      = null;
    $deanPosition  = null;
    $deanName      = '';

    if ($sessionUserId || $sessionEmail) {
        try {
            $stmt = $pdo->prepare("
                SELECT id, user_id, designated_department, position, first_name, last_name
                FROM faculty_profiles
                WHERE user_id = :uid1
                   OR id = :uid2
                   OR (:email1 IS NOT NULL AND email = :email2)
                LIMIT 1
            ");
            $stmt->execute([
                'uid1'   => $sessionUserId,
                'uid2'   => $sessionUserId,
                'email1' => $sessionEmail,
                'email2' => $sessionEmail,
            ]);
            $row = $stmt->fetch();
            if ($row) {
                $deanDept      = trim($row['designated_department'] ?? '');
                $deanPosition  = trim($row['position'] ?? '');
                $deanName      = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                // Use whichever id we actually matched on so the "exclude
                // myself from the list" filter below works even if the
                // session only gave us an email.
                $currentUserId = (int) $row['id'];
            }
        } catch (PDOException $e) {
            $deanDept = null;
        }
    }

    // Fallback, same pattern as the Department Head page, in case the
    // department was stashed directly on the session instead.
    if (empty($deanDept)) {
        $deanDept = trim($_SESSION['department'] ?? $_SESSION['designated_department'] ?? '');
    }

    $isDean = $deanPosition !== null && strcasecmp($deanPosition, 'Dean') === 0;

    // 2. Fetch faculty members (Department Heads, Faculty Professors,
    //    Secretaries, etc.) from the SAME department as the Dean only.
    //    The Dean's own profile and any other "Dean" position records are
    //    excluded - a Dean does not appear in their own summary list.
    $facultyMembers = [];

    $facultyQuerySql = "
        SELECT fp.id, fp.faculty_id AS profile_faculty_no, fp.first_name, fp.last_name,
               fp.designated_department, fp.position, fp.email,
               f.faculty_id AS real_faculty_id
        FROM faculty_profiles fp
        LEFT JOIN faculty f ON f.faculty_id = (
            SELECT f2.faculty_id
            FROM faculty f2
            WHERE (fp.email IS NOT NULL AND fp.email <> '' AND f2.email = fp.email)
               OR f2.faculty_no = fp.faculty_id
            ORDER BY (fp.email IS NOT NULL AND fp.email <> '' AND f2.email = fp.email) DESC
            LIMIT 1
        )
    ";

    if (!empty($deanDept)) {
        $stmt = $pdo->prepare($facultyQuerySql . "
            WHERE LOWER(TRIM(fp.designated_department)) = LOWER(:dept)
              AND LOWER(TRIM(COALESCE(fp.position, ''))) <> 'dean'
              AND fp.id <> :selfId
            ORDER BY fp.last_name ASC
        ");
        $stmt->execute(['dept' => $deanDept, 'selfId' => (int) $currentUserId]);
        $facultyMembers = $stmt->fetchAll();
    }

    // 3. Compute the 50/30/20 Student/Peer/DeptHead weighted composite for
    //    every faculty member under the Dean's department.
    $performanceDB = [];

    foreach ($facultyMembers as $fac) {
        $facId         = $fac['id']; // faculty_profiles.id - UI/selection key only
        $realFacultyId = $fac['real_faculty_id'] !== null ? (int) $fac['real_faculty_id'] : null;
        $isLinked      = $realFacultyId !== null;

        $studentAvg = 0; $studentCount = 0;
        $peerAvg    = 0; $peerCount    = 0;
        $headAvg    = 0; $headCount    = 0;

        if ($isLinked) {
            $stmtStud = $pdo->prepare("
                SELECT COALESCE(AVG(composite_score), 0) AS avg_score, COUNT(evaluation_id) AS total_evals
                FROM evaluations
                WHERE faculty_id = :fac_id AND source_type = 'Student'
            ");
            $stmtStud->execute(['fac_id' => $realFacultyId]);
            $studData     = $stmtStud->fetch();
            $studentAvg   = (float) ($studData['avg_score'] ?? 0);
            $studentCount = (int) ($studData['total_evals'] ?? 0);

            $stmtPeer = $pdo->prepare("
                SELECT COALESCE(AVG(composite_score), 0) AS avg_score, COUNT(evaluation_id) AS total_evals
                FROM evaluations
                WHERE faculty_id = :fac_id AND source_type = 'Peer'
            ");
            $stmtPeer->execute(['fac_id' => $realFacultyId]);
            $peerData  = $stmtPeer->fetch();
            $peerAvg   = (float) ($peerData['avg_score'] ?? 0);
            $peerCount = (int) ($peerData['total_evals'] ?? 0);

            $stmtHead = $pdo->prepare("
                SELECT COALESCE(AVG(composite_score), 0) AS avg_score, COUNT(evaluation_id) AS total_evals
                FROM evaluations
                WHERE faculty_id = :fac_id AND source_type = 'DeptHead'
            ");
            $stmtHead->execute(['fac_id' => $realFacultyId]);
            $headData  = $stmtHead->fetch();
            $headAvg   = (float) ($headData['avg_score'] ?? 0);
            $headCount = (int) ($headData['total_evals'] ?? 0);
        }

        // Sources with zero evaluations are excluded and the remaining
        // weights are renormalized, so a faculty member missing one source
        // isn't dragged down by a phantom 0.
        $weightedSources = [
            ['avg' => $studentAvg, 'count' => $studentCount, 'weight' => 0.50],
            ['avg' => $peerAvg,    'count' => $peerCount,    'weight' => 0.30],
            ['avg' => $headAvg,    'count' => $headCount,    'weight' => 0.20],
        ];
        $weightedSum = 0; $weightTotal = 0;
        foreach ($weightedSources as $src) {
            if ($src['count'] > 0) {
                $weightedSum += $src['avg'] * $src['weight'];
                $weightTotal += $src['weight'];
            }
        }
        $composite = $weightTotal > 0 ? $weightedSum / $weightTotal : 0;

        // Remarks
        $commentsRaw = [];
        if ($isLinked) {
            $stmtComments = $pdo->prepare("
                SELECT ef.strength_comment, ef.improvement_comment, e.source_type
                FROM evaluations e
                INNER JOIN evaluation_feedback ef ON ef.evaluation_id = e.evaluation_id
                WHERE e.faculty_id = :fac_id
                  AND (
                      (ef.strength_comment IS NOT NULL AND TRIM(ef.strength_comment) != '')
                      OR (ef.improvement_comment IS NOT NULL AND TRIM(ef.improvement_comment) != '')
                  )
                ORDER BY e.submitted_at DESC
                LIMIT 10
            ");
            $stmtComments->execute(['fac_id' => $realFacultyId]);
            $commentsRaw = $stmtComments->fetchAll();
        }

        $studentComments = [];
        $peerComments    = [];
        $headComments    = [];
        foreach ($commentsRaw as $c) {
            $parts = [];
            if (!empty(trim((string) $c['strength_comment']))) {
                $parts[] = 'Strength: ' . htmlspecialchars($c['strength_comment']);
            }
            if (!empty(trim((string) $c['improvement_comment']))) {
                $parts[] = 'To improve: ' . htmlspecialchars($c['improvement_comment']);
            }
            $line = implode(' | ', $parts);

            if ($c['source_type'] === 'Peer') {
                $peerComments[] = ['strong' => $line];
            } elseif ($c['source_type'] === 'DeptHead') {
                $headComments[] = ['strong' => $line];
            } else {
                $studentComments[] = ['strong' => $line];
            }
        }
        if (empty($studentComments)) $studentComments[] = ['strong' => 'No student comments recorded yet.'];
        if (empty($peerComments))    $peerComments[]    = ['strong' => 'No peer comments recorded yet.'];
        if (empty($headComments))    $headComments[]    = ['strong' => 'No department head comments recorded yet.'];

        $fullName = 'Prof. ' . $fac['first_name'] . ' ' . $fac['last_name'];
        $initials = strtoupper(substr($fac['first_name'], 0, 1) . substr($fac['last_name'], 0, 1));

        $performanceDB[$facId] = [
            'name'            => $fullName,
            'department'      => $fac['designated_department'] ?? 'N/A',
            'position'        => $fac['position'] ?? 'N/A',
            'initials'        => $initials,
            'isLinked'        => $isLinked,
            'compositeScore'  => number_format($composite, 2),
            'compositeRating' => getRatingLabel($composite),
            'totalEvals'      => $studentCount + $peerCount + $headCount,
            'sources' => [
                'student' => [
                    'score'      => number_format($studentAvg, 2),
                    'ratingText' => getRatingLabel($studentAvg),
                    'evalCount'  => $studentCount,
                    'feedback'   => $studentComments,
                ],
                'peer' => [
                    'score'      => number_format($peerAvg, 2),
                    'ratingText' => getRatingLabel($peerAvg),
                    'evalCount'  => $peerCount,
                    'feedback'   => $peerComments,
                ],
                'head' => [
                    'score'      => number_format($headAvg, 2),
                    'ratingText' => getRatingLabel($headAvg),
                    'evalCount'  => $headCount,
                    'feedback'   => $headComments,
                ],
            ],
        ];
    }

    // 4. College/department-wide overview stats. A faculty member only
    //    counts toward a given average once they actually have at least
    //    one evaluation of that type - otherwise a not-yet-rated faculty
    //    member would silently drag the average down as a phantom 0.
    $totalFacultyCount = count($performanceDB);
    $deptOverallScores = [];
    $deptStudentScores = [];
    $deptPeerScores    = [];
    $deptHeadScores    = [];

    foreach ($performanceDB as $facId => $data) {
        if ((int) $data['totalEvals'] > 0) {
            $deptOverallScores[$facId] = (float) $data['compositeScore'];
        }
        if ((int) $data['sources']['student']['evalCount'] > 0) {
            $deptStudentScores[$facId] = (float) $data['sources']['student']['score'];
        }
        if ((int) $data['sources']['peer']['evalCount'] > 0) {
            $deptPeerScores[$facId] = (float) $data['sources']['peer']['score'];
        }
        if ((int) $data['sources']['head']['evalCount'] > 0) {
            $deptHeadScores[$facId] = (float) $data['sources']['head']['score'];
        }
    }

    $evaluatedFacultyCount = count($deptOverallScores);

    // "Fully Evaluated" requires ALL THREE sources to each have at least
    // one evaluation recorded - stricter than $evaluatedFacultyCount above.
    $fullyEvaluatedCount = 0;
    foreach ($performanceDB as $data) {
        $sc = (int) $data['sources']['student']['evalCount'];
        $pc = (int) $data['sources']['peer']['evalCount'];
        $hc = (int) $data['sources']['head']['evalCount'];
        if ($sc > 0 && $pc > 0 && $hc > 0) {
            $fullyEvaluatedCount++;
        }
    }

    $deptOverallAverage = !empty($deptOverallScores) ? array_sum($deptOverallScores) / count($deptOverallScores) : 0;
    $deptStudentAverage = !empty($deptStudentScores) ? array_sum($deptStudentScores) / count($deptStudentScores) : 0;
    $deptPeerAverage    = !empty($deptPeerScores)    ? array_sum($deptPeerScores)    / count($deptPeerScores)    : 0;
    $deptHeadAverage    = !empty($deptHeadScores)    ? array_sum($deptHeadScores)    / count($deptHeadScores)    : 0;
    $deptPeerRatedCount = count($deptPeerScores);

    // Top performer by TRUE overall composite (50/30/20), not just one source.
    $topPerformerId    = null;
    $topPerformerScore = -1;
    foreach ($deptOverallScores as $facId => $score) {
        if ($score > $topPerformerScore) {
            $topPerformerScore = $score;
            $topPerformerId    = $facId;
        }
    }
    $topPerformer = $topPerformerId !== null ? $performanceDB[$topPerformerId] : null;

    // Faculty trending below "Very Satisfactory" (3.50) on their composite.
    $needsAttentionThreshold = 3.50;
    $needsAttentionList      = [];
    foreach ($deptOverallScores as $facId => $score) {
        if ($score < $needsAttentionThreshold) {
            $needsAttentionList[] = ['name' => $performanceDB[$facId]['name'], 'score' => $score];
        }
    }
    usort($needsAttentionList, fn($a, $b) => $a['score'] <=> $b['score']);

    return [
        'deanDept'              => $deanDept,
        'isDean'                => $isDean,
        'deanName'              => $deanName,
        'facultyMembers'        => $facultyMembers,
        'performanceDB'         => $performanceDB,
        'totalFacultyCount'     => $totalFacultyCount,
        'evaluatedFacultyCount' => $evaluatedFacultyCount,
        'fullyEvaluatedCount'   => $fullyEvaluatedCount,
        'deptOverallAverage'    => $deptOverallAverage,
        'deptStudentAverage'    => $deptStudentAverage,
        'deptPeerAverage'       => $deptPeerAverage,
        'deptHeadAverage'       => $deptHeadAverage,
        'deptStudentScores'     => $deptStudentScores,
        'deptPeerScores'        => $deptPeerScores,
        'deptHeadScores'        => $deptHeadScores,
        'deptPeerRatedCount'    => $deptPeerRatedCount,
        'topPerformer'          => $topPerformer,
        'topPerformerScore'     => $topPerformerScore,
        'needsAttentionList'    => $needsAttentionList,
        'needsAttentionCount'   => count($needsAttentionList),
    ];
}