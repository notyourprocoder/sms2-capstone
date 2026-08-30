<?php
/**
 * SMS 2 - Teaching History
 * Module: Faculty Management
 */
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../includes/authentication.php';
requireAuth();
require_once __DIR__ . '/../../controllers/FacultyController.php';

$pageTitle    = 'Teaching History';
$activeModule = 'faculty';
$activePage   = 'teaching-history';
$breadcrumbs  = [
    ['label' => 'Faculty Management', 'url' => BASE_URL . '/modules/faculty/index.php'],
    ['label' => 'Teaching History', 'url' => null],
];

// Real faculty list scoped to the logged-in dean's departments
$facultyController = new FacultyController();
$deanFacultyList   = $facultyController->getDirectoryList();

// Rotating avatar color palette
$avatarPalette = [
    ['bg' => 'bg-primary', 'text' => 'text-white'],
    ['bg' => 'bg-info',    'text' => 'text-dark'],
    ['bg' => 'bg-success', 'text' => 'text-white'],
    ['bg' => 'bg-warning', 'text' => 'text-dark'],
    ['bg' => 'bg-danger',  'text' => 'text-white'],
    ['bg' => 'bg-secondary', 'text' => 'text-white'],
];

require_once __DIR__ . '/../../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../../includes/layout-start.php';
?>

<?php renderBreadcrumbs($breadcrumbs); ?>

<style>
    /* Custom Scrollbar Styles */
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
        max-height: 520px;
        overflow-y: auto;
    }
</style>

<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <h1><i class="fas fa-chalkboard-teacher text-sms-primary me-2"></i>Teaching History</h1>
    </div>
</div>

