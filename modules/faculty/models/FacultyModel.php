<?php
/**
 * SMS 2 - Faculty Model
 * Path: modules/faculty/models/FacultyModel.php
 */

if (!class_exists('FacultyModel')) {

class FacultyModel {
    private $db;

    public function __construct($pdoConnection = null) {
        if ($pdoConnection instanceof \PDO) {
            $this->db = $pdoConnection;
        } elseif (function_exists('facultyDb') && facultyDb() instanceof \PDO) {
            $this->db = facultyDb();
        } elseif (function_exists('db') && db() instanceof \PDO) {
            $this->db = db();
        } else {
            $this->db = null;
        }
    }

    /**
     * Ensure active PDO instance before running queries
     */
    private function ensureDb() {
        if (!$this->db) {
            if (function_exists('facultyDb') && facultyDb() instanceof \PDO) {
                $this->db = facultyDb();
            } elseif (function_exists('db') && db() instanceof \PDO) {
                $this->db = db();
            } else {
                throw new \Exception("Database connection is missing or could not be established.");
            }
        }
    }

    /**
     * Load faculty members for a specific department
     */
    public function getDepartmentMembers($department) {
        $this->ensureDb();
        $stmt = $this->db->prepare("
            SELECT *, designated_department AS designated_dept 
            FROM faculty_db.faculty_profiles 
            WHERE LOWER(TRIM(designated_department)) = LOWER(TRIM(:dept))
              AND (request_status IS NULL OR request_status = 'approved')
            ORDER BY last_name ASC, first_name ASC
        ");
        $stmt->execute([':dept' => $department]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Fetch a single faculty profile linked to a user account ID
     */
    public function getProfileByUserId($userId) {
        $this->ensureDb();
        $stmt = $this->db->prepare("
            SELECT *, designated_department AS designated_dept 
            FROM faculty_db.faculty_profiles 
            WHERE user_id = :user_id 
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $userId]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Fetch a single faculty profile by primary key ID or faculty_id string
     */
    public function getProfileById($id) {
        $this->ensureDb();
        $stmt = $this->db->prepare("
            SELECT *, designated_department AS designated_dept 
            FROM faculty_db.faculty_profiles 
            WHERE id = :id OR faculty_id = :id 
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Load all faculty profiles for Directory / Management views
     */
    public function getAllProfiles() {
        $this->ensureDb();
        $stmt = $this->db->query("
            SELECT *, designated_department AS designated_dept 
            FROM faculty_db.faculty_profiles 
            ORDER BY last_name ASC, first_name ASC
        ");
        return $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
    }

    /**
     * Load faculty profiles filtered by department
     */
    public function getProfilesByDepartment($deptId) {
        $this->ensureDb();
        
        if (empty($deptId) || $deptId === '1' || $deptId === 1) {
            return $this->getAllProfiles();
        }

        $stmt = $this->db->prepare("
            SELECT *, designated_department AS designated_dept 
            FROM faculty_db.faculty_profiles 
            WHERE LOWER(TRIM(designated_department)) = LOWER(TRIM(:dept))
               OR designated_department = :dept
            ORDER BY last_name ASC, first_name ASC
        ");
        $stmt->execute([':dept' => $deptId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Save or update a faculty profile record
     */
    public function saveProfile($data) {
        $this->ensureDb();
        $department = $data['designated_department'] ?? $data['designated_dept'] ?? null;

        if (!empty($data['id'])) {
            $stmt = $this->db->prepare("
                UPDATE faculty_db.faculty_profiles SET
                    first_name = :first_name,
                    middle_name = :middle_name,
                    last_name = :last_name,
                    suffix = :suffix,
                    sex = :sex,
                    birthdate = :birthdate,
                    phone = :phone,
                    email = :email,
                    designated_department = :designated_department,
                    position = :position,
                    profile_status = :profile_status,
                    request_status = :request_status,
                    user_id = :user_id
                WHERE id = :id
            ");
            return $stmt->execute([
                ':first_name'            => $data['first_name'] ?? null,
                ':middle_name'           => $data['middle_name'] ?? null,
                ':last_name'             => $data['last_name'] ?? null,
                ':suffix'                => $data['suffix'] ?? null,
                ':sex'                   => $data['sex'] ?? null,
                ':birthdate'             => $data['birthdate'] ?? null,
                ':phone'                 => $data['phone'] ?? null,
                ':email'                 => $data['email'] ?? null,
                ':designated_department' => $department,
                ':position'              => $data['position'] ?? null,
                ':profile_status'        => $data['profile_status'] ?? 'Active',
                ':request_status'        => $data['request_status'] ?? 'approved',
                ':user_id'               => $data['user_id'] ?? null,
                ':id'                    => $data['id']
            ]);
        }

        $stmt = $this->db->prepare("
            INSERT INTO faculty_db.faculty_profiles (
                faculty_id, first_name, middle_name, last_name, suffix,
                sex, birthdate, phone, email, designated_department, position,
                profile_status, request_status, user_id
            ) VALUES (
                :faculty_id, :first_name, :middle_name, :last_name, :suffix,
                :sex, :birthdate, :phone, :email, :designated_department, :position,
                :profile_status, :request_status, :user_id
            )
        ");
        return $stmt->execute([
            ':faculty_id'            => $data['faculty_id'] ?? null,
            ':first_name'            => $data['first_name'] ?? null,
            ':middle_name'           => $data['middle_name'] ?? null,
            ':last_name'             => $data['last_name'] ?? null,
            ':suffix'                => $data['suffix'] ?? null,
            ':sex'                   => $data['sex'] ?? null,
            ':birthdate'             => $data['birthdate'] ?? null,
            ':phone'                 => $data['phone'] ?? null,
            ':email'                 => $data['email'] ?? null,
            ':designated_department' => $department,
            ':position'              => $data['position'] ?? null,
            ':profile_status'        => $data['profile_status'] ?? 'Active',
            ':request_status'        => $data['request_status'] ?? 'approved',
            ':user_id'               => $data['user_id'] ?? null,
        ]);
    }

    /**
     * Update an existing faculty profile from the Faculty Profile edit form.
     * Called statically by FacultyController::handleUpdateFaculty().
     *
     * Intentionally does NOT touch designated_department - that field was
     * removed from the edit form, so it must never be overwritten here.
     */
    public static function update($profileId, array $data): bool
    {
        $pdo = null;
        if (function_exists('facultyDb') && facultyDb() instanceof \PDO) {
            $pdo = facultyDb();
        } elseif (function_exists('db') && db() instanceof \PDO) {
            $pdo = db();
        }
        if (!$pdo) {
            throw new \Exception("Database connection is missing or could not be established.");
        }

        $nullIfEmpty = function ($value) {
            $value = trim((string) ($value ?? ''));
            return $value === '' ? null : $value;
        };

        $stmt = $pdo->prepare("
            UPDATE faculty_db.faculty_profiles SET
                first_name = :first_name,
                middle_name = :middle_name,
                last_name = :last_name,
                suffix = :suffix,
                sex = :sex,
                birthdate = :birthdate,
                age = :age,
                phone = :phone,
                email = :email,
                academic_rank = :academic_rank,
                tier = :tier,
                hired_date = :hired_date,
                contractual_end = :contractual_end,
                employment_status = :employment_status,
                profile_status = :profile_status
            WHERE id = :id
        ");

        return $stmt->execute([
            ':first_name'        => $nullIfEmpty($data['first_name'] ?? null),
            ':middle_name'       => $nullIfEmpty($data['middle_name'] ?? null),
            ':last_name'         => $nullIfEmpty($data['last_name'] ?? null),
            ':suffix'            => $nullIfEmpty($data['suffix'] ?? null),
            ':sex'               => $nullIfEmpty($data['sex'] ?? null),
            ':birthdate'         => $nullIfEmpty($data['birthdate'] ?? null),
            ':age'               => ($data['age'] ?? null) !== null ? (int) $data['age'] : null,
            ':phone'             => $nullIfEmpty($data['phone'] ?? null),
            ':email'             => $nullIfEmpty($data['email'] ?? null),
            ':academic_rank'     => $nullIfEmpty($data['academic_rank'] ?? null),
            ':tier'              => $nullIfEmpty($data['tier'] ?? null),
            ':hired_date'        => $nullIfEmpty($data['hired_date'] ?? null),
            ':contractual_end'   => $nullIfEmpty($data['contractual_end'] ?? null),
            ':employment_status' => $nullIfEmpty($data['employment_status'] ?? null),
            ':profile_status'    => $nullIfEmpty($data['profile_status'] ?? null),
            ':id'                => (int) $profileId,
        ]);
    }

    /**
     * Builds the per-faculty composite performance rows used by
     * getPerformanceMetrics(), getTopPerformers(), getPerformanceList(),
     * and getPerformanceListCount(). Each row has:
     *   id, full_name, student_score, peer_score, teaching_score, overall
     * (any of the three scores is null if that faculty member has no
     * evaluations of that source_type yet).
     *
     * "overall" = 50% Student + 30% Peer + 20% Dept Head, renormalized
     * over whichever sources are actually present, so a faculty member
     * with e.g. only Student + Peer scores still gets a usable overall
     * instead of null until Dept Head evaluations exist too.
     */
private function fetchPerformanceRows($deptId) {
    $this->ensureDb();

    $params = [];
    $sql = "
        SELECT
            fp.id,
            fp.faculty_id,
            CONCAT(fp.first_name, ' ', fp.last_name) AS full_name,
            AVG(CASE WHEN LOWER(e.source_type) LIKE '%student%' THEN e.composite_score END) AS student_score,
            AVG(CASE WHEN LOWER(e.source_type) LIKE '%peer%'    THEN e.composite_score END) AS peer_score,
            AVG(CASE WHEN LOWER(e.source_type) LIKE '%head%' OR LOWER(e.source_type) LIKE '%dept%' THEN e.composite_score END) AS teaching_score
        FROM faculty_db.faculty_profiles fp
        LEFT JOIN evaluations e 
            ON (CAST(e.faculty_id AS CHAR) = CAST(fp.id AS CHAR) OR CAST(e.faculty_id AS CHAR) = CAST(fp.faculty_id AS CHAR))
    ";

    if (!(empty($deptId) || $deptId === '1' || $deptId === 1)) {
        $sql .= " WHERE (LOWER(TRIM(fp.designated_department)) = LOWER(TRIM(:dept)) OR fp.designated_department = :dept2) ";
        $params[':dept']  = $deptId;
        $params[':dept2'] = $deptId;
    }

    $sql .= " GROUP BY fp.id, fp.faculty_id, fp.first_name, fp.last_name ORDER BY fp.last_name ASC, fp.first_name ASC ";

    $stmt = $this->db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['student_score']  = $row['student_score']  !== null ? (float) $row['student_score']  : null;
        $row['peer_score']     = $row['peer_score']     !== null ? (float) $row['peer_score']     : null;
        $row['teaching_score'] = $row['teaching_score'] !== null ? (float) $row['teaching_score'] : null;

        $weights = [];
        if ($row['student_score']  !== null) $weights['student_score']  = 0.5;
        if ($row['peer_score']     !== null) $weights['peer_score']     = 0.3;
        if ($row['teaching_score'] !== null) $weights['teaching_score'] = 0.2;

        if (empty($weights)) {
            $row['overall'] = null;
        } else {
            $weightSum = array_sum($weights);
            $overall = 0.0;
            foreach ($weights as $key => $w) {
                $overall += $row[$key] * ($w / $weightSum);
            }
            $row['overall'] = round($overall, 2);
        }
    }
    unset($row);

    return $rows;
}

/**
     * Fetch performance metrics summary for a department
     */
    public function getPerformanceMetrics($deptId) {
        $rows = $this->fetchPerformanceRows($deptId);

        // "Faculty Evaluated" requires ALL THREE sources - Student, Peer,
        // and Department Head - to each have at least one evaluation.
        // Having just one or two of the three does NOT count as complete.
        // (Dept Avg / Top Performers below intentionally stay based on
        // "has any data at all" - requiring full completion there too would
        // make both cards show nothing until every faculty member has all
        // three evaluation types done, which could be a long rollout.)
        $fullyEvaluatedRows = array_filter($rows, fn($r) =>
            $r['student_score'] !== null && $r['peer_score'] !== null && $r['teaching_score'] !== null
        );
        $totalEvaluated = count($fullyEvaluatedRows);

        $evaluatedRows = array_filter($rows, fn($r) => $r['overall'] !== null);
        $deptAvg = 0.0;
        if (!empty($evaluatedRows)) {
            $sum = array_sum(array_column($evaluatedRows, 'overall'));
            $deptAvg = $sum / count($evaluatedRows);
        }

        $topPerformers = count(array_filter($evaluatedRows, fn($r) => $r['overall'] >= 4.5));

        return [
            'total_evaluated' => $totalEvaluated, // fully evaluated (all 3 sources)
            'total_faculty'   => count($rows),
            'dept_avg'        => number_format($deptAvg, 1),
            'top_performers'  => $topPerformers,
        ];
    }

    /**
     * Fetch top performers (composite overall >= 4.5)
     */
    public function getTopPerformers($deptId) {
        $rows = $this->fetchPerformanceRows($deptId);

        $top = array_filter($rows, fn($r) => $r['overall'] !== null && $r['overall'] >= 4.5);
        usort($top, fn($a, $b) => $b['overall'] <=> $a['overall']);
        $top = array_slice($top, 0, 6);

        return array_map(fn($r) => [
            'full_name' => $r['full_name'],
            'overall'   => $r['overall'],
        ], $top);
    }

    /**
     * Fetch filtered performance list with pagination.
     * Filtering/sorting/pagination happens in PHP over the already-computed
     * composite rows, since "overall" is a derived weighted value rather
     * than a raw column - a department's faculty count is small enough
     * (dozens, not millions) that this is simpler and clearer than trying
     * to express the same renormalized weighting in SQL.
     */
    public function getPerformanceList($deptId, $searchName, $ratingRange, $limit, $offset) {
        $rows = $this->fetchPerformanceRows($deptId);

        if (!empty($searchName)) {
            $needle = mb_strtolower($searchName);
            $rows = array_values(array_filter($rows, function ($r) use ($needle) {
                return str_contains(mb_strtolower($r['full_name']), $needle);
            }));
        }

        if ($ratingRange === '4.5-5.0') {
            $rows = array_values(array_filter($rows, fn($r) => $r['overall'] !== null && $r['overall'] >= 4.5));
        } elseif ($ratingRange === '3.5-4.4') {
            $rows = array_values(array_filter($rows, fn($r) => $r['overall'] !== null && $r['overall'] >= 3.5 && $r['overall'] <= 4.4));
        } elseif ($ratingRange === '0.0-3.4') {
            $rows = array_values(array_filter($rows, fn($r) => $r['overall'] === null || $r['overall'] < 3.5));
        }

        usort($rows, function ($a, $b) {
            if ($a['overall'] === null && $b['overall'] === null) return strcmp($a['full_name'], $b['full_name']);
            if ($a['overall'] === null) return 1;
            if ($b['overall'] === null) return -1;
            return $b['overall'] <=> $a['overall'];
        });

        return array_slice($rows, (int)$offset, (int)$limit);
    }

    /**
     * Total count matching the same filters as getPerformanceList, for
     * correct pagination (getPerformanceMetrics' total_evaluated is NOT the
     * same number once search/rating filters are applied).
     */
    public function getPerformanceListCount($deptId, $searchName, $ratingRange) {
        $rows = $this->fetchPerformanceRows($deptId);

        if (!empty($searchName)) {
            $needle = mb_strtolower($searchName);
            $rows = array_filter($rows, function ($r) use ($needle) {
                return str_contains(mb_strtolower($r['full_name']), $needle);
            });
        }

        if ($ratingRange === '4.5-5.0') {
            $rows = array_filter($rows, fn($r) => $r['overall'] !== null && $r['overall'] >= 4.5);
        } elseif ($ratingRange === '3.5-4.4') {
            $rows = array_filter($rows, fn($r) => $r['overall'] !== null && $r['overall'] >= 3.5 && $r['overall'] <= 4.4);
        } elseif ($ratingRange === '0.0-3.4') {
            $rows = array_filter($rows, fn($r) => $r['overall'] === null || $r['overall'] < 3.5);
        }

        return count($rows);
    }
}

}