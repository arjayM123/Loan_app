<?php
require_once '/Loan-system/config.php';

if (isLoggedIn()) {
    if (isAdmin()) {
        redirect('admin_dashboard.php');
    } else {
        redirect('user_dashboard.php');
    }
} else {
    redirect('Loan-system/login.php');
}
?>
