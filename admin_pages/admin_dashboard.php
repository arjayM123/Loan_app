<?php
require_once '../Loan-system/config.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect('../Loan-system/login.php');
}

$db = Database::getInstance()->getConnection();

// Get statistics
$stmt = $db->query("SELECT 
    COUNT(*) as total_loans,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_loans,
    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_loans,
    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_loans,
    SUM(CASE WHEN status = 'approved' THEN loan_amount ELSE 0 END) as total_disbursed,
    SUM(CASE WHEN status = 'approved' THEN total_amount ELSE 0 END) as total_receivable
FROM loan_applications");
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get total users
$stmt = $db->query("SELECT COUNT(*) as total_users FROM users WHERE role = 'user'");
$user_count = $stmt->fetch(PDO::FETCH_ASSOC)['total_users'];

// Get recent applications
$stmt = $db->query("SELECT la.*, u.full_name as user_name 
    FROM loan_applications la 
    LEFT JOIN users u ON la.user_id = u.id 
    ORDER BY la.application_date DESC LIMIT 10");
$recent_loans = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Loan System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }
        .stat-card {
            border-radius: 15px;
            padding: 25px;
            color: white;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .stat-card-1 { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .stat-card-2 { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .stat-card-3 { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .stat-card-4 { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); }
        .stat-card-5 { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); }
        .stat-card-6 { background: linear-gradient(135deg, #30cfd0 0%, #330867 100%); }
        
        .content-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
    <?php include '../Loan-system/navbar.php'; ?>
    
    <div class="container">
        <div class="mb-4">
            <h2>Admin Dashboard</h2>
            <p class="text-muted">Overview of loan system statistics</p>
        </div>
        
        <!-- Statistics Cards -->
        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="stat-card stat-card-1">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="opacity-75 mb-2">Total Loans</h6>
                            <h2 class="mb-0"><?php echo $stats['total_loans']; ?></h2>
                        </div>
                        <i class="bi bi-folder fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="stat-card stat-card-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="opacity-75 mb-2">Pending</h6>
                            <h2 class="mb-0"><?php echo $stats['pending_loans']; ?></h2>
                        </div>
                        <i class="bi bi-clock-history fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="stat-card stat-card-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="opacity-75 mb-2">Approved</h6>
                            <h2 class="mb-0"><?php echo $stats['approved_loans']; ?></h2>
                        </div>
                        <i class="bi bi-check-circle fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="stat-card stat-card-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="opacity-75 mb-2">Rejected</h6>
                            <h2 class="mb-0"><?php echo $stats['rejected_loans']; ?></h2>
                        </div>
                        <i class="bi bi-x-circle fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="stat-card stat-card-5">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="opacity-75 mb-2">Total Disbursed</h6>
                            <h5 class="mb-0">₱<?php echo number_format($stats['total_disbursed'], 2); ?></h5>
                        </div>
                        <i class="bi bi-cash-stack fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="stat-card stat-card-6">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="opacity-75 mb-2">Total Users</h6>
                            <h2 class="mb-0"><?php echo $user_count; ?></h2>
                        </div>
                        <i class="bi bi-people fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Recent Applications -->
        <div class="content-card">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4 class="mb-0"><i class="bi bi-clock-history me-2"></i>Recent Loan Applications</h4>
                <a href="manage_loans.php" class="btn btn-primary">View All</a>
            </div>
            
            <?php if (count($recent_loans) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Applicant</th>
                                <th>Amount</th>
                                <th>Rate</th>
                                <th>Term</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_loans as $loan): ?>
                                <tr>
                                    <td>#<?php echo $loan['id']; ?></td>
                                    <td><?php echo $loan['applicant_name']; ?></td>
                                    <td>₱<?php echo number_format($loan['loan_amount'], 2); ?></td>
                                    <td><?php echo $loan['interest_rate']; ?>%</td>
                                    <td><?php echo $loan['loan_term']; ?>m</td>
                                    <td>
                                        <?php
                                        $badge_class = [
                                            'pending' => 'bg-warning text-dark',
                                            'approved' => 'bg-success',
                                            'rejected' => 'bg-danger',
                                            'completed' => 'bg-info'
                                        ];
                                        ?>
                                        <span class="badge <?php echo $badge_class[$loan['status']]; ?>">
                                            <?php echo ucfirst($loan['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($loan['application_date'])); ?></td>
                                    <td>
                                        <a href="loan_details.php?id=<?php echo $loan['id']; ?>" class="btn btn-sm btn-primary">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="bi bi-inbox fs-1 text-muted mb-3"></i>
                    <p class="text-muted">No loan applications yet</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>