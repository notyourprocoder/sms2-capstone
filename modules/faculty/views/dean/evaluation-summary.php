<?php
/**
 * SMS 2 - Evaluation Summary (Dean)
 * Module: Faculty Management
 *
 * Dean's counterpart to modules/faculty/views/department-head/evaluation-summary.php.
 * Same 50% Student / 30% Peer / 20% Department-Head composite report and
 * the same UI, but scoped to the department currently assigned to the
 * logged-in Dean instead of a Department Head. All data-fetching /
 * computation lives in DeanEvaluationSummaryController.php - this file is
 * presentation only.
 */
require_once __DIR__ . '/../../../../config/config.php';

// NOTE: config.php alone does NOT start the session. Only
// includes/authentication.php does (via config/session.php, using the
// app's real session name, SMS2SESSID) - a bare session_start() here would
// create/join PHP's default session instead. Without this require, $_SESSION
// is never initialized on this page and every session lookup below silently
// resolves to nothing, no matter what key names are used.
require_once ROOT_PATH . '/includes/authentication.php';

// Establish Database Connection
//
// IMPORTANT: config.php's own global $pdo (if any) points at the app's
// CORE database (sms2_db - users/roles/auth), not the faculty module's own
// database (faculty_db, which holds faculty_profiles/evaluations/etc).
// Blindly reusing that $pdo silently queries the wrong database and every
// faculty_profiles lookup below fails with "table doesn't exist". Mirror
// Processdeptheadevaluationcontroller.php: prefer the module's own
// facultyDb() helper first, and only fall back to a bare $pdo/$conn/$db if
// that helper isn't available.
require_once __DIR__ . '/../../config/database.php';

if (function_exists('facultyDb')) {
    $pdo = facultyDb();
}

if (!isset($pdo) || !$pdo) {
    $pdo = $conn ?? $db ?? null;
}

if (!$pdo) {
    try {
        $dbHost = defined('DB_HOST') ? DB_HOST : 'localhost';
        $dbName = defined('DB_NAME') ? DB_NAME : 'faculty_db';
        $dbUser = defined('DB_USER') ? DB_USER : 'root';
        $dbPass = defined('DB_PASS') ? DB_PASS : '';

        $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
    }
}

require_once __DIR__ . '/../../controllers/DeanEvaluationSummaryController.php';
$summary = getDeanEvaluationSummaryData($pdo);
extract($summary);
/**
 * Extracted variables: $deanDept, $isDean, $deanName, $facultyMembers,
 * $performanceDB, $totalFacultyCount, $evaluatedFacultyCount,
 * $fullyEvaluatedCount, $deptOverallAverage, $deptStudentAverage,
 * $deptPeerAverage, $deptHeadAverage, $deptStudentScores, $deptPeerScores,
 * $deptHeadScores, $deptPeerRatedCount, $topPerformer, $topPerformerScore,
 * $needsAttentionList, $needsAttentionCount
 */

$pageTitle    = 'Evaluation Summary';
$activeModule = 'faculty';
$activePage   = 'evaluation-summary';
$breadcrumbs  = [
    ['label' => 'Faculty Management', 'url' => BASE_URL . '/modules/faculty/index.php'],
    ['label' => 'Evaluation Summary', 'url' => null],
];

require_once __DIR__ . '/../../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../../includes/layout-start.php';
?>

<style>
    /* Custom Scrollbar Styles (Chrome, Safari, Edge, Firefox) */
    .custom-scrollbar {
        scrollbar-width: thin;
        scrollbar-color: rgba(255, 255, 255, 0.3) transparent;
    }
    .custom-scrollbar::-webkit-scrollbar {
        width: 6px;
        height: 6px;
    }
    .custom-scrollbar::-webkit-scrollbar-track {
        background: transparent;
    }
    .custom-scrollbar::-webkit-scrollbar-thumb {
        background-color: rgba(255, 255, 255, 0.25);
        border-radius: 10px;
    }
    .custom-scrollbar::-webkit-scrollbar-thumb:hover {
        background-color: rgba(13, 110, 253, 0.6);
    }

    /* Light Theme Scrollbar Adjustments */
    [data-bs-theme="light"] .custom-scrollbar,
    body:not([data-bs-theme="dark"]) .custom-scrollbar {
        scrollbar-color: rgba(13, 110, 253, 0.3) transparent;
    }
    [data-bs-theme="light"] .custom-scrollbar::-webkit-scrollbar-thumb,
    body:not([data-bs-theme="dark"]) .custom-scrollbar::-webkit-scrollbar-thumb {
        background-color: rgba(13, 110, 253, 0.3);
    }

    /* Faculty list height boundary to enforce scrolling */
    .faculty-list-scroll {
        max-height: 480px;
        overflow-y: auto;
    }
