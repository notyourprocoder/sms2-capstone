<?php
/**
 * Faculty Records
 * Purpose: View and update active faculty information for the Secretary's department only.
 */
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../controllers/faculty-data.php';

// 1. Fetch base scoped faculty list
$facultyList = getScopedFacultyList();

// 2. Resolve the logged-in Secretary's department
$userDepartment = trim((string) (
    $_SESSION['department_name']
    ?? $_SESSION['user_department']
    ?? $_SESSION['designated_department']
    ?? $_SESSION['department']
    ?? $_SESSION['dept_name']
    ?? $_SESSION['dept']
    ?? $_SESSION['user']['department_name']
    ?? $_SESSION['user']['department']
    ?? ''
));

// Fallback lookup via DB if session department isn't populated
if ($userDepartment === '') {
    $sessionUserId = (int) (
        $_SESSION['user_id']
        ?? $_SESSION['user']['id']
        ?? $_SESSION['id']
        ?? $_SESSION['account_id']
        ?? 0
    );
    $sessionEmail = $_SESSION['user_email']
        ?? $_SESSION['user']['email']
        ?? $_SESSION['email']
        ?? null;

    if ($sessionUserId || $sessionEmail) {
        try {
            $pdo = function_exists('facultyDb') ? facultyDb() : ($conn ?? $db ?? null);
            if ($pdo) {
                $stmtUser = $pdo->prepare("
                    SELECT designated_department, department
                    FROM faculty_db.faculty_profiles
                    WHERE user_id = :uid
                       OR (:email1 IS NOT NULL AND email = :email2)
                    LIMIT 1
                ");
                $stmtUser->execute([
                    'uid'    => $sessionUserId,
                    'email1' => $sessionEmail,
                    'email2' => $sessionEmail
                ]);
                $prof = $stmtUser->fetch(PDO::FETCH_ASSOC);
                if ($prof) {
                    $userDepartment = trim($prof['designated_department'] ?? $prof['department'] ?? '');
                    if ($userDepartment !== '') {
                        $_SESSION['department_name'] = $userDepartment;
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('faculty-records.php department resolution error: ' . $e->getMessage());
        }
    }
}

/**
 * Normalizes department string into uniform codes
 */
function normalizeDepartmentCode(string $dept): string {
    $deptUpper = strtoupper(trim($dept));
    if ($deptUpper === '') return '';

    if (str_contains($deptUpper, 'INFORMATION TECHNOLOGY') || str_contains($deptUpper, 'BSIT') || $deptUpper === 'IT') {
        return 'BSIT';
    }
    if (str_contains($deptUpper, 'BUSINESS ADMINISTRATION') || str_contains($deptUpper, 'BSBA')) {
        return 'BSBA';
    }
    if (str_contains($deptUpper, 'CRIMINOLOGY') || str_contains($deptUpper, 'BS CRIM') || str_contains($deptUpper, 'BSCRIM')) {
        return 'BS CRIM';
    }
    if (str_contains($deptUpper, 'COMPUTER SCIENCE') || str_contains($deptUpper, 'BSCS')) {
        return 'BSCS';
    }
    if (str_contains($deptUpper, 'EDUCATION') || str_contains($deptUpper, 'BSED') || str_contains($deptUpper, 'BEED')) {
        return 'EDUC';
    }
    if (str_contains($deptUpper, 'HOSPITALITY') || str_contains($deptUpper, 'BSHM')) {
        return 'BSHM';
    }

    return preg_replace('/[^A-Z0-9]/', '', $deptUpper);
}

// 3. Strict Filtering: Department Scoping + Exclude Dean/Dept Head + Exclude Pending/Rejected
$targetCode = normalizeDepartmentCode($userDepartment !== '' ? $userDepartment : 'BSIT');

$facultyList = array_values(array_filter($facultyList, function ($f) use ($targetCode) {
    // --- A. Department Filtering ---
    $rawDept = $f['designated_department'] 
        ?? $f['department'] 
        ?? $f['department_name'] 
        ?? $f['dept'] 
        ?? $f['dept_name'] 
        ?? '';
    $recordCode = normalizeDepartmentCode((string)$rawDept);
    if ($recordCode === '' || $targetCode === '' || $recordCode !== $targetCode) {
        return false;
    }

    // --- B. Status Filtering (Exclude Pending, Rejected, Resigned) ---
    $status = strtolower(trim((string) ($f['profile_status'] ?? $f['employment_status'] ?? $f['status'] ?? 'active')));
    if (in_array($status, ['pending approval', 'pending', 'rejected', 'resigned'], true)) {
        return false;
    }

    // --- C. Role Filtering (Exclude Dean & Department Head) ---
    $role = strtolower(trim((string) ($f['role'] ?? $f['position'] ?? $f['user_role'] ?? $f['designation'] ?? '')));
    $email = strtolower(trim((string) ($f['email'] ?? '')));
    $firstName = strtolower(trim((string) ($f['first_name'] ?? '')));
    $lastName = strtolower(trim((string) ($f['last_name'] ?? '')));

    if (
        str_contains($role, 'dean') || 
        str_contains($role, 'department head') || 
        str_contains($role, 'dept head') || 
        str_contains($role, 'head') ||
        str_contains($email, 'dean') ||
        str_contains($email, 'depthead') ||
        str_contains($firstName, 'dean') ||
        str_contains($lastName, 'dean')
    ) {
        return false;
    }

    return true;
}));

// 4. UI Search and Status Dropdown Filters
$searchTerm   = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

if ($searchTerm !== '') {
    $needle = strtolower($searchTerm);
    $facultyList = array_values(array_filter($facultyList, function ($f) use ($needle) {
        $haystack = strtolower(trim(($f['first_name'] ?? '') . ' ' . ($f['last_name'] ?? '') . ' ' . ($f['faculty_id'] ?? '')));
        return str_contains($haystack, $needle);
    }));
}

if ($statusFilter !== '') {
    $facultyList = array_values(array_filter($facultyList, function ($f) use ($statusFilter) {
        return strcasecmp((string) ($f['employment_status'] ?? ''), $statusFilter) === 0;
    }));
}

$facultyCount = count($facultyList);

// 5. Pagination
$perPage     = 10;
$facultyPage = isset($_GET['page']) && is_numeric($_GET['page']) ? (int) $_GET['page'] : 1;
if ($facultyPage < 1) { $facultyPage = 1; }
$totalPages  = max(1, (int) ceil($facultyCount / $perPage));
if ($facultyPage > $totalPages) { $facultyPage = $totalPages; }
$offset      = ($facultyPage - 1) * $perPage;
$pagedFacultyList = array_slice($facultyList, $offset, $perPage);

function renderFacultyRows(array $facultyList, string $searchTerm): string {
    ob_start();
    if (empty($facultyList)) {
        ?>
        <tr>
            <td colspan="5" class="text-center py-4" style="color:var(--fr-text-muted);">
                No active faculty records found<?= $searchTerm !== '' ? ' matching your search.' : ' in your department.' ?>
            </td>
        </tr>
        <?php
    }
    foreach ($facultyList as $f) {
        $fullName    = trim(($f['first_name'] ?? '') . ' ' . ($f['last_name'] ?? ''));
        $facultyId   = (string) ($f['faculty_id'] ?? '');
        $dept        = (string) ($f['designated_department'] ?? $f['department'] ?? $f['dept'] ?? '—');
        $phone       = (string) ($f['phone'] ?? '—');
        $email       = (string) ($f['email'] ?? '—');
        $status      = (string) ($f['profile_status'] ?? $f['employment_status'] ?? 'Active');
        
        $statusClass = 'fr-badge fr-badge-success';
        if (strcasecmp($status, 'Pending Approval') === 0) {
            $statusClass = 'fr-badge fr-badge-warning';
        } elseif (strcasecmp($status, 'Rejected') === 0 || strcasecmp($status, 'Resigned') === 0) {
            $statusClass = 'fr-badge fr-badge-danger';
        }

        $nameEsc   = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
        $idEsc     = htmlspecialchars($facultyId, ENT_QUOTES, 'UTF-8');
        $deptEsc   = htmlspecialchars($dept, ENT_QUOTES, 'UTF-8');
        $phoneEsc  = htmlspecialchars($phone, ENT_QUOTES, 'UTF-8');
        $emailEsc  = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        $statusEsc = htmlspecialchars($status, ENT_QUOTES, 'UTF-8');
        echo <<<HTML
        <tr>
            <td>
                <div class="d-flex align-items-center gap-3">
                    <div class="fr-avatar flex-shrink-0">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div>
                        <div class="fr-cell-strong">{$nameEsc}</div>
                        <div class="fr-cell-meta">ID: {$idEsc}</div>
                    </div>
                </div>
            </td>
            <td><span class="fr-cell-dept">{$deptEsc}</span></td>
            <td>
                <div class="fr-cell-strong">{$phoneEsc}</div>
                <div class="fr-cell-meta">{$emailEsc}</div>
            </td>
            <td><span class="{$statusClass}">{$statusEsc}</span></td>
            <td class="text-end">
                <div class="fr-table-actions">
                    <button class="fr-btn fr-btn-ghost fr-btn-sm fr-btn-icon-only" title="View Profile" onclick="viewProfile('{$idEsc}')" style="color:var(--fr-primary) !important;">
                        <i class="fas fa-eye"></i>
                    </button>
                    <button class="fr-btn fr-btn-ghost fr-btn-sm fr-btn-icon-only" title="Update Info" onclick="updateInfo('{$idEsc}')" style="color:#D97706 !important;">
                        <i class="fas fa-edit"></i>
                    </button>
                </div>
            </td>
        </tr>
        HTML;
    }
    return ob_get_clean();
}

function renderFacultyPagination(int $page, int $totalPages): string {
    ob_start();
    ?>
    <li><button class="page-btn" onclick="fetchFacultyPage(<?= $page - 1 ?>)" <?= $page <= 1 ? 'disabled' : '' ?>>Previous</button></li>
    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <li><button class="page-btn <?= $page === $i ? 'active' : '' ?>" onclick="fetchFacultyPage(<?= $i ?>)"><?= $i ?></button></li>
    <?php endfor; ?>
    <li><button class="page-btn" style="color:var(--fr-primary);" onclick="fetchFacultyPage(<?= $page + 1 ?>)" <?= $page >= $totalPages ? 'disabled' : '' ?>>Next</button></li>
    <?php
    return ob_get_clean();
}

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
if ($isAjax) {
    $start = $facultyCount > 0 ? $offset + 1 : 0;
    $end   = min($offset + $perPage, $facultyCount);
    header('Content-Type: application/json');
    echo json_encode([
        'tbody'      => renderFacultyRows($pagedFacultyList, $searchTerm),
        'pagination' => renderFacultyPagination($facultyPage, $totalPages),
        'count'      => $facultyCount,
        'rangeText'  => "Showing {$start} to {$end} of {$facultyCount} records",
    ]);
    exit;
}

$pageTitle    = 'Faculty Records';
$activeModule = 'faculty';
$activePage   = 'faculty-records';
$breadcrumbs  = [
    ['label' => 'Faculty Management', 'url' => BASE_URL . '/modules/faculty/index.php'],
    ['label' => 'Secretary', 'url' => BASE_URL . '/modules/faculty/users/secretary/index.php'],
    ['label' => 'Faculty Records', 'url' => null],
];

require_once __DIR__ . '/../../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../../includes/layout-start.php';
require_once __DIR__ . '/../../../../includes/nav-icons.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/modules/faculty/assets/css/faculty.css?v=<?= time(); ?>">

<style>
    :root,
    [data-theme="light"] {
        --fr-primary:        #4338CA;
        --fr-primary-hover:  #3730A3;
        --fr-primary-soft:   #EEF2FF;
        --fr-primary-ring:   rgba(67, 56, 202, 0.15);

        --fr-surface:        #FFFFFF;
        --fr-surface-muted:  #F8FAFC;
        --fr-surface-subtle: #F1F5F9;
        --fr-page-bg:        inherit;

        --fr-text-strong:    #0F172A;
        --fr-text-body:      #334155;
        --fr-text-muted:     #64748B;
        --fr-text-faint:     #94A3B8;

        --fr-border:         rgba(15, 23, 42, 0.08);
        --fr-border-strong:  rgba(15, 23, 42, 0.12);

        --fr-success-bg:     #ECFDF5;
        --fr-success-text:   #047857;
        --fr-success-ring:   rgba(16, 185, 129, 0.18);
        --fr-warning-bg:     #FFFBEB;
        --fr-warning-text:   #B45309;
        --fr-info-bg:        #EFF6FF;
        --fr-info-text:      #1D4ED8;
        --fr-danger-bg:      #FEF2F2;
        --fr-danger-text:    #B91C1C;

        --fr-shadow-sm:      0 1px 2px rgba(15, 23, 42, 0.04), 0 1px 3px rgba(15, 23, 42, 0.03);
        --fr-shadow-md:      0 4px 8px -2px rgba(15, 23, 42, 0.06), 0 2px 4px -2px rgba(15, 23, 42, 0.04);

        --fr-radius-sm:      8px;
        --fr-radius-md:      10px;
        --fr-radius-lg:      14px;
        --fr-radius-xl:      16px;
        --fr-radius-pill:    999px;

        --fr-ease:           cubic-bezier(0.4, 0, 0.2, 1);
    }

    [data-theme="dark"] {
        --fr-primary:        #818CF8;
        --fr-primary-hover:  #A5B4FC;
        --fr-primary-soft:   rgba(99, 102, 241, 0.20);
        --fr-primary-ring:   rgba(129, 140, 248, 0.25);

        --fr-surface:        rgba(18, 28, 52, 0.88);
        --fr-surface-muted:  rgba(15, 23, 42, 0.55);
        --fr-surface-subtle: rgba(15, 23, 42, 0.75);
        --fr-page-bg:        inherit;

        --fr-text-strong:    #F1F5F9;
        --fr-text-body:      #CBD5E1;
        --fr-text-muted:     #94A3B8;
        --fr-text-faint:     #64748B;

        --fr-border:         rgba(255, 255, 255, 0.07);
        --fr-border-strong:  rgba(255, 255, 255, 0.11);

        --fr-success-bg:     rgba(6, 78, 59, 0.55);
        --fr-success-text:   #6EE7B7;
        --fr-success-ring:   rgba(16, 185, 129, 0.28);
        --fr-warning-bg:     rgba(120, 53, 15, 0.55);
        --fr-warning-text:   #FDE047;
        --fr-info-bg:        rgba(30, 58, 138, 0.55);
        --fr-info-text:      #93C5FD;
        --fr-danger-bg:      rgba(127, 29, 29, 0.55);
        --fr-danger-text:    #FCA5A5;

        --fr-shadow-sm:      0 1px 2px rgba(0, 0, 0, 0.3), 0 1px 3px rgba(0, 0, 0, 0.2);
        --fr-shadow-md:      0 4px 12px -2px rgba(0, 0, 0, 0.4), 0 2px 6px -2px rgba(0, 0, 0, 0.3);
    }

    .fr-wrapper {
        font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
        color: var(--fr-text-body);
        line-height: 1.55;
        -webkit-font-smoothing: antialiased;
    }

    .fr-page-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1.25rem;
        flex-wrap: wrap;
        padding: 1.25rem 0 1.5rem;
    }
    .fr-kicker {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.7rem;
        font-weight: 650;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--fr-primary);
        margin-bottom: 0.45rem;
    }
    .fr-kicker::before {
        content: '';
        width: 6px; height: 6px;
        border-radius: 50%;
        background: var(--fr-primary);
        box-shadow: 0 0 0 3px var(--fr-primary-ring);
    }
    .fr-title {
        margin: 0;
        font-size: 1.55rem;
        font-weight: 750;
        letter-spacing: -0.025em;
        color: var(--fr-text-strong);
        line-height: 1.2;
    }
    .fr-title i { color: var(--fr-primary); }
    .fr-subtitle {
        margin: 0.4rem 0 0;
        font-size: 0.88rem;
        color: var(--fr-text-muted);
        font-weight: 450;
    }

    .fr-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.5rem 0.9rem;
        border-radius: var(--fr-radius-sm);
        font-size: 0.82rem;
        font-weight: 600;
        letter-spacing: -0.01em;
        border: 1px solid transparent;
        cursor: pointer;
        transition: all 0.16s var(--fr-ease);
        text-decoration: none !important;
        white-space: nowrap;
        line-height: 1.3;
    }
    .fr-btn-primary {
        background: var(--fr-primary);
        color: #FFFFFF !important;
        border-color: var(--fr-primary);
        box-shadow: var(--fr-shadow-sm);
    }
    .fr-btn-primary:hover {
        background: var(--fr-primary-hover);
        border-color: var(--fr-primary-hover);
        transform: translateY(-1px);
        box-shadow: var(--fr-shadow-md);
    }
    .fr-btn-ghost {
        background: var(--fr-surface);
        color: var(--fr-text-body) !important;
        border-color: var(--fr-border-strong);
    }
    .fr-btn-ghost:hover {
        background: var(--fr-surface-muted);
        color: var(--fr-text-strong) !important;
        border-color: var(--fr-text-muted);
        transform: translateY(-1px);
        box-shadow: var(--fr-shadow-sm);
    }
    .fr-btn-success {
        background: var(--fr-success-bg);
        color: var(--fr-success-text) !important;
        border-color: var(--fr-success-ring);
    }
    .fr-btn-sm { padding: 0.38rem 0.72rem; font-size: 0.76rem; }
    .fr-btn-icon-only {
        width: 32px; height: 32px;
        padding: 0;
        display: inline-grid;
        place-items: center;
    }

    .fr-card {
        background: var(--fr-surface);
        border: 1px solid var(--fr-border);
        border-radius: var(--fr-radius-lg);
        box-shadow: var(--fr-shadow-sm);
        transition: box-shadow 0.2s var(--fr-ease);
        overflow: hidden;
    }
    .fr-card-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: 0.95rem 1.25rem;
        border-bottom: 1px solid var(--fr-border);
        background: var(--fr-surface);
    }
    .fr-card-title {
        margin: 0;
        font-size: 0.9rem;
        font-weight: 700;
        letter-spacing: -0.01em;
        color: var(--fr-text-strong);
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }
    .fr-card-foot {
        padding: 0.85rem 1.25rem;
        border-top: 1px solid var(--fr-border);
        background: var(--fr-surface-subtle);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
    }

    .fr-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.32rem;
        padding: 0.34em 0.78em;
        border-radius: var(--fr-radius-pill);
        font-size: 0.7rem;
        font-weight: 750;
        letter-spacing: 0.01em;
        border: 1px solid transparent;
        line-height: 1.2;
    }
    .fr-badge-success {
        background: var(--fr-success-bg);
        color: var(--fr-success-text);
        border-color: var(--fr-success-ring);
    }
    .fr-badge-warning {
        background: var(--fr-warning-bg);
        color: var(--fr-warning-text);
    }
    .fr-badge-danger {
        background: var(--fr-danger-bg);
        color: var(--fr-danger-text);
    }

    .fr-form-label {
        display: block;
        font-size: 0.72rem;
        font-weight: 750;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--fr-primary);
        margin-bottom: 0.35rem;
    }
    .fr-input,
    .fr-select {
        width: 100%;
        background: var(--fr-surface);
        color: var(--fr-text-body);
        border: 1px solid var(--fr-border-strong);
        border-radius: var(--fr-radius-sm);
        font-size: 0.85rem;
        padding: 0.58rem 0.8rem;
        line-height: 1.4;
        transition: all 0.15s var(--fr-ease);
        font-family: inherit;
    }
    .fr-input:focus,
    .fr-select:focus {
        outline: none;
        border-color: var(--fr-primary);
        box-shadow: 0 0 0 3px var(--fr-primary-ring);
    }
    .fr-select-sm {
        width: auto;
        padding: 0.35rem 1.75rem 0.35rem 0.7rem;
        font-size: 0.76rem;
    }
    .fr-input-group {
        display: flex;
        align-items: stretch;
    }
    .fr-input-group-prepend {
        display: inline-flex;
        align-items: center;
        padding: 0 0.75rem;
        border: 1px solid var(--fr-border-strong);
        border-right: none;
        border-radius: var(--fr-radius-sm) 0 0 var(--fr-radius-sm);
        background: var(--fr-primary-soft);
        color: var(--fr-primary);
        font-size: 0.82rem;
    }
    .fr-input-group > .fr-input {
        border-top-left-radius: 0;
        border-bottom-left-radius: 0;
    }

    .fr-table-wrap { overflow-x: auto; }
    .fr-table {
        width: 100%;
        color: var(--fr-text-body);
        border-collapse: separate;
        border-spacing: 0;
        margin: 0;
    }
    .fr-table thead th {
        font-size: 0.7rem;
        font-weight: 750;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--fr-text-muted);
        background: var(--fr-surface-subtle);
        border-bottom: 1px solid var(--fr-border);
        padding: 0.8rem 1rem;
        text-align: left;
        white-space: nowrap;
    }
    .fr-table tbody td {
        padding: 0.8rem 1rem;
        border-bottom: 1px solid var(--fr-border);
        font-size: 0.84rem;
        background: var(--fr-surface);
        vertical-align: middle;
    }
    .fr-table tbody tr:hover td { background: var(--fr-surface-muted); }
    .fr-avatar {
        width: 40px; height: 40px;
        border-radius: 50%;
        background: var(--fr-primary-soft);
        color: var(--fr-primary);
        display: inline-grid;
        place-items: center;
        flex-shrink: 0;
        font-size: 1.05rem;
        font-weight: 700;
    }
    .fr-cell-strong { font-weight: 700; color: var(--fr-text-strong); }
    .fr-cell-meta { font-size: 0.74rem; color: var(--fr-text-muted); margin-top: 2px; }
    .fr-cell-dept { font-weight: 750; color: var(--fr-primary); }
    .fr-table-actions { display: flex; gap: 0.35rem; justify-content: flex-end; }

    .fr-pagination {
        display: flex;
        align-items: center;
        gap: 0.35rem;
        margin: 0; padding: 0;
        list-style: none;
    }
    .fr-pagination .page-btn {
        min-width: 30px; height: 30px;
        padding: 0 0.6rem;
        display: inline-grid;
        place-items: center;
        border-radius: var(--fr-radius-pill);
        border: 1px solid var(--fr-border);
        background: var(--fr-surface);
        color: var(--fr-text-body);
        font-size: 0.76rem;
        font-weight: 600;
        cursor: pointer;
    }
    .fr-pagination .page-btn.active {
        background: var(--fr-primary);
        color: #FFFFFF !important;
        border-color: var(--fr-primary);
    }
    .fr-pagination .page-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    .fr-modal-content {
        background: var(--fr-surface);
        color: var(--fr-text-body);
        border: 1px solid var(--fr-border);
        border-radius: var(--fr-radius-xl) !important;
    }
    .fr-modal-head {
        padding: 1rem 1.25rem;
        border-bottom: 1px solid var(--fr-border);
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .fr-modal-title {
        margin: 0;
        font-size: 1rem;
        font-weight: 750;
        color: var(--fr-text-strong);
    }
    .fr-modal-body { padding: 1.25rem; }
    .fr-modal-foot {
        padding: 0.9rem 1.25rem 1.25rem;
        border-top: 1px solid var(--fr-border);
        display: flex;
        justify-content: flex-end;
        gap: 0.5rem;
    }
</style>

<?php renderBreadcrumbs($breadcrumbs); ?>

<div class="fr-wrapper">

    <!-- PAGE HEADER -->
    <header class="fr-page-header">
        <div>
            <span class="fr-kicker">Secretary · Faculty Management</span>
            <h2 class="fr-title">
                <i class="fas fa-id-badge me-2"></i>Faculty Records
            </h2>
            <p class="fr-subtitle">Manage profile and contact information in your department directory</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button class="fr-btn fr-btn-success">
                <i class="fas fa-file-excel"></i> Export Directory
            </button>
        </div>
    </header>

    <!-- MAIN GRID LAYOUT -->
    <div class="row g-4">

        <!-- Left Column: Search & Filters -->
        <div class="col-lg-5 col-xl-4">
            <section class="fr-card h-100">
                <div class="fr-card-head">
                    <h6 class="fr-card-title"><i class="fas fa-sliders-h"></i>Filter Records</h6>
                </div>
                <div class="p-4">
                    <form method="GET" action="" id="facultyFilterForm" onsubmit="event.preventDefault(); fetchFacultyPage(1);">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="fr-form-label">Search Faculty</label>
                                <div class="fr-input-group">
                                    <span class="fr-input-group-prepend"><i class="fas fa-search small"></i></span>
                                    <input type="text" name="search" id="facultySearchInput" class="fr-input" placeholder="Search by name or ID..." value="<?= htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8') ?>" oninput="triggerFacultyAutoSearch()">
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="fr-form-label">Employment Status</label>
                                <select name="status" id="facultyStatusSelect" class="fr-select" onchange="fetchFacultyPage(1)">
                                    <option value="">All Statuses</option>
                                    <?php foreach (['Regular', 'Probationary', 'Part-Time'] as $statusOpt): ?>
                                        <option value="<?= $statusOpt ?>" <?= strcasecmp($statusFilter, $statusOpt) === 0 ? 'selected' : '' ?>><?= $statusOpt ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 pt-2 d-flex gap-2">
                                <button type="submit" class="fr-btn fr-btn-primary w-100 py-2">Apply Filter</button>
                                <button type="button" class="fr-btn fr-btn-ghost w-100 py-2" onclick="resetFacultyFilters()">Reset</button>
                            </div>
                        </div>
                    </form>
                </div>
            </section>
        </div>

        <!-- Right Column: Faculty Directory Table -->
        <div class="col-lg-7 col-xl-8">
            <section class="fr-card">
                <div class="fr-card-head">
                    <h6 class="fr-card-title">
                        <i class="fas fa-users-cog" style="color:#7C3AED;"></i>Faculty Directory
                        <span class="fr-badge fr-badge-success ms-2" id="facultyCountBadge"><?= (int) $facultyCount ?> Registered</span>
                    </h6>
                    <div class="d-flex align-items-center gap-2">
                        <span class="small" style="color:var(--fr-text-muted);">Show:</span>
                        <select class="fr-select fr-select-sm">
                            <option>10 per page</option>
                            <option>25 per page</option>
                            <option>50 per page</option>
                        </select>
                    </div>
                </div>
                <div class="fr-table-wrap">
                    <table class="fr-table align-middle">
                        <thead>
                            <tr>
                                <th>Faculty Member</th>
                                <th>Dept</th>
                                <th>Contact Information</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="facultyTableBody">
                            <?= renderFacultyRows($pagedFacultyList, $searchTerm) ?>
                        </tbody>
                    </table>
                </div>
                <div class="fr-card-foot">
                    <small id="facultyRangeText" style="color:var(--fr-text-muted); font-weight:600;"><?php
                        $start = $facultyCount > 0 ? $offset + 1 : 0;
                        $end   = min($offset + $perPage, $facultyCount);
                        echo "Showing {$start} to {$end} of {$facultyCount} records";
                    ?></small>
                    <ul class="fr-pagination" id="facultyPagination">
                        <?= renderFacultyPagination($facultyPage, $totalPages) ?>
                    </ul>
                </div>
            </section>
        </div>

    </div>

    <!-- VIEW PROFILE MODAL -->
    <div class="modal fade" id="profileModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content fr-modal-content">
                <div class="fr-modal-head">
                    <h5 class="fr-modal-title"><i class="fas fa-id-card me-2"></i>Faculty Profile</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="fr-modal-body">
                    <div class="row g-4">
                        <div class="col-md-4 text-center">
                            <div class="fr-avatar mx-auto mb-3" style="width:72px; height:72px; font-size:1.8rem;">
                                <i class="fas fa-user-graduate"></i>
                            </div>
                            <h5 style="font-weight:750; color:var(--fr-text-strong);" id="modalName">Faculty Member</h5>
                            <p class="fr-cell-meta mb-3">Instructor</p>
                            <span class="fr-badge fr-badge-success">Active</span>
                        </div>
                        <div class="col-md-8">
                            <div class="mb-4">
                                <h6 class="fr-form-label mb-3"><i class="fas fa-info-circle me-2"></i>Contact Details</h6>
                                <div class="p-3 border rounded">
                                    <div><strong>Faculty ID:</strong> F-001</div>
                                    <div><strong>Email:</strong> faculty@bestlink.edu.ph</div>
                                    <div><strong>Contact:</strong> +63 912 345 6789</div>
                                    <div><strong>Department:</strong> <?= htmlspecialchars($userDepartment ?: 'BSIT', ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="fr-modal-foot">
                    <button type="button" class="fr-btn fr-btn-ghost" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="fr-btn fr-btn-primary" onclick="updateInfo()">Edit Details</button>
                </div>
            </div>
        </div>
    </div>

    <!-- UPDATE INFORMATION MODAL -->
    <div class="modal fade" id="updateModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content fr-modal-content">
                <div class="fr-modal-head">
                    <h5 class="fr-modal-title"><i class="fas fa-edit me-2"></i>Update Directory Record</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="fr-modal-body">
                    <div class="mb-3">
                        <label class="fr-form-label">Contact Number</label>
                        <input type="text" class="fr-input" value="+63 912 345 6789">
                    </div>
                    <div class="mb-3">
                        <label class="fr-form-label">Email Address</label>
                        <input type="email" class="fr-input" value="faculty@bestlink.edu.ph">
                    </div>
                    <div class="mb-3">
                        <label class="fr-form-label">Address</label>
                        <textarea class="fr-input" rows="2">123 Main Street, Quezon City</textarea>
                    </div>
                </div>
                <div class="fr-modal-foot">
                    <button type="button" class="fr-btn fr-btn-ghost" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="fr-btn fr-btn-primary">Save Changes</button>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
function viewProfile(id) {
    const modal = new bootstrap.Modal(document.getElementById('profileModal'));
    modal.show();
}
function updateInfo(id) {
    const modal = new bootstrap.Modal(document.getElementById('updateModal'));
    modal.show();
}

let facultySearchDebounce = null;

function triggerFacultyAutoSearch() {
    clearTimeout(facultySearchDebounce);
    facultySearchDebounce = setTimeout(function() {
        fetchFacultyPage(1);
    }, 300);
}

function fetchFacultyPage(page) {
    if (page < 1) return;

    const search = document.getElementById('facultySearchInput').value;
    const status = document.getElementById('facultyStatusSelect').value;
    const params = new URLSearchParams({ search: search, status: status, page: page });

    fetch('?' + params.toString(), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        document.getElementById('facultyTableBody').innerHTML = data.tbody;
        document.getElementById('facultyPagination').innerHTML = data.pagination;
        document.getElementById('facultyRangeText').textContent = data.rangeText;
        document.getElementById('facultyCountBadge').textContent = data.count + ' Registered';
    })
    .catch(error => console.error('Error updating faculty records:', error));
}

function resetFacultyFilters() {
    document.getElementById('facultySearchInput').value = '';
    document.getElementById('facultyStatusSelect').value = '';
    fetchFacultyPage(1);
}
</script>

<?php require_once __DIR__ . '/../../../../includes/layout-end.php'; ?>