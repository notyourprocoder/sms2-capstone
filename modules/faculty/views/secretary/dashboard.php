<?php
/**
 * Secretary Dashboard
 * Purpose: Premium SaaS-style overview of secretary tasks and department status
 * Design: Linear / Vercel / Stripe inspired
 */
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../includes/authentication.php';
requireAuth();
require_once __DIR__ . '/../../controllers/FacultyController.php';
require_once __DIR__ . '/../../controllers/faculty-data.php';

$pageTitle    = 'Secretary Dashboard';
$activeModule = 'faculty';
$activePage   = 'dashboard';
$breadcrumbs  = [
    ['label' => 'Faculty Management', 'url' => BASE_URL . '/modules/faculty/index.php'],
    ['label' => 'Secretary', 'url' => BASE_URL . '/modules/faculty/users/secretary/index.php'],
    ['label' => 'Dashboard', 'url' => null],
];

require_once __DIR__ . '/../../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../../includes/layout-start.php';
require_once __DIR__ . '/../../../../includes/nav-icons.php';

// ============================================================
// DYNAMIC DATABASE QUERIES
// ============================================================

$pdo = function_exists('facultyDb') ? facultyDb() : (function_exists('db') ? db() : null);

// Department-scoped faculty list - same proven method faculty-profile.php
// and faculty-records.php already use successfully, instead of guessing
// at $_SESSION keys directly.
$facultyController  = new FacultyController();
$departmentFaculty  = $facultyController->getDirectoryList();

// The faculty_profiles.id values for everyone in this department - used
// below to scope leave_requests and faculty_deadlines, since neither of
// those tables has a department_id column of its own.
// NOTE: this assumes leave_requests.faculty_id / faculty_deadlines.faculty_id
// store faculty_profiles.id (matching FacultyModel::fetchPerformanceRows'
// convention). If your leave-request-screening.php page actually uses
// faculty.faculty_id instead, tell me and I'll switch this to match.
$departmentFacultyIds = array_map('intval', array_column($departmentFaculty, 'id'));

// 1. TOTAL FACULTY - only this secretary's department, excluding Rejected
// and Pending Approval (profile_status values seen in faculty-records.php)
$totalFaculty = 0;
foreach ($departmentFaculty as $f) {
    $status = strtolower(trim((string) ($f['profile_status'] ?? '')));
    if ($status !== 'rejected' && $status !== 'pending approval') {
        $totalFaculty++;
    }
}

