<?php
require_once '../Loan-system/config.php';
require_once 'admin_layout.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect('../Loan-system/login.php');
}

$db = Database::getInstance()->getConnection();
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

// Handle loan deletion
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_loan'])) {
    $loan_id = intval($_POST['loan_id']);
    
    try {
        $db->beginTransaction();
        
        // Get file paths before deleting
        $stmt = $db->prepare("SELECT id_front_path, id_back_path FROM loan_applications WHERE id = ?");
        $stmt->execute([$loan_id]);
        $loan = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Delete associated files
        if ($loan) {
            if ($loan['id_front_path'] && file_exists($loan['id_front_path'])) {
                unlink($loan['id_front_path']);
            }
            if ($loan['id_back_path'] && file_exists($loan['id_back_path'])) {
                unlink($loan['id_back_path']);
            }
        }
        
        // Delete payment records (will cascade delete payment_schedule and payments due to foreign keys)
        $stmt = $db->prepare("DELETE FROM loan_applications WHERE id = ?");
        $stmt->execute([$loan_id]);
        
        $db->commit();
        $success = "Loan deleted permanently and successfully!";
    } catch (Exception $e) {
        $db->rollBack();
        $error = "Error deleting loan: " . $e->getMessage();
    }
}

// Get filter
$status_filter = isset($_GET['status']) ? sanitize($_GET['status']) : 'all';

