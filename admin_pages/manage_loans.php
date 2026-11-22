<?php
require_once '../Loan-system/config.php';
require_once 'admin_layout.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect('../Loan-system/login.php');
}

$db = Database::getInstance()->getConnection();
$success = '';
$error = '';
if (empty($_SESSION['manage_loans_csrf'])) {
    $_SESSION['manage_loans_csrf'] = bin2hex(random_bytes(32));
}
$currency_symbol = static function ($currency) {
    return strtoupper($currency ?? 'PHP') === 'SGD' ? '$' : '₱';
};

// Handle status update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_status'])) {
    $loan_id = intval($_POST['loan_id']);
    $new_status = sanitize($_POST['status']);
    
    $stmt = $db->prepare("UPDATE loan_applications SET status = ? WHERE id = ?");
    if ($stmt->execute([$new_status, $loan_id])) {
        $success = "Loan status updated successfully!";
    }
}

// Delete selected loan applications in one transaction.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_selected'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    $submitted_ids = $_POST['selected_loan_ids'] ?? [];
    $loan_ids = [];

    if (is_array($submitted_ids)) {
        foreach ($submitted_ids as $submitted_id) {
            $loan_id = filter_var($submitted_id, FILTER_VALIDATE_INT);
            if ($loan_id !== false && $loan_id > 0) {
                $loan_ids[] = $loan_id;
            }
        }
    }
    $loan_ids = array_values(array_unique($loan_ids));

    if (!is_string($csrf_token) || !hash_equals($_SESSION['manage_loans_csrf'], $csrf_token)) {
        $error = 'This form expired. Reload the page and try again.';
    } elseif (!$loan_ids) {
        $error = 'Select at least one loan to delete.';
    } else {
        try {
            $placeholders = implode(',', array_fill(0, count($loan_ids), '?'));
            $db->beginTransaction();

            $stmt = $db->prepare("SELECT id_front_path, id_back_path FROM loan_applications WHERE id IN ($placeholders)");
            $stmt->execute($loan_ids);
            $loan_files = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Payment records and schedules are removed by the loan foreign-key cascades.
            $stmt = $db->prepare("DELETE FROM loan_applications WHERE id IN ($placeholders)");
            $stmt->execute($loan_ids);
            $deleted_count = $stmt->rowCount();
            $db->commit();

            foreach ($loan_files as $loan_file) {
                foreach (['id_front_path', 'id_back_path'] as $file_field) {
                    $file_path = $loan_file[$file_field];
                    if ($file_path && strpos($file_path, '../User_pages/uploads/') === 0) {
                        $file_path = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file_path);
                    }
                    if ($file_path && is_file($file_path)) {
                        unlink($file_path);
                    }
                }
            }

            $success = $deleted_count . ' loan application' . ($deleted_count === 1 ? '' : 's') . ' deleted successfully.';
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = 'Could not delete the selected loans. Please try again.';
        }
    }
}

// Get filter
$status_filter = isset($_GET['status']) ? sanitize($_GET['status']) : 'all';

// Build query
$query = "SELECT la.* FROM loan_applications la";

if ($status_filter != 'all') {
    $query .= " WHERE la.status = :status";
}

$query .= " ORDER BY la.application_date DESC";

$stmt = $db->prepare($query);

if ($status_filter != 'all') {
    $stmt->bindParam(':status', $status_filter);
}