// 2. ON LEAVE TODAY - approved leave covering today, for this department's faculty
$onLeaveToday = 0;
if ($pdo && !empty($departmentFacultyIds)) {
    $placeholders = implode(',', array_fill(0, count($departmentFacultyIds), '?'));
    $stmtLeave = $pdo->prepare("
        SELECT COUNT(*)
        FROM faculty_db.leave_requests
        WHERE approval_status = 'Approved'
          AND CURRENT_DATE() BETWEEN start_date AND end_date
          AND faculty_id IN ($placeholders)
    ");
    $stmtLeave->execute($departmentFacultyIds);
    $onLeaveToday = (int) $stmtLeave->fetchColumn();
}

// 3. PENDING SCREENING COUNT - leave requests awaiting this secretary's screening
$pendingScreening = 0;
if ($pdo && !empty($departmentFacultyIds)) {
    $placeholders = implode(',', array_fill(0, count($departmentFacultyIds), '?'));
    $stmtPending = $pdo->prepare("
        SELECT COUNT(*)
        FROM faculty_db.leave_requests
        WHERE screening_status = 'Pending'
          AND faculty_id IN ($placeholders)
    ");
    $stmtPending->execute($departmentFacultyIds);
    $pendingScreening = (int) $stmtPending->fetchColumn();
}

// 4. LEAVE REQUESTS BY CATEGORY (Chart Data)
$leaveCategories = [];
$leaveCounts     = [];

if ($pdo && !empty($departmentFacultyIds)) {
    $placeholders = implode(',', array_fill(0, count($departmentFacultyIds), '?'));
    $stmtCategories = $pdo->prepare("
        SELECT leave_type, COUNT(*) as total
        FROM faculty_db.leave_requests
        WHERE approval_status != 'Rejected'
          AND faculty_id IN ($placeholders)
        GROUP BY leave_type
    ");
    $stmtCategories->execute($departmentFacultyIds);
    $categoryData = $stmtCategories->fetchAll(PDO::FETCH_ASSOC);
} else {
    $categoryData = [];
}

if (!empty($categoryData)) {
    foreach ($categoryData as $row) {
        $leaveCategories[] = htmlspecialchars($row['leave_type']);
        $leaveCounts[]     = (int) $row['total'];
    }
} else {
    // Default fallback values if no database records exist yet
    $leaveCategories = ['Sick Leave', 'Vacation Leave', 'Emergency Leave', 'Study Leave'];
    $leaveCounts     = [0, 0, 0, 0];
}

// 5. UPCOMING DEADLINES
// faculty_deadlines has no priority or completion-status column, so those
// aspects of the original mock aren't real data - every deadline is shown
// with a neutral priority marker instead of a fabricated one.
$deadlines = [];
if ($pdo) {
    $placeholders = !empty($departmentFacultyIds)
        ? implode(',', array_fill(0, count($departmentFacultyIds), '?'))
        : '';
    $sql = "
        SELECT title, due_date
        FROM faculty_db.faculty_deadlines
        WHERE due_date >= CURRENT_DATE()
    ";
    $params = [];
    if ($placeholders !== '') {
        // Include department-specific deadlines plus general ones (no faculty_id set)
        $sql .= " AND (faculty_id IS NULL OR faculty_id IN ($placeholders))";
        $params = $departmentFacultyIds;
    }
    $sql .= " ORDER BY due_date ASC LIMIT 5";

    $stmtDeadlines = $pdo->prepare($sql);
    $stmtDeadlines->execute($params);
    $deadlines = $stmtDeadlines->fetchAll(PDO::FETCH_ASSOC);
}

// Map priority values to design colors (kept for markup compatibility -
// faculty_deadlines has no priority column, so every real deadline gets
// the neutral 'muted' marker until/unless that column is added).
function getPriorityColor($priority) {
    return match (strtolower((string) $priority)) {
        'high', 'danger', 'urgent' => 'danger',
        'medium', 'warning'        => 'warning',
        'low', 'info'              => 'info',
        default                    => 'muted',
    };
}
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/modules/faculty/assets/css/faculty.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/dashboard-glass.css">
<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    /* ---------- Design Tokens ---------- */
    :root,
    [data-theme="light"] {
        --sec-bg:              #F8FAFC;
        --sec-bg-elevated:     #FFFFFF;
        --sec-surface:         #FFFFFF;
        --sec-surface-muted:   #F1F5F9;
        --sec-surface-hover:   #F8FAFC;
        --sec-border:          rgba(15, 23, 42, 0.08);
        --sec-border-strong:   rgba(15, 23, 42, 0.12);
        --sec-text:            #334155;
        --sec-text-strong:     #0F172A;
        --sec-text-muted:      #64748B;
        --sec-text-faint:      #94A3B8;
        --sec-accent:          #2563EB;
        --sec-accent-2:        #4F46E5;
        --sec-success:         #10B981;
        --sec-warning:         #F59E0B;
        --sec-danger:          #EF4444;
        --sec-info:            #3B82F6;
        --sec-shadow-sm:       0 1px 2px rgba(15,23,42,0.04), 0 1px 3px rgba(15,23,42,0.03);
        --sec-shadow-md:       0 4px 6px -1px rgba(15,23,42,0.04), 0 2px 4px -2px rgba(15,23,42,0.03);
        --sec-shadow-lg:       0 10px 15px -3px rgba(15,23,42,0.05), 0 4px 6px -4px rgba(15,23,42,0.03);
        --sec-radius-xs:       6px;
        --sec-radius-sm:       8px;
        --sec-radius-md:       12px;
        --sec-radius-lg:       16px;
        --sec-radius-xl:       20px;
        --sec-ease:            cubic-bezier(0.4, 0, 0.2, 1);
    }

    [data-theme="dark"] {
        --sec-bg:              #080E1E;
        --sec-bg-elevated:     #0B132B;
        --sec-surface:         #0F172A;
        --sec-surface-muted:   #131C31;
        --sec-surface-hover:   #111C33;
        --sec-border:          rgba(255, 255, 255, 0.06);
        --sec-border-strong:   rgba(255, 255, 255, 0.10);
        --sec-text:            #CBD5E1;
        --sec-text-strong:     #F1F5F9;
        --sec-text-muted:      #94A3B8;
        --sec-text-faint:      #64748B;
        --sec-accent:          #3B82F6;
        --sec-accent-2:        #6366F1;
        --sec-success:         #34D399;
        --sec-warning:         #FBBF24;
        --sec-danger:          #F87171;
        --sec-info:            #60A5FA;
        --sec-shadow-sm:       0 1px 2px rgba(0,0,0,0.3), 0 1px 3px rgba(0,0,0,0.2);
        --sec-shadow-md:       0 4px 6px -1px rgba(0,0,0,0.35), 0 2px 4px -2px rgba(0,0,0,0.2);
        --sec-shadow-lg:       0 10px 15px -3px rgba(0,0,0,0.4), 0 4px 6px -4px rgba(0,0,0,0.25);
    }

    /* ---------- Dashboard Shell ---------- */
    .sec-dashboard {
        font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, sans-serif;
        color: var(--sec-text);
        line-height: 1.5;
        padding-bottom: 2rem;
    }

    /* ---------- Page Header ---------- */
    .sec-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1.5rem;
        flex-wrap: wrap;
        margin-bottom: 2rem;
        padding: 1.75rem 0 0.25rem;
    }

    .sec-header-left { min-width: 0; flex: 1; }

    .sec-kicker {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.72rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--sec-accent);
        margin-bottom: 0.6rem;
    }
    .sec-kicker::before {
        content: '';
        width: 6px; height: 6px;
        border-radius: 50%;
        background: var(--sec-accent);
        box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
    }

    .sec-title {
        margin: 0;
        font-size: 1.65rem;
        font-weight: 700;
        letter-spacing: -0.025em;
        color: var(--sec-text-strong);
        line-height: 1.2;
    }

    .sec-subtitle {
        margin: 0.45rem 0 0;
        font-size: 0.9rem;
        color: var(--sec-text-muted);
        font-weight: 450;
    }

    .sec-header-actions {
        display: flex;
        gap: 0.6rem;
        flex-wrap: wrap;
        align-items: center;
    }

    .sec-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        padding: 0.55rem 0.95rem;
        border-radius: var(--sec-radius-sm);
        font-size: 0.82rem;
        font-weight: 600;
        letter-spacing: -0.01em;
        border: 1px solid transparent;
        cursor: pointer;
        transition: all 0.18s var(--sec-ease);
        text-decoration: none !important;
        white-space: nowrap;
        line-height: 1.3;
    }
    .sec-btn-secondary {
        background: var(--sec-surface);
        color: var(--sec-text-strong);
        border-color: var(--sec-border-strong);
    }
    .sec-btn-secondary:hover {
        background: var(--sec-surface-hover);
        border-color: var(--sec-text-muted);
        transform: translateY(-1px);
        box-shadow: var(--sec-shadow-sm);
    }

    /* ---------- Card Container Base ---------- */
    .sec-card {
        background: var(--sec-surface);
        border: 1px solid var(--sec-border);
        border-radius: var(--sec-radius-lg);
        box-shadow: var(--sec-shadow-sm);
        transition: box-shadow 0.2s var(--sec-ease), border-color 0.2s var(--sec-ease), transform 0.2s var(--sec-ease);
        overflow: hidden;
    }
    .sec-card:hover {
        box-shadow: var(--sec-shadow-md);
    }

    .sec-card-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        padding: 1.25rem 1.35rem 0.9rem;
        border-bottom: 1px solid var(--sec-border);
    }

    .sec-card-title-wrap { min-width: 0; }

    .sec-card-title {
        margin: 0;
        font-size: 0.95rem;
        font-weight: 650;
        letter-spacing: -0.01em;
        color: var(--sec-text-strong);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .sec-card-sub {
        margin: 0.25rem 0 0;
        font-size: 0.78rem;
        color: var(--sec-text-muted);
        font-weight: 450;
    }

    .sec-card-head-right {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-shrink: 0;
    }

    .sec-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.32rem 0.7rem;
        border-radius: 999px;
        font-size: 0.72rem;
        font-weight: 600;
        letter-spacing: -0.005em;
        border: 1px solid var(--sec-border-strong);
        background: var(--sec-surface-muted);
        color: var(--sec-text-muted);
        white-space: nowrap;
    }

    .sec-card-body { padding: 1.35rem; }

    /* ---------- Stat Cards ---------- */
    .stat-card {
        background: var(--sec-surface);
        border: 1px solid var(--sec-border) !important;
        border-radius: var(--sec-radius-md);
        transition: transform 0.2s var(--sec-ease), box-shadow 0.2s var(--sec-ease);
        overflow: hidden;
    }
    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: var(--sec-shadow-md) !important;
    }

    /* ---------- Grid Layout ---------- */
    .sec-content-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.35fr) minmax(0, 1fr);
        gap: 1.25rem;
        margin-top: 1.25rem;
    }
    @media (max-width: 991.98px) {
        .sec-content-grid { grid-template-columns: 1fr; }
    }

    .sec-stack { display: grid; gap: 1.25rem; }

    /* ---------- Chart Wrapper ---------- */
    .chart-wrapper {
        position: relative;
        width: 100%;
        height: 280px;
    }

    /* ---------- Deadlines ---------- */
    .sec-deadlines {
        list-style: none;
        margin: 0;
        padding: 0;
        display: grid;
        gap: 0.5rem;
    }

    .sec-deadline-item {
        display: flex;
        align-items: center;
        gap: 1rem;
        padding: 0.75rem 0.85rem;
        border-radius: var(--sec-radius-sm);
        border: 1px solid var(--sec-border);
        background: var(--sec-bg-elevated);
        transition: all 0.15s var(--sec-ease);
    }
    .sec-deadline-item:hover {
        border-color: var(--sec-border-strong);
        background: var(--sec-surface-hover);
    }

    .sec-deadline-date {
        flex-shrink: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        min-width: 52px;
        padding: 0.35rem 0.4rem;
        border-radius: var(--sec-radius-xs);
        background: var(--sec-surface-muted);
        border: 1px solid var(--sec-border);
    }

    .sec-deadline-date span {
        display: block;
        font-size: 0.58rem;
        font-weight: 650;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: var(--sec-text-faint);
    }
    .sec-deadline-date strong {
        display: block;
        font-size: 1.05rem;
        font-weight: 800;
        letter-spacing: -0.03em;
        color: var(--sec-text-strong);
        line-height: 1;
        margin-top: 1px;
        font-variant-numeric: tabular-nums;
    }

    .sec-deadline-text {
        flex: 1;
        min-width: 0;
        font-size: 0.82rem;
        font-weight: 550;
        color: var(--sec-text-strong);
        line-height: 1.35;
    }

    .sec-priority {
        flex-shrink: 0;
        width: 8px;
        height: 8px;
        border-radius: 50%;
    }
    .p-danger  { background: var(--sec-danger);  box-shadow: 0 0 0 3px color-mix(in srgb, var(--sec-danger) 20%, transparent); }
    .p-warning { background: var(--sec-warning); box-shadow: 0 0 0 3px color-mix(in srgb, var(--sec-warning) 20%, transparent); }
    .p-info    { background: var(--sec-info);    box-shadow: 0 0 0 3px color-mix(in srgb, var(--sec-info) 20%, transparent); }
    .p-muted   { background: var(--sec-text-faint); box-shadow: 0 0 0 3px color-mix(in srgb, var(--sec-text-faint) 18%, transparent); }

    .sec-mb { margin-bottom: 1.25rem; }

    .sec-card,
    .sec-header {
        animation: secFadeIn 0.5s var(--sec-ease) both;
    }

    @keyframes secFadeIn {
        from { opacity: 0; transform: translateY(6px); }
        to   { opacity: 1; transform: translateY(0); }
    }
