<?php
require_once 'config.php';

if (isLoggedIn()) {
    if (isAdmin()) {
        redirect('admin_dashboard.php');
    } else {
        redirect('user_dashboard.php');
    }
} else {
    redirect('login.php');
}
?>
