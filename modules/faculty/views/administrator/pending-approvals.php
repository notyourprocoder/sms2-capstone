<?php
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../includes/authentication.php';
require_once __DIR__ . '/../../controllers/FacultyController.php';

requireAuth();

$pdo = db();
$message = '';
$messageType = 'success';

// Ensure CSRF Token initialized
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle Approval / Rejection Post Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        $message = "Invalid security token. Please try again.";
        $messageType = "danger";
    } else {
        $action = $_POST['action'] ?? '';
        $userId = (int)($_POST['user_id'] ?? 0);

        if ($userId > 0) {
            try {
                $pdo->beginTransaction();

                if ($action === 'approve') {
                    // 1. Fetch first_name and last_name directly from faculty_db.faculty_profiles
                    $profileStmt = $pdo->prepare("SELECT first_name, last_name FROM faculty_db.faculty_profiles WHERE user_id = :user_id");
                    $profileStmt->execute([':user_id' => $userId]);
                    $profileData = $profileStmt->fetch(PDO::FETCH_ASSOC);

                    $firstName = trim($profileData['first_name'] ?? '');
                    $lastName  = trim($profileData['last_name'] ?? '');
                    $fullName  = trim($firstName . ' ' . $lastName);

                    // Fallback: If faculty profile missing, check full_name in sms2_db
                    if ($lastName === '') {
                        $userStmt = $pdo->prepare("SELECT full_name FROM sms2_db.users WHERE id = :user_id");
                        $userStmt->execute([':user_id' => $userId]);
                        $userData = $userStmt->fetch(PDO::FETCH_ASSOC);
                        
                        $fullName = trim($userData['full_name'] ?? '');
                        $nameParts = array_filter(explode(' ', $fullName));
                        $lastName = !empty($nameParts) ? end($nameParts) : 'User';
                    }

                    // 2. Generate dynamic password (e.g., "Mercer@2026")
                    $defaultPassword = ucfirst(strtolower($lastName)) . '@2026';
                    $hashedPassword  = password_hash($defaultPassword, PASSWORD_DEFAULT);

                    // 3. Activate account, SET full_name, update password_hash, and enforce password change
                    $stmt1 = $pdo->prepare("
                        UPDATE sms2_db.users 
                        SET status = 'active',
                            full_name = :full_name,
                            password_hash = :password_hash,
                            must_change_password = 1 
                        WHERE id = :user_id
                    ");
                    $stmt1->execute([
                        ':full_name'     => $fullName,
                        ':password_hash' => $hashedPassword,
                        ':user_id'       => $userId
                    ]);

                    // 4. Activate faculty profile - status the Dean/Admin
                    // sees ("Active") AND the underlying request_status
                    // ("approved") both need to move together. Only
                    // updating profile_status left request_status stuck
                    // on its default 'pending' forever.
                    $stmt2 = $pdo->prepare("UPDATE faculty_db.faculty_profiles SET profile_status = 'Active', request_status = 'approved' WHERE user_id = :user_id");
                    $stmt2->execute([':user_id' => $userId]);

                    // 5. Ensure a bridging faculty_db.faculty record exists.
                    //
                    // Evaluations (and the Peer Evaluation directory, Dean
                    // summary, etc.) all key off faculty.faculty_id - NEVER
                    // faculty_profiles.id. A profile with no matching faculty
                    // row shows up everywhere as "NOT LINKED" and can never
                    // be evaluated, no matter how "Active"/"approved" it is.
                    // Approval is the right moment to create that row if it
                    // doesn't already exist, using the same email/faculty_no
                    // bridging rule used everywhere else in this app.
                    //
                    // Wrapped in its own try/catch, separate from the outer
                    // one: if this step fails (e.g. an enum value mismatch),
                    // the account should still be approved/activated rather
                    // than the whole approval being rolled back over a
                    // linking problem the Dean/Admin can fix later.
                    try {
                        $fullProfileStmt = $pdo->prepare("SELECT * FROM faculty_db.faculty_profiles WHERE user_id = :user_id LIMIT 1");
                        $fullProfileStmt->execute([':user_id' => $userId]);
                        $fp = $fullProfileStmt->fetch(PDO::FETCH_ASSOC);

                        if ($fp) {
                            $emailParam = !empty($fp['email']) ? $fp['email'] : null;

                            $checkFacultyStmt = $pdo->prepare("
                                SELECT faculty_id FROM faculty_db.faculty
                                WHERE (:email_check IS NOT NULL AND email = :email_val)
                                   OR faculty_no = :faculty_no
                                LIMIT 1
                            ");
                            $checkFacultyStmt->execute([
                                ':email_check' => $emailParam,
                                ':email_val'   => $emailParam,
                                ':faculty_no'  => $fp['faculty_id'] ?? '',
                            ]);
                            $existingFacultyId = $checkFacultyStmt->fetchColumn();

                            if (!$existingFacultyId) {
                                $departmentId = null;
                                if (!empty($fp['designated_department'])) {
                                    $deptIdStmt = $pdo->prepare("SELECT department_id FROM faculty_db.departments WHERE code = :code LIMIT 1");
                                    $deptIdStmt->execute([':code' => $fp['designated_department']]);
                                    $departmentId = $deptIdStmt->fetchColumn() ?: null;
                                }

                                $insertFacultyStmt = $pdo->prepare("
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
                                $insertFacultyStmt->execute([
                                    ':faculty_no'           => $fp['faculty_id'] ?? null,
                                    ':external_user_id'     => (string) $userId,
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
                            }
                        }
                    } catch (Throwable $linkError) {
                        // Don't let a linking hiccup block the approval itself.
                        error_log('Auto-link faculty record on approval failed for user_id=' . $userId . ': ' . $linkError->getMessage());
                    }

                    $pdo->commit();
                    $message = "Account successfully approved! Default password set to: " . htmlspecialchars($defaultPassword);
                    $messageType = "success";

                } elseif ($action === 'reject') {
                    // Reject account and update profile status - same fix
                    // as approve: request_status must move to 'rejected'
                    // alongside profile_status, or it's left stuck on
                    // 'pending' even though the account itself is rejected.
                    $stmt1 = $pdo->prepare("UPDATE sms2_db.users SET status = 'rejected' WHERE id = :user_id");
                    $stmt1->execute([':user_id' => $userId]);

                    $stmt2 = $pdo->prepare("UPDATE faculty_db.faculty_profiles SET profile_status = 'Rejected', request_status = 'rejected' WHERE user_id = :user_id");
                    $stmt2->execute([':user_id' => $userId]);

                    $pdo->commit();
                    $message = "Account request has been rejected.";
                    $messageType = 'warning';
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Approval Processing Error: " . $e->getMessage());
                $message = "An error occurred while updating the account status.";
                $messageType = "danger";
            }
        }
    }
}

// Load pending accounts
$stmt = $pdo->query("
    SELECT fp.*, u.status AS account_status, u.id AS auth_user_id
    FROM faculty_db.faculty_profiles fp
    JOIN sms2_db.users u ON fp.user_id = u.id
    WHERE u.status = 'pending_approval' OR fp.profile_status = 'Pending Approval'
    ORDER BY fp.created_at DESC
");
$pendingFaculty = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate Quick Stats for Dashboard Cards
$totalPending = count($pendingFaculty);

$departments = array_map(function($item) {
    return $item['designated_department'] ?? $item['designated_dept'] ?? '';
}, $pendingFaculty);
$uniqueDepts = count(array_unique(array_filter($departments)));

$fullTimeCount = count(array_filter($pendingFaculty, fn($item) => strtolower($item['employment_status'] ?? '') === 'regular' || strtolower($item['employment_status'] ?? '') === 'active'));
$partTimeCount = $totalPending - $fullTimeCount;

// Page configuration & breadcrumbs
$pageTitle    = 'Pending Approvals';
$activeModule = 'faculty';
$activePage   = 'pending-approvals';
$breadcrumbs  = [
    ['label' => 'Faculty Management', 'url' => BASE_URL . '/modules/faculty/index.php'],
    ['label' => 'Pending Approvals', 'url' => null],
];

require_once __DIR__ . '/../../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../../includes/layout-start.php';
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/modules/faculty/assets/css/faculty.css">

<?php renderBreadcrumbs($breadcrumbs); ?>

<div class="container-fluid py-3 px-2 px-md-3">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <h1 class="h4 h3-md text-body mb-1 d-flex align-items-center gap-2">
                <i class="fas fa-user-clock text-sms-primary me-2"></i>
                <span>Pending Account Approvals</span>
            </h1>
            <p class="text-body-secondary small mb-0">Review and verify faculty accounts requested by Department Heads.</p>
        </div>
        <div>
            <span class="badge bg-warning text-dark fs-7 fs-md-6 px-3 py-2 shadow-sm rounded-pill">
                <i class="fas fa-hourglass-half me-1"></i> <?= $totalPending ?> Action Needed
            </span>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= $messageType ?> alert-dismissible fade show rounded-3 shadow-sm fs-7" role="alert">
            <i class="fas <?= $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle' ?> me-2"></i>
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Summary Cards Section -->
    <div class="row g-2 g-md-3 mb-4">
        <div class="col-12 col-sm-6 col-xl-3">
            <section class="card stat-card warning border shadow-sm position-relative h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon me-3 text-warning fs-4">
                        <i class="fas fa-user-clock"></i>
                    </div>
                    <div>
                        <h6 class="text-muted mb-0 small text-uppercase fw-bold">Awaiting Review</h6>
                        <h4 class="mb-0 fw-bold"><?= $totalPending ?></h4>
                        <small class="text-warning fw-semibold" style="font-size: 0.75rem;">
                            <i class="fas fa-hourglass-half me-1"></i>Action needed
                        </small>
                    </div>
                </div>
                <a href="#pendingTableBody" class="position-absolute top-0 end-0 m-3 text-muted border rounded p-1 d-flex align-items-center justify-content-center border-secondary-subtle" style="width: 24px; height: 24px; font-size: 0.7rem;" title="View Requests">
                    <i class="fas fa-arrow-up-right-from-square"></i>
                </a>
            </section>
        </div>

        <div class="col-12 col-sm-6 col-xl-3">
            <section class="card stat-card info border shadow-sm position-relative h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon me-3 text-info fs-4">
                        <i class="fas fa-building"></i>
                    </div>
                    <div>
                        <h6 class="text-muted mb-0 small text-uppercase fw-bold">Departments</h6>
                        <h4 class="mb-0 fw-bold"><?= $uniqueDepts ?></h4>
                        <small class="text-info fw-semibold" style="font-size: 0.75rem;">
                            <i class="fas fa-layer-group me-1"></i>Active units
                        </small>
                    </div>
                </div>
                <a href="#pendingTableBody" class="position-absolute top-0 end-0 m-3 text-muted border rounded p-1 d-flex align-items-center justify-content-center border-secondary-subtle" style="width: 24px; height: 24px; font-size: 0.7rem;" title="View Departments">
                    <i class="fas fa-arrow-up-right-from-square"></i>
                </a>
            </section>
        </div>

        <div class="col-12 col-sm-6 col-xl-3">
            <section class="card stat-card success border shadow-sm position-relative h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon me-3 text-success fs-4">
                        <i class="fas fa-briefcase"></i>
                    </div>
                    <div>
                        <h6 class="text-muted mb-0 small text-uppercase fw-bold">Full-Time Requests</h6>
                        <h4 class="mb-0 fw-bold"><?= $fullTimeCount ?></h4>
                        <small class="text-success fw-semibold" style="font-size: 0.75rem;">
                            <i class="fas fa-user-check me-1"></i>Regular staff
                        </small>
                    </div>
                </div>
                <a href="#pendingTableBody" class="position-absolute top-0 end-0 m-3 text-muted border rounded p-1 d-flex align-items-center justify-content-center border-secondary-subtle" style="width: 24px; height: 24px; font-size: 0.7rem;" title="View Full-Time">
                    <i class="fas fa-arrow-up-right-from-square"></i>
                </a>
            </section>
        </div>

        <div class="col-12 col-sm-6 col-xl-3">
            <section class="card stat-card primary border shadow-sm position-relative h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon me-3 text-primary fs-4">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div>
                        <h6 class="text-muted mb-0 small text-uppercase fw-bold">Part-Time / Other</h6>
                        <h4 class="mb-0 fw-bold"><?= $partTimeCount ?></h4>
                        <small class="text-primary fw-semibold" style="font-size: 0.75rem;">
                            <i class="fas fa-user-clock me-1"></i>Adjunct & non-regular
                        </small>
                    </div>
                </div>
                <a href="#pendingTableBody" class="position-absolute top-0 end-0 m-3 text-muted border rounded p-1 d-flex align-items-center justify-content-center border-secondary-subtle" style="width: 24px; height: 24px; font-size: 0.7rem;" title="View Part-Time">
                    <i class="fas fa-arrow-up-right-from-square"></i>
                </a>
            </section>
        </div>
    </div>

    <!-- Main Approvals Table Card -->
    <div class="card bg-body-tertiary border border-light-subtle shadow-sm rounded-4">
        <div class="card-header bg-transparent border-bottom border-light-subtle py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="card-title text-body mb-0 fw-bold fs-6"><i class="fas fa-list-alt me-2 text-info"></i>Pending Queue</h5>
            <div class="col-12 col-md-4 col-lg-3">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-body text-body-secondary border-light-subtle">
                        <i class="fas fa-search"></i>
                    </span>
                    <input type="text" id="pendingSearch" class="form-control bg-body text-body border-light-subtle fs-7 shadow-none" placeholder="Search pending requests..." onkeyup="filterPending()">
                </div>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 fs-7">
                <thead>
                    <tr class="text-body-secondary border-light-subtle">
                        <th style="width: 60px;">Avatar</th>
                        <th>Faculty ID</th>
                        <th>Name</th>
                        <th class="d-none d-md-table-cell">Department</th>
                        <th class="d-none d-sm-table-cell">Position</th>
                        <th>Employment</th>
                        <th class="text-end text-sm-center" style="min-width: 140px;">Action</th>
                    </tr>
                </thead>
                <tbody id="pendingTableBody">
                    <?php if (empty($pendingFaculty)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-body-secondary py-5">
                                <i class="fas fa-check-circle fa-3x mb-3 text-success d-block opacity-75"></i>
                                <h5 class="fs-6 fw-bold">All caught up!</h5>
                                <p class="mb-0 fs-7">There are no pending account approval requests at this time.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($pendingFaculty as $row): ?>
                            <?php 
                                $fullName = trim(($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                                $deptLabel = FacultyController::getDepartmentLabel((string)($row['designated_department'] ?? $row['designated_dept'] ?? ''));
                                
                                $initials = '';
                                foreach (array_filter(explode(' ', $fullName)) as $part) {
                                    $initials .= strtoupper(substr($part, 0, 1));
                                    if (strlen($initials) >= 2) break;
                                }
                                if ($initials === '') $initials = 'NA';
                            ?>
                            <tr>
                                <td>
                                    <div class="rounded-circle d-flex align-items-center justify-content-center bg-warning text-dark fw-bold shadow-sm" 
                                        style="width: 36px; height: 36px; font-size: 13px;">
                                        <?= htmlspecialchars($initials) ?>
                                    </div>
                                </td>
                                <td class="fw-bold text-info"><?= htmlspecialchars($row['faculty_id'] ?? '—') ?></td>
                                <td class="fw-semibold text-body"><?= htmlspecialchars($fullName) ?></td>
                                <td class="d-none d-md-table-cell"><span class="badge bg-body-secondary text-body border border-light-subtle"><?= htmlspecialchars($deptLabel) ?></span></td>
                                <td class="d-none d-sm-table-cell"><?= htmlspecialchars($row['position'] ?? '—') ?></td>
                                <td><span class="badge bg-warning-subtle text-warning border border-warning-subtle"><?= htmlspecialchars(ucfirst($row['employment_status'] ?? 'Pending')) ?></span></td>
                                <td class="text-end text-sm-center">
                                    <div class="d-inline-flex gap-1 flex-wrap justify-content-end justify-content-sm-center">
                                        <!-- Details / Inspect Button -->
                                        <button type="button" class="btn btn-sm btn-outline-info rounded-3 px-2" onclick="inspectRequest(this)"
                                            data-user-id="<?= (int)$row['auth_user_id'] ?>"
                                            data-faculty-id="<?= htmlspecialchars($row['faculty_id'] ?? '') ?>"
                                            data-full-name="<?= htmlspecialchars($fullName) ?>"
                                            data-email="<?= htmlspecialchars($row['email'] ?? '') ?>"
                                            data-phone="<?= htmlspecialchars($row['phone'] ?? '') ?>"
                                            data-dept="<?= htmlspecialchars($deptLabel) ?>"
                                            data-position="<?= htmlspecialchars($row['position'] ?? '') ?>"
                                            data-employment="<?= htmlspecialchars($row['employment_status'] ?? '') ?>"
                                            data-rank="<?= htmlspecialchars($row['academic_rank'] ?? '') ?>"
                                            data-tier="<?= htmlspecialchars($row['tier'] ?? '') ?>"
                                            title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>

                                        <!-- Approve and Reject Actions -->
                                        <form method="post" class="d-inline-flex gap-1">
                                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                            <input type="hidden" name="user_id" value="<?= htmlspecialchars($row['auth_user_id']) ?>">
                                            
                                            <button type="submit" name="action" value="approve" class="btn btn-sm btn-success rounded-3 fw-bold px-2" title="Approve">
                                                <i class="fas fa-check"></i><span class="d-none d-sm-inline ms-1"> Approve</span>
                                            </button>
                                            
                                            <button type="submit" name="action" value="reject" class="btn btn-sm btn-outline-danger rounded-3 fw-bold px-2" onclick="return confirm('Are you sure you want to reject this account request?');" title="Reject">
                                                <i class="fas fa-times"></i><span class="d-none d-sm-inline ms-1"> Reject</span>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Details Review Modal -->
<div class="modal fade" id="reviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 rounded-4 shadow">
            <div class="modal-header border-bottom border-light-subtle py-3 px-4">
                <h5 class="modal-title fw-bold fs-6"><i class="fas fa-user-shield text-warning me-2"></i>Review Account Request</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4 fs-7">
                <div class="row g-3">
                    <div class="col-12 col-sm-6">
                        <label class="text-body-secondary small">Faculty ID</label>
                        <div class="fw-bold text-info fs-6" id="modalFacultyId">—</div>
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="text-body-secondary small">Full Name</label>
                        <div class="fw-bold fs-6 text-body" id="modalFullName">—</div>
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="text-body-secondary small">Department</label>
                        <div id="modalDept" class="text-body">—</div>
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="text-body-secondary small">Position</label>
                        <div id="modalPosition" class="text-body">—</div>
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="text-body-secondary small">Academic Rank & Tier</label>
                        <div id="modalRank" class="text-body">—</div>
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="text-body-secondary small">Employment Status</label>
                        <div id="modalEmployment" class="text-body">—</div>
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="text-body-secondary small">Email Address</label>
                        <div id="modalEmail" class="text-body">—</div>
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="text-body-secondary small">Phone Number</label>
                        <div id="modalPhone" class="text-body">—</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-top border-light-subtle py-2 px-4 justify-content-between flex-wrap gap-2">
                <button type="button" class="btn btn-secondary btn-sm rounded-3" data-bs-dismiss="modal">Close</button>
                <form method="post" id="modalActionForm" class="d-inline-flex gap-2 w-100 w-sm-auto justify-content-end">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="user_id" id="modalUserId" value="">
                    <button type="submit" name="action" value="reject" class="btn btn-outline-danger btn-sm rounded-3 fw-bold flex-fill flex-sm-grow-0" onclick="return confirm('Reject this account request?');">
                        <i class="fas fa-times me-1"></i> Reject Request
                    </button>
                    <button type="submit" name="action" value="approve" class="btn btn-success btn-sm rounded-3 fw-bold px-3 flex-fill flex-sm-grow-0">
                        <i class="fas fa-check me-1"></i> Approve & Activate
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    function filterPending() {
        const query = document.getElementById('pendingSearch').value.toLowerCase();
        const rows = document.querySelectorAll('#pendingTableBody tr');

        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(query) ? '' : 'none';
        });
    }

    function inspectRequest(button) {
        if (!button || !button.dataset) return;
        
        document.getElementById('modalUserId').value = button.dataset.userId || '';
        document.getElementById('modalFacultyId').textContent = button.dataset.facultyId || '—';
        document.getElementById('modalFullName').textContent = button.dataset.fullName || '—';
        document.getElementById('modalDept').textContent = button.dataset.dept || '—';
        document.getElementById('modalPosition').textContent = button.dataset.position || '—';
        document.getElementById('modalRank').textContent = (button.dataset.rank || '') + ' ' + (button.dataset.tier ? '(' + button.dataset.tier + ')' : '');
        document.getElementById('modalEmployment').textContent = button.dataset.employment || '—';
        document.getElementById('modalEmail').textContent = button.dataset.email || '—';
        document.getElementById('modalPhone').textContent = button.dataset.phone || '—';

        const modalEl = document.getElementById('reviewModal');
        if (modalEl && window.bootstrap && bootstrap.Modal) {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
    }
</script>

<?php require_once __DIR__ . '/../../../../includes/layout-end.php'; ?>