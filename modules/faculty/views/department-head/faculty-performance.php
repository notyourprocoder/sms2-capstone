<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../models/FacultyModel.php';
require_once __DIR__ . '/../../controllers/FacultyPerformanceController.php';

$pdo = function_exists('facultyDb') ? facultyDb() : null;

// Instantiate Controller & Extract Variables
$controller = new FacultyPerformanceController($pdo);
$data       = $controller->handleRequest();
extract($data);

$pageTitle    = 'Faculty Performance';
$activeModule = 'faculty';
$activePage   = 'faculty-performance';
$breadcrumbs  = [
    ['label' => 'Faculty Management', 'url' => BASE_URL . '/modules/faculty/index.php'],
    ['label' => 'Faculty Performance', 'url' => null],
];

require_once __DIR__ . '/../../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../../includes/layout-start.php';
?>

<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <h1><i class="fas fa-chalkboard-teacher text-sms-primary me-2"></i> Faculty Performance</h1>
        <p class="text-muted mb-0">Showing faculty assigned to <strong><?= htmlspecialchars($headDepartment, ENT_QUOTES, 'UTF-8') ?></strong></p>
        <small class="text-muted">Overall = 50% Student + 30% Peer + 20% Department Head</small>
    </div>
</div>

<!-- Metric Summary Cards -->
<div class="row g-3 mb-4">
    <!-- Top Performers Card (Warning/Gold) -->
    <div class="col-12 col-md-4">
        <section class="card stat-card warning border shadow-sm position-relative h-100">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon me-3 text-warning fs-4">
                    <i class="fas fa-trophy"></i>
                </div>
                <div>
                    <h6 class="text-muted mb-0 small text-uppercase fw-bold">Top Performers</h6>
                    <h4 class="mb-0 fw-bold"><?= htmlspecialchars((string)($summary['top_performers'] ?? 0)) ?></h4>
                    <small class="text-success fw-semibold" style="font-size: 0.75rem;">
                        <i class="fas fa-star me-1"></i>Rating ≥ 4.5
                    </small>
                </div>
            </div>
            <a href="#" class="position-absolute top-0 end-0 m-3 text-muted border rounded p-1 d-flex align-items-center justify-content-center border-secondary-subtle" style="width: 24px; height: 24px; font-size: 0.7rem;" title="View Performers">
                <i class="fas fa-arrow-up-right-from-square"></i>
            </a>
        </section>
    </div>

    <!-- Dept Average Card (Success/Green) -->
    <div class="col-12 col-md-4">
        <section class="card stat-card success border shadow-sm position-relative h-100">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon me-3 text-success fs-4">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div>
                    <h6 class="text-muted mb-0 small text-uppercase fw-bold">Dept Average</h6>
                    <h4 class="mb-0 fw-bold"><?= htmlspecialchars((string)($summary['dept_avg'] ?? '0.0')) ?></h4>
                    <small class="text-success fw-semibold" style="font-size: 0.75rem;">
                        <i class="fas fa-arrow-trend-up me-1"></i>Overall Score
                    </small>
                </div>
            </div>
            <a href="#" class="position-absolute top-0 end-0 m-3 text-muted border rounded p-1 d-flex align-items-center justify-content-center border-secondary-subtle" style="width: 24px; height: 24px; font-size: 0.7rem;" title="View Average Details">
                <i class="fas fa-arrow-up-right-from-square"></i>
            </a>
        </section>
    </div>

    <!-- Faculty Evaluated Card (Info/Blue-Cyan) -->
    <div class="col-12 col-md-4">
        <section class="card stat-card info border shadow-sm position-relative h-100">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon me-3 text-info fs-4">
                    <i class="fas fa-user-check"></i>
                </div>
                <div>
                    <h6 class="text-muted mb-0 small text-uppercase fw-bold">Fully Evaluated</h6>
                    <h4 class="mb-0 fw-bold"><?= (int)($summary['total_evaluated'] ?? 0) ?> <span class="text-muted fs-6 fw-normal">/ <?= (int)($summary['total_faculty'] ?? 0) ?></span></h4>
                    <small class="text-success fw-semibold" style="font-size: 0.75rem;">
                        <i class="fas fa-check-circle me-1"></i>All 3 sources complete
                    </small>
                </div>
            </div>
            <a href="#" class="position-absolute top-0 end-0 m-3 text-muted border rounded p-1 d-flex align-items-center justify-content-center border-secondary-subtle" style="width: 24px; height: 24px; font-size: 0.7rem;" title="View Evaluated List">
                <i class="fas fa-arrow-up-right-from-square"></i>
            </a>
        </section>
    </div>
</div>

<!-- Top Performers List -->
<div class="card border shadow-sm p-3 bg-white mb-4">
    <h6 class="fw-bold mb-3"><i class="fas fa-trophy text-warning me-1"></i> Top Performers <span class="text-muted fw-normal small">(Rating ≥ 4.5)</span></h6>
    <div class="d-flex flex-wrap gap-3">
        <?php if (!empty($topPerformers)): ?>
            <?php foreach ($topPerformers as $top): ?>
                <div class="card border p-3 text-center shadow-xs" style="min-width: 150px;">
                    <div class="mb-2"><i class="fas fa-user-circle fa-2x text-primary"></i></div>
                    <div class="fw-bold small text-truncate" style="max-width: 130px;"><?= htmlspecialchars($top['full_name']) ?></div>
                    <div class="text-success fw-bold mt-1"><?= number_format((float)$top['overall'], 1) ?> <i class="fas fa-arrow-up small"></i></div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="card border p-3 text-center shadow-xs text-muted" style="min-width: 150px;">
                <div class="mb-2"><i class="fas fa-user-circle fa-2x text-secondary"></i></div>
                <div class="fw-bold small">Pending Data</div>
                <div class="text-muted small mt-1">0.0 —</div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Search & Auto-Filter Bar -->