<div class="container-fluid my-4 bg-light text-dark p-3 rounded-3">
    <div class="row g-4">
        
        <!-- LEFT COLUMN: Faculty Members List -->
        <div class="col-12 col-lg-5 col-xl-4">
            <div class="card shadow-sm border border-secondary border-opacity-25 bg-white h-100">
                <div class="card-header bg-white border-bottom border-secondary border-opacity-25 d-flex justify-content-between align-items-center py-3">
                    <h5 class="mb-0 fw-bold fs-6 text-dark">Faculty Members</h5>
                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25">Teaching Logs</span>
                </div>
                <div class="px-3 pt-3">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-secondary border-opacity-25"><i class="fas fa-search text-secondary"></i></span>
                        <input type="text" id="facultySearchInput" class="form-control border-secondary border-opacity-25" placeholder="Search by name or department..." oninput="onSearchInput()">
                    </div>
                </div>
                <div class="card-body p-3 custom-scrollbar faculty-list-scroll">
                    
                    <div class="d-flex flex-column gap-2" id="facultyListContainer">
                        <?php if (empty($deanFacultyList)): ?>
                            <div class="text-center text-secondary small py-4">No faculty members found.</div>
                        <?php endif; ?>
                        
                        <?php foreach ($deanFacultyList as $i => $f):
                            $fullName  = trim(($f['first_name'] ?? '') . ' ' . ($f['last_name'] ?? ''));
                            $initials  = strtoupper(substr($f['first_name'] ?? '', 0, 1) . substr($f['last_name'] ?? '', 0, 1));
                            $dept      = (string) ($f['designated_department'] ?? '—');
                            $spec      = trim((string) ($f['specialization_assignment'] ?? ''));
                            $title     = (string) ($f['position'] ?? ($f['academic_rank'] ?? ''));
                            $facultyId = (string) ($f['faculty_id'] ?? '');
                            $color     = $avatarPalette[$i % count($avatarPalette)];
                            $avatarClass = $color['bg'] . ' ' . $color['text'];
                        ?>
                            <div class="faculty-item d-flex align-items-center justify-content-between p-2 rounded-3 border border-secondary border-opacity-25 bg-light transition-all" 
                                 data-name="<?= htmlspecialchars(mb_strtolower($fullName), ENT_QUOTES, 'UTF-8') ?>" 
                                 data-dept="<?= htmlspecialchars(mb_strtolower($dept), ENT_QUOTES, 'UTF-8') ?>">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="rounded-circle <?= $avatarClass ?> d-flex align-items-center justify-content-center fw-bold shadow-sm" style="width: 42px; height: 42px; font-size: 14px; min-width: 42px;">
                                        <?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-dark small"><?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="text-secondary" style="font-size: 11px;">
                                            <?= htmlspecialchars($dept, ENT_QUOTES, 'UTF-8') ?><?= $spec !== '' ? ' • ' . htmlspecialchars($spec, ENT_QUOTES, 'UTF-8') : '' ?>
                                        </div>
                                    </div>
                                </div>
                                <button class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1" style="font-size: 12px;"
                                    onclick="loadTeachingHistory('<?= htmlspecialchars($facultyId, ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($dept, ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?>', '<?= $avatarClass ?>', '<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>')">
                                    View
                                </button>
                            </div>
                        <?php endforeach; ?>
                        
                        <div id="facultyNoResults" class="text-center text-secondary small py-4" style="display: none;">No faculty members match your search.</div>
                    </div>
                </div>

                <!-- Pagination Footer -->
                <div class="card-header bg-white border-top border-secondary border-opacity-25 d-flex justify-content-between align-items-center py-2 px-3" id="paginationControls">
                    <span class="text-secondary small" style="font-size: 11px;" id="paginationInfo">Showing 0-0 of 0</span>
                    <nav aria-label="Faculty List Navigation">
                        <ul class="pagination pagination-sm mb-0" id="paginationList">
                            <!-- Dynamic Page Buttons -->
                        </ul>
                    </nav>
                </div>
            </div>
        </div>

        <!-- RIGHT COLUMN: Detailed Teaching History Display -->
        <div class="col-12 col-lg-7 col-xl-8">
            <div class="card shadow-sm border border-secondary border-opacity-25 bg-white h-100">
                
                <!-- Header Panel for Selected Faculty -->
                <div class="card-header bg-white border-bottom border-secondary border-opacity-25 p-3">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                        <div class="d-flex align-items-center gap-3">
                            <div id="targetAvatar" class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center fw-bold fs-5 shadow-sm" style="width: 48px; height: 48px; min-width: 48px;">
                                —
                            </div>
                            <div>
                                <h5 class="mb-0 fw-bold text-dark" id="targetName">Select a faculty member</h5>
                                <div class="d-flex align-items-center gap-2 mt-1">
                                    <span class="badge border border-secondary border-opacity-25 text-dark bg-light" id="targetDept">—</span>
                                    <span class="text-secondary small" id="targetTitle">—</span>
                                    <span class="text-secondary small">• ID: <span id="targetId" class="text-dark fw-semibold">—</span></span>
                                </div>
                            </div>
                        </div>
                        <button class="btn btn-sm btn-outline-secondary border-opacity-50 text-dark d-flex align-items-center gap-2 bg-light" onclick="exportHistory()">
                            <i class="fas fa-download"></i> Export History PDF
                        </button>
                    </div>
                </div>

                <!-- History Body Section -->
                <div class="card-body p-4">
                    
                    <!-- Quick Stats Row -->
                    <div class="row g-3 mb-4">
                        <div class="col-4">
                            <div class="p-3 rounded-3 border border-secondary border-opacity-25 bg-light text-center">
                                <small class="text-secondary d-block mb-1" style="font-size: 11px;">Total Semesters</small>
                                <span class="h5 fw-bold mb-0 text-dark" id="statSemesters">—</span>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-3 rounded-3 border border-secondary border-opacity-25 bg-light text-center">
                                <small class="text-secondary d-block mb-1" style="font-size: 11px;">Subjects Handled</small>
                                <span class="h5 fw-bold mb-0 text-primary" id="statSubjects">—</span>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-3 rounded-3 border border-secondary border-opacity-25 bg-light text-center">
                                <small class="text-secondary d-block mb-1" style="font-size: 11px;">Avg. Evaluation</small>
                                <span class="h5 fw-bold mb-0 text-success" id="statEval">—</span>
                            </div>
                        </div>
                    </div>

                    <h6 class="text-secondary small text-uppercase fw-bold mb-3" style="letter-spacing: 0.5px;">Academic Assignments Timeline</h6>

                    <!-- Teaching History Table -->
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="historyTable">
                            <thead class="table-light border-bottom border-secondary border-opacity-25 small text-uppercase text-secondary">
                                <tr>
                                    <th>Academic Term</th>
                                    <th>Subject Code & Title</th>
                                    <th>Section</th>
                                    <th>Units</th>
                                    <th class="text-end">Status</th>
                                </tr>
                            </thead>
                            <tbody class="border-top-0" id="historyTableBody">
                                <tr>
                                    <td colspan="5" class="text-center text-secondary py-4">Select a faculty member to view their teaching history.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                </div>
            </div>
        </div>

    </div>
</div>