// Build query
$query = "SELECT la.*, u.full_name as user_name, u.email 
          FROM loan_applications la 
          LEFT JOIN users u ON la.user_id = u.id";

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
    <div class="container">
        <div class="card border-0 shadow-sm p-4 mt-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3><i class="bi bi-folder2-open me-2"></i>Manage Loan Applications</h3>
            </div>
            
            <?php if (isset($success)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i><?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <!-- Filters -->
            <div class="mb-4">
                <!-- Desktop Button Group -->
                <div class="d-none d-md-flex justify-content-center flex-wrap gap-2">
                    <a href="?status=all" class="btn btn-outline-primary <?php echo $status_filter == 'all' ? 'active' : ''; ?>">All</a>
                    <a href="?status=pending" class="btn btn-outline-warning <?php echo $status_filter == 'pending' ? 'active' : ''; ?>">Pending</a>
                    <a href="?status=approved" class="btn btn-outline-success <?php echo $status_filter == 'approved' ? 'active' : ''; ?>">Approved</a>
                    <a href="?status=rejected" class="btn btn-outline-danger <?php echo $status_filter == 'rejected' ? 'active' : ''; ?>">Rejected</a>
                    <a href="?status=completed" class="btn btn-outline-info <?php echo $status_filter == 'completed' ? 'active' : ''; ?>">Completed</a>
                </div>

                <!-- Mobile Dropdown -->
                <div class="d-md-none">
                    <div class="dropdown">
                        <button class="btn btn-outline-primary w-100 dropdown-toggle" type="button" id="statusDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            Status: <?php echo ucfirst($status_filter); ?>
                        </button>
                        <ul class="dropdown-menu w-100" aria-labelledby="statusDropdown">
                            <li><a class="dropdown-item <?php echo $status_filter == 'all' ? 'active' : ''; ?>" href="?status=all">All</a></li>
                            <li><a class="dropdown-item <?php echo $status_filter == 'pending' ? 'active' : ''; ?>" href="?status=pending">Pending</a></li>
                            <li><a class="dropdown-item <?php echo $status_filter == 'approved' ? 'active' : ''; ?>" href="?status=approved">Approved</a></li>
                            <li><a class="dropdown-item <?php echo $status_filter == 'rejected' ? 'active' : ''; ?>" href="?status=rejected">Rejected</a></li>
                            <li><a class="dropdown-item <?php echo $status_filter == 'completed' ? 'active' : ''; ?>" href="?status=completed">Completed</a></li>
                        </ul>
                    </div>
                </div>
            </div>
            
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
                
                <div class="row g-3">
                    <?php foreach ($loans as $loan): ?>
                        <?php $symbol = $currency_symbol($loan['currency'] ?? 'PHP'); ?>
                        <div class="col-12 col-lg-6">
                            <div class="card h-100 shadow-sm border-0">
                                <a href="loan_details.php?id=<?php echo $loan['id']; ?>" class="card-body text-decoration-none text-dark">
                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                        <div>
                                            <h5 class="card-title mb-1"><?php echo htmlspecialchars($loan['applicant_name']); ?></h5>
                                            <p class="text-muted small mb-3">Application #<?php echo $loan['id']; ?> · <?php echo date('M d, Y', strtotime($loan['application_date'])); ?></p>
                                        </div>
                                        <span class="badge <?php echo $badge_class[$loan['status']] ?? 'bg-secondary'; ?>">
                                            <?php echo ucfirst($loan['status']); ?>
                                        </span>
                                    </div>
                                    <div class="row g-2 small">
                                        <div class="col-6"><span class="text-muted d-block">Loan amount</span><strong><?php echo $symbol . number_format($loan['loan_amount'], 2); ?></strong></div>
                                        <div class="col-6"><span class="text-muted d-block">Term</span><strong><?php echo $loan['loan_term']; ?> months</strong></div>
                                        <div class="col-6"><span class="text-muted d-block">Interest</span><strong><?php echo $loan['interest_rate']; ?>%</strong></div>
                                        <div class="col-6"><span class="text-muted d-block">Customer</span><strong><?php echo htmlspecialchars($loan['user_name'] ?? 'N/A'); ?></strong></div>
                                    </div>
                                    
                                </a>
                                <div class="card-footer bg-transparent border-0 pt-0 d-flex gap-2">
                                    <?php if ($loan['status'] == 'approved'): ?><a href="manage_payments.php?loan_id=<?php echo $loan['id']; ?>" class="btn btn-sm btn-info"><i class="bi bi-cash-stack"></i></a><?php endif; ?>
                                    <?php if ($loan['status'] == 'pending'): ?><button class="btn btn-sm btn-success" onclick="updateStatus(<?php echo $loan['id']; ?>, 'approved')"><i class="bi bi-check-lg"></i></button><button class="btn btn-sm btn-danger" onclick="updateStatus(<?php echo $loan['id']; ?>, 'rejected')"><i class="bi bi-x-lg"></i></button><?php endif; ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger ms-auto" onclick="confirmDelete(<?php echo $loan['id']; ?>, '<?php echo htmlspecialchars($loan['applicant_name'], ENT_QUOTES); ?>')"><i class="bi bi-trash"></i></button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php else: ?>
                <div class="text-center py-5">
                    <i class="bi bi-inbox fs-1 text-muted mb-3"></i>
                    <p class="text-muted">No loan applications found</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Hidden form for status update -->
    <form id="statusForm" method="POST" class="visually-hidden">
        <input type="hidden" name="loan_id" id="statusLoanId">
        <input type="hidden" name="status" id="statusValue">
        <input type="hidden" name="update_status" value="1">
    </form>
    
    <!-- Hidden form for deletion -->
    <form id="deleteForm" method="POST" class="visually-hidden">
        <input type="hidden" name="loan_id" id="deleteLoanId">
        <input type="hidden" name="delete_loan" value="1">
    </form>
    
    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>Confirm Deletion
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-circle me-2"></i>
                        <strong>Warning:</strong> This action cannot be undone!
                    </div>
                    <p class="mb-3">You are about to permanently delete:</p>
                    <div class="bg-light p-3 rounded mb-3">
                        <strong>Loan ID:</strong> <span id="deleteModalLoanId"></span><br>
                        <strong>Applicant:</strong> <span id="deleteModalApplicant"></span>
                    </div>
                    <p class="text-danger mb-0">
                        <i class="bi bi-trash me-2"></i>
                        This will delete:
                    </p>
                    <ul class="text-danger">
                        <li>Loan application record</li>
                        <li>Payment schedule</li>
                        <li>Payment history</li>
                        <li>Uploaded ID documents</li>
                    </ul>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-2"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-danger" onclick="deleteLoan()">
                        <i class="bi bi-trash me-2"></i>Yes, Delete Permanently
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        let deleteModalInstance;
        
        document.addEventListener('DOMContentLoaded', function() {
            deleteModalInstance = new bootstrap.Modal(document.getElementById('deleteModal'));
        });
        
        function updateStatus(loanId, status) {
            const action = status === 'approved' ? 'approve' : 'reject';
            if (confirm(`Are you sure you want to ${action} this loan application?`)) {
                document.getElementById('statusLoanId').value = loanId;
                document.getElementById('statusValue').value = status;
                document.getElementById('statusForm').submit();
            }
        }
        
        function confirmDelete(loanId, applicantName) {
            document.getElementById('deleteLoanId').value = loanId;
            document.getElementById('deleteModalLoanId').textContent = '#' + loanId;
            document.getElementById('deleteModalApplicant').textContent = applicantName;
            deleteModalInstance.show();
        }
        
        function deleteLoan() {
            document.getElementById('deleteForm').submit();
        }
    </script>
<?php
$content = ob_get_clean();
renderAdminPage('Manage Loans - Admin', $content);