<?php
$user_nav_pages = [
    'user_dashboard.php' => 'home',
    'loan_details.php' => 'home',
    'apply_loan.php' => 'apply',
    'payment_history.php' => 'history'
];
$user_nav_current = $user_nav_pages[basename($_SERVER['PHP_SELF'] ?? '')] ?? '';
?>
<nav class="navbar navbar-expand-lg navbar-dark admin-navbar mb-4">
    <div class="container-fluid px-3 px-lg-4">
        <a class="navbar-brand admin-navbar-brand d-flex align-items-center gap-2 py-2" href="user_dashboard.php">
            <span class="lh-1">
                <span class="d-block fw-bold">HIRAM</span>
                <small class="admin-navbar-tagline">LOAN MANAGEMENT</small>
            </span>
        </a>
        <button class="navbar-toggler p-2" type="button" data-bs-toggle="collapse" data-bs-target="#userNavbarNav" aria-controls="userNavbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="userNavbarNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0 gap-lg-2">
                <li class="nav-item">
                    <a class="nav-link px-3 py-2 rounded <?php echo $user_nav_current === 'home' ? 'active' : ''; ?>" href="user_dashboard.php" <?php echo $user_nav_current === 'home' ? 'aria-current="page"' : ''; ?>>Home</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link px-3 py-2 rounded <?php echo $user_nav_current === 'apply' ? 'active' : ''; ?>" href="apply_loan.php" <?php echo $user_nav_current === 'apply' ? 'aria-current="page"' : ''; ?>>Apply Loan</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link px-3 py-2 rounded <?php echo $user_nav_current === 'history' ? 'active' : ''; ?>" href="payment_history.php" <?php echo $user_nav_current === 'history' ? 'aria-current="page"' : ''; ?>>Payment History</a>
                </li>
            </ul>
            <ul class="navbar-nav mb-2 mb-lg-0">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle admin-navbar-profile px-3 py-2 rounded" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-person-circle me-1"></i><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'User'); ?></a>
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