$stmt->execute();
$loans = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<?php ob_start(); ?>
    <style>
        body { background: #edf1f6; }
        .loan-page-container { max-width: 1280px; padding-bottom: 4rem; }
        .loan-page-panel { margin-top: 1.5rem; }
        .loan-page-heading { display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-bottom: 1.5rem; padding: 1.5rem; border-radius: 1.15rem; color: #fff; background: linear-gradient(120deg, #172033, #273c5a); box-shadow: 0 .75rem 1.75rem rgba(28,43,66,.16); }
        .loan-page-kicker { margin-bottom: .35rem; color: #f6c343; font-size: .75rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; }
        .loan-page-heading h1 { margin: 0; font-size: clamp(1.45rem,2.5vw,2rem); font-weight: 700; }
        .loan-page-heading p { margin: .4rem 0 0; color: rgba(255,255,255,.72); }
        .loan-count-card { min-width: 115px; padding: .8rem 1rem; border: 1px solid rgba(255,255,255,.16); border-radius: .9rem; background: rgba(255,255,255,.08); text-align: center; }
        .loan-count-card strong { display: block; font-size: 1.65rem; line-height: 1.1; }
        .loan-count-card span { color: rgba(255,255,255,.72); font-size: .8rem; }
        .loan-page-alert { border: 0; border-radius: .85rem; box-shadow: 0 .25rem .8rem rgba(28,43,66,.06); }
        .loan-grid { row-gap: 1rem; }
        .loan-card { overflow: hidden; border: 1px solid #d5deea !important; border-radius: 1rem; background: #fff; box-shadow: 0 .3rem 1rem rgba(28,43,66,.09) !important; transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease; }
        .loan-card:hover { transform: translateY(-3px); border-color: #aebdd0 !important; box-shadow: 0 .85rem 1.7rem rgba(28,43,66,.16) !important; }
        .loan-card-link { display: block; padding: 1.2rem; color: #172033 !important; text-decoration: none; }
        .loan-card-top { display: flex; justify-content: space-between; align-items: flex-start; gap: .75rem; padding-bottom: .9rem; border-bottom: 1px solid #edf0f5; }
        .loan-card-meta { color: #718096; font-size: .78rem; line-height: 1.5; }
        .loan-card-meta strong { color: #3d4b61; }
        .loan-status-badge { flex: 0 0 auto; padding: .42rem .65rem; border-radius: 999px; font-size: .72rem; font-weight: 700; letter-spacing: .02em; }
        .loan-card-main { display: grid; grid-template-columns: minmax(0,1fr) auto; align-items: end; gap: 1rem; padding-top: 1rem; }
        .loan-field-label { display: block; margin-bottom: .25rem; color: #7b8798; font-size: .72rem; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; }
        .loan-customer-name { margin: 0; color: #172033; font-size: 1.05rem; font-weight: 700; line-height: 1.3; overflow-wrap: anywhere; }
        .loan-amount-block { text-align: right; white-space: nowrap; }
        .loan-amount-value { color: #1e3658; font-size: 1.2rem; font-weight: 750; letter-spacing: -.02em; }
        .loan-card-footer { display: flex; align-items: center; gap: .5rem; min-height: 3.4rem; padding: .65rem 1.2rem; border-top: 1px solid #edf0f5; background: #fafbfd; }
        .loan-card-footer .btn { width: 2.1rem; height: 2.1rem; display: inline-flex; align-items: center; justify-content: center; border-radius: .65rem; }
        .loan-empty-state { padding: 3.5rem 1rem; border: 1px dashed #cbd5e1; border-radius: 1rem; background: rgba(255,255,255,.72); text-align: center; }
        .loan-action-rail {
            position: fixed;
            top: 50%;
            right: 1rem;
            z-index: 1030;
            display: flex;
            flex-direction: column;
            gap: .55rem;
            padding: .65rem;
            background: #fff;
            border: 1px solid rgba(0, 0, 0, .1);
            border-radius: .9rem;
            box-shadow: 0 .5rem 1.25rem rgba(0, 0, 0, .18);
            transform: translateY(-50%);
        }
        .loan-action-rail .btn { width: 48px; height: 48px; padding: .5rem; display: inline-flex; align-items: center; justify-content: center; }
        .loan-action-rail .btn i { margin: 0 !important; }
        .loan-filter-button { width: 42px; height: 42px; display: inline-flex; align-items: center; justify-content: center; }
        .loan-selection-checkbox { display: none; align-items: center; gap: .35rem; margin: 0; cursor: pointer; }
        #loanCards.selection-mode .loan-selection-checkbox { display: flex; }
        #loanCards.selection-mode .loan-card.selected-for-deletion { outline: 2px solid #dc3545; }
        .loan-selection-tools { display: flex; flex-direction: column; gap: .55rem; border-top: 1px solid #dee2e6; padding-top: .55rem; }
        @media (max-width: 575.98px) {
            .loan-action-rail { right: .5rem; padding: .45rem; }
            .loan-action-rail .btn { width: 44px; height: 44px; }
            .loan-page-heading { align-items: flex-start; padding: 1.15rem; }
            .loan-count-card { min-width: 82px; padding: .65rem .7rem; }
            .loan-count-card strong { font-size: 1.35rem; }
            .loan-card-link { padding: 1rem; }
            .loan-card-footer { padding-inline: 1rem; }
            .loan-card-main { gap: .65rem; }
            .loan-amount-value { font-size: 1.05rem; }
        }
    </style>
    <div class="container loan-page-container">
        <div class="loan-page-panel">
            <header class="loan-page-heading">
                <div>
                    <div class="loan-page-kicker">Loan management</div>
                    <h1>Applications</h1>
                    <p>Review loan requests and manage their status.</p>
                </div>
                <div class="loan-count-card" aria-label="Number of loan applications shown">
                    <strong><?php echo count($loans); ?></strong>
                    <span><?php echo count($loans) === 1 ? 'application' : 'applications'; ?></span>
                </div>
            </header>
            
            <?php if ($success !== ''): ?>
                <div class="alert alert-success alert-dismissible fade show loan-page-alert" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger alert-dismissible fade show loan-page-alert" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php
            $badge_class = [
                'pending' => 'bg-warning text-dark',
                'approved' => 'bg-success',
                'rejected' => 'bg-danger',
                'completed' => 'bg-info'
            ];
            ?>

            <!-- Responsive Loans Display -->
            <?php if (count($loans) > 0): ?>
                
                <div class="row g-3 loan-grid" id="loanCards">
                    <?php foreach ($loans as $loan): ?>
                        <?php $symbol = $currency_symbol($loan['currency'] ?? 'PHP'); ?>
                        <div class="col-12 col-md-6 col-xl-4">
                            <article class="card loan-card h-100 shadow-sm" data-loan-id="<?php echo (int) $loan['id']; ?>">
                                <a href="loan_details.php?id=<?php echo $loan['id']; ?>" class="loan-card-link">
                                    <div class="loan-card-top">
                                        <div class="loan-card-meta">
                                            <p class="text-muted small mb-3">Application #<?php echo $loan['id']; ?> · <?php echo date('M d, Y', strtotime($loan['application_date'])); ?></p>
                                        </div>
                                        <span class="badge loan-status-badge <?php echo $badge_class[$loan['status']] ?? 'bg-secondary'; ?>">
                                            <?php echo ucfirst($loan['status']); ?>
                                        </span>
                                    </div>
                                    <div class="loan-card-main">
                                        <div>
                                            <span class="loan-field-label">Customer</span>
                                            <h2 class="loan-customer-name"><?php echo htmlspecialchars($loan['applicant_name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                                        </div>
                                        <div class="loan-amount-block">
                                            <span class="loan-field-label">Loan amount</span>
                                            <strong class="loan-amount-value"><?php echo $symbol . number_format($loan['loan_amount'], 2); ?></strong>
                                        </div>
                                    </div>
                                    
                                </a>
                                <div class="loan-card-footer">
                                    <?php if ($loan['status'] === 'pending'): ?>
                                        <button type="button" class="btn btn-sm btn-success" onclick="updateStatus(<?php echo (int) $loan['id']; ?>, 'approved')" aria-label="Approve loan">
                                            <i class="bi bi-check-lg"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-danger" onclick="updateStatus(<?php echo (int) $loan['id']; ?>, 'rejected')" aria-label="Reject loan">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    <?php endif; ?>
                                    <label class="loan-selection-checkbox ms-auto">
                                        <input type="checkbox" class="form-check-input loan-row-checkbox" value="<?php echo (int) $loan['id']; ?>" aria-label="Select loan <?php echo (int) $loan['id']; ?>">
                                        <span>Select</span>
                                    </label>
                                </div>
                            </article>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php else: ?>
                <div class="loan-empty-state">
                    <i class="bi bi-inbox fs-1 text-secondary"></i>
                    <h4 class="mt-3">No applications found</h4>
                    <p class="text-muted mb-0">Try another status filter or create a loan application.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="loan-action-rail" aria-label="Loan actions">
        <a href="create_loan.php" class="btn btn-warning fw-semibold" aria-label="Create loan" title="Create loan">
            <i class="bi bi-file-earmark-plus"></i>
        </a>
        <div class="dropdown dropstart">
            <button type="button" class="btn btn-outline-primary loan-filter-button" id="loanFilterMenu" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Filter loans" title="Filter loans">
                <i class="bi bi-list fs-3"></i>
            </button>
            <ul class="dropdown-menu" aria-labelledby="loanFilterMenu">
                <li><h6 class="dropdown-header">Filter by status</h6></li>
                <li><a class="dropdown-item <?php echo $status_filter === 'all' ? 'active' : ''; ?>" href="?status=all">All loans</a></li>
                <li><a class="dropdown-item <?php echo $status_filter === 'pending' ? 'active' : ''; ?>" href="?status=pending">Pending</a></li>
                <li><a class="dropdown-item <?php echo $status_filter === 'approved' ? 'active' : ''; ?>" href="?status=approved">Approved</a></li>
                <li><a class="dropdown-item <?php echo $status_filter === 'rejected' ? 'active' : ''; ?>" href="?status=rejected">Rejected</a></li>
                <li><a class="dropdown-item <?php echo $status_filter === 'completed' ? 'active' : ''; ?>" href="?status=completed">Completed</a></li>
            </ul>
        </div>
        <button type="button" class="btn btn-outline-danger fw-semibold" id="deleteActionButton" aria-label="Select loans for deletion" title="Select loans for deletion">
            <i class="bi bi-trash" id="deleteActionIcon"></i>
        </button>
        <div class="loan-selection-tools d-none" id="loanSelectionTools">
            <button type="button" class="btn btn-outline-secondary" id="markAllButton" aria-label="Mark all loans" title="Mark all loans">
                <i class="bi bi-check2-square" id="markAllIcon"></i>
            </button>
            <div class="small text-muted text-center" id="selectedLoanCount">0 selected</div>
            <button type="button" class="btn btn-light" id="cancelSelectionButton" aria-label="Cancel selection" title="Cancel selection">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
    </div>
    
    <!-- Hidden form for status update -->
    <form id="statusForm" method="POST" class="visually-hidden">
        <input type="hidden" name="loan_id" id="statusLoanId">
        <input type="hidden" name="status" id="statusValue">
        <input type="hidden" name="update_status" value="1">
    </form>
    
    <!-- Hidden form for deleting selected applications -->
    <form id="deleteSelectedForm" method="POST" class="visually-hidden">
        <input type="hidden" name="delete_selected" value="1">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['manage_loans_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
        <div id="selectedLoanInputs"></div>
    </form>
    
    <!-- Delete selected confirmation -->
    <div class="modal fade" id="deleteSelectedModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>Delete Selected Loans?
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-circle me-2"></i>
                        <strong>Warning:</strong> This action cannot be undone!
                    </div>
                    <p class="mb-3">You are about to permanently delete <strong><span id="deleteSelectedCount">0</span> loan applications</strong>.</p>
                    <div class="bg-light p-3 rounded mb-3">
                        The selected loans, their payment schedules, payment history, and uploaded ID documents will be removed.
                    </div>
                    <p class="text-danger mb-0"><i class="bi bi-trash me-2"></i>This action cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-2"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-danger" onclick="submitSelectedLoans()">
                        <i class="bi bi-trash me-2"></i>Delete Selected
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        let deleteSelectedModalInstance;
        let selectionMode = false;
        const loanCards = document.getElementById('loanCards');
        const deleteActionButton = document.getElementById('deleteActionButton');
        const deleteActionIcon = document.getElementById('deleteActionIcon');
        const loanSelectionTools = document.getElementById('loanSelectionTools');
        const markAllButton = document.getElementById('markAllButton');
        const markAllIcon = document.getElementById('markAllIcon');
        const selectedLoanCount = document.getElementById('selectedLoanCount');
        const deleteSelectedForm = document.getElementById('deleteSelectedForm');
        
        document.addEventListener('DOMContentLoaded', function() {
            deleteSelectedModalInstance = new bootstrap.Modal(document.getElementById('deleteSelectedModal'));
            deleteActionButton.disabled = !document.querySelector('.loan-row-checkbox');
        });
        
        function updateStatus(loanId, status) {
            const action = status === 'approved' ? 'approve' : 'reject';
            if (confirm(`Are you sure you want to ${action} this loan application?`)) {
                document.getElementById('statusLoanId').value = loanId;
                document.getElementById('statusValue').value = status;
                document.getElementById('statusForm').submit();
            }
        }
        
        function selectedLoanCheckboxes() {
            return Array.from(document.querySelectorAll('.loan-row-checkbox'));
        }

        function checkedLoanCheckboxes() {
            return selectedLoanCheckboxes().filter(function (checkbox) { return checkbox.checked; });
        }

        function updateSelectionDisplay() {
            const checkboxes = selectedLoanCheckboxes();
            const selected = checkedLoanCheckboxes();
            const allSelected = checkboxes.length > 0 && selected.length === checkboxes.length;

            selectedLoanCount.textContent = selected.length + ' selected';
            deleteActionButton.disabled = !selectionMode || selected.length === 0;
            deleteActionButton.classList.toggle('btn-outline-danger', !selectionMode);
            deleteActionButton.classList.toggle('btn-danger', selectionMode);
            deleteActionButton.setAttribute('aria-label', selectionMode ? 'Delete selected loans' : 'Select loans for deletion');
            deleteActionButton.title = selectionMode ? 'Delete selected loans' : 'Select loans for deletion';
            document.querySelectorAll('.loan-card').forEach(function (card) {
                const checkbox = card.querySelector('.loan-row-checkbox');
                card.classList.toggle('selected-for-deletion', Boolean(checkbox && checkbox.checked));
            });
            markAllIcon.classList.toggle('bi-check2-square', !allSelected);
            markAllIcon.classList.toggle('bi-square', allSelected);
            markAllButton.setAttribute('aria-label', allSelected ? 'Unmark all loans' : 'Mark all loans');
            markAllButton.title = allSelected ? 'Unmark all loans' : 'Mark all loans';
        }

        function setSelectionMode(enabled) {
            selectionMode = enabled;
            if (loanCards) {
                loanCards.classList.toggle('selection-mode', enabled);
            }
            loanSelectionTools.classList.toggle('d-none', !enabled);
            if (!enabled) {
                selectedLoanCheckboxes().forEach(function (checkbox) { checkbox.checked = false; });
            }
            updateSelectionDisplay();
        }

        deleteActionButton.addEventListener('click', function () {
            if (!selectionMode) {
                setSelectionMode(true);
                return;
            }

            const selected = checkedLoanCheckboxes();
            if (selected.length === 0) {
                return;
            }
            document.getElementById('deleteSelectedCount').textContent = selected.length;
            deleteSelectedModalInstance.show();
        });

        markAllButton.addEventListener('click', function () {
            const checkboxes = selectedLoanCheckboxes();
            const shouldCheck = checkboxes.some(function (checkbox) { return !checkbox.checked; });
            checkboxes.forEach(function (checkbox) { checkbox.checked = shouldCheck; });
            updateSelectionDisplay();
        });

        document.getElementById('cancelSelectionButton').addEventListener('click', function () {
            setSelectionMode(false);
        });

        document.querySelectorAll('.loan-row-checkbox').forEach(function (checkbox) {
            checkbox.addEventListener('change', updateSelectionDisplay);
        });

        document.querySelectorAll('.loan-card .loan-card-link[href]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                if (selectionMode) {
                    event.preventDefault();
                    const checkbox = link.closest('.loan-card').querySelector('.loan-row-checkbox');
                    checkbox.checked = !checkbox.checked;
                    updateSelectionDisplay();
                }
            });
        });

        function submitSelectedLoans() {
            const inputsContainer = document.getElementById('selectedLoanInputs');
            inputsContainer.innerHTML = '';
            checkedLoanCheckboxes().forEach(function (checkbox) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'selected_loan_ids[]';
                input.value = checkbox.value;
                inputsContainer.appendChild(input);
            });
            deleteSelectedForm.submit();
        }
    </script>
<?php
$content = ob_get_clean();
renderAdminPage('Manage Loans - Admin', $content);