<script>
    const ITEMS_PER_PAGE = 10;
    let currentPage = 1;
    let filteredItems = [];

    document.addEventListener('DOMContentLoaded', () => {
        initPagination();
    });

    function getMatchingItems() {
        const input = document.getElementById('facultySearchInput');
        const query = input ? input.value.trim().toLowerCase() : '';
        const allItems = Array.from(document.querySelectorAll('#facultyListContainer .faculty-item'));

        return allItems.filter(item => {
            const name = (item.getAttribute('data-name') || '').toLowerCase();
            const dept = (item.getAttribute('data-dept') || '').toLowerCase();
            return name.includes(query) || dept.includes(query);
        });
    }

    function initPagination() {
        filteredItems = getMatchingItems();
        currentPage = 1;
        renderPage();
    }

    function onSearchInput() {
        initPagination();
    }

    function renderPage() {
        const allItems = document.querySelectorAll('#facultyListContainer .faculty-item');
        const totalItems = filteredItems.length;
        const totalPages = Math.ceil(totalItems / ITEMS_PER_PAGE) || 1;

        if (currentPage > totalPages) currentPage = totalPages;
        if (currentPage < 1) currentPage = 1;

        const startIndex = (currentPage - 1) * ITEMS_PER_PAGE;
        const endIndex = startIndex + ITEMS_PER_PAGE;

        allItems.forEach(item => {
            item.classList.remove('d-flex');
            item.classList.add('d-none');
        });

        const currentPageItems = filteredItems.slice(startIndex, endIndex);
        currentPageItems.forEach(item => {
            item.classList.remove('d-none');
            item.classList.add('d-flex');
        });

        // Toggle No Results Message
        const noResults = document.getElementById('facultyNoResults');
        if (noResults) {
            noResults.style.display = totalItems === 0 ? 'block' : 'none';
        }

        // Update Pagination Info Text
        const infoEl = document.getElementById('paginationInfo');
        if (infoEl) {
            if (totalItems === 0) {
                infoEl.innerText = 'Showing 0 of 0';
            } else {
                const currentStart = startIndex + 1;
                const currentEnd = Math.min(endIndex, totalItems);
                infoEl.innerText = `Showing ${currentStart}-${currentEnd} of ${totalItems}`;
            }
        }

        // Build Pagination Buttons
        const listEl = document.getElementById('paginationList');
        if (!listEl) return;
        listEl.innerHTML = '';

        if (totalPages <= 1) return;

        // Previous Button
        const prevLi = document.createElement('li');
        prevLi.className = `page-item ${currentPage === 1 ? 'disabled' : ''}`;
        prevLi.innerHTML = `<a class="page-link py-1 px-2" href="#" onclick="changePage(${currentPage - 1}); return false;">&laquo;</a>`;
        listEl.appendChild(prevLi);

        // Numeric Page Buttons
        for (let page = 1; page <= totalPages; page++) {
            const li = document.createElement('li');
            li.className = `page-item ${page === currentPage ? 'active' : ''}`;
            li.innerHTML = `<a class="page-link py-1 px-2" href="#" onclick="changePage(${page}); return false;">${page}</a>`;
            listEl.appendChild(li);
        }

        // Next Button
        const nextLi = document.createElement('li');
        nextLi.className = `page-item ${currentPage === totalPages ? 'disabled' : ''}`;
        nextLi.innerHTML = `<a class="page-link py-1 px-2" href="#" onclick="changePage(${currentPage + 1}); return false;">&raquo;</a>`;
        listEl.appendChild(nextLi);
    }

    function changePage(page) {
        currentPage = page;
        renderPage();
    }

    function loadTeachingHistory(id, name, dept, initials, avatarBgClass, title) {
        document.getElementById('targetName').innerText = name;
        document.getElementById('targetId').innerText = id;
        document.getElementById('targetDept').innerText = dept;
        document.getElementById('targetTitle').innerText = title || '—';

        const avatarEl = document.getElementById('targetAvatar');
        avatarEl.innerText = initials;
        avatarEl.className = `rounded-circle ${avatarBgClass} d-flex align-items-center justify-content-center fw-bold fs-5`;

        document.getElementById('statSemesters').innerText = '—';
        document.getElementById('statSubjects').innerText = '—';
        document.getElementById('statEval').innerText = '—';

        const tableBody = document.getElementById('historyTableBody');
        tableBody.innerHTML = `
            <tr>
                <td colspan="5" class="text-center text-secondary py-4">No teaching history data available yet.</td>
            </tr>
        `;
    }

    function exportHistory() {
        alert('Export is not connected to a data source yet.');
    }
</script>

<?php require_once __DIR__ . '/../../../../includes/layout-end.php'; ?>