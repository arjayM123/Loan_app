<?php
require_once 'Loan-system/config.php';

if (isLoggedIn()) {
    if (isAdmin()) {
        redirect('admin_pages/admin_dashboard.php');
    } else {
        redirect('User_pages/user_dashboard.php');
    }
} else {
    redirect('Loan-system/login.php');
}
?>