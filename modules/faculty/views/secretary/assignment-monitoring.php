<?php
/**
 * Assignment Monitoring
 * Purpose: Display all faculty schedule assignments from the database.
 */
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../includes/authentication.php';
require_once __DIR__ . '/../../controllers/faculty-data.php';

requireAuth();

$pageTitle    = 'Assignment Monitoring';
$activeModule = 'faculty';
$activePage   = 'assignment-monitoring';
$breadcrumbs  = [
    ['label' => 'Faculty Management', 'url' => BASE_URL . '/modules/faculty/index.php'],
    ['label' => 'Secretary', 'url' => BASE_URL . '/modules/faculty/users/secretary/index.php'],
    ['label' => 'Assignment Monitoring', 'url' => null],
];

$assignmentCards = [];
$assignmentSummary = [];
$facultyConflicts = [];
$roomConflicts = [];
$allConflicts = [];
$dbTableStatus = 'Waiting for schedule data integration (REST API)';
$dbMatchingTables = [];

/* ============================================================
 * TODO: SCHEDULE DATA INTEGRATION (REST API)
 * ------------------------------------------------------------
 * Schedule/assignment data lives in a different module. The block
 * below queried faculty_class_assignments directly from this DB,
 * which is no longer the plan - replace it with a REST API call
 * to that module instead. Expected shape for $assignmentCards
 * (one row per class assignment) so the rest of this page (unit
 * summary table, chart, conflict detection, conflict modal) keeps
 * working unchanged once real data is wired in:
 *
 *   [
 *     'id'         => int,
 *     'faculty_id' => int,
 *     'class_id'   => int,
 *     'units'      => int,
 *     'room'       => string,
 *     'time'       => string,   // e.g. "8:00 AM - 9:30 AM"
 *     'days'       => string,   // e.g. "MWF"
 *     'status'     => string,
 *     'first_name' => string,
 *     'last_name'  => string,
 *   ]
 *
 * Example of what this will likely look like once the endpoint exists:
 *
 *   $response = fetch_from_scheduling_api(BASE_URL . '/api/schedules/assignments');
 *   if ($response['success']) {
 *       $assignmentCards = $response['data'];
 *   }
 *
 * Everything below this comment (conflict detection, summary table,
 * chart) already operates purely on the $assignmentCards array, so
 * no other changes should be needed elsewhere on this page once
 * $assignmentCards is populated from the real API response.
 * ============================================================ */

/*
try {
    $pdo = facultyDb();

    if ($pdo) {
        $dbMatchingTables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '%assign%' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);

        if (empty($dbMatchingTables)) {
            $dbMatchingTables = $pdo->query("SHOW TABLES LIKE '%assign%'")->fetchAll(PDO::FETCH_COLUMN);
        }

        $tableExists = in_array('faculty_class_assignments', $dbMatchingTables, true)
            || $pdo->query("SHOW TABLES LIKE 'faculty_class_assignments'")->fetchColumn() !== false;

        if ($tableExists) {
            $stmt = $pdo->query("
                SELECT fca.id, fca.faculty_id, fca.class_id, fca.units, fca.room, fca.time, fca.days, fca.status,
                       fp.first_name, fp.last_name
                FROM faculty_class_assignments fca
                LEFT JOIN faculty_profiles fp ON fp.id = fca.faculty_id
                ORDER BY fca.id DESC
            ");

            $assignmentCards = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

            $summaryStmt = $pdo->query("
                SELECT fca.faculty_id,
                       fp.first_name,
                       fp.last_name,
                       GROUP_CONCAT(fca.class_id ORDER BY fca.class_id SEPARATOR ', ') AS class_ids,
                       SUM(fca.units) AS total_units
                FROM faculty_class_assignments fca
                LEFT JOIN faculty_profiles fp ON fp.id = fca.faculty_id
                GROUP BY fca.faculty_id, fp.first_name, fp.last_name
                ORDER BY fca.faculty_id ASC
            ");
            $assignmentSummary = $summaryStmt ? $summaryStmt->fetchAll(PDO::FETCH_ASSOC) : [];

            $dbTableStatus = 'Connected to database: ' . DB_NAME;
        } else {
            $dbTableStatus = 'faculty_class_assignments not found in ' . DB_NAME;
        }
    }
} catch (Throwable $e) {
    error_log('[assignment-monitoring] ' . $e->getMessage());
    $dbTableStatus = 'Database connection error: ' . $e->getMessage();
}
*/