</style>

<?php renderBreadcrumbs($breadcrumbs); ?>

<div class="page-header d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mb-3">
    <div>
        <h1 class="h4 h3-md mb-1"><i class="fas fa-chalkboard-teacher text-sms-primary me-2"></i>Evaluation Summary</h1>
        <small class="text-muted small">Showing faculty under your college/department: <strong><?= htmlspecialchars(!empty($deanDept) ? $deanDept : 'All Departments') ?></strong></small>
    </div>
</div>

<?php if (!$isDean): ?>
<div class="alert alert-secondary border-secondary-subtle d-flex align-items-start gap-2 mb-3" role="alert">
    <i class="fas fa-circle-info fs-5 flex-shrink-0 mt-1"></i>
    <div class="small">
        Your account isn't currently recorded with the position <strong>Dean</strong>. Showing the best available data for your assigned department below; contact the administrator if this is unexpected.
    </div>
</div>
<?php endif; ?>

<!-- Department Overview: Coverage + Composite + Attention -->
<div class="row g-3 mb-3">
    <!-- Fully Evaluated Card (Info - Cyan) -->
    <div class="col-12 col-sm-6 col-xl-3">
        <section class="card stat-card info border shadow-sm position-relative h-100">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon me-3 text-info fs-4">
                    <i class="fas fa-clipboard-check"></i>
                </div>
                <div>
                    <h6 class="text-muted mb-0 small text-uppercase fw-bold">Fully Evaluated</h6>
                    <h4 class="mb-0 fw-bold"><?= $fullyEvaluatedCount ?> <span class="text-muted fs-6 fw-normal">/ <?= $totalFacultyCount ?></span></h4>
                    <small class="text-muted fw-semibold" style="font-size: 0.75rem;">
                        All 3 sources complete
                    </small>
                </div>
            </div>
            <a href="#facultyListContainer" class="position-absolute top-0 end-0 m-3 text-muted border rounded p-1 d-flex align-items-center justify-content-center border-secondary-subtle" style="width: 24px; height: 24px; font-size: 0.7rem;" title="View Faculty List">
                <i class="fas fa-arrow-up-right-from-square"></i>
            </a>
        </section>
    </div>

    <!-- College Overall Avg Card (Primary - Blue) -->
    <div class="col-12 col-sm-6 col-xl-3">
        <section class="card stat-card primary border shadow-sm position-relative h-100">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon me-3 text-primary fs-4">
                    <i class="fas fa-chart-pie"></i>
                </div>
                <div>
                    <h6 class="text-muted mb-0 small text-uppercase fw-bold">College Overall Avg</h6>
                    <?php if ($evaluatedFacultyCount > 0): ?>
                        <h4 class="mb-0 fw-bold"><?= number_format($deptOverallAverage, 2) ?> <span class="text-muted fs-6 fw-normal">/ 5.00</span></h4>
                        <small class="text-success fw-semibold" style="font-size: 0.75rem;">
                            <?= getRatingLabel($deptOverallAverage) ?>
                        </small>
                    <?php else: ?>
                        <h4 class="mb-0 fw-bold">--</h4>
                        <small class="text-muted fw-semibold" style="font-size: 0.75rem;">No data yet</small>
                    <?php endif; ?>
                </div>
            </div>
            <a href="#evalSourceTabs" class="position-absolute top-0 end-0 m-3 text-muted border rounded p-1 d-flex align-items-center justify-content-center border-secondary-subtle" style="width: 24px; height: 24px; font-size: 0.7rem;" title="View Breakdown">
                <i class="fas fa-arrow-up-right-from-square"></i>
            </a>
        </section>
    </div>

    <!-- Top Performer Card (Success - Green) -->
    <div class="col-12 col-sm-6 col-xl-3">
        <section class="card stat-card success border shadow-sm position-relative h-100">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon me-3 text-success fs-4">
                    <i class="fas fa-trophy"></i>
                </div>
                <div class="text-truncate">
                    <h6 class="text-muted mb-0 small text-uppercase fw-bold">Top Performer</h6>
                    <?php if ($topPerformer): ?>
                        <h4 class="mb-0 fw-bold text-truncate" style="max-width: 170px; font-size: 1.15rem;" title="<?= htmlspecialchars($topPerformer['name']) ?>"><?= htmlspecialchars($topPerformer['name']) ?></h4>
                        <small class="text-success fw-semibold" style="font-size: 0.75rem;">
                            <i class="fas fa-star me-1"></i><?= number_format($topPerformerScore, 2) ?> / 5.00
                        </small>
                    <?php else: ?>
                        <h4 class="mb-0 fw-bold">--</h4>
                        <small class="text-muted fw-semibold" style="font-size: 0.75rem;">No data yet</small>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($topPerformer && isset($topPerformer['id'])): ?>
                <a href="javascript:void(0)" onclick="selectFaculty('<?= htmlspecialchars($topPerformer['id'], ENT_QUOTES) ?>')" class="position-absolute top-0 end-0 m-3 text-muted border rounded p-1 d-flex align-items-center justify-content-center border-secondary-subtle" style="width: 24px; height: 24px; font-size: 0.7rem;" title="View Top Performer">
                    <i class="fas fa-arrow-up-right-from-square"></i>
                </a>
            <?php endif; ?>
        </section>
    </div>