<div class="card border shadow-sm p-3 bg-white mb-4">
    <form id="searchForm" onsubmit="event.preventDefault(); triggerAutoSearch();" class="row g-2 align-items-end">
        <div class="col-12 col-md-5">
            <label class="form-label small fw-bold text-muted text-uppercase mb-1">Faculty Name</label>
            <input type="text" id="searchInput" name="search_name" class="form-control form-control-sm" placeholder="Search faculty..." value="<?= htmlspecialchars($searchName) ?>" oninput="triggerAutoSearch()">
        </div>
        <div class="col-12 col-md-3">
            <label class="form-label small fw-bold text-muted text-uppercase mb-1">Evaluation Period</label>
            <select id="periodInput" name="evaluation_period" class="form-select form-select-sm" onchange="triggerAutoSearch()">
                <option value="">All Periods</option>
                <option value="2nd Semester 2025" <?= $searchPeriod === '2nd Semester 2025' ? 'selected' : '' ?>>2nd Semester 2025</option>
                <option value="1st Semester 2025" <?= $searchPeriod === '1st Semester 2025' ? 'selected' : '' ?>>1st Semester 2025</option>
            </select>
        </div>
        <div class="col-12 col-md-3">
            <label class="form-label small fw-bold text-muted text-uppercase mb-1">Rating Range</label>
            <select id="ratingInput" name="rating_range" class="form-select form-select-sm" onchange="triggerAutoSearch()">
                <option value="">All</option>
                <option value="4.5-5.0" <?= $ratingRange === '4.5-5.0' ? 'selected' : '' ?>>4.5 - 5.0</option>
                <option value="3.5-4.4" <?= $ratingRange === '3.5-4.4' ? 'selected' : '' ?>>3.5 - 4.4</option>
                <option value="0.0-3.4" <?= $ratingRange === '0.0-3.4' ? 'selected' : '' ?>>Below 3.5</option>
            </select>
        </div>
        <div class="col-12 col-md-1 text-end">
            <button type="button" class="btn btn-sm btn-outline-secondary w-100" onclick="resetSearchFilters()" title="Reset Filters"><i class="fas fa-sync-alt"></i></button>
        </div>
    </form>
</div>

<!-- Performance Overview Table -->
<div class="card border shadow-sm p-3 bg-white">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold mb-0">Performance Overview</h5>
        <select class="form-select form-select-sm w-auto bg-white text-dark fw-medium border shadow-sm">
            <option selected>10 per page</option>
        </select>
    </div>

    <div class="table-responsive">
        <table class="table align-middle text-dark mb-0">
            <thead class="table-light text-uppercase small text-muted">
                <tr>
                    <th>Faculty</th>
                    <th>Overall</th>
                    <th>Department Head</th>
                    <th>Peer-to-Peer</th>
                    <th>Student</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody id="tableBody">
                <?php if (!empty($facultyList)): ?>
                    <?php foreach ($facultyList as $row): ?>
                        <?= $controller->renderRowPublic($row) ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">No faculty members found matching your search in this department.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination Container -->
    <div id="paginationContainer">
        <?php if ($totalPages > 1): ?>
            <nav class="d-flex justify-content-end mt-3" id="paginationNav">
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>"><a class="page-link" href="#" onclick="fetchPage(<?= $page - 1 ?>); return false;">Previous</a></li>
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <li class="page-item <?= ($page == $i) ? 'active' : '' ?>"><a class="page-link" href="#" onclick="fetchPage(<?= $i ?>); return false;"><?= $i ?></a></li>
                    <?php endfor; ?>
                    <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>"><a class="page-link" href="#" onclick="fetchPage(<?= $page + 1 ?>); return false;">Next</a></li>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
</div>

<script>
let searchDebounce = null;

function triggerAutoSearch() {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(function() {
        performAjaxSearch(1);
    }, 300);
}

function fetchPage(page) {
    performAjaxSearch(page);
}

function performAjaxSearch(page = 1) {
    const searchName   = document.getElementById('searchInput').value;
    const searchPeriod = document.getElementById('periodInput').value;
    const ratingRange  = document.getElementById('ratingInput').value;

    const params = new URLSearchParams({
        search_name: searchName,
        evaluation_period: searchPeriod,
        rating_range: ratingRange,
        page: page
    });

    fetch('?' + params.toString(), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        document.getElementById('tableBody').outerHTML = data.tbody;
        document.getElementById('paginationContainer').innerHTML = data.pagination;
    })
    .catch(error => console.error('Error updating performance table:', error));
}

function resetSearchFilters() {
    document.getElementById('searchInput').value = '';
    document.getElementById('periodInput').value = '';
    document.getElementById('ratingInput').value = '';
    performAjaxSearch(1);
}

function viewPerformanceDetails(name, id) {
    alert("Viewing performance details for: " + name);
}

function openAiRecommendations(name) {
    alert("Generating AI recommendations for: " + name);
}
</script>

<?php 
require_once __DIR__ . '/../../../../includes/layout-end.php'; 
?>