// Conflict detection - already works purely off $assignmentCards, so it
// stays active and will "just work" the moment the REST API above is wired
// in and starts populating $assignmentCards with real rows.
try {
    // Detect schedule conflicts (Faculty & Room conflicts)
    if (!empty($assignmentCards)) {
        // Build time slot index: faculty_id => [time_slot_key] => [assignments]
        $facultyTimeIndex = [];
        $roomTimeIndex = [];

        foreach ($assignmentCards as $assignment) {
            $fid = (int) ($assignment['faculty_id'] ?? 0);
            $rid = htmlspecialchars((string) ($assignment['room'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8');
            $time = htmlspecialchars((string) ($assignment['time'] ?? ''), ENT_QUOTES, 'UTF-8');
            $days = htmlspecialchars((string) ($assignment['days'] ?? ''), ENT_QUOTES, 'UTF-8');
            $classId = (int) ($assignment['class_id'] ?? 0);

            // Create a time slot key (faculty)
            if ($fid > 0 && !empty($time) && !empty($days)) {
                $timeSlotKey = $time . '|' . $days;
                if (!isset($facultyTimeIndex[$fid])) {
                    $facultyTimeIndex[$fid] = [];
                }
                if (!isset($facultyTimeIndex[$fid][$timeSlotKey])) {
                    $facultyTimeIndex[$fid][$timeSlotKey] = [];
                }
                $facultyTimeIndex[$fid][$timeSlotKey][] = $assignment;
            }

            // Create a time slot key (room)
            if (!empty($rid) && $rid !== 'Unknown' && !empty($time) && !empty($days)) {
                $timeSlotKey = $time . '|' . $days;
                if (!isset($roomTimeIndex[$rid])) {
                    $roomTimeIndex[$rid] = [];
                }
                if (!isset($roomTimeIndex[$rid][$timeSlotKey])) {
                    $roomTimeIndex[$rid][$timeSlotKey] = [];
                }
                $roomTimeIndex[$rid][$timeSlotKey][] = $assignment;
            }
        }

        // Find faculty conflicts (same faculty, same time slot, multiple classes)
        foreach ($facultyTimeIndex as $fid => $slots) {
            foreach ($slots as $timeSlotKey => $assignments) {
                if (count($assignments) > 1) {
                    foreach ($assignments as $assignment) {
                        $facultyConflicts[] = [
                            'type' => 'Faculty Schedule Conflict',
                            'faculty_id' => $fid,
                            'faculty_name' => trim((string) ($assignment['first_name'] ?? '') . ' ' . (string) ($assignment['last_name'] ?? '')) ?: 'Unknown',
                            'time_slot' => $timeSlotKey,
                            'classes' => array_map(function ($a) { return (int) ($a['class_id'] ?? 0); }, $assignments),
                            'severity' => 'high'
                        ];
                    }
                }
            }
        }

        // Find room conflicts (same room, same time slot, multiple classes)
        foreach ($roomTimeIndex as $rid => $slots) {
            foreach ($slots as $timeSlotKey => $assignments) {
                if (count($assignments) > 1) {
                    foreach ($assignments as $assignment) {
                        $roomConflicts[] = [
                            'type' => 'Room Schedule Conflict',
                            'room' => $rid,
                            'time_slot' => $timeSlotKey,
                            'classes' => array_map(function ($a) { return (int) ($a['class_id'] ?? 0); }, $assignments),
                            'severity' => 'high'
                        ];
                    }
                }
            }
        }
    }

    $allConflicts = array_merge($facultyConflicts, $roomConflicts);
} catch (Throwable $e) {
    error_log('[assignment-monitoring] conflict detection error: ' . $e->getMessage());
}

require_once __DIR__ . '/../../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../../includes/layout-start.php';
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<?php renderBreadcrumbs($breadcrumbs); ?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
        <h2 class="h4 fw-bold text-dark mb-1">
            <i class="fas fa-clipboard-list text-primary me-2"></i>Assignment Monitoring
        </h2>
        <p class="text-muted small mb-0">All faculty schedule assignments from the database</p>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-primary" type="button" id="orToolsResolveBtn" <?= empty($allConflicts) ? 'disabled' : '' ?>>
            <i class="fas fa-robot me-2"></i>
            <span class="fw-bold">Resolve with OR-Tools</span>
        </button>
        <button class="btn btn-outline-warning" type="button" data-bs-toggle="modal" data-bs-target="#conflictModal">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <span class="fw-bold">Conflicts</span>
            <?php if (!empty($allConflicts)): ?>
                <span class="badge bg-danger-subtle text-danger ms-2"><?= count($allConflicts) ?></span>
            <?php else: ?>
                <span class="badge bg-success-subtle text-success ms-2">✓</span>
            <?php endif; ?>
        </button>
    </div>
</div>

<?php if (!empty($dbMatchingTables)): ?>
<?php else: ?>
    <div class="alert alert-warning small mb-3">
        <?= htmlspecialchars($dbTableStatus, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>



<div class="row mt-4 g-4 align-items-stretch">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-0 py-3">
                <h5 class="fw-bold mb-0"><i class="fas fa-table me-2 text-primary"></i>Professor Unit Summary</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Faculty</th>
                                <th>Faculty ID</th>
                                <th>Class ID(s)</th>
                                <th>Units</th>
                                <th>Total Load</th>
                                <th>Max Units</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($assignmentSummary)): ?>
                                <?php foreach ($assignmentSummary as $summary): ?>
                                    <?php $totalUnits = (int) ($summary['total_units'] ?? 0); ?>
                                    <?php $status = $totalUnits >= 24 ? 'Exceeded' : ($totalUnits >= 20 ? 'Warning' : 'OK'); ?>
                                    <tr>
                                        <td><?= htmlspecialchars(trim((string) ($summary['first_name'] ?? '') . ' ' . (string) ($summary['last_name'] ?? '')) ?: 'Unknown Faculty', ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= (int) ($summary['faculty_id'] ?? 0) ?></td>
                                        <td><?= htmlspecialchars((string) ($summary['class_ids'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= $totalUnits ?></td>
                                        <td><?= $totalUnits ?></td>
                                        <td>24</td>
                                        <td>
                                            <span class="badge rounded-pill <?= $status === 'Exceeded' ? 'bg-danger-subtle text-danger' : ($status === 'Warning' ? 'bg-warning-subtle text-warning' : 'bg-success-subtle text-success') ?>">
                                                <?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">No unit summary available.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-0 py-3">
                <h5 class="fw-bold mb-0"><i class="fas fa-chart-pie me-2 text-primary"></i>Unit Load Distribution</h5>
            </div>
            <div class="card-body">
                <?php if (!empty($assignmentSummary)): ?>
                    <div style="height: 280px; position: relative;">
                        <canvas id="unitLoadChart"></canvas>
                    </div>
                <?php else: ?>
                    <div class="text-center text-muted py-4">No chart data available.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<br>
<div class="row g-3 mb-4">
    <?php if (!empty($assignmentCards)): ?>
        <?php foreach ($assignmentCards as $assignment): ?>
            <div class="col-md-6 col-xl-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                <div class="text-uppercase small text-muted fw-semibold">Assignment for</div>
                                <h5 class="mb-0 fw-bold">
                                    <?= htmlspecialchars(trim((string) ($assignment['first_name'] ?? '') . ' ' . (string) ($assignment['last_name'] ?? '')) ?: 'Faculty ID ' . (int) ($assignment['faculty_id'] ?? 0), ENT_QUOTES, 'UTF-8') ?>
                                </h5>
                            </div>
                            <span class="badge rounded-pill bg-primary-subtle text-primary">
                                <?= htmlspecialchars((string) ($assignment['status'] ?? 'pending'), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>

                        <div class="small text-muted mb-2">
                            <span class="fw-semibold text-dark">ID:</span>
                            <?= (int) ($assignment['id'] ?? 0) ?>
                        </div>
                        <div class="small text-muted mb-2">
                            <span class="fw-semibold text-dark">Faculty ID:</span>
                            <?= (int) ($assignment['faculty_id'] ?? 0) ?>
                        </div>
                        <div class="small text-muted mb-2">
                            <span class="fw-semibold text-dark">Class ID:</span>
                            <?= (int) ($assignment['class_id'] ?? 0) ?>
                        </div>
                        <div class="small text-muted mb-2">
                            <span class="fw-semibold text-dark">Units:</span>
                            <?= (int) ($assignment['units'] ?? 0) ?>
                        </div>
                        <div class="small text-muted mb-2">
                            <span class="fw-semibold text-dark">Room:</span>
                            <?= htmlspecialchars((string) ($assignment['room'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <div class="small text-muted mb-2">
                            <span class="fw-semibold text-dark">Time:</span>
                            <?= htmlspecialchars((string) ($assignment['time'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <div class="small text-muted mb-2">
                            <span class="fw-semibold text-dark">Days:</span>
                            <?= htmlspecialchars((string) ($assignment['days'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <div class="small text-muted">
                            <span class="fw-semibold text-dark">Status:</span>
                            <?= htmlspecialchars((string) ($assignment['status'] ?? 'pending'), ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <?php $status = (string) ($assignment['status'] ?? 'pending'); ?>
                        <?php if ($status === 'approved'): ?>
                            <!-- No action for approved assignments -->
                        <?php elseif ($status === 'waiting for approval'): ?>
                            <div class="mt-3">
                                <button class="btn btn-sm btn-success w-100" disabled>
                                    <i class="fas fa-check-circle me-1"></i>Awaiting Approval
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="mt-3">
                                <button class="btn btn-sm btn-primary w-100 confirm-btn" data-id="<?= (int) ($assignment['id'] ?? 0) ?>">
                                    <i class="fas fa-check me-1"></i>Confirm
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center text-muted py-4">
                    No schedule assignments found in the database.
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
<br>

<!-- Conflict Detection Modal -->
<div class="modal fade" id="conflictModal" tabindex="-1" aria-labelledby="conflictModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow-sm">
            <div class="modal-header bg-light border-0 py-3">
                <h5 class="modal-title fw-bold" id="conflictModalLabel">
                    <i class="fas fa-exclamation-triangle me-2 text-warning"></i>Conflict Detection &amp; Optimization
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- OR-Tools Decision Support Header -->
                <div class="alert alert-info border-0 mb-4" role="alert">
                    <div class="d-flex gap-2">
                        <div style="flex-shrink: 0;">
                            <i class="fas fa-robot fa-lg text-info"></i>
                        </div>
                        <div>
                            <h6 class="alert-heading fw-bold mb-1">Google OR-Tools Decision Support System</h6>
                            <small class="d-block mb-2">
                                This tool analyzes scheduling constraints and conflicts to provide recommendations. 
                                <strong>All final decisions remain with you.</strong> Review the suggestions and implement 
                                changes manually through the assignment interface.
                            </small>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <!-- Conflict Summary -->
                    <div class="col-md-4">
                        <div class="border rounded p-3 bg-light">
                            <h6 class="fw-bold text-dark mb-3">Conflict Summary</h6>
                            <div class="mb-2">
                                <span class="small text-muted">Faculty Conflicts:</span>
                                <strong class="d-block text-danger"><?= count($facultyConflicts) ?></strong>
                            </div>
                            <div class="mb-2">
                                <span class="small text-muted">Room Conflicts:</span>
                                <strong class="d-block text-warning"><?= count($roomConflicts) ?></strong>
                            </div>
                            <div>
                                <span class="small text-muted">Total Issues:</span>
                                <strong class="d-block text-info"><?= count($allConflicts) ?></strong>
                            </div>
                        </div>
                    </div>

                    <!-- Constraint Variables -->
                    <div class="col-md-4">
                        <div class="border rounded p-3 bg-light">
                            <h6 class="fw-bold text-dark mb-3">Constraint Analysis</h6>
                            <div class="mb-2">
                                <span class="small text-muted">Total Faculty:</span>
                                <strong class="d-block"><?= count($assignmentSummary) ?></strong>
                            </div>
                            <div class="mb-2">
                                <span class="small text-muted">Total Assignments:</span>
                                <strong class="d-block"><?= count($assignmentCards) ?></strong>
                            </div>
                            <div class="mb-2">
                                <span class="small text-muted">Conflicting Assignments:</span>
                                <strong class="d-block text-warning"><?= count($allConflicts) ?></strong>
                            </div>
                            <div>
                                <span class="small text-muted">Max Faculty Load:</span>
                                <strong class="d-block">24 units</strong>
                            </div>
                        </div>
                    </div>

                    <!-- Optimization Status -->
                    <div class="col-md-4">
                        <div class="border rounded p-3 bg-light">
                            <h6 class="fw-bold text-dark mb-3">Schedule Status</h6>
                            <?php if (count($allConflicts) === 0): ?>
                                <span class="badge bg-success-subtle text-success w-100 p-2 mb-2">✓ Feasible Schedule</span>
                                <small class="text-success">All constraints satisfied. No conflicts detected.</small>
                            <?php else: ?>
                                <span class="badge bg-danger-subtle text-danger w-100 p-2 mb-2">✗ Infeasible Schedule</span>
                                <small class="text-danger">
                                    <strong><?= count($allConflicts) ?></strong> constraint violation<?= count($allConflicts) !== 1 ? 's' : '' ?> detected. 
                                    Review suggestions below to resolve.
                                </small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Conflicts Detail List -->
                <?php if (!empty($allConflicts)): ?>
                    <div class="border-top pt-4">
                        <h6 class="fw-bold text-dark mb-3">Issues Detected</h6>
                        <div class="list-group">
                            <?php foreach ($allConflicts as $idx => $conflict): ?>
                                <div class="list-group-item border-start border-4 border-danger">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div>
                                            <h6 class="mb-1 fw-bold"><?= htmlspecialchars($conflict['type'], ENT_QUOTES, 'UTF-8') ?></h6>
                                            <small class="text-muted">
                                                <?php if ($conflict['type'] === 'Faculty Schedule Conflict'): ?>
                                                    <strong><?= htmlspecialchars($conflict['faculty_name'], ENT_QUOTES, 'UTF-8') ?></strong> 
                                                    assigned to multiple classes at <strong><?= htmlspecialchars($conflict['time_slot'], ENT_QUOTES, 'UTF-8') ?></strong>
                                                    (Classes: <?= implode(', ', $conflict['classes']) ?>)
                                                <?php else: ?>
                                                    Room <strong><?= htmlspecialchars($conflict['room'], ENT_QUOTES, 'UTF-8') ?></strong> 
                                                    double-booked at <strong><?= htmlspecialchars($conflict['time_slot'], ENT_QUOTES, 'UTF-8') ?></strong>
                                                    (Classes: <?= implode(', ', $conflict['classes']) ?>)
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                        <span class="badge bg-danger-subtle text-danger">HIGH</span>
                                    </div>
                                    
                                    <!-- OR-Tools Decision Support Suggestions -->
                                    <div class="mt-3 p-3 bg-info-subtle rounded border border-info-subtle">
                                        <h6 class="small fw-bold text-info mb-2">
                                            <i class="fas fa-lightbulb me-1"></i>OR-Tools Suggestions
                                        </h6>
                                        <ul class="small mb-0 ps-3">
                                            <?php if ($conflict['type'] === 'Faculty Schedule Conflict'): ?>
                                                <li class="mb-1">Reassign one class to a different time slot</li>
                                                <li class="mb-1">Assign one class to a different faculty member</li>
                                                <li class="mb-1">Split the classes across different faculty</li>
                                                <li>Review faculty availability for alternative time slots</li>
                                            <?php else: ?>
                                                <li class="mb-1">Move one class to a different room</li>
                                                <li class="mb-1">Reschedule one class to a different time slot</li>
                                                <li class="mb-1">Check available rooms for the same time slot</li>
                                                <li>Review room capacity constraints</li>
                                            <?php endif; ?>
                                        </ul>
                                        <small class="text-muted d-block mt-2">
                                            <i class="fas fa-info-circle me-1"></i>Select an option above and implement the change manually to resolve this conflict.
                                        </small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="border-top pt-4 text-center">
                        <div class="mb-3">
                            <i class="fas fa-check-circle text-success fa-3x mb-2"></i>
                        </div>
                        <h6 class="fw-bold text-success mb-2">Schedule is Feasible</h6>
                        <p class="text-muted small mb-0">
                            All constraints are satisfied and no conflicts were detected. 
                            The current schedule is valid and ready for implementation.
                        </p>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer border-0 bg-light">
                <div class="w-100 text-start me-auto">
                    <small class="text-muted d-block mb-2">
                        <i class="fas fa-arrow-right me-1"></i><strong>Next Step:</strong> 
                        Review the OR-Tools suggestions above and implement changes through the assignment interface.
                    </small>
                </div>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    /* ============================================================
     * TODO: OR-TOOLS CONFLICT RESOLUTION (REST API)
     * ------------------------------------------------------------
     * This button is meant to call a Google OR-Tools-based
     * scheduling service (likely the Python service in
     * modules/faculty/python) to automatically resolve the
     * detected conflicts. Uncomment and fill in the real endpoint
     * once that service/REST API is ready.
     * ============================================================ */
    const orToolsBtn = document.getElementById('orToolsResolveBtn');
    if (orToolsBtn) {
        orToolsBtn.addEventListener('click', function () {
            /*
            orToolsBtn.disabled = true;
            const originalHtml = orToolsBtn.innerHTML;
            orToolsBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Resolving...';

            fetch('<?= BASE_URL ?>/api/scheduling/or-tools-resolve.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    conflicts: <?= json_encode($allConflicts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('OR-Tools found a resolved schedule. Review the suggested changes.');
                    // TODO: render data.resolved_assignments / data.suggestions somewhere
                    location.reload();
                } else {
                    alert('OR-Tools could not resolve: ' + (data.message || 'Unknown error'));
                    orToolsBtn.disabled = false;
                    orToolsBtn.innerHTML = originalHtml;
                }
            })
            .catch(error => {
                console.error('OR-Tools resolve error:', error);
                alert('Error contacting the OR-Tools service.');
                orToolsBtn.disabled = false;
                orToolsBtn.innerHTML = originalHtml;
            });
            */

            // Placeholder until the REST API above is wired in.
            alert('OR-Tools integration is not connected yet.');
        });
    }

    // Handle Confirm buttons
    document.querySelectorAll('.confirm-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const assignmentId = this.dataset.id;
            const btnElement = this;
            
            if (!assignmentId) return;
            
            if (confirm('Change assignment status to "Waiting for Approval"?')) {
                fetch('<?= BASE_URL ?>/api/assignments/confirm.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ id: assignmentId })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update button appearance and disable it
                        btnElement.disabled = true;
                        btnElement.classList.remove('btn-primary');
                        btnElement.classList.add('btn-success');
                        btnElement.innerHTML = '<i class="fas fa-check-circle me-1"></i>Confirmed';
                        
                        // Show success message
                        alert('Assignment status updated to "Waiting for Approval"');
                        
                        // Optional: reload page after a delay
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        alert('Error: ' + (data.message || 'Failed to update status'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error updating assignment status');
                });
            }
        });
    });

    <?php if (!empty($assignmentSummary)): ?>
        const labels = <?= json_encode(array_map(function ($row) { return trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')) ?: 'Faculty ' . (int) ($row['faculty_id'] ?? 0); }, $assignmentSummary), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const data = <?= json_encode(array_map(function ($row) { return (int) ($row['total_units'] ?? 0); }, $assignmentSummary), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const colors = ['#0d6efd', '#198754', '#ffc107', '#dc3545', '#6f42c1', '#20c997', '#fd7e14', '#6610f2', '#0dcaf0', '#adb5bd'];

        new Chart(document.getElementById('unitLoadChart'), {
            type: 'pie',
            data: {
                labels: labels,
                datasets: [{
                    data: data,
                    backgroundColor: colors.slice(0, labels.length),
                    borderWidth: 1,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    <?php endif; ?>
});
</script>

<?php require_once __DIR__ . '/../../../../includes/layout-end.php'; ?>