<!-- Needs Attention Card (Danger - Bright Red) -->
    <div class="col-12 col-sm-6 col-xl-3">
        <section class="card stat-card danger border shadow-sm position-relative overflow-hidden h-100">
            <!-- High-contrast bright red left indicator bar -->
            <div class="position-absolute top-0 start-0 h-100" style="width: 4px; background-color: #ff4d4d; z-index: 1;"></div>

            <div class="card-body d-flex align-items-center ps-4">
                <div class="stat-icon me-3 fs-4" style="color: #ff4d4d;">
                    <i class="fas fa-triangle-exclamation"></i>
                </div>
                <div>
                    <h6 class="text-muted mb-0 small text-uppercase fw-bold">Needs Attention</h6>
                    <h4 class="mb-0 fw-bold" style="color: #ff4d4d;"><?= $needsAttentionCount ?></h4>
                    <small class="fw-semibold" style="color: #ff4d4d; font-size: 0.75rem;">
                        Below 3.50 overall
                    </small>
                </div>
            </div>
            <a href="#needsAttentionAlert" class="position-absolute top-0 end-0 m-3 text-muted border rounded p-1 d-flex align-items-center justify-content-center border-secondary-subtle" style="width: 24px; height: 24px; font-size: 0.7rem;" title="View List">
                <i class="fas fa-arrow-up-right-from-square"></i>
            </a>
        </section>
    </div>

