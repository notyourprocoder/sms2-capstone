<?php
/**
 * SMS 2 - Authentication & Authorization (Phase 2 – database-backed)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/security-workflow.php';

smsEnforceSessionTimeout();

/**
 * True when the database has zero users (first-time setup required).
 */
function smsNeedsSetup(): bool
{
    $pdo = db();
    if (!$pdo) {
        return false;
    }
    try {
        $count = (int) $pdo->query('SELECT COUNT(*) AS c FROM users')->fetch()['c'];
        return $count === 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Map UI / matrix role keys to session role keys.
 */
function smsNormalizeRoleKey(string $roleKey): string
{
    if ($roleKey === 'crad') {
        return 'crad_officer';
    }
    if ($roleKey === 'admissionoffice' || $roleKey === 'admission_office') {
        return 'admission';
    }
    return $roleKey;
}

/**
 * Map session role key to permission-matrix key.
 */
function smsMatrixRoleKey(string $roleKey): string
{
    return $roleKey === 'crad_officer' ? 'crad' : $roleKey;
}

function isAuthenticated(): bool
{
    return !empty($_SESSION['user_id']);
}

function requireAuth(): void
{
    if (!isAuthenticated()) {
        require_once __DIR__ . '/module-controls.php';
        if (function_exists('smsIsSystemInMaintenance') && smsIsSystemInMaintenance()) {
            header('Location: ' . BASE_URL . '/account/maintenance.php');
            exit;
        }
        header('Location: ' . BASE_URL . '/login/login.php');
        exit;
    }

    // Enforce live account status check on all protected pages
    $userId = getCurrentUserId();
    if ($userId) {
        $pdo = db();
        if ($pdo) {
            try {
                $stmt = $pdo->prepare('SELECT status FROM users WHERE id = ? LIMIT 1');
                $stmt->execute([$userId]);
                $status = strtolower(trim((string) ($stmt->fetchColumn() ?? '')));
                
                if ($status !== 'active') {
                    logout();
                    $_SESSION['flash_login_error'] = str_contains($status, 'pending')
                        ? 'Your account is currently pending administrator approval.'
                        : 'Your account is inactive or disabled.';
                    header('Location: ' . BASE_URL . '/login/login.php');
                    exit;
                }
            } catch (Throwable $e) {
                // Ignore query failure to prevent lockouts on db error
            }
        }
    }

    // Mark online first so status stays accurate even if we redirect next
    smsTouchUserPresence();
    require_once __DIR__ . '/module-controls.php';
    smsEnforceSystemMaintenance();
    smsEnforceModuleForceLogout();
    smsEnforcePrimaryModuleMaintenance();
}

function getCurrentUserName(): string
{
    if (empty($_SESSION['user_id'])) {
        return 'Guest';
    }

    $roleKey = strtolower($_SESSION['user_role_key'] ?? $_SESSION['user_role'] ?? '');

    // Map of roles that should display their Role Title instead of Personal Name
    $adminRoleTitles = [
        'faculty_admin'      => 'Faculty Admin',
        'admin'              => 'Faculty Admin',
        'dean'               => 'Dean',
        'department_head'    => 'Department Head',
        'dept_head'          => 'Department Head',
        'secretary'          => 'Secretary',
        'monitoring_officer' => 'Monitoring Officer',
    ];

    // If the logged-in user has one of these admin roles, return the Role Title
    if (array_key_exists($roleKey, $adminRoleTitles)) {
        return $adminRoleTitles[$roleKey];
    }

    // Otherwise (Faculty / Teacher), return their actual Full Name stored in session
    return $_SESSION['user_name'] ?? 'User';
}

function getCurrentUserRole(): string
{
    return $_SESSION['user_role'] ?? 'User';
}

function getCurrentUserRoleKey(): string
{
    return $_SESSION['user_role_key'] ?? '';
}

function getCurrentUserId(): ?int
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

function getRestrictedDepartmentId(): ?int
{
    $roleKey = getCurrentUserRoleKey();
    $deptScopedRoles = ['secretary', 'monitoring_officer', 'department_head', 'dept_head'];

    if (in_array($roleKey, $deptScopedRoles, true)) {
        return $_SESSION['department_id'] ? (int) $_SESSION['department_id'] : 0;
    }

    return null;
}
/**
 * Default module access when DB permissions are empty (fallback).
 */
function smsDefaultModulesForRole(string $roleKey): array
{
    $roleKey = smsNormalizeRoleKey($roleKey);
    $defaults = [
        'superadmin'       => ['user-management', 'student_portal'],
        'admin'            => ['user-management'],
        'admission'        => ['enrollment'],
        'student'          => ['student_portal'],
        'registrar'        => ['registrar', 'curriculum', 'scheduling'],
        'crad_officer'     => ['crad'],
        'research_coordinator' => ['crad'],
        'finance'          => ['payment'],
        'hr'               => ['faculty'],
        'adviser'          => ['faculty'],
        'panel'            => ['faculty'],
        'it_office'        => ['lms'],
        'osa'              => ['cocurricular'],
        'qa'               => ['accreditation'],
        // Faculty module sub-roles
        'dean'             => ['faculty'],
        'department_head'  => ['faculty'],
        'secretary'        => ['faculty'],
        'monitoring_officer' => ['faculty'],
        'faculty'          => ['faculty'],
        'teacher'          => ['faculty'],
    ];

    return $defaults[$roleKey] ?? [];
}

/**
 * Load granted modules for a role from DB (+ JSON file legacy overrides).
 */
function smsAllowedModuleKeysForRole(string $roleKey): array
{
    $roleKey = smsNormalizeRoleKey($roleKey);

    $allowed = [];

    $pdo = db();
    if ($pdo) {
        try {
            $stmt = $pdo->prepare(
                'SELECT module_key, granted FROM role_permissions WHERE role_key = ?'
            );
            $stmt->execute([$roleKey]);
            $rows = $stmt->fetchAll();

            if ($rows) {
                foreach ($rows as $row) {
                    if ((int) $row['granted'] === 1) {
                        $allowed[] = $row['module_key'];
                    }
                }
            } else {
                $allowed = smsDefaultModulesForRole($roleKey);
            }
        } catch (Throwable $e) {
            $allowed = smsDefaultModulesForRole($roleKey);
        }
    } else {
        $allowed = smsDefaultModulesForRole($roleKey);
    }

    // Legacy JSON overrides (migrate gradually)
    $matrixRoleKey = smsMatrixRoleKey($roleKey);
    $permFile = ROOT_PATH . '/config/perm_overrides.json';
    if (is_readable($permFile)) {
        $overrides = json_decode((string) file_get_contents($permFile), true);
        if (is_array($overrides) && !empty($overrides[$matrixRoleKey])) {
            foreach ($overrides[$matrixRoleKey] as $module => $granted) {
                if ($granted) {
                    if (!in_array($module, $allowed, true)) {
                        $allowed[] = $module;
                    }
                } else {
                    $allowed = array_values(array_filter(
                        $allowed,
                        static fn($m) => $m !== $module
                    ));
                }
            }
        }
    }

    if (in_array($roleKey, ['superadmin', 'admin'], true) && !in_array('user-management', $allowed, true)) {
        $allowed[] = 'user-management';
    }

    if ($roleKey === 'student') {
        $allowed = ['student_portal'];
    } elseif ($roleKey !== 'superadmin') {
        $allowed = array_values(array_filter(
            $allowed,
            static fn($moduleKey) => $moduleKey !== 'student_portal'
        ));
    }

    return $allowed;
}

/**
 * Load granted modules for the current session role.
 */
function getAllowedModuleKeys(): array
{
    return smsAllowedModuleKeysForRole(getCurrentUserRoleKey());
}

function userCanAccessModule(string $moduleKey): bool
{
    if ($moduleKey === '' || $moduleKey === 'dashboard') {
        return true;
    }

    if ($moduleKey === 'user-management') {
        $roleKey = getCurrentUserRoleKey();
        if (in_array($roleKey, ['superadmin', 'admin'], true)) {
            return true;
        }
        return in_array('user-management', getAllowedModuleKeys(), true);
    }

    // Legacy admin accounts are restricted to dashboard and User Management.
    if (getCurrentUserRoleKey() === 'admin') {
        return false;
    }

    // Student portal alias
    if ($moduleKey === 'student-portal' || $moduleKey === 'student_portal') {
        $moduleKey = 'student_portal';
        $roleKey = getCurrentUserRoleKey();
        if ($roleKey === 'student') {
            return true;
        }
        if ($roleKey !== 'superadmin') {
            return false;
        }
    }

    $allowedModules = getAllowedModuleKeys();
    return in_array($moduleKey, $allowedModules, true);
}

function requireSuperAdmin(): void
{
    if (!userCanAccessModule('user-management')) {
        header('Location: ' . BASE_URL . '/dashboard/index.php');
        exit;
    }
}

function getVisibleModules(array $modules): array
{
    $allowedModules = getAllowedModuleKeys();
    $visible = array_intersect_key($modules, array_flip($allowedModules));

    if (getCurrentUserRoleKey() === 'research_coordinator' && isset($visible['crad'])) {
        $visible['crad'] = smsResearchCoordinatorCradModule();
    }

    if (in_array('student_portal', $allowedModules, true) && !isset($visible['student_portal'])) {
        $visible['student_portal'] = [
            'label' => 'Student Portal',
            'icon'  => 'fa-user-graduate',
            'groups' => [
                'Overview' => ['dashboard'],
                'Student Information' => ['my-profile', 'student-id'],
                'Financial' => ['account-balance', 'payment-history'],
                'Academics' => ['class-schedule', 'academic-records', 'subjects-professors', 'grades-portal'],
                'Research' => ['research-proposal-submission', 'submit-documents'],
            ],
            'pages' => [
                ['slug' => 'dashboard', 'title' => 'Dashboard'],
                ['slug' => 'my-profile', 'title' => 'My Profile'],
                ['slug' => 'student-id', 'title' => 'Student ID'],
                ['slug' => 'account-balance', 'title' => 'Account Balance'],
                ['slug' => 'payment-history', 'title' => 'Payment History'],
                ['slug' => 'class-schedule', 'title' => 'Class Schedule'],
                ['slug' => 'academic-records', 'title' => 'Academic Records'],
                ['slug' => 'subjects-professors', 'title' => 'Subject & Professors'],
                ['slug' => 'grades-portal', 'title' => 'Grades Portal'],
                ['slug' => 'research-proposal-submission', 'title' => 'Research Proposal'],
                ['slug' => 'submit-documents', 'title' => 'Submit Documents'],
            ],
        ];
    }

    if (isset($visible['reports-analytics'])) {
        require_once __DIR__ . '/reports-catalog.php';
        $roleReports = smsReportsForRole();
        $visible['reports-analytics']['pages'] = array_map(
            static fn(array $report): array => [
                'slug'  => $report['slug'],
                'title' => $report['title'],
            ],
            $roleReports
        );
    }

    return $visible;
}

function smsResearchCoordinatorCradModule(): array
{
    return [
        'label' => 'Research Coordinator',
        'icon'  => 'fa-microscope',
        'groups' => [
            'Approved Research' => [
                'approved-research',
            ],
            'A. Adviser Assignment' => [
                'find-contact-adviser',
                'adviser-availability',
                'assign-research-adviser',
            ],
            'B. Panel Assignment' => [
                'find-contact-panel',
                'panel-availability',
                'assign-panel-members',
            ],
            'Coordination' => [
                'send-notifications',
                'manage-assignments',
            ],
        ],
        'pages' => [
            ['slug' => 'approved-research', 'title' => 'View Approved Research'],
            ['slug' => 'find-contact-adviser', 'title' => 'Find/Contact Adviser'],
            ['slug' => 'adviser-availability', 'title' => 'Check Adviser Availability'],
            ['slug' => 'assign-research-adviser', 'title' => 'Assign Research Adviser'],
            ['slug' => 'find-contact-panel', 'title' => 'Find/Contact Panel'],
            ['slug' => 'panel-availability', 'title' => 'Check Panel Availability'],
            ['slug' => 'assign-panel-members', 'title' => 'Assign Panel Members'],
            ['slug' => 'send-notifications', 'title' => 'Send Notifications'],
            ['slug' => 'manage-assignments', 'title' => 'View/Manage Assignments'],
        ],
    ];
}

function smsFacultySidebarPages(): array
{
    $roleKey = getCurrentUserRoleKey();

    if (in_array($roleKey, ['dean', 'hr'], true)) {
        return [
            ['label' => 'Faculty Profile',        'url' => 'faculty-profile.php'],
            ['label' => 'Faculty Directory',       'url' => 'faculty-directory.php'],
            ['label' => 'Teaching History',        'url' => 'teaching-history.php'],
            ['label' => 'Subject Load Tracker',    'url' => 'subject-load-tracker.php'],
            ['label' => 'Attendance Monitoring',   'url' => 'attendance-monitoring.php'],
            ['label' => 'Leave Application & Approval', 'url' => 'leave-application-approval.php'],
            ['label' => 'Evaluation Summary',      'url' => 'evaluation-summary.php'],
            ['label' => 'Clearance System',        'url' => 'clearance-system.php'],
        ];
    }

    // department_head, secretary, faculty/teacher get their own arrays here later

    return [];
}

function smsPostLoginRedirectUrl(): string
{
    $roleKey = getCurrentUserRoleKey();

    // Direct role-based redirection to designated Faculty subsystem views
    if ($roleKey === 'faculty_admin') {
        return BASE_URL . '/modules/faculty/views/administrator/dashboard.php';
    }
    if ($roleKey === 'dean' || $roleKey === 'hr') {
        return BASE_URL . '/modules/faculty/views/dean/faculty-profile.php';
    }
    if (in_array($roleKey, ['department_head', 'department-head', 'dept_head', 'depthead'], true)) {
        return BASE_URL . '/modules/faculty/views/department-head/faculty-profile.php';
    }
    if ($roleKey === 'secretary') {
        return BASE_URL . '/modules/faculty/views/secretary/dashboard.php';
    }
    if (in_array($roleKey, ['monitoring_officer'], true)) {
        return BASE_URL . '/modules/faculty/views/monitoring-officer/dashboard.php';
    }
    
    if (in_array($roleKey, ['faculty', 'teacher'], true)) {
        return BASE_URL . '/modules/faculty/views/faculty/dashboard.php';
    }

    $allowedModules = getAllowedModuleKeys();
    $priority = [
        'user-management',
        'enrollment',
        'registrar',
        'curriculum',
        'accreditation',
        'payment',
        'faculty',
        'scheduling',
        'cocurricular',
        'lms',
        'crad',
        'reports-analytics',
        'student_portal',
    ];

    foreach ($priority as $moduleKey) {
        if (!in_array($moduleKey, $allowedModules, true)) {
            continue;
        }
        if ($moduleKey === 'faculty') {
            return BASE_URL . '/modules/faculty/views/faculty/dashboard.php';
        }
        if ($moduleKey === 'student_portal') {
            return BASE_URL . '/modules/student-portal/pages/my-profile.php';
        }
        return BASE_URL . '/modules/' . $moduleKey . '/index.php';
    }

    return BASE_URL . '/dashboard/index.php';
}

function requireModuleAccess(string $moduleKey): void
{
    // student-portal folder uses hyphen
    $key = $moduleKey === 'student-portal' ? 'student_portal' : $moduleKey;
    if ($key === '') {
        return;
    }

    require_once __DIR__ . '/module-controls.php';

    // Per-module maintenance: staff/students see maintenance page; Super Admin still allowed
    // Only the maintenance page + logout are allowed (no escape into the module)
    $scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $isMaintEscape = str_ends_with($scriptPath, '/account/module-unavailable.php')
        || str_ends_with($scriptPath, '/login/logout.php');

    if (
        smsIsModuleInMaintenance($key)
        && !in_array(getCurrentUserRoleKey(), ['superadmin', 'admin'], true)
        && $key !== 'user-management'
        && !$isMaintEscape
    ) {
        header('Location: ' . BASE_URL . '/account/module-unavailable.php?module=' . rawurlencode($key));
        exit;
    }

    if ($key === 'crad' && getCurrentUserRoleKey() === 'research_coordinator') {
        $allowedCoordinatorPages = [
            '/modules/crad/index.php',
            '/modules/crad/pages/approved-research.php',
            '/modules/crad/pages/adviser-panel-assignment.php',
            '/modules/crad/pages/find-contact-adviser.php',
            '/modules/crad/pages/adviser-availability.php',
            '/modules/crad/pages/assign-research-adviser.php',
            '/modules/crad/pages/find-contact-panel.php',
            '/modules/crad/pages/panel-availability.php',
            '/modules/crad/pages/assign-panel-members.php',
            '/modules/crad/pages/send-notifications.php',
            '/modules/crad/pages/manage-assignments.php',
        ];
        $isAllowedCoordinatorPage = false;
        foreach ($allowedCoordinatorPages as $allowedPath) {
            if (str_ends_with($scriptPath, $allowedPath)) {
                $isAllowedCoordinatorPage = true;
                break;
            }
        }
        if (!$isAllowedCoordinatorPage) {
            header('Location: ' . BASE_URL . '/modules/crad/index.php');
            exit;
        }
    }

    if ($key === 'student_portal') {
        if (userCanAccessModule('student_portal')) {
            return;
        }
        header('Location: ' . BASE_URL . '/dashboard/index.php');
        exit;
    }

    if (!userCanAccessModule($key) && !userCanAccessModule($moduleKey)) {
        header('Location: ' . BASE_URL . '/dashboard/index.php');
        exit;
    }
}

/**
 * Resolve login identifier to a user row.
 */
function smsFindUserByLogin(string $input): ?array
{
    $pdo = db();
    if (!$pdo) {
        return null;
    }

    $input = strtolower(trim($input));
    if ($input === '') {
        return null;
    }

    // Extract handle if email format was entered
    $usernameHandle = str_contains($input, '@') ? explode('@', $input)[0] : $input;

    try {
        $stmt = $pdo->prepare(
            'SELECT u.*, r.label AS role_label
            FROM users u
            LEFT JOIN roles r ON r.role_key = u.role_key
            WHERE LOWER(u.email) = ? 
               OR LOWER(u.username) = ? 
               OR LOWER(u.username) = ?
               OR LOWER(u.student_id) = ?
            LIMIT 1'
        );
        $stmt->execute([$input, $input, $usernameHandle, $input]);

        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        error_log('SMS2 find user failed: ' . $e->getMessage());
        return null;
    }
}

function smsIsAccountLocked(array $user): bool
{
    if (!empty($user['locked_until'])) {
        $until = strtotime((string) $user['locked_until']);
        if ($until !== false && $until > time()) {
            return true;
        }
        // Expired cooldown — do not keep locking forever on status alone
        return false;
    }

    // Locked with no cooldown timestamp (manual / legacy)
    return ($user['status'] ?? '') === 'locked';
}

function smsClearLockIfExpired(array $user): void
{
    if (empty($user['locked_until'])) {
        return;
    }

    $until = strtotime((string) $user['locked_until']);
    if ($until === false || $until > time()) {
        return;
    }

    $pdo = db();
    if (!$pdo) {
        return;
    }

    $pdo->prepare(
        'UPDATE users SET locked_until = NULL, failed_login_attempts = 0,
         status = CASE WHEN status = \'locked\' THEN \'active\' ELSE status END
         WHERE id = ?'
    )->execute([(int) $user['id']]);
}

function smsLockoutSeconds(): int
{
    $sec = (int) smsSetting('lockout_seconds', '0');
    if ($sec > 0) {
        return max(1, $sec);
    }
    return max(1, (int) smsSetting('lockout_minutes', '5')) * 60;
}

function smsFormatDuration(int $seconds): string
{
    $seconds = max(0, $seconds);
    if ($seconds < 60) {
        return $seconds . ' second' . ($seconds === 1 ? '' : 's');
    }
    $mins = (int) floor($seconds / 60);
    $secs = $seconds % 60;
    if ($secs === 0) {
        return $mins . ' minute' . ($mins === 1 ? '' : 's');
    }
    return $mins . ' min ' . $secs . ' sec';
}

function smsLockRemainingMinutes(array $user): int
{
    if (empty($user['locked_until'])) {
        return 0;
    }
    $until = strtotime((string) $user['locked_until']);
    if ($until === false || $until <= time()) {
        return 0;
    }
    return max(1, (int) ceil(($until - time()) / 60));
}

function smsLockRemainingSeconds(array $user): int
{
    if (empty($user['locked_until'])) {
        return 0;
    }
    $until = strtotime((string) $user['locked_until']);
    if ($until === false || $until <= time()) {
        return 0;
    }
    return max(1, $until - time());
}

function smsLoginThrottleKey(string $loginInput = ''): string
{
    $ip = smsClientIp();
    $norm = strtolower(trim($loginInput));
    // IP-wide key (anti-spam for random emails) — primary gate
    if ($norm === '') {
        return hash('sha256', 'ip|' . $ip);
    }
    return hash('sha256', 'ip|' . $ip);
}

function smsEnsureLoginThrottleTables(): void
{
    if (function_exists('smsEnsureSecurityTables')) {
        smsEnsureSecurityTables();
    }
}

/**
 * @return array{attempts:int,max:int,remaining:?int,locked:bool,lock_seconds:int,locked_until:?string}
 */
function smsGetLoginThrottle(string $loginInput = ''): array
{
    smsEnsureLoginThrottleTables();
    $pdo = db();
    $maxFails = max(0, (int) smsSetting('max_failed_logins', '3'));
    $lockSeconds = smsLockoutSeconds();
    $empty = [
        'attempts' => 0,
        'max' => $maxFails,
        'remaining' => $maxFails > 0 ? $maxFails : null,
        'locked' => false,
        'lock_seconds' => $lockSeconds,
        'locked_until' => null,
    ];
    if (!$pdo) {
        return $empty;
    }

    $key = smsLoginThrottleKey($loginInput);
    $stmt = $pdo->prepare('SELECT attempts, locked_until FROM login_throttles WHERE throttle_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    if (!$row) {
        return $empty;
    }

    $lockedUntil = $row['locked_until'] ?? null;
    if ($lockedUntil) {
        $untilTs = strtotime((string) $lockedUntil);
        if ($untilTs !== false && $untilTs <= time()) {
            $pdo->prepare('UPDATE login_throttles SET attempts = 0, locked_until = NULL WHERE throttle_key = ?')
                ->execute([$key]);
            return $empty;
        }
        if ($untilTs !== false && $untilTs > time()) {
            return [
                'attempts' => (int) $row['attempts'],
                'max' => $maxFails,
                'remaining' => 0,
                'locked' => true,
                'lock_seconds' => $lockSeconds,
                'locked_until' => (string) $lockedUntil,
            ];
        }
    }

    $attempts = (int) $row['attempts'];
    return [
        'attempts' => $attempts,
        'max' => $maxFails,
        'remaining' => $maxFails > 0 ? max(0, $maxFails - $attempts) : null,
        'locked' => false,
        'lock_seconds' => $lockSeconds,
        'locked_until' => null,
    ];
}

/**
 * @return array{attempts:int,max:int,remaining:?int,locked:bool,lock_seconds:int,locked_until:?string}
 */
function smsRegisterLoginThrottleFailure(string $loginInput = ''): array
{
    smsEnsureLoginThrottleTables();
    $pdo = db();
    $maxFails = max(0, (int) smsSetting('max_failed_logins', '3'));
    $lockSeconds = smsLockoutSeconds();
    $key = smsLoginThrottleKey($loginInput);
    $ip = smsClientIp();

    $result = [
        'attempts' => 1,
        'max' => $maxFails,
        'remaining' => $maxFails > 0 ? max(0, $maxFails - 1) : null,
        'locked' => false,
        'lock_seconds' => $lockSeconds,
        'locked_until' => null,
    ];

    if (!$pdo) {
        return $result;
    }

    $current = smsGetLoginThrottle($loginInput);
    if (!empty($current['locked'])) {
        return $current;
    }

    // Atomic increment — prevents spam/race from skipping the lock threshold
    $pdo->prepare(
        'INSERT INTO login_throttles (throttle_key, ip_address, attempts, locked_until)
         VALUES (?, ?, 1, NULL)
         ON DUPLICATE KEY UPDATE
            attempts = attempts + 1,
            ip_address = VALUES(ip_address),
            locked_until = IF(locked_until IS NOT NULL AND locked_until > NOW(), locked_until, NULL)'
    )->execute([$key, $ip]);

    $stmt = $pdo->prepare('SELECT attempts, locked_until FROM login_throttles WHERE throttle_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $row = $stmt->fetch() ?: [];
    $attempts = max(1, (int) ($row['attempts'] ?? 1));
    $lockedUntil = null;
    $locked = false;

    if ($maxFails > 0 && $attempts >= $maxFails) {
        $locked = true;
        $pdo->prepare(
            'UPDATE login_throttles
             SET locked_until = DATE_ADD(NOW(), INTERVAL ? SECOND)
             WHERE throttle_key = ?'
        )->execute([$lockSeconds, $key]);
        $fresh = $pdo->prepare('SELECT locked_until FROM login_throttles WHERE throttle_key = ? LIMIT 1');
        $fresh->execute([$key]);
        $lockedUntil = $fresh->fetchColumn() ?: null;
        if (is_string($lockedUntil) && $lockedUntil !== '') {
            $lockedUntil = (string) $lockedUntil;
        } else {
            $lockedUntil = null;
        }
    }

    return [
        'attempts' => $attempts,
        'max' => $maxFails,
        'remaining' => $maxFails > 0 ? max(0, $maxFails - $attempts) : null,
        'locked' => $locked,
        'lock_seconds' => $lockSeconds,
        'locked_until' => $lockedUntil,
    ];
}

function smsClearLoginThrottle(string $loginInput = ''): void
{
    $pdo = db();
    if (!$pdo) {
        return;
    }
    smsEnsureLoginThrottleTables();
    $pdo->prepare('DELETE FROM login_throttles WHERE throttle_key = ?')
        ->execute([smsLoginThrottleKey($loginInput)]);
}

/**
 * Force IP throttle into locked state (no extra attempt increment).
 */
function smsForceLoginThrottleLock(string $loginInput = '', ?int $lockSeconds = null, ?int $minAttempts = null): ?string
{
    smsEnsureLoginThrottleTables();
    $pdo = db();
    if (!$pdo) {
        return null;
    }
    $seconds = max(1, $lockSeconds ?? smsLockoutSeconds());
    $attempts = max(1, $minAttempts ?? max(1, (int) smsSetting('max_failed_logins', '3')));
    $key = smsLoginThrottleKey($loginInput);
    $pdo->prepare(
        'INSERT INTO login_throttles (throttle_key, ip_address, attempts, locked_until)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
         ON DUPLICATE KEY UPDATE
            attempts = GREATEST(attempts, VALUES(attempts)),
            locked_until = DATE_ADD(NOW(), INTERVAL ? SECOND),
            ip_address = VALUES(ip_address)'
    )->execute([$key, smsClientIp(), $attempts, $seconds, $seconds]);

    $stmt = $pdo->prepare('SELECT locked_until FROM login_throttles WHERE throttle_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $until = $stmt->fetchColumn();
    return $until ? (string) $until : null;
}

function smsLoginGateSet(?string $lockedUntil, string $message, string $alert = 'warning', ?int $lockSeconds = null): void
{
    // Always anchor the UI gate to PHP time so countdown/lock cannot
    // disappear immediately due to MySQL/PHP timezone skew.
    $seconds = max(1, $lockSeconds ?? smsLockoutSeconds());
    $untilTs = time() + $seconds;
    if ($lockedUntil) {
        $parsed = (int) strtotime($lockedUntil);
        if ($parsed > time()) {
            $untilTs = max($untilTs, $parsed);
        }
    }
    $_SESSION['login_gate'] = [
        'until' => $untilTs,
        'message' => $message,
        'alert' => $alert,
    ];
}

function smsLoginGateClear(): void
{
    unset($_SESSION['login_gate']);
}

/**
 * @return array{active:bool,message:string,alert:string,remaining_minutes:int,until:int}|null
 */
function smsLoginGateStatus(): ?array
{
    $gate = $_SESSION['login_gate'] ?? null;
    if (!is_array($gate) || empty($gate['until'])) {
        return null;
    }
    $until = (int) $gate['until'];
    if ($until <= time()) {
        smsLoginGateClear();
        return null;
    }
    $mins = max(1, (int) ceil(($until - time()) / 60));
    $message = (string) ($gate['message'] ?? '');
    if ($message === '') {
        $message = 'Login is temporarily locked. Please wait ' . $mins . ' minute' . ($mins === 1 ? '' : 's') . ' before trying again.';
    }
    return [
        'active' => true,
        'message' => $message,
        'alert' => (string) ($gate['alert'] ?? 'warning'),
        'remaining_minutes' => $mins,
        'until' => $until,
    ];
}

function smsFailMessageFromThrottle(array $info, bool $unknownUser = false): array
{
    $lockSeconds = (int) ($info['lock_seconds'] ?? smsLockoutSeconds());
    $lockLabel = smsFormatDuration($lockSeconds);
    if (!empty($info['locked'])) {
        $msg = 'Login is temporarily locked after too many failed attempts. Please wait '
            . $lockLabel
            . ' before trying again. Sign-in is disabled until the cooldown ends.';
        return ['code' => 'locked', 'message' => $msg, 'alert' => 'warning', 'show_reset' => !$unknownUser];
    }

    $remaining = $info['remaining'] ?? null;
    $max = (int) ($info['max'] ?? 0);
    $prefix = $unknownUser
        ? 'Invalid login attempt.'
        : 'Incorrect password.';

    if ($max > 0 && $remaining === 1) {
        return [
            'code' => 'last_attempt',
            'message' => $prefix . ' Warning: this is your last attempt. One more failure will lock this login for '
                . $lockLabel . '.'
                . ($unknownUser ? '' : ' If you forgot your password, reset it now.'),
            'alert' => 'warning',
            'show_reset' => !$unknownUser,
        ];
    }

    if ($max > 0 && $remaining !== null) {
        return [
            'code' => $unknownUser ? 'unknown' : 'bad_password',
            'message' => $prefix . ' ' . $remaining . ' attempt' . ($remaining === 1 ? '' : 's') . ' remaining.'
                . ($unknownUser ? '' : ' If you forgot your password, use Forgot password to reset it.'),
            'alert' => 'danger',
            'show_reset' => !$unknownUser,
        ];
    }

    return [
        'code' => $unknownUser ? 'unknown' : 'bad_password',
        'message' => $prefix . ($unknownUser ? '' : ' If you forgot your password, use Forgot password to reset it.'),
        'alert' => 'danger',
        'show_reset' => !$unknownUser,
    ];
}

function smsRegisterFailedLogin(array $user): array
{
    $pdo = db();
    $maxFails = max(0, (int) smsSetting('max_failed_logins', '3'));
    $lockSeconds = smsLockoutSeconds();
    $result = [
        'attempts' => ((int) $user['failed_login_attempts']) + 1,
        'max' => $maxFails,
        'remaining' => null,
        'locked' => false,
        'lock_seconds' => $lockSeconds,
        'locked_until' => null,
    ];

    if (!$pdo) {
        $attempts = (int) $result['attempts'];
        $result['remaining'] = $maxFails > 0 ? max(0, $maxFails - $attempts) : null;
        return $result;
    }

    // Atomic increment — rapid spam clicks must still hit the lock threshold
    $pdo->prepare(
        'UPDATE users SET failed_login_attempts = failed_login_attempts + 1 WHERE id = ?'
    )->execute([(int) $user['id']]);

    $fresh = $pdo->prepare(
        'SELECT failed_login_attempts, locked_until, status FROM users WHERE id = ? LIMIT 1'
    );
    $fresh->execute([(int) $user['id']]);
    $row = $fresh->fetch() ?: [];
    $attempts = max(1, (int) ($row['failed_login_attempts'] ?? 1));
    $result['attempts'] = $attempts;
    $result['remaining'] = $maxFails > 0 ? max(0, $maxFails - $attempts) : null;

    if ($maxFails > 0 && $attempts >= $maxFails) {
        $pdo->prepare(
            'UPDATE users
             SET locked_until = DATE_ADD(NOW(), INTERVAL ? SECOND), status = \'locked\'
             WHERE id = ?'
        )->execute([$lockSeconds, (int) $user['id']]);

        $result['locked'] = true;
        $result['remaining'] = 0;
        $untilStmt = $pdo->prepare('SELECT locked_until FROM users WHERE id = ? LIMIT 1');
        $untilStmt->execute([(int) $user['id']]);
        $result['locked_until'] = $untilStmt->fetchColumn() ?: null;

        logActivity(
            'lockout',
            'Login locked after failed attempts',
            'System',
            (int) $user['id'],
            (string) $user['full_name'],
            (string) $user['role_key'],
            false
        );
    }

    return $result;
}

function smsRegisterSuccessfulLogin(array $user): void
{
    $pdo = db();
    if (!$pdo) {
        return;
    }

    $pdo->prepare(
        'UPDATE users
         SET failed_login_attempts = 0, locked_until = NULL,
             last_login_at = NOW(), last_login_ip = ?,
             status = CASE WHEN status = \'locked\' THEN \'active\' ELSE status END
         WHERE id = ?'
    )->execute([smsClientIp(), (int) $user['id']]);
}

/**
 * Database-backed login with detailed result for UI messaging.
 *
 * @return array{ok:bool,code:string,message:string,alert:string,show_reset:bool,locked:bool,locked_until:?string}
 */
function smsLoginAttempt(string $username, string $password): array
{
    $pack = static function (
        string $code,
        string $message,
        string $alert = 'danger',
        bool $showReset = false,
        bool $locked = false,
        ?string $lockedUntil = null
    ): array {
        return [
            'ok' => false,
            'code' => $code,
            'message' => $message,
            'alert' => $alert,
            'show_reset' => $showReset,
            'locked' => $locked,
            'locked_until' => $lockedUntil,
        ];
    };

    if (trim($username) === '' || trim($password) === '') {
        return $pack('empty', 'Please enter your email and password.');
    }

    // IP / login gate first (covers random spam emails too)
    $throttle = smsGetLoginThrottle($username);
    if (!empty($throttle['locked'])) {
        $secs = 0;
        if (!empty($throttle['locked_until'])) {
            $untilTs = strtotime((string) $throttle['locked_until']);
            if ($untilTs !== false && $untilTs > time()) {
                $secs = max(1, $untilTs - time());
            }
        }
        if ($secs <= 0) {
            $secs = (int) ($throttle['lock_seconds'] ?? smsLockoutSeconds());
        }
        $msg = 'Login is temporarily locked after too many failed attempts. Please wait '
            . smsFormatDuration($secs)
            . ' before trying again. Sign-in is disabled until the cooldown ends.';
        return $pack('locked', $msg, 'warning', false, true, $throttle['locked_until'] ?? null);
    }

    $user = smsFindUserByLogin($username);
    if (!$user) {
        password_verify($password, '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWX12');
        $failInfo = smsRegisterLoginThrottleFailure($username);
        logActivity(
            'login_failed',
            'Invalid login attempt (unknown credentials)',
            'System',
            null,
            'Unknown',
            null,
            false
        );
        $msg = smsFailMessageFromThrottle($failInfo, true);
        return $pack(
            $msg['code'],
            $msg['message'],
            $msg['alert'],
            $msg['show_reset'],
            !empty($failInfo['locked']),
            $failInfo['locked_until'] ?? null
        );
    }

    smsClearLockIfExpired($user);
    $fresh = smsFindUserByLogin($username);
    if ($fresh) {
        $user = $fresh;
    }

    $accountStatus = strtolower(trim((string) ($user['status'] ?? '')));
    // Block pending approval accounts (catches any variation of 'pending')
    if (str_contains($accountStatus, 'pending') || in_array($accountStatus, ['unapproved', 'waiting_approval'], true)) {
        logActivity(
            'login_failed',
            'Login blocked — account pending approval: ' . $user['status'],
            'System',
            (int) $user['id'],
            (string) $user['full_name'],
            (string) $user['role_key'],
            false
        );
        return $pack(
            'pending',
            'Your account is currently pending administrator approval. Please wait for an admin to activate it.',
            'warning'
        );
    }

    // Block all other non-active account statuses (inactive, suspended, etc.)
    if ($accountStatus !== 'active') {
        logActivity(
            'login_failed',
            'Login blocked — account status: ' . $user['status'],
            'System',
            (int) $user['id'],
            (string) $user['full_name'],
            (string) $user['role_key'],
            false
        );
        return $pack('inactive', 'This login cannot be used right now. Contact your administrator.');
    }
    if (!password_verify($password, (string) $user['password_hash'])) {
        $userFail = smsRegisterFailedLogin($user);
        $ipFail = smsRegisterLoginThrottleFailure($username);
        // Use the stricter of the two
        $failInfo = $userFail;
        if (!empty($ipFail['locked']) && empty($userFail['locked'])) {
            $failInfo = $ipFail;
        } elseif (!empty($ipFail['locked']) && !empty($userFail['locked'])) {
            $failInfo = $userFail;
            if (empty($failInfo['locked_until']) && !empty($ipFail['locked_until'])) {
                $failInfo['locked_until'] = $ipFail['locked_until'];
            }
        } elseif (
            isset($ipFail['remaining'], $userFail['remaining'])
            && $ipFail['remaining'] !== null
            && $userFail['remaining'] !== null
            && $ipFail['remaining'] < $userFail['remaining']
        ) {
            $failInfo = $ipFail;
        }

        logActivity(
            'login_failed',
            'Invalid password',
            'System',
            (int) $user['id'],
            (string) $user['full_name'],
            (string) $user['role_key'],
            false
        );

        $msg = smsFailMessageFromThrottle($failInfo, false);
        $isLocked = !empty($failInfo['locked']);
        if ($isLocked) {
            $until = smsForceLoginThrottleLock(
                $username,
                (int) ($failInfo['lock_seconds'] ?? smsLockoutSeconds()),
                (int) ($failInfo['attempts'] ?? 0)
            );
            if ($until && empty($failInfo['locked_until'])) {
                $failInfo['locked_until'] = $until;
            }
        }
        return $pack(
            $msg['code'],
            $msg['message'],
            $msg['alert'],
            $msg['show_reset'],
            $isLocked,
            $failInfo['locked_until'] ?? null
        );
    }

    // Rehash if algorithm upgraded
    if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
        $pdo = db();
        if ($pdo) {
            $pdo->prepare('UPDATE users SET password_hash = ?, password_changed_at = password_changed_at WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), (int) $user['id']]);
        }
    }

    // Direct login — skip mandatory 2FA / OTP screens
    return smsCompleteLoginSession($user, $username);
}

/**
 * Finalize login session after password (+ optional 2FA) succeeds.
 *
 * @param array<string,mixed> $user
 * @return array{ok:bool,code:string,message:string,alert:string,show_reset:bool,locked:bool,locked_until:?string}
 */
function smsCompleteLoginSession(array $user, string $username = ''): array
{
    smsRegisterSuccessfulLogin($user);
    if ($username !== '') {
        smsClearLoginThrottle($username);
    }
    smsLoginGateClear();
    unset($_SESSION['pending_2fa']);

    session_regenerate_id(true);

    // -------------------------------------------------------------
    // 1. Resolve Display Name (Role Titles vs. Personal Name)
    // -------------------------------------------------------------
    $roleKey = strtolower((string) ($user['role_key'] ?? ''));

    // Admin role title lookup table
    $adminRoleTitles = [
        'faculty_admin'      => 'Faculty Admin',
        'admin'              => 'Faculty Admin',
        'dean'               => 'Dean',
        'department_head'    => 'Department Head',
        'dept_head'          => 'Department Head',
        'secretary'          => 'Secretary',
        'monitoring_officer' => 'Monitoring Officer',
    ];

    if (array_key_exists($roleKey, $adminRoleTitles)) {
        // Administrative roles get their Role Title as display name
        $displayName = $adminRoleTitles[$roleKey];
    } else {
        // Faculty / Teachers get their personal name
        $displayName = trim((string) ($user['full_name'] ?? ''));

        // If full_name is empty OR contains an email address (@)
        if ($displayName === '' || str_contains($displayName, '@')) {
            try {
                // Fallback to primary db() or facultyDb()
                $facultyPdo = function_exists('facultyDb') ? facultyDb() : db();
                if ($facultyPdo) {
                    $profStmt = $facultyPdo->prepare("SELECT first_name, last_name FROM faculty_db.faculty_profiles WHERE user_id = ?");
                    $profStmt->execute([(int) $user['id']]);
                    $prof = $profStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!empty($prof['first_name']) || !empty($prof['last_name'])) {
                        $displayName = trim(($prof['first_name'] ?? '') . ' ' . ($prof['last_name'] ?? ''));
                    }
                }
            } catch (Throwable $e) {
                // Silently skip on db error
            }
        }

        // Guaranteed Fallback: If still an email or empty, strip email handle
        if ($displayName === '' || str_contains($displayName, '@')) {
            $rawHandle   = str_contains($displayName, '@') ? explode('@', $displayName)[0] : ($user['username'] ?? 'User');
            $displayName = ucwords(str_replace(['.', '_', '-'], ' ', $rawHandle));
        }
    }

    // -------------------------------------------------------------
    // 2. Set Session Variables
    // -------------------------------------------------------------
    $_SESSION['user_id']               = (int) $user['id'];
    $_SESSION['user_name']             = $displayName;
    $_SESSION['user_role']             = (string) ($user['role_label'] ?? $user['role_key']);
    $_SESSION['user_role_key']         = (string) $user['role_key'];
    $_SESSION['user_email']            = (string) $user['email'];
    $_SESSION['must_change_password']  = (int) ($user['must_change_password'] ?? 0);
    $_SESSION['last_activity']         = time();
    $_SESSION['login_at']              = time();

    // changes
    try {
        $facultyPdo = function_exists('facultyDb') ? facultyDb() : db();
        if ($facultyPdo) {
            // Retrieve both designated_department/department strings and department_id
            $stmt = $facultyPdo->prepare('
                SELECT department_id, designated_department, department 
                FROM faculty_db.faculty_profiles 
                WHERE user_id = ? 
                LIMIT 1
            ');
            $stmt->execute([(int) $user['id']]);
            $deptData = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($deptData) {
                $resolvedDept = trim((string) ($deptData['designated_department'] ?? $deptData['department'] ?? ''));
                $_SESSION['department_id']   = !empty($deptData['department_id']) ? (int) $deptData['department_id'] : null;
                $_SESSION['department_name'] = $resolvedDept !== '' ? $resolvedDept : null;
                $_SESSION['department']      = $resolvedDept !== '' ? $resolvedDept : null;
            } else {
                $_SESSION['department_id']   = null;
                $_SESSION['department_name'] = null;
                $_SESSION['department']      = null;
            }
        }
    } catch (Throwable $e) {
        $_SESSION['department_id']   = null;
        $_SESSION['department_name'] = null;
        $_SESSION['department']      = null;
    }

    unset($_SESSION['presence_touched_at']);
    smsTouchUserPresence((int) $user['id']);

    if (!empty($user['student_id'])) {
        $_SESSION['student_id'] = (string) $user['student_id'];
    } else {
        unset($_SESSION['student_id']);
    }

    $primaryModule = smsPrimaryModuleForRole((string) $user['role_key']);

    logActivity(
        'login',
        'Logged in successfully',
        $primaryModule,
        (int) $user['id'],
        $displayName,
        (string) $user['role_key'],
        false
    );

    return [
        'ok'           => true,
        'code'         => 'ok',
        'message'      => '',
        'alert'        => 'success',
        'show_reset'   => false,
        'locked'       => false,
        'locked_until' => null,
    ];
}

/**
 * Database-backed login.
 */
function attemptLogin(string $username, string $password): bool
{
    return smsLoginAttempt($username, $password)['ok'];
}

/**
 * Create a password-reset token. Returns raw token (for link) or null.
 */
function smsCreatePasswordResetToken(int $userId): ?string
{
    $pdo = db();
    if (!$pdo) {
        return null;
    }

    $raw = bin2hex(random_bytes(32));
    $hash = hash('sha256', $raw);

    // Invalidate previous unused tokens
    $pdo->prepare(
        'UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL'
    )->execute([$userId]);

    $pdo->prepare(
        'INSERT INTO password_resets (user_id, token_hash, expires_at, created_ip)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), ?)'
    )->execute([$userId, $hash, smsClientIp()]);

    return $raw;
}

/**
 * Consume reset token and set new password.
 */
function smsResetPasswordWithToken(string $rawToken, string $newPassword): bool
{
    $pdo = db();
    if (!$pdo || strlen($newPassword) < (int) smsSetting('min_password_length', '8')) {
        return false;
    }

    $hash = hash('sha256', $rawToken);
    $stmt = $pdo->prepare(
        'SELECT * FROM password_resets
         WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
    if (!$row) {
        return false;
    }

    $userId = (int) $row['user_id'];
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE users
             SET password_hash = ?, must_change_password = 0, password_changed_at = NOW(),
                 failed_login_attempts = 0, locked_until = NULL,
                 status = CASE WHEN status = \'locked\' THEN \'active\' ELSE status END
             WHERE id = ?'
        )->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);

        $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')
            ->execute([(int) $row['id']]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return false;
    }

    logActivity('password_reset', 'Password reset via token', 'System', $userId);
    return true;
}

/**
 * Admin / self password update.
 */
function smsSetUserPassword(int $userId, string $newPassword, bool $forceChange = false): bool
{
    $pdo = db();
    $minLen = (int) smsSetting('min_password_length', '8');
    if (!$pdo || strlen($newPassword) < $minLen) {
        return false;
    }

    $pdo->prepare(
        'UPDATE users
         SET password_hash = ?, must_change_password = ?, password_changed_at = NOW(),
             failed_login_attempts = 0, locked_until = NULL
         WHERE id = ?'
    )->execute([
        password_hash($newPassword, PASSWORD_DEFAULT),
        $forceChange ? 1 : 0,
        $userId,
    ]);

    return true;
}

function logout(): void
{
    $uid = isAuthenticated() ? getCurrentUserId() : null;
    if (isAuthenticated()) {
        $mod = smsPrimaryModuleForRole(getCurrentUserRoleKey());
        logActivity('logout', 'Logged out', $mod);
    }

    if ($uid) {
        smsMarkUserOffline((int) $uid);
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'] ?? '',
            (bool) ($params['secure'] ?? false),
            (bool) ($params['httponly'] ?? true)
        );
    }

    session_destroy();
}

/**
 * Ensure users.last_seen_at exists for online/offline presence.
 */
function smsEnsureUserPresenceColumn(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo = db();
    if (!$pdo) {
        return;
    }
    try {
        $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'last_seen_at'")->fetch();
        if (!$col) {
            $pdo->exec(
                'ALTER TABLE users
                 ADD COLUMN last_seen_at DATETIME NULL AFTER last_login_at,
                 ADD KEY idx_users_last_seen (last_seen_at)'
            );
        }
    } catch (Throwable $e) {
        // Column may already exist under race — ignore
    }
}

/**
 * Heartbeat: mark the signed-in user as online (throttled).
 * Uses PHP clock (not MySQL NOW) so Online status matches app timezone.
 */
function smsTouchUserPresence(?int $userId = null): void
{
    $userId = $userId ?? (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        return;
    }

    $now = time();
    $lastTouch = (int) ($_SESSION['presence_touched_at'] ?? 0);
    if ($lastTouch > 0 && ($now - $lastTouch) < 45) {
        return;
    }

    smsEnsureUserPresenceColumn();
    $pdo = db();
    if (!$pdo) {
        return;
    }
    try {
        $stmt = $pdo->prepare('UPDATE users SET last_seen_at = ? WHERE id = ? LIMIT 1');
        $stmt->execute([date('Y-m-d H:i:s', $now), $userId]);
        $_SESSION['presence_touched_at'] = $now;
    } catch (Throwable $e) {
        // ignore
    }
}

function smsMarkUserOffline(int $userId): void
{
    if ($userId <= 0) {
        return;
    }
    smsEnsureUserPresenceColumn();
    $pdo = db();
    if (!$pdo) {
        return;
    }
    try {
        $stmt = $pdo->prepare('UPDATE users SET last_seen_at = NULL WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
    } catch (Throwable $e) {
        // ignore
    }
}

/** True when last_seen_at is within the online window (default 5 minutes). */
function smsUserIsOnline(?string $lastSeenAt, int $onlineSeconds = 300): bool
{
    $lastSeenAt = trim((string) $lastSeenAt);
    if ($lastSeenAt === '' || $lastSeenAt === '0000-00-00 00:00:00') {
        return false;
    }
    $ts = strtotime($lastSeenAt);
    if ($ts === false) {
        return false;
    }
    return (time() - $ts) <= max(60, $onlineSeconds);
}

/**
 * Create a new user account submitted by a Department Head (defaults to pending_approval).
 */
function smsCreatePendingUser(array $data, int $createdByUserId): ?int
{
    $pdo = db();
    if (!$pdo) {
        return null;
    }

    $email = strtolower(trim($data['email'] ?? ''));
    $username = strtolower(trim($data['username'] ?? ''));
    $fullName = trim($data['full_name'] ?? '');
    $roleKey = smsNormalizeRoleKey(trim($data['role_key'] ?? 'faculty'));

    if ($email === '' || $fullName === '') {
        return null;
    }

    // Extract last name dynamically (e.g., "rimuru J tempest" -> "Tempest")
    $nameParts = array_filter(explode(' ', $fullName));
    $lastName = !empty($nameParts) ? end($nameParts) : 'User';
    $defaultPassword = ucfirst(strtolower($lastName)) . '@2026';

    $tempPassword = !empty($data['password']) ? $data['password'] : $defaultPassword;

    if ($username === '') {
        $username = str_contains($email, '@') ? explode('@', $email)[0] : $email;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO users (username, full_name, email, password_hash, role_key, status, must_change_password, created_at)
             VALUES (?, ?, ?, ?, ?, \'pending_approval\', 1, NOW())'
        );
        $stmt->execute([
            $username,
            $fullName,
            $email,
            password_hash($tempPassword, PASSWORD_DEFAULT), // Hashes your dynamic Lastname@2026 password
            $roleKey
        ]);

        $newUserId = (int) $pdo->lastInsertId();

        logActivity(
            'user_created_pending',
            'Department head created account pending approval',
            'user-management',
            $newUserId,
            $fullName,
            $roleKey
        );

        return $newUserId;
    } catch (Throwable $e) {
        error_log('SMS2 create pending user failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Retrieve all users waiting for administrator approval.
 */
function smsGetPendingUsers(): array
{
    $pdo = db();
    if (!$pdo) {
        return [];
    }
    try {
        $stmt = $pdo->query(
            'SELECT u.*, r.label AS role_label
             FROM users u
             LEFT JOIN roles r ON r.role_key = u.role_key
             WHERE u.status IN (\'pending\', \'pending_approval\')
             ORDER BY u.id DESC'
        );
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Approve a pending user account, changing status to active.
 */
function smsApprovePendingUser(int $userId, int $adminUserId): bool
{
    $pdo = db();
    if (!$pdo) {
        return false;
    }

    try {
        $stmt = $pdo->prepare(
            'UPDATE users 
             SET status = \'active\', failed_login_attempts = 0, locked_until = NULL 
             WHERE id = ? AND status IN (\'pending\', \'pending_approval\')'
        );
        $stmt->execute([$userId]);

        if ($stmt->rowCount() > 0) {
            logActivity(
                'user_approved',
                'Administrator approved account',
                'user-management',
                $userId
            );
            return true;
        }
        return false;
    } catch (Throwable $e) {
        error_log('SMS2 approve pending user failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Reject a pending user account by marking it inactive.
 */
function smsRejectPendingUser(int $userId, int $adminUserId): bool
{
    $pdo = db();
    if (!$pdo) {
        return false;
    }

    try {
        $stmt = $pdo->prepare(
            'UPDATE users SET status = \'inactive\' WHERE id = ? AND status IN (\'pending\', \'pending_approval\')'
        );
        $stmt->execute([$userId]);

        logActivity(
            'user_rejected',
            'Administrator rejected pending account',
            'user-management',
            $userId
        );
        return true;
    } catch (Throwable $e) {
        return false;
    }
}