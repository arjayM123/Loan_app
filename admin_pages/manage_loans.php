<?php
require_once '../Loan-system/config.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect('login.php');
}

$db = Database::getInstance()->getConnection();

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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Loans - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }
        .content-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            margin-top: 20px;
        }
        .filter-btn {
            border-radius: 20px;
            padding: 8px 20px;
        }
        @media (max-width: 767px) {
            .card {
                border-radius: 12px;
            }
            .card-body {
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <?php include '../Loan-system/navbar.php'; ?>
    
    <div class="container">
        <div class="content-card">
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
                    <a href="?status=all" class="btn btn-outline-primary filter-btn <?php echo $status_filter == 'all' ? 'active' : ''; ?>">All</a>
                    <a href="?status=pending" class="btn btn-outline-warning filter-btn <?php echo $status_filter == 'pending' ? 'active' : ''; ?>">Pending</a>
                    <a href="?status=approved" class="btn btn-outline-success filter-btn <?php echo $status_filter == 'approved' ? 'active' : ''; ?>">Approved</a>
                    <a href="?status=rejected" class="btn btn-outline-danger filter-btn <?php echo $status_filter == 'rejected' ? 'active' : ''; ?>">Rejected</a>
                    <a href="?status=completed" class="btn btn-outline-info filter-btn <?php echo $status_filter == 'completed' ? 'active' : ''; ?>">Completed</a>
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
                
                <!-- Desktop Table View -->
                <div class="table-responsive d-none d-md-block">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Applicant</th>
                                <th>User</th>
                                <th>Loan Amount</th>
                                <th>Total Amount</th>
                                <th>Rate</th>
                                <th>Term</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($loans as $loan): ?>
                                <tr>
                                    <td><strong>#<?php echo $loan['id']; ?></strong></td>
                                    <td>
                                        <strong><?php echo $loan['applicant_name']; ?></strong><br>
                                        <small class="text-muted"><?php echo $loan['phone'] ?: ''; ?></small>
                                    </td>
                                    <td>
                                        <?php echo $loan['user_name'] ?? 'N/A'; ?><br>
                                        <small class="text-muted"><?php echo $loan['email'] ?: ''; ?></small>
                                    </td>
                                    <td>₱<?php echo number_format($loan['loan_amount'], 2); ?></td>
                                    <td><strong>₱<?php echo number_format($loan['total_amount'], 2); ?></strong></td>
                                    <td><?php echo $loan['interest_rate']; ?>%</td>
                                    <td><?php echo $loan['loan_term']; ?> mos</td>
                                    <td>
                                        <span class="badge <?php echo $badge_class[$loan['status']] ?? 'bg-secondary'; ?>">
                                            <?php echo ucfirst($loan['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($loan['application_date'])); ?></td>
                                    <td>
                                        <div class="btn-group">
                                            <a href="loan_details.php?id=<?php echo $loan['id']; ?>" 
                                               class="btn btn-sm btn-primary" title="View Details">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <?php if ($loan['status'] == 'approved'): ?>
                                                <a href="manage_payments.php?loan_id=<?php echo $loan['id']; ?>" 
                                                   class="btn btn-sm btn-info" title="Manage Payments">
                                                    <i class="bi bi-cash-stack"></i>
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($loan['status'] == 'pending'): ?>
                                                <button class="btn btn-sm btn-success"
                                                        onclick="updateStatus(<?php echo $loan['id']; ?>, 'approved')"
                                                        title="Approve">
                                                    <i class="bi bi-check-lg"></i>
                                                </button>
                                                <button class="btn btn-sm btn-danger"
                                                        onclick="updateStatus(<?php echo $loan['id']; ?>, 'rejected')"
                                                        title="Reject">
                                                    <i class="bi bi-x-lg"></i>
                                                </button>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-sm btn-danger" 
                                                    onclick="confirmDelete(<?php echo $loan['id']; ?>, '<?php echo htmlspecialchars($loan['applicant_name']); ?>')" 
                                                    title="Delete Permanently">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Card View -->
                <div class="d-block d-md-none">
                    <?php foreach ($loans as $loan): ?>
                        <div class="card mb-3 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="card-title mb-1">#<?php echo $loan['id']; ?> - <?php echo $loan['applicant_name']; ?></h5>
                                    <span class="badge <?php echo $badge_class[$loan['status']] ?? 'bg-secondary'; ?>">
                                        <?php echo ucfirst($loan['status']); ?>
                                    </span>
                                </div>
                                <p class="mb-2 text-muted small"><?php echo date('M d, Y', strtotime($loan['application_date'])); ?></p>

                                <ul class="list-unstyled small mb-3">
                                    <li><strong>Email:</strong> <?php echo $loan['email'] ?: '—'; ?></li>
                                    <li><strong>Phone:</strong> <?php echo $loan['phone'] ?: '—'; ?></li>
                                    <li><strong>Loan:</strong> ₱<?php echo number_format($loan['loan_amount'], 2); ?></li>
                                    <li><strong>Total:</strong> ₱<?php echo number_format($loan['total_amount'], 2); ?></li>
                                    <li><strong>Term:</strong> <?php echo $loan['loan_term']; ?> months</li>
                                    <li><strong>Rate:</strong> <?php echo $loan['interest_rate']; ?>%</li>
                                </ul>

                                <div class="d-flex justify-content-end gap-2 flex-wrap">
                                    <a href="loan_details.php?id=<?php echo $loan['id']; ?>" 
                                       class="btn btn-sm btn-primary" title="View Details">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <?php if ($loan['status'] == 'approved'): ?>
                                        <a href="manage_payments.php?loan_id=<?php echo $loan['id']; ?>" 
                                           class="btn btn-sm btn-info" title="Manage Payments">
                                            <i class="bi bi-cash-stack"></i>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($loan['status'] == 'pending'): ?>
                                        <button class="btn btn-sm btn-success"
                                                onclick="updateStatus(<?php echo $loan['id']; ?>, 'approved')"
                                                title="Approve">
                                            <i class="bi bi-check-lg"></i>
                                        </button>
                                        <button class="btn btn-sm btn-danger"
                                                onclick="updateStatus(<?php echo $loan['id']; ?>, 'rejected')"
                                                title="Reject">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm btn-danger" 
                                            onclick="confirmDelete(<?php echo $loan['id']; ?>, '<?php echo htmlspecialchars($loan['applicant_name']); ?>')" 
                                            title="Delete Permanently">
                                        <i class="bi bi-trash"></i>
                                    </button>
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
    <form id="statusForm" method="POST" style="display: none;">
        <input type="hidden" name="loan_id" id="statusLoanId">
        <input type="hidden" name="status" id="statusValue">
        <input type="hidden" name="update_status" value="1">
    </form>
    
    <!-- Hidden form for deletion -->
    <form id="deleteForm" method="POST" style="display: none;">
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
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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
</body>
</html>