<!-- Per-Source Comparison -->
<div class="row g-3 mb-3">
    <!-- Student Avg Card (Info - Cyan) -->
    <div class="col-12 col-md-4">
        <section class="card stat-card info border shadow-sm position-relative h-100">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon me-3 text-info fs-4">
                    <i class="fas fa-user-graduate"></i>
                </div>
                <div>
                    <h6 class="text-muted mb-0 small text-uppercase fw-bold">Student Avg <span class="fw-normal">(50%)</span></h6>
                    <?php if (!empty($deptStudentScores)): ?>
                        <h4 class="mb-0 fw-bold"><?= number_format($deptStudentAverage, 2) ?> <span class="text-muted fs-6 fw-normal">/ 5.00</span></h4>
                        <small class="text-muted fw-semibold" style="font-size: 0.75rem;">
                            based on <?= count($deptStudentScores) ?> rated faculty
                        </small>
                    <?php else: ?>
                        <h4 class="mb-0 fw-bold">--</h4>
                        <small class="text-muted fw-semibold" style="font-size: 0.75rem;">No ratings yet</small>
                    <?php endif; ?>
                </div>
            </div>
            <a href="javascript:void(0)" onclick="switchEvalTab('student', document.querySelectorAll('#evalSourceTabs .nav-link')[1])" class="position-absolute top-0 end-0 m-3 text-muted border rounded p-1 d-flex align-items-center justify-content-center border-secondary-subtle" style="width: 24px; height: 24px; font-size: 0.7rem;" title="View Student Tab">
                <i class="fas fa-arrow-up-right-from-square"></i>
            </a>
        </section>
    </div>

    <!-- Peer Avg Card (Warning - Yellow) -->
    <div class="col-12 col-md-4">
        <section class="card stat-card warning border shadow-sm position-relative h-100">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon me-3 text-warning fs-4">
                    <i class="fas fa-user-friends"></i>
                </div>
                <div>
                    <h6 class="text-muted mb-0 small text-uppercase fw-bold">Peer Avg <span class="fw-normal">(30%)</span></h6>
                    <?php if ($deptPeerRatedCount > 0): ?>
                        <h4 class="mb-0 fw-bold"><?= number_format($deptPeerAverage, 2) ?> <span class="text-muted fs-6 fw-normal">/ 5.00</span></h4>
                        <small class="text-muted fw-semibold" style="font-size: 0.75rem;">
                            based on <?= $deptPeerRatedCount ?> rated faculty
                        </small>
                    <?php else: ?>
                        <h4 class="mb-0 fw-bold">--</h4>
                        <small class="text-muted fw-semibold" style="font-size: 0.75rem;">No ratings yet</small>
                    <?php endif; ?>
                </div>
            </div>
            <a href="javascript:void(0)" onclick="switchEvalTab('peer', document.querySelectorAll('#evalSourceTabs .nav-link')[2])" class="position-absolute top-0 end-0 m-3 text-muted border rounded p-1 d-flex align-items-center justify-content-center border-secondary-subtle" style="width: 24px; height: 24px; font-size: 0.7rem;" title="View Peer Tab">
                <i class="fas fa-arrow-up-right-from-square"></i>
            </a>
        </section>
    </div>

    <!-- Dept. Head Avg Card (Primary - Blue) -->
    <div class="col-12 col-md-4">
        <section class="card stat-card primary border shadow-sm position-relative h-100">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon me-3 text-primary fs-4">
                    <i class="fas fa-user-tie"></i>
                </div>
                <div>
                    <h6 class="text-muted mb-0 small text-uppercase fw-bold">Dept. Head Avg <span class="fw-normal">(20%)</span></h6>
                    <?php if (!empty($deptHeadScores)): ?>
                        <h4 class="mb-0 fw-bold"><?= number_format($deptHeadAverage, 2) ?> <span class="text-muted fs-6 fw-normal">/ 5.00</span></h4>
                        <small class="text-muted fw-semibold" style="font-size: 0.75rem;">
                            based on <?= count($deptHeadScores) ?> rated faculty
                        </small>
                    <?php else: ?>
                        <h4 class="mb-0 fw-bold">--</h4>
                        <small class="text-muted fw-semibold" style="font-size: 0.75rem;">No ratings yet</small>
                    <?php endif; ?>
                </div>
            </div>
            <a href="javascript:void(0)" onclick="switchEvalTab('head', document.querySelectorAll('#evalSourceTabs .nav-link')[3])" class="position-absolute top-0 end-0 m-3 text-muted border rounded p-1 d-flex align-items-center justify-content-center border-secondary-subtle" style="width: 24px; height: 24px; font-size: 0.7rem;" title="View Dept Head Tab">
                <i class="fas fa-arrow-up-right-from-square"></i>
            </a>
        </section>
    </div>
</div>

<?php if ($needsAttentionCount > 0): ?>
<div id="needsAttentionAlert" class="alert alert-warning border-warning-subtle bg-warning-subtle text-warning-emphasis d-flex align-items-start gap-2 mb-3" role="alert">
    <i class="fas fa-triangle-exclamation fs-5 flex-shrink-0 mt-1"></i>
    <div class="small">
        <strong><?= $needsAttentionCount ?> faculty member<?= $needsAttentionCount === 1 ? '' : 's' ?> trending below 3.50 overall:</strong>
        <?= htmlspecialchars(implode(', ', array_map(fn($n) => $n['name'] . ' (' . number_format($n['score'], 2) . ')', array_slice($needsAttentionList, 0, 6)))) ?><?= $needsAttentionCount > 6 ? ', and ' . ($needsAttentionCount - 6) . ' more' : '' ?>.
    </div>
</div>
<?php endif; ?>

