<nav class="navbar navbar-expand-lg navbar-dark bg-dark border-bottom border-warning border-3 mb-4">
    <div class="container-fluid px-3 px-lg-4">
        <a class="navbar-brand d-flex align-items-center gap-2 py-2" href="admin_dashboard.php">
            <span class="lh-1">
                <span class="d-block fw-bold text-warning">HIRAM</span>
            </span>
        </a>
        <button class="navbar-toggler p-2" type="button" data-bs-toggle="collapse" data-bs-target="#adminNavbarNav" aria-controls="adminNavbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="adminNavbarNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0 gap-lg-2">
                <li class="nav-item">
                    <a class="nav-link px-3 py-2 rounded" href="admin_dashboard.php">
                        <i class="bi bi-speedometer2 me-1"></i>Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link px-3 py-2 rounded" href="manage_loans.php">
                        <i class="bi bi-folder me-1"></i>Manage Loans
                    </a>
                </li>
            </ul>
            <ul class="navbar-nav mb-2 mb-lg-0">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle px-3 py-2 rounded border border-secondary" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-person-circle me-1"></i><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i>Profile</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="../Loan-system/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>
