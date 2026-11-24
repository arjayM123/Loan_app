<?php
$admin_nav_pages = [
    'admin_dashboard.php' => 'dashboard',
    'manage_loans.php' => 'loans',
    'loan_details.php' => 'loans',
    'payment_history.php' => 'payments',
    'manage_payments.php' => 'payments',
    'create_loan.php' => 'create'
];
$admin_nav_current = $admin_nav_pages[basename($_SERVER['PHP_SELF'] ?? '')] ?? '';
?>
<nav class="navbar navbar-expand-lg navbar-dark admin-navbar mb-4">
    <div class="container-fluid px-3 px-lg-4">
        <a class="navbar-brand admin-navbar-brand d-flex align-items-center gap-2 py-2" href="admin_dashboard.php">
            <span class="lh-1">
                <span class="d-block fw-bold">HIRAM</span>
                <small class="admin-navbar-tagline">LOAN MANAGEMENT</small>
            </span>
        </a>
        <button class="navbar-toggler p-2" type="button" data-bs-toggle="collapse" data-bs-target="#adminNavbarNav" aria-controls="adminNavbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="adminNavbarNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0 gap-lg-2">
                <li class="nav-item">
                    <a class="nav-link px-3 py-2 rounded <?php echo $admin_nav_current === 'dashboard' ? 'active' : ''; ?>" href="admin_dashboard.php" <?php echo $admin_nav_current === 'dashboard' ? 'aria-current="page"' : ''; ?>>
                        Dashboard
                    </a>
                </li>
                                <li class="nav-item">
                    <a class="nav-link px-3 py-2 rounded <?php echo $admin_nav_current === 'create' ? 'active' : ''; ?>" href="create_loan.php" <?php echo $admin_nav_current === 'create' ? 'aria-current="page"' : ''; ?>>
                        Create Loan
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link px-3 py-2 rounded <?php echo $admin_nav_current === 'loans' ? 'active' : ''; ?>" href="manage_loans.php" <?php echo $admin_nav_current === 'loans' ? 'aria-current="page"' : ''; ?>>
                         Loans
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link px-3 py-2 rounded <?php echo $admin_nav_current === 'payments' ? 'active' : ''; ?>" href="payment_history.php" <?php echo $admin_nav_current === 'payments' ? 'aria-current="page"' : ''; ?>>
                         History
                    </a>
                </li>

            </ul>
            <ul class="navbar-nav mb-2 mb-lg-0">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle admin-navbar-profile px-3 py-2 rounded" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-person-circle me-1"></i><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="profile.php">Profile</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="../Loan-system/logout.php">Logout</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>