<div class="container-fluid my-2 my-sm-4 p-2 p-sm-3 rounded-3">
    <div class="row g-3 g-lg-4">

        <!-- LEFT COLUMN: Department Faculty Selector -->
        <div class="col-12 col-lg-4 col-xl-3">
            <div class="card shadow-sm border border-secondary border-opacity-25 h-100">
                <div class="card-header border-bottom border-secondary border-opacity-25 d-flex justify-content-between align-items-center py-2 py-sm-3">
                    <h5 class="mb-0 fw-bold fs-6 small">Department Faculty</h5>
                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25" id="facultyCountBadge"><?= count($facultyMembers) ?> Members</span>
                </div>

                <div class="p-2 p-sm-3 border-bottom border-secondary border-opacity-25">
                    <div class="input-group input-group-sm mb-0">
                        <span class="input-group-text bg-transparent border-end-0 text-muted">
                            <i class="fas fa-search"></i>
                        </span>
                        <input type="text" id="facultySearchInput" class="form-control border-start-0 ps-0 bg-transparent" placeholder="Search faculty..." onkeyup="onSearchInput()">
                    </div>
                </div>

                <!-- Scrollable Faculty Card List -->
                <div class="card-body p-2 p-sm-3 custom-scrollbar faculty-list-scroll">
                    <div class="d-flex flex-column gap-2" id="facultyListContainer">
                        <?php if (!empty($facultyMembers)): ?>
                            <?php foreach ($facultyMembers as $fac): ?>
                                <?php
                                    $fId = $fac['id'];
                                    $fName = 'Prof. ' . $fac['first_name'] . ' ' . $fac['last_name'];
                                    $initials = strtoupper(substr($fac['first_name'], 0, 1) . substr($fac['last_name'], 0, 1));
                                ?>
                                <div class="faculty-card d-flex align-items-center justify-content-between p-2 rounded-3 border border-secondary border-opacity-25 gap-2"
                                     data-name="<?= strtolower($fName) ?>">
                                    <div class="d-flex align-items-center gap-2 text-truncate">
                                        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center fw-bold shadow-sm flex-shrink-0" style="width: 36px; height: 36px; font-size: 12px;">
                                            <?= $initials ?>
                                        </div>
                                        <div class="text-truncate">
                                            <div class="fw-bold small text-truncate faculty-name"><?= htmlspecialchars($fName) ?></div>
                                            <div class="text-muted text-truncate faculty-subject" style="font-size: 11px;"><?= htmlspecialchars($fac['position'] ?? 'Faculty') ?></div>
                                        </div>
                                    </div>
                                    <button class="btn btn-sm btn-outline-primary rounded-pill px-2 px-sm-3 py-1 flex-shrink-0" style="font-size: 11px;" onclick="selectFaculty('<?= htmlspecialchars($fId, ENT_QUOTES) ?>')">
                                        View
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-4 text-muted">
                                <i class="fas fa-user-slash fs-4 mb-2 d-block opacity-50"></i>
                                <span class="small">No department faculty found.</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Footer Pagination Controls (Max 10 per page) -->
                <div class="card-footer border-top border-secondary border-opacity-25 py-2 px-3 d-flex align-items-center justify-content-between bg-transparent" id="facultyPaginationWrapper">
                    <small class="text-muted" style="font-size: 11px;" id="paginationInfo">Showing 1-10</small>
                    <nav>
                        <ul class="pagination pagination-sm mb-0 faculty-pagination" id="paginationList">
                        </ul>
                    </nav>
                </div>
            </div>
        </div>

        <!-- RIGHT COLUMN: Evaluation Summary -->
        <div class="col-12 col-lg-8 col-xl-9">

            <!-- Header Card -->
            <div class="card border shadow-sm mb-3">
                <div class="card-body p-3">
                    <div class="d-flex flex-row align-items-start justify-content-between gap-2">
                        <div class="d-flex align-items-start gap-3 w-100">
                            <div id="profAvatar" class="rounded-circle bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 d-flex align-items-center justify-content-center fw-bold fs-5 flex-shrink-0" style="width: 44px; height: 44px;">
                                --
                            </div>
                            <div class="w-100">
                                <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                    <h5 class="mb-0 fw-bold fs-6 fs-sm-5 text-break">Faculty Multi-Source Summary</h5>
                                    <span class="badge border border-success border-opacity-50 text-success bg-success bg-opacity-10" style="font-size: 10px;">AY 2025-2026</span>
                                </div>
                                <div class="text-muted small">
                                    <span class="d-block d-sm-inline">Faculty: <strong class="text-body" id="profName">-</strong></span>
                                    <span class="d-none d-sm-inline mx-1">|</span>
                                    <span class="d-block d-sm-inline">Position: <strong class="text-body" id="profPosition">-</strong></span>
                                </div>
                            </div>
                        </div>

                        <button class="btn btn-sm btn-outline-primary flex-shrink-0 align-self-start" onclick="window.print()" title="Export Summary">
                            <i class="fas fa-print"></i>
                            <span class="d-none d-sm-inline ms-1">Export</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- EVALUATION SOURCE NAVIGATION TABS -->
            <div class="mb-3 border-bottom overflow-x-auto text-nowrap custom-scrollbar pb-1">
                <ul class="nav nav-tabs border-0 flex-nowrap" id="evalSourceTabs">
                    <li class="nav-item flex-shrink-0">
                        <button class="nav-link active fw-bold py-2 px-3 small" onclick="switchEvalTab('all', this)">
                            <i class="fas fa-chart-pie me-1 text-primary"></i> Composite (50/30/20)
                        </button>
                    </li>
                    <li class="nav-item flex-shrink-0">
                        <button class="nav-link text-secondary py-2 px-3 small" onclick="switchEvalTab('student', this)">
                            <i class="fas fa-user-graduate me-1 text-info"></i> Student (50%)
                        </button>
                    </li>
                    <li class="nav-item flex-shrink-0">
                        <button class="nav-link text-secondary py-2 px-3 small" onclick="switchEvalTab('peer', this)">
                            <i class="fas fa-user-friends me-1 text-warning"></i> Peer (30%)
                        </button>
                    </li>
                    <li class="nav-item flex-shrink-0">
                        <button class="nav-link text-secondary py-2 px-3 small" onclick="switchEvalTab('head', this)">
                            <i class="fas fa-user-tie me-1 text-primary"></i> Department Head (20%)
                        </button>
                    </li>
                </ul>
            </div>

            <div class="row g-3 g-lg-4">

                <!-- Score Breakdown Sidebar -->
                <div class="col-12 col-md-5 col-xl-4">

                    <!-- Main Score Summary -->
                    <div class="card border shadow-sm mb-3">
                        <div class="card-body p-3 p-sm-4 text-center">
                            <small class="text-uppercase text-muted fw-bold" style="font-size: 10px; letter-spacing: 0.8px;" id="scoreCardTitle">Composite Rating</small>

                            <div class="d-flex align-items-baseline justify-content-center gap-1 my-2">
                                <span class="display-5 fw-bold" id="profScore">0.00</span>
                                <span class="text-muted fs-6">/ 5.00</span>
                            </div>

                            <div class="p-2 rounded-2 bg-success bg-opacity-10 border border-success border-opacity-25 mb-3">
                                <span class="text-success fw-bold small" id="profRatingLabel">No Rating</span>
                            </div>

                            <div class="row g-2 pt-2 border-top border-secondary border-opacity-25">
                                <div class="col-12">
                                    <div class="p-2 rounded border border-secondary border-opacity-25">
                                        <span class="d-block text-muted text-uppercase" style="font-size: 10px;">Total Evaluations</span>
                                        <span class="fw-bold fs-6" id="profCount">0</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Source Breakdown List -->
                    <div class="card border shadow-sm mb-3" id="sourceBreakdownCard">
                        <div class="card-header border-bottom border-secondary border-opacity-25 py-2">
                            <h6 class="mb-0 fw-bold small text-uppercase" style="font-size: 11px;">Breakdown Weight</h6>
                        </div>
                        <div class="card-body p-3 small" style="font-size: 11px;">
                            <ul class="list-unstyled mb-0 d-flex flex-column gap-2">
                                <li class="d-flex justify-content-between align-items-center">
                                    <span><i class="fas fa-user-graduate me-1 text-info"></i> Student Rating (50%)</span>
                                    <span class="fw-bold" id="scoreStudentWeight">0.00</span>
                                </li>
                                <li class="d-flex justify-content-between align-items-center">
                                    <span><i class="fas fa-user-friends me-1 text-warning"></i> Peer Rating (30%)</span>
                                    <span class="fw-bold" id="scorePeerWeight">0.00</span>
                                </li>
                                <li class="d-flex justify-content-between align-items-center">
                                    <span><i class="fas fa-user-tie me-1 text-primary"></i> Dept. Head Rating (20%)</span>
                                    <span class="fw-bold" id="scoreHeadWeight">0.00</span>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <!-- Rating Scale Legend -->
                    <div class="card border shadow-sm mb-3 mb-md-0">
                        <div class="card-header border-bottom border-secondary border-opacity-25 py-2">
                            <h6 class="mb-0 fw-bold small text-uppercase" style="font-size: 11px;">Rating Scale</h6>
                        </div>
                        <div class="card-body p-3 small" style="font-size: 11px;">
                            <ul class="list-unstyled mb-0 d-flex flex-column gap-1">
                                <li class="d-flex justify-content-between"><span class="fw-bold text-success">5 - Outstanding</span> <span>4.50 - 5.00</span></li>
                                <li class="d-flex justify-content-between"><span class="fw-bold text-primary">4 - Very Satisfactory</span> <span>3.50 - 4.49</span></li>
                                <li class="d-flex justify-content-between"><span class="fw-bold text-info">3 - Satisfactory</span> <span>2.50 - 3.49</span></li>
                                <li class="d-flex justify-content-between"><span class="fw-bold text-warning">2 - Average</span> <span>1.50 - 2.49</span></li>
                                <li class="d-flex justify-content-between"><span class="fw-bold text-danger">1 - Needs Improvement</span> <span>1.00 - 1.49</span></li>
                            </ul>
                        </div>
                    </div>

                </div>

                <!-- Main Content Area -->
                <div class="col-12 col-md-7 col-xl-8">
                    <!-- Qualitative Feedback Table -->
                    <div class="card border shadow-sm">
                        <div class="card-header border-bottom border-secondary border-opacity-25 py-3">
                            <h6 class="mb-0 fw-bold small text-uppercase" id="feedbackHeaderTitle">Qualitative Feedback & Remarks</h6>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive custom-scrollbar">
                                <table class="table table-hover align-middle mb-0 small" style="font-size: 12px;">
                                    <thead class="table-light text-muted text-uppercase">
                                        <tr>
                                            <th class="ps-3 py-2 w-100">Feedback Comments</th>
                                        </tr>
                                    </thead>
                                    <tbody id="commentsTableBody">
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

    </div>