</style>

<div class="sec-dashboard">

    <!-- PAGE HEADER -->
    <header class="sec-header">
        <div class="sec-header-left">
            <span class="sec-kicker">Faculty · Secretary Workspace</span>
            <h1 class="sec-title">Secretary Dashboard</h1>
            <p class="sec-subtitle">College of Computer Studies (CCS) — Task overview, department status, and quick actions.</p>
        </div>
        <div class="sec-header-actions">
            <button type="button" class="sec-btn sec-btn-secondary" onclick="window.location.href='<?= BASE_URL ?>/modules/faculty/users/secretary/pages/daily-attendance-log.php'">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                    <line x1="16" y1="2" x2="16" y2="6"/>
                    <line x1="8" y1="2" x2="8" y2="6"/>
                    <line x1="3" y1="10" x2="21" y2="10"/>
                    <polyline points="9 16 11 18 15 14"/>
                </svg>
                Record Attendance
            </button>
            <button type="button" class="btn btn-primary d-inline-flex align-items-center gap-2 rounded-3 shadow-sm" onclick="window.location.href='<?= BASE_URL ?>/modules/faculty/users/secretary/pages/leave-request-screening.php'">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M22 12h-4l-3 9L9 3l-3 9H2"/>
                </svg>
                <span>Pending Screening</span>
                <span class="badge bg-white text-primary rounded-pill fw-bold"><?= $pendingScreening ?></span>
            </button>
        </div>
    </header>

    <!-- PERFORMANCE OVERVIEW -->
    <section class="sec-card sec-mb">
        <div class="sec-card-head">
            <div class="sec-card-title-wrap">
                <h2 class="sec-card-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <line x1="18" y1="20" x2="18" y2="10"/>
                        <line x1="12" y1="20" x2="12" y2="4"/>
                        <line x1="6" y1="20" x2="6" y2="14"/>
                    </svg>
                    Performance Overview
                </h2>
                <p class="sec-card-sub">Key metrics for your workspace · Faculty operations snapshot</p>
            </div>
            <div class="sec-card-head-right">
                <span class="sec-chip">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
                    </svg>
                    This month
                </span>
            </div>
        </div>
        <div class="sec-card-body">
            <div class="row g-3">

                <!-- Card 1: Dynamic Active Total Faculty -->
                <div class="col-12 col-md-6">
                    <section class="card stat-card primary border shadow-sm position-relative h-100">
                        <div class="card-body d-flex align-items-center">
                            <div class="stat-icon me-3 text-primary fs-4">
                                <i class="fas fa-users"></i>
                            </div>
                            <div>
                                <h6 class="text-muted mb-0 small text-uppercase fw-bold">Total Faculty</h6>
                                <h4 class="mb-0 fw-bold"><?= number_format($totalFaculty) ?></h4>
                                <small class="text-muted fw-semibold" style="font-size: 0.75rem;">
                                    Active department faculty members
                                </small>
                            </div>
                        </div>
                        <a href="<?= BASE_URL ?>/modules/faculty/users/secretary/pages/faculty-list.php" class="position-absolute top-0 end-0 m-3 text-muted border rounded p-1 d-flex align-items-center justify-content-center border-secondary-subtle" style="width: 24px; height: 24px; font-size: 0.7rem;" title="View Details">
                            <i class="fas fa-arrow-up-right-from-square"></i>
                        </a>
                    </section>
                </div>

                <!-- Card 2: Dynamic On Leave Today -->
                <div class="col-12 col-md-6">
                    <section class="card stat-card warning border shadow-sm position-relative h-100">
                        <div class="card-body d-flex align-items-center">
                            <div class="stat-icon me-3 text-warning fs-4">
                                <i class="fas fa-calendar-minus"></i>
                            </div>
                            <div>
                                <h6 class="text-muted mb-0 small text-uppercase fw-bold">On Leave Today</h6>
                                <h4 class="mb-0 fw-bold"><?= number_format($onLeaveToday) ?></h4>
                                <small class="text-muted fw-semibold" style="font-size: 0.75rem;">
                                    Approved leave active today
                                </small>
                            </div>
                        </div>
                        <a href="<?= BASE_URL ?>/modules/faculty/users/secretary/pages/leave-request-screening.php" class="position-absolute top-0 end-0 m-3 text-muted border rounded p-1 d-flex align-items-center justify-content-center border-secondary-subtle" style="width: 24px; height: 24px; font-size: 0.7rem;" title="View Details">
                            <i class="fas fa-arrow-up-right-from-square"></i>
                        </a>
                    </section>
                </div>

            </div>
        </div>
    </section>

    <!-- TWO-COLUMN CONTENT GRID -->
    <div class="sec-content-grid">

        <!-- LEFT COLUMN: Leave Requests Chart -->
        <div class="sec-stack">
            <section class="sec-card">
                <div class="sec-card-head">
                    <div class="sec-card-title-wrap">
                        <h2 class="sec-card-title">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M21.21 15.89A10 10 0 1 1 8 2.83"/>
                                <path d="M22 12A10 10 0 0 0 12 2v10z"/>
                            </svg>
                            Leave Requests by Category
                        </h2>
                        <p class="sec-card-sub">Distribution of pending and approved leave types</p>
                    </div>
                    <div class="sec-card-head-right">
                        <span class="sec-chip">This Month</span>
                    </div>
                </div>
                <div class="sec-card-body">
                    <div class="chart-wrapper">
                        <canvas id="leaveCategoryChart"></canvas>
                    </div>
                </div>
            </section>
        </div>

        <!-- RIGHT COLUMN: Deadlines -->
        <div class="sec-stack">
            <section class="sec-card">
                <div class="sec-card-head">
                    <div class="sec-card-title-wrap">
                        <h2 class="sec-card-title">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="12" cy="12" r="10"/>
                                <polyline points="12 6 12 12 16 14"/>
                            </svg>
                            Upcoming Deadlines
                        </h2>
                        <p class="sec-card-sub">Action items with priority · CCS Secretary queue</p>
                    </div>
                    <div class="sec-card-head-right">
                        <span class="sec-chip"><?= count($deadlines) ?> tasks</span>
                    </div>
                </div>
                <div class="sec-card-body">
                    <ul class="sec-deadlines">
                        <?php if (!empty($deadlines)): ?>
                            <?php foreach ($deadlines as $item): 
                                $date  = strtotime($item['due_date']);
                                $month = date('M', $date);
                                $day   = date('d', $date);
                                $p     = getPriorityColor($item['priority'] ?? null);
                            ?>
                                <li class="sec-deadline-item">
                                    <div class="sec-deadline-date">
                                        <span><?= $month ?></span>
                                        <strong><?= $day ?></strong>
                                    </div>
                                    <span class="sec-deadline-text"><?= htmlspecialchars($item['title']) ?></span>
                                    <span class="sec-priority p-<?= $p ?>"></span>
                                </li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li class="sec-deadline-item">
                                <span class="sec-deadline-text text-muted">No pending deadlines scheduled.</span>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </section>
        </div>

    </div>

</div>

<!-- DYNAMIC CHART INITIALIZATION -->
<script>
document.addEventListener("DOMContentLoaded", function () {
    const textColor = '#94A3B8';

    // Inject Dynamic PHP arrays into Javascript
    const leaveLabels = <?= json_encode($leaveCategories) ?>;
    const leaveData   = <?= json_encode($leaveCounts) ?>;

    const ctxLeave = document.getElementById('leaveCategoryChart').getContext('2d');
    new Chart(ctxLeave, {
        type: 'doughnut',
        data: {
            labels: leaveLabels,
            datasets: [{
                data: leaveData,
                backgroundColor: ['#EF4444', '#3B82F6', '#F59E0B', '#10B981', '#8B5CF6', '#EC4899'],
                borderWidth: 2,
                borderColor: '#0F172A'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { color: textColor, font: { family: 'Inter', size: 12 }, padding: 20 }
                }
            },
            cutout: '68%'
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../../../includes/layout-end.php'; ?>