</div>

<script>
    const performanceDB = <?= json_encode($performanceDB) ?>;
    let activeFacultyId = Object.keys(performanceDB)[0] || null;
    let currentTab = 'all';

    // Pagination Variables
    const itemsPerPage = 10;
    let currentPage = 1;
    let filteredCards = [];

    function selectFaculty(facId) {
        activeFacultyId = facId;
        renderData();
    }

    function switchEvalTab(tabKey, element) {
        currentTab = tabKey;

        document.querySelectorAll('#evalSourceTabs .nav-link').forEach(btn => {
            btn.classList.remove('active', 'fw-bold', 'text-dark');
            btn.classList.add('text-secondary');
        });
        element.classList.add('active', 'fw-bold');
        element.classList.remove('text-secondary');

        renderData();
    }

    function renderData() {
        if (!activeFacultyId || !performanceDB[activeFacultyId]) return;
        const fac = performanceDB[activeFacultyId];

        // Basic Header Info
        document.getElementById('profName').innerText = fac.name;
        document.getElementById('profPosition').innerText = fac.position || 'Faculty';

        const avatar = document.getElementById('profAvatar');
        avatar.innerText = fac.initials;

        if (!fac.isLinked) {
            document.getElementById('scoreCardTitle').innerText = 'Overall Composite Rating (50/30/20)';
            document.getElementById('profScore').innerText = '--';
            document.getElementById('profRatingLabel').innerText = 'Not linked to a faculty record';
            document.getElementById('profCount').innerText = 0;
            document.getElementById('sourceBreakdownCard').classList.add('d-none');
            document.getElementById('feedbackHeaderTitle').innerText = 'Feedback & Remarks';
            document.getElementById('commentsTableBody').innerHTML =
                '<tr><td class="ps-3 text-secondary py-2">This profile has no linked faculty record yet, so no evaluations can exist for it.</td></tr>';
            return;
        }

        // Weights
        document.getElementById('scoreStudentWeight').innerText = fac.sources.student.score;
        document.getElementById('scorePeerWeight').innerText = fac.sources.peer.score;
        document.getElementById('scoreHeadWeight').innerText = fac.sources.head.score;

        let activeSourceData;

        if (currentTab === 'all') {
            document.getElementById('scoreCardTitle').innerText = 'Overall Composite Rating (50/30/20)';
            document.getElementById('profScore').innerText = fac.compositeScore;
            document.getElementById('profRatingLabel').innerText = fac.compositeRating;
            document.getElementById('profCount').innerText = fac.totalEvals;
            document.getElementById('sourceBreakdownCard').classList.remove('d-none');
            document.getElementById('feedbackHeaderTitle').innerText = 'Combined Feedback & Remarks';

            activeSourceData = fac.sources.student;
        } else {
            activeSourceData = fac.sources[currentTab];
            document.getElementById('sourceBreakdownCard').classList.add('d-none');

            const titles = {
                student: 'Student Evaluation (50% Weight)',
                peer: 'Peer / Co-Worker Evaluation (30% Weight)',
                head: 'Department Head Evaluation (20% Weight)'
            };

            document.getElementById('scoreCardTitle').innerText = titles[currentTab];
            document.getElementById('profScore').innerText = activeSourceData.score;
            document.getElementById('profRatingLabel').innerText = activeSourceData.ratingText;
            document.getElementById('profCount').innerText = activeSourceData.evalCount;
            document.getElementById('feedbackHeaderTitle').innerText = `${currentTab.toUpperCase()} Feedback & Remarks`;
        }

        // Render Comments
        const commContainer = document.getElementById('commentsTableBody');
        commContainer.innerHTML = '';
        activeSourceData.feedback.forEach(item => {
            commContainer.innerHTML += `
                <tr>
                    <td class="ps-3 text-secondary py-2">${item.strong}</td>
                </tr>
            `;
        });
    }

    // Initialize or Refresh List Pagination & Search
    function initFacultyPagination() {
        const query = document.getElementById('facultySearchInput').value.toLowerCase().trim();
        const allCards = Array.from(document.querySelectorAll('.faculty-card'));

        filteredCards = allCards.filter(card => {
            const name = card.getAttribute('data-name');
            return name.includes(query);
        });

        document.getElementById('facultyCountBadge').innerText = `${filteredCards.length} Members`;

        // Reset page if bounds exceeded
        const totalPages = Math.ceil(filteredCards.length / itemsPerPage) || 1;
        if (currentPage > totalPages) {
            currentPage = 1;
        }

        renderFacultyPage();
    }

    function renderFacultyPage() {
        const allCards = document.querySelectorAll('.faculty-card');
        allCards.forEach(card => {
            card.classList.add('d-none');
            card.classList.remove('d-flex');
        });

        const startIdx = (currentPage - 1) * itemsPerPage;
        const endIdx = startIdx + itemsPerPage;
        const pageItems = filteredCards.slice(startIdx, endIdx);

        pageItems.forEach(card => {
            card.classList.remove('d-none');
            card.classList.add('d-flex');
        });

        // Update Info Text
        const total = filteredCards.length;
        const startNum = total === 0 ? 0 : startIdx + 1;
        const endNum = Math.min(endIdx, total);
        document.getElementById('paginationInfo').innerText = `${startNum}-${endNum} of ${total}`;

        renderPaginationControls();
    }

    function renderPaginationControls() {
        const totalPages = Math.ceil(filteredCards.length / itemsPerPage) || 1;
        const paginationList = document.getElementById('paginationList');
        paginationList.innerHTML = '';

        if (totalPages <= 1) return;

        // Previous Button
        paginationList.innerHTML += `
            <li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
                <button class="page-link" onclick="goToPage(${currentPage - 1})"><i class="fas fa-chevron-left"></i></button>
            </li>
        `;

        // Page Number Buttons
        for (let i = 1; i <= totalPages; i++) {
            paginationList.innerHTML += `
                <li class="page-item ${i === currentPage ? 'active' : ''}">
                    <button class="page-link" onclick="goToPage(${i})">${i}</button>
                </li>
            `;
        }

        // Next Button
        paginationList.innerHTML += `
            <li class="page-item ${currentPage === totalPages ? 'disabled' : ''}">
                <button class="page-link" onclick="goToPage(${currentPage + 1})"><i class="fas fa-chevron-right"></i></button>
            </li>
        `;
    }

    function goToPage(page) {
        const totalPages = Math.ceil(filteredCards.length / itemsPerPage) || 1;
        if (page < 1 || page > totalPages) return;
        currentPage = page;
        renderFacultyPage();
    }

    function onSearchInput() {
        currentPage = 1;
        initFacultyPagination();
    }

    document.addEventListener('DOMContentLoaded', () => {
        initFacultyPagination();
        renderData();
    });
</script>

<?php require_once __DIR__ . '/../../../../includes/layout-end.php'; ?>